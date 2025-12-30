<?php

namespace Cmd\Reports\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AgentRoiReportRepository extends SqlSrvRepository
{
    protected ?array $resolved = null;

    /**
     * @return array<string, string>
     */
    public function columns(): array
    {
        return [
            'agent' => 'Agent',
            'tier' => 'Tier',
            'calls' => 'Calls',
            'cost_per_call' => 'Cost Per Call',
            'drop_cost' => 'Drop Cost',
            'deals' => 'Deals',
            'revenue_deals' => 'Revenue Deals',
            'debt_amount' => 'Debt Amount',
            'avg_debt' => 'Avg Debt',
            'revenue' => 'Revenue',
            'commission' => 'Commission',
            'cpa' => 'CPA',
            'roi' => 'ROI',
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
    public function sample(?string $from = null, ?string $to = null, int $limit = 500, array $filters = []): Collection
    {
        return $this->baseQuery($from, $to, $filters)->limit($limit)->get();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function baseQuery(?string $from, ?string $to, array $filters): Builder
    {
        // Build Agent ROI metrics from TblEnrollment data
        // Tier is based on avg debt: T1 < 15k, T2 15-25k, T3 25-35k, T4 35-50k, T5 50k+
        $query = $this->table('TblEnrollment')
            ->select([
                DB::raw("COALESCE(NULLIF(Agent,''), 'N/A') AS agent"),
                DB::raw("CASE 
                    WHEN AVG(Debt_Amount) < 15000 THEN 'T1'
                    WHEN AVG(Debt_Amount) < 25000 THEN 'T2'
                    WHEN AVG(Debt_Amount) < 35000 THEN 'T3'
                    WHEN AVG(Debt_Amount) < 50000 THEN 'T4'
                    ELSE 'T5'
                END AS tier"),
                DB::raw('COUNT(*) AS calls'),
                DB::raw('CASE WHEN COUNT(*) > 0 THEN ROUND(SUM(Debt_Amount) * 0.01 / COUNT(*), 2) ELSE 0 END AS cost_per_call'),
                DB::raw('SUM(Debt_Amount) * 0.005 AS drop_cost'),
                DB::raw('COUNT(*) AS deals'),
                DB::raw("COUNT(CASE WHEN First_Payment_Status = 'Cleared' THEN 1 END) AS revenue_deals"),
                DB::raw('SUM(Debt_Amount) AS debt_amount'),
                DB::raw('AVG(Debt_Amount) AS avg_debt'),
                DB::raw('SUM(Debt_Amount) * 0.25 AS revenue'),
                DB::raw('SUM(Debt_Amount) * 0.02 AS commission'),
                DB::raw('CASE WHEN COUNT(*) > 0 THEN ROUND(SUM(Debt_Amount) * 0.25 / COUNT(*), 2) ELSE 0 END AS cpa'),
                DB::raw("CASE WHEN SUM(Debt_Amount) > 0 THEN ROUND(CAST(COUNT(CASE WHEN First_Payment_Status = 'Cleared' THEN 1 END) AS FLOAT) / COUNT(*) * 100, 2) ELSE 0 END AS roi"),
            ])
            ->whereNotNull('LLG_ID')
            ->groupBy(DB::raw("COALESCE(NULLIF(Agent,''), 'N/A')"));

        if ($from) {
            $query->whereDate('Submitted_Date', '>=', $from);
        }
        if ($to) {
            $query->whereDate('Submitted_Date', '<=', $to);
        }

        // Fix: Use WHERE clause with Agent column directly instead of HAVING on alias
        if (!empty($filters['agent'])) {
            $query->whereRaw("COALESCE(NULLIF(Agent,''), 'N/A') LIKE ?", ['%' . $filters['agent'] . '%']);
        }

        if (!empty($filters['tier'])) {
            // Tier filter applied in having clause since it's computed
            $query->havingRaw("CASE 
                WHEN AVG(Debt_Amount) < 15000 THEN 'T1'
                WHEN AVG(Debt_Amount) < 25000 THEN 'T2'
                WHEN AVG(Debt_Amount) < 35000 THEN 'T3'
                WHEN AVG(Debt_Amount) < 50000 THEN 'T4'
                ELSE 'T5'
            END LIKE ?", ['%' . $filters['tier'] . '%']);
        }

        return $query->orderByRaw('SUM(Debt_Amount) DESC');
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
            foreach ([
                'dbo.TblAgentROI',
                'TblAgentROI',
                'dbo.AgentROI',
                'AgentROI',
                'dbo.TblAgentPerformance',
                'TblAgentPerformance',
                'dbo.AgentPerformance',
                'AgentPerformance',
            ] as $candidate) {
                if ($this->tableExists($candidate)) {
                    $table = $candidate;
                    break;
                }
            }
        }

        if (!$table) {
            $table = $this->tableExists('dbo.TblMarketing') ? 'dbo.TblMarketing' : 'TblMarketing';
            $forceEmpty = true; // better to return empty than wrong table
        }

        $cols = $this->listColumns($table);

        $findCol = function (array $candidates) use ($cols): ?string {
            foreach ($cols as $col) {
                $norm = strtolower(preg_replace('/[^a-z0-9]+/', '', $col) ?? $col);
                foreach ($candidates as $cand) {
                    $c = strtolower(preg_replace('/[^a-z0-9]+/', '', $cand) ?? $cand);
                    if ($c !== '' && str_contains($norm, $c)) {
                        return $col;
                    }
                }
            }
            return null;
        };

        $mapped = [
            'agent' => ['agent', 'rep', 'salesrep', 'consultant'],
            'tier' => ['tier', 'level'],
            'cost_per_call' => ['costpercall', 'callcost', 'cost_call'],
            'calls' => ['calls', 'call_count', 'totalcalls'],
            'drop_cost' => ['dropcost', 'drop_cost', 'cost_drop', 'marketingcost'],
            'revenue_deals' => ['revenue_deals', 'revenue deals', 'deals', 'sales', 'closeddeals'],
            'cpa' => ['cpa', 'costperacquisition', 'cost_per_acquisition'],
            'revenue' => ['revenue', 'rev'],
            'commission' => ['commission', 'comm', 'payout'],
            'roi' => ['roi', 'return'],
            'date' => ['date', 'created', 'send', 'call_date', 'logdate'],
        ];

        $selects = [];
        $labels = [];
        $filterColumns = [];
        $columnsByKey = [];

        foreach ([
            'agent' => 'Agent',
            'tier' => 'Tier',
            'cost_per_call' => 'Cost Per Call',
            'calls' => 'Calls',
            'drop_cost' => 'Drop Cost',
            'revenue_deals' => 'Revenue Deals',
            'cpa' => 'CPA',
            'revenue' => 'Revenue',
            'commission' => 'Commission',
            'roi' => 'ROI',
            'date' => 'Date',
        ] as $key => $label) {
            $col = $findCol($mapped[$key] ?? []);
            if (!$col) {
                continue;
            }

            $labels[$key] = $label;
            $columnsByKey[$key] = $col;
            $selects[] = DB::raw('x.[' . $col . '] as [' . $key . ']');

            if (in_array($key, ['agent', 'tier'], true)) {
                $filterColumns[$key] = 'x.[' . $col . ']';
            }
        }

        if (count($selects) === 0) {
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
        }

        $dateCol = $columnsByKey['date'] ?? $findCol(['date', 'created', 'send', 'call_date', 'logdate']);
        $dateColumn = $dateCol ? ('x.[' . $dateCol . ']') : null;

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
        if ($key === '') {
            $key = 'col';
        }

        return $key;
    }

    protected function detectBestTable(): ?string
    {
        try {
            $likeParts = [
                "COLUMN_NAME LIKE '%AGENT%'",
                "COLUMN_NAME LIKE '%TIER%'",
                "COLUMN_NAME LIKE '%CALL%'",
                "COLUMN_NAME LIKE '%COST%'",
                "COLUMN_NAME LIKE '%CPA%'",
                "COLUMN_NAME LIKE '%REV%'",
                "COLUMN_NAME LIKE '%COMMIS%'",
                "COLUMN_NAME LIKE '%ROI%'",
                "COLUMN_NAME LIKE '%DROP%'",
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

                if (str_contains($col, 'AGENT')) $scores[$table] += 6;
                if (str_contains($col, 'TIER')) $scores[$table] += 4;
                if (str_contains($col, 'CALL')) $scores[$table] += 4;
                if (str_contains($col, 'COST')) $scores[$table] += 4;
                if (str_contains($col, 'CPA')) $scores[$table] += 5;
                if (str_contains($col, 'REV')) $scores[$table] += 5;
                if (str_contains($col, 'COMMIS')) $scores[$table] += 5;
                if (str_contains($col, 'ROI')) $scores[$table] += 6;
                if (str_contains($col, 'DROP')) $scores[$table] += 3;

                if (str_contains(strtoupper($tableName), 'ROI')) $scores[$table] += 8;
                if (str_contains(strtoupper($tableName), 'AGENT')) $scores[$table] += 4;
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

        foreach (['agent', 'tier'] as $input) {
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
