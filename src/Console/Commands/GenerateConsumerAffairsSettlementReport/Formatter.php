<?php

namespace Cmd\Reports\Console\Commands\GenerateConsumerAffairsSettlementReport;

use Cmd\Reports\Services\DBConnector;
use Cmd\Reports\Services\EmailSenderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class Formatter
{
    public function buildWorkbook(array $rows, array $poorRatings): array
    {
        $headers = [
            'Phone',
            'Email',
            'First Name',
            'Last Name',
            'City',
            'State',
            'Order Number',
            'Customer Service Number',
            'Date of Experience',
            'Company Info',
            'Additional Info',
            'Product Info',
            'Enrollment Date',
            '# of Settlements',
            'Enrollment Status',
        ];

        $baseFilename = 'Consumer Affairs Settlement Report';
        $csvFilename = $baseFilename . '.csv';
        $idsFilename = $baseFilename . ' - IDs.txt';
        $csvPath = storage_path('app/' . $csvFilename);
        $idsPath = storage_path('app/' . $idsFilename);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Consumer Affairs Settlement');
        $sheet->setShowGridlines(false);

        $col = 'A';
        foreach ($headers as $header) {
            $sheet->setCellValue("{$col}1", $header);
            $col++;
        }

        $sheet->getStyle('A1:O1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF17853B']],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FF444444']]],
        ]);
        $sheet->setAutoFilter('A1:O1');

        $sheet->getColumnDimension('A')->setWidth(18);
        $sheet->getColumnDimension('B')->setWidth(30);
        $sheet->getColumnDimension('C')->setWidth(16);
        $sheet->getColumnDimension('D')->setWidth(16);
        $sheet->getColumnDimension('E')->setWidth(16);
        $sheet->getColumnDimension('F')->setWidth(8);
        $sheet->getColumnDimension('G')->setWidth(16);
        $sheet->getColumnDimension('H')->setWidth(22);
        $sheet->getColumnDimension('I')->setWidth(16);
        $sheet->getColumnDimension('J')->setWidth(20);
        $sheet->getColumnDimension('K')->setWidth(18);
        $sheet->getColumnDimension('L')->setWidth(22);
        $sheet->getColumnDimension('M')->setWidth(18);
        $sheet->getColumnDimension('N')->setWidth(16);
        $sheet->getColumnDimension('O')->setWidth(24);

        $rowIndex = 2;
        foreach ($rows as $row) {
            $contactId = (string) ($row['ID'] ?? '');
            if ($contactId !== '' && isset($poorRatings[$contactId])) {
                continue;
            }

            $phone = $this->firstPhone(
                (string) ($row['PHONE'] ?? ''),
                (string) ($row['PHONE2'] ?? ''),
                (string) ($row['PHONE3'] ?? ''),
                (string) ($row['PHONE4'] ?? '')
            );

            $dataRow = [
                $this->formatPhone($phone),
                (string) ($row['EMAIL'] ?? ''),
                (string) ($row['FIRSTNAME'] ?? ''),
                (string) ($row['LASTNAME'] ?? ''),
                (string) ($row['CITY'] ?? ''),
                (string) ($row['STATE'] ?? ''),
                $contactId,
                (string) ($row['CUSTOMER_SERVICE'] ?? ''),
                $this->formatDate($row['ENROLLED_DATE'] ?? null),
                (string) ($row['COMPANY_INFO'] ?? ''),
                (string) ($row['ADDITIONAL_INFO'] ?? ''),
                (string) ($row['PRODUCT_INFO'] ?? ''),
                $this->formatDate($row['ENROLLMENT_DATE'] ?? null),
                (string) ($row['SETTLEMENTS'] ?? 0),
                (string) ($row['ENROLLMENT_STATUS'] ?? ''),
            ];

            $col = 'A';
            foreach ($dataRow as $cellValue) {
                $sheet->setCellValue("{$col}{$rowIndex}", $cellValue);
                $col++;
            }

            if ($rowIndex % 2 === 0) {
                $sheet->getStyle("A{$rowIndex}:O{$rowIndex}")
                    ->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()
                    ->setARGB('FFF5F7FA');
            }

            $rowIndex++;
        }

        $lastRow = $rowIndex - 1;
        if ($lastRow >= 2) {
            $sheet->getStyle("A2:O{$lastRow}")
                ->getBorders()
                ->getAllBorders()
                ->setBorderStyle(Border::BORDER_THIN);
            $sheet->getStyle("A2:O{$lastRow}")
                ->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_LEFT)
                ->setVertical(Alignment::VERTICAL_CENTER);
            $sheet->getStyle("A2:A{$lastRow}")
                ->getNumberFormat()
                ->setFormatCode('(###) ###-####');
            $sheet->getStyle("I2:I{$lastRow}")
                ->getNumberFormat()
                ->setFormatCode('mm/dd/yyyy');
            $sheet->getStyle("M2:M{$lastRow}")
                ->getNumberFormat()
                ->setFormatCode('mm/dd/yyyy');
        }

        // Save as CSV like VBA does
        $sheet->setSelectedCells('A1');

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Csv($spreadsheet);
        $writer->setDelimiter(',');
        $writer->setEnclosure('"');
        $writer->save($csvPath);

        // Generate IDs text file like VBA does
        $contactIds = [];
        for ($r = 2; $r <= $lastRow; $r++) {
            $id = $sheet->getCell("G{$r}")->getValue();
            if ($id !== null && $id !== '') {
                $contactIds[] = $id;
            }
        }
        file_put_contents($idsPath, implode(', ', $contactIds));

        return [
            'csvFilename' => $csvFilename,
            'csvPath' => $csvPath,
            'idsFilename' => $idsFilename,
            'idsPath' => $idsPath,
        ];
    }

    public function sendReport(DBConnector $connector, array $report, ?Command $console = null): void
    {
        $csvPath = $report['csvPath'] ?? '';
        $idsPath = $report['idsPath'] ?? '';
        $csvFilename = $report['csvFilename'] ?? '';
        $idsFilename = $report['idsFilename'] ?? '';

        if (!is_file($csvPath)) {
            Log::warning('GenerateConsumerAffairsSettlementReport: CSV file missing.', ['path' => $csvPath]);
            if ($console) {
                $console->warn('[WARN] Consumer Affairs Settlement report not sent (CSV file missing).');
            }
            return;
        }

        $attachments = [
            [
                'name' => $csvFilename,
                'contentType' => 'text/csv',
                'contentBytes' => base64_encode(file_get_contents($csvPath)),
            ],
        ];

        if (is_file($idsPath)) {
            $attachments[] = [
                'name' => $idsFilename,
                'contentType' => 'text/plain',
                'contentBytes' => base64_encode(file_get_contents($idsPath)),
            ];
        }

        $email = new EmailSenderService();
        $subject = 'Consumer Affairs Settlement Report';
        $body = 'Please see the attached Consumer Affairs Settlement Report.';

        $sent = $email->sendMailUsingTblReports(
            $connector,
            ['ConsumerAffairsSettlementReport', 'Consumer Affairs Settlement Report'],
            ['LDR'],
            $subject,
            $body,
            $attachments,
            true
        );

        if ($console) {
            if ($sent) {
                $console->info('[INFO] Consumer Affairs Settlement report sent.');
            } else {
                $console->warn('[WARN] Consumer Affairs Settlement report not sent (no recipients or send failed).');
            }
        } elseif (!$sent) {
            Log::warning('GenerateConsumerAffairsSettlementReport: failed to send email.');
        }
    }

    private function firstPhone(string ...$phones): string
    {
        foreach ($phones as $phone) {
            if (trim($phone) !== '') {
                return $phone;
            }
        }
        return '';
    }

    private function formatPhone(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value ?? '');
        if (strlen($digits) === 10) {
            return sprintf('(%s) %s-%s', substr($digits, 0, 3), substr($digits, 3, 3), substr($digits, 6));
        }
        return $value;
    }

    private function formatDate($value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $string = (string) $value;

        // Handle Snowflake epoch days (integer representing days since 1970-01-01)
        // Values like 20028, 20024, etc. are days since Unix epoch
        if (preg_match('/^\d{4,5}$/', $string) && (int) $string > 10000 && (int) $string < 50000) {
            $epochDays = (int) $string;
            $dt = (new \DateTimeImmutable('1970-01-01'))->modify("+{$epochDays} days");
            return $dt->format('m/d/Y');
        }

        // Handle ISO date format
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $string)) {
            $ts = strtotime($string);
            if ($ts !== false) {
                return date('m/d/Y', $ts);
            }
        }

        $ts = strtotime($string);
        if ($ts === false) {
            return $string;
        }
        return date('m/d/Y', $ts);
    }
}
