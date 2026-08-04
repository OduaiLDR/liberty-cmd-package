<?php

namespace Cmd\Reports\Console\Commands\GenerateAdvanceRecoupReport;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class Formatter
{
    /**
     * @param array<string,array<int,array<string,mixed>>> $sheets Advances/Refunds/Recoups, in display order
     * @param array<int,array{category:string,amount:?float,effective_debt:?float,primary_account:?float,operation_account:?float}> $summaryRows
     * @return array{filename:string,path:string}
     */
    public function buildWorkbook(array $sheets, array $summaryRows, string $monthLabel): array
    {
        $spreadsheet = new Spreadsheet();

        $summarySheet = $spreadsheet->getActiveSheet();
        $summarySheet->setTitle('Summary');
        $summarySheet->setShowGridlines(false);
        $this->buildSummarySheet($summarySheet, $summaryRows);

        foreach ($sheets as $title => $rows) {
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle($title);
            $sheet->setShowGridlines(false);
            $this->buildDetailSheet($sheet, $rows);
        }

        $spreadsheet->setActiveSheetIndex(0);
        $summarySheet->setSelectedCells('A1');

        $filename = 'EPF Adjustments - ' . $monthLabel . '.xlsx';
        $path = storage_path('app/' . $filename);

        $writer = new Xlsx($spreadsheet);
        $writer->setPreCalculateFormulas(false);
        $writer->save($path);

        return [
            'filename' => $filename,
            'path' => $path,
        ];
    }

    /**
     * @param array<int,array{category:string,amount:?float,effective_debt:?float,primary_account:?float,operation_account:?float}> $rows
     */
    private function buildSummarySheet(Worksheet $sheet, array $rows): void
    {
        $headers = ['Category', 'Amount', 'Effective Debt', 'Primary Account', 'Operation Account'];

        $col = 'A';
        foreach ($headers as $header) {
            $sheet->setCellValue("{$col}1", $header);
            $col++;
        }

        $sheet->getStyle('A1:E1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF17853B']],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FF444444']]],
        ]);

        $sheet->getColumnDimension('A')->setWidth(20);
        $sheet->getColumnDimension('B')->setWidth(18);
        $sheet->getColumnDimension('C')->setWidth(18);
        $sheet->getColumnDimension('D')->setWidth(18);
        $sheet->getColumnDimension('E')->setWidth(18);

        $rowIndex = 2;
        foreach ($rows as $row) {
            $sheet->setCellValue("A{$rowIndex}", $row['category']);
            $sheet->setCellValue("B{$rowIndex}", $row['amount']);
            $sheet->setCellValue("C{$rowIndex}", $row['effective_debt']);
            $sheet->setCellValue("D{$rowIndex}", $row['primary_account']);
            $sheet->setCellValue("E{$rowIndex}", $row['operation_account']);

            if ($row['category'] === 'Total') {
                $sheet->getStyle("A{$rowIndex}:E{$rowIndex}")->getFont()->setBold(true);
            }

            $rowIndex++;
        }

        $lastRow = $rowIndex - 1;
        if ($lastRow >= 2) {
            $sheet->getStyle("A2:A{$lastRow}")
                ->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_LEFT);
            $sheet->getStyle("B2:E{$lastRow}")
                ->getNumberFormat()
                ->setFormatCode('#,##0.00;(#,##0.00)');
            $sheet->getStyle("A2:E{$lastRow}")
                ->getBorders()
                ->getAllBorders()
                ->setBorderStyle(Border::BORDER_THIN);
        }
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     */
    private function buildDetailSheet(Worksheet $sheet, array $rows): void
    {
        $headers = ['Contact ID', 'Trans Type', 'Amount', 'EPF Rate', 'Prorated Debt', 'Process Date', 'Cleared Date', 'Memo'];

        $col = 'A';
        foreach ($headers as $header) {
            $sheet->setCellValue("{$col}1", $header);
            $col++;
        }

        $sheet->getStyle('A1:H1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF17853B']],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FF444444']]],
        ]);
        $sheet->setAutoFilter('A1:H1');

        $sheet->getColumnDimension('A')->setWidth(16);
        $sheet->getColumnDimension('B')->setWidth(12);
        $sheet->getColumnDimension('C')->setWidth(14);
        $sheet->getColumnDimension('D')->setWidth(12);
        $sheet->getColumnDimension('E')->setWidth(16);
        $sheet->getColumnDimension('F')->setWidth(16);
        $sheet->getColumnDimension('G')->setWidth(16);
        $sheet->getColumnDimension('H')->setWidth(40);

        $rowIndex = 2;
        foreach ($rows as $row) {
            $sheet->setCellValue("A{$rowIndex}", (string) ($row['CONTACT_ID'] ?? ''));
            $sheet->setCellValue("B{$rowIndex}", (string) ($row['TRANS_TYPE'] ?? ''));
            $sheet->setCellValue("C{$rowIndex}", (float) ($row['AMOUNT'] ?? 0));
            $sheet->setCellValue("D{$rowIndex}", (float) ($row['EPF_RATE'] ?? 0));
            $sheet->setCellValue("E{$rowIndex}", (float) ($row['PRORATED_DEBT'] ?? 0));
            $sheet->setCellValue("F{$rowIndex}", $this->formatDate($row['PROCESS_DATE'] ?? null));
            $sheet->setCellValue("G{$rowIndex}", $this->formatDate($row['CLEARED_DATE'] ?? null));
            $sheet->setCellValue("H{$rowIndex}", (string) ($row['MEMO'] ?? ''));

            if ($rowIndex % 2 === 0) {
                $sheet->getStyle("A{$rowIndex}:H{$rowIndex}")
                    ->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()
                    ->setARGB('FFF5F7FA');
            }

            $rowIndex++;
        }

        $lastRow = $rowIndex - 1;
        if ($lastRow >= 2) {
            $sheet->getStyle("A2:H{$lastRow}")
                ->getBorders()
                ->getAllBorders()
                ->setBorderStyle(Border::BORDER_THIN);
            $sheet->getStyle("C2:C{$lastRow}")
                ->getNumberFormat()
                ->setFormatCode('#,##0.00;(#,##0.00)');
            $sheet->getStyle("D2:D{$lastRow}")
                ->getNumberFormat()
                ->setFormatCode('0.00%');
            $sheet->getStyle("E2:E{$lastRow}")
                ->getNumberFormat()
                ->setFormatCode('#,##0.00;(#,##0.00)');
        }

        $sheet->setSelectedCells('A1');
    }

    private function formatDate($value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $string = (string) $value;

        // Snowflake epoch days (integer days since 1970-01-01)
        if (preg_match('/^\d{4,5}$/', $string) && (int) $string > 10000 && (int) $string < 50000) {
            $epochDays = (int) $string;
            $dt = (new \DateTimeImmutable('1970-01-01'))->modify("+{$epochDays} days");
            return $dt->format('m/d/Y');
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $string)) {
            $ts = strtotime($string);
            if ($ts !== false) {
                return date('m/d/Y', $ts);
            }
        }

        $ts = strtotime($string);
        if ($ts === false) {
            return $string;
        }
        return date('m/d/Y', $ts);
    }
}
