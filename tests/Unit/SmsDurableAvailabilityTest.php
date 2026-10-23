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
