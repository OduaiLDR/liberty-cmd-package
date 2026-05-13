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
                fn($o) => $o->Drop_Name,
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
                fn($o) => $o->State,
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
                fn($o) => $o->Vendor,
                $this->connection()->select("SELECT DISTINCT Vendor FROM TblMarketing WHERE Vendor IS NOT NULL ORDER BY Vendor")
            );
        });
    }

    /**
     * @return array<int,string>
     */
    public function listDataProviders(): array
    {
        return ['A', 'E', 'S'];
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
            $marketingWhereParts[] = 'm.Drop_Name IN (' . $ph . ')';
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
            $marketingWhereParams[] = date('Y-m-d', strtotime($sendEnd . ' +1 day'));
        }

        $month = (int) ($filters['month'] ?? 0);
        $year = (int) ($filters['year'] ?? 0);
        $tier = (string) ($filters['tier'] ?? '');
        $vendor = (string) ($filters['vendor'] ?? '');
        $dataProvider = (string) ($filters['data_provider'] ?? '');
        $marketingType = (string) ($filters['marketing_type'] ?? '');
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
            $marketingWhereParts[] = "CASE WHEN RIGHT(REPLACE(REPLACE(UPPER(m.Drop_Name), 'NAO', ''), 'AO', ''), 1) = 'A' THEN 'A' WHEN RIGHT(REPLACE(REPLACE(UPPER(m.Drop_Name), 'NAO', ''), 'AO', ''), 1) = 'E' THEN 'E' ELSE 'S' END = ?";
            $marketingWhereParams[] = $dataProvider;
        }
        if ($marketingType !== '') {
            $marketingWhereParts[] = "CASE WHEN RIGHT(m.Drop_Name, 3) = 'NAO' THEN 'NAO' WHEN RIGHT(m.Drop_Name, 2) = 'AO' THEN 'AO' ELSE 'X' END = ?";
            $marketingWhereParams[] = $marketingType;
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

        $marketingWhereSql = count($marketingWhereParts) > 0 ? ' WHERE ' . implode(' AND ', $marketingWhereParts) : '';
        $contactWhereSql = count($contactWhereParts) > 0 ? ' WHERE ' . implode(' AND ', $contactWhereParts) : '';
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
    m.Drop_Type AS actual_drop_type,
    CASE
      WHEN RIGHT(m.Drop_Name, 3) = 'NAO' THEN 'NAO'
      WHEN RIGHT(m.Drop_Name, 2) = 'AO'  THEN 'AO'
      ELSE 'X'
    END AS Marketing_Type,
    m.Vendor,
    CASE
      WHEN RIGHT(REPLACE(REPLACE(UPPER(m.Drop_Name), 'NAO', ''), 'AO', ''), 1) = 'A' THEN 'A'
      WHEN RIGHT(REPLACE(REPLACE(UPPER(m.Drop_Name), 'NAO', ''), 'AO', ''), 1) = 'E' THEN 'E'
      ELSE 'S'
    END AS Data_Type,
    m.Mail_Style,
    COALESCE(m.Amount_Dropped, 0) AS Amount_Dropped,
    COALESCE(m.Data_Drop_Cost, 0) AS Data_Drop_Cost,
    COALESCE(m.Mail_Drop_Cost, 0) AS Mail_Drop_Cost
  FROM TblMarketing m
  {$marketingWhereSql}
), contact_filtered AS (
  SELECT
    f.Drop_Name,
    f.Send_Date,
    c.LLG_ID,
    COALESCE(c.Debt_Amount, 0) AS Debt_Amount,
    c.Status,
    c.Agent
  FROM filtered f
  JOIN TblContacts c ON c.Campaign = f.Drop_Name
  {$contactWhereSql}
), csum AS (
  SELECT
    cf.Drop_Name,
    cf.Send_Date,
    COUNT(1) AS total_leads,
    SUM(CASE WHEN cf.Agent IS NOT NULL AND LEN(cf.Agent) > 0 THEN 1 ELSE 0 END) AS assigned_leads
  FROM contact_filtered cf
  GROUP BY cf.Drop_Name, cf.Send_Date
), qsum AS (
  SELECT cf.Drop_Name, cf.Send_Date, COUNT(1) AS qualified_leads
  FROM contact_filtered cf
  WHERE cf.Status NOT IN ('Rejected (Not Qualified DS)', 'Rejected (New Accounts)', 'No Credit Ran', 'Funded')
  GROUP BY cf.Drop_Name, cf.Send_Date
), uqsum AS (
  SELECT cf.Drop_Name, cf.Send_Date, COUNT(1) AS unqualified_leads
  FROM contact_filtered cf
  WHERE cf.Status IN ('Rejected (Not Qualified DS)', 'Rejected (New Accounts)')
  GROUP BY cf.Drop_Name, cf.Send_Date
), veritas_fees AS (
  SELECT LLG_ID,
         SUM(enrollment_fee)   AS enrollment_fee,
         SUM(monthly_fee_rate) AS monthly_fee_rate
  FROM (
    SELECT LLG_ID, Enrollment_Fee AS enrollment_fee, Monthly_Fee AS monthly_fee_rate
    FROM TblVeritasEnrollments
    UNION ALL
    SELECT LLG_ID, Enrollment_Fee AS enrollment_fee, Monthly_Fee AS monthly_fee_rate
    FROM TblProgressLawEnrollments
  ) v
  GROUP BY LLG_ID
), esum AS (
  SELECT
    f.Drop_Name,
    f.Send_Date,
    COUNT(1) AS total_enrollments,
    SUM(CASE WHEN e.Cancel_Date IS NOT NULL THEN 1 ELSE 0 END) AS cancels,
    SUM(CASE WHEN e.NSF_Date IS NOT NULL THEN 1 ELSE 0 END) AS nsfs,
    SUM(CASE WHEN e.Cancel_Date IS NULL AND e.NSF_Date IS NULL THEN COALESCE(e.Debt_Amount, 0) ELSE 0 END) AS enrolled_debt,
    SUM(COALESCE(e.Sold_Debt, 0) * 0.08) AS capital_partner,
    SUM(CASE WHEN e.Lookback_Date IS NOT NULL THEN COALESCE(e.Sold_Debt, 0) * 0.08 ELSE 0 END) AS lookback,
    SUM(COALESCE(e.Commission, 0)) AS commission,
    SUM(COALESCE(vf.enrollment_fee, 0)) AS veritas_enrollment_fee,
    SUM(COALESCE(vf.monthly_fee_rate, 0)) AS veritas_monthly_fee
  FROM filtered f
  JOIN TblEnrollment e ON e.Drop_Name = f.Drop_Name
  LEFT JOIN veritas_fees vf ON vf.LLG_ID = e.LLG_ID
  GROUP BY f.Drop_Name, f.Send_Date
), active_reps AS (
  SELECT
    sd.Send_Date,
    COUNT(1) AS active_reps
  FROM (SELECT DISTINCT Send_Date FROM filtered) sd
  JOIN TblEmployees e
    ON e.Access_Level = 'Agent'
   AND e.Hire_Date <= sd.Send_Date
   AND (e.Term_Date IS NULL OR e.Term_Date >= sd.Send_Date)
  GROUP BY sd.Send_Date
)
SQL;

        $net = 'COALESCE(esum.total_enrollments, 0) - COALESCE(esum.cancels, 0) - COALESCE(esum.nsfs, 0)';

        $selectCols = 'SELECT '
            . 'COUNT(1) OVER() AS _total_count, '
            . 'f.Drop_Name AS [Drop Name], '
            . 'f.Debt_Tier AS [Tier], '
            . 'f.Send_Date AS [Send Date], '
            . 'f.actual_drop_type AS [Drop Type], '
            . 'f.Vendor AS [Vendor], '
            . 'f.Data_Type AS [Data Provider], '
            . 'f.Marketing_Type AS [Marketing Type], '
            . 'f.Mail_Style AS [Mail Style], '
            . 'f.Amount_Dropped AS [Amount Dropped], '
            . '(f.Data_Drop_Cost + f.Mail_Drop_Cost) AS [Drop Cost], '
            . 'COALESCE(csum.total_leads, 0) AS [Total Leads], '
            . 'COALESCE(qsum.qualified_leads, 0) AS [Qualified Leads], '
            . 'COALESCE(uqsum.unqualified_leads, 0) AS [Unqualified Leads], '
            . 'COALESCE(csum.assigned_leads, 0) AS [Assigned Leads], '
            . 'COALESCE(ar.active_reps, 0) AS [Active Reps], '
            . 'COALESCE(CAST(f.Amount_Dropped AS FLOAT) / NULLIF(CAST(csum.total_leads AS FLOAT), 0), 0) AS [Amount Per Rep], '
            . 'COALESCE(CAST((f.Data_Drop_Cost + f.Mail_Drop_Cost) AS FLOAT) / NULLIF(CAST(f.Amount_Dropped AS FLOAT), 0), 0) AS [Price Per Drop], '
            . 'COALESCE(CAST(csum.total_leads AS FLOAT) / NULLIF(CAST(f.Amount_Dropped AS FLOAT), 0), 0) AS [Response Rate], '
            . 'COALESCE(CAST(csum.total_leads AS FLOAT) / NULLIF(CAST(ar.active_reps AS FLOAT), 0), 0) AS [Calls Per Rep], '
            . 'COALESCE(CAST((f.Data_Drop_Cost + f.Mail_Drop_Cost) AS FLOAT) / NULLIF(CAST(csum.total_leads AS FLOAT), 0), 0) AS [Cost Per Call], '
            . 'COALESCE(esum.total_enrollments, 0) AS [Total Enrollments], '
            . 'COALESCE(esum.cancels, 0) AS [Cancels], '
            . 'COALESCE(esum.nsfs, 0) AS [NSFs], '
            . "({$net}) AS [Net Enrollments], "
            . "COALESCE(CAST((f.Data_Drop_Cost + f.Mail_Drop_Cost) AS FLOAT) / NULLIF(CAST(({$net}) AS FLOAT), 0), 0) AS [CPA], "
            . 'COALESCE(esum.enrolled_debt, 0) AS [Enrolled Debt], '
            . "COALESCE(CAST(esum.enrolled_debt AS FLOAT) / NULLIF(CAST(({$net}) AS FLOAT), 0), 0) AS [Average Debt], "
            . 'COALESCE(esum.capital_partner, 0) AS [Capital Partner], '
            . 'COALESCE(esum.lookback, 0) AS [Lookback], '
            . 'COALESCE(esum.commission, 0) AS [Commission], '
            . 'COALESCE(esum.veritas_enrollment_fee, 0) AS [Veritas Enrollment], '
            . 'COALESCE(esum.veritas_monthly_fee, 0) AS [Veritas Monthly], '
            . 'COALESCE(esum.capital_partner, 0) + COALESCE(esum.veritas_enrollment_fee, 0) - (f.Data_Drop_Cost + f.Mail_Drop_Cost) - COALESCE(esum.lookback, 0) - COALESCE(esum.commission, 0) AS [ROI], '
            . 'COALESCE(CAST(esum.veritas_enrollment_fee AS FLOAT) / NULLIF(CAST(f.Amount_Dropped AS FLOAT), 0), 0) AS [Per Piece ROI], '
            . '1 AS [Visible]';

        $joins = ' FROM filtered f '
            . 'LEFT JOIN csum ON csum.Drop_Name = f.Drop_Name AND csum.Send_Date = f.Send_Date '
            . 'LEFT JOIN qsum ON qsum.Drop_Name = f.Drop_Name AND qsum.Send_Date = f.Send_Date '
            . 'LEFT JOIN uqsum ON uqsum.Drop_Name = f.Drop_Name AND uqsum.Send_Date = f.Send_Date '
            . 'LEFT JOIN esum ON esum.Drop_Name = f.Drop_Name AND esum.Send_Date = f.Send_Date '
            . 'LEFT JOIN active_reps ar ON ar.Send_Date = f.Send_Date';

        $sortMap = [
            'send_date'        => 'f.Send_Date',
            'drop_name'        => 'f.Drop_Name',
            'tier'             => 'f.Debt_Tier',
            'vendor'           => 'f.Vendor',
            'drop_cost'        => '(f.Data_Drop_Cost + f.Mail_Drop_Cost)',
            'total_leads'      => 'COALESCE(csum.total_leads, 0)',
            'total_enrollments' => 'COALESCE(esum.total_enrollments, 0)',
            'net_enrollments'  => 'COALESCE(esum.total_enrollments, 0) - COALESCE(esum.cancels, 0) - COALESCE(esum.nsfs, 0)',
            'capital_partner'  => 'COALESCE(esum.capital_partner, 0)',
            'roi'              => 'COALESCE(esum.capital_partner, 0) + COALESCE(esum.veritas_enrollment_fee, 0) - (f.Data_Drop_Cost + f.Mail_Drop_Cost) - COALESCE(esum.lookback, 0) - COALESCE(esum.commission, 0)',
        ];

        $sortKey = strtolower((string) ($filters['sort'] ?? 'send_date'));
        $sortExpression = $sortMap[$sortKey] ?? $sortMap['send_date'];
        $sortDirection = strtolower((string) ($filters['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';

        $orderParts = [$sortExpression . ' ' . $sortDirection];
        if (strtolower($sortExpression) !== 'f.drop_name') {
            $orderParts[] = 'f.Drop_Name ASC';
        }
        if (strtolower($sortExpression) !== 'f.send_date') {
            $orderParts[] = 'f.Send_Date ASC';
        }

        $orderSql = ' ORDER BY ' . implode(', ', $orderParts);

        // PERFORMANCE FIX: Single query with window function to get count + data in one pass
        $dataSql = $cteHeader . ' ' . $selectCols . $joins . $resultWhereSql . $orderSql . ' OFFSET ' . $offset . ' ROWS FETCH NEXT ' . $perPage . ' ROWS ONLY';

        $rows = $this->connection()->select($dataSql, $allParams);
        $total = !empty($rows) ? (int) ($rows[0]->_total_count ?? 0) : 0;

        // Remove internal _total_count from result rows
        foreach ($rows as $row) {
            unset($row->_total_count);
        }

        $finalColumns = [
            'Drop Name',
            'Tier',
            'Send Date',
            'Drop Type',
            'Vendor',
            'Data Provider',
            'Marketing Type',
            'Mail Style',
            'Amount Dropped',
            'Drop Cost',
            'Total Leads',
            'Qualified Leads',
            'Unqualified Leads',
            'Assigned Leads',
            'Active Reps',
            'Amount Per Rep',
            'Price Per Drop',
            'Response Rate',
            'Calls Per Rep',
            'Cost Per Call',
            'Total Enrollments',
            'Cancels',
            'NSFs',
            'Net Enrollments',
            'CPA',
            'Enrolled Debt',
            'Average Debt',
            'Capital Partner',
            'Lookback',
            'Commission',
            'Veritas Enrollment',
            'Veritas Monthly',
            'ROI',
            'Per Piece ROI',
            'Visible',
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
