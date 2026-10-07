<?php

namespace Cmd\Reports\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class MarketingReportRepository extends SqlSrvRepository
{
    /**
     * Retrieve all marketing records for export.
     *
     * @param  array<string, mixed>  $filters
     */
    public function all(?string $from = null, ?string $to = null, array $filters = []): Collection
    {
        return $this->baseQuery($from, $to, $filters)->get();
    }

    /**
     * Retrieve paginated marketing records for the UI.
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginate(?string $from = null, ?string $to = null, int $perPage = 25, array $filters = []): LengthAwarePaginator
    {
        return $this->paginateBuilder(
            $this->baseQuery($from, $to, $filters),
            $perPage
        );
    }

    /**
     * Distinct filter options for dropdowns.
     *
     * @return array<string, Collection<int, string>>
     */
    public function options(): array
    {
        $base = $this->table('TblMarketing');

        $pluckDistinct = fn(string $column, int $limit = 500): Collection => $this->distinctValues($base, $column, $limit);

        return [
            'vendors' => $pluckDistinct('Vendor'),
            'drop_types' => $pluckDistinct('Drop_Type'),
            'data_types' => $pluckDistinct('Data_Type'),
            'mail_styles' => $pluckDistinct('Mail_Style'),
            'debt_tiers' => $pluckDistinct('Debt_Tier'),
            'languages' => $pluckDistinct('Language'),
        ];
    }

    /**
     * Update the mail drop cost for a record.
     */
    public function updateMailDropCost(int $pk, float $cost)
    {
        return $this->updateUninvoicedCost($pk, $cost, 'Mail_Drop_Cost', 'Mail_Invoice_Number');
    }

    /**
     * Update the data drop cost for a record.
     */
    public function updateDataDropCost(int $pk, float $cost)
    {
        return $this->updateUninvoicedCost($pk, $cost, 'Data_Drop_Cost', 'Data_Invoice_Number');
    }

    protected function updateUninvoicedCost(int $pk, float $cost, string $costColumn, string $invoiceColumn)
    {
        return $this->connection()->transaction(function () use ($pk, $cost, $costColumn, $invoiceColumn) {
            $drop = $this->table('TblMarketing')->where('PK', $pk)->first(['Drop_Name']);
            if (! $drop) {
                throw \Illuminate\Validation\ValidationException::withMessages(['pk' => 'This marketing row no longer exists.']);
            }
            $invoiceNumbers = $this->table('TblMarketing')->where('Drop_Name', $drop->Drop_Name)
                ->orderBy('PK')->lockForUpdate()->pluck($invoiceColumn);
            if ($invoiceNumbers->contains(fn ($number): bool => trim((string) ($number ?? '')) !== '')) {
                throw \Illuminate\Validation\ValidationException::withMessages(['pk' => 'This drop already has an invoice for this cost type.']);
            }
            $this->table('TblMarketing')->where('PK', $pk)->update([$costColumn => $cost]);
            return $this->baseQuery()->where('PK', $pk)->first();
        });
    }

    /**
     * Build the base marketing query used by both list and export.
     *
     * @param  array<string, mixed>  $filters
     */
    protected function baseQuery(?string $from = null, ?string $to = null, array $filters = [])
    {
        $query = $this->table('TblMarketing')
            ->select([
                'PK',
                'Drop_Name',
                'Debt_Tier',
                'Drop_Type',
                'Vendor',
                'Data_Type',
                'Mail_Style',
                'Send_Date',
                'Amount_Dropped',
                'Mail_Invoice_Number',
                DB::raw("CASE WHEN EXISTS (SELECT 1 FROM TblMarketing AS invoiced_mail WHERE invoiced_mail.Drop_Name = TblMarketing.Drop_Name AND LTRIM(RTRIM(COALESCE(invoiced_mail.Mail_Invoice_Number, ''))) <> '') THEN 1 ELSE 0 END AS Mail_Invoice_Recorded"),
                DB::raw('COALESCE(Mail_Drop_Cost, 0) as Mail_Drop_Cost'),
                'Data_Invoice_Number',
                DB::raw("CASE WHEN EXISTS (SELECT 1 FROM TblMarketing AS invoiced_data WHERE invoiced_data.Drop_Name = TblMarketing.Drop_Name AND LTRIM(RTRIM(COALESCE(invoiced_data.Data_Invoice_Number, ''))) <> '') THEN 1 ELSE 0 END AS Data_Invoice_Recorded"),
                DB::raw('COALESCE(Data_Drop_Cost, 0) as Data_Drop_Cost'),
                'Calls',
                'Language',
                'Drop_Name_Sequential',
                DB::raw('CASE WHEN Amount_Dropped IS NULL OR Amount_Dropped = 0 THEN 0 ELSE COALESCE(Mail_Drop_Cost, 0) / Amount_Dropped END as Per_Piece_Mail_Cost'),
                DB::raw('CASE WHEN Amount_Dropped IS NULL OR Amount_Dropped = 0 THEN 0 ELSE COALESCE(Data_Drop_Cost, 0) / Amount_Dropped END as Per_Piece_Data_Cost'),
                DB::raw('COALESCE(Mail_Drop_Cost, 0) + COALESCE(Data_Drop_Cost, 0) as Total_Drop_Cost'),
                DB::raw('CASE WHEN Amount_Dropped IS NULL OR Amount_Dropped = 0 THEN 0 ELSE (COALESCE(Mail_Drop_Cost, 0) + COALESCE(Data_Drop_Cost, 0)) / Amount_Dropped END as Per_Piece_Total_Cost'),
            ]);

        if ($from) {
            $query->whereDate('Send_Date', '>=', $from);
        }

        if ($to) {
            $query->whereDate('Send_Date', '<=', $to);
        }

        $this->applyFilters($query, $filters);

        return $query->orderBy('Send_Date', 'asc')->orderBy('Drop_Name', 'asc');
    }

    /**
     * Apply filter definitions to the query.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array<string, mixed>  $filters
     */
    protected function applyFilters($query, array $filters): void
    {
        $contains = static fn($value): bool => $value !== null && $value !== '';

        $map = [
            'drop_name' => 'Drop_Name',
            'debt_tier' => 'Debt_Tier',
            'drop_type' => 'Drop_Type',
            'vendor' => 'Vendor',
            'data_type' => 'Data_Type',
            'mail_style' => 'Mail_Style',
            'language' => 'Language',
        ];

        foreach ($map as $input => $column) {
            $value = $filters[$input] ?? null;
            if ($contains($value)) {
                $query->where($column, 'like', '%' . trim((string) $value) . '%');
            }
        }
    }

    public function smsExports(string $week): Collection
    {
        $start = \Carbon\Carbon::parse($week)->startOfWeek()->toDateString();
        return $this->table('TblSmsExports')->where('Week_Start', $start)->orderBy('PK')->get();
    }

    /** @param array{kind:string,invoice_number:string,cost:string,week?:string,drop_name?:string} $data */
    public function allocateInvoice(array $data): void
    {
        $this->connection()->transaction(function () use ($data): void {
            $sms = $data['kind'] === 'sms';
            if ($sms) {
                $start = \Carbon\Carbon::parse($data['week'])->startOfWeek();
                $query = $this->table('TblSmsExports')->where('Week_Start', $start->toDateString());
                $countColumn = 'SMS_Count';
                $costColumn = 'SMS_Cost';
                $invoiceColumn = 'SMS_Invoice_Number';
                $table = 'TblSmsExports';
            } else {
                $table = 'TblMarketing';
                $query = $this->table($table)
                    ->where('Drop_Name', $data['drop_name']);
                $countColumn = 'Amount_Dropped';
                $costColumn = $data['kind'] === 'mail' ? 'Mail_Drop_Cost' : 'Data_Drop_Cost';
                $invoiceColumn = $data['kind'] === 'mail' ? 'Mail_Invoice_Number' : 'Data_Invoice_Number';
            }
            $rows = $query->orderBy('PK')->lockForUpdate()->get(['PK', $countColumn, $invoiceColumn]);
            if ($rows->isEmpty()) {
                throw \Illuminate\Validation\ValidationException::withMessages([$sms ? 'week' : 'drop_name' => 'No rows match this invoice selection.']);
            }
            if ($rows->contains(fn (object $row): bool => trim((string) ($row->{$invoiceColumn} ?? '')) !== '')) {
                throw \Illuminate\Validation\ValidationException::withMessages([$sms ? 'week' : 'drop_name' => $sms
                    ? 'This SMS export week already has an invoice.'
                    : 'This drop already has an invoice for this cost type.']);
            }
            $counts = $rows->mapWithKeys(fn (object $row): array => [(int) $row->PK => (int) $row->{$countColumn}])->all();
            $costs = (new \Cmd\Reports\Services\SmsDropPlanner)->allocate((string) $data['cost'], $counts);
            foreach ($costs as $pk => $cost) {
                $this->table($table)->where('PK', $pk)->update([
                    $costColumn => $cost, $invoiceColumn => $data['invoice_number'],
                ]);
            }
        });
    }
}
