<?php

namespace Cmd\Reports\Console\Commands\GenerateLendingTowerInvoices;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

/**
 * The client-level backup that travels with an invoice, so the recipient can reconcile the total
 * line by line.
 *
 * Each sheet is a title, a one-line summary quoting the invoice figures, a header row and the
 * rows. Money columns get a SUM row whose result must equal the debt basis on the invoice; the
 * summary line states that figure so a mismatch is visible to whoever opens the file.
 */
final class InvoiceBackupWorkbook
{
    private const MONEY_FORMAT = '"$"#,##0.00';

    private Spreadsheet $spreadsheet;

    private bool $hasSheet = false;

    public function __construct()
    {
        $this->spreadsheet = new Spreadsheet();
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<mixed>>  $rows
     * @param  list<int>  $moneyColumns  zero-based indexes of columns holding dollar amounts
     */
    public function addSheet(string $name, string $title, string $summary, array $headers, array $rows, array $moneyColumns = []): void
    {
        $sheet = $this->hasSheet ? $this->spreadsheet->createSheet() : $this->spreadsheet->getActiveSheet();
        $this->hasSheet = true;
        $sheet->setTitle(mb_substr($name, 0, 31));

        $sheet->setCellValue('A1', $title);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);
        $sheet->setCellValue('A2', $summary);

        $headerRow = 4;
        $lastColumn = count($headers);

        foreach ($headers as $i => $header) {
            $sheet->setCellValue([$i + 1, $headerRow], $header);
        }

        $headerRange = [1, $headerRow, $lastColumn, $headerRow];
        $sheet->getStyle($headerRange)->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle($headerRange)->getFill()->setFillType('solid')->getStartColor()->setARGB('FF2F383D');

        $row = $headerRow;
        foreach ($rows as $values) {
            $row++;
            foreach (array_values($values) as $i => $value) {
                $sheet->setCellValue([$i + 1, $row], $value);
            }
        }

        $firstDataRow = $headerRow + 1;
        $lastDataRow = $row;

        if ($moneyColumns !== [] && $lastDataRow >= $firstDataRow) {
            $totalRow = $lastDataRow + 1;
            $sheet->setCellValue([1, $totalRow], 'Total');
            $sheet->getStyle([1, $totalRow, $lastColumn, $totalRow])->getFont()->setBold(true);

            foreach ($moneyColumns as $index) {
                $column = $index + 1;
                $letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($column);
                $sheet->setCellValue([$column, $totalRow], "=SUM({$letter}{$firstDataRow}:{$letter}{$lastDataRow})");
                $sheet->getStyle([$column, $firstDataRow, $column, $totalRow])
                    ->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);
            }
        }

        foreach (range(1, $lastColumn) as $column) {
            $sheet->getColumnDimensionByColumn($column)->setAutoSize(true);
        }

        $sheet->freezePane([1, $firstDataRow]);
    }

    /** The finished workbook as .xlsx bytes. */
    public function toBytes(): string
    {
        if (! $this->hasSheet) {
            throw new RuntimeException('Backup workbook has no sheets.');
        }

        $this->spreadsheet->setActiveSheetIndex(0);

        // The xlsx writer needs a real path to build its zip in.
        $path = tempnam(sys_get_temp_dir(), 'lt-invoice-');
        if ($path === false) {
            throw new RuntimeException('Could not create a temporary file for the backup workbook.');
        }

        try {
            (new Xlsx($this->spreadsheet))->save($path);
            $bytes = file_get_contents($path);
        } finally {
            @unlink($path);
        }

        if (! is_string($bytes) || $bytes === '') {
            throw new RuntimeException('Backup workbook came out empty.');
        }

        return $bytes;
    }
}
