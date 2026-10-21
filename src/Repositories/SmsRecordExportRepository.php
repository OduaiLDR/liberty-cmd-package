<?php

declare(strict_types=1);

namespace Cmd\Reports\Repositories;

use Carbon\Carbon;
use Cmd\Reports\Services\SmsAvailabilityRefresh;
use Cmd\Reports\Services\SmsProspectValues;
use Cmd\Reports\Services\SmsRecordPolicy;
use Illuminate\Database\Query\Builder;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** PRD record membership. No phone deduplication, household expansion or inferred send date. */
class SmsRecordExportRepository extends SqlSrvRepository
{
    private function eligible(): Builder
    {
        $sqlServer = $this->connection()->getDriverName() === 'sqlsrv';
        $contactPhone = $sqlServer
            ? "CASE WHEN LEN(c.Phone) = 11 AND LEFT(c.Phone, 1) = '1' THEN SUBSTRING(c.Phone, 2, 10) ELSE c.Phone END"
            : "CASE WHEN length(c.Phone) = 11 AND substr(c.Phone, 1, 1) = '1' THEN substr(c.Phone, 2, 10) ELSE c.Phone END";
        $query = $this->table('TblProspects')->where('Contacted', 0)->where('Opt_Out', 0)->where('Invalid_Phone', 0)
            ->whereNotNull('Phone')
            // Legacy loaders may leave prospect flags clear despite existing phone exclusions.
            ->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from('TblSMSSuppressions as s')
                ->whereColumn('s.Phone', 'TblProspects.Phone')
                ->where(fn (Builder $flags) => $flags->where('s.Opt_Out', 1)->orWhere('s.Invalid_Phone', 1)))
            ->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from('TblPhoneNumbers as c')
                ->whereNotNull('c.Phone')->whereRaw("{$contactPhone} = TblProspects.Phone"));
        if ($sqlServer) {
            return $query->whereRaw("DATALENGTH(Phone)=10 AND Phone NOT LIKE '%[^0-9]%'");
        }
        return $query->whereRaw("length(Phone)=10 AND Phone NOT GLOB '*[^0-9]*'");
    }

    public function availability(): array
    {
        SmsRecordPolicy::assertReady();
        $row = $this->eligible()->selectRaw('COUNT(*) AS Eligible, COALESCE(SUM(CASE WHEN Last_SMS_Date IS NULL THEN 1 ELSE 0 END),0) AS Never_Texted')->first();
        $all = (int) $row->Eligible;
        $never = (int) $row->Never_Texted;
        return ['eligible' => $all, 'never_texted' => $never, 'texted_before' => $all - $never];
    }

    public function select(string $requestId, int $target): array
    {
        SmsRecordPolicy::assertReady();
        if ($target < 1 || $target > 10000000) throw new HttpException(422, 'Enter a positive whole-number quantity up to 10,000,000.');
        return $this->withQueryTimeout(SmsProspectExportRepository::STEP_QUERY_TIMEOUT, fn () => $this->connection()->transaction(function () use ($requestId, $target): array {
            $run = $this->table('TblSMSRuns')->where('Request_ID', $requestId)->lockForUpdate()->first();
            if (! $run || ! SmsRecordPolicy::isRecord($run) || $run->Status !== 'selecting') throw new HttpException(409, 'This record batch is not selecting.');
            if ($this->table('TblProspectsExport')->where('Request_ID', $requestId)->exists()
                || $this->table('TblSMSHistory')->where('Request_ID', $requestId)->exists()) throw new HttpException(409, 'This batch already has frozen membership.');
            $clean = "UPPER(REPLACE(TRIM(Tier),' ',''))";
            $tier = $this->connection()->getDriverName() === 'sqlsrv' ? SmsProspectValues::tierSql('Tier')
                : "CASE WHEN {$clean} GLOB 'T[1-9]' THEN {$clean} WHEN {$clean} GLOB 'TIER[1-9]' THEN 'T'||substr({$clean},-1) WHEN {$clean} GLOB '[1-9]' THEN 'T'||{$clean} END";
            $rows = $this->eligible()->selectRaw("? AS Request_ID, PK AS Prospect_PK, Phone, {$tier} AS Debt_Tier,
                Client,Address,City,State,Zip,Email,
                CASE WHEN Last_SMS_Date IS NULL THEN 0 ELSE 1 END AS Was_Previously_Texted,
                Last_SMS_Date AS Last_SMS_Date_At_Selection", [$requestId])
                ->orderByRaw('CASE WHEN Last_SMS_Date IS NULL THEN 0 ELSE 1 END ASC')
                ->orderByRaw('CASE WHEN Last_SMS_Date IS NULL THEN Creation_Date END DESC')
                ->orderBy('Last_SMS_Date')->orderBy('PK')->limit($target);
            $this->table('TblProspectsExport')->insertUsing(['Request_ID','Prospect_PK','Phone','Debt_Tier',
                'Client','Address','City','State','Zip','Email','Was_Previously_Texted','Last_SMS_Date_At_Selection'], $rows);
            $counts = $this->composition($requestId);
            $this->table('TblSMSRuns')->where('Request_ID', $requestId)->update(['Never_Texted' => $counts['never_texted'], 'Previously_Texted' => $counts['previously_texted']]);
            return ['selected' => $counts['selected'], 'target' => $target, 'shortfall' => max(0, $target - $counts['selected']), 'contacted_flagged' => 0];
        }));
    }

    public function composition(string $requestId): array
    {
        $row = $this->table('TblProspectsExport')->where('Request_ID', $requestId)->selectRaw('COUNT(*) AS Selected,
            COALESCE(SUM(CASE WHEN Was_Previously_Texted=0 THEN 1 ELSE 0 END),0) AS Never_Texted,
            COALESCE(SUM(CASE WHEN Was_Previously_Texted=1 THEN 1 ELSE 0 END),0) AS Previously_Texted')->first();
        return ['selected' => (int) $row->Selected, 'never_texted' => (int) $row->Never_Texted, 'previously_texted' => (int) $row->Previously_Texted];
    }

    /** Validate frozen content, not current eligibility. Upturn owns live suppression after generation. */
    public function assertFrozen(string $requestId, int $expected): void
    {
        $rows = $this->table('TblProspectsExport')->where('Request_ID', $requestId);
        if ((clone $rows)->count() !== $expected || $expected < 1
            || (clone $rows)->where(function ($q): void {
                $q->whereNull('SMS_Drop_PK')->orWhereNull('Debt_Tier')->orWhereNotIn('Debt_Tier', ['T1','T2','T3','T4','T5','T6','T7','T8','T9'])
                    ->orWhereNull('Debt_Amount')->orWhere('Debt_Amount', '<=', 0)->orWhereNull('Was_Previously_Texted')->orWhereNotIn('Was_Previously_Texted', [0,1]);
            })->exists()) throw new RuntimeException('Frozen SMS membership, tier, debt or count does not reconcile.');
    }

    /** Stream exact frozen values; no mutable prospect join or post-generation eligibility check. */
    public function writeCsv(string $requestId, string $path): int
    {
        SmsRecordPolicy::assertReady();
        $run = $this->table('TblSMSRuns')->where('Request_ID', $requestId)->first();
        if (! $run || ! SmsRecordPolicy::isRecord($run) || $run->Status !== 'building_file') throw new HttpException(409, 'This record batch is not building a file.');
        $this->assertFrozen($requestId, (int) $run->Selected);
        $file = fopen($path, 'wb');
        if ($file === false) throw new RuntimeException('Unable to open the SMS record export.');
        $count = 0;
        try {
            if (fputcsv($file, SmsProspectExportRepository::CSV_HEADER, ',', '"', '', "\r\n") === false) throw new RuntimeException('Unable to write the SMS header.');
            $rows = $this->table('TblProspectsExport', 'x')->join('TblSMSDrops as d', 'd.PK', '=', 'x.SMS_Drop_PK')
                ->where('x.Request_ID', $requestId)->orderBy('d.Drop_Name')->orderBy('x.Prospect_PK')
                ->select(['x.Client','x.Address','x.Debt_Amount','x.Phone','d.Drop_Date'])->cursor();
            foreach ($rows as $row) {
                $debt = rtrim(rtrim(number_format((float) $row->Debt_Amount, 2, '.', ''), '0'), '.');
                $cells = [explode(' ', trim((string) $row->Client))[0], (string) $row->Address, $debt,
                    (string) $row->Phone, '', '', '', '', Carbon::parse($row->Drop_Date)->toDateString()];
                $cells = array_map(fn (string $cell): string => preg_match('/^[=+@\-\t\r\n]/', $cell) ? "'".$cell : $cell, $cells);
                if (fputcsv($file, $cells, ',', '"', '', "\r\n") === false) throw new RuntimeException('Unable to write a frozen SMS record.');
                $count++;
            }
            if (! fflush($file)) throw new RuntimeException('Unable to finish the SMS record export.');
        } finally { fclose($file); }
        return $count;
    }

    /** Called in the service's same approval transaction, with the run row already locked. */
    public function approve(string $requestId, Carbon $approvedAt, int $expected): int
    {
        SmsRecordPolicy::assertReady();
        $db = $this->connection();
        if ($db->transactionLevel() < 1) throw new RuntimeException('Record approval requires the batch transaction.');
        $this->assertFrozen($requestId, $expected);
        $counts = $this->composition($requestId);
        $run = $this->table('TblSMSRuns')->where('Request_ID', $requestId)->first();
        if (! SmsRecordPolicy::isRecord($run) || $run->Status !== 'approving'
            || (int) $run->Never_Texted !== $counts['never_texted'] || (int) $run->Previously_Texted !== $counts['previously_texted']
            || $counts['never_texted'] + $counts['previously_texted'] !== $expected) throw new RuntimeException('The approved composition differs from the frozen batch.');
        if ($this->table('TblSMSHistory')->where('Request_ID', $requestId)->exists()) throw new RuntimeException('This pending batch already has history; reconcile before retrying.');
        $date = $approvedAt->copy()->setTimezone(SmsProspectExportRepository::TIMEZONE)->toDateString();
        $members = $this->table('TblProspectsExport')->where('Request_ID', $requestId)->select('Prospect_PK');
        $updated = $this->table('TblProspects')->whereIn('PK', $members)->update([
            'Last_SMS_Date' => $date, 'Latest_Export_Date' => $date, 'SMS_Attempts' => $db->raw('SMS_Attempts + 1'),
        ]);
        if ($updated !== $expected) throw new RuntimeException('Not all frozen prospect IDs could be updated; approval was rolled back.');
        $columns = ['Request_ID','SMS_Drop_PK','Drop_Name','Prospect_PK','Phone','Debt_Amount','Debt_Tier','Debt_Source','Debt_Source_PK',
            'Client','Address','City','State','Zip','Email','Was_Previously_Texted','Last_SMS_Date_At_Selection'];
        $this->table('TblSMSHistory')->insertUsing([...$columns,'Export_Date'], $this->table('TblProspectsExport')->where('Request_ID', $requestId)
            ->select($columns)->selectRaw('? AS Export_Date', [$approvedAt->copy()->utc()->format('Y-m-d H:i:s')]));
        $tierCounts = $this->table('TblSMSHistory')->where('Request_ID', $requestId)->groupBy('Debt_Tier')->selectRaw('Debt_Tier,COUNT(*) AS Records')->get();
        foreach ($tierCounts as $tier) {
            $drop = $this->table('TblSMSDrops')->where('Request_ID', $requestId)->where('Debt_Tier', $tier->Debt_Tier);
            if ((clone $drop)->count() !== 1) throw new RuntimeException('The approved tier drop does not reconcile.');
            $drop->update(['Drop_Date' => $date, 'Amount_Dropped' => (int) $tier->Records]);
        }
        if ($this->table('TblSMSHistory')->where('Request_ID', $requestId)->count() !== $expected) throw new RuntimeException('Approved history count does not reconcile.');
        $this->table('TblProspectsExport')->where('Request_ID', $requestId)->delete();
        SmsAvailabilityRefresh::afterCommit($db);
        return $updated;
    }

    /** Frozen denominators and live suppressions. Response metrics await validated attribution. */
    public function details(string $requestId): array
    {
        $run = $this->table('TblSMSRuns')->where('Request_ID', $requestId)->first();
        if (! $run || ! SmsRecordPolicy::isRecord($run) || $run->Status !== 'approved') throw new HttpException(409, 'Only approved record batches have this report.');
        $rows = $this->table('TblSMSHistory', 'h')->leftJoin('TblProspects as p', 'p.PK', '=', 'h.Prospect_PK')
            ->where('h.Request_ID', $requestId)->groupBy('h.Debt_Tier')->selectRaw('h.Debt_Tier,COUNT(*) AS Records,
                COALESCE(SUM(CASE WHEN p.Opt_Out=1 THEN 1 ELSE 0 END),0) AS Opt_Out,
                COALESCE(SUM(CASE WHEN p.Invalid_Phone=1 THEN 1 ELSE 0 END),0) AS Invalid')->get()->keyBy('Debt_Tier');
        $tiers = []; $total = ['records' => 0, 'opt_out' => 0, 'invalid' => 0];
        for ($i = 1; $i <= 9; $i++) {
            $row = $rows->get('T'.$i);
            $tier = ['tier' => 'T'.$i, 'records' => (int) ($row->Records ?? 0), 'opt_out' => (int) ($row->Opt_Out ?? 0),
                'invalid' => (int) ($row->Invalid ?? 0)];
            foreach (array_keys($total) as $key) $total[$key] += $tier[$key];
            $tier['contacted'] = null;
            $tier['response_rate'] = null;
            $tiers[] = $tier;
        }
        $total['contacted'] = null;
        $total['response_rate'] = null;
        return ['request_id' => strtolower($requestId), 'reporting' => 'Live Status', 'tiers' => $tiers, 'total' => $total,
            'response_metrics_available' => false, 'response_metrics_reason' => 'Contact response attribution is not implemented yet.'];
    }
}
