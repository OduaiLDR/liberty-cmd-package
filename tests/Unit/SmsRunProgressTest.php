<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Carbon\Carbon;
use Cmd\Reports\Services\SmsRunProgress;
use Illuminate\Cache\CacheManager;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

/** In-memory cache only; progress never touches SQL. */
final class SmsRunProgressTest extends TestCase
{
    private Container $previous;
    private Container $app;
    private const ID = 'AAAAAAAA-BBBB-4CCC-8DDD-EEEEEEEEEEEE';

    protected function setUp(): void
    {
        $this->previous = Container::getInstance();
        $this->app = new Container;
        Container::setInstance($this->app);
        $this->app->instance('config', new Repository(['cache' => ['default' => 'array',
            'stores' => ['file' => ['driver' => 'array'], 'array' => ['driver' => 'array']], 'prefix' => 'sms-test']]));
        $this->app->instance('cache', new CacheManager($this->app));
        Facade::clearResolvedInstances(); Facade::setFacadeApplication($this->app);
        Carbon::setTestNow(Carbon::parse('2026-11-02T10:00:00Z'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Facade::clearResolvedInstances(); Container::setInstance($this->previous); Facade::setFacadeApplication($this->previous);
    }

    private function clocked(): SmsRunProgress
    {
        return new class extends SmsRunProgress {
            public float $now = 1000.0;
            protected function clock(): float { return $this->now; }
        };
    }

    public function test_write_and_read_share_one_fixed_shape_under_the_lowercase_run_key(): void
    {
        $progress = new SmsRunProgress;
        $progress->write(self::ID, ['phase' => 'selecting', 'done' => 12, 'total' => 100, 'scanned' => 40, 'updated_at' => 'ignored']);
        $expected = ['phase' => 'selecting', 'done' => 12, 'total' => 100, 'scanned' => 40, 'parts_done' => null,
            'parts_total' => null, 'updated_at' => '2026-11-02T10:00:00+00:00'];
        self::assertSame($expected, $progress->read(strtolower(self::ID)));
        self::assertSame($expected, (new SmsRunProgress)->read(self::ID));
        self::assertSame(['request_id' => strtolower(self::ID)] + $expected,
            Cache::store('file')->get('cmd-sms-run-progress:'.strtolower(self::ID)));
        // Only the file store is used, matching the older selection progress cache.
        self::assertNull(Cache::store('array')->get('cmd-sms-run-progress:'.strtolower(self::ID)));
    }

    public function test_same_phase_writes_are_throttled_while_phase_changes_always_write(): void
    {
        $progress = $this->clocked();
        $progress->write(self::ID, ['phase' => 'writing', 'done' => 0, 'total' => 9]);
        $progress->now += 0.5;
        $progress->write(self::ID, ['phase' => 'writing', 'done' => 5, 'total' => 9, 'parts_done' => 1, 'parts_total' => 2]);
        self::assertSame(0, $progress->read(self::ID)['done']);
        $progress->now += 0.1;
        $progress->write(self::ID, ['phase' => 'packaging', 'done' => 9, 'total' => 9, 'parts_done' => 2, 'parts_total' => 2]);
        self::assertSame('packaging', $progress->read(self::ID)['phase']);
        $progress->now += 0.1;
        $progress->write(self::ID, ['phase' => 'verifying', 'done' => 9, 'total' => 9, 'parts_done' => 2, 'parts_total' => 2]);
        self::assertSame('verifying', $progress->read(self::ID)['phase']);
        $progress->now += 0.2;
        $progress->write(self::ID, ['phase' => 'verifying', 'done' => 8, 'total' => 9]);
        self::assertSame(9, $progress->read(self::ID)['done']);
        $progress->now += 1.0;
        $progress->write(self::ID, ['phase' => 'verifying', 'done' => 8, 'total' => 9]);
        self::assertSame(8, $progress->read(self::ID)['done']);
        // Throttling is per run.
        $other = 'bbbbbbbb-bbbb-4ccc-8ddd-eeeeeeeeeeee';
        $progress->write($other, ['phase' => 'verifying', 'done' => 1, 'total' => 1]);
        self::assertSame(1, $progress->read($other)['done']);
    }

    public function test_forget_removes_the_snapshot_and_resets_its_throttle(): void
    {
        $progress = $this->clocked();
        $progress->write(self::ID, ['phase' => 'selecting', 'done' => 1, 'total' => 9]);
        $progress->forget(strtolower(self::ID));
        self::assertNull($progress->read(self::ID));
        $progress->write(self::ID, ['phase' => 'selecting', 'done' => 2, 'total' => 9]);
        self::assertSame(2, $progress->read(self::ID)['done']);
    }

    public function test_malformed_snapshots_are_never_written_or_returned_and_counts_are_safe(): void
    {
        $progress = new SmsRunProgress;
        foreach ([['phase' => 'unknown', 'done' => 1, 'total' => 1], ['phase' => 'writing', 'done' => '1', 'total' => 1],
            ['phase' => 'writing', 'total' => 1], ['phase' => 'writing', 'done' => 1, 'total' => 1, 'scanned' => 1.5]] as $snapshot) {
            $progress->write(self::ID, $snapshot);
            self::assertNull($progress->read(self::ID));
        }
        $progress->write(self::ID, ['phase' => 'writing', 'done' => -5, 'total' => PHP_INT_MAX, 'parts_done' => -1, 'parts_total' => 2]);
        $read = $progress->read(self::ID);
        self::assertSame([0, 9007199254740991, 0, 2], [$read['done'], $read['total'], $read['parts_done'], $read['parts_total']]);
        $key = 'cmd-sms-run-progress:'.strtolower(self::ID);
        Cache::store('file')->put($key, ['request_id' => 'bbbbbbbb-bbbb-4ccc-8ddd-eeeeeeeeeeee'] + $read, 60);
        self::assertNull($progress->read(self::ID));
        Cache::store('file')->put($key, 'not an array', 60);
        self::assertNull($progress->read(self::ID));
    }

    public function test_missing_or_failing_cache_never_breaks_a_run(): void
    {
        $this->app->instance('cache', new class {
            public function store(?string $name = null): never { throw new \RuntimeException('Synthetic cache outage.'); }
        });
        Facade::clearResolvedInstances();
        $progress = new SmsRunProgress;
        $progress->write(self::ID, ['phase' => 'selecting', 'done' => 1, 'total' => 2]);
        self::assertNull($progress->read(self::ID));
        $progress->forget(self::ID);
        self::assertNull($progress->forRun((object) ['Request_ID' => self::ID, 'Status' => 'selecting', 'Updated_At' => '2026-11-02 10:00:00']));
        $unbound = new Container;
        Container::setInstance($unbound);
        Facade::clearResolvedInstances(); Facade::setFacadeApplication($unbound);
        $progress->write(self::ID, ['phase' => 'selecting', 'done' => 1, 'total' => 2]);
        self::assertNull($progress->read(self::ID));
    }

    public function test_run_view_only_accepts_its_current_busy_phase_written_after_the_last_status_change(): void
    {
        $progress = new SmsRunProgress;
        $run = fn (string $status, string $updated = '2026-11-02 10:00:00'): object => (object) ['Request_ID' => self::ID, 'Status' => $status, 'Updated_At' => $updated];
        self::assertNull($progress->forRun($run('selecting')));
        $progress->write(self::ID, ['phase' => 'selecting', 'done' => 3, 'total' => 9, 'scanned' => 7]);
        foreach (['queued', 'selecting'] as $status) self::assertSame(3, $progress->forRun($run($status))['done']);
        foreach (['building_file', 'review', 'file_ready', 'failed', 'approved'] as $status) self::assertNull($progress->forRun($run($status)));
        // A snapshot older than the run's last status change belongs to an earlier attempt.
        self::assertNull($progress->forRun($run('selecting', '2026-11-02 10:00:01')));
        $progress->write(self::ID, ['phase' => 'finalizing_selection', 'done' => 9, 'total' => 9, 'scanned' => 20]);
        self::assertSame('finalizing_selection', $progress->forRun($run('selecting'))['phase']);
        $progress->write(self::ID, ['phase' => 'checking', 'done' => 0, 'total' => 9]);
        self::assertNull($progress->forRun($run('selecting')));
        foreach (['checking', 'writing', 'packaging', 'verifying', 'final_check'] as $phase) {
            $progress->forget(self::ID);
            $progress->write(self::ID, ['phase' => $phase, 'done' => 9, 'total' => 9, 'parts_done' => 1, 'parts_total' => 1]);
            self::assertSame($phase, $progress->forRun($run('building_file'))['phase']);
        }
        self::assertNull($progress->forRun((object) ['Request_ID' => 'bbbbbbbb-bbbb-4ccc-8ddd-eeeeeeeeeeee', 'Status' => 'building_file', 'Updated_At' => '2026-11-02 10:00:00']));
    }
}
