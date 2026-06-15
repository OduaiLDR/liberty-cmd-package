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

        $result = $sqlConnector->querySqlServer($sql)['data'] ?? [];
        $this->info("[INFO] Found " . count($result) . " records with missing Drop_Name or State");

        $updated = 0;
        foreach ($result as $row) {
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
                $campaignResult = $sqlConnector->querySqlServer($campaignSql)['data'] ?? [];
                $campaign = $campaignResult[0]['Campaign'] ?? '';

                if ($campaign === '') {
                    $leadSql = "SELECT Drop_Name FROM TblLeads WHERE LLG_ID = '{$this->esc($llgId)}'";
                    $leadResult = $sqlConnector->querySqlServer($leadSql)['data'] ?? [];
                    $campaign = $leadResult[0]['Drop_Name'] ?? '';
                }

                if ($campaign !== '') {
                    $updates[] = "Drop_Name = '{$this->esc($campaign)}'";
                    $needsUpdate = true;
                }
            }

            // Get State if missing
            if ($state === '') {
                $stateSql = "SELECT State FROM TblContacts WHERE LLG_ID = '{$this->esc($llgId)}'";
                $stateResult = $sqlConnector->querySqlServer($stateSql)['data'] ?? [];
                $stateValue = $stateResult[0]['State'] ?? '';

                if ($stateValue === '') {
                    $leadStateSql = "SELECT State FROM TblLeads WHERE LLG_ID = '{$this->esc($llgId)}'";
                    $leadStateResult = $sqlConnector->querySqlServer($leadStateSql)['data'] ?? [];
                    $stateValue = $leadStateResult[0]['State'] ?? '';
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
        $enrollmentData = $sqlConnector->querySqlServer($sql)['data'] ?? [];
        $this->info("[INFO] Processing " . count($enrollmentData) . " enrollment records for Cancel_Date");

        // Query BOTH Snowflake sources — contacts with Category=LDR may exist in PLAW Snowflake and vice versa
        $otherSource = strtolower($this->source) === 'ldr' ? 'plaw' : 'ldr';
        $snowflakeSources = [$snowflake];
        try {
            $snowflakeSources[] = DBConnector::fromEnvironment($otherSource);
        } catch (\Throwable $e) {
            $this->warn("[WARN] Could not connect to $otherSource Snowflake: " . $e->getMessage());
        }

        $droppedMap = [];
        $statusMap  = [];

        foreach ($snowflakeSources as $sf) {
            // Primary: CONTACTS.DROPPED_DATE
            try {
                $rows = $sf->query("
                    SELECT ID, LEFT(DROPPED_DATE, 10) AS DROPPED_DATE
                    FROM CONTACTS
                    WHERE _FIVETRAN_DELETED = FALSE
                      AND DROPPED_DATE IS NOT NULL
                ")['data'] ?? [];

                foreach ($rows as $row) {
                    $id   = (string) ($row['ID'] ?? '');
                    $date = $this->normalizeSnowflakeDate($row['DROPPED_DATE'] ?? null);
                    if ($id !== '' && $date !== null && !isset($droppedMap[$id])) {
                        $droppedMap[$id] = $date;
                    }
                }
            } catch (\Throwable $e) {
                $this->warn("[WARN] CONTACTS query failed: " . $e->getMessage());
            }

            // Fallback: CONTACTS_STATUS with any cancelled status title
            try {
                $rows = $sf->query("
                    SELECT cs.CONTACT_ID, LEFT(cs.STAMP, 10) AS CANCEL_DATE
                    FROM CONTACTS_STATUS cs
                    JOIN CONTACTS_LEAD_STATUS cls ON cs.STATUS_ID = cls.ID
                    WHERE UPPER(cls.TITLE) LIKE '%CANCELLED%'
                      AND cs._FIVETRAN_DELETED = FALSE
                    QUALIFY ROW_NUMBER() OVER (PARTITION BY cs.CONTACT_ID ORDER BY cs.STAMP DESC) = 1
                ")['data'] ?? [];

                foreach ($rows as $row) {
                    $id   = (string) ($row['CONTACT_ID'] ?? '');
                    $date = $this->normalizeSnowflakeDate($row['CANCEL_DATE'] ?? null);
                    if ($id !== '' && $date !== null && !isset($statusMap[$id])) {
                        $statusMap[$id] = $date;
                    }
                }
            } catch (\Throwable $e) {
                $this->warn("[WARN] CONTACTS_STATUS query failed: " . $e->getMessage());
            }
        }

        $this->info("[INFO] Found " . count($droppedMap) . " DROPPED_DATE entries, " . count($statusMap) . " status-based cancel dates");

        $updated = 0;
        foreach ($enrollmentData as $enrollment) {
            $llgId = $enrollment['LLG_ID'] ?? null;
            $currentCancelDate = $enrollment['Cancel_Date'] ?? null;

            if (!$llgId) {
                continue;
            }

            $contactId = str_replace('LLG-', '', $llgId);

            // Use DROPPED_DATE first, fall back to status stamp
            $droppedDate = $droppedMap[$contactId] ?? $statusMap[$contactId] ?? null;

            if ($droppedDate === null) {
                continue;
            }

            // Update if Cancel_Date is empty or the new date is later
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

        $this->info("[INFO] Updated {$updated} Cancel_Date records");
    }

    /**
     * Normalize a Snowflake date value — handles ISO strings and Excel serial numbers.
     * PLAW Snowflake stores DROPPED_DATE as Excel serial integers (e.g. 20540 = 2026-03-26).
     */
    private function normalizeSnowflakeDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        // Excel serial number: integer-like value with no dashes
        if (is_numeric($value) && !str_contains((string) $value, '-')) {
            // Excel epoch is 1899-12-30
            $timestamp = mktime(0, 0, 0, 12, 30, 1899) + ((int) $value * 86400);
            return date('Y-m-d', $timestamp);
        }

        // ISO string or datetime — take first 10 chars
        $date = substr((string) $value, 0, 10);
        return strtotime($date) !== false ? $date : null;
    }

    private function updatePayments(DBConnector $snowflake, DBConnector $sqlConnector): void
    {
        // Get payment counts from BOTH Snowflake databases (LDR and PLAW)
        // Some contacts have Category=LDR but payments in PLAW or vice versa
        $snowflakeSql = "
            SELECT CONTACT_ID, COUNT(*) AS PAYMENT_COUNT
            FROM TRANSACTIONS
            WHERE TRANS_TYPE = 'D'
              AND CLEARED_DATE IS NOT NULL
              AND RETURNED_DATE IS NULL
              AND _FIVETRAN_DELETED = FALSE
            GROUP BY CONTACT_ID
        ";

        // Query current source's Snowflake
        $snowflakeResult = $snowflake->query($snowflakeSql);
        $paymentCounts = $snowflakeResult['data'] ?? [];
        $this->info("[INFO] Fetched payment counts for " . count($paymentCounts) . " contacts from {$this->source} Snowflake");

        // Query the OTHER source's Snowflake too
        $otherSource = $this->source === 'LDR' ? 'plaw' : 'ldr';
        $otherPaymentCounts = [];
        try {
            $otherSnowflake = DBConnector::fromEnvironment($otherSource);
            $otherResult = $otherSnowflake->query($snowflakeSql);
            $otherPaymentCounts = $otherResult['data'] ?? [];
            $this->info("[INFO] Fetched payment counts for " . count($otherPaymentCounts) . " contacts from " . strtoupper($otherSource) . " Snowflake");
        } catch (\Throwable $e) {
            $this->warn("[WARN] Could not query " . strtoupper($otherSource) . " Snowflake: " . $e->getMessage());
        }

        // Create lookup map - merge both sources, take the max count per contact
        $paymentsMap = [];
        foreach ($paymentCounts as $row) {
            $contactId = $row['CONTACT_ID'] ?? null;
            $count = $row['PAYMENT_COUNT'] ?? 0;
            if ($contactId) {
                $paymentsMap[$contactId] = max((int) $count, $paymentsMap[$contactId] ?? 0);
            }
        }
        foreach ($otherPaymentCounts as $row) {
            $contactId = $row['CONTACT_ID'] ?? null;
            $count = $row['PAYMENT_COUNT'] ?? 0;
            if ($contactId) {
                $paymentsMap[$contactId] = max((int) $count, $paymentsMap[$contactId] ?? 0);
            }
        }

        $this->info("[INFO] Total unique contacts with payments: " . count($paymentsMap));

        // Get current payments and payment frequency from TblEnrollment for this category
        $sql = "SELECT LLG_ID, Payments, Payment_Frequency FROM TblEnrollment WHERE Category = '{$this->esc($this->source)}'";
        $enrollmentResult = $sqlConnector->querySqlServer($sql);
        $enrollmentData = $enrollmentResult['data'] ?? [];

        // Collect updates needed
        $updates = [];
        foreach ($enrollmentData as $enrollment) {
            $llgId = $enrollment['LLG_ID'] ?? null;
            $currentPayments = (float) ($enrollment['Payments'] ?? 0);
            $paymentFrequency = trim($enrollment['Payment_Frequency'] ?? '');

            if (!$llgId) {
                continue;
            }

            // Remove LLG- prefix to get contact ID
            $contactId = str_replace('LLG-', '', $llgId);

            if (isset($paymentsMap[$contactId])) {
                $rawPaymentCount = $paymentsMap[$contactId];
                
                // Adjust payment count based on Payment_Frequency
                // Note: Check Bi-Weekly/Semi-Monthly BEFORE Weekly to avoid substring matching issue
                if (stripos($paymentFrequency, 'Bi-Weekly') !== false || stripos($paymentFrequency, 'Semi-Monthly') !== false) {
                    $adjustedPaymentCount = (int) round($rawPaymentCount / 2);
                } elseif (stripos($paymentFrequency, 'Weekly') !== false) {
                    $adjustedPaymentCount = (int) round($rawPaymentCount / 4);
                } else {
                    $adjustedPaymentCount = $rawPaymentCount;
                }

                // Only add to updates if different
                if ((int) $currentPayments !== $adjustedPaymentCount) {
                    $updates[$llgId] = $adjustedPaymentCount;
                }
            }
        }

        $this->info("[INFO] Found " . count($updates) . " records needing Payments update");

        // Batch update using CASE statement (500 at a time)
        $updated = 0;
        $chunks = array_chunk($updates, 500, true);
        
        foreach ($chunks as $chunkIndex => $chunk) {
            if (empty($chunk)) {
                continue;
            }

            $cases = [];
            $ids = [];
            foreach ($chunk as $llgId => $paymentCount) {
                $cases[] = "WHEN '{$this->esc($llgId)}' THEN {$paymentCount}";
                $ids[] = "'{$this->esc($llgId)}'";
            }

            $updateSql = "
                UPDATE TblEnrollment
                SET Payments = CASE LLG_ID " . implode(' ', $cases) . " END
                WHERE LLG_ID IN (" . implode(',', $ids) . ")
            ";
            
            $sqlConnector->querySqlServer($updateSql);
            $updated += count($chunk);
            $this->info("[INFO] Updated batch " . ($chunkIndex + 1) . " (" . count($chunk) . " records, total: {$updated})");
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
