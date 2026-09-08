<?php

namespace Cmd\Reports\Console\Commands;

use Cmd\Reports\Services\DBConnector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ImportMissingEnrollments extends Command
{
    protected $signature = 'enrollment:import-missing
                            {--dry-run : Report inserts and ownership repairs without changing SQL Server}';

    protected $description = 'Insert new enrollments from Snowflake (LDR + PLAW) that are missing from TblEnrollment. Category is LDR or CCS only.';

    public function handle(): int
    {
        ini_set('memory_limit', '512M');
        $this->info('[INFO] ImportMissingEnrollments: starting.');
        $dryRun = (bool) $this->option('dry-run');
        if ($dryRun) {
            $this->warn('[DRY RUN] No inserts or updates will be performed.');
        }

        try {
            $sqlConnector = DBConnector::fromEnvironment('ldr');
            $sqlConnector->initializeSqlServer();
        } catch (\Throwable $e) {
            $this->error('Failed to initialize SQL Server: ' . $e->getMessage());
            return Command::FAILURE;
        }

        // Load ALL enrollments for person-dup prevention (not only LDR/CCS).
        // Restricting to LDR/CCS let twin SF contact IDs re-insert when Category differed/null.
        $existingResult = $sqlConnector->querySqlServer(
            "SELECT LLG_ID, Client, State, Debt_Amount FROM TblEnrollment"
        );
        $existingRows = is_array($existingResult)
            ? ($existingResult['data'] ?? (array_is_list($existingResult) ? $existingResult : []))
            : [];
        $existingIds = [];
        $existingPeople = [];
        foreach ($existingRows as $row) {
            $llgId = trim((string) ($row['LLG_ID'] ?? ''));
            if ($llgId !== '') {
                $existingIds[$llgId] = true;
            }
            $personKey = $this->personKey(
                (string) ($row['Client'] ?? ''),
                (string) ($row['State'] ?? ''),
                $row['Debt_Amount'] ?? null
            );
            if ($personKey !== '') {
                $existingPeople[$personKey] = true;
            }
        }
        $this->info('[INFO] Existing TblEnrollment rows: ' . count($existingIds));

        $totalInserted = 0;

        foreach (['ldr', 'plaw'] as $source) {
            $this->info("\n" . str_repeat('=', 60));
            $this->info('Processing Snowflake source: ' . strtoupper($source));
            $this->info(str_repeat('=', 60));

            try {
                $snowflake = DBConnector::fromEnvironment($source);
            } catch (\Throwable $e) {
                $this->error('Failed to connect to ' . strtoupper($source) . ' Snowflake: ' . $e->getMessage());
                Log::error('ImportMissingEnrollments: Snowflake connect failed', [
                    'source' => $source,
                    'error'  => $e->getMessage(),
                ]);
                continue;
            }

            $inserted = $this->importFromSource($snowflake, $sqlConnector, $existingIds, $existingPeople, strtoupper($source), $dryRun);
            $totalInserted += $inserted;
        }

        // Fix any agents that came through as '% User' — pull correct name from TblContacts
        $fixed = $this->fixUserAgents($sqlConnector, $dryRun);

        $this->info("\n" . str_repeat('=', 60));
        $this->info("[DONE] Total inserted: {$totalInserted} | Agent fixes applied: {$fixed}");

        return Command::SUCCESS;
    }

    private function importFromSource(
        DBConnector $snowflake,
        DBConnector $sqlConnector,
        array &$existingIds,
        array &$existingPeople,
        string $source,
        bool $dryRun = false
    ): int {
        $sfSql = "
            SELECT
                c.ID,
                c.TP_ID,
                c.STATE,
                CONCAT(u.FIRSTNAME, ' ', u.LASTNAME)  AS AGENT,
                CONCAT(c.FIRSTNAME, ' ', c.LASTNAME)  AS CLIENT,
                d.DEBT,
                TO_CHAR(CONVERT_TIMEZONE('America/Los_Angeles', COALESCE(c.MODIFIED, c.CREATED)), 'YYYY-MM-DD HH24:MI:SS') AS MODIFIED,
                TO_CHAR(CAST(CONVERT_TIMEZONE('America/Los_Angeles', c.ENROLLED_DATE) AS DATE), 'YYYY-MM-DD') AS ENROLLED_DATE,
                TO_CHAR(CAST(CONVERT_TIMEZONE('America/Los_Angeles', c.DROPPED_DATE) AS DATE), 'YYYY-MM-DD') AS CANCEL_DATE,
                ed.TITLE,
                ep.FREQUENCY AS PAYMENT_FREQUENCY,
                t.PAYMENT_DATE_1,
                t.PAYMENT_DATE_2
            FROM CONTACTS AS c
            LEFT JOIN USERS AS u
                ON c.ASSIGNED_TO = u.UID
            LEFT JOIN (
                SELECT
                    CONTACT_ID,
                    SUM(ORIGINAL_DEBT_AMOUNT) AS DEBT
                FROM DEBTS
                WHERE ENROLLED          = 1
                  AND _FIVETRAN_DELETED = FALSE
                GROUP BY CONTACT_ID
            ) AS d ON c.ID = d.CONTACT_ID
            LEFT JOIN (
                SELECT
                    CONTACT_ID,
                    MAX(CASE WHEN N = 1 THEN TO_CHAR(CAST(CONVERT_TIMEZONE('America/Los_Angeles', PROCESS_DATE) AS DATE), 'YYYY-MM-DD') END) AS PAYMENT_DATE_1,
                    MAX(CASE WHEN N = 2 THEN TO_CHAR(CAST(CONVERT_TIMEZONE('America/Los_Angeles', PROCESS_DATE) AS DATE), 'YYYY-MM-DD') END) AS PAYMENT_DATE_2
                FROM (
                    SELECT
                        CONTACT_ID,
                        PROCESS_DATE,
                        ROW_NUMBER() OVER (
                            PARTITION BY CONTACT_ID
                            ORDER BY CONTACT_ID ASC, CONVERT_TIMEZONE('America/Los_Angeles', PROCESS_DATE) ASC
                        ) AS N
                    FROM TRANSACTIONS
                    WHERE TRANS_TYPE        = 'D'
                      AND _FIVETRAN_DELETED = FALSE
                )
                WHERE N <= 2
                GROUP BY CONTACT_ID
            ) AS t ON c.ID = t.CONTACT_ID
            LEFT JOIN ENROLLMENT_PLAN AS ep
                ON c.ID = ep.CONTACT_ID
            LEFT JOIN ENROLLMENT_DEFAULTS2 AS ed
                ON ep.PLAN_ID = ed.ID
            WHERE CAST(CONVERT_TIMEZONE('America/Los_Angeles', c.ENROLLED_DATE) AS DATE) >= '2022-07-01'
              AND c._FIVETRAN_DELETED = FALSE
              AND c.DEL = 'FALSE'
        ";

        $sfResult  = $snowflake->query($sfSql);
        $sfRows    = $sfResult['data'] ?? (array_is_list($sfResult ?? []) ? $sfResult : []);
        $this->info("[INFO] {$source} Snowflake: " . count($sfRows) . " enrolled contacts returned");

        if (empty($sfRows)) {
            return 0;
        }

        $sfRows = $this->dedupeSnowflakeByTpId($sfRows);
        $this->info("[INFO] {$source}: " . count($sfRows) . " after 1 per TP_ID (newest Modified)");

        $missing = [];
        foreach ($sfRows as $row) {
            $id = trim((string) ($row['ID'] ?? ''));
            if ($id === '') {
                continue;
            }
            $llgId = 'LLG-' . $id;
            if (isset($existingIds[$llgId])) {
                continue;
            }
            $personKey = $this->personKey(
                (string) ($row['CLIENT'] ?? ''),
                (string) ($row['STATE'] ?? ''),
                $row['DEBT'] ?? null
            );
            if ($personKey !== '' && isset($existingPeople[$personKey])) {
                continue;
            }
            $missing[$llgId] = $row;
        }

        // Twin SF contact IDs: if this ID is already External_ID on a remapped
        // TblContacts row that has enrollment, do not insert a second enroll.
        if ($missing !== []) {
            $missing = $this->excludeAlreadyEnrolledViaRemap($sqlConnector, $missing, $existingIds, $existingPeople);
        }

        $this->info("[INFO] {$source}: " . count($missing) . " contacts missing from TblEnrollment");

        if (empty($missing)) {
            return 0;
        }

        if ($dryRun) {
            $this->warn("[DRY RUN] {$source}: would insert " . count($missing) . ' missing enrollment(s).');
            return count($missing);
        }

        $inserted = 0;
        $skipped  = 0;
        $pdo = $sqlConnector->getSqlServerConnection();
        $contactAgents = $this->lookupContactAgents($sqlConnector, array_keys($missing));

        // One transaction per chunk — fewer commits than per-row, still NOT EXISTS safe.
        foreach (array_chunk($missing, 50, true) as $chunk) {
            try {
                $pdo->beginTransaction();

                foreach ($chunk as $llgId => $row) {
                    $state        = $this->esc(trim((string) ($row['STATE']        ?? '')));
                    $client       = $this->esc(trim((string) ($row['CLIENT']       ?? '')));
                    $enrolledDate = trim((string) ($row['ENROLLED_DATE'] ?? ''));
                    $cancelDate   = trim((string) ($row['CANCEL_DATE']   ?? ''));
                    $title        = trim((string) ($row['TITLE']         ?? ''));
                    $freq         = trim((string) ($row['PAYMENT_FREQUENCY'] ?? ''));
                    $debt         = $row['DEBT'] ?? null;
                    $contactId    = preg_replace('/^LLG-/i', '', $llgId);

                    $agent = $this->rosterAgentName((string) ($row['AGENT'] ?? ''));
                    if ($agent === '' && isset($contactAgents[$llgId])) {
                        $agent = $contactAgents[$llgId];
                    }
                    $agent = $this->esc($agent);

                    $normalizedFreq = strtoupper($freq);
                    $isMonthly = ($normalizedFreq === 'MONTHLY' || $normalizedFreq === 'M' || $normalizedFreq === '' || $normalizedFreq === 'NULL');

                    $paymentDate1 = trim((string) ($row['PAYMENT_DATE_1'] ?? ''));
                    $paymentDate2 = (!$isMonthly) ? trim((string) ($row['PAYMENT_DATE_2'] ?? '')) : '';

                    if ($enrolledDate === '') {
                        $skipped++;
                        continue;
                    }

                    $category = (stripos($title, 'CCS') !== false) ? 'CCS' : 'LDR';

                    $debtSql = is_numeric($debt) ? $this->normalizeDebt($debt) : 'NULL';
                    $pay1Sql  = $paymentDate1 !== '' ? "'{$this->esc($paymentDate1)}'" : 'NULL';
                    $pay2Sql  = $paymentDate2 !== '' ? "'{$this->esc($paymentDate2)}'" : 'NULL';
                    $freqSql  = $freq !== '' ? "'{$this->esc($freq)}'" : 'NULL';
                    $cxlSql   = $cancelDate   !== '' ? "'{$this->esc($cancelDate)}'"   : 'NULL';
                    $personKey = $this->personKey(
                        (string) ($row['CLIENT'] ?? ''),
                        (string) ($row['STATE'] ?? ''),
                        $debt
                    );
                    $personMatchSql = is_numeric($debt)
                        ? "Debt_Amount = {$debtSql}"
                        : 'Debt_Amount IS NULL';

                    $insertResult = $sqlConnector->querySqlServer("
                        INSERT INTO TblEnrollment
                            (LLG_ID, Category, State, Agent, Client, Debt_Amount, Welcome_Call_Date, Payment_Date_1, Payment_Date_2, Payment_Frequency, Cancel_Date)
                        SELECT '{$llgId}', '{$category}', '{$state}', '{$agent}', '{$client}',
                               {$debtSql}, '{$this->esc($enrolledDate)}', {$pay1Sql}, {$pay2Sql}, {$freqSql}, {$cxlSql}
                        WHERE NOT EXISTS (
                            SELECT 1
                            FROM TblEnrollment WITH (UPDLOCK, HOLDLOCK)
                            WHERE LLG_ID = '{$llgId}'
                               OR (Client = '{$client}' AND State = '{$state}' AND {$personMatchSql})
                        )
                        AND NOT EXISTS (
                            SELECT 1
                            FROM TblContacts AS c WITH (UPDLOCK, HOLDLOCK)
                            INNER JOIN TblEnrollment AS e ON e.LLG_ID = c.LLG_ID
                            WHERE c.External_ID = '{$this->esc($contactId)}'
                              AND c.LLG_ID <> '{$llgId}'
                        )
                    ");

                    if (!is_array($insertResult) || ($insertResult['success'] ?? false) !== true) {
                        throw new \RuntimeException(
                            'SQL Server insert failed: ' . (string) ($insertResult['error'] ?? 'unknown error')
                        );
                    }

                    $affected = (int) ($insertResult['row_count'] ?? 0);
                    $existingIds[$llgId] = true;
                    if ($personKey !== '') {
                        $existingPeople[$personKey] = true;
                    }

                    if ($affected === 1) {
                        $inserted++;
                    } else {
                        $skipped++;
                    }
                }

                $pdo->commit();
                if ($inserted > 0) {
                    $this->info("[INFO] {$source}: {$inserted} inserted so far...");
                }
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $msg = $e->getMessage();
                if (
                    stripos($msg, 'duplicate') !== false ||
                    stripos($msg, 'UNIQUE') !== false ||
                    stripos($msg, 'PRIMARY KEY') !== false
                ) {
                    foreach (array_keys($chunk) as $llgId) {
                        $existingIds[$llgId] = true;
                    }
                    $skipped += count($chunk);
                    continue;
                }
                $this->warn("[WARN] Chunk insert failed: {$msg}");
                Log::warning('ImportMissingEnrollments: chunk INSERT failed', [
                    'source' => $source,
                    'error'  => $msg,
                ]);
            }
        }

        $this->info("[INFO] {$source}: inserted {$inserted}, skipped/duplicate {$skipped}");
        return $inserted;
    }

    /**
     * Drop candidates whose SF contact id is already External_ID on a remapped
     * contact that has enrollment (prevents twin-ID person dups).
     *
     * @param array<string, array> $missing
     * @return array<string, array>
     */
    private function excludeAlreadyEnrolledViaRemap(
        DBConnector $sqlConnector,
        array $missing,
        array &$existingIds,
        array &$existingPeople
    ): array {
        $extIds = [];
        foreach (array_keys($missing) as $llgId) {
            $extIds[] = preg_replace('/^LLG-/i', '', $llgId);
        }
        $blocked = [];
        foreach (array_chunk($extIds, 500) as $batch) {
            $inList = implode(', ', array_map(fn($id) => "'" . $this->esc($id) . "'", $batch));
            $r = $sqlConnector->querySqlServer("
                SELECT c.External_ID, c.LLG_ID, e.Client, e.State, e.Debt_Amount
                FROM TblContacts AS c
                INNER JOIN TblEnrollment AS e ON e.LLG_ID = c.LLG_ID
                WHERE c.External_ID IN ({$inList})
                  AND c.LLG_ID <> 'LLG-' + CAST(c.External_ID AS VARCHAR(50))
            ");
            foreach ($r['data'] ?? [] as $row) {
                $ext = trim((string) ($row['External_ID'] ?? ''));
                if ($ext === '') {
                    continue;
                }
                $blocked['LLG-' . $ext] = true;
                $existingIds['LLG-' . $ext] = true;
                $pk = $this->personKey(
                    (string) ($row['Client'] ?? ''),
                    (string) ($row['State'] ?? ''),
                    $row['Debt_Amount'] ?? null
                );
                if ($pk !== '') {
                    $existingPeople[$pk] = true;
                }
            }
        }
        if ($blocked === []) {
            return $missing;
        }
        $kept = [];
        foreach ($missing as $llgId => $row) {
            if (isset($blocked[$llgId])) {
                continue;
            }
            $kept[$llgId] = $row;
        }
        $skipped = count($missing) - count($kept);
        if ($skipped > 0) {
            $this->info("[INFO] Skipped {$skipped} already enrolled via remapped External_ID");
        }
        return $kept;
    }

    /** @param list<string> $llgIds @return array<string, string> */
    private function lookupContactAgents(DBConnector $sqlConnector, array $llgIds): array
    {
        $map = [];
        foreach (array_chunk($llgIds, 500) as $batch) {
            $inList = implode(', ', array_map(fn($id) => "'" . $this->esc($id) . "'", $batch));
            $r = $sqlConnector->querySqlServer("
                SELECT LLG_ID, Agent FROM TblContacts
                WHERE LLG_ID IN ({$inList})
                  AND Agent IS NOT NULL AND Agent <> '' AND Agent NOT LIKE '% User'
            ");
            foreach ($r['data'] ?? [] as $row) {
                $llg = trim((string) ($row['LLG_ID'] ?? ''));
                $agent = $this->rosterAgentName((string) ($row['Agent'] ?? ''));
                if ($llg !== '' && $agent !== '') {
                    $map[$llg] = $agent;
                }
            }
        }
        return $map;
    }

    private function rosterAgentName(string $assignedTo): string
    {
        $name = trim($assignedTo);
        if ($name === '' || preg_match('/\bUser$/i', $name)) {
            return '';
        }
        return $name;
    }

    private function fixUserAgents(DBConnector $sqlConnector, bool $dryRun = false): int
    {
        // Correct blank or stale enrollment agents from the LT ownership table.
        $sql = "
            SELECT e.LLG_ID, c.Agent AS CorrectAgent
            FROM TblEnrollment AS e
            LEFT JOIN TblContacts AS c ON e.LLG_ID = c.LLG_ID
            WHERE c.Agent IS NOT NULL
              AND c.Agent <> ''
              AND c.Agent NOT LIKE '% User'
              AND (e.Agent IS NULL OR e.Agent = '' OR e.Agent LIKE '% User' OR e.Agent <> c.Agent)
        ";

        $result = $sqlConnector->querySqlServer($sql);
        $rows = is_array($result)
            ? ($result['data'] ?? (array_is_list($result) ? $result : []))
            : [];

        if (empty($rows)) {
            $this->info('[INFO] No agent fixes needed.');
            return 0;
        }

        if ($dryRun) {
            $this->warn('[DRY RUN] Would correct ' . count($rows) . ' enrollment agent(s) from LT ownership.');
            return count($rows);
        }

        $fixed = 0;
        foreach (array_chunk($rows, 500) as $chunk) {
            $cases = [];
            $ids = [];
            foreach ($chunk as $row) {
                $llgId = trim((string) ($row['LLG_ID'] ?? ''));
                $correctAgent = $this->rosterAgentName((string) ($row['CorrectAgent'] ?? ''));
                if ($llgId === '' || $correctAgent === '') {
                    continue;
                }
                $llgEsc = $this->esc($llgId);
                $agentEsc = $this->esc($correctAgent);
                $cases[] = "WHEN '{$llgEsc}' THEN '{$agentEsc}'";
                $ids[] = "'{$llgEsc}'";
            }
            if ($cases === []) {
                continue;
            }
            $sqlConnector->querySqlServer("
                UPDATE TblEnrollment
                SET Agent = CASE LLG_ID " . implode(' ', $cases) . " END
                WHERE LLG_ID IN (" . implode(', ', $ids) . ")
            ");
            $fixed += count($ids);
        }

        $this->info("[INFO] Corrected {$fixed} enrollment agents from LT ownership");
        return $fixed;
    }

    /** Keep newest Modified per TP_ID. Blank/fake TP_ID stays keyed by contact ID. */
    private function dedupeSnowflakeByTpId(array $rows): array
    {
        $best = [];
        foreach ($rows as $row) {
            $tpId = trim((string) ($row['TP_ID'] ?? ''));
            if ($this->isFakeExternalId($tpId)) {
                $tpId = '';
            }
            $id = (string) ($row['ID'] ?? '');
            $key = $tpId !== '' ? $tpId : ('ID-' . $id);
            $modified = (string) ($row['MODIFIED'] ?? '');
            if (!isset($best[$key])) {
                $best[$key] = $row;
                continue;
            }
            $existingModified = (string) ($best[$key]['MODIFIED'] ?? '');
            if ($modified > $existingModified
                || ($modified === $existingModified && $id > (string) ($best[$key]['ID'] ?? ''))
            ) {
                $best[$key] = $row;
            }
        }
        return array_values($best);
    }

    private function isFakeExternalId(string $tpId): bool
    {
        return in_array($tpId, ['0', '1234567840', 'UNKNOWN'], true);
    }

    private function personKey(string $client, string $state, $debt): string
    {
        $client = strtolower(trim($client));
        if ($client === '') {
            return '';
        }
        return $client . '|' . strtoupper(trim($state)) . '|' . $this->normalizeDebt($debt);
    }

    private function normalizeDebt($debt): string
    {
        if (!is_numeric($debt)) {
            return '';
        }
        return number_format((float) $debt, 2, '.', '');
    }

    protected function esc(string $value): string
    {
        return str_replace("'", "''", $value);
    }
}
