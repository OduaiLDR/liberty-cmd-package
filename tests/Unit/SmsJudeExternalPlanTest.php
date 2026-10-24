<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Console\Commands\RegisterJudeSmsEvent;
use Cmd\Reports\Services\SmsExternalEventSeal;
use Cmd\Reports\Services\SmsJudeExternalPlan;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;

/** Local pure proof/command checks: no .env, production connection or business writer. */
final class SmsJudeExternalPlanTest extends TestCase
{
    private function proofRow(): array
    {
        $csv=['pk'=>4,'source_id'=>1000007,'status'=>'matched','part'=>2,'phone'=>'0005550100','first_name'=>'Synthetic','address'=>'1 Fixture St','debt_text'=>'13000','send_date'=>'2026-10-21'];
        $current=['Selected_PK'=>'4','Found_PK'=>'4','Phone'=>$csv['phone'],'Client'=>'Synthetic Person','Address'=>$csv['address'],'SMS_Attempts'=>'0','Last_SMS_Date'=>null,'Latest_Export_Date'=>null];
        return ['pk'=>4,'source_id'=>1000007,'part'=>2,'decision'=>'clear','reasons'=>'[]','membership_source_id'=>1000007,'membership_status'=>'matched',
            'sealed_source_id'=>1000007,'sealed_part'=>2,'selected_pk'=>4,'current_snapshot'=>json_encode($current,JSON_THROW_ON_ERROR),'original_csv'=>json_encode($csv,JSON_THROW_ON_ERROR)]+array_intersect_key($csv,array_flip(['phone','first_name','address','debt_text','send_date']));
    }

    public function test_explicit_source_identity_and_part_row_are_distinct_from_pk_order(): void
    {
        $raw=SmsJudeExternalPlan::memberFromProof($this->proofRow());
        self::assertSame(4,$raw['Prospect_PK']);self::assertSame(2,$raw['Part_Number']);self::assertSame(7,$raw['Part_Row']);
        self::assertSame('0005550100',$raw['Original_Phone']);self::assertSame(0,$raw['Expected_Attempts']);self::assertNull($raw['Expected_Last_SMS_Date']);
        $sealed=SmsExternalEventSeal::member($raw);self::assertSame('13000.00',$sealed['Original_Debt_Amount']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D',$sealed['Before_SHA256']);
    }

    public function test_changed_phone_source_mapping_or_missing_before_field_never_becomes_null_evidence(): void
    {
        foreach(['decision'=>'hold','membership_source_id'=>1000008,'sealed_source_id'=>1,'selected_pk'=>7,'reasons'=>'["unknown"]','membership_status'=>'address_mismatch'] as $key=>$value){
            try{SmsJudeExternalPlan::memberFromProof(array_replace($this->proofRow(),[$key=>$value]));self::fail('Changed mapping accepted.');}catch(RuntimeException){}
        }
        foreach(['Phone'=>'0005550101','SMS_Attempts'=>'1e3','Address'=>'Changed'] as $key=>$value){$row=$this->proofRow();$current=json_decode($row['current_snapshot'],true);$current[$key]=$value;$row['current_snapshot']=json_encode($current,JSON_THROW_ON_ERROR);
            try{SmsJudeExternalPlan::memberFromProof($row);self::fail('Changed before evidence accepted.');}catch(RuntimeException){}
        }
        $row=$this->proofRow();$current=json_decode($row['current_snapshot'],true);unset($current['Last_SMS_Date']);$row['current_snapshot']=json_encode($current,JSON_THROW_ON_ERROR);
        $this->expectException(RuntimeException::class);SmsJudeExternalPlan::memberFromProof($row);
    }

    public function test_only_complete_clear_full_proofs_are_accepted(): void
    {
        $sha=str_repeat('a',64);$r=['complete'=>true,'membership_verified'=>true,'production_writes'=>false,'business_tables_unchanged'=>true,'format'=>1,
            'checked'=>3000000,'found_active_pks'=>3000000,'target_object_id'=>1847065716,'scope'=>'exact-selected-pks-only',
            'source_plan_sha256'=>SmsJudeExternalPlan::ORIGINAL_PLAN_SHA,'preview_sqlite_sha256'=>$sha,'distinct_csv_phones_matched_by_phone'=>3000000,
            'csv_phones_not_referenced'=>0,'csv_phones_referenced_by_multiple_pks'=>0,'classifications'=>['matched'=>3000000,'missing_active_pk'=>0,'invalid_active_phone'=>0,'phone_not_in_csv'=>0,'first_name_mismatch'=>0,'address_mismatch'=>0]];
        SmsJudeExternalPlan::validateMembership($r,$sha);self::assertTrue(true);
        foreach(['complete'=>false,'membership_verified'=>false,'checked'=>2999999,'preview_sqlite_sha256'=>str_repeat('b',64)] as $key=>$value){try{SmsJudeExternalPlan::validateMembership(array_replace($r,[$key=>$value]),$sha);self::fail('Unsealed proof accepted.');}catch(RuntimeException){}}
        $t=['format'=>1,'complete'=>true,'outcome'=>'clear_read_only','production_writes'=>false,'writer_authorized'=>false,'expected'=>3000000,'checked'=>3000000,'held'=>0,'clear'=>3000000,
            'scope'=>'exact-selected-pks-only','tool_sha256'=>SmsJudeExternalPlan::TRACKING_TOOL_SHA,'membership_tool_sha256'=>SmsJudeExternalPlan::MEMBERSHIP_TOOL_SHA,
            'plan_sha256'=>SmsJudeExternalPlan::ORIGINAL_PLAN_SHA,'membership_sqlite_sha256'=>$sha,'tracking_sqlite_sha256'=>$sha,'confirmed_submitted_at_utc'=>'2026-10-21T18:11:15Z','reasons'=>[],'unreviewed_external_tables'=>[],'evidence_sha256'=>$sha];
        SmsJudeExternalPlan::validateTracking($t,$sha,$sha);self::assertTrue(true);
        foreach(['held'=>1,'clear'=>2999999,'tool_sha256'=>str_repeat('c',64),'confirmed_submitted_at_utc'=>'2026-10-24T18:11:15Z','outcome'=>'hold'] as $key=>$value){try{SmsJudeExternalPlan::validateTracking(array_replace($t,[$key=>$value]),$sha,$sha);self::fail('Held tracking accepted.');}catch(RuntimeException){}}
    }

    public function test_uuid_and_action_contracts_do_not_silently_reidentify_or_apply(): void
    {
        $first=SmsJudeExternalPlan::uuid();$second=SmsJudeExternalPlan::uuid();self::assertNotSame($first,$second);
        self::assertMatchesRegularExpression('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D',$first);
        self::assertSame('preview',RegisterJudeSmsEvent::mode([]));self::assertSame('prepare',RegisterJudeSmsEvent::mode(['prepare'=>true]));
        self::assertSame('resume',RegisterJudeSmsEvent::mode(['resume'=>true]));self::assertSame('Operator',SmsJudeExternalPlan::actor('Operator'));
        try{SmsJudeExternalPlan::actor("Operator\n");self::fail('Actor control accepted.');}catch(RuntimeException){}
        $this->expectException(RuntimeException::class);RegisterJudeSmsEvent::mode(['prepare'=>true,'apply'=>true]);
    }

    public function test_direct_preparation_rejects_nonimmutable_package_before_reading_paths(): void
    {
        $this->expectException(RuntimeException::class);$this->expectExceptionMessage('forty-hex');
        (new SmsJudeExternalPlan)->prepare('/not-read/original','/not-read/member','/not-read/tracking','/not-read/input','/not-read/output','Operator','mutable-branch');
    }

    public function test_true_controls_with_partial_payloads_never_override_full_reports(): void
    {
        $tracking=['complete'=>true,'checked'=>3000000,'tracking_sqlite_sha256'=>str_repeat('a',64)];$membership=['complete'=>true,'checked'=>3000000,'preview_sqlite_sha256'=>str_repeat('b',64)];$payload=['complete'=>true,'rows'=>3000000];
        $control=['complete'=>1,'payload'=>'{"complete":true,"checked":3000000}'];$summaries=['{"complete":true,"checked":3000000}'];$state=['complete'=>1,'payload'=>json_encode($payload,JSON_THROW_ON_ERROR)];
        SmsJudeExternalPlan::validateControls($control,$tracking,$summaries,$membership,$state,$payload);self::assertTrue(true);
        foreach([['complete'=>0,'payload'=>$control['payload']],['complete'=>1,'payload'=>'{"complete":true,"checked":2999999}'],['complete'=>1,'payload'=>'{"complete":false,"checked":3000000}']] as $partial){
            try{SmsJudeExternalPlan::validateControls($partial,$tracking,$summaries,$membership,$state,$payload);self::fail('Partial control accepted.');}catch(RuntimeException){}
        }
        $this->expectException(RuntimeException::class);SmsJudeExternalPlan::validateControls($control,$tracking,[],$membership,$state,$payload);
    }

    public function test_source_roles_cannot_authorize_an_arbitrary_hash_file_read(): void
    {
        $method=new \ReflectionMethod(SmsJudeExternalPlan::class,'sourceMap');
        $this->expectException(RuntimeException::class);$this->expectExceptionMessage('roles differ');
        $method->invoke(null,['unreviewed-role'=>'/not-read/private-secret'],['/not-read/private-secret'=>str_repeat('a',64)]);
    }

    public function test_default_console_preview_never_resolves_a_database_or_writer(): void
    {
        $previous=Container::getInstance();$container=new class extends Container { public function runningUnitTests(): bool{return true;} };Container::setInstance($container);
        $plan=new class extends SmsJudeExternalPlan {
            public function inspect(string $path): array{return ['public'=>['mode'=>'preview','prepared'=>true,'rows'=>3000000,'occurred_at_utc'=>'2026-10-21T18:11:15Z','sql_writes'=>false]];}
        };
        $container->instance(SmsJudeExternalPlan::class,$plan);
        $container->bind('db',static function():never{throw new RuntimeException('Preview attempted SQL.');});
        try{$command=new RegisterJudeSmsEvent;$command->setLaravel($container);$tester=new CommandTester($command);$code=$tester->execute(['--plan'=>'synthetic-preview-only']);
            self::assertSame(0,$code);$out=json_decode(trim($tester->getDisplay()),true,32,JSON_THROW_ON_ERROR);self::assertSame('preview',$out['mode']);self::assertFalse($out['sql_writes']);self::assertSame('2026-10-21T18:11:15Z',$out['occurred_at_utc']);
        }finally{Container::setInstance($previous);}
    }
}
