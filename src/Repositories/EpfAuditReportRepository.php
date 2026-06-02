<?php

namespace Cmd\Reports\Repositories;

use Cmd\Reports\Services\DBConnector;
use Throwable;

class EpfAuditReportRepository
{
    /** @var array<int, string> */
    private array $lastErrors = [];

    /** @return array<int, string> */
    public function lastErrors(): array
    {
        return $this->lastErrors;
    }

    /**
     * Query 1 — EPFs (TRANS_TYPE = 'PF', PAID_TO <> 10362).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getEpfs(string $cutoff, string $fromDate = ''): array
    {
        $fromClause = $fromDate !== '' ? "AND t1.CREATED_AT >= DATE '{$fromDate}'" : '';
        $sql = "
            SELECT
                t1.CONTACT_ID,
                t1.AMOUNT,
                TO_CHAR(t1.PROCESS_DATE,  'YYYY-MM-DD') AS PROCESS_DATE,
                TO_CHAR(t1.CLEARED_DATE,  'YYYY-MM-DD') AS CLEARED_DATE,
                TO_CHAR(t1.RETURNED_DATE, 'YYYY-MM-DD') AS RETURNED_DATE,
                t1.CANCELLED,
                t1.ACTIVE,
                so.DEBT_ID,
                t1.PAID_TO,
                LEFT(t1._FIVETRAN_SYNCED, 10) AS FIVETRAN_SYNCED,
                LEFT(t1.CREATED_AT, 10)      AS CREATED_AT,
                t1.TRANS_TYPE
            FROM TRANSACTIONS AS t1
            LEFT JOIN TRANSACTIONS AS t2
                ON t1.LINKED_TO = t2.ID AND t2.TRANS_TYPE = 'S'
            LEFT JOIN SETTLEMENT_OFFERS AS so
                ON t2.LINKED_TO = so.ID
            WHERE t1.TRANS_TYPE IN ('PF')
              AND t1.PAID_TO <> 10362
              AND t1.CREATED_AT < DATE '{$cutoff}'
              {$fromClause}
        ";

        return $this->runOnBoth($sql);
    }

    /**
     * Query 2 — Advances (TRANS_TYPE IN SA/RV/T/C, PAID_TO IN known IDs).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAdvances(string $cutoff, string $fromDate = ''): array
    {
        $fromClause = $fromDate !== '' ? "AND t1.CREATED_AT >= DATE '{$fromDate}'" : '';
        $sql = "
            SELECT
                t1.CONTACT_ID,
                t1.AMOUNT,
                TO_CHAR(t1.PROCESS_DATE,  'YYYY-MM-DD') AS PROCESS_DATE,
                TO_CHAR(t1.CLEARED_DATE,  'YYYY-MM-DD') AS CLEARED_DATE,
                TO_CHAR(t1.RETURNED_DATE, 'YYYY-MM-DD') AS RETURNED_DATE,
                t1.CANCELLED,
                t1.ACTIVE,
                so.DEBT_ID,
                t1.PAID_TO,
                LEFT(t1._FIVETRAN_SYNCED, 10) AS FIVETRAN_SYNCED,
                LEFT(t1.CREATED_AT, 10)      AS CREATED_AT,
                t1.TRANS_TYPE,
                t1.MEMO
            FROM TRANSACTIONS AS t1
            LEFT JOIN TRANSACTIONS AS t2
                ON t1.LINKED_TO = t2.ID AND t2.TRANS_TYPE = 'PF'
            LEFT JOIN TRANSACTIONS AS t3
                ON t2.LINKED_TO = t3.ID AND t3.TRANS_TYPE = 'S'
            LEFT JOIN SETTLEMENT_OFFERS AS so
                ON t3.LINKED_TO = so.ID
            WHERE t1.TRANS_TYPE IN ('SA', 'RV', 'T', 'C')
              AND t1.PAID_TO IN (27745, 35281)
              AND t1.CREATED_AT < DATE '{$cutoff}'
              {$fromClause}
        ";

        return $this->runOnBoth($sql);
    }

    /**
     * Query 3 — Summary (enrolled contacts + debts + creditors + EPF rate).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getSummary(string $cutoff, string $fromDate = ''): array
    {
        $fromClause = $fromDate !== '' ? "AND c.ENROLLED_DATE >= DATE '{$fromDate}'" : "AND c.ENROLLED_DATE >= DATE '2021-07-01'";
        $sql = "
            SELECT
                c.ID                                          AS LLG_ID,
                CONCAT(c.FIRSTNAME, ' ', c.LASTNAME)          AS CLIENT,
                CONCAT(u.FIRSTNAME, ' ', u.LASTNAME)          AS ASSIGNED_TO,
                ed.TITLE,
                TO_CHAR(c.ENROLLED_DATE,   'YYYY-MM-DD')      AS ENROLLED_DATE,
                c.STATE,
                TO_CHAR(c.DROPPED_DATE,    'YYYY-MM-DD')      AS DROPPED_DATE,
                d.ID                                          AS DEBT_PK,
                cr.COMPANY,
                TO_CHAR(d.SETTLEMENT_DATE, 'YYYY-MM-DD')      AS SETTLEMENT_DATE,
                d.SETTLEMENT_ID,
                ep.FEE1                                       AS EPF_RATE,
                d.ORIGINAL_DEBT_AMOUNT
            FROM CONTACTS AS c
            LEFT JOIN DEBTS              AS d  ON c.ID = d.CONTACT_ID
            LEFT JOIN USERS              AS u  ON c.ASSIGNED_TO = u.UID
            LEFT JOIN ENROLLMENT_PLAN    AS ep ON c.ID = ep.CONTACT_ID
            LEFT JOIN ENROLLMENT_DEFAULTS2 AS ed ON ep.PLAN_ID = ed.ID
            LEFT JOIN CREDITORS          AS cr ON d.CREDITOR_ID = cr.ID
            WHERE ep.CONTACT_ID IN (SELECT ID FROM CONTACTS)
              AND d.ENROLLED = 1
              AND c.DEL = 0
              AND UPPER(c.FIRSTNAME) <> 'TEST'
              AND UPPER(c.LASTNAME) <> 'TEST'
              AND CONCAT(c.FIRSTNAME, ' ', c.LASTNAME) <> 'Bryan Roland'
              AND COALESCE(CONCAT(u.FIRSTNAME, ' ', u.LASTNAME), 'xxx') <> 'Debt PayPro'
              {$fromClause}
              AND c.ENROLLED_DATE < DATE '{$cutoff}'
            ORDER BY c.LASTNAME ASC, c.FIRSTNAME ASC
        ";

        return $this->runOnBoth($sql);
    }

    /** @return array<int, string> */
    public function epfColumns(): array
    {
        return [
            'Source',
            'Contact ID',
            'Amount',
            'Process Date',
            'Cleared Date',
            'Returned Date',
            'Cancelled',
            'Active',
            'Debt ID',
            'Paid To',
            'Fivetran Synced',
            'Created At',
            'Trans Type',
        ];
    }

    /** @return array<int, string> */
    public function advancesColumns(): array
    {
        return [
            'Source',
            'Contact ID',
            'Amount',
            'Process Date',
            'Cleared Date',
            'Returned Date',
            'Cancelled',
            'Active',
            'Debt ID',
            'Paid To',
            'Fivetran Synced',
            'Created At',
            'Trans Type',
            'Memo',
        ];
    }

    /** @return array<int, string> */
    public function summaryColumns(): array
    {
        return [
            'Source',
            'LLG ID',
            'Client',
            'Assigned To',
            'Title',
            'Enrolled Date',
            'State',
            'Dropped Date',
            'Debt PK',
            'Company',
            'Settlement Date',
            'Settlement ID',
            'EPF Rate',
            'Original Debt Amount',
        ];
    }

    /**
     * Run the given SQL against both LDR and PLAW Snowflake environments,
     * prefixing each row with its Source.
     *
     * @return array<int, array<string, mixed>>
     */
    private function runOnBoth(string $sql): array
    {
        $tagged = [];

        foreach (['LDR' => 'ldr', 'PLAW' => 'plaw'] as $label => $env) {
            try {
                $rows = DBConnector::fromEnvironment($env)->query($sql)['data'] ?? [];
                foreach ($rows as $row) {
                    $tagged[] = ['SOURCE' => $label] + $row;
                }
            } catch (Throwable $e) {
                $this->lastErrors[] = $label . ': ' . $e->getMessage();
            }
        }

        return $tagged;
    }
}
