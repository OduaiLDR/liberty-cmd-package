<?php

namespace Cmd\Reports\Console\Commands\GenerateOfferAuthorizationReport;

use Cmd\Reports\Services\DBConnector;
use Cmd\Reports\Services\EmailSenderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class Formatter
{
    private const HEADERS = [
        'OFFER_ID',
        'LLG_ID',
        'TITLE',
        'FIRSTNAME',
        'LASTNAME',
        'ADDRESS',
        'ADDRESS2',
        'ADDRESS3',
        'CITY',
        'STATE',
        'ZIP',
        'RETURN_ADDRESS',
    ];

    private const SOURCES = ['LDR', 'PLAW'];

    /**
     * @param  list<array{
     *   offer_id:string,
     *   llg_id:string,
     *   title:string,
     *   firstname:string,
     *   lastname:string,
     *   address:string,
     *   address2:string,
     *   address3:string,
     *   city:string,
     *   state:string,
     *   zip:string,
     *   return_address:string
     * }>  $rows
     * @return array{filename:string,path:string}
     */
    public function buildWorkbook(array $rows, string $source, string $reportDate): array
    {
        $source = $this->normalizeSource($source);
        $reportDateLabel = $this->formatReportDate($reportDate);

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($this->truncateSheetTitle('Offer Authorization Report'));
        $sheet->setShowGridlines(false);
        $this->fillSheet($sheet, $rows, $reportDateLabel);
        $sheet->freezePane('A4');
        $sheet->setSelectedCells('A1');

        $filename = 'Offer Authorization Report - '.$source.' - '.$this->formatFilenameDate($reportDate).'.xlsx';
        $slug = strtolower($source);
        $path = storage_path(
            'app/offer-authorization-'.$slug.'-'.date('Ymd-His').'-'.bin2hex(random_bytes(4)).'.xlsx'
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
            Log::warning('GenerateOfferAuthorizationReport: report file missing/unreadable.', [
                'path' => $path,
                'source' => $source,
            ]);
            $console?->warn("[WARN] {$source} report not sent (file missing/unreadable).");

            return false;
        }

        $bytes = file_get_contents($path);
        if ($bytes === false || $bytes === '') {
            Log::warning('GenerateOfferAuthorizationReport: failed to read report file.', [
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
        $subject = 'Offer Authorization Report';
        $body = 'Please review the attached Offer Authorization Report - '.$source.'<br><br>Thanks<br><br>';

        // Fail closed: company-filtered TblReports only. No env extras, no shared VBA list.
        $sent = $email->sendMailUsingTblReportsHtml(
            $connector,
            [
                'OfferAuthorizationReport',
                'Offer Authorization Report',
                'Offer Authorization',
                'OfferAuthorization',
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
                $console->info("[INFO] {$source} Offer Authorization report sent.");
            } else {
                $console->warn("[WARN] {$source} Offer Authorization report not sent (no company recipients or send failed).");
            }
        } elseif (! $sent) {
            Log::warning('GenerateOfferAuthorizationReport: failed to send email.', [
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
     * @param  list<array{
     *   offer_id:string,
     *   llg_id:string,
     *   title:string,
     *   firstname:string,
     *   lastname:string,
     *   address:string,
     *   address2:string,
     *   address3:string,
     *   city:string,
     *   state:string,
     *   zip:string,
     *   return_address:string
     * }>  $rows
     */
    private function fillSheet(Worksheet $sheet, array $rows, string $reportDateLabel): void
    {
        // VBA inserts two blank rows then puts the title in A1.
        $sheet->setCellValue('A1', 'Offer Authorization Report - '.$reportDateLabel);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setName('Calibri')->setSize(11);

        foreach (self::HEADERS as $i => $header) {
            $sheet->setCellValueByColumnAndRow($i + 1, 3, $header);
        }

        $headerRange = 'A3:L3';
        $sheet->getStyle($headerRange)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF'], 'name' => 'Calibri', 'size' => 9],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF17853B']],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        ]);

        $r = 4;
        foreach ($rows as $row) {
            $sheet->setCellValueExplicit("A{$r}", (string) $row['offer_id'], DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("B{$r}", (string) $row['llg_id'], DataType::TYPE_STRING);
            $sheet->setCellValue("C{$r}", (string) $row['title']);
            $sheet->setCellValue("D{$r}", (string) $row['firstname']);
            $sheet->setCellValue("E{$r}", (string) $row['lastname']);
            $sheet->setCellValue("F{$r}", (string) $row['address']);
            $sheet->setCellValue("G{$r}", (string) $row['address2']);
            $sheet->setCellValue("H{$r}", (string) $row['address3']);
            $sheet->setCellValue("I{$r}", (string) $row['city']);
            $sheet->setCellValue("J{$r}", (string) $row['state']);
            $sheet->setCellValueExplicit("K{$r}", (string) $row['zip'], DataType::TYPE_STRING);
            $sheet->setCellValue("L{$r}", (string) $row['return_address']);
            $r++;
        }

        $lastRow = max(3, $r - 1);
        $dataRange = "A3:L{$lastRow}";
        $sheet->getStyle($dataRange)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle($dataRange)->getFont()->setName('Calibri')->setSize(9);

        for ($c = 1; $c <= 12; $c++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setAutoSize(true);
        }
    }

    private function formatReportDate(string $reportDate): string
    {
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $reportDate);
        if ($dt === false) {
            return $reportDate;
        }

        return $dt->format('m/d/Y');
    }

    private function formatFilenameDate(string $reportDate): string
    {
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $reportDate);
        if ($dt === false) {
            return $reportDate;
        }

        return $dt->format('m-d-Y');
    }

    private function truncateSheetTitle(string $title): string
    {
        return mb_substr($title, 0, 31);
    }
}
