<?php

declare(strict_types=1);

namespace Cmd\Reports\Console\Commands\GenerateRetentionBonusCommission;

use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Shared\Date as XlDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class BonusFormatter
{
    private const HEADER_FILL = 'FF17853B';
    private const HEADER_FONT = 'FFFFFFFF';
    private const DATE_FMT    = 'mm/dd/yyyy';
    private const MONEY_FMT   = '$#,##0';
    private const MONEY2_FMT  = '$#,##0.00';
    private const PCT_FMT     = '0%';

    /**
     * @param array<int,array<string,mixed>> $rows
     * @param array<string,array{location:string,company:string}> $employeeMap  UPPER(agent_name) => employee data
     */
    public function buildWorkbook(array $rows, string $source, string $start, string $end, array $employeeMap = []): ?array
    {
        try {
            $sp    = new Spreadsheet();
            $sheet = $sp->getActiveSheet();
            $sheet->setTitle('Retention Data');
            $sheet->setShowGridlines(false);

            $headers = [
                'ID', 'Client', 'Retention Agent', 'Retention Date', 'Immediate Results',
                'Enrolled Debt', 'Reconsideration Date', 'Retained Date', 'Dropped Date',
                'First Payment Date', 'Cutoff', 'Payments', 'Agent', 'Commission Rate',
                'Violations', 'Retention Commission', 'Agent Deduction',
            ];

            $cols = array_merge(range('A', 'Z'), ['AA']);
            foreach ($headers as $i => $h) {
                if ($h !== null) {
                    $sheet->setCellValue($cols[$i] . '1', $h);
                }
            }
            $this->applyHeaderStyle($sheet, 'A1:Q1');

            // Payroll date = 15th of current month (e.g. 6/15/2026)
            $payrollDate = date('n/15/Y');

            $r = 2;
            foreach ($rows as $row) {
                $id        = $row['ID']                 ?? '';
                $agent     = $row['AGENT']              ?? '';
                $client    = $row['CLIENT']             ?? '';
                $comm      = $row['RETENTION_COMMISSION'] ?? 0;
                $deduction = $row['AGENT_DEDUCTION']    ?? '';

                $sheet->setCellValue("A$r", $id);
                $sheet->setCellValue("B$r", $client);
                $sheet->setCellValue("C$r", $row['RETENTION_AGENT']                 ?? '');
                $this->setDateCell($sheet, "D$r", $row['RETENTION_DATE']            ?? null);
                $sheet->setCellValue("E$r", $row['IMMEDIATE_RESULTS']               ?? '');
                $sheet->setCellValue("F$r", (float) ($row['ENROLLED_DEBT']          ?? 0));
                $this->setDateCell($sheet, "G$r", $row['RECONSIDERATION_DATE']      ?? null);
                $this->setDateCell($sheet, "H$r", $row['RETAINED_DATE']             ?? null);
                $this->setDateCell($sheet, "I$r", $row['DROPPED_DATE']              ?? null);
                $this->setDateCell($sheet, "J$r", $row['FIRST_PAYMENT_CLEARED_DATE'] ?? null);
                $this->setDateCell($sheet, "K$r", $row['CUTOFF']                    ?? null);
                $sheet->setCellValue("L$r", (int) ($row['PAYMENTS']                 ?? 0));
                $sheet->setCellValue("M$r", $agent);
                $sheet->setCellValue("N$r", $row['COMMISSION_RATE']                 ?? '');
                $sheet->setCellValue("O$r", $row['VIOLATIONS']                      ?? '');
                $sheet->setCellValue("P$r", $comm);
                $sheet->setCellValue("Q$r", $deduction === '' ? '' : (float) $deduction);
                $r++;
            }

            $last = max($r - 1, 1);

            foreach (['D', 'G', 'H', 'I', 'J', 'K'] as $c) {
                $sheet->getStyle("{$c}2:{$c}{$last}")->getNumberFormat()->setFormatCode(self::DATE_FMT);
            }
            $sheet->getStyle("F2:F{$last}")->getNumberFormat()->setFormatCode(self::MONEY_FMT);
            $sheet->getStyle("P2:Q{$last}")->getNumberFormat()->setFormatCode(self::MONEY2_FMT);
            $sheet->getStyle("O2:O{$last}")->getNumberFormat()->setFormatCode(self::PCT_FMT);

            if ($last > 1) {
                $sheet->getStyle("A1:Q{$last}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
            }
            foreach (range('A', 'Q') as $c) {
                $sheet->getColumnDimension($c)->setAutoSize(true);
            }
            $sheet->getStyle("A1:Q{$last}")->getFont()->setName('Calibri')->setSize(9);
            $sheet->freezePane('A2');
            $sheet->setSelectedCells('A1');

            // -- Summary sheet --------------------------------------------------
            $summary = $sp->createSheet();
            $summary->setTitle('Agent Summary');
            $summary->setShowGridlines(false);

            $summary->setCellValue('A1', 'Retention Agent');
            $summary->setCellValue('B1', 'Total Commission');
            $summary->setCellValue('C1', 'Location');
            $summary->setCellValue('D1', 'Company');
            $this->applyHeaderStyle($summary, 'A1:D1');

            // Aggregate commission per retention agent
            $agentTotals = [];
            foreach ($rows as $row) {
                $agentName = (string) ($row['RETENTION_AGENT'] ?? '');
                $comm      = (float)  ($row['RETENTION_COMMISSION'] ?? 0);
                $key = strtoupper($agentName);
                $agentTotals[$agentName] = [
                    'commission' => ($agentTotals[$agentName]['commission'] ?? 0.0) + $comm,
                    'location' => $employeeMap[$key]['location'] ?? '',
                    'company' => $employeeMap[$key]['company'] ?? '',
                ];
            }
            uasort($agentTotals, fn ($a, $b) => [$a['location'], $a['company']] <=> [$b['location'], $b['company']]);
            $agentNames = array_keys($agentTotals);
            usort($agentNames, fn ($a, $b) => [
                $agentTotals[$a]['location'],
                $agentTotals[$a]['company'],
                $a,
            ] <=> [
                $agentTotals[$b]['location'],
                $agentTotals[$b]['company'],
                $b,
            ]);

            $sr = 2;
            foreach ($agentNames as $agentName) {
                $summary->setCellValue("A{$sr}", $agentName);
                $summary->setCellValue("B{$sr}", round($agentTotals[$agentName]['commission'], 2, PHP_ROUND_HALF_EVEN));
                $summary->setCellValue("C{$sr}", $agentTotals[$agentName]['location']);
                $summary->setCellValue("D{$sr}", $agentTotals[$agentName]['company']);
                $sr++;
            }

            $lastSr = max($sr - 1, 1);
            $summary->getStyle("B2:B{$lastSr}")->getNumberFormat()->setFormatCode(self::MONEY2_FMT);
            if ($lastSr > 1) {
                $summary->getStyle("A1:D{$lastSr}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
            }
            foreach (['A', 'B', 'C', 'D'] as $c) {
                $summary->getColumnDimension($c)->setAutoSize(true);
            }
            $summary->getStyle("A1:D{$lastSr}")->getFont()->setName('Calibri')->setSize(9);
            $summary->freezePane('A2');
            $summary->setSelectedCells('A1');

            // Set active sheet back to data tab
            $sp->setActiveSheetIndex(0);

            $filename = "Retention Bonus Commission - {$source}.xlsx";
            $path     = storage_path("app/{$filename}");
            (new Xlsx($sp))->save($path);

            return ['filename' => $filename, 'path' => $path];
        } catch (\Throwable $e) {
            Log::error('RetentionBonusCommissionFormatter::buildWorkbook failed', ['err' => $e->getMessage()]);
            return null;
        }
    }

    private function applyHeaderStyle(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'font'      => ['bold' => true, 'color' => ['argb' => self::HEADER_FONT]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::HEADER_FILL]],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        ]);
    }

    private function setDateCell(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, string $cell, ?string $val): void
    {
        if ($val !== null && $val !== '' && strtotime($val) !== false) {
            $sheet->setCellValue($cell, XlDate::PHPToExcel(strtotime($val)));
            $sheet->getStyle($cell)->getNumberFormat()->setFormatCode(self::DATE_FMT);
        }
    }
}
