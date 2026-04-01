<?php

namespace Cmd\Reports\Console\Commands\GenerateConsumerAffairsFundedReport;

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
    public function buildWorkbook(array $rows): array
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
            'Source',
            'Loan Representative',
        ];

        $filename = 'Consumer Affairs Funded Report.xlsx';
        $path = storage_path('app/' . $filename);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Consumer Affairs');
        $sheet->setShowGridlines(false);

        $col = 'A';
        foreach ($headers as $header) {
            $sheet->setCellValue("{$col}1", $header);
            $col++;
        }

        $sheet->getStyle('A1:N1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF17853B']],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FF444444']]],
        ]);
        $sheet->setAutoFilter('A1:N1');

        $sheet->getColumnDimension('A')->setWidth(18);
        $sheet->getColumnDimension('B')->setWidth(30);
        $sheet->getColumnDimension('C')->setWidth(16);
        $sheet->getColumnDimension('D')->setWidth(16);
        $sheet->getColumnDimension('E')->setWidth(16);
        $sheet->getColumnDimension('F')->setWidth(8);
        $sheet->getColumnDimension('G')->setWidth(18);
        $sheet->getColumnDimension('H')->setWidth(22);
        $sheet->getColumnDimension('I')->setWidth(16);
        $sheet->getColumnDimension('J')->setWidth(18);
        $sheet->getColumnDimension('K')->setWidth(16);
        $sheet->getColumnDimension('L')->setWidth(40);
        $sheet->getColumnDimension('M')->setWidth(20);
        $sheet->getColumnDimension('N')->setWidth(22);

        $pks = [];
        $rowIndex = 2;

        foreach ($rows as $row) {
            $pk = $row['PK'] ?? null;
            if ($pk !== null) {
                $pks[] = (int) $pk;
            }

            $client = (string) ($row['Client'] ?? '');
            [$firstName, $lastName] = $this->splitName($client);

            $phone = $this->formatPhone((string) ($row['Phone'] ?? ''));
            $orderNumber = str_replace('LLG-', '', (string) ($row['Order_Number'] ?? ''));
            $source = (string) ($row['Source'] ?? '');
            $source = stripos($source, 'online') !== false ? 'Online Application' : 'Phone Application';

            $dataRow = [
                $phone,
                (string) ($row['Email'] ?? ''),
                $firstName,
                $lastName,
                (string) ($row['City'] ?? ''),
                (string) ($row['State'] ?? ''),
                $orderNumber,
                '(800) 481-1821',
                $this->formatDate($row['Date_of_Experience'] ?? null),
                'Lending Tower',
                'Personal Loan',
                (string) ($row['Product_Info'] ?? ''),
                $source,
                (string) ($row['Loan_Representative'] ?? ''),
            ];

            $col = 'A';
            foreach ($dataRow as $cellValue) {
                $sheet->setCellValue("{$col}{$rowIndex}", $cellValue);
                $col++;
            }

            if ($rowIndex % 2 === 0) {
                $sheet->getStyle("A{$rowIndex}:N{$rowIndex}")
                    ->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()
                    ->setARGB('FFF5F7FA');
            }

            $rowIndex++;
        }

        $lastRow = $rowIndex - 1;
        if ($lastRow >= 2) {
            $sheet->getStyle("A2:N{$lastRow}")
                ->getBorders()
                ->getAllBorders()
                ->setBorderStyle(Border::BORDER_THIN);
            $sheet->getStyle("A2:N{$lastRow}")
                ->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_LEFT)
                ->setVertical(Alignment::VERTICAL_CENTER);
            $sheet->getStyle("I2:I{$lastRow}")
                ->getNumberFormat()
                ->setFormatCode('mm/dd/yyyy');
        }

        $sheet->setSelectedCells('A1');

        $writer = new Xlsx($spreadsheet);
        $writer->save($path);

        return [
            'filename' => $filename,
            'path' => $path,
            'pks' => $pks,
        ];
    }

    public function sendReport(DBConnector $connector, string $path, string $filename, ?Command $console = null): void
    {
        if (!is_file($path)) {
            Log::warning('GenerateConsumerAffairsFundedReport: report file missing.', ['path' => $path]);
            if ($console) {
                $console->warn('[WARN] Consumer Affairs report not sent (file missing).');
            }
            return;
        }

        $email = new EmailSenderService();
        $attachments = [
            [
                'name' => $filename,
                'contentType' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'contentBytes' => base64_encode(file_get_contents($path)),
            ],
        ];

        $subject = 'Consumer Affairs Funded Report';
        $body = 'Please see the attached Consumer Affairs Funded Report.';

        $sent = $email->sendMailUsingTblReports(
            $connector,
            ['ConsumerAffairsReport', 'Consumer Affairs Report', 'Consumer Affairs Funded Report'],
            [],
            $subject,
            $body,
            $attachments,
            true
        );

        if ($console) {
            if ($sent) {
                $console->info('[INFO] Consumer Affairs report sent.');
            } else {
                $console->warn('[WARN] Consumer Affairs report not sent (no recipients or send failed).');
            }
        } elseif (!$sent) {
            Log::warning('GenerateConsumerAffairsFundedReport: failed to send email.');
        }
    }

    private function splitName(string $fullName): array
    {
        $fullName = trim($fullName);
        if ($fullName === '') {
            return ['', ''];
        }

        $parts = preg_split('/\s+/', $fullName);
        if (!$parts || count($parts) === 1) {
            return [$fullName, ''];
        }

        $last = array_pop($parts);
        $first = implode(' ', $parts);
        return [$first, $last];
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
        $ts = strtotime((string) $value);
        if ($ts === false) {
            return (string) $value;
        }
        return date('m/d/Y', $ts);
    }
}
