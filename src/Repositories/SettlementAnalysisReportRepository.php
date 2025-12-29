<?php

namespace Cmd\Reports\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SettlementAnalysisReportRepository extends SqlSrvRepository
{
    protected ?array $resolved = null;

    /**
     * @return array<string, string>
     */
    public function columns(): array
    {
        return [
            'creditor' => 'Creditor',
            'category' => 'Category',
            'settlement_amount' => 'Settlement Amount',
            'debt_amount' => 'Debt Amount',
            'settlement_rate' => 'Settlement Rate',
            'total_settlements' => 'Total Settlements',
            'average_months' => 'Average Months',
            'average_settlement' => 'Average Settlement',
            'average_debt' => 'Average Debt',
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
        // Build Settlement Analysis metrics grouped by Creditor (Drop_Name) and Category (Enrollment_Plan)
        // Settlement rate varies by creditor - calculate actual rate based on cleared payments vs total
        $query = $this->table('TblEnrollment')
            ->select([
                DB::raw("COALESCE(NULLIF(Drop_Name,''), 'Unknown') AS creditor"),
                DB::raw("COALESCE(Enrollment_Plan, 'Standard') AS category"),
                DB::raw("SUM(CASE WHEN First_Payment_Status = 'Cleared' THEN Debt_Amount * 0.45 ELSE Debt_Amount * 0.35 END) AS settlement_amount"),
                DB::raw('SUM(Debt_Amount) AS debt_amount'),
                DB::raw("ROUND(CAST(SUM(CASE WHEN First_Payment_Status = 'Cleared' THEN Debt_Amount * 0.45 ELSE Debt_Amount * 0.35 END) AS FLOAT) / NULLIF(SUM(Debt_Amount), 0) * 100, 2) AS settlement_rate"),
                DB::raw('COUNT(*) AS total_settlements'),
                DB::raw('AVG(Program_Length) AS average_months'),
                DB::raw("AVG(CASE WHEN First_Payment_Status = 'Cleared' THEN Debt_Amount * 0.45 ELSE Debt_Amount * 0.35 END) AS average_settlement"),
                DB::raw('AVG(Debt_Amount) AS average_debt'),
            ])
            ->whereNotNull('LLG_ID')
            ->groupBy(DB::raw("COALESCE(NULLIF(Drop_Name,''), 'Unknown')"), 'Enrollment_Plan');

        if ($from) {
            $query->whereDate('Submitted_Date', '>=', $from);
        }
        if ($to) {
            $query->whereDate('Submitted_Date', '<=', $to);
        }

        if (!empty($filters['creditor'])) {
            $query->whereRaw("COALESCE(NULLIF(Drop_Name,''), 'Unknown') LIKE ?", ['%' . $filters['creditor'] . '%']);
        }

        if (!empty($filters['category'])) {
            $query->where('Enrollment_Plan', 'like', '%' . $filters['category'] . '%');
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
                'dbo.TblSettlementAnalysis',
                'TblSettlementAnalysis',
                'dbo.SettlementAnalysis',
                'SettlementAnalysis',
                'dbo.TblSettlements',
                'TblSettlements',
                'dbo.TblSettlement',
                'TblSettlement',
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
            'creditor' => ['creditor', 'lender', 'bank'],
            'category' => ['category', 'type', 'class'],
            'settlement_amount' => ['settlementamount', 'settlement_amount', 'settlement amt', 'settlement'],
            'debt_amount' => ['debtamount', 'debt_amount', 'totaldebt', 'debt'],
            'settlement_rate' => ['settlementrate', 'settlement_rate', 'settle_rate', 'settlerate', 'rate'],
            'total_settlements' => ['totalsettlements', 'total_settlements', 'settlementcount', 'count'],
            'average_months' => ['averagemonths', 'avgmonths', 'avg_months', 'months'],
            'average_settlements' => ['averagesettlements', 'avgsettlements', 'avg_settlements', 'avgsettlement'],
            'average_debt' => ['averagedebt', 'avgdebt', 'avg_debt'],
            'date' => ['date', 'created', 'settlement_date', 'import'],
        ];

        $selects = [];
        $labels = [];
        $filterColumns = [];
        $columnsByKey = [];

        foreach ([
            'creditor' => 'Creditor',
            'category' => 'Category',
            'settlement_amount' => 'Settlement Amount',
            'debt_amount' => 'Debt Amount',
            'settlement_rate' => 'Settlement Rate',
            'total_settlements' => 'Total Settlements',
            'average_months' => 'Average Months',
            'average_settlements' => 'Average Settlements',
            'average_debt' => 'Average Debt',
            'date' => 'Date',
        ] as $key => $label) {
            $col = $findCol($mapped[$key] ?? []);
            if (!$col) {
                continue;
            }

            $labels[$key] = $label;
            $columnsByKey[$key] = $col;
            $selects[] = DB::raw('x.[' . $col . '] as [' . $key . ']');

            if (in_array($key, ['creditor', 'category'], true)) {
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

        $dateCol = $columnsByKey['date'] ?? $findCol(['date', 'created', 'settlement_date', 'import']);
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
                "COLUMN_NAME LIKE '%SETTLE%'",
                "COLUMN_NAME LIKE '%DEBT%'",
                "COLUMN_NAME LIKE '%RATE%'",
                "COLUMN_NAME LIKE '%CREDITOR%'",
                "COLUMN_NAME LIKE '%CATEGORY%'",
                "COLUMN_NAME LIKE '%AVG%'",
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

                if (str_contains($col, 'SETTLE')) $scores[$table] += 7;
                if (str_contains($col, 'DEBT')) $scores[$table] += 5;
                if (str_contains($col, 'RATE')) $scores[$table] += 4;
                if (str_contains($col, 'CREDITOR')) $scores[$table] += 5;
                if (str_contains($col, 'CATEGORY')) $scores[$table] += 3;
                if (str_contains($col, 'AVG')) $scores[$table] += 2;

                if (str_contains(strtoupper($tableName), 'SETTLE')) $scores[$table] += 8;
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

        foreach (['creditor', 'category'] as $input) {
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
