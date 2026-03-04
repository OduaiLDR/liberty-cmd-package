<?php

namespace Cmd\Reports\Console\Commands;

use Cmd\Reports\Services\DBConnector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncEnrollmentData extends Command
{
    protected $signature = 'Sync:enrollment-data';

    protected $description = 'Sync enrollment data: updates Drop_Name, State, Cancel_Date, and Payments from Snowflake and SQL Server sources';

    private string $source;

    public function handle(): int
    {
        $this->info("[INFO] Sync Enrollment Data: starting for both LDR and PLAW.");

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
        $this->info("[INFO] Sync Enrollment Data: starting for {$this->source}.");

        try {
            $snowflake = DBConnector::fromEnvironment(strtolower($this->source));
        } catch (\Throwable $e) {
            $this->error('Failed to initialize Snowflake connector: ' . $e->getMessage());
            Log::error('SyncEnrollmentData: Snowflake init failed', ['exception' => $e, 'source' => $this->source]);
            return Command::FAILURE;
        }

        try {
            $sqlConnector = DBConnector::fromEnvironment(strtolower($this->source));
            $sqlConnector->initializeSqlServer();
        } catch (\Throwable $e) {
            $this->error('Failed to initialize SQL Server connector: ' . $e->getMessage());
            Log::error('SyncEnrollmentData: SQL Server init failed', ['exception' => $e, 'source' => $this->source]);
            return Command::FAILURE;
        }

        // Step 1: Update missing Drop_Name and State
        $this->info('[STEP 1] Updating missing Drop_Name and State...');
        $this->updateDropNameAndState($sqlConnector);

        // Step 2: Update Cancel_Date from Snowflake
        $this->info('[STEP 2] Updating Cancel_Date from Snowflake...');
        $this->updateCancelDate($snowflake, $sqlConnector);

        // Step 3: Update Payments count from Snowflake
        $this->info('[STEP 3] Updating Payments count from Snowflake...');
        $this->updatePayments($snowflake, $sqlConnector);

        // Step 4: Update TblContacts.Campaign from TblEnrollment.Drop_Name
        $this->info('[STEP 4] Updating TblContacts.Campaign...');
        $this->updateContactsCampaign($sqlConnector);

        $this->info("[SUCCESS] {$this->source} sync completed successfully!");
        return Command::SUCCESS;
    }

    private function updateDropNameAndState(DBConnector $sqlConnector): void
    {
        // Get enrollment records with missing Drop_Name or State
        // PLAW filters by last 90 days, LDR gets all
        $dateFilter = $this->source === 'PLAW' 
            ? "AND Welcome_Call_Date > DATEADD(day, -90, GETDATE())" 
            : "";

        $sql = "
            SELECT PK, Drop_Name, LLG_ID, State, Agent, Client
            FROM TblEnrollment
            WHERE Category = '{$this->esc($this->source)}'
              AND (Drop_Name IS NULL OR Drop_Name = '' OR State IS NULL OR State = '')
              {$dateFilter}
        ";

        $result = $sqlConnector->querySqlServer($sql);
        $rows = $result['data'] ?? [];
        $this->info("[INFO] Found " . count($rows) . " records with missing Drop_Name or State");

        $updated = 0;
        foreach ($rows as $row) {
            $pk = $row['PK'] ?? null;
            $llgId = $row['LLG_ID'] ?? null;
            $dropName = trim($row['Drop_Name'] ?? '');
            $state = trim($row['State'] ?? '');

            if (!$pk || !$llgId) {
                continue;
            }

            $needsUpdate = false;
            $updates = [];

            // Get Drop_Name if missing
            if ($dropName === '') {
                $campaignSql = "SELECT Campaign FROM TblContacts WHERE LLG_ID = '{$this->esc($llgId)}'";
                $campaignResult = $sqlConnector->querySqlServer($campaignSql);
                $campaign = $campaignResult['data'][0]['Campaign'] ?? '';

                if ($campaign === '') {
                    $leadSql = "SELECT Drop_Name FROM TblLeads WHERE LLG_ID = '{$this->esc($llgId)}'";
                    $leadResult = $sqlConnector->querySqlServer($leadSql);
                    $campaign = $leadResult['data'][0]['Drop_Name'] ?? '';
                }

                if ($campaign !== '') {
                    $updates[] = "Drop_Name = '{$this->esc($campaign)}'";
                    $needsUpdate = true;
                }
            }

            // Get State if missing
            if ($state === '') {
                $stateSql = "SELECT State FROM TblContacts WHERE LLG_ID = '{$this->esc($llgId)}'";
                $stateResult = $sqlConnector->querySqlServer($stateSql);
                $stateValue = $stateResult['data'][0]['State'] ?? '';

                if ($stateValue === '') {
                    $leadStateSql = "SELECT State FROM TblLeads WHERE LLG_ID = '{$this->esc($llgId)}'";
                    $leadStateResult = $sqlConnector->querySqlServer($leadStateSql);
                    $stateValue = $leadStateResult['data'][0]['State'] ?? '';
                }

                if ($stateValue !== '') {
                    $updates[] = "State = '{$this->esc($stateValue)}'";
                    $needsUpdate = true;
                }
            }

            if ($needsUpdate && !empty($updates)) {
                $updateSql = "UPDATE TblEnrollment SET " . implode(', ', $updates) . " WHERE PK = {$pk}";
                $sqlConnector->querySqlServer($updateSql);
                $updated++;

                if ($updated % 100 === 0) {
                    $this->info("[INFO] Updated {$updated} records...");
                }
            }
        }

        $this->info("[INFO] Updated {$updated} records with Drop_Name/State");
    }

    private function updateCancelDate(DBConnector $snowflake, DBConnector $sqlConnector): void
    {
        // Get all enrollment records with LLG_ID and current Cancel_Date
        $sql = "SELECT LLG_ID, Cancel_Date FROM TblEnrollment WHERE Category = '{$this->esc($this->source)}'";
        $enrollmentResult = $sqlConnector->querySqlServer($sql);
        $enrollmentData = $enrollmentResult['data'] ?? [];
        $this->info("[INFO] Processing " . count($enrollmentData) . " enrollment records for Cancel_Date");

        // Get DROPPED_DATE from Snowflake
        $snowflakeSql = "
            SELECT ID, DROPPED_DATE
            FROM CONTACTS
            WHERE _FIVETRAN_DELETED = FALSE
              AND DROPPED_DATE IS NOT NULL
        ";
        $snowflakeResult = $snowflake->query($snowflakeSql);
        $droppedDates = $snowflakeResult['data'] ?? [];

        // Create lookup map
        $droppedMap = [];
        foreach ($droppedDates as $row) {
            $id = $row['ID'] ?? null;
            $droppedDate = $row['DROPPED_DATE'] ?? null;
            if ($id && $droppedDate) {
                $droppedMap[$id] = $droppedDate;
            }
        }

        $updated = 0;
        foreach ($enrollmentData as $enrollment) {
            $llgId = $enrollment['LLG_ID'] ?? null;
            $currentCancelDate = $enrollment['Cancel_Date'] ?? null;

            if (!$llgId) {
                continue;
            }

            // Remove LLG- prefix to get contact ID
            $contactId = str_replace('LLG-', '', $llgId);

            if (isset($droppedMap[$contactId])) {
                $droppedDate = $droppedMap[$contactId];

                // Update if Cancel_Date is empty or if dropped date is later
                if (!$currentCancelDate || (strtotime($droppedDate) > strtotime($currentCancelDate))) {
                    $updateSql = "
                        UPDATE TblEnrollment
                        SET Cancel_Date = '{$this->esc($droppedDate)}'
                        WHERE LLG_ID = '{$this->esc($llgId)}'
                    ";
                    $sqlConnector->querySqlServer($updateSql);
                    $updated++;

                    if ($updated % 100 === 0) {
                        $this->info("[INFO] Updated {$updated} Cancel_Date records...");
                    }
                }
            }
        }

        $this->info("[INFO] Updated {$updated} Cancel_Date records");
    }

    private function updatePayments(DBConnector $snowflake, DBConnector $sqlConnector): void
    {
        // Get payment counts from Snowflake
        $snowflakeSql = "
            SELECT CONTACT_ID, COUNT(*) AS PAYMENT_COUNT
            FROM TRANSACTIONS
            WHERE TRANS_TYPE = 'D'
              AND CLEARED_DATE IS NOT NULL
              AND RETURNED_DATE IS NULL
              AND _FIVETRAN_DELETED = FALSE
            GROUP BY CONTACT_ID
        ";
        $snowflakeResult = $snowflake->query($snowflakeSql);
        $paymentCounts = $snowflakeResult['data'] ?? [];

        $this->info("[INFO] Fetched payment counts for " . count($paymentCounts) . " contacts");

        // Create lookup map
        $paymentsMap = [];
        foreach ($paymentCounts as $row) {
            $contactId = $row['CONTACT_ID'] ?? null;
            $count = $row['PAYMENT_COUNT'] ?? 0;
            if ($contactId) {
                $paymentsMap[$contactId] = $count;
            }
        }

        // Get current payments and payment frequency from TblEnrollment
        $sql = "SELECT LLG_ID, Payments, Payment_Frequency FROM TblEnrollment WHERE Category = '{$this->esc($this->source)}'";
        $enrollmentResult = $sqlConnector->querySqlServer($sql);
        $enrollmentData = $enrollmentResult['data'] ?? [];

        $updated = 0;
        foreach ($enrollmentData as $enrollment) {
            $llgId = $enrollment['LLG_ID'] ?? null;
            $currentPayments = $enrollment['Payments'] ?? 0;
            $paymentFrequency = trim($enrollment['Payment_Frequency'] ?? '');

            if (!$llgId) {
                continue;
            }

            // Remove LLG- prefix to get contact ID
            $contactId = str_replace('LLG-', '', $llgId);

            if (isset($paymentsMap[$contactId])) {
                $rawPaymentCount = $paymentsMap[$contactId];
                
                // Adjust payment count based on Payment_Frequency.
                // Check bi-weekly before weekly so "Bi-Weekly" does not match "Weekly".
                if (stripos($paymentFrequency, 'Semi-Monthly') !== false || stripos($paymentFrequency, 'Bi-Weekly') !== false) {
                    // Semi-Monthly or Bi-Weekly: divide by 2
                    $adjustedPaymentCount = (int) round($rawPaymentCount / 2);
                } elseif (stripos($paymentFrequency, 'Weekly') !== false) {
                    // Weekly: divide by 4
                    $adjustedPaymentCount = (int) round($rawPaymentCount / 4);
                } else {
                    // Otherwise: full count
                    $adjustedPaymentCount = $rawPaymentCount;
                }

                // Update if different
                if ($currentPayments != $adjustedPaymentCount) {
                    $updateSql = "
                        UPDATE TblEnrollment
                        SET Payments = {$adjustedPaymentCount}
                        WHERE LLG_ID = '{$this->esc($llgId)}'
                    ";
                    $sqlConnector->querySqlServer($updateSql);
                    $updated++;

                    if ($updated % 100 === 0) {
                        $this->info("[INFO] Updated {$updated} Payments records...");
                    }
                }
            }
        }

        $this->info("[INFO] Updated {$updated} Payments records");
    }

    private function updateContactsCampaign(DBConnector $sqlConnector): void
    {
        $sql = "
            UPDATE TblContacts
            SET TblContacts.Campaign = TblEnrollment.Drop_Name
            FROM TblContacts
            INNER JOIN TblEnrollment ON TblEnrollment.LLG_ID = TblContacts.LLG_ID
            WHERE COALESCE(TblContacts.Campaign, '') = ''
              AND TblEnrollment.Category = '{$this->esc($this->source)}'
        ";

        try {
            $sqlConnector->querySqlServer($sql);
            $this->info("[INFO] Updated TblContacts.Campaign from TblEnrollment.Drop_Name");
        } catch (\Throwable $e) {
            $this->warn("[WARN] Failed to update TblContacts.Campaign: " . $e->getMessage());
            Log::warning('SyncEnrollmentData: Campaign update failed', [
                'source' => $this->source,
                'error' => $e->getMessage()
            ]);
        }
    }

    protected function esc(string $value): string
    {
        return str_replace("'", "''", $value);
    }
}
