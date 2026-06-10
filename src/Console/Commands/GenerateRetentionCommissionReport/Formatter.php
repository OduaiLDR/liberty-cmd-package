<?php

namespace Cmd\Reports\Console\Commands\GenerateRetentionCommissionReport;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Builds the Retention Commission Excel workbook.
 *
 * Sheet 1 – "Retention Commission Report"  (raw data, mirrors VBA layout)
 * Sheet 2 – "Commission Summary"           (agent-level summary)
 *
 * Commission tier thresholds (VBA IF(D2<0.2 …)):
 *   Tier 0: < 20%      – no commission
 *   Tier 1: 20–34.9%   – T1 column
 *   Tier 2: 35–49.9%   – T2 column
 *   Tier 3: ≥ 50%      – T3 column
 */
class Formatter
{
    private const HEADER_FILL   = 'FF17853B';  // LDR green
    private const HEADER_FONT   = 'FFFFFFFF';
    private const DATE_FORMAT   = 'mm/dd/yyyy';
    private const MONEY_FORMAT  = '$#,##0';
    private const PCT_FORMAT    = '0%';

    /**
     * Build the two-sheet Excel workbook and return file info,
     * or null if something prevented writing.
     *
     * @param  array  $rows       Enriched rows from the data pipeline
     * @param  string $source     Display name, e.g. "LDR" or "Progress Law"
     * @param  string $startDate  Y-m-d
     * @param  string $endDate    Y-m-d
     * @param  array  $agents     Full agent list for Summary sheet
     * @param  string|null $agentFilter  When set, title uses the agent name
     * @return array{filename:string,path:string}|null
     */
    public function buildWorkbook(
        array  $rows,
        string $source,
        string $startDate,
        string $endDate,
        array  $agents,
        ?string $agentFilter = null
    ): ?array {
        try {
            $spreadsheet = new Spreadsheet();

            // ── Sheet 1: Retention Commission Report ──────────────────────
            $sheet1 = $spreadsheet->getActiveSheet();
            $sheet1->setTitle('Retention Commission Report');
            $this->buildDataSheet($sheet1, $rows);

            // ── Sheet 2: Commission Summary ───────────────────────────────
            $spreadsheet->createSheet();
            $sheet2 = $spreadsheet->getSheet(1);
            $sheet2->setTitle('Commission Summary');
            $this->buildSummarySheet($sheet2, $rows, $agents, $startDate, $endDate);

            $spreadsheet->setActiveSheetIndex(0);

            // Filename mirrors VBA: "Retention Commission (LDR) - All.xlsx" or "- AgentName.xlsx"
            $suffix   = $agentFilter ? $agentFilter : 'All';
            $filename = "Retention Commission ({$source}) - {$suffix}.xlsx";
            $path     = storage_path("app/{$filename}");

            $writer = new Xlsx($spreadsheet);
            $writer->save($path);

            return ['filename' => $filename, 'path' => $path];
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Formatter::buildWorkbook failed', ['err' => $e->getMessage()]);
            return null;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    private function buildDataSheet(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, array $rows): void
    {
        $sheet->setShowGridlines(false);

        // Headers (VBA columns A-O)
        $headers = [
            'ID', 'Client', 'Retention Agent', 'Retention Date',
            'Immediate Results', 'Enrolled Debt', 'Cleared Payments',
            'Reconsideration Date', 'Dropped Date', 'Retained Date',
            'Retention Payment Date', 'Commission T1', 'Commission T2', 'Commission T3',
            'Cancel Request Date',
        ];
        $cols = range('A', 'O');
        foreach ($headers as $i => $h) {
            $sheet->setCellValue($cols[$i] . '1', $h);
        }
        $this->applyHeaderStyle($sheet, 'A1:O1');

        // Data rows
        $r = 2;
        foreach ($rows as $row) {
            $sheet->setCellValue("A$r", $row['ID']                     ?? '');
            $sheet->setCellValue("B$r", $row['CLIENT']                 ?? '');
            $sheet->setCellValue("C$r", $row['RETENTION_AGENT']        ?? '');
            $this->setDate($sheet, "D$r", $row['RETENTION_DATE']       ?? null);
            $sheet->setCellValue("E$r", $row['IMMEDIATE_RESULTS']      ?? '');
            $sheet->setCellValue("F$r", (float)($row['ENROLLED_DEBT']  ?? 0));
            $sheet->setCellValue("G$r", (int)($row['CLEARED_PAYMENTS'] ?? 0));
            $this->setDate($sheet, "H$r", $row['RECONSIDERATION_DATE'] ?? null);
            $this->setDate($sheet, "I$r", $row['DROPPED_DATE']         ?? null);
            $this->setDate($sheet, "J$r", $row['RETAINED_DATE']        ?? null);
            $this->setDate($sheet, "K$r", $row['RETENTION_PAYMENT_DATE'] ?? null);
            $sheet->setCellValue("L$r", $row['RETENTION_COMMISSION_T1'] ?? '');
            $sheet->setCellValue("M$r", $row['RETENTION_COMMISSION_T2'] ?? '');
            $sheet->setCellValue("N$r", $row['RETENTION_COMMISSION_T3'] ?? '');
            $this->setDate($sheet, "O$r", $row['CANCEL_REQUEST_DATE']   ?? null);
            $r++;
        }

        $last = max($r - 1, 1);

        // Number formats
        foreach (['D', 'H', 'I', 'J', 'K', 'O'] as $c) {
            $sheet->getStyle("{$c}2:{$c}{$last}")->getNumberFormat()->setFormatCode(self::DATE_FORMAT);
        }
        foreach (['F', 'L', 'M', 'N'] as $c) {
            $sheet->getStyle("{$c}2:{$c}{$last}")->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);
        }

        // Borders + auto-width
        if ($last > 1) {
            $sheet->getStyle("A1:O{$last}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        }
        foreach ($cols as $c) {
            $sheet->getColumnDimension($c)->setAutoSize(true);
        }
        $sheet->getStyle("A1:O{$last}")->getFont()->setName('Calibri')->setSize(9);
        $sheet->setSelectedCells('A1');
    }

    // ─────────────────────────────────────────────────────────────────────────
    private function buildSummarySheet(
        \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet,
        array $rows,
        array $agents,
        string $startDate,
        string $endDate
    ): void {
        $sheet->setShowGridlines(false);

        // Headers mirror VBA Commission Summary sheet
        $headers = ['Retention Agent', 'Assigned', 'Retained', '% Retained', 'Tier', 'Commission'];
        foreach (range('A', 'F') as $i => $c) {
            $sheet->setCellValue("{$c}1", $headers[$i]);
        }
        $this->applyHeaderStyle($sheet, 'A1:F1');

        // Pre-index rows by agent (UPPER) for fast lookup
        $byAgent = [];
        foreach ($rows as $row) {
            $ag = strtoupper(trim((string)($row['RETENTION_AGENT'] ?? '')));
            $byAgent[$ag][] = $row;
        }

        $r = 2;
        foreach ($agents as $agent) {
            $agUpper  = strtoupper($agent);
            $agRows   = $byAgent[$agUpper] ?? [];

            // Assigned: contacts whose cancel request (col O) is in range (mirrors VBA COUNTIFS col O)
            $assigned = 0;
            foreach ($agRows as $row) {
                $cancelDate = $row['CANCEL_REQUEST_DATE'] ?? null;
                if ($cancelDate) {
                    $cTime = is_numeric($cancelDate) ? (int)$cancelDate : strtotime($cancelDate);
                    if ($cTime && $cTime >= strtotime($startDate) && $cTime <= strtotime($endDate . ' 23:59:59')) {
                        $assigned++;
                    }
                }
            }

            // Retained: contacts whose retention date (col D) is in range
            $retained = 0;
            foreach ($agRows as $row) {
                $retDate = $row['RETENTION_DATE'] ?? null;
                if ($retDate && $retDate >= $startDate && $retDate <= $endDate) {
                    $retained++;
                }
            }

            $pct  = $assigned > 0 ? ($retained / $assigned) : 0;
            $tier = match(true) {
                $pct < 0.20 => 0,
                $pct < 0.35 => 1,
                $pct < 0.50 => 2,
                default     => 3,
            };

            // Commission: sum the tier column for this agent where K (payment date) is in range
            $commission = 0.0;
            if ($tier > 0) {
                $tierKey = 'RETENTION_COMMISSION_T' . $tier;
                foreach ($agRows as $row) {
                    $payDate = $row['RETENTION_PAYMENT_DATE'] ?? null;
                    if ($payDate && $payDate >= $startDate && $payDate <= $endDate) {
                        $commission += (float)($row[$tierKey] ?? 0);
                    }
                }
            }

            $sheet->setCellValue("A$r", $agent);
            $sheet->setCellValue("B$r", $assigned);
            $sheet->setCellValue("C$r", $retained);
            $sheet->setCellValue("D$r", $pct);
            $sheet->setCellValue("E$r", $tier);
            $sheet->setCellValue("F$r", $commission);
            $r++;
        }

        $last = max($r - 1, 1);

        // Number formats
        $sheet->getStyle("D2:D{$last}")->getNumberFormat()->setFormatCode(self::PCT_FORMAT);
        $sheet->getStyle("F2:F{$last}")->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);

        // Borders + auto-width
        if ($last > 1) {
            $sheet->getStyle("A1:F{$last}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        }
        foreach (range('A', 'F') as $c) {
            $sheet->getColumnDimension($c)->setAutoSize(true);
        }
        $sheet->getStyle("A1:F{$last}")->getFont()->setName('Calibri')->setSize(9);
    }

    // ─────────────────────────────────────────────────────────────────────────
    private function applyHeaderStyle(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'font'      => ['bold' => true, 'color' => ['argb' => self::HEADER_FONT]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::HEADER_FILL]],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        ]);
    }

    private function setDate($sheet, string $cell, ?string $val): void
    {
        if (!$val) {
            $sheet->setCellValue($cell, '');
            return;
        }
        $ts = is_numeric($val) ? (int)$val : strtotime($val);
        if ($ts) {
            $sheet->setCellValue($cell, \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel($ts));
            $sheet->getStyle($cell)->getNumberFormat()->setFormatCode('mm/dd/yyyy');
        }
    }
}
