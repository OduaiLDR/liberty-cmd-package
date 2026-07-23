<?php

namespace Cmd\Reports\Console\Commands\GenerateCancellationReport;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Builds the per-company "staircase" cohort workbook: "{Category} - All Contacts" / "{Category} -
 * With Settlements" (the 5-block cohort tables, computed from that company's own Data 1/Data 2)
 * plus "Cancellation Report" (the same 3 static company blocks reused verbatim in every workbook —
 * only the block matching that source's own bucket(s) will show non-zero data), matching the real
 * reference workbooks ("LDR - Cancellation Report.xlsx" / "Progress Law - Cancellation Report.xlsx").
 *
 * The raw Data 1/Data 2 Snowflake extracts that feed these formulas are build inputs only; they
 * are never rendered as sheets or emailed.
 *
 * See CohortReportBuilder for where the cohort sheets' formulas were extracted from.
 */
class Formatter
{
    private const BLOCK_STRIDE = 15;

    /**
     * @param array<int, array<string, mixed>> $allContactsBlocks
     * @param array<int, array<string, mixed>> $withSettlementsBlocks
     * @param array<int, array<string, mixed>> $cancellationReportBlocks
     */
    public function buildWorkbook(
        string $category,
        array $allContactsBlocks,
        array $withSettlementsBlocks,
        array $cancellationReportBlocks
    ): ?array {
        $filename = "{$category} - Cancellation Report - " . date('m-d-Y') . '.xlsx';
        $path = storage_path('app/' . $filename);

        $spreadsheet = new Spreadsheet();
        $spreadsheet->removeSheetByIndex(0);

        $this->buildCohortSheet($spreadsheet, "{$category} - All Contacts", $allContactsBlocks, true);
        $this->buildCohortSheet($spreadsheet, "{$category} - With Settlements", $withSettlementsBlocks, true);
        $this->buildCohortSheet($spreadsheet, 'Cancellation Report', $cancellationReportBlocks, false);

        $spreadsheet->setActiveSheetIndex(0);

        $writer = new Xlsx($spreadsheet);
        $writer->save($path);

        return [
            'filename' => $filename,
            'path' => $path,
        ];
    }

    // Zero renders as a dash, negatives get a minus — blanks (future/inapplicable offset windows)
    // are gray-filled cells instead (see below), not just empty white ones.
    private const INT_FORMAT = '#,##0;-#,##0;"-"';
    private const PERCENT_FORMAT = '0%;-0%;"-"';
    private const BLANK_FILL = 'D9D9D9';
    private const NAVY = '1F4E78';
    private const LIGHT_BLUE = 'DDEBF7';
    private const ZEBRA = 'F2F7FC';
    private const GRID_COLOR = 'BFBFBF';

    /**
     * Renders a stack of 12-month cohort tables (blocks), each occupying 15 rows: a title row
     * (Month / [Gross Enrollments /] block name, e.g. "Net Enrollments"), a label row (Month 0..12
     * offset headers), and 12 month rows — matching the template's row layout (blocks start at
     * rows 1, 16, 31, ...). Cells for offset windows that haven't happened yet are gray-filled,
     * forming a staircase.
     *
     * The "Cancellation Report" sheet has no Gross Enrollments column (13 offset columns run B-N
     * instead of C-O) — set $includeGross accordingly.
     *
     * @param array<int, array{title: string, percent: bool, months: string[], gross: int[], values: array<int, array<int, int|float|null>>}> $blocks
     */
    private function buildCohortSheet(Spreadsheet $spreadsheet, string $title, array $blocks, bool $includeGross): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle($title);
        $sheet->setShowGridlines(false);

        $offsetStartCol = $includeGross ? 'C' : 'B';
        $lastCol = $includeGross ? 'O' : 'N';
        // The column identifying each row (Month, and Gross Enrollments when present) gets a bold
        // divider against the data grid so the two zones read as visually distinct.
        $dividerCol = $includeGross ? 'B' : 'A';

        $row = 1;
        foreach ($blocks as $block) {
            $sheet->setCellValue("A{$row}", 'Month');
            if ($includeGross) {
                $sheet->setCellValue("B{$row}", "Gross\nEnrollments");
            }
            $sheet->setCellValue("{$offsetStartCol}{$row}", $block['title']);
            $sheet->mergeCells("{$offsetStartCol}{$row}:{$lastCol}{$row}");
            $sheet->getStyle("A{$row}:{$lastCol}{$row}")->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 10],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::NAVY]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            ]);
            $sheet->getRowDimension($row)->setRowHeight(26);

            $labelRow = $row + 1;
            for ($n = 0; $n <= 12; $n++) {
                $col = chr(ord($offsetStartCol) + $n);
                $sheet->setCellValue("{$col}{$labelRow}", "Month {$n}");
            }
            $sheet->getStyle("A{$labelRow}:{$lastCol}{$labelRow}")->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => self::NAVY]],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::LIGHT_BLUE]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                'borders' => ['bottom' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => self::NAVY]]],
            ]);

            $dataStart = $row + 2;
            $dataEnd = $dataStart + count($block['months']) - 1;
            foreach ($block['months'] as $i => $month) {
                $r = $dataStart + $i;
                $sheet->setCellValue("A{$r}", $month);
                $sheet->getStyle("A{$r}")->applyFromArray([
                    'numberFormat' => ['formatCode' => 'mmmm yyyy'],
                    'font' => ['bold' => true, 'color' => ['rgb' => self::NAVY]],
                ]);

                if ($includeGross) {
                    $sheet->setCellValue("B{$r}", $block['gross'][$i]);
                    $sheet->getStyle("B{$r}")->applyFromArray([
                        'numberFormat' => ['formatCode' => self::INT_FORMAT],
                        'font' => ['bold' => true],
                    ]);
                }

                foreach ($block['values'][$i] as $n => $value) {
                    $col = chr(ord($offsetStartCol) + $n);
                    if ($value === null) {
                        $sheet->getStyle("{$col}{$r}")->applyFromArray([
                            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::BLANK_FILL]],
                        ]);
                        continue;
                    }
                    $sheet->setCellValue("{$col}{$r}", $value);
                    $sheet->getStyle("{$col}{$r}")->getNumberFormat()
                        ->setFormatCode($block['percent'] ? self::PERCENT_FORMAT : self::INT_FORMAT);
                }

                if ($i % 2 === 1) {
                    $sheet->getStyle("A{$r}:{$lastCol}{$r}")->applyFromArray([
                        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::ZEBRA]],
                    ]);
                    // Re-apply the staircase fill on top of the zebra stripe so blanks stay gray.
                    foreach ($block['values'][$i] as $n => $value) {
                        if ($value !== null) {
                            continue;
                        }
                        $col = chr(ord($offsetStartCol) + $n);
                        $sheet->getStyle("{$col}{$r}")->applyFromArray([
                            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::BLANK_FILL]],
                        ]);
                    }
                }
            }

            $sheet->getStyle("A{$dataStart}:{$lastCol}{$dataEnd}")->applyFromArray([
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['rgb' => self::GRID_COLOR]]],
            ]);
            $sheet->getStyle("A{$dataStart}:A{$dataEnd}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

            // Divider between the identifying columns (Month [/ Gross]) and the offset data grid.
            $sheet->getStyle("{$dividerCol}{$row}:{$dividerCol}{$dataEnd}")->getBorders()->getRight()
                ->setBorderStyle(Border::BORDER_MEDIUM)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color(self::NAVY));

            $sheet->getStyle("A{$row}:{$lastCol}{$dataEnd}")->getBorders()->getOutline()
                ->setBorderStyle(Border::BORDER_MEDIUM)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color(self::NAVY));

            $row += self::BLOCK_STRIDE;
        }

        $lastRow = $row - 2;
        $sheet->getColumnDimension('A')->setWidth(16);
        if ($includeGross) {
            $sheet->getColumnDimension('B')->setWidth(13);
        }
        foreach (range($offsetStartCol, $lastCol) as $columnLetter) {
            $sheet->getColumnDimension($columnLetter)->setWidth(10);
        }
        $sheet->getStyle("A1:{$lastCol}{$lastRow}")->getFont()->setName('Calibri')->setSize(10);
        $sheet->freezePane("{$offsetStartCol}3");
        $sheet->setSelectedCells('A1');
    }
}
