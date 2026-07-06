<?php

namespace Cmd\Reports\Console\Commands;

use Cmd\Reports\Services\DBConnector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Sync SF_UID_LDR / SF_UID_PLAW on TblEmployees by matching Email against
 * Snowflake USERS.EMAIL on the respective account.
 *
 * Runs daily (schedule Mon–Fri 1AM via admin automation).
 *
 * Candidate filter on TblEmployees:
 *   - Term_Date IS NULL
 *   - Access_Level IN (Negotiator Baby, Negotiator Legal, Negotiator Liaison,
 *                      Negotiator Admin, Sales Manager)
 *   - AND (SF_UID_LDR is null/blank OR SF_UID_PLAW is null/blank)
 *
 * Domain drives which Snowflake gets queried:
 *   @libertydebtrelief.com -> LDR Snowflake -> writes SF_UID_LDR
 *   @progresslaw.com       -> PLAW Snowflake -> writes SF_UID_PLAW
 *
 * Missing matches are logged and skipped — the next day's run will retry.
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
            '[SUCCESS] Finished. Updated: LDR=%d, PLAW=%d.',
            $ldrUpdated,
            $plawUpdated
        ));

        return Command::SUCCESS;
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
