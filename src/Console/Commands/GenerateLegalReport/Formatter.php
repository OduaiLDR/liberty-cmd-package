<?php

namespace Cmd\Reports\Console\Commands\GenerateLegalReport;

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
    public function buildWorkbook(array $sheets, string $source): array
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->removeSheetByIndex(0);

        foreach ($sheets as $sheetData) {
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle($sheetData['title']);
            $sheet->setShowGridlines(false);
            $this->buildLegalSheet($sheet, $sheetData['rows'], $sheetData['prior_map']);
        }

        $spreadsheet->setActiveSheetIndexByName('Legal Report - Not Settled');
        $spreadsheet->getActiveSheet()->setSelectedCells('A1');

        $sourceLabel = $source === 'Progress Law' ? 'Progress Law' : $source;
        $filename = 'Legal Report - ' . $sourceLabel . ' - ' . date('m-d-Y') . '.xlsx';
        $path = storage_path('app/' . $filename);

        $writer = new Xlsx($spreadsheet);
        $writer->setPreCalculateFormulas(false);
        $writer->save($path);

        return [
            'filename' => $filename,
            'path' => $path,
        ];
    }

    public function sendReport(DBConnector $connector, string $path, string $filename, string $company, ?Command $console = null): void
    {
        if (!is_file($path)) {
            Log::warning('GenerateLegalReport: report file missing.', ['path' => $path]);
            if ($console) {
                $console->warn('[WARN] Legal report not sent (file missing).');
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
        $sourceLabel = $company === 'PLAW' ? 'Progress Law' : $company;
        $subject = 'Legal Report - ' . $sourceLabel . ' - ' . date('m/d/Y');
        $body = 'Please see the attached Legal Report for ' . $sourceLabel . ' for ' . date('m/d/Y') . '.';

        $sent = $email->sendMailUsingTblReports(
            $connector,
            ['LegalReport', 'Legal Report'],
            [$company],
            $subject,
            $body,
            $attachments,
            true
        );

        if ($console) {
            if ($sent) {
                $console->info('[INFO] Legal report sent.');
            } else {
                $console->warn('[WARN] Legal report not sent (no recipients or send failed).');
            }
        } elseif (!$sent) {
            Log::warning('GenerateLegalReport: failed to send email.');
        }
    }

    private function buildLegalSheet(Worksheet $sheet, array $rows, array $priorMap): void
    {
        $headers = [
            'ID',
            'Client',
            'DOB',
            'State',
            'Plan Name',
            'Summons Date',
            'Answer Date',
            'Original Creditor',
            'Debt Buyer',
            'Account Number',
            'Verified Amount',
            'SPA Balance',
            'POA Sent Date',
            'Legal Negotiator',
            'Attorney Assignment',
            'Legal Claim ID',
            'Settlement Date',
            'Negotiator Last Note',
            'Latest Note Date',
            'Prior Note Date',
        ];

        $sheet->fromArray($headers, null, 'A1');
        $this->styleHeader($sheet, 'A1:T1');

        $rowIndex = 2;
        foreach ($rows as $row) {
            $this->setIdCell($sheet, "A{$rowIndex}", $row['ID'] ?? null);
            $sheet->setCellValue("B{$rowIndex}", (string) ($row['CLIENT'] ?? ''));
            $this->setDateCell($sheet, "C{$rowIndex}", $row['DOB'] ?? null);
            $sheet->setCellValue("D{$rowIndex}", (string) ($row['STATE'] ?? ''));
            $sheet->setCellValue("E{$rowIndex}", (string) ($row['PLAN_NAME'] ?? ''));
            $this->setDateCell($sheet, "F{$rowIndex}", $row['SUMMONS_DATE'] ?? null);
            $this->setDateCell($sheet, "G{$rowIndex}", $row['ANSWER_DATE'] ?? null);
            $sheet->setCellValue("H{$rowIndex}", (string) ($row['ORIGINAL_CREDITOR'] ?? ''));
            $sheet->setCellValue("I{$rowIndex}", (string) ($row['DEBT_BUYER'] ?? ''));
            $sheet->setCellValueExplicit("J{$rowIndex}", (string) ($row['ACCOUNT_NUM'] ?? ''), DataType::TYPE_STRING);
            $sheet->setCellValue("K{$rowIndex}", (float) ($row['VERIFIED_AMOUNT'] ?? 0));
            $sheet->setCellValue("L{$rowIndex}", (float) ($row['SPA_BALANCE'] ?? 0));
            $this->setDateCell($sheet, "M{$rowIndex}", $row['POA_SENT_DATE'] ?? null);
            $sheet->setCellValue("N{$rowIndex}", (string) ($row['LEGAL_NEGOTIATOR'] ?? ''));
            $sheet->setCellValue("O{$rowIndex}", (string) ($row['ATTORNEY_ASSIGNMENT'] ?? ''));
            $sheet->setCellValue("P{$rowIndex}", (string) ($row['LEGAL_CLAIM_ID'] ?? ''));
            $this->setDateCell($sheet, "Q{$rowIndex}", $row['SETTLEMENT_DATE'] ?? null);
            $sheet->setCellValue("R{$rowIndex}", (string) ($row['NEGOTIATOR_LAST_NOTE'] ?? ''));
            $this->setDateCell($sheet, "S{$rowIndex}", $row['LATEST_NOTE_DATE'] ?? null);

            $priorDate = $priorMap[(string) ($row['ID'] ?? '')] ?? null;
            $this->setDateCell($sheet, "T{$rowIndex}", $priorDate);

            $rowIndex++;
        }

        $lastRow = max(2, $rowIndex - 1);

        $sheet->getStyle("C2:C{$lastRow}")->getNumberFormat()->setFormatCode('mm/dd/yyyy');
        $sheet->getStyle("F2:G{$lastRow}")->getNumberFormat()->setFormatCode('mm/dd/yyyy');
        $sheet->getStyle("M2:M{$lastRow}")->getNumberFormat()->setFormatCode('mm/dd/yyyy');
        $sheet->getStyle("Q2:Q{$lastRow}")->getNumberFormat()->setFormatCode('mm/dd/yyyy');
        $sheet->getStyle("S2:T{$lastRow}")->getNumberFormat()->setFormatCode('mm/dd/yyyy');
        $sheet->getStyle("K2:L{$lastRow}")->getNumberFormat()->setFormatCode('$#,##0.00');

        $sheet->getStyle("R2:R{$lastRow}")->getAlignment()->setWrapText(true);

        $this->applyBorders($sheet, "A1:T{$lastRow}");
        $this->applyAutoWidths($sheet, 20);
        $this->applyFont($sheet, "A1:T{$lastRow}");
        $this->applyVerticalAlignment($sheet, "A1:T{$lastRow}");
        $this->applyAlternatingRowShading($sheet, 2, $lastRow, 20);
        
        // Set Column R width AFTER autoWidths to override it
        $sheet->getColumnDimension('R')->setAutoSize(false);
        $sheet->getColumnDimension('R')->setWidth(60);
        
        // Make Plan Name column smaller
        $sheet->getColumnDimension('E')->setAutoSize(false);
        $sheet->getColumnDimension('E')->setWidth(45);
        
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

    private function setIdCell(Worksheet $sheet, string $cell, $value): void
    {
        if ($value === null || $value === '') {
            $sheet->setCellValue($cell, '');
            return;
        }

        if (is_numeric($value)) {
            $sheet->setCellValueExplicit($cell, (string) $value, DataType::TYPE_NUMERIC);
            return;
        }

        $sheet->setCellValue($cell, (string) $value);
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
}
