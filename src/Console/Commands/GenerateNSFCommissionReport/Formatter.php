<?php

namespace Cmd\Reports\Console\Commands\GenerateNSFCommissionReport;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class Formatter
{
    private const HEADER_FILL  = 'FF17853B';
    private const HEADER_FONT  = 'FFFFFFFF';
    private const TIER_FILL    = 'FFC5C5C5';
    private const DATE_FORMAT  = 'mm/dd/yyyy';
    private const MONEY_FORMAT = '$#,##0.00';
    private const PCT_FORMAT   = '0%';

    public function buildWorkbook(
        array  $dataRows,
        array  $commissionRows,
        string $source,
        string $startDate,
        string $endDate
    ): array {
        $spreadsheet = new Spreadsheet();

        $dataSheet = $spreadsheet->getActiveSheet();
        $dataSheet->setTitle('Data');
        $dataSheet->setShowGridlines(false);
        $this->buildDataSheet($dataSheet, $dataRows);

        $spreadsheet->createSheet();
        $commSheet = $spreadsheet->getSheet(1);
        $commSheet->setTitle('Commission');
        $commSheet->setShowGridlines(false);
        $this->buildCommissionSheet($commSheet, $commissionRows);

        $spreadsheet->setActiveSheetIndex(0);

        $period   = date('m-Y', strtotime($startDate));
        $filename = "NSF Commission Report - {$source} - {$period}.xlsx";
        $path     = storage_path("app/{$filename}");

        $writer = new Xlsx($spreadsheet);
        $writer->setPreCalculateFormulas(false);
        $writer->save($path);

        return ['filename' => $filename, 'path' => $path];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Data sheet
    // ─────────────────────────────────────────────────────────────────────────

    private function buildDataSheet(Worksheet $s, array $rows): void
    {
        $headers = ['ID', 'Agent', 'NSF Returned Date', 'NSF Action', 'NSF Recoup Date', 'Cleared Date', 'Valid Commission'];
        foreach ($headers as $i => $h) {
            $col = $i + 1;
            $s->setCellValueByColumnAndRow($col, 1, $h);
        }
        $this->styleHeader($s, 'A1:G1');

        $r = 2;
        foreach ($rows as $row) {
            $s->setCellValueExplicit("A$r", (string) ($row['ID'] ?? ''), DataType::TYPE_NUMERIC);
            $s->setCellValue("B$r", (string) ($row['AGENT']            ?? ''));
            $this->setDateCell($s, "C$r", $row['NSF_RETURNED_DATE']    ?? null);
            $s->setCellValue("D$r", (string) ($row['NSF_ACTION']       ?? ''));
            $this->setDateCell($s, "E$r", $row['NSF_RECOUP_DATE']      ?? null);
            $this->setDateCell($s, "F$r", $row['CLEARED_DATE']         ?? null);
            $s->setCellValue("G$r", (bool) ($row['valid_commission']   ?? false));
            $r++;
        }

        $last = max(2, $r - 1);
        $s->getStyle("C2:C{$last}")->getNumberFormat()->setFormatCode(self::DATE_FORMAT);
        $s->getStyle("E2:E{$last}")->getNumberFormat()->setFormatCode(self::DATE_FORMAT);
        $s->getStyle("F2:F{$last}")->getNumberFormat()->setFormatCode(self::DATE_FORMAT);
        $this->applyBorders($s, "A1:G{$last}");
        $this->applyFont($s, "A1:G{$last}");
        $this->autoWidths($s, 7);
        $s->freezePane('A2');
        $s->setSelectedCells('A1');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Commission sheet — mirrors VBA Commission sheet layout
    // ─────────────────────────────────────────────────────────────────────────

    private function buildCommissionSheet(Worksheet $s, array $rows): void
    {
        // Main table headers (A1:I1)
        $headers = ['NGO', 'Assignments', 'Actions', 'Ratio', 'Actions Tier', 'Cleared Tier', 'Rate', 'Clears', 'Commission'];
        foreach ($headers as $i => $h) {
            $s->setCellValueByColumnAndRow($i + 1, 1, $h);
        }
        $this->styleHeader($s, 'A1:I1');

        // Data rows
        $r = 2;
        foreach ($rows as $row) {
            $s->setCellValue("A$r", $row['agent']);
            $s->setCellValue("B$r", $row['assignments']);
            $s->setCellValue("C$r", $row['actions']);
            $s->setCellValue("D$r", round($row['ratio'], 4));
            // Tier columns are informational (computed in PHP)
            $s->setCellValue("E$r", '');
            $s->setCellValue("F$r", '');
            $s->setCellValue("G$r", $row['rate']);
            $s->setCellValue("H$r", $row['clears']);
            $s->setCellValue("I$r", $row['commission']);
            $r++;
        }

        $last = max(2, $r - 1);
        $s->getStyle("D2:D{$last}")->getNumberFormat()->setFormatCode(self::PCT_FORMAT);
        $s->getStyle("G2:G{$last}")->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);
        $s->getStyle("I2:I{$last}")->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);
        $this->applyBorders($s, "A1:I{$last}");
        $this->applyFont($s, "A1:I{$last}");
        $s->getStyle("A1:I1")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // Commission tier reference table (columns K–N, mirroring VBA N1:Q5)
        $this->buildTierTable($s);

        $this->autoWidths($s, 14);
        $s->setSelectedCells('A1');
    }

    /**
     * Tier reference table placed at K1:N5 — same values as VBA N1:Q5.
     *
     *                │  0.2  │  0.4  │  0.6
     * ───────────────┼───────┼───────┼──────
     *  1  (Clears)   │ $1.50 │ $1.75 │ $2.00
     *  51 (Clears)   │ $2.50 │ $2.75 │ $3.00
     * 101 (Clears)   │ $3.50 │ $3.75 │ $4.00
     */
    private function buildTierTable(Worksheet $s): void
    {
        $s->setCellValue('K1', 'Commission Tiers');
        $s->mergeCells('K1:N1');
        $s->getStyle('K1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $s->getStyle('K1')->getFont()->setBold(true);

        // Header row: blank label cell + 3 ratio columns
        $s->setCellValue('K2', '');
        $s->getStyle('K2')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::TIER_FILL);
        $ratios = [0.2, 0.4, 0.6];
        foreach ($ratios as $i => $v) {
            $col = chr(76 + $i); // L, M, N
            $s->setCellValue("{$col}2", $v);
            $s->getStyle("{$col}2")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::TIER_FILL);
            $s->getStyle("{$col}2")->getNumberFormat()->setFormatCode(self::PCT_FORMAT);
        }

        // Data rows
        $thresholds = [1, 51, 101];
        $rates = [
            [1.50, 1.75, 2.00],
            [2.50, 2.75, 3.00],
            [3.50, 3.75, 4.00],
        ];
        foreach ($thresholds as $ri => $threshold) {
            $excelRow = $ri + 3;
            $s->setCellValue("K{$excelRow}", $threshold);
            foreach ($rates[$ri] as $ci => $rate) {
                $col = chr(76 + $ci); // L, M, N
                $s->setCellValue("{$col}{$excelRow}", $rate);
                $s->getStyle("{$col}{$excelRow}")->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);
            }
        }

        $this->applyBorders($s, 'K1:N5');
        $this->applyFont($s, 'K1:N5');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function styleHeader(Worksheet $s, string $range): void
    {
        $s->getStyle($range)->applyFromArray([
            'font'      => ['bold' => true, 'color' => ['argb' => self::HEADER_FONT]],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::HEADER_FILL]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
    }

    private function applyBorders(Worksheet $s, string $range): void
    {
        $s->getStyle($range)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    }

    private function applyFont(Worksheet $s, string $range): void
    {
        $s->getStyle($range)->getFont()->setName('Calibri')->setSize(9);
    }

    private function autoWidths(Worksheet $s, int $cols): void
    {
        for ($i = 1; $i <= $cols; $i++) {
            $s->getColumnDimensionByColumn($i)->setAutoSize(true);
        }
    }

    private function setDateCell(Worksheet $s, string $cell, $value): void
    {
        if ($value === null || $value === '') {
            $s->setCellValue($cell, '');
            return;
        }
        $ts = strtotime((string) $value);
        if ($ts === false) {
            $s->setCellValue($cell, (string) $value);
            return;
        }
        $s->setCellValue($cell, \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel($ts));
    }
}
