<?php

declare(strict_types=1);

namespace Cmd\Reports\Console\Commands\GenerateMissingAssignmentsReport;

use Cmd\Reports\Services\DBConnector;
use Cmd\Reports\Services\EmailSenderService;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class Formatter
{
    public function __construct(private readonly ?EmailSenderService $email = null) {}

    /** @return array{filename:string,path:string} */
    public function buildWorkbook(array $rows, array $window): array
    {
        $spreadsheet = new Spreadsheet;
        try {
            $this->fillSheet($spreadsheet->getActiveSheet(), $rows, $window);
            $filename = 'Missing Assignments Report - '.$window['report_date'].'.xlsx';
            $path = storage_path('app/missing-assignments-'.bin2hex(random_bytes(8)).'.xlsx');
            $writer = new Xlsx($spreadsheet);
            $writer->setPreCalculateFormulas(false);
            $writer->save($path);

            return ['filename' => $filename, 'path' => $path];
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    public function sendReport(DBConnector $connector, array $file, array $window, int $count, int $unresolved): bool
    {
        $bytes = is_readable($file['path']) ? file_get_contents($file['path']) : false;
        if ($bytes === false || $bytes === '') {
            return false;
        }

        $email = $this->email ?? new EmailSenderService;

        // Combined LDR/PLAW report: recipients are managed only in TblReports.
        return $email->sendMailUsingTblReports(
            $connector,
            ['MissingAssignmentsReport', 'Missing Assignments Report'],
            [],
            'Missing Assignments Report - '.$window['report_date'],
            $this->emailBody($window, $count, $unresolved),
            [[
                'name' => $file['filename'],
                'contentType' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'contentBytes' => base64_encode($bytes),
            ]],
            false
        );
    }

    private function emailBody(array $window, int $count, int $unresolved): string
    {
        $body = 'The following clients are not assigned to an agent, please review and assign the appropriate agent in the Lending Tower Forth CRM.'
            ."\n\nPlease see the attached Missing Assignments Report."
            ."\nCreated dates: {$window['start']} through {$window['end']} (inclusive)."
            ."\nClients requiring assignment: {$count}.";
        if ($count === 0) {
            $body .= "\nNo clients match the report criteria.";
        }
        if ($unresolved > 0) {
            $body .= "\nClients without a resolved Lending Tower ID: {$unresolved}. These clients remain listed.";
        }

        return $body;
    }

    private function fillSheet(Worksheet $sheet, array $rows, array $window): void
    {
        $sheet->setTitle('Missing Assignments');
        $sheet->setShowGridlines(false);
        $sheet->mergeCells('A1:E1');
        $sheet->setCellValue('A1', 'Missing Assignments Report');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
        $sheet->mergeCells('A2:E2');
        $sheet->setCellValue('A2', "Created dates: {$window['start']} through {$window['end']} (inclusive)");
        $sheet->mergeCells('A3:E3');
        $sheet->setCellValue('A3', 'Clients requiring assignment: '.count($rows));
        $unresolved = count(array_filter($rows, static fn (array $row): bool => $row['lending_tower_id'] === ''));
        if ($unresolved > 0) {
            $sheet->mergeCells('A4:E4');
            $sheet->setCellValue('A4', "Clients without a resolved Lending Tower ID: {$unresolved} (blank yellow cells)");
        }
        $sheet->fromArray(['Enrollment ID', 'Lending Tower ID', 'Client', 'Status', 'Created Date'], null, 'A5');
        $sheet->getStyle('A5:E5')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF17853B']],
        ]);

        $index = 6;
        foreach ($rows as $row) {
            foreach (['A' => 'llg_id', 'B' => 'lending_tower_id', 'C' => 'client', 'D' => 'status'] as $column => $key) {
                $sheet->setCellValueExplicit($column.$index, $row[$key], DataType::TYPE_STRING);
            }
            $sheet->setCellValue('E'.$index, ExcelDate::PHPToExcel(new \DateTimeImmutable($row['created_date'])));
            $sheet->getStyle('E'.$index)->getNumberFormat()->setFormatCode('mm/dd/yyyy');
            if ($row['lending_tower_id'] === '') {
                $sheet->getStyle('B'.$index)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFF2CC');
            }
            $index++;
        }

        if ($rows === []) {
            $sheet->mergeCells('A6:E6');
            $sheet->setCellValue('A6', 'No clients match the report criteria.');
        } else {
            $sheet->setAutoFilter('A5:E'.($index - 1));
            $sheet->getStyle('A5:E'.($index - 1))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
            $sheet->getStyle('C6:D'.($index - 1))->getAlignment()->setWrapText(true);
        }

        foreach (['A' => 23, 'B' => 23, 'C' => 38, 'D' => 34, 'E' => 18] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
        $sheet->freezePane('A6');
        $sheet->setSelectedCells('A1');
        $sheet->getPageSetup()->setOrientation('landscape')->setFitToWidth(1)->setFitToHeight(0)->setRowsToRepeatAtTopByStartAndEnd(1, 5);
    }
}
