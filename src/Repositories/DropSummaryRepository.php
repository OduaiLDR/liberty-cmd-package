<?php

namespace Cmd\Reports\Repositories;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DropSummaryRepository extends SqlSrvRepository
{

    /**
     * @return array<int,string>
     */
    public function listDrops(): array
    {
        return Cache::remember('cmdpkg:drop_summary:lists:drops', 600, function () {
            return array_map(
                fn ($o) => $o->Drop_Name,
                $this->connection()->select("SELECT TOP 500 Drop_Name FROM TblMarketing ORDER BY Send_Date DESC, Drop_Name DESC")
            );
        });
    }

    /**
     * @return array<int,string>
     */
    public function listVendors(): array
    {
        return Cache::remember('cmdpkg:drop_summary:lists:vendors', 600, function () {
            return array_map(
                fn ($o) => $o->Vendor,
                $this->connection()->select("SELECT DISTINCT Vendor FROM TblMarketing WHERE Vendor IS NOT NULL ORDER BY Vendor")
            );
        });
    }

    /**
     * @return array<int,string>
     */
    public function listDataProviders(): array
    {
        return Cache::remember('cmdpkg:drop_summary:lists:data_providers', 600, function () {
            return array_map(
                fn ($o) => $o->Data_Type,
                $this->connection()->select("SELECT DISTINCT Data_Type FROM TblMarketing WHERE Data_Type IS NOT NULL ORDER BY Data_Type")
            );
        });
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{summary: array<int,array{Metric:string,Summary:float|int} >}
     */
    public function dropSummaryAggregates(array $filters): array
    {
        $whereParts = [];
        $params = [];
        $selectedDrops = $filters['drops'] ?? [];
        if (is_string($selectedDrops)) {
            $selectedDrops = array_filter(array_map('trim', explode(',', $selectedDrops)));
        }
        if (!is_array($selectedDrops)) { $selectedDrops = []; }
        if (count($selectedDrops) > 0) {
            $ph = implode(',', array_fill(0, count($selectedDrops), '?'));
            $whereParts[] = 'm.Drop_Name IN ('.$ph.')';
            foreach ($selectedDrops as $d) { $params[] = $d; }
        }
        $sendStart = (string) ($filters['send_start'] ?? '');
        $sendEnd = (string) ($filters['send_end'] ?? '');
        if ($sendStart !== '') { $whereParts[] = 'm.Send_Date >= ?'; $params[] = $sendStart; }
        if ($sendEnd !== '') { $whereParts[] = 'm.Send_Date < ?'; $params[] = date('Y-m-d', strtotime($sendEnd.' +1 day')); }
        $month = (int) ($filters['month'] ?? 0);
        $year = (int) ($filters['year'] ?? 0);
        $tier = (string) ($filters['tier'] ?? '');
        $vendor = (string) ($filters['vendor'] ?? '');
        $dataProvider = (string) ($filters['data_provider'] ?? '');
        if ($month >= 1 && $month <= 12) { $whereParts[] = 'MONTH(m.Send_Date) = ?'; $params[] = $month; }
        if ($year >= 2020 && $year <= 2100) { $whereParts[] = 'YEAR(m.Send_Date) = ?'; $params[] = $year; }
        if ($tier !== '') { $whereParts[] = 'm.Debt_Tier = ?'; $params[] = $tier; }
        if ($vendor !== '') { $whereParts[] = 'm.Vendor = ?'; $params[] = $vendor; }
        if ($dataProvider !== '') { $whereParts[] = 'm.Data_Type = ?'; $params[] = $dataProvider; }
        $whereSql = count($whereParts) ? (' WHERE '.implode(' AND ', $whereParts)) : '';

        $cteHeader = <<<SQL
WITH filtered AS (
  SELECT 
    m.Drop_Name,
    m.Debt_Tier,
    CONVERT(date, m.Send_Date) AS Send_Date,
    m.Drop_Type,
    m.Vendor,
    m.Data_Type,
    COALESCE(m.Amount_Dropped,0) AS Amount_Dropped,
    COALESCE(m.Data_Drop_Cost,0) AS Data_Drop_Cost,
    COALESCE(m.Mail_Drop_Cost,0) AS Mail_Drop_Cost,
    COALESCE(m.Calls,0) AS Calls
  FROM TblMarketing m{$whereSql}
),
csum AS (
  SELECT 
    f.Drop_Name,
    f.Send_Date,
    COUNT(1) AS total_leads
  FROM TblContacts c
  JOIN filtered f ON f.Drop_Name = c.Campaign
  GROUP BY f.Drop_Name, f.Send_Date
),
qsum AS (
  SELECT 
    f.Drop_Name,
    f.Send_Date,
    COUNT(1) AS qualified_leads
  FROM TblContacts c
  JOIN filtered f ON f.Drop_Name = c.Campaign
  WHERE c.LLG_ID IN (SELECT LLG_ID FROM TblEnrollment)
  GROUP BY f.Drop_Name, f.Send_Date
),
esum AS (
  SELECT 
    f.Drop_Name,
    COUNT(1) AS total_enrollments,
    SUM(e.Debt_Amount) AS enrolled_debt,
    AVG(NULLIF(e.Debt_Amount,0)) AS avg_debt,
    SUM(CASE WHEN e.Cancel_Date IS NOT NULL THEN 1 ELSE 0 END) AS cancels,
    SUM(CASE WHEN e.NSF_Date    IS NOT NULL THEN 1 ELSE 0 END) AS nsfs
  FROM TblEnrollment e
  JOIN filtered f ON f.Drop_Name = e.Drop_Name
  GROUP BY f.Drop_Name
),
active_reps AS (
  SELECT 
    sd.Send_Date,
    COUNT(1) AS active_reps
  FROM (SELECT DISTINCT Send_Date FROM filtered) sd
  JOIN TblEmployees e
    ON e.Access_Level = 'Agent'
   AND e.Hire_Date <= sd.Send_Date
   AND (e.Term_Date IS NULL OR e.Term_Date > sd.Send_Date)
  GROUP BY sd.Send_Date
)
SQL;

        $aggSql = $cteHeader . " SELECT 
            SUM(f.Amount_Dropped) AS amount_dropped,
            SUM(f.Data_Drop_Cost + f.Mail_Drop_Cost) AS drop_cost,
            SUM(f.Calls) AS calls,
            SUM(COALESCE(csum.total_leads,0)) AS total_leads,
            SUM(COALESCE(qsum.qualified_leads,0)) AS qualified_leads,
            SUM(COALESCE(esum.total_enrollments,0)) AS total_enrollments,
            SUM(COALESCE(esum.cancels,0)) AS cancels,
            SUM(COALESCE(esum.nsfs,0)) AS nsfs,
            SUM(COALESCE(esum.enrolled_debt,0)) AS enrolled_debt,
            AVG(COALESCE(esum.avg_debt,0)) AS avg_debt,
            AVG(COALESCE(ar.active_reps,0)) AS avg_active_reps
        FROM filtered f
        LEFT JOIN csum ON csum.Drop_Name = f.Drop_Name AND csum.Send_Date = f.Send_Date
        LEFT JOIN qsum ON qsum.Drop_Name = f.Drop_Name AND qsum.Send_Date = f.Send_Date
        LEFT JOIN esum ON esum.Drop_Name = f.Drop_Name
        LEFT JOIN active_reps ar ON ar.Send_Date = f.Send_Date";

        $agg = $this->connection()->selectOne($aggSql, $params);

        $amountDropped = (float) ($agg->amount_dropped ?? 0);
        $dropCost = (float) ($agg->drop_cost ?? 0);
        $calls = (float) ($agg->calls ?? 0);
        $totalLeads = (float) ($agg->total_leads ?? 0);
        $qualifiedLeads = (float) ($agg->qualified_leads ?? 0);
        $avgActiveReps = (float) ($agg->avg_active_reps ?? 0);
        $totalEnrollments = (float) ($agg->total_enrollments ?? 0);
        $cancels = (float) ($agg->cancels ?? 0);
        $nsfs = (float) ($agg->nsfs ?? 0);
        $enrolledDebt = (float) ($agg->enrolled_debt ?? 0);
        $avgDebt = (float) ($agg->avg_debt ?? 0);

        $netEnrollments = $totalEnrollments - ($cancels + $nsfs);
        $responseRate = ($amountDropped > 0) ? ($totalLeads / $amountDropped) : 0;
        $callsPerRep = ($avgActiveReps > 0) ? ($calls / $avgActiveReps) : 0;
        $costPerCall = ($calls > 0) ? ($dropCost / $calls) : 0;
        $amountPerRep = ($avgActiveReps > 0) ? ($amountDropped / $avgActiveReps) : 0;
        $pricePerDrop = ($amountDropped > 0) ? ($dropCost / $amountDropped) : 0;
        $cpa = ($totalEnrollments > 0) ? ($dropCost / $totalEnrollments) : 0;

        $summary = [
            ['Metric' => 'Amount Dropped', 'Summary' => $amountDropped],
            ['Metric' => 'Drop Cost', 'Summary' => $dropCost],
            ['Metric' => 'Calls', 'Summary' => $calls],
            ['Metric' => 'Total Leads', 'Summary' => $totalLeads],
            ['Metric' => 'Qualified Leads', 'Summary' => $qualifiedLeads],
            ['Metric' => 'Average Reps', 'Summary' => $avgActiveReps],
            ['Metric' => 'Response Rate', 'Summary' => $responseRate],
            ['Metric' => 'Calls Per Rep', 'Summary' => $callsPerRep],
            ['Metric' => 'Cost Per Call', 'Summary' => $costPerCall],
            ['Metric' => 'Amount Per Rep', 'Summary' => $amountPerRep],
            ['Metric' => 'Price Per Drop', 'Summary' => $pricePerDrop],
            ['Metric' => 'Total Enrollments', 'Summary' => $totalEnrollments],
            ['Metric' => 'Cancels', 'Summary' => $cancels],
            ['Metric' => 'NSFs', 'Summary' => $nsfs],
            ['Metric' => 'Net Enrollments', 'Summary' => $netEnrollments],
            ['Metric' => 'CPA', 'Summary' => $cpa],
            ['Metric' => 'Enrolled Debt', 'Summary' => $enrolledDebt],
            ['Metric' => 'Average Debt', 'Summary' => $avgDebt],
        ];

        return ['summary' => $summary];
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{labels:array<int,string>,amount:array<int,float>,cost:array<int,float>,calls:array<int,float>,avg_reps:array<int,float>,response:array<int,float>}
     */
    public function dropSummaryTimeSeries(array $filters): array
    {
        $selectedDrops = $filters['drops'] ?? [];
        if (is_string($selectedDrops)) {
            $selectedDrops = array_filter(array_map('trim', explode(',', $selectedDrops)));
        }
        if (!is_array($selectedDrops)) { $selectedDrops = []; }

        $chartWhereParts = [];
        $chartParams = [];
        if (count($selectedDrops) > 0) {
            $ph = implode(',', array_fill(0, count($selectedDrops), '?'));
            $chartWhereParts[] = 'm.Drop_Name IN ('.$ph.')';
            foreach ($selectedDrops as $d) { $chartParams[] = $d; }
        }
        $chartStart = (string) ($filters['chart_start'] ?? '');
        $chartEnd = (string) ($filters['chart_end'] ?? '');
        if ($chartStart !== '') { $chartWhereParts[] = 'm.Send_Date >= ?'; $chartParams[] = $chartStart; }
        if ($chartEnd !== '') { $chartWhereParts[] = 'm.Send_Date < ?'; $chartParams[] = date('Y-m-d', strtotime($chartEnd.' +1 day')); }
        $tier = (string) ($filters['tier'] ?? '');
        $vendor = (string) ($filters['vendor'] ?? '');
        $dataProvider = (string) ($filters['data_provider'] ?? '');
        if ($tier !== '') { $chartWhereParts[] = 'm.Debt_Tier = ?'; $chartParams[] = $tier; }
        if ($vendor !== '') { $chartWhereParts[] = 'm.Vendor = ?'; $chartParams[] = $vendor; }
        if ($dataProvider !== '') { $chartWhereParts[] = 'm.Data_Type = ?'; $chartParams[] = $dataProvider; }
        $chartWhereSql = count($chartWhereParts) ? (' WHERE '.implode(' AND ', $chartWhereParts)) : '';

        $chartCteHeader = <<<SQL
WITH chart_filtered AS (
  SELECT 
    m.Drop_Name,
    m.Debt_Tier,
    CONVERT(date, m.Send_Date) AS Send_Date,
    m.Drop_Type,
    m.Vendor,
    m.Data_Type,
    COALESCE(m.Amount_Dropped,0) AS Amount_Dropped,
    COALESCE(m.Data_Drop_Cost,0) AS Data_Drop_Cost,
    COALESCE(m.Mail_Drop_Cost,0) AS Mail_Drop_Cost,
    COALESCE(m.Calls,0) AS Calls
  FROM TblMarketing m{$chartWhereSql}
),
chart_csum AS (
  SELECT 
    f.Drop_Name,
    f.Send_Date,
    COUNT(1) AS total_leads
  FROM TblContacts c
  JOIN chart_filtered f ON f.Drop_Name = c.Campaign
  GROUP BY f.Drop_Name, f.Send_Date
)
SQL;

        $period = ($filters['chart_period'] ?? 'weekly') === 'monthly' ? 'monthly' : 'weekly';
        $groupBy = $period === 'monthly' ? 'DATEFROMPARTS(YEAR(f.Send_Date), MONTH(f.Send_Date), 1)' : 'f.Send_Date';
        $selectPeriod = $period === 'monthly' ? 'DATEFROMPARTS(YEAR(f.Send_Date), MONTH(f.Send_Date), 1) AS period_date' : 'f.Send_Date AS period_date';
        $tsSql = $chartCteHeader . " SELECT 
            $selectPeriod,
            SUM(f.Amount_Dropped) AS amount_dropped,
            SUM(f.Data_Drop_Cost + f.Mail_Drop_Cost) AS drop_cost,
            SUM(f.Calls) AS calls,
            SUM(COALESCE(csum.total_leads,0)) AS total_leads,
            0 AS avg_reps
        FROM chart_filtered f
        LEFT JOIN chart_csum csum ON csum.Drop_Name = f.Drop_Name AND csum.Send_Date = f.Send_Date
        GROUP BY $groupBy
        ORDER BY period_date";

        $tsRows = $this->connection()->select($tsSql, $chartParams);

        $labels = [];
        $amount = [];
        $cost = [];
        $calls = [];
        $avgReps = [];
        $response = [];
        foreach ($tsRows as $row) {
            $labels[] = (string) ($row->period_date ?? '');
            $a = (float) ($row->amount_dropped ?? 0);
            $c = (float) ($row->drop_cost ?? 0);
            $k = (float) ($row->calls ?? 0);
            $tls = (float) ($row->total_leads ?? 0);
            $avg = (float) ($row->avg_reps ?? 0);
            $amount[] = $a;
            $cost[] = $c;
            $calls[] = $k;
            $avgReps[] = $avg;
            $response[] = ($a > 0) ? ($tls / max(1, $a)) * 100.0 : 0.0;
        }

        return [
            'labels' => $labels,
            'amount' => $amount,
            'cost' => $cost,
            'calls' => $calls,
            'avg_reps' => $avgReps,
            'response' => $response,
        ];
    }
}
