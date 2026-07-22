<?php

namespace Cmd\Reports\Console\Commands\GenerateUnclearedSettlementPaymentsReport;

use Cmd\Reports\Services\DBConnector;
use Cmd\Reports\Services\EmailSenderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
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
        'LLG ID',
        'Process Date',
        'Amount',
        'Creditor',
    ];

    private const SOURCES = ['LDR', 'PLAW'];

    /**
     * @param  list<array{contact_id:string,process_date:string,amount:float,creditor:string}>  $rows
     * @return array{filename:string,path:string}
     */
    public function buildWorkbook(array $rows, string $source): array
    {
        $source = $this->normalizeSource($source);

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($this->truncateSheetTitle('Uncleared Payments - '.$source));
        $sheet->setShowGridlines(false);
        $this->fillSheet($sheet, $rows);
        $sheet->freezePane('A2');
        $sheet->setSelectedCells('A1');

        $filename = 'Uncleared Settlement Payments - '.$source.' - '.date('m-d-Y').'.xlsx';
        $slug = strtolower($source);
        $path = storage_path(
            'app/uncleared-settlement-payments-'.$slug.'-'.date('Ymd-His').'-'.bin2hex(random_bytes(4)).'.xlsx'
        );

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
            Log::warning('GenerateUnclearedSettlementPaymentsReport: report file missing/unreadable.', [
                'path' => $path,
                'source' => $source,
            ]);
            $console?->warn("[WARN] {$source} report not sent (file missing/unreadable).");

            return false;
        }

        $bytes = file_get_contents($path);
        if ($bytes === false || $bytes === '') {
            Log::warning('GenerateUnclearedSettlementPaymentsReport: failed to read report file.', [
                'path' => $path,
                'source' => $source,
            ]);
            $console?->warn("[WARN] {$source} report not sent (could not read file).");

            return false;
        }

        $attachments = [[
            'name' => $filename,
            'contentType' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'contentBytes' => base64_encode($bytes),
        ]];

        $email = new EmailSenderService;
        $subject = 'Uncleared Settlement Payments Report - '.$source;
        $body = 'Please review the attached Uncleared Settlement Payments Report for '.$source.'.';

        // Fail closed: company-filtered TblReports only. No env extras, no shared VBA list.
        $sent = $email->sendMailUsingTblReports(
            $connector,
            [
                'UnclearedSettlementPaymentsReport',
                'Uncleared Settlement Payments Report',
                'Uncleared Settlement Payments',
                'UnclearedSettlementPayments',
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
                $console->info("[INFO] {$source} Uncleared Settlement Payments report sent.");
            } else {
                $console->warn("[WARN] {$source} Uncleared Settlement Payments report not sent (no company recipients or send failed).");
            }
        } elseif (! $sent) {
            Log::warning('GenerateUnclearedSettlementPaymentsReport: failed to send email.', [
                'source' => $source,
                'company' => $company,
            ]);
        }

        return $sent;
    }

    private function normalizeSource(string $source): string
    {
        $source = strtoupper(trim($source));
        if (! in_array($source, self::SOURCES, true)) {
            throw new \InvalidArgumentException('Invalid source: '.$source);
        }

        return $source;
    }

    /**
     * @param  list<array{contact_id:string,process_date:string,amount:float,creditor:string}>  $rows
     */
    private function fillSheet(Worksheet $sheet, array $rows): void
    {
        foreach (self::HEADERS as $i => $header) {
            $sheet->setCellValueByColumnAndRow($i + 1, 1, $header);
        }

        $headerRange = 'A1:D1';
        $sheet->getStyle($headerRange)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF'], 'name' => 'Calibri', 'size' => 9],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF17853B']],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        ]);

        $r = 2;
        foreach ($rows as $row) {
            $sheet->setCellValueExplicit("A{$r}", (string) $row['contact_id'], DataType::TYPE_STRING);

            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $row['process_date']);
            if ($date !== false) {
                $sheet->setCellValue("B{$r}", ExcelDate::PHPToExcel($date));
            } else {
                $sheet->setCellValue("B{$r}", $row['process_date']);
            }

            $sheet->setCellValue("C{$r}", (float) $row['amount']);
            $sheet->setCellValue("D{$r}", (string) $row['creditor']);
            $r++;
        }

        $lastRow = max(1, $r - 1);
        $dataRange = "A1:D{$lastRow}";

        if ($lastRow >= 2) {
            $sheet->getStyle("B2:B{$lastRow}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_DATE_XLSX14);
            $sheet->getStyle("C2:C{$lastRow}")->getNumberFormat()->setFormatCode('$#,##0.00');
        }

        $sheet->getStyle($dataRange)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle($dataRange)->getFont()->setName('Calibri')->setSize(9);

        for ($c = 1; $c <= 4; $c++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setAutoSize(true);
        }
    }

    private function truncateSheetTitle(string $title): string
    {
        return mb_substr($title, 0, 31);
    }
}
