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
        return $this->smsRowsForWeek($week);
    }

    /** One billing population for history and invoicing; IDs are stable across both tables. */
    protected function smsRowsForWeek(string $week, bool $lock = false): Collection
    {
        $start = \Carbon\Carbon::parse($week, 'America/Los_Angeles')->startOfWeek(\Carbon\Carbon::MONDAY);
        $legacy = $this->table('TblSmsExports')->where('Week_Start', $start->toDateString())->orderBy('PK');
        if ($lock) $legacy->lockForUpdate();
        $rows = $legacy->get(['PK', 'SMS_Drop_Name', 'Request_ID', 'Debt_Tier', 'Week_Start',
            'Exported_At', 'SMS_Count', 'SMS_Invoice_Number', 'SMS_Cost'])->map(function (object $row): object {
            $row->Source_Table = 'TblSmsExports';
            $row->Source_PK = $row->PK;
            $row->PK = 'TblSmsExports:'.$row->PK;
            $row->sources = [];
            return $row;
        });

        // Keep legacy history available on hosts which have not enabled person-level SMS yet.
        $schema = $this->connection()->getSchemaBuilder();
        if (! $schema->hasTable('TblSMSDrops') || ! $schema->hasTable('TblSMSRuns')) return $rows;

        // Sent_At is stored in UTC. Convert both CA midnights separately so DST weeks
        // are correct, and leave the column bare for an indexed range lookup.
        $runs = $this->table('TblSMSRuns')->whereIn('Status', ['sent', 'approved'])
            ->where('Sent_At', '>=', $start->copy()->utc()->toDateTimeString())
            ->where('Sent_At', '<', $start->copy()->addWeek()->utc()->toDateTimeString())
            ->orderBy('Request_ID');
        // Invoice writers lock run state before drops. Send corrections must acquire
        // that same run lock before checking invoices and changing status.
        if ($lock) $runs->lockForUpdate();
        $sentRuns = $runs->get(['Request_ID', 'Sent_At'])->keyBy(fn (object $run): string => strtolower((string) $run->Request_ID));
        if ($sentRuns->isEmpty()) return $rows;

        $columns = ['PK', 'Request_ID', 'Drop_Name', 'Debt_Tier', 'Amount_Dropped', 'Invoice_Number', 'Cost'];
        $drops = collect();
        $historicalIds = collect();
        $hasHistory = $schema->hasTable('TblSMSHistory');
        foreach ($sentRuns->pluck('Request_ID')->chunk(1000) as $ids) {
            $linked = $this->table('TblSMSDrops')->whereIn('Request_ID', $ids->all())->orderBy('PK');
            if ($lock) $linked->lockForUpdate();
            $drops = $drops->concat($linked->get($columns));
            if (! $hasHistory) continue;
            // Start with only these sent requests, using the history's Request_ID index.
            // EXISTS probes drop PKs and never joins recipient rows into the result.
            $historicalIds = $historicalIds->concat($this->table('TblSMSHistory', 'h')
                ->whereIn('h.Request_ID', $ids->all())
                ->whereExists(function ($query): void {
                    $query->selectRaw('1')->from('TblSMSDrops as d')
                        ->whereColumn('d.PK', 'h.SMS_Drop_PK')->whereColumn('d.Drop_Name', 'h.Drop_Name')
                        ->whereNull('d.Request_ID');
                })->distinct()->pluck('h.SMS_Drop_PK'));
        }
        $unlinked = collect();
        foreach ($historicalIds->unique()->values()->chunk(1000) as $ids) {
            $query = $this->table('TblSMSDrops')->whereNull('Request_ID')->whereIn('PK', $ids->all())->orderBy('PK');
            if ($lock) $query->lockForUpdate();
            $unlinked = $unlinked->concat($query->get($columns));
        }
        $drops = $drops->concat($unlinked)->keyBy('PK')->sortBy('PK');

        // Check every request associated with these candidate old drop PKs, including
        // out-of-week/non-sent requests, so an ambiguous link cannot become billable.
        $historyRequests = [];
        foreach ($unlinked->pluck('PK')->chunk(1000) as $ids) {
            $history = $this->table('TblSMSHistory')->whereIn('SMS_Drop_PK', $ids->all())
                ->select(['SMS_Drop_PK', 'Drop_Name', 'Request_ID'])
                ->groupBy('SMS_Drop_PK', 'Drop_Name', 'Request_ID')->get();
            foreach ($history as $entry) {
                if ((string) $entry->Drop_Name !== (string) $drops->get($entry->SMS_Drop_PK)->Drop_Name) continue;
                $historyRequests[$entry->SMS_Drop_PK][strtolower((string) $entry->Request_ID)] = $entry->Request_ID;
            }
        }
        $drops = $drops->filter(function (object $drop) use ($historyRequests): bool {
            if (($drop->Request_ID ?? null) === null) {
                $requests = $historyRequests[$drop->PK] ?? [];
                // An ambiguous or missing historical link cannot establish a confirmed send.
                if (count($requests) !== 1) return false;
                $drop->Request_ID = reset($requests);
            }
            return true;
        });

        foreach ($drops as $drop) {
            $run = $sentRuns->get(strtolower((string) $drop->Request_ID));
            if ($run === null) continue;
            $rows->push((object) [
                'PK' => 'TblSMSDrops:'.$drop->PK, 'Source_Table' => 'TblSMSDrops', 'Source_PK' => $drop->PK,
                'Request_ID' => $run->Request_ID, 'SMS_Drop_Name' => $drop->Drop_Name,
                'Debt_Tier' => $drop->Debt_Tier, 'SMS_Count' => (int) $drop->Amount_Dropped,
                'Week_Start' => $start->toDateString(), 'Exported_At' => $run->Sent_At,
                'SMS_Invoice_Number' => $drop->Invoice_Number, 'SMS_Cost' => $drop->Cost ?? 0,
                // Person-level sends have no legacy TblMarketing source rows.
                'sources' => [],
            ]);
        }

        return $rows->values();
    }

    /** @param array{kind:string,invoice_number:string,cost:string,week?:string,drop_name?:string} $data */
    public function allocateInvoice(array $data): void
    {
        $this->connection()->transaction(function () use ($data): void {
            $sms = $data['kind'] === 'sms';
            if ($sms) {
                $rows = $this->smsRowsForWeek($data['week'], true);
                $countColumn = 'SMS_Count';
                $costColumn = 'SMS_Cost';
                $invoiceColumn = 'SMS_Invoice_Number';
            } else {
                $table = 'TblMarketing';
                $query = $this->table($table)
                    ->where('Drop_Name', $data['drop_name']);
                $countColumn = 'Amount_Dropped';
                $costColumn = $data['kind'] === 'mail' ? 'Mail_Drop_Cost' : 'Data_Drop_Cost';
                $invoiceColumn = $data['kind'] === 'mail' ? 'Mail_Invoice_Number' : 'Data_Invoice_Number';
                $rows = $query->orderBy('PK')->lockForUpdate()->get(['PK', $countColumn, $invoiceColumn]);
            }
            if ($rows->isEmpty()) {
                throw \Illuminate\Validation\ValidationException::withMessages([$sms ? 'week' : 'drop_name' => 'No rows match this invoice selection.']);
            }
            if ($rows->contains(fn (object $row): bool => trim((string) ($row->{$invoiceColumn} ?? '')) !== '')) {
                throw \Illuminate\Validation\ValidationException::withMessages([$sms ? 'week' : 'drop_name' => $sms
                    ? 'This SMS export week already has an invoice.'
                    : 'This drop already has an invoice for this cost type.']);
            }
            $counts = $rows->mapWithKeys(fn (object $row): array => [$row->PK => (int) $row->{$countColumn}])->all();
            $costs = (new \Cmd\Reports\Services\SmsDropPlanner)->allocate((string) $data['cost'], $counts);
            foreach ($rows as $row) {
                $newSms = $sms && $row->Source_Table === 'TblSMSDrops';
                $this->table($sms ? $row->Source_Table : $table)->where('PK', $sms ? $row->Source_PK : $row->PK)->update([
                    $newSms ? 'Cost' : $costColumn => $costs[$row->PK],
                    $newSms ? 'Invoice_Number' : $invoiceColumn => $data['invoice_number'],
                ]);
            }
        });
    }
}
