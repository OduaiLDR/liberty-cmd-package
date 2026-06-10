<?php

namespace Cmd\Reports\Console\Commands\GenerateSuppressionReport;

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
    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{filename:string, path:string}
     */
    public function buildWorkbook(array $rows, string $label): array
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Suppression Report');
        $sheet->setShowGridlines(false);

        $this->buildSheet($sheet, $rows);

        $sheet->setSelectedCells('A1');

        $filename = 'Suppression Report - ' . date('Y-m-d') . '.xlsx';
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
            Log::warning('GenerateSuppressionReport: report file missing.', ['path' => $path]);
            if ($console) {
                $console->warn('[WARN] Suppression report not sent (file missing).');
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
        $subject = 'Suppression Report - ' . $label;
        $body = 'Attached is the Suppression Report for ' . $label . ' for review.';

        // No company filter; empty companies array skips the Company clause in TblReports.
        $sent = $email->sendMailUsingTblReports(
            $connector,
            ['SuppressionReport', 'Suppression Report'],
            [],
            $subject,
            $body,
            $attachments,
            true
        );

        if ($console) {
            if ($sent) {
                $console->info('[INFO] Suppression report sent.');
            } else {
                $console->warn('[WARN] Suppression report not sent (no recipients or send failed).');
            }
        } elseif (!$sent) {
            Log::warning('GenerateSuppressionReport: failed to send email.');
        }

        return $sent;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function buildSheet(Worksheet $sheet, array $rows): void
    {
        $headers = ['Drop Name', 'Full Name', 'Address', 'City', 'State', 'Zip'];
        $sheet->fromArray($headers, null, 'A1');
        $this->styleHeader($sheet, 'A1:F1');

        $rowIndex = 2;
        foreach ($rows as $row) {
            $sheet->setCellValue("A{$rowIndex}", (string) ($row['DropName'] ?? ''));
            $sheet->setCellValue("B{$rowIndex}", (string) ($row['FullName'] ?? ''));
            $sheet->setCellValue("C{$rowIndex}", (string) ($row['Address1'] ?? ''));
            $sheet->setCellValue("D{$rowIndex}", (string) ($row['City'] ?? ''));
            $sheet->setCellValue("E{$rowIndex}", (string) ($row['State'] ?? ''));
            $sheet->setCellValueExplicit(
                "F{$rowIndex}",
                (string) ($row['Zip'] ?? ''),
                \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
            );
            $rowIndex++;
        }

        $lastRow = max(2, $rowIndex - 1);

        $this->applyBorders($sheet, "A1:F{$lastRow}");
        $this->applyAutoWidths($sheet, 6);
        $this->applyFont($sheet, "A1:F{$lastRow}");
        $this->applyVerticalAlignment($sheet, "A1:F{$lastRow}");
        $this->applyAlternatingRowShading($sheet, 2, $lastRow, 6);

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
}
