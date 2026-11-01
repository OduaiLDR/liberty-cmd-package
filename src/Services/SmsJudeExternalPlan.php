<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

use PDO;
use RuntimeException;
use Throwable;

/** Private-file preparation only. No SQL Server connection or business mutation. */
class SmsJudeExternalPlan
{
    public const ROWS=3000000;
    public const ORIGINAL_PLAN_SHA='39c2c3e062cd8e6e58b3843f728afb12b384db1e9e31efd4b01b081bf5caeba5';
    public const MEMBERSHIP_TOOL_SHA='d2fa666ca76ab38a1ea0e8b0fcb906f55af2714ecb4d5eb49636b29f87970315';
    public const TRACKING_TOOL_SHA='5e8ebb4de3ea3f00b41e014316b65af2aac13c8375b30be52277b5bd40a2f9f0';
    private const KEYS=['Prospect_PK','Original_Phone','Original_First_Name','Original_Address','Original_Debt_Text','Original_Send_Date',
        'Part_Number','Part_Row','Expected_Attempts','Expected_Last_SMS_Date','Expected_Latest_Export_Date'];

    public static function uuid(): string
    {
        $bytes=random_bytes(16);$bytes[6]=chr((ord($bytes[6])&15)|64);$bytes[8]=chr((ord($bytes[8])&63)|128);$hex=bin2hex($bytes);
        return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
    }

    public static function actor(string $actor): string
    {
        if($actor===''||trim($actor)!==$actor||!preg_match('//u',$actor)||mb_strlen($actor)>255||preg_match('/[\x00-\x1f\x7f]/',$actor))throw new RuntimeException('An explicit valid registration operator is required.');
        return $actor;
    }

    public static function privatePath(string $path,bool $directory=false): void
    {
        $normal=str_replace('\\','/',$path);
        if(!preg_match('~^(?:[A-Za-z]:/|/)~',$normal)||preg_match('/[\x00-\x1f\x7f]/',$path))throw new RuntimeException('Absolute evidence path required.');
        if(!file_exists($path)&&!is_link($path))throw new RuntimeException('Evidence is missing.');
        for($current=$path;;$current=dirname($current)){if(is_link($current))throw new RuntimeException('Linked evidence path refused.');if(dirname($current)===$current)break;}
        $s=lstat($path);
        if(!$s||($s['mode']&0170000)!==($directory?0040000:0100000)||(!$directory&&$s['nlink']!==1))throw new RuntimeException('Regular private evidence required.');
        if(PHP_OS_FAMILY==='Linux'&&(!function_exists('posix_geteuid')||$s['uid']!==posix_geteuid()||($s['mode']&07777)!==($directory?0700:0600)))throw new RuntimeException('Evidence must retain private owner/modes.');
    }

    private static function jsonFile(string $path): array
    {
        self::privatePath($path);
        if(filesize($path)>100000)throw new RuntimeException('Proof header exceeds its contract.');
        $value=json_decode(file_get_contents($path),true,48,JSON_THROW_ON_ERROR);
        if(!is_array($value))throw new RuntimeException('Proof header is not an object.');return $value;
    }

    public static function validateMembership(array $r,string $sqliteSha): void
    {
        foreach(['complete'=>true,'membership_verified'=>true,'production_writes'=>false,'business_tables_unchanged'=>true,'format'=>1,
            'checked'=>self::ROWS,'found_active_pks'=>self::ROWS,'target_object_id'=>1847065716,'scope'=>'exact-selected-pks-only',
            'source_plan_sha256'=>self::ORIGINAL_PLAN_SHA,'preview_sqlite_sha256'=>$sqliteSha,'distinct_csv_phones_matched_by_phone'=>self::ROWS,
            'csv_phones_not_referenced'=>0,'csv_phones_referenced_by_multiple_pks'=>0] as $key=>$expected)if(($r[$key]??null)!==$expected)throw new RuntimeException('Complete exact membership proof required.');
        foreach(['matched'=>self::ROWS,'missing_active_pk'=>0,'invalid_active_phone'=>0,'phone_not_in_csv'=>0,'first_name_mismatch'=>0,'address_mismatch'=>0] as $key=>$expected)if(($r['classifications'][$key]??null)!==$expected)throw new RuntimeException('Membership contains unresolved rows.');
    }

    public static function validateTracking(array $r,string $sqliteSha,string $membershipSha): void
    {
        foreach(['format'=>1,'complete'=>true,'outcome'=>'clear_read_only','production_writes'=>false,'writer_authorized'=>false,
            'expected'=>self::ROWS,'checked'=>self::ROWS,'held'=>0,'clear'=>self::ROWS,'scope'=>'exact-selected-pks-only',
            'tool_sha256'=>self::TRACKING_TOOL_SHA,'membership_tool_sha256'=>self::MEMBERSHIP_TOOL_SHA,
            'plan_sha256'=>self::ORIGINAL_PLAN_SHA,'membership_sqlite_sha256'=>$membershipSha,'tracking_sqlite_sha256'=>$sqliteSha,
            'confirmed_submitted_at_utc'=>'2026-10-21T18:11:15Z'] as $key=>$expected)if(($r[$key]??null)!==$expected)throw new RuntimeException('Complete clear tracking evidence required.');
        if(($r['reasons']??null)!==[]||($r['unreviewed_external_tables']??null)!==[])throw new RuntimeException('Tracking evidence contains unresolved provenance.');
        SmsExternalEventSeal::hash($r['evidence_sha256']??'');
    }

    public static function validateControls(array $control,array $tracking,array $summaries,array $membership,array $sealedState,array $sealedPayload): void
    {
        unset($tracking['tracking_sqlite_sha256'],$membership['preview_sqlite_sha256']);
        if((int)($control['complete']??0)!==1||json_decode($control['payload']??'null',true,48,JSON_THROW_ON_ERROR)!==$tracking
            ||count($summaries)!==1||json_decode($summaries[0],true,48,JSON_THROW_ON_ERROR)!==$membership
            ||(int)($sealedState['complete']??0)!==1||json_decode($sealedState['payload']??'null',true,48,JSON_THROW_ON_ERROR)!==$sealedPayload)throw new RuntimeException('Stored proof controls differ from their complete reports.');
    }

    private static function readonly(string $path): PDO
    {
        self::privatePath($path);$db=new PDO('sqlite:'.$path,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::SQLITE_ATTR_OPEN_FLAGS=>PDO::SQLITE_OPEN_READONLY]);
        $db->exec('PRAGMA query_only=ON');return $db;
    }

    private static function attach(PDO $db,string $path,string $alias): void
    {
        if(!in_array($alias,['membership','sealed'],true))throw new RuntimeException('Unknown proof attachment.');
        self::privatePath($path);$statement=$db->prepare('ATTACH DATABASE ? AS '.$alias);
        $statement->execute(['file:'.str_replace('\\','/',$path).'?mode=ro&immutable=1']);$statement->closeCursor();
    }

    /** Verify exact joins and the retained CSV identity, never pair files by ordinal position. */
    public static function memberFromProof(array $row): array
    {
        $pk=(int)$row['pk'];$source=(int)$row['source_id'];
        if($pk<1||$source<1||$row['decision']!=='clear'||$row['reasons']!=='[]'||$row['membership_status']!=='matched'
            ||(int)$row['membership_source_id']!==$source||(int)$row['sealed_source_id']!==$source||(int)$row['selected_pk']!==$pk
            ||(int)$row['part']!==(int)$row['sealed_part'])throw new RuntimeException('Proof mapping or hold differs.');
        $current=json_decode($row['current_snapshot'],true,32,JSON_THROW_ON_ERROR);$csv=json_decode($row['original_csv'],true,32,JSON_THROW_ON_ERROR);
        foreach(['Selected_PK','Found_PK','Phone','Client','Address','SMS_Attempts','Last_SMS_Date','Latest_Export_Date'] as $key)if(!array_key_exists($key,$current))throw new RuntimeException('Required explicit before-state field missing.');
        foreach(['phone','first_name','address','debt_text','send_date'] as $key)if(($csv[$key]??null)!==$row[$key])throw new RuntimeException('Retained original CSV differs from its sealed source.');
        if((int)($csv['pk']??0)!==$pk||(int)($csv['source_id']??0)!==$source||(int)($csv['part']??0)!==(int)$row['part']
            ||(int)($current['Selected_PK']??0)!==$pk||(int)($current['Found_PK']??0)!==$pk||($current['Phone']??null)!==$row['phone']
            ||explode(' ',trim((string)($current['Client']??'')))[0]!==$row['first_name']||($current['Address']??null)!==$row['address'])throw new RuntimeException('Current identity is not the verified original mapping.');
        $attempt=$current['SMS_Attempts']??null;
        if(!is_int($attempt)&&(!is_string($attempt)||!preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/',$attempt)))throw new RuntimeException('Tracking attempts are not an exact integer.');
        $part=(int)$row['part'];$partRow=$source-(($part-1)*1000000);
        $raw=['Prospect_PK'=>$pk,'Original_Phone'=>$row['phone'],'Original_First_Name'=>$row['first_name'],'Original_Address'=>$row['address'],
            'Original_Debt_Text'=>$row['debt_text'],'Original_Send_Date'=>$row['send_date'],'Part_Number'=>$part,'Part_Row'=>$partRow,
            'Expected_Attempts'=>(int)$attempt,'Expected_Last_SMS_Date'=>$current['Last_SMS_Date']??null,'Expected_Latest_Export_Date'=>$current['Latest_Export_Date']??null];
        SmsExternalEventSeal::member($raw);return $raw;
    }

    /** Source IDs retain original CSV file/row identity; their order never establishes a PK match. */
    public function source(string $plan,string $membership,string $tracking): array
    {
        foreach([$plan,$membership,$tracking] as $path)self::privatePath($path,true);
        $original=self::jsonFile($plan.'/manifest.json');
        if(hash_file('sha256',$plan.'/manifest.json')!==self::ORIGINAL_PLAN_SHA)throw new RuntimeException('Original closed-plan seal differs.');
        $payload=$original['payload']??[];
        if(($payload['format']??null)!==1||($payload['complete']??null)!==true||($payload['rows']??null)!==self::ROWS
            ||($payload['tool_sha256']??null)!==self::MEMBERSHIP_TOOL_SHA||($payload['files']??null)!==SmsExternalEventSeal::FILE_HASHES
            ||($payload['date']??null)!==SmsExternalEventSeal::DAY)throw new RuntimeException('Original closed plan changed.');
        foreach([$plan.'/membership.sqlite',$membership.'/preview.sqlite',$tracking.'/tracking.sqlite'] as $file)self::privatePath($file);
        $sealedSha=hash_file('sha256',$plan.'/membership.sqlite');$membershipSha=hash_file('sha256',$membership.'/preview.sqlite');$trackingSha=hash_file('sha256',$tracking.'/tracking.sqlite');
        if($sealedSha!==($original['sqlite_sha256']??null))throw new RuntimeException('Original SQLite seal differs.');
        $m=self::jsonFile($membership.'/report.json');$t=self::jsonFile($tracking.'/report.json');self::validateMembership($m,$membershipSha);self::validateTracking($t,$trackingSha,$membershipSha);
        $membershipReportSha=hash_file('sha256',$membership.'/report.json');
        if(($t['membership_report_sha256']??null)!==$membershipReportSha)throw new RuntimeException('Tracking used another membership report.');
        $db=self::readonly($tracking.'/tracking.sqlite');self::attach($db,$membership.'/preview.sqlite','membership');self::attach($db,$plan.'/membership.sqlite','sealed');
        $control=$db->query('SELECT complete,payload FROM control WHERE id=1')->fetch(PDO::FETCH_ASSOC);
        $ms=$db->query('SELECT payload FROM membership.summary')->fetchAll(PDO::FETCH_COLUMN);
        $ps=$db->query('SELECT complete,payload FROM sealed.state WHERE id=1')->fetch(PDO::FETCH_ASSOC);
        self::validateControls($control?:[],$t,$ms,$m,$ps?:[],$payload);
        $check=$db->query("SELECT COUNT(*) AS n,COUNT(DISTINCT t.pk) AS pks,COUNT(DISTINCT t.source_id) AS ids,
 SUM(CASE WHEN t.decision='clear' AND m.status='matched' AND t.source_id=m.source_id AND p.pk IS NOT NULL AND s.id IS NOT NULL THEN 1 ELSE 0 END) AS matched
 FROM checked t LEFT JOIN membership.checked m ON m.pk=t.pk LEFT JOIN sealed.selected_pks p ON p.pk=t.pk LEFT JOIN sealed.source_rows s ON s.id=t.source_id")->fetch(PDO::FETCH_ASSOC);
        foreach(['n','pks','ids','matched'] as $key)if((int)$check[$key]!==self::ROWS)throw new RuntimeException('Writer input is not a complete exact bijection.');
        foreach(['sealed.source_rows','sealed.selected_pks','membership.checked'] as $table)if((int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()!==self::ROWS)throw new RuntimeException('Proof row count differs.');
        return ['db'=>$db,'files'=>[$plan.'/manifest.json'=>self::ORIGINAL_PLAN_SHA,$plan.'/membership.sqlite'=>$sealedSha,
            $membership.'/report.json'=>$membershipReportSha,$membership.'/preview.sqlite'=>$membershipSha,$tracking.'/report.json'=>hash_file('sha256',$tracking.'/report.json'),$tracking.'/tracking.sqlite'=>$trackingSha],
            'tracking_report'=>$t,'membership_report'=>$m];
    }

    public function sourceRows(PDO $db): \Generator
    {
        $sql="SELECT t.pk,t.source_id,t.part,t.decision,t.reasons,t.current_snapshot,t.original_csv,m.source_id AS membership_source_id,m.status AS membership_status,
 p.pk AS selected_pk,s.id AS sealed_source_id,s.part AS sealed_part,s.phone,s.first_name,s.address,s.debt_text,s.send_date
 FROM checked t LEFT JOIN membership.checked m ON m.pk=t.pk LEFT JOIN sealed.selected_pks p ON p.pk=t.pk LEFT JOIN sealed.source_rows s ON s.id=t.source_id ORDER BY t.pk";
        $statement=$db->query($sql);try{while(($row=$statement->fetch(PDO::FETCH_ASSOC))!==false)yield self::memberFromProof($row);}finally{$statement->closeCursor();}
    }

    private static function unchanged(array $files): void
    {
        foreach($files as $path=>$sha){self::privatePath($path);if(hash_file('sha256',$path)!==$sha)throw new RuntimeException('A proof/input changed during preparation.');}
    }

    /** Reconstruct the only ten supported source files; never hash arbitrary plan-supplied paths. */
    private static function sourceMap(array $paths,array $actual): array
    {
        $keys=array_keys($paths);sort($keys);if($keys!==['input','membership','original_plan','tracking'])throw new RuntimeException('Writer source path roles differ.');
        foreach($paths as $path){if(!is_string($path))throw new RuntimeException('Writer source path is not text.');self::privatePath($path,true);}
        $original=$paths['original_plan'];$membership=$paths['membership'];$tracking=$paths['tracking'];$input=$paths['input'];
        if(hash_file('sha256',$original.'/manifest.json')!==self::ORIGINAL_PLAN_SHA)throw new RuntimeException('Writer original manifest pin differs.');
        $p=self::jsonFile($original.'/manifest.json');$m=self::jsonFile($membership.'/report.json');$t=self::jsonFile($tracking.'/report.json');
        if(($p['payload']['files']??null)!==SmsExternalEventSeal::FILE_HASHES||($p['payload']['rows']??null)!==self::ROWS
            ||($p['payload']['tool_sha256']??null)!==self::MEMBERSHIP_TOOL_SHA||($p['payload']['complete']??null)!==true)throw new RuntimeException('Writer original proof identity differs.');
        $map=[$original.'/manifest.json'=>self::ORIGINAL_PLAN_SHA,$original.'/membership.sqlite'=>$p['sqlite_sha256']??'',
            $membership.'/report.json'=>hash_file('sha256',$membership.'/report.json'),$membership.'/preview.sqlite'=>$m['preview_sqlite_sha256']??'',
            $tracking.'/report.json'=>hash_file('sha256',$tracking.'/report.json'),$tracking.'/tracking.sqlite'=>$t['tracking_sqlite_sha256']??''];
        self::validateMembership($m,$map[$membership.'/preview.sqlite']);self::validateTracking($t,$map[$tracking.'/tracking.sqlite'],$map[$membership.'/preview.sqlite']);
        if(($t['membership_report_sha256']??null)!==$map[$membership.'/report.json'])throw new RuntimeException('Writer source reports are unrelated.');
        foreach(SmsExternalEventSeal::FILE_HASHES as $name=>$sha)$map[$input.'/'.$name]=$sha;
        if(count($map)!==10||count($actual)!==10)throw new RuntimeException('Writer requires exactly ten distinct proof/input files.');
        $ordered=$map;ksort($ordered);ksort($actual);if($ordered!==$actual)throw new RuntimeException('Writer source map differs from its pinned roles.');
        return $map;
    }

    public function preview(string $original,string $membership,string $tracking): array
    {
        $source=$this->source($original,$membership,$tracking);$mapping=hash_init('sha256');$before=hash_init('sha256');$count=0;$previous=0;
        foreach($this->sourceRows($source['db']) as $raw){$row=SmsExternalEventSeal::member($raw);if($row['Prospect_PK']<=$previous)throw new RuntimeException('Unordered writer membership.');$previous=$row['Prospect_PK'];$count++;hash_update($mapping,hex2bin($row['Row_SHA256']));hash_update($before,hex2bin($row['Before_SHA256']));}
        if($count!==self::ROWS)throw new RuntimeException('Writer preview count differs.');self::unchanged($source['files']);
        return ['mode'=>'preview','rows'=>$count,'event_key'=>SmsExternalEventSeal::EVENT,'occurred_at_utc'=>'2026-10-21T18:11:15Z',
            'membership_sha256'=>hash_final($mapping),'before_state_sha256'=>hash_final($before),'sql_writes'=>false,'prepared'=>false];
    }

    private static function exclusiveJson(string $path,array $value): void
    {
        $handle=fopen($path,'x+b');if($handle===false)throw new RuntimeException('Refuse to replace private evidence.');
        try{$text=SmsExternalEventSeal::json($value)."\n";if(fwrite($handle,$text)!==strlen($text)||!fflush($handle))throw new RuntimeException('Cannot seal private evidence.');}finally{fclose($handle);}if(!chmod($path,0600))throw new RuntimeException('Cannot protect private evidence.');
    }

    private static function artifactDirectory(string $path): void
    {
        if(!preg_match('~^(?:[A-Za-z]:/|/)~',str_replace('\\','/',$path)))throw new RuntimeException('Absolute artifact root required.');
        for($current=$path;;$current=dirname($current)){if(is_link($current))throw new RuntimeException('Linked artifact ancestor refused.');if(dirname($current)===$current)break;}
        $parent=dirname($path);if(!is_dir($parent))self::artifactDirectory($parent);
        if(is_link($path))throw new RuntimeException('Linked artifact directory refused.');
        if(!is_dir($path)&&!mkdir($path,0750))throw new RuntimeException('Cannot create private original artifact directory.');
        if(PHP_OS_FAMILY==='Linux'&&(!chgrp($path,'www-data')||!chmod($path,0750)))throw new RuntimeException('Cannot grant controlled original download access.');
    }

    public function prepare(string $original,string $membership,string $tracking,string $input,string $output,string $actor,string $packageReference): array
    {
        self::actor($actor);
        if(!preg_match('/\A[a-f0-9]{40}\z/',$packageReference))throw new RuntimeException('Immutable forty-hex package reference required.');
        self::privatePath($input,true);self::privatePath(dirname($output),true);
        $code=['plan_code_sha256'=>hash_file('sha256',__FILE__),'seal_code_sha256'=>hash_file('sha256',(new \ReflectionClass(SmsExternalEventSeal::class))->getFileName()),
            'artifact_code_sha256'=>hash_file('sha256',(new \ReflectionClass(SmsExternalArtifact::class))->getFileName())];
        if(file_exists($output)||is_link($output))throw new RuntimeException('A writer plan must use a new directory; preserve any earlier identity.');
        $source=$this->source($original,$membership,$tracking);
        foreach(SmsExternalEventSeal::FILE_HASHES as $name=>$sha){$path=$input.'/'.$name;self::privatePath($path);if(hash_file('sha256',$path)!==$sha)throw new RuntimeException('Original file pin differs.');$source['files'][$path]=$sha;}
        $free=disk_free_space(dirname($output));if($free===false||$free<8589934592)throw new RuntimeException('Eight GiB evidence headroom required.');
        $previousUmask=umask(0077);
        try {
        if(!mkdir($output,0700))throw new RuntimeException('Cannot preserve new writer identity.');$id=self::uuid();self::exclusiveJson($output.'/identity.json',['request_id'=>$id,'event_key'=>SmsExternalEventSeal::EVENT,'created_at_utc'=>gmdate('c')]);
        $members=new PDO('sqlite:'.$output.'/members.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);chmod($output.'/members.sqlite',0600);
        $members->exec('PRAGMA journal_mode=DELETE;PRAGMA synchronous=FULL;CREATE TABLE members(Prospect_PK INTEGER PRIMARY KEY,Part_Number INTEGER NOT NULL,Part_Row INTEGER NOT NULL,payload TEXT NOT NULL,Row_SHA256 TEXT NOT NULL,Before_SHA256 TEXT NOT NULL,UNIQUE(Part_Number,Part_Row));CREATE TABLE control(id INTEGER PRIMARY KEY CHECK(id=1),complete INTEGER NOT NULL,payload TEXT);INSERT INTO control VALUES(1,0,NULL)');
        $insert=$members->prepare('INSERT INTO members VALUES(?,?,?,?,?,?)');$count=0;$mappingHash=hash_init('sha256');$beforeHash=hash_init('sha256');$parts=[1=>0,2=>0,3=>0];$previous=0;
        try{
            $members->beginTransaction();foreach($this->sourceRows($source['db']) as $raw){$sealed=SmsExternalEventSeal::member($raw);if($raw['Prospect_PK']<=$previous)throw new RuntimeException('Unordered writer input.');$previous=$raw['Prospect_PK'];
                $insert->execute([$raw['Prospect_PK'],$raw['Part_Number'],$raw['Part_Row'],SmsExternalEventSeal::json($raw),$sealed['Row_SHA256'],$sealed['Before_SHA256']]);hash_update($mappingHash,hex2bin($sealed['Row_SHA256']));hash_update($beforeHash,hex2bin($sealed['Before_SHA256']));$parts[$raw['Part_Number']]++;$count++;
                if($count%10000===0){$members->commit();$members->beginTransaction();}
            }$members->commit();
            if($count!==self::ROWS||array_values($parts)!==[1000000,1000000,1000000])throw new RuntimeException('Original part coverage differs.');
            $mappingSha=hash_final($mappingHash);$beforeSha=hash_final($beforeHash);
            $root=SmsExternalArtifact::root((object)['Request_ID'=>$id]);if(file_exists($root)||is_link($root))throw new RuntimeException('Original artifact event directory already exists.');self::artifactDirectory($root);
            $artifactFree=disk_free_space($root);if($artifactFree===false||$artifactFree<1073741824)throw new RuntimeException('One GiB artifact filesystem headroom required.');
            $receipts=[];foreach(SmsExternalEventSeal::FILE_HASHES as $name=>$sha){$target=$root.'/'.$name;$handle=fopen($target,'x+b');$sourceHandle=fopen($input.'/'.$name,'rb');if(!$handle||!$sourceHandle)throw new RuntimeException('Cannot preserve original bytes.');
                try{if(stream_copy_to_stream($sourceHandle,$handle)!==filesize($input.'/'.$name)||!fflush($handle))throw new RuntimeException('Original copy incomplete.');}finally{fclose($sourceHandle);fclose($handle);}
                if(hash_file('sha256',$target)!==$sha)throw new RuntimeException('Copied original differs.');
                if(PHP_OS_FAMILY==='Linux'&&($name==='selected_TblProspects_PKs.csv'?!chmod($target,0600):(!chgrp($target,'www-data')||!chmod($target,0640))))throw new RuntimeException('Cannot protect original copy.');
                $entry=['name'=>$name,'rows'=>$name==='selected_TblProspects_PKs.csv'?self::ROWS:1000000,'bytes'=>filesize($target),'sha256'=>$sha,'private_relative_path'=>$name];if(count($receipts)<3)$entry=['part'=>count($receipts)+1]+$entry;$receipts[]=$entry;
            }
            $zip=SmsExternalArtifact::buildOriginalZip($root,array_slice($receipts,0,3),'Jude original SMS 2026-10-21.zip');
            $artifact=['version'=>1,'csv_format'=>SmsExternalArtifact::FORMAT,'header'=>SmsExternalArtifact::HEADER,'parts'=>array_slice($receipts,0,3),'pk_list'=>$receipts[3],'download'=>$zip];
            self::unchanged($source['files']);
            foreach(['plan_code_sha256'=>__FILE__,'seal_code_sha256'=>(new \ReflectionClass(SmsExternalEventSeal::class))->getFileName(),'artifact_code_sha256'=>(new \ReflectionClass(SmsExternalArtifact::class))->getFileName()] as $key=>$file)if(hash_file('sha256',$file)!==$code[$key])throw new RuntimeException('Plan/row/artifact code changed during preparation.');
            $control=['version'=>1,'request_id'=>$id,'rows'=>$count,'membership_sha256'=>$mappingSha,'before_state_sha256'=>$beforeSha,'input_hashes'=>SmsExternalEventSeal::FILE_HASHES];
            $members->prepare('UPDATE control SET complete=1,payload=? WHERE id=1')->execute([SmsExternalEventSeal::json($control)]);$insert=null;$members=null;
            $body=['version'=>1,'request_id'=>$id,'event_key'=>SmsExternalEventSeal::EVENT,'occurred_at'=>SmsExternalEventSeal::OCCURRED,'business_date'=>SmsExternalEventSeal::DAY,'expected_rows'=>$count,
                'membership_sha256'=>$mappingSha,'before_state_sha256'=>$beforeSha,'preview_sha256'=>hash_file('sha256',$tracking.'/report.json'),'registered_by'=>$actor,
                'original_artifacts_json'=>SmsExternalEventSeal::json($artifact),'file_path'=>$root.'/'.$zip['private_relative_path'],'file_bytes'=>$zip['bytes'],'file_sha256'=>$zip['sha256'],
                'members_sqlite_sha256'=>hash_file('sha256',$output.'/members.sqlite'),'source_hashes'=>$source['files'],'package_reference'=>$packageReference,
                'source_paths'=>['original_plan'=>$original,'membership'=>$membership,'tracking'=>$tracking,'input'=>$input],
                'plan_code_sha256'=>$code['plan_code_sha256'],'seal_code_sha256'=>$code['seal_code_sha256'],'artifact_code_sha256'=>$code['artifact_code_sha256'],'prepared_at_utc'=>gmdate('c')];
            $envelope=['payload'=>$body,'plan_sha256'=>hash('sha256',SmsExternalEventSeal::json($body))];self::exclusiveJson($output.'/plan.json',$envelope);return $this->inspect($output.'/plan.json');
        }catch(Throwable $error){if($members instanceof PDO&&$members->inTransaction())$members->rollBack();self::exclusiveJson($output.'/prepare-error-'.bin2hex(random_bytes(4)).'.json',['class'=>get_class($error),'message'=>$error->getMessage(),'at_utc'=>gmdate('c')]);throw new RuntimeException('Preparation stopped; retain partial identity/artifacts and evidence.',0,$error);}
        } finally { umask($previousUmask); }
    }

    public function inspect(string $path): array
    {
        $envelope=self::jsonFile($path);$body=$envelope['payload']??null;$sha=$envelope['plan_sha256']??null;
        if(!is_array($body)||!is_string($sha)||hash('sha256',SmsExternalEventSeal::json($body))!==$sha||($body['version']??null)!==1
            ||($body['expected_rows']??null)!==self::ROWS||($body['event_key']??null)!==SmsExternalEventSeal::EVENT||($body['occurred_at']??null)!==SmsExternalEventSeal::OCCURRED
            ||($body['business_date']??null)!==SmsExternalEventSeal::DAY||!preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/',$body['request_id']??''))throw new RuntimeException('Writer plan envelope differs.');
        if(($body['plan_code_sha256']??null)!==hash_file('sha256',__FILE__)||($body['seal_code_sha256']??null)!==hash_file('sha256',(new \ReflectionClass(SmsExternalEventSeal::class))->getFileName())
            ||($body['artifact_code_sha256']??null)!==hash_file('sha256',(new \ReflectionClass(SmsExternalArtifact::class))->getFileName()))throw new RuntimeException('Writer plan code changed; review compatibility rather than reseal.');
        self::actor($body['registered_by']);
        if(!is_string($body['package_reference']??null)||!preg_match('/\A[a-f0-9]{40}\z/',$body['package_reference'])||!is_array($body['source_paths']??null)||!is_array($body['source_hashes']??null))throw new RuntimeException('Writer package/source roles differ.');
        self::unchanged(self::sourceMap($body['source_paths'],$body['source_hashes']));
        if(($body['preview_sha256']??null)!==($body['source_hashes'][$body['source_paths']['tracking'].'/report.json']??null))throw new RuntimeException('Writer before-proof receipt differs.');
        $file=dirname($path).'/members.sqlite';self::privatePath($file);if(hash_file('sha256',$file)!==$body['members_sqlite_sha256'])throw new RuntimeException('Writer members changed.');
        $db=self::readonly($file);$control=$db->query('SELECT complete,payload FROM control WHERE id=1')->fetch(PDO::FETCH_ASSOC);
        $expected=['version'=>1,'request_id'=>$body['request_id'],'rows'=>self::ROWS,'membership_sha256'=>$body['membership_sha256'],'before_state_sha256'=>$body['before_state_sha256'],'input_hashes'=>SmsExternalEventSeal::FILE_HASHES];
        if((int)($control['complete']??0)!==1||json_decode($control['payload'],true,32,JSON_THROW_ON_ERROR)!==$expected||(int)$db->query('SELECT COUNT(*) FROM members')->fetchColumn()!==self::ROWS)throw new RuntimeException('Writer member control differs.');
        $service=$body+['plan_sha256'=>$sha];$header=(object)['Expected_Rows'=>self::ROWS,'Original_Artifacts_JSON'=>$body['original_artifacts_json']];
        $manifest=SmsExternalArtifact::manifest($header);foreach($manifest['parts'] as $part)if($part['rows']!==1000000||$part['sha256']!==SmsExternalEventSeal::FILE_HASHES[$part['name']])throw new RuntimeException('Writer artifact pins differ.');
        if($manifest['pk_list']['sha256']!==SmsExternalEventSeal::FILE_HASHES['selected_TblProspects_PKs.csv'])throw new RuntimeException('Writer PK pin differs.');
        SmsExternalArtifact::verify((object)['Request_ID'=>$body['request_id'],'File_Path'=>$body['file_path'],'File_Bytes'=>$body['file_bytes'],'File_Sha256'=>$body['file_sha256']],$header);
        $root=SmsExternalArtifact::root((object)['Request_ID'=>$body['request_id']]);
        foreach([...$manifest['parts'],$manifest['pk_list']] as $entry){if($entry['private_relative_path']!==$entry['name'])throw new RuntimeException('Writer original path differs.');$raw=$root.'/'.$entry['name'];$stat=lstat($raw);if(!$stat||!is_file($raw)||is_link($raw)||$stat['nlink']!==1||filesize($raw)!==$entry['bytes']||hash_file('sha256',$raw)!==$entry['sha256'])throw new RuntimeException('Retained original artifact changed.');}
        if(PHP_OS_FAMILY==='Linux'){
            $group=posix_getgrnam('www-data');$directory=lstat($root);$zip=lstat($body['file_path']);$pk=lstat($root.'/selected_TblProspects_PKs.csv');
            if(!$group||!$directory||!$zip||!$pk||($directory['mode']&07777)!==0750||$directory['gid']!==$group['gid']
                ||($zip['mode']&07777)!==0640||$zip['gid']!==$group['gid']||($pk['mode']&07777)!==0600)throw new RuntimeException('Original artifact privacy/group access changed.');
        }
        return ['service_plan'=>$service,'path'=>$path,'request_id'=>$body['request_id'],'plan_sha256'=>$sha,'package_reference'=>$body['package_reference'],
            'members_file'=>$file,'public'=>['mode'=>'preview','prepared'=>true,'request_id'=>$body['request_id'],'rows'=>self::ROWS,'event_key'=>SmsExternalEventSeal::EVENT,'occurred_at_utc'=>'2026-10-21T18:11:15Z','plan_sha256'=>$sha,'sql_writes'=>false]];
    }

    /** Fully consumed local pages; no PDO cursor is left open across SQL Server operations. */
    public function chunks(array $plan): \Generator
    {
        $db=self::readonly($plan['members_file']);$after=0;$all=hash_init('sha256');$before=hash_init('sha256');$count=0;
        while(true){$statement=$db->prepare('SELECT Prospect_PK,payload,Row_SHA256,Before_SHA256 FROM members WHERE Prospect_PK>? ORDER BY Prospect_PK LIMIT 1000');$statement->execute([$after]);$page=$statement->fetchAll(PDO::FETCH_ASSOC);$statement->closeCursor();if($page===[])break;$raw=[];
            foreach($page as $row){$value=json_decode($row['payload'],true,32,JSON_THROW_ON_ERROR);$sealed=SmsExternalEventSeal::member($value);if($value['Prospect_PK']!==(int)$row['Prospect_PK']||$sealed['Row_SHA256']!==$row['Row_SHA256']||$sealed['Before_SHA256']!==$row['Before_SHA256'])throw new RuntimeException('Sealed writer member changed.');hash_update($all,hex2bin($sealed['Row_SHA256']));hash_update($before,hex2bin($sealed['Before_SHA256']));$raw[]=$value;$count++;}$after=(int)end($page)['Prospect_PK'];yield $raw;
        }
        if($count!==self::ROWS||hash_final($all)!==$plan['service_plan']['membership_sha256']||hash_final($before)!==$plan['service_plan']['before_state_sha256'])throw new RuntimeException('Full writer member seal differs.');
    }
}
