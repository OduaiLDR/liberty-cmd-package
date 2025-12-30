<?php

namespace Cmd\Reports\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class VeritasReportRepository extends SqlSrvRepository
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
            'company' => 'Company',
            'category' => 'Category',
            'payment_date' => 'Payment Date',
            'enrollment_fee' => 'Enrollment Fee',
            'monthly_fee' => 'Monthly Fee',
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
        // Build Veritas report from TblEnrollment data
        $query = $this->table('TblEnrollment')
            ->select([
                DB::raw("CONCAT('LLG-', LLG_ID) AS llg_id"),
                'Client AS client',
                DB::raw("COALESCE(Drop_Name, 'N/A') AS company"),
                DB::raw("COALESCE(Enrollment_Plan, 'Standard') AS category"),
                'First_Payment_Date AS payment_date',
                DB::raw('Debt_Amount * 0.15 AS enrollment_fee'),
                'Program_Payment AS monthly_fee',
            ])
            ->whereNotNull('LLG_ID');

        if ($from) {
            $query->whereDate('First_Payment_Date', '>=', $from);
        }
        if ($to) {
            $query->whereDate('First_Payment_Date', '<=', $to);
        }

        if (!empty($filters['llg_id'])) {
            $query->where(DB::raw("CONCAT('LLG-', LLG_ID)"), 'like', '%' . $filters['llg_id'] . '%');
        }

        if (!empty($filters['client'])) {
            $query->where('Client', 'like', '%' . $filters['client'] . '%');
        }

        if (!empty($filters['company'])) {
            $query->where('Drop_Name', 'like', '%' . $filters['company'] . '%');
        }

        if (!empty($filters['category'])) {
            $query->where('Enrollment_Plan', 'like', '%' . $filters['category'] . '%');
        }

        return $query->orderBy('First_Payment_Date', 'desc');
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
                'dbo.TblVeritas',
                'TblVeritas',
                'dbo.TblVeritasReport',
                'TblVeritasReport',
                'dbo.TblVeritasPayments',
                'TblVeritasPayments',
                'dbo.TblVeritasPayment',
                'TblVeritasPayment',
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
            'llg_id' => ['llgid', 'llg_id', 'llg id', 'llg'],
            'client' => ['client', 'customer', 'borrower', 'name'],
            'company' => ['company', 'firm', 'employer'],
            'category' => ['category', 'type', 'class'],
            'payment_date' => ['paymentdate', 'payment_date', 'payment date', 'paydate', 'pay date', 'paiddate', 'paid date', 'date'],
            'enrollment_fee' => ['enrollmentfee', 'enrollment_fee', 'enrollment fee', 'enrollfee', 'enroll fee', 'setupfee', 'setup fee'],
            'monthly_fee' => ['monthlyfee', 'monthly_fee', 'monthly fee', 'monthfee', 'month fee'],
        ];

        $selects = [];
        $labels = [];
        $filterColumns = [];
        $columnsByKey = [];

        foreach ([
            'llg_id' => 'LLG ID',
            'client' => 'Client',
            'company' => 'Company',
            'category' => 'Category',
            'payment_date' => 'Payment Date',
            'enrollment_fee' => "Enrollment Fee",
            'monthly_fee' => "Monthly Fee",
        ] as $key => $label) {
            $col = $findCol($mapped[$key] ?? []);
            if (!$col) {
                continue;
            }

            $labels[$key] = $label;
            $columnsByKey[$key] = $col;
            $selects[] = DB::raw('x.[' . $col . '] as [' . $key . ']');

            if (in_array($key, ['llg_id', 'client', 'company', 'category'], true)) {
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

        $dateCol = $columnsByKey['payment_date'] ?? $findCol(['paymentdate', 'payment_date', 'payment date', 'date']);
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
                "COLUMN_NAME LIKE '%LLG%'",
                "COLUMN_NAME LIKE '%CLIENT%'",
                "COLUMN_NAME LIKE '%COMPANY%'",
                "COLUMN_NAME LIKE '%CATEGORY%'",
                "COLUMN_NAME LIKE '%PAY%'",
                "COLUMN_NAME LIKE '%PAYMENT%'",
                "COLUMN_NAME LIKE '%ENROLL%'",
                "COLUMN_NAME LIKE '%ENROLLMENT%'",
                "COLUMN_NAME LIKE '%MONTH%'",
                "COLUMN_NAME LIKE '%MONTHLY%'",
                "COLUMN_NAME LIKE '%FEE%'",
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

                if (str_contains($col, 'LLG')) $scores[$table] += 5;
                if (str_contains($col, 'CLIENT')) $scores[$table] += 6;
                if (str_contains($col, 'COMPANY')) $scores[$table] += 5;
                if (str_contains($col, 'CATEGORY')) $scores[$table] += 4;
                if (str_contains($col, 'PAYMENT') || str_contains($col, 'PAY')) $scores[$table] += 7;
                if (str_contains($col, 'ENROLL')) $scores[$table] += 6;
                if (str_contains($col, 'MONTH')) $scores[$table] += 6;
                if (str_contains($col, 'FEE')) $scores[$table] += 6;

                if (str_contains(strtoupper($tableName), 'VERITAS')) $scores[$table] += 12;
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

        foreach (['llg_id', 'client', 'company', 'category'] as $input) {
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
