<?php

namespace Cmd\Reports\Console\Commands;

use Cmd\Reports\Services\DBConnector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * SyncFirstPaymentDate — set First_Payment_Date in TblEnrollment from Snowflake
 * TRANSACTIONS (Jacob 2026-08-17 rule, replacing the old roll-forward "mover"):
 *
 *   First_Payment_Date = the process date of the FIRST 'D' transaction that
 *   CLEARED or RETURNED. If the contact has no cleared/returned transaction (no
 *   payment ever processed), use the process date of their FIRST 'D' transaction.
 *
 * Stateless: recomputed for the ENTIRE pipeline every run straight from Snowflake,
 * so a late clear/return is always reflected on the next run. No date "moving", no
 * 3-advance cap, no Payment_Attempted tracking — all removed per Jacob. The
 * cleared-payment routine (SyncFirstPaymentClearedDate) applies the same rule at
 * the moment a payment clears, so it stays consistent between runs.
 *
 * A "return" is RETURNED_DATE present OR a NON-EMPTY RETURN_CODE — RETURN_CODE = ''
 * is NOT a return (it's an unprocessed draft). Both Snowflake instances (ldr + plaw)
 * are consulted; a contact living in both resolves to the PLAW value (matches the
 * prior command's precedence).
 */
class SyncFirstPaymentDate extends Command
{
    protected $signature = 'sync:first-payment-date
                            {--dry-run : Preview what would change without writing to SQL Server}';

    protected $description = 'Set First_Payment_Date in TblEnrollment = process date of the first cleared-or-returned D transaction (else the first D transaction), for the entire pipeline';

    private const CHUNK_SIZE = 1000;   // contacts per Snowflake aggregate query
    private const WRITE_CHUNK_SIZE = 500;

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $this->info($dryRun ? 'First payment date sync: starting (DRY RUN — no writes).' : 'First payment date sync: starting.');
        Log::info('SyncFirstPaymentDate started.', ['dry_run' => $dryRun]);

        try {
            $sqlConnector = DBConnector::fromEnvironment('ldr');
            $sqlConnector->initializeSqlServer();
        } catch (\Throwable $e) {
            $this->error('Failed to initialize SQL Server connector: ' . $e->getMessage());
            Log::error('SyncFirstPaymentDate: SQL Server init failed.', ['exception' => $e]);
            return Command::FAILURE;
        }

        try {
            $contactToLlg = $this->fetchPipelineContacts($sqlConnector);
            $this->info('Pipeline contacts: ' . count($contactToLlg) . '.');
            if ($contactToLlg === []) {
                $this->info('Nothing to process.');
                return Command::SUCCESS;
            }

            $contactIds = array_keys($contactToLlg);

            $this->info('Computing first-payment dates from Snowflake (PLAW)...');
            $plaw = $this->fetchFirstPaymentDates(DBConnector::fromEnvironment('plaw'), $contactIds);
            $this->info('PLAW: ' . count($plaw) . ' contact(s) with transactions.');

            $this->info('Computing first-payment dates from Snowflake (LDR)...');
            $ldr = $this->fetchFirstPaymentDates($sqlConnector, $contactIds);
            $this->info('LDR: ' . count($ldr) . ' contact(s) with transactions.');

            // LLG_ID => new First_Payment_Date. PLAW wins if a contact is in both.
            $newDates = [];
            foreach ($contactToLlg as $cid => $llgId) {
                $fpd = $plaw[$cid] ?? ($ldr[$cid] ?? null);
                if ($fpd !== null && $fpd !== '') {
                    $newDates[$llgId] = $fpd;
                }
            }
            $this->info('Computed First_Payment_Date for ' . count($newDates) . ' contact(s).');

            if ($dryRun) {
                $this->previewChanges($sqlConnector, $newDates);
                $this->info('First payment date sync: finished (dry run).');
                return Command::SUCCESS;
            }

            $updated = $this->applyFirstPaymentDates($sqlConnector, $newDates);
            $this->info("Updated First_Payment_Date on {$updated} row(s) (only rows whose value changed).");

            Log::info('SyncFirstPaymentDate finished.', ['computed' => count($newDates), 'updated' => $updated]);
            $this->info('First payment date sync: finished.');
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('First payment date sync failed: ' . $e->getMessage());
            Log::error('SyncFirstPaymentDate: exception during sync.', ['exception' => $e]);
            return Command::FAILURE;
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    // Read
    // ──────────────────────────────────────────────────────────────────────

    /** @return array<string, string> CONTACT_ID => LLG_ID for the whole pipeline. */
    protected function fetchPipelineContacts(DBConnector $connector): array
    {
        $sql = <<<SQL
SELECT LLG_ID
FROM dbo.TblEnrollment
WHERE LLG_ID LIKE 'LLG-%'
  AND TRY_CONVERT(BIGINT, REPLACE(LLG_ID, 'LLG-', '')) IS NOT NULL
SQL;

        $rows = $this->extractRows($connector->querySqlServer($sql));

        $map = [];
        foreach ($rows as $row) {
            $llg = $this->getRowValue($row, 'LLG_ID');
            if ($llg === null) {
                continue;
            }
            $cid = preg_replace('/\D+/', '', $llg);
            if ($cid !== '' && !isset($map[$cid])) {
                $map[$cid] = $llg;
            }
        }

        return $map;
    }

    /**
     * Per contact: process date of the FIRST cleared-or-returned D transaction; if none, the
     * FIRST D transaction's process date. One aggregate query per chunk — no per-draft rows are
     * pulled into PHP.
     *
     * @param list<string> $contactIds
     * @return array<string, string> CONTACT_ID => 'Y-m-d'
     */
    protected function fetchFirstPaymentDates(DBConnector $connector, array $contactIds): array
    {
        $out = [];

        foreach (array_chunk($contactIds, self::CHUNK_SIZE) as $chunk) {
            $values = implode(', ', array_map(
                fn(string $id): string => "('" . $this->escapeSqlString($id) . "')",
                $chunk
            ));

            $sql = <<<SQL
SELECT
    CONTACT_ID,
    TO_VARCHAR(
        MIN(CASE
            WHEN (CLEARED_DATE IS NOT NULL OR RETURNED_DATE IS NOT NULL OR (RETURN_CODE IS NOT NULL AND RETURN_CODE <> ''))
            THEN CAST(CONVERT_TIMEZONE('America/Los_Angeles', PROCESS_DATE) AS DATE)
        END),
        'YYYY-MM-DD'
    ) AS FIRST_PROCESSED,
    TO_VARCHAR(MIN(CAST(CONVERT_TIMEZONE('America/Los_Angeles', PROCESS_DATE) AS DATE)), 'YYYY-MM-DD') AS FIRST_DRAFT
FROM TRANSACTIONS
WHERE CONTACT_ID IN (SELECT TO_NUMBER(column1) FROM VALUES {$values})
  AND TRANS_TYPE = 'D'
GROUP BY CONTACT_ID
SQL;

            try {
                $rows = $this->extractRows($connector->query($sql));
            } catch (\Throwable $e) {
                Log::warning('SyncFirstPaymentDate: aggregate chunk failed; skipped', ['error' => $e->getMessage()]);
                continue;
            }

            foreach ($rows as $row) {
                $cid = $this->getRowValue($row, 'CONTACT_ID');
                if ($cid === null) {
                    continue;
                }
                $processed = $this->normalizeDate($this->getRowValue($row, 'FIRST_PROCESSED'));
                $draft = $this->normalizeDate($this->getRowValue($row, 'FIRST_DRAFT'));
                $fpd = $processed ?? $draft;
                if ($fpd !== null) {
                    $out[$cid] = $fpd;
                }
            }
        }

        return $out;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Write
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Batch-update First_Payment_Date, writing only rows whose value actually changes so
     * unchanged rows aren't churned.
     *
     * @param array<string, string> $newDates LLG_ID => 'Y-m-d'
     */
    protected function applyFirstPaymentDates(DBConnector $connector, array $newDates): int
    {
        if ($newDates === []) {
            return 0;
        }

        $total = 0;

        foreach (array_chunk($newDates, self::WRITE_CHUNK_SIZE, true) as $chunk) {
            $cases = [];
            $ids = [];
            foreach ($chunk as $llgId => $date) {
                $llgEsc = $this->escapeSqlString($llgId);
                $dateEsc = $this->escapeSqlString($date);
                $cases[] = "WHEN '{$llgEsc}' THEN '{$dateEsc}'";
                $ids[] = "'{$llgEsc}'";
            }

            $caseSql = implode(' ', $cases);
            $idList = implode(', ', $ids);

            $sql = "UPDATE dbo.TblEnrollment "
                . "SET First_Payment_Date = CASE LLG_ID {$caseSql} END "
                . "WHERE LLG_ID IN ({$idList}) "
                . "AND (First_Payment_Date IS NULL OR CONVERT(varchar(10), First_Payment_Date, 120) <> CASE LLG_ID {$caseSql} END)";

            $result = $connector->querySqlServer($sql);
            $total += $this->getRowCount($result);
        }

        return $total;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Dry-run preview
    // ──────────────────────────────────────────────────────────────────────

    /** @param array<string, string> $newDates LLG_ID => 'Y-m-d' */
    protected function previewChanges(DBConnector $connector, array $newDates): void
    {
        if ($newDates === []) {
            $this->line('DRY RUN — nothing to compute.');
            return;
        }

        $current = $this->fetchCurrentFpd($connector, array_keys($newDates));

        $changed = [];
        foreach ($newDates as $llgId => $newDate) {
            $cur = $current[$llgId] ?? null;
            if ($cur !== $newDate) {
                $changed[$llgId] = ['from' => $cur ?? 'NULL', 'to' => $newDate];
            }
        }

        $this->info(sprintf(
            'DRY RUN — %d computed, %d already correct (no-op), %d would change.',
            count($newDates), count($newDates) - count($changed), count($changed)
        ));

        $shown = 0;
        foreach ($changed as $llgId => $row) {
            if ($shown >= 25) {
                $this->line(sprintf('  ... and %d more not shown', count($changed) - $shown));
                break;
            }
            $this->line(sprintf('  %s: First_Payment_Date %s -> %s', $llgId, $row['from'], $row['to']));
            $shown++;
        }
    }

    /**
     * @param list<string> $llgIds
     * @return array<string, ?string> LLG_ID => current First_Payment_Date ('Y-m-d' or null)
     */
    protected function fetchCurrentFpd(DBConnector $connector, array $llgIds): array
    {
        $current = [];

        foreach (array_chunk($llgIds, self::WRITE_CHUNK_SIZE) as $chunk) {
            $idList = implode(', ', array_map(
                fn(string $id): string => "'" . $this->escapeSqlString($id) . "'",
                $chunk
            ));

            $sql = "SELECT LLG_ID, CONVERT(varchar(10), First_Payment_Date, 120) AS FPD FROM dbo.TblEnrollment WHERE LLG_ID IN ({$idList})";
            $rows = $this->extractRows($connector->querySqlServer($sql));

            foreach ($rows as $row) {
                $llg = $this->getRowValue($row, 'LLG_ID');
                if ($llg === null) {
                    continue;
                }
                $current[$llg] = $this->getRowValue($row, 'FPD'); // null if column is NULL
            }
        }

        return $current;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────

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
