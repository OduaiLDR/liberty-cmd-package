<?php

namespace Cmd\Reports\Console\Commands\GenerateNoOffersReport;

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
    public function buildWorkbook(array $rows, string $reportDate): array
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $sheetTitle = 'No Offers Report - ' . date('m-d-Y', strtotime($reportDate));
        $sheet->setTitle($this->truncateSheetTitle($sheetTitle));
        $sheet->setShowGridlines(false);

        $this->buildNoOffersSheet($sheet, $rows);

        $sheet->setSelectedCells('A1');

        $filename = 'No Offers Report - ' . date('m-d-Y', strtotime($reportDate)) . '.xlsx';
        $path = storage_path('app/' . $filename);

        $writer = new Xlsx($spreadsheet);
        $writer->setPreCalculateFormulas(false);
        $writer->save($path);

        return [
            'filename' => $filename,
            'path' => $path,
        ];
    }

    public function sendReport(DBConnector $connector, string $path, string $filename, string $reportDate, ?Command $console = null): bool
    {
        if (!is_file($path)) {
            Log::warning('GenerateNoOffersReport: report file missing.', ['path' => $path]);
            if ($console) {
                $console->warn('[WARN] No Offers report not sent (file missing).');
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
        $subject = 'No Offers Report - ' . date('m-d-Y', strtotime($reportDate));
        $body = 'Please see the attached No Offers Report for ' . date('n/j/Y', strtotime($reportDate)) . '.';

        $sent = $email->sendMailUsingTblReports(
            $connector,
            ['NoOffersReport', 'No Offers Report'],
            ['LT'],
            $subject,
            $body,
            $attachments,
            true
        );

        if ($console) {
            if ($sent) {
                $console->info('[INFO] No Offers report sent.');
            } else {
                $console->warn('[WARN] No Offers report not sent (no recipients or send failed).');
            }
        } elseif (!$sent) {
            Log::warning('GenerateNoOffersReport: failed to send email.');
        }

        return $sent;
    }

    private function buildNoOffersSheet(Worksheet $sheet, array $rows): void
    {
        $headers = [
            'First Name',
            'Last Name',
            'Address',
            'City',
            'State',
            'Zip',
            'Status',
        ];

        $sheet->fromArray($headers, null, 'A1');
        $this->styleHeader($sheet, 'A1:G1');

        $rowIndex = 2;
        foreach ($rows as $row) {
            $sheet->setCellValue("A{$rowIndex}", (string) ($row['FIRSTNAME'] ?? ''));
            $sheet->setCellValue("B{$rowIndex}", (string) ($row['LASTNAME'] ?? ''));
            $sheet->setCellValue("C{$rowIndex}", (string) ($row['ADDRESS'] ?? ''));
            $sheet->setCellValue("D{$rowIndex}", (string) ($row['CITY'] ?? ''));
            $sheet->setCellValue("E{$rowIndex}", (string) ($row['STATE'] ?? ''));
            $sheet->setCellValue("F{$rowIndex}", (string) ($row['ZIP'] ?? ''));
            $sheet->setCellValue("G{$rowIndex}", (string) ($row['STATUS'] ?? ''));
            $rowIndex++;
        }

        $lastRow = max(2, $rowIndex - 1);

        $this->applyBorders($sheet, "A1:G{$lastRow}");
        $this->applyAutoWidths($sheet, 7);
        $this->applyFont($sheet, "A1:G{$lastRow}");
        $this->applyVerticalAlignment($sheet, "A1:G{$lastRow}");
        $this->applyAlternatingRowShading($sheet, 2, $lastRow, 7);

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

    private function truncateSheetTitle(string $title): string
    {
        return mb_substr($title, 0, 31);
    }
}
