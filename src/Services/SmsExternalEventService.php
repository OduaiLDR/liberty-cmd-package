<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/** Internal exact-PK historical registration. No HTTP writer, selection, repricing or send adapter. */
final class SmsExternalEventService
{
    public const EVENTS='TblSMSExternalEvents';
    public const MEMBERS='TblSMSExternalMembers';
    public const CHUNKS='TblSMSExternalChunks';
    private ?\PDO $session=null;

    public static function pending(): bool
    {
        $db=DB::connection('sqlsrv');
        return $db->getSchemaBuilder()->hasTable(self::EVENTS)
            && $db->table(self::EVENTS)->where('State','<>','completed')->exists();
    }

    public static function exists(): bool
    {
        $db=DB::connection('sqlsrv');
        return $db->getSchemaBuilder()->hasTable(self::EVENTS) && $db->table(self::EVENTS)->exists();
    }

    /** Does not depend on the writer flag: existing history must remain readable. */
    public function status(string $id): object
    {
        return DB::connection('sqlsrv')->table(self::EVENTS)->where('Request_ID',$id)->first()
            ?? throw new RuntimeException('Historical SMS registration not found.');
    }

    /** Plan hashes bind the complete private proof, including exact preflight before-state. */
    public function begin(array $plan): object
    {
        foreach (['request_id','event_key','occurred_at','business_date','expected_rows','plan_sha256','membership_sha256',
            'preview_sha256','before_state_sha256','registered_by','original_artifacts_json','file_path','file_bytes','file_sha256'] as $key) {
            if (! array_key_exists($key,$plan)) throw new InvalidArgumentException('Incomplete sealed historical registration plan.');
        }
        if (! is_string($plan['request_id']) || ! preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/',$plan['request_id'])
            || $plan['event_key']!==SmsExternalEventSeal::EVENT || $plan['occurred_at']!==SmsExternalEventSeal::OCCURRED
            || $plan['business_date']!==SmsExternalEventSeal::DAY || ! is_int($plan['expected_rows'])
            || $plan['expected_rows']<1 || $plan['expected_rows']>3000000
            || ! is_string($plan['registered_by']) || trim($plan['registered_by'])==='' || mb_strlen($plan['registered_by'])>255) {
            throw new InvalidArgumentException('Historical event identity differs from its explicit contract.');
        }
        foreach (['plan_sha256','membership_sha256','preview_sha256','before_state_sha256','file_sha256'] as $key) SmsExternalEventSeal::hash($plan[$key]);
        return $this->locked($plan['request_id'],function (Connection $db) use ($plan): object {
            return $db->transaction(function () use ($db,$plan): object {
                $this->assertSession($db);
                $existing=$db->table(self::EVENTS)->where('Event_Key',$plan['event_key'])->lockForUpdate()->first();
                if ($existing) {
                    foreach (['Request_ID'=>'request_id','Expected_Rows'=>'expected_rows','Plan_SHA256'=>'plan_sha256',
                        'Membership_SHA256'=>'membership_sha256','Preview_SHA256'=>'preview_sha256','Before_State_SHA256'=>'before_state_sha256','Original_Artifacts_JSON'=>'original_artifacts_json'] as $column=>$key) {
                        $stored=$column==='Request_ID'?strtolower((string)$existing->$column):(string)$existing->$column;
                        if ($stored!==(string)$plan[$key]) throw new RuntimeException('Historical event already exists with a conflicting plan or request ID.');
                    }
                    if ($existing->Registered_By!==$plan['registered_by']) throw new RuntimeException('Historical registration operator differs from the sealed plan.');
                    return $existing;
                }
                if ($db->table('TblSMSRuns')->where('Request_ID',$plan['request_id'])->exists()
                    || $db->table('TblSMSRuns')->whereNotIn('Status',SmsRunService::CLOSED)->exists()
                    || self::pending()) throw new RuntimeException('Another SMS workflow or registration is open.');
                $now=$this->now(); $coreNow=substr($now,0,19);
                $run=['Request_ID'=>$plan['request_id'],'Selection_Policy'=>SmsRecordPolicy::EXTERNAL,'Status'=>'external_registering',
                    'Target'=>$plan['expected_rows'],'Selected'=>null,'Run_Options_JSON'=>null,'Created_By'=>$plan['registered_by'],
                    'Created_At'=>$coreNow,'Updated_At'=>$coreNow,'Progress_Step'=>'external_staging',
                    'File_Path'=>$plan['file_path'],'File_Bytes'=>$plan['file_bytes'],'File_Sha256'=>$plan['file_sha256'],'File_At'=>$coreNow];
                $header=['Request_ID'=>$plan['request_id'],'Event_Key'=>$plan['event_key'],'Policy_Version'=>SmsRecordPolicy::EXTERNAL,
                    'State'=>'staging','Occurred_At_UTC'=>$plan['occurred_at'],'Occurred_Precision'=>0,'Business_Date'=>$plan['business_date'],
                    'Registered_By'=>$plan['registered_by'],'Started_At_UTC'=>$now,'Updated_At_UTC'=>$now,'Expected_Rows'=>$plan['expected_rows'],
                    'Plan_SHA256'=>$plan['plan_sha256'],'Membership_SHA256'=>$plan['membership_sha256'],'Preview_SHA256'=>$plan['preview_sha256'],
                    'Before_State_SHA256'=>$plan['before_state_sha256'],
                    'Original_Artifacts_JSON'=>$plan['original_artifacts_json']];
                $this->assertProductionEvent($db,(object)$header);
                // Private bytes and their exact three-part receipt must already exist. Reads never build them.
                SmsExternalArtifact::verify((object)$run,(object)$header);
                $db->table('TblSMSRuns')->insert($run); $db->table(self::EVENTS)->insert($header);
                return $this->status($plan['request_id']);
            });
        });
    }

    /** Staging is resumable only when each repeated immutable row and before-proof is identical. */
    public function stage(string $id,string $planHash,array $input): object
    {
        if ($input===[] || count($input)>SmsExternalEventSeal::MAX_CHUNK) throw new InvalidArgumentException('Use a bounded nonempty historical member chunk.');
        $rows=array_map([SmsExternalEventSeal::class,'member'],$input);
        SmsExternalEventSeal::membership($rows);
        return $this->locked($id,function (Connection $db) use ($id,$planHash,$rows): object {
            return $db->transaction(function () use ($db,$id,$planHash,$rows): object {
                $header=$this->header($db,$id,$planHash);
                if (! in_array($header->State,['staging','verified'],true)) throw new RuntimeException('This historical event no longer accepts staging.');
                $existingRows=$db->table(self::MEMBERS)->where('Request_ID',$id)
                    ->whereIn('Prospect_PK',array_column($rows,'Prospect_PK'))->get()->keyBy('Prospect_PK');
                $newRows=[];
                foreach ($rows as $row) {
                    $existing=$existingRows[$row['Prospect_PK']]??null;
                    if ($existing) {
                        foreach ($row as $key=>$value) {
                            $stored=$key==='Original_Debt_Amount'?SmsExternalEventSeal::storedDebt($existing->$key):$existing->$key;
                            if (($stored===null)!==($value===null) || (string)$stored!==(string)$value) throw new RuntimeException('A staged historical member changed; nothing was replaced.');
                        }
                    } else {
                        if ($header->State!=='staging') throw new RuntimeException('A sealed historical event cannot gain new members.');
                        $newRows[]=['Request_ID'=>$id]+$row;
                    }
                }
                $added=count($newRows);
                if ((int)$header->Staged_Rows+$added>(int)$header->Expected_Rows) throw new RuntimeException('Historical staging exceeds its sealed count.');
                // SQL Server permits at most 2100 bound parameters. Preserve whole chunks
                // atomically while splitting their parameterized inserts, not their meaning.
                foreach (array_chunk($newRows,100) as $insert) $db->table(self::MEMBERS)->insert($insert);
                if ($added>0) $db->table(self::EVENTS)->where('Request_ID',$id)->update(['Staged_Rows'=>(int)$header->Staged_Rows+$added,'Updated_At_UTC'=>$this->now()]);
                return $this->status($id);
            });
        });
    }

    /** Stream full exact membership once; a numeric range never defines membership. */
    public function seal(string $id,string $planHash): object
    {
        return $this->locked($id,function (Connection $db) use ($id,$planHash): object {
            return $this->longRead($db,fn ()=>$db->transaction(function () use ($db,$id,$planHash): object {
                $header=$this->header($db,$id,$planHash);
                if ($header->State==='verified') return $header;
                if ($header->State!=='staging' || (int)$header->Staged_Rows!==(int)$header->Expected_Rows) throw new RuntimeException('Historical staging is incomplete.');
                $query=$db->table(self::MEMBERS)->where('Request_ID',$id);
                $mappingHash=hash_init('sha256'); $beforeHash=hash_init('sha256'); $count=0; $previous=0;
                foreach ((clone $query)->orderBy('Prospect_PK')->cursor() as $member) {
                    $row=SmsExternalEventSeal::storedMember($member);
                    SmsExternalEventSeal::assertUnapplied($member);
                    if ($row['Prospect_PK']<=$previous || $member->Applied_Chunk!==null) throw new RuntimeException('Historical staging order or prior application differs.');
                    hash_update($mappingHash,hex2bin($row['Row_SHA256'])); hash_update($beforeHash,hex2bin($row['Before_SHA256']));
                    $previous=$row['Prospect_PK']; $count++;
                }
                if ($count!==(int)$header->Expected_Rows || hash_final($mappingHash)!==$header->Membership_SHA256
                    || hash_final($beforeHash)!==$header->Before_State_SHA256) {
                    throw new RuntimeException('Full historical membership seal differs.');
                }
                $parts=(clone $query)->groupBy('Part_Number')->selectRaw('Part_Number,COUNT(*) AS n,MIN(Part_Row) AS lo,MAX(Part_Row) AS hi')->get();
                if (count($parts)!==3) throw new RuntimeException('Historical membership must preserve all three original parts.');
                $manifest=SmsExternalArtifact::manifest($header);
                foreach ($parts as $part) {
                    $receipt=array_values(array_filter($manifest['parts'],fn ($r)=>(int)$r['part']===(int)$part->Part_Number));
                    if (count($receipt)!==1 || (int)$receipt[0]['rows']!==(int)$part->n || (int)$part->lo!==1 || (int)$part->hi!==(int)$part->n) {
                        throw new RuntimeException('Original part row bijection differs.');
                    }
                }
                $db->table(self::EVENTS)->where('Request_ID',$id)->update(['State'=>'verified','Updated_At_UTC'=>$this->now()]);
                $db->table('TblSMSRuns')->where('Request_ID',$id)->update(['Progress_Step'=>'external_verified','Updated_At'=>substr($this->now(),0,19)]);
                return $this->status($id);
            }));
        });
    }

    /** One atomic update/audit/history/checkpoint. An uncertain commit is inspected, never blindly repeated. */
    public function applyNext(string $id,string $planHash,?int $expectedAfter): array
    {
        return $this->locked($id,function (Connection $db) use ($id,$planHash,$expectedAfter): array {
            return $db->transaction(function () use ($db,$id,$planHash,$expectedAfter): array {
                $header=$this->header($db,$id,$planHash);
                if ($header->State==='completed') return ['outcome'=>'already_completed','updated'=>0,'history_added'=>0];
                if (! in_array($header->State,['verified','registering'],true)) throw new RuntimeException('Historical event is not sealed for registration.');
                $after=$header->Last_Applied_PK===null?null:(int)$header->Last_Applied_PK;
                if ($after!==$expectedAfter) throw new RuntimeException('Historical chunk cursor changed; inspect its committed receipt before retrying.');
                $query=$db->table(self::MEMBERS)->where('Request_ID',$id)->orderBy('Prospect_PK');
                if ($after!==null) $query->where('Prospect_PK','>',$after);
                $members=$query->limit(SmsExternalEventSeal::MAX_CHUNK)->get();
                if ($members->isEmpty()) return ['outcome'=>'ready_to_finish','updated'=>0,'history_added'=>0];
                $ids=$members->pluck('Prospect_PK')->map(fn ($v)=>(int)$v)->all();
                $current=$db->table('TblProspects')->whereIn('PK',$ids)->orderBy('PK')->lockForUpdate()
                    ->get(['PK','Phone','Client','Address','SMS_Attempts','Last_SMS_Date','Latest_Export_Date'])->keyBy('PK');
                if ($current->count()!==$members->count()) throw new RuntimeException('An exact historical prospect is missing.');
                foreach ($members as $member) {
                    SmsExternalEventSeal::assertUnapplied($member);
                    $row=$current[$member->Prospect_PK];
                    if ($member->Applied_Chunk!==null || $member->Previous_Attempts!==null || $member->Recorded_Attempts!==null
                        || $row->Phone!==$member->Original_Phone || explode(' ',trim((string)$row->Client))[0]!==$member->Original_First_Name
                        || (string)$row->Address!==$member->Original_Address || (int)$row->SMS_Attempts!==(int)$member->Expected_Attempts
                        || $row->Last_SMS_Date!==$member->Expected_Last_SMS_Date || $row->Latest_Export_Date!==$member->Expected_Latest_Export_Date
                        || $row->SMS_Attempts===null || (int)$row->SMS_Attempts<0 || (int)$row->SMS_Attempts>=2147483647) {
                        throw new RuntimeException('Historical identity, sealed before-state or unapplied receipt changed.');
                    }
                    SmsExternalEventSeal::storedMember($member);
                }
                $this->assertNoOverlap($db,$id,$ids,$header->Business_Date);
                $chunk=(int)$header->Last_Chunk+1; $now=$this->now();
                if ($db->getDriverName()==='sqlsrv') {
                    $placeholders=implode(',',array_fill(0,count($ids),'?'));
                    $result=$db->selectOne("SET NOCOUNT ON;
DECLARE @Changed TABLE(Prospect_PK int NOT NULL PRIMARY KEY,Previous_Attempts int,Recorded_Attempts int,
 Previous_Last_SMS_Date date,Recorded_Last_SMS_Date date,Previous_Latest_Export_Date date,Recorded_Latest_Export_Date date);
UPDATE p SET SMS_Attempts=p.SMS_Attempts+1,
 Last_SMS_Date=CASE WHEN p.Last_SMS_Date IS NULL OR p.Last_SMS_Date<? THEN ? ELSE p.Last_SMS_Date END,
 Latest_Export_Date=CASE WHEN p.Latest_Export_Date IS NULL OR p.Latest_Export_Date<? THEN ? ELSE p.Latest_Export_Date END
OUTPUT inserted.PK,deleted.SMS_Attempts,inserted.SMS_Attempts,deleted.Last_SMS_Date,inserted.Last_SMS_Date,
 deleted.Latest_Export_Date,inserted.Latest_Export_Date INTO @Changed
FROM dbo.TblProspects p JOIN dbo.TblSMSExternalMembers m ON m.Prospect_PK=p.PK
WHERE m.Request_ID=? AND m.Applied_Chunk IS NULL AND m.Prospect_PK IN ($placeholders);
UPDATE m SET Previous_Attempts=c.Previous_Attempts,Recorded_Attempts=c.Recorded_Attempts,
 Previous_Last_SMS_Date=c.Previous_Last_SMS_Date,Recorded_Last_SMS_Date=c.Recorded_Last_SMS_Date,
 Previous_Latest_Export_Date=c.Previous_Latest_Export_Date,Recorded_Latest_Export_Date=c.Recorded_Latest_Export_Date,
 Applied_Chunk=?,Applied_At_UTC=?
FROM dbo.TblSMSExternalMembers m JOIN @Changed c ON c.Prospect_PK=m.Prospect_PK WHERE m.Request_ID=?;
SELECT COUNT(*) AS n FROM @Changed;",[
                        $header->Business_Date,$header->Business_Date,$header->Business_Date,$header->Business_Date,$id,...$ids,$chunk,$now,$id]);
                    if ((int)($result->n??-1)!==count($ids)) throw new RuntimeException('Historical UPDATE OUTPUT did not reconcile.');
                } else {
                    foreach ($members as $member) {
                        $row=$current[$member->Prospect_PK];
                        $last=SmsExternalEventSeal::later($row->Last_SMS_Date,$header->Business_Date);
                        $latest=SmsExternalEventSeal::later($row->Latest_Export_Date,$header->Business_Date);
                        if ($db->table('TblProspects')->where('PK',$row->PK)->update(['SMS_Attempts'=>(int)$row->SMS_Attempts+1,
                            'Last_SMS_Date'=>$last,'Latest_Export_Date'=>$latest])!==1) throw new RuntimeException('Historical prospect update did not reconcile.');
                        $db->table(self::MEMBERS)->where('Request_ID',$id)->where('Prospect_PK',$row->PK)->update([
                            'Previous_Attempts'=>(int)$row->SMS_Attempts,'Recorded_Attempts'=>(int)$row->SMS_Attempts+1,
                            'Previous_Last_SMS_Date'=>$row->Last_SMS_Date,'Recorded_Last_SMS_Date'=>$last,
                            'Previous_Latest_Export_Date'=>$row->Latest_Export_Date,'Recorded_Latest_Export_Date'=>$latest,
                            'Applied_Chunk'=>$chunk,'Applied_At_UTC'=>$now]);
                    }
                }
                $source=$db->table(self::MEMBERS)->where('Request_ID',$id)->where('Applied_Chunk',$chunk)->whereIn('Prospect_PK',$ids)
                    ->selectRaw('? AS Request_ID,Prospect_PK,Original_Phone,? AS Export_Date,Original_Debt_Amount,Original_Address',[$id,SmsExternalEventSeal::OCCURRED]);
                $db->table('TblSMSHistory')->insertUsing(['Request_ID','Prospect_PK','Phone','Export_Date','Debt_Amount','Address'],$source);
                if ($db->table('TblSMSHistory')->where('Request_ID',$id)->whereIn('Prospect_PK',$ids)->count()!==count($ids)) {
                    throw new RuntimeException('Historical immutable history does not reconcile.');
                }
                $through=max($ids); $seal=SmsExternalEventSeal::membership($members);
                $db->table(self::CHUNKS)->insert(['Request_ID'=>$id,'Chunk_Number'=>$chunk,'After_PK'=>$after,'Through_PK'=>$through,
                    'Members'=>count($ids),'Membership_SHA256'=>$seal,'Updated_Prospects'=>count($ids),'History_Rows'=>count($ids),'Committed_At_UTC'=>$now]);
                if ((int)$header->Applied_Rows+count($ids)>(int)$header->Expected_Rows) throw new RuntimeException('Historical application exceeds its sealed count.');
                $db->table(self::EVENTS)->where('Request_ID',$id)->update(['State'=>'registering','Applied_Rows'=>(int)$header->Applied_Rows+count($ids),
                    'Last_Applied_PK'=>$through,'Last_Chunk'=>$chunk,'Updated_At_UTC'=>$now]);
                $db->table('TblSMSRuns')->where('Request_ID',$id)->update(['Progress_Step'=>'external_registering','Updated_At'=>substr($now,0,19)]);
                app(SmsAvailabilityLedger::class)->dirty($db);
                return ['outcome'=>'applied','chunk'=>$chunk,'after_pk'=>$through,'updated'=>count($ids),'history_added'=>count($ids),
                    'applied_total'=>(int)$header->Applied_Rows+count($ids)];
            });
        });
    }

    /** Full immutable event reconciliation. Later legitimate prospect changes are not replay work. */
    public function verify(string $id,string $planHash): array
    {
        SmsExternalEventSeal::hash($planHash);
        $db=DB::connection('sqlsrv'); $header=$this->status($id);
        if ($header->Plan_SHA256!==$planHash) throw new RuntimeException('Historical verification plan differs.');
        $run=$db->table('TblSMSRuns')->where('Request_ID',$id)->first();
        if (! $run) throw new RuntimeException('Historical core run is missing.');
        $complete=SmsExternalEventProjection::assertComplete($run);
        SmsExternalArtifact::verify($run,$complete);
        return $this->verifyLedger($db,$header);
    }

    public function finish(string $id,string $planHash): array
    {
        return $this->locked($id,function (Connection $db) use ($id,$planHash): array {
            return $db->transaction(function () use ($db,$id,$planHash): array {
                $header=$this->header($db,$id,$planHash);
                $receipt=$this->verifyLedger($db,$header);
                if ($header->State==='completed') {
                    $run=$db->table('TblSMSRuns')->where('Request_ID',$id)->first();
                    $complete=SmsExternalEventProjection::assertComplete($run); SmsExternalArtifact::verify($run,$complete);
                    return $receipt+['outcome'=>'already_completed','updated'=>0,'history_added'=>0];
                }
                if (! in_array($header->State,['verified','registering'],true)) throw new RuntimeException('Historical event is not ready to finish.');
                $run=$db->table('TblSMSRuns')->where('Request_ID',$id)->first();
                SmsExternalArtifact::verify($run,$header);
                $now=$this->now();
                $db->table(self::EVENTS)->where('Request_ID',$id)->update(['State'=>'completed','Completed_At_UTC'=>$now,'Updated_At_UTC'=>$now]);
                $db->table('TblSMSRuns')->where('Request_ID',$id)->update(['Status'=>'external_sent','Selected'=>(int)$header->Expected_Rows,
                    'Sent_At'=>SmsExternalEventSeal::OCCURRED,'Sent_By'=>null,'Progress_Step'=>'external_completed','Closed_At'=>substr($now,0,19),'Updated_At'=>substr($now,0,19)]);
                // Every mutation chunk already committed both scope revisions. Queue one recount after final close.
                $db->afterCommit(static fn()=>SmsAvailabilityRefresh::afterNativeCommit());
                return $receipt+['outcome'=>'completed','updated'=>0,'history_added'=>0];
            });
        });
    }

    public function hold(string $id,string $planHash,string $errorCode): object
    {
        if (! preg_match('/\A[a-z][a-z0-9_]{0,79}\z/',$errorCode)) throw new InvalidArgumentException('Use a bounded non-PII historical error code.');
        return $this->locked($id,fn (Connection $db)=>$db->transaction(function () use ($db,$id,$planHash,$errorCode): object {
            $header=$this->header($db,$id,$planHash);
            if ($header->State==='completed') return $header;
            $now=$this->now();
            $db->table(self::EVENTS)->where('Request_ID',$id)->update(['State'=>'held','Error_Code'=>$errorCode,'Error_At_UTC'=>$now,'Updated_At_UTC'=>$now]);
            $db->table('TblSMSRuns')->where('Request_ID',$id)->update(['Progress_Step'=>'external_held','Updated_At'=>substr($now,0,19)]);
            return $this->status($id);
        }));
    }

    /** Explicit operator recovery only. Never guesses whether an uncertain chunk committed. */
    public function resume(string $id,string $planHash): object
    {
        return $this->locked($id,fn (Connection $db)=>$db->transaction(function () use ($db,$id,$planHash): object {
            $header=$this->header($db,$id,$planHash);
            if ($header->State==='completed') { $this->verifyLedger($db,$header); return $header; }
            if ($header->State!=='held') throw new RuntimeException('Only an explicitly held historical registration can be resumed.');
            $staged=(int)$header->Staged_Rows; $applied=(int)$header->Applied_Rows;
            if ($staged===(int)$header->Expected_Rows) {
                $this->verifyLedger($db,$header,true);
                $state=$applied===0?'verified':'registering';
            } else {
                if ($staged<0 || $staged>(int)$header->Expected_Rows || $applied!==0 || (int)$header->Last_Chunk!==0 || $header->Last_Applied_PK!==null
                    || $db->table('TblSMSHistory')->where('Request_ID',$id)->exists() || $db->table(self::CHUNKS)->where('Request_ID',$id)->exists()) {
                    throw new RuntimeException('An incomplete historical stage has unexpected applied evidence.');
                }
                $count=$this->longRead($db,function () use ($db,$id): int {
                    $count=0; $previous=0;
                    foreach ($db->table(self::MEMBERS)->where('Request_ID',$id)->orderBy('Prospect_PK')->cursor() as $member) {
                        $row=SmsExternalEventSeal::storedMember($member); SmsExternalEventSeal::assertUnapplied($member);
                        if ($row['Prospect_PK']<=$previous) throw new RuntimeException('Historical staged prefix differs.');
                        $previous=$row['Prospect_PK']; $count++;
                    }
                    return $count;
                });
                if ($count!==$staged) throw new RuntimeException('Historical staged prefix count differs.');
                $state='staging';
            }
            $now=$this->now();
            $db->table(self::EVENTS)->where('Request_ID',$id)->update(['State'=>$state,'Error_Code'=>null,'Error_At_UTC'=>null,'Updated_At_UTC'=>$now]);
            $db->table('TblSMSRuns')->where('Request_ID',$id)->update(['Progress_Step'=>'external_'.$state,'Updated_At'=>substr($now,0,19)]);
            return $this->status($id);
        }));
    }

    private function verifyLedger(Connection $db,object $header,bool $partial=false): array
    {
        return $this->longRead($db,fn ()=>$this->verifyLedgerNow($db,$header,$partial));
    }

    private function verifyLedgerNow(Connection $db,object $header,bool $partial): array
    {
        $id=(string)$header->Request_ID; $expected=(int)$header->Expected_Rows;
        $applied=(int)$header->Applied_Rows;
        $members=$db->table(self::MEMBERS)->where('Request_ID',$id);
        $history=$db->table('TblSMSHistory')->where('Request_ID',$id);
        if ((int)$header->Staged_Rows!==$expected || $applied<0 || $applied>$expected || (! $partial && $applied!==$expected)
            || (clone $members)->count()!==$expected || (clone $history)->count()!==$applied
            || (clone $members)->whereNotNull('Applied_Chunk')->count()!==$applied) {
            throw new RuntimeException('Historical event is incomplete or its full membership differs.');
        }
        $badAudit=(clone $members)->whereNotNull('Applied_Chunk')->where(function ($q): void {
            $q->whereNull('Previous_Attempts')->orWhereNull('Recorded_Attempts')->orWhereColumn('Previous_Attempts','<>','Expected_Attempts')
                ->orWhereRaw('Recorded_Attempts <> Previous_Attempts + 1')->orWhereNull('Applied_At_UTC')
                ->orWhereRaw('(Previous_Last_SMS_Date <> Expected_Last_SMS_Date OR (Previous_Last_SMS_Date IS NULL AND Expected_Last_SMS_Date IS NOT NULL) OR (Previous_Last_SMS_Date IS NOT NULL AND Expected_Last_SMS_Date IS NULL))')
                ->orWhereRaw('(Previous_Latest_Export_Date <> Expected_Latest_Export_Date OR (Previous_Latest_Export_Date IS NULL AND Expected_Latest_Export_Date IS NOT NULL) OR (Previous_Latest_Export_Date IS NOT NULL AND Expected_Latest_Export_Date IS NULL))')
                ->orWhereRaw('CASE WHEN Previous_Last_SMS_Date IS NULL OR Previous_Last_SMS_Date < ? THEN ? ELSE Previous_Last_SMS_Date END <> Recorded_Last_SMS_Date', [SmsExternalEventSeal::DAY,SmsExternalEventSeal::DAY])
                ->orWhereRaw('CASE WHEN Previous_Latest_Export_Date IS NULL OR Previous_Latest_Export_Date < ? THEN ? ELSE Previous_Latest_Export_Date END <> Recorded_Latest_Export_Date', [SmsExternalEventSeal::DAY,SmsExternalEventSeal::DAY])
                ->orWhereNull('Recorded_Last_SMS_Date')->orWhereNull('Recorded_Latest_Export_Date');
        })->exists();
        if ($badAudit) throw new RuntimeException('Historical before/after attempts or dates do not reconcile.');
        $addressDifferent=$db->getDriverName()==='sqlsrv'
            ? 'CONVERT(varbinary(max),CONVERT(nvarchar(max),h.Address)) <> CONVERT(varbinary(max),CONVERT(nvarchar(max),m.Original_Address))'
            : 'CAST(h.Address AS BLOB) <> CAST(m.Original_Address AS BLOB)';
        $badHistory=$db->table('TblSMSHistory as h')->leftJoin(self::MEMBERS.' as m',function ($join): void {
            $join->on('m.Request_ID','=','h.Request_ID')->on('m.Prospect_PK','=','h.Prospect_PK');
        })->where('h.Request_ID',$id)->where(function ($q) use ($addressDifferent): void {
            $q->whereNull('m.Prospect_PK')->orWhereNull('m.Applied_Chunk')->orWhereColumn('h.Phone','<>','m.Original_Phone')
                ->orWhereRaw($addressDifferent)->orWhereNull('h.Address')
                ->orWhereRaw('(h.Debt_Amount <> m.Original_Debt_Amount OR (h.Debt_Amount IS NULL AND m.Original_Debt_Amount IS NOT NULL) OR (h.Debt_Amount IS NOT NULL AND m.Original_Debt_Amount IS NULL))')
                ->orWhere('h.Export_Date','<>',SmsExternalEventSeal::OCCURRED);
            foreach (['SMS_Drop_PK','Drop_Name','Debt_Tier','Debt_Source','Debt_Source_PK','Client','City','State','Zip','Email','Was_Previously_Texted','Last_SMS_Date_At_Selection'] as $field) $q->orWhereNotNull('h.'.$field);
        })->exists();
        if ($badHistory) throw new RuntimeException('Historical immutable history does not match known original metadata.');
        $after=null; $number=0; $total=0; $byNumber=[];
        // Metadata is at most 3000 chunks for the fixed 3M/1000 contract. Do not
        // run another query while a forward-only PDO cursor is still open.
        $chunks=$db->table(self::CHUNKS)->where('Request_ID',$id)->orderBy('Chunk_Number')->limit((int)ceil($expected/SmsExternalEventSeal::MAX_CHUNK)+1)->get();
        foreach ($chunks as $chunk) {
            $n=(int)$chunk->Members; $through=(int)$chunk->Through_PK;
            if ((int)$chunk->Chunk_Number!==++$number || ($chunk->After_PK===null?null:(int)$chunk->After_PK)!==$after
                || $n<1 || $n>SmsExternalEventSeal::MAX_CHUNK || $through<=($after??0)
                || (int)$chunk->Updated_Prospects!==$n || (int)$chunk->History_Rows!==$n) throw new RuntimeException('Historical committed chunk chain differs.');
            $byNumber[$number]=$chunk;
            $total+=$n; $after=$through;
        }
        if ($total!==$applied || $number!==(int)$header->Last_Chunk || $after!==($header->Last_Applied_PK===null?null:(int)$header->Last_Applied_PK)) throw new RuntimeException('Historical final checkpoint differs.');
        $allHash=hash_init('sha256'); $beforeHash=hash_init('sha256'); $chunkHash=hash_init('sha256'); $chunkNumber=1; $inChunk=0; $previous=0;
        $unapplied=false;
        foreach ((clone $members)->orderBy('Prospect_PK')->cursor() as $member) {
            $pk=(int)$member->Prospect_PK; $chunk=$byNumber[$chunkNumber]??null;
            if ($pk<=$previous) throw new RuntimeException('Historical membership order differs.');
            SmsExternalEventSeal::storedMember($member);
            $binary=hex2bin(SmsExternalEventSeal::hash($member->Row_SHA256));
            hash_update($allHash,$binary); hash_update($beforeHash,hex2bin($member->Before_SHA256)); $previous=$pk;
            if ($member->Applied_Chunk===null) {
                SmsExternalEventSeal::assertUnapplied($member);
                if ($inChunk!==0 || $chunkNumber!==$number+1 || $member->Recorded_Attempts!==null || $member->Previous_Attempts!==null
                    || $member->Applied_At_UTC!==null || $member->Recorded_Last_SMS_Date!==null || $member->Recorded_Latest_Export_Date!==null
                    || $member->Previous_Last_SMS_Date!==null || $member->Previous_Latest_Export_Date!==null) throw new RuntimeException('Historical unapplied prefix contains a partial audit.');
                $unapplied=true; continue;
            }
            if ($unapplied || ! $chunk || (int)$member->Applied_Chunk!==$chunkNumber) throw new RuntimeException('Historical applied prefix is not contiguous.');
            hash_update($chunkHash,$binary); $inChunk++;
            if ($inChunk===(int)$chunk->Members) {
                if ($pk!==(int)$chunk->Through_PK || hash_final($chunkHash)!==$chunk->Membership_SHA256) throw new RuntimeException('Historical chunk membership seal differs.');
                $chunkNumber++; $inChunk=0; $chunkHash=hash_init('sha256');
            }
        }
        if ($inChunk!==0 || $chunkNumber!==$number+1 || hash_final($allHash)!==$header->Membership_SHA256
            || hash_final($beforeHash)!==$header->Before_State_SHA256) throw new RuntimeException('Historical full membership/before-state seal differs.');
        return ['request_id'=>strtolower($id),'verified'=>true,'complete'=>$applied===$expected,'members'=>$expected,'history'=>$applied,'chunks'=>$number,
            'membership_sha256'=>$header->Membership_SHA256,'new_attempts_on_replay'=>0,'new_history_on_replay'=>0];
    }

    private function assertNoOverlap(Connection $db,string $id,array $ids,string $date): void
    {
        // The two specifically established discarded unused/corrected batches remain audit evidence.
        $unused=['e20ca1c2-a0e6-4e6a-a034-ccb312257d44','7786e508-1e7c-4d14-abd2-e61cb0af0e52'];
        $history=$db->table('TblSMSHistory as h')->leftJoin('TblSMSRuns as r','r.Request_ID','=','h.Request_ID')
            ->whereIn('h.Prospect_PK',$ids)->where(function ($q) use ($unused): void {
                $q->whereNull('r.Request_ID')->orWhere('r.Status','<>','discarded')->orWhereNotIn('h.Request_ID',$unused);
            });
        if ($history->exists() || $db->table('TblSMSAttemptAudit')->whereIn('Prospect_PK',$ids)->exists()
            || $db->table('TblSMSResponseAttribution')->whereIn('Prospect_PK',$ids)->exists()
            || $db->table('TblSMSLegacySends as s')->join(self::MEMBERS.' as m','m.Original_Phone','=','s.Phone')
                ->where('m.Request_ID',$id)->whereIn('m.Prospect_PK',$ids)->where('s.Send_Date',$date)->exists()) {
            throw new RuntimeException('Historical event overlaps unresolved send, attempt or attribution evidence.');
        }
    }

    private function header(Connection $db,string $id,string $planHash): object
    {
        $this->assertSession($db);
        SmsExternalEventSeal::hash($planHash);
        $header=$db->table(self::EVENTS)->where('Request_ID',$id)->lockForUpdate()->first();
        $run=$db->table('TblSMSRuns')->where('Request_ID',$id)->lockForUpdate()->first();
        if (! $header || ! $run || ! SmsRecordPolicy::isExternal($run) || $header->Policy_Version!==SmsRecordPolicy::EXTERNAL
            || $header->Plan_SHA256!==$planHash || $header->Event_Key!==SmsExternalEventSeal::EVENT
            || ! preg_match('/\A2026-10-21 18:11:15(?:\.0{1,7})?\z/',(string)$header->Occurred_At_UTC) || (int)$header->Occurred_Precision!==0
            || $header->Business_Date!==SmsExternalEventSeal::DAY || $run->Run_Options_JSON!==null
            || ! in_array($header->State,['staging','verified','registering','held','completed'],true)
            || (int)$header->Expected_Rows<1 || (int)$header->Expected_Rows>3000000 || (int)$run->Target!==(int)$header->Expected_Rows
            || (int)$header->Staged_Rows<0 || (int)$header->Staged_Rows>(int)$header->Expected_Rows
            || (int)$header->Applied_Rows<0 || (int)$header->Applied_Rows>(int)$header->Staged_Rows
            || $run->Approved_At!==null || $run->Approved_By!==null
            || ($header->State==='completed' ? (int)$run->Selected!==(int)$header->Expected_Rows : $run->Selected!==null)
            || ($header->State==='completed' ? $run->Status!=='external_sent' : $run->Status!=='external_registering')) {
            throw new RuntimeException('Historical event identity or durable state changed.');
        }
        $this->assertProductionEvent($db,$header);
        return $header;
    }

    private function locked(string $id,callable $work): mixed
    {
        if (config('sms-exports.external_event_writer_enabled',false)!==true) throw new RuntimeException('Historical registration writer is disabled.');
        $db=DB::connection('sqlsrv');
        if ($db->transactionLevel()!==0 || $db->getPdo()->inTransaction()) throw new RuntimeException('Historical registration owns each writer transaction.');
        $session=$db->getPdo(); $this->session=$session;
        if ($db->getDriverName()==='sqlsrv' && ! $this->isFixture($db)) {
            $identity=$db->selectOne("SELECT DB_NAME() AS db_name,OBJECT_ID('dbo.TblProspects','U') AS object_id");
            if (config('app.env')!=='production' || $db->getConfig('host')!=='serverlibertydebtrelief.database.windows.net'
                || $identity->db_name!=='CommissionDatabase' || (int)$identity->object_id!==1847065716) throw new RuntimeException('Historical writer database identity changed.');
            if ($db->getTablePrefix()!=='' || config('services.cmd.auth_enabled',true)!==true) throw new RuntimeException('Historical writer authentication/schema settings changed.');
            foreach (['durable_availability_enabled','record_workflow_enabled','run_file_options_enabled','tier_selection_enabled','tier_selection_windowed_enabled'] as $key) {
                if (config('sms-exports.'.$key)!==true) throw new RuntimeException('Historical writer requires the coordinated core rollout.');
            }
            foreach (['prospect_enrichment_enabled','tu_completion_date_enabled','contact_attribution_enabled','run_archive_enabled','file_publication_enabled','email_handoff_enabled','demo'] as $key) {
                if (config('sms-exports.'.$key,false)!==false) throw new RuntimeException('Unexpected integration activation during historical registration.');
            }
            if (config('sms-exports.email_allowed_link_hosts',[])!==[]) throw new RuntimeException('Unexpected email hosts during historical registration.');
        }
        if ($db->getDriverName()==='sqlsrv') $this->assertSqlSafety($db);
        $resources=['new-run','maintenance-start','prospect-maintenance','prospect-admission','contact-sync','phone-suppression','contact-attribution','run:'.$id];
        $next=function (int $i) use (&$next,$resources,$db,$session,$work): mixed {
            if ($db->getPdo()!==$session) throw new RuntimeException('Historical registration coordination session changed.');
            return isset($resources[$i]) ? SmsWorkflowLock::run($resources[$i],fn ()=>$next($i+1)) : $work($db);
        };
        try { return $next(0); } finally { $this->session=null; }
    }

    private function assertSession(Connection $db): void
    {
        if ($this->session===null || $db->getPdo()!==$this->session) throw new RuntimeException('Historical registration transaction lost its coordination session.');
    }

    /** Known exact event ledger only; the 1000-row mutation timeout is restored afterward. */
    private function longRead(Connection $db,callable $read): mixed
    {
        if ($db->getDriverName()!=='sqlsrv') return $read();
        $pdo=$db->getPdo(); $before=$pdo->getAttribute(\PDO::SQLSRV_ATTR_QUERY_TIMEOUT);
        $pdo->setAttribute(\PDO::SQLSRV_ATTR_QUERY_TIMEOUT,300);
        try { return $read(); }
        finally { if ($db->getPdo()===$pdo) $pdo->setAttribute(\PDO::SQLSRV_ATTR_QUERY_TIMEOUT,$before); }
    }

    private function isFixture(Connection $db): bool
    {
        return config('app.env')==='testing' && $db->getConfig('host')==='127.0.0.1'
            && (int)$db->getConfig('port')===14330 && preg_match('/\Asms_external_event_test_[a-f0-9]{16}\z/',(string)$db->getDatabaseName())===1;
    }

    private function assertProductionEvent(Connection $db,object $header): void
    {
        if ($db->getDriverName()!=='sqlsrv' || $this->isFixture($db)) return;
        if ((int)$header->Expected_Rows!==3000000) throw new RuntimeException('Production Jude event requires exactly 3M verified members.');
        $manifest=SmsExternalArtifact::manifest($header);
        foreach ($manifest['parts'] as $part) {
            if (($part['rows']??null)!==1000000 || ($part['sha256']??null)!==(SmsExternalEventSeal::FILE_HASHES[$part['name']]??null)) {
                throw new RuntimeException('Production original CSV hash/count differs.');
            }
        }
        if (($manifest['pk_list']['rows']??null)!==3000000
            || ($manifest['pk_list']['sha256']??null)!==SmsExternalEventSeal::FILE_HASHES['selected_TblProspects_PKs.csv']) throw new RuntimeException('Production selected-PK input hash/count differs.');
    }

    private function assertSqlSafety(Connection $db): void
    {
        $settings=$db->selectOne("SELECT SESSIONPROPERTY('ANSI_NULLS') AS ansi_nulls,SESSIONPROPERTY('ANSI_PADDING') AS ansi_padding,
            SESSIONPROPERTY('ANSI_WARNINGS') AS ansi_warnings,SESSIONPROPERTY('QUOTED_IDENTIFIER') AS quoted_identifier,
            SESSIONPROPERTY('CONCAT_NULL_YIELDS_NULL') AS concat_null,SESSIONPROPERTY('NUMERIC_ROUNDABORT') AS numeric_roundabort,
            SESSIONPROPERTY('ARITHABORT') AS arithabort,compatibility_level FROM sys.databases WHERE database_id=DB_ID()");
        foreach (['ansi_nulls','ansi_padding','ansi_warnings','quoted_identifier','concat_null'] as $key) {
            if ((int)($settings->$key??0)!==1) throw new RuntimeException('Historical writer requires lossless compatible session SET options.');
        }
        if ((int)($settings->numeric_roundabort??1)!==0 || ((int)($settings->arithabort??0)!==1 && (int)($settings->compatibility_level??0)<90)) throw new RuntimeException('Historical arithmetic session options differ.');
        $triggers=$db->select("SELECT TOP(1) name FROM sys.triggers WHERE is_disabled=0 AND parent_id IN
            (OBJECT_ID('dbo.TblProspects'),OBJECT_ID('dbo.TblSMSHistory'),OBJECT_ID('dbo.TblSMSRuns'),OBJECT_ID('dbo.TblSMSAvailabilitySummary'),
             OBJECT_ID('dbo.TblSMSExternalEvents'),OBJECT_ID('dbo.TblSMSExternalMembers'),OBJECT_ID('dbo.TblSMSExternalChunks'))");
        if ($triggers!==[]) throw new RuntimeException('An enabled unreviewed trigger prevents exact historical registration.');
        $index=$db->selectOne("SELECT TOP(1) i.name FROM sys.indexes i JOIN sys.index_columns ic ON ic.object_id=i.object_id AND ic.index_id=i.index_id
            JOIN sys.columns c ON c.object_id=ic.object_id AND c.column_id=ic.column_id WHERE i.object_id=OBJECT_ID('dbo.TblSMSAttemptAudit')
            AND i.type IN(1,2) AND i.is_disabled=0 AND i.is_hypothetical=0 AND i.has_filter=0 AND ic.key_ordinal=1 AND c.name='Prospect_PK'");
        if (! $index && $db->table('TblSMSAttemptAudit')->exists()) throw new RuntimeException('Nonempty attempt evidence lacks a bounded exact-prospect lookup.');
    }

    private function now(): string
    {
        return CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u');
    }
}
