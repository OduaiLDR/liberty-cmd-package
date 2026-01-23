<?php

namespace Cmd\Reports\Console\Commands;

use Cmd\Reports\Services\DBConnector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncVeritasTransactions extends Command
{
    protected $signature = 'Sync:veritas-transactions';

    protected $description = 'Sync Veritas eligible deposits from Snowflake to SQL Server (TblVeritasEligibleDeposits) for both LDR and PLAW';

    private string $source;

    public function handle(): int
    {
        $this->info("[INFO] Sync Veritas Transactions: starting for both LDR and PLAW.");

        // Run for LDR
        $this->info("\n" . str_repeat('=', 80));
        $this->info("SYNCING LDR");
        $this->info(str_repeat('=', 80));
        $ldrResult = $this->syncForSource('LDR');

        // Run for PLAW
        $this->info("\n" . str_repeat('=', 80));
        $this->info("SYNCING PLAW");
        $this->info(str_repeat('=', 80));
        $plawResult = $this->syncForSource('PLAW');

        $this->info("\n" . str_repeat('=', 80));
        if ($ldrResult === Command::SUCCESS && $plawResult === Command::SUCCESS) {
            $this->info('[SUCCESS] Both LDR and PLAW sync completed successfully!');
            return Command::SUCCESS;
        } else {
            $this->error('[ERROR] One or more syncs failed. Check logs for details.');
            return Command::FAILURE;
        }
    }

    private function syncForSource(string $source): int
    {
        $this->source = $source;
        $this->info("[INFO] Sync Veritas Transactions: starting for {$this->source}.");

        try {
            $snowflake = DBConnector::fromEnvironment(strtolower($this->source));
        } catch (\Throwable $e) {
            $this->error("Failed to initialize Snowflake connector for {$this->source}: " . $e->getMessage());
            Log::error('SyncVeritasTransactions: Snowflake init failed', ['source' => $this->source, 'exception' => $e]);
            return Command::FAILURE;
        }

        try {
            $sqlConnector = DBConnector::fromEnvironment(strtolower($this->source));
            $sqlConnector->initializeSqlServer();
        } catch (\Throwable $e) {
            $this->error("Failed to initialize SQL Server connector for {$this->source}: " . $e->getMessage());
            Log::error('SyncVeritasTransactions: SQL Server init failed', ['source' => $this->source, 'exception' => $e]);
            return Command::FAILURE;
        }

        $today = date('Y-m-d');

        // Fetch transactions for contacts with Veritas plans
        $sql = "
            SELECT 
                CONCAT('LLG-', t.CONTACT_ID) AS LLG_ID,
                NULL AS PAYMENT,
                TO_VARCHAR(t.PROCESS_DATE::date, 'YYYY-MM-DD') AS PROCESS_DATE,
                TO_VARCHAR(t.CLEARED_DATE::date, 'YYYY-MM-DD') AS CLEARED_DATE,
                TO_VARCHAR(t.RETURNED_DATE::date, 'YYYY-MM-DD') AS RETURNED_DATE
            FROM TRANSACTIONS AS t
            WHERE t.TRANS_TYPE = 'D'
              AND t.PROCESS_DATE <= '{$this->esc($today)}'
              AND t.CONTACT_ID IN (
                  SELECT c.ID
                  FROM CONTACTS AS c
                  LEFT JOIN ENROLLMENT_PLAN AS ep ON c.ID = ep.CONTACT_ID
                  LEFT JOIN ENROLLMENT_DEFAULTS2 AS ed ON ep.PLAN_ID = ed.ID
                  WHERE UPPER(ed.TITLE) LIKE '%WITH VERITAS%'
              )
            ORDER BY CONCAT('LLG-', t.CONTACT_ID) ASC, t.PROCESS_DATE ASC
        ";

        try {
            $result = $snowflake->query($sql);
            $rows = $result['data'] ?? [];
            $this->info("[INFO] Fetched {$this->source} Veritas transactions: " . count($rows));
        } catch (\Throwable $e) {
            $this->error("Snowflake query failed for {$this->source}: " . $e->getMessage());
            Log::error('SyncVeritasTransactions: Snowflake query failed', ['source' => $this->source, 'exception' => $e]);
            return Command::FAILURE;
        }

        if (empty($rows)) {
            $this->info("[INFO] No Veritas transactions found for {$this->source}.");
            return Command::SUCCESS;
        }

        // Calculate payment numbers (sequential cleared deposits per contact)
        $rows = $this->calculatePaymentNumbers($rows);

        // Delete existing records for this source (cleanup legacy sources)
        try {
            $deleteSql = $this->buildDeleteSql();
            $sqlConnector->querySqlServer($deleteSql);
            $deletedLabel = implode('/', $this->buildDeleteSources($this->source));
            $this->info("[INFO] Deleted existing {$deletedLabel} records from TblVeritasEligibleDeposits.");
        } catch (\Throwable $e) {
            $this->error("Failed to delete existing records for {$this->source}: " . $e->getMessage());
            Log::error('SyncVeritasTransactions: Delete failed', ['source' => $this->source, 'exception' => $e]);
            return Command::FAILURE;
        }

        // Insert in batches of 1000
        $totalRows = count($rows);
        $batchSize = 1000;
        $inserted = 0;

        for ($i = 0; $i < $totalRows; $i += $batchSize) {
            $batch = array_slice($rows, $i, $batchSize);
            
            try {
                $this->insertBatch($sqlConnector, $batch);
                $inserted += count($batch);
                $this->info("[INFO] Inserted batch: {$inserted}/{$totalRows} records for {$this->source}");
            } catch (\Throwable $e) {
                $this->error("Failed to insert batch for {$this->source}: " . $e->getMessage());
                Log::error('SyncVeritasTransactions: Insert batch failed', [
                    'source' => $this->source,
                    'batch_start' => $i,
                    'exception' => $e
                ]);
                return Command::FAILURE;
            }
        }

        $this->info("[SUCCESS] Synced {$inserted} Veritas transactions for {$this->source}.");
        
        // Log to TblLog
        $this->insertLogRow($sqlConnector, $this->source, 'SUCCESS', $inserted);
        
        return Command::SUCCESS;
    }

    private function calculatePaymentNumbers(array $rows): array
    {
        $previousLlgId = null;
        $paymentNumber = 0;

        foreach ($rows as &$row) {
            $llgId = $row['LLG_ID'] ?? '';
            $clearedDate = $row['CLEARED_DATE'] ?? '';

            // Reset counter when contact changes
            if ($llgId !== $previousLlgId) {
                $paymentNumber = 0;
                $previousLlgId = $llgId;
            }

            // Increment payment number if cleared date exists
            if (!empty($clearedDate) && $clearedDate !== null) {
                $paymentNumber++;
            }

            $row['PAYMENT'] = $paymentNumber;
        }

        return $rows;
    }

    private function insertBatch(DBConnector $sqlConnector, array $batch): void
    {
        $values = [];

        foreach ($batch as $row) {
            $llgId = $this->esc($row['LLG_ID'] ?? '');
            $payment = (int)($row['PAYMENT'] ?? 0);
            $processDate = $row['PROCESS_DATE'] ?? null;
            $clearedDate = $row['CLEARED_DATE'] ?? null;
            $returnedDate = $row['RETURNED_DATE'] ?? null;

            $processDateStr = $processDate ? "'{$this->esc($processDate)}'" : 'NULL';
            $clearedDateStr = $clearedDate ? "'{$this->esc($clearedDate)}'" : 'NULL';
            $returnedDateStr = $returnedDate ? "'{$this->esc($returnedDate)}'" : 'NULL';

            $values[] = "('{$llgId}', {$payment}, {$processDateStr}, {$clearedDateStr}, {$returnedDateStr}, '{$this->esc('DP_' . strtoupper($this->source))}')";
        }

        $valuesSql = implode(', ', $values);
        $insertSql = "
            INSERT INTO TblVeritasEligibleDeposits (LLG_ID, Payment, Process_Date, Cleared_Date, Returned_Date, Source)
            VALUES {$valuesSql}
        ";

        $sqlConnector->querySqlServer($insertSql);
    }

    private function esc(string $value): string
    {
        return str_replace("'", "''", $value);
    }

    private function buildDeleteSources(string $source): array
    {
        // Delete DP_ sources + legacy ProLaw/PLAW/LDR
        if ($source === 'PLAW') {
            return ['DP_PLAW', 'PLAW', 'ProLaw'];
        }

        if ($source === 'LDR') {
            return ['DP_LDR', 'LDR'];
        }

        return ['DP_' . strtoupper($source), $source];
    }

    private function buildDeleteSql(): string
    {
        $sources = $this->buildDeleteSources($this->source);
        $escaped = array_map(function ($value) {
            return "'" . $this->esc($value) . "'";
        }, $sources);
        $sourceList = implode(', ', $escaped);

        return "DELETE FROM TblVeritasEligibleDeposits WHERE Source IN ({$sourceList})";
    }

    private function insertLogRow(DBConnector $connector, string $source, string $status, int $recordsProcessed): void
    {
        $tableName = 'TblVeritasEligibleDeposits';
        $macro = 'SyncVeritasTransactions';
        $logSource = 'DP_' . strtoupper($source);
        
        $description = "Sync Veritas deposits for {$logSource}";
        $resultSummary = "Status={$status} Processed={$recordsProcessed}";
        $timestamp = now()->format('Y-m-d H:i:s');

        $sql = "
            DECLARE @hasPK BIT = CASE WHEN COL_LENGTH('dbo.TblLog', 'PK') IS NULL THEN 0 ELSE 1 END;
            DECLARE @isIdentity BIT = CASE WHEN COLUMNPROPERTY(OBJECT_ID('dbo.TblLog'), 'PK', 'IsIdentity') = 1 THEN 1 ELSE 0 END;
            IF @hasPK = 1 AND @isIdentity = 0
            BEGIN
                DECLARE @nextPK INT = ISNULL((SELECT MAX([PK]) FROM [dbo].[TblLog]), 0) + 1;
                INSERT INTO [dbo].[TblLog] ([PK], [Table_Name], [Macro], [Description], [Action], [Result], [Timestamp])
                VALUES (@nextPK, '{$this->esc($tableName)}', '{$this->esc($macro)}', '{$this->esc($description)}', 'SYNC_VERITAS_TRANSACTIONS', '{$this->esc($resultSummary)}', '{$this->esc($timestamp)}');
            END
            ELSE
            BEGIN
                INSERT INTO [dbo].[TblLog] ([Table_Name], [Macro], [Description], [Action], [Result], [Timestamp])
                VALUES ('{$this->esc($tableName)}', '{$this->esc($macro)}', '{$this->esc($description)}', 'SYNC_VERITAS_TRANSACTIONS', '{$this->esc($resultSummary)}', '{$this->esc($timestamp)}');
            END;
        ";

        try {
            $connector->querySqlServer($sql);
            $this->info("[INFO] Log entry inserted into TblLog for {$source}.");
        } catch (\Throwable $e) {
            Log::error('SyncVeritasTransactions: TblLog insert failed', ['source' => $source, 'exception' => $e]);
        }
    }
}
