<?php

namespace Cmd\Reports\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class LdrPastDueReportRepository extends SqlSrvRepository
{
    protected ?array $resolved = null;

    /** @return array<string, string> */
    public function columns(): array
    {
        return $this->resolvedDefinition()['labels'];
    }

    /**
     * @param  array<string,mixed>  $filters
     */
    public function all(array $filters = []): Collection
    {
        return $this->baseQuery($filters)->get();
    }

    /**
     * @param  array<string,mixed>  $filters
     */
    public function paginate(int $perPage = 25, array $filters = []): LengthAwarePaginator
    {
        return $this->paginateBuilder($this->baseQuery($filters), $perPage);
    }

    /**
     * @param  array<string,mixed>  $filters
     */
    protected function baseQuery(array $filters = []): Builder
    {
        $def = $this->resolvedDefinition();

        $query = $this->table($def['transaction_table'], 't')->select($def['selects']);

        if ($def['contact_table'] && $def['join_left'] && $def['join_right']) {
            $query->leftJoin($def['contact_table'] . ' as c', $def['join_left'], '=', $def['join_right']);
        }

        $this->applyFilters($query, $filters, $def['filter_columns']);

        if ($def['order_by'] && isset($def['filter_columns'][$def['order_by']])) {
            $query->orderByRaw($def['filter_columns'][$def['order_by']] . ' desc');
        }

        return $query;
    }

    /**
     * @return array{
     *  transaction_table:string,
     *  contact_table:?string,
     *  selects:array<int,\Illuminate\Database\Query\Expression|string>,
     *  labels:array<string,string>,
     *  filter_columns:array<string,string>,
     *  join_left:?string,
     *  join_right:?string,
     *  order_by:?string
     * }
     */
    protected function resolvedDefinition(): array
    {
        // always recompute to avoid stale joins/columns
        $this->resolved = null;

        [$transactionTable, $transactionColumns] = $this->detectTransactionTable();
        [$contactTable, $contactColumns] = $this->detectContactTable();

        $mapT = [
            'CONTACT_ID' => ['label' => 'Contact ID', 'candidates' => ['CONTACT_ID', 'LLG_ID', 'ID']],
            'AMOUNT' => ['label' => 'Amount', 'candidates' => ['AMOUNT', 'Amount', 'TransAmount', 'TransactionAmount']],
            'PROCESS_DATE' => ['label' => 'Process Date', 'candidates' => ['PROCESS_DATE', 'Process_Date', 'ProcessDate']],
            'TRANS_TYPE' => ['label' => 'Trans Type', 'candidates' => ['TRANS_TYPE', 'Trans_Type', 'TransType', 'TYPE']],
            'CLEARED_DATE' => ['label' => 'Cleared Date', 'candidates' => ['CLEARED_DATE', 'Cleared_Date', 'ClearedDate']],
            'RETURNED_DATE' => ['label' => 'Returned Date', 'candidates' => ['RETURNED_DATE', 'Returned_Date', 'ReturnedDate']],
            'ACTIVE' => ['label' => 'Active', 'candidates' => ['ACTIVE', 'Active']],
            'CANCELLED' => ['label' => 'Cancelled', 'candidates' => ['CANCELLED', 'Cancelled', 'CANCELED']],
        ];

        $mapC = [
            'C_ID' => ['label' => 'Contact ID', 'candidates' => ['ID', 'Contact_ID', 'LLG_ID']],
            'GRADUATED' => ['label' => 'Graduated', 'candidates' => ['GRADUATED', 'Graduated']],
            'DROPPED' => ['label' => 'Dropped', 'candidates' => ['DROPPED', 'Dropped']],
        ];

        $labels = [];
        $selects = [];
        $filterColumns = [];

        $tIdCol = null;
        $cIdCol = null;

        foreach ($mapT as $alias => $def) {
            $col = $this->firstMatchingColumn($transactionColumns, $def['candidates']);
            if (!$col) {
                continue;
            }
            $col = $this->sanitizeColumnName($col);
            $labels[$alias] = $def['label'];
            $selects[] = DB::raw('t.[' . $col . '] as [' . $alias . ']');
            $filterColumns[$alias] = 't.[' . $col . ']';
            if ($alias === 'CONTACT_ID') {
                $tIdCol = $col;
            }
        }

        foreach ($mapC as $alias => $def) {
            $col = $this->firstMatchingColumn($contactColumns, $def['candidates']);
            if (!$col) {
                continue;
            }
            $col = $this->sanitizeColumnName($col);
            $labels[$alias] = $def['label'];
            $selects[] = DB::raw('c.[' . $col . '] as [' . $alias . ']');
            $filterColumns[$alias] = 'c.[' . $col . ']';
            if ($alias === 'C_ID') {
                $cIdCol = $col;
            }
        }

        // include remaining transaction columns to pull all data
        foreach ($transactionColumns as $col) {
            $col = $this->sanitizeColumnName($col);
            $alias = $this->normalizeKey('t_' . $col);
            if (isset($labels[$alias]) || isset($labels[$col])) {
                continue;
            }
            $labels[$alias] = $col;
            $selects[] = DB::raw('t.[' . $col . '] as [' . $alias . ']');
            $filterColumns[$alias] = 't.[' . $col . ']';
        }

        // include remaining contact columns only if join is possible
        // choose join key only if the exact column exists on both tables (case-insensitive, no substring matches)
        // prefer more common keys first to avoid invalid columns; LLG_ID last
        $candidateJoins = ['ID', 'CONTACT_ID', 'CID', 'LLG_ID'];
        $joinKey = null;

        $tLookup = [];
        foreach ($transactionColumns as $col) {
            $clean = $this->sanitizeColumnName($col);
            $tLookup[strtolower($clean)] = $clean;
        }
        $cLookup = [];
        foreach ($contactColumns as $col) {
            $clean = $this->sanitizeColumnName($col);
            $cLookup[strtolower($clean)] = $clean;
        }

        foreach ($candidateJoins as $cand) {
            $lc = strtolower($cand);
            if (isset($tLookup[$lc]) && isset($cLookup[$lc])) {
                $joinKey = $tLookup[$lc];
                break;
            }
        }

        // if only LLG_ID is common and it is unreliable, skip joining to avoid invalid column errors
        if ($joinKey === 'LLG_ID') {
            $joinKey = null;
        }

        $joinPossible = $transactionTable && $contactTable && $joinKey;
        if ($joinPossible) {
            foreach ($contactColumns as $col) {
                $col = $this->sanitizeColumnName($col);
                $alias = $this->normalizeKey('c_' . $col);
                if (isset($labels[$alias]) || isset($labels[$col])) {
                    continue;
                }
                $labels[$alias] = $col;
                $selects[] = DB::raw('c.[' . $col . '] as [' . $alias . ']');
                $filterColumns[$alias] = 'c.[' . $col . ']';
            }
        } else {
            // rebuild selects/labels/filters to only include transaction fields (drop contact fields)
            $labels = array_filter(
                $labels,
                static fn ($label, $key) => !str_starts_with($key, 'C_') && !str_starts_with($key, 'GRADUATED') && !str_starts_with($key, 'DROPPED') && !str_starts_with($key, 'c_'),
                ARRAY_FILTER_USE_BOTH
            );
            $filterColumns = array_filter(
                $filterColumns,
                static fn ($col, $key) => !str_starts_with($key, 'C_') && !str_starts_with($key, 'GRADUATED') && !str_starts_with($key, 'DROPPED') && !str_starts_with($key, 'c_'),
                ARRAY_FILTER_USE_BOTH
            );

            $selects = [];
            foreach ($filterColumns as $alias => $col) {
                $selects[] = DB::raw($col . ' as [' . $alias . ']');
            }

            $contactTable = null;
            $cIdCol = null;
        }

        $orderBy = array_key_exists('PROCESS_DATE', $filterColumns) ? 'PROCESS_DATE' : (array_key_first($filterColumns) ?: null);

        $this->resolved = [
            'transaction_table' => $transactionTable,
            'contact_table' => $contactTable,
            'selects' => $selects,
            'labels' => $labels,
            'filter_columns' => $filterColumns,
            'join_left' => $joinPossible ? 'c.[' . $joinKey . ']' : null,
            'join_right' => $joinPossible ? 't.[' . $joinKey . ']' : null,
            'order_by' => $orderBy,
        ];

        return $this->resolved;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, string>  $filterColumns
     */
    protected function applyFilters(Builder $query, array $filters, array $filterColumns): void
    {
        $contains = static fn ($value): bool => $value !== null && $value !== '';

        $map = [
            'contact_id' => 'CONTACT_ID',
            'trans_type' => 'TRANS_TYPE',
            'active' => 'ACTIVE',
            'cancelled' => 'CANCELLED',
            'process_from' => 'PROCESS_DATE',
            'process_to' => 'PROCESS_DATE',
            'cleared_from' => 'CLEARED_DATE',
            'cleared_to' => 'CLEARED_DATE',
            'returned_from' => 'RETURNED_DATE',
            'returned_to' => 'RETURNED_DATE',
            'amount_min' => 'AMOUNT',
            'amount_max' => 'AMOUNT',
        ];

        foreach ($map as $input => $alias) {
            $value = $filters[$input] ?? null;
            if (!$contains($value) || !isset($filterColumns[$alias])) {
                continue;
            }

            switch ($input) {
                case 'process_from':
                case 'cleared_from':
                case 'returned_from':
                    $query->whereRaw('CAST(' . $filterColumns[$alias] . ' AS date) >= ?', [$value]);
                    break;
                case 'process_to':
                case 'cleared_to':
                case 'returned_to':
                    $query->whereRaw('CAST(' . $filterColumns[$alias] . ' AS date) <= ?', [$value]);
                    break;
                case 'amount_min':
                    $query->whereRaw($filterColumns[$alias] . ' >= ?', [$value]);
                    break;
                case 'amount_max':
                    $query->whereRaw($filterColumns[$alias] . ' <= ?', [$value]);
                    break;
                default:
                    $query->whereRaw($filterColumns[$alias] . ' like ?', ['%' . trim((string) $value) . '%']);
                    break;
            }
        }
    }

    /**
     * @param  array<int, string>  $columns
     * @param  array<int, string>  $candidates
     */
    protected function firstMatchingColumn(array $columns, array $candidates): ?string
    {
        $lookup = [];
        foreach ($columns as $col) {
            $lookup[strtolower($col)] = $col;
        }

        foreach ($candidates as $candidate) {
            $key = strtolower($candidate);
            if (isset($lookup[$key])) {
                return $lookup[$key];
            }
        }

        foreach ($columns as $col) {
            $lower = strtolower($col);
            foreach ($candidates as $candidate) {
                $cand = strtolower($candidate);
                if ($cand !== '' && str_contains($lower, $cand)) {
                    return $col;
                }
            }
        }

        return null;
    }

    /**
     * @return array{0:string,1:array<int,string>}
     */
    protected function detectTransactionTable(): array
    {
        $candidates = [
            'dbo.TblTransactions',
            'TblTransactions',
            'dbo.Transactions',
            'Transactions',
            'dbo.TblACH',
            'TblACH',
        ];

        foreach ($candidates as $candidate) {
            if ($this->tableExists($candidate)) {
                return [$candidate, $this->listColumns($candidate)];
            }
        }

        $likeParts = [
            "COLUMN_NAME LIKE '%PROCESS_DATE%'",
            "COLUMN_NAME LIKE '%CLEARED%'",
            "COLUMN_NAME LIKE '%RETURNED%'",
            "COLUMN_NAME LIKE '%TRANS_TYPE%'",
            "COLUMN_NAME LIKE '%CONTACT_ID%'",
            "COLUMN_NAME LIKE '%LLG_ID%'",
        ];

        $detected = $this->detectBestTable($likeParts);
        if ($detected) {
            return [$detected, $this->listColumns($detected)];
        }

        return ['dbo.TblTransactions', $this->listColumns('dbo.TblTransactions')];
    }

    /**
     * @return array{0:?string,1:array<int,string>}
     */
    protected function detectContactTable(): array
    {
        $candidates = [
            'dbo.TblContacts',
            'TblContacts',
            'dbo.Contacts',
            'Contacts',
        ];

        foreach ($candidates as $candidate) {
            if ($this->tableExists($candidate)) {
                return [$candidate, $this->listColumns($candidate)];
            }
        }

        $detected = $this->detectBestTable(["COLUMN_NAME LIKE '%GRADUATED%'", "COLUMN_NAME LIKE '%DROPPED%'"]);

        return [$detected, $detected ? $this->listColumns($detected) : []];
    }

    /**
     * @param  array<int, string>  $likeParts
     */
    protected function detectBestTable(array $likeParts): ?string
    {
        try {
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
                if (str_contains($col, 'PROCESS')) $scores[$table] += 5;
                if (str_contains($col, 'TRANS')) $scores[$table] += 4;
                if (str_contains($col, 'CLEARED')) $scores[$table] += 3;
                if (str_contains($col, 'RETURN')) $scores[$table] += 3;
                if (str_contains($col, 'CONTACT')) $scores[$table] += 3;
                if (str_contains($col, 'LLG')) $scores[$table] += 2;
                if (str_contains($tableName, 'TRANS')) $scores[$table] += 3;
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

    protected function normalizeKey(string $column): string
    {
        $key = preg_replace('/[^A-Za-z0-9]+/', '_', $column) ?: $column;
        $key = trim($key, '_');
        return $key === '' ? 'col' : $key;
    }

    protected function sanitizeColumnName(string $column): string
    {
        $col = trim($column);
        $col = ltrim($col, '[');
        $col = rtrim($col, ']');

        return $col;
    }
}
