<?php

namespace Cmd\Reports\Console\Commands;

use Cmd\Reports\Services\DBConnector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncCollectionCompanies extends Command
{
    protected $signature = 'Sync:collection-companies';

    protected $description = 'Sync collection companies from Snowflake DEBTS/CREDITORS to SQL Server TblNegotiatorAssignments (runs for both LDR and PLAW)';

    private string $source;

    public function handle(): int
    {
        $this->info("[INFO] Sync Collection Companies: starting for both LDR and PLAW.");

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
        $this->info("[INFO] Sync Collection Companies: starting for {$this->source}.");

        try {
            $snowflake = $this->initializeSnowflakeConnector();
        } catch (\Throwable $e) {
            $this->error('Failed to initialize Snowflake connector: ' . $e->getMessage());
            Log::error('SyncCollectionCompanies: Snowflake init failed', ['exception' => $e, 'source' => $this->source]);
            return Command::FAILURE;
        }

        try {
            $sqlConnector = $this->initializeSqlServerConnector();
        } catch (\Throwable $e) {
            $this->error('Failed to initialize SQL Server connector: ' . $e->getMessage());
            Log::error('SyncCollectionCompanies: SQL Server init failed', ['exception' => $e, 'source' => $this->source]);
            return Command::FAILURE;
        }

        // Step 1: Fetch debts with collection companies from Snowflake
        $this->info('[STEP 1] Fetching debts with collection companies from Snowflake...');
        $debtsData = $this->fetchDebtsWithCollectionCompanies($snowflake);
        $this->info('[INFO] Fetched ' . count($debtsData) . ' debt records');

        if (empty($debtsData)) {
            $this->warn('[WARN] No debts found. Exiting.');
            return Command::SUCCESS;
        }

        // Step 2: Fetch existing collection companies from SQL Server
        $this->info('[STEP 2] Fetching existing collection companies from SQL Server...');
        $existingData = $this->fetchExistingCollectionCompanies($sqlConnector);
        $this->info('[INFO] Fetched ' . count($existingData) . ' existing assignments');

        // Step 3: Compare and update differences
        $this->info('[STEP 3] Comparing and updating differences...');
        $updated = $this->updateCollectionCompanies($sqlConnector, $debtsData, $existingData);
        $this->info("[INFO] Updated {$updated} collection company assignments");

        $this->info("[SUCCESS] {$this->source} sync completed successfully!");
        return Command::SUCCESS;
    }

    private function fetchDebtsWithCollectionCompanies(DBConnector $snowflake): array
    {
        $sql = "
            SELECT *
            FROM (
                SELECT
                    d.CONTACT_ID,
                    d.ID,
                    c.COMPANY
                FROM DEBTS AS d
                LEFT JOIN CREDITORS AS c ON d.DEBT_BUYER = c.ID
                WHERE d.ENROLLED = 1
                  AND d.SETTLED = 0
            )
            WHERE COMPANY <> ''
        ";

        $result = $snowflake->query($sql);
        return $result['data'] ?? [];
    }

    private function fetchExistingCollectionCompanies(DBConnector $connector): array
    {
        $sql = "
            SELECT Debt_ID, Collection_Company
            FROM TblNegotiatorAssignments
        ";

        $result = $connector->querySqlServer($sql);
        
        $lookup = [];
        foreach ($result as $row) {
            $debtId = $row['Debt_ID'] ?? null;
            if ($debtId) {
                $lookup[$debtId] = trim($row['Collection_Company'] ?? '');
            }
        }

        return $lookup;
    }

    private function updateCollectionCompanies(
        DBConnector $connector,
        array $debtsData,
        array $existingData
    ): int {
        $updated = 0;
        $toUpdate = [];

        // Collect records that need updating
        foreach ($debtsData as $row) {
            $debtId = $row['ID'] ?? null;
            $newCompany = trim($row['COMPANY'] ?? '');

            if (!$debtId || $newCompany === '') {
                continue;
            }

            $existingCompany = $existingData[$debtId] ?? '';

            // Only update if different
            if ($newCompany !== $existingCompany) {
                $toUpdate[] = [
                    'debt_id' => $debtId,
                    'company' => $this->escSql($newCompany)
                ];
            }
        }

        if (empty($toUpdate)) {
            $this->info('[INFO] No updates needed.');
            return 0;
        }

        $this->info('[INFO] Found ' . count($toUpdate) . ' records to update');

        // Process in batches of 500
        $batchSize = 500;
        $batches = array_chunk($toUpdate, $batchSize);
        $totalBatches = count($batches);

        foreach ($batches as $batchIndex => $batch) {
            $this->info("[INFO] Processing batch " . ($batchIndex + 1) . " of {$totalBatches}...");

            try {
                $whenClauses = [];
                $debtIds = [];

                foreach ($batch as $item) {
                    $debtId = $item['debt_id'];
                    $company = $item['company'];
                    $whenClauses[] = "WHEN {$debtId} THEN '{$company}'";
                    $debtIds[] = $debtId;
                }

                $whenClausesStr = implode("\n                ", $whenClauses);
                $debtIdsStr = implode(',', $debtIds);

                $sql = "
                    UPDATE TblNegotiatorAssignments
                    SET Collection_Company = CASE Debt_ID
                        {$whenClausesStr}
                    END
                    WHERE Debt_ID IN ({$debtIdsStr})
                ";

                $connector->querySqlServer($sql);
                $updated += count($batch);
                $this->info("[INFO] Updated {$updated} records so far...");

            } catch (\Throwable $e) {
                $this->warn("[WARN] Batch update failed: " . $e->getMessage());
                Log::warning('SyncCollectionCompanies: Batch update failed', [
                    'batch_index' => $batchIndex,
                    'batch_size' => count($batch),
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $updated;
    }

    protected function initializeSnowflakeConnector(): DBConnector
    {
        return DBConnector::fromEnvironment(strtolower($this->source));
    }

    protected function initializeSqlServerConnector(): DBConnector
    {
        $connector = DBConnector::fromEnvironment(strtolower($this->source));
        $connector->initializeSqlServer();
        return $connector;
    }

    protected function escSql(string $value): string
    {
        return str_replace("'", "''", $value);
    }
}
