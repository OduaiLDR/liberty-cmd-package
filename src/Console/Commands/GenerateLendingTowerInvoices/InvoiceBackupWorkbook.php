<?php

namespace Cmd\Reports\Console\Commands\GenerateLendingTowerInvoices;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

/** Client-level invoice backup, shared by PLAW and both LDR worksheets. */
final class InvoiceBackupWorkbook
{
    private const MONEY_FORMAT = '"$"#,##0.00;[Red]("$"#,##0.00)';

    private Spreadsheet $spreadsheet;

    private bool $hasSheet = false;

    public function __construct()
    {
        $this->spreadsheet = new Spreadsheet();
        $this->spreadsheet->getDefaultStyle()->getFont()->setName('Arial')->setSize(10);
    }

    /**
     * @param list<string> $headers
     * @param list<list<mixed>> $rows
     * @param list<int> $moneyColumns Zero-based columns with numeric dollar amounts.
     */
    public function addSheet(string $name, string $title, string $summary, array $headers, array $rows, array $moneyColumns = []): void
    {
        $sheet = $this->hasSheet ? $this->spreadsheet->createSheet() : $this->spreadsheet->getActiveSheet();
        $this->hasSheet = true;
        $sheet->setTitle(mb_substr($name, 0, 31));
        $sheet->setShowGridlines(false);
        $sheet->setPrintGridlines(false);
        $sheet->getDefaultRowDimension()->setRowHeight(21);
        $lastColumn = count($headers);
        $lastLetter = Coordinate::stringFromColumnIndex($lastColumn);

        // Titles span the report instead of forcing the ID column to fit the full sentence.
        $sheet->mergeCells("A1:{$lastLetter}1");
        $sheet->mergeCells("A2:{$lastLetter}2");
        $sheet->setCellValueExplicit('A1', $title, DataType::TYPE_STRING);
        $sheet->setCellValueExplicit('A2', $summary, DataType::TYPE_STRING);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14)->getColor()->setARGB('FF2F383D');
        $sheet->getStyle('A2')->getFont()->getColor()->setARGB('FF53605B');
        $sheet->getStyle("A1:{$lastLetter}2")->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getRowDimension(1)->setRowHeight(40);
        $sheet->getRowDimension(2)->setRowHeight(32);
        $sheet->getRowDimension(3)->setRowHeight(9);

        foreach ($headers as $i => $header) {
            $column = $i + 1;
            $sheet->setCellValueExplicit([$column, 4], $header, DataType::TYPE_STRING);
            $width = match (true) {
                $i === 0 => 19,
                $header === 'Client' => 34,
                $header === 'State' => 9,
                $header === 'Qualifying Status Date' => 26,
                str_contains($header, 'Status') && ! str_contains($header, 'Pacific') => 36,
                str_contains($header, 'Pacific') => 29,
                str_contains($header, 'Cleared') => 24,
                str_contains($header, 'Date') => 19,
                default => 21,
            };
            $sheet->getColumnDimensionByColumn($column)->setAutoSize(false)->setWidth($width);
        }
        $sheet->getRowDimension(4)->setRowHeight(34);
        $sheet->getStyle("A4:{$lastLetter}4")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF2F383D']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFFFFFFF']]],
        ]);

        $row = 4;
        foreach ($rows as $values) {
            $row++;
            foreach (array_values($values) as $i => $value) {
                // IDs and source text stay literal (including leading zeros and '=' prefixes).
                $sheet->setCellValueExplicit([$i + 1, $row], $value,
                    is_int($value) || is_float($value) ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING);
            }
            $sheet->getStyle("A{$row}:{$lastLetter}{$row}")->applyFromArray([
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
                'borders' => ['bottom' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['argb' => 'FFDCE3DF']]],
            ]);
            $sheet->getRowDimension($row)->setRowHeight(-1);
            if ($row % 2 === 0) {
                $sheet->getStyle("A{$row}:{$lastLetter}{$row}")->getFill()
                    ->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF2F6F0');
            }
        }
        $lastDataRow = $row;
        if ($moneyColumns !== [] && $lastDataRow >= 5) {
            $row++;
            $sheet->setCellValue("A{$row}", 'Total');
            $sheet->getStyle("A{$row}:{$lastLetter}{$row}")->applyFromArray([
                'font' => ['bold' => true],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFBCDB90']],
                'borders' => ['top' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FF2F383D']]],
            ]);
            foreach ($moneyColumns as $index) {
                $letter = Coordinate::stringFromColumnIndex($index + 1);
                $sheet->setCellValue("{$letter}{$row}", "=SUM({$letter}5:{$letter}{$lastDataRow})");
                $sheet->getStyle("{$letter}5:{$letter}{$row}")->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);
                $sheet->getStyle("{$letter}5:{$letter}{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            }
        }
        $sheet->getStyle("A4:{$lastLetter}{$row}")->getBorders()->getOutline()
            ->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB('FFD0D8D2');
        $sheet->setAutoFilter("A4:{$lastLetter}{$lastDataRow}");
        $sheet->freezePane('A5');
        $sheet->getPageSetup()->setOrientation('landscape')->setFitToWidth(1)->setFitToHeight(0);
        $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(1, 4);
        $sheet->getPageSetup()->setPrintArea("A1:{$lastLetter}{$row}");
    }

    public function toBytes(): string
    {
        if (! $this->hasSheet) {
            throw new RuntimeException('Backup workbook has no sheets.');
        }
        $this->spreadsheet->setActiveSheetIndex(0);
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
