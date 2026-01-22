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
        $this->source = $source;
        $this->info("[INFO] Sync Last Deposit Date: starting for {$this->source}.");

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

        // Step 1: Fetch last deposit dates from Snowflake
        $this->info('[STEP 1] Fetching last deposit dates from Snowflake...');
        $depositData = $this->fetchLastDepositDates($snowflake);
        $this->info('[INFO] Fetched ' . count($depositData) . ' last deposit records');

        if (empty($depositData)) {
            $this->warn('[WARN] No deposit data found. Exiting.');
            return Command::SUCCESS;
        }

        // Step 2: Update TblEnrollment
        $this->info('[STEP 2] Updating TblEnrollment...');
        $updated = $this->updateLastDepositDates($sqlConnector, $depositData);
        $this->info("[INFO] Updated {$updated} records");

        $this->info("[SUCCESS] {$this->source} sync completed successfully!");
        return Command::SUCCESS;
    }

    private function fetchLastDepositDates(DBConnector $snowflake): array
    {
        $sql = "
            SELECT CONTACT_ID, CLEARED_DATE
            FROM (
                SELECT
                    t.CONTACT_ID,
                    t.CLEARED_DATE,
                    ROW_NUMBER() OVER(PARTITION BY t.CONTACT_ID ORDER BY t.CLEARED_DATE DESC) AS N
                FROM TRANSACTIONS AS t
                LEFT JOIN CONTACTS AS c ON t.CONTACT_ID = c.ID
                WHERE t.TRANS_TYPE = 'D'
                  AND t.CLEARED_DATE IS NOT NULL
                  AND t.RETURNED_DATE IS NULL
                  AND c.ENROLLED = 0
            )
            WHERE N = 1
        ";

        $result = $snowflake->query($sql);
        return $result['data'] ?? [];
    }

    private function updateLastDepositDates(DBConnector $connector, array $depositData): int
    {
        $updated = 0;

        foreach ($depositData as $row) {
            $contactId = $row['CONTACT_ID'] ?? null;
            $clearedDate = $row['CLEARED_DATE'] ?? null;

            if (!$contactId || !$clearedDate) {
                continue;
            }

            $formattedDate = $this->formatDate($clearedDate);
            if (!$formattedDate) {
                continue;
            }

            $sql = "
                UPDATE TblEnrollment
                SET Last_Deposit_Date = '{$formattedDate}'
                WHERE LLG_ID = 'LLG-{$this->escSql($contactId)}'
            ";

            try {
                $connector->querySqlServer($sql);
                $updated++;

                if ($updated % 100 === 0) {
                    $this->info("[INFO] Updated {$updated} records...");
                }
            } catch (\Throwable $e) {
                $this->warn("[WARN] Failed to update Contact_ID {$contactId}: " . $e->getMessage());
                Log::warning('SyncLastDepositDate: Update failed', [
                    'contact_id' => $contactId,
                    'cleared_date' => $clearedDate,
                    'error' => $e->getMessage()
                ]);
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
