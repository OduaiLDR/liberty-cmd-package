<?php

namespace Cmd\Reports\Repositories;

use Illuminate\Support\Facades\Cache;

class MarketingAdminRepository extends SqlSrvRepository
{
    /** @return array<int,string> */
    public function listDrops(): array
    {
        return Cache::remember('cmdpkg:marketing_admin:lists:drops', 33600, function () {
            return array_map(
                fn ($o) => $o->Drop_Name,
                $this->connection()->select("SELECT DISTINCT TOP 500 Drop_Name FROM TblMarketing WHERE Drop_Name IS NOT NULL ORDER BY Drop_Name")
            );
        });
    }

    /** @return array<int,string> */
    public function listStates(): array
    {
        return Cache::remember('cmdpkg:marketing_admin:lists:states', 3600, function () {
            // ASCII 65–90 = A–Z; avoids collation-dependent LIKE [A-Z] matching lowercase/digits
            return array_map(
                fn ($o) => $o->State,
                $this->connection()->select(
                    "SELECT DISTINCT State FROM TblContacts
                     WHERE State IS NOT NULL
                       AND LEN(State) = 2
                       AND ASCII(LEFT(State,1)) BETWEEN 65 AND 90
                       AND ASCII(RIGHT(State,1)) BETWEEN 65 AND 90
                     ORDER BY State"
                )
            );
        });
    }

    /** @return array<int,string> */
    public function listVendors(): array
    {
        return Cache::remember('cmdpkg:marketing_admin:lists:vendors', 3600, function () {
            return array_map(
                fn ($o) => $o->Vendor,
                $this->connection()->select("SELECT DISTINCT Vendor FROM TblMarketing WHERE Vendor IS NOT NULL ORDER BY Vendor")
            );
        });
    }

    /** @return array<int,string> */
    public function listDataProviders(): array
    {
        return Cache::remember('cmdpkg:marketing_admin:lists:data_providers', 3600, function () {
            return array_map(
                fn ($o) => $o->Data_Type,
                $this->connection()->select("SELECT DISTINCT Data_Type FROM TblMarketing WHERE Data_Type IS NOT NULL ORDER BY Data_Type")
            );
        });
    }

    /** @return array<int,string> */
    public function listTiers(): array
    {
        return Cache::remember('cmdpkg:marketing_admin:lists:tiers', 3600, function () {
            return array_map(
                fn ($o) => $o->Debt_Tier,
                $this->connection()->select("SELECT DISTINCT Debt_Tier FROM TblMarketing WHERE Debt_Tier IS NOT NULL ORDER BY Debt_Tier")
            );
        });
    }

    /** @return array<int,int> */
    public function listYears(): array
    {
        return Cache::remember('cmdpkg:marketing_admin:lists:years', 3600, function () {
            return array_map(
                fn ($o) => (int) $o->yr,
                $this->connection()->select("SELECT DISTINCT YEAR(Send_Date) AS yr FROM TblMarketing WHERE Send_Date IS NOT NULL ORDER BY yr DESC")
            );
        });
    }

    /** @return array<int,string> */
    public function listMailStyles(): array
    {
        return Cache::remember('cmdpkg:marketing_admin:lists:mail_styles', 3600, function () {
            return array_map(
                fn ($o) => $o->Mail_Style,
                $this->connection()->select("SELECT DISTINCT Mail_Style FROM TblMarketing WHERE Mail_Style IS NOT NULL ORDER BY Mail_Style")
            );
        });
    }

    /** @return array<int,string> */
    public function listDropTypes(): array
    {
        return Cache::remember('cmdpkg:marketing_admin:lists:drop_types', 3600, function () {
            return array_map(
                fn ($o) => $o->Drop_Type,
                $this->connection()->select("SELECT DISTINCT Drop_Type FROM TblMarketing WHERE Drop_Type IS NOT NULL ORDER BY Drop_Type")
            );
        });
    }

    /**
     * Build the shared CTE header + WHERE arrays from filters.
     * Returns [cteHeader, allParams, resultWhereSql]
     *
     * @param array<string,mixed> $filters
     * @return array{string, array<int,mixed>, string}
     */
    private function buildCteAndParams(array $filters): array
    {
        $selectedDrops = $filters['drops'] ?? [];
        if (is_string($selectedDrops)) {
            $selectedDrops = array_filter(array_map('trim', explode(',', $selectedDrops)));
        }
        $selectedDrops = array_filter((array) $selectedDrops);

        $tiers          = array_filter((array) ($filters['tiers'] ?? []));
        $vendors        = array_filter((array) ($filters['vendors'] ?? []));
        $dataProviders  = array_filter((array) ($filters['data_providers'] ?? []));
        $marketingTypes = array_filter((array) ($filters['marketing_types'] ?? []));
        $mailStyles     = array_filter((array) ($filters['mail_styles'] ?? []));
        $months         = array_filter(array_map('intval', (array) ($filters['months'] ?? [])), fn ($m) => $m >= 1 && $m <= 12);
        $years          = array_filter(array_map('intval', (array) ($filters['years'] ?? [])), fn ($y) => $y >= 2000 && $y <= 2100);

        $marketingWhereParts  = [];
        $marketingWhereParams = [];

        if (count($selectedDrops) > 0) {
            $ph = implode(',', array_fill(0, count($selectedDrops), 'UPPER(?)'));
            $marketingWhereParts[] = 'UPPER(m.Drop_Name) IN ('.$ph.')';
            foreach ($selectedDrops as $d) { $marketingWhereParams[] = strtoupper($d); }
        }

        $sendStart = (string) ($filters['send_start'] ?? '');
        $sendEnd   = (string) ($filters['send_end'] ?? '');

        if ($sendStart !== '') {
            $marketingWhereParts[] = 'm.Send_Date >= ?';
            $marketingWhereParams[] = $sendStart;
        }
        if ($sendEnd !== '') {
            $marketingWhereParts[] = 'm.Send_Date < ?';
            $marketingWhereParams[] = date('Y-m-d', strtotime($sendEnd.' +1 day'));
        }

        if (count($months) > 0) {
            $ph = implode(',', array_fill(0, count($months), '?'));
            $marketingWhereParts[] = 'MONTH(m.Send_Date) IN ('.$ph.')';
            foreach ($months as $m) { $marketingWhereParams[] = $m; }
        }
        if (count($years) > 0) {
            $ph = implode(',', array_fill(0, count($years), '?'));
            $marketingWhereParts[] = 'YEAR(m.Send_Date) IN ('.$ph.')';
            foreach ($years as $y) { $marketingWhereParams[] = $y; }
        }
        if (count($tiers) > 0) {
            $ph = implode(',', array_fill(0, count($tiers), 'UPPER(?)'));
            $marketingWhereParts[] = 'UPPER(m.Debt_Tier) IN ('.$ph.')';
            foreach ($tiers as $t) { $marketingWhereParams[] = strtoupper($t); }
        }
        if (count($vendors) > 0) {
            $ph = implode(',', array_fill(0, count($vendors), 'UPPER(?)'));
            $marketingWhereParts[] = 'UPPER(m.Vendor) IN ('.$ph.')';
            foreach ($vendors as $v) { $marketingWhereParams[] = strtoupper($v); }
        }
        if (count($dataProviders) > 0) {
            $ph = implode(',', array_fill(0, count($dataProviders), 'UPPER(?)'));
            $marketingWhereParts[] = 'UPPER(m.Data_Type) IN ('.$ph.')';
            foreach ($dataProviders as $dp) { $marketingWhereParams[] = strtoupper($dp); }
        }
        if (count($marketingTypes) > 0) {
            $ph = implode(',', array_fill(0, count($marketingTypes), 'UPPER(?)'));
            $marketingWhereParts[] = 'UPPER(m.Drop_Type) IN ('.$ph.')';
            foreach ($marketingTypes as $mt) { $marketingWhereParams[] = strtoupper($mt); }
        }
        if (count($mailStyles) > 0) {
            $ph = implode(',', array_fill(0, count($mailStyles), 'UPPER(?)'));
            $marketingWhereParts[] = 'UPPER(m.Mail_Style) IN ('.$ph.')';
            foreach ($mailStyles as $ms) { $marketingWhereParams[] = strtoupper($ms); }
        }

        $intent = (string) ($filters['intent'] ?? 'all');
        if ($intent === 'yes') {
            $marketingWhereParts[] = 'tmi.Drop_Name IS NOT NULL';
        } elseif ($intent === 'no') {
            $marketingWhereParts[] = 'tmi.Drop_Name IS NULL';
        }

        $contactWhereParts  = [];
        $contactWhereParams = [];

        $states = array_filter((array) ($filters['states'] ?? []));
        if (count($states) > 0) {
            $ph = implode(',', array_fill(0, count($states), 'UPPER(?)'));
            $contactWhereParts[] = 'UPPER(c.State) IN ('.$ph.')';
            foreach ($states as $s) { $contactWhereParams[] = strtoupper($s); }
        }

        $debtMin = $filters['debt_min'] ?? null;
        $debtMax = $filters['debt_max'] ?? null;
        if ($debtMin !== null && $debtMin !== '') {
            $contactWhereParts[] = 'COALESCE(c.Debt_Amount, 0) >= ?';
            $contactWhereParams[] = (float) $debtMin;
        }
        if ($debtMax !== null && $debtMax !== '') {
            $contactWhereParts[] = 'COALESCE(c.Debt_Amount, 0) <= ?';
            $contactWhereParams[] = (float) $debtMax;
        }

        $ficoMin = $filters['fico_min'] ?? null;
        $ficoMax = $filters['fico_max'] ?? null;
        if ($ficoMin !== null && $ficoMin !== '') {
            $contactWhereParts[] = 'COALESCE(c.Credit_Score, 0) >= ?';
            $contactWhereParams[] = (int) $ficoMin;
        }
        if ($ficoMax !== null && $ficoMax !== '') {
            $contactWhereParts[] = 'COALESCE(c.Credit_Score, 0) <= ?';
            $contactWhereParams[] = (int) $ficoMax;
        }

        $marketingWhereSql = count($marketingWhereParts) > 0 ? ' WHERE '.implode(' AND ', $marketingWhereParts) : '';
        $contactWhereSql   = count($contactWhereParts) > 0 ? ' WHERE '.implode(' AND ', $contactWhereParts) : '';
        $resultWhereSql    = count($contactWhereParts) > 0
            ? ' WHERE EXISTS (SELECT 1 FROM contact_filtered cf WHERE cf.Drop_Name = f.Drop_Name AND cf.Send_Date = f.Send_Date)'
            : '';

        $allParams = array_merge($marketingWhereParams, $contactWhereParams);

        $cteHeader = <<<SQL
WITH filtered AS (
  SELECT DISTINCT
    m.Drop_Name,
    m.Debt_Tier,
    CONVERT(date, m.Send_Date) AS Send_Date,
    m.Drop_Type,
    m.Vendor,
    m.Data_Type,
    m.Mail_Style,
    COALESCE(m.Amount_Dropped, 0) AS Amount_Dropped,
    COALESCE(m.Data_Drop_Cost, 0) AS Data_Drop_Cost,
    COALESCE(m.Mail_Drop_Cost, 0) AS Mail_Drop_Cost,
    COALESCE(m.Calls, 0) AS Calls,
    CASE WHEN tmi.Drop_Name IS NOT NULL THEN 'Yes' ELSE 'No' END AS Intent
  FROM TblMarketing m WITH (NOLOCK)
  LEFT JOIN (SELECT DISTINCT [Drop_Name] FROM TblmailersIntent WITH (NOLOCK)) tmi ON tmi.Drop_Name = m.Drop_Name
  {$marketingWhereSql}
), contact_filtered AS (
  SELECT
    f.Drop_Name,
    f.Send_Date,
    c.LLG_ID,
    c.Assigned_Date,
    COALESCE(c.Debt_Amount, 0) AS Debt_Amount
  FROM filtered f
  JOIN TblContacts c WITH (NOLOCK) ON c.Campaign = f.Drop_Name
  {$contactWhereSql}
), csum AS (
  SELECT
    cf.Drop_Name,
    cf.Send_Date,
    COUNT(1) AS total_leads,
    SUM(CASE WHEN cf.Assigned_Date IS NOT NULL THEN 1 ELSE 0 END) AS assigned_leads
  FROM contact_filtered cf
  GROUP BY cf.Drop_Name, cf.Send_Date
), qsum AS (
  SELECT
    cf.Drop_Name,
    cf.Send_Date,
    COUNT(1) AS qualified_leads
  FROM contact_filtered cf
  WHERE cf.Debt_Amount >= 7500
  GROUP BY cf.Drop_Name, cf.Send_Date
), esum AS (
  SELECT
    cf.Drop_Name,
    cf.Send_Date,
    COUNT(1) AS total_enrollments,
    SUM(COALESCE(e.Debt_Amount, 0)) AS enrolled_debt,
    AVG(NULLIF(COALESCE(e.Debt_Amount, 0), 0)) AS avg_debt,
    SUM(CASE WHEN e.Cancel_Date IS NOT NULL THEN 1 ELSE 0 END) AS cancels,
    SUM(CASE WHEN e.NSF_Date IS NOT NULL THEN 1 ELSE 0 END) AS nsfs,
    SUM(CASE WHEN e.Cancel_Date IS NULL AND e.NSF_Date IS NULL THEN COALESCE(e.Debt_Amount, 0) ELSE 0 END) AS retained_debt,
    SUM(CASE WHEN e.Cancel_Date IS NULL AND e.NSF_Date IS NULL THEN COALESCE(e.Debt_Amount, 0) * 0.15 ELSE 0 END) AS veritas_enrollment_fee,
    SUM(CASE WHEN e.Cancel_Date IS NULL AND e.NSF_Date IS NULL THEN COALESCE(e.Program_Payment, 0) ELSE 0 END) AS veritas_monthly_fee
  FROM contact_filtered cf
  JOIN TblEnrollment e WITH (NOLOCK) ON e.LLG_ID = cf.LLG_ID
  GROUP BY cf.Drop_Name, cf.Send_Date
), active_reps AS (
  SELECT
    sd.Send_Date,
    COUNT(1) AS active_reps
  FROM (SELECT DISTINCT Send_Date FROM filtered) sd
  JOIN TblEmployees e WITH (NOLOCK)
    ON e.Access_Level = 'Agent'
   AND e.Hire_Date <= sd.Send_Date
   AND (e.Term_Date IS NULL OR e.Term_Date > sd.Send_Date)
  GROUP BY sd.Send_Date
)
SQL;

        return [$cteHeader, $allParams, $resultWhereSql];
    }

    /**
     * Build summary (table) data and total count.
     * @param array<string,mixed> $filters
     * @return array{columns:array<int,string>,rows:array<int,object>,total:int,report:string}
     */
    public function summary(array $filters, int $page, int $perPage): array
    {
        $offset = ($page - 1) * $perPage;

        [$cteHeader, $allParams, $resultWhereSql] = $this->buildCteAndParams($filters);

        $selectCols = 'SELECT '
            .'COUNT(1) OVER() AS _total_count, '
            .'f.Drop_Name AS [Drop Name], '
            .'f.Debt_Tier AS [Tier], '
            .'f.Send_Date AS [Send Date], '
            .'f.Drop_Type AS [Marketing Type], '
            .'f.Vendor AS [Vendor], '
            .'f.Data_Type AS [Data Provider], '
            .'f.Intent AS [Intent], '
            .'f.Mail_Style AS [Mail Style], '
            .'f.Amount_Dropped AS [Amount Dropped], '
            .'(f.Data_Drop_Cost + f.Mail_Drop_Cost) AS [Drop Cost], '
            .'f.Calls AS [Calls], '
            .'COALESCE(csum.total_leads, 0) AS [Total Leads], '
            .'COALESCE(qsum.qualified_leads, 0) AS [Qualified Leads], '
            .'COALESCE(csum.total_leads, 0) - COALESCE(qsum.qualified_leads, 0) AS [Unqualified Leads], '
            .'COALESCE(csum.assigned_leads, 0) AS [Assigned Leads], '
            .'COALESCE(ar.active_reps, 0) AS [Active Reps], '
            .'COALESCE(CAST(csum.total_leads AS FLOAT) / NULLIF(CAST(f.Amount_Dropped AS FLOAT), 0), 0) AS [Lead Rate], '
            .'COALESCE(CAST(f.Calls AS FLOAT) / NULLIF(CAST(ar.active_reps AS FLOAT), 0), 0) AS [Calls Per Rep], '
            .'COALESCE(CAST((f.Data_Drop_Cost + f.Mail_Drop_Cost) AS FLOAT) / NULLIF(CAST(f.Calls AS FLOAT), 0), 0) AS [Cost Per Call], '
            .'COALESCE(CAST(f.Amount_Dropped AS FLOAT) / NULLIF(CAST(ar.active_reps AS FLOAT), 0), 0) AS [Amount Per Rep], '
            .'COALESCE(CAST((f.Data_Drop_Cost + f.Mail_Drop_Cost) AS FLOAT) / NULLIF(CAST(f.Amount_Dropped AS FLOAT), 0), 0) AS [Price Per Drop], '
            .'COALESCE(esum.total_enrollments, 0) AS [Total Enrollments], '
            .'COALESCE(esum.cancels, 0) AS [Cancels], '
            .'COALESCE(esum.nsfs, 0) AS [NSFs], '
            .'COALESCE(esum.total_enrollments, 0) - (COALESCE(esum.cancels, 0) + COALESCE(esum.nsfs, 0)) AS [Net Enrollments], '
            .'COALESCE(CAST((f.Data_Drop_Cost + f.Mail_Drop_Cost) AS FLOAT) / NULLIF(CAST(esum.total_enrollments AS FLOAT), 0), 0) AS [CPA], '
            .'COALESCE(esum.enrolled_debt, 0) AS [Enrolled Debt], '
            .'COALESCE(esum.avg_debt, 0) AS [Average Debt], '
            .'COALESCE(CAST(esum.total_enrollments AS FLOAT) / NULLIF(CAST(csum.total_leads AS FLOAT), 0) * 100, 0) AS [Conversion Rate %], '
            .'COALESCE(CAST((esum.retained_debt * 0.25) - (f.Data_Drop_Cost + f.Mail_Drop_Cost) AS FLOAT) / NULLIF(CAST((f.Data_Drop_Cost + f.Mail_Drop_Cost) AS FLOAT), 0), 0) AS [ROI Ratio], '
            .'COALESCE(esum.retained_debt * 0.25, 0) AS [Est Revenue], '
            .'COALESCE((esum.retained_debt * 0.25) - (f.Data_Drop_Cost + f.Mail_Drop_Cost), 0) AS [Est Profit], '
            .'COALESCE(CAST((esum.total_enrollments - COALESCE(esum.cancels, 0) - COALESCE(esum.nsfs, 0)) AS FLOAT) / NULLIF(CAST(esum.total_enrollments AS FLOAT), 0) * 100, 0) AS [Retention Rate %], '
            .'COALESCE(CAST((f.Data_Drop_Cost + f.Mail_Drop_Cost) AS FLOAT) / NULLIF(CAST(csum.total_leads AS FLOAT), 0), 0) AS [Cost Per Lead], '
            .'COALESCE(CAST(esum.retained_debt * 0.25 AS FLOAT) / NULLIF(CAST(csum.total_leads AS FLOAT), 0), 0) AS [Revenue Per Lead], '
            .'COALESCE(esum.veritas_enrollment_fee, 0) AS [Veritas Enrollment], '
            .'COALESCE(esum.veritas_monthly_fee, 0) AS [Veritas Monthly]';

        $joins = ' FROM filtered f '
            .'LEFT JOIN csum ON csum.Drop_Name = f.Drop_Name AND csum.Send_Date = f.Send_Date '
            .'LEFT JOIN qsum ON qsum.Drop_Name = f.Drop_Name AND qsum.Send_Date = f.Send_Date '
            .'LEFT JOIN esum ON esum.Drop_Name = f.Drop_Name AND esum.Send_Date = f.Send_Date '
            .'LEFT JOIN active_reps ar ON ar.Send_Date = f.Send_Date';

        $sortMap = [
            'send_date'        => 'f.Send_Date',
            'drop_name'        => 'f.Drop_Name',
            'tier'             => 'f.Debt_Tier',
            'vendor'           => 'f.Vendor',
            'drop_cost'        => '(f.Data_Drop_Cost + f.Mail_Drop_Cost)',
            'calls'            => 'f.Calls',
            'total_leads'      => 'COALESCE(csum.total_leads, 0)',
            'total_enrollments'=> 'COALESCE(esum.total_enrollments, 0)',
            'net_enrollments'  => 'COALESCE(esum.total_enrollments, 0) - (COALESCE(esum.cancels, 0) + COALESCE(esum.nsfs, 0))',
            'est_profit'       => 'COALESCE((esum.retained_debt * 0.25) - (f.Data_Drop_Cost + f.Mail_Drop_Cost), 0)',
            'conversion_rate'  => 'COALESCE(CAST(esum.total_enrollments AS FLOAT) / NULLIF(CAST(csum.total_leads AS FLOAT), 0) * 100, 0)',
        ];

        $sortKey       = strtolower((string) ($filters['sort'] ?? 'send_date'));
        $sortExpression = $sortMap[$sortKey] ?? $sortMap['send_date'];
        $sortDirection  = strtolower((string) ($filters['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';

        $orderParts = [$sortExpression.' '.$sortDirection];
        if (strtolower($sortExpression) !== 'f.drop_name') { $orderParts[] = 'f.Drop_Name ASC'; }
        if (strtolower($sortExpression) !== 'f.send_date') { $orderParts[] = 'f.Send_Date ASC'; }
        $orderSql = ' ORDER BY '.implode(', ', $orderParts);

        $dataSql = $cteHeader.' '.$selectCols.$joins.$resultWhereSql.$orderSql.' OFFSET '.$offset.' ROWS FETCH NEXT '.$perPage.' ROWS ONLY';

        $rows  = $this->connection()->select($dataSql, $allParams);
        $total = !empty($rows) ? (int) ($rows[0]->_total_count ?? 0) : 0;

        foreach ($rows as $row) { unset($row->_total_count); }

        $finalColumns = [
            'Drop Name','Tier','Send Date','Marketing Type','Vendor','Data Provider','Intent','Mail Style',
            'Amount Dropped','Drop Cost','Calls',
            'Total Leads','Qualified Leads','Unqualified Leads','Assigned Leads','Active Reps',
            'Lead Rate','Calls Per Rep','Cost Per Call','Amount Per Rep','Price Per Drop',
            'Total Enrollments','Cancels','NSFs','Net Enrollments','CPA','Enrolled Debt','Average Debt',
            'Conversion Rate %','ROI Ratio','Est Revenue','Est Profit','Retention Rate %',
            'Cost Per Lead','Revenue Per Lead','Veritas Enrollment','Veritas Monthly',
        ];

        return ['columns' => $finalColumns, 'rows' => $rows, 'total' => $total, 'report' => 'marketing_admin'];
    }

    /**
     * Aggregate totals for the Drop Summary insight panel.
     * Uses the nightly snapshot when available (zero Azure hits).
     * Falls back to live Azure query when contact-level filters are active or no snapshot exists.
     * @param array<string,mixed> $filters
     */
    public function summaryAggregates(array $filters): ?object
    {
        $hasContactFilters = !empty(array_filter([
            $filters['states']   ?? [],
            $filters['debt_min'] ?? '',
            $filters['debt_max'] ?? '',
            $filters['fico_min'] ?? '',
            $filters['fico_max'] ?? '',
        ]));

        if (!$hasContactFilters) {
            $snapshot = $this->getSnapshot();
            if ($snapshot !== null) {
                $filtered = $this->filterSnapshotRows($snapshot, $filters);
                return $this->aggregateSnapshotRows($filtered);
            }
        }

        // ── Live Azure fallback ───────────────────────────────────────────────
        [$cteHeader, $allParams, $resultWhereSql] = $this->buildCteAndParams($filters);

        $sql = $cteHeader.' SELECT '
            .'ISNULL(SUM(f.Amount_Dropped), 0) AS amount_dropped, '
            .'(SELECT AVG(CAST(ar2.active_reps AS FLOAT)) FROM active_reps ar2) AS avg_reps, '
            .'ISNULL(SUM(f.Data_Drop_Cost + f.Mail_Drop_Cost), 0) AS drop_costs, '
            .'ISNULL(SUM(f.Calls), 0) AS total_calls, '
            .'ISNULL(SUM(COALESCE(csum.total_leads, 0)), 0) AS total_leads, '
            .'ISNULL(SUM(COALESCE(qsum.qualified_leads, 0)), 0) AS qualified_leads, '
            .'ISNULL(SUM(COALESCE(csum.assigned_leads, 0)), 0) AS assigned_leads, '
            .'ISNULL(SUM(COALESCE(esum.total_enrollments, 0)), 0) AS total_enrollments, '
            .'ISNULL(SUM(COALESCE(esum.cancels, 0)), 0) AS total_cancels, '
            .'ISNULL(SUM(COALESCE(esum.nsfs, 0)), 0) AS total_nsfs, '
            .'ISNULL(SUM(COALESCE(esum.enrolled_debt, 0)), 0) AS total_enrolled_debt, '
            .'ISNULL(SUM(COALESCE(esum.retained_debt, 0)), 0) AS total_retained_debt, '
            .'ISNULL(SUM(COALESCE(esum.veritas_enrollment_fee, 0)), 0) AS total_veritas_enrollment, '
            .'ISNULL(SUM(COALESCE(esum.veritas_monthly_fee, 0)), 0) AS total_veritas_monthly '
            .'FROM filtered f '
            .'LEFT JOIN csum ON csum.Drop_Name = f.Drop_Name AND csum.Send_Date = f.Send_Date '
            .'LEFT JOIN qsum ON qsum.Drop_Name = f.Drop_Name AND qsum.Send_Date = f.Send_Date '
            .'LEFT JOIN esum ON esum.Drop_Name = f.Drop_Name AND esum.Send_Date = f.Send_Date '
            .'LEFT JOIN active_reps ar ON ar.Send_Date = f.Send_Date'
            .$resultWhereSql;

        $rows = $this->connection()->select($sql, $allParams);
        if (empty($rows)) {
            return null;
        }

        $r = $rows[0];
        $amountDropped      = (float) ($r->amount_dropped ?? 0);
        $avgReps            = (float) ($r->avg_reps ?? 0);
        $dropCosts          = (float) ($r->drop_costs ?? 0);
        $totalCalls         = (float) ($r->total_calls ?? 0);
        $totalLeads         = (int)   ($r->total_leads ?? 0);
        $qualifiedLeads     = (int)   ($r->qualified_leads ?? 0);
        $assignedLeads      = (int)   ($r->assigned_leads ?? 0);
        $totalEnrollments   = (int)   ($r->total_enrollments ?? 0);
        $cancels            = (int)   ($r->total_cancels ?? 0);
        $nsfs               = (int)   ($r->total_nsfs ?? 0);
        $totalEnrolledDebt  = (float) ($r->total_enrolled_debt ?? 0);
        $totalRetainedDebt  = (float) ($r->total_retained_debt ?? 0);
        $veritasEnrollment  = (float) ($r->total_veritas_enrollment ?? 0);
        $veritasMonthly     = (float) ($r->total_veritas_monthly ?? 0);

        $unqualifiedLeads = $totalLeads - $qualifiedLeads;
        $netEnrollments   = $totalEnrollments - $cancels - $nsfs;
        $estRevenue       = $totalRetainedDebt * 0.25;
        $estProfit        = $estRevenue - $dropCosts;

        return (object) [
            'amount_dropped'         => (int) $amountDropped,
            'avg_reps'               => (int) round($avgReps),
            'amount_per_rep'         => $avgReps > 0 ? (int) round($amountDropped / $avgReps) : 0,
            'cost_per_drop'          => $amountDropped > 0 ? $dropCosts / $amountDropped : 0.0,
            'total_leads'            => $totalLeads,
            'qualified_leads'        => $qualifiedLeads,
            'unqualified_leads'      => $unqualifiedLeads,
            'assigned_leads'         => $assignedLeads,
            'qualified_leads_rate'   => $totalLeads > 0 ? ($qualifiedLeads / $totalLeads) * 100 : 0.0,
            'unqualified_leads_rate' => $totalLeads > 0 ? ($unqualifiedLeads / $totalLeads) * 100 : 0.0,
            'assigned_leads_rate'    => $totalLeads > 0 ? ($assignedLeads / $totalLeads) * 100 : 0.0,
            'calls_per_rep'          => $avgReps > 0 ? $totalCalls / $avgReps : 0.0,
            'cost_per_call'          => $totalCalls > 0 ? $dropCosts / $totalCalls : 0.0,
            'cpa'                    => $totalEnrollments > 0 ? $dropCosts / $totalEnrollments : 0.0,
            'response_rate'          => $amountDropped > 0 ? ($totalLeads / $amountDropped) * 100 : 0.0,
            'drop_costs'             => $dropCosts,
            'active_deals'           => $netEnrollments,
            'conversion_rate'        => $totalLeads > 0 ? ($totalEnrollments / $totalLeads) * 100 : 0.0,
            'total_debt_enrolled'    => $totalEnrolledDebt,
            'average_debt'           => $totalEnrollments > 0 ? $totalEnrolledDebt / $totalEnrollments : 0.0,
            'debt_buyer_8pct'        => $totalEnrolledDebt * 0.08,
            'veritas_enrollment_fees'=> $veritasEnrollment,
            'veritas_monthly_fees'   => $veritasMonthly,
            'total_gross_revenue'    => $estRevenue,
            'cancels'                => $cancels,
            'nsfs'                   => $nsfs,
            'net_enrollments'        => $netEnrollments,
            'retention_ratio'        => $totalEnrollments > 0 ? ($netEnrollments / $totalEnrollments) * 100 : 0.0,
            'roi'                    => $estProfit,
            'pproi'                  => $amountDropped > 0 ? $estProfit / $amountDropped : 0.0,
        ];
    }

    /**
     * Build time-series from cached snapshot rows entirely in PHP.
     *
     * @param list<\stdClass>     $snapshot
     * @param array<string,mixed> $filters
     * @return array{labels:list<string>,amount:list<float>,cost:list<float>,calls:list<float>,avgReps:list<float>,response:list<float>,totalLeads:list<int>,enrollments:list<int>,netEnrollments:list<int>,cancels:list<int>,nsfs:list<int>,enrolledDebt:list<float>,retainedDebt:list<float>}
     */
    private function timeSeriesFromSnapshot(array $snapshot, array $filters, string $period): array
    {
        $rows = $this->filterSnapshotRows($snapshot, $filters);

        $buckets    = [];
        $seenDrops  = [];

        foreach ($rows as $r) {
            $date = (string) ($r->Send_Date ?? '');
            if ($date === '') {
                continue;
            }

            $key = $period === 'monthly'
                ? date('Y-m', strtotime($date)).'-01'
                : $date;

            if (!isset($buckets[$key])) {
                $buckets[$key] = [
                    'amount' => 0.0, 'cost' => 0.0, 'calls' => 0.0, 'reps' => 0, 'leads' => 0,
                    'enrollments' => 0, 'cancels' => 0, 'nsfs' => 0,
                    'enrolledDebt' => 0.0, 'retainedDebt' => 0.0,
                ];
            }

            $buckets[$key]['amount'] += (float) ($r->Amount_Dropped ?? 0);
            $buckets[$key]['cost']   += (float) ($r->Drop_Cost      ?? 0);
            $buckets[$key]['calls']  += (float) ($r->Calls          ?? 0);
            $buckets[$key]['reps']    = max($buckets[$key]['reps'], (int) ($r->active_reps ?? 0));

            // Lead + enrollment data is per-drop not per-drop+date — deduplicate within each bucket
            $drop = strtoupper($key.'|'.(string) ($r->Drop_Name ?? ''));
            if (!isset($seenDrops[$drop])) {
                $seenDrops[$drop]                  = true;
                $buckets[$key]['leads']            += (int)   ($r->total_leads        ?? 0);
                $buckets[$key]['enrollments']      += (int)   ($r->total_enrollments  ?? 0);
                $buckets[$key]['cancels']          += (int)   ($r->cancels            ?? 0);
                $buckets[$key]['nsfs']             += (int)   ($r->nsfs               ?? 0);
                $buckets[$key]['enrolledDebt']     += (float) ($r->enrolled_debt      ?? 0);
                $buckets[$key]['retainedDebt']     += (float) ($r->retained_debt      ?? 0);
            }
        }

        ksort($buckets);

        $labels = $amount = $cost = $calls = $avgReps = $response = $totalLeads = [];
        $enrollments = $netEnrollments = $cancels = $nsfs = $enrolledDebt = $retainedDebt = [];

        foreach ($buckets as $label => $b) {
            $labels[]         = $label;
            $a                = $b['amount'];
            $tls              = $b['leads'];
            $en               = $b['enrollments'];
            $ca               = $b['cancels'];
            $ns               = $b['nsfs'];
            $amount[]         = $a;
            $cost[]           = $b['cost'];
            $calls[]          = $b['calls'];
            $avgReps[]        = (float) $b['reps'];
            $response[]       = $a > 0 ? ($tls / $a) * 100.0 : 0.0;
            $totalLeads[]     = $tls;
            $enrollments[]    = $en;
            $netEnrollments[] = $en - $ca - $ns;
            $cancels[]        = $ca;
            $nsfs[]           = $ns;
            $enrolledDebt[]   = $b['enrolledDebt'];
            $retainedDebt[]   = $b['retainedDebt'];
        }

        return compact(
            'labels', 'amount', 'cost', 'calls', 'avgReps', 'response', 'totalLeads',
            'enrollments', 'netEnrollments', 'cancels', 'nsfs', 'enrolledDebt', 'retainedDebt'
        );
    }

    /**
     * Build WHERE parts for TblMarketing-only filters (no contact/debt/FICO).
     * Used by timeSeries() to avoid joining TblContacts for every chart render.
     *
     * @param array<string,mixed> $filters
     * @return array{array<int,string>, array<int,mixed>}
     */
    private function buildMarketingWhere(array $filters): array
    {
        $parts  = [];
        $params = [];

        $drops = array_filter((array) ($filters['drops'] ?? []));
        if ($drops) {
            $ph      = implode(',', array_fill(0, count($drops), 'UPPER(?)'));
            $parts[] = "UPPER(m.Drop_Name) IN ($ph)";
            foreach ($drops as $d) { $params[] = strtoupper((string) $d); }
        }

        $sendStart = trim((string) ($filters['send_start'] ?? ''));
        $sendEnd   = trim((string) ($filters['send_end'] ?? ''));
        if ($sendStart !== '') { $parts[] = 'm.Send_Date >= ?'; $params[] = $sendStart; }
        if ($sendEnd !== '')   { $parts[] = 'm.Send_Date < ?';  $params[] = date('Y-m-d', strtotime($sendEnd.' +1 day')); }

        $months = array_filter(array_map('intval', (array) ($filters['months'] ?? [])), fn ($m) => $m >= 1 && $m <= 12);
        if ($months) {
            $ph = implode(',', array_fill(0, count($months), '?'));
            $parts[] = "MONTH(m.Send_Date) IN ($ph)";
            foreach ($months as $m) { $params[] = $m; }
        }
        $years = array_filter(array_map('intval', (array) ($filters['years'] ?? [])), fn ($y) => $y >= 2000 && $y <= 2100);
        if ($years) {
            $ph = implode(',', array_fill(0, count($years), '?'));
            $parts[] = "YEAR(m.Send_Date) IN ($ph)";
            foreach ($years as $y) { $params[] = $y; }
        }

        foreach ([
            ['tiers',           'UPPER(m.Debt_Tier)'],
            ['vendors',         'UPPER(m.Vendor)'],
            ['data_providers',  'UPPER(m.Data_Type)'],
            ['marketing_types', 'UPPER(m.Drop_Type)'],
            ['mail_styles',     'UPPER(m.Mail_Style)'],
        ] as [$key, $col]) {
            $vals = array_filter((array) ($filters[$key] ?? []));
            if ($vals) {
                $ph      = implode(',', array_fill(0, count($vals), 'UPPER(?)'));
                $parts[] = "$col IN ($ph)";
                foreach ($vals as $v) { $params[] = strtoupper((string) $v); }
            }
        }

        return [$parts, $params];
    }

    /**
     * Time-series data for the AJAX chart.
     * Uses nightly snapshot when available; falls back to lightweight SQL query.
     *
     * @param array<string,mixed> $filters
     * @param string $period 'weekly'|'monthly'
     * @return array{labels:list<string>,amount:list<float>,cost:list<float>,calls:list<float>,avgReps:list<float>,response:list<float>,totalLeads:list<int>,enrollments:list<int>,netEnrollments:list<int>,cancels:list<int>,nsfs:list<int>,enrolledDebt:list<float>,retainedDebt:list<float>}
     */
    public function timeSeries(array $filters, string $period = 'weekly'): array
    {
        $snapshot = $this->getSnapshot();
        if ($snapshot !== null) {
            return $this->timeSeriesFromSnapshot($snapshot, $filters, $period);
        }

        [$whereParts, $params] = $this->buildMarketingWhere($filters);
        $whereSql = $whereParts ? ' WHERE ' . implode(' AND ', $whereParts) : '';

        $groupExpr  = $period === 'monthly'
            ? 'DATEFROMPARTS(YEAR(m.Send_Date), MONTH(m.Send_Date), 1)'
            : 'm.Send_Date';
        $selectExpr = $period === 'monthly'
            ? 'DATEFROMPARTS(YEAR(m.Send_Date), MONTH(m.Send_Date), 1) AS period_date'
            : 'm.Send_Date AS period_date';

        $sql = "
WITH mkt AS (
  SELECT
    CONVERT(date, m.Send_Date)                                     AS Send_Date,
    m.Drop_Name,
    COALESCE(m.Amount_Dropped, 0)                                  AS Amount_Dropped,
    COALESCE(m.Data_Drop_Cost, 0) + COALESCE(m.Mail_Drop_Cost, 0) AS Drop_Cost,
    COALESCE(m.Calls, 0)                                           AS Calls
  FROM TblMarketing m WITH (NOLOCK)
  {$whereSql}
),
lead_agg AS (
  SELECT c.Campaign AS Drop_Name, COUNT(1) AS total_leads
  FROM TblContacts c WITH (NOLOCK)
  WHERE c.Campaign IN (SELECT DISTINCT Drop_Name FROM mkt)
  GROUP BY c.Campaign
),
enroll_agg AS (
  SELECT
    c.Campaign AS Drop_Name,
    COUNT(1)                                                                                                  AS total_enrollments,
    SUM(CASE WHEN e.Cancel_Date IS NOT NULL THEN 1 ELSE 0 END)                                               AS cancels,
    SUM(CASE WHEN e.NSF_Date    IS NOT NULL THEN 1 ELSE 0 END)                                               AS nsfs,
    SUM(COALESCE(e.Debt_Amount, 0))                                                                          AS enrolled_debt,
    SUM(CASE WHEN e.Cancel_Date IS NULL AND e.NSF_Date IS NULL THEN COALESCE(e.Debt_Amount,0) ELSE 0 END)   AS retained_debt
  FROM TblContacts c WITH (NOLOCK)
  JOIN TblEnrollment e WITH (NOLOCK) ON e.LLG_ID = c.LLG_ID
  WHERE c.Campaign IN (SELECT DISTINCT Drop_Name FROM mkt)
  GROUP BY c.Campaign
),
rep_agg AS (
  SELECT sd.Send_Date, COUNT(1) AS active_reps
  FROM (SELECT DISTINCT Send_Date FROM mkt) sd
  JOIN TblEmployees e WITH (NOLOCK)
    ON  e.Access_Level = 'Agent'
    AND e.Hire_Date   <= sd.Send_Date
    AND (e.Term_Date IS NULL OR e.Term_Date > sd.Send_Date)
  GROUP BY sd.Send_Date
)
SELECT
  {$selectExpr},
  SUM(m.Amount_Dropped)                  AS amount_dropped,
  SUM(m.Drop_Cost)                       AS drop_cost,
  SUM(m.Calls)                           AS calls,
  COALESCE(MAX(r.active_reps), 0)        AS avg_reps,
  COALESCE(SUM(l.total_leads), 0)        AS total_leads,
  COALESCE(SUM(en.total_enrollments), 0) AS total_enrollments,
  COALESCE(SUM(en.cancels), 0)           AS cancels,
  COALESCE(SUM(en.nsfs), 0)             AS nsfs,
  COALESCE(SUM(en.enrolled_debt), 0)     AS enrolled_debt,
  COALESCE(SUM(en.retained_debt), 0)     AS retained_debt
FROM mkt m
LEFT JOIN lead_agg   l  ON l.Drop_Name  = m.Drop_Name
LEFT JOIN enroll_agg en ON en.Drop_Name = m.Drop_Name
LEFT JOIN rep_agg    r  ON r.Send_Date  = m.Send_Date
GROUP BY {$groupExpr}
ORDER BY period_date";

        $rows = $this->connection()->select($sql, $params);

        $labels = $amount = $cost = $calls = $avgReps = $response = $totalLeads = [];
        $enrollments = $netEnrollments = $cancels = $nsfs = $enrolledDebt = $retainedDebt = [];

        foreach ($rows as $row) {
            $labels[]         = (string) ($row->period_date       ?? '');
            $a                = (float)  ($row->amount_dropped     ?? 0);
            $tls              = (int)    ($row->total_leads        ?? 0);
            $en               = (int)    ($row->total_enrollments  ?? 0);
            $ca               = (int)    ($row->cancels            ?? 0);
            $ns               = (int)    ($row->nsfs               ?? 0);
            $amount[]         = $a;
            $cost[]           = (float)  ($row->drop_cost          ?? 0);
            $calls[]          = (float)  ($row->calls              ?? 0);
            $avgReps[]        = (float)  ($row->avg_reps           ?? 0);
            $response[]       = $a > 0 ? ($tls / $a) * 100.0 : 0.0;
            $totalLeads[]     = $tls;
            $enrollments[]    = $en;
            $netEnrollments[] = $en - $ca - $ns;
            $cancels[]        = $ca;
            $nsfs[]           = $ns;
            $enrolledDebt[]   = (float) ($row->enrolled_debt  ?? 0);
            $retainedDebt[]   = (float) ($row->retained_debt  ?? 0);
        }

        return compact(
            'labels', 'amount', 'cost', 'calls', 'avgReps', 'response', 'totalLeads',
            'enrollments', 'netEnrollments', 'cancels', 'nsfs', 'enrolledDebt', 'retainedDebt'
        );
    }

    // ─── Nightly snapshot cache ────────────────────────────────────────────────

    private const SNAPSHOT_KEY    = 'cmdpkg:marketing_admin:snapshot';
    private const SNAPSHOT_AT_KEY = 'cmdpkg:marketing_admin:snapshot_at';
    private const SNAPSHOT_TTL    = 90000; // 25 hours

    /**
     * Run the full unfiltered aggregation query and store results in cache.
     * Called nightly by CacheMarketingAdminSnapshot command.
     */
    public function cacheSnapshot(): \Carbon\Carbon
    {
        $rows = $this->buildSnapshotRows();
        $at   = now();
        Cache::put(self::SNAPSHOT_KEY,    $rows, self::SNAPSHOT_TTL);
        Cache::put(self::SNAPSHOT_AT_KEY, $at->toIso8601String(), self::SNAPSHOT_TTL);
        return $at;
    }

    /** @return list<\stdClass>|null */
    public function getSnapshot(): ?array
    {
        $rows = Cache::get(self::SNAPSHOT_KEY);
        return is_array($rows) ? $rows : null;
    }

    public function snapshotAt(): ?\Carbon\Carbon
    {
        $val = Cache::get(self::SNAPSHOT_AT_KEY);
        return $val ? \Carbon\Carbon::parse($val) : null;
    }

    /** @return list<\stdClass> */
    private function buildSnapshotRows(): array
    {
        $sql = <<<'SQL'
WITH mkt AS (
  SELECT DISTINCT
    m.Drop_Name,
    CONVERT(date, m.Send_Date)                                     AS Send_Date,
    m.Debt_Tier, m.Drop_Type, m.Vendor, m.Data_Type, m.Mail_Style,
    COALESCE(m.Amount_Dropped, 0)                                  AS Amount_Dropped,
    COALESCE(m.Data_Drop_Cost, 0) + COALESCE(m.Mail_Drop_Cost, 0) AS Drop_Cost,
    COALESCE(m.Calls, 0)                                           AS Calls,
    CASE WHEN tmi.Drop_Name IS NOT NULL THEN 'Yes' ELSE 'No' END   AS Intent
  FROM TblMarketing m WITH (NOLOCK)
  LEFT JOIN (SELECT DISTINCT Drop_Name FROM TblmailersIntent WITH (NOLOCK)) tmi
         ON tmi.Drop_Name = m.Drop_Name
),
lead_data AS (
  SELECT
    c.Campaign                                                         AS Drop_Name,
    COUNT(1)                                                           AS total_leads,
    SUM(CASE WHEN c.Assigned_Date IS NOT NULL THEN 1 ELSE 0 END)      AS assigned_leads,
    SUM(CASE WHEN COALESCE(c.Debt_Amount, 0) >= 7500 THEN 1 ELSE 0 END) AS qualified_leads
  FROM TblContacts c WITH (NOLOCK)
  WHERE c.Campaign IN (SELECT DISTINCT Drop_Name FROM mkt)
  GROUP BY c.Campaign
),
enroll_data AS (
  SELECT
    c.Campaign                                                                                       AS Drop_Name,
    COUNT(1)                                                                                         AS total_enrollments,
    SUM(COALESCE(e.Debt_Amount, 0))                                                                  AS enrolled_debt,
    SUM(CASE WHEN e.Cancel_Date IS NOT NULL THEN 1 ELSE 0 END)                                       AS cancels,
    SUM(CASE WHEN e.NSF_Date IS NOT NULL THEN 1 ELSE 0 END)                                          AS nsfs,
    SUM(CASE WHEN e.Cancel_Date IS NULL AND e.NSF_Date IS NULL THEN COALESCE(e.Debt_Amount,0) ELSE 0 END)          AS retained_debt,
    SUM(CASE WHEN e.Cancel_Date IS NULL AND e.NSF_Date IS NULL THEN COALESCE(e.Debt_Amount,0)*0.15 ELSE 0 END)     AS veritas_enrollment_fee,
    SUM(CASE WHEN e.Cancel_Date IS NULL AND e.NSF_Date IS NULL THEN COALESCE(e.Program_Payment,0) ELSE 0 END)      AS veritas_monthly_fee
  FROM TblContacts c WITH (NOLOCK)
  JOIN TblEnrollment e WITH (NOLOCK) ON e.LLG_ID = c.LLG_ID
  WHERE c.Campaign IN (SELECT DISTINCT Drop_Name FROM mkt)
  GROUP BY c.Campaign
),
rep_data AS (
  SELECT sd.Send_Date, COUNT(1) AS active_reps
  FROM (SELECT DISTINCT Send_Date FROM mkt) sd
  JOIN TblEmployees e WITH (NOLOCK)
    ON  e.Access_Level = 'Agent'
    AND e.Hire_Date   <= sd.Send_Date
    AND (e.Term_Date IS NULL OR e.Term_Date > sd.Send_Date)
  GROUP BY sd.Send_Date
)
SELECT
  m.Drop_Name, m.Send_Date, m.Debt_Tier, m.Drop_Type, m.Vendor, m.Data_Type, m.Mail_Style, m.Intent,
  m.Amount_Dropped, m.Drop_Cost, m.Calls,
  COALESCE(l.total_leads,              0) AS total_leads,
  COALESCE(l.qualified_leads,          0) AS qualified_leads,
  COALESCE(l.assigned_leads,           0) AS assigned_leads,
  COALESCE(e.total_enrollments,        0) AS total_enrollments,
  COALESCE(e.cancels,                  0) AS cancels,
  COALESCE(e.nsfs,                     0) AS nsfs,
  COALESCE(e.enrolled_debt,            0) AS enrolled_debt,
  COALESCE(e.retained_debt,            0) AS retained_debt,
  COALESCE(e.veritas_enrollment_fee,   0) AS veritas_enrollment_fee,
  COALESCE(e.veritas_monthly_fee,      0) AS veritas_monthly_fee,
  COALESCE(r.active_reps,              0) AS active_reps
FROM mkt m
LEFT JOIN lead_data   l ON l.Drop_Name = m.Drop_Name
LEFT JOIN enroll_data e ON e.Drop_Name = m.Drop_Name
LEFT JOIN rep_data    r ON r.Send_Date  = m.Send_Date
ORDER BY m.Send_Date, m.Drop_Name
SQL;

        return $this->connection()->select($sql);
    }

    /**
     * Filter snapshot rows using the same filter logic as buildCteAndParams(),
     * but entirely in PHP. Contact-level filters (state/debt/FICO) are NOT supported.
     *
     * @param list<\stdClass>     $rows
     * @param array<string,mixed> $filters
     * @return list<\stdClass>
     */
    private function filterSnapshotRows(array $rows, array $filters): array
    {
        $drops          = array_map('strtoupper', array_filter((array) ($filters['drops']          ?? [])));
        $tiers          = array_map('strtoupper', array_filter((array) ($filters['tiers']          ?? [])));
        $vendors        = array_map('strtoupper', array_filter((array) ($filters['vendors']        ?? [])));
        $dataProviders  = array_map('strtoupper', array_filter((array) ($filters['data_providers'] ?? [])));
        $marketingTypes = array_map('strtoupper', array_filter((array) ($filters['marketing_types']?? [])));
        $mailStyles     = array_map('strtoupper', array_filter((array) ($filters['mail_styles']    ?? [])));
        $months         = array_filter(array_map('intval', (array) ($filters['months'] ?? [])), fn ($m) => $m >= 1 && $m <= 12);
        $years          = array_filter(array_map('intval', (array) ($filters['years']  ?? [])), fn ($y) => $y >= 2000 && $y <= 2100);
        $sendStart      = trim((string) ($filters['send_start'] ?? ''));
        $sendEnd        = trim((string) ($filters['send_end']   ?? ''));
        $sendEndDate    = $sendEnd !== '' ? date('Y-m-d', strtotime($sendEnd.' +1 day')) : '';
        $intent         = trim((string) ($filters['intent'] ?? 'all'));

        return array_values(array_filter($rows, function (\stdClass $r) use (
            $drops, $tiers, $vendors, $dataProviders, $marketingTypes, $mailStyles,
            $months, $years, $sendStart, $sendEndDate, $intent
        ): bool {
            $date = (string) ($r->Send_Date ?? '');

            if ($drops          && !in_array(strtoupper((string)($r->Drop_Name  ?? '')), $drops,          true)) { return false; }
            if ($tiers          && !in_array(strtoupper((string)($r->Debt_Tier  ?? '')), $tiers,          true)) { return false; }
            if ($vendors        && !in_array(strtoupper((string)($r->Vendor     ?? '')), $vendors,        true)) { return false; }
            if ($dataProviders  && !in_array(strtoupper((string)($r->Data_Type  ?? '')), $dataProviders,  true)) { return false; }
            if ($marketingTypes && !in_array(strtoupper((string)($r->Drop_Type  ?? '')), $marketingTypes, true)) { return false; }
            if ($mailStyles     && !in_array(strtoupper((string)($r->Mail_Style ?? '')), $mailStyles,     true)) { return false; }
            if ($sendStart      && $date < $sendStart)    { return false; }
            if ($sendEndDate    && $date >= $sendEndDate)  { return false; }
            if ($months) {
                $m = (int) date('n', strtotime($date));
                if (!in_array($m, $months, true)) { return false; }
            }
            if ($years) {
                $y = (int) date('Y', strtotime($date));
                if (!in_array($y, $years, true)) { return false; }
            }
            if ($intent === 'yes' && strtolower((string)($r->Intent ?? '')) !== 'yes') { return false; }
            if ($intent === 'no'  && strtolower((string)($r->Intent ?? '')) === 'yes') { return false; }

            return true;
        }));
    }

    /**
     * Build summaryAggregates object from pre-filtered snapshot rows entirely in PHP.
     *
     * @param list<\stdClass> $rows
     */
    private function aggregateSnapshotRows(array $rows): ?object
    {
        if (empty($rows)) {
            return null;
        }

        $amountDropped     = 0.0;
        $dropCosts         = 0.0;
        $totalCalls        = 0.0;
        $totalLeads        = 0;
        $qualifiedLeads    = 0;
        $assignedLeads     = 0;
        $totalEnrollments  = 0;
        $cancels           = 0;
        $nsfs              = 0;
        $enrolledDebt      = 0.0;
        $retainedDebt      = 0.0;
        $veritasEnrollment = 0.0;
        $veritasMonthly    = 0.0;

        $repsByDate = [];
        $seenDrops  = [];

        foreach ($rows as $r) {
            $amountDropped += (float) ($r->Amount_Dropped ?? 0);
            $dropCosts     += (float) ($r->Drop_Cost      ?? 0);
            $totalCalls    += (float) ($r->Calls          ?? 0);

            $date = (string) ($r->Send_Date ?? '');
            if ($date && !isset($repsByDate[$date])) {
                $repsByDate[$date] = (int) ($r->active_reps ?? 0);
            }

            // Lead/enrollment data is per-drop (not per drop+date); deduplicate by drop name
            $drop = strtoupper((string) ($r->Drop_Name ?? ''));
            if (!isset($seenDrops[$drop])) {
                $seenDrops[$drop]   = true;
                $totalLeads        += (int)   ($r->total_leads           ?? 0);
                $qualifiedLeads    += (int)   ($r->qualified_leads        ?? 0);
                $assignedLeads     += (int)   ($r->assigned_leads         ?? 0);
                $totalEnrollments  += (int)   ($r->total_enrollments      ?? 0);
                $cancels           += (int)   ($r->cancels               ?? 0);
                $nsfs              += (int)   ($r->nsfs                   ?? 0);
                $enrolledDebt      += (float) ($r->enrolled_debt          ?? 0);
                $retainedDebt      += (float) ($r->retained_debt          ?? 0);
                $veritasEnrollment += (float) ($r->veritas_enrollment_fee ?? 0);
                $veritasMonthly    += (float) ($r->veritas_monthly_fee    ?? 0);
            }
        }

        $avgReps = count($repsByDate) > 0 ? array_sum($repsByDate) / count($repsByDate) : 0.0;

        $unqualifiedLeads = $totalLeads - $qualifiedLeads;
        $netEnrollments   = $totalEnrollments - $cancels - $nsfs;
        $estRevenue       = $retainedDebt * 0.25;
        $estProfit        = $estRevenue - $dropCosts;

        return (object) [
            'amount_dropped'         => (int) $amountDropped,
            'avg_reps'               => (int) round($avgReps),
            'amount_per_rep'         => $avgReps > 0 ? (int) round($amountDropped / $avgReps) : 0,
            'cost_per_drop'          => $amountDropped > 0 ? $dropCosts / $amountDropped : 0.0,
            'total_leads'            => $totalLeads,
            'qualified_leads'        => $qualifiedLeads,
            'unqualified_leads'      => $unqualifiedLeads,
            'assigned_leads'         => $assignedLeads,
            'qualified_leads_rate'   => $totalLeads > 0 ? ($qualifiedLeads / $totalLeads) * 100 : 0.0,
            'unqualified_leads_rate' => $totalLeads > 0 ? ($unqualifiedLeads / $totalLeads) * 100 : 0.0,
            'assigned_leads_rate'    => $totalLeads > 0 ? ($assignedLeads / $totalLeads) * 100 : 0.0,
            'calls_per_rep'          => $avgReps > 0 ? $totalCalls / $avgReps : 0.0,
            'cost_per_call'          => $totalCalls > 0 ? $dropCosts / $totalCalls : 0.0,
            'cpa'                    => $totalEnrollments > 0 ? $dropCosts / $totalEnrollments : 0.0,
            'response_rate'          => $amountDropped > 0 ? ($totalLeads / $amountDropped) * 100 : 0.0,
            'drop_costs'             => $dropCosts,
            'active_deals'           => $netEnrollments,
            'conversion_rate'        => $totalLeads > 0 ? ($totalEnrollments / $totalLeads) * 100 : 0.0,
            'total_debt_enrolled'    => $enrolledDebt,
            'average_debt'           => $totalEnrollments > 0 ? $enrolledDebt / $totalEnrollments : 0.0,
            'debt_buyer_8pct'        => $enrolledDebt * 0.08,
            'veritas_enrollment_fees'=> $veritasEnrollment,
            'veritas_monthly_fees'   => $veritasMonthly,
            'total_gross_revenue'    => $estRevenue,
            'cancels'                => $cancels,
            'nsfs'                   => $nsfs,
            'net_enrollments'        => $netEnrollments,
            'retention_ratio'        => $totalEnrollments > 0 ? ($netEnrollments / $totalEnrollments) * 100 : 0.0,
            'roi'                    => $estProfit,
            'pproi'                  => $amountDropped > 0 ? $estProfit / $amountDropped : 0.0,
        ];
    }

    /**
     * @param array{total:int,with:int,without:int}
     */
    public function auditIntentCounts(): array
    {
        return Cache::remember('cmdpkg:marketing_admin:audit_intent', 33600, function () {
            return $this->fetchAuditIntentCounts();
        });
    }

    /** @return array{total:int,with:int,without:int} */
    private function fetchAuditIntentCounts(): array
    {
        $base = $this->connection();

        $totalRow = $base->selectOne("SELECT COUNT(DISTINCT Drop_Name) AS cnt FROM TblMarketing");
        $total    = (int) ($totalRow->cnt ?? 0);

        $withRow = $base->selectOne("
            SELECT COUNT(DISTINCT m.Drop_Name) AS cnt
            FROM TblMarketing m
            LEFT JOIN (SELECT DISTINCT [Drop_Name] FROM TblmailersIntent) tmi ON tmi.Drop_Name = m.Drop_Name
            WHERE tmi.Drop_Name IS NOT NULL
        ");
        $with = (int) ($withRow->cnt ?? 0);

        $withoutRow = $base->selectOne("
            SELECT COUNT(DISTINCT m.Drop_Name) AS cnt
            FROM TblMarketing m
            LEFT JOIN (SELECT DISTINCT [Drop_Name] FROM TblmailersIntent) tmi ON tmi.Drop_Name = m.Drop_Name
            WHERE tmi.Drop_Name IS NULL
        ");
        $without = (int) ($withoutRow->cnt ?? 0);

        return ['total' => $total, 'with' => $with, 'without' => $without];
    }
}
