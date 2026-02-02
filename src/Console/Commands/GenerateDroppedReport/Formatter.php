<?php

namespace Cmd\Reports\Console\Commands\GenerateDroppedReport;

use Cmd\Reports\Services\DBConnector;
use Cmd\Reports\Services\EmailSenderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class Formatter
{
    public function buildWorkbook(array $rows, string $source, string $dateRange, bool $isMonday): ?array
    {
        $filenameDatePart = $isMonday ? str_replace('/', '-', $dateRange) : date('m-d-Y', strtotime($dateRange));
        $filename = "Dropped Report - {$source} - {$filenameDatePart}.xlsx";
        $path = storage_path('app/' . $filename);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle("Dropped Report - {$source}");
        $sheet->setShowGridlines(false);

        // Headers
        $headers = ['ID', 'Client', 'Enrolled Date', 'Dropped Date', 'Days Enrolled', 'Title', 'Enrolled Debt', 'Dropped Reason', 'Status'];
        $col = 'A';
        foreach ($headers as $header) {
            $sheet->setCellValue("{$col}1", ucwords(str_replace('_', ' ', $header)));
            $col++;
        }

        // Apply header styling
        $headerRange = 'A1:I1';
        $sheet->getStyle($headerRange)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF17853B']],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        ]);

        // Data rows
        $rowIndex = 2;
        foreach ($rows as $row) {
            $sheet->setCellValue("A{$rowIndex}", $row['ID'] ?? '');
            $sheet->setCellValue("B{$rowIndex}", $row['CLIENT'] ?? '');
            $sheet->setCellValue("C{$rowIndex}", $row['ENROLLED_DATE'] ?? '');
            $sheet->setCellValue("D{$rowIndex}", $row['DROPPED_DATE'] ?? '');
            $sheet->setCellValue("E{$rowIndex}", $row['DAYS_ENROLLED'] ?? '');
            $sheet->setCellValue("F{$rowIndex}", $row['TITLE'] ?? '');
            $sheet->setCellValue("G{$rowIndex}", (float)($row['ENROLLED_DEBT'] ?? 0));
            $sheet->setCellValue("H{$rowIndex}", $row['DROPPED_REASON'] ?? '');
            $sheet->setCellValue("I{$rowIndex}", $row['STATUS'] ?? '');
            $rowIndex++;
        }

        $lastRow = $rowIndex - 1;

        // Apply formatting
        $sheet->getStyle("C2:D{$lastRow}")->getNumberFormat()->setFormatCode('mm/dd/yyyy');
        $sheet->getStyle("E2:E{$lastRow}")->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getStyle("G2:G{$lastRow}")->getNumberFormat()->setFormatCode('$#,##0');

        // Borders
        $sheet->getStyle("A1:I{$lastRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

        // Auto-width
        foreach (range('A', 'I') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

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
        string $dateRange,
        string $source,
        ?Command $console = null,
        bool $isMonday = false
    ): void {
        if (!is_file($path)) {
            Log::warning('GenerateDroppedReport: report file missing.', ['path' => $path]);
            if ($console) {
                $console->warn('[WARN] Dropped report not sent (file missing).');
            }
            return;
        }

        $attachments = [
            [
                'name' => $filename,
                'contentType' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'contentBytes' => base64_encode(file_get_contents($path)),
            ],
        ];

        $email = new EmailSenderService();
        $displaySource = $source === 'PLAW' ? 'Progress Law' : $source;
        
        if ($isMonday) {
            $subject = "Dropped Report - {$displaySource} - {$dateRange}";
            $body = "Please see the attached Dropped Report for {$displaySource} for the date range: {$dateRange}.";
        } else {
            $subject = "Dropped Report - {$displaySource} - {$dateRange}";
            $body = "Please see the attached Dropped Report for {$displaySource} on {$dateRange}.";
        }

        $sent = $email->sendMailUsingTblReports(
            $connector,
            ['DroppedReport', 'Dropped Report'],
            [$source],
            $subject,
            $body,
            $attachments,
            true
        );

        if ($console) {
            if ($sent) {
                $console->info('[INFO] Dropped report sent.');
            } else {
                $console->warn('[WARN] Dropped report not sent (no recipients or send failed).');
            }
        } elseif (!$sent) {
            Log::warning('GenerateDroppedReport: failed to send email.');
        }

        // Insert log entry into TblLog
        $this->insertLogEntry($connector, $source, $dateRange, $console);
    }

    private function insertLogEntry(
        DBConnector $connector,
        string $source,
        string $dateRange,
        ?Command $console = null
    ): void {
        try {
            $tableName = $this->escapeSqlString('TblDroppedReport');
            $macro = $this->escapeSqlString('GenerateDroppedReport');
            $description = $this->escapeSqlString("DP_{$source} - Dropped Report generated for {$dateRange}");
            $action = $this->escapeSqlString('GENERATE_DROPPED_REPORT');
            $result = $this->escapeSqlString('SUCCESS');
            $timestamp = $this->escapeSqlString(date('Y-m-d H:i:s'));

            $logSql = <<<SQL
DECLARE @hasPK BIT = CASE WHEN COL_LENGTH('dbo.TblLog', 'PK') IS NULL THEN 0 ELSE 1 END;
DECLARE @isIdentity BIT = CASE WHEN COLUMNPROPERTY(OBJECT_ID('dbo.TblLog'), 'PK', 'IsIdentity') = 1 THEN 1 ELSE 0 END;

IF @hasPK = 1 AND @isIdentity = 0
BEGIN
    DECLARE @nextPK INT = ISNULL((SELECT MAX([PK]) FROM [dbo].[TblLog]), 0) + 1;
    INSERT INTO [dbo].[TblLog] ([PK], [Table_Name], [Macro], [Description], [Action], [Result], [Timestamp])
    VALUES (@nextPK, '{$tableName}', '{$macro}', '{$description}', '{$action}', '{$result}', '{$timestamp}');
END
ELSE
BEGIN
    INSERT INTO [dbo].[TblLog] ([Table_Name], [Macro], [Description], [Action], [Result], [Timestamp])
    VALUES ('{$tableName}', '{$macro}', '{$description}', '{$action}', '{$result}', '{$timestamp}');
END;
SQL;

            $queryResult = $connector->querySqlServer($logSql);
            
            if (is_array($queryResult) && isset($queryResult['success']) && $queryResult['success'] === false) {
                $errorMsg = $queryResult['error'] ?? 'Unknown SQL Server error';
                Log::error('GenerateDroppedReport: TblLog insert failed', ['error' => $errorMsg]);
                if ($console) {
                    $console->warn('[WARN] Failed to insert log entry: ' . $errorMsg);
                }
                return;
            }
            
            if ($console) {
                $console->info('[INFO] Log entry inserted into TblLog.');
            }
        } catch (\Throwable $e) {
            Log::error('GenerateDroppedReport: Failed to insert TblLog entry', ['exception' => $e]);
            if ($console) {
                $console->warn('[WARN] Failed to insert log entry: ' . $e->getMessage());
            }
        }
    }

    private function escapeSqlString(string $value): string
    {
        return str_replace("'", "''", $value);
    }
}
