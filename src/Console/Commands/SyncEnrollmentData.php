<?php

namespace Cmd\Reports\Console\Commands;

use Cmd\Reports\Services\DBConnector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncEnrollmentData extends Command
{
    protected $signature = 'Sync:enrollment-data {--payments-only : Update only the Payments count} {--dry-run : Preview changes without writing to SQL Server}';

    protected $description = 'Sync enrollment data: updates Drop_Name, State, Cancel_Date, and Payments from Snowflake (no inserts — use enrollment:import-missing for new rows)';

    private string $source;
    private string $category;
    private bool $paymentsOnly = false;
    private bool $dryRun = false;

    public function handle(): int
    {
        $this->paymentsOnly = (bool) $this->option('payments-only');
        $this->dryRun = (bool) $this->option('dry-run');
        // A dry run is always limited to payment counts, never the other enrollment sync steps.
        $this->paymentsOnly = $this->paymentsOnly || $this->dryRun;
        $mode = $this->paymentsOnly ? 'Payments only' : 'full enrollment sync';
        $mode .= $this->dryRun ? ' (DRY RUN — no SQL writes)' : '';
        $this->info("[INFO] Sync Enrollment Data: starting for both LDR and PLAW — {$mode}.");

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
        // PLAW Snowflake contacts are stored as Category='CCS' in TblEnrollment (never 'PLAW')
        $this->category = ($source === 'PLAW') ? 'CCS' : 'LDR';
        $this->info("[INFO] Sync Enrollment Data: starting for {$this->source} (Category={$this->category}).");

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

        if (!$this->paymentsOnly) {
            // Step 1: Update missing Drop_Name and State
            $this->info('[STEP 1] Updating missing Drop_Name and State...');
            $this->updateDropNameAndState($sqlConnector);

            // Step 2: Update Cancel_Date from Snowflake
            $this->info('[STEP 2] Updating Cancel_Date from Snowflake...');
            $this->updateCancelDate($snowflake, $sqlConnector);
        }

        // Step 3: Update Payments count from Snowflake
        $this->info('[STEP 3] Updating Payments count from Snowflake...');
        $this->updatePayments($snowflake, $sqlConnector, $this->dryRun);

        if (!$this->paymentsOnly) {
            // Step 4: Update TblContacts.Campaign from TblEnrollment.Drop_Name
            $this->info('[STEP 4] Updating TblContacts.Campaign...');
            $this->updateContactsCampaign($sqlConnector);
        }

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
            WHERE Category = '{$this->esc($this->category)}'
              AND (Drop_Name IS NULL OR Drop_Name = '' OR State IS NULL OR State = '')
              {$dateFilter}
        ";

        $rawResult = $sqlConnector->querySqlServer($sql);
        $result = $rawResult['data'] ?? (array_is_list($rawResult) ? $rawResult : []);
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
                $campaignResult = $sqlConnector->querySqlServer($campaignSql);
                $campaign = $campaignResult[0]['Campaign'] ?? '';

                if ($campaign === '') {
                    $leadSql = "SELECT Drop_Name FROM TblLeads WHERE LLG_ID = '{$this->esc($llgId)}'";
                    $leadResult = $sqlConnector->querySqlServer($leadSql);
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
                $stateResult = $sqlConnector->querySqlServer($stateSql);
                $stateValue = $stateResult[0]['State'] ?? '';

                if ($stateValue === '') {
                    $leadStateSql = "SELECT State FROM TblLeads WHERE LLG_ID = '{$this->esc($llgId)}'";
                    $leadStateResult = $sqlConnector->querySqlServer($leadStateSql);
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
        $sql = "SELECT LLG_ID, Cancel_Date FROM TblEnrollment WHERE Category = '{$this->esc($this->category)}'";
        $enrollmentData = $sqlConnector->querySqlServer($sql);
        $this->info("[INFO] Processing " . count($enrollmentData) . " enrollment records for Cancel_Date");

        // Get DROPPED_DATE from Snowflake
        $snowflakeSql = "
            SELECT ID, CAST(CONVERT_TIMEZONE('America/Los_Angeles', DROPPED_DATE) AS DATE) AS DROPPED_DATE
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

    private function updatePayments(DBConnector $snowflake, DBConnector $sqlConnector, bool $dryRun = false): void
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
        $bothSourcesLoaded = false;
        try {
            $otherSnowflake = DBConnector::fromEnvironment($otherSource);
            $otherResult = $otherSnowflake->query($snowflakeSql);
            $otherPaymentCounts = $otherResult['data'] ?? [];
            $bothSourcesLoaded = true;
            $this->info("[INFO] Fetched payment counts for " . count($otherPaymentCounts) . " contacts from " . strtoupper($otherSource) . " Snowflake");
        } catch (\Throwable $e) {
            $this->warn("[WARN] Could not query " . strtoupper($otherSource) . " Snowflake: " . $e->getMessage() . " — stale counts will not be reset to 0 this run.");
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
        $sql = "SELECT LLG_ID, Payments, Payment_Frequency FROM TblEnrollment WHERE Category = '{$this->esc($this->category)}'";
        $enrollmentResult = $sqlConnector->querySqlServer($sql);
        $enrollmentData = $enrollmentResult['data'] ?? [];

        // Collect updates needed
        $updates = [];
        $preview = [];
        $normalizedCounts = [];
        foreach ($enrollmentData as $enrollment) {
            $llgId = $enrollment['LLG_ID'] ?? null;
            $currentPayments = (float) ($enrollment['Payments'] ?? 0);
            $paymentFrequency = trim($enrollment['Payment_Frequency'] ?? '');

            if (!$llgId) {
                continue;
            }

            // Remove LLG- prefix to get contact ID
            $contactId = str_replace('LLG-', '', $llgId);

            // A contact with no cleared-and-not-returned draft has no row in the GROUP BY at all, so
            // "absent" must mean 0 — not "keep the old count". Jacob, 17 Sep 2026: LLG-1223838618's
            // only draft cleared on 8 Sep and returned on 10 Sep; the sync ran in between, wrote
            // Payments = 1, and nothing ever put it back. Reset only when both Snowflake sources
            // answered, or an outage on one side would zero the other side's clients.
            if (isset($paymentsMap[$contactId])) {
                $rawPaymentCount = (int) $paymentsMap[$contactId];
                $paymentCount = $this->normalizePaymentCount($rawPaymentCount, $paymentFrequency);
                if ($paymentCount !== $rawPaymentCount) {
                    $frequencyLabel = $paymentFrequency !== '' ? $paymentFrequency : '(blank)';
                    $normalizedCounts[$frequencyLabel] = ($normalizedCounts[$frequencyLabel] ?? 0) + 1;
                }
            } elseif ($bothSourcesLoaded) {
                $paymentCount = 0;
            } else {
                continue;
            }

            // Only add to updates if different
            if ((int) $currentPayments !== $paymentCount) {
                $updates[$llgId] = $paymentCount;
                $preview[$llgId] = [
                    'current' => $currentPayments,
                    'frequency' => $paymentFrequency !== '' ? $paymentFrequency : '(blank)',
                    'next' => $paymentCount,
                ];
            }
        }

        $this->info("[INFO] Found " . count($updates) . " records needing Payments update");
        foreach ($normalizedCounts as $frequency => $count) {
            $this->info("[INFO] Normalized {$count} payment count(s) at '{$frequency}' frequency to monthly equivalents");
        }

        if ($dryRun) {
            $this->info('[DRY RUN] Sample payment changes (up to 25):');
            $shown = 0;
            foreach ($preview as $llgId => $change) {
                $this->line("[DRY RUN] {$llgId}: {$change['current']} -> {$change['next']} ({$change['frequency']})");
                if (++$shown >= 25) {
                    break;
                }
            }
            $this->info('[DRY RUN] No SQL Server writes performed.');
            return;
        }

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

    /**
     * Convert cleared payment transactions to the monthly-equivalent count used by payroll.
     * Check bi-weekly before weekly: "Bi-Weekly" contains the substring "Weekly".
     */
    private function normalizePaymentCount(int $rawCount, string $frequency): int
    {
        $normalizedFrequency = strtolower(preg_replace('/[^a-z]/i', '', trim($frequency)) ?? '');

        if (str_contains($normalizedFrequency, 'biweekly') || str_contains($normalizedFrequency, 'semimonthly')) {
            return (int) round($rawCount / 2);
        }

        if ($normalizedFrequency === 'weekly') {
            return (int) round($rawCount / 4);
        }

        // Monthly and unknown frequencies retain the full observed count, matching legacy behavior.
        return $rawCount;
    }

    private function updateContactsCampaign(DBConnector $sqlConnector): void
    {
        $sql = "
            UPDATE TblContacts
            SET TblContacts.Campaign = TblEnrollment.Drop_Name
            FROM TblContacts
            INNER JOIN TblEnrollment ON TblEnrollment.LLG_ID = TblContacts.LLG_ID
            WHERE COALESCE(TblContacts.Campaign, '') = ''
              AND TblEnrollment.Category = '{$this->esc($this->category)}'
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
