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
 * every run — nothing about "which transaction we're on" is ever persisted:
 *
 * Only contacts with Payment_Attempted IS NULL are pulled. For each, look at
 * every fetched 'D' transaction (in process-date order, starting at the very
 * first one ever) for a cleared or returned date — this check always runs
 * first, regardless of staleness or cancellation, so a transaction that
 * clears/returns late (after we'd have otherwise rolled past it) is always
 * caught on the next run. No separate "undo the roll" logic is needed.
 *
 *   - No transaction found in EITHER Snowflake instance (ldr and plaw)
 *     -> Payment_Attempted = 'N/A'. Done, never pulled again.
 *
 *   - Some transaction has a CLEARED_DATE
 *     -> First_Payment_Date = its process date, First_Payment_Cleared_Date =
 *        its cleared date, Payment_Attempted = today's date. Locked, done.
 *
 *   - Some transaction has a RETURNED_DATE (or RETURN_CODE) instead
 *     -> First_Payment_Date = its process date (cleared date stays null),
 *        Payment_Attempted = today's date. Locked, done.
 *
 *   - Nothing has cleared/returned, and 90+ calendar days have passed since
 *     the very first transaction's date
 *     -> Give up. First_Payment_Date = the first transaction's date,
 *        Payment_Attempted = 'No Payment'. Done, never pulled again.
 *
 *   - Nothing has cleared/returned, under 90 days, contact is CANCELLED
 *     -> First_Payment_Date = the first transaction's date. Never rolls
 *        forward — a cancelled contact isn't waiting on a payment, so there's
 *        nothing to advance to. Payment_Attempted stays NULL (still open,
 *        re-checked every run in case it clears/returns or the 90-day window
 *        above closes it out).
 *
 *   - Nothing has cleared/returned, under 90 days, contact is ACTIVE
 *     -> Roll forward through transactions that are 4+ days stale to find
 *        the most current one still worth showing; First_Payment_Date = its
 *        date. Payment_Attempted stays NULL — still open.
 */
class SyncFirstPaymentDate extends Command
{
    protected $signature = 'sync:first-payment-date
                            {--dry-run : Preview what would change without writing to SQL Server or TblLog}';

    protected $description = 'Sync First_Payment_Date, First_Payment_Cleared_Date and Payment_Attempted in TblEnrollment from Snowflake TRANSACTIONS';

    private const STALE_AFTER_DAYS = 4;
    private const GIVE_UP_AFTER_DAYS = 90;
    // Safety cap on how many transactions we look at per contact. With no advance-count
    // limit, a frequent (e.g. weekly) payment schedule could have 12-13 attempts inside
    // the 90-day give-up window alone, so this needs real headroom above that, not just
    // enough for a handful of rolls.
    private const TXNS_PER_CONTACT_FETCHED = 20;

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
            $awaiting = $this->fetchIdsAwaitingAttempt($sqlConnector);
            $this->info('Found ' . count($awaiting) . ' LLG_IDs with Payment_Attempted IS NULL.');

            if (empty($awaiting)) {
                $this->info('Nothing to process.');
                return Command::SUCCESS;
            }

            $llgIds = array_keys($awaiting);
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
            $noPayment = [];  // llgId => ['first_payment_date'=>]  (90-day give-up)
            $notFound = [];   // list of llgId

            foreach ($contactToLlg as $cid => $llgId) {
                $txns = $plawTxns[$cid] ?? ($ldrTxns[$cid] ?? null);

                if ($txns === null) {
                    $notFound[] = $llgId;
                    continue;
                }

                $cancelled = $awaiting[$llgId]['cancelled'] ?? false;
                $outcome = $this->walkTransactions($txns, $cancelled, $today);

                match ($outcome['outcome']) {
                    'resolved' => $resolved[$llgId] = [
                        'first_payment_date' => $outcome['first_payment_date'],
                        'first_payment_cleared_date' => $outcome['first_payment_cleared_date'],
                        'payment_attempted' => $today,
                    ],
                    'no_payment' => $noPayment[$llgId] = [
                        'first_payment_date' => $outcome['first_payment_date'],
                    ],
                    default => $stillOpen[$llgId] = [
                        'first_payment_date' => $outcome['first_payment_date'],
                    ],
                };
            }

            $this->info(sprintf(
                'Resolved: %d (cleared/returned). Still open: %d. Gave up (90+ days, no payment): %d. Not found in either Snowflake: %d.',
                count($resolved), count($stillOpen), count($noPayment), count($notFound)
            ));

            if ($dryRun) {
                $this->previewResolved($sqlConnector, $resolved);
                $this->previewStillOpen($sqlConnector, $stillOpen, 'still open');
                $this->previewStillOpen($sqlConnector, $noPayment, 'gave up (No Payment)');
                $this->info(sprintf(
                    'DRY RUN summary — would resolve %d, update %d still-open dates, give up on %d (No Payment), mark %d as N/A. Nothing was written.',
                    count($resolved), count($stillOpen), count($noPayment), count($notFound)
                ));
                $this->info('First payment sync: finished (dry run).');
                return Command::SUCCESS;
            }

            $updatedResolved = $this->applyResolved($sqlConnector, $resolved);
            $this->info("Updated {$updatedResolved} rows: resolved (Payment_Attempted set).");

            $updatedOpen = $this->applyStillOpen($sqlConnector, $stillOpen);
            $this->info("Updated {$updatedOpen} rows: First_Payment_Date only, still open.");

            $updatedNoPayment = $this->applyNoPayment($sqlConnector, $noPayment);
            $this->info("Updated {$updatedNoPayment} rows: gave up after 90+ days, marked No Payment.");

            $updatedNotFound = $this->applyNotFound($sqlConnector, $notFound);
            $this->info("Updated {$updatedNotFound} rows: marked N/A.");

            Log::info('SyncFirstPaymentDate command finished.', [
                'resolved' => count($resolved),
                'still_open' => count($stillOpen),
                'no_payment' => count($noPayment),
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
     * @return array{outcome:'resolved'|'open'|'no_payment',first_payment_date:string,first_payment_cleared_date:?string}
     */
    protected function walkTransactions(array $txns, bool $cancelled, string $today): array
    {
        $originalDate = $txns[0]['process_date'];

        // Priority 1, always: does ANY fetched transaction have a cleared or
        // returned date? Checked before staleness/cancellation so a late
        // clear/return — even on a transaction we'd otherwise have rolled
        // past — is always caught. Locked once found; nothing below can undo it.
        foreach ($txns as $txn) {
            if ($txn['cleared_date'] !== null) {
                return [
                    'outcome' => 'resolved',
                    'first_payment_date' => $txn['process_date'],
                    'first_payment_cleared_date' => $txn['cleared_date'],
                ];
            }
            if ($txn['returned']) {
                return [
                    'outcome' => 'resolved',
                    'first_payment_date' => $txn['process_date'],
                    'first_payment_cleared_date' => null,
                ];
            }
        }

        // Nothing resolved. Give up once 90 real days have passed since the
        // very first transaction, regardless of cancelled status.
        if ($this->daysBetween($originalDate, $today) > self::GIVE_UP_AFTER_DAYS) {
            return [
                'outcome' => 'no_payment',
                'first_payment_date' => $originalDate,
                'first_payment_cleared_date' => null,
            ];
        }

        // Cancelled contacts never roll forward — there's nothing left to
        // advance toward, so just show the original date and stay open.
        if ($cancelled) {
            return [
                'outcome' => 'open',
                'first_payment_date' => $originalDate,
                'first_payment_cleared_date' => null,
            ];
        }

        // Active contact: roll forward through transactions that are 4+ days
        // stale to find the most current one still worth showing.
        $current = $txns[0];
        foreach ($txns as $txn) {
            $current = $txn;
            if ($this->daysBetween($txn['process_date'], $today) < self::STALE_AFTER_DAYS) {
                break; // not stale — this is the one to show, stop advancing
            }
            // stale — loop continues onto the next fetched transaction, if any
        }

        return [
            'outcome' => 'open',
            'first_payment_date' => $current['process_date'],
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

    /** @return array<string, array{cancelled: bool}> LLG_ID => info */
    protected function fetchIdsAwaitingAttempt(DBConnector $connector): array
    {
        $sql = <<<SQL
SELECT LLG_ID, Cancel_Date
FROM dbo.TblEnrollment
WHERE Payment_Attempted IS NULL
  AND LLG_ID LIKE 'LLG-%'
  AND TRY_CONVERT(BIGINT, REPLACE(LLG_ID, 'LLG-', '')) IS NOT NULL
SQL;

        $rows = $this->extractRows($connector->querySqlServer($sql));
        $awaiting = [];

        foreach ($rows as $row) {
            $llgId = $this->getRowValue($row, 'LLG_ID');
            if ($llgId === null) {
                continue;
            }
            $awaiting[$llgId] = [
                'cancelled' => $this->getRowValue($row, 'Cancel_Date') !== null,
            ];
        }

        return $awaiting;
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

    /** @param array<string, array{first_payment_date:string}> $noPayment */
    protected function applyNoPayment(DBConnector $connector, array $noPayment): int
    {
        if (empty($noPayment)) {
            return 0;
        }

        $totalUpdated = 0;

        foreach (array_chunk($noPayment, 500, true) as $chunk) {
            $casesDate = [];
            $ids = [];

            foreach ($chunk as $llgId => $data) {
                $llgEsc = $this->escapeSqlString($llgId);
                $dateEsc = $this->escapeSqlString($data['first_payment_date']);

                $casesDate[] = "WHEN '{$llgEsc}' THEN '{$dateEsc}'";
                $ids[] = "'{$llgEsc}'";
            }

            $idList = implode(', ', $ids);
            $sql = "UPDATE dbo.TblEnrollment SET "
                . "First_Payment_Date = CASE LLG_ID " . implode(' ', $casesDate) . " END, "
                . "Payment_Attempted = 'No Payment' "
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

    /** @param array<string, array{first_payment_date:string}> $rows */
    protected function previewStillOpen(DBConnector $connector, array $rows, string $label = 'still open'): void
    {
        if (empty($rows)) {
            $this->line("DRY RUN — {$label}: none.");
            return;
        }

        $current = $this->fetchCurrentValues($connector, array_keys($rows));

        $changed = [];
        foreach ($rows as $llgId => $data) {
            $cur = $current[$llgId] ?? ['First_Payment_Date' => null];
            $curDate = $cur['First_Payment_Date'] !== null ? substr($cur['First_Payment_Date'], 0, 10) : null;

            if ($curDate !== $data['first_payment_date']) {
                $changed[$llgId] = ['cur_date' => $curDate ?? 'NULL', 'new_date' => $data['first_payment_date']];
            }
        }

        $this->line(sprintf(
            'DRY RUN — %s: %d row(s) matched, %d already correct (no-op), %d would actually change.',
            $label, count($rows), count($rows) - count($changed), count($changed)
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
