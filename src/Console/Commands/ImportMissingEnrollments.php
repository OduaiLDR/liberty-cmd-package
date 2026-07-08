<?php

namespace Cmd\Reports\Console\Commands;

use Cmd\Reports\Services\DBConnector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ImportMissingEnrollments extends Command
{
    protected $signature = 'enrollment:import-missing';

    protected $description = 'Insert new enrollments from Snowflake (LDR + PLAW) that are missing from TblEnrollment. Category is LDR or CCS only.';

    public function handle(): int
    {
        $this->info('[INFO] ImportMissingEnrollments: starting.');

        try {
            $sqlConnector = DBConnector::fromEnvironment('ldr');
            $sqlConnector->initializeSqlServer();
        } catch (\Throwable $e) {
            $this->error('Failed to initialize SQL Server: ' . $e->getMessage());
            return Command::FAILURE;
        }

        // Load ALL existing LLG_IDs for LDR and CCS rows — used to skip contacts already present
        $existingResult = $sqlConnector->querySqlServer(
            "SELECT LLG_ID FROM TblEnrollment WHERE Category IN ('LDR', 'CCS')"
        );
        $existingRows = is_array($existingResult)
            ? ($existingResult['data'] ?? (array_is_list($existingResult) ? $existingResult : []))
            : [];
        $existingIds = array_flip(array_column($existingRows, 'LLG_ID'));
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

            $inserted = $this->importFromSource($snowflake, $sqlConnector, $existingIds, strtoupper($source));
            $totalInserted += $inserted;
        }

        // Fix any agents that came through as '% User' — pull correct name from TblContacts
        $fixed = $this->fixUserAgents($sqlConnector);

        $this->info("\n" . str_repeat('=', 60));
        $this->info("[DONE] Total inserted: {$totalInserted} | Agent fixes applied: {$fixed}");

        return Command::SUCCESS;
    }

    private function importFromSource(
        DBConnector $snowflake,
        DBConnector $sqlConnector,
        array &$existingIds,
        string $source
    ): int {
        // Pull enrolled contacts from Snowflake with all fields needed for TblEnrollment.
        // Mirrors Jacob's ImportMissingEnrollments VBA exactly:
        //   - ENROLLED_DATE >= 2022-07-01 (program start)
        //   - Agent from USERS table (not TblContacts)
        //   - Debt_Amount = SUM(ORIGINAL_DEBT_AMOUNT) from enrolled DEBTS
        //   - Payment_Date_1 = first qualifying deposit PROCESS_DATE (N=1)
        //   - Cancel_Date from DROPPED_DATE
        //   - Category = 'CCS' if ed.TITLE contains 'CCS', else 'LDR'
        $sfSql = "
            SELECT *
            FROM (
                SELECT
                    c.ID,
                    c.STATE,
                    CONCAT(u.FIRSTNAME, ' ', u.LASTNAME)  AS AGENT,
                    CONCAT(c.FIRSTNAME, ' ', c.LASTNAME)  AS CLIENT,
                    d.DEBT,
                    TO_CHAR(c.ENROLLED_DATE, 'YYYY-MM-DD')  AS ENROLLED_DATE,
                    TO_CHAR(t.PROCESS_DATE,  'YYYY-MM-DD')  AS PAYMENT_DATE_1,
                    TO_CHAR(c.DROPPED_DATE,  'YYYY-MM-DD')  AS CANCEL_DATE,
                    ed.TITLE,
                    t.N
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
                        PROCESS_DATE,
                        ROW_NUMBER() OVER (
                            PARTITION BY CONTACT_ID
                            ORDER BY CONTACT_ID ASC, PROCESS_DATE ASC
                        ) AS N
                    FROM TRANSACTIONS
                    WHERE TRANS_TYPE    = 'D'
                      AND RETURNED_DATE IS NULL
                      AND CANCELLED     = 0
                ) AS t ON c.ID = t.CONTACT_ID
                LEFT JOIN ENROLLMENT_PLAN AS ep
                    ON c.ID = ep.CONTACT_ID
                LEFT JOIN ENROLLMENT_DEFAULTS2 AS ed
                    ON ep.PLAN_ID = ed.ID
                WHERE c.ENROLLED_DATE    >= '2022-07-01'
                  AND c._FIVETRAN_DELETED = FALSE
            )
            WHERE N = 1
               OR N IS NULL
        ";

        $sfResult  = $snowflake->query($sfSql);
        $sfRows    = $sfResult['data'] ?? (array_is_list($sfResult ?? []) ? $sfResult : []);
        $this->info("[INFO] {$source} Snowflake: " . count($sfRows) . " enrolled contacts returned");

        if (empty($sfRows)) {
            return 0;
        }

        // Filter to contacts not already in TblEnrollment
        $missing = [];
        foreach ($sfRows as $row) {
            $id = trim((string) ($row['ID'] ?? ''));
            if ($id === '') continue;
            $llgId = 'LLG-' . $id;
            if (!isset($existingIds[$llgId])) {
                $missing[$llgId] = $row;
            }
        }

        $this->info("[INFO] {$source}: " . count($missing) . " contacts missing from TblEnrollment");

        if (empty($missing)) {
            return 0;
        }

        $inserted = 0;
        $skipped  = 0;

        foreach ($missing as $llgId => $row) {
            $state        = $this->esc(trim((string) ($row['STATE']        ?? '')));
            $agent        = $this->esc(trim((string) ($row['AGENT']        ?? '')));
            $client       = $this->esc(trim((string) ($row['CLIENT']       ?? '')));
            $enrolledDate = trim((string) ($row['ENROLLED_DATE'] ?? ''));
            $paymentDate1 = trim((string) ($row['PAYMENT_DATE_1'] ?? ''));
            $cancelDate   = trim((string) ($row['CANCEL_DATE']   ?? ''));
            $title        = trim((string) ($row['TITLE']         ?? ''));
            $debt         = $row['DEBT'] ?? null;

            if ($enrolledDate === '') {
                $skipped++;
                continue;
            }

            // Category: 'CCS' if enrollment plan title contains 'CCS', else 'LDR'
            $category = (stripos($title, 'CCS') !== false) ? 'CCS' : 'LDR';

            $debtSql  = is_numeric($debt) ? (float) $debt : 'NULL';
            $pay1Sql  = $paymentDate1 !== '' ? "'{$this->esc($paymentDate1)}'" : 'NULL';
            $cxlSql   = $cancelDate   !== '' ? "'{$this->esc($cancelDate)}'"   : 'NULL';

            try {
                $sqlConnector->querySqlServer("
                    INSERT INTO TblEnrollment
                        (LLG_ID, Category, State, Agent, Client, Debt_Amount, Welcome_Call_Date, Payment_Date_1, Cancel_Date)
                    SELECT '{$llgId}', '{$category}', '{$state}', '{$agent}', '{$client}',
                           {$debtSql}, '{$this->esc($enrolledDate)}', {$pay1Sql}, {$cxlSql}
                    WHERE NOT EXISTS (
                        SELECT 1 FROM TblEnrollment WHERE LLG_ID = '{$llgId}'
                    )
                ");
                $inserted++;
                $existingIds[$llgId] = true; // prevent duplicate from PLAW pass

                if ($inserted % 50 === 0) {
                    $this->info("[INFO] {$source}: {$inserted} inserted so far...");
                }
            } catch (\Throwable $e) {
                $msg = $e->getMessage();
                if (
                    stripos($msg, 'duplicate')   !== false ||
                    stripos($msg, 'UNIQUE')       !== false ||
                    stripos($msg, 'PRIMARY KEY')  !== false
                ) {
                    $existingIds[$llgId] = true;
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

    private function fixUserAgents(DBConnector $sqlConnector): int
    {
        // Contacts synced from Snowflake sometimes have a generic '% User' agent name.
        // Fix those rows by pulling the real agent from TblContacts.
        $sql = "
            SELECT e.LLG_ID, c.Agent AS CorrectAgent
            FROM TblEnrollment AS e
            LEFT JOIN TblContacts AS c ON e.LLG_ID = c.LLG_ID
            WHERE e.Agent LIKE '% User'
              AND c.Agent IS NOT NULL
              AND c.Agent <> ''
              AND c.Agent NOT LIKE '% User'
        ";

        $result = $sqlConnector->querySqlServer($sql);
        $rows = is_array($result)
            ? ($result['data'] ?? (array_is_list($result) ? $result : []))
            : [];

        if (empty($rows)) {
            $this->info('[INFO] No agent fixes needed.');
            return 0;
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

        $this->info("[INFO] Fixed {$fixed} agent names from '% User' to correct name");
        return $fixed;
    }

    protected function esc(string $value): string
    {
        return str_replace("'", "''", $value);
    }
}
