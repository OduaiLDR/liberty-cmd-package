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

        $existingResult = $sqlConnector->querySqlServer(
            "SELECT LLG_ID, Client, State, Debt_Amount FROM TblEnrollment WHERE Category IN ('LDR', 'CCS')"
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
        $this->info('[INFO] Existing TblEnrollment rows (LDR + CCS): ' . count($existingIds));

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

        foreach ($missing as $llgId => $row) {
            $state        = $this->esc(trim((string) ($row['STATE']        ?? '')));
            $agent        = $this->esc(trim((string) ($row['AGENT']        ?? '')));
            $client       = $this->esc(trim((string) ($row['CLIENT']       ?? '')));
            $enrolledDate = trim((string) ($row['ENROLLED_DATE'] ?? ''));
            $cancelDate   = trim((string) ($row['CANCEL_DATE']   ?? ''));
            $title        = trim((string) ($row['TITLE']         ?? ''));
            $freq         = trim((string) ($row['PAYMENT_FREQUENCY'] ?? ''));
            $debt         = $row['DEBT'] ?? null;

            $normalizedFreq = strtoupper($freq);
            $isMonthly = ($normalizedFreq === 'MONTHLY' || $normalizedFreq === 'M' || $normalizedFreq === '' || $normalizedFreq === 'NULL');

            $paymentDate1 = trim((string) ($row['PAYMENT_DATE_1'] ?? ''));
            // If not monthly, populate Payment_Date_2; if monthly, keep null.
            $paymentDate2 = (!$isMonthly) ? trim((string) ($row['PAYMENT_DATE_2'] ?? '')) : '';

            if ($enrolledDate === '') {
                $skipped++;
                continue;
            }

            // Category: 'CCS' if enrollment plan title contains 'CCS', else 'LDR'
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

            $pdo = $sqlConnector->getSqlServerConnection();

            try {
                $pdo->beginTransaction();

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
                ");

                if (!is_array($insertResult) || ($insertResult['success'] ?? false) !== true) {
                    throw new \RuntimeException(
                        'SQL Server insert failed: ' . (string) ($insertResult['error'] ?? 'unknown error')
                    );
                }

                $affected = (int) ($insertResult['row_count'] ?? 0);
                $pdo->commit();

                if ($affected === 1) {
                    $inserted++;
                    $existingIds[$llgId] = true;
                    if ($personKey !== '') {
                        $existingPeople[$personKey] = true;
                    }
                } else {
                    $skipped++;
                    $existingIds[$llgId] = true;
                    if ($personKey !== '') {
                        $existingPeople[$personKey] = true;
                    }
                    Log::info('ImportMissingEnrollments: skipped existing enrollment', [
                        'source' => $source,
                        'llgId'  => $llgId,
                    ]);
                }

                if ($affected === 1 && $inserted % 50 === 0) {
                    $this->info("[INFO] {$source}: {$inserted} inserted so far...");
                }
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $msg = $e->getMessage();
                if (
                    stripos($msg, 'duplicate')   !== false ||
                    stripos($msg, 'UNIQUE')       !== false ||
                    stripos($msg, 'PRIMARY KEY')  !== false
                ) {
                    $existingIds[$llgId] = true;
                    if ($personKey !== '') {
                        $existingPeople[$personKey] = true;
                    }
                    $skipped++;
                    continue;
                }
                $this->warn("[WARN] Failed to insert {$llgId}: {$msg}");
                Log::warning('ImportMissingEnrollments: INSERT failed', [
                    'source' => $source,
                    'llgId'  => $llgId,
                    'error'  => $msg,
                ]);
            }
        }

        $this->info("[INFO] {$source}: inserted {$inserted}, skipped/duplicate {$skipped}");
        return $inserted;
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
              AND (e.Agent IS NULL OR e.Agent = '' OR e.Agent <> c.Agent)
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
        foreach ($rows as $row) {
            $llgId        = trim((string) ($row['LLG_ID']       ?? ''));
            $correctAgent = trim((string) ($row['CorrectAgent'] ?? ''));
            if ($llgId === '' || $correctAgent === '') continue;

            $sqlConnector->querySqlServer("
                UPDATE TblEnrollment
                SET    Agent = '{$this->esc($correctAgent)}'
                WHERE  LLG_ID = '{$this->esc($llgId)}'
            ");
            $fixed++;
        }

        $this->info("[INFO] Corrected {$fixed} enrollment agents from LT ownership");
        return $fixed;
    }

    /** Keep newest Modified per TP_ID. Blank TP_ID stays keyed by contact ID. */
    private function dedupeSnowflakeByTpId(array $rows): array
    {
        $best = [];
        foreach ($rows as $row) {
            $tpId = trim((string) ($row['TP_ID'] ?? ''));
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
