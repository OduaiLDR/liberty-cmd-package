<?php

namespace Cmd\Reports\Console\Commands\GenerateCancellationReport;

use Cmd\Reports\Services\DBConnector;
use Cmd\Reports\Services\EmailSenderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Mirrors VBA Sub GenerateCancellationReport(Category As String) — two near-identical subs (LDR/
 * Progress Law). Each connects to its OWN Snowflake account only (LDR SF for the LDR sub, Progress Law
 * SF for the Progress Law sub — confirmed against real reference workbooks: "LDR - Cancellation
 * Report.xlsx"'s Data 1 is 100% rows from the 'ldr' Snowflake account, "Progress Law - Cancellation
 * Report.xlsx"'s Data 1 is 100% rows from the 'plaw' account — there is no cross-source merging)
 * and builds its own "staircase" cohort workbook: "{Category} - All Contacts" / "{Category} -
 * With Settlements" (the 5-block cohort tables, computed from that source's Data 1/Data 2) plus a
 * "Cancellation Report" sheet (the same 3 static company blocks — LDR/Progress Law/PLAW — reused
 * verbatim in both workbooks; only the block matching that source's own bucket(s) will show
 * non-zero data). All formulas reimplemented in CohortReportBuilder from the real template's
 * COUNTIFS formulas rather than guessed.
 *
 * The two raw Snowflake extracts ("Data 1", "Data 2") that feed those formulas are build inputs
 * only — never emailed themselves.
 *
 * Data 1 column E (TITLE) gets overwritten in place with the company bucket:
 * - Progress Law SF ('plaw' env): every contact is automatically "Progress Law".
 * - LDR SF ('ldr' env): PLAW-prefixed enrollment plan titles are "PLAW", everything else is "LDR".
 *
 * TESTING: emails directly to oduai@libertydebtrelief.com for now. Switch to the TblReports lookup
 * (Report_Name = 'CancellationReport', Company = LDR/PLAW — confirmed in TblReports) once verified.
 */
class GenerateCancellationReport extends Command
{
    protected $signature = 'Generate:cancellation-report
        {category? : LDR or Progress Law. If omitted, runs both.}
        {--no-email : Skip sending the email, just build the file}
        {--output= : Save the workbook to this path instead of the temp storage path (implies --no-email)}';

    protected $description = 'Generate the Cancellation Report staircase workbook ({Category} - All Contacts / {Category} - With Settlements / Cancellation Report) for LDR and Progress Law and email them.';

    private const NOTIFY_EMAIL = 'oduai@libertydebtrelief.com';

    private const CATEGORY_ENVS = ['LDR' => 'ldr', 'Progress Law' => 'plaw'];

    // Which Cancellation Report company blocks are possible per category's own Snowflake source:
    // the 'ldr' account can contain both LDR- and PLAW-titled enrollment plans (never Progress Law); the
    // 'plaw' account is 100% Progress Law.
    private const CATEGORY_CANCELLATION_COMPANIES = ['LDR' => ['LDR', 'PLAW'], 'Progress Law' => ['Progress Law']];

    public function handle(): int
    {
        // Data 1/Data 2 can each run tens of thousands of rows across the ~13-month rolling window.
        ini_set('memory_limit', '1024M');

        $categoryArg = $this->argument('category') ? $this->normalizeCategoryArg($this->argument('category')) : null;
        $categories = $categoryArg ? [$categoryArg] : ['LDR', 'Progress Law'];

        $hadFailure = false;
        foreach ($categories as $category) {
            if (!$this->buildAndSend($category)) {
                $hadFailure = true;
            }
        }

        return $hadFailure ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Builds and sends one company's staircase workbook, sourced entirely from that company's own
     * Snowflake account.
     */
    private function buildAndSend(string $category): bool
    {
        $this->info("[INFO] Cancellation Report ({$category}): starting.");

        $env = self::CATEGORY_ENVS[$category];

        try {
            $source = DBConnector::fromEnvironment($env);

            $data1 = $this->fetchData1($source, $env);
            $this->info("[{$category}] Data 1 rows: " . count($data1));

            $data2 = $this->fetchData2($source);
            $this->info("[{$category}] Data 2 rows: " . count($data2));

            $cohortBuilder = new CohortReportBuilder();
            $allContactsBlocks = $cohortBuilder->buildReportBlocks($data1);
            $withSettlementsBlocks = $cohortBuilder->buildReportBlocks($data2);
            $cancellationReportBlocks = $cohortBuilder->buildCancellationReportBlocks($data1, self::CATEGORY_CANCELLATION_COMPANIES[$category]);
        } catch (\Throwable $e) {
            $this->error("[{$category}] Failed to build Cancellation Report: " . $e->getMessage());
            Log::error('GenerateCancellationReport: build failed', ['exception' => $e, 'category' => $category]);
            return false;
        }

        $formatter = new Formatter();
        $workbook = $formatter->buildWorkbook($category, $allContactsBlocks, $withSettlementsBlocks, $cancellationReportBlocks);

        $outputPath = $this->option('output');
        if ($outputPath !== null && $workbook !== null) {
            copy($workbook['path'], $outputPath);
            $this->info("[{$category}] Workbook saved to {$outputPath}");
        }

        $skipEmail = $this->option('no-email') || $outputPath !== null;

        $sent = true;
        if (!$skipEmail) {
            $sent = $this->sendReport($category, $workbook);
        } else {
            $this->info("[{$category}] Skipping email send (--no-email or --output was used).");
        }

        if ($workbook !== null && is_file($workbook['path']) && $outputPath !== $workbook['path']) {
            @unlink($workbook['path']);
        }

        return $sent;
    }

    /**
     * VBA: WHERE ENROLLED_DATE >= DateSerial(Year(Date)-1, Month(Date)-1, 1) — a rolling ~13-month
     * window, scoped to a single Snowflake account (whichever the sub is running for). Column E
     * (TITLE) gets overwritten in place with the normalized company bucket.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchData1(DBConnector $snowflake, string $env): array
    {
        $startDate = $this->rollingStartDate();

        $sql = "
            SELECT c.ID, c.ENROLLED_DATE, c.DROPPED_DATE, t.PAYMENTS, ed.TITLE
            FROM CONTACTS AS c
            LEFT JOIN (
                SELECT CONTACT_ID, COUNT(*) AS PAYMENTS
                FROM TRANSACTIONS
                WHERE TRANS_TYPE = 'D'
                  AND CLEARED_DATE IS NOT NULL
                  AND RETURNED_DATE IS NULL
                GROUP BY CONTACT_ID
            ) AS t ON c.ID = t.CONTACT_ID
            LEFT JOIN ENROLLMENT_PLAN AS ep ON c.ID = ep.CONTACT_ID
            LEFT JOIN ENROLLMENT_DEFAULTS2 AS ed ON ep.PLAN_ID = ed.ID
            WHERE ENROLLED_DATE >= '{$this->esc($startDate)}'
        ";

        $rows = $snowflake->query($sql)['data'] ?? [];

        $result = [];
        foreach ($rows as $row) {
            // VBA: `.Range("E" & i).Value = "LDR"` — overwrites TITLE in place, doesn't add a column.
            $row['TITLE'] = $this->normalizeCompanyBucket($env, (string) ($row['TITLE'] ?? ''));
            $row['ENROLLED_DATE'] = $this->normalizeSnowflakeDate($row['ENROLLED_DATE'] ?? null);
            $row['DROPPED_DATE'] = $this->normalizeSnowflakeDate($row['DROPPED_DATE'] ?? null);
            $result[] = $row;
        }

        return $result;
    }

    /**
     * VBA: same window, restricted to contacts with at least one SETTLED debt. Same as Data 1 — no
     * category filter, no TITLE column at all (never selected).
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchData2(DBConnector $snowflake): array
    {
        $startDate = $this->rollingStartDate();

        $sql = "
            SELECT c.ID, c.ENROLLED_DATE, c.DROPPED_DATE, t.PAYMENTS
            FROM CONTACTS AS c
            LEFT JOIN (
                SELECT CONTACT_ID, COUNT(*) AS PAYMENTS
                FROM TRANSACTIONS
                WHERE TRANS_TYPE = 'D'
                  AND CLEARED_DATE IS NOT NULL
                  AND RETURNED_DATE IS NULL
                GROUP BY CONTACT_ID
            ) AS t ON c.ID = t.CONTACT_ID
            LEFT JOIN ENROLLMENT_PLAN AS ep ON c.ID = ep.CONTACT_ID
            LEFT JOIN ENROLLMENT_DEFAULTS2 AS ed ON ep.PLAN_ID = ed.ID
            WHERE ENROLLED_DATE >= '{$this->esc($startDate)}'
              AND c.ID IN (
                  SELECT CONTACT_ID FROM DEBTS WHERE SETTLED = 1
              )
        ";

        $rows = $snowflake->query($sql)['data'] ?? [];

        $result = [];
        foreach ($rows as $row) {
            $row['ENROLLED_DATE'] = $this->normalizeSnowflakeDate($row['ENROLLED_DATE'] ?? null);
            $row['DROPPED_DATE'] = $this->normalizeSnowflakeDate($row['DROPPED_DATE'] ?? null);
            $result[] = $row;
        }

        return $result;
    }

    private function rollingStartDate(): string
    {
        return (new \DateTimeImmutable())->modify('first day of this month')->modify('-13 months')->format('Y-m-d');
    }

    /**
     * Snowflake's REST API returns DATE columns as integer day-offsets from the Unix epoch
     * (1970-01-01), not Excel's 1899-12-30 epoch — convert to a plain Y-m-d string.
     */
    private function normalizeSnowflakeDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value) && !str_contains((string) $value, '-')) {
            $timestamp = ((int) $value) * 86400;
            return gmdate('Y-m-d', $timestamp);
        }

        $date = substr((string) $value, 0, 10);
        return strtotime($date) !== false ? $date : null;
    }

    private function normalizeCategoryArg(string $arg): string
    {
        $upper = strtoupper(trim($arg));

        if (in_array($upper, ['PROLAW', 'PLAW', 'PROGRESSLAW', 'PROGRESS LAW'], true)) {
            return 'Progress Law';
        }

        return 'LDR';
    }

    /**
     * Company bucket depends on which Snowflake account the contact came from, not just its
     * enrollment plan title:
     * - Progress Law SF ('plaw' env): every contact is automatically "Progress Law".
     * - LDR SF ('ldr' env): the enrollment plan title decides — PLAW-prefixed plans are PLAW,
     *   everything else is LDR.
     */
    private function normalizeCompanyBucket(string $env, string $title): string
    {
        if ($env === 'plaw') {
            return 'Progress Law';
        }

        return str_starts_with(strtoupper(trim($title)), 'PLAW') ? 'PLAW' : 'LDR';
    }

    private function sendReport(string $category, ?array $workbook): bool
    {
        $subject = "{$category} - Cancellation Report";
        $body = 'Please see the attached Cancellation report.';

        $attachments = [];
        if ($workbook !== null) {
            $attachments[] = [
                'name' => $workbook['filename'],
                'contentType' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'contentBytes' => base64_encode(file_get_contents($workbook['path'])),
            ];
        }

        $email = new EmailSenderService();
        $sent = $email->sendMail($subject, $body, [self::NOTIFY_EMAIL], [], [], $attachments);

        if ($sent) {
            $this->info("[{$category}] Cancellation Report emailed to " . self::NOTIFY_EMAIL);
        } else {
            $this->warn("[{$category}] Cancellation Report email failed to send.");
            Log::warning('GenerateCancellationReport: notification email failed.', ['category' => $category]);
        }

        return $sent;
    }

    private function esc(string $value): string
    {
        return str_replace("'", "''", $value);
    }
}
