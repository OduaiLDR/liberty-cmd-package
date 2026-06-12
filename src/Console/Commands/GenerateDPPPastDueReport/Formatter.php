<?php

namespace Cmd\Reports\Console\Commands\GenerateDPPPastDueReport;

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
    /** @var string[] Sheet order matches Jacob's request */
    private const SHEET_ORDER = ['Active', 'Graduated', 'Dropped'];

    /**
     * Build a single workbook with 3 sheets (Active / Graduated / Dropped).
     *
     * @param array $partitioned ['Active' => [...], 'Graduated' => [...], 'Dropped' => [...]]
     */
    public function buildWorkbook(array $partitioned, string $source): array
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->removeSheetByIndex(0);

        foreach (self::SHEET_ORDER as $sheetName) {
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle($this->truncateSheetTitle($sheetName));
            $sheet->setShowGridlines(false);
            $this->buildSheet($sheet, $partitioned[$sheetName] ?? []);
        }

        $spreadsheet->setActiveSheetIndexByName('Active');
        $spreadsheet->getActiveSheet()->setSelectedCells('A1');

        $filename = 'DPP Past Due Report - ' . $source . ' - ' . date('m-d-Y') . '.xlsx';
        $path = storage_path('app/' . $filename);

        $writer = new Xlsx($spreadsheet);
        $writer->setPreCalculateFormulas(false);
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
        string $company,
        array $partitioned,
        ?Command $console = null
    ): bool {
        if (!is_file($path)) {
            Log::warning('GenerateDPPPastDueReport: report file missing.', ['path' => $path]);
            if ($console) {
                $console->warn('[WARN] DPP Past Due report not sent (file missing).');
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
        $today = date('m/d/Y');
        $subject = 'DPP Past Due Report - ' . $company . ' - ' . $today;

        $activeCount = count($partitioned['Active'] ?? []);
        $graduatedCount = count($partitioned['Graduated'] ?? []);
        $droppedCount = count($partitioned['Dropped'] ?? []);

        $body = 'Please see the attached DPP Past Due Report for ' . $company . ' on ' . $today . '.<br><br>'
            . 'Breakdown of past-due PF/C transactions by client status:<br>'
            . '&bull; <b>Active:</b> ' . number_format($activeCount) . '<br>'
            . '&bull; <b>Graduated:</b> ' . number_format($graduatedCount) . '<br>'
            . '&bull; <b>Dropped:</b> ' . number_format($droppedCount) . '<br><br>'
            . 'Can you review this attachment and cancel these past due fees or advise on what we should do with these fees?<br><br>'
            . 'Thanks';

        $sent = $email->sendMailUsingTblReportsHtml(
            $connector,
            ['DPPPastDueReport', 'DPP Past Due Report'],
            [$company],
            $subject,
            $body,
            $attachments,
            true
        );

        if ($console) {
            if ($sent) {
                $console->info("[INFO] [{$company}] DPP Past Due report sent.");
            } else {
                $console->warn("[WARN] [{$company}] DPP Past Due report not sent (no recipients or send failed).");
            }
        } elseif (!$sent) {
            Log::warning('GenerateDPPPastDueReport: failed to send email.', ['company' => $company]);
        }

        return $sent;
    }

    private function buildSheet(Worksheet $sheet, array $rows): void
    {
        $headers = [
            'Contact ID',
            'Contact Name',
            'Amount',
            'Process Date',
            'Trans Type',
        ];

        $sheet->fromArray($headers, null, 'A1');
        $this->styleHeader($sheet, 'A1:E1');

        if (empty($rows)) {
            $sheet->setCellValue('A2', 'No past-due transactions found.');
            $sheet->mergeCells('A2:E2');
            $sheet->getStyle('A2')
                ->getFont()->setItalic(true)->setSize(10);
            $sheet->getStyle('A2')
                ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $this->applyAutoWidths($sheet, 5);
            $this->applyFont($sheet, "A1:E2");
            return;
        }

        $rowIndex = 2;
        foreach ($rows as $row) {
            $this->setIdCell($sheet, "A{$rowIndex}", $row['CONTACT_ID'] ?? null);
            $sheet->setCellValue("B{$rowIndex}", trim((string) ($row['CONTACT_NAME'] ?? '')));

            $amount = $row['AMOUNT'] ?? null;
            if ($amount === null || $amount === '') {
                $sheet->setCellValue("C{$rowIndex}", 0);
            } else {
                $sheet->setCellValue("C{$rowIndex}", (float) $amount);
            }

            $this->setDateCell($sheet, "D{$rowIndex}", $row['PROCESS_DATE'] ?? null);
            $sheet->setCellValue("E{$rowIndex}", (string) ($row['TRANS_TYPE'] ?? ''));

            $rowIndex++;
        }

        $lastRow = max(2, $rowIndex - 1);

        $sheet->getStyle("C2:C{$lastRow}")->getNumberFormat()->setFormatCode('$#,##0.00');
        $sheet->getStyle("D2:D{$lastRow}")->getNumberFormat()->setFormatCode('mm/dd/yyyy');

        $this->applyBorders($sheet, "A1:E{$lastRow}");
        $this->applyAutoWidths($sheet, 5);
        $this->applyFont($sheet, "A1:E{$lastRow}");
        $this->applyVerticalAlignment($sheet, "A1:E{$lastRow}");
        $this->applyAlternatingRowShading($sheet, 2, $lastRow, 5);

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
