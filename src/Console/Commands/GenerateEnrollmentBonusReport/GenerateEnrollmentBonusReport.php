<?php

namespace Cmd\Reports\Console\Commands\GenerateEnrollmentBonusReport;

use Cmd\Reports\Services\DBConnector;
use Cmd\Reports\Services\EmailSenderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class GenerateEnrollmentBonusReport extends Command
{
    private const LT_SUBMITTED_STATUS_ID = 293285;

    private const PENDING_STATUS_TITLES = ['Submitted', 'Approved', 'Attorney Approved CFLN'];

    protected $signature = 'reports:generate-enrollment-bonus-report
                            {from? : Submitted date start YYYY-MM-DD (defaults to report period)}
                            {to? : Submitted date end YYYY-MM-DD (defaults to report period)}
                            {--download : Copy the workbook to Downloads}
                            {--no-email : Build workbook only, skip email}';

    protected $description = 'Generate the Enrollment Bonus Report (LDR / Progress Law enrollment summary).';

    public function handle(): int
    {
        try {
            [$from, $to] = $this->resolvePeriod(
                (string) ($this->argument('from') ?? ''),
                (string) ($this->argument('to') ?? '')
            );
            $exclusiveTo = date('Y-m-d', strtotime('+1 day', strtotime($to)));

            $sql = DBConnector::fromEnvironment('ldr');
            $sql->initializeSqlServer();
            $azureRows = $this->fetchAzureRows($sql, $from, $exclusiveTo);
            $enrollmentRows = $this->attachSnowflakeStatuses($azureRows, $from, $to);
            $enrolledContactIds = $this->contactIdsFromEnrollmentRows($enrollmentRows);
            $pendingRows = $this->fetchPendingRows($from, $to, $enrolledContactIds);
            $summary = $this->buildSummary($enrollmentRows, $pendingRows);

            $filename = "Enrollment Bonus Report - {$to}.xlsx";
            $path = storage_path("app/{$filename}");
            (new Formatter())->buildWorkbook($enrollmentRows, $pendingRows, $summary, $path);
            $this->info('Workbook created: ' . $path);

            if ($this->option('download')) {
                $this->info('Workbook copied to: ' . $this->copyToDownloads($path, $filename));
            }
            if ($this->option('no-email')) {
                $this->warn('[WARN] --no-email set; workbook kept under storage/app.');
            } else {
                $this->sendReport($sql, $path, $filename, $summary, $to);
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Enrollment Bonus Report failed: ' . $e->getMessage());
            Log::error('GenerateEnrollmentBonusReport failed', ['exception' => $e]);
            return self::FAILURE;
        }
    }

    private function fetchAzureRows(DBConnector $sql, string $from, string $exclusiveTo): array
    {
        $result = $sql->querySqlServer(
            "SELECT LLG_ID, Client, Debt_Amount, Enrollment_Status, Enrollment_Plan, Submitted_Date
             FROM TblEnrollment
             WHERE Submitted_Date >= ? AND Submitted_Date < ? AND LLG_ID IS NOT NULL
             ORDER BY Submitted_Date ASC, LLG_ID ASC",
            [$from, $exclusiveTo]
        );

        return $result['data'] ?? [];
    }

    private function resolvePeriod(string $fromInput, string $toInput): array
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('America/Los_Angeles'));
        $yesterday = $now->modify('-1 day')->format('Y-m-d');
        $dayOfMonth = (int) $now->format('j');

        if ($fromInput !== '' && $toInput !== '') {
            [$from, $to] = $this->parseDateRange($fromInput, $toInput);
            $monthEnd = date('Y-m-t', strtotime($from));
            $effectiveTo = min($to, $monthEnd, $yesterday);

            return [$from, $effectiveTo];
        }

        // Days 1-6 report the previous month; from the 7th report the current month.
        $monthAnchor = $dayOfMonth <= 6
            ? $now->modify('first day of last month')->format('Y-m-01')
            : $now->format('Y-m-01');
        $from = $monthAnchor;
        $monthEnd = date('Y-m-t', strtotime($from));
        $effectiveTo = min($monthEnd, $yesterday);

        return [$from, $effectiveTo];
    }

    private function parseDateRange(string $fromInput, string $toInput): array
    {
        $from = $this->normalizeDate($fromInput, 'from');
        $to = $this->normalizeDate($toInput, 'to');
        if ($from > $to) {
            throw new \InvalidArgumentException("Invalid date range: from ({$from}) must be on or before to ({$to}).");
        }

        return [$from, $to];
    }

    private function normalizeDate(string $value, string $label): string
    {
        $timestamp = strtotime($value);
        if ($timestamp === false) {
            throw new \InvalidArgumentException("Invalid {$label} date: {$value}");
        }
        $normalized = date('Y-m-d', $timestamp);
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $normalized)) {
            throw new \InvalidArgumentException("Invalid {$label} date: {$value}");
        }

        return $normalized;
    }

    private function contactIdsFromEnrollmentRows(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $id = (string) ($row['SNOWFLAKE_CONTACT_ID'] ?? '');
            if ($id !== '') {
                $ids[$id] = true;
            }
        }

        return $ids;
    }

    private function attachSnowflakeStatuses(array $azureRows, string $from, string $to): array
    {
        $grouped = ['ldr' => [], 'plaw' => []];
        $rows = [];
        foreach ($azureRows as $azure) {
            $llg = trim((string) $this->value($azure, 'LLG_ID', ''));
            $contactId = preg_replace('/^LLG-/i', '', $llg);
            if ($contactId === '' || ! $this->isValidContactId($contactId)) {
                continue;
            }
            $plan = (string) $this->value($azure, 'Enrollment_Plan', '');
            $source = stripos($plan, 'Progress') !== false ? 'plaw' : 'ldr';
            $rows[$contactId] = [
                'LLG_ID' => $llg,
                'CLIENT' => (string) $this->value($azure, 'Client', ''),
                'DEBT_AMOUNT' => (float) $this->value($azure, 'Debt_Amount', 0),
                'AZURE_STATUS' => (string) $this->value($azure, 'Enrollment_Status', ''),
                'ENROLLMENT_PLAN' => $plan,
                'SUBMITTED_DATE' => $this->value($azure, 'Submitted_Date', ''),
                'SNOWFLAKE_SOURCE' => strtoupper($source === 'plaw' ? 'PLAW' : 'LDR'),
                'SNOWFLAKE_CONTACT_ID' => $contactId,
                'STATUS_TITLE' => 'No Snowflake status found',
                'STATUS_STAMP_PT' => '',
                'ASOF_TITLE' => '',
                'ENROLLED' => false,
                'SNOWFLAKE_MATCH' => 'No',
            ];
            $grouped[$source][] = $contactId;
        }

        foreach ($grouped as $source => $ids) {
            if ($ids === []) {
                continue;
            }
            foreach ($this->fetchStatuses($source, $ids, $from, $to) as $id => $status) {
                if (isset($rows[$id])) {
                    $rows[$id]['STATUS_TITLE'] = $status['title'];
                    $rows[$id]['STATUS_STAMP_PT'] = $status['stamp_pt'];
                    $rows[$id]['ASOF_TITLE'] = $status['asof_title'];
                    $rows[$id]['ENROLLED'] = $status['enrolled'];
                    $rows[$id]['SNOWFLAKE_MATCH'] = 'Yes';
                }
            }
        }

        return array_values($rows);
    }

    private function fetchStatuses(string $source, array $ids, string $from, string $to): array
    {
        $connector = DBConnector::fromEnvironment($source);
        $map = [];
        $asOfExclusive = date('Y-m-d', strtotime('+1 day', strtotime($to)));
        $asOfEscaped = $this->escapeSqlLiteral($asOfExclusive);

        foreach (array_chunk($this->filterValidContactIds($ids), 500) as $chunk) {
            $values = $this->buildContactValuesClause($chunk);
            if ($values === '') {
                continue;
            }
            $sql = "
WITH requested AS (SELECT column1 AS CONTACT_ID_STR FROM VALUES {$values}), statuses AS (
 SELECT TO_VARCHAR(cs.CONTACT_ID) AS CONTACT_ID,
        CONVERT_TIMEZONE('America/Los_Angeles', cs.STAMP) AS STAMP_PT,
        TO_CHAR(CONVERT_TIMEZONE('America/Los_Angeles', cs.STAMP), 'YYYY-MM-DD HH24:MI:SS') AS STAMP_PT_STR,
        cls.TITLE,
        ROW_NUMBER() OVER (PARTITION BY cs.CONTACT_ID ORDER BY cs.STAMP DESC) AS current_rn,
        ROW_NUMBER() OVER (
            PARTITION BY cs.CONTACT_ID
            ORDER BY CASE WHEN CONVERT_TIMEZONE('America/Los_Angeles', cs.STAMP) < '{$asOfEscaped}' THEN 0 ELSE 1 END,
                     cs.STAMP DESC
        ) AS asof_rn
 FROM CONTACTS_STATUS cs
 LEFT JOIN CONTACTS_LEAD_STATUS cls ON cs.STATUS_ID = cls.ID
 INNER JOIN requested r ON TO_VARCHAR(cs.CONTACT_ID) = r.CONTACT_ID_STR
 WHERE cs.STATUS_ID > 0
)
SELECT c.CONTACT_ID, c.TITLE AS CURRENT_TITLE, c.STAMP_PT_STR AS CURRENT_STAMP,
       a.TITLE AS ASOF_TITLE, a.STAMP_PT_STR AS ASOF_STAMP
FROM (SELECT CONTACT_ID, STAMP_PT_STR, TITLE FROM statuses WHERE current_rn = 1) c
LEFT JOIN (SELECT CONTACT_ID, STAMP_PT_STR, TITLE FROM statuses WHERE asof_rn = 1) a ON a.CONTACT_ID = c.CONTACT_ID";
            foreach (($connector->query($sql)['data'] ?? []) as $row) {
                $id = (string) $this->value($row, 'CONTACT_ID', '');
                if ($id === '') {
                    continue;
                }
                $currentTitle = (string) $this->value($row, 'CURRENT_TITLE', '');
                $asofTitle = (string) $this->value($row, 'ASOF_TITLE', '');
                $map[$id] = [
                    'title' => $currentTitle,
                    'stamp_pt' => $this->normalizeSnowflakeStamp((string) $this->value($row, 'CURRENT_STAMP', '')),
                    'asof_title' => $asofTitle,
                    'enrolled' => $this->isEnrolledTitle($currentTitle) || $this->isEnrolledTitle($asofTitle),
                ];
            }
        }

        return $map;
    }

    private function fetchPendingRows(string $from, string $to, array $enrolledContactIds): array
    {
        $connector = DBConnector::fromEnvironment('lt');
        $ids = $this->fetchLtSubmittedIds($connector, $from, $to);
        $statuses = $this->fetchLtStatuses($connector, $ids);
        $plans = $this->fetchLtPlans($connector, array_keys($statuses));
        $debts = $this->fetchLtDebts($connector, array_keys($statuses));

        $pending = [];
        foreach ($statuses as $id => $status) {
            if (isset($enrolledContactIds[$id])) {
                continue;
            }
            if (! in_array($status['title'], self::PENDING_STATUS_TITLES, true)) {
                continue;
            }
            $plan = $plans[$id] ?? '';
            $pending[] = [
                'CONTACT_ID' => $id,
                'CLIENT' => $status['client'],
                'STATUS_TITLE' => $status['title'],
                'STATUS_STAMP_PT' => $status['stamp_pt'],
                'ENROLLMENT_PLAN' => $plan,
                'SOURCE' => stripos($plan, 'Progress') !== false ? 'PLAW' : 'LDR',
                'ENROLLED_DEBT' => $debts[$id] ?? 0.0,
            ];
        }

        return $pending;
    }

    private function fetchLtSubmittedIds(DBConnector $connector, string $from, string $to): array
    {
        $fromEscaped = $this->escapeSqlLiteral($from);
        $toEscaped = $this->escapeSqlLiteral($to);
        $statusId = self::LT_SUBMITTED_STATUS_ID;
        $sql = "SELECT DISTINCT TO_VARCHAR(CONTACT_ID) AS CONTACT_ID
                FROM CONTACTS_STATUS
                WHERE STATUS_ID = {$statusId}
                  AND CAST(CONVERT_TIMEZONE('America/Los_Angeles', STAMP) AS DATE) BETWEEN '{$fromEscaped}' AND '{$toEscaped}'";

        return array_values(array_filter(array_map(
            fn (array $row): string => (string) $this->value($row, 'CONTACT_ID', ''),
            $connector->query($sql)['data'] ?? []
        )));
    }

    private function fetchLtStatuses(DBConnector $connector, array $ids): array
    {
        $map = [];
        foreach (array_chunk($this->filterValidContactIds($ids), 500) as $chunk) {
            $values = $this->buildContactValuesClause($chunk);
            if ($values === '') {
                continue;
            }
            $sql = "
WITH requested AS (SELECT column1 AS CONTACT_ID_STR FROM VALUES {$values}), latest AS (
 SELECT cs.CONTACT_ID, cls.TITLE,
        TO_CHAR(CONVERT_TIMEZONE('America/Los_Angeles', cs.STAMP), 'YYYY-MM-DD HH24:MI:SS') AS STAMP_PT,
        CONCAT(c.FIRSTNAME, ' ', c.LASTNAME) AS CLIENT,
        ROW_NUMBER() OVER (PARTITION BY cs.CONTACT_ID ORDER BY cs.STAMP DESC) AS rn
 FROM CONTACTS_STATUS cs
 LEFT JOIN CONTACTS_LEAD_STATUS cls ON cs.STATUS_ID = cls.ID
 JOIN CONTACTS c ON c.ID = cs.CONTACT_ID
 INNER JOIN requested r ON TO_VARCHAR(cs.CONTACT_ID) = r.CONTACT_ID_STR
)
SELECT CONTACT_ID, TITLE, STAMP_PT, CLIENT FROM latest WHERE rn = 1";
            foreach (($connector->query($sql)['data'] ?? []) as $row) {
                $id = (string) $this->value($row, 'CONTACT_ID', '');
                if ($id !== '') {
                    $map[$id] = [
                        'title' => (string) $this->value($row, 'TITLE', ''),
                        'stamp_pt' => $this->normalizeSnowflakeStamp((string) $this->value($row, 'STAMP_PT', '')),
                        'client' => (string) $this->value($row, 'CLIENT', ''),
                    ];
                }
            }
        }

        return $map;
    }

    private function fetchLtPlans(DBConnector $connector, array $ids): array
    {
        $map = [];
        foreach (array_chunk($this->filterValidContactIds($ids), 500) as $chunk) {
            $values = $this->buildContactValuesClause($chunk);
            if ($values === '') {
                continue;
            }
            $sql = "SELECT DISTINCT ep.CONTACT_ID, ed.TITLE
                    FROM ENROLLMENT_PLAN ep
                    LEFT JOIN ENROLLMENT_DEFAULTS2 ed ON ep.PLAN_ID = ed.ID
                    INNER JOIN (SELECT column1 AS CONTACT_ID_STR FROM VALUES {$values}) r
                      ON TO_VARCHAR(ep.CONTACT_ID) = r.CONTACT_ID_STR";
            foreach (($connector->query($sql)['data'] ?? []) as $row) {
                $id = (string) $this->value($row, 'CONTACT_ID', '');
                if ($id !== '' && ! isset($map[$id])) {
                    $map[$id] = (string) $this->value($row, 'TITLE', '');
                }
            }
        }

        return $map;
    }

    private function fetchLtDebts(DBConnector $connector, array $ids): array
    {
        $map = [];
        foreach (array_chunk($this->filterValidContactIds($ids), 500) as $chunk) {
            $values = $this->buildContactValuesClause($chunk);
            if ($values === '') {
                continue;
            }
            $sql = "SELECT CONTACT_ID, SUM(ORIGINAL_DEBT_AMOUNT) AS ENROLLED_DEBT
                    FROM DEBTS
                    WHERE ENROLLED = 1
                      AND _FIVETRAN_DELETED = FALSE
                      AND TO_VARCHAR(CONTACT_ID) IN (SELECT column1 FROM VALUES {$values})
                    GROUP BY CONTACT_ID";
            foreach (($connector->query($sql)['data'] ?? []) as $row) {
                $id = (string) $this->value($row, 'CONTACT_ID', '');
                if ($id !== '') {
                    $map[$id] = (float) $this->value($row, 'ENROLLED_DEBT', 0);
                }
            }
        }

        return $map;
    }

    private function buildSummary(array $rows, array $pending): array
    {
        $categories = ['All Enrollments', 'Enrolled', 'Cancels', 'Reconsideration Pending', 'At-Risk', 'Pending'];
        $summary = [];
        foreach (['LDR', 'Progress Law', 'Combined'] as $column) {
            $summary[$column] = array_fill_keys($categories, 0.0);
        }

        foreach ($rows as $row) {
            $source = strtoupper((string) $row['SNOWFLAKE_SOURCE']) === 'PLAW' ? 'Progress Law' : 'LDR';
            $debt = (float) $row['DEBT_AMOUNT'];
            $category = $this->classifyStatus((string) $row['AZURE_STATUS']);
            if ((bool) ($row['ENROLLED'] ?? false)) {
                $category = 'Enrolled';
            }
            foreach ([$source, 'Combined'] as $column) {
                $summary[$column]['All Enrollments'] += $debt;
                $summary[$column][$category] += $debt;
            }
        }

        foreach ($pending as $row) {
            $source = $row['SOURCE'] === 'PLAW' ? 'Progress Law' : 'LDR';
            $debt = (float) $row['ENROLLED_DEBT'];
            foreach ([$source, 'Combined'] as $column) {
                $summary[$column]['Pending'] += $debt;
            }
        }

        foreach (['LDR', 'Progress Law', 'Combined'] as $column) {
            $summary[$column]['Projected'] = $summary[$column]['Enrolled'] + $summary[$column]['Pending'];
        }

        return $summary;
    }

    private function classifyStatus(string $status): string
    {
        $status = strtoupper(trim($status));

        return match (true) {
            $status === 'LDR ENROLLED', $status === 'PROLAW ENROLLED' => 'Enrolled',
            str_contains($status, 'CANCEL'), str_contains($status, 'DROPPED'), str_contains($status, 'SYSTEM CANCEL') => 'Cancels',
            str_contains($status, 'RECONSIDERATION PENDING') => 'Reconsideration Pending',
            default => 'At-Risk',
        };
    }

    private function isEnrolledTitle(string $title): bool
    {
        $title = strtoupper(trim($title));

        return $title === 'LDR ENROLLED' || $title === 'PROLAW ENROLLED';
    }

    private function sendReport(DBConnector $sql, string $path, string $filename, array $summary, string $to): void
    {
        $subject = 'Enrollment Bonus Report - ' . date('m/d/Y', strtotime($to));
        $body = '<p>Sales data through ' . $this->dataThroughLabel($to) . '</p>';
        $body .= '<table border="1"><tr><th>Enrollment Status</th><th>LDR</th><th>Progress Law</th><th>Combined</th></tr>';
        foreach (array_keys($summary['Combined'] ?? []) as $category) {
            $cells = '<td>' . htmlspecialchars($category) . '</td>';
            foreach (['LDR', 'Progress Law', 'Combined'] as $column) {
                $cells .= '<td>$' . number_format((float) ($summary[$column][$category] ?? 0), 0) . '</td>';
            }
            $body .= '<tr>' . $cells . '</tr>';
        }
        $body .= '</table>';
        $attachment = [
            'name' => $filename,
            'contentType' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'contentBytes' => base64_encode((string) file_get_contents($path)),
        ];
        $sent = (new EmailSenderService())->sendMailUsingTblReportsHtml(
            $sql,
            ['Enrollment Bonus Report', 'EnrollmentBonusReport'],
            ['LDR', 'PLAW'],
            $subject,
            $body,
            [$attachment],
            false,
            true
        );
        if (! $sent) {
            $this->warn('Enrollment Bonus Report email was not sent.');
        }
    }

    private function dataThroughLabel(string $to): string
    {
        return date('m/d/Y', strtotime($to));
    }

    private function copyToDownloads(string $path, string $filename): string
    {
        $profile = getenv('USERPROFILE') ?: getenv('HOME');
        if (! $profile) {
            throw new \RuntimeException('User profile directory is unavailable.');
        }
        $directory = rtrim($profile, '\\/') . DIRECTORY_SEPARATOR . 'Downloads';
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new \RuntimeException('Unable to create Downloads directory.');
        }
        $destination = $directory . DIRECTORY_SEPARATOR . $filename;
        if (! copy($path, $destination)) {
            throw new \RuntimeException('Unable to copy workbook to Downloads.');
        }

        return $destination;
    }

    private function value(array $row, string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $row)) {
            return $row[$key];
        }
        foreach ($row as $actualKey => $value) {
            if (strcasecmp((string) $actualKey, $key) === 0) {
                return $value;
            }
        }

        return $default;
    }

    private function normalizeSnowflakeStamp(string $stamp): string
    {
        $stamp = trim($stamp);
        if ($stamp === '') {
            return '';
        }
        if (preg_match('/^(\d{10,})(?:\.\d+)?/', $stamp, $matches)) {
            $seconds = (int) $matches[1];
            if ($seconds > 1000000000 && $seconds < 2000000000) {
                return date('Y-m-d H:i:s', $seconds);
            }
        }

        return $stamp;
    }

    private function escapeSqlLiteral(string $value): string
    {
        return str_replace("'", "''", $value);
    }

    private function isValidContactId(string $id): bool
    {
        return (bool) preg_match('/^\d+$/', $id);
    }

    private function filterValidContactIds(array $ids): array
    {
        return array_values(array_unique(array_filter(
            array_map('strval', $ids),
            fn (string $id): bool => $this->isValidContactId($id)
        )));
    }

    private function buildContactValuesClause(array $ids): string
    {
        $values = array_map(
            fn (string $id): string => "('" . $this->escapeSqlLiteral($id) . "')",
            $this->filterValidContactIds($ids)
        );

        return implode(', ', $values);
    }
}
