<?php

namespace Cmd\Reports\Repositories;

use Illuminate\Support\Facades\Cache;

class MarketingAdminRepository extends SqlSrvRepository
{

    /**
     * @return array<int,string>
     */
    public function listDrops(): array
    {
        return Cache::remember('cmdpkg:marketing_admin:lists:drops', 600, function () {
            return array_map(
                fn ($o) => $o->Drop_Name,
                $this->connection()->select("SELECT DISTINCT TOP 500 Drop_Name FROM TblMarketing WHERE Drop_Name IS NOT NULL ORDER BY Drop_Name")
            );
        });
    }

    /**
     * @return array<int,string>
     */
    public function listStates(): array
    {
        return Cache::remember('cmdpkg:marketing_admin:lists:states', 600, function () {
            return array_map(
                fn ($o) => $o->State,
                $this->connection()->select("SELECT DISTINCT State FROM TblContacts WHERE State IS NOT NULL AND LEN(State)=2 AND State LIKE '[A-Z][A-Z]' ORDER BY State")
            );
        });
    }

    /**
     * @return array<int,string>
     */
    public function listVendors(): array
    {
        return Cache::remember('cmdpkg:marketing_admin:lists:vendors', 600, function () {
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
        return Cache::remember('cmdpkg:marketing_admin:lists:data_providers', 600, function () {
            return array_map(
                fn ($o) => $o->Data_Type,
                $this->connection()->select("SELECT DISTINCT Data_Type FROM TblMarketing WHERE Data_Type IS NOT NULL ORDER BY Data_Type")
            );
        });
    }

    /**
     * Build summary (table) data and total count.
     * @param array<string,mixed> $filters
     * @return array{columns:array<int,string>,rows:array<int,object>,total:int,report:string}
     */
    public function summary(array $filters, int $page, int $perPage): array
    {
        $offset = ($page - 1) * $perPage;

        $selectedDrops = $filters['drops'] ?? [];
        if (is_string($selectedDrops)) {
            $selectedDrops = array_filter(array_map('trim', explode(',', $selectedDrops)));
        }
        if (!is_array($selectedDrops)) {
            $selectedDrops = [];
        }

        $marketingWhereParts = [];
        $marketingWhereParams = [];

        if (count($selectedDrops) > 0) {
            $ph = implode(',', array_fill(0, count($selectedDrops), '?'));
            $marketingWhereParts[] = 'm.Drop_Name IN ('.$ph.')';
            foreach ($selectedDrops as $dropName) {
                $marketingWhereParams[] = $dropName;
            }
        }

        $sendStart = (string) ($filters['send_start'] ?? '');
        $sendEnd = (string) ($filters['send_end'] ?? '');
        
        // PERFORMANCE FIX: Default to last 90 days if no date range specified to prevent full table scans
        if ($sendStart === '' && $sendEnd === '') {
            $sendStart = date('Y-m-d', strtotime('-90 days'));
        }
        
        if ($sendStart !== '') {
            $marketingWhereParts[] = 'm.Send_Date >= ?';
            $marketingWhereParams[] = $sendStart;
        }
        if ($sendEnd !== '') {
            $marketingWhereParts[] = 'm.Send_Date < ?';
            $marketingWhereParams[] = date('Y-m-d', strtotime($sendEnd.' +1 day'));
        }

        $month = (int) ($filters['month'] ?? 0);
        $year = (int) ($filters['year'] ?? 0);
        $tier = (string) ($filters['tier'] ?? '');
        $vendor = (string) ($filters['vendor'] ?? '');
        $dataProvider = (string) ($filters['data_provider'] ?? '');
        $marketingType = (string) ($filters['marketing_type'] ?? '');
        $intent = (string) ($filters['intent'] ?? 'all');

        if ($month >= 1 && $month <= 12) {
            $marketingWhereParts[] = 'MONTH(m.Send_Date) = ?';
            $marketingWhereParams[] = $month;
        }
        if ($year >= 2020 && $year <= 2100) {
            $marketingWhereParts[] = 'YEAR(m.Send_Date) = ?';
            $marketingWhereParams[] = $year;
        }
        if ($tier !== '') {
            $marketingWhereParts[] = 'm.Debt_Tier = ?';
            $marketingWhereParams[] = $tier;
        }
        if ($vendor !== '') {
            $marketingWhereParts[] = 'm.Vendor = ?';
            $marketingWhereParams[] = $vendor;
        }
        if ($dataProvider !== '') {
            $marketingWhereParts[] = 'm.Data_Type = ?';
            $marketingWhereParams[] = $dataProvider;
        }
        if ($marketingType !== '') {
            $marketingWhereParts[] = 'm.Drop_Type = ?';
            $marketingWhereParams[] = $marketingType;
        }
        if ($intent === 'yes') {
            $marketingWhereParts[] = 'tmi.Drop_Name IS NOT NULL';
        } elseif ($intent === 'no') {
            $marketingWhereParts[] = 'tmi.Drop_Name IS NULL';
        }

        $contactWhereParts = [];
        $contactWhereParams = [];
        $state = (string) ($filters['state'] ?? '');
        if ($state !== '') {
            $contactWhereParts[] = 'c.State = ?';
            $contactWhereParams[] = $state;
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
        $contactWhereSql = count($contactWhereParts) > 0 ? ' WHERE '.implode(' AND ', $contactWhereParts) : '';
        $resultWhereSql = count($contactWhereParts) > 0
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
  FROM TblMarketing m
  LEFT JOIN (SELECT DISTINCT [Drop_Name] FROM TblmailersIntent) tmi ON tmi.Drop_Name = m.Drop_Name
  {$marketingWhereSql}
), contact_filtered AS (
  SELECT
    f.Drop_Name,
    f.Send_Date,
    c.LLG_ID,
    c.Assigned_Date,
    COALESCE(c.Debt_Amount, 0) AS Debt_Amount
  FROM filtered f
  JOIN TblContacts c ON c.Campaign = f.Drop_Name
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
  -- QUALIFIED LEADS: Contacts meeting minimum business criteria for debt settlement
  -- Qualification = Debt Amount >= $7,500 (industry standard minimum for debt settlement programs)
  -- Note: This is DIFFERENT from enrolled clients - measures top-of-funnel lead quality
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
    -- Veritas-style economics: only count active enrollments (non-canceled, non-NSF)
    -- Enrollment fee = 15% of debt for active enrollments only
    -- Monthly fee = program payment for active enrollments only
    SUM(CASE WHEN e.Cancel_Date IS NULL AND e.NSF_Date IS NULL THEN COALESCE(e.Debt_Amount, 0) * 0.15 ELSE 0 END) AS veritas_enrollment_fee,
    SUM(CASE WHEN e.Cancel_Date IS NULL AND e.NSF_Date IS NULL THEN COALESCE(e.Program_Payment, 0) ELSE 0 END) AS veritas_monthly_fee
  FROM contact_filtered cf
  JOIN TblEnrollment e ON e.LLG_ID = cf.LLG_ID
  GROUP BY cf.Drop_Name, cf.Send_Date
), active_reps AS (
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
            // LEAD RATE: Percentage of mail drops that resulted in contact/lead (not enrollment)
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
            // CONVERSION RATE: Lead-to-enrollment funnel efficiency
            .'COALESCE(CAST(esum.total_enrollments AS FLOAT) / NULLIF(CAST(csum.total_leads AS FLOAT), 0) * 100, 0) AS [Conversion Rate %], '
            // ROI METRICS: Based on 25% revenue assumption (typical debt settlement fee-to-debt ratio)
            // WARNING: These are PROJECTED metrics - actual revenue realizes over 24-48 month program lifecycle
            // ROI Ratio = (Projected Revenue - Marketing Cost) / Marketing Cost
            .'COALESCE(CAST((esum.retained_debt * 0.25) - (f.Data_Drop_Cost + f.Mail_Drop_Cost) AS FLOAT) / NULLIF(CAST((f.Data_Drop_Cost + f.Mail_Drop_Cost) AS FLOAT), 0), 0) AS [ROI Ratio], '
            // Est Revenue = Retained Debt × 25% (industry standard fee structure)
            .'COALESCE(esum.retained_debt * 0.25, 0) AS [Est Revenue], '
            // Est Profit = Projected Revenue - Marketing Cost (does not account for operational costs or time-to-revenue)
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
            'send_date' => 'f.Send_Date',
            'drop_name' => 'f.Drop_Name',
            'tier' => 'f.Debt_Tier',
            'vendor' => 'f.Vendor',
            'drop_cost' => '(f.Data_Drop_Cost + f.Mail_Drop_Cost)',
            'calls' => 'f.Calls',
            'total_leads' => 'COALESCE(csum.total_leads, 0)',
            'total_enrollments' => 'COALESCE(esum.total_enrollments, 0)',
            'net_enrollments' => 'COALESCE(esum.total_enrollments, 0) - (COALESCE(esum.cancels, 0) + COALESCE(esum.nsfs, 0))',
            'est_profit' => 'COALESCE((esum.retained_debt * 0.25) - (f.Data_Drop_Cost + f.Mail_Drop_Cost), 0)',
            'conversion_rate' => 'COALESCE(CAST(esum.total_enrollments AS FLOAT) / NULLIF(CAST(csum.total_leads AS FLOAT), 0) * 100, 0)',
        ];

        $sortKey = strtolower((string) ($filters['sort'] ?? 'send_date'));
        $sortExpression = $sortMap[$sortKey] ?? $sortMap['send_date'];
        $sortDirection = strtolower((string) ($filters['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';

        $orderParts = [$sortExpression.' '.$sortDirection];
        if (strtolower($sortExpression) !== 'f.drop_name') {
            $orderParts[] = 'f.Drop_Name ASC';
        }
        if (strtolower($sortExpression) !== 'f.send_date') {
            $orderParts[] = 'f.Send_Date ASC';
        }

        $orderSql = ' ORDER BY '.implode(', ', $orderParts);

        // PERFORMANCE FIX: Single query with window function to get count + data in one pass
        $dataSql = $cteHeader.' '.$selectCols.$joins.$resultWhereSql.$orderSql.' OFFSET '.$offset.' ROWS FETCH NEXT '.$perPage.' ROWS ONLY';

        $rows = $this->connection()->select($dataSql, $allParams);
        $total = !empty($rows) ? (int) ($rows[0]->_total_count ?? 0) : 0;
        
        // Remove internal _total_count from result rows
        foreach ($rows as $row) {
            unset($row->_total_count);
        }

        $finalColumns = [
            'Drop Name','Tier','Send Date','Marketing Type','Vendor','Data Provider','Intent','Mail Style','Amount Dropped','Drop Cost','Calls',
            'Total Leads','Qualified Leads','Unqualified Leads','Assigned Leads','Active Reps',
            'Lead Rate','Calls Per Rep','Cost Per Call','Amount Per Rep','Price Per Drop',
            'Total Enrollments','Cancels','NSFs','Net Enrollments','CPA','Enrolled Debt','Average Debt','Conversion Rate %','ROI Ratio','Est Revenue','Est Profit','Retention Rate %','Cost Per Lead','Revenue Per Lead',
            'Veritas Enrollment','Veritas Monthly'
        ];

        return [
            'columns' => $finalColumns,
            'rows' => $rows,
            'total' => $total,
            'report' => 'marketing_admin',
        ];
    }

    /**
     * Temporary audit helper for intent counts.
     * @return array{total:int,with:int,without:int}
     */
    public function auditIntentCounts(): array
    {
        return Cache::remember('cmdpkg:marketing_admin:audit_intent', 3600, function () {
            return $this->fetchAuditIntentCounts();
        });
    }

    private function fetchAuditIntentCounts(): array
    {
        $base = $this->connection();

        $totalRow = $base->selectOne("SELECT COUNT(DISTINCT Drop_Name) AS cnt FROM TblMarketing");
        $total = (int) ($totalRow->cnt ?? 0);

        $withRow = $base->selectOne("
            SELECT COUNT(DISTINCT m.Drop_Name) AS cnt
            FROM TblMarketing m
            LEFT JOIN (
                SELECT DISTINCT [Drop_Name]
                FROM TblmailersIntent
            ) tmi ON tmi.Drop_Name = m.Drop_Name
            WHERE tmi.Drop_Name IS NOT NULL
        ");
        $with = (int) ($withRow->cnt ?? 0);

        $withoutRow = $base->selectOne("
            SELECT COUNT(DISTINCT m.Drop_Name) AS cnt
            FROM TblMarketing m
            LEFT JOIN (
                SELECT DISTINCT [Drop_Name]
                FROM TblmailersIntent
            ) tmi ON tmi.Drop_Name = m.Drop_Name
            WHERE tmi.Drop_Name IS NULL
        ");
        $without = (int) ($withoutRow->cnt ?? 0);

        return [
            'total' => $total,
            'with' => $with,
            'without' => $without,
        ];
    }
}
