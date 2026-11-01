<?php

namespace Cmd\Reports\Tests\Unit;

use Carbon\Carbon;
use Cmd\Reports\Jobs\CountDurableSmsAvailabilityJob;
use Cmd\Reports\Repositories\SmsProspectExportRepository;
use Cmd\Reports\Services\SmsAvailabilityLedger;
use Cmd\Reports\Services\SmsAvailabilityService;
use Cmd\Reports\Services\SmsDurableAvailabilityService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Support\Facades\Facade;
use Mockery;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Real SQLite ledger and fake async dispatcher; no app bootstrap, cache or provider. */
final class SmsDurableAvailabilityTest extends TestCase
{
    private Container $previous;
    private Container $app;
    private Connection $db;
    private SmsAvailabilityLedger $ledger;
    private SmsDurableAvailabilityService $service;
    private array $jobs = [];
    private bool $dispatchFails = false;
    private const COUNTS = ['eligible' => 11, 'never_texted' => 7, 'texted_before' => 4];

    protected function setUp(): void
    {
        $this->previous = Container::getInstance();
        $this->app = new Container;
        Container::setInstance($this->app);
        $this->app->instance('config', new Repository([
            'app' => ['env' => 'testing'],
            'database' => ['connections' => ['sqlsrv' => ['host' => 'local.invalid', 'database' => 'synthetic_sms']]],
            'sms-exports' => ['durable_availability_enabled' => true, 'queue' => 'sms-test'],
            'queue' => ['default' => 'database', 'connections' => ['database' => ['retry_after' => 15000]]],
        ]));
        $capsule = new Manager($this->app);
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:'], 'sqlsrv');
        $this->app->instance('db', $capsule->getDatabaseManager());
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);
        $this->db = $capsule->getConnection('sqlsrv');
        $this->db->setTransactionManager(new DatabaseTransactionsManager);
        (require dirname(__DIR__, 2).'/database/sms-migrations/2026_10_20_000001_create_sms_availability_summary.php')->up();
        (require dirname(__DIR__, 2).'/database/sms-migrations/2026_10_22_000002_add_sms_tu_summary_date_proof.php')->up();
        $this->ledger = new SmsAvailabilityLedger;
        $this->service = new SmsDurableAvailabilityService($this->ledger);
        $this->app->instance(SmsAvailabilityLedger::class, $this->ledger);
        $this->app->instance(SmsDurableAvailabilityService::class, $this->service);
        $bus = Mockery::mock(Dispatcher::class);
        $bus->shouldReceive('dispatch')->andReturnUsing(function ($job): int {
            $this->jobs[] = $job;
            if ($this->dispatchFails) throw new RuntimeException('PRIVATE dispatch outage');
            return 1;
        });
        $this->app->instance(Dispatcher::class, $bus);
        Carbon::setTestNow('2026-10-20 12:00:00 UTC');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->db->disconnect();
        Mockery::close();
        Facade::clearResolvedInstances();
        Container::setInstance($this->previous);
        Facade::setFacadeApplication($this->previous);
    }

    private function export(?callable $callback = null): SmsProspectExportRepository
    {
        $export = Mockery::mock(SmsProspectExportRepository::class);
        $export->shouldReceive('availabilitySnapshotWithTimeout')->once()->andReturnUsing($callback ?? fn () => self::COUNTS);
        $export->shouldNotReceive('availabilityWithTimeout');
        $export->shouldNotReceive('markContacted');
        $export->shouldNotReceive('select');
        return $export;
    }

    private function good(): array
    {
        $this->service->request();
        $this->jobs[0]->handle($this->service, $this->export());
        return $this->service->request();
    }

    public function test_http_facade_only_queues_and_reads_database_without_cache_or_inline_count(): void
    {
        $result = (new SmsAvailabilityService)->snapshot();
        self::assertSame('pending', $result['state']);
        self::assertTrue($result['queued_now']);
        self::assertNull($result['latest_data_date']);
        self::assertSame('legacy-phone-v1', $result['selection_policy']);
        self::assertInstanceOf(CountDurableSmsAvailabilityJob::class, $this->jobs[0]);
        $again = (new SmsAvailabilityService)->snapshot(true);
        self::assertArrayNotHasKey('queued_now', $again);
        self::assertCount(1, $this->jobs);
    }

    public function test_worker_publishes_atomic_counts_but_never_advances_the_tu_data_date(): void
    {
        $result = $this->good();
        self::assertSame('ready', $result['state']);
        self::assertSame(11, $result['eligible']);
        self::assertSame(7, $result['never_texted']);
        self::assertSame(4, $result['texted_before']);
        self::assertSame('2026-10-20T12:00:00+00:00', $result['counted_at']);
        self::assertNull($result['latest_data_date']);
        self::assertFalse($result['latest_data_date_available']);
        self::assertCount(1, $this->jobs);
        self::assertFalse($this->app->bound('cache'));
    }

    public function test_dispatch_failure_retains_last_good_and_scheduler_style_request_retries_after_cooldown(): void
    {
        $good = $this->good();
        $this->dispatchFails = true;
        $failed = $this->service->request(true);
        self::assertSame('failed', $failed['state']);
        self::assertTrue($failed['stale']);
        self::assertSame($good['counted_at'], $failed['counted_at']);
        self::assertSame($good['eligible'], $failed['eligible']);
        self::assertStringNotContainsString('PRIVATE', json_encode($failed));
        self::assertCount(2, $this->jobs);
        $this->service->request(true);
        self::assertCount(2, $this->jobs);
        Carbon::setTestNow(now()->addSeconds(SmsAvailabilityLedger::RETRY_SECONDS + 1));
        $this->dispatchFails = false;
        self::assertTrue($this->service->request()['queued_now']);
        self::assertCount(3, $this->jobs);
        $this->jobs[2]->handle($this->service, $this->export());
        self::assertSame('ready', $this->service->request()['state']);
    }

    private function withDate(?string $date): array
    {
        return self::COUNTS + ['latest_data_date' => $date];
    }

    private function goodDate(?string $date): array
    {
        config(['sms-exports.tu_completion_date_enabled' => true]);
        $this->service->request();
        $this->jobs[0]->handle($this->service, $this->export(fn () => $this->withDate($date)));
        return $this->service->request();
    }

    public function test_completed_tu_date_and_counts_publish_together_and_remain_distinct_from_count_time(): void
    {
        $result = $this->goodDate('2026-10-13');
        self::assertSame('ready', $result['state']);
        self::assertSame('2026-10-13', $result['latest_data_date']);
        self::assertTrue($result['latest_data_date_available']);
        self::assertSame('2026-10-20T12:00:00+00:00', $result['counted_at']);
        self::assertSame(11, $result['eligible']);
    }

    public function test_before_first_completed_batch_date_remains_null_even_with_successful_counts(): void
    {
        $result = $this->goodDate(null);
        self::assertSame('ready', $result['state']); self::assertNull($result['latest_data_date']);
        self::assertTrue($result['latest_data_date_available']);
        self::assertSame(11, $result['eligible']);
    }

    public function test_missing_invalid_or_regressed_date_keeps_the_entire_previous_summary(): void
    {
        $good = $this->goodDate('2026-10-13');
        foreach ([[], ['latest_data_date' => '2026-02-29'], ['latest_data_date' => null], ['latest_data_date' => '2026-10-06']] as $data) {
            $this->service->request(true);
            $job = $this->jobs[array_key_last($this->jobs)];
            try {
                $job->handle($this->service, $this->export(fn () => ['eligible' => 99, 'never_texted' => 90, 'texted_before' => 9] + $data));
                self::fail('Expected invalid completion evidence refusal.');
            } catch (\InvalidArgumentException|\LogicException) { self::assertTrue(true); }
            $failed = $this->service->request();
            self::assertSame('failed', $failed['state']);
            foreach (['eligible', 'never_texted', 'texted_before', 'counted_at', 'latest_data_date'] as $key) self::assertSame($good[$key], $failed[$key]);
            Carbon::setTestNow(now()->addSeconds(SmsAvailabilityLedger::RETRY_SECONDS + 1));
        }
    }

    public function test_completion_racing_count_invalidates_the_whole_tuple_then_retries_with_new_date(): void
    {
        $good = $this->goodDate('2026-10-13');
        $this->service->request(true);
        $this->jobs[1]->handle($this->service, $this->export(function (): array {
            $this->db->transaction(fn () => $this->ledger->dirty($this->db));
            return ['eligible' => 99, 'never_texted' => 90, 'texted_before' => 9, 'latest_data_date' => '2026-10-20'];
        }));
        $pending = $this->service->request();
        self::assertSame('pending', $pending['state']);
        foreach (['eligible', 'counted_at', 'latest_data_date'] as $key) self::assertSame($good[$key], $pending[$key]);
        $this->jobs[2]->handle($this->service, $this->export(fn () => $this->withDate('2026-10-20')));
        self::assertSame('2026-10-20', $this->service->request()['latest_data_date']);
    }

    public function test_flag_rollback_does_not_clear_a_previously_verified_date_or_accept_injected_dates(): void
    {
        $this->goodDate('2026-10-13');
        config(['sms-exports.tu_completion_date_enabled' => false]);
        $this->service->request(true);
        $this->jobs[1]->handle($this->service, $this->export(fn () => $this->withDate('2099-01-01')));
        $result = $this->service->request();
        self::assertSame('2026-10-13', $result['latest_data_date']);
        self::assertFalse($result['latest_data_date_available']);
    }

    public function test_missing_completion_source_fails_the_real_snapshot_and_retains_the_last_tuple(): void
    {
        $good = $this->goodDate('2026-10-13');
        $this->service->request(true);
        $export = new class extends SmsProspectExportRepository {
            public function availabilityWithTimeout(): array { return ['eligible' => 99, 'never_texted' => 90, 'texted_before' => 9]; }
        };
        try { $this->jobs[1]->handle($this->service, $export); self::fail('Missing TU schema must fail the snapshot.'); }
        catch (RuntimeException $error) { self::assertStringContainsString('completion schema', $error->getMessage()); }
        $failed = $this->service->request();
        self::assertSame('failed', $failed['state']);
        foreach (['eligible', 'never_texted', 'texted_before', 'counted_at', 'latest_data_date'] as $key) self::assertSame($good[$key], $failed[$key]);
        self::assertSame(0, $this->db->transactionLevel());
    }

    public function test_enabling_date_reporting_never_relabels_old_cached_counts_as_no_data_imported(): void
    {
        $this->good();
        config(['sms-exports.tu_completion_date_enabled' => true]);
        $old = $this->service->request();
        self::assertSame('ready', $old['state']); self::assertNull($old['latest_data_date']);
        self::assertFalse($old['latest_data_date_available']);
        $this->service->request(true);
        $this->jobs[1]->handle($this->service, $this->export(fn () => $this->withDate(null)));
        self::assertTrue($this->service->request()['latest_data_date_available']);
    }

    public function test_disabled_recount_invalidates_date_proof_before_a_later_reenable(): void
    {
        $this->goodDate('2026-10-13');
        config(['sms-exports.tu_completion_date_enabled' => false]);
        $this->service->request(true);
        $this->jobs[1]->handle($this->service, $this->export());
        config(['sms-exports.tu_completion_date_enabled' => true]);
        $result = $this->service->request();
        self::assertSame('2026-10-13', $result['latest_data_date']);
        self::assertFalse($result['latest_data_date_available']);
    }

    public function test_missing_date_proof_migration_fails_closed_without_losing_legacy_counts(): void
    {
        $good = $this->good();
        $this->db->getSchemaBuilder()->table(SmsAvailabilityLedger::TABLE, fn ($t) => $t->dropColumn('tu_date_verified'));
        config(['sms-exports.tu_completion_date_enabled' => true]);
        $this->service->request(true);
        try { $this->jobs[1]->handle($this->service, $this->export(fn () => $this->withDate(null))); self::fail('Expected migration guard.'); }
        catch (\LogicException $error) { self::assertStringContainsString('date-proof migration', $error->getMessage()); }
        $failed = $this->service->request();
        self::assertSame('failed', $failed['state']); self::assertFalse($failed['latest_data_date_available']);
        self::assertSame($good['eligible'], $failed['eligible']); self::assertSame($good['counted_at'], $failed['counted_at']);
    }

    public function test_additive_date_proof_migration_retains_existing_summary_without_claiming_verification(): void
    {
        $good = $this->good();
        $this->db->getSchemaBuilder()->table(SmsAvailabilityLedger::TABLE, fn ($t) => $t->dropColumn('tu_date_verified'));
        $migration = require dirname(__DIR__, 2).'/database/sms-migrations/2026_10_22_000002_add_sms_tu_summary_date_proof.php';
        $migration->up();
        self::assertSame(0, (int) $this->ledger->read()['tu_date_verified']);
        self::assertSame($good['eligible'], $this->service->request()['eligible']);
        self::assertSame($good['counted_at'], $this->service->request()['counted_at']);
        try { $migration->up(); self::fail('An already installed date-proof schema must be refused.'); }
        catch (RuntimeException $error) { self::assertStringContainsString('already exists', $error->getMessage()); }
        try { $migration->down(); self::fail('Date proof must be retained.'); }
        catch (RuntimeException $error) { self::assertStringContainsString('Retain', $error->getMessage()); }
        self::assertSame(0, (int) $this->ledger->read()['tu_date_verified']);
    }

    public function test_dirty_mid_query_discards_sample_and_coalesces_one_follow_up(): void
    {
        $good = $this->good();
        $this->service->request(true);
        $this->jobs[1]->handle($this->service, $this->export(function (): array {
            $this->db->transaction(fn () => $this->ledger->dirty($this->db));
            return ['eligible' => 99, 'never_texted' => 90, 'texted_before' => 9];
        }));
        $pending = $this->service->request();
        self::assertSame('pending', $pending['state']);
        self::assertTrue($pending['stale']);
        self::assertSame($good['eligible'], $pending['eligible']);
        self::assertSame($good['counted_at'], $pending['counted_at']);
        self::assertCount(3, $this->jobs);
        $this->jobs[2]->handle($this->service, $this->export());
        self::assertSame('ready', $this->service->request()['state']);
    }

    public function test_failed_query_keeps_success_timestamp_and_late_callback_cannot_overwrite_a_new_generation(): void
    {
        $good = $this->good();
        $this->service->request(true);
        try {
            $this->jobs[1]->handle($this->service, $this->export(fn () => throw new RuntimeException('PRIVATE query error')));
            self::fail('Expected worker exception.');
        } catch (RuntimeException $error) { self::assertSame('PRIVATE query error', $error->getMessage()); }
        self::assertSame('failed', $this->service->request()['state']);
        Carbon::setTestNow(now()->addSeconds(SmsAvailabilityLedger::RETRY_SECONDS + 1));
        $this->service->request();
        $this->jobs[1]->failed(null);
        $pending = $this->service->request();
        self::assertSame('pending', $pending['state']);
        self::assertSame($good['counted_at'], $pending['counted_at']);
        $this->jobs[2]->handle($this->service, $this->export());
        $this->jobs[1]->failed(null);
        self::assertSame('ready', $this->service->request()['state']);
    }

    public function test_bad_queue_configuration_fails_closed_with_last_good_not_an_inline_fallback(): void
    {
        $good = $this->good();
        config(['queue.default' => 'sync']);
        $failed = $this->service->request(true);
        self::assertSame('failed', $failed['state']);
        self::assertSame($good['eligible'], $failed['eligible']);
        self::assertSame($good['counted_at'], $failed['counted_at']);
        self::assertCount(1, $this->jobs);
    }

    public function test_disabled_new_job_does_not_count_and_legacy_job_cannot_count_when_new_mode_enabled(): void
    {
        $this->service->request();
        $export = Mockery::mock(SmsProspectExportRepository::class);
        $export->shouldNotReceive('availabilitySnapshotWithTimeout');
        $export->shouldNotReceive('availabilityWithTimeout');
        (new SmsAvailabilityService)->count('old-scope', 'old-generation', 'old-owner', $export);
        config(['sms-exports.durable_availability_enabled' => false]);
        $this->jobs[0]->handle($this->service, $export);
        self::assertSame('queued', $this->ledger->read()['status']);
    }
}
