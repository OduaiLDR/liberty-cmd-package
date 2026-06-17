<?php

namespace Cmd\Reports\Console\Commands\GenerateEmployeesReport;

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
    /** Last data column letter (4 columns: A..D). */
    private const LAST_COL    = 'D';
    private const COL_COUNT   = 4;
    private const DATA_COLS   = ['A', 'B', 'C', 'D'];

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{filename:string, path:string}
     */
    public function buildWorkbook(array $rows, string $label): array
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Employees Report');
        $sheet->setShowGridlines(false);

        $this->buildSheet($sheet, $rows);

        $sheet->setSelectedCells('A1');

        $filename = 'Employees Report - ' . date('Y-m-d') . '.xlsx';
        $path     = storage_path('app/' . $filename);

        $writer = new Xlsx($spreadsheet);
        $writer->setPreCalculateFormulas(false);
        $writer->save($path);

        return [
            'filename' => $filename,
            'path'     => $path,
        ];
    }

    public function sendReport(DBConnector $connector, string $path, string $filename, string $label, ?Command $console = null): bool
    {
        if (!is_file($path)) {
            Log::warning('GenerateEmployeesReport: report file missing.', ['path' => $path]);
            if ($console) {
                $console->warn('[WARN] Employees report not sent (file missing).');
            }
            return false;
        }

        $attachments = [
            [
                'name'         => $filename,
                'contentType'  => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'contentBytes' => base64_encode(file_get_contents($path)),
            ],
        ];

        $email   = new EmailSenderService();
        $subject = 'Employees Report - ' . $label;
        $body    = 'Attached is the Employees Report for ' . $label . '.';

        // Empty companies array skips the Company clause when looking up TblReports.
        $sent = $email->sendMailUsingTblReports(
            $connector,
            ['EmployeesReport', 'Employees Report'],
            [],
            $subject,
            $body,
            $attachments,
            true
        );

        if ($console) {
            if ($sent) {
                $console->info('[INFO] Employees report sent.');
            } else {
                $console->warn('[WARN] Employees report not sent (no recipients or send failed).');
            }
        } elseif (!$sent) {
            Log::warning('GenerateEmployeesReport: failed to send email.');
        }

        return $sent;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function buildSheet(Worksheet $sheet, array $rows): void
    {
        $headers = ['Employee Name', 'Access Level', 'Location', 'Company'];
        $sheet->fromArray($headers, null, 'A1');
        $this->styleHeader($sheet, 'A1:' . self::LAST_COL . '1');

        $rowIndex = 2;
        foreach ($rows as $row) {
            $sheet->setCellValue("A{$rowIndex}", $this->blankIfNull($row['Employee_Name'] ?? null));
            $sheet->setCellValue("B{$rowIndex}", $this->blankIfNull($row['Access_Level'] ?? null));
            $sheet->setCellValue("C{$rowIndex}", $this->blankIfNull($row['Location']      ?? null));
            $sheet->setCellValue("D{$rowIndex}", $this->blankIfNull($row['Company']       ?? null));
            $rowIndex++;
        }

        $lastRow = max(2, $rowIndex - 1);
        $fullRange = 'A1:' . self::LAST_COL . $lastRow;

        $this->applyBorders($sheet, $fullRange);
        $this->applyAutoWidths($sheet, self::COL_COUNT);
        $this->applyFont($sheet, $fullRange);
        $this->applyVerticalAlignment($sheet, $fullRange);
        $this->applyAlternatingRowShading($sheet, 2, $lastRow);

        // Yellow NULL highlight LAST so it overrides the alternating shading.
        $this->highlightEmptyCells($sheet, 2, $lastRow);

        $sheet->freezePane('A2');
    }

    private function blankIfNull($value): string
    {
        if ($value === null) {
            return '';
        }
        $trimmed = trim((string) $value);
        return $trimmed === '' ? '' : (string) $value;
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

    private function applyAlternatingRowShading(Worksheet $sheet, int $startRow, int $endRow): void
    {
        for ($row = $startRow; $row <= $endRow; $row++) {
            if ($row % 2 === 0) {
                $sheet->getStyle('A' . $row . ':' . self::LAST_COL . $row)
                    ->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()
                    ->setARGB('FFF5F7FA');
            }
        }
    }

    /**
     * Paint every empty data cell yellow. Run AFTER alternating shading so the
     * yellow overrides the row tint.
     */
    private function highlightEmptyCells(Worksheet $sheet, int $startRow, int $endRow): void
    {
        for ($row = $startRow; $row <= $endRow; $row++) {
            foreach (self::DATA_COLS as $col) {
                $cellRef = $col . $row;
                $value = $sheet->getCell($cellRef)->getValue();
                if ($value === null || trim((string) $value) === '') {
                    $sheet->getStyle($cellRef)
                        ->getFill()
                        ->setFillType(Fill::FILL_SOLID)
                        ->getStartColor()
                        ->setARGB('FFFFFF00');
                }
            }
        }
    }
}
