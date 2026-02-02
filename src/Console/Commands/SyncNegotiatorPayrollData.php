<?php

namespace Cmd\Reports\Console\Commands;

use Cmd\Reports\Services\DBConnector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncNegotiatorPayrollData extends Command
{
    protected $signature = 'Sync:negotiator-payroll-data';

    protected $description = 'Sync negotiator payroll data from Snowflake to SQL Server for LDR and PLAW';

    public function handle(): int
    {
        $this->info('[INFO] Syncing negotiator payroll data: starting.');

        try {
            $sqlConnector = DBConnector::fromEnvironment('ldr');
            $sqlConnector->initializeSqlServer();
        } catch (\Throwable $e) {
            $this->error('SQL Server connection failed: ' . $e->getMessage());
            Log::error('SyncNegotiatorPayrollData: SQL Server connection failed', ['exception' => $e]);
            return Command::FAILURE;
        }

        // Calculate date range: last 24 months to capture all historical data
        // VBA uses 3 months, but we need to re-sync all legacy data
        $endDate = new \DateTime('last day of this month');
        $startDate = (clone $endDate)->modify('first day of this month')->modify('-24 months');
        $startDateString = $startDate->format('Y-m-d');
        $endDateString = $endDate->format('Y-m-d');

        $this->info("[INFO] Date range: {$startDateString} to {$endDateString}");

        // Process LDR
        $this->info('[INFO] Processing LDR data...');
        try {
            $this->syncForSource('LDR', $sqlConnector, $startDateString, $endDateString);
            $this->info('[INFO] LDR negotiator payroll data synced successfully.');
        } catch (\Throwable $e) {
            $this->error('LDR sync failed: ' . $e->getMessage());
            Log::error('SyncNegotiatorPayrollData: LDR sync failed', ['exception' => $e]);
        }

        // Process PLAW
        $this->info('[INFO] Processing PLAW data...');
        try {
            $this->syncForSource('PLAW', $sqlConnector, $startDateString, $endDateString);
            $this->info('[INFO] PLAW negotiator payroll data synced successfully.');
        } catch (\Throwable $e) {
            $this->error('PLAW sync failed: ' . $e->getMessage());
            Log::error('SyncNegotiatorPayrollData: PLAW sync failed', ['exception' => $e]);
        }

        $this->info('[INFO] Negotiator payroll data sync completed.');
        return Command::SUCCESS;
    }

    private function syncForSource(string $source, DBConnector $sqlConnector, string $startDate, string $endDate): void
    {
        $snowflake = DBConnector::fromEnvironment(strtolower($source));
        $deleteSources = $this->buildDeleteSources($source);
        $deleteSourceList = $this->implodeSourceList($deleteSources);

        // First query: EPF Summary data
        $sql1 = "
            SELECT
                CONCAT(c.FIRSTNAME,' ', c.LASTNAME) AS Contact_Name,
                t.CONTACT_ID,
                SUM(t.AMOUNT) AS Collected,
                d.SETTLEMENT_ID,
                t.MEMO,
                d.SETTLEMENT_DATE AS SDate,
                so.NEG_ID
            FROM TRANSACTIONS AS t
            JOIN TRANSACTIONS AS t1 ON (t.CONTACT_ID = t1.CONTACT_ID AND t1.TRANS_TYPE = 'S' AND t.LINKED_TO = t1.ID)
            LEFT JOIN CONTACTS AS c ON t.CONTACT_ID = c.ID
            JOIN SETTLEMENT_OFFERS AS so ON (t1.CONTACT_ID = so.CONTACT_ID AND t1.LINKED_TO = so.ID)
            JOIN DEBTS AS d ON so.DEBT_ID = d.ID
            WHERE t.TRANS_TYPE = 'PF'
              AND t.STATUS IN(0, 1, 4)
              AND so.OFFER_STATUS = 10
              AND t._FIVETRAN_DELETED = FALSE
              AND c._FIVETRAN_DELETED = FALSE
              AND so._FIVETRAN_DELETED = FALSE
              AND d._FIVETRAN_DELETED = FALSE
              AND d.SETTLEMENT_DATE BETWEEN '{$this->esc($startDate)}' AND '{$this->esc($endDate)}'
            GROUP BY CONCAT(c.FIRSTNAME,' ', c.LASTNAME), d.SETTLEMENT_DATE, d.SETTLEMENT_ID, t.MEMO, t.CONTACT_ID, so.NEG_ID
            ORDER BY so.NEG_ID ASC, d.SETTLEMENT_DATE ASC
        ";

        $result1 = $snowflake->query($sql1);
        $rows1 = $result1['data'] ?? [];
        $this->info("[INFO] {$source} EPF Summary rows: " . count($rows1));

        // Delete existing EPF Summary records for this source and date range
        $deleteSql1 = "
            DELETE FROM TblNegotiatorEPFSummary
            WHERE Settlement_Date BETWEEN '{$this->esc($startDate)}' AND '{$this->esc($endDate)}'
              AND Source IN ({$deleteSourceList})
        ";
        $deleteResult1 = $sqlConnector->querySqlServer($deleteSql1);
        if (!($deleteResult1['success'] ?? false)) {
            $this->error("[ERROR] Failed to delete EPF Summary: " . ($deleteResult1['error'] ?? 'Unknown error'));
        }

        // Insert EPF Summary data in batches
        if (!empty($rows1)) {
            $this->insertEPFSummaryBatch($sqlConnector, $rows1, $source);
        }

        // Second query: Settlement Summary data
        $sql2 = "
            SELECT
                CONCAT(c.FIRSTNAME,' ', c.LASTNAME) AS Contact_Name,
                c.ID AS LLG_ID,
                d.ORIGINAL_DEBT_AMOUNT,
                d.SETTLEMENT_ID,
                cr1.COMPANY AS Creditor_Name,
                cr2.COMPANY AS Collection_Company,
                d.SETTLEMENT_DATE,
                so.NEG_ID,
                so.CREATED_AT,
                so.SETTLEMENT_AMOUNT,
                d.ID
            FROM SETTLEMENT_OFFERS AS so
            LEFT JOIN DEBTS AS d ON so.DEBT_ID = d.ID
            LEFT JOIN CREDITORS AS cr1 ON so.CREDITOR_ID = cr1.ID
            LEFT JOIN CREDITORS AS cr2 ON d.DEBT_BUYER = cr2.ID
            LEFT JOIN CONTACTS AS c ON d.CONTACT_ID = c.ID
            WHERE so.OFFER_STATUS = 10
              AND so.CREATED_AT BETWEEN '{$this->esc($startDate)}' AND '{$this->esc($endDate)}'
        ";

        $result2 = $snowflake->query($sql2);
        $rows2 = $result2['data'] ?? [];
        $this->info("[INFO] {$source} Settlement Summary rows: " . count($rows2));

        // Delete existing Settlement Summary records for this source and date range
        $deleteSql2 = "
            DELETE FROM TblNegotiatorSettlementSummary
            WHERE Created_Date BETWEEN '{$this->esc($startDate)}' AND '{$this->esc($endDate)}'
              AND Source IN ({$deleteSourceList})
        ";
        $deleteResult2 = $sqlConnector->querySqlServer($deleteSql2);
        if (!($deleteResult2['success'] ?? false)) {
            $this->error("[ERROR] Failed to delete Settlement Summary: " . ($deleteResult2['error'] ?? 'Unknown error'));
        }

        // Insert Settlement Summary data in batches
        if (!empty($rows2)) {
            $this->insertSettlementSummaryBatch($sqlConnector, $rows2, $source);
        }
        
        // Log to TblLog
        $this->insertLogRow($sqlConnector, $source, 'SUCCESS', count($rows1), count($rows2));
    }

    private function insertEPFSummaryBatch(DBConnector $connector, array $rows, string $source): void
    {
        $batchSize = 1000;
        $batches = array_chunk($rows, $batchSize);

        foreach ($batches as $batchIndex => $batch) {
            $values = [];
            foreach ($batch as $row) {
                $contactName = $this->esc((string)($row['CONTACT_NAME'] ?? ''));
                $contactId = $this->esc((string)($row['CONTACT_ID'] ?? ''));
                $collected = $this->esc((string)($row['COLLECTED'] ?? '0'));
                $settlementId = $this->esc((string)($row['SETTLEMENT_ID'] ?? ''));
                $creditor = $this->esc((string)($row['MEMO'] ?? ''));
                $settlementDate = $this->formatDate($row['SDATE'] ?? null);
                $negotiatorId = $this->esc((string)($row['NEG_ID'] ?? ''));
                $sourceEsc = $this->esc('DP_' . strtoupper($source));

                $values[] = "('{$contactName}', '{$contactId}', '{$collected}', '{$settlementId}', '{$creditor}', {$settlementDate}, '{$negotiatorId}', '{$sourceEsc}')";
            }

            $valueString = implode(', ', $values);
            $sql = "
                INSERT INTO TblNegotiatorEPFSummary
                (Contact_Name, Contact_ID, Collected, Settlement_ID, Creditor, Settlement_Date, Negotiator_ID, Source)
                VALUES {$valueString}
            ";

            $result = $connector->querySqlServer($sql);
            if (!($result['success'] ?? false)) {
                $error = $result['error'] ?? 'Unknown error';
                $this->error("[ERROR] Failed to insert EPF Summary batch " . ($batchIndex + 1) . ": " . $error);
                throw new \Exception("EPF Summary insert failed: " . $error);
            }
            $this->info("[INFO] Inserted EPF Summary batch " . ($batchIndex + 1) . " (" . count($batch) . " rows)");
        }
    }

    private function insertSettlementSummaryBatch(DBConnector $connector, array $rows, string $source): void
    {
        $batchSize = 1000;
        $batches = array_chunk($rows, $batchSize);

        foreach ($batches as $batchIndex => $batch) {
            $values = [];
            foreach ($batch as $row) {
                $contactName = $this->esc((string)($row['CONTACT_NAME'] ?? ''));
                $contactId = $this->esc((string)($row['LLG_ID'] ?? ''));
                $debtAmount = $this->esc((string)($row['ORIGINAL_DEBT_AMOUNT'] ?? '0'));
                $settlementId = $this->esc((string)($row['SETTLEMENT_ID'] ?? ''));
                $creditor = $this->esc((string)($row['CREDITOR_NAME'] ?? ''));
                $collectionCompany = $this->esc((string)($row['COLLECTION_COMPANY'] ?? ''));
                $settlementDate = $this->formatDate($row['SETTLEMENT_DATE'] ?? null);
                $negotiatorId = $this->esc((string)($row['NEG_ID'] ?? ''));
                $createdDate = $this->formatDate($row['CREATED_AT'] ?? null);
                $settlementAmount = $this->esc((string)($row['SETTLEMENT_AMOUNT'] ?? '0'));
                $debtId = $this->esc((string)($row['ID'] ?? ''));
                $sourceEsc = $this->esc('DP_' . strtoupper($source));

                $values[] = "('{$contactName}', '{$contactId}', '{$debtAmount}', '{$settlementId}', '{$creditor}', '{$collectionCompany}', {$settlementDate}, '{$negotiatorId}', {$createdDate}, '{$settlementAmount}', '{$debtId}', '{$sourceEsc}')";
            }

            $valueString = implode(', ', $values);
            $sql = "
                INSERT INTO TblNegotiatorSettlementSummary
                (Contact_Name, Contact_ID, Debt_Amount, Settlement_ID, Creditor, Collection_Company, Settlement_Date, Negotiator_ID, Created_Date, Settlement_Amount, Debt_ID, Source)
                VALUES {$valueString}
            ";

            $result = $connector->querySqlServer($sql);
            if (!($result['success'] ?? false)) {
                $error = $result['error'] ?? 'Unknown error';
                $this->error("[ERROR] Failed to insert Settlement Summary batch " . ($batchIndex + 1) . ": " . $error);
                throw new \Exception("Settlement Summary insert failed: " . $error);
            }
            $this->info("[INFO] Inserted Settlement Summary batch " . ($batchIndex + 1) . " (" . count($batch) . " rows)");
        }
    }

    private function esc(string $value): string
    {
        return str_replace("'", "''", $value);
    }

    /**
     * Format date from Snowflake to SQL Server compatible format (YYYY-MM-DD)
     * Snowflake returns dates in two formats:
     * - Timestamp: "1760625248.000000000 1440" (unix timestamp with timezone offset in minutes)
     * - Date: "20388" (days since 1970-01-01)
     */
    private function formatDate($date): string
    {
        if ($date === null || $date === '' || $date === '0000-00-00') {
            return 'NULL';
        }
        
        $dateStr = trim((string) $date);
        
        // Check if it's a Snowflake timestamp format: "1760625248.000000000 1440"
        if (preg_match('/^(\d+)\.?\d*\s+\d+$/', $dateStr, $matches)) {
            $timestamp = (int) $matches[1];
            $dateTime = new \DateTime();
            $dateTime->setTimestamp($timestamp);
            return "'" . $dateTime->format('Y-m-d') . "'";
        }
        
        // Check if it's a Snowflake date format: days since epoch (e.g., "20388")
        if (preg_match('/^\d+$/', $dateStr) && strlen($dateStr) <= 6) {
            $days = (int) $dateStr;
            $dateTime = new \DateTime('1970-01-01');
            $dateTime->modify("+{$days} days");
            return "'" . $dateTime->format('Y-m-d') . "'";
        }
        
        // Try to parse as standard date string (YYYY-MM-DD or ISO 8601)
        try {
            $dateTime = new \DateTime($dateStr);
            return "'" . $dateTime->format('Y-m-d') . "'";
        } catch (\Exception $e) {
            // If parsing fails, try to extract just the date part (YYYY-MM-DD)
            if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $dateStr, $matches)) {
                return "'" . $matches[1] . "'";
            }
            return 'NULL';
        }
    }

    private function buildDeleteSources(string $source): array
    {
        // Delete ALL sources for this source type (legacy + DP_ prefixed)
        // This cleans up old data before inserting fresh DP_ prefixed data
        if ($source === 'PLAW') {
            return ['DP_PLAW', 'PLAW', 'ProLaw', 'prolaw'];
        }

        if ($source === 'LDR') {
            return ['DP_LDR', 'LDR', 'ldr'];
        }

        return ['DP_' . strtoupper($source), strtoupper($source), strtolower($source)];
    }

    private function implodeSourceList(array $sources): string
    {
        $escaped = array_map(function ($value) {
            return "'" . $this->esc((string) $value) . "'";
        }, $sources);

        return implode(', ', $escaped);
    }

    private function insertLogRow(DBConnector $connector, string $source, string $status, int $epfCount, int $settlementCount): void
    {
        $tableName = 'TblNegotiatorEPFSummary';
        $macro = 'SyncNegotiatorPayrollData';
        $logSource = 'DP_' . strtoupper($source);
        
        $description = "Sync negotiator payroll for {$logSource}";
        $resultSummary = "Status={$status} EPF={$epfCount} Settlement={$settlementCount}";
        $timestamp = now()->format('Y-m-d H:i:s');

        $sql = "
            DECLARE @hasPK BIT = CASE WHEN COL_LENGTH('dbo.TblLog', 'PK') IS NULL THEN 0 ELSE 1 END;
            DECLARE @isIdentity BIT = CASE WHEN COLUMNPROPERTY(OBJECT_ID('dbo.TblLog'), 'PK', 'IsIdentity') = 1 THEN 1 ELSE 0 END;
            IF @hasPK = 1 AND @isIdentity = 0
            BEGIN
                DECLARE @nextPK INT = ISNULL((SELECT MAX([PK]) FROM [dbo].[TblLog]), 0) + 1;
                INSERT INTO [dbo].[TblLog] ([PK], [Table_Name], [Macro], [Description], [Action], [Result], [Timestamp])
                VALUES (@nextPK, '{$this->esc($tableName)}', '{$this->esc($macro)}', '{$this->esc($description)}', 'SYNC_NEGOTIATOR_PAYROLL', '{$this->esc($resultSummary)}', '{$this->esc($timestamp)}');
            END
            ELSE
            BEGIN
                INSERT INTO [dbo].[TblLog] ([Table_Name], [Macro], [Description], [Action], [Result], [Timestamp])
                VALUES ('{$this->esc($tableName)}', '{$this->esc($macro)}', '{$this->esc($description)}', 'SYNC_NEGOTIATOR_PAYROLL', '{$this->esc($resultSummary)}', '{$this->esc($timestamp)}');
            END;
        ";

        try {
            $connector->querySqlServer($sql);
            $this->info("[INFO] Log entry inserted into TblLog for {$source}.");
        } catch (\Throwable $e) {
            Log::error('SyncNegotiatorPayrollData: TblLog insert failed', ['source' => $source, 'exception' => $e]);
        }
    }
}
