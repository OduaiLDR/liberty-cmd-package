<?php

namespace Cmd\Reports\Console\Commands\GenerateEnrollmentSummaryReport;

use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class Formatter
{
    private const TRANCHE_SUMMARY_COLUMNS = [
        'Tranche', 'Sold Date', 'Tranch Date', 'Enrolled Debt', 'LDR Contacts', 'PLAW Clients',
        'ProLaw Clients', 'Total Contacts', 'Paid to LDR', 'Lookback Debt', 'Lookback Repayment',
        'EPF Projection Original', 'EPF Projection Current', 'Preferred Return', 'EPF Paid From DPP',
        'EPF Paid From LDR', 'EPF Paid Total', 'EPF Paid Preferred Return', 'Preferred Return Balance',
        'EPF Paid Standard Return', 'Percent of Preferred Return Achieved', 'Flip Date',
    ];

    /** Accounting-style 2-decimal format, matching the reference workbook's Tranche Summary tab exactly. */
    private const ACCOUNTING_2DP = '_("$"* #,##0.00_);_("$"* \(#,##0.00\);_("$"* "-"??_);_(@_)';

    /** Per-column number format codes copied directly from the reference Tranche Summary cells. */
    private const TRANCHE_SUMMARY_FORMATS = [
        'Enrolled Debt' => self::ACCOUNTING_2DP,
        'LDR Contacts' => '"$"#,##0',
        'PLAW Clients' => self::ACCOUNTING_2DP,
        'ProLaw Clients' => self::ACCOUNTING_2DP,
        'Total Contacts' => self::ACCOUNTING_2DP,
        'Paid to LDR' => self::ACCOUNTING_2DP,
        'Lookback Debt' => self::ACCOUNTING_2DP,
        'Lookback Repayment' => self::ACCOUNTING_2DP,
        'EPF Projection Original' => self::ACCOUNTING_2DP,
        'EPF Projection Current' => self::ACCOUNTING_2DP,
        'Preferred Return' => self::ACCOUNTING_2DP,
        'EPF Paid From DPP' => self::ACCOUNTING_2DP,
        'EPF Paid From LDR' => self::ACCOUNTING_2DP,
        'EPF Paid Total' => self::ACCOUNTING_2DP,
        'EPF Paid Preferred Return' => '"$"#,##0.00',
        'Preferred Return Balance' => '"$"#,##0.00',
        'EPF Paid Standard Return' => '"$"#,##0.00',
        'Percent of Preferred Return Achieved' => '0.00%',
        'Sold Date' => 'm/d/yyyy',
        'Tranch Date' => 'm/d/yyyy',
        'Flip Date' => 'm/d/yyyy',
    ];

    /** key => display header (source has literal duplicate labels on AC/AD; keys disambiguate them) */
    private const CAPITAL_REPORT_COLUMNS = [
        'Tranche' => 'Tranche',
        'Sold Date' => 'Sold Date',
        'Tranche Date' => 'Tranche Date',
        'Sold Debt Amount' => 'Sold Debt Amount',
        'LDR EPF Original' => 'LDR EPF Original',
        'PLAW EPF Original' => 'PLAW EPF Original',
        'Pro Law EPF Original' => 'Pro Law EPF Original',
        'LDR EPF Current' => 'LDR EPF Current',
        'PLAW EPF Current' => 'PLAW EPF Current',
        'Pro Law EPF Current' => 'Pro Law EPF Current',
        'NGF Payments' => 'NGF Payments',
        'Preferred Return' => 'Preferred Return',
        'LDR EPF Paid' => 'LDR EPF Paid',
        'PLAW EPF Paid' => 'PLAW EPF Paid',
        'Pro Law EPF Paid' => 'Pro Law EPF Paid',
        'LDR EPF Remaining' => 'LDR EPF Remaining',
        'PLAW EPF Remaining' => 'PLAW EPF Remaining',
        'Pro Law EPF Remaining' => 'Pro Law EPF Remaining',
        'Total EPF Original' => 'Total EPF Original',
        'Total EPF Current' => 'Total EPF Current',
        'Total EPF Paid' => 'Total EPF Paid',
        'Total EPF Remaining' => 'Total EPF Remaining',
        'LDR EPF Earned (15%)' => 'LDR EPF Earned (15%)',
        'EPF Paid To NGF' => 'EPF Paid To NGF',
        'Preferred Return Paid' => 'Preferred Return Paid',
        'Preferred Return Remaining' => 'Preferred Return Remaining',
        'EPF Needed to Cap Preferred Return' => 'EPF Needed to Cap Preferred Return',
        'EPF LDR (15%)' => 'EPF LDR (15%)',
        'EPF LDR (85%)' => 'EPF LDR (85%)',
        'PLAW EPF Paid 5%' => 'PLAW EPF Paid',
        'Pro Law EPF Paid 3%' => 'Pro Law EPF Paid',
        'PLAW EPF Projection' => 'PLAW EPF Projection',
        'Pro Law EPF Projection' => 'Pro Law EPF Projection',
        'LDR Net' => 'LDR Net',
        'Collection Rate' => 'Collection Rate',
        'NGF Total' => 'NGF Total',
        'Annualized Return Initial' => 'Annualized Return Initial',
        'Lookback' => 'Lookback',
        'Annualized Return with Lookback' => 'Annualized Return with Lookback',
    ];

    private const CAPITAL_REPORT_PERCENT = ['Collection Rate', 'Annualized Return Initial', 'Annualized Return with Lookback'];
    private const CAPITAL_REPORT_DATE = ['Sold Date'];
    private const CAPITAL_REPORT_MONTH_YEAR = ['Tranche Date'];
    private const CAPITAL_REPORT_TEXT = ['Tranche'];

    /**
     * Builds the combined workbook: Enrollment Summary, Tranche Summary, Capital Report.
     *
     * @param array<int, array<string, mixed>> $enrollmentRows Ordered row definitions from the command.
     * @param string[] $columnKeys e.g. ['Total', 'LDR', 'Legal']
     * @param array<int, array<string, mixed>>|null $trancheRows
     * @param array{rows: array<int, array<string, mixed>>, totals: array<string, mixed>}|null $capitalReport
     * @param array<int, array{label: string, contacts: int, fee: ?float, residual: float, projection: float, bold: bool}>|null $monthlyResiduals
     */
    public function buildWorkbook(
        array $enrollmentRows,
        array $columnKeys,
        string $reportDate,
        ?array $trancheRows = null,
        ?array $capitalReport = null,
        ?array $monthlyResiduals = null
    ): ?array {
        if (empty($enrollmentRows)) {
            return null;
        }

        $filename = 'Enrollment Summary Report - ' . date('m-d-Y', strtotime($reportDate)) . '.xlsx';
        $path = storage_path('app/' . $filename);

        $spreadsheet = new Spreadsheet();
        $spreadsheet->removeSheetByIndex(0);

        $this->buildEnrollmentSummarySheet($spreadsheet, $enrollmentRows, $columnKeys, $reportDate);

        if ($trancheRows !== null) {
            $this->buildTrancheSummarySheet($spreadsheet, $trancheRows);
        }

        if ($capitalReport !== null) {
            $this->buildCapitalReportSheet($spreadsheet, $capitalReport['rows'], $capitalReport['totals'], $monthlyResiduals);
        }

        $spreadsheet->setActiveSheetIndex(0);

        $writer = new Xlsx($spreadsheet);
        $writer->save($path);

        return [
            'filename' => $filename,
            'path' => $path,
        ];
    }

    private function buildEnrollmentSummarySheet(Spreadsheet $spreadsheet, array $rows, array $columnKeys, string $reportDate): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Enrollment Summary');
        $sheet->setShowGridlines(false);

        $sheet->getColumnDimension('A')->setWidth(60);
        foreach (range('B', chr(ord('B') + count($columnKeys) - 1)) as $col) {
            $sheet->getColumnDimension($col)->setWidth(15);
        }

        $sheet->setCellValue('A1', 'Enrollment Summary For ' . date('n/j/Y', strtotime($reportDate)));
        $sheet->getStyle('A1')->getFont()->setBold(true);

        $lastColLetter = chr(ord('A') + count($columnKeys));

        $sheet->setCellValue('A3', 'Category');
        $col = 'B';
        foreach ($columnKeys as $columnKey) {
            $sheet->setCellValue("{$col}3", $columnKey);
            $col++;
        }
        $sheet->getStyle("A3:{$lastColLetter}3")->applyFromArray([
            'font' => ['bold' => true],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);

        $rowIndex = 4;
        foreach ($rows as $row) {
            if ($row['blank']) {
                $sheet->getStyle("A{$rowIndex}:{$lastColLetter}{$rowIndex}")->applyFromArray([
                    'fill' => [
                        'fillType' => Fill::FILL_SOLID,
                        'startColor' => ['rgb' => 'C5C5C5'],
                    ],
                ]);
                $rowIndex++;
                continue;
            }

            $sheet->setCellValue("A{$rowIndex}", $row['label']);

            $col = 'B';
            foreach ($columnKeys as $columnKey) {
                $value = $row['values'][$columnKey] ?? 0;
                $sheet->setCellValue("{$col}{$rowIndex}", $value);
                if ($row['format'] === 'currency') {
                    $sheet->getStyle("{$col}{$rowIndex}")->getNumberFormat()->setFormatCode('$#,##0');
                } else {
                    $sheet->getStyle("{$col}{$rowIndex}")->getNumberFormat()->setFormatCode('#,##0');
                }
                $col++;
            }

            if ($row['bold']) {
                $sheet->getStyle("A{$rowIndex}:{$lastColLetter}{$rowIndex}")->getFont()->setBold(true);
            }

            $rowIndex++;
        }

        $lastRow = $rowIndex - 1;

        $sheet->getStyle("A3:{$lastColLetter}{$lastRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle("A1:{$lastColLetter}{$lastRow}")->getFont()->setName('Calibri')->setSize(9);
        $sheet->setSelectedCells('B1');
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function buildTrancheSummarySheet(Spreadsheet $spreadsheet, array $rows): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Tranche Summary');
        $sheet->setShowGridlines(false);

        $headers = self::TRANCHE_SUMMARY_COLUMNS;
        $lastColLetter = $this->columnLetter(count($headers));

        $col = 'A';
        foreach ($headers as $header) {
            $sheet->setCellValue("{$col}1", $header);
            $col++;
        }
        $this->styleHeaderRow($sheet, "A1:{$lastColLetter}1");

        $rowIndex = 2;
        foreach ($rows as $row) {
            $col = 'A';
            foreach ($headers as $header) {
                $value = $this->cellValue($row[$header] ?? null);
                $sheet->setCellValue("{$col}{$rowIndex}", $value);

                if (isset(self::TRANCHE_SUMMARY_FORMATS[$header])) {
                    $sheet->getStyle("{$col}{$rowIndex}")->getNumberFormat()->setFormatCode(self::TRANCHE_SUMMARY_FORMATS[$header]);
                }

                $col++;
            }
            $rowIndex++;
        }

        $this->finishSheet($sheet, $lastColLetter, $rowIndex - 1);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, mixed> $totals
     */
    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, mixed> $totals
     * @param array<int, array{label: string, contacts: int, fee: ?float, residual: float, projection: float, bold: bool}>|null $monthlyResiduals
     */
    private function buildCapitalReportSheet(Spreadsheet $spreadsheet, array $rows, array $totals, ?array $monthlyResiduals = null): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Capital Report');
        $sheet->setShowGridlines(false);

        $keys = array_keys(self::CAPITAL_REPORT_COLUMNS);
        $lastColLetter = $this->columnLetter(count($keys));

        $col = 'A';
        foreach (self::CAPITAL_REPORT_COLUMNS as $header) {
            $sheet->setCellValue("{$col}1", $header);
            $col++;
        }
        $this->styleHeaderRow($sheet, "A1:{$lastColLetter}1");

        // Second-to-last column: VBA hardcodes the Unsold Tranche merge to ":AL" even though the sheet
        // now extends one column further (to AM), so it deliberately stops one column short.
        $mergeEndColLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(
            \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($lastColLetter) - 1
        );

        $rowIndex = 2;
        foreach ($rows as $row) {
            if ($row['Tranche'] === 'Unsold Tranche') {
                // VBA only ever sets the label + merges the row — D onward are never written, so this
                // row should render as one blank merged cell, not a row full of zeros.
                $sheet->setCellValue("A{$rowIndex}", 'Unsold Tranche');
                $sheet->getStyle("A{$rowIndex}")->getFont()->setBold(true);
                $sheet->mergeCells("A{$rowIndex}:{$mergeEndColLetter}{$rowIndex}");
            } else {
                $this->writeCapitalReportRow($sheet, $keys, $row, $rowIndex);
            }
            $rowIndex++;
        }

        $totalsRow = $rowIndex;
        $this->writeCapitalReportRow($sheet, $keys, $totals, $totalsRow);
        $sheet->getStyle("A{$totalsRow}:{$lastColLetter}{$totalsRow}")->getFont()->setBold(true);
        $sheet->mergeCells("A{$totalsRow}:C{$totalsRow}");

        $this->finishSheet($sheet, $lastColLetter, $totalsRow);

        if ($monthlyResiduals !== null) {
            $this->buildMonthlyResidualsSection($sheet, $monthlyResiduals, $totalsRow + 4);
        }
    }

    /**
     * "Monthly Residuals" footer: a standalone 6-column (A-F) table below the main Capital Report grid,
     * not spanning the full width. Mirrors the tail of VBA Sub GenerateCapitalReport.
     *
     * @param array<int, array{label: string, contacts: int, fee: ?float, residual: float, projection: float, bold: bool}> $rows
     */
    private function buildMonthlyResidualsSection($sheet, array $rows, int $startRow): void
    {
        $sheet->setCellValue("A{$startRow}", 'Monthly Residuals');
        $sheet->mergeCells("A{$startRow}:F{$startRow}");

        $headerRow = $startRow + 1;
        $sheet->setCellValue("A{$headerRow}", 'Legal Protection');
        $sheet->mergeCells("A{$headerRow}:B{$headerRow}");
        $sheet->setCellValue("C{$headerRow}", 'Contacts');
        $sheet->setCellValue("D{$headerRow}", 'Fee');
        $sheet->setCellValue("E{$headerRow}", 'Residual');
        $sheet->setCellValue("F{$headerRow}", 'Projection');

        $sheet->getStyle("A{$startRow}:F{$headerRow}")->applyFromArray([
            'font' => ['bold' => true],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);

        $rowIndex = $headerRow + 1;
        foreach ($rows as $row) {
            $sheet->setCellValue("A{$rowIndex}", $row['label']);
            $sheet->mergeCells("A{$rowIndex}:B{$rowIndex}");
            $sheet->setCellValue("C{$rowIndex}", $row['contacts']);
            $sheet->getStyle("C{$rowIndex}")->getNumberFormat()->setFormatCode('#,##0');
            if ($row['fee'] !== null) {
                $sheet->setCellValue("D{$rowIndex}", $row['fee']);
                $sheet->getStyle("D{$rowIndex}")->getNumberFormat()->setFormatCode('$#,##0.00');
            }
            $sheet->setCellValue("E{$rowIndex}", $row['residual']);
            $sheet->setCellValue("F{$rowIndex}", $row['projection']);
            $sheet->getStyle("E{$rowIndex}:F{$rowIndex}")->getNumberFormat()->setFormatCode('$#,##0');

            if ($row['bold']) {
                $sheet->getStyle("A{$rowIndex}:F{$rowIndex}")->getFont()->setBold(true);
            }

            $rowIndex++;
        }

        $lastRow = $rowIndex - 1;
        $sheet->getStyle("A{$startRow}:F{$lastRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle("A{$startRow}:F{$lastRow}")->getFont()->setName('Calibri')->setSize(9);
        foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $columnLetter) {
            $sheet->getColumnDimension($columnLetter)->setAutoSize(true);
        }
    }

    private function writeCapitalReportRow($sheet, array $keys, array $row, int $rowIndex): void
    {
        $col = 'A';
        foreach ($keys as $key) {
            $value = $this->cellValue($row[$key] ?? null);
            $sheet->setCellValue("{$col}{$rowIndex}", $value);

            if (in_array($key, self::CAPITAL_REPORT_TEXT, true)) {
                // leave as-is
            } elseif (in_array($key, self::CAPITAL_REPORT_DATE, true)) {
                $sheet->getStyle("{$col}{$rowIndex}")->getNumberFormat()->setFormatCode('mm/dd/yyyy');
            } elseif (in_array($key, self::CAPITAL_REPORT_MONTH_YEAR, true)) {
                $sheet->getStyle("{$col}{$rowIndex}")->getNumberFormat()->setFormatCode('mmmm yyyy');
            } elseif (in_array($key, self::CAPITAL_REPORT_PERCENT, true)) {
                $sheet->getStyle("{$col}{$rowIndex}")->getNumberFormat()->setFormatCode('0.00%');
            } else {
                $sheet->getStyle("{$col}{$rowIndex}")->getNumberFormat()->setFormatCode('$#,##0');
            }

            $col++;
        }
    }

    /**
     * Converts DateTimeImmutable row values into Excel date serials so date/month-year number formats
     * actually apply (PhpSpreadsheet ignores number formats on plain string cell values).
     */
    private function cellValue(mixed $value): mixed
    {
        if ($value instanceof \DateTimeImmutable) {
            return ExcelDate::dateTimeToExcel($value);
        }

        return $value;
    }

    private function styleHeaderRow($sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FF000000']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF8EA9DB']],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        ]);
        // Reference workbook uses a 36pt header row to fit wrapped 2-line headers without clipping.
        $sheet->getRowDimension(1)->setRowHeight(36);
        $sheet->freezePane('A2');
    }

    private function finishSheet($sheet, string $lastColLetter, int $lastRow): void
    {
        $sheet->getStyle("A1:{$lastColLetter}{$lastRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $lastColIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($lastColLetter);
        for ($i = 1; $i <= $lastColIndex; $i++) {
            $columnLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i);
            $sheet->getColumnDimension($columnLetter)->setAutoSize(true);
            // FormatReport enforces a 12-char minimum column width.
            if ($sheet->getColumnDimension($columnLetter)->getWidth() < 12) {
                $sheet->getColumnDimension($columnLetter)->setWidth(12);
            }
        }

        // FormatReport zebra-stripes odd data rows gray (RGB 190,190,190) — header is row 1, so the
        // first data row (2) is unstriped, the second (3) is striped, and so on.
        for ($row = 3; $row <= $lastRow; $row += 2) {
            $sheet->getStyle("A{$row}:{$lastColLetter}{$row}")->applyFromArray([
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'BEBEBE']],
            ]);
        }

        $sheet->getStyle("A1:{$lastColLetter}{$lastRow}")->getFont()->setName('Calibri')->setSize(9);
        $sheet->setSelectedCells('A2');
    }

    private function columnLetter(int $columnCount): string
    {
        $columnCount = max($columnCount, 1);
        $letter = '';
        while ($columnCount > 0) {
            $columnCount--;
            $letter = chr(65 + ($columnCount % 26)) . $letter;
            $columnCount = intdiv($columnCount, 26);
        }

        return $letter;
    }
}
