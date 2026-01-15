<?php

namespace Cmd\Reports\Console\Commands\GenerateWelcomePacketReport;

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
    public function buildWorkbook(array $rows, \DateTimeInterface $startDate, \DateTimeInterface $endDate, string $source): ?array
    {
        $sourceLabel = strtoupper(trim($source)) !== '' ? $source : 'Report';
        $headers = [
            'Client',
            'Plan',
            'Address',
            'Address2',
            'City',
            'State',
            'Zip',
            'LLG_ID',
            'Return Address',
        ];

        $title = 'Welcome Packet Report - ' . $sourceLabel . ' - ' . $startDate->format('m/d/Y') . ' through ' . $endDate->format('m/d/Y');
        $filename = 'Welcome Packet Report - ' . $sourceLabel . ' - ' . $startDate->format('m-d-Y') . '.xlsx';
        $path = storage_path('app/' . $filename);

        $normalized = $this->normalizeRows($rows, $source);
        // Already sorted by ORDER BY CLIENT ASC in SQL query

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Welcome Packet Report');
        $sheet->setShowGridlines(false);

        $sheet->setCellValue('A1', $title);
        $sheet->mergeCells('A1:I1');
        $sheet->getStyle('A1')->getFont()->setBold(true);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

        $sheet->fromArray($headers, null, 'A3');
        $sheet->getStyle('A3:I3')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF17853B']],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FF444444']]],
        ]);

        $rowIndex = 4;
        foreach ($normalized as $row) {
            $sheet->setCellValue("A{$rowIndex}", $row['client']);
            $sheet->setCellValue("B{$rowIndex}", $row['plan']);
            $sheet->setCellValue("C{$rowIndex}", $row['address']);
            $sheet->setCellValue("D{$rowIndex}", $row['address2']);
            $sheet->setCellValue("E{$rowIndex}", $row['city']);
            $sheet->setCellValue("F{$rowIndex}", $row['state']);
            $sheet->setCellValue("G{$rowIndex}", $row['zip']);
            $sheet->setCellValue("H{$rowIndex}", $row['llg_id']);
            $sheet->setCellValue("I{$rowIndex}", $row['return_address']);

            if ($rowIndex % 2 === 0) {
                $sheet->getStyle("A{$rowIndex}:I{$rowIndex}")
                    ->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()
                    ->setARGB('FFF5F7FA');
            }

            $rowIndex++;
        }

        $lastRow = max(4, $rowIndex - 1);
        $sheet->getStyle("A1:I{$lastRow}")
            ->getBorders()
            ->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN);

        for ($col = 'A'; $col <= 'I'; $col++) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $sheet->freezePane('A4');
        $sheet->setSelectedCells('A1');

        $writer = new Xlsx($spreadsheet);
        $writer->save($path);

        return [
            'filename' => $filename,
            'path' => $path,
            'source' => $sourceLabel,
            'startDate' => $startDate,
            'endDate' => $endDate,
        ];
    }

    public function sendReport(
        ?DBConnector $connector,
        string $path,
        string $filename,
        string $sourceLabel,
        \DateTimeInterface $startDate,
        \DateTimeInterface $endDate,
        ?Command $console = null
    ): void
    {
        if (!is_file($path)) {
            Log::warning('GenerateWelcomePacketReport: report file missing.', ['path' => $path]);
            if ($console) {
                $console->warn('[WARN] Welcome Packet report not sent (file missing).');
            }
            return;
        }

        if ($connector === null) {
            Log::warning('GenerateWelcomePacketReport: SQL connector missing; email not sent.');
            if ($console) {
                $console->warn('[WARN] Welcome Packet report not sent (no SQL connector for TblReports).');
            }
            return;
        }

        $attachments = [
            [
                'name' => $filename,
                'contentType' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'contentBytes' => base64_encode(file_get_contents($path)),
            ],
        ];

        $email = new EmailSenderService();
        
        // Welcome Packet shows date range
        $datePeriod = $startDate->format('m/d/Y') . ' through ' . $endDate->format('m/d/Y');
        
        $subject = 'Welcome Packet Report - ' . $sourceLabel . ' - ' . $datePeriod;
        $body = 'Please review the attached Welcome Packet Report for ' . $sourceLabel . ' for ' . $datePeriod . '.<br><br>Thanks<br><br>';

        $sent = $email->sendMailUsingTblReportsHtml(
            $connector,
            ['WelcomePacketReport', 'Welcome Packet Report'],
            [$sourceLabel],
            $subject,
            $body,
            $attachments,
            true
        );

        if ($console) {
            if ($sent) {
                $console->info('[INFO] Welcome Packet report sent.');
            } else {
                $console->warn('[WARN] Welcome Packet report not sent (TblReports had no recipients or send failed).');
            }
        } elseif (!$sent) {
            Log::warning('GenerateWelcomePacketReport: failed to send email.');
        }
    }

    private function normalizeRows(array $rows, string $source): array
    {
        $normalized = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $planRaw = (string) ($row['PLAN'] ?? '');
            $planUpper = strtoupper(trim($planRaw));

            // Filter by source BEFORE normalization using raw plan data
            if ($source === 'LDR') {
                // LDR: include plans starting with LDR or LT LDR, exclude PLAW
                if (str_starts_with($planUpper, 'PLAW')) {
                    continue;
                }
            }
            // PLAW: NO FILTERING - include ALL records and set plan to PLAW

            $plan = $this->normalizePlan($planRaw, $source);
            $city = str_replace('-', '/', (string) ($row['CITY'] ?? ''));

            $normalized[] = [
                'client' => (string) ($row['CLIENT'] ?? ''),
                'plan' => $plan,
                'address' => (string) ($row['ADDRESS'] ?? ''),
                'address2' => (string) ($row['ADDRESS2'] ?? ''),
                'city' => $city,
                'state' => (string) ($row['STATE'] ?? ''),
                'zip' => (string) ($row['ZIP'] ?? ''),
                'llg_id' => (string) ($row['LLG_ID'] ?? ''),
                'return_address' => $this->returnAddressForPlan($plan),
            ];
        }

        return $normalized;
    }

    private function normalizePlan(string $plan, string $source): string
    {
        // PLAW: ALL records are set to PLAW (no conditional logic)
        if ($source === 'PLAW') {
            return 'PLAW';
        }

        // LDR: normalize based on plan prefix
        $plan = trim($plan);
        if ($plan === '') {
            return 'Check Plan';
        }

        $upper = strtoupper($plan);
        
        if (str_starts_with($upper, 'LDR')) {
            return 'LDR';
        }
        if (str_starts_with($upper, 'LT LDR')) {
            return 'LDR';
        }
        if (str_starts_with($upper, 'PLAW')) {
            return 'PLAW';
        }

        return 'Check Plan';
    }

    private function returnAddressForPlan(string $plan): string
    {
        return match ($plan) {
            'LDR' => '333 City Blvd W 17 FL, Orange CA, 92868',
            'PLAW' => '8383 Wilshire Blvd Suite 800. Beverly Hills, CA 90211',
            default => '8383 Wilshire Blvd Suite 800. Beverly Hills, CA 90211',
        };
    }
}
