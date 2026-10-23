<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Carbon\Carbon;
use Cmd\Reports\Repositories\SmsProspectExportRepository;
use Cmd\Reports\Repositories\SmsRecordExportRepository;
use Cmd\Reports\Services\SmsAvailabilityLedger;
use Cmd\Reports\Services\SmsDurableAvailabilityService;
use Cmd\Reports\Services\SmsRecordPolicy;
use Cmd\Reports\Services\SmsRunService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/** In-memory synthetic data and owned temporary files only. No application or external services. */
final class SmsRecordWorkflowTest extends TestCase
{
    private Container $previous;
    private $db;
    private SmsRecordExportRepository $records;
    private SmsRunService $runs;
    private string $directory;
    private const ID = '11111111-2222-4333-8444-555555555555';
    private const OTHER = '22222222-3333-4444-8555-666666666666';

    protected function setUp(): void
    {
        $this->previous = Container::getInstance();
        $container = new Container;
        Container::setInstance($container);
        $this->directory = sys_get_temp_dir().'/sms-record-test-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0700);
        $container->instance('config', new Repository(['app' => ['env' => 'testing', 'url' => 'https://local.invalid'],
            'sms-exports' => ['record_workflow_enabled' => true, 'durable_availability_enabled' => true,
                'local_root' => $this->directory], 'database' => ['default' => 'sqlsrv']]));
        $capsule = new Manager($container);
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:'], 'sqlsrv');
        $capsule->getDatabaseManager()->setDefaultConnection('sqlsrv');
        $container->instance('db', $capsule->getDatabaseManager());
        Facade::clearResolvedInstances(); Facade::setFacadeApplication($container);
        $this->db = $capsule->getConnection('sqlsrv');
        $this->db->setTransactionManager(new DatabaseTransactionsManager);
        $this->db->getSchemaBuilder()->create('TblPhoneNumbers', fn (Blueprint $t) => $t->string('Phone'));
        foreach (['2026_10_12_000001_create_sms_prospects_tables.php', '2026_10_19_000001_complete_sms_workflow.php',
            '2026_10_20_000001_create_sms_availability_summary.php', '2026_10_20_000003_add_sms_record_approval.php'] as $file) {
            (require dirname(__DIR__, 2).'/database/sms-migrations/'.$file)->up();
        }
        $summary = \Mockery::mock(SmsDurableAvailabilityService::class);
        $summary->shouldReceive('request')->andReturn(['state' => 'pending']);
        $container->instance(SmsDurableAvailabilityService::class, $summary);
        $this->records = new SmsRecordExportRepository;
        $container->instance(SmsRecordExportRepository::class, $this->records);
        $export = new class extends SmsProspectExportRepository {
            public function lookupDebts(string $id, array $debts = [], ?callable $progress = null): array
            {
                $this->table('TblProspectsExport')->where('Request_ID', $id)->update(['Debt_Amount' => 10000, 'Debt_Tier' => 'T1', 'Debt_Source' => 'TblTUData']);
                return [];
            }
        };
        $this->runs = new class($export) extends SmsRunService {
            public array $queued = [];
            public bool $interruptCleanup = false;
            protected function dispatch(string $id, string $job): void { $this->queued[] = [$id, $job]; }
            public function cleanupDeletedFile(string $id): void
            {
                if ($this->interruptCleanup) throw new \RuntimeException('Synthetic cleanup interruption.');
                parent::cleanupDeletedFile($id);
            }
        };
        Carbon::setTestNow(Carbon::parse('2026-10-20T00:30:00Z'));
    }

    protected function tearDown(): void
    {
        \Mockery::close(); Carbon::setTestNow();
        // Only this test's freshly generated directory; never follow links.
        if (is_dir($this->directory)) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($iterator as $file) { if ($file->isDir() && ! $file->isLink()) rmdir($file->getPathname()); else unlink($file->getPathname()); }
            rmdir($this->directory);
        }
        Facade::clearResolvedInstances(); Container::setInstance($this->previous); Facade::setFacadeApplication($this->previous);
    }

    private function prospect(array $values = []): int
    {
        return (int) $this->db->table('TblProspects')->insertGetId($values + ['Client' => 'Synthetic Person', 'Address' => '1 Fixture St',
            'City' => 'Test', 'State' => 'CA', 'Zip' => '00123', 'Phone' => '2025550100', 'Creation_Date' => '2026-10-01']);
    }

    private function prepare(int $target = 10): object
    {
        $this->runs->start(self::ID, $target, 'editor@example.invalid');
        $this->runs->prepare(self::ID);
        return $this->runs->show(self::ID);
    }

    private function fileReady(int $target = 10): object
    {
        $this->prepare($target); $this->runs->requestFile(self::ID); $this->runs->buildFile(self::ID);
        return $this->runs->show(self::ID);
    }

    private function refuses(callable $work, int $status = 409): void
    {
        try { $work(); self::fail('Expected record workflow refusal.'); }
        catch (HttpException $error) { self::assertSame($status, $error->getStatusCode()); }
    }

    public function test_record_counts_and_selection_preserve_distinct_pks_sharing_one_phone(): void
    {
        $a = $this->prospect(['Creation_Date' => '2026-10-10']);
        $b = $this->prospect(['Creation_Date' => '2026-10-10', 'Client' => 'Another Person']);
        $this->prospect(['Contacted' => 1]); $this->prospect(['Opt_Out' => 1]); $this->prospect(['Invalid_Phone' => 1]);
        $this->prospect(['Phone' => '12025550100']); $this->prospect(['Phone' => '20255501x0']); $this->prospect(['Phone' => null]);
        self::assertSame(['eligible' => 2, 'never_texted' => 2, 'texted_before' => 0], $this->records->availability());
        $run = $this->prepare(5);
        self::assertSame(SmsRecordPolicy::RECORDS, $run->selection_policy);
        self::assertSame([2, 2, 0, 3], [$run->selected, $run->never_texted, $run->previously_texted, $run->shortfall]);
        self::assertSame([$a,$b], array_map('intval', $this->db->table('TblProspectsExport')->orderBy('PK')->pluck('Prospect_PK')->all()));
        self::assertSame(['eligible' => 2, 'never_texted' => 2, 'texted_before' => 0], $this->records->availability(), 'Pending membership does not change eligibility.');
        self::assertSame(0, $this->db->table('TblSMSHistory')->count());
        self::assertSame(0, (int) $this->db->table('TblProspects')->sum('SMS_Attempts'));
    }

    public function test_selection_uses_source_debt_tier_without_a_live_tier_column(): void
    {
        $this->prospect();
        $this->prepare(1);
        self::assertSame('T1', $this->db->table('TblProspectsExport')->value('Debt_Tier'));
        self::assertFalse($this->db->getSchemaBuilder()->hasColumn('TblProspects', 'Debt_Tier'));
        self::assertFalse($this->db->getSchemaBuilder()->hasColumn('TblProspects', 'Tier'));
    }

    public function test_order_uses_creation_only_for_never_texted_then_date_and_pk_for_previous(): void
    {
        $old = $this->prospect(['Creation_Date' => '2025-01-01']);
        $new = $this->prospect(['Creation_Date' => '2026-10-10']);
        $previousA = $this->prospect(['Last_SMS_Date' => '2026-09-01', 'Creation_Date' => '2020-01-01']);
        $previousB = $this->prospect(['Last_SMS_Date' => '2026-09-01', 'Creation_Date' => '2026-10-10']);
        $later = $this->prospect(['Last_SMS_Date' => '2026-10-01']);
        $this->prepare(4);
        self::assertSame([$new,$old,$previousA,$previousB], array_map('intval', $this->db->table('TblProspectsExport')->orderBy('PK')->pluck('Prospect_PK')->all()));
        self::assertFalse($this->db->table('TblProspectsExport')->where('Prospect_PK', $later)->exists());
        self::assertSame([2,2], [$this->runs->show(self::ID)->never_texted, $this->runs->show(self::ID)->previously_texted]);
    }

    public function test_authoritative_suppressions_exclude_zero_flag_records_before_admission(): void
    {
        // Feedback can precede a legacy loader that inserts prospects with all flags still zero.
        $this->db->table('TblSMSSuppressions')->insert([
            ['Phone' => '2025550100', 'Opt_Out' => 1, 'Invalid_Phone' => 0, 'Updated_At' => '2026-10-01'],
            ['Phone' => '2025550101', 'Opt_Out' => 0, 'Invalid_Phone' => 1, 'Updated_At' => '2026-10-01'],
            ['Phone' => '2025550102', 'Opt_Out' => 1, 'Invalid_Phone' => 1, 'Updated_At' => '2026-10-01'],
            ['Phone' => '2025550110', 'Opt_Out' => 0, 'Invalid_Phone' => 0, 'Updated_At' => '2026-10-01'],
        ]);
        foreach (['2025550100', '2025550100', '2025550101', '2025550102'] as $phone) {
            $this->prospect(['Phone' => $phone, 'Creation_Date' => '2026-10-19']);
            $this->prospect(['Phone' => $phone, 'Last_SMS_Date' => '2026-01-01']);
        }
        $never = $this->prospect(['Phone' => '2025550110', 'Creation_Date' => '2025-01-01']);
        $previous = $this->prospect(['Phone' => '2025550111', 'Last_SMS_Date' => '2026-09-01']);
        $before = $this->db->table('TblProspects')->orderBy('PK')->get()->all();

        self::assertSame(['eligible' => 2, 'never_texted' => 1, 'texted_before' => 1], $this->records->availability());
        $run = $this->prepare(3);
        self::assertSame([2, 1, 1, 1], [$run->selected, $run->never_texted, $run->previously_texted, $run->shortfall]);
        self::assertSame([$never, $previous], array_map('intval', $this->db->table('TblProspectsExport')->orderBy('PK')->pluck('Prospect_PK')->all()));
        self::assertEquals($before, $this->db->table('TblProspects')->orderBy('PK')->get()->all());
    }

    public function test_synced_contacts_exclude_zero_flag_records_using_existing_phone_normalization(): void
    {
        $this->db->table('TblPhoneNumbers')->insert([
            ['Phone' => '2025550100'], ['Phone' => '2025550100'], ['Phone' => '12025550101'],
            // Existing contact normalization strips only an eleven-digit leading 1.
            ['Phone' => '22025550110'], ['Phone' => '+1 (202) 555-0110'], ['Phone' => '112025550111'],
        ]);
        $this->prospect(['Phone' => '2025550100', 'Creation_Date' => '2026-10-19']);
        $this->prospect(['Phone' => '2025550101', 'Creation_Date' => '2026-10-19']);
        $this->prospect(['Phone' => '2025550101', 'Last_SMS_Date' => '2026-01-01']);
        $first = $this->prospect(['Phone' => '2025550110', 'Creation_Date' => '2025-01-01']);
        $second = $this->prospect(['Phone' => '2025550110', 'Creation_Date' => '2025-01-01']);
        $previous = $this->prospect(['Phone' => '2025550111', 'Last_SMS_Date' => '2026-09-01']);
        $before = $this->db->table('TblProspects')->orderBy('PK')->get()->all();

        self::assertSame(['eligible' => 3, 'never_texted' => 2, 'texted_before' => 1], $this->records->availability());
        $run = $this->prepare(4);
        self::assertSame([3, 2, 1, 1], [$run->selected, $run->never_texted, $run->previously_texted, $run->shortfall]);
        self::assertSame([$first, $second, $previous], array_map('intval', $this->db->table('TblProspectsExport')->orderBy('PK')->pluck('Prospect_PK')->all()));
        self::assertEquals($before, $this->db->table('TblProspects')->orderBy('PK')->get()->all());
    }

    public function test_new_last_sms_date_does_not_inherit_old_export_assumptions(): void
    {
        $this->prospect(['Latest_Export_Date' => '2026-10-08', 'SMS_Attempts' => 8]);
        $run = $this->prepare(1);
        self::assertSame(1, $run->never_texted); self::assertSame(0, $run->previously_texted);
        $p = $this->db->table('TblProspects')->first();
        self::assertNull($p->Last_SMS_Date); self::assertSame('2026-10-08', $p->Latest_Export_Date); self::assertSame(8, (int) $p->SMS_Attempts);
    }

    public function test_single_pending_batch_is_enforced_and_same_start_is_idempotent(): void
    {
        $this->prospect();
        $this->runs->start(self::ID, 1, 'editor@example.invalid');
        $this->runs->start(self::ID, 1, 'editor@example.invalid');
        self::assertCount(1, $this->runs->queued);
        $this->refuses(fn () => $this->runs->start(self::OTHER, 1, 'editor@example.invalid'));
        $this->refuses(fn () => $this->runs->start(self::ID, 2, 'editor@example.invalid'));
    }

    public function test_five_column_file_keeps_rounded_snapshot_and_ready_file_is_not_rebuilt(): void
    {
        config(['sms-exports.single_phone_csv' => true]);
        $this->prospect(); $this->prepare(1);
        $this->db->table('TblProspectsExport')->update(['Debt_Amount' => 14000]);
        $this->runs->requestFile(self::ID); $this->runs->buildFile(self::ID);
        $path = $this->runs->filePath(self::ID);
        $before = hash_file('sha256', $path);
        $stream = fopen($path, 'rb');
        try {
            self::assertSame(['First name','address','debt load','phone1','send date'], fgetcsv($stream, escape: ''));
            $row = fgetcsv($stream, escape: '');
            self::assertCount(5, $row); self::assertSame('14000', $row[2]);
            self::assertSame('2026-10-26', $row[4]);
        } finally { fclose($stream); }
        config(['sms-exports.single_phone_csv' => false]);
        Carbon::setTestNow(Carbon::parse('2026-11-04T12:00:00Z'));
        $this->runs->buildFile(self::ID);
        self::assertSame($before, hash_file('sha256', $this->runs->filePath(self::ID)));
    }

    public function test_generation_in_a_later_week_requires_new_batch_instead_of_rewriting_review(): void
    {
        $this->prospect(); $this->prepare(1);
        $this->runs->requestFile(self::ID);
        Carbon::setTestNow(Carbon::parse('2026-11-04T12:00:00Z'));
        try { $this->runs->buildFile(self::ID); self::fail('Stale planned Monday was exported.'); }
        catch (\RuntimeException $e) { self::assertStringContainsString('Start a fresh batch', $e->getMessage()); }
        self::assertSame('2026-10-26', $this->db->table('TblSMSDrops')->value('Drop_Date'));
        self::assertNull($this->db->table('TblSMSRuns')->value('File_Path'));
    }

    public function test_old_exact_debt_snapshot_cannot_silently_diverge_from_the_generated_file(): void
    {
        $this->prospect(); $this->prepare(1);
        $this->db->table('TblProspectsExport')->update(['Debt_Amount' => '13500.01']);
        $this->runs->requestFile(self::ID);
        try { $this->runs->buildFile(self::ID); self::fail('Old exact debt was exported.'); }
        catch (\RuntimeException $e) { self::assertStringContainsString('does not reconcile', $e->getMessage()); }
        self::assertNull($this->db->table('TblSMSRuns')->value('File_Path'));
        self::assertSame('13500.01', (string) $this->db->table('TblProspectsExport')->value('Debt_Amount'));
    }

    public function test_approval_updates_only_selected_ids_uses_acceptance_date_and_is_immutable(): void
    {
        $selected = $this->prospect(['Creation_Date' => '2026-10-10']);
        $unselected = $this->prospect(['Creation_Date' => '2025-01-01']);
        $this->fileReady(1);
        $this->runs->requestApprove(self::ID, 'approver@example.invalid');
        $at = $this->db->table('TblSMSRuns')->value('Approved_At');
        $this->runs->requestApprove(self::ID, 'other@example.invalid');
        self::assertSame(1, count(array_filter($this->runs->queued, fn ($entry) => $entry[1] === 'approve')));
        // A slow queue must not change the business approval date.
        Carbon::setTestNow(Carbon::parse('2026-10-22T12:00:00Z'));
        $this->runs->markApproved(self::ID); $this->runs->markApproved(self::ID);
        self::assertSame('2026-10-19', $this->db->table('TblProspects')->where('PK', $selected)->value('Last_SMS_Date'));
        self::assertNull($this->db->table('TblProspects')->where('PK', $unselected)->value('Last_SMS_Date'));
        self::assertSame([1,0], array_map('intval', $this->db->table('TblProspects')->orderBy('PK')->pluck('SMS_Attempts')->all()));
        self::assertSame(1, $this->db->table('TblSMSHistory')->count()); self::assertSame(0, $this->db->table('TblProspectsExport')->count());
        self::assertSame(0, $this->db->table('TblSMSLegacySends')->count(), 'Record approval must not invent phone-level sends.');
        self::assertSame('approved', $this->runs->show(self::ID)->status);
        self::assertSame($at, $this->db->table('TblSMSRuns')->value('Approved_At'));
        self::assertSame('2026-10-26', $this->db->table('TblSMSDrops')->value('Drop_Date'), 'Approval must not replace the CSV planned Monday with its acceptance date.');
        self::assertSame('approver@example.invalid', $this->db->table('TblSMSRuns')->value('Approved_By'));
        foreach ([fn () => $this->runs->discard(self::ID), fn () => $this->runs->requestSent(self::ID, 'actor'),
            fn () => $this->runs->reviewOnly(self::ID, 'actor'), fn () => $this->runs->requestNotSent(self::ID, 'actor', 'A sufficiently long correction reason')] as $action) $this->refuses($action);
        self::assertFalse($this->runs->show(self::ID)->can_correct_send);
        self::assertNull($this->runs->overview()['open']); self::assertSame('approved', $this->runs->overview()['recent'][0]->status);
    }

    public function test_confirmed_j4_uses_california_acceptance_day_not_monday_and_freezes_microseconds(): void
    {
        (require dirname(__DIR__, 2).'/database/sms-migrations/2026_10_23_000001_add_sms_contact_attribution_evidence.php')->up();
        config(['sms-exports.contact_attribution_enabled' => true, 'sms-exports.contact_source_system' => 'fixture.contacts',
            'sms-exports.contact_source_contract_sha256' => str_repeat('a', 64)]);
        $pk = $this->prospect(); $this->fileReady(1);
        $bytes = file_get_contents($this->runs->filePath(self::ID));
        Carbon::setTestNow(Carbon::parse('2026-10-22T06:59:59.987654Z'));
        $this->runs->requestApprove(self::ID, 'first@example.invalid');
        self::assertSame('2026-10-22 06:59:59.987654', $this->db->table('TblSMSRuns')->value('Approved_At'));
        self::assertSame(6, (int) $this->db->table('TblSMSRuns')->value('Approved_At_Precision'));
        Carbon::setTestNow(Carbon::parse('2026-10-24T12:00:00Z'));
        $this->runs->requestApprove(self::ID, 'later@example.invalid');
        $this->runs->markApproved(self::ID); $this->runs->markApproved(self::ID);
        self::assertSame('2026-10-21', $this->db->table('TblProspects')->where('PK', $pk)->value('Last_SMS_Date'));
        self::assertSame('2026-10-26', $this->db->table('TblSMSDrops')->value('Drop_Date'));
        self::assertSame('2026-10-22 06:59:59.987654', $this->db->table('TblSMSRuns')->value('Approved_At'));
        self::assertSame('first@example.invalid', $this->db->table('TblSMSRuns')->value('Approved_By'));
        self::assertSame($bytes, file_get_contents($this->runs->filePath(self::ID)));
        self::assertSame(1, (int) $this->db->table('TblProspects')->value('SMS_Attempts'));
        self::assertNull($this->db->table('TblProspects')->value('Contacted_Date'));
    }

    public function test_frozen_file_and_approval_do_not_recheck_live_eligibility(): void
    {
        $pk = $this->prospect(['Client' => 'Original Name', 'Address' => 'Original Address']);
        $this->prepare(1);
        $this->db->table('TblSMSSuppressions')->insert(['Phone' => '2025550100', 'Opt_Out' => 1,
            'Invalid_Phone' => 1, 'Updated_At' => '2026-10-20']);
        $this->db->table('TblPhoneNumbers')->insert(['Phone' => '12025550100']);
        $this->db->table('TblProspects')->where('PK', $pk)->update(['Client' => 'Changed Name', 'Address' => 'Changed Address',
            'Contacted' => 1, 'Opt_Out' => 1, 'Invalid_Phone' => 1, 'Phone' => '2025550199']);
        $this->runs->requestFile(self::ID); $this->runs->buildFile(self::ID);
        $text = file_get_contents($this->runs->filePath(self::ID));
        self::assertStringContainsString('Original', $text); self::assertStringContainsString('Original Address', $text);
        self::assertStringNotContainsString('Changed', $text); self::assertStringContainsString('2025550100', $text);
        $this->runs->requestApprove(self::ID, 'actor'); $this->runs->markApproved(self::ID);
        $history = $this->db->table('TblSMSHistory')->first();
        self::assertSame('Original Name', $history->Client); self::assertSame('T1', $history->Debt_Tier); self::assertSame('2025550100', $history->Phone);
        self::assertSame(1, (int) $this->db->table('TblProspects')->value('Opt_Out'));
    }

    public function test_history_failure_rolls_back_prospect_history_summary_and_status_together(): void
    {
        $this->prospect(); $this->fileReady(1); $this->runs->requestApprove(self::ID, 'actor');
        $this->db->unprepared("CREATE TRIGGER reject_record_history BEFORE INSERT ON TblSMSHistory BEGIN SELECT RAISE(ABORT,'synthetic history failure'); END");
        try { $this->runs->markApproved(self::ID); self::fail('Expected synthetic failure.'); } catch (\Illuminate\Database\QueryException) {}
        self::assertSame('approving', $this->runs->show(self::ID)->status);
        self::assertNull($this->db->table('TblProspects')->value('Last_SMS_Date'));
        self::assertSame(0, (int) $this->db->table('TblProspects')->value('SMS_Attempts'));
        self::assertSame(1, $this->db->table('TblProspectsExport')->count()); self::assertSame(0, $this->db->table('TblSMSHistory')->count());
        self::assertSame(0, $this->db->table(SmsAvailabilityLedger::TABLE)->count());
        $this->db->unprepared('DROP TRIGGER reject_record_history');
        $this->runs->fail(self::ID, 'approve', 'synthetic failure');
        $this->runs->retry(self::ID); $this->runs->markApproved(self::ID);
        self::assertSame('approved', $this->runs->show(self::ID)->status);
    }

    public function test_delete_removes_pending_file_and_membership_without_a_send_or_history(): void
    {
        $this->prospect(); $this->fileReady(1);
        $path = $this->runs->filePath(self::ID);
        $deleted = $this->runs->discard(self::ID);
        self::assertSame('discarded', $deleted->status); self::assertFalse(is_file($path)); self::assertFalse(is_file($path.'.delete-pending'));
        self::assertFalse($deleted->file_cleanup_pending);
        self::assertSame(0, $this->db->table('TblProspectsExport')->count()); self::assertSame(0, $this->db->table('TblSMSDrops')->count());
        self::assertSame(0, $this->db->table('TblSMSHistory')->count()); self::assertNull($this->db->table('TblProspects')->value('Last_SMS_Date'));
        self::assertSame(0, (int) $this->db->table('TblProspects')->value('SMS_Attempts'));
        self::assertSame('discarded', $this->runs->discard(self::ID)->status);
        $this->refuses(fn () => $this->runs->requestApprove(self::ID, 'actor'));
        $this->refuses(fn () => $this->runs->filePath(self::ID), 404);
    }

    public function test_delete_sql_failure_restores_the_quarantined_file(): void
    {
        $this->prospect(); $this->fileReady(1); $path = $this->runs->filePath(self::ID);
        $this->db->unprepared("CREATE TRIGGER reject_record_delete BEFORE DELETE ON TblProspectsExport BEGIN SELECT RAISE(ABORT,'synthetic delete failure'); END");
        try { $this->runs->discard(self::ID); self::fail('Expected synthetic failure.'); } catch (\Illuminate\Database\QueryException) {}
        self::assertSame('file_ready', $this->runs->show(self::ID)->status);
        self::assertFileExists($path); self::assertFalse(is_file($path.'.delete-pending'));
        self::assertSame(1, $this->db->table('TblProspectsExport')->count());
    }

    public function test_approval_wins_delete_race_and_prevents_deleting_a_failed_approval(): void
    {
        $this->prospect(); $this->fileReady(1); $this->runs->requestApprove(self::ID, 'actor');
        $this->refuses(fn () => $this->runs->discard(self::ID));
        $this->runs->fail(self::ID, 'approve', 'synthetic failure');
        $this->refuses(fn () => $this->runs->discard(self::ID));
        self::assertSame(1, $this->db->table('TblProspectsExport')->count());
    }

    public function test_delete_recovers_a_quarantine_left_before_sql_commit(): void
    {
        $this->prospect(); $this->fileReady(1); $path = $this->runs->filePath(self::ID);
        self::assertTrue(rename($path, $path.'.delete-pending'));
        self::assertSame('file_ready', $this->runs->show(self::ID)->status);
        $deleted = $this->runs->discard(self::ID);
        self::assertSame('discarded', $deleted->status);
        self::assertFalse($deleted->file_cleanup_pending);
        self::assertFileDoesNotExist($path.'.delete-pending');
        self::assertSame(0, $this->db->table('TblProspectsExport')->count());
        self::assertNull($this->db->table('TblProspects')->value('Last_SMS_Date'));
    }

    public function test_committed_delete_retains_cleanup_receipt_and_denies_old_downloads_until_retry(): void
    {
        $this->prospect(); $this->fileReady(1); $path = $this->runs->filePath(self::ID);
        $this->runs->interruptCleanup = true;
        $deleted = $this->runs->discard(self::ID);
        self::assertSame('discarded', $deleted->status); self::assertTrue($deleted->file_cleanup_pending);
        self::assertFileExists($path.'.delete-pending');
        self::assertSame(0, $this->db->table('TblProspectsExport')->count());
        $this->refuses(fn () => $this->runs->filePath(self::ID), 404);
        $this->runs->interruptCleanup = false;
        $this->runs->discard(self::ID);
        self::assertFalse($this->runs->show(self::ID)->file_cleanup_pending);
        self::assertFileDoesNotExist($path.'.delete-pending');
        self::assertSame(0, $this->db->table('TblSMSHistory')->count());
    }

    public function test_conflicting_quarantine_does_not_overwrite_either_file_or_delete_membership(): void
    {
        $this->prospect(); $this->fileReady(1); $path = $this->runs->filePath(self::ID);
        copy($path, $path.'.delete-pending'); $hash = hash_file('sha256', $path);
        try { $this->runs->discard(self::ID); self::fail('Expected conflicting quarantine refusal.'); }
        catch (\RuntimeException $error) { self::assertStringContainsString('conflicting quarantine', $error->getMessage()); }
        self::assertSame($hash, hash_file('sha256', $path));
        self::assertSame($hash, hash_file('sha256', $path.'.delete-pending'));
        self::assertSame('file_ready', $this->runs->show(self::ID)->status);
        self::assertSame(1, $this->db->table('TblProspectsExport')->count());
    }

    public function test_report_uses_frozen_tiers_and_does_not_invent_unimplemented_response_metrics(): void
    {
        $a = $this->prospect(); $b = $this->prospect();
        $this->prepare(2);
        $this->db->table('TblProspectsExport')->update(['Debt_Tier' => 'T2']);
        $this->db->table('TblSMSDrops')->update(['Debt_Tier' => 'T2']);
        $this->runs->requestFile(self::ID); $this->runs->buildFile(self::ID);
        $this->runs->requestApprove(self::ID, 'actor'); $this->runs->markApproved(self::ID);
        $this->db->table('TblProspects')->update(['Contacted' => 1,'Opt_Out' => 1,'Invalid_Phone' => 1]);
        $this->db->table('TblSMSResponseAttribution')->insert(['Prospect_PK' => $a, 'Request_ID' => self::ID,
            'Contact_At_UTC' => '2026-10-21 12:00:00', 'Contacted_Date' => '2026-10-21', 'Created_At' => '2026-10-21 13:00:00']);
        $report = $this->runs->details(self::ID);
        self::assertSame('Live Status', $report['reporting']); self::assertCount(9, $report['tiers']);
        self::assertSame(['tier' => 'T2','records' => 2,'opt_out' => 2,'invalid' => 2,'contacted' => null,'response_rate' => null], $report['tiers'][1]);
        self::assertNull($report['tiers'][0]['response_rate']); self::assertSame(0, $report['tiers'][8]['records']);
        self::assertSame(2, $report['total']['records']); self::assertNull($report['total']['response_rate']);
        self::assertFalse($report['response_metrics_available']); self::assertStringContainsString('not implemented', $report['response_metrics_reason']);
        // A prospect disappearing must not change the original denominator.
        $this->db->table('TblProspects')->where('PK', $b)->delete();
        self::assertSame(2, $this->runs->details(self::ID)['total']['records']);
    }

    public function test_legacy_actions_stay_blocked_for_record_batches_when_feature_is_turned_off(): void
    {
        $this->prospect(); $this->fileReady(1);
        config(['sms-exports.record_workflow_enabled' => false]);
        $this->refuses(fn () => $this->runs->requestSent(self::ID, 'actor'));
        $this->refuses(fn () => $this->runs->requestApprove(self::ID, 'actor'), 503);
        self::assertSame('file_ready', $this->runs->show(self::ID)->status);
    }

    public function test_record_scope_cannot_reuse_phone_based_summary_counts(): void
    {
        $ledger = new SmsAvailabilityLedger;
        $recordScope = $ledger->scope();
        config(['sms-exports.record_workflow_enabled' => false]);
        self::assertNotSame($recordScope, $ledger->scope());
    }

    public function test_post_commit_listener_failure_does_not_restore_a_committed_deleted_file(): void
    {
        $this->prospect(); $this->fileReady(1); $path = $this->runs->filePath(self::ID);
        $events = new \Illuminate\Events\Dispatcher;
        $events->listen(\Illuminate\Database\Events\TransactionCommitted::class,
            fn () => throw new \RuntimeException('Synthetic after-commit failure.'));
        $this->db->setEventDispatcher($events);
        try { $this->runs->discard(self::ID); self::fail('Expected post-commit listener failure.'); }
        catch (\RuntimeException $error) { self::assertSame('Synthetic after-commit failure.', $error->getMessage()); }
        finally { $this->db->unsetEventDispatcher(); }
        self::assertSame('discarded', $this->runs->show(self::ID)->status);
        self::assertFileDoesNotExist($path); self::assertFileExists($path.'.delete-pending');
        self::assertTrue($this->runs->show(self::ID)->file_cleanup_pending);
        $this->runs->discard(self::ID);
        self::assertFileDoesNotExist($path); self::assertFileDoesNotExist($path.'.delete-pending');
        self::assertFalse($this->runs->show(self::ID)->file_cleanup_pending);
    }

    public function test_http_approval_requires_explicit_confirmation_and_authenticated_actor(): void
    {
        $this->prospect(); $this->fileReady(1);
        app()->instance('validator', new \Illuminate\Validation\Factory(
            new \Illuminate\Translation\Translator(new \Illuminate\Translation\ArrayLoader, 'en')));
        $controller = new \Cmd\Reports\Http\Controllers\SmsRunController($this->runs);
        foreach ([[], ['confirmed_approval' => false], ['confirmed_approval' => 'no']] as $body) {
            $request = \Illuminate\Http\Request::create('/approve', 'POST', $body);
            $request->attributes->set('cmd_user', ['email' => 'editor@example.invalid']);
            try { $controller->approve($request, self::ID); self::fail('Confirmation must be required.'); }
            catch (\Illuminate\Validation\ValidationException $error) { self::assertArrayHasKey('confirmed_approval', $error->errors()); }
        }
        $request = \Illuminate\Http\Request::create('/approve', 'POST', ['confirmed_approval' => true]);
        $this->refuses(fn () => $controller->approve($request, self::ID), 401);
        self::assertSame('file_ready', $this->runs->show(self::ID)->status);
        $request->attributes->set('cmd_user', ['email' => 'editor@example.invalid']);
        self::assertSame(202, $controller->approve($request, self::ID)->getStatusCode());
        self::assertSame('approving', $this->runs->show(self::ID)->status);
        self::assertSame('editor@example.invalid', $this->db->table('TblSMSRuns')->value('Approved_By'));
    }
}
