<?php

namespace Cmd\Reports\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EpfPaidReportRepository extends SqlSrvRepository
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
    protected function baseQuery(?string $from, ?string $to, array $filters): Builder
    {
        $resolved = $this->resolvedSelectMap();

        $epfTable = $resolved['tables']['epf'];
        $enrollmentTable = $resolved['tables']['enrollment'];
        $settlementTable = $resolved['tables']['settlement'];

        $query = $this->table($epfTable, 'ep');

        if ($enrollmentTable) {
            $query->leftJoin($enrollmentTable . ' as e', 'e.LLG_ID', '=', 'ep.LLG_ID');
        }

        if ($settlementTable) {
            $query->leftJoin($settlementTable . ' as s', 's.LLG_ID', '=', 'ep.LLG_ID');
        }

        foreach ($resolved['selects'] as $sel) {
            $query->addSelect($sel);
        }

        $dateColumn = $resolved['date_column'];
        if ($dateColumn) {
            if ($from) {
                $query->whereRaw('CAST(' . $dateColumn . ' AS date) >= ?', [$from]);
            }
            if ($to) {
                $query->whereRaw('CAST(' . $dateColumn . ' AS date) <= ?', [$to]);
            }
        }

        $this->applyFilters($query, $filters, $resolved['filter_columns']);

        if ($resolved['order_by']) {
            $query->orderByRaw($resolved['order_by'] . ' desc');
        }

        return $query;
    }

    /**
     * @return array{
     *   tables: array{epf:string,enrollment:?string,settlement:?string},
     *   selects: array<int, \Illuminate\Database\Query\Expression|string>,
     *   labels: array<string,string>,
     *   filter_columns: array<string,string>,
     *   date_column: ?string,
     *   order_by: ?string
     * }
     */
    protected function resolvedSelectMap(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $epfTable = $this->tableExists('dbo.TblEPFs') ? 'dbo.TblEPFs' : 'TblEPFs';
        $enrollmentTable = $this->tableExists('dbo.TblEnrollment') ? 'dbo.TblEnrollment' : ($this->tableExists('TblEnrollment') ? 'TblEnrollment' : null);
        $settlementTable = $this->tableExists('dbo.TblSettlementDetails') ? 'dbo.TblSettlementDetails' : ($this->tableExists('TblSettlementDetails') ? 'TblSettlementDetails' : null);

        $epCols = $this->listColumns($epfTable);
        $eCols = $enrollmentTable ? $this->listColumns($enrollmentTable) : [];
        $sCols = $settlementTable ? $this->listColumns($settlementTable) : [];

        $labels = [];
        $selects = [];
        $filterColumns = [];

        $add = function (string $key, string $label, string $expr, string $filterExpr) use (&$labels, &$selects, &$filterColumns) {
            $labels[$key] = $label;
            $selects[] = DB::raw($expr . ' as [' . $key . ']');
            $filterColumns[$key] = $filterExpr;
        };

        $find = fn(array $cols, array $candidates) => $this->firstMatchingColumn($cols, $candidates);

        // Tranche
        if ($enrollmentTable && ($col = $find($eCols, ['Tranche']))) {
            $add('Tranche', 'Tranche', 'e.[' . $col . ']', 'e.[' . $col . ']');
        }

        // LLG ID
        if ($col = $find($epCols, ['LLG_ID', 'LLGID', 'Contact_ID', 'ContactID'])) {
            $add('LLG_ID', 'LLG ID', 'ep.[' . $col . ']', 'ep.[' . $col . ']');
        }

        // State
        if ($enrollmentTable && ($col = $find($eCols, ['State']))) {
            $add('State', 'State', 'e.[' . $col . ']', 'e.[' . $col . ']');
        }

        // Settlement ID
        if ($settlementTable && ($col = $find($sCols, ['Settlement_ID', 'SettlementID', 'Settlement_Id']))) {
            $add('Settlement_ID', 'Settlement ID', 's.[' . $col . ']', 's.[' . $col . ']');
        } elseif ($col = $find($epCols, ['Settlement_ID', 'SettlementID', 'Settlement_Id'])) {
            $add('Settlement_ID', 'Settlement ID', 'ep.[' . $col . ']', 'ep.[' . $col . ']');
        }

        // Creditor
        if ($settlementTable && ($col = $find($sCols, ['Creditor', 'Creditor_Name', 'CreditorName']))) {
            $epCol = $find($epCols, ['Creditor', 'Creditor_Name', 'CreditorName']);
            $expr = $epCol ? 'COALESCE(s.[' . $col . '], ep.[' . $epCol . '])' : 's.[' . $col . ']';
            $add('Creditor', 'Creditor', $expr, 's.[' . $col . ']');
        } elseif ($col = $find($epCols, ['Creditor', 'Creditor_Name', 'CreditorName'])) {
            $add('Creditor', 'Creditor', 'ep.[' . $col . ']', 'ep.[' . $col . ']');
        }

        // Original Amount
        if ($settlementTable && ($col = $find($sCols, ['Debt_Amount', 'Original_Amount', 'OriginalAmount', 'Amount']))) {
            $epCol = $find($epCols, ['Debt_Amount', 'Original_Amount', 'OriginalAmount', 'Amount']);
            $expr = $epCol ? 'COALESCE(s.[' . $col . '], ep.[' . $epCol . '])' : 's.[' . $col . ']';
            $add('Original_Amount', 'Original Amount', $expr, 's.[' . $col . ']');
        }

        // Settlement Amount
        if ($settlementTable && ($col = $find($sCols, ['Settlement', 'Settlement_Amount', 'SettlementAmount']))) {
            $epCol = $find($epCols, ['Settlement', 'Settlement_Amount', 'SettlementAmount']);
            $expr = $epCol ? 'COALESCE(s.[' . $col . '], ep.[' . $epCol . '])' : 's.[' . $col . ']';
            $add('Settlement_Amount', 'Settlement Amount', $expr, 's.[' . $col . ']');
        }

        // EPF Rate
        if ($enrollmentTable && ($col = $find($eCols, ['EPF_Rate', 'Epf_Rate', 'EPFRate']))) {
            $add('EPF_Rate', 'EPF Rate', 'e.[' . $col . ']', 'e.[' . $col . ']');
        }

        // Total EPF (computed if possible)
        $soldDebtCol = $enrollmentTable ? $find($eCols, ['Sold_Debt', 'SoldDebt']) : null;
        $epfRateCol = $enrollmentTable ? $find($eCols, ['EPF_Rate', 'Epf_Rate', 'EPFRate']) : null;
        if ($enrollmentTable && $soldDebtCol && $epfRateCol) {
            $labels['Total_EPF'] = 'Total EPF';
            $selects[] = DB::raw('COALESCE(e.[' . $soldDebtCol . '], 0) * COALESCE(e.[' . $epfRateCol . '], 0) as [Total_EPF]');
        }

        // EPF Current Payment
        if ($col = $find($epCols, ['Amount', 'Payment', 'EPF_Amount', 'Epf_Amount'])) {
            $add('EPF_Current_Payment', 'EPF Current Payment', 'ep.[' . $col . ']', 'ep.[' . $col . ']');
        }

        // Cleared Date
        $clearedCol = $find($epCols, ['Cleared_Date', 'ClearedDate', 'Clear_Date', 'Cleared']);
        if ($clearedCol) {
            $add('Cleared_Date', 'Cleared Date', 'ep.[' . $clearedCol . ']', 'ep.[' . $clearedCol . ']');
        }

        // Payment Number
        if ($col = $find($epCols, ['Payment_Number', 'PaymentNumber', 'Payment_No', 'PaymentNo'])) {
            $add('Payment_Number', 'Payment Number', 'ep.[' . $col . ']', 'ep.[' . $col . ']');
        }

        // Payment Date
        $payDateCol = $find($epCols, ['Payment_Date', 'PaymentDate', 'Date', 'Posted_Date', 'PostedDate']);
        if ($payDateCol) {
            $add('Payment_Date', 'Payment Date', 'ep.[' . $payDateCol . ']', 'ep.[' . $payDateCol . ']');
        }

        // Confirmation
        if ($col = $find($epCols, ['Confirmation', 'Confirmation_Number', 'ConfirmationNumber', 'Confirm', 'Reference'])) {
            $add('Confirmation', 'Confirmation', 'ep.[' . $col . ']', 'ep.[' . $col . ']');
        }

        // If nothing resolved (should not happen), just expose EPF table raw columns
        if (count($selects) === 0) {
            $selects = ['ep.*'];
            foreach ($epCols as $c) {
                $labels[$c] = $c;
                $filterColumns[$c] = 'ep.[' . $c . ']';
            }
        }

        $dateColumn = $clearedCol ? ('ep.' . $clearedCol) : ($payDateCol ? ('ep.' . $payDateCol) : null);

        $this->resolved = [
            'tables' => [
                'epf' => $epfTable,
                'enrollment' => $enrollmentTable,
                'settlement' => $settlementTable,
            ],
            'selects' => $selects,
            'labels' => $labels,
            'filter_columns' => $this->buildFilterColumns($filterColumns, $labels),
            'date_column' => $dateColumn,
            'order_by' => $dateColumn,
        ];

        return $this->resolved;
    }

    /**
     * @param  array<string, string>  $filterColumns
     * @param  array<string, string>  $labels
     * @return array<string, string>
     */
    protected function buildFilterColumns(array $filterColumns, array $labels): array
    {
        $cols = $filterColumns;

        // normalize common aliases for filter inputs
        foreach ([
            'llg_id' => 'LLG_ID',
            'state' => 'State',
            'tranche' => 'Tranche',
            'creditor' => 'Creditor',
            'settlement_id' => 'Settlement_ID',
            'payment_number' => 'Payment_Number',
            'confirmation' => 'Confirmation',
        ] as $input => $alias) {
            if (!isset($cols[$alias]) && isset($labels[$alias])) {
                // no-op (alias exists but no filter expr built)
            }
        }

        return $cols;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, string>  $filterColumns
     */
    protected function applyFilters(Builder $query, array $filters, array $filterColumns): void
    {
        $contains = static fn ($value): bool => $value !== null && $value !== '';

        $map = [
            'llg_id' => 'LLG_ID',
            'state' => 'State',
            'tranche' => 'Tranche',
            'creditor' => 'Creditor',
            'settlement_id' => 'Settlement_ID',
            'payment_number' => 'Payment_Number',
            'confirmation' => 'Confirmation',
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
