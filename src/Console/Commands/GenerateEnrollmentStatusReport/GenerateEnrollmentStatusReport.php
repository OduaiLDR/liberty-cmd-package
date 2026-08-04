<?php

namespace Cmd\Reports\Console\Commands\GenerateEnrollmentStatusReport;

use Cmd\Reports\Services\DBConnector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class GenerateEnrollmentStatusReport extends Command
{
    protected $signature = 'reports:generate-enrollment-status-report
                            {from=2026-07-01 : Submitted date start YYYY-MM-DD}
                            {to=2026-07-31 : Submitted date end YYYY-MM-DD}
                            {--download : Copy the workbook to the current user Downloads folder}';

    protected $description = 'Generate an Azure enrollment and Snowflake July status report.';

    public function handle(): int
    {
        $from = date('Y-m-d', strtotime((string) $this->argument('from')));
        $to = date('Y-m-d', strtotime((string) $this->argument('to')));
        $exclusiveTo = date('Y-m-d', strtotime('+1 day', strtotime($to)));
        $asOfLabel = date('m/d/Y', strtotime($to));

        $this->info("Enrollment status report: {$from} through {$to}.");

        try {
            $sql = DBConnector::fromEnvironment('ldr');
            $sql->initializeSqlServer();
            $azureRows = $this->fetchAzureRows($sql, $from, $exclusiveTo);
            $this->info('Azure rows: ' . count($azureRows));

            $rows = $this->attachSnowflakeStatuses($azureRows, $from, $to);
            $filename = "Enrollment Status Report - {$to}.xlsx";
            $path = storage_path("app/{$filename}");

            (new Formatter())->buildWorkbook($rows, $asOfLabel, $path);
            $this->info("Workbook created: {$path}");

            if ($this->option('download')) {
                $downloadPath = $this->copyToDownloads($path, $filename);
                $this->info("Workbook copied to: {$downloadPath}");
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Enrollment status report failed: ' . $e->getMessage());
            Log::error('GenerateEnrollmentStatusReport failed', ['exception' => $e]);
            return self::FAILURE;
        }
    }

    private function fetchAzureRows(DBConnector $sql, string $from, string $exclusiveTo): array
    {
        $result = $sql->querySqlServer(
            "SELECT LLG_ID, Client, Debt_Amount, Enrollment_Status, Enrollment_Plan, Submitted_Date
             FROM TblEnrollment
             WHERE Submitted_Date >= ?
               AND Submitted_Date < ?
               AND LLG_ID IS NOT NULL
             ORDER BY Submitted_Date ASC, LLG_ID ASC",
            [$from, $exclusiveTo]
        );

        return $result['data'] ?? [];
    }

    private function attachSnowflakeStatuses(array $azureRows, string $from, string $to): array
    {
        $grouped = ['ldr' => [], 'plaw' => []];
        $rows = [];

        foreach ($azureRows as $azure) {
            $rawId = trim((string) $this->value($azure, 'LLG_ID', ''));
            $contactId = preg_replace('/^LLG-/i', '', $rawId);
            if ($contactId === '') {
                continue;
            }

            $plan = (string) $this->value($azure, 'Enrollment_Plan', '');
            $source = stripos($plan, 'Progress') !== false ? 'plaw' : 'ldr';
            $rows[$contactId] = [
                'LLG_ID' => $rawId,
                'CLIENT' => (string) $this->value($azure, 'Client', ''),
                'DEBT_AMOUNT' => $this->value($azure, 'Debt_Amount', 0),
                'AZURE_STATUS' => (string) $this->value($azure, 'Enrollment_Status', ''),
                'ENROLLMENT_PLAN' => $plan,
                'SUBMITTED_DATE' => $this->value($azure, 'Submitted_Date', ''),
                'SNOWFLAKE_SOURCE' => strtoupper($source === 'plaw' ? 'PLAW' : 'LDR'),
                'SNOWFLAKE_CONTACT_ID' => $contactId,
                'STATUS_TITLE' => 'No Snowflake status found',
                'STATUS_STAMP_PT' => '',
                'SNOWFLAKE_MATCH' => 'No',
            ];
            $grouped[$source][] = $contactId;
        }

        foreach ($grouped as $source => $contactIds) {
            if ($contactIds === []) {
                continue;
            }
            $statusMap = $this->fetchStatuses($source, $contactIds, $from, $to);
            foreach ($statusMap as $contactId => $status) {
                if (!isset($rows[$contactId])) {
                    continue;
                }
                $rows[$contactId]['STATUS_TITLE'] = $status['title'];
                $rows[$contactId]['STATUS_STAMP_PT'] = $status['stamp_pt'];
                $rows[$contactId]['SNOWFLAKE_MATCH'] = 'Yes';
            }
        }

        return array_values($rows);
    }

    private function fetchStatuses(string $source, array $contactIds, string $from, string $to): array
    {
        $connector = DBConnector::fromEnvironment($source);
        $statusMap = [];
        $fromEscaped = str_replace("'", "''", $from);
        $toEscaped = str_replace("'", "''", $to);

        foreach (array_chunk(array_values(array_unique($contactIds)), 500) as $chunk) {
            $values = implode(', ', array_map(
                fn (string $id): string => "('" . str_replace("'", "''", $id) . "')",
                $chunk
            ));

            $sql = "
WITH requested AS (
    SELECT column1 AS CONTACT_ID_STR
    FROM VALUES {$values}
), latest AS (
    SELECT
        TO_VARCHAR(cs.CONTACT_ID) AS CONTACT_ID,
        CONVERT_TIMEZONE('America/Los_Angeles', cs.STAMP) AS STAMP_PT,
        cls.TITLE,
        ROW_NUMBER() OVER (
            PARTITION BY cs.CONTACT_ID
            ORDER BY cs.STAMP DESC
        ) AS rn
    FROM CONTACTS_STATUS AS cs
    LEFT JOIN CONTACTS_LEAD_STATUS AS cls
        ON cs.STATUS_ID = cls.ID
    INNER JOIN requested AS r
        ON TO_VARCHAR(cs.CONTACT_ID) = r.CONTACT_ID_STR
    WHERE cs.STATUS_ID > 0
      AND CAST(CONVERT_TIMEZONE('America/Los_Angeles', cs.STAMP) AS DATE)
          BETWEEN '{$fromEscaped}' AND '{$toEscaped}'
)
SELECT CONTACT_ID, STAMP_PT, TITLE
FROM latest
WHERE rn = 1
ORDER BY STAMP_PT DESC
";

            $result = $connector->query($sql);
            foreach ($result['data'] ?? [] as $row) {
                $id = (string) $this->value($row, 'CONTACT_ID', '');
                if ($id !== '') {
                    $statusMap[$id] = [
                        'title' => (string) $this->value($row, 'TITLE', ''),
                        'stamp_pt' => (string) $this->value($row, 'STAMP_PT', ''),
                    ];
                }
            }
        }

        return $statusMap;
    }

    private function copyToDownloads(string $path, string $filename): string
    {
        $profile = getenv('USERPROFILE') ?: getenv('HOME');
        if (!$profile) {
            throw new \RuntimeException('User profile directory is unavailable.');
        }
        $directory = rtrim($profile, '\\/') . DIRECTORY_SEPARATOR . 'Downloads';
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException("Unable to create Downloads directory: {$directory}");
        }
        $destination = $directory . DIRECTORY_SEPARATOR . $filename;
        if (!copy($path, $destination)) {
            throw new \RuntimeException("Unable to copy workbook to {$destination}");
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
}
