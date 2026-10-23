<?php

namespace Cmd\Reports\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

abstract class SqlSrvRepository
{
    protected string $connection = 'sqlsrv';

    protected function connection(): ConnectionInterface
    {
        return DB::connection($this->connection);
    }

    /**
     * Runs $callback with a longer SQL Server query timeout (cmd-runner's default is
     * CMD_DB_QUERY_TIMEOUT, 300 s), then puts the previous one back. For queue jobs and
     * commands only; keep it below their own time limits.
     */
    protected function withQueryTimeout(int $seconds, callable $callback): mixed
    {
        $connection = $this->connection();
        $attribute = defined('PDO::SQLSRV_ATTR_QUERY_TIMEOUT') ? constant('PDO::SQLSRV_ATTR_QUERY_TIMEOUT') : null;
        if ($attribute === null || ! method_exists($connection, 'getDriverName') || $connection->getDriverName() !== 'sqlsrv') {
            return $callback();
        }
        $pdo = $connection->getPdo();
        $previous = $pdo->getAttribute($attribute);
        $pdo->setAttribute($attribute, $seconds);
        try {
            return $callback();
        } finally {
            $pdo->setAttribute($attribute, $previous);
        }
    }

    protected function table(string $table, ?string $as = null): Builder
    {
        $name = $as ? "{$table} as {$as}" : $table;

        return $this->connection()->table($name);
    }

    /** A worker-only consistent read; restore pooled session settings on every exit. */
    protected function withReadSnapshot(callable $callback): mixed
    {
        $db = $this->connection();
        if ($db->transactionLevel() !== 0 || $db->getPdo()->inTransaction()) {
            throw new \RuntimeException('A summary snapshot must start outside a business transaction.');
        }
        if ($db->getDriverName() !== 'sqlsrv') return $db->transaction($callback);

        $settings = $db->selectOne('SELECT transaction_isolation_level AS isolation_level FROM sys.dm_exec_sessions WHERE session_id = @@SPID', [], false);
        $levels = [1 => 'READ UNCOMMITTED', 2 => 'READ COMMITTED', 3 => 'REPEATABLE READ', 4 => 'SERIALIZABLE', 5 => 'SNAPSHOT'];
        $previous = $levels[(int) ($settings->isolation_level ?? 0)] ?? null;
        if ($previous === null) throw new \RuntimeException('Cannot determine the summary connection isolation level.');

        $db->unprepared('SET TRANSACTION ISOLATION LEVEL SNAPSHOT');
        $snapshotPdo = $db->getPdo();
        try {
            // SQL Server deliberately fails if ALLOW_SNAPSHOT_ISOLATION is not enabled;
            // do not silently downgrade to a read that can combine different versions.
            return $db->transaction(function () use ($db, $snapshotPdo, $callback): mixed {
                // Laravel can reconnect and retry beginTransaction(). That replacement
                // session did not receive SET SNAPSHOT: fail before reading any source.
                if ($db->getPdo() !== $snapshotPdo) {
                    throw new \RuntimeException('The summary connection changed before its snapshot began. Retry the refresh.');
                }
                return $callback();
            });
        } finally {
            $db->unprepared('SET TRANSACTION ISOLATION LEVEL '.$previous);
        }
    }

    protected function clampPerPage(int $perPage, int $default = 25, int $max = 1000): int
    {
        return $perPage > 0 && $perPage <= $max ? $perPage : $default;
    }

    protected function paginateBuilder(Builder $query, int $perPage, int $default = 25, int $max = 1000): LengthAwarePaginator
    {
        $perPage = $this->clampPerPage($perPage, $default, $max);

        return $query->paginate($perPage)->withQueryString();
    }

    protected function distinctValues(Builder $baseQuery, string $column, int $limit = 500): Collection
    {
        $clone = (clone $baseQuery)
            ->select($column)
            ->whereNotNull($column)
            ->distinct()
            ->orderBy($column)
            ->limit($limit);

        return $clone
            ->pluck($column)
            ->filter(static fn($value) => $value !== null && $value !== '')
            ->values();
    }
}
