<?php

declare(strict_types=1);

namespace Cmd\Reports\Console\Commands\GenerateRetentionBonusCommission;

use Cmd\Reports\Services\CommissionCompanyMatch;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Shared\Date as XlDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class BonusFormatter
{
    private const HEADER_FILL = 'FF17853B';
    private const HEADER_FONT = 'FFFFFFFF';
    private const DATE_FMT    = 'mm/dd/yyyy';
    private const MONEY_FMT   = '$#,##0';
    private const MONEY2_FMT  = '$#,##0.00';
    private const PCT_FMT     = '0%';

    // Two different problems, two different fills, so a reviewer can tell them apart at a glance.
    // Missing data (pink) is a gap in the employee record; a company mismatch (solid red) means the
    // person is on the wrong report entirely.
    private const BLANK_FILL      = 'FFFFC7CE';
    private const BLANK_FONT      = 'FF9C0006';
    private const MISMATCH_FILL   = 'FFFF0000';
    private const MISMATCH_FONT   = 'FFFFFFFF';

    /** Case/space-insensitive key, so CRM and roster spellings of one name line up. */
    private static function nameKey(string $name): string
    {
        return strtolower(trim((string) preg_replace('/\s+/', ' ', trim($name))));
    }

    /**
     * Write one Agent Summary row and apply whichever highlight it earns.
     *
     * @param array{name:string,commission:float,location:string,company:string} $entry
     * @return int The next free row.
     */
    private function writeSummaryRow(Worksheet $summary, int $sr, array $entry, string $rosterSource): int
    {
        $summary->setCellValue("A{$sr}", $entry['name']);
        $summary->setCellValue("B{$sr}", $entry['commission']);
        $summary->setCellValue("C{$sr}", $entry['location']);
        $summary->setCellValue("D{$sr}", $entry['company']);

        $company  = trim((string) $entry['company']);
        $location = trim((string) $entry['location']);

        // Judged against the agent's OWN roster source. 'both' and unassigned agents never flag —
        // they are not pinned to a brand, so their company cannot contradict one.
        if ($rosterSource !== '' && CommissionCompanyMatch::mismatches($rosterSource, $company)) {
            // Jacob: "If you are in Progress Law and the company is Liberty or vise versa then flag
            // that red." Checked before the blank rule — a mismatch is the more serious finding,
            // and a mismatching company is by definition not blank.
            $summary->getStyle("A{$sr}:D{$sr}")->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setARGB(self::MISMATCH_FILL);
            $summary->getStyle("A{$sr}:D{$sr}")->getFont()->getColor()->setARGB(self::MISMATCH_FONT);
            $summary->getStyle("A{$sr}:D{$sr}")->getFont()->setBold(true);
        } elseif ($company === '' || $location === '') {
            // Call out agents with no company or location — without a company they cannot appear
            // on the Commission Review page (which is separated per company).
            $summary->getStyle("A{$sr}:D{$sr}")->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setARGB(self::BLANK_FILL);
            $summary->getStyle("A{$sr}:D{$sr}")->getFont()->getColor()->setARGB(self::BLANK_FONT);
        }

        return $sr + 1;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @param array<string,array{location:string,company:string}> $employeeMap  UPPER(agent_name) => employee data
     * @param string|null $agentFilter When set, only the agent's rows appear and the
     *                                 filename uses the agent name ("- <Agent Name>")
     * @param array<string,string> $rosterSources Normalised agent name => their roster source
     *                                            ('ldr' | 'plaw' | 'both'). The company-mismatch
     *                                            flag is judged per agent against this, so a 'both'
     *                                            agent never flags.
     * @param array<int,string>|null $rosterAgents The roster that defines the summary. Null when the
     *                                            roster was unreadable; the summary then falls back
     *                                            to the CRM agent names, as it always used to.
     * @param array<int,array{agent:string,amount:float}> $unassigned Earners not on the roster.
     */
    public function buildWorkbook(
        array $rows,
        string $source,
        string $start,
        string $end,
        array $employeeMap = [],
        ?string $agentFilter = null,
        array $rosterSources = [],
        ?array $rosterAgents = null,
        array $unassigned = []
    ): ?array {
        try {
            $sp    = new Spreadsheet();
            $sheet = $sp->getActiveSheet();
            $sheet->setTitle('Retention Data');
            $sheet->setShowGridlines(false);

            $headers = [
                'ID', 'Client', 'Retention Agent', 'Retention Date', 'Immediate Results',
                'Enrolled Debt', 'Reconsideration Date', 'Retained Date', 'Dropped Date',
                'First Payment Date', 'Cutoff', 'Payments', 'Agent', 'Commission Rate',
                'Violations', 'Retention Commission', 'Agent Deduction',
            ];

            $cols = array_merge(range('A', 'Z'), ['AA']);
            foreach ($headers as $i => $h) {
                if ($h !== null) {
                    $sheet->setCellValue($cols[$i] . '1', $h);
                }
            }
            $this->applyHeaderStyle($sheet, 'A1:Q1');

            // Payroll date = 15th of current month (e.g. 6/15/2026)
            $payrollDate = date('n/15/Y');

            $r = 2;
            foreach ($rows as $row) {
                $id        = $row['ID']                 ?? '';
                $agent     = $row['AGENT']              ?? '';
                $client    = $row['CLIENT']             ?? '';
                $comm      = $row['RETENTION_COMMISSION'] ?? 0;
                $deduction = $row['AGENT_DEDUCTION']    ?? '';

                $sheet->setCellValue("A$r", $id);
                $sheet->setCellValue("B$r", $client);
                $sheet->setCellValue("C$r", $row['RETENTION_AGENT']                 ?? '');
                $this->setDateCell($sheet, "D$r", $row['RETENTION_DATE']            ?? null);
                $sheet->setCellValue("E$r", $row['IMMEDIATE_RESULTS']               ?? '');
                $sheet->setCellValue("F$r", (float) ($row['ENROLLED_DEBT']          ?? 0));
                $this->setDateCell($sheet, "G$r", $row['RECONSIDERATION_DATE']      ?? null);
                $this->setDateCell($sheet, "H$r", $row['RETAINED_DATE']             ?? null);
                $this->setDateCell($sheet, "I$r", $row['DROPPED_DATE']              ?? null);
                $this->setDateCell($sheet, "J$r", $row['FIRST_PAYMENT_CLEARED_DATE'] ?? null);
                $this->setDateCell($sheet, "K$r", $row['CUTOFF']                    ?? null);
                $sheet->setCellValue("L$r", (int) ($row['PAYMENTS']                 ?? 0));
                $sheet->setCellValue("M$r", $agent);
                $sheet->setCellValue("N$r", $row['COMMISSION_RATE']                 ?? '');
                $sheet->setCellValue("O$r", $row['VIOLATIONS']                      ?? '');
                $sheet->setCellValue("P$r", $comm);
                $sheet->setCellValue("Q$r", $deduction === '' ? '' : (float) $deduction);
                $r++;
            }

            $last = max($r - 1, 1);

            foreach (['D', 'G', 'H', 'I', 'J', 'K'] as $c) {
                $sheet->getStyle("{$c}2:{$c}{$last}")->getNumberFormat()->setFormatCode(self::DATE_FMT);
            }
            $sheet->getStyle("F2:F{$last}")->getNumberFormat()->setFormatCode(self::MONEY_FMT);
            $sheet->getStyle("P2:Q{$last}")->getNumberFormat()->setFormatCode(self::MONEY2_FMT);
            $sheet->getStyle("O2:O{$last}")->getNumberFormat()->setFormatCode(self::PCT_FMT);

            if ($last > 1) {
                $sheet->getStyle("A1:Q{$last}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
            }
            foreach (range('A', 'Q') as $c) {
                $sheet->getColumnDimension($c)->setAutoSize(true);
            }
            $sheet->getStyle("A1:Q{$last}")->getFont()->setName('Calibri')->setSize(9);
            $sheet->freezePane('A2');
            $sheet->setSelectedCells('A1');

            // -- Summary sheet --------------------------------------------------
            $summary = $sp->createSheet();
            $summary->setTitle('Agent Summary');
            $summary->setShowGridlines(false);

            $summary->setCellValue('A1', 'Retention Agent');
            $summary->setCellValue('B1', 'Total Commission');
            $summary->setCellValue('C1', 'Location');
            $summary->setCellValue('D1', 'Company');
            $this->applyHeaderStyle($summary, 'A1:D1');

            // Commission earned this period, keyed on the CRM agent name.
            $commissionByAgent = [];
            foreach ($rows as $row) {
                $agentName = trim((string) ($row['RETENTION_AGENT'] ?? ''));
                if ($agentName === '') {
                    continue;
                }
                $key = self::nameKey($agentName);
                $commissionByAgent[$key]['name'] = $commissionByAgent[$key]['name'] ?? $agentName;
                $commissionByAgent[$key]['commission'] =
                    ($commissionByAgent[$key]['commission'] ?? 0.0) + (float) ($row['RETENTION_COMMISSION'] ?? 0);
            }

            // Who gets a summary row. The roster decides when we have one; without it we keep the
            // old behaviour of listing whoever the CRM named, so a broken roster degrades to the
            // familiar report rather than to a blank one.
            $summaryNames = [];
            if ($rosterAgents !== null) {
                foreach ($rosterAgents as $rosterName) {
                    $summaryNames[self::nameKey((string) $rosterName)] = trim((string) $rosterName);
                }
                // A per-agent copy is filtered to one person; keep it to that person even when
                // they are not on the roster, or their own workbook would come out empty.
                if ($agentFilter !== null) {
                    $filterKey = self::nameKey($agentFilter);
                    $summaryNames = isset($summaryNames[$filterKey])
                        ? [$filterKey => $summaryNames[$filterKey]]
                        : [$filterKey => trim($agentFilter)];
                }
            } else {
                foreach ($commissionByAgent as $key => $entry) {
                    $summaryNames[$key] = $entry['name'];
                }
            }

            $buildRow = static function (string $key, string $name) use ($commissionByAgent, $employeeMap): array {
                $lookup = strtoupper($name);
                return [
                    'name'       => $name,
                    'commission' => round((float) ($commissionByAgent[$key]['commission'] ?? 0.0), 2, PHP_ROUND_HALF_EVEN),
                    'location'   => (string) ($employeeMap[$lookup]['location'] ?? ''),
                    'company'    => (string) ($employeeMap[$lookup]['company'] ?? ''),
                ];
            };

            $summaryRows = [];
            foreach ($summaryNames as $key => $name) {
                $summaryRows[] = $buildRow($key, $name);
            }
            usort(
                $summaryRows,
                fn ($a, $b) => [$a['location'], $a['company'], $a['name']] <=> [$b['location'], $b['company'], $b['name']]
            );

            $sr = 2;
            foreach ($summaryRows as $entry) {
                $sr = $this->writeSummaryRow(
                    $summary, $sr, $entry,
                    $rosterSources[self::nameKey((string) $entry['name'])] ?? ''
                );
            }

            // Anyone with real commission this period who is not on the roster, listed separately
            // rather than mixed into the paid list (Jacob, 2026-09-03: "anyone we have data for
            // that is not on the roster is listed separately"). These are the same names that go
            // into the email body.
            if ($unassigned !== []) {
                $sr++;
                $summary->setCellValue("A{$sr}", 'Unassigned Agents');
                $summary->getStyle("A{$sr}")->getFont()->setBold(true);
                $summary->setCellValue("B{$sr}", 'Not on the retention roster');
                $summary->getStyle("B{$sr}")->getFont()->getColor()->setARGB('FF9C0006');
                $sr++;
                foreach ($unassigned as $entry) {
                    $sr = $this->writeSummaryRow(
                        $summary,
                        $sr,
                        $buildRow(self::nameKey((string) $entry['agent']), (string) $entry['agent']),
                        ''   // not on the roster, so not pinned to a brand — never company-flagged
                    );
                }
            }

            $lastSr = max($sr - 1, 1);
            $summary->getStyle("B2:B{$lastSr}")->getNumberFormat()->setFormatCode(self::MONEY2_FMT);
            if ($lastSr > 1) {
                $summary->getStyle("A1:D{$lastSr}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
            }
            foreach (['A', 'B', 'C', 'D'] as $c) {
                $summary->getColumnDimension($c)->setAutoSize(true);
            }
            $summary->getStyle("A1:D{$lastSr}")->getFont()->setName('Calibri')->setSize(9);

            // Legend — two fills that mean different things is only useful if the sheet says which
            // is which.
            $legendRow = $lastSr + 2;
            $summary->setCellValue("A{$legendRow}", 'Company does not match this report');
            $summary->getStyle("A{$legendRow}")->getFill()
                ->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::MISMATCH_FILL);
            $summary->getStyle("A{$legendRow}")->getFont()->getColor()->setARGB(self::MISMATCH_FONT);
            $summary->getStyle("A{$legendRow}")->getFont()->setBold(true);
            $summary->setCellValue("A" . ($legendRow + 1), 'Missing location or company');
            $summary->getStyle('A' . ($legendRow + 1))->getFill()
                ->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::BLANK_FILL);
            $summary->getStyle('A' . ($legendRow + 1))->getFont()->getColor()->setARGB(self::BLANK_FONT);
            $summary->getStyle("A{$legendRow}:A" . ($legendRow + 1))->getFont()->setName('Calibri')->setSize(9);

            $summary->freezePane('A2');
            $summary->setSelectedCells('A1');

            // Set active sheet back to data tab
            $sp->setActiveSheetIndex(0);

            $suffix   = $agentFilter !== null ? $this->safeFilenamePart($agentFilter) : 'All';
            $filename = "Retention Bonus Commission - {$source} - {$suffix}.xlsx";
            $path     = storage_path("app/{$filename}");
            (new Xlsx($sp))->save($path);

            return ['filename' => $filename, 'path' => $path];
        } catch (\Throwable $e) {
            Log::error('RetentionBonusCommissionFormatter::buildWorkbook failed', ['err' => $e->getMessage()]);
            return null;
        }
    }

    private function applyHeaderStyle(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'font'      => ['bold' => true, 'color' => ['argb' => self::HEADER_FONT]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::HEADER_FILL]],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        ]);
    }

    /** Strip characters that are illegal in filenames (Windows/Linux). */
    private function safeFilenamePart(string $name): string
    {
        return trim((string) preg_replace('/[\/\\\\:*?"<>|]/', '_', $name), " \t\n\r\0\x0B.");
    }

    private function setDateCell(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, string $cell, ?string $val): void
    {
        if ($val !== null && $val !== '' && strtotime($val) !== false) {
            $sheet->setCellValue($cell, XlDate::PHPToExcel(strtotime($val)));
            $sheet->getStyle($cell)->getNumberFormat()->setFormatCode(self::DATE_FMT);
        }
    }
}
