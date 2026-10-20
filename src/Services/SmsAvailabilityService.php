<?php

namespace Cmd\Reports\Services;

use Cmd\Reports\Jobs\CountSmsAvailabilityJob;
use Cmd\Reports\Repositories\SmsProspectExportRepository;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Cache\DynamoDbStore;
use Illuminate\Cache\MemcachedStore;
use Illuminate\Cache\RedisStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** Only aggregate counts and coordination metadata enter the shared cache. Never count in HTTP. */
class SmsAvailabilityService
{
    public const FRESH_SECONDS = 300;
    public const LEASE_SECONDS = SmsExportQueue::JOB_TIMEOUT + 300;
    private const MUTEX_SECONDS = 30;
    private const FAILURE_SECONDS = 60;
    private const FAILURE_MESSAGE = 'Could not count available people. Refresh the count to try again.';

    /** @return array{state:string, requested_at?:string, queued_now?:bool, counted_at?:string, eligible?:int, never_texted?:int, texted_before?:int, stale?:bool, message?:string} */
    public function snapshot(bool $refresh = false): array
    {
        $entry = null;
        try {
            $cache = $this->cache();
            $scope = $this->scope();
            $entry = $cache->get($scope.':snapshot');
            if ($this->reusable($cache, $scope, $entry, $refresh)) return $this->response($entry);

            $mutex = $cache->getStore()->lock($scope.':state', self::MUTEX_SECONDS);
            if (! $mutex->get()) {
                $entry = $cache->get($scope.':snapshot');
                return $this->reusable($cache, $scope, $entry, $refresh) ? $this->response($entry) : $this->withLastGood($entry, $this->pending());
            }
            $job = null;
            try {
                $entry = $cache->get($scope.':snapshot');
                if ($this->reusable($cache, $scope, $entry, $refresh)) return $this->response($entry);
                app(SmsExportQueue::class)->assertReady();
                $lease = $cache->getStore()->lock($scope.':count', self::LEASE_SECONDS);
                if (! $lease->get()) return $this->withLastGood($entry, $this->pending());
                $generation = (string) Str::uuid();
                $owner = $lease->owner();
                $response = $this->withLastGood($entry, $this->pending());
                try {
                    $this->put($cache, $scope, [
                        'generation' => $generation, 'owner' => $owner,
                        'lease_until' => now()->getTimestamp() + self::LEASE_SECONDS,
                        'started_at' => null, 'response' => $response,
                        'revision' => $entry['revision'] ?? 0, 'count_revision' => $entry['revision'] ?? 0,
                        'last_good' => $this->lastGood($entry),
                    ]);
                } catch (Throwable $error) {
                    $lease->release();
                    throw $error;
                }
                $job = new CountSmsAvailabilityJob($scope, $generation, $owner);
            } finally {
                $mutex->release();
            }

            // Publish pending BEFORE dispatch, and never dispatch while holding the state mutex.
            try {
                app(Dispatcher::class)->dispatch($job);
            } catch (Throwable $error) {
                $this->fail($scope, $generation, $owner);
                $entry = $cache->get($scope.':snapshot');
                return $this->reusable($cache, $scope, $entry, false) ? $this->response($entry) : $this->withLastGood($entry, $this->failure());
            }
            // Per-caller freshness acknowledgement, deliberately absent from the shared snapshot.
            return $response + ['queued_now' => true];
        } catch (Throwable $error) {
            // Configuration, cache and queue details must not reach the client.
            return $this->withLastGood($entry, $this->failure());
        }
    }

    /**
     * Notify AFTER the writer's outermost commit. Queueing coalesces with existing work;
     * a notification during the query requires another count. Persistence errors propagate
     * safely so the writer can retry rather than silently acknowledge a lost notification.
     */
    public function invalidate(): void
    {
        try {
            $cache = $this->cache();
            $scope = $this->scope();
            $mutex = $cache->getStore()->lock($scope.':state', self::MUTEX_SECONDS);
            $mutex->block(self::MUTEX_SECONDS);
            try {
                $entry = $cache->get($scope.':snapshot') ?? [];
                $entry['revision'] = ($entry['revision'] ?? 0) + 1;
                $this->put($cache, $scope, $entry);
            } finally {
                $mutex->release();
            }
        } catch (Throwable $error) {
            throw new RuntimeException('Availability invalidation could not be saved. Retry the notification.', 0, $error);
        }

        // snapshot() only coordinates background work; it never counts inline.
        $this->snapshot();
    }

    public function count(string $scope, string $generation, string $owner, SmsProspectExportRepository $export): void
    {
        if ($scope !== $this->scope()) return;
        $cache = $this->cache();
        $mutex = $cache->getStore()->lock($scope.':state', self::MUTEX_SECONDS);
        if (! $mutex->get()) throw new RuntimeException('Availability state is busy.');
        try {
            $entry = $cache->get($scope.':snapshot');
            if (! $this->owns($cache, $scope, $entry, $generation, $owner) || $entry['started_at'] !== null) return;
            // Queue wait has its own bounded lease. Start a full job lease on actual execution.
            $cache->getStore()->restoreLock($scope.':count', $owner)->release();
            $lease = $cache->getStore()->lock($scope.':count', self::LEASE_SECONDS, $owner);
            if (! $lease->get()) throw new RuntimeException('Availability lease could not be renewed.');
            $entry['lease_until'] = now()->getTimestamp() + self::LEASE_SECONDS;
            $entry['started_at'] = now()->toIso8601String();
            // Include every write committed before the query actually starts, not just
            // those known when this job was queued (possibly hours earlier).
            $entry['count_revision'] = $entry['revision'];
            $this->put($cache, $scope, $entry);
        } finally {
            $mutex->release();
        }

        $counts = $export->availabilityWithTimeout();
        foreach (['eligible', 'never_texted', 'texted_before'] as $key) {
            if (! isset($counts[$key]) || ! is_int($counts[$key]) || $counts[$key] < 0) {
                throw new RuntimeException('Invalid availability aggregate.');
            }
        }
        if ($counts['eligible'] !== $counts['never_texted'] + $counts['texted_before']) {
            throw new RuntimeException('Inconsistent availability aggregate.');
        }
        $response = ['state' => 'ready', 'eligible' => $counts['eligible'],
            'never_texted' => $counts['never_texted'], 'texted_before' => $counts['texted_before'],
            'counted_at' => now()->toIso8601String()];
        $this->finish($cache, $scope, $generation, $owner, $response, self::FRESH_SECONDS);
    }

    /** Also called by the worker's failed() callback, including timeout/max-attempt failures. */
    public function fail(string $scope, string $generation, string $owner): void
    {
        if ($scope !== $this->scope()) return;
        $this->finish($this->cache(), $scope, $generation, $owner, $this->failure(), self::FAILURE_SECONDS);
    }

    private function finish(Repository $cache, string $scope, string $generation, string $owner, array $response, int $ttl): void
    {
        $mutex = $cache->getStore()->lock($scope.':state', self::MUTEX_SECONDS);
        if (! $mutex->get()) throw new RuntimeException('Availability state is busy.');
        $followUp = false;
        try {
            $entry = $cache->get($scope.':snapshot');
            // A cache transport can report an error after committing a terminal snapshot.
            $published = ($entry['generation'] ?? null) === $generation
                && in_array($entry['response']['state'] ?? null, ['ready', 'failed'], true);
            if ($this->owns($cache, $scope, $entry, $generation, $owner)) {
                // One atomic record: a failed ready publication cannot destroy the
                // previous successful aggregate or clear a concurrent dirty revision.
                $entry = [
                    'generation' => $generation, 'response' => $response,
                    'revision' => $entry['revision'], 'count_revision' => $entry['count_revision'],
                    'valid_until' => now()->getTimestamp() + $ttl,
                    'last_good' => $response['state'] === 'ready' ? $this->aggregate($response) : $this->lastGood($entry),
                ];
                $this->put($cache, $scope, $entry);
                $published = true;
            }
            if ($published) {
                // Keep ownership on publication failure so fail()/failed() can still publish.
                // Owned release only: an old delivery cannot release a newer generation's lease.
                $cache->getStore()->restoreLock($scope.':count', $owner)->release();
                $followUp = $this->dirty($entry);
            }
        } finally {
            $mutex->release();
        }
        // A dirty terminal response is never reusable as fresh. Publish the next
        // pending generation under the mutex, then dispatch only after unlocking.
        if ($followUp) $this->snapshot();
    }

    private function owns(Repository $cache, string $scope, mixed $entry, string $generation, string $owner): bool
    {
        return is_array($entry) && ($entry['generation'] ?? null) === $generation
            && ($entry['owner'] ?? null) === $owner && ($entry['response']['state'] ?? null) === 'pending'
            && ($entry['lease_until'] ?? 0) > now()->getTimestamp()
            && $cache->getStore()->restoreLock($scope.':count', $owner)->isOwnedByCurrentProcess();
    }

    private function reusable(Repository $cache, string $scope, mixed $entry, bool $refresh): bool
    {
        if (! is_array($entry)) return false;
        if (($entry['response']['state'] ?? null) === 'pending') {
            // A pending write may commit and then report failure before dispatch. Its
            // released lease makes that orphan recoverable without waiting hours.
            return ($entry['lease_until'] ?? 0) > now()->getTimestamp() && isset($entry['owner'])
                && $cache->getStore()->restoreLock($scope.':count', $entry['owner'])->isOwnedByCurrentProcess();
        }
        return ! $refresh && ! $this->dirty($entry) && ($entry['valid_until'] ?? 0) > now()->getTimestamp()
            && in_array($entry['response']['state'] ?? null, ['ready', 'failed'], true);
    }

    private function dirty(array $entry): bool
    {
        return ($entry['revision'] ?? 0) !== ($entry['count_revision'] ?? 0);
    }

    private function response(array $entry): array
    {
        return $entry['response']['state'] === 'ready' ? $entry['response'] : $this->withLastGood($entry, $entry['response']);
    }

    private function withLastGood(mixed $entry, array $response): array
    {
        $lastGood = $this->lastGood($entry);
        return $lastGood === null ? $response : $response + $lastGood + ['stale' => true];
    }

    private function lastGood(mixed $entry): ?array
    {
        return is_array($entry['last_good'] ?? null) ? $this->aggregate($entry['last_good']) : null;
    }

    /** Copy only the aggregate contract; no source rows or other payload fields. */
    private function aggregate(array $counts): array
    {
        return array_intersect_key($counts, array_flip(['eligible', 'never_texted', 'texted_before', 'counted_at']));
    }

    private function pending(): array
    {
        return ['state' => 'pending', 'requested_at' => now()->toIso8601String()];
    }

    private function failure(): array
    {
        return ['state' => 'failed', 'message' => self::FAILURE_MESSAGE];
    }

    private function put(Repository $cache, string $scope, array $entry): void
    {
        // Freshness/failure cooldown and pending leases expire independently of the
        // last successful aggregate and dirty revision, which have no cache TTL.
        if (! $cache->forever($scope.':snapshot', $entry)) throw new RuntimeException('Availability cache write failed.');
    }

    protected function cache(): Repository
    {
        $cache = Cache::store();
        $store = $cache->getStore();
        $shared = $store instanceof DatabaseStore || $store instanceof RedisStore
            || $store instanceof MemcachedStore || $store instanceof DynamoDbStore;
        if (! $store instanceof LockProvider || (! $shared && ! ($store instanceof ArrayStore && app()->environment('testing')))) {
            throw new RuntimeException('Availability needs a shared persistent cache with atomic locks.');
        }
        return $cache;
    }

    protected function scope(): string
    {
        // A shared cache must not mix databases/environments, including the isolated demo.
        // Older workers use expiring v1 entries and cannot preserve these revisions.
        return 'sms:availability:v2:'.hash('sha256', json_encode([
            config('app.env'), config('database.connections.sqlsrv.host'),
            config('database.connections.sqlsrv.port'), config('database.connections.sqlsrv.database'),
            (bool) config('sms-exports.demo', false),
        ], JSON_THROW_ON_ERROR));
    }
}
