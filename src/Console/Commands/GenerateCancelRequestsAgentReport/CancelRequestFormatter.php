<?php

namespace Cmd\Reports\Console\Commands\GenerateCancelRequestsAgentReport;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Illuminate\Support\Facades\Log;

/**
 * Builds the Cancel Request Agent Report Excel workbook.
 *
 * Sheet 1 – "Cancel Req Data"     Raw data rows
 * Sheet 2 – "Cancel Req Summary"  Agent summary with color coding:
 *   - Active agents:     green fill  (VBA: RGB(146,208,80)  = #92D050)
 *   - Terminated agents: red fill    (VBA: RGB(255,0,0)      = #FF0000)
 */
class CancelRequestFormatter
{
    private const GREEN = 'FF92D050';   // VBA RGB(146,208,80)
    private const RED   = 'FFFF0000';   // VBA RGB(255,0,0)
    private const HEADER_FILL = 'FF17853B';
    private const HEADER_FONT = 'FFFFFFFF';
    private const DATE_FORMAT = 'mm/dd/yyyy';

    public function buildWorkbook(
        array  $dataRows,
        array  $agentSummary,
        string $source,
        string $startDate,
        string $endDate
    ): ?array {
        try {
            $spreadsheet = new Spreadsheet();

            // Sheet 1 – raw data
            $sheet1 = $spreadsheet->getActiveSheet();
            $sheet1->setTitle('Cancel Req Data');
            $this->buildDataSheet($sheet1, $dataRows);

            // Sheet 2 – summary
            $spreadsheet->createSheet();
            $sheet2 = $spreadsheet->getSheet(1);
            $sheet2->setTitle('Cancel Req Summary');
            $this->buildSummarySheet($sheet2, $agentSummary);

            $spreadsheet->setActiveSheetIndex(0);

            $filename = "Cancel Req Summary - {$source} - {$startDate} to {$endDate}.xlsx";
            $path     = storage_path("app/{$filename}");

            (new Xlsx($spreadsheet))->save($path);
            return ['filename' => $filename, 'path' => $path];

        } catch (\Throwable $e) {
            Log::error('CancelRequestFormatter: buildWorkbook failed', ['err' => $e->getMessage()]);
            return null;
        }
    }

    private function buildDataSheet(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $s, array $rows): void
    {
        $s->setShowGridlines(false);
        $headers = ['LLG ID', 'Cancel Request Date', 'Agent', 'Dropped Date'];
        foreach (range('A', 'D') as $i => $c) {
            $s->setCellValue("{$c}1", $headers[$i]);
        }
        $this->applyHeaderStyle($s, 'A1:D1');

        $r = 2;
        foreach ($rows as $row) {
            $s->setCellValue("A$r", $row['LLG_ID']              ?? '');
            $this->setDate($s, "B$r", $row['CANCEL_REQUEST_DATE'] ?? null);
            $s->setCellValue("C$r", $row['AGENT']               ?? '');
            $this->setDate($s, "D$r", $row['DROPPED_DATE']       ?? null);
            $r++;
        }

        $last = max($r-1, 1);
        if ($last > 1) {
            $s->getStyle("A1:D{$last}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        }
        foreach (range('A','D') as $c) $s->getColumnDimension($c)->setAutoSize(true);
        $s->getStyle("A1:D{$last}")->getFont()->setName('Calibri')->setSize(9);
    }

    private function buildSummarySheet(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $s, array $summary): void
    {
        $s->setShowGridlines(false);
        $s->setCellValue('A1', 'Agent');
        $s->setCellValue('B1', 'Cancellation Requests');
        $this->applyHeaderStyle($s, 'A1:B1');

        $r = 2;
        foreach ($summary as $row) {
            $s->setCellValue("A$r", $row['agent']);
            $s->setCellValue("B$r", $row['count']);

            // VBA color coding
            $fill = $row['terminated'] ? self::RED : self::GREEN;
            $s->getStyle("A{$r}:B{$r}")->getFill()
              ->setFillType(Fill::FILL_SOLID)
              ->getStartColor()->setARGB($fill);

            $r++;
        }

        $last = max($r-1, 1);
        if ($last > 1) {
            $s->getStyle("A1:B{$last}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        }
        foreach (range('A','B') as $c) $s->getColumnDimension($c)->setAutoSize(true);
        $s->getStyle("A1:B{$last}")->getFont()->setName('Calibri')->setSize(9);
    }

    private function applyHeaderStyle(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $s, string $range): void
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
        if ($val) {
            $ts = is_numeric($val) ? (int)$val : strtotime($val);
            if ($ts !== false && $ts > 0) {
                $s->setCellValue($cell, \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel($ts));
                $s->getStyle($cell)->getNumberFormat()->setFormatCode(self::DATE_FORMAT);
            }
        }
    }
}
