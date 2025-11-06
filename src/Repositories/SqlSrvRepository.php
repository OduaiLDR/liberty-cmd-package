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

    protected function table(string $table, ?string $as = null): Builder
    {
        $name = $as ? "{$table} as {$as}" : $table;

        return $this->connection()->table($name);
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
