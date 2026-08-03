<?php

namespace Cmd\Reports\Console\Commands;

use Cmd\Reports\Services\DBConnector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * SyncFirstPaymentDate - Sync First_Payment_Date / First_Payment_Cleared_Date
 * in TblEnrollment from Snowflake TRANSACTIONS, driven by Payment_Attempted.
 *
 * Replaces the old cleared/scheduled/cancelled step pipeline (which caused two
 * real bugs Jacob flagged 2026-07-31 — cancelled accounts rolling forward to a
 * future draft, and stale payments getting silently stuck forever) with a
 * single, stateless, per-contact walk that's fully recomputed from Snowflake
 * every run:
 *
 * Only contacts with Payment_Attempted IS NULL are pulled. For each, walk
 * their 'D' transactions in process-date order, starting at the first ever:
 *
 *   - No transaction found in EITHER Snowflake instance (ldr and plaw)
 *     -> Payment_Attempted = 'N/A'. Done, never pulled again.
 *
 *   - The transaction under consideration has a CLEARED_DATE
 *     -> First_Payment_Date = its process date, First_Payment_Cleared_Date =
 *        its cleared date, Payment_Attempted = today's date. Done.
 *
 *   - It has a RETURNED_DATE (or RETURN_CODE) instead
 *     -> First_Payment_Date = its process date (cleared date stays null),
 *        Payment_Attempted = today's date. Done.
 *
 *   - Neither, and it's less than 4 days stale
 *     -> First_Payment_Date = its process date. Payment_Attempted stays
 *        NULL — still open, re-walked from scratch next run.
 *
 *   - Neither, and it's 4+ days stale
 *     -> Advance to the next transaction and repeat, up to 3 advances total,
 *        never advancing past 90 days from the FIRST transaction's date.
 *        Once either cap is hit, freeze First_Payment_Date on the last
 *        transaction reached. Payment_Attempted stays NULL — still open.
 *
 * Because nothing about "which transaction we rolled to" is persisted, a
 * transaction that clears late (after we'd already advanced past it) is
 * caught automatically: the walk starts over at transaction #1 every run, so
 * the next run just finds the CLEARED_DATE that's now present and resolves
 * there. No separate "undo the roll" logic is needed.
 */
class SyncFirstPaymentDate extends Command
{
    protected $signature = 'sync:first-payment-date
                            {--dry-run : Preview what would change without writing to SQL Server or TblLog}';

    protected $description = 'Sync First_Payment_Date, First_Payment_Cleared_Date and Payment_Attempted in TblEnrollment from Snowflake TRANSACTIONS';

    private const STALE_AFTER_DAYS = 4;
    private const MAX_ADVANCES = 3;
    private const MAX_DAYS_PAST_ORIGINAL = 90;
    private const TXNS_PER_CONTACT_FETCHED = 6; // original + up to 3 advances, plus buffer

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $this->info($dryRun ? 'First payment sync: starting (DRY RUN — no writes will be made).' : 'First payment sync: starting.');
        Log::info('SyncFirstPaymentDate command started.', ['dry_run' => $dryRun]);

        try {
            $sqlConnector = DBConnector::fromEnvironment('ldr');
            $sqlConnector->initializeSqlServer();
        } catch (\Throwable $e) {
            $this->error('Failed to initialize SQL Server connector: ' . $e->getMessage());
            Log::error('SyncFirstPaymentDate: SQL Server init failed.', ['exception' => $e]);
            return Command::FAILURE;
        }

        try {
            $llgIds = $this->fetchIdsAwaitingAttempt($sqlConnector);
            $this->info('Found ' . count($llgIds) . ' LLG_IDs with Payment_Attempted IS NULL.');

            if (empty($llgIds)) {
                $this->info('Nothing to process.');
                return Command::SUCCESS;
            }

            $contactToLlg = $this->buildContactToLlgMap($llgIds);
            $contactIds = array_keys($contactToLlg);

            $this->info('Fetching PLAW transaction history...');
            $plawConnector = DBConnector::fromEnvironment('plaw');
            $plawTxns = $this->fetchTransactionHistory($plawConnector, $contactIds);
            $this->info('PLAW: ' . count($plawTxns) . ' contact(s) with transaction history.');

            $this->info('Fetching LDR transaction history...');
            $ldrTxns = $this->fetchTransactionHistory($sqlConnector, $contactIds);
            $this->info('LDR: ' . count($ldrTxns) . ' contact(s) with transaction history.');

            $today = now()->format('Y-m-d');

            $resolved = [];   // llgId => ['first_payment_date'=>, 'first_payment_cleared_date'=>?, 'payment_attempted'=>today]
            $stillOpen = [];  // llgId => ['first_payment_date'=>]
            $notFound = [];   // list of llgId

            foreach ($contactToLlg as $cid => $llgId) {
                $txns = $plawTxns[$cid] ?? ($ldrTxns[$cid] ?? null);

                if ($txns === null) {
                    $notFound[] = $llgId;
                    continue;
                }

                $outcome = $this->walkTransactions($txns, $today);

                if ($outcome['resolved']) {
                    $resolved[$llgId] = [
                        'first_payment_date' => $outcome['first_payment_date'],
                        'first_payment_cleared_date' => $outcome['first_payment_cleared_date'],
                        'payment_attempted' => $today,
                    ];
                } else {
                    $stillOpen[$llgId] = [
                        'first_payment_date' => $outcome['first_payment_date'],
                    ];
                }
            }

            $this->info(sprintf(
                'Resolved: %d (cleared/returned). Still open (frozen or within window): %d. Not found in either Snowflake: %d.',
                count($resolved), count($stillOpen), count($notFound)
            ));

            if ($dryRun) {
                $this->previewResolved($sqlConnector, $resolved);
                $this->previewStillOpen($sqlConnector, $stillOpen);
                $this->info(sprintf(
                    'DRY RUN summary — would resolve %d, update %d still-open dates, mark %d as N/A. Nothing was written.',
                    count($resolved), count($stillOpen), count($notFound)
                ));
                $this->info('First payment sync: finished (dry run).');
                return Command::SUCCESS;
            }

            $updatedResolved = $this->applyResolved($sqlConnector, $resolved);
            $this->info("Updated {$updatedResolved} rows: resolved (Payment_Attempted set).");

            $updatedOpen = $this->applyStillOpen($sqlConnector, $stillOpen);
            $this->info("Updated {$updatedOpen} rows: First_Payment_Date only, still open.");

            $updatedNotFound = $this->applyNotFound($sqlConnector, $notFound);
            $this->info("Updated {$updatedNotFound} rows: marked N/A.");

            Log::info('SyncFirstPaymentDate command finished.', [
                'resolved' => count($resolved),
                'still_open' => count($stillOpen),
                'not_found' => count($notFound),
            ]);

            $this->info('First payment sync: finished.');
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('First payment sync failed: ' . $e->getMessage());
            Log::error('SyncFirstPaymentDate: exception during sync.', ['exception' => $e]);
            return Command::FAILURE;
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    // Walk logic
    // ──────────────────────────────────────────────────────────────────────

    /**
     * @param list<array{process_date:string,cleared_date:?string,returned:bool}> $txns
     *        Sorted ascending by process_date. Guaranteed non-empty.
     * @return array{resolved:bool,first_payment_date:string,first_payment_cleared_date:?string}
     */
    protected function walkTransactions(array $txns, string $today): array
    {
        $originalDate = $txns[0]['process_date'];
        $advancesUsed = 0;

        foreach ($txns as $i => $txn) {
            if ($txn['cleared_date'] !== null) {
                return [
                    'resolved' => true,
                    'first_payment_date' => $txn['process_date'],
                    'first_payment_cleared_date' => $txn['cleared_date'],
                ];
            }

            if ($txn['returned']) {
                return [
                    'resolved' => true,
                    'first_payment_date' => $txn['process_date'],
                    'first_payment_cleared_date' => null,
                ];
            }

            $daysStale = $this->daysBetween($txn['process_date'], $today);

            if ($daysStale < self::STALE_AFTER_DAYS) {
                return [
                    'resolved' => false,
                    'first_payment_date' => $txn['process_date'],
                    'first_payment_cleared_date' => null,
                ];
            }

            $next = $txns[$i + 1] ?? null;
            $canAdvance = $advancesUsed < self::MAX_ADVANCES
                && $next !== null
                && $this->daysBetween($originalDate, $next['process_date']) <= self::MAX_DAYS_PAST_ORIGINAL;

            if (!$canAdvance) {
                return [
                    'resolved' => false,
                    'first_payment_date' => $txn['process_date'],
                    'first_payment_cleared_date' => null,
                ];
            }

            $advancesUsed++;
            // loop continues onto $next
        }

        // Fetched window exhausted without resolving or hitting a freeze condition
        // (shouldn't normally happen given TXNS_PER_CONTACT_FETCHED comfortably
        // covers MAX_ADVANCES, but freeze on the last one seen rather than error).
        $last = $txns[count($txns) - 1];
        return [
            'resolved' => false,
            'first_payment_date' => $last['process_date'],
            'first_payment_cleared_date' => null,
        ];
    }

    protected function daysBetween(string $earlier, string $later): int
    {
        $a = new \DateTimeImmutable($earlier);
        $b = new \DateTimeImmutable($later);
        return (int) $a->diff($b)->days * ($b < $a ? -1 : 1);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Fetch
    // ──────────────────────────────────────────────────────────────────────

    /** @return list<string> */
    protected function fetchIdsAwaitingAttempt(DBConnector $connector): array
    {
        $sql = <<<SQL
SELECT LLG_ID
FROM dbo.TblEnrollment
WHERE Payment_Attempted IS NULL
  AND LLG_ID LIKE 'LLG-%'
  AND TRY_CONVERT(BIGINT, REPLACE(LLG_ID, 'LLG-', '')) IS NOT NULL
SQL;

        return $this->extractLlgIds($connector->querySqlServer($sql));
    }

    /**
     * @param list<string> $contactIds
     * @return array<string, list<array{process_date:string,cleared_date:?string,returned:bool}>>
     */
    protected function fetchTransactionHistory(DBConnector $connector, array $contactIds): array
    {
        if (empty($contactIds)) {
            return [];
        }

        $byContact = [];
        $maxRows = self::TXNS_PER_CONTACT_FETCHED;

        foreach (array_chunk($contactIds, 500) as $chunk) {
            $values = implode(', ', array_map(
                fn(string $id): string => "('" . $this->escapeSqlString($id) . "')",
                $chunk
            ));

            $sql = <<<SQL
SELECT
    CONTACT_ID,
    TO_VARCHAR(PROCESS_DATE, 'YYYY-MM-DD') AS PROCESS_DATE,
    TO_VARCHAR(CLEARED_DATE, 'YYYY-MM-DD') AS CLEARED_DATE,
    TO_VARCHAR(RETURNED_DATE, 'YYYY-MM-DD') AS RETURNED_DATE,
    RETURN_CODE
FROM (
    SELECT
        t.CONTACT_ID,
        t.PROCESS_DATE,
        t.CLEARED_DATE,
        t.RETURNED_DATE,
        t.RETURN_CODE,
        ROW_NUMBER() OVER (PARTITION BY t.CONTACT_ID ORDER BY TO_DATE(t.PROCESS_DATE) ASC) AS N
    FROM TRANSACTIONS t
    WHERE t.CONTACT_ID IN (SELECT TO_NUMBER(column1) FROM VALUES {$values})
      AND t.TRANS_TYPE = 'D'
)
WHERE N <= {$maxRows}
ORDER BY CONTACT_ID, PROCESS_DATE ASC
SQL;

            $rows = $this->extractRows($connector->query($sql));

            foreach ($rows as $row) {
                $cid = $this->getRowValue($row, 'CONTACT_ID');
                $processDate = $this->normalizeDate($this->getRowValue($row, 'PROCESS_DATE'));
                if ($cid === null || $processDate === null) {
                    continue;
                }

                $clearedDate = $this->normalizeDate($this->getRowValue($row, 'CLEARED_DATE'));
                $returnedDate = $this->getRowValue($row, 'RETURNED_DATE');
                $returnCode = $this->getRowValue($row, 'RETURN_CODE');
                $returned = ($returnedDate !== null && $returnedDate !== '') || ($returnCode !== null && $returnCode !== '');

                $byContact[$cid] ??= [];
                $byContact[$cid][] = [
                    'process_date' => $processDate,
                    'cleared_date' => $clearedDate,
                    'returned' => $returned,
                ];
            }
        }

        return $byContact;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Write
    // ──────────────────────────────────────────────────────────────────────

    /** @param array<string, array{first_payment_date:string,first_payment_cleared_date:?string,payment_attempted:string}> $resolved */
    protected function applyResolved(DBConnector $connector, array $resolved): int
    {
        if (empty($resolved)) {
            return 0;
        }

        $totalUpdated = 0;

        foreach (array_chunk($resolved, 500, true) as $chunk) {
            $casesDate = [];
            $casesCleared = [];
            $casesAttempted = [];
            $ids = [];

            foreach ($chunk as $llgId => $data) {
                $llgEsc = $this->escapeSqlString($llgId);
                $dateEsc = $this->escapeSqlString($data['first_payment_date']);
                $attemptedEsc = $this->escapeSqlString($data['payment_attempted']);

                $casesDate[] = "WHEN '{$llgEsc}' THEN '{$dateEsc}'";
                $casesCleared[] = $data['first_payment_cleared_date'] !== null
                    ? "WHEN '{$llgEsc}' THEN '" . $this->escapeSqlString($data['first_payment_cleared_date']) . "'"
                    : "WHEN '{$llgEsc}' THEN NULL";
                $casesAttempted[] = "WHEN '{$llgEsc}' THEN '{$attemptedEsc}'";
                $ids[] = "'{$llgEsc}'";
            }

            $idList = implode(', ', $ids);
            $sql = "UPDATE dbo.TblEnrollment SET "
                . "First_Payment_Date = CASE LLG_ID " . implode(' ', $casesDate) . " END, "
                . "First_Payment_Cleared_Date = CASE LLG_ID " . implode(' ', $casesCleared) . " END, "
                . "Payment_Attempted = CASE LLG_ID " . implode(' ', $casesAttempted) . " END "
                . "WHERE LLG_ID IN ({$idList})";

            $result = $connector->querySqlServer($sql);
            $totalUpdated += $this->getRowCount($result);
        }

        return $totalUpdated;
    }

    /** @param array<string, array{first_payment_date:string}> $stillOpen */
    protected function applyStillOpen(DBConnector $connector, array $stillOpen): int
    {
        if (empty($stillOpen)) {
            return 0;
        }

        $totalUpdated = 0;

        foreach (array_chunk($stillOpen, 500, true) as $chunk) {
            $casesDate = [];
            $ids = [];

            foreach ($chunk as $llgId => $data) {
                $llgEsc = $this->escapeSqlString($llgId);
                $dateEsc = $this->escapeSqlString($data['first_payment_date']);

                $casesDate[] = "WHEN '{$llgEsc}' THEN '{$dateEsc}'";
                $ids[] = "'{$llgEsc}'";
            }

            $idList = implode(', ', $ids);
            $sql = "UPDATE dbo.TblEnrollment SET First_Payment_Date = CASE LLG_ID " . implode(' ', $casesDate) . " END "
                . "WHERE LLG_ID IN ({$idList})";

            $result = $connector->querySqlServer($sql);
            $totalUpdated += $this->getRowCount($result);
        }

        return $totalUpdated;
    }

    /** @param list<string> $llgIds */
    protected function applyNotFound(DBConnector $connector, array $llgIds): int
    {
        if (empty($llgIds)) {
            return 0;
        }

        $totalUpdated = 0;

        foreach (array_chunk($llgIds, 500) as $chunk) {
            $idList = implode(', ', array_map(
                fn(string $id): string => "'" . $this->escapeSqlString($id) . "'",
                $chunk
            ));

            $sql = "UPDATE dbo.TblEnrollment SET Payment_Attempted = 'N/A' WHERE LLG_ID IN ({$idList})";

            $result = $connector->querySqlServer($sql);
            $totalUpdated += $this->getRowCount($result);
        }

        return $totalUpdated;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Dry run preview
    // ──────────────────────────────────────────────────────────────────────

    protected function previewResolved(DBConnector $connector, array $resolved): void
    {
        if (empty($resolved)) {
            $this->line('DRY RUN — resolved: none.');
            return;
        }

        $current = $this->fetchCurrentValues($connector, array_keys($resolved));

        $changed = [];
        foreach ($resolved as $llgId => $data) {
            $cur = $current[$llgId] ?? ['First_Payment_Date' => null, 'First_Payment_Cleared_Date' => null];
            $curDate = $cur['First_Payment_Date'] !== null ? substr($cur['First_Payment_Date'], 0, 10) : null;
            $curCleared = $cur['First_Payment_Cleared_Date'] !== null ? substr($cur['First_Payment_Cleared_Date'], 0, 10) : null;

            if ($curDate !== $data['first_payment_date'] || $curCleared !== $data['first_payment_cleared_date']) {
                $changed[$llgId] = $data + ['cur_date' => $curDate ?? 'NULL', 'cur_cleared' => $curCleared ?? 'NULL'];
            }
        }

        $this->line(sprintf(
            'DRY RUN — resolved: %d row(s) would be marked done (Payment_Attempted set), %d differ from current values.',
            count($resolved), count($changed)
        ));

        $shown = 0;
        foreach ($changed as $llgId => $row) {
            if ($shown >= 25) {
                $this->line(sprintf('  ... and %d more not shown', count($changed) - $shown));
                break;
            }
            $this->line(sprintf(
                '  %s: FPD %s -> %s | FPCD %s -> %s | Payment_Attempted -> %s',
                $llgId, $row['cur_date'], $row['first_payment_date'],
                $row['cur_cleared'], $row['first_payment_cleared_date'] ?? 'NULL',
                $row['payment_attempted'],
            ));
            $shown++;
        }
    }

    protected function previewStillOpen(DBConnector $connector, array $stillOpen): void
    {
        if (empty($stillOpen)) {
            $this->line('DRY RUN — still open: none.');
            return;
        }

        $current = $this->fetchCurrentValues($connector, array_keys($stillOpen));

        $changed = [];
        foreach ($stillOpen as $llgId => $data) {
            $cur = $current[$llgId] ?? ['First_Payment_Date' => null];
            $curDate = $cur['First_Payment_Date'] !== null ? substr($cur['First_Payment_Date'], 0, 10) : null;

            if ($curDate !== $data['first_payment_date']) {
                $changed[$llgId] = ['cur_date' => $curDate ?? 'NULL', 'new_date' => $data['first_payment_date']];
            }
        }

        $this->line(sprintf(
            'DRY RUN — still open: %d row(s) matched, %d already correct (no-op), %d would actually change.',
            count($stillOpen), count($stillOpen) - count($changed), count($changed)
        ));

        $shown = 0;
        foreach ($changed as $llgId => $row) {
            if ($shown >= 25) {
                $this->line(sprintf('  ... and %d more not shown', count($changed) - $shown));
                break;
            }
            $this->line(sprintf('  %s: First_Payment_Date %s -> %s', $llgId, $row['cur_date'], $row['new_date']));
            $shown++;
        }
    }

    /**
     * @param list<string> $llgIds
     * @return array<string, array{First_Payment_Date: ?string, First_Payment_Cleared_Date: ?string}>
     */
    protected function fetchCurrentValues(DBConnector $connector, array $llgIds): array
    {
        $current = [];

        foreach (array_chunk($llgIds, 500) as $chunk) {
            $idList = implode(', ', array_map(
                fn(string $id): string => "'" . $this->escapeSqlString($id) . "'",
                $chunk
            ));

            $sql = "SELECT LLG_ID, First_Payment_Date, First_Payment_Cleared_Date FROM dbo.TblEnrollment WHERE LLG_ID IN ({$idList})";
            $rows = $this->extractRows($connector->querySqlServer($sql));

            foreach ($rows as $row) {
                $llgId = $this->getRowValue($row, 'LLG_ID');
                if ($llgId === null) {
                    continue;
                }
                $current[$llgId] = [
                    'First_Payment_Date' => $this->getRowValue($row, 'First_Payment_Date'),
                    'First_Payment_Cleared_Date' => $this->getRowValue($row, 'First_Payment_Cleared_Date'),
                ];
            }
        }

        return $current;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────

    /** @param list<string> $llgIds @return array<string, string> CONTACT_ID => LLG_ID */
    protected function buildContactToLlgMap(array $llgIds): array
    {
        $map = [];
        foreach ($llgIds as $llg) {
            $numeric = preg_replace('/\D+/', '', (string) $llg);
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

        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $trimmed)) {
            return substr($trimmed, 0, 10);
        }

        if (preg_match('/^\d+(?:\.\d+)?$/', $trimmed)) {
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

    protected function escapeSqlString(string $value): string
    {
        return str_replace("'", "''", $value);
    }
}
