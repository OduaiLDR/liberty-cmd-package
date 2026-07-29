<?php

namespace Cmd\Reports\Console\Commands\GenerateNSFReport;

use Cmd\Reports\Services\DBConnector;
use Cmd\Reports\Services\EmailSenderService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
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
    private const REPORT_TIMEZONE = 'America/Los_Angeles';

    private const SOURCES = ['LDR', 'PLAW'];

    private const HEADERS = [
        'ID',
        'Contact',
        'Enrolled Date',
        'Enrolled Debt',
        'Status',
        'Status Date',
        'Days',
        'Phone 1',
        'Phone 2',
        'Phone 3',
        'Phone 4',
    ];

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return array{filename:string,path:string}
     */
    public function buildWorkbook(array $rows, string $source, ?string $outputDir = null): array
    {
        $source = $this->normalizeSource($source);
        $now = Carbon::now(self::REPORT_TIMEZONE);

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($this->truncateSheetTitle('NSF Report - '.$now->format('m-d-Y')));
        $sheet->setShowGridlines(false);
        $this->fillSheet($sheet, $rows);
        $sheet->freezePane('A2');
        $sheet->setSelectedCells('A1');

        $displaySource = $source === 'PLAW' ? 'Progress Law' : $source;
        $filename = 'NSF Report - '.$displaySource.' - '.$now->format('m-d-Y').'.xlsx';
        $slug = strtolower($source);

        if ($outputDir !== null && $outputDir !== '') {
            $path = rtrim(str_replace('\\', '/', $outputDir), '/').'/'.$filename;
        } else {
            $path = storage_path(
                'app/nsf-report-'.$slug.'-'.$now->format('Ymd-His').'-'.bin2hex(random_bytes(4)).'.xlsx'
            );
        }

        try {
            $writer = new Xlsx($spreadsheet);
            $writer->setPreCalculateFormulas(false);
            $writer->save($path);
        } finally {
            $spreadsheet->disconnectWorksheets();
        }

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
            Log::warning('GenerateNSFReport: report file missing/unreadable.', [
                'path' => $path,
                'source' => $source,
            ]);
            $console?->warn("[WARN] {$source} report not sent (file missing/unreadable).");

            return false;
        }

        $bytes = file_get_contents($path);
        if ($bytes === false || $bytes === '') {
            Log::warning('GenerateNSFReport: failed to read report file.', [
                'path' => $path,
                'source' => $source,
            ]);
            $console?->warn("[WARN] {$source} report not sent (could not read file).");

            return false;
        }

        $contentBytes = base64_encode($bytes);
        unset($bytes);

        $attachments = [[
            'name' => $filename,
            'contentType' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'contentBytes' => $contentBytes,
        ]];

        $displaySource = $source === 'PLAW' ? 'Progress Law' : $source;
        $email = new EmailSenderService;
        $today = Carbon::now(self::REPORT_TIMEZONE)->format('m/d/Y');
        $subject = 'NSF Report - '.$today;
        $body = 'Please see the attached NSF report for '.$displaySource.' on '.$today.'.';

        // Fail closed: company-filtered TblReports only. No env extras, no shared VBA fallback.
        $sent = $email->sendMailUsingTblReports(
            $connector,
            [
                'NSF Report',
            ],
            [$company],
            $subject,
            $body,
            $attachments,
            false,
            true
        );

        if ($console) {
            if ($sent) {
                $console->info("[INFO] {$source} NSF report sent.");
            } else {
                $console->warn("[WARN] {$source} NSF report not sent (no company recipients or send failed).");
            }
        } elseif (! $sent) {
            Log::warning('GenerateNSFReport: failed to send email.', [
                'source' => $source,
                'company' => $company,
            ]);
        }

        return $sent;
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     */
    private function fillSheet(Worksheet $sheet, array $rows): void
    {
        foreach (self::HEADERS as $i => $header) {
            $sheet->setCellValueByColumnAndRow($i + 1, 1, $header);
        }

        $headerRange = 'A1:K1';
        $sheet->getStyle($headerRange)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF'], 'name' => 'Calibri', 'size' => 9],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF17853B']],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        ]);

        $r = 2;
        foreach ($rows as $row) {
            $id = $row['ID'] ?? $row['id'] ?? '';
            $sheet->setCellValueExplicit("A{$r}", (string) $id, DataType::TYPE_STRING);
            $sheet->setCellValue("B{$r}", (string) ($row['CONTACT'] ?? $row['Contact'] ?? ''));
            $this->setDateCell($sheet, "C{$r}", (string) ($row['ENROLLED_DATE'] ?? ''));
            $sheet->setCellValue("D{$r}", (float) ($row['ENROLLED_DEBT'] ?? 0));
            $sheet->setCellValue("E{$r}", (string) ($row['STATUS'] ?? $row['Status'] ?? ''));
            $this->setDateCell($sheet, "F{$r}", (string) ($row['STATUS_DATE'] ?? ''));

            $days = $row['DAYS'] ?? $row['Days'] ?? null;
            if ($days === null || $days === '') {
                $sheet->setCellValue("G{$r}", '');
            } else {
                $sheet->setCellValue("G{$r}", (int) $days);
            }

            $this->setPhoneCell($sheet, "H{$r}", $row['PHONE_1'] ?? $row['Phone_1'] ?? null);
            $this->setPhoneCell($sheet, "I{$r}", $row['PHONE_2'] ?? $row['Phone_2'] ?? null);
            $this->setPhoneCell($sheet, "J{$r}", $row['PHONE_3'] ?? $row['Phone_3'] ?? null);
            $this->setPhoneCell($sheet, "K{$r}", $row['PHONE_4'] ?? $row['Phone_4'] ?? null);
            $r++;
        }

        $lastRow = max(1, $r - 1);
        $dataRange = "A1:K{$lastRow}";

        if ($lastRow >= 2) {
            $sheet->getStyle("C2:C{$lastRow}")->getNumberFormat()->setFormatCode('mm/dd/yyyy');
            $sheet->getStyle("F2:F{$lastRow}")->getNumberFormat()->setFormatCode('mm/dd/yyyy');
            $sheet->getStyle("D2:D{$lastRow}")->getNumberFormat()->setFormatCode('$#,##0');
            $sheet->getStyle("G2:G{$lastRow}")->getNumberFormat()->setFormatCode('0');
            $sheet->getStyle("H2:K{$lastRow}")->getNumberFormat()->setFormatCode('(###) ###-####');
        }

        $sheet->getStyle($dataRange)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle($dataRange)->getFont()->setName('Calibri')->setSize(9);

        $widths = [
            'A' => 14,
            'B' => 28,
            'C' => 14,
            'D' => 16,
            'E' => 34,
            'F' => 14,
            'G' => 10,
            'H' => 16,
            'I' => 16,
            'J' => 16,
            'K' => 16,
        ];
        foreach ($widths as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
    }

    private function setDateCell(Worksheet $sheet, string $cell, string $ymd): void
    {
        if ($ymd === '') {
            $sheet->setCellValue($cell, '');

            return;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($ymd, 0, 10));
        if ($date !== false) {
            $sheet->setCellValue($cell, ExcelDate::PHPToExcel($date));
        } else {
            $sheet->setCellValue($cell, $ymd);
        }
    }

    private function setPhoneCell(Worksheet $sheet, string $cell, mixed $value): void
    {
        if ($value === null || $value === '') {
            $sheet->setCellValue($cell, '');

            return;
        }

        $digits = preg_replace('/\D+/', '', (string) $value);
        if ($digits === null || $digits === '') {
            $sheet->setCellValue($cell, '');

            return;
        }

        // Keep as number so Excel phone format can render like VBA.
        $sheet->setCellValueExplicit($cell, $digits, DataType::TYPE_NUMERIC);
    }

    private function normalizeSource(string $source): string
    {
        $source = strtoupper(trim($source));
        if (! in_array($source, self::SOURCES, true)) {
            throw new \InvalidArgumentException('Invalid source: '.$source);
        }

        return $source;
    }

    private function truncateSheetTitle(string $title): string
    {
        return mb_substr($title, 0, 31);
    }
}
