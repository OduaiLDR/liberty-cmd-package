<?php

namespace Cmd\Reports\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class LlgExecAdminReportRepository extends SqlSrvRepository
{
    protected ?array $resolved = null;

    /**
     * @return array<string, string>
     */
    public function columns(): array
    {
        return [
            'llg_id' => 'LLG ID',
            'client' => 'Client',
            'source' => 'Source',
            'funding_date' => 'Funding Date',
            'loan_amount' => 'Loan Amount',
            'commission' => 'Commission',
            'import_date' => 'Import Date',
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function all(?string $from = null, ?string $to = null, array $filters = []): Collection
    {
        return $this->baseQuery($from, $to, $filters)->get();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(?string $from = null, ?string $to = null, int $perPage = 25, array $filters = []): LengthAwarePaginator
    {
        return $this->paginateBuilder($this->baseQuery($from, $to, $filters), $perPage);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function sample(?string $from = null, ?string $to = null, int $limit = 250, array $filters = []): Collection
    {
        return $this->baseQuery($from, $to, $filters)->limit($limit)->get();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function baseQuery(?string $from, ?string $to, array $filters): Builder
    {
        // Build LLG Exec Admin report from TblEnrollment data
        // Use Submitted_Date as funding_date if First_Payment_Date is null
        $query = $this->table('TblEnrollment')
            ->select([
                DB::raw("CONCAT('LLG-', LLG_ID) AS llg_id"),
                'Client AS client',
                DB::raw("COALESCE(Drop_Name, 'Direct') AS source"),
                DB::raw("COALESCE(First_Payment_Date, First_Payment_Cleared_Date, Submitted_Date) AS funding_date"),
                'Debt_Amount AS loan_amount',
                DB::raw('Debt_Amount * 0.02 AS commission'),
                'Submitted_Date AS import_date',
            ])
            ->whereNotNull('LLG_ID');

        if ($from) {
            $query->whereDate('Submitted_Date', '>=', $from);
        }
        if ($to) {
            $query->whereDate('Submitted_Date', '<=', $to);
        }

        if (!empty($filters['client'])) {
            $query->where('Client', 'like', '%' . $filters['client'] . '%');
        }

        if (!empty($filters['source'])) {
            $query->where('Drop_Name', 'like', '%' . $filters['source'] . '%');
        }

        return $query->orderBy('Submitted_Date', 'desc');
    }
    
    /**
     * Get summary totals for the report
     */
    public function getSummary(?string $from = null, ?string $to = null, array $filters = []): object
    {
        $query = $this->table('TblEnrollment')
            ->selectRaw('COUNT(*) AS total_records')
            ->selectRaw('SUM(Debt_Amount) AS total_loan_amount')
            ->selectRaw('SUM(Debt_Amount * 0.02) AS total_commission')
            ->whereNotNull('LLG_ID');

        if ($from) {
            $query->whereDate('Submitted_Date', '>=', $from);
        }
        if ($to) {
            $query->whereDate('Submitted_Date', '<=', $to);
        }

        if (!empty($filters['client'])) {
            $query->where('Client', 'like', '%' . $filters['client'] . '%');
        }

        if (!empty($filters['source'])) {
            $query->where('Drop_Name', 'like', '%' . $filters['source'] . '%');
        }

        return $query->first() ?? (object) ['total_records' => 0, 'total_loan_amount' => 0, 'total_commission' => 0];
    }

    /**
     * @return array{
     *   table:string,
     *   selects: array<int, \Illuminate\Database\Query\Expression|string>,
     *   labels: array<string,string>,
     *   filter_columns: array<string,string>,
     *   date_column: ?string,
     *   order_by: ?string,
     *   force_empty: bool
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
            $table = $this->tableExists('dbo.TblLlgExecAdmin')
                ? 'dbo.TblLlgExecAdmin'
                : ($this->tableExists('TblLlgExecAdmin')
                    ? 'TblLlgExecAdmin'
                    : ($this->tableExists('dbo.TblExecAdmin')
                        ? 'dbo.TblExecAdmin'
                        : ($this->tableExists('TblExecAdmin') ? 'TblExecAdmin' : null)));
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

        $findByLabel = function (array $candidates) use ($labels): ?string {
            foreach ($labels as $key => $label) {
                $l = strtolower((string) $label);
                foreach ($candidates as $cand) {
                    if ($cand !== '' && str_contains($l, strtolower($cand))) {
                        return $key;
                    }
                }
            }
            return null;
        };

        $dateKey = $findByLabel(['funding date', 'funding_date', 'import date', 'import_date', 'date', 'created']);
        $dateColumn = $dateKey ? ('x.[' . $labels[$dateKey] . ']') : null;

        $orderBy = $dateColumn;

        foreach ([
            'client' => ['client', 'customer', 'borrower'],
            'source' => ['source'],
            'employee_name' => ['employee', 'agent', 'rep', 'sales'],
            'payment_type' => ['payment type', 'payment_type', 'payment'],
        ] as $input => $cands) {
            $key = $findByLabel($cands);
            if ($key) {
                $filterColumns[$input] = 'x.[' . $labels[$key] . ']';
            }
        }

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
        if ($key === '') {
            $key = 'col';
        }

        return $key;
    }

    protected function detectBestTable(): ?string
    {
        try {
            $likeParts = [
                "COLUMN_NAME LIKE '%CLIENT%'",
                "COLUMN_NAME LIKE '%SOURCE%'",
                "COLUMN_NAME LIKE '%FUND%'",
                "COLUMN_NAME LIKE '%FUNDING%'",
                "COLUMN_NAME LIKE '%LOAN%'",
                "COLUMN_NAME LIKE '%AMOUNT%'",
                "COLUMN_NAME LIKE '%COMMIS%'",
                "COLUMN_NAME LIKE '%IMPORT%'",
                "COLUMN_NAME LIKE '%EMPLOYEE%'",
                "COLUMN_NAME LIKE '%PAYMENT%'",
                "COLUMN_NAME LIKE '%TYPE%'",
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

                if (str_contains($col, 'CLIENT')) $scores[$table] += 6;
                if (str_contains($col, 'SOURCE')) $scores[$table] += 5;
                if (str_contains($col, 'FUNDING') || str_contains($col, 'FUND')) $scores[$table] += 6;
                if (str_contains($col, 'LOAN')) $scores[$table] += 5;
                if (str_contains($col, 'AMOUNT')) $scores[$table] += 5;
                if (str_contains($col, 'COMMIS')) $scores[$table] += 6;
                if (str_contains($col, 'IMPORT')) $scores[$table] += 5;
                if (str_contains($col, 'EMPLOYEE')) $scores[$table] += 4;
                if (str_contains($col, 'PAYMENT')) $scores[$table] += 4;
                if (str_contains($col, 'TYPE')) $scores[$table] += 2;

                if (str_contains($tableName, 'EXEC')) $scores[$table] += 4;
                if (str_contains($tableName, 'ADMIN')) $scores[$table] += 4;
                if (str_contains($tableName, 'LLG')) $scores[$table] += 2;
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

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, string>  $filterColumns
     */
    protected function applyFilters(Builder $query, array $filters, array $filterColumns): void
    {
        $contains = static fn ($value): bool => $value !== null && $value !== '';

        foreach (['client', 'source', 'employee_name', 'payment_type'] as $input) {
            $value = $filters[$input] ?? null;
            if (!$contains($value)) {
                continue;
            }

            if (!isset($filterColumns[$input])) {
                continue;
            }

            $query->whereRaw($filterColumns[$input] . ' like ?', ['%' . trim((string) $value) . '%']);
        }
    }
}
