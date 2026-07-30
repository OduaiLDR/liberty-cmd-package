<?php

namespace Cmd\Reports\Console\Commands;

use Cmd\Reports\Services\DBConnector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Sync SF_UID_LDR / SF_UID_PLAW on TblEmployees against Snowflake USERS.
 *
 * Runs daily (schedule Mon–Fri 1AM via admin automation) in two phases:
 *
 *   Phase 0 — Validate & NULL stale/wrong UIDs
 *     For every non-terminated employee with a value in SF_UID_LDR or
 *     SF_UID_PLAW, confirm the stored UID actually exists in the CORRESPONDING
 *     Snowflake USERS table. If it doesn't, the value is wrong (typo, wrong
 *     column, or the person left that company) — NULL it out. Runs against
 *     ALL employees regardless of Access_Level (bad data is bad data).
 *
 *   Phase 1 — Fill blanks by email
 *     Candidate filter: Term_Date IS NULL, target Access_Level, and at least
 *     one of SF_UID_LDR / SF_UID_PLAW empty (Phase 0's NULLs feed into this).
 *     Email domain drives which Snowflake gets queried:
 *       @libertydebtrelief.com -> LDR Snowflake -> writes SF_UID_LDR
 *       @progresslaw.com       -> PLAW Snowflake -> writes SF_UID_PLAW
 *     Missing matches are logged and skipped — the next day's run will retry.
 */
class SyncEmployeeSfUids extends Command
{
    protected $signature = 'Sync:employee-sf-uids';

    protected $description = 'Backfill SF_UID_LDR / SF_UID_PLAW on TblEmployees by matching email against Snowflake USERS.';

    private const TARGET_ACCESS_LEVELS = [
        'Negotiator Baby',
        'Negotiator Legal',
        'Negotiator Liaison',
        'Negotiator Admin',
        'Sales Manager',
        'Settlement Manager', // added 2026-07-30 per Jacob (Paxton, Hunter, etc.)
    ];

    private const LDR_DOMAIN  = '@libertydebtrelief.com';
    private const PLAW_DOMAIN = '@progresslaw.com';

    public function handle(): int
    {
        $this->info('[INFO] SyncEmployeeSfUids: starting.');

        try {
            $cmd = DBConnector::fromEnvironment('ldr');
            $cmd->initializeSqlServer();
        } catch (\Throwable $e) {
            $this->error('Failed to initialize CMD SQL Server: ' . $e->getMessage());
            Log::error('SyncEmployeeSfUids: SQL Server init failed', ['exception' => $e]);
            return Command::FAILURE;
        }

        // Phase 0: validate every stored UID actually exists in its Snowflake.
        // NULL any that don't — catches column-swap errors (Paxton), stale UIDs
        // after company changes, and typos. Runs before Phase 1 so newly-NULLed
        // rows get re-filled in the same run.
        $this->info('[INFO] Phase 0: validating existing SF_UIDs against Snowflake USERS.');
        $nulledLdr  = 0;
        $nulledPlaw = 0;
        try {
            $nulledLdr = $this->validateAndNullInvalid($cmd, 'ldr', 'SF_UID_LDR');
        } catch (\Throwable $e) {
            $this->error('SF_UID_LDR validation failed: ' . $e->getMessage());
            Log::error('SyncEmployeeSfUids: SF_UID_LDR validation failed', ['exception' => $e]);
        }
        try {
            $nulledPlaw = $this->validateAndNullInvalid($cmd, 'plaw', 'SF_UID_PLAW');
        } catch (\Throwable $e) {
            $this->error('SF_UID_PLAW validation failed: ' . $e->getMessage());
            Log::error('SyncEmployeeSfUids: SF_UID_PLAW validation failed', ['exception' => $e]);
        }
        $this->info(sprintf('[INFO] Phase 0 complete. NULLed: SF_UID_LDR=%d, SF_UID_PLAW=%d.', $nulledLdr, $nulledPlaw));

        // Phase 1: fill blanks
        $this->info('[INFO] Phase 1: filling blank SF_UIDs by email lookup.');
        try {
            $candidates = $this->fetchCandidates($cmd);
        } catch (\Throwable $e) {
            $this->error('Failed to fetch candidates: ' . $e->getMessage());
            Log::error('SyncEmployeeSfUids: candidate query failed', ['exception' => $e]);
            return Command::FAILURE;
        }

        $this->info('[INFO] Candidates: ' . count($candidates));

        if (empty($candidates)) {
            $this->info('[INFO] Nothing to backfill.');
            return Command::SUCCESS;
        }

        // Split by domain and by which SF_UID column is still empty.
        $ldrCandidates  = []; // PK => lowercased email
        $plawCandidates = []; // PK => lowercased email

        foreach ($candidates as $row) {
            $pk    = (int) ($row['PK'] ?? 0);
            $email = strtolower(trim((string) ($row['Email'] ?? '')));
            if ($pk === 0 || $email === '') {
                continue;
            }

            $ldrEmpty  = $this->isBlank($row['SF_UID_LDR']  ?? null);
            $plawEmpty = $this->isBlank($row['SF_UID_PLAW'] ?? null);

            if ($ldrEmpty && $this->endsWith($email, self::LDR_DOMAIN)) {
                $ldrCandidates[$pk] = $email;
            }
            if ($plawEmpty && $this->endsWith($email, self::PLAW_DOMAIN)) {
                $plawCandidates[$pk] = $email;
            }
        }

        $this->info(sprintf(
            '[INFO] Lookups needed: LDR=%d, PLAW=%d.',
            count($ldrCandidates),
            count($plawCandidates)
        ));

        $ldrUpdated  = 0;
        $plawUpdated = 0;

        if (!empty($ldrCandidates)) {
            try {
                $ldrUpdated = $this->backfill($cmd, 'ldr', 'SF_UID_LDR', $ldrCandidates);
            } catch (\Throwable $e) {
                $this->error('LDR backfill failed: ' . $e->getMessage());
                Log::error('SyncEmployeeSfUids: LDR backfill failed', ['exception' => $e]);
            }
        }

        if (!empty($plawCandidates)) {
            try {
                $plawUpdated = $this->backfill($cmd, 'plaw', 'SF_UID_PLAW', $plawCandidates);
            } catch (\Throwable $e) {
                $this->error('PLAW backfill failed: ' . $e->getMessage());
                Log::error('SyncEmployeeSfUids: PLAW backfill failed', ['exception' => $e]);
            }
        }

        $this->info(sprintf(
            '[SUCCESS] Finished. NULLed (Phase 0): LDR=%d, PLAW=%d. Filled (Phase 1): LDR=%d, PLAW=%d.',
            $nulledLdr,
            $nulledPlaw,
            $ldrUpdated,
            $plawUpdated
        ));

        return Command::SUCCESS;
    }

    /**
     * Phase 0 helper: for every non-terminated employee with $column set,
     * verify the stored UID exists in $sfEnv's Snowflake USERS. NULL the ones
     * that don't. Runs across ALL access levels — wrong data is wrong data.
     */
    private function validateAndNullInvalid(DBConnector $cmd, string $sfEnv, string $column): int
    {
        $sql = "
            SELECT PK, Employee_Name, Email, {$column} AS UID
            FROM dbo.TblEmployees
            WHERE Term_Date IS NULL
              AND {$column} IS NOT NULL
              AND LTRIM(RTRIM(CAST({$column} AS NVARCHAR(50)))) <> ''
        ";

        $result = $cmd->querySqlServer($sql);
        $rows   = $result['data'] ?? [];

        if (empty($rows)) {
            return 0;
        }

        // Collect PKs grouped by UID (multiple employees can share a UID if data is duplicated).
        $uidToPks = []; // int UID => list of PKs
        $pkToInfo = []; // PK => [uid, name, email]  (for per-row logging)
        foreach ($rows as $row) {
            $pk  = (int) ($row['PK'] ?? 0);
            $uid = (int) ($row['UID'] ?? 0);
            if ($pk === 0 || $uid <= 0) {
                continue;
            }
            $uidToPks[$uid][] = $pk;
            $pkToInfo[$pk] = [
                'uid'   => $uid,
                'name'  => (string) ($row['Employee_Name'] ?? ''),
                'email' => (string) ($row['Email'] ?? ''),
            ];
        }

        $uids = array_keys($uidToPks);
        if (empty($uids)) {
            return 0;
        }

        $this->info(sprintf('[INFO] %s: verifying %d distinct UIDs in Snowflake USERS.', $column, count($uids)));

        $snowflake     = DBConnector::fromEnvironment($sfEnv);
        $existingUids  = $this->lookupExistingUids($snowflake, $uids);
        $invalidUids   = array_values(array_diff($uids, $existingUids));

        if (empty($invalidUids)) {
            $this->info(sprintf('[INFO] %s: all UIDs valid, nothing to NULL.', $column));
            return 0;
        }

        $this->info(sprintf('[INFO] %s: %d invalid UIDs found, NULLing.', $column, count($invalidUids)));

        // Collect PKs to NULL, and log each one.
        $pksToNull = [];
        foreach ($invalidUids as $uid) {
            foreach ($uidToPks[$uid] ?? [] as $pk) {
                $pksToNull[] = $pk;
                $info = $pkToInfo[$pk] ?? ['uid' => $uid, 'name' => '', 'email' => ''];
                $this->warn(sprintf(
                    '[WARN] %s: NULLing PK=%d (%s / %s) stale UID %d (not in %s Snowflake).',
                    $column,
                    $pk,
                    $info['name'],
                    $info['email'],
                    $info['uid'],
                    strtoupper($sfEnv)
                ));
                Log::info('SyncEmployeeSfUids: nulling stale UID', [
                    'column' => $column,
                    'pk'     => $pk,
                    'uid'    => $info['uid'],
                    'name'   => $info['name'],
                    'email'  => $info['email'],
                    'sf_env' => $sfEnv,
                ]);
            }
        }

        $nulled = 0;
        foreach (array_chunk($pksToNull, 500) as $chunk) {
            $ids = implode(', ', array_map('intval', $chunk));
            $updateSql = "UPDATE dbo.TblEmployees SET {$column} = NULL WHERE PK IN ({$ids})";
            $updateResult = $cmd->querySqlServer($updateSql);
            if (is_array($updateResult) && ($updateResult['success'] ?? true) === false) {
                $this->warn(sprintf(
                    '[WARN] %s: NULL UPDATE failed for chunk of %d rows: %s',
                    $column,
                    count($chunk),
                    $updateResult['error'] ?? 'unknown'
                ));
                continue;
            }
            $nulled += count($chunk);
        }

        return $nulled;
    }

    /**
     * Phase 0 helper: return the subset of $uids that exist in Snowflake USERS.
     *
     * @param  list<int>  $uids
     * @return list<int>
     */
    private function lookupExistingUids(DBConnector $snowflake, array $uids): array
    {
        if (empty($uids)) {
            return [];
        }

        $existing = [];
        foreach (array_chunk($uids, 500) as $chunk) {
            $idList = implode(', ', array_map('intval', $chunk));
            $sql    = "SELECT UID FROM USERS WHERE UID IN ({$idList})";

            try {
                $result = $snowflake->query($sql);
                $rows   = $result['data'] ?? [];
                foreach ($rows as $row) {
                    $uid = (int) $this->getField($row, 'UID');
                    if ($uid > 0) {
                        $existing[] = $uid;
                    }
                }
            } catch (\Throwable $e) {
                $this->warn('[WARN] Snowflake USERS lookup failed for chunk: ' . $e->getMessage());
                Log::warning('SyncEmployeeSfUids: UID existence lookup failed', [
                    'error' => $e->getMessage(),
                    'chunk_size' => count($chunk),
                ]);
            }
        }

        return $existing;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchCandidates(DBConnector $cmd): array
    {
        $levels = implode(', ', array_map(
            fn($l) => "'" . $this->escSql($l) . "'",
            self::TARGET_ACCESS_LEVELS
        ));

        $sql = "
            SELECT PK, Employee_Name, Email, SF_UID_LDR, SF_UID_PLAW
            FROM dbo.TblEmployees
            WHERE Term_Date IS NULL
              AND Access_Level IN ({$levels})
              AND (COALESCE(SF_UID_LDR, '') = '' OR COALESCE(SF_UID_PLAW, '') = '')
        ";

        $result = $cmd->querySqlServer($sql);
        return $result['data'] ?? [];
    }

    /**
     * Look up UIDs in Snowflake for the given candidates, then UPDATE TblEmployees.
     *
     * @param  array<int, string>  $pkToEmail  PK => lowercased email
     * @return int  rows updated
     */
    private function backfill(DBConnector $cmd, string $sfEnv, string $column, array $pkToEmail): int
    {
        $snowflake  = DBConnector::fromEnvironment($sfEnv);
        $emails     = array_values(array_unique(array_values($pkToEmail)));
        $emailToUid = $this->lookupUids($snowflake, $emails);

        if (empty($emailToUid)) {
            $this->info(sprintf('[INFO] %s: no matches in Snowflake USERS.', strtoupper($sfEnv)));
            return 0;
        }

        $this->info(sprintf(
            '[INFO] %s: %d Snowflake USERS matches.',
            strtoupper($sfEnv),
            count($emailToUid)
        ));

        $updated = 0;
        foreach ($pkToEmail as $pk => $email) {
            if (!isset($emailToUid[$email])) {
                $this->info(sprintf('[INFO] %s: no UID match for %s (PK=%d).', strtoupper($sfEnv), $email, $pk));
                continue;
            }

            $uid = (int) $emailToUid[$email];
            $sql = "UPDATE dbo.TblEmployees SET {$column} = {$uid} WHERE PK = " . (int) $pk;

            $result = $cmd->querySqlServer($sql);

            if (is_array($result) && ($result['success'] ?? true) === false) {
                $this->warn(sprintf(
                    '[WARN] %s: UPDATE failed for PK=%d (%s): %s',
                    strtoupper($sfEnv),
                    $pk,
                    $email,
                    $result['error'] ?? 'unknown'
                ));
                continue;
            }

            $updated++;
            $this->info(sprintf('[INFO] %s: PK=%d %s -> UID %d.', strtoupper($sfEnv), $pk, $email, $uid));
        }

        return $updated;
    }

    /**
     * Query Snowflake USERS for the given emails (case-insensitive).
     *
     * @param  list<string>  $emails
     * @return array<string, int>  lowercased email => UID
     */
    private function lookupUids(DBConnector $snowflake, array $emails): array
    {
        if (empty($emails)) {
            return [];
        }

        $values = implode(', ', array_map(
            fn($e) => "('" . $this->escSql(strtolower($e)) . "')",
            $emails
        ));

        $sql = "
            SELECT UID, LOWER(EMAIL) AS EMAIL
            FROM USERS
            WHERE LOWER(EMAIL) IN (SELECT column1 FROM VALUES {$values})
        ";

        $result = $snowflake->query($sql);
        $rows   = $result['data'] ?? [];

        $map = [];
        foreach ($rows as $row) {
            // Snowflake results tend to be UPPERCASE-keyed — do a case-insensitive lookup.
            $email = strtolower(trim((string) $this->getField($row, 'EMAIL')));
            $uid   = $this->getField($row, 'UID');

            if ($email === '' || $uid === null || $uid === '') {
                continue;
            }

            $uidInt = (int) $uid;

            if (isset($map[$email]) && $map[$email] !== $uidInt) {
                $this->warn(sprintf(
                    '[WARN] Multiple USERS UIDs for %s in Snowflake; keeping first (%d), ignoring %d.',
                    $email,
                    $map[$email],
                    $uidInt
                ));
                continue;
            }

            $map[$email] = $uidInt;
        }

        return $map;
    }

    /**
     * Case-insensitive array field lookup.
     *
     * @return mixed
     */
    private function getField(array $row, string $key)
    {
        foreach ($row as $k => $v) {
            if (strcasecmp($k, $key) === 0) {
                return $v;
            }
        }
        return null;
    }

    private function isBlank($value): bool
    {
        return $value === null || trim((string) $value) === '';
    }

    private function endsWith(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return true;
        }
        $len = strlen($needle);
        return $len <= strlen($haystack) && substr($haystack, -$len) === $needle;
    }

    private function escSql(string $value): string
    {
        return str_replace("'", "''", $value);
    }
}
