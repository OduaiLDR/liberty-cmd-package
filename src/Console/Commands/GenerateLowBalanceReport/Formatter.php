<?php

namespace Cmd\Reports\Console\Commands\GenerateLowBalanceReport;

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
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class Formatter
{
    private const HEADERS = [
        'Contact ID',
        'Current Balance',
        'Low Balance',
        'Low Balance Date',
        'Advance Required',
        'NSFs',
    ];

    private const SOURCES = ['LDR', 'PLAW'];

    /**
     * @param  array{under_500:list<array>,over_500:list<array>,shortfall:list<array>}  $sheets
     * @return array{filename:string,path:string}
     */
    public function buildWorkbook(array $sheets, string $source): array
    {
        $source = $this->normalizeSource($source);

        $spreadsheet = new Spreadsheet();

        $sheetDefs = [
            ['title' => 'Advance Required Under 500', 'rows' => $sheets['under_500'], 'recoup' => true],
            ['title' => 'Advance Required 500+', 'rows' => $sheets['over_500'], 'recoup' => false],
            ['title' => 'Shortfall', 'rows' => $sheets['shortfall'], 'recoup' => false],
        ];

        $first = true;
        foreach ($sheetDefs as $def) {
            $sheet = $first ? $spreadsheet->getActiveSheet() : $spreadsheet->createSheet();
            $first = false;
            $sheet->setTitle($this->truncateSheetTitle($def['title']));
            $sheet->setShowGridlines(false);
            $this->fillSheet($sheet, $def['rows'], $def['recoup']);
            $sheet->freezePane('A2');
            $sheet->setSelectedCells('A1');
        }

        $filename = 'Low Balance - ' . $source . ' - ' . date('m-d-Y') . '.xlsx';
        $slug = strtolower($source);
        $path = storage_path('app/low-balance-' . $slug . '-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.xlsx');

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
        string $source,
        string $company,
        ?Command $console = null
    ): bool {
        $source = $this->normalizeSource($source);
        $company = $this->normalizeSource($company);

        if (! is_file($path) || ! is_readable($path)) {
            Log::warning('GenerateLowBalanceReport: report file missing/unreadable.', ['path' => $path, 'source' => $source]);
            $console?->warn("[WARN] {$source} report not sent (file missing/unreadable).");

            return false;
        }

        $bytes = file_get_contents($path);
        if ($bytes === false || $bytes === '') {
            Log::warning('GenerateLowBalanceReport: failed to read report file.', ['path' => $path, 'source' => $source]);
            $console?->warn("[WARN] {$source} report not sent (could not read file).");

            return false;
        }

        $attachments = [[
            'name' => $filename,
            'contentType' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'contentBytes' => base64_encode($bytes),
        ]];

        $email = new EmailSenderService();
        $subject = 'Low Balance Report (Process Date) - ' . $source . ' - ' . date('Y-m-d');
        $body = 'Please see the attached Low Balance Report for ' . $source . ' on ' . date('Y-m-d') . '.';

        // Fail closed: company-filtered TblReports only. No env extras, no shared VBA CC list.
        $sent = $email->sendMailUsingTblReports(
            $connector,
            ['LowBalanceReport', 'Low Balance Report', 'LowBalance'],
            [$company],
            $subject,
            $body,
            $attachments,
            false,
            true
        );

        if ($console) {
            if ($sent) {
                $console->info("[INFO] {$source} Low Balance report sent.");
            } else {
                $console->warn("[WARN] {$source} Low Balance report not sent (no company recipients or send failed).");
            }
        } elseif (! $sent) {
            Log::warning('GenerateLowBalanceReport: failed to send email.', ['source' => $source, 'company' => $company]);
        }

        return $sent;
    }

    private function normalizeSource(string $source): string
    {
        $source = strtoupper(trim($source));
        if (! in_array($source, self::SOURCES, true)) {
            throw new \InvalidArgumentException('Invalid source: ' . $source);
        }

        return $source;
    }

    /**
     * @param  list<array{contact_id:mixed,current_balance:float,low_balance:float,low_balance_date:string,nsf_count:int,advance_required?:bool}>  $rows
     */
    private function fillSheet(Worksheet $sheet, array $rows, bool $includeRecoupCol): void
    {
        $headers = self::HEADERS;
        if ($includeRecoupCol) {
            $headers[] = 'Recoup Date';
        }

        foreach ($headers as $i => $header) {
            $sheet->setCellValueByColumnAndRow($i + 1, 1, $header);
        }

        $lastCol = count($headers);
        $headerRange = $this->range(1, 1, $lastCol, 1);
        $sheet->getStyle($headerRange)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF'], 'name' => 'Calibri', 'size' => 9],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF17853B']],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        ]);

        $r = 2;
        foreach ($rows as $row) {
            $sheet->setCellValueExplicit("A{$r}", (string) $row['contact_id'], DataType::TYPE_STRING);
            $sheet->setCellValue("B{$r}", (float) $row['current_balance']);
            $sheet->setCellValue("C{$r}", (float) $row['low_balance']);

            try {
                $sheet->setCellValue("D{$r}", ExcelDate::PHPToExcel(strtotime((string) $row['low_balance_date'])));
            } catch (\Throwable) {
                $sheet->setCellValue("D{$r}", $row['low_balance_date']);
            }

            $sheet->setCellValue("E{$r}", ! empty($row['advance_required']) ? 'x' : '');
            $sheet->setCellValue("F{$r}", (int) $row['nsf_count']);
            if ($includeRecoupCol) {
                $sheet->setCellValue("G{$r}", '');
            }
            $r++;
        }

        $lastRow = max(1, $r - 1);
        $dataRange = $this->range(1, 1, $lastCol, $lastRow);

        if ($lastRow >= 2) {
            $sheet->getStyle("B2:C{$lastRow}")->getNumberFormat()->setFormatCode('$#,##0.00');
            $sheet->getStyle("D2:D{$lastRow}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_DATE_XLSX14);
            $sheet->getStyle("F2:F{$lastRow}")->getNumberFormat()->setFormatCode('#,##0');
        }

        $sheet->getStyle($dataRange)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle($dataRange)->getFont()->setName('Calibri')->setSize(9);

        for ($c = 1; $c <= $lastCol; $c++) {
            $sheet->getColumnDimensionByColumn($c)->setAutoSize(true);
        }
    }

    private function range(int $c1, int $r1, int $c2, int $r2): string
    {
        return \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c1)
            . $r1
            . ':'
            . \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c2)
            . $r2;
    }

    private function truncateSheetTitle(string $title): string
    {
        return mb_substr($title, 0, 31);
    }
}
