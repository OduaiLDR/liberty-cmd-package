<?php

namespace Cmd\Reports\Repositories;

class EpfAuditReportRepository extends SqlSrvRepository
{
    /**
     * Sheet 1 — EPFs from TblEPFs.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getEpfs(string $cutoff, string $fromDate): array
    {
        $sql = "
            SELECT
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

        $rows = $this->connection()->select($sql, ['10362', $fromDate, $cutoff]);

        return array_map(static fn($r) => (array) $r, $rows);
    }

    /**
     * Sheet 2 — Advances/deductions from TblEPFsDeductions.
     * Source is derived per-LLG by picking any matching TblEPFs row.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAdvances(string $cutoff, string $fromDate): array
    {
        $sql = "
            SELECT
                (SELECT TOP 1 ee.Source FROM TblEPFs ee WHERE ee.LLG_ID = d.LLG_ID) AS [Source],
                d.LLG_ID         AS [LLG ID],
                d.Amount         AS [Amount],
                d.Process_Date   AS [Process Date],
                d.Cleared_Date   AS [Cleared Date],
                d.Paid_To        AS [Paid To],
                d.Linked_To      AS [Linked To]
            FROM TblEPFsDeductions d
            WHERE d.Paid_To IN (?, ?)
              AND d.Process_Date >= ?
              AND d.Process_Date <  ?
            ORDER BY d.Process_Date DESC, d.PK DESC
        ";

        $rows = $this->connection()->select($sql, ['27745', '35281', $fromDate, $cutoff]);

        return array_map(static fn($r) => (array) $r, $rows);
    }

    /**
     * Sheet 3 — Enrolled clients summary from TblEnrollment.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getSummary(string $cutoff, string $fromDate): array
    {
        $sql = "
            SELECT
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

        $rows = $this->connection()->select($sql, [$fromDate, $cutoff]);

        return array_map(static fn($r) => (array) $r, $rows);
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
