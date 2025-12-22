<?php

namespace Cmd\Reports\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CreditorContactsReportRepository extends SqlSrvRepository
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
        $table = $resolved['table'];
        $selects = $resolved['selects'];
        $filterColumns = $resolved['filter_columns'];

        $query = $this->table($table, 'cc')->select($selects);

        $this->applyFilters($query, $filters, $filterColumns);

        $orderKey = $resolved['order_by'] ?? null;
        if ($orderKey && isset($filterColumns[$orderKey])) {
            $query->orderByRaw($filterColumns[$orderKey] . ' asc');
        }

        return $query;
    }

    /**
     * @return array{
     *     table:string,
     *     selects:array<int, \Illuminate\Database\Query\Expression|string>,
     *     labels:array<string,string>,
     *     filter_columns:array<string,string>,
     *     order_by:?string
     * }
     */
    protected function resolvedSelectMap(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $table = $this->tableExists('dbo.TblCreditorContacts')
            ? 'dbo.TblCreditorContacts'
            : ($this->tableExists('TblCreditorContacts') ? 'TblCreditorContacts' : ($this->detectBestTable() ?? 'dbo.TblCreditorGroups'));

        $columns = $this->listColumns($table);

        $map = [
            'Creditor_Name' => [
                'label' => 'Creditor Name',
                'candidates' => ['Creditor_Name', 'Creditor', 'Name', 'CreditorName'],
            ],
            'Parent_Account' => [
                'label' => 'Parent Account',
                'candidates' => ['Parent_Account', 'ParentAccount', 'Parent', 'Group_Name', 'GroupName'],
            ],
            'POA_Exclusion' => [
                'label' => 'POA Exclusion',
                'candidates' => ['POA_Exclusion', 'Poa_Exclusion', 'POAExclude', 'Exclude_POA', 'ExcludePOA', 'POA'],
            ],
            'Email' => [
                'label' => 'Email',
                'candidates' => ['Email', 'Email_Address', 'EmailAddress', 'E_mail'],
            ],
            'Fax' => [
                'label' => 'Fax',
                'candidates' => ['Fax', 'Fax_Number', 'FaxNumber'],
            ],
            'Contact_Name' => [
                'label' => 'Contact Name',
                'candidates' => ['Contact_Name', 'ContactName', 'Contact', 'POC', 'Point_of_Contact'],
            ],
            'Contact_Phone' => [
                'label' => 'Contact Phone',
                'candidates' => ['Contact_Phone', 'ContactPhone', 'Phone', 'Phone_Number', 'PhoneNumber', 'Telephone'],
            ],
            'Creditor_Address' => [
                'label' => 'Creditor Address',
                'candidates' => ['Creditor_Address', 'Address', 'Address_1', 'Mailing_Address', 'Street_Address', 'Street'],
            ],
            'Notes' => [
                'label' => 'Notes',
                'candidates' => ['Notes', 'Note', 'Comments', 'Comment'],
            ],
        ];

        $labels = [];
        $selects = [];
        $filterColumns = [];

        foreach ($map as $alias => $def) {
            $resolvedColumn = $this->firstMatchingColumn($columns, $def['candidates']);
            if (!$resolvedColumn) {
                continue;
            }

            $labels[$alias] = $def['label'];
            $selects[] = DB::raw('cc.[' . $resolvedColumn . '] as [' . $alias . ']');
            $filterColumns[$alias] = 'cc.[' . $resolvedColumn . ']';
        }

        if (count($selects) === 0) {
            $selects = ['cc.*'];
            $labels = [];
            foreach ($columns as $col) {
                $labels[$col] = $col;
                $filterColumns[$col] = 'cc.[' . $col . ']';
            }
        }

        $this->resolved = [
            'table' => $table,
            'selects' => $selects,
            'labels' => $labels,
            'filter_columns' => $filterColumns,
            'order_by' => array_key_exists('Creditor_Name', $filterColumns) ? 'Creditor_Name' : (array_key_first($filterColumns) ?: null),
        ];

        return $this->resolved;
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

    protected function detectBestTable(): ?string
    {
        try {
            $likeParts = [
                "COLUMN_NAME LIKE '%CREDITOR%'",
                "COLUMN_NAME LIKE '%EMAIL%'",
                "COLUMN_NAME LIKE '%FAX%'",
                "COLUMN_NAME LIKE '%CONTACT%'",
                "COLUMN_NAME LIKE '%PHONE%'",
                "COLUMN_NAME LIKE '%ADDRESS%'",
                "COLUMN_NAME LIKE '%NOTES%'",
                "COLUMN_NAME LIKE '%POA%'",
                "COLUMN_NAME LIKE '%PARENT%'",
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

                if (str_contains($col, 'CREDITOR')) $scores[$table] += 5;
                if (str_contains($col, 'CONTACT')) $scores[$table] += 4;
                if (str_contains($col, 'EMAIL')) $scores[$table] += 3;
                if (str_contains($col, 'PHONE')) $scores[$table] += 3;
                if (str_contains($col, 'FAX')) $scores[$table] += 2;
                if (str_contains($col, 'ADDRESS')) $scores[$table] += 2;
                if (str_contains($col, 'NOTES')) $scores[$table] += 1;
                if (str_contains($col, 'POA')) $scores[$table] += 1;
                if (str_contains($col, 'PARENT')) $scores[$table] += 1;
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
     * @param  array<string, mixed>  $filters
     * @param  array<string, string>  $filterColumns
     */
    protected function applyFilters(Builder $query, array $filters, array $filterColumns): void
    {
        $contains = static fn ($value): bool => $value !== null && $value !== '';

        $map = [
            'creditor_name' => 'Creditor_Name',
            'parent_account' => 'Parent_Account',
            'poa_exclusion' => 'POA_Exclusion',
            'email' => 'Email',
            'fax' => 'Fax',
            'contact_name' => 'Contact_Name',
            'contact_phone' => 'Contact_Phone',
            'creditor_address' => 'Creditor_Address',
            'notes' => 'Notes',
        ];

        foreach ($map as $input => $alias) {
            $value = $filters[$input] ?? null;
            if (!$contains($value)) {
                continue;
            }

            if (!isset($filterColumns[$alias])) {
                continue;
            }

            $query->whereRaw($filterColumns[$alias] . ' like ?', ['%' . trim((string) $value) . '%']);
        }
    }
}
