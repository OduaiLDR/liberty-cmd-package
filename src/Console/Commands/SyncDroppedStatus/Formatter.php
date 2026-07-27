<?php

declare(strict_types=1);

namespace Cmd\Reports\Console\Commands\SyncDroppedStatus;

use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

final class Formatter
{
    private const REPORT_TIMEZONE = 'America/Los_Angeles';

    /**
     * @param  array<string,mixed>  $report
     * @return array{filename:string,path:string}
     */
    public function buildWorkbook(array $report, string $source, ?string $outputDir = null): array
    {
        $source = strtoupper(trim($source));
        if (! in_array($source, ['LDR', 'PLAW'], true)) {
            throw new \InvalidArgumentException('Invalid source: '.$source);
        }

        $now = Carbon::now(self::REPORT_TIMEZONE);
        $spreadsheet = new Spreadsheet;

        $summary = $spreadsheet->getActiveSheet();
        $summary->setTitle('Summary');
        $this->fillSummary($summary, $report);

        $candidates = $spreadsheet->createSheet();
        $candidates->setTitle('Candidates');
        $this->fillCandidates($candidates, $report['candidates'] ?? []);

        $skipped = $spreadsheet->createSheet();
        $skipped->setTitle('Skipped');
        $this->fillSkipped($skipped, $report['skipped'] ?? []);

        $spreadsheet->setActiveSheetIndex(0);

        $filename = 'Sync Dropped Status - '.$source.' - '.$now->format('m-d-Y').'.xlsx';
        if ($outputDir !== null && $outputDir !== '') {
            if (! is_dir($outputDir)) {
                throw new \InvalidArgumentException("Output directory does not exist: {$outputDir}");
            }

            $path = rtrim(str_replace('\\', '/', $outputDir), '/').'/'.$filename;
        } else {
            $path = storage_path(
                'app/sync-dropped-status-'.strtolower($source).'-'.$now->format('Ymd-His').'-'.bin2hex(random_bytes(4)).'.xlsx'
            );
        }

        try {
            $writer = new Xlsx($spreadsheet);
            $writer->setPreCalculateFormulas(false);
            $writer->save($path);
        } finally {
            $spreadsheet->disconnectWorksheets();
        }

        return ['filename' => $filename, 'path' => $path];
    }

    /** @param array<string,mixed> $report */
    private function fillSummary(Worksheet $sheet, array $report): void
    {
        $sheet->setShowGridlines(false);
        $sheet->setCellValue('A1', 'Sync Dropped Status Preview');
        $sheet->setCellValue('A2', 'No CRM updates were sent');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A2')->getFont()->setBold(true)->getColor()->setARGB('FFB00020');

        $rows = [
            ['Metric', 'Value'],
            ['Total scanned', (int) ($report['scanned_count'] ?? 0)],
            ['Update candidates', (int) ($report['candidate_count'] ?? 0)],
            ['Selected by limit', (int) ($report['selected_count'] ?? 0)],
            ['Skipped: already Dropped', (int) ($report['skipped_dropped_count'] ?? 0)],
            ['Skipped: System Cancel', (int) ($report['skipped_system_cancel_count'] ?? 0)],
            ['Skipped: missing status', (int) ($report['skipped_missing_status_count'] ?? 0)],
            ['Skipped: invalid ID', (int) ($report['skipped_invalid_id_count'] ?? 0)],
            ['Duplicate IDs removed', (int) ($report['duplicate_count'] ?? 0)],
            ['Limit', $report['limit'] ?? 'none'],
            ['Target status', 'Dropped / Cancelled'],
        ];

        // Strict null comparison preserves legitimate zero counts instead of
        // rendering them as blank summary cells.
        $sheet->fromArray($rows, null, 'A4', true);
        $this->styleHeader($sheet, 'A4:B4');
        $this->finishSheet($sheet, 'A4:B'.(3 + count($rows)), ['A' => 34, 'B' => 20]);
        $sheet->freezePane('A5');
    }

    /** @param list<array<string,mixed>> $rows */
    private function fillCandidates(Worksheet $sheet, array $rows): void
    {
        $headers = ['Contact ID', 'Client', 'Enrolled Date', 'Dropped Date', 'Current Status', 'Target Status', 'Action'];
        $sheet->setShowGridlines(false);
        $sheet->fromArray($headers, null, 'A1');
        $this->styleHeader($sheet, 'A1:G1');

        $rowNumber = 2;
        foreach ($rows as $row) {
            $sheet->setCellValueExplicit("A{$rowNumber}", (string) ($row['contact_id'] ?? ''), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValue("B{$rowNumber}", (string) ($row['client'] ?? ''));
            $this->setDateCell($sheet, "C{$rowNumber}", (string) ($row['enrolled_date'] ?? ''));
            $this->setDateCell($sheet, "D{$rowNumber}", (string) ($row['dropped_date'] ?? ''));
            $sheet->setCellValue("E{$rowNumber}", (string) ($row['current_status'] ?? ''));
            $sheet->setCellValue("F{$rowNumber}", (string) ($row['target_status'] ?? ''));
            $sheet->setCellValue("G{$rowNumber}", (string) ($row['action'] ?? ''));
            $rowNumber++;
        }

        $last = max(1, $rowNumber - 1);
        if ($last >= 2) {
            $sheet->getStyle("C2:D{$last}")->getNumberFormat()->setFormatCode('mm/dd/yyyy');
        }
        $this->finishSheet($sheet, "A1:G{$last}", ['A' => 16, 'B' => 28, 'C' => 14, 'D' => 14, 'E' => 34, 'F' => 24, 'G' => 24]);
        $sheet->freezePane('A2');
    }

    /** @param list<array<string,mixed>> $rows */
    private function fillSkipped(Worksheet $sheet, array $rows): void
    {
        $headers = ['Contact ID', 'Client', 'Enrolled Date', 'Dropped Date', 'Current Status', 'Skip Reason'];
        $sheet->setShowGridlines(false);
        $sheet->fromArray($headers, null, 'A1');
        $this->styleHeader($sheet, 'A1:F1');

        $rowNumber = 2;
        foreach ($rows as $row) {
            $sheet->setCellValueExplicit("A{$rowNumber}", (string) ($row['contact_id'] ?? ''), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValue("B{$rowNumber}", (string) ($row['client'] ?? ''));
            $this->setDateCell($sheet, "C{$rowNumber}", (string) ($row['enrolled_date'] ?? ''));
            $this->setDateCell($sheet, "D{$rowNumber}", (string) ($row['dropped_date'] ?? ''));
            $sheet->setCellValue("E{$rowNumber}", (string) ($row['current_status'] ?? ''));
            $sheet->setCellValue("F{$rowNumber}", (string) ($row['skip_reason'] ?? ''));
            $rowNumber++;
        }

        $last = max(1, $rowNumber - 1);
        if ($last >= 2) {
            $sheet->getStyle("C2:D{$last}")->getNumberFormat()->setFormatCode('mm/dd/yyyy');
        }
        $this->finishSheet($sheet, "A1:F{$last}", ['A' => 16, 'B' => 28, 'C' => 14, 'D' => 14, 'E' => 34, 'F' => 30]);
        $sheet->freezePane('A2');
    }

    private function setDateCell(Worksheet $sheet, string $cell, string $value): void
    {
        if ($value === '') {
            $sheet->setCellValue($cell, '');

            return;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($value, 0, 10));
        if ($date !== false) {
            $sheet->setCellValue($cell, ExcelDate::PHPToExcel($date));
        } else {
            $sheet->setCellValue($cell, $value);
        }
    }

    private function styleHeader(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF'], 'name' => 'Calibri', 'size' => 9],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF17853B']],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        ]);
    }

    /** @param array<string,int|float> $widths */
    private function finishSheet(Worksheet $sheet, string $range, array $widths): void
    {
        $sheet->getStyle($range)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle($range)->getFont()->setName('Calibri')->setSize(9);
        foreach ($widths as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
    }
}
