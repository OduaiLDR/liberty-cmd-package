<?php

declare(strict_types=1);

namespace Cmd\Reports\Console\Commands\GenerateResumePayments;

use Cmd\Reports\Services\DBConnector;
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
    /**
     * TblReports lookup keys (Report_Name) for the recap recipients. Mirrors how
     * GenerateEmployeesReport pulls its distribution list from dbo.TblReports
     * instead of hardcoding. Recipients (Send_To/Send_CC/Send_BCC) are managed in
     * the table, not in code.
     */
    private const REPORT_NAMES = ['ResumePayments', 'Client NSF Status Updates'];

    /**
     * @param list<array{llg_id:string,name:string,status:string}> $statusChanges
     */
    public function sendRecap(DBConnector $connector, array $statusChanges, string $company, bool $dryRun = false, ?Command $console = null): bool
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

        // Recipients come from dbo.TblReports (Send_To/Send_CC/Send_BCC keyed by
        // Report_Name + Company), same as the other package reports. One row per
        // company (LDR / PLAW). If a company row is missing the service falls back
        // to the report's company-less rows, so a single shared row also works.
        $email = new EmailSenderService();
        $sent = $email->sendMailUsingTblReportsHtml(
            $connector,
            self::REPORT_NAMES,
            [strtoupper($company)],
            $subject,
            $body,
            $attachments,
            true
        );

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
     * Categorize the status changes into the recap's display groups and sort
     * each group by client name (Jacob 2026-07-06): regular NSF status updates,
     * then System Cancel "Pending" bucketed by Day (0 → 3), then the final
     * cancels. Shared by the email body and the Excel attachment so both present
     * in the same order.
     *
     * @param list<array{llg_id:string,name:string,status:string}> $statusChanges
     * @return array{status:list<array{llg_id:string,name:string,status:string}>, pending:array<int,list<array{llg_id:string,name:string,status:string}>>, final:list<array{llg_id:string,name:string,status:string}>}
     */
    private function groupChanges(array $statusChanges): array
    {
        $status = [];
        $pending = [];   // day (0..3) => rows — "System Cancel Pending - Day N"
        $final = [];     // completed cancels + other cancel outcomes

        foreach ($statusChanges as $change) {
            $s = (string) ($change['status'] ?? '');
            if (preg_match('/System Cancel Pending - Day (\d+)/i', $s, $m) === 1) {
                $pending[(int) $m[1]][] = $change;
            } elseif (stripos($s, 'System Cancel') !== false) {
                $final[] = $change;
            } else {
                $status[] = $change;
            }
        }

        $byName = static fn(array $a, array $b): int => strcasecmp(
            (string) ($a['name'] ?? ''),
            (string) ($b['name'] ?? ''),
        );

        usort($status, $byName);
        usort($final, $byName);
        ksort($pending);                 // Day 0 → 3
        foreach ($pending as &$rows) {
            usort($rows, $byName);       // each day sorted by client
        }
        unset($rows);

        return ['status' => $status, 'pending' => $pending, 'final' => $final];
    }

    /**
     * HTML body grouped for the managers (Jacob 2026-07-06): the NSF status-update
     * list, then System Cancellations — Pending split into Day 0 / 1 / 2 / 3
     * sub-groups, then the final "cancelled" group. Every group is sorted by
     * client name. Full detail also ships as the attached "Status Changes" Excel.
     *
     * @param list<array{llg_id:string,name:string,status:string}> $statusChanges
     */
    public function buildHtmlBody(array $statusChanges): string
    {
        $groups = $this->groupChanges($statusChanges);

        $fmt = static function (array $c): string {
            $line = sprintf(
                '%s | %s | %s',
                (string) ($c['llg_id'] ?? ''),
                (string) ($c['name'] ?? ''),
                (string) ($c['status'] ?? ''),
            );

            return htmlspecialchars($line, ENT_QUOTES, 'UTF-8');
        };

        $statusRows = array_map($fmt, $groups['status']);

        $body  = 'The following clients were in NSF status and have been processed: <br><br>';
        $body .= $statusRows === [] ? '(none)' : implode('<br>', $statusRows);

        $body .= '<br><br><b>System Cancellations - Pending</b> (Day 0&ndash;3 &mdash; managers can still intervene)<br>';
        if ($groups['pending'] === []) {
            $body .= '(none)';
        } else {
            $blocks = [];
            foreach ($groups['pending'] as $day => $rows) {
                $lines = array_map($fmt, $rows);
                $blocks[] = '<u>Day ' . (int) $day . '</u><br>' . implode('<br>', $lines);
            }
            $body .= implode('<br><br>', $blocks);
        }

        $finalRows = array_map($fmt, $groups['final']);
        $body .= '<br><br><b>System Cancellations</b> (final &mdash; cancelled)<br>';
        $body .= $finalRows === [] ? '(none)' : implode('<br>', $finalRows);

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

        // Same grouping/sort as the email body (Jacob 2026-07-06): NSF updates,
        // then Pending Day 0 → 3, then final cancels — each group by client name.
        $groups = $this->groupChanges($statusChanges);
        $ordered = $groups['status'];
        foreach ($groups['pending'] as $rows) {
            foreach ($rows as $row) {
                $ordered[] = $row;
            }
        }
        foreach ($groups['final'] as $row) {
            $ordered[] = $row;
        }

        $rowIndex = 2;
        foreach ($ordered as $change) {
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
