<?php

namespace Cmd\Reports\Console\Commands;

use Cmd\Reports\Services\DBConnector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncLastDepositDate extends Command
{
    protected $signature = 'Sync:last-deposit-date';

    protected $description = 'Sync Last_Deposit_Date in TblEnrollment from Snowflake TRANSACTIONS (most recent cleared deposit for non-enrolled contacts)';

    private string $source;
    private string $category; // 'LDR' for LDR source, 'CCS' for PLAW source

    public function handle(): int
    {
        $this->info("[INFO] Sync Last Deposit Date: starting for both LDR and PLAW.");

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
        $this->source   = $source;
        // PLAW contacts are stored as Category='CCS' in TblEnrollment — never 'PLAW'
        $this->category = ($source === 'PLAW') ? 'CCS' : 'LDR';
        $this->info("[INFO] Sync Last Deposit Date: starting for {$this->source} (Category={$this->category}).");

        try {
            $snowflake = $this->initializeSnowflakeConnector();
        } catch (\Throwable $e) {
            $this->error('Failed to initialize Snowflake connector: ' . $e->getMessage());
            Log::error('SyncLastDepositDate: Snowflake init failed', ['exception' => $e, 'source' => $this->source]);
            return Command::FAILURE;
        }

        try {
            $sqlConnector = $this->initializeSqlServerConnector();
        } catch (\Throwable $e) {
            $this->error('Failed to initialize SQL Server connector: ' . $e->getMessage());
            Log::error('SyncLastDepositDate: SQL Server init failed', ['exception' => $e, 'source' => $this->source]);
            return Command::FAILURE;
        }

        // Step 1: Get contact IDs that need updating from Azure SQL
        $this->info('[STEP 1] Fetching contact IDs missing Last_Deposit_Date from Azure SQL...');
        $contactIds = $this->fetchContactIdsNeedingUpdate($sqlConnector);
        $this->info('[INFO] Found ' . count($contactIds) . ' contacts needing Last_Deposit_Date update');

        if (empty($contactIds)) {
            $this->info('[INFO] No contacts need updating. Exiting.');
            return Command::SUCCESS;
        }

        // Step 2: Fetch last deposit dates from Snowflake for only those contacts
        $this->info('[STEP 2] Fetching last deposit dates from Snowflake...');
        $depositData = $this->fetchLastDepositDates($snowflake, $contactIds);
        $this->info('[INFO] Fetched ' . count($depositData) . ' last deposit records from Snowflake');

        if (empty($depositData)) {
            $this->warn('[WARN] No deposit data found in Snowflake. Exiting.');
            return Command::SUCCESS;
        }

        // Step 3: Batch update TblEnrollment (500 rows per query)
        $this->info('[STEP 3] Updating TblEnrollment in batches...');
        $updated = $this->updateLastDepositDates($sqlConnector, $depositData);
        $this->info("[INFO] Updated {$updated} records");

        $this->info("[SUCCESS] {$this->source} sync completed successfully!");
        return Command::SUCCESS;
    }

    private function fetchContactIdsNeedingUpdate(DBConnector $connector): array
    {
        $sql = "
            SELECT LTRIM(RTRIM(REPLACE(LLG_ID, 'LLG-', ''))) AS CONTACT_ID
            FROM TblEnrollment
            WHERE Category = '{$this->category}'
              AND Last_Deposit_Date IS NULL
              AND First_Payment_Cleared_Date IS NOT NULL
              AND COALESCE(Payments, 0) > 0
        ";

        $result = $connector->querySqlServer($sql);
        $rows   = $result['data'] ?? (array_is_list($result) ? $result : []);

        $ids = [];
        foreach ($rows as $row) {
            $cid = $row['CONTACT_ID'] ?? null;
            if ($cid !== null && $cid !== '') {
                $ids[] = (string) $cid;
            }
        }
        return $ids;
    }

    private function fetchLastDepositDates(DBConnector $snowflake, array $contactIds): array
    {
        $all    = [];
        $chunks = array_chunk($contactIds, 500);
        $total  = count($chunks);

        foreach ($chunks as $i => $chunk) {
            $inList = implode(',', array_map(fn($id) => "'" . str_replace("'", "''", $id) . "'", $chunk));
            $sql    = "
                SELECT CONTACT_ID, TO_VARCHAR(MAX(CLEARED_DATE), 'YYYY-MM-DD') AS CLEARED_DATE
                FROM TRANSACTIONS
                WHERE CONTACT_ID IN ({$inList})
                  AND TRANS_TYPE = 'D'
                  AND CLEARED_DATE IS NOT NULL
                  AND RETURNED_DATE IS NULL
                GROUP BY CONTACT_ID
            ";

            try {
                $result = $snowflake->query($sql);
                $rows   = $result['data'] ?? (is_array($result) && array_is_list($result) ? $result : []);
                foreach ($rows as $row) {
                    $cid = $row['CONTACT_ID'] ?? null;
                    if ($cid !== null) {
                        $all[$cid] = $row;
                    }
                }
                if (($i + 1) % 10 === 0) {
                    $this->info("[INFO] Snowflake: processed chunk " . ($i + 1) . "/{$total}");
                }
            } catch (\Throwable $e) {
                $this->warn("[WARN] Snowflake chunk " . ($i + 1) . " failed: " . $e->getMessage());
                Log::warning('SyncLastDepositDate: Snowflake chunk failed', ['error' => $e->getMessage()]);
            }
        }

        return array_values($all);
    }

    private function updateLastDepositDates(DBConnector $connector, array $depositData): int
    {
        // Build map: contactId => formattedDate (skip any with unparseable dates)
        $map = [];
        foreach ($depositData as $row) {
            $contactId = $row['CONTACT_ID'] ?? null;
            $date      = $this->formatDate($row['CLEARED_DATE'] ?? null);
            if ($contactId && $date) {
                $map[(string) $contactId] = $date;
            }
        }

        if (empty($map)) {
            return 0;
        }

        $updated = 0;
        $batches = array_chunk($map, 500, true);

        foreach ($batches as $batch) {
            $caseWhen = '';
            $inList   = [];

            foreach ($batch as $contactId => $date) {
                $escaped   = $this->escSql($contactId);
                $caseWhen .= "WHEN 'LLG-{$escaped}' THEN '{$date}' ";
                $inList[]  = "'LLG-{$escaped}'";
            }

            $sql = "
                UPDATE TblEnrollment
                SET Last_Deposit_Date = CASE LLG_ID {$caseWhen} END
                WHERE LLG_ID IN (" . implode(',', $inList) . ")
                  AND Category = '{$this->category}'
            ";

            try {
                $connector->querySqlServer($sql);
                $updated += count($batch);
                $this->info("[INFO] Updated {$updated} records so far...");
            } catch (\Throwable $e) {
                $this->warn("[WARN] Batch update failed: " . $e->getMessage());
                Log::warning('SyncLastDepositDate: Batch update failed', ['error' => $e->getMessage()]);
            }
        }

        return $updated;
    }

    private function formatDate($value): ?string
    {
        if (empty($value)) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        try {
            $dt = new \DateTime($value);
            return $dt->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return null;
        }
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
