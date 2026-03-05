<?php

namespace Cmd\Reports\Console\Commands;

use Cmd\Reports\Services\DBConnector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * SyncFirstPaymentDate - Sync first payment dates from Snowflake TRANSACTIONS
 * 
 * Logic (per Jacob's requirements):
 * 1. Get IDs that do NOT have a cleared date in CMD
 * 2. Check Snowflake (both instances) for a cleared date (NO date restrictions)
 *    - If found → update First_Payment_Date = PROCESS_DATE, First_Payment_Cleared_Date = CLEARED_DATE (final values)
 * 3. If no cleared date → get first D payment where PROCESS_DATE <= CURRENT_DATE - 5
 * 4. For CANCELLED contacts:
 *    - Don't update first payment date if it already has a value (prevent dates moving up)
 *    - BUT if First_Payment_Date IS NULL, still populate it
 */
class SyncFirstPaymentDate extends Command
{
    protected $signature = 'sync:first-payment-date';

    protected $description = 'Sync First_Payment_Date and First_Payment_Cleared_Date in TblEnrollment from Snowflake TRANSACTIONS';

    public function handle(): int
    {
        $this->info('First payment sync: starting.');
        Log::info('SyncFirstPaymentDate command started.');

        $connections = ['plaw', 'ldr'];
        $hadException = false;

        foreach ($connections as $connection) {
            $source = strtoupper($connection);
            $this->info("[$source] Starting first payment sync.");
            Log::info('SyncFirstPaymentDate: starting connection.', [
                'connection' => $connection,
                'source' => $source,
            ]);

            try {
                $connector = DBConnector::fromEnvironment($connection);
                $connector->initializeSqlServer();
                $this->ensureLogTable($connector);

                // STEP 1: Get IDs missing First_Payment_Cleared_Date (non-cancelled)
                $this->info("[$source] Fetching IDs missing First_Payment_Cleared_Date...");
                $missingClearedIds = $this->fetchIdsMissingClearedDate($connector);
                $this->info("[$source] Found " . count($missingClearedIds) . " IDs missing cleared date.");

                // STEP 2: Check Snowflake for cleared dates (no date restriction)
                $clearedPayments = [];
                if (!empty($missingClearedIds)) {
                    $this->info("[$source] Checking Snowflake for cleared payments (no date restriction)...");
                    $clearedPayments = $this->fetchClearedPaymentsFromSnowflake($connector, $missingClearedIds);
                    $this->info("[$source] Found " . count($clearedPayments) . " cleared payments.");

                    // Apply cleared payments (final values)
                    if (!empty($clearedPayments)) {
                        $this->info("[$source] Applying cleared payments to SQL Server...");
                        $updatedCleared = $this->updateWithClearedPayments($connector, $clearedPayments);
                        $this->info("[$source] Updated {$updatedCleared} rows with cleared payment dates.");
                    }
                }

                // STEP 3: For remaining IDs without cleared date, check for process date <= current_date - 5
                $remainingIds = array_diff($missingClearedIds, array_keys($clearedPayments));
                $this->info("[$source] " . count($remainingIds) . " IDs still without cleared date.");

                $scheduledPayments = [];
                if (!empty($remainingIds)) {
                    $this->info("[$source] Checking Snowflake for scheduled payments (process_date <= current_date - 5)...");
                    $scheduledPayments = $this->fetchScheduledPaymentsFromSnowflake($connector, $remainingIds);
                    $this->info("[$source] Found " . count($scheduledPayments) . " scheduled payments.");

                    // Apply scheduled payments (update First_Payment_Date only)
                    if (!empty($scheduledPayments)) {
                        $this->info("[$source] Applying scheduled payments to SQL Server...");
                        $updatedScheduled = $this->updateWithScheduledPayments($connector, $scheduledPayments);
                        $this->info("[$source] Updated {$updatedScheduled} rows with scheduled payment dates.");
                    }
                }

                // STEP 4: Handle CANCELLED contacts with NULL First_Payment_Date
                $this->info("[$source] Fetching cancelled IDs with NULL First_Payment_Date...");
                $cancelledNullIds = $this->fetchCancelledWithNullPaymentDate($connector);
                $this->info("[$source] Found " . count($cancelledNullIds) . " cancelled IDs with NULL first payment date.");

                if (!empty($cancelledNullIds)) {
                    $this->info("[$source] Checking Snowflake for first payment for cancelled contacts...");
                    $cancelledPayments = $this->fetchFirstPaymentForCancelled($connector, $cancelledNullIds);
                    $this->info("[$source] Found " . count($cancelledPayments) . " payments for cancelled contacts.");

                    if (!empty($cancelledPayments)) {
                        $this->info("[$source] Applying first payment dates for cancelled contacts...");
                        $updatedCancelled = $this->updateCancelledWithFirstPayment($connector, $cancelledPayments);
                        $this->info("[$source] Updated {$updatedCancelled} cancelled rows with first payment date.");
                    }
                }

                $totalUpdated = count($clearedPayments) + count($scheduledPayments) + count($cancelledNullIds);
                $this->insertLogRow(
                    $connector,
                    $source,
                    'SYNC_FIRST_PAYMENT_DATE',
                    'SUCCESS',
                    $totalUpdated,
                    0,
                    sprintf('Cleared: %d, Scheduled: %d, Cancelled: %d', 
                        count($clearedPayments), count($scheduledPayments), count($cancelledNullIds))
                );

                Log::info('SyncFirstPaymentDate: connection finished.', [
                    'connection' => $connection,
                    'source' => $source,
                    'cleared' => count($clearedPayments),
                    'scheduled' => count($scheduledPayments),
                ]);
            } catch (\Throwable $e) {
                $hadException = true;

                $this->error("First payment sync failed for connection [{$connection}] ({$source}).");
                $this->error($e->getMessage());

                Log::error('SyncFirstPaymentDate: exception during sync.', [
                    'connection' => $connection,
                    'source' => $source,
                    'exception' => $e,
                ]);

                try {
                    if (!isset($connector)) {
                        $connector = DBConnector::fromEnvironment($connection);
                        $connector->initializeSqlServer();
                        $this->ensureLogTable($connector);
                    }

                    $errorMessage = mb_substr($e->getMessage(), 0, 900);

                    $this->insertLogRow(
                        $connector,
                        $source,
                        'SYNC_FIRST_PAYMENT_DATE',
                        'FAILED',
                        0,
                        0,
                        $errorMessage
                    );
                } catch (\Throwable $logException) {
                    Log::error('SyncFirstPaymentDate: failed to log to TblLog after exception.', [
                        'connection' => $connection,
                        'source' => $source,
                        'exception' => $logException,
                    ]);
                }
            }
        }

        $this->info('First payment sync: finished.');
        Log::info('SyncFirstPaymentDate command finished.', [
            'status' => $hadException ? 'FAILURE' : 'SUCCESS',
        ]);

        return $hadException ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Get IDs that do NOT have a cleared date in CMD (non-cancelled)
     */
    protected function fetchIdsMissingClearedDate(DBConnector $connector): array
    {
        $sql = <<<SQL
SELECT LLG_ID
FROM dbo.TblEnrollment
WHERE First_Payment_Cleared_Date IS NULL
  AND Cancel_Date IS NULL
  AND LLG_ID LIKE 'LLG-%'
  AND TRY_CONVERT(BIGINT, REPLACE(LLG_ID, 'LLG-', '')) IS NOT NULL
SQL;

        return $this->extractLlgIds($connector->querySqlServer($sql));
    }

    /**
     * Get CANCELLED contacts with NULL First_Payment_Date
     */
    protected function fetchCancelledWithNullPaymentDate(DBConnector $connector): array
    {
        $sql = <<<SQL
SELECT LLG_ID
FROM dbo.TblEnrollment
WHERE First_Payment_Date IS NULL
  AND Cancel_Date IS NOT NULL
  AND LLG_ID LIKE 'LLG-%'
  AND TRY_CONVERT(BIGINT, REPLACE(LLG_ID, 'LLG-', '')) IS NOT NULL
SQL;

        return $this->extractLlgIds($connector->querySqlServer($sql));
    }

    /**
     * Check Snowflake for cleared payments (NO date restriction)
     * Returns payments with CLEARED_DATE IS NOT NULL and RETURNED_DATE IS NULL
     */
    protected function fetchClearedPaymentsFromSnowflake(DBConnector $connector, array $llgIds): array
    {
        if (empty($llgIds)) {
            return [];
        }

        $contactToLlg = $this->buildContactToLlgMap($llgIds);
        $contactIds = array_keys($contactToLlg);

        if (empty($contactIds)) {
            return [];
        }

        $payments = [];
        $chunkSize = 500;

        foreach (array_chunk($contactIds, $chunkSize) as $chunk) {
            $values = implode(', ', array_map(function ($id) {
                return "('" . $this->escapeSqlString($id) . "')";
            }, $chunk));

            // Get first CLEARED payment per contact (ordered by CLEARED_DATE)
            $sql = <<<SQL
SELECT
    CONTACT_ID,
    TO_VARCHAR(CLEARED_DATE, 'YYYY-MM-DD') AS CLEARED_DATE,
    TO_VARCHAR(PROCESS_DATE, 'YYYY-MM-DD') AS PROCESS_DATE,
    AMOUNT
FROM (
    SELECT
        t.CONTACT_ID,
        TO_DATE(t.CLEARED_DATE) AS CLEARED_DATE,
        TO_DATE(t.PROCESS_DATE) AS PROCESS_DATE,
        t.AMOUNT,
        ROW_NUMBER() OVER (PARTITION BY t.CONTACT_ID ORDER BY TO_DATE(t.CLEARED_DATE)) AS N
    FROM TRANSACTIONS t
    WHERE t.CONTACT_ID IN (SELECT TO_NUMBER(column1) FROM VALUES {$values})
      AND t.TRANS_TYPE = 'D'
      AND t.CLEARED_DATE IS NOT NULL
      AND t.RETURNED_DATE IS NULL
)
WHERE N = 1
SQL;

            $result = $connector->query($sql);
            $rows = $this->extractRows($result);

            foreach ($rows as $row) {
                $cid = $this->getRowValue($row, 'CONTACT_ID');
                $clearedDate = $this->normalizeDate($this->getRowValue($row, 'CLEARED_DATE'));
                $processDate = $this->normalizeDate($this->getRowValue($row, 'PROCESS_DATE'));
                $amount = $this->getRowValue($row, 'AMOUNT');

                if (!$cid || !$clearedDate) {
                    continue;
                }

                $llgId = $contactToLlg[$cid] ?? ('LLG-' . $cid);

                $payments[$llgId] = [
                    'cleared_date' => $clearedDate,
                    'process_date' => $processDate,
                    'amount' => $amount,
                ];
            }
        }

        return $payments;
    }

    /**
     * Check Snowflake for scheduled payments - ROLL FORWARD LOGIC
     * 
     * Per Jacob's requirements:
     * - If payment date has passed by 3+ business days with no clear, look at the NEXT date
     * - Keep rolling forward until we find a payment that hasn't passed the 3 business day threshold
     * - This prevents dates from getting "stuck" on old missed payments
     * - Business days = weekdays only (no weekends, holidays not considered)
     * 
     * Logic:
     * 1. Get FIRST D payment where payment is still within 3 business days window
     * 2. If no upcoming payment, get the MOST RECENT payment (to show latest expected date)
     */
    protected function fetchScheduledPaymentsFromSnowflake(DBConnector $connector, array $llgIds): array
    {
        if (empty($llgIds)) {
            return [];
        }

        $contactToLlg = $this->buildContactToLlgMap($llgIds);
        $contactIds = array_keys($contactToLlg);

        if (empty($contactIds)) {
            return [];
        }

        $payments = [];
        $chunkSize = 500;

        foreach (array_chunk($contactIds, $chunkSize) as $chunk) {
            $values = implode(', ', array_map(function ($id) {
                return "('" . $this->escapeSqlString($id) . "')";
            }, $chunk));

            // PRIORITY 1: Get FIRST upcoming payment (within 3 business days window)
            // This is the next payment we're waiting on
            // Business days calculation: subtract days while skipping weekends
            $sql = <<<SQL
SELECT
    CONTACT_ID,
    TO_VARCHAR(PROCESS_DATE, 'YYYY-MM-DD') AS PROCESS_DATE
FROM (
    SELECT
        t.CONTACT_ID,
        TO_DATE(t.PROCESS_DATE) AS PROCESS_DATE,
        ROW_NUMBER() OVER (PARTITION BY t.CONTACT_ID ORDER BY TO_DATE(t.PROCESS_DATE) ASC) AS N
    FROM TRANSACTIONS t
    WHERE t.CONTACT_ID IN (SELECT TO_NUMBER(column1) FROM VALUES {$values})
      AND t.TRANS_TYPE = 'D'
      AND t.RETURNED_DATE IS NULL
      AND t.CLEARED_DATE IS NULL
      AND TO_DATE(t.PROCESS_DATE) >= (
        CASE 
          -- Calculate 3 business days back from today (excluding weekends)
          -- Mon (1): Mon->Fri->Thu->Wed = 5 calendar days back
          -- Tue (2): Tue->Mon->Fri->Thu = 5 calendar days back  
          -- Wed (3): Wed->Tue->Mon->Fri = 5 calendar days back
          -- Thu (4): Thu->Wed->Tue->Mon = 3 calendar days back
          -- Fri (5): Fri->Thu->Wed->Tue = 3 calendar days back
          -- Sat (6): Count from Fri: Fri->Thu->Wed = 5 calendar days back (Sat-5=Wed)
          -- Sun (0): Count from Fri: Fri->Thu->Wed = 6 calendar days back (Sun-6=Wed)
          WHEN DAYOFWEEK(CURRENT_DATE) = 1 THEN CURRENT_DATE - 5  -- Mon: Wed (prev week)
          WHEN DAYOFWEEK(CURRENT_DATE) = 2 THEN CURRENT_DATE - 5  -- Tue: Thu (prev week)
          WHEN DAYOFWEEK(CURRENT_DATE) = 3 THEN CURRENT_DATE - 5  -- Wed: Fri (prev week)
          WHEN DAYOFWEEK(CURRENT_DATE) = 4 THEN CURRENT_DATE - 3  -- Thu: Mon (this week)
          WHEN DAYOFWEEK(CURRENT_DATE) = 5 THEN CURRENT_DATE - 3  -- Fri: Tue (this week)
          WHEN DAYOFWEEK(CURRENT_DATE) = 6 THEN CURRENT_DATE - 5  -- Sat: Wed (from Fri)
          WHEN DAYOFWEEK(CURRENT_DATE) = 0 THEN CURRENT_DATE - 6  -- Sun: Wed (from Fri)
        END
      )
)
WHERE N = 1
SQL;

            $result = $connector->query($sql);
            $rows = $this->extractRows($result);

            foreach ($rows as $row) {
                $cid = $this->getRowValue($row, 'CONTACT_ID');
                $processDate = $this->normalizeDate($this->getRowValue($row, 'PROCESS_DATE'));

                if (!$cid || !$processDate) {
                    continue;
                }

                $llgId = $contactToLlg[$cid] ?? ('LLG-' . $cid);
                $payments[$llgId] = [
                    'process_date' => $processDate,
                ];
            }

            // PRIORITY 2: For contacts not found above, get MOST RECENT uncleared payment
            // (All payments have passed 3+ business days - use latest as "expected" date)
            $foundContactIds = [];
            foreach ($rows as $row) {
                $cid = $this->getRowValue($row, 'CONTACT_ID');
                if ($cid) {
                    $foundContactIds[$cid] = true;
                }
            }

            $remainingIds = array_filter($chunk, function ($id) use ($foundContactIds) {
                return !isset($foundContactIds[$id]);
            });

            if (!empty($remainingIds)) {
                $remainingValues = implode(', ', array_map(function ($id) {
                    return "('" . $this->escapeSqlString($id) . "')";
                }, $remainingIds));

                $sqlFallback = <<<SQL
SELECT
    CONTACT_ID,
    TO_VARCHAR(PROCESS_DATE, 'YYYY-MM-DD') AS PROCESS_DATE
FROM (
    SELECT
        t.CONTACT_ID,
        TO_DATE(t.PROCESS_DATE) AS PROCESS_DATE,
        ROW_NUMBER() OVER (PARTITION BY t.CONTACT_ID ORDER BY TO_DATE(t.PROCESS_DATE) DESC) AS N
    FROM TRANSACTIONS t
    WHERE t.CONTACT_ID IN (SELECT TO_NUMBER(column1) FROM VALUES {$remainingValues})
      AND t.TRANS_TYPE = 'D'
      AND t.RETURNED_DATE IS NULL
      AND t.CLEARED_DATE IS NULL
)
WHERE N = 1
SQL;

                $resultFallback = $connector->query($sqlFallback);
                $rowsFallback = $this->extractRows($resultFallback);

                foreach ($rowsFallback as $row) {
                    $cid = $this->getRowValue($row, 'CONTACT_ID');
                    $processDate = $this->normalizeDate($this->getRowValue($row, 'PROCESS_DATE'));

                    if (!$cid || !$processDate) {
                        continue;
                    }

                    $llgId = $contactToLlg[$cid] ?? ('LLG-' . $cid);
                    $payments[$llgId] = [
                        'process_date' => $processDate,
                    ];
                }
            }
        }

        return $payments;
    }

    /**
     * Fetch first payment for cancelled contacts (to populate NULL first payment date)
     */
    protected function fetchFirstPaymentForCancelled(DBConnector $connector, array $llgIds): array
    {
        if (empty($llgIds)) {
            return [];
        }

        $contactToLlg = $this->buildContactToLlgMap($llgIds);
        $contactIds = array_keys($contactToLlg);

        if (empty($contactIds)) {
            return [];
        }

        $payments = [];
        $chunkSize = 500;

        foreach (array_chunk($contactIds, $chunkSize) as $chunk) {
            $values = implode(', ', array_map(function ($id) {
                return "('" . $this->escapeSqlString($id) . "')";
            }, $chunk));

            // Get first D payment (no date restrictions for cancelled)
            $sql = <<<SQL
SELECT
    CONTACT_ID,
    TO_VARCHAR(PROCESS_DATE, 'YYYY-MM-DD') AS PROCESS_DATE,
    TO_VARCHAR(CLEARED_DATE, 'YYYY-MM-DD') AS CLEARED_DATE
FROM (
    SELECT
        t.CONTACT_ID,
        TO_DATE(t.PROCESS_DATE) AS PROCESS_DATE,
        TO_DATE(t.CLEARED_DATE) AS CLEARED_DATE,
        ROW_NUMBER() OVER (PARTITION BY t.CONTACT_ID ORDER BY TO_DATE(t.PROCESS_DATE)) AS N
    FROM TRANSACTIONS t
    WHERE t.CONTACT_ID IN (SELECT TO_NUMBER(column1) FROM VALUES {$values})
      AND t.TRANS_TYPE = 'D'
      AND t.RETURNED_DATE IS NULL
)
WHERE N = 1
SQL;

            $result = $connector->query($sql);
            $rows = $this->extractRows($result);

            foreach ($rows as $row) {
                $cid = $this->getRowValue($row, 'CONTACT_ID');
                $processDate = $this->normalizeDate($this->getRowValue($row, 'PROCESS_DATE'));
                $clearedDate = $this->normalizeDate($this->getRowValue($row, 'CLEARED_DATE'));

                if (!$cid || !$processDate) {
                    continue;
                }

                $llgId = $contactToLlg[$cid] ?? ('LLG-' . $cid);

                $payments[$llgId] = [
                    'process_date' => $processDate,
                    'cleared_date' => $clearedDate,
                ];
            }
        }

        return $payments;
    }

    /**
     * Update SQL Server with cleared payments (final values)
     * Sets First_Payment_Date = PROCESS_DATE, First_Payment_Cleared_Date = CLEARED_DATE
     */
    protected function updateWithClearedPayments(DBConnector $connector, array $payments): int
    {
        if (empty($payments)) {
            return 0;
        }

        $totalUpdated = 0;
        $batchSize = 500;

        foreach (array_chunk($payments, $batchSize, true) as $chunk) {
            $casesClearedDate = [];
            $casesProcessDate = [];
            $casesAmount = [];
            $ids = [];

            foreach ($chunk as $llgId => $data) {
                $llgEsc = $this->escapeSqlString($llgId);
                $clearedDateEsc = $this->escapeSqlString($data['cleared_date']);
                $processDateEsc = $data['process_date'] ? $this->escapeSqlString($data['process_date']) : null;
                $amount = is_numeric($data['amount'] ?? null) ? $data['amount'] : null;

                $casesClearedDate[] = "WHEN '{$llgEsc}' THEN '{$clearedDateEsc}'";
                if ($processDateEsc) {
                    $casesProcessDate[] = "WHEN '{$llgEsc}' THEN '{$processDateEsc}'";
                }
                if ($amount !== null) {
                    $casesAmount[] = "WHEN '{$llgEsc}' THEN {$amount}";
                }
                $ids[] = "'{$llgEsc}'";
            }

            $idList = implode(', ', $ids);
            $setClauses = [
                "First_Payment_Cleared_Date = CASE LLG_ID " . implode(' ', $casesClearedDate) . " END",
                "First_Payment_Status = 'Cleared'",
            ];

            if (!empty($casesProcessDate)) {
                $setClauses[] = "First_Payment_Date = CASE LLG_ID " . implode(' ', $casesProcessDate) . " ELSE First_Payment_Date END";
            }
            if (!empty($casesAmount)) {
                $setClauses[] = "Program_Payment = CASE LLG_ID " . implode(' ', $casesAmount) . " ELSE Program_Payment END";
            }

            $sql = "UPDATE dbo.TblEnrollment SET " . implode(", ", $setClauses) . " WHERE LLG_ID IN ({$idList})";

            $result = $connector->querySqlServer($sql);
            $totalUpdated += $this->getRowCount($result);
        }

        return $totalUpdated;
    }

    /**
     * Update SQL Server with scheduled payments (First_Payment_Date only)
     */
    protected function updateWithScheduledPayments(DBConnector $connector, array $payments): int
    {
        if (empty($payments)) {
            return 0;
        }

        $totalUpdated = 0;
        $batchSize = 500;

        foreach (array_chunk($payments, $batchSize, true) as $chunk) {
            $casesDate = [];
            $ids = [];

            foreach ($chunk as $llgId => $data) {
                $llgEsc = $this->escapeSqlString($llgId);
                $dateEsc = $this->escapeSqlString($data['process_date']);

                $casesDate[] = "WHEN '{$llgEsc}' THEN '{$dateEsc}'";
                $ids[] = "'{$llgEsc}'";
            }

            $idList = implode(', ', $ids);
            $casesDateSql = implode(' ', $casesDate);
            $sql = "UPDATE dbo.TblEnrollment SET First_Payment_Date = CASE LLG_ID {$casesDateSql} ELSE First_Payment_Date END, First_Payment_Status = 'Pending' WHERE LLG_ID IN ({$idList}) AND Cancel_Date IS NULL";

            $result = $connector->querySqlServer($sql);
            $totalUpdated += $this->getRowCount($result);
        }

        return $totalUpdated;
    }

    /**
     * Update cancelled contacts with first payment date (only if currently NULL)
     */
    protected function updateCancelledWithFirstPayment(DBConnector $connector, array $payments): int
    {
        if (empty($payments)) {
            return 0;
        }

        $totalUpdated = 0;
        $batchSize = 500;

        foreach (array_chunk($payments, $batchSize, true) as $chunk) {
            $casesDate = [];
            $casesClearedDate = [];
            $casesStatus = [];
            $ids = [];

            foreach ($chunk as $llgId => $data) {
                $llgEsc = $this->escapeSqlString($llgId);
                $dateEsc = $this->escapeSqlString($data['process_date']);

                $casesDate[] = "WHEN '{$llgEsc}' THEN '{$dateEsc}'";
                
                if (!empty($data['cleared_date'])) {
                    $clearedDateEsc = $this->escapeSqlString($data['cleared_date']);
                    $casesClearedDate[] = "WHEN '{$llgEsc}' THEN '{$clearedDateEsc}'";
                    $casesStatus[] = "WHEN '{$llgEsc}' THEN 'Cleared'";
                } else {
                    $casesStatus[] = "WHEN '{$llgEsc}' THEN 'Pending'";
                }
                
                $ids[] = "'{$llgEsc}'";
            }

            $idList = implode(', ', $ids);
            $casesDateSql = implode(' ', $casesDate);
            $casesStatusSql = implode(' ', $casesStatus);
            
            $setClauses = [
                "First_Payment_Date = CASE LLG_ID {$casesDateSql} END",
                "First_Payment_Status = CASE LLG_ID {$casesStatusSql} END",
            ];
            
            if (!empty($casesClearedDate)) {
                $casesClearedDateSql = implode(' ', $casesClearedDate);
                $setClauses[] = "First_Payment_Cleared_Date = CASE LLG_ID {$casesClearedDateSql} ELSE First_Payment_Cleared_Date END";
            }
            
            $sql = "UPDATE dbo.TblEnrollment SET " . implode(", ", $setClauses) . 
                   " WHERE LLG_ID IN ({$idList}) AND First_Payment_Date IS NULL";

            $result = $connector->querySqlServer($sql);
            $totalUpdated += $this->getRowCount($result);
        }

        return $totalUpdated;
    }

    // Helper methods
    
    protected function buildContactToLlgMap(array $llgIds): array
    {
        $map = [];
        foreach ($llgIds as $llg) {
            $numeric = preg_replace('/\\D+/', '', (string) $llg);
            if ($numeric !== '' && !isset($map[$numeric])) {
                $map[$numeric] = (string) $llg;
            }
        }
        return $map;
    }

    protected function extractLlgIds($result): array
    {
        $rows = $this->extractRows($result);
        $ids = [];
        foreach ($rows as $row) {
            $id = $this->getRowValue($row, 'LLG_ID');
            if ($id) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    protected function extractRows($result): array
    {
        if (!is_array($result)) {
            return [];
        }
        if (isset($result['data']) && is_array($result['data'])) {
            return $result['data'];
        }
        if (array_is_list($result)) {
            return $result;
        }
        return [];
    }

    protected function getRowValue(array $row, string $key): ?string
    {
        foreach ($row as $k => $v) {
            if (strcasecmp($k, $key) === 0 && $v !== null && $v !== '') {
                return (string) $v;
            }
        }
        return null;
    }

    protected function getRowCount($result): int
    {
        if (!is_array($result)) {
            return 0;
        }
        foreach (['row_count', 'rowCount', 'affected_rows'] as $key) {
            if (isset($result[$key]) && is_numeric($result[$key])) {
                return (int) $result[$key];
            }
        }
        return 0;
    }

    protected function normalizeDate(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $trimmed = trim($value);
        
        if (preg_match('/^\\d{4}-\\d{2}-\\d{2}/', $trimmed)) {
            return substr($trimmed, 0, 10);
        }
        
        if (preg_match('/^\\d+(?:\\.\\d+)?$/', $trimmed)) {
            $days = (int) floor((float) $trimmed);
            if ($days > 0 && $days < 50000) {
                $epoch = new \DateTimeImmutable('1970-01-01');
                return $epoch->modify('+' . $days . ' days')->format('Y-m-d');
            }
        }
        
        try {
            return (new \DateTimeImmutable($trimmed))->format('Y-m-d');
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function ensureLogTable(DBConnector $connector): void
    {
        // Assume TblLog exists.
    }

    protected function insertLogRow(
        DBConnector $connector,
        string $source,
        string $action,
        string $status,
        int $recordsProcessed,
        int $recordsDeleted,
        string $details
    ): void {
        // TblLog schema: Table_Name/Macro NVARCHAR(50), Description/Action NVARCHAR(255), Result NVARCHAR(50)
        $tableName = 'TblEnrollment';
        $macro = 'SyncFirstPaymentDate';
        $actionLabel = $action !== '' ? strtoupper($action) : 'SYNC_FIRST_PAYMENT_DATE';

        $description = $this->truncateString(
            sprintf('Sync first payment date for %s', $source),
            255
        );
        $descriptionEsc = $this->escapeSqlString($description);

        $details = $this->truncateString($details, 200);
        $resultSummary = $this->truncateString(
            sprintf('S=%s A=%s P=%d D=%d', $status, $actionLabel, $recordsProcessed, $recordsDeleted),
            50
        );
        $resultSummaryEsc = $this->escapeSqlString($resultSummary);

        $actionSanitized = $this->truncateString($actionLabel, 255);
        $actionEsc = $this->escapeSqlString($actionSanitized);
        $tableName = $this->truncateString($tableName, 50);
        $tableNameEsc = $this->escapeSqlString($tableName);
        $macro = $this->truncateString($macro, 50);
        $macroEsc = $this->escapeSqlString($macro);

        $timestamp = now()->format('Y-m-d H:i:s');
        $timestampEsc = $this->escapeSqlString($timestamp);

        $this->info(sprintf('[%s] Writing log entry to TblLog...', $source));

        $sql = <<<SQL
DECLARE @hasPK BIT = CASE WHEN COL_LENGTH('dbo.TblLog', 'PK') IS NULL THEN 0 ELSE 1 END;
DECLARE @isIdentity BIT = CASE WHEN COLUMNPROPERTY(OBJECT_ID('dbo.TblLog'), 'PK', 'IsIdentity') = 1 THEN 1 ELSE 0 END;

IF @hasPK = 1 AND @isIdentity = 0
BEGIN
    DECLARE @nextPK INT = ISNULL((SELECT MAX([PK]) FROM [dbo].[TblLog]), 0) + 1;
    INSERT INTO [dbo].[TblLog] ([PK], [Table_Name], [Macro], [Description], [Action], [Result], [Timestamp])
    VALUES (@nextPK, '{$tableNameEsc}', '{$macroEsc}', '{$descriptionEsc}', '{$actionEsc}', '{$resultSummaryEsc}', '{$timestampEsc}');
END
ELSE
BEGIN
    INSERT INTO [dbo].[TblLog] ([Table_Name], [Macro], [Description], [Action], [Result], [Timestamp])
    VALUES ('{$tableNameEsc}', '{$macroEsc}', '{$descriptionEsc}', '{$actionEsc}', '{$resultSummaryEsc}', '{$timestampEsc}');
END;
SQL;

        try {
            $result = $connector->querySqlServer($sql);

            if (is_array($result) && isset($result['success']) && $result['success'] === false) {
                $errorMsg = $result['error'] ?? 'Unknown SQL Server error';
                $this->error(sprintf('[%s] Log insert failed: %s', $source, $errorMsg));
                Log::error('SyncFirstPaymentDate: log insert failed.', [
                    'source' => $source,
                    'sql' => $sql,
                    'result' => $result,
                ]);
                return;
            }

            $this->info(sprintf('[%s] Log entry inserted into TblLog.', $source));
        } catch (\Throwable $e) {
            $this->error(sprintf('[%s] Log insert failed: %s', $source, $e->getMessage()));
            Log::error('SyncFirstPaymentDate: log insert failed.', [
                'source' => $source,
                'sql' => $sql,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    protected function escapeSqlString(string $value): string
    {
        return str_replace("'", "''", $value);
    }

    protected function truncateString(string $value, int $maxLength): string
    {
        if (mb_strlen($value) <= $maxLength) {
            return $value;
        }

        return mb_substr($value, 0, $maxLength);
    }
}
