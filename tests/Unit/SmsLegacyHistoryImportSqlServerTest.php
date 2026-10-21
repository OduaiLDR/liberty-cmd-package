<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Services\SmsLegacyHistoryImport as Import;
use Cmd\Reports\Services\SmsProspectBackfill;
use Cmd\Reports\Tests\Support\SmsBackfillSqlFixture;
use PDO;
use PHPUnit\Framework\TestCase;
use Throwable;

/** Reuses only the hard-wired loopback fixture and its uniquely owned disposable database. */
final class SmsLegacyHistoryImportSqlServerTest extends TestCase
{
    private static ?SmsBackfillSqlFixture $fixture = null;

    private const RUN = '77777777-8888-4999-aaaa-bbbbbbbbbbbb';

    public static function setUpBeforeClass(): void
    {
        if (! in_array('sqlsrv', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('Real SQL Server PDO required.');
        }
        self::$fixture = new SmsBackfillSqlFixture;
        try {
            SmsProspectBackfill::install(self::$fixture->db);
            Import::install(self::$fixture->db);
        } catch (Throwable $error) {
            self::$fixture->close();
            throw $error;
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::$fixture?->close();
        self::$fixture = null;
    }

    protected function setUp(): void
    {
        $f = self::$fixture;
        $f->assertOwnedDatabase();
        $f->db->exec('DELETE dbo.TblSMSLegacyImportRows; DELETE dbo.TblSMSLegacyImportChunks; DELETE dbo.TblSMSLegacyImportRuns');
        $f->reset();
    }

    private function events(): array
    {
        return [['Event_ID' => 1, 'Phone' => '2025550101', 'Send_Date' => '2026-10-08'],
            ['Event_ID' => 2, 'Phone' => '2025550102', 'Send_Date' => '2026-10-08'],
            ['Event_ID' => 3, 'Phone' => '0005550001', 'Send_Date' => '2026-10-08']];
    }

    private function proof(): array
    {
        return ['format' => 1, 'complete' => true, 'policy' => Import::POLICY, 'sent_date' => '2026-10-08',
            'files' => [['part' => 1, 'sha256' => hash('sha256', 'synthetic file'), 'bytes' => 50, 'rows' => 3, 'phone_occurrences' => 3]],
            'rows' => 3, 'phone_occurrences' => 3, 'unique_events' => 3, 'duplicate_events' => 0, 'event_max_id' => 3,
            'events_sha256' => hash('sha256', 'synthetic verified events')];
    }

    private function begin(): void
    {
        Import::begin(self::$fixture->db, self::RUN, $this->proof(), 'local-fixture@example.invalid', true);
    }

    private function legacy(): array
    {
        return self::$fixture->rows('SELECT * FROM dbo.TblSMSLegacySends ORDER BY Phone,Send_Date');
    }

    private function checkpoint(): array
    {
        return [self::$fixture->rows('SELECT * FROM dbo.TblSMSLegacyImportRuns ORDER BY Run_ID'),
            self::$fixture->rows('SELECT * FROM dbo.TblSMSLegacyImportChunks ORDER BY After_ID'),
            self::$fixture->rows('SELECT * FROM dbo.TblSMSLegacyImportRows ORDER BY Event_ID'),
            self::$fixture->rows('SELECT * FROM dbo.TblSMSAvailabilitySummary ORDER BY id')];
    }

    private function otherBusiness(): array
    {
        $snapshot = self::$fixture->snapshot();
        unset($snapshot['dbo.TblSMSLegacySends']);

        return $snapshot;
    }

    private function refuses(callable $work, string $message): void
    {
        try {
            $work();
        } catch (Throwable $error) {
            self::assertStringContainsString($message, $error->getMessage());
            self::assertFalse(self::$fixture->db->inTransaction());

            return;
        }
        self::fail('Expected safe refusal.');
    }

    public function test_install_is_additive_and_refuses_replacement(): void
    {
        $business = self::$fixture->snapshot();
        $this->refuses(fn () => Import::install(self::$fixture->db), 'exists or is partially installed');
        self::assertSame($business, self::$fixture->snapshot());
    }

    public function test_export_file_without_actual_send_confirmation_cannot_create_a_run(): void
    {
        $before = $this->checkpoint();
        $business = self::$fixture->snapshot();
        $this->refuses(fn () => Import::begin(self::$fixture->db, self::RUN, $this->proof(), 'actor'), 'Actual sending must be confirmed');
        self::assertSame($before, $this->checkpoint());
        self::assertSame($business, self::$fixture->snapshot());
    }

    public function test_appends_only_missing_phone_days_and_replays_without_touching_other_business_rows(): void
    {
        $f = self::$fixture;
        $f->db->exec("INSERT dbo.TblSMSLegacySends VALUES('2025550101','2026-10-08',NULL)");
        $this->begin();
        $business = $this->otherBusiness();
        $old = $this->legacy();
        $events = $this->events();
        $result = Import::applyBatch($f->db, self::RUN, 0, $events);
        self::assertSame(['after_id' => 0, 'through_id' => 3, 'checked' => 3, 'added' => 2, 'existing' => 1, 'replayed' => false], $result);
        self::assertCount(count($old) + 2, $this->legacy());
        self::assertSame($business, $this->otherBusiness());
        foreach ($old as $row) {
            self::assertContains($row, $this->legacy());
        }
        self::assertSame('0005550001', $f->rows("SELECT Phone FROM dbo.TblSMSLegacySends WHERE Phone='0005550001'")[0]['Phone']);
        self::assertSame(['existing', 'added', 'added'], array_column($f->rows('SELECT Decision FROM dbo.TblSMSLegacyImportRows ORDER BY Event_ID'), 'Decision'));
        foreach ($f->rows('SELECT Phone_Date_SHA256 FROM dbo.TblSMSLegacyImportRows') as $row) {
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/iD', $row['Phone_Date_SHA256']);
        }
        $after = $this->checkpoint();
        $legacy = $this->legacy();
        self::assertSame(array_replace($result, ['replayed' => true]), Import::applyBatch($f->db, self::RUN, 0, $events));
        self::assertSame($after, $this->checkpoint());
        self::assertSame($legacy, $this->legacy());
        $finished = Import::finish($f->db, self::RUN);
        self::assertSame('completed', $finished['status']);
        self::assertSame($finished, Import::begin($f->db, self::RUN, $this->proof(), 'another actor', true));
        self::assertSame($finished, Import::finish($f->db, self::RUN));
    }

    public function test_sql_checkpoint_and_added_rows_roll_back_together_then_resume(): void
    {
        $f = self::$fixture;
        $this->begin();
        $before = $this->checkpoint();
        $business = $f->snapshot();
        $f->db->exec("CREATE TRIGGER dbo.SmsLegacyImportTestFail ON dbo.TblSMSLegacyImportChunks AFTER INSERT AS BEGIN THROW 52901,'history fixture checkpoint failure',1; END");
        try {
            $this->refuses(fn () => Import::applyBatch($f->db, self::RUN, 0, $this->events()), 'history fixture checkpoint failure');
            self::assertSame($before, $this->checkpoint());
            self::assertSame($business, $f->snapshot());
        } finally {
            $f->db->exec('DROP TRIGGER dbo.SmsLegacyImportTestFail');
        }
        self::assertSame(3, Import::applyBatch($f->db, self::RUN, 0, $this->events())['added']);
    }

    public function test_changed_checkpoint_payload_or_proof_is_not_accepted(): void
    {
        $f = self::$fixture;
        $this->begin();
        Import::applyBatch($f->db, self::RUN, 0, $this->events());
        $before = $this->checkpoint();
        $business = $f->snapshot();
        $events = $this->events();
        $events[0]['Phone'] = '2025550199';
        $this->refuses(fn () => Import::applyBatch($f->db, self::RUN, 0, $events), 'cannot accept different events');
        $proof = $this->proof();
        $proof['events_sha256'] = hash('sha256', 'different evidence');
        $this->refuses(fn () => Import::begin($f->db, self::RUN, $proof, 'actor', true), 'evidence or confirmation changed');
        self::assertSame($before, $this->checkpoint());
        self::assertSame($business, $f->snapshot());
    }

    public function test_duplicate_events_and_wrong_dates_are_atomic_refusals(): void
    {
        $f = self::$fixture;
        $this->begin();
        $before = $this->checkpoint();
        $business = $f->snapshot();
        $events = $this->events();
        $events[1]['Phone'] = $events[0]['Phone'];
        $this->refuses(fn () => Import::applyBatch($f->db, self::RUN, 0, $events), 'IX_LegacyInputPhoneDate');
        $events = $this->events();
        $events[1]['Send_Date'] = '2026-10-07';
        $this->refuses(fn () => Import::applyBatch($f->db, self::RUN, 0, $events), 'date differs');
        self::assertSame($before, $this->checkpoint());
        self::assertSame($business, $f->snapshot());
    }

    public function test_linked_legacy_or_modern_same_day_sends_require_reconciliation(): void
    {
        $f = self::$fixture;
        $this->begin();
        $f->db->exec("INSERT dbo.TblSMSLegacySends VALUES('2025550101','2026-10-08','00000000-0000-0000-0000-000000000004')");
        $before = $this->checkpoint();
        $business = $f->snapshot();
        $this->refuses(fn () => Import::applyBatch($f->db, self::RUN, 0, $this->events()), 'linked send needs manual reconciliation');
        self::assertSame($before, $this->checkpoint());
        self::assertSame($business, $f->snapshot());
        $f->db->exec("DELETE dbo.TblSMSLegacySends WHERE Send_Date='2026-10-08'; UPDATE dbo.TblSMSRuns SET Sent_At='2026-10-08T08:00:00' WHERE Request_ID='00000000-0000-0000-0000-000000000001'");
        $business = $f->snapshot();
        $this->refuses(fn () => Import::applyBatch($f->db, self::RUN, 0, $this->events()), 'overlaps a modern sent event');
        self::assertSame($before, $this->checkpoint());
        self::assertSame($business, $f->snapshot());
    }

    public function test_open_run_and_workflow_locks_prevent_partial_import(): void
    {
        $f = self::$fixture;
        $this->begin();
        $other = $f->connection();
        $before = $this->checkpoint();
        $business = $f->snapshot();
        $locks = ['sms:new-run', 'sms:prospect-maintenance', 'sms:prospect-admission', 'sms:contact-sync', 'sms:phone-suppression', 'sms:legacy-history-import'];
        foreach ($locks as $name) {
            $f->rows("DECLARE @r int; EXEC @r=sys.sp_getapplock @Resource=?,@LockMode='Exclusive',@LockOwner='Session',@LockTimeout=0; SELECT @r AS result", [$name], $other);
            try {
                $this->refuses(fn () => Import::applyBatch($f->db, self::RUN, 0, $this->events()), 'Another SMS workflow is active');
            } finally {
                $f->rows("EXEC sys.sp_releaseapplock @Resource=?,@LockOwner='Session'", [$name], $other);
            }
        }
        self::assertSame($before, $this->checkpoint());
        self::assertSame($business, $f->snapshot());
        $other = null;
        $f->db->exec("INSERT dbo.TblSMSRuns(Request_ID,Status) VALUES('eeeeeeee-dddd-4ccc-bbbb-aaaaaaaaaaaa','selecting')");
        $business = $f->snapshot();
        $this->refuses(fn () => Import::applyBatch($f->db, self::RUN, 0, $this->events()), 'open SMS run');
        self::assertSame($business, $f->snapshot());
    }

    public function test_finish_requires_the_entire_plan_and_cursor_must_resume_in_order(): void
    {
        $f = self::$fixture;
        $this->begin();
        Import::applyBatch($f->db, self::RUN, 0, array_slice($this->events(), 0, 1));
        $before = $this->checkpoint();
        $business = $f->snapshot();
        $this->refuses(fn () => Import::finish($f->db, self::RUN), 'has not exhausted');
        $this->refuses(fn () => Import::applyBatch($f->db, self::RUN, 2, array_slice($this->events(),2)), 'cursor changed');
        self::assertSame($before,$this->checkpoint());
        self::assertSame($business,$f->snapshot());
        self::assertSame(2,Import::applyBatch($f->db,self::RUN,1,array_slice($this->events(),1))['added']);
        self::assertSame('completed',Import::finish($f->db,self::RUN)['status']);
    }

    public function test_missing_unique_index_and_unexpected_trigger_stop_before_inserts(): void
    {
        $f = self::$fixture;
        $this->begin();
        $before = $this->checkpoint();
        $business = $f->snapshot();
        $f->db->exec('ALTER TABLE dbo.TblSMSLegacySends DROP CONSTRAINT UQ_Legacy_Phone_Date');
        try {
            $this->refuses(fn () => Import::applyBatch($f->db, self::RUN, 0, $this->events()), 'lookup index is unavailable');
            self::assertSame($before, $this->checkpoint());
            self::assertSame($business, $f->snapshot());
        } finally {
            $f->db->exec('ALTER TABLE dbo.TblSMSLegacySends ADD CONSTRAINT UQ_Legacy_Phone_Date UNIQUE(Phone,Send_Date)');
        }
        $f->db->exec('CREATE TRIGGER dbo.SmsLegacyImportTestSideEffect ON dbo.TblSMSLegacySends AFTER INSERT AS BEGIN UPDATE dbo.TblProspects SET SMS_Attempts=999; END');
        try {
            $this->refuses(fn () => Import::applyBatch($f->db, self::RUN, 0, $this->events()), 'Review legacy-send triggers');
            self::assertSame($before, $this->checkpoint());
            self::assertSame($business, $f->snapshot());
        } finally {
            $f->db->exec('DROP TRIGGER dbo.SmsLegacyImportTestSideEffect');
        }
    }

    public function test_full_250_event_chunk_handles_production_unicode_phone_schema(): void
    {
        $f = self::$fixture;
        $f->assertOwnedDatabase();
        $f->db->exec('ALTER TABLE dbo.TblSMSLegacySends DROP CONSTRAINT UQ_Legacy_Phone_Date');
        $f->db->exec('ALTER TABLE dbo.TblSMSLegacySends ALTER COLUMN Phone nvarchar(10) NOT NULL');
        $f->db->exec('ALTER TABLE dbo.TblSMSLegacySends ADD CONSTRAINT UQ_Legacy_Phone_Date UNIQUE(Phone,Send_Date)');
        try {
            $events = [];
            foreach (range(1, 250) as $id) $events[] = ['Event_ID' => $id, 'Phone' => (string) (2025551000 + $id), 'Send_Date' => '2026-10-08'];
            $proof = $this->proof();
            foreach (['rows', 'phone_occurrences', 'unique_events', 'event_max_id'] as $key) $proof[$key] = 250;
            $proof['files'][0]['rows'] = $proof['files'][0]['phone_occurrences'] = 250;
            Import::begin($f->db, self::RUN, $proof, 'actor', true);
            $business = $this->otherBusiness();
            $before = count($this->legacy());
            self::assertSame(250, Import::applyBatch($f->db, self::RUN, 0, $events)['added']);
            self::assertCount($before + 250, $this->legacy());
            self::assertCount(250, $f->rows('SELECT Event_ID FROM dbo.TblSMSLegacyImportRows'));
            self::assertSame($business, $this->otherBusiness());
            self::assertSame('completed', Import::finish($f->db, self::RUN)['status']);
        } finally {
            $f->db->exec('ALTER TABLE dbo.TblSMSLegacySends DROP CONSTRAINT UQ_Legacy_Phone_Date');
            $f->db->exec('ALTER TABLE dbo.TblSMSLegacySends ALTER COLUMN Phone varchar(10) NOT NULL');
            $f->db->exec('ALTER TABLE dbo.TblSMSLegacySends ADD CONSTRAINT UQ_Legacy_Phone_Date UNIQUE(Phone,Send_Date)');
        }
    }
}
