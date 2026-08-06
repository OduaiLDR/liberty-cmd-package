<?php

namespace Cmd\Reports\Console\Commands\GenerateEnrollmentBonusReport;

use PhpOffice\PhpSpreadsheet\Shared\Date as XlDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class Formatter
{
    public function buildWorkbook(array $rows, array $pending, array $summary, array $statusHistory, string $path): string
    {
        $spreadsheet = new Spreadsheet();
        $this->buildSummarySheet($spreadsheet->getActiveSheet(), $summary);
        $this->buildEnrollmentSheet($spreadsheet->createSheet(), $rows, $pending);
        $this->buildStatusSheet($spreadsheet->createSheet(), $statusHistory);
        $spreadsheet->setActiveSheetIndex(0);
        (new Xlsx($spreadsheet))->save($path);
        return $path;
    }

    private function buildSummarySheet($sheet, array $summary): void
    {
        $sheet->setTitle('Summary');
        $sheet->setShowGridlines(false);
        $columns = ['LDR', 'Progress Law', 'Combined'];
        $headers = ['Enrollment Status'];
        foreach ($columns as $column) $headers[] = $column;
        $rows = [];
        foreach (array_keys($summary['Combined'] ?? []) as $category) {
            $row = [$category];
            foreach ($columns as $column) {
                $row[] = (float) ($summary[$column][$category] ?? 0);
            }
            $rows[] = $row;
        }
        $this->writeSheet($sheet, $headers, $rows);
        foreach ($columns as $index => $column) {
            $this->formatCurrency($sheet, 2 + $index, count($rows) + 1, '$#,##0');
        }
        $this->selectTopLeft($sheet);
    }

    private function buildEnrollmentSheet($sheet, array $rows, array $pending): void
    {
        $sheet->setTitle('Enrollment Data');
        $data = [];
        foreach ($rows as $row) {
            $cutoffStatus = $row['ASOF_TITLE'] !== '' ? $row['ASOF_TITLE'] : $row['STATUS_TITLE'];
            $data[] = [
                $row['SNOWFLAKE_CONTACT_ID'],
                $row['CLIENT'],
                $row['SUBMITTED_DATE'],
                $row['DEBT_AMOUNT'],
                $row['AZURE_STATUS'],
                $cutoffStatus,
                $this->relevantStatus($cutoffStatus),
            ];
        }
        foreach ($pending as $row) {
            $data[] = [
                $row['CONTACT_ID'],
                $row['CLIENT'],
                '',
                $row['ENROLLED_DEBT'],
                'Pending',
                $row['STATUS_TITLE'],
                'Pending',
            ];
        }
        $this->writeSheet($sheet, ['CID', 'Client', 'Submitted Date', 'Debt Amount', 'Current Status', 'Cut Off Status', 'Relevant Status'], $data);
        $this->formatDate($sheet, 3, count($data) + 1, 'mm/dd/yyyy');
        $this->formatCurrency($sheet, 4, count($data) + 1);
        $this->selectTopLeft($sheet);
    }

    private function buildStatusSheet($sheet, array $statusHistory): void
    {
        $sheet->setTitle('Status Data');
        $data = [];
        foreach ($statusHistory as $row) {
            $data[] = [$row['CONTACT_ID'], $row['STATUS_STAMP_PT'], $row['STATUS_TITLE']];
        }
        $this->writeSheet($sheet, ['CID', 'Date', 'Enrollment Status'], $data);
        $this->formatDate($sheet, 2, count($data) + 1, 'mm/dd/yyyy hh:mm AM/PM');
        $this->selectTopLeft($sheet);
    }

    private function writeSheet($sheet, array $headers, array $rows): void
    {
        foreach ($headers as $index => $header) {
            $sheet->setCellValueByColumnAndRow($index + 1, 1, $header);
        }
        $this->headerStyle($sheet, 'A1:' . $this->columnName(count($headers)) . '1');
        foreach ($rows as $rowIndex => $row) {
            foreach ($row as $columnIndex => $value) {
                $sheet->setCellValueByColumnAndRow($columnIndex + 1, $rowIndex + 2, $value);
            }
        }
        $lastColumn = $this->columnName(count($headers));
        $lastRow = max(2, count($rows) + 1);
        $sheet->getStyle("A1:{$lastColumn}{$lastRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle("A1:{$lastColumn}{$lastRow}")->getFont()->setName('Calibri')->setSize(9);
        foreach (range('A', $lastColumn) as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
        $sheet->freezePane('A2');
    }

    private function selectTopLeft($sheet): void
    {
        $sheet->setSelectedCells('A1');
    }

    private function formatCurrency($sheet, int $column, int $lastRow, string $format = '$#,##0.00'): void
    {
        $letter = $this->columnName($column);
        $sheet->getStyle("{$letter}2:{$letter}{$lastRow}")->getNumberFormat()->setFormatCode($format);
    }

    private function formatDate($sheet, int $column, int $lastRow, string $format): void
    {
        $letter = $this->columnName($column);
        for ($row = 2; $row <= $lastRow; $row++) {
            $value = $sheet->getCell("{$letter}{$row}")->getValue();
            if ($value === null || $value === '') {
                continue;
            }
            $timestamp = $this->timestampFromValue($value);
            if ($timestamp !== null) {
                $sheet->setCellValue("{$letter}{$row}", XlDate::PHPToExcel($timestamp));
            }
        }
        $sheet->getStyle("{$letter}2:{$letter}{$lastRow}")->getNumberFormat()->setFormatCode($format);
    }

    private function timestampFromValue(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            $numeric = (float) $value;
            if ($numeric > 1000000000 && $numeric < 2000000000) {
                return (int) floor($numeric);
            }
            if ($numeric > 20000 && $numeric < 80000) {
                return (int) XlDate::excelToTimestamp($numeric);
            }
        }
        $timestamp = strtotime((string) $value);

        return $timestamp !== false ? $timestamp : null;
    }

    private function relevantStatus(string $status): string
    {
        $normalized = strtoupper($status);
        if (str_contains($normalized, 'LDR ENROLLED')) {
            return 'LDR Enrolled';
        }
        if (str_contains($normalized, 'PROLAW ENROLLED') || str_contains($normalized, 'PLAW ENROLLED')) {
            return 'Progress Law Enrolled';
        }
        if (str_contains($normalized, 'CANCEL') || str_contains($normalized, 'DROPPED')) {
            return 'Cancelled';
        }
        if (str_contains($normalized, 'RECONSIDERATION PENDING')) {
            return 'Reconsideration Pending';
        }

        return 'At-Risk';
    }

    private function headerStyle($sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF17853B']],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        ]);
    }

    private function columnName(int $number): string
    {
        $name = '';
        while ($number > 0) {
            $name = chr(65 + (($number - 1) % 26)) . $name;
            $number = intdiv($number - 1, 26);
        }
        return $name;
    }
}
