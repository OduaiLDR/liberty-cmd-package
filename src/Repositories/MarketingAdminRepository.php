<?php

namespace Cmd\Reports\Repositories;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

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
                $this->connection()->select("SELECT TOP 500 Drop_Name FROM TblMarketing ORDER BY Send_Date DESC, Drop_Name DESC")
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
        if (!is_array($selectedDrops)) { $selectedDrops = []; }

        $whereParts = [];
        $whereParams = [];
        if (count($selectedDrops) > 0) {
            $ph = implode(',', array_fill(0, count($selectedDrops), '?'));
            $whereParts[] = 'm.Drop_Name IN ('.$ph.')';
            foreach ($selectedDrops as $d) { $whereParams[] = $d; }
        }
        $sendStart = (string) ($filters['send_start'] ?? '');
        $sendEnd = (string) ($filters['send_end'] ?? '');
        if ($sendStart !== '') { $whereParts[] = 'm.Send_Date >= ?'; $whereParams[] = $sendStart; }
        if ($sendEnd !== '') { $whereParts[] = 'm.Send_Date < ?'; $whereParams[] = date('Y-m-d', strtotime($sendEnd.' +1 day')); }
        $month = (int) ($filters['month'] ?? 0);
        $year = (int) ($filters['year'] ?? 0);
        $tier = (string) ($filters['tier'] ?? '');
        $vendor = (string) ($filters['vendor'] ?? '');
        $dataProvider = (string) ($filters['data_provider'] ?? '');
        $marketingType = (string) ($filters['marketing_type'] ?? '');
        $intent = (string) ($filters['intent'] ?? 'all');
        if ($month >= 1 && $month <= 12) { $whereParts[] = 'MONTH(m.Send_Date) = ?'; $whereParams[] = $month; }
        if ($year >= 2020 && $year <= 2100) { $whereParts[] = 'YEAR(m.Send_Date) = ?'; $whereParams[] = $year; }
        if ($tier !== '') { $whereParts[] = 'm.Debt_Tier = ?'; $whereParams[] = $tier; }
        if ($vendor !== '') { $whereParts[] = 'm.Vendor = ?'; $whereParams[] = $vendor; }
        if ($dataProvider !== '') { $whereParts[] = 'm.Data_Type = ?'; $whereParams[] = $dataProvider; }
        if ($marketingType !== '') { $whereParts[] = 'm.Drop_Type = ?'; $whereParams[] = $marketingType; }
        if ($intent === 'yes') {
            $whereParts[] = 'tmi.Drop_Name IS NOT NULL';
        } elseif ($intent === 'no') {
            $whereParts[] = 'tmi.Drop_Name IS NULL';
        }
        $whereSql = count($whereParts) ? (' WHERE '.implode(' AND ', $whereParts)) : '';

        $cteHeader = <<<SQL
WITH csum AS (
  SELECT c.Campaign, COUNT(1) AS total_leads
  FROM TblContacts c
  GROUP BY c.Campaign
), qsum AS (
  SELECT c.Campaign, COUNT(1) AS qualified_leads
  FROM TblContacts c
  WHERE c.LLG_ID IN (SELECT LLG_ID FROM TblEnrollment)
  GROUP BY c.Campaign
), esum AS (
  SELECT e.Drop_Name, COUNT(1) AS total_enrollments,
         SUM(e.Debt_Amount) AS enrolled_debt, AVG(NULLIF(e.Debt_Amount,0)) AS avg_debt,
         SUM(CASE WHEN e.Cancel_Date IS NOT NULL THEN 1 ELSE 0 END) AS cancels,
         SUM(CASE WHEN e.NSF_Date    IS NOT NULL THEN 1 ELSE 0 END) AS nsfs
  FROM TblEnrollment e
  GROUP BY e.Drop_Name
)
SQL;

        $joins = ' FROM TblMarketing m '
            .'LEFT JOIN csum ON csum.Campaign = m.Drop_Name '
            .'LEFT JOIN qsum ON qsum.Campaign = m.Drop_Name '
            .'LEFT JOIN esum ON esum.Drop_Name = m.Drop_Name '
            .'LEFT JOIN (SELECT DISTINCT [Drop_Name] FROM TblmailersIntent) tmi ON tmi.Drop_Name = m.Drop_Name';

        $selectCols = 'SELECT '
            .'m.Drop_Name AS [Drop Name], '
            .'m.Debt_Tier AS [Tier], '
            .'m.Send_Date AS [Send Date], '
            .'m.Drop_Type AS [Drop Type], '
            .'m.Vendor AS [Vendor], '
            .'m.Data_Type AS [Data Provider], '
            .'CAST(NULL AS NVARCHAR(50)) AS [Marketing Type], '
            .'m.Mail_Style AS [Mail Style], '
            .'m.Amount_Dropped AS [Amount Dropped], '
            .'(COALESCE(m.Data_Drop_Cost, 0) + COALESCE(m.Mail_Drop_Cost, 0)) AS [Drop Cost], '
            .'COALESCE(m.Calls, 0) AS [Calls], '
            .'COALESCE(csum.total_leads, 0) AS [Total Leads], '
            .'COALESCE(qsum.qualified_leads, 0) AS [Qualified Leads], '
            .'COALESCE(csum.total_leads, 0) - COALESCE(qsum.qualified_leads, 0) AS [Unqualified Leads], '
            .'CAST(0 AS INT) AS [Assigned Leads], '
            ."(SELECT COUNT(1) FROM TblEmployees e WHERE e.Access_Level = 'Agent' AND e.Hire_Date <= m.Send_Date AND (e.Term_Date IS NULL OR e.Term_Date > m.Send_Date)) AS [Active Reps], "
            .'COALESCE( NULLIF(CAST(csum.total_leads AS FLOAT),0) / NULLIF(CAST(m.Amount_Dropped AS FLOAT),0), 0) AS [Response Rate], '
            ."COALESCE( NULLIF(CAST(m.Calls AS FLOAT),0) / NULLIF(CAST((SELECT COUNT(1) FROM TblEmployees e WHERE e.Access_Level = 'Agent' AND e.Hire_Date <= m.Send_Date AND (e.Term_Date IS NULL OR e.Term_Date > m.Send_Date)) AS FLOAT),0), 0) AS [Calls Per Rep], "
            .'COALESCE( (COALESCE(m.Data_Drop_Cost,0) + COALESCE(m.Mail_Drop_Cost,0)) / NULLIF(CAST(m.Calls AS FLOAT),0), 0) AS [Cost Per Call], '
            ."COALESCE( NULLIF(CAST(m.Amount_Dropped AS FLOAT),0) / NULLIF(CAST((SELECT COUNT(1) FROM TblEmployees e WHERE e.Access_Level = 'Agent' AND e.Hire_Date <= m.Send_Date AND (e.Term_Date IS NULL OR e.Term_Date > m.Send_Date)) AS FLOAT),0), 0) AS [Amount Per Rep], "
            .'COALESCE( (COALESCE(m.Data_Drop_Cost,0) + COALESCE(m.Mail_Drop_Cost,0)) / NULLIF(CAST(m.Amount_Dropped AS FLOAT),0), 0) AS [Price Per Drop], '
            .'COALESCE(esum.total_enrollments, 0) AS [Total Enrollments], '
            .'COALESCE(esum.cancels, 0) AS [Cancels], '
            .'COALESCE(esum.nsfs, 0) AS [NSFs], '
            .'COALESCE(esum.total_enrollments, 0) - (COALESCE(esum.cancels, 0) + COALESCE(esum.nsfs, 0)) AS [Net Enrollments], '
            .'COALESCE( (COALESCE(m.Data_Drop_Cost,0) + COALESCE(m.Mail_Drop_Cost,0)) / NULLIF(CAST(esum.total_enrollments AS FLOAT),0), 0) AS [CPA], '
            .'COALESCE(esum.enrolled_debt, 0) AS [Enrolled Debt], '
            .'COALESCE(esum.avg_debt, 0) AS [Average Debt], '
            .'CAST(0 AS MONEY) AS [Veritas Enrollment], CAST(0 AS MONEY) AS [Veritas Monthly]';

        $orderSql = ' ORDER BY m.Send_Date '.($filters['dir'] === 'desc' ? 'DESC' : 'ASC').', m.Drop_Name ASC';

        $countSql = $cteHeader . ' SELECT COUNT(1) AS cnt ' . $joins . $whereSql;
        $dataSql  = $cteHeader . ' ' . $selectCols . $joins . $whereSql . $orderSql . ' OFFSET '.$offset.' ROWS FETCH NEXT '.$perPage.' ROWS ONLY';

        $totalRow = $this->connection()->selectOne($countSql, $whereParams);
        $total = $totalRow ? (int) $totalRow->cnt : 0;
        $rows = $this->connection()->select($dataSql, $whereParams);

        $finalColumns = [
            'Drop Name','Tier','Send Date','Drop Type','Vendor','Data Provider','Marketing Type','Mail Style','Amount Dropped','Drop Cost','Calls',
            'Total Leads','Qualified Leads','Unqualified Leads','Assigned Leads','Active Reps',
            'Response Rate','Calls Per Rep','Cost Per Call','Amount Per Rep','Price Per Drop',
            'Total Enrollments','Cancels','NSFs','Net Enrollments','CPA','Enrolled Debt','Average Debt',
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
