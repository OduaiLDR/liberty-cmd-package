<?php

namespace Cmd\Reports\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class GrowthModelReportRepository extends SqlSrvRepository
{
    protected ?array $resolved = null;

    /** @return array<string, string> */
    public function columns(): array
    {
        return $this->resolvedDefinition()['labels'];
    }

    /**
     * @param array<string,mixed> $filters
     */
    public function all(?string $from = null, ?string $to = null, array $filters = []): Collection
    {
        return $this->baseQuery($from, $to, $filters)->get();
    }

    /**
     * @param array<string,mixed> $filters
     */
    public function paginate(?string $from = null, ?string $to = null, int $perPage = 25, array $filters = []): LengthAwarePaginator
    {
        return $this->paginateBuilder($this->baseQuery($from, $to, $filters), $perPage);
    }

    /**
     * @param array<string,mixed> $filters
     */
    public function sample(?string $from = null, ?string $to = null, int $limit = 500, array $filters = []): Collection
    {
        return $this->baseQuery($from, $to, $filters)->limit($limit)->get();
    }

    /**
     * @param array<string,mixed> $filters
     */
    protected function baseQuery(?string $from, ?string $to, array $filters): Builder
    {
        $def = $this->resolvedDefinition();

        $query = $this->table($def['table'], 'x');

        if ($def['force_empty']) {
            return $query->whereRaw('1=0');
        }

        foreach ($def['selects'] as $sel) {
            $query->addSelect($sel);
        }

        $dateColumn = $def['date_column'];
        if ($dateColumn) {
            if ($from) {
                $query->whereRaw('CAST(' . $dateColumn . ' AS date) >= ?', [$from]);
            }
            if ($to) {
                $query->whereRaw('CAST(' . $dateColumn . ' AS date) <= ?', [$to]);
            }
        }

        if ($def['order_by']) {
            $query->orderByRaw($def['order_by'] . ' desc');
        }

        return $query;
    }

    /**
     * @return array{
     *  table:string,
     *  selects: array<int,\Illuminate\Database\Query\Expression|string>,
     *  labels: array<string,string>,
     *  filter_columns: array<string,string>,
     *  date_column: ?string,
     *  order_by: ?string,
     *  force_empty: bool
     * }
     */
    protected function resolvedDefinition(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $table = $this->detectBestTable();
        $forceEmpty = false;

        if (!$table) {
            foreach ([
                'dbo.TblGrowthModel', 'TblGrowthModel',
                'dbo.GrowthModel', 'GrowthModel',
                'dbo.TblGrowthProjection', 'TblGrowthProjection',
                'dbo.GrowthProjection', 'GrowthProjection',
            ] as $candidate) {
                if ($this->tableExists($candidate)) {
                    $table = $candidate;
                    break;
                }
            }
        }

        if (!$table) {
            $table = $this->tableExists('dbo.TblEnrollment') ? 'dbo.TblEnrollment' : 'TblEnrollment';
            $forceEmpty = true;
        }

        $cols = $this->listColumns($table);

        $labels = [];
        $selects = [];
        $filterColumns = [];
        $used = [];

        foreach ($cols as $col) {
            $key = $this->normalizeKey($col);
            $i = 1;
            while (isset($used[strtolower($key)])) {
                $i++;
                $key = $this->normalizeKey($col) . '_' . $i;
            }
            $used[strtolower($key)] = true;

            $labels[$key] = $col;
            $selects[] = DB::raw('x.[' . $col . '] as [' . $key . ']');
        }

        $dateKey = null;
        foreach ($labels as $key => $label) {
            $l = strtolower($label);
            $k = strtolower($key);
            if (str_contains($l, 'date') || preg_match('/\b(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)\b/', $label) || preg_match('/\b(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)\b/', $k)) {
                $dateKey = $key;
                break;
            }
        }
        $dateColumn = $dateKey ? ('x.[' . $labels[$dateKey] . ']') : null;

        $orderBy = $dateColumn;

        $this->resolved = [
            'table' => $table,
            'selects' => $selects,
            'labels' => $labels,
            'filter_columns' => $filterColumns,
            'date_column' => $dateColumn,
            'order_by' => $orderBy,
            'force_empty' => $forceEmpty,
        ];

        return $this->resolved;
    }

    protected function normalizeKey(string $column): string
    {
        $key = preg_replace('/[^A-Za-z0-9]+/', '_', $column) ?: $column;
        $key = trim($key, '_');
        return $key === '' ? 'col' : $key;
    }

    protected function detectBestTable(): ?string
    {
        try {
            $likeParts = [
                "COLUMN_NAME LIKE '%GROWTH%'",
                "COLUMN_NAME LIKE '%MODEL%'",
                "COLUMN_NAME LIKE '%REVENUE%'",
                "COLUMN_NAME LIKE '%EXPENSE%'",
                "COLUMN_NAME LIKE '%PROJECTION%'",
                "COLUMN_NAME LIKE '%CPA%'",
                "COLUMN_NAME LIKE '%ROI%'",
                "COLUMN_NAME LIKE '%JAN%'",
                "COLUMN_NAME LIKE '%FEB%'",
                "COLUMN_NAME LIKE '%MAR%'",
                "COLUMN_NAME LIKE '%APR%'",
                "COLUMN_NAME LIKE '%MAY%'",
                "COLUMN_NAME LIKE '%JUN%'",
                "COLUMN_NAME LIKE '%JUL%'",
                "COLUMN_NAME LIKE '%AUG%'",
                "COLUMN_NAME LIKE '%SEP%'",
                "COLUMN_NAME LIKE '%OCT%'",
                "COLUMN_NAME LIKE '%NOV%'",
                "COLUMN_NAME LIKE '%DEC%'",
            ];

            $rows = $this->connection()->select(
                'SELECT TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE ' . implode(' OR ', $likeParts)
            );

            $scores = [];
            foreach ($rows as $row) {
                $schema = (string) ($row->TABLE_SCHEMA ?? '');
                $tableName = (string) ($row->TABLE_NAME ?? '');
                $table = ($schema !== '' ? $schema . '.' : '') . $tableName;
                $col = strtoupper((string) ($row->COLUMN_NAME ?? ''));
                if ($table === '' || $tableName === '' || $col === '') {
                    continue;
                }
                $scores[$table] ??= 0;
                if (str_contains($col, 'GROWTH')) $scores[$table] += 6;
                if (str_contains($col, 'MODEL')) $scores[$table] += 5;
                if (str_contains($col, 'REVENUE')) $scores[$table] += 5;
                if (str_contains($col, 'EXPENSE')) $scores[$table] += 5;
                if (str_contains($col, 'PROJECTION')) $scores[$table] += 4;
                if (str_contains($col, 'CPA')) $scores[$table] += 3;
                if (str_contains($col, 'ROI')) $scores[$table] += 3;
                if (preg_match('/\b(JAN|FEB|MAR|APR|MAY|JUN|JUL|AUG|SEP|OCT|NOV|DEC)\b/', $col)) $scores[$table] += 3;
                if (str_contains(strtoupper($tableName), 'MODEL')) $scores[$table] += 6;
                if (str_contains(strtoupper($tableName), 'GROWTH')) $scores[$table] += 4;
            }

            if (empty($scores)) {
                return null;
            }
            arsort($scores);
            return array_key_first($scores) ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    protected function tableExists(string $table): bool
    {
        $quoted = $this->quoteObjectName($table);
        try {
            $this->connection()->select('SELECT TOP 0 1 as [x] FROM ' . $quoted);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array<int, string>
     */
    protected function listColumns(string $table): array
    {
        [$schema, $name] = $this->splitObjectName($table);

        if ($schema !== null) {
            $rows = $this->connection()->select(
                'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
                [$schema, $name]
            );

            return array_values(array_filter(array_map(static fn ($row) => (string) ($row->COLUMN_NAME ?? ''), $rows)));
        }

        $rows = $this->connection()->select(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'dbo' AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION",
            [$name]
        );

        $names = array_values(array_filter(array_map(static fn ($row) => (string) ($row->COLUMN_NAME ?? ''), $rows)));
        if (count($names) > 0) {
            return $names;
        }

        $rows = $this->connection()->select(
            'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
            [$name]
        );

        return array_values(array_filter(array_map(static fn ($row) => (string) ($row->COLUMN_NAME ?? ''), $rows)));
    }

    protected function quoteObjectName(string $name): string
    {
        $parts = array_values(array_filter(explode('.', $name), static fn ($p) => $p !== ''));

        $quoted = array_map(static function (string $part): string {
            $part = trim($part);
            if ($part === '') {
                return $part;
            }

            if (str_starts_with($part, '[') && str_ends_with($part, ']')) {
                return $part;
            }

            return '[' . str_replace(']', ']]', $part) . ']';
        }, $parts);

        return implode('.', $quoted);
    }

    /**
     * @return array{0:?string,1:string}
     */
    protected function splitObjectName(string $name): array
    {
        $parts = array_values(array_filter(explode('.', $name), static fn ($p) => $p !== ''));
        if (count($parts) >= 2) {
            return [$parts[0], $parts[count($parts) - 1]];
        }

        return [null, $parts[0] ?? $name];
    }
}
