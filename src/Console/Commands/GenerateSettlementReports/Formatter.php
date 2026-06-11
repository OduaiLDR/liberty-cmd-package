<?php

namespace Cmd\Reports\Console\Commands\GenerateSettlementReports;

use Cmd\Reports\Services\DBConnector;
use Cmd\Reports\Services\EmailSenderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Builds settlement-report workbooks (3 layouts) and emails them.
 * Recipients come from dbo.TblReports (Report_Name 'SettlementReports', Company per portal).
 */
class Formatter
{
    private const REPORT_NAMES = ['SettlementReports', 'Settlement Reports'];

    /** Column layouts: key => label, in display order. Each matches the DebtPayPro report. */
    private const LAYOUTS = [
        'pending' => [
            'CONTACT_ID' => 'Contact ID',
            'FULL_NAME' => 'Full Name',
            'PROCESS_DATE' => 'Process Date',
            'AMOUNT' => 'Amount',
            'CREDITOR_NAME' => 'Creditor Name',
            'DEBT_THIRD_PARTY' => 'Debt - Third Party',
            'STATUS' => 'Status',
            'SETT_REF' => 'Sett Ref',
            'TRANS_ID' => 'Trans ID',
            'TRANS_TYPE' => 'Trans Type',
            'NEGOTIATOR' => 'Negotiator',
        ],
        'shipped' => [
            'CONTACT_ID' => 'Contact ID',
            'FULL_NAME' => 'Full Name',
            'PROCESS_DATE' => 'Process Date',
            'AMOUNT' => 'Amount',
            'SETT_REF' => 'Sett Ref',
            'SUB_TYPE' => 'Sub Type',
            'STATUS' => 'Status',
            'CREDITOR_NAME' => 'Creditor Name',
            'TRANS_ROW_ID' => 'id',
            'TRANS_ID' => 'Trans ID',
            'SETTLEMENT_CONFIRMATION' => 'Settlement Confirmation',
            'NEGOTIATOR' => 'Negotiator',
        ],
        'uncollected' => [
            'FULL_NAME' => 'Full Name',
            'PROCESS_DATE' => 'Process Date',
            'STATUS' => 'Status',
            'AMOUNT' => 'Amount',
            'TRANS_TYPE' => 'Trans Type',
            'CONTACT_ID' => 'Contact ID',
            'ASSIGNED_TO' => 'Assigned To',
            'CREDITOR_NAME' => 'Creditor Name',
            'SUB_TYPE' => 'Sub Type',
        ],
    ];

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{name:string, path:string}
     */
    public function buildWorkbook(array $rows, string $layoutKey, string $sheetTitle, string $filename): array
    {
        $headers = self::LAYOUTS[$layoutKey] ?? self::LAYOUTS['pending'];
        $keys = array_keys($headers);
        $colCount = count($keys);
        $lastCol = Coordinate::stringFromColumnIndex($colCount);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($this->safeSheetTitle($sheetTitle));
        $sheet->setShowGridlines(false);

        $sheet->fromArray(array_values($headers), null, 'A1');
        $this->styleHeader($sheet, "A1:{$lastCol}1");

        $rowIndex = 2;
        foreach ($rows as $row) {
            $col = 1;
            foreach ($keys as $key) {
                $letter = Coordinate::stringFromColumnIndex($col);
                $value = $row[$key] ?? '';

                if ($key === 'AMOUNT' && $value !== '' && $value !== null) {
                    $sheet->setCellValueExplicit("{$letter}{$rowIndex}", (string) $value, DataType::TYPE_NUMERIC);
                } else {
                    // Text — keeps long numeric refs/ids/confirmations from being rounded.
                    $sheet->setCellValueExplicit("{$letter}{$rowIndex}", (string) $value, DataType::TYPE_STRING);
                }
                $col++;
            }
            $rowIndex++;
        }

        $lastRow = max(2, $rowIndex - 1);
        $range = "A1:{$lastCol}{$lastRow}";

        // Amount number format.
        if (in_array('AMOUNT', $keys, true)) {
            $amountCol = Coordinate::stringFromColumnIndex(array_search('AMOUNT', $keys, true) + 1);
            $sheet->getStyle("{$amountCol}2:{$amountCol}{$lastRow}")->getNumberFormat()->setFormatCode('#,##0.00');
        }

        $sheet->getStyle($range)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        for ($i = 1; $i <= $colCount; $i++) {
            $sheet->getColumnDimensionByColumn($i)->setAutoSize(true);
        }
        $sheet->getStyle($range)->getFont()->setName('Calibri')->setSize(9);
        $sheet->getStyle($range)->getAlignment()->setVertical(Alignment::VERTICAL_TOP);

        for ($r = 2; $r <= $lastRow; $r++) {
            if ($r % 2 === 0) {
                $sheet->getStyle("A{$r}:{$lastCol}{$r}")
                    ->getFill()->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setARGB('FFF5F7FA');
            }
        }

        $sheet->freezePane('A2');
        $sheet->setSelectedCells('A1');

        $path = storage_path('app/' . $filename);
        $writer = new Xlsx($spreadsheet);
        $writer->setPreCalculateFormulas(false);
        $writer->save($path);

        return ['name' => $filename, 'path' => $path];
    }

    /**
     * Email a portal's report files in one message, recipients from TblReports.
     *
     * @param  array<int, array{name:string, path:string}>  $files
     */
    public function sendReports(DBConnector $connector, array $files, string $portal, string $company, ?Command $console = null): bool
    {
        $attachments = [];
        foreach ($files as $f) {
            if (!isset($f['path']) || !is_file($f['path'])) {
                continue;
            }
            $attachments[] = [
                'name' => $f['name'],
                'contentType' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'contentBytes' => base64_encode(file_get_contents($f['path'])),
            ];
        }

        if (empty($attachments)) {
            Log::warning('GenerateSettlementReports: no attachments to send.', ['portal' => $portal]);
            if ($console) {
                $console->warn("[WARN] {$portal} settlement reports not sent (no files).");
            }
            return false;
        }

        $label = date('F Y');
        $subject = "Settlement Reports - {$portal} - {$label}";
        $body = "Attached are the {$portal} settlement reports for {$label}.";

        $email = new EmailSenderService();
        $sent = $email->sendMailUsingTblReports(
            $connector,
            self::REPORT_NAMES,
            [$company],
            $subject,
            $body,
            $attachments,
            true
        );

        if ($console) {
            if ($sent) {
                $console->info("[INFO] {$portal} settlement reports sent.");
            } else {
                $console->warn("[WARN] {$portal} settlement reports not sent (no recipients or send failed).");
            }
        } elseif (!$sent) {
            Log::warning('GenerateSettlementReports: failed to send email.', ['portal' => $portal]);
        }

        return $sent;
    }

    private function styleHeader(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->getFont()->setBold(true);
        $sheet->getStyle($range)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF17853B');
        $sheet->getStyle($range)->getFont()->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle($range)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    }

    private function safeSheetTitle(string $title): string
    {
        // Excel sheet titles: max 31 chars, no : \ / ? * [ ]
        $clean = preg_replace('/[:\\\\\/\?\*\[\]]/', ' ', $title);
        return substr($clean, 0, 31);
    }
}
