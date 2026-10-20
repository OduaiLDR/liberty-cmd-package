<?php

namespace Cmd\Reports\Tests\Unit;

use Carbon\Carbon;
use Cmd\Reports\Http\Controllers\SmsRunController;
use Cmd\Reports\Jobs\CountSmsAvailabilityJob;
use Cmd\Reports\Repositories\SmsProspectExportRepository;
use Cmd\Reports\Services\SmsAvailabilityService;
use Cmd\Reports\Services\SmsExportQueue;
use Cmd\Reports\Services\SmsRunService;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\CacheManager;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\SqlServerConnection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Illuminate\Validation\ValidationException;
use Mockery;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class SmsAvailabilityTest extends TestCase
{
    private Container $previous;
    private Container $app;
    private SmsAvailabilityService $service;
    private CacheManager $cache;
    private array $jobs;
    private mixed $dispatchHook;
    private array $counts = ['eligible' => 11, 'never_texted' => 7, 'texted_before' => 4];

    protected function setUp(): void
    {
        $this->previous = Container::getInstance();
        $this->app = new class extends Container {
            public function environment(string ...$names): bool { return in_array($this['config']->get('app.env'), $names, true); }
        };
        Container::setInstance($this->app);
        $this->app->instance('config', new Repository([
            'app' => ['env' => 'testing'],
            'database' => ['connections' => ['sqlsrv' => ['host' => 'test.invalid', 'database' => 'synthetic_sms']]],
            'sms-exports' => ['queue' => 'sms-demo', 'demo' => false],
            'queue' => ['default' => 'database', 'connections' => ['database' => ['retry_after' => 15000]]],
            'cache' => ['default' => 'array', 'stores' => ['array' => ['driver' => 'array']]],
        ]));
        $this->cache = new CacheManager($this->app);
        $this->app->instance('cache', $this->cache);
        $this->app->instance('validator', new Factory(new Translator(new ArrayLoader, 'en'), $this->app));
        $this->service = new SmsAvailabilityService;
        $this->app->instance(SmsAvailabilityService::class, $this->service);
        $this->jobs = [];
        $this->dispatchHook = null;
        $bus = Mockery::mock(Dispatcher::class);
        $bus->shouldReceive('dispatch')->andReturnUsing(function ($job) {
            $this->jobs[] = $job;
            if ($this->dispatchHook) ($this->dispatchHook)($job);
            return 1;
        });
        $this->app->instance(Dispatcher::class, $bus);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);
        Carbon::setTestNow('2026-10-20 01:00:00 UTC');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();
        Facade::clearResolvedInstances();
        Container::setInstance($this->previous);
        Facade::setFacadeApplication($this->previous);
    }

    private function export(?callable $work = null): SmsProspectExportRepository
    {
        $export = Mockery::mock(SmsProspectExportRepository::class);
        $export->shouldReceive('availabilityWithTimeout')->once()->andReturnUsing($work ?? fn () => $this->counts);
        $export->shouldNotReceive('select');
        $export->shouldNotReceive('markContacted');
        return $export;
    }

    private function controller(): SmsRunController
    {
        return new class(Mockery::mock(SmsRunService::class)) extends SmsRunController {
            protected function ensureSchemaReady(): void {} // No database exists in this isolated contract test.
        };
    }

    private function finishFirst(?callable $work = null): void
    {
        $this->jobs[0]->handle($this->service, $this->export($work));
    }

    private function publicationFailures(array $states, bool $throw, bool $afterWrite = false): ArrayStore
    {
        $store = new class($states, $throw, $afterWrite) extends ArrayStore {
            public function __construct(public array $failures, private bool $throw, public bool $afterWrite) { parent::__construct(); }
            public function put($key, $value, $seconds)
            {
                if (($value['response']['state'] ?? null) === ($this->failures[0] ?? null) && $this->failures !== []) {
                    array_shift($this->failures);
                    if ($this->afterWrite) parent::put($key, $value, $seconds);
                    if ($this->throw) throw new RuntimeException('PRIVATE transient cache publication error');
                    return false;
                }
                return parent::put($key, $value, $seconds);
            }
        };
        $this->cache->extend('array', fn () => new \Illuminate\Cache\Repository($store));
        return $store;
    }

    public function test_initial_get_is_202_and_publishes_pending_before_dispatch_with_no_sync_count(): void
    {
        $this->dispatchHook = function ($job): void {
            self::assertSame('pending', $this->service->snapshot()['state']);
            self::assertSame($job->generation, $this->cache->store()->get($job->scope.':snapshot')['generation']);
        };
        $response = $this->controller()->availability(Request::create('/', 'GET'), $this->service);
        self::assertSame(202, $response->getStatusCode());
        self::assertSame(['state' => 'pending', 'requested_at' => now()->toIso8601String(), 'queued_now' => true, 'demo' => false], $response->getData(true));
        self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        self::assertCount(1, $this->jobs);
        self::assertInstanceOf(CountSmsAvailabilityJob::class, $this->jobs[0]);
    }

    public function test_pending_gets_and_explicit_refresh_coalesce_to_one_job_and_keep_requested_time(): void
    {
        $first = $this->service->snapshot();
        self::assertTrue($first['queued_now']);
        unset($first['queued_now']);
        Carbon::setTestNow(now()->addMinutes(2));
        self::assertSame($first, $this->service->snapshot());
        self::assertSame($first, $this->service->snapshot(true));
        self::assertCount(1, $this->jobs);
    }

    public function test_completion_is_exact_integer_counts_with_actual_completion_time_and_http_200(): void
    {
        $this->service->snapshot();
        $this->finishFirst(function (): array {
            Carbon::setTestNow(now()->addSeconds(42));
            return $this->counts;
        });
        $response = $this->controller()->availability(Request::create('/', 'GET'), $this->service);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['state' => 'ready'] + $this->counts + ['counted_at' => '2026-10-20T01:00:42+00:00', 'demo' => false], $response->getData(true));
        self::assertCount(1, $this->jobs);
    }

    public function test_ready_cache_lives_300_seconds_from_completion_then_queues_a_new_generation(): void
    {
        $this->service->snapshot();
        $this->finishFirst();
        Carbon::setTestNow(now()->addSeconds(299));
        self::assertSame('ready', $this->service->snapshot()['state']);
        Carbon::setTestNow(now()->addSeconds(2));
        self::assertSame('pending', $this->service->snapshot()['state']);
        self::assertCount(2, $this->jobs);
        self::assertNotSame($this->jobs[0]->generation, $this->jobs[1]->generation);
    }

    public function test_refresh_one_queues_fresh_even_when_ready_and_refresh_zero_uses_cache(): void
    {
        $this->service->snapshot();
        $this->finishFirst();
        $controller = $this->controller();
        self::assertSame(200, $controller->availability(Request::create('/?refresh=0', 'GET'), $this->service)->getStatusCode());
        $refresh = $controller->availability(Request::create('/?refresh=1', 'GET'), $this->service);
        self::assertSame(202, $refresh->getStatusCode());
        self::assertTrue($refresh->getData(true)['queued_now']);
        self::assertArrayNotHasKey('queued_now', $this->service->snapshot(true));
        self::assertArrayNotHasKey('queued_now', $this->cache->store()->get($this->jobs[1]->scope.':snapshot')['response']);
        self::assertCount(2, $this->jobs);
    }

    public function test_queue_acknowledgement_is_only_on_successful_new_dispatch_and_never_cached(): void
    {
        self::assertTrue($this->service->snapshot()['queued_now']);
        self::assertArrayNotHasKey('queued_now', $this->service->snapshot());
        self::assertArrayNotHasKey('queued_now', $this->service->snapshot(true));
        self::assertArrayNotHasKey('queued_now', $this->cache->store()->get($this->jobs[0]->scope.':snapshot')['response']);
        self::assertCount(1, $this->jobs);
        $this->jobs[0]->failed(null);
        $this->dispatchHook = fn () => throw new RuntimeException('dispatch failed');
        self::assertArrayNotHasKey('queued_now', $this->service->snapshot(true));
    }

    public function test_invalid_refresh_is_rejected_before_queueing(): void
    {
        try {
            $this->controller()->availability(Request::create('/?refresh=yes', 'GET'), $this->service);
            self::fail('Expected boolean validation.');
        } catch (ValidationException $error) {
            self::assertArrayHasKey('refresh', $error->errors());
        }
        self::assertCount(0, $this->jobs);
    }

    public function test_dispatch_failure_has_safe_failed_status_releases_lease_and_can_be_retried(): void
    {
        $this->dispatchHook = fn () => throw new RuntimeException('PRIVATE database password and SQL');
        $response = $this->controller()->availability(Request::create('/', 'GET'), $this->service);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('failed', $response->getData(true)['state']);
        self::assertStringNotContainsString('PRIVATE', $response->getContent());
        $old = $this->jobs[0];
        $lock = $this->cache->store()->getStore()->lock($old->scope.':count', 1);
        self::assertTrue($lock->get());
        $lock->release();
        self::assertSame('failed', $this->service->snapshot()['state']);
        $this->dispatchHook = null;
        self::assertSame('pending', $this->service->snapshot(true)['state']);
        self::assertCount(2, $this->jobs);
    }

    public function test_failed_cache_expires_and_does_not_pin_recovery_forever(): void
    {
        $this->dispatchHook = fn () => throw new RuntimeException('offline');
        $this->service->snapshot();
        $this->dispatchHook = null;
        Carbon::setTestNow(now()->addSeconds(61));
        self::assertSame('pending', $this->service->snapshot()['state']);
        self::assertCount(2, $this->jobs);
    }

    public function test_query_failure_and_worker_failed_callback_are_safe_idempotent_and_release_owned_lease(): void
    {
        $this->service->snapshot();
        try { $this->finishFirst(fn () => throw new RuntimeException('PRIVATE SQL payload')); self::fail('Expected query failure.'); }
        catch (RuntimeException $error) { self::assertSame('PRIVATE SQL payload', $error->getMessage()); }
        $this->jobs[0]->failed(new RuntimeException('PRIVATE worker details'));
        $failed = $this->service->snapshot();
        self::assertSame('failed', $failed['state']);
        self::assertStringNotContainsString('PRIVATE', json_encode($failed));
        self::assertSame('pending', $this->service->snapshot(true)['state']);
        // Delayed failed callback from the old generation must not disturb the new one.
        $this->jobs[0]->failed(null);
        self::assertSame('pending', $this->service->snapshot()['state']);
        self::assertFalse($this->cache->store()->getStore()->lock($this->jobs[1]->scope.':count', 1)->get());
    }

    public function test_worker_failed_before_handle_cleans_up_pending(): void
    {
        $this->service->snapshot();
        $this->jobs[0]->failed(null);
        self::assertSame('failed', $this->service->snapshot()['state']);
        self::assertSame('pending', $this->service->snapshot(true)['state']);
        self::assertCount(2, $this->jobs);
    }

    public static function publicationFailureModes(): array { return [[false], [true]]; }

    #[DataProvider('publicationFailureModes')]
    public function test_one_ready_publication_failure_becomes_safe_failed_and_refresh_queues_replacement(bool $throw): void
    {
        $this->publicationFailures(['ready'], $throw);
        $this->service->snapshot();
        try { $this->finishFirst(); self::fail('Expected ready publication failure.'); }
        catch (RuntimeException) {}
        $response = $this->controller()->availability(Request::create('/', 'GET'), $this->service);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('failed', $response->getData(true)['state']);
        self::assertStringNotContainsString('PRIVATE', $response->getContent());
        $entry = $this->cache->store()->get($this->jobs[0]->scope.':snapshot');
        self::assertSame(['generation', 'response', 'revision', 'count_revision', 'valid_until', 'last_good'], array_keys($entry));
        self::assertSame(0, $entry['revision']);
        self::assertSame(0, $entry['count_revision']);
        self::assertNull($entry['last_good'], 'A rejected ready publication is not a successful snapshot.');
        self::assertArrayNotHasKey('owner', $entry, 'A published failure is terminal and releases ownership.');
        $replacement = $this->service->snapshot(true);
        self::assertSame('pending', $replacement['state']);
        self::assertTrue($replacement['queued_now']);
        self::assertCount(2, $this->jobs);
        self::assertNotSame($this->jobs[0]->generation, $this->jobs[1]->generation);
    }

    #[DataProvider('publicationFailureModes')]
    public function test_ready_and_failure_publication_errors_keep_ownership_until_worker_callback_can_publish(bool $throw): void
    {
        $store = $this->publicationFailures(['ready', 'failed'], $throw);
        $this->service->snapshot();
        try { $this->finishFirst(); self::fail('Expected failure publication error.'); }
        catch (RuntimeException) {}
        $old = $this->jobs[0];
        self::assertTrue($store->restoreLock($old->scope.':count', $old->owner)->isOwnedByCurrentProcess());
        self::assertSame('pending', $this->service->snapshot()['state']);
        $old->failed(new RuntimeException('PRIVATE worker error'));
        self::assertSame('failed', $this->service->snapshot()['state']);
        self::assertFalse($store->restoreLock($old->scope.':count', $old->owner)->isOwnedByCurrentProcess());
        self::assertTrue($this->service->snapshot(true)['queued_now']);
        $old->failed(null);
        self::assertSame('pending', $this->service->snapshot()['state']);
        self::assertTrue($store->restoreLock($this->jobs[1]->scope.':count', $this->jobs[1]->owner)->isOwnedByCurrentProcess());
    }

    #[DataProvider('publicationFailureModes')]
    public function test_failed_callback_publication_can_be_retried_without_losing_the_lease(bool $throw): void
    {
        $store = $this->publicationFailures(['failed'], $throw);
        $this->service->snapshot();
        $old = $this->jobs[0];
        try { $old->failed(null); self::fail('Expected failed publication error.'); }
        catch (RuntimeException) {}
        self::assertTrue($store->restoreLock($old->scope.':count', $old->owner)->isOwnedByCurrentProcess());
        $old->failed(null);
        self::assertSame('failed', $this->service->snapshot()['state']);
        self::assertTrue($this->service->snapshot(true)['queued_now']);
        self::assertCount(2, $this->jobs);
    }

    public function test_ready_write_committed_before_transport_error_is_retained_and_owned_lease_is_cleaned_up(): void
    {
        $this->publicationFailures(['ready'], true, true);
        $this->service->snapshot();
        try { $this->finishFirst(); self::fail('Expected transport error after cache commit.'); }
        catch (RuntimeException) {}
        self::assertSame('ready', $this->service->snapshot()['state']);
        self::assertSame($this->counts['eligible'], $this->service->snapshot()['eligible']);
        self::assertTrue($this->service->snapshot(true)['queued_now']);
        self::assertCount(2, $this->jobs);
        $this->jobs[0]->failed(null);
        self::assertSame('pending', $this->service->snapshot()['state']);
        self::assertFalse($this->cache->store()->getStore()->lock($this->jobs[1]->scope.':count', 1)->get());
    }

    public function test_repeated_failed_publication_errors_still_allow_bounded_lease_expiry_recovery(): void
    {
        $store = $this->publicationFailures(['failed', 'failed'], true);
        $this->service->snapshot();
        $old = $this->jobs[0];
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try { $old->failed(null); self::fail('Expected repeated publication error.'); }
            catch (RuntimeException) {}
        }
        self::assertTrue($store->restoreLock($old->scope.':count', $old->owner)->isOwnedByCurrentProcess());
        Carbon::setTestNow(now()->addSeconds(SmsAvailabilityService::LEASE_SECONDS + 1));
        self::assertTrue($this->service->snapshot(true)['queued_now']);
        self::assertCount(2, $this->jobs);
        $old->failed(null);
        self::assertTrue($store->restoreLock($this->jobs[1]->scope.':count', $this->jobs[1]->owner)->isOwnedByCurrentProcess());
    }

    public function test_late_old_completion_cannot_overwrite_or_release_a_newer_pending_generation(): void
    {
        $this->service->snapshot();
        $this->finishFirst(function (): array {
            Carbon::setTestNow(now()->addSeconds(SmsAvailabilityService::LEASE_SECONDS + 1));
            self::assertSame('pending', $this->service->snapshot()['state']);
            return $this->counts;
        });
        self::assertCount(2, $this->jobs);
        self::assertSame('pending', $this->service->snapshot()['state']);
        self::assertFalse($this->cache->store()->getStore()->lock($this->jobs[1]->scope.':count', 1)->get());
        $this->jobs[1]->handle($this->service, $this->export(fn () => ['eligible' => 5, 'never_texted' => 5, 'texted_before' => 0]));
        self::assertSame(5, $this->service->snapshot()['eligible']);
    }

    public function test_late_old_completion_cannot_overwrite_a_newer_ready_generation(): void
    {
        $this->service->snapshot();
        $this->finishFirst(function (): array {
            Carbon::setTestNow(now()->addSeconds(SmsAvailabilityService::LEASE_SECONDS + 1));
            $this->service->snapshot();
            $this->jobs[1]->handle($this->service, $this->export(fn () => ['eligible' => 2, 'never_texted' => 1, 'texted_before' => 1]));
            return $this->counts;
        });
        self::assertSame(2, $this->service->snapshot()['eligible']);
        $this->jobs[0]->failed(null);
        self::assertSame(2, $this->service->snapshot()['eligible']);
    }

    public function test_expired_queue_wait_recovers_and_the_old_unstarted_job_does_not_count(): void
    {
        $this->service->snapshot();
        Carbon::setTestNow(now()->addSeconds(SmsAvailabilityService::LEASE_SECONDS + 1));
        $this->service->snapshot();
        $export = Mockery::mock(SmsProspectExportRepository::class);
        $export->shouldNotReceive('availabilityWithTimeout');
        $this->jobs[0]->handle($this->service, $export);
        self::assertCount(2, $this->jobs);
        self::assertSame('pending', $this->service->snapshot()['state']);
    }

    public function test_starting_after_queue_wait_renews_a_full_lease_and_duplicate_delivery_does_not_recount(): void
    {
        $this->service->snapshot();
        Carbon::setTestNow(now()->addSeconds(SmsAvailabilityService::LEASE_SECONDS - 1));
        $this->finishFirst(function (): array {
            $unused = Mockery::mock(SmsProspectExportRepository::class);
            $unused->shouldNotReceive('availabilityWithTimeout');
            $this->jobs[0]->handle($this->service, $unused);
            Carbon::setTestNow(now()->addSeconds(599));
            return $this->counts;
        });
        self::assertSame('ready', $this->service->snapshot()['state']);
        self::assertCount(1, $this->jobs);
    }

    public function test_state_mutex_contention_never_blocks_or_dispatches_a_second_job(): void
    {
        $this->service->snapshot();
        $scope = $this->jobs[0]->scope;
        $this->jobs[0]->failed(null);
        $mutex = $this->cache->store()->getStore()->lock($scope.':state', 30);
        self::assertTrue($mutex->get());
        self::assertSame('pending', $this->service->snapshot(true)['state']);
        self::assertCount(1, $this->jobs);
        $mutex->release();
        $this->service->snapshot(true);
        self::assertCount(2, $this->jobs);
    }

    public static function invalidQueues(): array
    {
        return [['sync', 15000], ['redis', 15000], ['database', 90], ['database', SmsExportQueue::JOB_TIMEOUT]];
    }

    #[DataProvider('invalidQueues')]
    public function test_queue_invariant_is_fail_closed_without_dispatch_or_inline_count(string $connection, int $retryAfter): void
    {
        config(['queue.default' => $connection, 'queue.connections.'.$connection.'.retry_after' => $retryAfter]);
        self::assertSame('failed', $this->service->snapshot()['state']);
        self::assertCount(0, $this->jobs);
    }

    public function test_job_has_database_connection_configured_sms_queue_and_a_lease_longer_than_timeout(): void
    {
        $this->service->snapshot();
        $job = unserialize(serialize($this->jobs[0]));
        self::assertSame('database', $job->connection);
        self::assertSame('sms-demo', $job->queue);
        self::assertSame(7000, $job->timeout);
        self::assertTrue($job->failOnTimeout);
        self::assertSame(1, $job->tries);
        self::assertGreaterThan($job->timeout, SmsAvailabilityService::LEASE_SECONDS);
    }

    public function test_process_local_array_cache_is_refused_outside_testing(): void
    {
        config(['app.env' => 'production']);
        self::assertSame('failed', $this->service->snapshot()['state']);
        self::assertCount(0, $this->jobs);
        self::assertSame([], $this->cache->store()->getStore()->all());
    }

    public function test_database_scope_changes_cannot_run_old_jobs_or_reuse_old_counts(): void
    {
        $this->service->snapshot();
        config(['database.connections.sqlsrv.database' => 'other_synthetic_sms']);
        $unused = Mockery::mock(SmsProspectExportRepository::class);
        $unused->shouldNotReceive('availabilityWithTimeout');
        $this->jobs[0]->handle($this->service, $unused);
        $this->service->snapshot();
        self::assertCount(2, $this->jobs);
        self::assertNotSame($this->jobs[0]->scope, $this->jobs[1]->scope);
    }

    public function test_only_allowlisted_aggregate_counts_enter_ready_cache(): void
    {
        $this->service->snapshot();
        $this->finishFirst(fn () => $this->counts + ['phone' => 'PRIVATE', 'row' => 'PRIVATE']);
        $entry = $this->cache->store()->get($this->jobs[0]->scope.':snapshot');
        self::assertStringNotContainsString('PRIVATE', json_encode($entry));
        self::assertSame(['state', 'eligible', 'never_texted', 'texted_before', 'counted_at'], array_keys($entry['response']));
    }

    private function assertStale(array $response, array $good, string $state): void
    {
        self::assertSame($state, $response['state']);
        self::assertTrue($response['stale']);
        foreach (['eligible', 'never_texted', 'texted_before', 'counted_at'] as $key) {
            self::assertSame($good[$key], $response[$key], $key.' must retain its last successful value.');
        }
        self::assertSame([], array_diff(array_keys($response), [
            'state', 'requested_at', 'queued_now', 'message', 'eligible', 'never_texted', 'texted_before', 'counted_at', 'stale',
        ]));
    }

    public function test_a_new_reader_retains_last_good_indefinitely_across_freshness_and_pending_lease_expiry(): void
    {
        $this->service->snapshot();
        $this->finishFirst();
        $good = $this->service->snapshot();
        Carbon::setTestNow(now()->addYears(2));
        $reader = new SmsAvailabilityService;
        $this->assertStale($reader->snapshot(), $good, 'pending');
        self::assertCount(2, $this->jobs);
        Carbon::setTestNow(now()->addSeconds(SmsAvailabilityService::LEASE_SECONDS + 61));
        $this->assertStale((new SmsAvailabilityService)->snapshot(), $good, 'pending');
        self::assertCount(3, $this->jobs);
        $this->jobs[1]->failed(null);
        $this->assertStale($reader->snapshot(), $good, 'pending');
        self::assertTrue($this->cache->store()->getStore()->restoreLock($this->jobs[2]->scope.':count', $this->jobs[2]->owner)->isOwnedByCurrentProcess());
    }

    public function test_pending_and_failed_refreshes_keep_the_original_counted_time_until_a_successful_replacement(): void
    {
        $this->service->snapshot();
        $this->finishFirst();
        $good = $this->service->snapshot();
        Carbon::setTestNow(now()->addMinutes(1));
        $this->assertStale($this->service->snapshot(true), $good, 'pending');
        try {
            $this->jobs[1]->handle($this->service, $this->export(fn () => throw new RuntimeException('PRIVATE SQL')));
            self::fail('Expected refresh query failure.');
        } catch (RuntimeException) {}
        $this->jobs[1]->failed(null);
        $this->assertStale((new SmsAvailabilityService)->snapshot(), $good, 'failed');
        Carbon::setTestNow(now()->addSeconds(61));
        $this->assertStale($this->service->snapshot(), $good, 'pending');
        $next = ['eligible' => 6, 'never_texted' => 2, 'texted_before' => 4];
        $this->jobs[2]->handle($this->service, $this->export(fn () => $next));
        self::assertSame(['state' => 'ready'] + $next + ['counted_at' => now()->toIso8601String()], $this->service->snapshot());
    }

    public function test_zero_is_a_real_last_good_snapshot_and_not_an_absent_count(): void
    {
        $this->service->snapshot();
        $this->finishFirst(fn () => ['eligible' => 0, 'never_texted' => 0, 'texted_before' => 0]);
        $good = $this->service->snapshot();
        $this->assertStale($this->service->snapshot(true), $good, 'pending');
        $this->jobs[1]->failed(null);
        $this->assertStale($this->service->snapshot(), $good, 'failed');
    }

    public function test_last_good_and_stale_responses_store_only_allowlisted_aggregate_fields(): void
    {
        $this->service->snapshot();
        $this->finishFirst(fn () => $this->counts + ['phone' => 'PRIVATE', 'rows' => [['name' => 'PRIVATE']]]);
        $good = $this->service->snapshot();
        $this->assertStale($this->service->snapshot(true), $good, 'pending');
        $this->jobs[1]->failed(null);
        $this->assertStale($this->service->snapshot(), $good, 'failed');
        self::assertStringNotContainsString('PRIVATE', json_encode($this->cache->store()->getStore()->all()));
    }

    public function test_invalidations_before_the_worker_starts_coalesce_into_one_count(): void
    {
        $this->service->invalidate();
        $this->service->invalidate();
        (new SmsAvailabilityService)->invalidate();
        self::assertCount(1, $this->jobs);
        self::assertSame('pending', $this->service->snapshot()['state']);
        self::assertArrayNotHasKey('eligible', $this->service->snapshot());
        $this->finishFirst();
        self::assertCount(1, $this->jobs);
        self::assertSame('ready', $this->service->snapshot()['state']);
    }

    public function test_mid_count_dirty_notifications_automatically_queue_one_follow_up_and_never_publish_stale_as_current(): void
    {
        $this->service->snapshot();
        $this->dispatchHook = function ($job): void {
            $mutex = $this->cache->store()->getStore()->lock($job->scope.':state', 30);
            self::assertTrue($mutex->get(), 'Follow-up dispatch must happen after releasing the state mutex.');
            $mutex->release();
            $this->assertStale($this->service->snapshot(), $this->counts + ['counted_at' => now()->toIso8601String()], 'pending');
        };
        $this->finishFirst(function (): array {
            $this->service->invalidate();
            (new SmsAvailabilityService)->invalidate();
            self::assertCount(1, $this->jobs, 'A running query must not overlap its follow-up.');
            return $this->counts;
        });
        self::assertCount(2, $this->jobs);
        self::assertSame('pending', $this->service->snapshot()['state']);
        $next = ['eligible' => 3, 'never_texted' => 1, 'texted_before' => 2];
        $this->jobs[1]->handle($this->service, $this->export(fn () => $next));
        self::assertSame(['state' => 'ready'] + $next + ['counted_at' => now()->toIso8601String()], $this->service->snapshot());
        self::assertCount(2, $this->jobs);
    }

    public function test_each_count_consumes_only_notifications_committed_before_it_starts(): void
    {
        $this->service->invalidate();
        $this->finishFirst(function (): array {
            $this->service->invalidate();
            return $this->counts;
        });
        $this->service->invalidate(); // Queued, not started: absorbed by the second count.
        self::assertCount(2, $this->jobs);
        $this->jobs[1]->handle($this->service, $this->export(function (): array {
            $this->service->invalidate(); // Already started: requires a third count.
            return $this->counts;
        }));
        self::assertCount(3, $this->jobs);
        $this->jobs[2]->handle($this->service, $this->export());
        self::assertCount(3, $this->jobs);
        self::assertSame('ready', $this->service->snapshot()['state']);
    }

    public function test_invalidation_dispatch_happens_outside_the_mutex_and_can_receive_another_notification(): void
    {
        $this->dispatchHook = function ($job): void {
            $mutex = $this->cache->store()->getStore()->lock($job->scope.':state', 30);
            self::assertTrue($mutex->get());
            $mutex->release();
            $this->service->invalidate();
        };
        $this->service->invalidate();
        self::assertCount(1, $this->jobs);
        $this->finishFirst();
        self::assertCount(1, $this->jobs);
        self::assertSame('ready', $this->service->snapshot()['state']);
    }

    public function test_mid_count_invalidation_follow_up_also_runs_after_a_query_failure(): void
    {
        $this->service->snapshot();
        $this->finishFirst();
        $good = $this->service->snapshot();
        $this->service->snapshot(true);
        try {
            $this->jobs[1]->handle($this->service, $this->export(function (): array {
                $this->service->invalidate();
                throw new RuntimeException('PRIVATE failing query');
            }));
            self::fail('Expected query failure.');
        } catch (RuntimeException) {}
        self::assertCount(3, $this->jobs);
        $this->jobs[1]->failed(null);
        $this->assertStale($this->service->snapshot(), $good, 'pending');
        $this->jobs[2]->handle($this->service, $this->export());
        self::assertSame('ready', $this->service->snapshot()['state']);
    }

    #[DataProvider('publicationFailureModes')]
    public function test_ready_and_failed_publication_failures_retain_last_good_and_dirty_revision_until_callback_retry(bool $throw): void
    {
        $store = $this->publicationFailures([], $throw);
        $this->service->snapshot();
        $this->finishFirst();
        $good = $this->service->snapshot();
        $this->service->snapshot(true);
        $store->failures = ['ready', 'failed'];
        try {
            $this->jobs[1]->handle($this->service, $this->export(function (): array {
                $this->service->invalidate();
                return ['eligible' => 1, 'never_texted' => 1, 'texted_before' => 0];
            }));
            self::fail('Expected both publications to fail.');
        } catch (RuntimeException) {}
        self::assertSame([], $store->failures, 'Both atomic publication failures must have been exercised.');
        self::assertCount(2, $this->jobs);
        self::assertTrue($store->restoreLock($this->jobs[1]->scope.':count', $this->jobs[1]->owner)->isOwnedByCurrentProcess());
        $this->assertStale((new SmsAvailabilityService)->snapshot(), $good, 'pending');
        $this->jobs[1]->failed(null);
        self::assertCount(3, $this->jobs, 'The callback must preserve and schedule the dirty revision.');
        $this->assertStale($this->service->snapshot(), $good, 'pending');
        self::assertFalse($store->restoreLock($this->jobs[1]->scope.':count', $this->jobs[1]->owner)->isOwnedByCurrentProcess());
        $this->jobs[2]->handle($this->service, $this->export());
        self::assertSame('ready', $this->service->snapshot()['state']);
    }

    public function test_dispatch_failure_retains_last_good_and_invalidation_can_retry_without_waiting_for_cooldown(): void
    {
        $this->service->snapshot();
        $this->finishFirst();
        $good = $this->service->snapshot();
        $this->dispatchHook = fn () => throw new RuntimeException('PRIVATE dispatch failure');
        $this->service->invalidate();
        $failed = (new SmsAvailabilityService)->snapshot();
        $this->assertStale($failed, $good, 'failed');
        self::assertArrayNotHasKey('queued_now', $failed);
        self::assertStringNotContainsString('PRIVATE', json_encode($failed));
        $this->dispatchHook = null;
        $this->service->invalidate();
        self::assertCount(3, $this->jobs);
        $this->assertStale($this->service->snapshot(), $good, 'pending');
    }

    #[DataProvider('publicationFailureModes')]
    public function test_pending_publication_failure_preserves_last_good_and_the_notification_for_retry(bool $throw): void
    {
        $store = $this->publicationFailures([], $throw);
        $this->service->snapshot();
        $this->finishFirst();
        $good = $this->service->snapshot();
        $store->failures = ['pending'];
        $failed = $this->service->snapshot(true);
        $this->assertStale($failed, $good, 'failed');
        self::assertCount(1, $this->jobs);
        $this->service->invalidate();
        self::assertCount(2, $this->jobs);
        $this->assertStale($this->service->snapshot(), $good, 'pending');
    }

    #[DataProvider('publicationFailureModes')]
    public function test_pending_commit_followed_by_a_cache_error_does_not_strand_an_undispatched_job(bool $throw): void
    {
        $store = $this->publicationFailures([], $throw);
        $this->service->snapshot();
        $this->finishFirst();
        $good = $this->service->snapshot();
        $store->failures = ['pending'];
        $store->afterWrite = true;
        $this->assertStale($this->service->snapshot(true), $good, 'failed');
        self::assertCount(1, $this->jobs, 'Failed pending publication must not dispatch.');
        $this->assertStale((new SmsAvailabilityService)->snapshot(), $good, 'pending');
        self::assertCount(2, $this->jobs, 'The released lease must permit immediate replacement.');
        $this->jobs[1]->handle($this->service, $this->export());
        self::assertSame('ready', $this->service->snapshot()['state']);
    }

    #[DataProvider('publicationFailureModes')]
    public function test_repeated_callback_publication_errors_and_expired_leases_retain_last_good_and_dirty_notifications(bool $throw): void
    {
        $store = $this->publicationFailures([], $throw);
        $this->service->snapshot();
        $this->finishFirst();
        $good = $this->service->snapshot();
        $this->service->snapshot(true);
        $store->failures = ['ready', 'failed', 'failed'];
        try {
            $this->jobs[1]->handle($this->service, $this->export(function (): array {
                $this->service->invalidate();
                return $this->counts;
            }));
            self::fail('Expected failed ready and failure publications.');
        } catch (RuntimeException) {}
        try { $this->jobs[1]->failed(null); self::fail('Expected another failed callback publication.'); }
        catch (RuntimeException) {}
        self::assertSame([], $store->failures);
        $this->assertStale($this->service->snapshot(), $good, 'pending');
        Carbon::setTestNow(now()->addSeconds(SmsAvailabilityService::LEASE_SECONDS + 1));
        $this->assertStale((new SmsAvailabilityService)->snapshot(), $good, 'pending');
        self::assertCount(3, $this->jobs);
        $this->jobs[1]->failed(null);
        $this->assertStale($this->service->snapshot(), $good, 'pending');
        $this->jobs[2]->handle($this->service, $this->export());
        self::assertSame('ready', $this->service->snapshot()['state']);
        self::assertCount(3, $this->jobs);
    }

    #[DataProvider('publicationFailureModes')]
    public function test_failed_invalidation_is_not_silently_acknowledged_or_allowed_to_delete_last_good(bool $throw): void
    {
        $store = $this->publicationFailures([], $throw);
        $this->service->snapshot();
        $this->finishFirst();
        $before = $this->cache->store()->get($this->jobs[0]->scope.':snapshot');
        $store->failures = ['ready']; // invalidate rewrites the ready record with a new revision.
        try {
            $this->service->invalidate();
            self::fail('A failed dirty write must be reported so its caller can retry.');
        } catch (RuntimeException $error) {
            self::assertSame('Availability invalidation could not be saved. Retry the notification.', $error->getMessage());
        }
        self::assertSame($before, $this->cache->store()->get($this->jobs[0]->scope.':snapshot'));
        self::assertCount(1, $this->jobs);
        $this->service->invalidate();
        self::assertCount(2, $this->jobs);
        $this->assertStale($this->service->snapshot(), $before['response'], 'pending');
    }

    public function test_a_committed_dirty_write_with_a_transport_error_cannot_be_reused_as_fresh(): void
    {
        $store = $this->publicationFailures([], true);
        $this->service->snapshot();
        $this->finishFirst();
        $good = $this->service->snapshot();
        $store->failures = ['ready'];
        $store->afterWrite = true;
        try { $this->service->invalidate(); self::fail('Expected uncertain invalidation acknowledgement.'); }
        catch (RuntimeException) {}
        $this->assertStale((new SmsAvailabilityService)->snapshot(), $good, 'pending');
        self::assertCount(2, $this->jobs);
        $this->jobs[1]->handle($this->service, $this->export());
        self::assertSame('ready', $this->service->snapshot()['state']);
    }

    public function test_a_committed_stale_completion_with_a_transport_error_still_queues_its_follow_up(): void
    {
        $store = $this->publicationFailures([], true);
        $this->service->snapshot();
        $store->failures = ['ready'];
        $store->afterWrite = true;
        try {
            $this->finishFirst(function (): array {
                $this->service->invalidate();
                return $this->counts;
            });
            self::fail('Expected uncertain ready acknowledgement.');
        } catch (RuntimeException) {}
        self::assertCount(2, $this->jobs);
        $this->assertStale($this->service->snapshot(), $this->counts + ['counted_at' => now()->toIso8601String()], 'pending');
        $this->jobs[1]->handle($this->service, $this->export());
        self::assertSame('ready', $this->service->snapshot()['state']);
    }

    public function test_last_good_remains_scoped_to_database_and_demo_environment(): void
    {
        $this->service->snapshot();
        $this->finishFirst();
        config(['sms-exports.demo' => true]);
        $other = $this->service->snapshot();
        self::assertSame('pending', $other['state']);
        self::assertArrayNotHasKey('eligible', $other);
        $this->jobs[0]->failed(null);
        config(['sms-exports.demo' => false]);
        self::assertSame('ready', $this->service->snapshot()['state']);
    }

    public static function invalidCounts(): array
    {
        return [[['eligible' => '11', 'never_texted' => 7, 'texted_before' => 4]],
            [['eligible' => 11, 'never_texted' => 7, 'texted_before' => 5]],
            [['eligible' => 0, 'never_texted' => -1, 'texted_before' => 1]]];
    }

    #[DataProvider('invalidCounts')]
    public function test_invalid_aggregate_cannot_be_published_ready(array $counts): void
    {
        $this->service->snapshot();
        try { $this->finishFirst(fn () => $counts); self::fail('Expected invalid aggregate.'); }
        catch (RuntimeException) { self::assertSame('failed', $this->service->snapshot()['state']); }
    }

    public static function queryOutcomes(): array { return [[false], [true]]; }

    #[DataProvider('queryOutcomes')]
    public function test_query_timeout_is_600_and_original_pdo_attribute_is_restored_even_on_error(bool $throw): void
    {
        if (! defined('PDO::SQLSRV_ATTR_QUERY_TIMEOUT')) self::markTestSkipped('PDO SQL Server timeout attribute is unavailable.');
        $attribute = constant('PDO::SQLSRV_ATTR_QUERY_TIMEOUT');
        $pdo = new class extends PDO {
            public array $attributes = [];
            public function __construct() {}
            public function getAttribute(int $attribute): mixed { return $this->attributes[$attribute]; }
            public function setAttribute(int $attribute, mixed $value): bool { $this->attributes[$attribute] = $value; return true; }
        };
        $pdo->attributes[$attribute] = 300;
        $connection = Mockery::mock(SqlServerConnection::class);
        $connection->shouldReceive('getDriverName')->once()->andReturn('sqlsrv');
        $connection->shouldReceive('getPdo')->once()->andReturn($pdo);
        $repo = new class($connection, $pdo, $attribute, $throw, $this->counts) extends SmsProspectExportRepository {
            public function __construct(private ConnectionInterface $db, private PDO $pdo, private int $attribute, private bool $throw, private array $counts) {}
            protected function connection(): ConnectionInterface { return $this->db; }
            public function availability(): array {
                TestCase::assertSame(600, $this->pdo->getAttribute($this->attribute));
                if ($this->throw) throw new RuntimeException('synthetic count failure');
                return $this->counts;
            }
        };
        try {
            self::assertSame($this->counts, $repo->availabilityWithTimeout());
            self::assertFalse($throw);
        } catch (RuntimeException $error) {
            self::assertTrue($throw);
            self::assertSame('synthetic count failure', $error->getMessage());
        }
        self::assertSame(300, $pdo->getAttribute($attribute));
    }
}
