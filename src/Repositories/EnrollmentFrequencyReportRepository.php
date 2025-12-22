<?php

namespace Cmd\Reports\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EnrollmentFrequencyReportRepository extends SqlSrvRepository
{
    protected ?array $resolved = null;

    /**
        * @return array<string, string>
     */
    public function columns(): array
    {
        return $this->resolvedSelectMap()['labels'];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function all(array $filters = []): Collection
    {
        return $this->baseQuery($filters)->get();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(int $perPage = 25, array $filters = []): LengthAwarePaginator
    {
        return $this->paginateBuilder($this->baseQuery($filters), $perPage);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function baseQuery(array $filters = []): Builder
    {
        $resolved = $this->resolvedSelectMap();

        $query = $this->table($resolved['table'], 'c')->select($resolved['selects']);

        if ($resolved['frequency_table'] && $resolved['frequency_join_left'] && $resolved['frequency_join_right']) {
            $query->leftJoin(
                $resolved['frequency_table'] . ' as cu',
                $resolved['frequency_join_left'],
                '=',
                $resolved['frequency_join_right']
            );
        }

        $this->applyFilters($query, $filters, $resolved['filter_columns']);

        if ($resolved['order_by'] && isset($resolved['filter_columns'][$resolved['order_by']])) {
            $query->orderByRaw($resolved['filter_columns'][$resolved['order_by']] . ' asc');
        }

        return $query;
    }

    /**
     * @return array{
     *  table:string,
     *  selects:array<int, \Illuminate\Database\Query\Expression|string>,
     *  labels:array<string,string>,
     *  filter_columns:array<string,string>,
     *  order_by:?string,
     *  frequency_table:?string,
     *  frequency_join_left:?string,
     *  frequency_join_right:?string
     * }
     */
    protected function resolvedSelectMap(): array
    {
        // recompute every call to avoid stale join metadata
        $this->resolved = null;

        $contactTable = $this->detectContactTable();
        $contactColumns = $this->listColumns($contactTable);

        $freqTable = $this->detectFrequencyTable();
        $freqColumns = $freqTable ? $this->listColumns($freqTable) : [];

        // only allow joining when both tables have LLG_ID
        $canJoin = $freqTable && in_array('LLG_ID', $contactColumns, true) && in_array('LLG_ID', $freqColumns, true);
        if (!$canJoin) {
            $freqTable = null;
            $freqColumns = [];
        }

        $preferredJoinKeys = ['LLG_ID'];

        $map = [
            'ID' => [
                'label' => 'ID',
                'candidates' => ['ID', 'Contact_ID', 'LLG_ID', 'LLGID', 'CID'],
                'table' => 'c',
            ],
            'FIRSTNAME' => [
                'label' => 'First Name',
                'candidates' => ['FIRSTNAME', 'FirstName', 'First_Name', 'FIRST_NAME'],
                'table' => 'c',
            ],
            'LASTNAME' => [
                'label' => 'Last Name',
                'candidates' => ['LASTNAME', 'LastName', 'Last_Name', 'LAST_NAME'],
                'table' => 'c',
            ],
            'F_SHORTSTRING' => [
                'label' => 'Frequency',
                'candidates' => ['F_SHORTSTRING', 'Frequency', 'FREQUENCY', 'F_ShortString', 'Payment_Frequency'],
                'table' => $freqTable ? 'cu' : 'c',
            ],
        ];

        $labels = [];
        $selects = [];
        $filterColumns = [];

        $idColumn = null;

        foreach ($map as $alias => $def) {
            $candidates = $def['candidates'];
            $tableAlias = $def['table'];
            $resolvedColumn = null;

            if ($tableAlias === 'c') {
                $resolvedColumn = $this->firstMatchingColumn($contactColumns, $candidates);
            } elseif ($tableAlias === 'cu' && $freqTable) {
                $resolvedColumn = $this->firstMatchingColumn($freqColumns, $candidates);
            }

            if (!$resolvedColumn) {
                continue;
            }

            $labels[$alias] = $def['label'];
            $selects[] = DB::raw($tableAlias . '.[' . $resolvedColumn . '] as [' . $alias . ']');
            $filterColumns[$alias] = $tableAlias . '.[' . $resolvedColumn . ']';

            if ($alias === 'ID') {
                $idColumn = $resolvedColumn;
            }
        }

        if (count($selects) === 0) {
            // fallback: select all contact columns
            foreach ($contactColumns as $col) {
                $labels[$col] = $col;
                $selects[] = DB::raw('c.[' . $col . '] as [' . $col . ']');
                $filterColumns[$col] = 'c.[' . $col . ']';
            }
        }

        // include remaining contact columns to pull all data
        foreach ($contactColumns as $col) {
            $alias = $this->normalizeKey($col);
            if (isset($labels[$alias])) {
                continue;
            }
            $labels[$alias] = $col;
            $selects[] = DB::raw('c.[' . $col . '] as [' . $alias . ']');
            $filterColumns[$alias] = 'c.[' . $col . ']';
        }

        // determine join keys strictly on LLG_ID; if missing on either table, drop frequency table entirely
        $contactJoinCol = in_array('LLG_ID', $contactColumns, true) ? 'LLG_ID' : null;
        $freqJoinCol = $freqTable && in_array('LLG_ID', $freqColumns, true) ? 'LLG_ID' : null;

        if ($freqTable && $contactJoinCol && $freqJoinCol) {
            foreach ($freqColumns as $col) {
                $alias = $this->normalizeKey('cu_' . $col);
                if (isset($labels[$alias])) {
                    continue;
                }
                $labels[$alias] = $col;
                $selects[] = DB::raw('cu.[' . $col . '] as [' . $alias . ']');
                $filterColumns[$alias] = 'cu.[' . $col . ']';
            }
        } else {
            // if we cannot join safely, disable frequency table usage to avoid invalid bindings
            $freqTable = null;
            $contactJoinCol = null;
            $freqJoinCol = null;
            // drop any frequency (cu.*) selections/labels/filters that were added earlier
            $labels = array_filter(
                $labels,
                static fn ($label, $key) => $key !== 'F_SHORTSTRING' && !str_starts_with($key, 'cu_'),
                ARRAY_FILTER_USE_BOTH
            );
            $filterColumns = array_filter(
                $filterColumns,
                static fn ($col, $key) => $key !== 'F_SHORTSTRING' && !str_starts_with($key, 'cu_'),
                ARRAY_FILTER_USE_BOTH
            );
            // rebuild selects using remaining filter columns (only contact table)
            $selects = [];
            foreach ($filterColumns as $alias => $col) {
                if (str_contains(strtolower($col), 'cu.')) {
                    continue;
                }
                $selects[] = DB::raw($col . ' as [' . $alias . ']');
            }
        }

        $this->resolved = [
            'table' => $contactTable,
            'selects' => $selects,
            'labels' => $labels,
            'filter_columns' => $filterColumns,
            'order_by' => array_key_exists('ID', $filterColumns) ? 'ID' : (array_key_first($filterColumns) ?: null),
            'frequency_table' => $freqTable,
            'frequency_join_left' => $freqTable && $freqJoinCol ? 'cu.[' . $freqJoinCol . ']' : null,
            'frequency_join_right' => $freqTable && $contactJoinCol ? 'c.[' . $contactJoinCol . ']' : null,
        ];

        return $this->resolved;
    }

    protected function detectContactTable(): string
    {
        foreach (['dbo.TblContacts', 'TblContacts', 'dbo.Contacts', 'Contacts'] as $candidate) {
            if ($this->tableExists($candidate)) {
                return $candidate;
            }
        }

        $detected = $this->detectBestTable(['COLUMN_NAME LIKE \'%FIRST%\'', 'COLUMN_NAME LIKE \'%LAST%\'']);
        return $detected ?? 'dbo.TblContacts';
    }

    protected function detectFrequencyTable(): ?string
    {
        foreach (['dbo.TblCustom', 'TblCustom', 'dbo.CustomFields', 'CustomFields'] as $candidate) {
            if ($this->tableExists($candidate) && in_array('F_SHORTSTRING', array_map('strtoupper', $this->listColumns($candidate)), true)) {
                return $candidate;
            }
        }

        return $this->detectBestTable(["COLUMN_NAME LIKE '%F_SHORTSTRING%'", "COLUMN_NAME LIKE '%FREQUENCY%'"]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, string>  $filterColumns
     */
    protected function applyFilters(Builder $query, array $filters, array $filterColumns): void
    {
        $contains = static fn ($value): bool => $value !== null && $value !== '';

        $map = [
            'id' => 'ID',
            'first_name' => 'FIRSTNAME',
            'last_name' => 'LASTNAME',
            'frequency' => 'F_SHORTSTRING',
            'created_from' => 'CREATED_DATE',
            'created_to' => 'CREATED_DATE',
            'assigned_from' => 'ASSIGNED_DATE',
            'assigned_to' => 'ASSIGNED_DATE',
        ];

        foreach ($map as $input => $alias) {
            $value = $filters[$input] ?? null;
            if (!$contains($value) || !isset($filterColumns[$alias])) {
                continue;
            }

            switch ($input) {
                case 'created_from':
                case 'assigned_from':
                    $query->whereRaw('CAST(' . $filterColumns[$alias] . ' AS date) >= ?', [$value]);
                    break;
                case 'created_to':
                case 'assigned_to':
                    $query->whereRaw('CAST(' . $filterColumns[$alias] . ' AS date) <= ?', [$value]);
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
                if (str_contains($col, 'ID')) $scores[$table] += 2;
                if (str_contains($col, 'FIRST')) $scores[$table] += 3;
                if (str_contains($col, 'LAST')) $scores[$table] += 3;
                if (str_contains($col, 'F_SHORTSTRING')) $scores[$table] += 5;
                if (str_contains($col, 'FREQ')) $scores[$table] += 4;
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
}
