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
 * Phase 6 — recap for a ResumePayments run (Jacob 2026-07-20 format):
 *   - Email BODY: a per-stage summary — one line per stage with the client count
 *     and total debt. No client lists in the body.
 *   - Attachment: one worksheet per stage, columns LLG ID / Name / Debt / Days
 *     since NSF, sorted by days (desc) then name (asc).
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
        ['key' => 'NSF-1', 'label' => 'NSF-1', 'sheet' => 'NSF-1'],
        ['key' => 'NSF-2', 'label' => 'NSF-2', 'sheet' => 'NSF-2'],
        ['key' => 'NSF-3', 'label' => 'NSF-3', 'sheet' => 'NSF-3'],
        ['key' => 'Cancels - Grace Period', 'label' => 'Cancels - Grace Period', 'sheet' => 'Cancel - Grace Period'],
        ['key' => 'Cancels - Release Hold Requested', 'label' => 'Cancels - Release Hold Requested', 'sheet' => 'Cancel - Hold Requested'],
        ['key' => 'Cancels - Backlog', 'label' => 'Cancels - Backlog', 'sheet' => 'Cancel - Backlog'],
        ['key' => 'Cancels - Complete', 'label' => 'Cancels - Complete', 'sheet' => 'Cancel - Complete'],
    ];

    /**
     * @param list<array{llg_id:string,name:string,stage:string,days:int,debt:float}> $statusChanges
     */
    public function sendRecap(DBConnector $connector, array $statusChanges, string $company, bool $dryRun = false, ?Command $console = null): bool
    {
        // VBA: LDR macro subject says "LDR"; PLAW macro subject says "ProLaw".
        $subjectSuffix = strtoupper($company) === 'PLAW' ? 'ProLaw' : 'LDR';
        $subject = 'Client NSF Status Updates - ' . $subjectSuffix;

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
            $body = $this->buildSummaryBody($grouped, $subjectSuffix);
            $built = $this->buildWorkbook($grouped, $company);
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
        return 'ResumePayments summary &mdash; ' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
            . ' &mdash; ' . date('m/d/Y') . '<br><br>'
            . 'No NSF status changes or system cancels to report today.';
    }

    /**
     * Bucket the rows by stage (in STAGES order) and sort each bucket by
     * days-since-NSF descending, then client name ascending (Jacob 2026-07-20).
     * Every stage gets a bucket (possibly empty) so the layout is stable. Rows with
     * an unrecognized stage are dropped (they should not occur).
     *
     * @param list<array{llg_id:string,name:string,stage:string,days:int,debt:float}> $statusChanges
     * @return array<string, list<array{llg_id:string,name:string,stage:string,days:int,debt:float}>>
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
     * Summary email body — one line per stage with the client count and total debt,
     * a grand total, and a pointer to the attached per-client detail (Jacob 2026-07-20:
     * "give a summary and totals only ... put detail in attachment").
     *
     * @param array<string, list<array{llg_id:string,name:string,stage:string,days:int,debt:float}>> $grouped
     */
    private function buildSummaryBody(array $grouped, string $label): string
    {
        $rows = '';
        $totalClients = 0;
        $totalDebt = 0.0;

        foreach (self::STAGES as $stage) {
            $bucket = $grouped[$stage['key']] ?? [];
            $count = count($bucket);
            $debt = 0.0;
            foreach ($bucket as $r) {
                $debt += (float) ($r['debt'] ?? 0);
            }
            $totalClients += $count;
            $totalDebt += $debt;

            $rows .= '<tr>'
                . '<td style="padding:4px 16px 4px 0;">' . htmlspecialchars($stage['label'], ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td style="padding:4px 16px 4px 0; text-align:right;">' . $count . '</td>'
                . '<td style="padding:4px 0; text-align:right;">$' . number_format($debt, 2) . '</td>'
                . '</tr>';
        }

        $body  = 'ResumePayments summary &mdash; ' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
            . ' &mdash; ' . date('m/d/Y') . '<br><br>';
        $body .= '<table style="border-collapse:collapse; font-family:Calibri,Arial,sans-serif; font-size:13px;">';
        $body .= '<tr>'
            . '<th style="text-align:left; padding:4px 16px 4px 0; border-bottom:1px solid #ccc;">Stage</th>'
            . '<th style="text-align:right; padding:4px 16px 4px 0; border-bottom:1px solid #ccc;">Clients</th>'
            . '<th style="text-align:right; padding:4px 0; border-bottom:1px solid #ccc;">Debt</th>'
            . '</tr>';
        $body .= $rows;
        $body .= '<tr>'
            . '<td style="padding:6px 16px 4px 0; border-top:1px solid #ccc;"><b>Total</b></td>'
            . '<td style="padding:6px 16px 4px 0; text-align:right; border-top:1px solid #ccc;"><b>' . $totalClients . '</b></td>'
            . '<td style="padding:6px 0 4px 0; text-align:right; border-top:1px solid #ccc;"><b>$' . number_format($totalDebt, 2) . '</b></td>'
            . '</tr>';
        $body .= '</table>';
        $body .= '<br>Per-client detail (LLG ID, name, debt, days since NSF) is in the attached workbook &mdash; one sheet per stage.';

        return $body;
    }

    /**
     * One worksheet per stage: LLG ID / Name / Debt / Days since NSF, sorted by days
     * (desc) then name. Empty stages still get a header-only sheet so the layout is
     * stable run-to-run.
     *
     * @param array<string, list<array{llg_id:string,name:string,stage:string,days:int,debt:float}>> $grouped
     * @return array{filename:string, path:string}
     */
    public function buildWorkbook(array $grouped, string $company): array
    {
        $spreadsheet = new Spreadsheet();

        $first = true;
        foreach (self::STAGES as $stage) {
            $sheet = $first ? $spreadsheet->getActiveSheet() : $spreadsheet->createSheet();
            $first = false;

            $sheet->setTitle($stage['sheet']);
            $sheet->setShowGridlines(false);

            $sheet->fromArray(['LLG ID', 'Name', 'Debt', 'Days since NSF'], null, 'A1');
            $this->styleHeader($sheet, 'A1:D1');

            $rowIndex = 2;
            foreach ($grouped[$stage['key']] ?? [] as $change) {
                $sheet->setCellValue("A{$rowIndex}", (string) ($change['llg_id'] ?? ''));
                $sheet->setCellValue("B{$rowIndex}", (string) ($change['name'] ?? ''));
                $sheet->setCellValueExplicit("C{$rowIndex}", (float) ($change['debt'] ?? 0), DataType::TYPE_NUMERIC);
                $sheet->setCellValueExplicit("D{$rowIndex}", (int) ($change['days'] ?? 0), DataType::TYPE_NUMERIC);
                $rowIndex++;
            }

            $lastRow = max(2, $rowIndex - 1);

            $this->applyBorders($sheet, "A1:D{$lastRow}");
            $sheet->getStyle("A1:D{$lastRow}")->getFont()->setName('Calibri')->setSize(9);
            $sheet->getStyle("A1:D{$lastRow}")->getAlignment()->setVertical(Alignment::VERTICAL_TOP);
            $sheet->getStyle("C2:C{$lastRow}")->getNumberFormat()->setFormatCode('#,##0.00');
            $sheet->getColumnDimension('A')->setWidth(18);
            $sheet->getColumnDimension('B')->setWidth(26);
            $sheet->getColumnDimension('C')->setWidth(14);
            $sheet->getColumnDimension('D')->setWidth(14);
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
