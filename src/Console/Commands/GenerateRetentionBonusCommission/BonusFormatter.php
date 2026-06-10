<?php

namespace Cmd\Reports\Console\Commands\GenerateRetentionBonusCommission;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Illuminate\Support\Facades\Log;

/**
 * Builds the Retention Bonus Commission Excel workbook.
 * Single sheet "Retention Data" mirroring VBA column layout.
 */
class BonusFormatter
{
    private const HEADER_FILL = 'FF17853B';
    private const HEADER_FONT = 'FFFFFFFF';
    private const DATE_FMT    = 'mm/dd/yyyy';
    private const MONEY_FMT   = '$#,##0';
    private const MONEY2_FMT  = '$#,##0.00';
    private const PCT_FMT     = '0%';

    public function buildWorkbook(array $rows, string $source, string $start, string $end): ?array
    {
        try {
            $sp    = new Spreadsheet();
            $sheet = $sp->getActiveSheet();
            $sheet->setTitle('Retention Data');
            $sheet->setShowGridlines(false);

            // Headers mirror VBA column order
            $headers = [
                'ID','Client','Retention Agent','Retention Date','Immediate Results',
                'Enrolled Debt','Reconsideration Date','Retained Date','Dropped Date',
                'First Payment Cleared','Cutoff','Payments','Agent','Commission Rate',
                'Violations','Retention Commission','Agent Deduction',
            ];
            $cols = array_merge(range('A','Z'), ['AA']);  // A-Q needed
            foreach ($headers as $i => $h) {
                $sheet->setCellValue($cols[$i] . '1', $h);
            }
            $this->headerStyle($sheet, 'A1:Q1');

            $r = 2;
            foreach ($rows as $row) {
                $sheet->setCellValue("A$r", $row['ID']                         ?? '');
                $sheet->setCellValue("B$r", $row['CLIENT']                     ?? '');
                $sheet->setCellValue("C$r", $row['RETENTION_AGENT']            ?? '');
                $this->setDate($sheet, "D$r", $row['RETENTION_DATE']           ?? null);
                $sheet->setCellValue("E$r", $row['IMMEDIATE_RESULTS']          ?? '');
                $sheet->setCellValue("F$r", (float)($row['ENROLLED_DEBT']      ?? 0));
                $this->setDate($sheet, "G$r", $row['RECONSIDERATION_DATE']     ?? null);
                $this->setDate($sheet, "H$r", $row['RETAINED_DATE']            ?? null);
                $this->setDate($sheet, "I$r", $row['DROPPED_DATE']             ?? null);
                $this->setDate($sheet, "J$r", $row['FIRST_PAYMENT_CLEARED_DATE'] ?? null);
                $this->setDate($sheet, "K$r", $row['CUTOFF']                   ?? null);
                $sheet->setCellValue("L$r", (int)($row['PAYMENTS']             ?? 0));
                $sheet->setCellValue("M$r", $row['AGENT']                      ?? '');
                $sheet->setCellValue("N$r", $row['COMMISSION_RATE']            ?? '');
                $sheet->setCellValue("O$r", $row['VIOLATIONS']                 ?? '');
                $sheet->setCellValue("P$r", $row['RETENTION_COMMISSION']       ?? '');
                $sheet->setCellValue("Q$r", $row['AGENT_DEDUCTION']            ?? '');
                $r++;
            }

            $last = max($r-1, 1);

            // Number formats (mirrors VBA)
            foreach (['D','G','H','I','J','K'] as $c) {
                $sheet->getStyle("{$c}2:{$c}{$last}")->getNumberFormat()->setFormatCode(self::DATE_FMT);
            }
            $sheet->getStyle("F2:F{$last}")->getNumberFormat()->setFormatCode(self::MONEY_FMT);
            $sheet->getStyle("P2:Q{$last}")->getNumberFormat()->setFormatCode(self::MONEY2_FMT);
            $sheet->getStyle("O2:O{$last}")->getNumberFormat()->setFormatCode(self::PCT_FMT);

            if ($last > 1) {
                $sheet->getStyle("A1:Q{$last}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
            }
            foreach (range('A','Q') as $c) $sheet->getColumnDimension($c)->setAutoSize(true);
            $sheet->getStyle("A1:Q{$last}")->getFont()->setName('Calibri')->setSize(9);
            $sheet->setSelectedCells('A1');

            $filename = "Retention Bonus Commission - {$source}.xlsx";
            $path     = storage_path("app/{$filename}");
            (new Xlsx($sp))->save($path);

            return ['filename' => $filename, 'path' => $path];
        } catch (\Throwable $e) {
            Log::error('BonusFormatter::buildWorkbook failed', ['err' => $e->getMessage()]);
            return null;
        }
    }

    private function headerStyle(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $s, string $range): void
    {
        $s->getStyle($range)->applyFromArray([
            'font'      => ['bold'=>true,'color'=>['argb'=>self::HEADER_FONT]],
            'alignment' => ['horizontal'=>Alignment::HORIZONTAL_CENTER],
            'fill'      => ['fillType'=>Fill::FILL_SOLID,'startColor'=>['argb'=>self::HEADER_FILL]],
            'borders'   => ['allBorders'=>['borderStyle'=>Border::BORDER_THIN]],
        ]);
    }

    private function setDate(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $s, string $cell, ?string $val): void
    {
        if ($val && strtotime($val) !== false) {
            $s->setCellValue($cell, \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel(strtotime($val)));
            $s->getStyle($cell)->getNumberFormat()->setFormatCode(self::DATE_FMT);
        }
    }
}
