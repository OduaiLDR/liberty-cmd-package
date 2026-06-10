<?php

namespace Cmd\Reports\Console\Commands\GenerateGraduationReport;

use Cmd\Reports\Services\DBConnector;
use Cmd\Reports\Services\EmailSenderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class Formatter
{
    /**
     * Legacy "current amount" factor carried over from the VBA macro
     * (`.Range("G" & i).Value = .Range("G" & i).Value * 0.29`).
     */
    private const CURRENT_AMOUNT_FACTOR = 0.29;

    /**
     * @param  array<int, array<string, mixed>>  $grads
     * @param  array<int, array<string, mixed>>  $debts
     * @return array{filename:string, path:string}
     */
    public function buildWorkbook(array $grads, array $debts, string $label): array
    {
        $spreadsheet = new Spreadsheet();

        $gradSheet = $spreadsheet->getActiveSheet();
        $gradSheet->setTitle('Graduation Report');
        $gradSheet->setShowGridlines(false);
        $this->buildGraduationSheet($gradSheet, $grads);

        $debtSheet = $spreadsheet->createSheet();
        $debtSheet->setTitle('Debt Summary Report');
        $debtSheet->setShowGridlines(false);
        $this->buildDebtSheet($debtSheet, $debts);

        $spreadsheet->setActiveSheetIndex(0);
        $gradSheet->setSelectedCells('A1');

        $filename = 'Graduation Report - ' . $label . '.xlsx';
        $path = storage_path('app/' . $filename);

        $writer = new Xlsx($spreadsheet);
        $writer->setPreCalculateFormulas(false);
        $writer->save($path);

        return [
            'filename' => $filename,
            'path' => $path,
        ];
    }

    public function sendReport(DBConnector $connector, string $path, string $filename, string $label, ?Command $console = null): bool
    {
        if (!is_file($path)) {
            Log::warning('GenerateGraduationReport: report file missing.', ['path' => $path]);
            if ($console) {
                $console->warn('[WARN] Graduation report not sent (file missing).');
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
        $subject = 'Graduation Report';
        $body = 'Please see attached graduation report for ' . $label . '.<br><br>Thanks';

        // No company filter (the legacy report covered all companies); empty
        // companies array makes the helper skip the Company clause in TblReports.
        $sent = $email->sendMailUsingTblReports(
            $connector,
            ['GraduationReport', 'Graduation Report'],
            [],
            $subject,
            $body,
            $attachments,
            true
        );

        if ($console) {
            if ($sent) {
                $console->info('[INFO] Graduation report sent.');
            } else {
                $console->warn('[WARN] Graduation report not sent (no recipients or send failed).');
            }
        } elseif (!$sent) {
            Log::warning('GenerateGraduationReport: failed to send email.');
        }

        return $sent;
    }

    /**
     * Sheet 1: graduated clients.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function buildGraduationSheet(Worksheet $sheet, array $rows): void
    {
        $headers = ['LLG ID', 'Enrollment Plan', 'Client', 'Graduation Date'];
        $sheet->fromArray($headers, null, 'A1');
        $this->styleHeader($sheet, 'A1:D1');

        $rowIndex = 2;
        foreach ($rows as $row) {
            $sheet->setCellValue("A{$rowIndex}", (string) ($row['LLG_ID'] ?? ''));
            $sheet->setCellValue("B{$rowIndex}", (string) ($row['Enrollment_Plan'] ?? ''));
            $sheet->setCellValue("C{$rowIndex}", (string) ($row['Client'] ?? ''));
            $this->setDateCell($sheet, "D{$rowIndex}", $row['Grad_Date'] ?? null);
            $rowIndex++;
        }

        $lastRow = max(2, $rowIndex - 1);

        $sheet->getStyle("D2:D{$lastRow}")->getNumberFormat()->setFormatCode('mm/dd/yyyy');

        $this->applyBorders($sheet, "A1:D{$lastRow}");
        $this->applyAutoWidths($sheet, 4);
        $this->applyFont($sheet, "A1:D{$lastRow}");
        $this->applyVerticalAlignment($sheet, "A1:D{$lastRow}");
        $this->applyAlternatingRowShading($sheet, 2, $lastRow, 4);

        $sheet->freezePane('A2');
    }

    /**
     * Sheet 2: debt/settlement detail.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function buildDebtSheet(Worksheet $sheet, array $rows): void
    {
        $headers = [
            'LLG ID',
            'Creditor',
            'Debt Buyer',
            'Settlement ID',
            'Account',
            'Original Debt Amount',
            'Current Amount',
            'Settlement Amount',
            'Type',
            'Settlement Date',
        ];
        $sheet->fromArray($headers, null, 'A1');
        $this->styleHeader($sheet, 'A1:J1');

        $rowIndex = 2;
        foreach ($rows as $row) {
            $sheet->setCellValue("A{$rowIndex}", (string) ($row['LLG_ID'] ?? ''));
            $sheet->setCellValue("B{$rowIndex}", (string) ($row['Creditor'] ?? ''));

            // Debt_Buyer is stored as '0' (string) when there is no buyer.
            $buyer = (string) ($row['Debt_Buyer'] ?? '');
            $sheet->setCellValue("C{$rowIndex}", ($buyer === '0' || $buyer === '') ? '' : $buyer);

            $sheet->setCellValue("D{$rowIndex}", (string) ($row['Settlement_ID'] ?? ''));

            // Account: last 4 (VBA Right(...,4)).
            $account = (string) ($row['Account_Number'] ?? '');
            $sheet->setCellValue("E{$rowIndex}", $account === '' ? '' : substr($account, -4));

            $sheet->setCellValue("F{$rowIndex}", (float) ($row['Original_Debt_Amount'] ?? 0));
            $sheet->setCellValue("G{$rowIndex}", (float) ($row['Current_Amount'] ?? 0) * self::CURRENT_AMOUNT_FACTOR);
            $sheet->setCellValue("H{$rowIndex}", (float) ($row['Settlement_Amount'] ?? 0));
            $sheet->setCellValue("I{$rowIndex}", 'Actual');
            $this->setDateCell($sheet, "J{$rowIndex}", $row['Settlement_Date'] ?? null);

            $rowIndex++;
        }

        $lastRow = max(2, $rowIndex - 1);

        $sheet->getStyle("F2:H{$lastRow}")->getNumberFormat()->setFormatCode('$#,##0.00');
        $sheet->getStyle("J2:J{$lastRow}")->getNumberFormat()->setFormatCode('mm/dd/yyyy');

        $this->applyBorders($sheet, "A1:J{$lastRow}");
        $this->applyAutoWidths($sheet, 10);
        $this->applyFont($sheet, "A1:J{$lastRow}");
        $this->applyVerticalAlignment($sheet, "A1:J{$lastRow}");
        $this->applyAlternatingRowShading($sheet, 2, $lastRow, 10);

        $sheet->freezePane('A2');
    }

    private function styleHeader(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->getFont()->setBold(true);
        $sheet->getStyle($range)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF17853B');
        $sheet->getStyle($range)->getFont()->getColor()->setARGB('FFFFFFFF');
    }

    private function applyBorders(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    }

    private function applyAutoWidths(Worksheet $sheet, int $columns): void
    {
        for ($i = 1; $i <= $columns; $i++) {
            $sheet->getColumnDimensionByColumn($i)->setAutoSize(true);
        }
    }

    private function applyFont(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->getFont()->setName('Calibri')->setSize(9);
    }

    private function applyVerticalAlignment(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->getAlignment()->setVertical(Alignment::VERTICAL_TOP);
    }

    private function applyAlternatingRowShading(Worksheet $sheet, int $startRow, int $endRow, int $columns): void
    {
        for ($row = $startRow; $row <= $endRow; $row++) {
            if ($row % 2 === 0) {
                $lastCol = chr(64 + $columns);
                $sheet->getStyle("A{$row}:{$lastCol}{$row}")
                    ->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()
                    ->setARGB('FFF5F7FA');
            }
        }
    }

    private function setDateCell(Worksheet $sheet, string $cell, $value): void
    {
        if ($value === null || $value === '') {
            $sheet->setCellValue($cell, '');
            return;
        }

        $excelDate = ExcelDate::PHPToExcel($value);
        if ($excelDate === false) {
            $sheet->setCellValue($cell, (string) $value);
            return;
        }

        $sheet->setCellValue($cell, $excelDate);
    }
}
