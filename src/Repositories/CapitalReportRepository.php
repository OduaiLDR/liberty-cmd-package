<?php

namespace Cmd\Reports\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CapitalReportRepository extends SqlSrvRepository
{
    protected ?array $resolved = null;

    /**
     * @return array<string, string>
     */
    public function columns(): array
    {
        return $this->resolvedDefinition()['labels'];
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
        $def = $this->resolvedDefinition();

        $salesTable = $def['tables']['sales'];
        $query = $this->table($salesTable, 'ts');

        foreach ($def['selects'] as $sel) {
            $query->addSelect($sel);
        }

        foreach ($def['joins'] as $join) {
            $query->leftJoinSub($join['sub'], $join['as'], $join['left'], '=', $join['right']);
        }

        $soldDateColumn = $def['sold_date_column'];
        if ($soldDateColumn) {
            if ($from) {
                $query->whereRaw('CAST(' . $soldDateColumn . ' AS date) >= ?', [$from]);
            }
            if ($to) {
                $query->whereRaw('CAST(' . $soldDateColumn . ' AS date) <= ?', [$to]);
            }
        }

        $tranche = $filters['tranche'] ?? null;
        if ($tranche !== null && $tranche !== '') {
            $query->where('ts.Tranche', 'like', '%' . trim((string) $tranche) . '%');
        }

        return $query->orderBy('ts.Tranche', 'asc');
    }

    /**
     * @return array{
     *   tables: array{sales:string},
     *   selects: array<int, \Illuminate\Database\Query\Expression|string>,
     *   labels: array<string,string>,
     *   joins: array<int, array{sub:\Illuminate\Database\Query\Builder,as:string,left:string,right:string}>,
     *   sold_date_column: ?string
     * }
     */
    protected function resolvedDefinition(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $conn = $this->connection();

        $salesTable = $this->tableExists('dbo.TblDebtTrancheSales') ? 'dbo.TblDebtTrancheSales' : 'TblDebtTrancheSales';
        $enrollmentTable = $this->tableExists('dbo.TblEnrollment') ? 'dbo.TblEnrollment' : ($this->tableExists('TblEnrollment') ? 'TblEnrollment' : null);
        $epfTable = $this->tableExists('dbo.TblEPFs') ? 'dbo.TblEPFs' : ($this->tableExists('TblEPFs') ? 'TblEPFs' : null);

        $salesCols = $this->listColumns($salesTable);
        $enrollmentCols = $enrollmentTable ? $this->listColumns($enrollmentTable) : [];
        $epfCols = $epfTable ? $this->listColumns($epfTable) : [];

        $labels = [];
        $selects = [];
        $joins = [];

        $add = function (string $key, string $label, string $expr) use (&$labels, &$selects) {
            $labels[$key] = $label;
            $selects[] = DB::raw($expr . ' as [' . $key . ']');
        };

        $find = fn(array $cols, array $candidates) => $this->firstMatchingColumn($cols, $candidates);

        // Tranche
        if ($find($salesCols, ['Tranche'])) {
            $add('Tranche', 'Tranche', 'ts.Tranche');
        }

        // Sold Date and Tranche Date (best-effort mapping)
        $soldDate = $find($salesCols, ['Sold_Date', 'Payment_Date', 'SoldDate', 'PaymentDate']);
        if ($soldDate) {
            $add('Sold_Date', 'Sold Date', 'ts.[' . $soldDate . ']');
        }

        $trancheDate = $find($salesCols, ['Tranche_Date', 'Report_Date', 'TrancheDate', 'ReportDate']);
        if ($trancheDate) {
            $add('Tranche_Date', 'Tranche Date', 'ts.[' . $trancheDate . ']');
        }

        // Sold Debt Amount (try a few fields)
        $soldDebtAmount = $find($salesCols, ['Sold_Debt_Amount', 'SoldDebtAmount', 'Total_Debt', 'TotalDebt', 'Sold_Debt']);
        if ($soldDebtAmount) {
            $add('Sold_Debt_Amount', 'Sold Debt Amount', 'ts.[' . $soldDebtAmount . ']');
        }

        // Additional tranche sales numeric fields (only if they exist; controller will hide if empty)
        if ($col = $find($salesCols, ['Payment'])) {
            $add('Payment', 'Payment', 'ts.[' . $col . ']');
        }
        if ($col = $find($salesCols, ['Flip_Date', 'FlipDate'])) {
            $add('Flip_Date', 'Flip Date', 'ts.[' . $col . ']');
        }

        // Enrollment tranche aggregates
        if ($enrollmentTable) {
            $planCol = $find($enrollmentCols, ['Enrollment_Plan', 'Plan']);
            $soldDebtCol = $find($enrollmentCols, ['Sold_Debt', 'SoldDebt']);
            $epfRateCol = $find($enrollmentCols, ['EPF_Rate', 'Epf_Rate', 'EPFRate']);
            $lookbackCol = $find($enrollmentCols, ['Lookback_Date', 'LookbackDate']);

            $aggParts = [
                'Tranche',
            ];

            if ($soldDebtCol) {
                $aggParts[] = 'SUM(COALESCE([' . $soldDebtCol . '], 0)) as Sold_Debt_Total';
            }

            if ($soldDebtCol && $epfRateCol) {
                $aggParts[] = 'SUM(COALESCE([' . $soldDebtCol . '], 0) * COALESCE([' . $epfRateCol . '], 0)) as EPF_Original_Total';
            }

            if ($planCol && $soldDebtCol && $epfRateCol) {
                $aggParts[] = "SUM(CASE WHEN [{$planCol}] NOT LIKE 'PLAW%' AND UPPER([{$planCol}]) NOT LIKE '%PROGRESS%' THEN COALESCE([{$soldDebtCol}], 0) * COALESCE([{$epfRateCol}], 0) ELSE 0 END) as LDR_EPF_Original";
                $aggParts[] = "SUM(CASE WHEN [{$planCol}] LIKE 'PLAW%' THEN COALESCE([{$soldDebtCol}], 0) * COALESCE([{$epfRateCol}], 0) ELSE 0 END) as PLAW_EPF_Original";
            }

            if ($soldDebtCol && $lookbackCol) {
                $aggParts[] = "SUM(CASE WHEN [{$lookbackCol}] IS NOT NULL THEN COALESCE([{$soldDebtCol}], 0) ELSE 0 END) as Sold_Debt_Lookback";
            }

            $enrollmentAgg = $conn->table($enrollmentTable)
                ->selectRaw(implode(",\n                ", $aggParts))
                ->groupBy('Tranche');

            $joins[] = [
                'sub' => $enrollmentAgg,
                'as' => 'e',
                'left' => 'e.Tranche',
                'right' => 'ts.Tranche',
            ];

            if (str_contains(implode('|', $aggParts), 'LDR_EPF_Original')) {
                $add('LDR_EPF_Original', 'LDR EPF Original', 'COALESCE(e.LDR_EPF_Original, 0)');
            }
            if (str_contains(implode('|', $aggParts), 'PLAW_EPF_Original')) {
                $add('PLAW_EPF_Original', 'PLAW EPF Original', 'COALESCE(e.PLAW_EPF_Original, 0)');
            }
            if (str_contains(implode('|', $aggParts), 'EPF_Original_Total')) {
                $add('EPF_Original_Total', 'EPF Original', 'COALESCE(e.EPF_Original_Total, 0)');
            }
            if (str_contains(implode('|', $aggParts), 'Sold_Debt_Total')) {
                $add('Sold_Debt_Total', 'Sold Debt Total', 'COALESCE(e.Sold_Debt_Total, 0)');
            }
            if (str_contains(implode('|', $aggParts), 'Sold_Debt_Lookback')) {
                $add('Sold_Debt_Lookback', 'Sold Debt Lookback', 'COALESCE(e.Sold_Debt_Lookback, 0)');
            }
        }

        // EPF payments by tranche (best-effort: Paid_To categories)
        if ($epfTable && $enrollmentTable) {
            $llgCol = $find($epfCols, ['LLG_ID', 'LLGID']);
            $enrollmentLlgCol = $find($enrollmentCols, ['LLG_ID', 'LLGID']);
            $paidToCol = $find($epfCols, ['Paid_To', 'PaidTo']);
            $amountCol = $find($epfCols, ['Amount', 'Payment', 'EPF_Amount', 'Epf_Amount']);
            $clearedCol = $find($epfCols, ['Cleared_Date', 'ClearedDate', 'Clear_Date', 'Cleared']);

            if ($llgCol && $enrollmentLlgCol && $amountCol) {
                $epfAgg = $conn->table($epfTable . ' as ep')
                    ->join($enrollmentTable . ' as en', 'ep.' . $llgCol, '=', 'en.' . $enrollmentLlgCol)
                    ->selectRaw('en.Tranche as Tranche')
                    ->when($clearedCol, fn($q) => $q->whereNotNull('ep.' . $clearedCol))
                    ->selectRaw('SUM(COALESCE(ep.[' . $amountCol . '], 0)) as EPF_Payments_Total');

                if ($paidToCol) {
                    $epfAgg
                        ->selectRaw("SUM(CASE WHEN ep.[{$paidToCol}] = 31213 THEN COALESCE(ep.[{$amountCol}], 0) ELSE 0 END) as LDR_Payments")
                        ->selectRaw("SUM(CASE WHEN ep.[{$paidToCol}] = 35285 THEN COALESCE(ep.[{$amountCol}], 0) ELSE 0 END) as PLAW_Payments")
                        ->selectRaw("SUM(CASE WHEN ep.[{$paidToCol}] NOT IN (31213,35285) THEN COALESCE(ep.[{$amountCol}], 0) ELSE 0 END) as NGF_Payments");
                }

                $epfAgg->groupBy('en.Tranche');

                $joins[] = [
                    'sub' => $epfAgg,
                    'as' => 'p',
                    'left' => 'p.Tranche',
                    'right' => 'ts.Tranche',
                ];

                $add('EPF_Payments_Total', 'EPF Payments', 'COALESCE(p.EPF_Payments_Total, 0)');
                if ($paidToCol) {
                    $add('LDR_Payments', 'LDR Payments', 'COALESCE(p.LDR_Payments, 0)');
                    $add('PLAW_Payments', 'PLAW Payments', 'COALESCE(p.PLAW_Payments, 0)');
                    $add('NGF_Payments', 'NGF Payments', 'COALESCE(p.NGF_Payments, 0)');
                }
            }
        }

        $soldDateQualified = $soldDate ? ('ts.[' . $soldDate . ']') : null;

        $this->resolved = [
            'tables' => ['sales' => $salesTable],
            'selects' => $selects,
            'labels' => $labels,
            'joins' => $joins,
            'sold_date_column' => $soldDateQualified,
        ];

        return $this->resolved;
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
}
