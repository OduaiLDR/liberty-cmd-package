<?php

namespace Cmd\Reports\Console\Commands\GenerateEnrollmentStatusReport;

use PhpOffice\PhpSpreadsheet\Shared\Date as XlDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class Formatter
{
    public function buildWorkbook(array $rows, string $asOfDate, string $path): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Enrollment Status');
        $sheet->setShowGridlines(false);

        $headers = [
            'LLG ID', 'Client', 'Debt Amount', 'Azure Enrollment Status',
            'Enrollment Plan', 'Submitted Date', 'Snowflake Source',
            'Snowflake Contact ID', 'Status Title', 'Status Stamp PT', 'Snowflake Match',
        ];

        foreach ($headers as $index => $header) {
            $sheet->setCellValueByColumnAndRow($index + 1, 1, $header);
        }
        $this->headerStyle($sheet, 'A1:K1');

        $rowNumber = 2;
        foreach ($rows as $row) {
            $sheet->setCellValue("A{$rowNumber}", $row['LLG_ID']);
            $sheet->setCellValue("B{$rowNumber}", $row['CLIENT']);
            $sheet->setCellValue("C{$rowNumber}", (float) $row['DEBT_AMOUNT']);
            $sheet->setCellValue("D{$rowNumber}", $row['AZURE_STATUS']);
            $sheet->setCellValue("E{$rowNumber}", $row['ENROLLMENT_PLAN']);
            $this->setDate($sheet, "F{$rowNumber}", $row['SUBMITTED_DATE']);
            $sheet->setCellValue("G{$rowNumber}", $row['SNOWFLAKE_SOURCE']);
            $sheet->setCellValue("H{$rowNumber}", $row['SNOWFLAKE_CONTACT_ID']);
            $sheet->setCellValue("I{$rowNumber}", $row['STATUS_TITLE']);
            $this->setDateTime($sheet, "J{$rowNumber}", $row['STATUS_STAMP_PT']);
            $sheet->setCellValue("K{$rowNumber}", $row['SNOWFLAKE_MATCH']);
            $rowNumber++;
        }

        $lastRow = max(2, $rowNumber - 1);
        $sheet->getStyle("C2:C{$lastRow}")->getNumberFormat()->setFormatCode('$#,##0.00');
        $sheet->getStyle("F2:F{$lastRow}")->getNumberFormat()->setFormatCode('mm/dd/yyyy');
        $sheet->getStyle("J2:J{$lastRow}")->getNumberFormat()->setFormatCode('mm/dd/yyyy hh:mm AM/PM');
        $sheet->getStyle("A1:K{$lastRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle("A1:K{$lastRow}")->getFont()->setName('Calibri')->setSize(9);
        foreach (range('A', 'K') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
        $sheet->freezePane('A2');
        $sheet->setSelectedCells('A1');

        $writer = new Xlsx($spreadsheet);
        $writer->save($path);
        return $path;
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

    private function setDate($sheet, string $cell, mixed $value): void
    {
        if ($value === null || $value === '') {
            return;
        }
        $timestamp = strtotime((string) $value);
        if ($timestamp !== false) {
            $sheet->setCellValue($cell, XlDate::PHPToExcel($timestamp));
        }
    }

    private function setDateTime($sheet, string $cell, mixed $value): void
    {
        if ($value === null || $value === '') {
            return;
        }
        $timestamp = strtotime((string) $value);
        if ($timestamp !== false) {
            $sheet->setCellValue($cell, XlDate::PHPToExcel($timestamp));
        }
    }
}
