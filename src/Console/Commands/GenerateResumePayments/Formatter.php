<?php

declare(strict_types=1);

namespace Cmd\Reports\Console\Commands\GenerateResumePayments;

use Cmd\Reports\Services\EmailSenderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Phase 6 — builds the "Status Changes" workbook and sends the recap email,
 * mirroring the VBA's final block: a 3-column sheet (LLG ID / Name / Status) and
 * an HTML body split into a status-updates section and a "System Cancellations"
 * section, mailed to a fixed distribution list.
 */
class Formatter
{
    /** VBA SendTo. */
    private const RECIPIENTS_TO = [
        'candice@libertydebtrelief.com',
        'anthony@libertydebtrelief.com',
        'adrian@libertydebtrelief.com',
        'rama@libertydebtrelief.com',
        'scarlett@libertydebtrelief.com',
        'bill@libertydebtrelief.com',
        'freddie@libertydebtrelief.com',
    ];

    /** VBA SendCC. */
    private const RECIPIENTS_CC = [
        'sam@libertydebtrelief.com',
        'omar@libertydebtrelief.com',
        'jacob@libertydebtrelief.com',
    ];

    /**
     * @param list<array{llg_id:string,name:string,status:string}> $statusChanges
     */
    public function sendRecap(array $statusChanges, string $company, bool $dryRun = false, ?Command $console = null): bool
    {
        // VBA: LDR macro subject says "LDR"; PLAW macro subject says "ProLaw".
        $subjectSuffix = strtoupper($company) === 'PLAW' ? 'ProLaw' : 'LDR';
        $subject = 'Client NSF Status Updates - ' . $subjectSuffix;
        $body = $this->buildHtmlBody($statusChanges);

        $built = $this->buildWorkbook($statusChanges, $company);
        $attachments = [[
            'name' => $built['filename'],
            'contentType' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'contentBytes' => base64_encode((string) file_get_contents($built['path'])),
        ]];

        if ($dryRun) {
            Log::info('ResumePayments: DRY RUN - would send recap email', [
                'company' => $company,
                'subject' => $subject,
                'status_change_count' => count($statusChanges),
                'workbook' => $built['path'],
            ]);
            if (is_file($built['path'])) {
                @unlink($built['path']);
            }
            if ($console) {
                $console->info("[INFO] [{$company}] DRY RUN - recap email not sent ({$built['filename']} built).");
            }

            return true;
        }

        $email = new EmailSenderService();
        $sent = $email->sendMailHtml($subject, $body, self::RECIPIENTS_TO, self::RECIPIENTS_CC, [], $attachments);

        if (is_file($built['path'])) {
            @unlink($built['path']);
        }

        if ($console) {
            $console->info($sent
                ? "[INFO] [{$company}] Recap email sent."
                : "[WARN] [{$company}] Recap email not sent (send failed).");
        } elseif (!$sent) {
            Log::warning('GenerateResumePayments: recap email failed to send', ['company' => $company]);
        }

        return $sent;
    }

    /**
     * Two-section HTML body, matching the VBA's `Body` string.
     *
     * @param list<array{llg_id:string,name:string,status:string}> $statusChanges
     */
    public function buildHtmlBody(array $statusChanges): string
    {
        $statusRows = [];
        $cancelRows = [];

        foreach ($statusChanges as $change) {
            $line = sprintf(
                '%s | %s | %s',
                (string) ($change['llg_id'] ?? ''),
                (string) ($change['name'] ?? ''),
                (string) ($change['status'] ?? ''),
            );
            $line = htmlspecialchars($line, ENT_QUOTES, 'UTF-8');

            if (stripos((string) ($change['status'] ?? ''), 'System Cancel') !== false) {
                $cancelRows[] = $line;
            } else {
                $statusRows[] = $line;
            }
        }

        $body = 'The following clients were in NSF status and have been processed: <br><br>';
        $body .= implode('<br>', $statusRows);
        $body .= '<br><br>System Cancellations<br>';
        $body .= implode('<br>', $cancelRows);

        return $body;
    }

    /**
     * @param list<array{llg_id:string,name:string,status:string}> $statusChanges
     * @return array{filename:string, path:string}
     */
    public function buildWorkbook(array $statusChanges, string $company): array
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Status Changes');
        $sheet->setShowGridlines(false);

        $sheet->fromArray(['LLG ID', 'Name', 'Status'], null, 'A1');
        $this->styleHeader($sheet, 'A1:C1');

        $rowIndex = 2;
        foreach ($statusChanges as $change) {
            $sheet->setCellValue("A{$rowIndex}", (string) ($change['llg_id'] ?? ''));
            $sheet->setCellValue("B{$rowIndex}", (string) ($change['name'] ?? ''));
            $sheet->setCellValue("C{$rowIndex}", (string) ($change['status'] ?? ''));
            $rowIndex++;
        }

        $lastRow = max(2, $rowIndex - 1);

        $this->applyBorders($sheet, "A1:C{$lastRow}");
        $sheet->getStyle("A1:C{$lastRow}")->getFont()->setName('Calibri')->setSize(9);
        $sheet->getStyle("A1:C{$lastRow}")->getAlignment()->setVertical(Alignment::VERTICAL_TOP);
        $sheet->getColumnDimension('A')->setWidth(20);
        $sheet->getColumnDimension('B')->setWidth(20);
        $sheet->getColumnDimension('C')->setWidth(40);
        $sheet->freezePane('A2');
        $sheet->setSelectedCells('A1');

        $filename = 'Status Changes - ' . $company . ' - ' . date('m-d-Y') . '.xlsx';
        $path = storage_path('app/' . $filename);

        $writer = new Xlsx($spreadsheet);
        $writer->setPreCalculateFormulas(false);
        $writer->save($path);

        return ['filename' => $filename, 'path' => $path];
    }

    private function styleHeader(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->getFont()->setBold(true);
        $sheet->getStyle($range)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF17853B');
        $sheet->getStyle($range)->getFont()->getColor()->setARGB('FFFFFFFF');
    }

    private function applyBorders(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    }
}
