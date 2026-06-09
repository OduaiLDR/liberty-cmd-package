<?php

namespace Cmd\Reports\Console\Commands\GeneratePauseHoldReport;

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
    public function buildWorkbook(array $rows, string $source): array
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $sheetTitle = 'Pause Hold Report - ' . date('m-d-Y');
        $sheet->setTitle($this->truncateSheetTitle($sheetTitle));
        $sheet->setShowGridlines(false);

        $this->buildPauseHoldSheet($sheet, $rows);

        $sheet->setSelectedCells('A1');

        $filename = 'Pause Hold Report - ' . $source . ' - ' . date('m-d-Y') . '.xlsx';
        $path = storage_path('app/' . $filename);

        $writer = new Xlsx($spreadsheet);
        $writer->setPreCalculateFormulas(false);
        $writer->save($path);

        return [
            'filename' => $filename,
            'path' => $path,
        ];
    }

    public function sendReport(DBConnector $connector, string $path, string $filename, string $company, ?Command $console = null): bool
    {
        if (!is_file($path)) {
            Log::warning('GeneratePauseHoldReport: report file missing.', ['path' => $path]);
            if ($console) {
                $console->warn('[WARN] Pause Hold report not sent (file missing).');
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
        $subject = 'Pause Hold Report - ' . $company . ' - ' . date('m/d/Y');
        $body = 'Please see the attached Pause Hold report for ' . $company . ' on ' . date('m/d/Y') . '.';

        $sent = $email->sendMailUsingTblReports(
            $connector,
            ['PauseHoldReport', 'Pause Hold Report'],
            [$company],
            $subject,
            $body,
            $attachments,
            true
        );

        if ($console) {
            if ($sent) {
                $console->info('[INFO] Pause Hold report sent.');
            } else {
                $console->warn('[WARN] Pause Hold report not sent (no recipients or send failed).');
            }
        } elseif (!$sent) {
            Log::warning('GeneratePauseHoldReport: failed to send email.');
        }

        return $sent;
    }

    private function buildPauseHoldSheet(Worksheet $sheet, array $rows): void
    {
        $headers = [
            'Contact ID',
            'Status Date',
            'Days',
        ];

        $sheet->fromArray($headers, null, 'A1');
        $this->styleHeader($sheet, 'A1:C1');

        $rowIndex = 2;
        foreach ($rows as $row) {
            $this->setIdCell($sheet, "A{$rowIndex}", $row['CONTACT_ID'] ?? null);
            $this->setDateCell($sheet, "B{$rowIndex}", $row['STATUS_DATE'] ?? null);

            $days = $row['DAYS'] ?? null;
            if ($days === null || $days === '') {
                $sheet->setCellValue("C{$rowIndex}", '');
            } else {
                $sheet->setCellValueExplicit("C{$rowIndex}", (string) (int) $days, DataType::TYPE_NUMERIC);
            }

            $rowIndex++;
        }

        $lastRow = max(2, $rowIndex - 1);

        $sheet->getStyle("B2:B{$lastRow}")->getNumberFormat()->setFormatCode('yyyy/mm/dd');
        $sheet->getStyle("C2:C{$lastRow}")->getNumberFormat()->setFormatCode('0');

        $this->applyBorders($sheet, "A1:C{$lastRow}");
        $this->applyAutoWidths($sheet, 3);
        $this->applyFont($sheet, "A1:C{$lastRow}");
        $this->applyVerticalAlignment($sheet, "A1:C{$lastRow}");
        $this->applyAlternatingRowShading($sheet, 2, $lastRow, 3);

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

    private function truncateSheetTitle(string $title): string
    {
        return mb_substr($title, 0, 31);
    }
}
