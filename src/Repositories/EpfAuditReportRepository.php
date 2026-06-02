<?php

namespace Cmd\Reports\Repositories;

class EpfAuditReportRepository extends SqlSrvRepository
{
    /**
     * Sheet 1 — EPFs from TblEPFs.
     * Pass $perPage = 0 to fetch all rows (used by Excel export).
     *
     * @return array{rows: array<int, object>, total: int}
     */
    public function getEpfs(string $cutoff, string $fromDate, int $page = 1, int $perPage = 0): array
    {
        $sql = "
            SELECT
                COUNT(1) OVER()      AS _total,
                e.Source             AS [Source],
                e.LLG_ID             AS [LLG ID],
                e.Amount             AS [Amount],
                e.Process_Date       AS [Process Date],
                e.Cleared_Date       AS [Cleared Date],
                e.Returned_Date      AS [Returned Date],
                e.Settlement_ID      AS [Settlement ID],
                e.Paid_To            AS [Paid To],
                e.Draft_Date         AS [Draft Date],
                e.Payment_Number     AS [Payment Number],
                e.Original_Amount    AS [Original Amount],
                e.Settlement_Amount  AS [Settlement Amount],
                e.Creditor_Name      AS [Creditor Name]
            FROM TblEPFs e
            WHERE e.Paid_To <> ?
              AND e.Draft_Date >= ?
              AND e.Draft_Date <  ?
            ORDER BY e.Draft_Date DESC, e.PK DESC
        ";

        $params = ['10362', $fromDate, $cutoff];
        if ($perPage > 0) {
            $sql .= ' OFFSET ? ROWS FETCH NEXT ? ROWS ONLY';
            $params[] = max(0, ($page - 1) * $perPage);
            $params[] = $perPage;
        }

        return $this->paginatedResult($this->connection()->select($sql, $params));
    }

    /**
     * Sheet 2 — Advances/deductions from TblEPFsDeductions.
     * Source is derived per-LLG via OUTER APPLY (index-friendly than correlated subquery).
     *
     * @return array{rows: array<int, object>, total: int}
     */
    public function getAdvances(string $cutoff, string $fromDate, int $page = 1, int $perPage = 0): array
    {
        $sql = "
            SELECT
                COUNT(1) OVER()  AS _total,
                src.Source       AS [Source],
                d.LLG_ID         AS [LLG ID],
                d.Amount         AS [Amount],
                d.Process_Date   AS [Process Date],
                d.Cleared_Date   AS [Cleared Date],
                d.Paid_To        AS [Paid To],
                d.Linked_To      AS [Linked To]
            FROM TblEPFsDeductions d
            OUTER APPLY (
                SELECT TOP 1 ee.Source FROM TblEPFs ee WHERE ee.LLG_ID = d.LLG_ID
            ) src
            WHERE d.Paid_To IN (?, ?)
              AND d.Process_Date >= ?
              AND d.Process_Date <  ?
            ORDER BY d.Process_Date DESC, d.PK DESC
        ";

        $params = ['27745', '35281', $fromDate, $cutoff];
        if ($perPage > 0) {
            $sql .= ' OFFSET ? ROWS FETCH NEXT ? ROWS ONLY';
            $params[] = max(0, ($page - 1) * $perPage);
            $params[] = $perPage;
        }

        return $this->paginatedResult($this->connection()->select($sql, $params));
    }

    /**
     * Sheet 3 — Enrolled clients summary from TblEnrollment.
     *
     * @return array{rows: array<int, object>, total: int}
     */
    public function getSummary(string $cutoff, string $fromDate, int $page = 1, int $perPage = 0): array
    {
        $sql = "
            SELECT
                COUNT(1) OVER()     AS _total,
                e.LLG_ID            AS [LLG ID],
                e.Client            AS [Client],
                e.Agent             AS [Assigned To],
                e.Enrollment_Plan   AS [Title],
                e.Submitted_Date    AS [Enrolled Date],
                e.State             AS [State],
                e.Cancel_Date       AS [Dropped Date],
                e.Debt_Amount       AS [Original Debt Amount],
                e.EPF_Rate          AS [EPF Rate]
            FROM TblEnrollment e
            WHERE e.Submitted_Date >= ?
              AND e.Submitted_Date <  ?
              AND (e.Client IS NULL OR UPPER(e.Client) NOT LIKE 'TEST %')
              AND (e.Client IS NULL OR UPPER(e.Client) NOT LIKE '% TEST')
              AND COALESCE(e.Client, '') <> 'Bryan Roland'
              AND COALESCE(e.Agent, 'xxx') <> 'Debt PayPro'
            ORDER BY e.Client ASC
        ";

        $params = [$fromDate, $cutoff];
        if ($perPage > 0) {
            $sql .= ' OFFSET ? ROWS FETCH NEXT ? ROWS ONLY';
            $params[] = max(0, ($page - 1) * $perPage);
            $params[] = $perPage;
        }

        return $this->paginatedResult($this->connection()->select($sql, $params));
    }

    /**
     * Strip the _total window column off each row and return {rows, total}.
     *
     * @param  array<int, object> $rows
     * @return array{rows: array<int, object>, total: int}
     */
    private function paginatedResult(array $rows): array
    {
        $total = !empty($rows) ? (int) ($rows[0]->_total ?? 0) : 0;
        foreach ($rows as $row) {
            unset($row->_total);
        }
        return ['rows' => $rows, 'total' => $total];
    }

    /** @return array<int, string> */
    public function epfColumns(): array
    {
        return [
            'Source',
            'LLG ID',
            'Amount',
            'Process Date',
            'Cleared Date',
            'Returned Date',
            'Settlement ID',
            'Paid To',
            'Draft Date',
            'Payment Number',
            'Original Amount',
            'Settlement Amount',
            'Creditor Name',
        ];
    }

    /** @return array<int, string> */
    public function advancesColumns(): array
    {
        return [
            'Source',
            'LLG ID',
            'Amount',
            'Process Date',
            'Cleared Date',
            'Paid To',
            'Linked To',
        ];
    }

    /** @return array<int, string> */
    public function summaryColumns(): array
    {
        return [
            'LLG ID',
            'Client',
            'Assigned To',
            'Title',
            'Enrolled Date',
            'State',
            'Dropped Date',
            'Original Debt Amount',
            'EPF Rate',
        ];
    }
}
