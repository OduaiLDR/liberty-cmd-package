<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Repositories\SmsProspectExportRepository;
use Cmd\Reports\Services\SmsTuCompletionDate;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Facade;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Real in-memory source metadata; never boot the host application or external services. */
final class SmsTuCompletionDateTest extends TestCase
{
    private Container $previous;
    private Container $app;
    private Connection $db;

    protected function setUp(): void
    {
        $this->previous = Container::getInstance();
        $this->app = new Container;
        Container::setInstance($this->app);
        $this->app->instance('config', new Repository(['sms-exports' => [
            'durable_availability_enabled' => true, 'tu_completion_date_enabled' => true]]));
        $capsule = new Manager($this->app);
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:'], 'sqlsrv');
        $this->app->instance('db', $capsule->getDatabaseManager());
        Facade::clearResolvedInstances(); Facade::setFacadeApplication($this->app);
        $this->db = $capsule->getConnection('sqlsrv');
    }

    protected function tearDown(): void
    {
        $this->db->disconnect();
        Facade::clearResolvedInstances();
        Container::setInstance($this->previous); Facade::setFacadeApplication($this->previous);
    }

    private function schema(): void
    {
        $this->db->getSchemaBuilder()->create('TblTUPipelineBatches', function (Blueprint $t): void {
            $t->string('Batch_ID')->primary(); $t->date('Data_Date')->nullable();
            $t->string('Status'); $t->dateTime('Created_At');
        });
        $this->db->getSchemaBuilder()->create('TblTUPipelinePhases', function (Blueprint $t): void {
            $t->string('Batch_ID'); $t->string('Phase'); $t->primary(['Batch_ID', 'Phase']);
        });
    }

    private function batch(string $id, ?string $date, string $status, ?string $phase = null): void
    {
        $this->db->table('TblTUPipelineBatches')->insert(['Batch_ID' => $id, 'Data_Date' => $date,
            'Status' => $status, 'Created_At' => '2026-12-31 23:59:59']);
        if ($phase !== null) $this->db->table('TblTUPipelinePhases')->insert(['Batch_ID' => $id, 'Phase' => $phase]);
    }

    private function latest(): ?string
    {
        return $this->db->transaction(fn () => (new SmsTuCompletionDate)->latest($this->db));
    }

    public function test_no_completion_proof_means_no_data_imported_not_upload_or_count_time(): void
    {
        $this->schema();
        self::assertNull($this->latest());
        foreach (['uploaded', 'processing', 'failed', 'complete'] as $i => $status) {
            $this->batch('pending-'.$i, '2026-12-31', $status, 'prospects-published');
        }
        self::assertNull($this->latest(), 'Even a mutable complete status is insufficient without its checkpoint.');
    }

    public function test_latest_completed_dataset_date_survives_old_batch_replay_and_later_operational_failure(): void
    {
        $this->schema();
        $this->batch('newer-data', '2026-10-20', 'failed', 'summary-refreshed');
        $this->batch('older-data', '2026-10-13', 'complete', 'summary-refreshed');
        $this->batch('new-upload', '2026-10-27', 'awaiting_return', 'prospects-published');
        self::assertSame('2026-10-20', $this->latest());
        $this->db->table('TblTUPipelineBatches')->where('Batch_ID', 'older-data')->update(['Created_At' => '2027-01-01']);
        self::assertSame('2026-10-20', $this->latest());
    }

    public function test_missing_or_partial_source_schema_refuses_to_invent_a_date(): void
    {
        try { $this->latest(); self::fail('Expected missing schema refusal.'); }
        catch (RuntimeException $error) { self::assertStringContainsString('completion schema', $error->getMessage()); }
        $this->schema();
        $this->db->getSchemaBuilder()->drop('TblTUPipelinePhases');
        $this->expectException(RuntimeException::class);
        $this->latest();
    }

    public function test_null_completed_date_is_invalid_evidence_not_a_missing_batch(): void
    {
        $this->schema(); $this->batch('bad', null, 'complete', 'summary-refreshed');
        $this->expectException(RuntimeException::class);
        $this->latest();
    }

    public function test_calendar_validation_is_strict_and_timezone_independent(): void
    {
        foreach ([null, '2024-02-29', '2026-10-20'] as $value) self::assertSame($value, SmsTuCompletionDate::validate($value));
        foreach (['2026-02-29', '2026-13-01', '2026-00-10', '0000-01-01', '2026-10-20T00:00:00Z',
            '2026-1-01', ' 2026-10-20', 20261020, false, []] as $value) {
            try { SmsTuCompletionDate::validate($value); self::fail('Expected invalid date refusal.'); }
            catch (InvalidArgumentException) { self::assertTrue(true); }
        }
    }

    public function test_reader_requires_the_existing_count_transaction_and_durable_rollout(): void
    {
        try { (new SmsTuCompletionDate)->latest($this->db); self::fail('Expected transaction refusal.'); }
        catch (LogicException) { self::assertTrue(true); }
        config(['sms-exports.durable_availability_enabled' => false]);
        $this->expectException(LogicException::class); $this->latest();
    }

    public function test_actual_repository_reads_metadata_in_its_count_transaction_on_the_same_connection(): void
    {
        $this->schema(); $this->batch('complete', '2026-10-20', 'complete', 'summary-refreshed');
        $export = new class extends SmsProspectExportRepository {
            public int $countLevel = 0;
            public function availabilityWithTimeout(): array
            {
                $this->countLevel = $this->connection()->transactionLevel();
                return ['eligible' => 3, 'never_texted' => 2, 'texted_before' => 1];
            }
        };
        $result = $export->availabilitySnapshotWithTimeout();
        self::assertSame(1, $export->countLevel);
        self::assertSame(['eligible' => 3, 'never_texted' => 2, 'texted_before' => 1, 'latest_data_date' => '2026-10-20'], $result);
        self::assertSame(0, $this->db->transactionLevel());
    }

    public function test_disabled_contract_performs_no_tu_schema_or_data_reads_and_preserves_old_shape(): void
    {
        config(['sms-exports.tu_completion_date_enabled' => false]);
        $this->db->enableQueryLog();
        $export = new class extends SmsProspectExportRepository {
            public function availabilityWithTimeout(): array { return ['eligible' => 0, 'never_texted' => 0, 'texted_before' => 0]; }
        };
        self::assertSame(['eligible' => 0, 'never_texted' => 0, 'texted_before' => 0], $export->availabilitySnapshotWithTimeout());
        self::assertSame([], $this->db->getQueryLog());
    }
}
