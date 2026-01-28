<?php

namespace Cmd\Reports\Console\Commands\GenerateScrubListReport;

use Cmd\Reports\Services\DBConnector;
use Cmd\Reports\Services\EmailSenderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class Formatter
{
    public function buildWorkbook(array $rows, array $coAppRows, string $source, string $reportDate): ?array
    {
        if (empty($rows)) {
            return null;
        }

        // Handle display names
        $displayName = $source;
        if ($source === 'Paramount') {
            $displayName = 'Paramount Law';
        } elseif ($source === 'PLAW') {
            $displayName = 'Progress Law';
        }

        $filename = "{$displayName} Scrub List Report.xlsx";
        $path = storage_path('app/' . $filename);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle("{$displayName} Scrub List");
        $sheet->setShowGridlines(false);

        // Build co-applicant lookup
        $coAppLookup = [];
        foreach ($coAppRows as $coApp) {
            $contactId = $coApp['CONTACT_ID'] ?? '';
            if ($contactId) {
                $coAppLookup[$contactId] = $coApp;
            }
        }

        // Merge header rows (removed Negotiator column)
        $sheet->mergeCells('A1:E1');
        $sheet->mergeCells('F1:I1');

        $sheet->setCellValue('A1', 'Applicant');
        $sheet->setCellValue('F1', 'Co-Applicant');

        // Sub-headers (VBA replaces underscores with spaces)
        $sheet->setCellValue('A2', 'ID');
        $sheet->setCellValue('B2', 'First Name');
        $sheet->setCellValue('C2', 'Last Name');
        $sheet->setCellValue('D2', 'SSN');
        $sheet->setCellValue('E2', 'DOB');
        $sheet->setCellValue('F2', 'First Name');
        $sheet->setCellValue('G2', 'Last Name');
        $sheet->setCellValue('H2', 'SSN');
        $sheet->setCellValue('I2', 'DOB');

        // Apply header styling
        $sheet->getStyle('A1:I2')->applyFromArray([
            'font' => ['bold' => true],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFD9D9D9']],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        ]);

        // Data rows
        $rowIndex = 3;
        foreach ($rows as $row) {
            $contactId = $row['ID'] ?? '';
            $coApp = $coAppLookup[$contactId] ?? null;

            $sheet->setCellValue("A{$rowIndex}", $contactId);
            $sheet->setCellValue("B{$rowIndex}", $row['FIRST_NAME'] ?? '');
            $sheet->setCellValue("C{$rowIndex}", $row['LAST_NAME'] ?? '');
            $sheet->setCellValue("D{$rowIndex}", $row['SSN'] ?? '');
            
            // Set DOB - just use the string value, Excel will format it
            $dob = $row['DOB'] ?? '';
            if ($dob && $dob !== 'FALSE') {
                $sheet->setCellValue("E{$rowIndex}", $dob);
            }
            
            if ($coApp) {
                $sheet->setCellValue("F{$rowIndex}", $coApp['FIRSTNAME'] ?? '');
                $sheet->setCellValue("G{$rowIndex}", $coApp['LASTNAME'] ?? '');
                $sheet->setCellValue("H{$rowIndex}", $coApp['SSN'] ?? '');
                
                // Set co-applicant DOB - just use the string value
                $coAppDob = $coApp['DOB'] ?? '';
                if ($coAppDob && $coAppDob !== 'FALSE') {
                    $sheet->setCellValue("I{$rowIndex}", $coAppDob);
                }
            }
            
            $rowIndex++;
        }

        $lastRow = $rowIndex - 1;

        // Format SSN columns with right alignment
        $sheet->getStyle("D3:D{$lastRow}")->getNumberFormat()->setFormatCode('###-##-####');
        $sheet->getStyle("D3:D{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->getStyle("H3:H{$lastRow}")->getNumberFormat()->setFormatCode('###-##-####');
        $sheet->getStyle("H3:H{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        // Right-align DOB columns (already formatted as strings from SQL)
        $sheet->getStyle("E3:E{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->getStyle("I3:I{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        // Borders
        $sheet->getStyle("A1:I{$lastRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

        // Set column widths - auto-width for most, fixed for DOB columns to prevent #######
        foreach (range('A', 'D') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        $sheet->getColumnDimension('E')->setWidth(12); // DOB
        foreach (range('F', 'H') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        $sheet->getColumnDimension('I')->setWidth(12); // Co-Applicant DOB

        // Alternating row shading
        for ($row = 3; $row <= $lastRow; $row++) {
            if ($row % 2 === 0) {
                $sheet->getStyle("A{$row}:I{$row}")
                    ->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()
                    ->setARGB('FFF5F7FA');
            }
        }

        // Freeze panes on first 2 rows
        $sheet->freezePane('A3');

        // Font
        $sheet->getStyle("A1:I{$lastRow}")->getFont()->setName('Calibri')->setSize(9);
        $sheet->setSelectedCells('A1');

        $writer = new Xlsx($spreadsheet);
        $writer->save($path);

        return [
            'filename' => $filename,
            'path' => $path,
        ];
    }

    public function sendReport(
        DBConnector $connector,
        string $path,
        string $filename,
        string $reportDate,
        string $source,
        ?Command $console = null
    ): bool {
        if (!is_file($path)) {
            Log::warning('GenerateScrubListReport: report file missing.', ['path' => $path]);
            if ($console) {
                $console->warn('[WARN] Scrub List report not sent (file missing).');
            }
            return false;
        }

        $attachments = [
            [
                'name' => $filename,
                'contentType' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'contentBytes' => base64_encode(file_get_contents($path)),
            ],
        ];

        $email = new EmailSenderService();
        
        // Match VBA email logic - handle display names
        $displaySource = $source;
        if ($source === 'Paramount') {
            $displaySource = 'Paramount Law';
        } elseif ($source === 'PLAW') {
            $displaySource = 'Progress Law';
        }
        
        $subject = "{$displaySource} Scrub List Report";
        $body = "Attached is the Scrub List Report for {$displaySource}.";

        $companyFilter = $this->resolveCompanyFilter($source);
        $companySlug = $this->resolveCompanySlug($source);
        $recipients = $this->fetchRecipientsFromTblReports(
            $connector,
            ['ScrubListReport', 'Scrub List Report'],
            [$companyFilter]
        );
        $extras = $this->parseRecipientList((string) env('REPORT_EXTRA_RECIPIENTS', ''));
        $recipients = array_merge($recipients, $extras);
        if ($companySlug !== '') {
            $recipients = $this->applyCompanyReplacement($recipients, $companySlug);
        }
        $recipients = array_values(array_unique(array_filter($recipients)));

        if (empty($recipients)) {
            Log::warning('GenerateScrubListReport: no recipients found for report.', [
                'reports' => ['ScrubListReport', 'Scrub List Report'],
                'companies' => [$companyFilter],
            ]);
            if ($console) {
                $console->warn('[WARN] Scrub List report not sent (no recipients found in TblReports).');
            }
            return false;
        }

        $sent = $email->sendMail($subject, $body, $recipients, [], [], $attachments);
        if ($sent) {
            $this->logToTblLog($connector, ['ScrubListReport', 'Scrub List Report'], [$companyFilter], 'SUCCESS');
        }

        if ($console) {
            if ($sent) {
                $console->info('[INFO] Scrub List report sent.');
            } else {
                $console->warn('[WARN] Scrub List report not sent (no recipients or send failed).');
            }
        } elseif (!$sent) {
            Log::warning('GenerateScrubListReport: failed to send email.');
        }

        return $sent;
    }

    private function resolveCompanyFilter(string $source): string
    {
        if ($source === 'PLAW') {
            return 'PLAW';
        }

        return 'LDR';
    }

    private function resolveCompanySlug(string $source): string
    {
        if ($source === 'LDR') {
            return 'libertydebtrelief';
        }

        if ($source === 'Paramount') {
            return 'paramountlaw';
        }

        if ($source === 'PLAW') {
            return 'progresslaw';
        }

        return '';
    }

    private function applyCompanyReplacement(array $recipients, string $companySlug): array
    {
        if ($companySlug === '') {
            return $recipients;
        }

        return array_map(function ($recipient) use ($companySlug) {
            if (!is_string($recipient)) {
                return $recipient;
            }

            return str_replace('[company]', $companySlug, $recipient);
        }, $recipients);
    }

    private function parseRecipientList(string $value): array
    {
        $normalized = str_replace([';', '|'], ',', $value);
        $parts = array_map('trim', explode(',', $normalized));

        return array_values(array_filter($parts, function ($email) {
            $lower = strtolower($email);
            return $email !== '' && $lower !== 'null' && $lower !== 'undefined';
        }));
    }

    private function fetchRecipientsFromTblReports(DBConnector $connector, array $reportNames, array $companies): array
    {
        if (empty($reportNames)) {
            return [];
        }

        $escapedReports = array_map(function ($name) {
            return "'" . str_replace("'", "''", (string) $name) . "'";
        }, $reportNames);
        $reportIn = implode(',', $escapedReports);

        $companyIn = '';
        if (!empty($companies)) {
            $escapedCompanies = array_map(function ($name) {
                return "'" . str_replace("'", "''", (string) $name) . "'";
            }, $companies);
            $companyIn = ' AND Company IN (' . implode(',', $escapedCompanies) . ')';
        }

        $columns = $this->getTblReportsColumns($connector);
        $reportColumn = $columns['report'] ?? null;
        $sendColumns = $columns['send'] ?? [];
        $companyColumn = $columns['company'] ?? null;

        if ($reportColumn === null || empty($sendColumns)) {
            Log::warning('GenerateScrubListReport: TblReports columns not found.', [
                'report_column' => $reportColumn,
                'send_columns' => $sendColumns,
            ]);
            return [];
        }

        $sendList = implode(', ', $sendColumns);
        $reportExpr = "LTRIM(RTRIM({$reportColumn}))";
        $companyClause = '';
        if ($companyColumn !== null && $companyIn !== '') {
            $companyExpr = "LTRIM(RTRIM({$companyColumn}))";
            $companyClause = str_replace('Company', $companyExpr, $companyIn);
        }

        $sql = "
            SELECT {$sendList}
            FROM dbo.TblReports
            WHERE {$reportExpr} IN ({$reportIn}) {$companyClause}
        ";
        $result = $connector->querySqlServer($sql);
        $rows = $result['data'] ?? [];
        $emails = $this->collectRecipients($rows, $sendColumns);

        if (empty($emails) && $companyClause !== '') {
            $fallbackSql = "
                SELECT {$sendList}
                FROM dbo.TblReports
                WHERE {$reportExpr} IN ({$reportIn})
            ";
            $fallbackResult = $connector->querySqlServer($fallbackSql);
            $fallbackRows = $fallbackResult['data'] ?? [];
            $emails = $this->collectRecipients($fallbackRows, $sendColumns);
            $rows = $fallbackRows;
        }

        if (empty($emails)) {
            Log::warning('GenerateScrubListReport: no recipients found for report.', [
                'reports' => $reportNames,
                'companies' => $companies,
                'report_column' => $reportColumn,
                'company_column' => $companyColumn,
                'send_columns' => $sendColumns,
                'row_count' => count($rows),
            ]);
        }

        return array_values(array_unique($emails));
    }

    private function getTblReportsColumns(DBConnector $connector): array
    {
        $result = $connector->querySqlServer("
            SELECT COLUMN_NAME
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = 'dbo'
              AND TABLE_NAME = 'TblReports'
        ");
        $rows = $result['data'] ?? [];
        $names = [];

        foreach ($rows as $row) {
            foreach ($row as $value) {
                if (is_string($value) && $value !== '') {
                    $names[] = $value;
                    break;
                }
            }
        }

        $lookup = array_flip($names);
        $reportColumn = null;
        if (isset($lookup['Report_Name'])) {
            $reportColumn = 'Report_Name';
        } elseif (isset($lookup['ReportName'])) {
            $reportColumn = 'ReportName';
        }

        $sendColumns = [];
        $sendSets = [
            ['Send_To', 'Send_CC', 'Send_BCC'],
            ['SendTo', 'SendCC', 'SendBCC'],
        ];
        foreach ($sendSets as $set) {
            $allFound = true;
            foreach ($set as $col) {
                if (!isset($lookup[$col])) {
                    $allFound = false;
                    break;
                }
            }
            if ($allFound) {
                $sendColumns = $set;
                break;
            }
        }

        return [
            'report' => $reportColumn,
            'send' => $sendColumns,
            'company' => $this->detectCompanyColumn($lookup),
        ];
    }

    private function detectCompanyColumn(array $lookup): ?string
    {
        foreach (['Company', 'Company_Name', 'CompanyName'] as $col) {
            if (isset($lookup[$col])) {
                return $col;
            }
        }

        return null;
    }

    private function collectRecipients(array $rows, array $keys): array
    {
        $emails = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            foreach ($keys as $key) {
                if (isset($row[$key]) && $row[$key] !== null && $row[$key] !== '') {
                    $emails = array_merge($emails, $this->parseRecipientList((string) $row[$key]));
                }
            }
        }

        return $emails;
    }

    private function logToTblLog(
        DBConnector $connector,
        array $reportNames,
        array $companies,
        string $status
    ): void {
        try {
            $reportName = !empty($reportNames) ? $reportNames[0] : 'Unknown';
            $company = !empty($companies) ? implode(', ', $companies) : 'All';

            $tableName = str_replace("'", "''", 'TblReports');
            $macro = str_replace("'", "''", substr($reportName, 0, 50));
            $description = str_replace("'", "''", substr("Generated {$reportName} for {$company}", 0, 255));
            $actionLabel = str_replace("'", "''", substr(strtoupper($reportName), 0, 255));
            $resultSummary = str_replace("'", "''", substr("Status={$status} Company={$company}", 0, 200));
            $timestamp = str_replace("'", "''", now()->format('Y-m-d H:i:s'));

            $sql = <<<SQL
DECLARE @hasPK BIT = CASE WHEN COL_LENGTH('dbo.TblLog', 'PK') IS NULL THEN 0 ELSE 1 END;
DECLARE @isIdentity BIT = CASE WHEN COLUMNPROPERTY(OBJECT_ID('dbo.TblLog'), 'PK', 'IsIdentity') = 1 THEN 1 ELSE 0 END;

IF @hasPK = 1 AND @isIdentity = 0
BEGIN
    DECLARE @nextPK INT = ISNULL((SELECT MAX([PK]) FROM [dbo].[TblLog]), 0) + 1;
    INSERT INTO [dbo].[TblLog] ([PK], [Table_Name], [Macro], [Description], [Action], [Result], [Timestamp])
    VALUES (@nextPK, '{$tableName}', '{$macro}', '{$description}', '{$actionLabel}', '{$resultSummary}', '{$timestamp}');
END
ELSE
BEGIN
    INSERT INTO [dbo].[TblLog] ([Table_Name], [Macro], [Description], [Action], [Result], [Timestamp])
    VALUES ('{$tableName}', '{$macro}', '{$description}', '{$actionLabel}', '{$resultSummary}', '{$timestamp}');
END;
SQL;

            $connector->querySqlServer($sql);
            Log::info("TblLog entry created for {$reportName}", ['company' => $company, 'status' => $status]);
        } catch (\Throwable $e) {
            Log::error('GenerateScrubListReport: failed to write to TblLog', [
                'reports' => $reportNames,
                'companies' => $companies,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
