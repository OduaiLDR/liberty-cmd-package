<?php

declare(strict_types=1);

namespace Cmd\Reports\Console\Commands\GenerateResumePayments;

use Cmd\Reports\Services\DBConnector;
use Cmd\Reports\Services\EmailSenderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Phase 6 — recap for a ResumePayments run (Jacob 2026-07-20, restructured 2026-08-11):
 *   - Email BODY: a per-stage summary — one line per stage with the client count
 *     and total debt. No client lists in the body.
 *   - Attachment: one worksheet per stage, columns LLG ID / Name / Enrollment Status /
 *     Cleared Payments / Debt / Days since NSF, sorted by enrollment status (asc) then
 *     days since payment (desc).
 * One recap per company (LDR / ProLaw). Recipients live in dbo.TblReports.
 */
class Formatter
{
    /**
     * TblReports lookup keys (Report_Name) for the recap recipients. Recipients
     * (Send_To/Send_CC/Send_BCC) are managed in the table, not in code.
     */
    private const REPORT_NAMES = ['ResumePayments', 'Client NSF Status Updates'];

    /**
     * The recap stages, in display order. `key` matches the `stage` set on each row
     * by GenerateResumePayments (its STAGE_* constants + nsfStage()). `label` is the
     * email/summary text; `sheet` is the Excel tab name (kept <= 31 chars with no
     * Excel-forbidden characters). Keep in sync with the command's STAGE_* keys.
     */
    private const STAGES = [
        ['key' => 'Resolved', 'label' => 'Resolved', 'sheet' => 'Resolved'],
        // Jacob 2026-08-11: the three fixed NSF-1/2/3 sheets are replaced by one At-Risk sheet
        // (everyone who changed to an at-risk status today and isn't cancelling yet).
        ['key' => 'At-Risk', 'label' => 'At-Risk', 'sheet' => 'At-Risk'],
        ['key' => 'Cancels - Grace Period', 'label' => 'Cancels - Grace Period', 'sheet' => 'Cancel - Grace Period'],
        // Internal key stays 'Cancels - Release Hold Requested' to match the command's
        // STAGE_CANCEL_HOLD (no matched-pair change), but the DISPLAY label is "Manual
        // Review" — the bucket actually holds settlement/EPF manual cases, not held
        // clients (held clients auto-cancel now). Jacob 2026-07-21.
        ['key' => 'Cancels - Release Hold Requested', 'label' => 'Cancels - Manual Review', 'sheet' => 'Cancel - Manual Review'],
        // Display labels renamed per Jacob 2026-07-21 (Backlog->Queued, Complete->Completed);
        // internal keys unchanged so the command↔Formatter match holds (no matched-pair risk).
        ['key' => 'Cancels - Backlog', 'label' => 'Cancels - Queued', 'sheet' => 'Cancel - Queued'],
        ['key' => 'Cancels - Complete', 'label' => 'Cancels - Completed', 'sheet' => 'Cancel - Completed'],
    ];

    /**
     * @param list<array{llg_id:string,name:string,stage:string,days:int,debt:float,enrollment_status:string,cleared_payments:int}> $statusChanges
     */
    public function sendRecap(DBConnector $connector, array $statusChanges, string $company, bool $dryRun = false, ?Command $console = null, bool $cancelsOnly = false): bool
    {
        // VBA: LDR macro subject says "LDR"; PLAW macro subject says "ProLaw".
        $subjectSuffix = strtoupper($company) === 'PLAW' ? 'ProLaw' : 'LDR';
        // A --cancels-only run skips the NSF status step (the day's first/full run
        // already did it), so its report is a pure cancels report: NSF stages omitted,
        // its own subject. The main full run keeps Jacob's NSF format unchanged.
        $subject = ($cancelsOnly ? 'System Cancels - ' : 'Client NSF Status Updates - ') . $subjectSuffix;

        $grouped = $this->groupByStage($statusChanges);
        $totalRows = 0;
        foreach ($grouped as $bucket) {
            $totalRows += count($bucket);
        }

        // Zero-activity run (nothing processed + no cancels): send a plain "nothing to
        // report" line instead of an all-zeros grid, and skip the empty attachment — so
        // a quiet day never lands in the team's inbox looking like a broken report.
        if ($totalRows === 0) {
            $body = $this->buildEmptyBody($subjectSuffix);
            $attachments = [];
            $builtPath = null;
        } else {
            $body = $this->buildSummaryBody($grouped, $subjectSuffix, $cancelsOnly);
            $built = $this->buildWorkbook($grouped, $company, $cancelsOnly);
            $builtPath = $built['path'];
            $attachments = [[
                'name' => $built['filename'],
                'contentType' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'contentBytes' => base64_encode((string) file_get_contents($built['path'])),
            ]];
        }

        if ($dryRun) {
            Log::info('ResumePayments: DRY RUN - would send recap email', [
                'company' => $company,
                'subject' => $subject,
                'status_change_count' => count($statusChanges),
                'workbook' => $builtPath,
            ]);
            if ($builtPath !== null && is_file($builtPath)) {
                @unlink($builtPath);
            }
            if ($console) {
                $note = $builtPath !== null ? basename($builtPath) . ' built' : 'no activity — plain note, no attachment';
                $console->info("[INFO] [{$company}] DRY RUN - recap email not sent ({$note}).");
            }

            return true;
        }

        // Recipients come from dbo.TblReports (Send_To/Send_CC/Send_BCC keyed by
        // Report_Name + Company), same as the other package reports.
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

        if ($builtPath !== null && is_file($builtPath)) {
            @unlink($builtPath);
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
     * Body for a zero-activity run — a plain confirmation line instead of an all-zeros
     * table, so a quiet day reads as "job ran, nothing to do" rather than a broken
     * report. No attachment accompanies this body.
     */
    private function buildEmptyBody(string $label): string
    {
        // Jacob 2026-07-30: two-line heading — title on line 1, "LDR — date" on line 2.
        return 'Resume Payments Summary<br>'
            . htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
            . ' &mdash; ' . date('m/d/Y') . '<br><br>'
            . 'No NSF status changes or system cancels to report today.';
    }

    /**
     * Bucket the rows by stage (in STAGES order) and sort each bucket by enrollment
     * status ascending, then days-since-payment descending, then client name ascending
     * (Jacob 2026-08-11). Every stage gets a bucket (possibly empty) so the layout is
     * stable. Rows with an unrecognized stage are dropped (they should not occur).
     *
     * @param list<array{llg_id:string,name:string,stage:string,days:int,debt:float,enrollment_status:string,cleared_payments:int}> $statusChanges
     * @return array<string, list<array{llg_id:string,name:string,stage:string,days:int,debt:float,enrollment_status:string,cleared_payments:int}>>
     */
    private function groupByStage(array $statusChanges): array
    {
        $buckets = [];
        foreach (self::STAGES as $stage) {
            $buckets[$stage['key']] = [];
        }

        foreach ($statusChanges as $change) {
            $stage = (string) ($change['stage'] ?? '');
            if ($stage !== '' && isset($buckets[$stage])) {
                $buckets[$stage][] = $change;
            }
        }

        $sorter = static function (array $a, array $b): int {
            // Jacob 2026-08-11: enrollment status ascending, then days-since-payment descending.
            $byStatus = strcasecmp((string) ($a['enrollment_status'] ?? ''), (string) ($b['enrollment_status'] ?? ''));
            if ($byStatus !== 0) {
                return $byStatus;
            }
            $byDays = ((int) ($b['days'] ?? 0)) <=> ((int) ($a['days'] ?? 0)); // days desc
            if ($byDays !== 0) {
                return $byDays;
            }

            return strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')); // name asc
        };

        foreach ($buckets as &$rows) {
            usort($rows, $sorter);
        }
        unset($rows);

        return $buckets;
    }

    /**
     * The stages to DISPLAY. A --cancels-only run skips the NSF status step, so its
     * report shows only the four Cancels stages (the NSF/Resolved rows would just be
     * zeros); a full run shows all eight. Grouping still buckets all eight — this only
     * controls what the summary + workbook render.
     *
     * @return list<array{key:string,label:string,sheet:string}>
     */
    private function displayStages(bool $cancelsOnly): array
    {
        if (!$cancelsOnly) {
            return self::STAGES;
        }

        return array_values(array_filter(
            self::STAGES,
            static fn(array $s): bool => str_starts_with($s['key'], 'Cancels'),
        ));
    }

    /**
     * Summary email body — one line per stage with the client count and total debt,
     * a grand total, and a pointer to the attached per-client detail (Jacob 2026-07-20:
     * "give a summary and totals only ... put detail in attachment"). A cancels-only
     * run renders only the Cancels stages (see displayStages).
     *
     * @param array<string, list<array{llg_id:string,name:string,stage:string,days:int,debt:float,enrollment_status:string,cleared_payments:int}>> $grouped
     */
    private function buildSummaryBody(array $grouped, string $label, bool $cancelsOnly = false): string
    {
        // Summary sections (Jacob 2026-08-11): Resolved on its own line, then At-Risk on its own
        // line, then the Cancels rows closed by a "Cancels Total" subtotal. Resolved and At-Risk
        // are each a single row; only Cancels carries a subtotal.
        $subtotalLabel = ['cancels' => 'Cancels Total'];
        $sectionOf = static function (string $key): string {
            if ($key === 'At-Risk') {
                return 'atrisk';
            }
            if (str_starts_with($key, 'Cancels')) {
                return 'cancels';
            }

            return 'resolved';
        };
        $spacer = '<tr><td colspan="3" style="padding:3px 0;"></td></tr>';

        $rows = '';
        $prevSection = null;
        $secClients = 0;
        $secDebt = 0.0;

        foreach ($this->displayStages($cancelsOnly) as $stage) {
            $section = $sectionOf($stage['key']);

            // Section changed → close out the previous one (its subtotal, if any) + a spacer.
            if ($prevSection !== null && $section !== $prevSection) {
                if (isset($subtotalLabel[$prevSection])) {
                    $rows .= $this->summaryRow($subtotalLabel[$prevSection], $secClients, $secDebt, 'subtotal');
                }
                $rows .= $spacer;
                $secClients = 0;
                $secDebt = 0.0;
            }

            $bucket = $grouped[$stage['key']] ?? [];
            $count = count($bucket);
            $debt = 0.0;
            foreach ($bucket as $r) {
                $debt += (float) ($r['debt'] ?? 0);
            }
            $secClients += $count;
            $secDebt += $debt;

            // Line under each single-row section (Resolved, At-Risk) before the next section.
            $rowBottom = in_array($stage['key'], ['Resolved', 'At-Risk'], true);
            $rows .= $this->summaryRow($stage['label'], $count, $debt, '', $rowBottom);
            $prevSection = $section;
        }

        // Close the final section's subtotal (e.g. Cancels — no bottom border; last visible row).
        if ($prevSection !== null && isset($subtotalLabel[$prevSection])) {
            $rows .= $this->summaryRow($subtotalLabel[$prevSection], $secClients, $secDebt, 'subtotal');
        }
        // Grand Total removed per Jacob 2026-07-30.

        // Jacob 2026-07-30: two-line heading — title on line 1, "LDR — date" on line 2.
        $heading = $cancelsOnly ? 'System Cancels Summary' : 'Resume Payments Summary';
        $body  = $heading . '<br>'
            . htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
            . ' &mdash; ' . date('m/d/Y') . '<br><br>';
        $body .= '<table style="border-collapse:collapse; font-family:Calibri,Arial,sans-serif; font-size:13px;">';
        $body .= '<tr>'
            . '<th style="text-align:left; padding:4px 16px 4px 0; border-bottom:1px solid #ccc;">Stage</th>'
            . '<th style="text-align:right; padding:4px 16px 4px 0; border-bottom:1px solid #ccc;">Clients</th>'
            . '<th style="text-align:right; padding:4px 0; border-bottom:1px solid #ccc;">Debt</th>'
            . '</tr>';
        $body .= $rows;
        $body .= '</table>';
        // Jacob 2026-07-30: short closing line.
        $body .= '<br>Contact level detail attached.';

        return $body;
    }

    /**
     * One summary-table row. $variant: '' = normal, 'subtotal' = bold + light top rule,
     * 'grand' = bold + heavier top rule.
     */
    private function summaryRow(string $label, int $count, float $debt, string $variant = '', bool $borderBottom = false): string
    {
        $bold = $variant !== '';
        $o = $bold ? '<b>' : '';
        $c = $bold ? '</b>' : '';
        $border = match ($variant) {
            'grand' => ' border-top:2px solid #999;',
            'subtotal' => ' border-top:1px solid #ccc;',
            default => '',
        };
        if ($borderBottom) {
            $border .= ' border-bottom:1px solid #ccc;';
        }

        return '<tr>'
            . '<td style="padding:5px 16px 4px 0;' . $border . '">' . $o . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . $c . '</td>'
            . '<td style="padding:5px 16px 4px 0; text-align:right;' . $border . '">' . $o . $count . $c . '</td>'
            . '<td style="padding:5px 0 4px 0; text-align:right;' . $border . '">' . $o . '$' . number_format($debt, 2) . $c . '</td>'
            . '</tr>';
    }

    /**
     * One worksheet per stage: LLG ID / Name / Enrollment Status / Cleared Payments /
     * Debt / Days since NSF, sorted by enrollment status (asc) then days-since-payment
     * (desc). Empty stages still get a header-only sheet so the layout is stable
     * run-to-run.
     *
     * @param array<string, list<array{llg_id:string,name:string,stage:string,days:int,debt:float,enrollment_status:string,cleared_payments:int}>> $grouped
     * @return array{filename:string, path:string}
     */
    public function buildWorkbook(array $grouped, string $company, bool $cancelsOnly = false): array
    {
        $spreadsheet = new Spreadsheet();

        $first = true;
        foreach ($this->displayStages($cancelsOnly) as $stage) {
            $sheet = $first ? $spreadsheet->getActiveSheet() : $spreadsheet->createSheet();
            $first = false;

            $sheet->setTitle($stage['sheet']);
            $sheet->setShowGridlines(false);

            // Jacob 2026-08-11: add Enrollment Status + Cleared Payments to every sheet.
            $sheet->fromArray(['LLG ID', 'Name', 'Enrollment Status', 'Cleared Payments', 'Debt', 'Days since NSF'], null, 'A1');
            $this->styleHeader($sheet, 'A1:F1');

            $rowIndex = 2;
            foreach ($grouped[$stage['key']] ?? [] as $change) {
                $sheet->setCellValue("A{$rowIndex}", (string) ($change['llg_id'] ?? ''));
                $sheet->setCellValue("B{$rowIndex}", (string) ($change['name'] ?? ''));
                $sheet->setCellValue("C{$rowIndex}", (string) ($change['enrollment_status'] ?? ''));
                $sheet->setCellValueExplicit("D{$rowIndex}", (int) ($change['cleared_payments'] ?? 0), DataType::TYPE_NUMERIC);
                $sheet->setCellValueExplicit("E{$rowIndex}", (float) ($change['debt'] ?? 0), DataType::TYPE_NUMERIC);
                $sheet->setCellValueExplicit("F{$rowIndex}", (int) ($change['days'] ?? 0), DataType::TYPE_NUMERIC);
                $rowIndex++;
            }

            $lastRow = max(2, $rowIndex - 1);

            $this->applyBorders($sheet, "A1:F{$lastRow}");
            $sheet->getStyle("A1:F{$lastRow}")->getFont()->setName('Calibri')->setSize(9);
            $sheet->getStyle("A1:F{$lastRow}")->getAlignment()->setVertical(Alignment::VERTICAL_TOP);
            $sheet->getStyle("E2:E{$lastRow}")->getNumberFormat()->setFormatCode('#,##0.00');
            $sheet->getColumnDimension('A')->setWidth(18);
            $sheet->getColumnDimension('B')->setWidth(26);
            $sheet->getColumnDimension('C')->setWidth(34);
            $sheet->getColumnDimension('D')->setWidth(16);
            $sheet->getColumnDimension('E')->setWidth(14);
            $sheet->getColumnDimension('F')->setWidth(14);
            $sheet->freezePane('A2');
            $sheet->setSelectedCells('A1');
        }

        $spreadsheet->setActiveSheetIndex(0);

        $filename = 'ResumePayments - ' . $company . ' - ' . date('m-d-Y') . '.xlsx';
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
