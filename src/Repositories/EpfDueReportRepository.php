<?php

namespace Cmd\Reports\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EpfDueReportRepository extends SqlSrvRepository
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

        // Default behavior for "Due": if a cleared date column exists, only show un-cleared records
        if ($resolved['cleared_column']) {
            $query->whereRaw($resolved['cleared_column'] . ' IS NULL');
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
     *   cleared_column: ?string,
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

        if ($enrollmentTable && ($col = $find($eCols, ['Tranche']))) {
            $add('Tranche', 'Tranche', 'e.[' . $col . ']', 'e.[' . $col . ']');
        }

        if ($col = $find($epCols, ['LLG_ID', 'LLGID', 'Contact_ID', 'ContactID'])) {
            $add('LLG_ID', 'LLG ID', 'ep.[' . $col . ']', 'ep.[' . $col . ']');
        }

        if ($enrollmentTable && ($col = $find($eCols, ['State']))) {
            $add('State', 'State', 'e.[' . $col . ']', 'e.[' . $col . ']');
        }

        if ($settlementTable && ($col = $find($sCols, ['Settlement_ID', 'SettlementID', 'Settlement_Id']))) {
            $add('Settlement_ID', 'Settlement ID', 's.[' . $col . ']', 's.[' . $col . ']');
        } elseif ($col = $find($epCols, ['Settlement_ID', 'SettlementID', 'Settlement_Id'])) {
            $add('Settlement_ID', 'Settlement ID', 'ep.[' . $col . ']', 'ep.[' . $col . ']');
        }

        if ($settlementTable && ($col = $find($sCols, ['Creditor', 'Creditor_Name', 'CreditorName']))) {
            $add('Creditor', 'Creditor', 's.[' . $col . ']', 's.[' . $col . ']');
        } elseif ($col = $find($epCols, ['Creditor', 'Creditor_Name', 'CreditorName'])) {
            $add('Creditor', 'Creditor', 'ep.[' . $col . ']', 'ep.[' . $col . ']');
        }

        if ($settlementTable && ($col = $find($sCols, ['Debt_Amount', 'Original_Amount', 'OriginalAmount', 'Amount']))) {
            $add('Original_Amount', 'Original Amount', 's.[' . $col . ']', 's.[' . $col . ']');
        }

        if ($settlementTable && ($col = $find($sCols, ['Settlement', 'Settlement_Amount', 'SettlementAmount']))) {
            $add('Settlement_Amount', 'Settlement Amount', 's.[' . $col . ']', 's.[' . $col . ']');
        }

        if ($enrollmentTable && ($col = $find($eCols, ['EPF_Rate', 'Epf_Rate', 'EPFRate']))) {
            $add('EPF_Rate', 'EPF Rate', 'e.[' . $col . ']', 'e.[' . $col . ']');
        }

        $soldDebtCol = $enrollmentTable ? $find($eCols, ['Sold_Debt', 'SoldDebt']) : null;
        $epfRateCol = $enrollmentTable ? $find($eCols, ['EPF_Rate', 'Epf_Rate', 'EPFRate']) : null;
        if ($enrollmentTable && $soldDebtCol && $epfRateCol) {
            $labels['Total_EPF'] = 'Total EPF';
            $selects[] = DB::raw('COALESCE(e.[' . $soldDebtCol . '], 0) * COALESCE(e.[' . $epfRateCol . '], 0) as [Total_EPF]');
        }

        if ($col = $find($epCols, ['Amount', 'Payment', 'EPF_Amount', 'Epf_Amount'])) {
            $add('EPF_Current_Payment', 'EPF Current Payment', 'ep.[' . $col . ']', 'ep.[' . $col . ']');
        }

        $clearedCol = $find($epCols, ['Cleared_Date', 'ClearedDate', 'Clear_Date', 'Cleared']);
        if ($clearedCol) {
            $add('Cleared_Date', 'Cleared Date', 'ep.[' . $clearedCol . ']', 'ep.[' . $clearedCol . ']');
        }

        if ($col = $find($epCols, ['Payment_Number', 'PaymentNumber', 'Payment_No', 'PaymentNo'])) {
            $add('Payment_Number', 'Payment Number', 'ep.[' . $col . ']', 'ep.[' . $col . ']');
        }

        $payDateCol = $find($epCols, ['Payment_Date', 'PaymentDate', 'Date', 'Posted_Date', 'PostedDate']);
        if ($payDateCol) {
            $add('Payment_Date', 'Payment Date', 'ep.[' . $payDateCol . ']', 'ep.[' . $payDateCol . ']');
        }

        if ($col = $find($epCols, ['Confirmation', 'Confirmation_Number', 'ConfirmationNumber', 'Confirm', 'Reference'])) {
            $add('Confirmation', 'Confirmation', 'ep.[' . $col . ']', 'ep.[' . $col . ']');
        }

        // Optional fields (only if they exist in settlement or EPF tables)
        $optional = [
            'EPF_Total_Payments' => ['EPF Total Payments', ['EPF_Total_Payments', 'Total_Payments', 'TotalPaid', 'Total_Paid']],
            'EPF_Balance' => ['EPF Balance', ['EPF_Balance', 'Balance', 'Epf_Balance', 'Remaining_Balance', 'RemainingBalance']],
            'Final_Payment_Date' => ['Final Payment Date', ['Final_Payment_Date', 'FinalPaymentDate', 'Final_Date', 'FinalDate']],
            'LDR_Split' => ['LDR Split', ['LDR_Split', 'Ldr_Split', 'LDRSplit']],
            'NGF_Split' => ['NGF Split', ['NGF_Split', 'Ngf_Split', 'NGFSplit']],
            'LDR_Amount' => ['LDR Amount', ['LDR_Amount', 'Ldr_Amount', 'LDRAmount']],
            'NGF_Amount' => ['NGF Amount', ['NGF_Amount', 'Ngf_Amount', 'NGFAmount']],
            'Paid_To_LDR' => ['Paid To LDR', ['Paid_To_LDR', 'PaidToLDR', 'LDR_Paid']],
            'Paid_To_Legal' => ['Paid To Legal', ['Paid_To_Legal', 'PaidToLegal', 'Paid_To_PLAW', 'PaidToPLAW', 'Paid_To_Law']],
            'Paid_To_NGF' => ['Paid To NGF', ['Paid_To_NGF', 'PaidToNGF', 'NGF_Paid']],
            'Due_To_NGF' => ['Due To NGF', ['Due_To_NGF', 'DueToNGF', 'NGF_Due', 'Due_NGF']],
        ];

        foreach ($optional as $key => [$label, $candidates]) {
            if ($settlementTable && ($col = $find($sCols, $candidates))) {
                $add($key, $label, 's.[' . $col . ']', 's.[' . $col . ']');
                continue;
            }
            if ($col = $find($epCols, $candidates)) {
                $add($key, $label, 'ep.[' . $col . ']', 'ep.[' . $col . ']');
            }
        }

        if (count($selects) === 0) {
            $selects = ['ep.*'];
            foreach ($epCols as $c) {
                $labels[$c] = $c;
                $filterColumns[$c] = 'ep.[' . $c . ']';
            }
        }

        $clearedExpr = $clearedCol ? ('ep.' . $clearedCol) : null;
        $dateColumn = $payDateCol ? ('ep.' . $payDateCol) : ($clearedExpr ?: null);

        $this->resolved = [
            'tables' => [
                'epf' => $epfTable,
                'enrollment' => $enrollmentTable,
                'settlement' => $settlementTable,
            ],
            'selects' => $selects,
            'labels' => $labels,
            'filter_columns' => $filterColumns,
            'cleared_column' => $clearedExpr,
            'date_column' => $dateColumn,
            'order_by' => $dateColumn,
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
