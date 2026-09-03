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
            'Loan Company',
            'Loan Amount',
            'APR',
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
        $sheet->getColumnDimension('G')->setWidth(18);
        $sheet->getColumnDimension('H')->setWidth(22);
        $sheet->getColumnDimension('I')->setWidth(16);
        $sheet->getColumnDimension('J')->setWidth(18);
        $sheet->getColumnDimension('K')->setWidth(22);
        $sheet->getColumnDimension('L')->setWidth(14);
        $sheet->getColumnDimension('M')->setWidth(10);
        $sheet->getColumnDimension('N')->setWidth(20);
        $sheet->getColumnDimension('O')->setWidth(22);

        usort($rows, function (array $a, array $b): int {
            [, $lastA] = $this->splitName((string) ($a['Client'] ?? ''));
            [, $lastB] = $this->splitName((string) ($b['Client'] ?? ''));
            $cmp = strcasecmp($lastA, $lastB);
            if ($cmp !== 0) {
                return $cmp;
            }
            [$firstA] = $this->splitName((string) ($a['Client'] ?? ''));
            [$firstB] = $this->splitName((string) ($b['Client'] ?? ''));
            return strcasecmp($firstA, $firstB);
        });

        $pks = [];
        $rowIndex = 2;

        foreach ($rows as $row) {
            $pk = $row['PK'] ?? null;
            if ($pk !== null) {
                $pks[] = (int) $pk;
            }

            $client = (string) ($row['Client'] ?? '');
            [$firstName, $lastName] = $this->splitName($client);

            $phone = $this->formatPhone($this->firstPhone(
                (string) ($row['Phone'] ?? ''),
                (string) ($row['Phone2'] ?? ''),
                (string) ($row['Phone3'] ?? ''),
                (string) ($row['Phone4'] ?? '')
            ));
            $orderNumber = str_replace('LLG-', '', (string) ($row['Order_Number'] ?? ''));
            $source = (string) ($row['Source'] ?? '');
            $source = stripos($source, 'online') !== false ? 'Online Application' : 'Phone Application';

            $product = $this->parseProductInfo((string) ($row['Product_Info'] ?? ''));
            $loanAmount = $this->toNumber($product['loan_amount']);
            $apr = $this->toNumber($product['apr']);

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
                $product['loan_company'],
                $loanAmount,
                $apr,
                $source,
                (string) ($row['Loan_Representative'] ?? ''),
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
            $sheet->getStyle("I2:I{$lastRow}")
                ->getNumberFormat()
                ->setFormatCode('mm/dd/yyyy');
            $sheet->getStyle("L2:L{$lastRow}")
                ->getNumberFormat()
                ->setFormatCode('#,##0.00');
            $sheet->getStyle("M2:M{$lastRow}")
                ->getNumberFormat()
                ->setFormatCode('0.00');
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

    /**
     * @param list<string>|null $recipientsOverride When set (test mode), sends
     *        directly to these addresses instead of the TblReports lookup.
     */
    public function sendReport(DBConnector $connector, string $path, string $filename, ?array $recipientsOverride, ?Command $console = null): void
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

        $subject = ($recipientsOverride !== null ? '[TEST] ' : '') . 'Consumer Affairs Funded Report';
        $body = 'Please see the attached Consumer Affairs Funded Report.';

        if ($recipientsOverride !== null) {
            $sent = $email->sendMailHtml($subject, $body, $recipientsOverride, [], [], $attachments);
        } else {
            $sent = $email->sendMailUsingTblReports(
                $connector,
                ['ConsumerAffairsReport', 'Consumer Affairs Report', 'Consumer Affairs Funded Report'],
                [],
                $subject,
                $body,
                $attachments,
                true
            );
        }

        if ($console) {
            if ($sent) {
                $console->info('[INFO] Consumer Affairs report sent' . ($recipientsOverride !== null ? ' to ' . implode(', ', $recipientsOverride) : '') . '.');
            } else {
                $console->warn('[WARN] Consumer Affairs report not sent (no recipients or send failed).');
            }
        } elseif (!$sent) {
            Log::warning('GenerateConsumerAffairsFundedReport: failed to send email.');
        }
    }

    /**
     * Notes / Product_Info blob, e.g.
     * "Personal Loan funded by: SoFi Loan Amount: $12,000 APR: 9.99% Loan Term: 36 Months"
     *
     * @return array{loan_company: string, loan_amount: string, apr: string}
     */
    private function parseProductInfo(string $notes): array
    {
        $notes = trim(preg_replace('/\s+/', ' ', $notes) ?? '');
        $parsed = [
            'loan_company' => '',
            'loan_amount' => '',
            'apr' => '',
        ];

        if ($notes === '') {
            return $parsed;
        }

        // Company: "Personal Loan funded by: X" (actual Notes wording) or Loan Company / Lender
        $parsed['loan_company'] = $this->matchLabeledValue(
            $notes,
            '/(?:funded\s+by|Loan\s*Company|Lender)\s*:\s*(.+?)(?=\s*(?:Loan\s*Amount|Amount\s*:|APR\s*:|Interest\s*Rate\s*:|Loan\s*Term|$))/i'
        );
        $parsed['loan_amount'] = $this->matchLabeledValue(
            $notes,
            '/(?:Loan\s*Amount|Amount)\s*:\s*(.+?)(?=\s*(?:APR\s*:|Interest\s*Rate\s*:|Loan\s*Term|funded\s+by|Loan\s*Company|Lender|$))/i'
        );
        $parsed['apr'] = $this->matchLabeledValue(
            $notes,
            '/APR\s*:\s*(.+?)(?=\s*(?:Interest\s*Rate\s*:|Loan\s*Term|Loan\s*Amount|Amount\s*:|funded\s+by|Loan\s*Company|Lender|$))/i'
        );

        return $parsed;
    }

    private function matchLabeledValue(string $text, string $pattern): string
    {
        if (!preg_match($pattern, $text, $matches)) {
            return '';
        }

        return trim((string) ($matches[1] ?? ''), " \t\n\r\0\x0B|,;");
    }

    /** Strip $, %, commas etc. and return a float, or '' if empty/unparseable. */
    private function toNumber(string $value): float|string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $cleaned = preg_replace('/[^0-9.\-]/', '', $value);
        if ($cleaned === null || $cleaned === '' || $cleaned === '-' || $cleaned === '.') {
            return '';
        }

        return (float) $cleaned;
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
        $ts = strtotime((string) $value);
        if ($ts === false) {
            return (string) $value;
        }
        return date('m/d/Y', $ts);
    }
}
