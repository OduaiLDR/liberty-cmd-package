<?php

namespace Cmd\Reports\Console\Commands\GenerateEnrollmentGrossNetReport;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class Formatter
{
    private const DEBT_ROWS = [
        ['label' => 'Gross', 'key' => 'gross', 'field' => 'debt', 'format' => 'currency'],
        ['label' => 'Cancelled', 'key' => 'cancelled', 'field' => 'debt', 'format' => 'currency'],
        ['label' => 'At-Risk', 'key' => 'at_risk', 'field' => 'debt', 'format' => 'currency'],
        ['label' => 'Sellable', 'key' => 'sellable', 'field' => 'debt', 'format' => 'currency'],
        ['label' => 'Sold', 'key' => 'sold', 'field' => 'debt', 'format' => 'currency'],
        ['label' => 'Cleared', 'key' => 'cleared', 'field' => 'debt', 'format' => 'currency'],
        ['label' => 'Pending', 'key' => 'pending', 'field' => 'debt', 'format' => 'currency'],
        ['label' => 'Projection Total', 'key' => 'projection_total', 'field' => 'debt', 'format' => 'currency'],
        ['label' => 'Sell Rate', 'key' => 'sell_rate', 'field' => null, 'format' => 'percent'],
    ];

    private const CLIENT_ROWS = [
        ['label' => 'Gross', 'key' => 'gross', 'field' => 'count', 'format' => 'count'],
        ['label' => 'Cancelled', 'key' => 'cancelled', 'field' => 'count', 'format' => 'count'],
        ['label' => 'At-Risk', 'key' => 'at_risk', 'field' => 'count', 'format' => 'count'],
        ['label' => 'Sellable', 'key' => 'sellable', 'field' => 'count', 'format' => 'count'],
        ['label' => 'Sold', 'key' => 'sold', 'field' => 'count', 'format' => 'count'],
        ['label' => 'Cleared', 'key' => 'cleared', 'field' => 'count', 'format' => 'count'],
        ['label' => 'Pending', 'key' => 'pending', 'field' => 'count', 'format' => 'count'],
        ['label' => 'Projection Total', 'key' => 'projection_total', 'field' => 'count', 'format' => 'count'],
    ];

    /**
     * @param array<string, array{label:string,buckets:array<string,mixed>}> $data
     * @return array{filename:string,path:string}|null
     */
    public function buildWorkbook(array $data, string $reportDate): ?array
    {
        if ($data === []) {
            return null;
        }

        $filename = 'Enrollment Gross-Net Summary - ' . date('m-d-Y', strtotime($reportDate)) . '.xlsx';
        $path = storage_path('app/' . $filename);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Monthly Enrollment Summary');
        $sheet->setShowGridlines(false);

        $months = array_values($data);
        $monthCount = count($months);
        $lastCol = $this->columnLetter($monthCount + 1);

        $sheet->getColumnDimension('A')->setWidth(22);
        for ($i = 2; $i <= $monthCount + 1; $i++) {
            $sheet->getColumnDimension($this->columnLetter($i))->setWidth(14);
        }

        $sheet->freezePane('B4');

        $sheet->setCellValue('A1', 'Enrollment Gross/Net Summary - ' . date('m/d/Y', strtotime($reportDate)));
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(12);

        $sheet->setCellValue('A2', 'Current month through ' . date('M j', strtotime('-1 day', strtotime($reportDate))));
        $sheet->getStyle('A2')->getFont()->setSize(9)->getColor()->setRGB('666666');

        $row = 4;
        $row = $this->writeSection($sheet, $row, 'Enrolled Debt', 'Category', $months, self::DEBT_ROWS, $lastCol);
        $row += 2;
        $this->writeSection($sheet, $row, 'Enrolled Clients', 'Category', $months, self::CLIENT_ROWS, $lastCol);

        $sheet->setSelectedCells('B4');

        $writer = new Xlsx($spreadsheet);
        $writer->save($path);

        return [
            'filename' => $filename,
            'path' => $path,
        ];
    }

    /**
     * HTML body matching the emailed digest tables (same data as the Excel sheet).
     *
     * @param array<string, array{label:string,buckets:array<string,mixed>}> $data
     */
    public function buildEmailBody(array $data, string $reportDate): string
    {
        $labelStyle = 'border:1px solid #000;padding:6px 10px;font-weight:bold;white-space:nowrap;';
        $thStyle = 'border:1px solid #000;padding:6px 10px;background:#D3D3D3;text-align:center;';
        $titleStyle = 'border:1px solid #000;padding:6px 10px;background:#1a5276;color:#fff;text-align:center;font-weight:bold;';
        $cellStyle = 'border:1px solid #000;padding:6px 10px;text-align:right;';

        $th = fn (string $label): string => "<th style=\"{$thStyle}\">" . htmlspecialchars($label) . '</th>';
        $tdLabel = fn (string $label): string => "<td style=\"{$labelStyle}\">" . htmlspecialchars($label) . '</td>';
        $tdMoney = fn (float $v): string => "<td style=\"{$cellStyle}\">\$" . number_format($v, 0) . '</td>';
        $tdCount = fn (int $v): string => "<td style=\"{$cellStyle}\">" . number_format($v) . '</td>';
        $tdPercent = function (?float $v) use ($cellStyle): string {
            if ($v === null) {
                return "<td style=\"{$cellStyle}\"></td>";
            }

            return "<td style=\"{$cellStyle}\">" . round($v * 100) . '%</td>';
        };

        $labelColWidth = 130;
        $dataColWidth = 90;
        $months = array_values($data);

        $buildTable = function (
            string $title,
            string $labelHeader,
            array $rowDefs,
            callable $cellFor
        ) use ($th, $tdLabel, $titleStyle, $labelColWidth, $dataColWidth, $months): string {
            $colCount = count($months) + 1;
            $tableWidth = $labelColWidth + ($dataColWidth * count($months));

            $html = '<table style="border-collapse:collapse;table-layout:fixed;width:' . $tableWidth . 'px;font-family:Arial,sans-serif;font-size:13px;margin-bottom:20px;overflow-x:auto;">';
            $html .= '<colgroup><col style="width:' . $labelColWidth . 'px;">';
            foreach ($months as $col) {
                $html .= '<col style="width:' . $dataColWidth . 'px;">';
            }
            $html .= '</colgroup>';
            $html .= '<tr><th colspan="' . $colCount . '" style="' . $titleStyle . '">' . htmlspecialchars($title) . '</th></tr>';
            $html .= '<tr>' . $th($labelHeader);
            foreach ($months as $col) {
                $html .= $th($col['label']);
            }
            $html .= '</tr>';

            foreach ($rowDefs as $rowDef) {
                $html .= '<tr>' . $tdLabel($rowDef['label']);
                foreach ($months as $col) {
                    $html .= $cellFor($col['buckets'], $rowDef);
                }
                $html .= '</tr>';
            }

            return $html . '</table>';
        };

        $html = '<b>Enrollment Gross/Net Summary - ' . date('m/d/Y', strtotime($reportDate)) . '</b><br><br>';
        $html .= '<div style="overflow-x:auto;">';

        $html .= $buildTable(
            'Enrolled Debt',
            'Category',
            self::DEBT_ROWS,
            function (array $buckets, array $rowDef) use ($tdMoney, $tdPercent): string {
                if ($rowDef['format'] === 'percent') {
                    return $tdPercent($buckets[$rowDef['key']] ?? null);
                }
                $field = $rowDef['field'] ?? 'debt';

                return $tdMoney((float) ($buckets[$rowDef['key']][$field] ?? 0));
            },
        );

        $html .= $buildTable(
            'Enrolled Clients',
            'Category',
            self::CLIENT_ROWS,
            function (array $buckets, array $rowDef) use ($tdCount): string {
                $field = $rowDef['field'] ?? 'count';

                return $tdCount((int) ($buckets[$rowDef['key']][$field] ?? 0));
            },
        );

        $html .= '</div>';
        $html .= '<p style="margin-top:12px;font-size:11px;color:#888;">Current month through '
            . date('M j', strtotime('-1 day', strtotime($reportDate)))
            . '. Excel workbook attached (13 months, column A frozen).</p>';

        return $html;
    }

    /**
     * @param array<int, array{label:string,buckets:array<string,mixed>}> $months
     * @param array<int, array{label:string,key:string,field:?string,format:string}> $rows
     */
    private function writeSection(
        \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet,
        int $startRow,
        string $title,
        string $labelHeader,
        array $months,
        array $rows,
        string $lastCol
    ): int {
        $monthCount = count($months);
        $sheet->setCellValue("A{$startRow}", $title);
        $sheet->mergeCells("A{$startRow}:{$lastCol}{$startRow}");
        $sheet->getStyle("A{$startRow}:{$lastCol}{$startRow}")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1A5276']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);

        $headerRow = $startRow + 1;
        $sheet->setCellValue("A{$headerRow}", $labelHeader);
        $colIndex = 2;
        foreach ($months as $month) {
            $col = $this->columnLetter($colIndex);
            $sheet->setCellValue("{$col}{$headerRow}", $month['label']);
            $colIndex++;
        }
        $sheet->getStyle("A{$headerRow}:{$lastCol}{$headerRow}")->applyFromArray([
            'font' => ['bold' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'D3D3D3']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);

        $rowIndex = $headerRow + 1;
        foreach ($rows as $rowDef) {
            $sheet->setCellValue("A{$rowIndex}", $rowDef['label']);
            $sheet->getStyle("A{$rowIndex}")->getFont()->setBold(true);

            $colIndex = 2;
            foreach ($months as $month) {
                $col = $this->columnLetter($colIndex);
                $buckets = $month['buckets'];
                if ($rowDef['format'] === 'percent') {
                    $value = $buckets[$rowDef['key']] ?? null;
                    if ($value === null) {
                        $sheet->setCellValue("{$col}{$rowIndex}", '');
                    } else {
                        $sheet->setCellValue("{$col}{$rowIndex}", $value);
                        $sheet->getStyle("{$col}{$rowIndex}")->getNumberFormat()->setFormatCode('0%');
                    }
                } else {
                    $field = $rowDef['field'] ?? 'debt';
                    $value = $buckets[$rowDef['key']][$field] ?? 0;
                    $sheet->setCellValue("{$col}{$rowIndex}", $value);
                    if ($rowDef['format'] === 'currency') {
                        $sheet->getStyle("{$col}{$rowIndex}")->getNumberFormat()->setFormatCode('$#,##0');
                    } else {
                        $sheet->getStyle("{$col}{$rowIndex}")->getNumberFormat()->setFormatCode('#,##0');
                    }
                }
                $sheet->getStyle("{$col}{$rowIndex}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                $colIndex++;
            }

            if (in_array($rowDef['label'], ['Projection Total', 'Sell Rate'], true)) {
                $sheet->getStyle("A{$rowIndex}:{$lastCol}{$rowIndex}")->getFont()->setBold(true);
            }

            $rowIndex++;
        }

        $lastDataRow = $rowIndex - 1;
        $sheet->getStyle("A{$startRow}:{$lastCol}{$lastDataRow}")
            ->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle("A{$startRow}:{$lastCol}{$lastDataRow}")
            ->getFont()->setName('Calibri')->setSize(10);

        return $lastDataRow;
    }

    private function columnLetter(int $index): string
    {
        $letter = '';
        while ($index > 0) {
            $index--;
            $letter = chr(65 + ($index % 26)) . $letter;
            $index = intdiv($index, 26);
        }

        return $letter;
    }
}
