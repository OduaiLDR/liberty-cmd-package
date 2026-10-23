<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

/** Durable aggregate state. Source data is counted outside these short transactions. */
final class SmsAvailabilityLedger
{
    public const TABLE = 'TblSMSAvailabilitySummary';
    public const FRESH_SECONDS = 300;
    public const LEASE_SECONDS = SmsExportQueue::JOB_TIMEOUT + 300;
    public const RETRY_SECONDS = 60;

    public function scope(): string
    {
        return $this->scopeForPolicy(SmsRecordPolicy::current());
    }

    private function scopeForPolicy(string $policy): string
    {
        // Fixed positions and policy version separate environments, databases and demo data.
        return hash('sha256', json_encode([
            config('app.env'),
            config('database.connections.sqlsrv.host'),
            config('database.connections.sqlsrv.port'),
            config('database.connections.sqlsrv.database'),
            (bool) config('sms-exports.demo', false),
            $policy,
        ], JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed>|null Raw database fields, including UTC SQL timestamps. */
    public function read(): ?array
    {
        return $this->transaction(fn (ConnectionInterface $db): ?array => $this->locked($db, $this->scope()));
    }

    /** Increment on the actual writer connection, so rollback also rolls back invalidation. */
    public function dirty(ConnectionInterface $db): void
    {
        if ($db->transactionLevel() === 0 && ! $db->getPdo()->inTransaction()) {
            throw new LogicException('Availability invalidation requires an active writer transaction.');
        }

        // Both policies derive from the same source rows. A mode rollback must not
        // resurrect a fresh-looking snapshot that missed writes under the other mode.
        // Take the same key order even during a coordinated flag transition.
        $scopes = [$this->scopeForPolicy(SmsRecordPolicy::LEGACY), $this->scopeForPolicy(SmsRecordPolicy::RECORDS)];
        sort($scopes, SORT_STRING);
        foreach ($scopes as $scope) {
            $this->ensureRow($db, $scope);
            $this->row($db, $scope)->increment('revision');
        }
    }

    /** @return array<string, mixed>|null The newly claimed generation, or null when coalesced. */
    public function claim(bool $force = false): ?array
    {
        return $this->transaction(function (ConnectionInterface $db) use ($force): ?array {
            $scope = $this->scope();
            $this->ensureRow($db, $scope);
            $row = $this->locked($db, $scope);
            $now = Carbon::now('UTC');

            if (in_array($row['status'], ['queued', 'running'], true) && $this->future($row['lease_until'], $now)) {
                return null;
            }
            // Explicit refresh never bypasses a failure cooldown.
            if ($this->future($row['retry_at'], $now)) {
                return null;
            }
            if (! $force && $row['status'] === 'ready'
                && (string) $row['revision'] === (string) $row['published_revision']
                && $this->future($row['counted_at'], $now->copy()->subSeconds(self::FRESH_SECONDS))) {
                return null;
            }

            $this->row($db, $scope)->update([
                'status' => 'queued',
                'generation' => (string) Str::uuid(),
                'requested_at' => $this->timestamp($now),
                'started_at' => null,
                'lease_until' => $this->timestamp($now->copy()->addSeconds(self::LEASE_SECONDS)),
                'retry_at' => null,
                'count_revision' => null,
                'error_code' => null,
            ]);

            return $this->locked($db, $scope);
        });
    }

    /** @return array<string, mixed>|null */
    public function start(string $scope, string $generation): ?array
    {
        if ($scope !== $this->scope()) return null;

        return $this->transaction(function (ConnectionInterface $db) use ($scope, $generation): ?array {
            $row = $this->locked($db, $scope);
            $now = Carbon::now('UTC');
            if (! $this->owns($row, $generation, ['queued'], $now)) return null;

            $this->row($db, $scope)->update([
                'status' => 'running',
                'count_revision' => $row['revision'],
                'started_at' => $this->timestamp($now),
                'lease_until' => $this->timestamp($now->copy()->addSeconds(self::LEASE_SECONDS)),
            ]);

            return $this->locked($db, $scope);
        });
    }

    public function complete(string $scope, string $generation, array $counts): bool
    {
        foreach (['eligible', 'never_texted', 'texted_before'] as $key) {
            if (! isset($counts[$key]) || ! is_int($counts[$key]) || $counts[$key] < 0) {
                throw new InvalidArgumentException('Availability counts must be nonnegative integers.');
            }
        }
        if ($counts['eligible'] !== $counts['never_texted'] + $counts['texted_before']) {
            throw new InvalidArgumentException('Availability counts must add up to eligible.');
        }
        if ($scope !== $this->scope()) return false;

        return $this->transaction(function (ConnectionInterface $db) use ($scope, $generation, $counts): bool {
            $row = $this->locked($db, $scope);
            $now = Carbon::now('UTC');
            if (! $this->owns($row, $generation, ['running'], $now)) return false;

            if ((string) $row['revision'] !== (string) $row['count_revision']) {
                // A concurrent business write makes this result unpublishable. Keep last good.
                $this->row($db, $scope)->update([
                    'status' => 'idle',
                    'generation' => null,
                    'lease_until' => null,
                    'retry_at' => $this->timestamp($now),
                    'error_code' => null,
                ]);

                return false;
            }

            $this->row($db, $scope)->update([
                'eligible' => $counts['eligible'],
                'never_texted' => $counts['never_texted'],
                'texted_before' => $counts['texted_before'],
                'counted_at' => $this->timestamp($now),
                'published_revision' => $row['count_revision'],
                'status' => 'ready',
                'lease_until' => null,
                'retry_at' => null,
                'error_code' => null,
            ]);

            return true;
        });
    }

    public function fail(string $scope, string $generation): void
    {
        if ($scope !== $this->scope()) return;

        $this->transaction(function (ConnectionInterface $db) use ($scope, $generation): void {
            $row = $this->locked($db, $scope);
            $now = Carbon::now('UTC');
            if (! $this->owns($row, $generation, ['queued', 'running'], $now)) return;

            $this->row($db, $scope)->update([
                'status' => 'failed',
                'lease_until' => null,
                'retry_at' => $this->timestamp($now->copy()->addSeconds(self::RETRY_SECONDS)),
                'error_code' => 'count_failed',
            ]);
        });
    }

    private function transaction(Closure $work): mixed
    {
        $db = DB::connection('sqlsrv');
        // Coordination must commit before a caller dispatches work, and must not hold a
        // ledger lock throughout an unrelated business transaction or source count.
        if ($db->transactionLevel() > 0 || $db->getPdo()->inTransaction()) {
            throw new LogicException('Availability coordination must run outside the writer transaction.');
        }

        return $db->transaction($work, 3);
    }

    private function row(ConnectionInterface $db, string $scope): Builder
    {
        return $db->table(self::TABLE)->useWritePdo()->where('scope', $scope);
    }

    private function locked(ConnectionInterface $db, string $scope): ?array
    {
        // SQL Server compiles this to WITH (ROWLOCK, UPDLOCK, HOLDLOCK).
        $row = $this->row($db, $scope)->lockForUpdate()->first();

        return $row === null ? null : (array) $row;
    }

    private function ensureRow(ConnectionInterface $db, string $scope): void
    {
        $grammar = $db->getQueryGrammar();
        $table = $grammar->wrapTable(self::TABLE);
        $column = $grammar->wrap('scope');

        if ($db->getDriverName() === 'sqlsrv') {
            // Lock the missing primary-key range too, until the caller's transaction ends.
            // A SELECT followed by an ordinary INSERT would race on the first dirty/claim.
            $db->insert("INSERT INTO {$table} ({$column}) SELECT ? WHERE NOT EXISTS (
                SELECT 1 FROM {$table} WITH (UPDLOCK, HOLDLOCK) WHERE {$column} = ?
            )", [$scope, $scope]);
        } elseif ($db->getDriverName() === 'sqlite') {
            $db->insert("INSERT INTO {$table} ({$column}) VALUES (?) ON CONFLICT ({$column}) DO NOTHING", [$scope]);
        } else {
            throw new LogicException('Availability ledger requires SQL Server or SQLite.');
        }
    }

    private function owns(?array $row, string $generation, array $statuses, Carbon $now): bool
    {
        return $row !== null && $row['generation'] === $generation
            && in_array($row['status'], $statuses, true) && $this->future($row['lease_until'], $now);
    }

    private function future(mixed $value, Carbon $now): bool
    {
        return $value !== null && Carbon::parse($value, 'UTC')->greaterThan($now);
    }

    private function timestamp(Carbon $value): string
    {
        return $value->copy()->utc()->format('Y-m-d H:i:s');
    }
}
