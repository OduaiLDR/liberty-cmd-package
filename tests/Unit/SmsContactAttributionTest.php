<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Carbon\Carbon;
use Cmd\Reports\Services\SmsAvailabilityLedger;
use Cmd\Reports\Services\SmsContactAttribution;
use Cmd\Reports\Services\SmsContactEvent;
use Cmd\Reports\Services\SmsDurableAvailabilityService;
use Cmd\Reports\Services\SmsRecordPolicy;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** In-memory synthetic evidence, real transactions/ledger, fake refresh. No host, Snowflake or provider. */
final class SmsContactAttributionTest extends TestCase
{
    private const ID = '11111111-2222-4333-8444-555555555555';
    private const OTHER = '22222222-3333-4444-8555-666666666666';
    private const SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private Container $previous;
    private Container $container;
    private $db;
    private SmsContactAttribution $writer;

    protected function setUp(): void
    {
        $this->previous = Container::getInstance();
        $this->container = new Container; Container::setInstance($this->container);
        $this->container->instance('config', new Repository(['app' => ['env' => 'testing'],
            'database' => ['default' => 'sqlsrv'], 'sms-exports' => [
                'record_workflow_enabled' => true, 'durable_availability_enabled' => true,
                'contact_attribution_enabled' => true, 'contact_source_system' => 'fixture.contacts',
                'contact_source_contract_sha256' => self::SHA,
            ]]));
        $capsule = new Manager($this->container);
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:'], 'sqlsrv');
        $capsule->getDatabaseManager()->setDefaultConnection('sqlsrv');
        $this->container->instance('db', $capsule->getDatabaseManager());
        Facade::clearResolvedInstances(); Facade::setFacadeApplication($this->container);
        $this->db = $capsule->getConnection('sqlsrv');
        $this->db->setTransactionManager(new DatabaseTransactionsManager);
        $this->db->getSchemaBuilder()->create('TblPhoneNumbers', fn (Blueprint $t) => $t->string('Phone'));
        foreach (['2026_10_12_000001_create_sms_prospects_tables.php', '2026_10_19_000001_complete_sms_workflow.php',
            '2026_10_20_000001_create_sms_availability_summary.php', '2026_10_20_000003_add_sms_record_approval.php',
            '2026_10_23_000001_add_sms_contact_attribution_evidence.php'] as $file) {
            (require dirname(__DIR__, 2).'/database/sms-migrations/'.$file)->up();
        }
        $refresh = \Mockery::mock(SmsDurableAvailabilityService::class);
        $refresh->shouldReceive('request')->andReturn(['state' => 'pending']);
        $this->container->instance(SmsDurableAvailabilityService::class, $refresh);
        $this->writer = new SmsContactAttribution;
        Carbon::setTestNow(Carbon::parse('2026-10-23T12:00:00.500000Z'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(); \Mockery::close(); $this->db->disconnect();
        Facade::clearResolvedInstances(); Container::setInstance($this->previous); Facade::setFacadeApplication($this->previous);
    }

    private function person(array $values = []): int
    {
        return (int) $this->db->table('TblProspects')->insertGetId($values + ['Client' => 'Synthetic Person',
            'Address' => 'Fixture St', 'Zip' => '00123', 'Phone' => '0005550100', 'Creation_Date' => '2026-10-01']);
    }

    private function event(int $pk, array $values = []): SmsContactEvent
    {
        return SmsContactEvent::fromCanonical(...array_replace(['prospectPk' => $pk, 'phone' => '0005550100',
            'explicitOriginalUtc' => '2026-10-22T06:59:59.999999999Z', 'sourceSystem' => 'fixture.contacts',
            'sourceContactId' => 'fixture-1', 'sourceContractSha256' => self::SHA,
            'candidateCount' => 1, 'coverageComplete' => true], $values));
    }

    private function member(int $pk, string $id = self::ID, array $run = [], string $phone = '0005550100'): void
    {
        $values = $run + ['Request_ID' => $id, 'Target' => 1, 'Selected' => 1, 'Status' => 'approved',
            'Selection_Policy' => SmsRecordPolicy::RECORDS, 'Approved_At' => '2026-10-21 12:00:00.123456',
            'Approved_At_Precision' => 6, 'Created_By' => 'fixture@example.invalid',
            'Created_At' => '2026-10-20 00:00:00', 'Updated_At' => '2026-10-21 12:00:00'];
        $this->db->table('TblSMSRuns')->insert($values);
        $membership = ['Request_ID' => $id, 'Prospect_PK' => $pk, 'Phone' => $phone, 'Debt_Tier' => 'T2'];
        if ($values['Status'] === 'approved') {
            $this->db->table('TblSMSHistory')->insert($membership + ['Export_Date' => '2026-10-21 12:00:00']);
        } else {
            $this->db->table('TblProspectsExport')->insert($membership);
        }
    }

    private function assertUntouched(int $pk): void
    {
        self::assertSame(0, (int) $this->db->table('TblProspects')->where('PK', $pk)->value('Contacted'));
        self::assertNull($this->db->table('TblProspects')->where('PK', $pk)->value('Contacted_Date'));
        self::assertSame(0, $this->db->table('TblSMSResponseAttribution')->count());
        self::assertSame(0, $this->db->table(SmsAvailabilityLedger::TABLE)->count());
    }

    public function test_atomic_original_event_preserves_exact_time_and_selects_latest_committed_record_membership(): void
    {
        $pk = $this->person(['Opt_Out' => 1, 'Response_Date' => '2026-10-01', 'Last_SMS_Date' => '2026-10-20', 'SMS_Attempts' => 3]);
        $this->member($pk); $this->member($pk, self::OTHER, ['Approved_At' => '2026-10-21 20:00:00.100000']);
        $history = $this->db->table('TblSMSHistory')->orderBy('PK')->get()->toJson();
        $result = $this->writer->record($this->event($pk));
        self::assertSame(['outcome' => 'recorded', 'request_id' => self::OTHER, 'contacted_date' => '2026-10-21'], $result);
        $evidence = (array) $this->db->table('TblSMSResponseAttribution')->first();
        self::assertSame('2026-10-22T06:59:59.999999999Z', $evidence['Contact_At_UTC_Exact']);
        self::assertSame('2026-10-22 06:59:59.9999999', $evidence['Contact_At_UTC']);
        self::assertSame(['fixture.contacts', 'fixture-1', self::SHA, 1], [$evidence['Source_System'], $evidence['Source_Contact_ID'], $evidence['Source_Contract_SHA256'], (int) $evidence['Source_Candidate_Count']]);
        $prospect = $this->db->table('TblProspects')->where('PK', $pk)->first();
        self::assertSame([1, '2026-10-21', '2026-10-01', '2026-10-20', 3, 1],
            [(int) $prospect->Contacted, $prospect->Contacted_Date, $prospect->Response_Date, $prospect->Last_SMS_Date, (int) $prospect->SMS_Attempts, (int) $prospect->Opt_Out]);
        self::assertSame($history, $this->db->table('TblSMSHistory')->orderBy('PK')->get()->toJson());
        self::assertSame([1, 1], array_map('intval', $this->db->table(SmsAvailabilityLedger::TABLE)->pluck('revision')->all()));
        self::assertSame('already_recorded', $this->writer->record($this->event($pk, ['sourceContactId' => 'later-fixture', 'explicitOriginalUtc' => '2026-10-23T10:00:00Z']))['outcome']);
        self::assertSame($evidence, (array) $this->db->table('TblSMSResponseAttribution')->first());
        self::assertSame([1, 1], array_map('intval', $this->db->table(SmsAvailabilityLedger::TABLE)->pluck('revision')->all()));
    }

    public function test_pre_drop_contact_gets_immutable_null_attribution_and_later_drops_cannot_steal_it(): void
    {
        $pk = $this->person();
        self::assertNull($this->writer->record($this->event($pk))['request_id']);
        $this->member($pk);
        $later = $this->writer->record($this->event($pk));
        self::assertSame('already_recorded', $later['outcome']); self::assertNull($later['request_id']);
    }

    public function test_existing_contacted_state_or_date_and_attribution_are_not_rewritten(): void
    {
        foreach ([['Contacted' => 1], ['Contacted_Date' => '2020-01-01']] as $values) {
            $pk = $this->person($values);
            $before = (array) $this->db->table('TblProspects')->where('PK', $pk)->first();
            self::assertSame('already_contacted', $this->writer->record($this->event($pk))['outcome']);
            self::assertSame($before, (array) $this->db->table('TblProspects')->where('PK', $pk)->first());
        }
        self::assertSame(0, $this->db->table('TblSMSResponseAttribution')->count());
        self::assertSame(0, $this->db->table(SmsAvailabilityLedger::TABLE)->count());
    }

    public function test_contract_ambiguity_future_and_current_phone_holds_have_no_business_or_summary_writes(): void
    {
        $pk = $this->person();
        foreach ([['sourceSystem' => 'other.contacts'], ['sourceContractSha256' => str_repeat('b', 64)],
            ['coverageComplete' => false], ['candidateCount' => 2], ['explicitOriginalUtc' => '2026-10-23T12:00:00.500000001Z'],
            ['phone' => '0005550199']] as $values) {
            self::assertSame('held', $this->writer->record($this->event($pk, $values))['outcome']);
            $this->assertUntouched($pk);
        }
    }

    public function test_pending_and_failed_accepted_approvals_hold_until_publication(): void
    {
        foreach ([['Status' => 'approving'], ['Status' => 'failed', 'Failed_Step' => 'approve']] as $i => $state) {
            $pk = $this->person(); $this->member($pk, $i === 0 ? self::ID : self::OTHER, $state);
            self::assertSame('approval_in_progress', $this->writer->record($this->event($pk))['reason']);
            $this->assertUntouched($pk);
        }
    }

    public function test_old_same_second_approval_holds_instead_of_guessing_subsecond_order(): void
    {
        $pk = $this->person(); $this->member($pk, self::ID, ['Approved_At' => '2026-10-22 06:59:59', 'Approved_At_Precision' => null]);
        self::assertSame('held', $this->writer->record($this->event($pk))['outcome']);
        $this->assertUntouched($pk);
    }

    public function test_phone_repair_never_credits_membership_sent_to_a_different_phone(): void
    {
        $pk = $this->person(); $this->member($pk, self::ID, [], '0005550199');
        self::assertNull($this->writer->record($this->event($pk))['request_id']);
    }

    public function test_discarded_and_legacy_history_do_not_qualify_as_committed_record_approval(): void
    {
        $pk = $this->person();
        $this->member($pk, self::ID, ['Selection_Policy' => SmsRecordPolicy::LEGACY]);
        $this->member($pk, self::OTHER); $this->db->table('TblSMSRuns')->where('Request_ID', self::OTHER)->update(['Status' => 'discarded']);
        self::assertNull($this->writer->record($this->event($pk))['request_id']);
    }

    public function test_failed_prospect_update_rolls_back_inserted_attribution_and_summary(): void
    {
        $pk = $this->person();
        $this->db->unprepared("CREATE TRIGGER reject_contact BEFORE UPDATE ON TblProspects BEGIN SELECT RAISE(ABORT,'Synthetic contact failure'); END");
        try { $this->writer->record($this->event($pk)); self::fail('Expected contact update rollback.'); }
        catch (\Illuminate\Database\QueryException) { $this->assertUntouched($pk); }
    }

    public function test_failed_summary_revision_rolls_back_contact_and_attribution(): void
    {
        $pk = $this->person();
        $this->container->instance(SmsAvailabilityLedger::class, new class {
            public function dirty($db): void { throw new RuntimeException('Synthetic revision failure'); }
        });
        try { $this->writer->record($this->event($pk)); self::fail('Expected revision rollback.'); }
        catch (RuntimeException $error) { self::assertSame('Synthetic revision failure', $error->getMessage()); }
        $this->assertUntouched($pk);
    }

    public function test_disabled_missing_source_missing_schema_and_ambient_transaction_fail_closed(): void
    {
        $pk = $this->person();
        foreach (['contact_attribution_enabled' => false, 'contact_source_contract_sha256' => null] as $key => $value) {
            $old = config('sms-exports.'.$key); config(['sms-exports.'.$key => $value]);
            try { $this->writer->record($this->event($pk)); self::fail('Expected activation refusal.'); }
            catch (HttpException $error) { self::assertSame(503, $error->getStatusCode()); }
            config(['sms-exports.'.$key => $old]); $this->assertUntouched($pk);
        }
        try { $this->db->transaction(fn () => $this->writer->record($this->event($pk))); self::fail('Expected ambient transaction refusal.'); }
        catch (\LogicException) { $this->assertUntouched($pk); }
        $this->db->getSchemaBuilder()->table('TblSMSRuns', fn (Blueprint $t) => $t->dropColumn('Approved_At_Precision'));
        $this->expectException(HttpException::class); $this->writer->record($this->event($pk));
    }

    public function test_missing_run_unknown_policy_and_inconsistent_states_hold_instead_of_freezing_null(): void
    {
        $pk = $this->person(); $this->member($pk);
        foreach ([['Selection_Policy' => 'unknown-policy'], ['Status' => 'unknown-status'], ['Status' => 'approving']] as $values) {
            $this->db->table('TblSMSRuns')->update(['Selection_Policy' => SmsRecordPolicy::RECORDS, 'Status' => 'approved']);
            $this->db->table('TblSMSRuns')->update($values);
            self::assertSame('held', $this->writer->record($this->event($pk))['outcome']); $this->assertUntouched($pk);
        }
        $this->db->table('TblSMSRuns')->delete();
        self::assertSame('unproven_membership_metadata', $this->writer->record($this->event($pk))['reason']);
        $this->assertUntouched($pk);
    }

    public function test_inconsistent_pending_metadata_and_membership_limit_hold_without_scanning_full_history(): void
    {
        $pk = $this->person(); $this->member($pk, self::ID, ['Status' => 'file_ready']);
        self::assertSame('unproven_pending_state', $this->writer->record($this->event($pk))['reason']); $this->assertUntouched($pk);
        $this->db->table('TblProspectsExport')->delete(); $this->db->table('TblSMSRuns')->delete();
        $rows = [];
        for ($i = 0; $i <= SmsContactAttribution::MAX_MEMBERSHIPS; $i++) {
            $rows[] = ['Request_ID' => sprintf('%08d-1111-4111-8111-111111111111', $i), 'Prospect_PK' => $pk,
                'Phone' => '0005550100', 'Export_Date' => '2026-10-21 00:00:00'];
        }
        foreach (array_chunk($rows, 100) as $chunk) $this->db->table('TblSMSHistory')->insert($chunk);
        self::assertSame('membership_limit_exceeded', $this->writer->record($this->event($pk))['reason']); $this->assertUntouched($pk);
    }

    public function test_missing_or_wrong_shape_lookup_indexes_fail_activation(): void
    {
        $this->db->statement('DROP INDEX IX_TblSMSHistory_Contact_Attribution');
        foreach ([false, true] as $wrongShape) {
            if ($wrongShape) $this->db->statement('CREATE INDEX IX_TblSMSHistory_Contact_Attribution ON TblSMSHistory (Request_ID,Phone,Prospect_PK)');
            try { SmsContactAttribution::assertReady(); self::fail('Expected reviewed index shape guard.'); }
            catch (HttpException $error) { self::assertSame(503, $error->getStatusCode()); }
        }
    }

    public function test_evidence_migration_preserves_old_nulls_and_refuses_replay_or_partial_schema(): void
    {
        $pk = $this->person(); $this->member($pk, self::ID, ['Approved_At_Precision' => null]);
        self::assertNull($this->db->table('TblSMSRuns')->value('Approved_At_Precision'));
        $migration = require dirname(__DIR__, 2).'/database/sms-migrations/2026_10_23_000001_add_sms_contact_attribution_evidence.php';
        try { $migration->up(); self::fail('Expected installed migration refusal.'); }
        catch (RuntimeException $error) { self::assertStringContainsString('already exists', $error->getMessage()); }
        try { $migration->down(); self::fail('Expected retained evidence.'); }
        catch (RuntimeException $error) { self::assertStringContainsString('Retain', $error->getMessage()); }
        self::assertSame(1, $this->db->table('TblSMSHistory')->count());
        self::assertSame(0, $this->db->table('TblSMSResponseAttribution')->count());
    }

    private function externalHeader(string $state='completed'): void
    {
        (require dirname(__DIR__,2).'/database/sms-migrations/2026_10_22_000001_add_sms_run_file_options.php')->up();
        (require dirname(__DIR__,2).'/database/sms-migrations/2026_10_24_000001_create_sms_external_events.php')->up();
        $this->db->table('TblSMSExternalEvents')->insert(['Request_ID'=>self::ID,'Event_Key'=>'jude-original-sms-2026-10-21',
            'Policy_Version'=>SmsRecordPolicy::EXTERNAL,'State'=>$state,'Occurred_At_UTC'=>'2026-10-21 18:11:15','Occurred_Precision'=>0,
            'Business_Date'=>'2026-10-21','Registered_By'=>'fixture@example.invalid','Started_At_UTC'=>'2026-10-23 00:00:00','Updated_At_UTC'=>'2026-10-23 00:00:00',
            'Completed_At_UTC'=>$state==='completed'?'2026-10-23 00:00:00':null,'Expected_Rows'=>1,'Staged_Rows'=>1,'Applied_Rows'=>$state==='completed'?1:0,
            'Plan_SHA256'=>self::SHA,'Membership_SHA256'=>self::SHA,'Preview_SHA256'=>self::SHA,'Before_State_SHA256'=>self::SHA,'Original_Artifacts_JSON'=>'{}']);
    }

    public function test_external_discarded_history_is_held_instead_of_permanently_freezing_null(): void
    {
        $pk=$this->person(); $this->member($pk,self::ID,['Selection_Policy'=>SmsRecordPolicy::EXTERNAL]);
        $this->db->table('TblSMSRuns')->update(['Status'=>'discarded','Approved_At'=>null,'Approved_At_Precision'=>null]);
        self::assertSame('unproven_external_history',$this->writer->record($this->event($pk))['reason']);
        $this->assertUntouched($pk);
    }

    public function test_pending_external_registration_holds_before_any_member_has_been_staged(): void
    {
        $pk=$this->person(); $this->member($pk,self::ID,['Selection_Policy'=>SmsRecordPolicy::EXTERNAL,'Status'=>'external_registering']);
        $this->externalHeader('held'); $this->db->table('TblProspectsExport')->delete();
        self::assertSame('historical_registration_in_progress',$this->writer->record($this->event($pk))['reason']);
        $this->assertUntouched($pk);
    }

    public function test_completed_external_history_uses_original_occurrence_and_holds_same_second(): void
    {
        $pk=$this->person(); $this->member($pk,self::ID,['Selection_Policy'=>SmsRecordPolicy::EXTERNAL]);
        $this->db->table('TblSMSRuns')->update(['Status'=>'external_sent','Approved_At'=>null,'Approved_At_Precision'=>null]);
        $this->db->table('TblSMSHistory')->update(['Export_Date'=>'2026-10-21 18:11:15']); $this->externalHeader();
        $member=\Cmd\Reports\Services\SmsExternalEventSeal::member(['Prospect_PK'=>$pk,'Original_Phone'=>'0005550100','Original_First_Name'=>'Synthetic',
            'Original_Address'=>'Fixture St','Original_Debt_Text'=>'13000','Original_Send_Date'=>'2026-10-21','Part_Number'=>1,'Part_Row'=>1,
            'Expected_Attempts'=>0,'Expected_Last_SMS_Date'=>null,'Expected_Latest_Export_Date'=>null]);
        $this->db->table('TblSMSExternalMembers')->insert(['Request_ID'=>self::ID,'Applied_Chunk'=>1,'Previous_Attempts'=>0,'Recorded_Attempts'=>1]+$member);
        self::assertSame('historical_event_precision',$this->writer->record($this->event($pk,['explicitOriginalUtc'=>'2026-10-21T18:11:15.123456789Z']))['reason']);
        $this->assertUntouched($pk);
        self::assertSame(self::ID,$this->writer->record($this->event($pk,['explicitOriginalUtc'=>'2026-10-21T18:11:16Z']))['request_id']);
        self::assertNull($this->db->table('TblSMSRuns')->value('Approved_At'));
        self::assertSame('2026-10-21 18:11:15',$this->db->table('TblSMSHistory')->value('Export_Date'));
    }
}
