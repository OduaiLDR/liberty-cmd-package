<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Services\SmsProspectBackfill;
use Cmd\Reports\Tests\Support\SmsBackfillSqlFixture;
use PDO;
use PHPUnit\Framework\TestCase;
use SmsProspectCoverage;
use SmsProspectReconciliation;
use Throwable;

require_once dirname(__DIR__, 2).'/scripts/sms-prospect-coverage.php';

/** One reusable disposable database, hard-wired to the existing loopback SQL Server fixture. */
final class SmsProspectBackfillSqlServerTest extends TestCase
{
    private static ?SmsBackfillSqlFixture $fixture = null;
    private const RUN = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';
    private const OTHER_RUN = 'bbbbbbbb-cccc-4ddd-8eee-ffffffffffff';

    public static function setUpBeforeClass(): void
    {
        if (! in_array('sqlsrv', PDO::getAvailableDrivers(), true)) self::markTestSkipped('Requires real PDO SQL Server.');
        self::$fixture = new SmsBackfillSqlFixture();
        try { SmsProspectBackfill::install(self::$fixture->db); }
        catch (Throwable $error) { self::$fixture->close(); throw $error; }
    }

    public static function tearDownAfterClass(): void
    {
        self::$fixture?->close();
        self::$fixture = null;
    }

    protected function setUp(): void
    {
        self::$fixture->reset();
    }

    private function begin(array $ids, bool $reviewed = true): array
    {
        [$proof, $candidates] = self::$fixture->plan($ids);
        SmsProspectBackfill::begin(self::$fixture->db, self::RUN, $proof, 'local-fixture@example.invalid', $reviewed);
        return [$proof, $candidates];
    }

    private function refuses(callable $work, string $message): Throwable
    {
        try { $work(); }
        catch (Throwable $error) {
            self::assertStringContainsString($message, $error->getMessage());
            self::assertFalse(self::$fixture->db->inTransaction(), 'A failed chunk must release its transaction.');
            return $error;
        }
        self::fail('Expected backfill refusal containing: '.$message);
    }

    private function assertUnchanged(array $business, array $ledger): void
    {
        self::assertSame($business, self::$fixture->snapshot());
        self::assertSame($ledger, self::$fixture->ledger());
    }

    public function test_install_is_additive_and_refuses_existing_or_partial_ledgers(): void
    {
        $f = self::$fixture;
        $f->assertOwnedDatabase();
        $f->db->exec('DROP TABLE dbo.TblSMSProspectBackfillRows; DROP TABLE dbo.TblSMSProspectBackfillBatches; DROP TABLE dbo.TblSMSProspectBackfillRuns');
        $business = $f->snapshot();
        $objects = $f->rows('SELECT object_id,name FROM sys.tables ORDER BY object_id');
        SmsProspectBackfill::install($f->db);
        self::assertSame($business, $f->snapshot());
        self::assertSame($objects, $f->rows("SELECT object_id,name FROM sys.tables WHERE name NOT LIKE 'TblSMSProspectBackfill%' ORDER BY object_id"));
        self::assertCount(count($objects) + 3, $f->rows('SELECT object_id FROM sys.tables'));
        $this->begin([]);
        $ledger = $f->ledger();
        $this->refuses(fn () => SmsProspectBackfill::install($f->db), 'already exists or is partially installed');
        $this->assertUnchanged($business, $ledger);

        $f->db->exec('DROP TABLE dbo.TblSMSProspectBackfillRows; DROP TABLE dbo.TblSMSProspectBackfillBatches; DROP TABLE dbo.TblSMSProspectBackfillRuns');
        $f->db->exec('CREATE TABLE dbo.TblSMSProspectBackfillRows(Marker int PRIMARY KEY); INSERT dbo.TblSMSProspectBackfillRows VALUES(73)');
        try {
            $this->refuses(fn () => SmsProspectBackfill::install($f->db), 'already exists or is partially installed');
            self::assertSame(73, (int) $f->rows('SELECT Marker FROM dbo.TblSMSProspectBackfillRows')[0]['Marker']);
            self::assertSame([['runs' => null, 'batches' => null]], $f->rows("SELECT OBJECT_ID('dbo.TblSMSProspectBackfillRuns') AS runs,OBJECT_ID('dbo.TblSMSProspectBackfillBatches') AS batches"));
            self::assertSame($business, $f->snapshot());
        } finally {
            $f->db->exec('DROP TABLE dbo.TblSMSProspectBackfillRows');
            SmsProspectBackfill::install($f->db);
        }
    }

    public function test_phone_and_email_only_inserts_use_verified_history_mailer_date_and_lineage(): void
    {
        $f = self::$fixture;
        [, $candidates] = $this->begin([1, 2]);
        $sourceAndHistory = $f->snapshot(false);
        $sentinel = $f->rows('SELECT * FROM dbo.TblProspects WHERE PK=100');
        self::assertSame(['after_id' => 0, 'through_id' => 2, 'checked' => 2, 'added' => 2,
            'existing' => 0, 'deferred' => 0, 'replayed' => false], SmsProspectBackfill::applyBatch($f->db, self::RUN, 0, $candidates));
        $rows = $f->rows('SELECT * FROM dbo.TblProspects WHERE PK>100 ORDER BY Client');
        self::assertCount(2, $rows);
        [$phone, $email] = $rows;
        self::assertSame('2025550101', $phone['Phone']);
        self::assertNull($phone['Email']);
        // One anonymous day, one mirrored actual run, one history-only run, one ledger-only run.
        self::assertSame(4, (int) $phone['SMS_Attempts']);
        self::assertSame('2026-10-06', $phone['Latest_Export_Date']);
        self::assertSame([1, 1, 1], array_map('intval', [$phone['Contacted'], $phone['Opt_Out'], $phone['Invalid_Phone']]));
        self::assertNull($email['Phone']);
        self::assertSame('Mail.Only@example.test', $email['Email']);
        self::assertSame(0, (int) $email['SMS_Attempts']);
        self::assertNull($email['Latest_Export_Date']);
        self::assertSame([0, 0, 0], array_map('intval', [$email['Contacted'], $email['Opt_Out'], $email['Invalid_Phone']]));
        foreach ($rows as $row) {
            self::assertSame('2026-09-12', $row['Creation_Date'], 'MAX mailer Send_Date, never the SMS marketing/export date.');
            self::assertArrayNotHasKey('Debt_Tier', $row);
            self::assertSame('00123', $row['Zip']);
            self::assertSame('Test City', $row['City']);
            self::assertSame('CA', $row['State']);
            self::assertNull($row['Response_Date']);
        }
        $receipts = $f->rows('SELECT * FROM dbo.TblSMSProspectBackfillRows ORDER BY Source_PK');
        foreach ($receipts as $i => $receipt) {
            self::assertSame('inserted', $receipt['Decision']);
            self::assertSame(1001 + $i, (int) $receipt['Unique_Source_PK']);
            self::assertSame((int) $rows[$i]['PK'], (int) $receipt['Prospect_PK']);
            self::assertSame($candidates[$i]['Observed_SHA256'], $receipt['Source_SHA256']);
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/iD', $receipt['After_SHA256']);
            self::assertNotNull($receipt['History_SHA256'], 'Inserted source '.$candidates[$i]['Source_PK'].' must retain its history evidence digest.');
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/iD', $receipt['History_SHA256']);
        }
        self::assertSame($sourceAndHistory, $f->snapshot(false));
        self::assertSame($sentinel, $f->rows('SELECT * FROM dbo.TblProspects WHERE PK=100'));
        self::assertSame(11, (int) $f->rows('SELECT revision FROM dbo.TblSMSAvailabilitySummary')[0]['revision']);
    }

    public function test_existing_after_audit_watermark_matches_case_insensitively_and_stays_unchanged(): void
    {
        $f = self::$fixture;
        [$proof, $candidates] = $this->begin([3]);
        self::assertSame(100, $proof['prospect_watermark_pk']);
        $pk = $f->rows("INSERT dbo.TblProspects(Client,Address,Zip,Phone,Email,Creation_Date,Contacted,Opt_Out,Invalid_Phone,Response_Date,Latest_Export_Date,SMS_Attempts)
            OUTPUT INSERTED.PK VALUES('CASE PERSON','3 TEST ST','00123','2025550198','keep@example.test','2020-01-01',1,1,1,'2026-01-01','2026-01-02',23)")[0]['PK'];
        self::assertGreaterThan(100, (int) $pk);
        self::assertSame($proof['source_guard'], SmsProspectCoverage::sourceGuard(SmsProspectCoverage::metadata($f->db)));
        $business = $f->snapshot();
        $result = SmsProspectBackfill::applyBatch($f->db, self::RUN, 0, $candidates);
        self::assertSame([0, 1, 0], [$result['added'], $result['existing'], $result['deferred']]);
        self::assertSame($business, $f->snapshot());
        self::assertSame([['Prospect_PK' => $pk, 'Decision' => 'existing', 'After_SHA256' => null]],
            $f->rows('SELECT Prospect_PK,Decision,After_SHA256 FROM dbo.TblSMSProspectBackfillRows'));
        self::assertSame(10, (int) $f->rows('SELECT revision FROM dbo.TblSMSAvailabilitySummary')[0]['revision']);
    }

    public function test_leading_zero_zip_is_preserved_and_not_equal_to_short_zip(): void
    {
        $f = self::$fixture;
        [, $candidates] = $this->begin([3]);
        $f->db->exec("INSERT dbo.TblProspects(Client,Address,Zip,Phone) VALUES('CASE PERSON','3 TEST ST','123','2025550198')");
        $result = SmsProspectBackfill::applyBatch($f->db, self::RUN, 0, $candidates);
        self::assertSame([1, 0], [$result['added'], $result['existing']]);
        self::assertSame([['Client' => 'Case Person', 'Address' => '3 Test St', 'Zip' => '00123']],
            $f->rows("SELECT Client,Address,Zip FROM dbo.TblProspects WHERE Phone='2025550103'"));
        self::assertSame(1, (int) $f->rows("SELECT COUNT(*) AS n FROM dbo.TblProspects WHERE Zip='123'")[0]['n']);
    }

    public function test_receipt_failure_rolls_back_inserts_row_audits_checkpoint_and_summary_then_resumes(): void
    {
        $f = self::$fixture;
        [, $candidates] = $this->begin([1, 2]);
        $business = $f->snapshot(); $ledger = $f->ledger();
        $f->db->exec(<<<'SQL'
CREATE TRIGGER dbo.SmsBackfillTestFailBatch ON dbo.TblSMSProspectBackfillBatches AFTER INSERT AS
BEGIN
 SET NOCOUNT ON;
 IF (SELECT COUNT(*) FROM dbo.TblProspects WHERE PK>100)<>2
    OR (SELECT COUNT(*) FROM dbo.TblSMSProspectBackfillRows)<>2
  THROW 51002,'Fixture failure did not reach post-insert receipts.',1;
 THROW 51001,'sms backfill fixture receipt failure',1;
END
SQL);
        try {
            $this->refuses(fn () => SmsProspectBackfill::applyBatch($f->db, self::RUN, 0, $candidates), 'sms backfill fixture receipt failure');
            $this->assertUnchanged($business, $ledger);
        } finally { $f->db->exec('DROP TRIGGER dbo.SmsBackfillTestFailBatch'); }
        self::assertSame(2, SmsProspectBackfill::applyBatch($f->db, self::RUN, 0, $candidates)['added']);
        self::assertCount(2, $f->rows('SELECT * FROM dbo.TblSMSProspectBackfillRows'));
        self::assertCount(1, $f->rows('SELECT * FROM dbo.TblSMSProspectBackfillBatches'));
        self::assertSame([2, 1, 2, 2], array_values(array_intersect_key(SmsProspectBackfill::status($f->db, self::RUN),
            array_flip(['after_id', 'batches', 'checked', 'added']))));
        self::assertSame(11, (int) $f->rows('SELECT revision FROM dbo.TblSMSAvailabilitySummary')[0]['revision']);
    }

    public function test_identical_old_chunk_replay_preserves_later_progress_and_changed_payload_refuses(): void
    {
        $f = self::$fixture;
        [, $all] = $this->begin([1, 2, 4]);
        $first = array_slice($all, 0, 2);
        $committed = SmsProspectBackfill::applyBatch($f->db, self::RUN, 0, $first);
        SmsProspectBackfill::applyBatch($f->db, self::RUN, 2, [$all[2]]);
        $business = $f->snapshot(); $ledger = $f->ledger();
        self::assertSame(array_replace($committed, ['replayed' => true]), SmsProspectBackfill::applyBatch($f->db, self::RUN, 0, $first));
        $this->assertUnchanged($business, $ledger);
        foreach (['Contact_Key', 'Observed_SHA256'] as $field) {
            $altered = $first; $altered[0][$field] = str_repeat('f', 64);
            $this->refuses(fn () => SmsProspectBackfill::applyBatch($f->db, self::RUN, 0, $altered), 'different candidates');
            $this->assertUnchanged($business, $ledger);
        }
        self::assertSame(4, SmsProspectBackfill::status($f->db, self::RUN)['after_id']);
    }

    public function test_unreviewed_phone_refuses_entire_mixed_batch_but_email_only_is_allowed(): void
    {
        $f = self::$fixture;
        [, $candidates] = $this->begin([2, 4], false);
        $business = $f->snapshot(); $ledger = $f->ledger();
        $this->refuses(fn () => SmsProspectBackfill::applyBatch($f->db, self::RUN, 0, $candidates), 'history has not been reviewed');
        $this->assertUnchanged($business, $ledger);
        self::assertSame(1, SmsProspectBackfill::applyBatch($f->db, self::RUN, 0, [$candidates[0]])['added']);
        self::assertNull($f->rows('SELECT Phone FROM dbo.TblProspects WHERE PK>100')[0]['Phone']);
    }

    public function test_real_source_write_invalidates_frozen_source_guard(): void
    {
        $f = self::$fixture;
        [, $candidates] = $this->begin([1]);
        $f->db->exec("UPDATE dbo.TblMailersUniqueEnriched SET Phone='2025550190' WHERE PK=1");
        self::assertSame(1, (int) $f->rows('SELECT PostPublishWrites FROM tu_repair_20261014.Control')[0]['PostPublishWrites']);
        $business = $f->snapshot(); $ledger = $f->ledger();
        $this->refuses(fn () => SmsProspectBackfill::applyBatch($f->db, self::RUN, 0, $candidates), 'Source/schema proof differs');
        $this->assertUnchanged($business, $ledger);
    }

    public function test_raw_hash_and_normalized_contact_key_must_both_match(): void
    {
        $f = self::$fixture;
        [, $candidates] = $this->begin([1]);
        $business = $f->snapshot(); $ledger = $f->ledger();
        $normalized = SmsProspectCoverage::sourceRows($f->db, 0, 1, 100, 1)[0];
        $normalized['Phone'] = SmsProspectCoverage::normalize($normalized)['Phone'];
        $altered = $candidates;
        $altered[0]['Observed_SHA256'] = hash('sha256', SmsProspectReconciliation::json($normalized));
        self::assertNotSame($candidates[0]['Observed_SHA256'], $altered[0]['Observed_SHA256']);
        $this->refuses(fn () => SmsProspectBackfill::applyBatch($f->db, self::RUN, 0, $altered), 'Source evidence changed');
        $this->assertUnchanged($business, $ledger);
        $altered = $candidates; $altered[0]['Contact_Key'] = str_repeat('e', 64);
        $this->refuses(fn () => SmsProspectBackfill::applyBatch($f->db, self::RUN, 0, $altered), 'contact or identity proof failed');
        $this->assertUnchanged($business, $ledger);
        self::assertSame(1, SmsProspectBackfill::applyBatch($f->db, self::RUN, 0, $candidates)['added']);
    }

    public function test_deferrals_are_receipted_and_finish_keeps_frozen_proof_and_totals(): void
    {
        $f = self::$fixture;
        [$proof, $candidates] = $this->begin([1, 5, 6]);
        $this->refuses(fn () => SmsProspectBackfill::finish($f->db, self::RUN), 'not exhausted');
        SmsProspectBackfill::applyBatch($f->db, self::RUN, 0, [$candidates[0]]);
        $this->refuses(fn () => SmsProspectBackfill::finish($f->db, self::RUN), 'not exhausted');
        self::assertSame(2, SmsProspectBackfill::applyBatch($f->db, self::RUN, 1, array_slice($candidates, 1))['deferred']);
        $finished = SmsProspectBackfill::finish($f->db, self::RUN);
        self::assertSame(['status' => 'completed_with_deferrals', 'after_id' => 6, 'batches' => 2, 'checked' => 3, 'added' => 1, 'existing' => 0, 'deferred' => 2],
            array_intersect_key($finished, array_flip(['status', 'after_id', 'batches', 'checked', 'added', 'existing', 'deferred'])));
        self::assertSame([['Source_PK' => 5, 'Decision' => 'deferred', 'Reason' => 'unresolved_source_owner'],
            ['Source_PK' => 6, 'Decision' => 'deferred', 'Reason' => 'invalid_city']],
            array_map(static fn (array $row): array => array_replace($row, ['Source_PK' => (int) $row['Source_PK']]),
                $f->rows("SELECT Source_PK,Decision,Reason FROM dbo.TblSMSProspectBackfillRows WHERE Decision='deferred' ORDER BY Source_PK")));
        $stored = $f->rows('SELECT Proof,Plan_SHA256,Actor FROM dbo.TblSMSProspectBackfillRuns')[0];
        self::assertSame($proof, json_decode($stored['Proof'], true, 32, JSON_THROW_ON_ERROR));
        self::assertSame(hash('sha256', SmsProspectReconciliation::json($proof)), $stored['Plan_SHA256']);
        $business = $f->snapshot(); $ledger = $f->ledger();
        self::assertSame($finished, SmsProspectBackfill::begin($f->db, self::RUN, $proof, 'another-actor', true));
        self::assertTrue(SmsProspectBackfill::applyBatch($f->db, self::RUN, 0, [$candidates[0]])['replayed']);
        $changed = $proof; $changed['coverage_state_sha256'] = str_repeat('b', 64);
        $this->refuses(fn () => SmsProspectBackfill::begin($f->db, self::RUN, $changed, 'actor', true), 'different immutable evidence');
        $this->refuses(fn () => SmsProspectBackfill::begin($f->db, self::RUN, $proof, 'actor', false), 'different immutable evidence');
        $this->assertUnchanged($business, $ledger);
    }

    public function test_empty_plan_finishes_without_any_insert_or_batch(): void
    {
        $f = self::$fixture;
        [$proof] = $this->begin([]);
        $business = $f->snapshot();
        $finished = SmsProspectBackfill::finish($f->db, self::RUN);
        self::assertSame('completed', $finished['status']);
        self::assertSame([0, 0, 0, 0, 0, 0], [$finished['after_id'], $finished['batches'], $finished['checked'], $finished['added'], $finished['existing'], $finished['deferred']]);
        self::assertSame($finished, SmsProspectBackfill::finish($f->db, self::RUN));
        self::assertSame($finished, SmsProspectBackfill::begin($f->db, self::RUN, $proof, 'actor', true));
        self::assertSame($business, $f->snapshot());
        self::assertSame([], $f->ledger()[1]);
        self::assertSame([], $f->ledger()[2]);
    }

    public function test_workflow_app_lock_contention_refuses_then_releases_partial_locks(): void
    {
        $f = self::$fixture;
        [$proof, $candidates] = $this->begin([1]);
        $other = $f->connection();
        $acquire = "DECLARE @r int; EXEC @r=sys.sp_getapplock @Resource=?,@LockMode='Exclusive',@LockOwner='Session',@LockTimeout=0; SELECT @r AS result";
        $business = $f->snapshot(); $ledger = $f->ledger();
        try {
            foreach (['sms:new-run', 'sms:prospect-maintenance', 'sms:prospect-admission', 'sms:contact-sync', 'sms:phone-suppression'] as $resource) {
                self::assertGreaterThanOrEqual(0, (int) $f->rows($acquire, [$resource], $other)[0]['result']);
                try {
                    $this->refuses(fn () => SmsProspectBackfill::applyBatch($f->db, self::RUN, 0, $candidates), 'Another SMS workflow is active');
                    $this->refuses(fn () => SmsProspectBackfill::begin($f->db, self::OTHER_RUN, $proof, 'actor', true), 'Another SMS workflow is active');
                    self::assertNull(SmsProspectBackfill::status($f->db, self::OTHER_RUN));
                    if ($resource !== 'sms:new-run') {
                        self::assertGreaterThanOrEqual(0, (int) $f->rows($acquire, ['sms:new-run'], $other)[0]['result'], 'Earlier acquired locks must be released on refusal.');
                        $f->rows("EXEC sys.sp_releaseapplock @Resource='sms:new-run',@LockOwner='Session'", [], $other);
                    }
                    $this->assertUnchanged($business, $ledger);
                } finally { $f->rows("EXEC sys.sp_releaseapplock @Resource=?,@LockOwner='Session'", [$resource], $other); }
            }
        } finally { $other = null; }
        self::assertSame(1, SmsProspectBackfill::applyBatch($f->db, self::RUN, 0, $candidates)['added']);
    }

    public function test_open_sms_run_refuses_begin_and_apply_until_discarded(): void
    {
        $f = self::$fixture;
        [$proof, $candidates] = $this->begin([1]);
        $f->rows("INSERT dbo.TblSMSRuns(Request_ID,Status) VALUES(?,'selecting')", [self::OTHER_RUN]);
        $business = $f->snapshot(); $ledger = $f->ledger();
        $this->refuses(fn () => SmsProspectBackfill::begin($f->db, self::OTHER_RUN, $proof, 'actor', true), 'open SMS run');
        $this->refuses(fn () => SmsProspectBackfill::applyBatch($f->db, self::RUN, 0, $candidates), 'open SMS run');
        $this->assertUnchanged($business, $ledger);
        $f->rows("UPDATE dbo.TblSMSRuns SET Status='discarded' WHERE Request_ID=?", [self::OTHER_RUN]);
        self::assertSame(1, SmsProspectBackfill::applyBatch($f->db, self::RUN, 0, $candidates)['added']);
    }

    public function test_missing_history_seek_index_fails_closed_without_partial_writes(): void
    {
        $f = self::$fixture;
        [, $candidates] = $this->begin([1]);
        $business = $f->snapshot(); $ledger = $f->ledger();
        $f->db->exec('DROP INDEX IX_History_Request_Phone ON dbo.TblSMSHistory');
        try {
            // The admission guard must reject before FORCESEEK could fall back to any scan.
            $this->refuses(fn () => SmsProspectBackfill::applyBatch($f->db, self::RUN, 0, $candidates), 'lookup index is unavailable');
            $this->assertUnchanged($business, $ledger);
        } finally { $f->db->exec('CREATE UNIQUE INDEX IX_History_Request_Phone ON dbo.TblSMSHistory(Request_ID,Phone)'); }
        self::assertSame(1, SmsProspectBackfill::applyBatch($f->db, self::RUN, 0, $candidates)['added']);
    }

    public function test_disabled_source_trigger_and_enabled_prospect_trigger_refuse(): void
    {
        $f = self::$fixture;
        [, $candidates] = $this->begin([1]);
        $business = $f->snapshot(); $ledger = $f->ledger();
        $f->db->exec('DISABLE TRIGGER dbo.TURepair20261014PostPublish ON dbo.TblMailersUniqueEnriched');
        try {
            $this->refuses(fn () => SmsProspectBackfill::applyBatch($f->db, self::RUN, 0, $candidates), 'change-detection trigger must be enabled');
            $this->assertUnchanged($business, $ledger);
        } finally { $f->db->exec('ENABLE TRIGGER dbo.TURepair20261014PostPublish ON dbo.TblMailersUniqueEnriched'); }
        $f->db->exec('CREATE TRIGGER dbo.SmsBackfillTestProspectTrigger ON dbo.TblProspects AFTER INSERT AS BEGIN SET NOCOUNT ON; END');
        try {
            $this->refuses(fn () => SmsProspectBackfill::applyBatch($f->db, self::RUN, 0, $candidates), 'Review prospect triggers');
            $this->assertUnchanged($business, $ledger);
        } finally { $f->db->exec('DROP TRIGGER dbo.SmsBackfillTestProspectTrigger'); }
    }

    public function test_changed_authoritative_external_id_is_deferred_instead_of_joined_by_pk_alone(): void
    {
        $f = self::$fixture;
        [, $candidates] = $this->begin([1]);
        $f->db->exec("UPDATE dbo.TblMailersUnique SET External_ID='different-purchased-person' WHERE PK=1001");
        self::assertSame(1, SmsProspectBackfill::applyBatch($f->db, self::RUN, 0, $candidates)['deferred']);
        self::assertSame([['Unique_Source_PK' => null, 'Prospect_PK' => null, 'Decision' => 'deferred', 'Reason' => 'unresolved_source_owner']],
            $f->rows('SELECT Unique_Source_PK,Prospect_PK,Decision,Reason FROM dbo.TblSMSProspectBackfillRows'));
        self::assertCount(1, $f->rows('SELECT * FROM dbo.TblProspects'));
    }

    public function test_case_equivalent_candidate_identities_refuse_atomically(): void
    {
        $f = self::$fixture;
        [, $candidates] = $this->begin([7, 8]);
        $business = $f->snapshot(); $ledger = $f->ledger();
        $this->refuses(fn () => SmsProspectBackfill::applyBatch($f->db, self::RUN, 0, $candidates), 'IX_BFInput_Identity');
        $this->assertUnchanged($business, $ledger);
    }

    public function test_mirrored_send_uses_actual_sent_pacific_date_not_ledger_or_export_date(): void
    {
        $f = self::$fixture;
        $f->db->exec("UPDATE dbo.TblSMSRuns SET Status='not_sent' WHERE Request_ID IN
            ('00000000-0000-0000-0000-000000000002','00000000-0000-0000-0000-000000000003')");
        [, $candidates] = $this->begin([1]);
        SmsProspectBackfill::applyBatch($f->db, self::RUN, 0, $candidates);
        self::assertSame([['SMS_Attempts' => 2, 'Latest_Export_Date' => '2026-10-04']],
            array_map(static fn (array $row): array => array_replace($row, ['SMS_Attempts' => (int) $row['SMS_Attempts']]),
                $f->rows('SELECT SMS_Attempts,Latest_Export_Date FROM dbo.TblProspects WHERE PK>100')));
    }

    public function test_sent_event_without_sent_at_refuses_instead_of_inventing_history(): void
    {
        $f = self::$fixture;
        [, $candidates] = $this->begin([1]);
        $f->db->exec("UPDATE dbo.TblSMSRuns SET Sent_At=NULL WHERE Request_ID='00000000-0000-0000-0000-000000000001'");
        $business = $f->snapshot(); $ledger = $f->ledger();
        $this->refuses(fn () => SmsProspectBackfill::applyBatch($f->db, self::RUN, 0, $candidates), 'no business date');
        $this->assertUnchanged($business, $ledger);
    }

    public function test_each_other_required_lookup_index_is_checked_before_writing(): void
    {
        $f = self::$fixture;
        [, $candidates] = $this->begin([1]);
        $business = $f->snapshot(); $ledger = $f->ledger();
        foreach ([
            ['DROP INDEX IX_PhoneNumbers_Phone ON dbo.TblPhoneNumbers', 'CREATE INDEX IX_PhoneNumbers_Phone ON dbo.TblPhoneNumbers(Phone)'],
            ['ALTER TABLE tu_repair_20261014.Lineage DROP CONSTRAINT PK_Lineage', 'ALTER TABLE tu_repair_20261014.Lineage ADD CONSTRAINT PK_Lineage PRIMARY KEY(Enriched_PK)'],
            ['DROP INDEX IndexExternalID ON dbo.TblMailersUnique', 'CREATE INDEX IndexExternalID ON dbo.TblMailersUnique(External_ID)'],
        ] as [$drop, $restore]) {
            $f->db->exec($drop);
            try {
                $this->refuses(fn () => SmsProspectBackfill::applyBatch($f->db, self::RUN, 0, $candidates), 'lookup index is unavailable');
                $this->assertUnchanged($business, $ledger);
            } finally { $f->db->exec($restore); }
        }
    }

    public function test_raw_hash_detects_source_change_even_if_change_detector_was_bypassed(): void
    {
        $f = self::$fixture;
        [$proof, $candidates] = $this->begin([1]);
        $f->db->exec('DISABLE TRIGGER dbo.TURepair20261014PostPublish ON dbo.TblMailersUniqueEnriched');
        try { $f->db->exec("UPDATE dbo.TblMailersUniqueEnriched SET last_seen_at='2026-10-03' WHERE PK=1"); }
        finally { $f->db->exec('ENABLE TRIGGER dbo.TURepair20261014PostPublish ON dbo.TblMailersUniqueEnriched'); }
        self::assertSame($proof['source_guard'], SmsProspectCoverage::sourceGuard(SmsProspectCoverage::metadata($f->db)));
        $business = $f->snapshot(); $ledger = $f->ledger();
        $this->refuses(fn () => SmsProspectBackfill::applyBatch($f->db, self::RUN, 0, $candidates), 'Source evidence changed');
        $this->assertUnchanged($business, $ledger);
    }

    public function test_external_id_heap_bucket_requires_exactly_one_matching_lineage_owner(): void
    {
        $f = self::$fixture;
        [, $candidates] = $this->begin([1, 2]);
        $f->db->exec('ALTER TABLE dbo.TblMailersUnique DROP CONSTRAINT PK_Unique');
        try {
            self::assertSame(1, (int) $f->rows("SELECT COUNT(*) AS n FROM sys.indexes WHERE object_id=OBJECT_ID('dbo.TblMailersUnique') AND index_id=0")[0]['n']);
            $f->db->exec("INSERT dbo.TblMailersUnique VALUES(9001,'another-drop','external-1','T8'),(9002,'fixture-drop','external-1','T9')");
            self::assertSame(1, SmsProspectBackfill::applyBatch($f->db, self::RUN, 0, [$candidates[0]])['added']);
            self::assertSame(1, (int) $f->rows('SELECT COUNT(*) AS n FROM dbo.TblProspects WHERE PK>100')[0]['n']);
            $f->db->exec("INSERT dbo.TblMailersUnique VALUES(1002,'fixture-drop','external-2','T9')");
            self::assertSame(1, SmsProspectBackfill::applyBatch($f->db, self::RUN, 1, [$candidates[1]])['deferred']);
            self::assertSame([['Prospect_PK' => null, 'Reason' => 'unresolved_source_owner']],
                $f->rows('SELECT Prospect_PK,Reason FROM dbo.TblSMSProspectBackfillRows WHERE Source_PK=2'));
        } finally {
            $f->db->exec("DELETE dbo.TblMailersUnique WHERE PK>=9000 OR (PK=1002 AND Debt_Tier='T9')");
            $f->db->exec('ALTER TABLE dbo.TblMailersUnique ADD CONSTRAINT PK_Unique PRIMARY KEY(PK)');
        }
    }

    public function test_null_external_id_defers_even_when_lineage_pk_exists(): void
    {
        $f = self::$fixture;
        [, $candidates] = $this->begin([9]);
        self::assertSame(1, SmsProspectBackfill::applyBatch($f->db, self::RUN, 0, $candidates)['deferred']);
        self::assertSame([['Decision' => 'deferred', 'Reason' => 'unresolved_source_owner']],
            $f->rows('SELECT Decision,Reason FROM dbo.TblSMSProspectBackfillRows'));
        self::assertCount(1, $f->rows('SELECT * FROM dbo.TblProspects'));
    }

    public function test_empty_history_with_only_discarded_runs_refuses_reviewed_phone_but_allows_email(): void
    {
        $f = self::$fixture;
        $f->db->exec("DELETE dbo.TblSMSLegacySends; UPDATE dbo.TblSMSRuns SET Status='discarded'");
        [, $candidates] = $this->begin([2, 4], true);
        $business = $f->snapshot(); $ledger = $f->ledger();
        $error = $this->refuses(fn () => SmsProspectBackfill::applyBatch($f->db, self::RUN, 0, $candidates),
            'Restore and review the known historical send evidence');
        self::assertInstanceOf(\PDOException::class, $error);
        self::assertSame(52303, (int) $error->errorInfo[1]);
        $this->assertUnchanged($business, $ledger);
        $f->db->exec('DELETE dbo.TblSMSHistory');
        self::assertSame([0, 0], array_map('intval', array_values($f->rows('SELECT
            (SELECT COUNT(*) FROM dbo.TblSMSLegacySends) AS legacy,(SELECT COUNT(*) FROM dbo.TblSMSHistory) AS history')[0])));
        self::assertSame(1, SmsProspectBackfill::applyBatch($f->db, self::RUN, 0, [$candidates[0]])['added']);
        self::assertSame([['Phone' => null, 'SMS_Attempts' => 0, 'Latest_Export_Date' => null]],
            array_map(static fn (array $row): array => array_replace($row, ['SMS_Attempts' => (int) $row['SMS_Attempts']]),
                $f->rows('SELECT Phone,SMS_Attempts,Latest_Export_Date FROM dbo.TblProspects WHERE PK>100')));
    }

    public function test_unknown_phone_gets_zero_attempts_only_with_verified_history_for_another_phone(): void
    {
        $f = self::$fixture;
        $f->db->exec("DELETE dbo.TblSMSLegacySends; DELETE dbo.TblSMSHistory; UPDATE dbo.TblSMSRuns SET Status='discarded';
            INSERT dbo.TblSMSLegacySends VALUES('2025550188','2026-09-01',NULL)");
        [, $candidates] = $this->begin([4]);
        self::assertSame(1, SmsProspectBackfill::applyBatch($f->db, self::RUN, 0, $candidates)['added']);
        self::assertSame([['Phone' => '2025550104', 'SMS_Attempts' => 0, 'Latest_Export_Date' => null]],
            array_map(static fn (array $row): array => array_replace($row, ['SMS_Attempts' => (int) $row['SMS_Attempts']]),
                $f->rows('SELECT Phone,SMS_Attempts,Latest_Export_Date FROM dbo.TblProspects WHERE PK>100')));
    }

    private function zeroHistoryPlan(array $ids): array
    {
        $f = self::$fixture;
        // Keep exported/discarded memberships as artifacts, not actual send evidence.
        $f->db->exec("DELETE dbo.TblSMSLegacySends; UPDATE dbo.TblSMSRuns SET Status='discarded',Sent_At=NULL");
        [$proof, $candidates] = $f->plan($ids);
        $proof['history_policy'] = SmsProspectBackfill::NO_PRIOR_SENDS;
        return [$proof, $candidates];
    }

    public function test_explicit_no_prior_sends_admits_new_rows_at_zero_without_changing_old_tracking(): void
    {
        $f = self::$fixture;
        [$proof, $candidates] = $this->zeroHistoryPlan([1, 2, 4]);
        $old = $f->rows('SELECT * FROM dbo.TblProspects WHERE PK=100');
        $sourceAndHistory = $f->snapshot(false);
        $state = SmsProspectBackfill::begin($f->db, self::RUN, $proof, 'confirmed-no-sends@example.invalid', true);
        self::assertTrue($state['history_reviewed']);
        self::assertSame(SmsProspectBackfill::NO_PRIOR_SENDS, $state['history_policy']);
        self::assertSame(3, SmsProspectBackfill::applyBatch($f->db, self::RUN, 0, $candidates)['added']);
        self::assertSame(3, (int) $f->rows('SELECT COUNT(*) AS n FROM dbo.TblProspects WHERE PK>100 AND SMS_Attempts=0 AND Latest_Export_Date IS NULL')[0]['n']);
        self::assertSame($old, $f->rows('SELECT * FROM dbo.TblProspects WHERE PK=100'));
        self::assertSame($sourceAndHistory, $f->snapshot(false));
        $emptyHistory = strtoupper(hash('sha256', mb_convert_encoding('[]', 'UTF-16LE', 'UTF-8')));
        self::assertSame([['History_SHA256' => $emptyHistory]], $f->rows('SELECT DISTINCT History_SHA256 FROM dbo.TblSMSProspectBackfillRows'));
        self::assertSame($proof, json_decode($f->rows('SELECT Proof FROM dbo.TblSMSProspectBackfillRuns')[0]['Proof'], true, 32, JSON_THROW_ON_ERROR));
        self::assertTrue(SmsProspectBackfill::applyBatch($f->db, self::RUN, 0, $candidates)['replayed']);
        self::assertSame('completed', SmsProspectBackfill::finish($f->db, self::RUN)['status']);
    }

    public function test_no_prior_sends_requires_confirmation_and_rejects_unknown_policy_without_writes(): void
    {
        $f = self::$fixture;
        [$proof] = $this->zeroHistoryPlan([1]);
        $business = $f->snapshot(); $ledger = $f->ledger();
        $this->refuses(fn () => SmsProspectBackfill::begin($f->db, self::RUN, $proof, 'actor', false), 'explicit reviewed confirmation');
        $this->assertUnchanged($business, $ledger);
        foreach ([null, false, '', 'exported-only', 'unconfirmed'] as $invalid) {
            $changed = array_replace($proof, ['history_policy' => $invalid]);
            $this->refuses(fn () => SmsProspectBackfill::begin($f->db, self::RUN, $changed, 'actor', true), 'Unknown frozen backfill history policy');
            $this->assertUnchanged($business, $ledger);
        }
    }

    public function test_no_prior_sends_policy_cannot_be_switched_on_resume(): void
    {
        $f = self::$fixture;
        [$proof] = $this->zeroHistoryPlan([1]);
        SmsProspectBackfill::begin($f->db, self::RUN, $proof, 'actor', true);
        $business = $f->snapshot(); $ledger = $f->ledger();
        $changed = array_replace($proof, ['history_policy' => SmsProspectBackfill::VERIFIED_HISTORY]);
        $this->refuses(fn () => SmsProspectBackfill::begin($f->db, self::RUN, $changed, 'actor', true), 'different immutable evidence');
        $this->assertUnchanged($business, $ledger);
    }

    public function test_no_prior_sends_rejects_any_contradictory_legacy_or_modern_evidence_at_begin(): void
    {
        $f = self::$fixture;
        foreach ([
            "INSERT dbo.TblSMSLegacySends VALUES('2025550188','2026-10-08',NULL)",
            "INSERT dbo.TblSMSLegacySends VALUES('2025550188','2026-10-08','00000000-0000-0000-0000-000000000001')",
            "UPDATE dbo.TblSMSRuns SET Status='sent',Sent_At=NULL WHERE Request_ID='00000000-0000-0000-0000-000000000001'",
            "UPDATE dbo.TblSMSRuns SET Sent_At='2026-10-08T12:00:00' WHERE Request_ID='00000000-0000-0000-0000-000000000001'",
        ] as $contradiction) {
            $f->reset();
            [$proof] = $this->zeroHistoryPlan([1]);
            $f->db->exec($contradiction);
            $business = $f->snapshot(); $ledger = $f->ledger();
            $this->refuses(fn () => SmsProspectBackfill::begin($f->db, self::RUN, $proof, 'actor', true), 'contradicts the no-prior-sends confirmation');
            $this->assertUnchanged($business, $ledger);
        }
    }

    public function test_new_evidence_blocks_zero_history_chunk_and_resume_without_partial_writes(): void
    {
        $f = self::$fixture;
        [$proof, $candidates] = $this->zeroHistoryPlan([1]);
        SmsProspectBackfill::begin($f->db, self::RUN, $proof, 'actor', true);
        $f->db->exec("INSERT dbo.TblSMSLegacySends VALUES('2025550188','2026-10-08',NULL)");
        $business = $f->snapshot(); $ledger = $f->ledger();
        $this->refuses(fn () => SmsProspectBackfill::applyBatch($f->db, self::RUN, 0, $candidates), 'contradicts the no-prior-sends confirmation');
        $this->refuses(fn () => SmsProspectBackfill::begin($f->db, self::RUN, $proof, 'actor', true), 'contradicts the no-prior-sends confirmation');
        $this->assertUnchanged($business, $ledger);
    }

    public function test_zero_history_finish_refuses_evidence_appearing_after_last_chunk(): void
    {
        $f = self::$fixture;
        [$proof, $candidates] = $this->zeroHistoryPlan([1]);
        SmsProspectBackfill::begin($f->db, self::RUN, $proof, 'actor', true);
        SmsProspectBackfill::applyBatch($f->db, self::RUN, 0, $candidates);
        $f->db->exec("UPDATE dbo.TblSMSRuns SET Status='sent',Sent_At='2026-10-08T12:00:00' WHERE Request_ID='00000000-0000-0000-0000-000000000001'");
        $business = $f->snapshot(); $ledger = $f->ledger();
        $this->refuses(fn () => SmsProspectBackfill::finish($f->db, self::RUN), 'contradicts the no-prior-sends confirmation');
        $this->assertUnchanged($business, $ledger);
    }

    public function test_snapshot_caller_is_normalized_to_supported_write_isolation_before_temp_ddl(): void
    {
        $f = self::$fixture;
        [$proof, $candidates] = $this->zeroHistoryPlan([1]);
        $f->db->exec('SET TRANSACTION ISOLATION LEVEL SNAPSHOT');
        try {
            SmsProspectBackfill::begin($f->db, self::RUN, $proof, 'actor', true);
            self::assertSame(2, (int) $f->rows('SELECT transaction_isolation_level AS level FROM sys.dm_exec_sessions WHERE session_id=@@SPID')[0]['level']);
            self::assertSame(1, SmsProspectBackfill::applyBatch($f->db, self::RUN, 0, $candidates)['added']);
            $f->db->exec("INSERT dbo.TblSMSLegacySends VALUES('2025550188','2026-10-08',NULL)");
            $business = $f->snapshot(); $ledger = $f->ledger();
            $this->refuses(fn () => SmsProspectBackfill::finish($f->db, self::RUN), 'contradicts the no-prior-sends confirmation');
            $this->assertUnchanged($business, $ledger);
        } finally { $f->db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED'); }
    }

    public function test_default_250_row_bulk_chunk_commits_all_receipts_and_checkpoint(): void
    {
        $f = self::$fixture;
        $f->assertOwnedDatabase();
        $sentinel = $f->rows('SELECT * FROM dbo.TblProspects WHERE PK=100');
        $f->db->exec('DISABLE TRIGGER dbo.TURepair20261014PostPublish ON dbo.TblMailersUniqueEnriched');
        try {
            $f->db->exec(<<<'SQL'
DELETE tu_repair_20261014.Lineage; DELETE dbo.TblMailersUniqueEnriched; DELETE dbo.TblMailersUnique;
DELETE dbo.TblSMSLegacySends; DELETE dbo.TblSMSHistory;
WITH Numbers AS(SELECT TOP(250) CONVERT(int,ROW_NUMBER() OVER(ORDER BY object_id)) AS id FROM sys.all_objects)
INSERT dbo.TblMailersUniqueEnriched(PK,Client,Address,City,State,Zip,Zip5_Normalized,Email,Drop_Name,External_ID)
 SELECT id,N'Bulk Person '+CONVERT(nvarchar(10),id),CONVERT(nvarchar(10),id)+N' Bulk St',N'Test City',N'CA',N'00123',N'00123',
  N'bulk'+CONVERT(nvarchar(10),id)+N'@example.test',N'fixture-drop',N'bulk-external-'+CONVERT(nvarchar(10),id) FROM Numbers;
INSERT dbo.TblMailersUnique(PK,Drop_Name,External_ID,Debt_Tier)
 SELECT 1000+PK,Drop_Name,External_ID,N'Tier2' FROM dbo.TblMailersUniqueEnriched;
INSERT tu_repair_20261014.Lineage(Enriched_PK,Unique_Source_PK) SELECT PK,1000+PK FROM dbo.TblMailersUniqueEnriched;
SQL);
        } finally { $f->db->exec('ENABLE TRIGGER dbo.TURepair20261014PostPublish ON dbo.TblMailersUniqueEnriched'); }
        [$proof, $candidates] = $this->begin(range(1, 250), false);
        self::assertSame([250, 250], [$proof['candidate_count'], $proof['candidate_max_id']]);
        $sourceAndHistory = $f->snapshot(false);
        self::assertSame(['after_id' => 0, 'through_id' => 250, 'checked' => 250, 'added' => 250,
            'existing' => 0, 'deferred' => 0, 'replayed' => false], SmsProspectBackfill::applyBatch($f->db, self::RUN, 0, $candidates));
        self::assertSame([0, 250, 250, 250, 0, 0], array_map('intval', array_values($f->rows(
            'SELECT After_ID,Through_ID,Checked,Added,Existing,Deferred FROM dbo.TblSMSProspectBackfillBatches')[0])));
        self::assertSame([250, 250, 250], array_map('intval', array_values($f->rows("SELECT COUNT(*) AS receipts,
            COUNT(DISTINCT Prospect_PK) AS prospects,SUM(CASE WHEN Decision='inserted' AND Unique_Source_PK=1000+Source_PK THEN 1 ELSE 0 END) AS mapped
            FROM dbo.TblSMSProspectBackfillRows")[0])));
        // SQL Server hashes NVARCHAR JSON as UTF-16LE; no events must still hash the empty array.
        $emptyHistory = strtoupper(hash('sha256', mb_convert_encoding('[]', 'UTF-16LE', 'UTF-8')));
        foreach ($f->rows('SELECT Source_SHA256,After_SHA256,History_SHA256 FROM dbo.TblSMSProspectBackfillRows ORDER BY Source_PK') as $i => $receipt) {
            self::assertSame($candidates[$i]['Observed_SHA256'], $receipt['Source_SHA256']);
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/iD', $receipt['After_SHA256']);
            self::assertSame($emptyHistory, $receipt['History_SHA256']);
        }
        self::assertSame(250, (int) $f->rows('SELECT COUNT(*) AS n FROM dbo.TblProspects WHERE PK>100 AND Phone IS NULL AND SMS_Attempts=0 AND Latest_Export_Date IS NULL')[0]['n']);
        self::assertSame($sentinel, $f->rows('SELECT * FROM dbo.TblProspects WHERE PK=100'));
        self::assertSame($sourceAndHistory, $f->snapshot(false));
        $finished = SmsProspectBackfill::finish($f->db, self::RUN);
        self::assertSame(['status' => 'completed', 'after_id' => 250, 'batches' => 1, 'checked' => 250, 'added' => 250, 'existing' => 0, 'deferred' => 0],
            array_intersect_key($finished, array_flip(['status', 'after_id', 'batches', 'checked', 'added', 'existing', 'deferred'])));
    }
}
