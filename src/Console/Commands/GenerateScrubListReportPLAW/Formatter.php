<?php

namespace Cmd\Reports\Console\Commands\GenerateScrubListReportPLAW;

use Cmd\Reports\Services\DBConnector;
use Cmd\Reports\Services\EmailSenderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Builds the Scrub List workbook matching the VBA layout:
 * row 1 merged "Applicant" (A:E) / "Co-Applicant" (F:I) / blank (J), row 2 sub-headers,
 * data A-I plus the Negotiator column J. Emails To/CC hardcoded as in the VBA.
 */
class Formatter
{
    // Recipients come from dbo.TblReports (Report_Name 'ScrubListReport', Company 'PLAW').
    private const REPORT_NAMES = ['ScrubListReport', 'Scrub List Report'];
    private const COMPANY = 'PLAW';

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, array<string, mixed>>  $coAppRows
     * @return array{filename:string, path:string}|null
     */
    public function buildWorkbook(array $rows, array $coAppRows, string $category): ?array
    {
        if (empty($rows)) {
            return null;
        }

        // VBA VLOOKUP maps applicant ID -> co-applicant row via cu.CONTACT_ID.
        $coAppLookup = [];
        foreach ($coAppRows as $coApp) {
            $cid = $coApp['CONTACT_ID'] ?? '';
            if ($cid !== '') {
                $coAppLookup[$cid] = $coApp;
            }
        }

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle("{$category} Scrub List");
        $sheet->setShowGridlines(false);

        // Row 1 merged group headers (J1:J2 merged, left blank as in the VBA).
        $sheet->mergeCells('A1:E1');
        $sheet->mergeCells('F1:I1');
        $sheet->mergeCells('J1:J2');
        $sheet->setCellValue('A1', 'Applicant');
        $sheet->setCellValue('F1', 'Co-Applicant');

        // Row 2 sub-headers (VBA replaces underscores with spaces; F:I copies B:E).
        $sheet->setCellValue('A2', 'ID');
        $sheet->setCellValue('B2', 'First Name');
        $sheet->setCellValue('C2', 'Last Name');
        $sheet->setCellValue('D2', 'SSN');
        $sheet->setCellValue('E2', 'DOB');
        $sheet->setCellValue('F2', 'First Name');
        $sheet->setCellValue('G2', 'Last Name');
        $sheet->setCellValue('H2', 'SSN');
        $sheet->setCellValue('I2', 'DOB');

        $sheet->getStyle('A1:J2')->applyFromArray([
            'font' => ['bold' => true],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFD9D9D9']],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        ]);

        $rowIndex = 3;
        foreach ($rows as $row) {
            $cid = $row['ID'] ?? '';
            $coApp = $coAppLookup[$cid] ?? null;

            $sheet->setCellValueExplicit("A{$rowIndex}", (string) $cid, DataType::TYPE_STRING);
            $sheet->setCellValue("B{$rowIndex}", $row['FIRST_NAME'] ?? '');
            $sheet->setCellValue("C{$rowIndex}", $row['LAST_NAME'] ?? '');
            $sheet->setCellValueExplicit("D{$rowIndex}", $this->formatSsn($row['SSN'] ?? ''), DataType::TYPE_STRING);

            $dob = (string) ($row['DOB'] ?? '');
            if ($dob !== '' && strtoupper($dob) !== 'FALSE') {
                $sheet->setCellValueExplicit("E{$rowIndex}", $dob, DataType::TYPE_STRING);
            }

            if ($coApp) {
                $sheet->setCellValue("F{$rowIndex}", $coApp['FIRSTNAME'] ?? '');
                $sheet->setCellValue("G{$rowIndex}", $coApp['LASTNAME'] ?? '');
                $sheet->setCellValueExplicit("H{$rowIndex}", $this->formatSsn($coApp['SSN'] ?? ''), DataType::TYPE_STRING);

                $coDob = (string) ($coApp['DOB'] ?? '');
                if ($coDob !== '' && strtoupper($coDob) !== 'FALSE') {
                    $sheet->setCellValueExplicit("I{$rowIndex}", $coDob, DataType::TYPE_STRING);
                }
            }

            // Column J = Negotiator (VBA keeps the 10th column).
            $sheet->setCellValue("J{$rowIndex}", $row['NEGOTIATOR'] ?? '');

            $rowIndex++;
        }

        $lastRow = $rowIndex - 1;

        // SSN columns: pre-formatted as ###-##-#### text (right aligned, like the VBA).
        foreach (['D', 'H'] as $col) {
            $sheet->getStyle("{$col}3:{$col}{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        }
        // DOB columns already MM/DD/YYYY strings from SQL; right align like the VBA.
        foreach (['E', 'I'] as $col) {
            $sheet->getStyle("{$col}3:{$col}{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        }

        $sheet->getStyle("A1:J{$lastRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

        foreach (['A', 'B', 'C', 'D', 'F', 'G', 'H', 'J'] as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        $sheet->getColumnDimension('E')->setWidth(12);
        $sheet->getColumnDimension('I')->setWidth(12);

        for ($r = 3; $r <= $lastRow; $r++) {
            if ($r % 2 === 0) {
                $sheet->getStyle("A{$r}:J{$r}")
                    ->getFill()->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setARGB('FFF5F7FA');
            }
        }

        $sheet->freezePane('A3');
        $sheet->getStyle("A1:J{$lastRow}")->getFont()->setName('Calibri')->setSize(9);
        $sheet->setSelectedCells('A1');

        $filename = "{$category} Scrub List Report.xlsx";
        $path = storage_path('app/' . $filename);

        $writer = new Xlsx($spreadsheet);
        $writer->setPreCalculateFormulas(false);
        $writer->save($path);

        return ['filename' => $filename, 'path' => $path];
    }

    /**
     * Render a 9-digit SSN as ###-##-#### (VBA number format). Keeps the value as
     * text so leading zeros survive. Non-9-digit values pass through unchanged.
     */
    private function formatSsn($value): string
    {
        $digits = preg_replace('/\D/', '', (string) $value);
        if (strlen($digits) === 9) {
            return substr($digits, 0, 3) . '-' . substr($digits, 3, 2) . '-' . substr($digits, 5, 4);
        }
        return (string) $value;
    }

    public function sendReport(DBConnector $connector, string $path, string $filename, string $category, ?Command $console = null): bool
    {
        if (!is_file($path)) {
            Log::warning('GenerateScrubListReportPLAW: report file missing.', ['path' => $path]);
            if ($console) {
                $console->warn('[WARN] Scrub List report not sent (file missing).');
            }
            return false;
        }

        $attachments = [[
            'name' => $filename,
            'contentType' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'contentBytes' => base64_encode(file_get_contents($path)),
        ]];

        $subject = "{$category} Scrub List Report";
        $body = "Attached is the Scrub List Report for {$category}.";

        $email = new EmailSenderService();
        $sent = $email->sendMailUsingTblReports(
            $connector,
            self::REPORT_NAMES,
            [self::COMPANY],
            $subject,
            $body,
            $attachments,
            true
        );

        if ($console) {
            if ($sent) {
                $console->info('[INFO] Scrub List report sent.');
            } else {
                $console->warn('[WARN] Scrub List report not sent (send failed or missing Graph config).');
            }
        } elseif (!$sent) {
            Log::warning('GenerateScrubListReportPLAW: failed to send email.');
        }

        return $sent;
    }
}
