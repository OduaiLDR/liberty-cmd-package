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

    /** @return array{state:string, requested_at?:string, queued_now?:bool, counted_at?:string, eligible?:int, never_texted?:int, texted_before?:int, message?:string} */
    public function snapshot(bool $refresh = false): array
    {
        try {
            $cache = $this->cache();
            $scope = $this->scope();
            $entry = $cache->get($scope.':snapshot');
            if ($this->reusable($entry, $refresh)) return $entry['response'];

            $mutex = $cache->getStore()->lock($scope.':state', self::MUTEX_SECONDS);
            if (! $mutex->get()) {
                $entry = $cache->get($scope.':snapshot');
                return $this->reusable($entry, $refresh) ? $entry['response'] : $this->pending();
            }
            $job = null;
            try {
                $entry = $cache->get($scope.':snapshot');
                if ($this->reusable($entry, $refresh)) return $entry['response'];
                app(SmsExportQueue::class)->assertReady();
                $lease = $cache->getStore()->lock($scope.':count', self::LEASE_SECONDS);
                if (! $lease->get()) return $this->pending();
                $generation = (string) Str::uuid();
                $owner = $lease->owner();
                $response = $this->pending();
                try {
                    $this->put($cache, $scope, [
                        'generation' => $generation, 'owner' => $owner,
                        'lease_until' => now()->getTimestamp() + self::LEASE_SECONDS,
                        'started_at' => null, 'response' => $response,
                    ], self::LEASE_SECONDS + 60);
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
                return $cache->get($scope.':snapshot')['response'] ?? $this->failure();
            }
            // Per-caller freshness acknowledgement, deliberately absent from the shared snapshot.
            return $response + ['queued_now' => true];
        } catch (Throwable $error) {
            // Configuration, cache and queue details must not reach the client.
            return $this->failure();
        }
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
            $this->put($cache, $scope, $entry, self::LEASE_SECONDS + 60);
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
        $this->finish($this->cache(), $scope, $generation, $owner, $this->failure(), self::FAILURE_SECONDS);
    }

    private function finish(Repository $cache, string $scope, string $generation, string $owner, array $response, int $ttl): void
    {
        $mutex = $cache->getStore()->lock($scope.':state', self::MUTEX_SECONDS);
        if (! $mutex->get()) throw new RuntimeException('Availability state is busy.');
        try {
            $entry = $cache->get($scope.':snapshot');
            // A cache transport can report an error after committing a terminal snapshot.
            $published = ($entry['generation'] ?? null) === $generation
                && in_array($entry['response']['state'] ?? null, ['ready', 'failed'], true);
            if ($this->owns($cache, $scope, $entry, $generation, $owner)) {
                $this->put($cache, $scope, ['generation' => $generation, 'response' => $response], $ttl);
                $published = true;
            }
            if ($published) {
                // Keep ownership on publication failure so fail()/failed() can still publish.
                // Owned release only: an old delivery cannot release a newer generation's lease.
                $cache->getStore()->restoreLock($scope.':count', $owner)->release();
            }
        } finally {
            $mutex->release();
        }
    }

    private function owns(Repository $cache, string $scope, mixed $entry, string $generation, string $owner): bool
    {
        return is_array($entry) && ($entry['generation'] ?? null) === $generation
            && ($entry['owner'] ?? null) === $owner && ($entry['response']['state'] ?? null) === 'pending'
            && ($entry['lease_until'] ?? 0) > now()->getTimestamp()
            && $cache->getStore()->restoreLock($scope.':count', $owner)->isOwnedByCurrentProcess();
    }

    private function reusable(mixed $entry, bool $refresh): bool
    {
        if (! is_array($entry)) return false;
        if (($entry['response']['state'] ?? null) === 'pending') return ($entry['lease_until'] ?? 0) > now()->getTimestamp();
        return ! $refresh && in_array($entry['response']['state'] ?? null, ['ready', 'failed'], true);
    }

    private function pending(): array
    {
        return ['state' => 'pending', 'requested_at' => now()->toIso8601String()];
    }

    private function failure(): array
    {
        return ['state' => 'failed', 'message' => self::FAILURE_MESSAGE];
    }

    private function put(Repository $cache, string $scope, array $entry, int $ttl): void
    {
        if (! $cache->put($scope.':snapshot', $entry, $ttl)) throw new RuntimeException('Availability cache write failed.');
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
        return 'sms:availability:v1:'.hash('sha256', json_encode([
            config('app.env'), config('database.connections.sqlsrv.host'),
            config('database.connections.sqlsrv.port'), config('database.connections.sqlsrv.database'),
            (bool) config('sms-exports.demo', false),
        ], JSON_THROW_ON_ERROR));
    }
}
