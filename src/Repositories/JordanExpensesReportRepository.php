<?php

namespace Cmd\Reports\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class JordanExpensesReportRepository extends SqlSrvRepository
{
    protected ?array $resolved = null;
    protected bool $forceEmpty = false;

    /**
     * @return array<string, string>
     */
    public function columns(): array
    {
        return [
            'date' => 'Date',
            'category' => 'Category',
            'description' => 'Description',
            'company' => 'Company',
            'amount' => 'Amount (JOD)',
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
     * Get total amount for summary
     */
    public function getTotalAmount(?string $from = null, ?string $to = null, array $filters = []): float
    {
        $tableInfo = $this->findExpenseTable();
        if (!$tableInfo) {
            return 0.0;
        }
        
        $query = $this->table($tableInfo['table']);
        
        if ($from && $tableInfo['date_col']) {
            $query->whereDate($tableInfo['date_col'], '>=', $from);
        }
        if ($to && $tableInfo['date_col']) {
            $query->whereDate($tableInfo['date_col'], '<=', $to);
        }
        
        if (!empty($filters['category']) && $tableInfo['category_col']) {
            $query->where($tableInfo['category_col'], 'like', '%' . $filters['category'] . '%');
        }
        if (!empty($filters['company']) && $tableInfo['company_col']) {
            $query->where($tableInfo['company_col'], 'like', '%' . $filters['company'] . '%');
        }
        
        return (float) $query->sum($tableInfo['amount_col']);
    }

    /**
     * Find the actual expense table in the database
     * @return array{table:string,date_col:string,category_col:?string,description_col:string,company_col:string,amount_col:string}|null
     */
    protected function findExpenseTable(): ?array
    {
        // Try common expense table names first
        $candidates = [
            'dbo.TblJordanExpenses', 'TblJordanExpenses',
            'dbo.JordanExpenses', 'JordanExpenses', 
            'dbo.TblExpenses', 'TblExpenses',
            'dbo.Expenses', 'Expenses',
            'dbo.TblJordanExpense', 'TblJordanExpense',
            'dbo.Jordan_Expenses', 'Jordan_Expenses',
            'dbo.ExpenseReport', 'ExpenseReport',
            'dbo.TblExpenseReport', 'TblExpenseReport',
        ];
        
        foreach ($candidates as $table) {
            $result = $this->tryTable($table);
            if ($result) return $result;
        }
        
        // If no predefined table found, search database for expense-related tables
        try {
            $tables = $this->connection()->select(
                "SELECT TABLE_SCHEMA, TABLE_NAME FROM INFORMATION_SCHEMA.TABLES 
                 WHERE TABLE_TYPE = 'BASE TABLE' 
                 AND (TABLE_NAME LIKE '%Expense%' OR TABLE_NAME LIKE '%Jordan%' OR TABLE_NAME LIKE '%Cost%')
                 ORDER BY TABLE_NAME"
            );
            
            foreach ($tables as $t) {
                $tableName = ($t->TABLE_SCHEMA ?? 'dbo') . '.' . ($t->TABLE_NAME ?? '');
                $result = $this->tryTable($tableName);
                if ($result) return $result;
            }
        } catch (\Throwable $e) {
            // Ignore errors in schema query
        }
        
        return null;
    }
    
    /**
     * Try a specific table to see if it has expense-like columns
     */
    protected function tryTable(string $table): ?array
    {
        if (!$this->tableExists($table)) {
            return null;
        }
        
        $cols = $this->listColumns($table);
        if (empty($cols)) {
            return null;
        }
        
        // Find column mappings with more flexible matching
        $dateCol = $this->findColumn($cols, ['Date', 'Expense_Date', 'Transaction_Date', 'Created_Date', 'Entry_Date', 'Posted_Date']);
        $categoryCol = $this->findColumn($cols, ['Category', 'Expense_Category', 'Type', 'Expense_Type', 'Class']);
        $descCol = $this->findColumn($cols, ['Description', 'Memo', 'Notes', 'Detail', 'Details', 'Expense_Description', 'Name', 'Item']);
        $companyCol = $this->findColumn($cols, ['Company', 'Company_Name', 'Vendor', 'Vendor_Name', 'Payee', 'Merchant', 'Business']);
        $amountCol = $this->findColumn($cols, ['Amount', 'Expense_Amount', 'Cost', 'Total', 'Value', 'Price', 'Debit']);
        
        // At minimum need date and amount columns
        if ($dateCol && $amountCol) {
            return [
                'table' => $table,
                'date_col' => $dateCol,
                'category_col' => $categoryCol,
                'description_col' => $descCol ?? $categoryCol ?? 'NULL',
                'company_col' => $companyCol ?? 'NULL',
                'amount_col' => $amountCol,
            ];
        }
        
        return null;
    }
    
    /**
     * Find a column from candidates
     */
    protected function findColumn(array $columns, array $candidates): ?string
    {
        $colsLower = [];
        foreach ($columns as $col) {
            $colsLower[strtolower($col)] = $col;
        }
        
        foreach ($candidates as $candidate) {
            $key = strtolower($candidate);
            if (isset($colsLower[$key])) {
                return $colsLower[$key];
            }
        }
        return null;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function baseQuery(?string $from, ?string $to, array $filters): Builder
    {
        $tableInfo = $this->findExpenseTable();
        
        if (!$tableInfo) {
            // No expense table found - return empty result
            return $this->table('TblEnrollment')->whereRaw('1=0');
        }
        
        $selects = [
            $tableInfo['date_col'] . ' AS date',
        ];
        
        if ($tableInfo['category_col'] && $tableInfo['category_col'] !== 'NULL') {
            $selects[] = DB::raw("COALESCE([{$tableInfo['category_col']}], '') AS category");
        } else {
            $selects[] = DB::raw("'' AS category");
        }
        
        if ($tableInfo['description_col'] && $tableInfo['description_col'] !== 'NULL') {
            $selects[] = DB::raw("COALESCE([{$tableInfo['description_col']}], '') AS description");
        } else {
            $selects[] = DB::raw("'' AS description");
        }
        
        if ($tableInfo['company_col'] && $tableInfo['company_col'] !== 'NULL') {
            $selects[] = DB::raw("COALESCE([{$tableInfo['company_col']}], '') AS company");
        } else {
            $selects[] = DB::raw("'' AS company");
        }
        
        $selects[] = $tableInfo['amount_col'] . ' AS amount';
        
        $query = $this->table($tableInfo['table'])->select($selects);

        if ($from) {
            $query->whereDate($tableInfo['date_col'], '>=', $from);
        }
        if ($to) {
            $query->whereDate($tableInfo['date_col'], '<=', $to);
        }

        if (!empty($filters['category']) && $tableInfo['category_col'] && $tableInfo['category_col'] !== 'NULL') {
            $query->where($tableInfo['category_col'], 'like', '%' . $filters['category'] . '%');
        }
        if (!empty($filters['description']) && $tableInfo['description_col'] && $tableInfo['description_col'] !== 'NULL') {
            $query->where($tableInfo['description_col'], 'like', '%' . $filters['description'] . '%');
        }
        if (!empty($filters['company']) && $tableInfo['company_col'] && $tableInfo['company_col'] !== 'NULL') {
            $query->where($tableInfo['company_col'], 'like', '%' . $filters['company'] . '%');
        }

        return $query->orderBy($tableInfo['date_col'], 'desc');
    }

    /**
     * @return array{
     *     table:string,
     *     selects:array<int, \Illuminate\Database\Query\Expression|string>,
     *     labels:array<string,string>,
     *     filter_columns:array<string,string>,
     *     date_column:?string,
     *     order_by:?string
     * }
     */
    protected function resolvedSelectMap(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $table = $this->detectBestTable();
        if (!$table) {
            $table = $this->tableExists('dbo.TblJordanExpenses')
                ? 'dbo.TblJordanExpenses'
                : ($this->tableExists('TblJordanExpenses')
                    ? 'TblJordanExpenses'
                    : ($this->tableExists('dbo.TblExpenses')
                        ? 'dbo.TblExpenses'
                        : ($this->tableExists('TblExpenses') ? 'TblExpenses' : null)));
        }

        $forceEmpty = false;
        if (!$table) {
            // Keep the route stable without accidentally showing unrelated data.
            $table = $this->tableExists('dbo.TblEnrollment') ? 'dbo.TblEnrollment' : 'TblEnrollment';
            $forceEmpty = true;
        }

        $columns = $this->listColumns($table);

        $map = [
            'Date' => [
                'label' => 'Date',
                'candidates' => ['Expense_Date', 'Transaction_Date', 'Posted_Date', 'Payment_Date', 'Date', 'ExpenseDate', 'TransactionDate', 'PostedDate', 'Created_Date', 'Entry_Date'],
            ],
            'Category' => [
                'label' => 'Category',
                'candidates' => ['Category', 'Catergory', 'Expense_Category', 'ExpenseCategory', 'Type', 'Expense_Type', 'Class'],
            ],
            'Description' => [
                'label' => 'Description',
                'candidates' => ['Description', 'Desc', 'Memo', 'Notes', 'Detail', 'Details', 'Expense_Description', 'Transaction_Description', 'Line_Item', 'Item'],
            ],
            'Company' => [
                'label' => 'Company',
                'candidates' => ['Company', 'Company_Name', 'Vendor', 'Vendor_Name', 'Merchant', 'Merchant_Name', 'Payee', 'Payee_Name', 'Supplier', 'Business', 'Store'],
            ],
            'Amount' => [
                'label' => 'Amount',
                'candidates' => ['Amount', 'Expense_Amount', 'ExpenseAmount', 'Cost', 'Total', 'Price', 'Payment', 'Debit', 'Credit', 'Value'],
            ],
        ];

        $labels = [];
        $selects = [];
        $filterColumns = [];

        $find = fn(array $cols, array $candidates) => $this->firstMatchingColumn($cols, $candidates);

        foreach ($map as $alias => $def) {
            $resolvedColumn = $find($columns, $def['candidates']);
            if (!$resolvedColumn) {
                continue;
            }

            $labels[$alias] = $def['label'];
            $selects[] = DB::raw('x.[' . $resolvedColumn . '] as [' . $alias . ']');
            $filterColumns[strtolower($alias)] = 'x.[' . $resolvedColumn . ']';
        }

        if (count($selects) === 0) {
            if (!$forceEmpty) {
                $selects = ['x.*'];
                foreach ($columns as $col) {
                    $labels[$col] = $col;
                    $filterColumns[strtolower($col)] = 'x.[' . $col . ']';
                }
            }
        }

        $dateColumn = null;
        if (isset($labels['Date'])) {
            $dateColumn = $filterColumns['date'] ?? null;
        }

        $orderBy = $dateColumn ?: (isset($filterColumns['date']) ? $filterColumns['date'] : null);

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

    protected function detectBestTable(): ?string
    {
        try {
            $likeParts = [
                "COLUMN_NAME LIKE '%EXPENSE%'",
                "COLUMN_NAME LIKE '%CATEGORY%'",
                "COLUMN_NAME LIKE '%DESCRIPTION%'",
                "COLUMN_NAME LIKE '%COMPANY%'",
                "COLUMN_NAME LIKE '%VENDOR%'",
                "COLUMN_NAME LIKE '%AMOUNT%'",
                "COLUMN_NAME LIKE '%COST%'",
                "COLUMN_NAME LIKE '%DATE%'",
                "COLUMN_NAME LIKE '%JORDAN%'",
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

                if (str_contains($col, 'EXPENSE')) $scores[$table] += 6;
                if (str_contains($col, 'CATEGORY')) $scores[$table] += 5;
                if (str_contains($col, 'DESCRIPTION')) $scores[$table] += 5;
                if (str_contains($col, 'COMPANY')) $scores[$table] += 4;
                if (str_contains($col, 'VENDOR')) $scores[$table] += 4;
                if (str_contains($col, 'AMOUNT')) $scores[$table] += 6;
                if (str_contains($col, 'COST')) $scores[$table] += 3;
                if (str_contains($col, 'DATE')) $scores[$table] += 4;
                if (str_contains($col, 'JORDAN')) $scores[$table] += 2;
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

        foreach (['category', 'company', 'description'] as $key) {
            $value = $filters[$key] ?? null;
            if (!$contains($value)) {
                continue;
            }

            if (!isset($filterColumns[$key])) {
                continue;
            }

            $query->whereRaw($filterColumns[$key] . ' like ?', ['%' . trim((string) $value) . '%']);
        }
    }
}
