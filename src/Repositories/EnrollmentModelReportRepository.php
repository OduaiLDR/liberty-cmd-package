<?php

namespace Cmd\Reports\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EnrollmentModelReportRepository extends SqlSrvRepository
{
    protected ?array $resolved = null;

    /** @return array<string, string> */
    public function columns(): array
    {
        return [
            'category' => 'Revenue and Expenses',
            'jan' => 'Jan',
            'feb' => 'Feb',
            'mar' => 'Mar',
            'apr' => 'Apr',
            'may' => 'May',
            'jun' => 'Jun',
            'jul' => 'Jul',
            'aug' => 'Aug',
            'sep' => 'Sep',
            'oct' => 'Oct',
            'nov' => 'Nov',
            'dec' => 'Dec',
        ];
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
        // This method returns a dummy query - actual data is generated in getMonthlyData()
        return $this->table('TblEnrollment')->whereRaw('1=0');
    }

    /**
     * Get monthly enrollment model data pivoted by category and month
     * @param int|null $year
     * @param int|null $fromMonth
     * @param int|null $toMonth
     * @return Collection
     */
    public function getMonthlyData(?int $year = null, ?int $fromMonth = null, ?int $toMonth = null): Collection
    {
        $year = $year ?? (int) date('Y');
        
        // Get monthly aggregates
        // Profit/Loss = Revenue (deals * commission rate) - Costs (item + mail + commission costs)
        // Balance = Cumulative running total of Profit/Loss
        $monthlyData = $this->table('TblEnrollment')
            ->select([
                DB::raw('MONTH(Submitted_Date) AS month'),
                DB::raw('COUNT(*) AS amount_dropped'),
                DB::raw('SUM(Debt_Amount) * 0.005 AS item_drop_cost'),
                DB::raw('SUM(Debt_Amount) * 0.01 AS mail_drop_cost'),
                DB::raw('SUM(Debt_Amount) * 0.02 AS commission_payments'),
                DB::raw('SUM(Debt_Amount) * 0.005 AS commission_adjustments'),
                DB::raw('0 AS commission_redrafts'),
                DB::raw('SUM(Debt_Amount) AS deals'),
                // Profit/Loss = Revenue (3% of deals) - Total Costs (item + mail + commission costs = 3.5%)
                DB::raw('(SUM(Debt_Amount) * 0.03) - (SUM(Debt_Amount) * 0.035) AS profit_or_loss'),
                // Balance will be calculated as cumulative sum in PHP below
                DB::raw('0 AS balance'),
            ])
            ->whereNotNull('LLG_ID')
            ->whereRaw('YEAR(Submitted_Date) = ?', [$year])
            ->groupBy(DB::raw('MONTH(Submitted_Date)'))
            ->get()
            ->keyBy('month');
        
        // Calculate cumulative balance
        $runningBalance = 0;
        for ($m = 1; $m <= 12; $m++) {
            if ($monthlyData->has($m)) {
                $runningBalance += $monthlyData[$m]->profit_or_loss;
                $monthlyData[$m]->balance = $runningBalance;
            }
        }

        // Build pivoted rows
        $categories = [
            'Amount Dropped' => 'amount_dropped',
            'Item Drop Cost' => 'item_drop_cost',
            'Mail Drop Cost' => 'mail_drop_cost',
            'Commission Payments' => 'commission_payments',
            'Commission Adjustments' => 'commission_adjustments',
            'Commission Redrafts' => 'commission_redrafts',
            'Deals' => 'deals',
            'Profit or Loss' => 'profit_or_loss',
            'Balance' => 'balance',
        ];

        // Determine month range
        $startMonth = $fromMonth ?? 1;
        $endMonth = $toMonth ?? 12;

        $result = [];
        foreach ($categories as $label => $field) {
            $row = ['category' => $label];
            for ($m = $startMonth; $m <= $endMonth; $m++) {
                $monthKey = strtolower(date('M', mktime(0, 0, 0, $m, 1)));
                $row[$monthKey] = $monthlyData->has($m) ? $monthlyData[$m]->{$field} : 0;
            }
            $result[] = (object) $row;
        }

        return collect($result);
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
                'dbo.TblEnrollmentModel', 'TblEnrollmentModel',
                'dbo.EnrollmentModel', 'EnrollmentModel',
                'dbo.TblEnrollmentProjection', 'TblEnrollmentProjection',
                'dbo.EnrollmentProjection', 'EnrollmentProjection',
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
            if (str_contains($l, 'date') || preg_match('/\b(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)\b/i', $label)) {
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
                "COLUMN_NAME LIKE '%ENROLL%'",
                "COLUMN_NAME LIKE '%MODEL%'",
                "COLUMN_NAME LIKE '%REVENUE%'",
                "COLUMN_NAME LIKE '%EXPENSE%'",
                "COLUMN_NAME LIKE '%PROJECTION%'",
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
                if (str_contains($col, 'ENROLL')) $scores[$table] += 6;
                if (str_contains($col, 'MODEL')) $scores[$table] += 5;
                if (str_contains($col, 'REVENUE')) $scores[$table] += 5;
                if (str_contains($col, 'EXPENSE')) $scores[$table] += 5;
                if (str_contains($col, 'PROJECTION')) $scores[$table] += 4;
                if (preg_match('/\b(JAN|FEB|MAR|APR|MAY|JUN|JUL|AUG|SEP|OCT|NOV|DEC)\b/', $col)) $scores[$table] += 3;
                if (str_contains(strtoupper($tableName), 'MODEL')) $scores[$table] += 6;
                if (str_contains(strtoupper($tableName), 'ENROLL')) $scores[$table] += 4;
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
