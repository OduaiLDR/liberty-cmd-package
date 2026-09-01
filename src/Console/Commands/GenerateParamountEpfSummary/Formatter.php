<?php

namespace Cmd\Reports\Console\Commands\GenerateParamountEpfSummary;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class Formatter
{
    /**
     * @param array<int,array<string,mixed>> $epfRows
     * @param array<int,array{contact_id:string,tier_debt:float}> $clientRows
     * @param array<int,array{tier:int,low:float,high:float,amount:float}> $debtRows
     * @return array{filename:string,path:string}
     */
    public function buildWorkbook(
        array $epfRows,
        array $clientRows,
        array $debtRows,
        float $totalDebt,
        int $tier,
        float $payment,
        string $monthLabel
    ): array {
        $spreadsheet = new Spreadsheet();
        $summary = $spreadsheet->getActiveSheet();
        $summary->setTitle('Summary');
        $this->buildSummarySheet($summary, $clientRows, $totalDebt, $tier, $payment);

        $epfData = $spreadsheet->createSheet();
        $epfData->setTitle('EPF Data');
        $this->buildEpfDataSheet($epfData, $epfRows);

        $debtTable = $spreadsheet->createSheet();
        $debtTable->setTitle('Debt Table');
        $this->buildDebtTableSheet($debtTable, $debtRows, $tier);

        $spreadsheet->setActiveSheetIndex(0);
        $summary->setSelectedCells('A1');

        $filename = 'Paramount Law EPF Summary - ' . $monthLabel . '.xlsx';
        $path = storage_path('app/' . $filename);
        $writer = new Xlsx($spreadsheet);
        $writer->setPreCalculateFormulas(false);
        $writer->save($path);

        return ['filename' => $filename, 'path' => $path];
    }

    /**
     * @param array<int,array{contact_id:string,tier_debt:float}> $clientRows
     */
    private function buildSummarySheet(
        Worksheet $sheet,
        array $clientRows,
        float $totalDebt,
        int $tier,
        float $payment
    ): void {
        $this->writeHeader($sheet, 'A1:B1', ['Contact ID', 'LDR Tier Debt']);
        $row = 2;
        foreach ($clientRows as $client) {
            $sheet->setCellValueExplicit("A{$row}", $client['contact_id'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValue("B{$row}", $client['tier_debt']);
            $row++;
        }

        $this->writeHeader($sheet, 'D1:E1', ['Summary', 'LDR']);
        $summaryRows = [
            ['LDR Tier Debt', $totalDebt],
            ['Tier', $tier],
            ['Payment', $payment],
        ];
        foreach ($summaryRows as $index => [$label, $value]) {
            $summaryRow = $index + 2;
            $sheet->setCellValue("D{$summaryRow}", $label);
            $sheet->setCellValue("E{$summaryRow}", $value);
        }

        $lastClientRow = max(1, $row - 1);
        $sheet->getColumnDimension('A')->setWidth(20);
        $sheet->getColumnDimension('B')->setWidth(18);
        $sheet->getColumnDimension('D')->setWidth(20);
        $sheet->getColumnDimension('E')->setWidth(18);
        $sheet->getStyle("B2:B{$lastClientRow}")->getNumberFormat()->setFormatCode('$#,##0.00;[Red]($#,##0.00)');
        $sheet->getStyle('E2')->getNumberFormat()->setFormatCode('$#,##0.00;[Red]($#,##0.00)');
        $sheet->getStyle('E3')->getNumberFormat()->setFormatCode('0');
        $sheet->getStyle('E4')->getNumberFormat()->setFormatCode('$#,##0.00;[Red]($#,##0.00)');
        $sheet->getStyle("A1:B{$lastClientRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle('D1:E4')->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->freezePane('A2');
        $sheet->setShowGridlines(false);
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function buildEpfDataSheet(Worksheet $sheet, array $rows): void
    {
        $headers = [
            'Contact ID', 'Plan', 'Cleared Date', 'Debt Amount', 'EPF Rate', 'EPF',
            'EPF Fee Allowed', 'EPF Fee Percent Collected', 'EPF Tier Debt', 'Debt ID',
            'Creditor Name', 'Process Date', 'Draft Date',
        ];
        $lastColumn = 'M';
        $this->writeHeader($sheet, "A1:{$lastColumn}1", $headers);

        $row = 2;
        foreach ($rows as $data) {
            $values = [
                (string) ($data['CONTACT_ID'] ?? ''),
                (string) ($data['PLAN'] ?? ''),
                $this->formatDate($data['CLEARED_DATE'] ?? null),
                (float) ($data['DEBT_AMOUNT'] ?? 0),
                (float) ($data['EPF_RATE'] ?? 0),
                (float) ($data['EPF'] ?? 0),
                (float) ($data['EPF_FEE_ALLOWED'] ?? 0),
                (float) ($data['EPF_FEE_PERCENT_COLLECTED'] ?? 0),
                (float) ($data['EPF_TIER_DEBT'] ?? 0),
                (string) ($data['DEBT_ID'] ?? ''),
                (string) ($data['CREDITOR_NAME'] ?? ''),
                $this->formatDate($data['PROCESS_DATE'] ?? null),
                $this->formatDate($data['DRAFT_DATE'] ?? null),
            ];
            foreach ($values as $index => $value) {
                $column = $this->columnLetter($index + 1);
                if (in_array($index, [0, 9], true)) {
                    $sheet->setCellValueExplicit("{$column}{$row}", $value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                } else {
                    $sheet->setCellValue("{$column}{$row}", $value);
                }
            }
            $row++;
        }

        $lastRow = max(1, $row - 1);
        foreach (['A' => 16, 'B' => 30, 'C' => 16, 'D' => 16, 'E' => 12, 'F' => 14, 'G' => 18, 'H' => 24, 'I' => 16, 'J' => 16, 'K' => 30, 'L' => 16, 'M' => 16] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
        $currencyFormat = '$#,##0.00;[Red]($#,##0.00)';
        $sheet->getStyle("D2:D{$lastRow}")->getNumberFormat()->setFormatCode($currencyFormat);
        $sheet->getStyle("E2:E{$lastRow}")->getNumberFormat()->setFormatCode('0.00%');
        $sheet->getStyle("F2:G{$lastRow}")->getNumberFormat()->setFormatCode($currencyFormat);
        $sheet->getStyle("H2:H{$lastRow}")->getNumberFormat()->setFormatCode('0.00%');
        $sheet->getStyle("I2:I{$lastRow}")->getNumberFormat()->setFormatCode($currencyFormat);
        $sheet->getStyle("A1:M{$lastRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->freezePane('A2');
        $sheet->setShowGridlines(false);
    }

    /** @param array<int,array{tier:int,low:float,high:float,amount:float}> $rows */
    private function buildDebtTableSheet(Worksheet $sheet, array $rows, int $currentTier): void
    {
        $this->writeHeader($sheet, 'A1:D1', ['Tier', 'Low Tier', 'High Teir', 'Amount']);
        foreach ($rows as $index => $data) {
            $row = $index + 2;
            $sheet->fromArray([[$data['tier'], $data['low'], $data['high'], $data['amount']]], null, "A{$row}");
            if ($data['tier'] === $currentTier) {
                $sheet->getStyle("A{$row}:D{$row}")->applyFromArray([
                    'font' => ['bold' => true],
                    'fill' => [
                        'fillType' => Fill::FILL_SOLID,
                        'startColor' => ['argb' => 'FFFFF3CD'],
                    ],
                ]);
            }
        }
        $lastRow = count($rows) + 1;
        foreach (['A' => 10, 'B' => 18, 'C' => 18, 'D' => 16] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
        $sheet->getStyle("B2:D{$lastRow}")->getNumberFormat()->setFormatCode('$#,##0.00;[Red]($#,##0.00)');
        $sheet->getStyle("A1:D{$lastRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->freezePane('A2');
        $sheet->setShowGridlines(false);
    }

    /** @param array<int,string> $headers */
    private function writeHeader(Worksheet $sheet, string $range, array $headers): void
    {
        $start = explode(':', $range)[0];
        preg_match('/([A-Z]+)\d+/', $start, $match);
        $column = $match[1];
        foreach ($headers as $header) {
            $sheet->setCellValue("{$column}1", $header);
            $column = $this->columnLetter(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($column) + 1);
        }
        $sheet->getStyle($range)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF17853B']],
        ]);
    }

    private function columnLetter(int $index): string
    {
        return \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index);
    }

    private function formatDate($value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        $string = (string) $value;
        if (preg_match('/^\d{4,5}$/', $string) && (int) $string > 10000 && (int) $string < 50000) {
            return (new \DateTimeImmutable('1970-01-01'))->modify('+' . (int) $string . ' days')->format('m/d/Y');
        }
        $timestamp = strtotime($string);
        return $timestamp === false ? $string : date('m/d/Y', $timestamp);
    }
}
