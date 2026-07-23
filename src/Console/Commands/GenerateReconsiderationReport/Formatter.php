<?php

namespace Cmd\Reports\Console\Commands\GenerateReconsiderationReport;

use Cmd\Reports\Services\DBConnector;
use Cmd\Reports\Services\EmailSenderService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class Formatter
{
    private const REPORT_TIMEZONE = 'America/Los_Angeles';

    private const SOURCES = ['LDR', 'PLAW'];

    private const DROPPED_HEADERS = [
        'ID',
        'CLIENT',
        'ENROLLED_DATE',
        'DROPPED_DATE',
        'DROPPED_BY',
        'DROPPED_REASON',
        'ENROLLED_DEBT',
    ];

    private const RECON_HEADERS = [
        'ID',
        'CLIENT',
        'ENROLLED_DATE',
        'DROPPED_DATE',
        'DROPPED_BY',
        'DROPPED_REASON',
        'ENROLLED_DEBT',
        'ACTIVE_STATUS',
        'CURRENT_STATUS',
        'STATUS_DATE',
        'LAST_STATUS_BY',
        'RETENTION_AGENT',
        'REASON_FOR_REQUEST',
        'RETENTION_IMMEDIATE_RESULTS',
        'ASSIGNED_TO',
        'CANCEL_REQUEST_DATE',
    ];

    private const REASON_LIST = [
        "Can't Afford Program",
        'Client Deceased',
        'Did not understand program',
        'Dissatisfied - No Contact',
        'Dissatisfied -Service / Performance',
        'Does not want credit affected',
        'Family Assistance paying off debts',
        'Filing Bankruptcy',
        'Force Cancel/Cannot Contact',
        'Negotiating Independently',
        'Other',
        'Personal hardships preventing payment commitment',
        'Reconsidered/Changed Mind',
        'Retained Attorney',
        'Unable to Resolve NSF',
        'Unknown',
        'Wants to continue using cards',
        'Went a different route or alternative solution',
        'Went With Competitor',
    ];

    /**
     * @param  array{
     *   dropped_clients:list<array<string,mixed>>,
     *   reconsideration_clients:list<array<string,mixed>>,
     *   reconsideration_pending:list<array<string,mixed>>,
     *   current_status_1:list<array<string,mixed>>,
     *   current_status_2:list<array<string,mixed>>,
     *   months:list<string>
     * }  $data
     * @return array{filename:string,path:string}
     */
    public function buildWorkbook(array $data, string $source): array
    {
        $source = $this->normalizeSource($source);
        $months = $data['months'] ?? $this->defaultMonths();

        $spreadsheet = new Spreadsheet;
        $spreadsheet->removeSheetByIndex(0);

        $droppedSheet = $spreadsheet->createSheet();
        $droppedSheet->setTitle($this->truncateSheetTitle('Dropped Clients'));
        $this->fillDroppedClients($droppedSheet, $data['dropped_clients'] ?? []);

        $reconSheet = $spreadsheet->createSheet();
        $reconSheet->setTitle($this->truncateSheetTitle('Reconsideration Clients'));
        $this->fillReconsiderationClients($reconSheet, $data['reconsideration_clients'] ?? []);

        $pendingSheet = $spreadsheet->createSheet();
        $pendingSheet->setTitle($this->truncateSheetTitle('Reconsideration Pending'));
        $this->fillPending($pendingSheet, $data['reconsideration_pending'] ?? []);
        $pendingSheet->setSheetState(Worksheet::SHEETSTATE_HIDDEN);

        $status1 = $spreadsheet->createSheet();
        $status1->setTitle($this->truncateSheetTitle('Current Status 1'));
        $this->fillCurrentStatus($status1, $data['current_status_1'] ?? []);
        $status1->setSheetState(Worksheet::SHEETSTATE_HIDDEN);

        $status2 = $spreadsheet->createSheet();
        $status2->setTitle($this->truncateSheetTitle('Current Status 2'));
        $this->fillCurrentStatus($status2, $data['current_status_2'] ?? []);
        $status2->setSheetState(Worksheet::SHEETSTATE_HIDDEN);

        $byReason = $spreadsheet->createSheet();
        $byReason->setTitle($this->truncateSheetTitle('Dropped By Reason'));
        $this->fillDroppedByReason($byReason, $data['dropped_clients'] ?? [], $months);

        $byAgent = $spreadsheet->createSheet();
        $byAgent->setTitle($this->truncateSheetTitle('Dropped By Agent'));
        $this->fillDroppedByAgent($byAgent, $data['dropped_clients'] ?? [], $months);

        $summary = $spreadsheet->createSheet();
        $summary->setTitle($this->truncateSheetTitle('Reconsideration Summary'));
        $this->fillReconsiderationSummary($summary, $data['reconsideration_clients'] ?? [], $months);

        $detail = $spreadsheet->createSheet();
        $detail->setTitle($this->truncateSheetTitle('Dropped Detail Report'));
        $this->fillDroppedDetail($detail, $data['reconsideration_clients'] ?? [], $months);

        $spreadsheet->setActiveSheetIndex(0);

        $now = Carbon::now(self::REPORT_TIMEZONE);
        $filename = 'Reconsideration Report - '.$source.' - '.$now->format('m-d-Y').'.xlsx';
        $slug = strtolower($source);
        $path = storage_path(
            'app/reconsideration-'.$slug.'-'.$now->format('Ymd-His').'-'.bin2hex(random_bytes(4)).'.xlsx'
        );

        try {
            $writer = new Xlsx($spreadsheet);
            $writer->setPreCalculateFormulas(false);
            $writer->save($path);
        } finally {
            $spreadsheet->disconnectWorksheets();
        }

        return [
            'filename' => $filename,
            'path' => $path,
        ];
    }

    public function sendReport(
        DBConnector $connector,
        string $path,
        string $filename,
        string $source,
        string $company,
        ?Command $console = null
    ): bool {
        $source = $this->normalizeSource($source);
        $company = $this->normalizeSource($company);

        if (! is_file($path) || ! is_readable($path)) {
            Log::warning('GenerateReconsiderationReport: report file missing/unreadable.', [
                'path' => $path,
                'source' => $source,
            ]);
            $console?->warn("[WARN] {$source} report not sent (file missing/unreadable).");

            return false;
        }

        $bytes = file_get_contents($path);
        if ($bytes === false || $bytes === '') {
            Log::warning('GenerateReconsiderationReport: failed to read report file.', [
                'path' => $path,
                'source' => $source,
            ]);
            $console?->warn("[WARN] {$source} report not sent (could not read file).");

            return false;
        }

        $attachments = [[
            'name' => $filename,
            'contentType' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'contentBytes' => base64_encode($bytes),
        ]];

        $email = new EmailSenderService;
        $subject = 'Reconsideration Report - '.Carbon::now(self::REPORT_TIMEZONE)->format('m/d/Y');
        $body = 'Please see the attached Reconsideration Report for '.$source.'.';

        $sent = $email->sendMailUsingTblReports(
            $connector,
            [
                'Reconsideration Report',
            ],
            [$company],
            $subject,
            $body,
            $attachments,
            false,
            true
        );

        if ($console) {
            if ($sent) {
                $console->info("[INFO] {$source} Reconsideration report sent.");
            } else {
                $console->warn("[WARN] {$source} Reconsideration report not sent (no company recipients or send failed).");
            }
        } elseif (! $sent) {
            Log::warning('GenerateReconsiderationReport: failed to send email.', [
                'source' => $source,
                'company' => $company,
            ]);
        }

        return $sent;
    }

    /** @param list<array<string,mixed>> $rows */
    private function fillDroppedClients(Worksheet $sheet, array $rows): void
    {
        $sheet->setShowGridlines(false);
        foreach (self::DROPPED_HEADERS as $i => $header) {
            $sheet->setCellValueByColumnAndRow($i + 1, 1, $header);
        }
        $this->styleHeader($sheet, 'A1:G1');

        $r = 2;
        foreach ($rows as $row) {
            $sheet->setCellValueExplicit("A{$r}", (string) ($row['id'] ?? ''), DataType::TYPE_STRING);
            $sheet->setCellValue("B{$r}", (string) ($row['client'] ?? ''));
            $this->setDateCell($sheet, "C{$r}", (string) ($row['enrolled_date'] ?? ''));
            $this->setDateCell($sheet, "D{$r}", (string) ($row['dropped_date'] ?? ''));
            $sheet->setCellValue("E{$r}", (string) ($row['dropped_by'] ?? ''));
            $sheet->setCellValue("F{$r}", (string) ($row['dropped_reason'] ?? ''));
            $sheet->setCellValue("G{$r}", (float) ($row['enrolled_debt'] ?? 0));
            $r++;
        }

        $last = max(1, $r - 1);
        if ($last >= 2) {
            $sheet->getStyle("C2:D{$last}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_DATE_XLSX14);
            $sheet->getStyle("G2:G{$last}")->getNumberFormat()->setFormatCode('$#,##0');
        }
        $this->finishSheet($sheet, "A1:G{$last}", 7);
        $sheet->freezePane('A2');
    }

    /** @param list<array<string,mixed>> $rows */
    private function fillReconsiderationClients(Worksheet $sheet, array $rows): void
    {
        $sheet->setShowGridlines(false);
        foreach (self::RECON_HEADERS as $i => $header) {
            $sheet->setCellValueByColumnAndRow($i + 1, 1, $header);
        }
        $this->styleHeader($sheet, 'A1:P1');

        $r = 2;
        foreach ($rows as $row) {
            $sheet->setCellValueExplicit("A{$r}", (string) ($row['id'] ?? ''), DataType::TYPE_STRING);
            $sheet->setCellValue("B{$r}", (string) ($row['client'] ?? ''));
            $this->setDateCell($sheet, "C{$r}", (string) ($row['enrolled_date'] ?? ''));
            $this->setDateCell($sheet, "D{$r}", (string) ($row['dropped_date'] ?? ''));
            $sheet->setCellValue("E{$r}", (string) ($row['dropped_by'] ?? ''));
            $sheet->setCellValue("F{$r}", (string) ($row['dropped_reason'] ?? ''));
            $sheet->setCellValue("G{$r}", (float) ($row['enrolled_debt'] ?? 0));
            $sheet->setCellValue("H{$r}", (string) ($row['active_status'] ?? ''));
            $sheet->setCellValue("I{$r}", (string) ($row['current_status'] ?? ''));
            $this->setDateCell($sheet, "J{$r}", (string) ($row['status_date'] ?? ''));
            $sheet->setCellValue("K{$r}", (string) ($row['last_status_by'] ?? ''));
            $sheet->setCellValue("L{$r}", (string) ($row['retention_agent'] ?? ''));
            $sheet->setCellValue("M{$r}", (string) ($row['reason_for_request'] ?? ''));
            $sheet->setCellValue("N{$r}", (string) ($row['retention_immediate_results'] ?? ''));
            $sheet->setCellValue("O{$r}", (string) ($row['assigned_to'] ?? ''));
            $sheet->setCellValue("P{$r}", (string) ($row['cancel_request_date'] ?? ''));
            $r++;
        }

        $last = max(1, $r - 1);
        if ($last >= 2) {
            $sheet->getStyle("C2:D{$last}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_DATE_XLSX14);
            $sheet->getStyle("G2:G{$last}")->getNumberFormat()->setFormatCode('$#,##0');
            $sheet->getStyle("J2:J{$last}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_DATE_XLSX14);
        }
        $this->finishSheet($sheet, "A1:P{$last}", 16);
        $sheet->freezePane('A2');
    }

    /** @param list<array<string,mixed>> $rows */
    private function fillPending(Worksheet $sheet, array $rows): void
    {
        $sheet->setShowGridlines(false);
        $sheet->fromArray(['CONTACT_ID', 'STATUS', 'STATUS_DATE'], null, 'A1');
        $this->styleHeader($sheet, 'A1:C1');

        $r = 2;
        foreach ($rows as $row) {
            $sheet->setCellValueExplicit("A{$r}", (string) ($row['contact_id'] ?? ''), DataType::TYPE_STRING);
            $sheet->setCellValue("B{$r}", (string) ($row['status'] ?? ''));
            $this->setDateCell($sheet, "C{$r}", (string) ($row['status_date'] ?? ''));
            $r++;
        }
        $last = max(1, $r - 1);
        if ($last >= 2) {
            $sheet->getStyle("C2:C{$last}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_DATE_XLSX14);
        }
        $this->finishSheet($sheet, "A1:C{$last}", 3);
    }

    /** @param list<array<string,mixed>> $rows */
    private function fillCurrentStatus(Worksheet $sheet, array $rows): void
    {
        $sheet->setShowGridlines(false);
        $sheet->fromArray(['CONTACT_ID', 'ENROLLED_BY', 'TITLE', 'STATUS_DATE'], null, 'A1');
        $this->styleHeader($sheet, 'A1:D1');

        $r = 2;
        foreach ($rows as $row) {
            $sheet->setCellValueExplicit("A{$r}", (string) ($row['CONTACT_ID'] ?? ''), DataType::TYPE_STRING);
            $sheet->setCellValue("B{$r}", (string) ($row['ENROLLED_BY'] ?? ''));
            $sheet->setCellValue("C{$r}", (string) ($row['TITLE'] ?? ''));
            $this->setDateCell($sheet, "D{$r}", (string) ($row['STATUS_DATE'] ?? ''));
            $r++;
        }

        $last = max(1, $r - 1);
        if ($last >= 2) {
            $sheet->getStyle("D2:D{$last}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_DATE_XLSX14);
        }
        $this->finishSheet($sheet, "A1:D{$last}", 4);
    }

    /** @param list<array<string,mixed>> $rows @param list<string> $months */
    private function fillDroppedByReason(Worksheet $sheet, array $rows, array $months): void
    {
        $sheet->setShowGridlines(false);
        $sheet->setCellValue('A1', 'Reason');
        foreach ($months as $i => $month) {
            $col = Coordinate::stringFromColumnIndex($i + 2);
            $this->setMonthHeader($sheet, $col.'1', $month);
        }
        $this->styleHeader($sheet, 'A1:E1');

        $r = 2;
        foreach (self::REASON_LIST as $reason) {
            $sheet->setCellValue("A{$r}", $reason);
            foreach ($months as $i => $month) {
                $col = Coordinate::stringFromColumnIndex($i + 2);
                $sheet->setCellValue("{$col}{$r}", $this->countDroppedByReasonMonth($rows, $reason, $month));
            }
            $r++;
        }

        $last = $r - 1;
        $sheet->getStyle("A1:E{$last}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle("A1:E{$last}")->getFont()->setName('Calibri')->setSize(9);
        $sheet->getColumnDimension('A')->setWidth(50);
        foreach (range(2, 5) as $c) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setWidth(12);
        }
        $sheet->freezePane('A2');
    }

    /** @param list<array<string,mixed>> $rows @param list<string> $months */
    private function fillDroppedByAgent(Worksheet $sheet, array $rows, array $months): void
    {
        $sheet->setShowGridlines(false);
        $sheet->setCellValue('A1', 'Agent');

        // B-C, D-E, F-G, H-I month pairs
        $pairs = [['B', 'C'], ['D', 'E'], ['F', 'G'], ['H', 'I']];
        foreach ($months as $i => $month) {
            [$left, $right] = $pairs[$i];
            $this->setMonthHeader($sheet, "{$left}1", $month);
            $sheet->mergeCells("{$left}1:{$right}1");
        }
        $this->styleHeader($sheet, 'A1:I1');

        $agents = [];
        foreach ($rows as $row) {
            $agent = trim((string) ($row['dropped_by'] ?? ''));
            if ($agent !== '') {
                $agents[$agent] = true;
            }
        }
        $agentNames = array_keys($agents);
        sort($agentNames, SORT_NATURAL | SORT_FLAG_CASE);

        $r = 2;
        foreach ($agentNames as $agent) {
            $sheet->setCellValue("A{$r}", $agent);
            foreach ($months as $i => $month) {
                [$countCol, $sumCol] = $pairs[$i];
                [$count, $sum] = $this->countSumDroppedByAgentMonth($rows, $agent, $month);
                $sheet->setCellValue("{$countCol}{$r}", $count);
                $sheet->setCellValue("{$sumCol}{$r}", $sum);
            }
            $r++;
        }

        $last = max(1, $r - 1);
        if ($last >= 2) {
            foreach (['C', 'E', 'G', 'I'] as $col) {
                $sheet->getStyle("{$col}2:{$col}{$last}")->getNumberFormat()->setFormatCode('$#,##0');
            }
        }
        $sheet->getStyle("A1:I{$last}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle("A1:I{$last}")->getFont()->setName('Calibri')->setSize(9);
        $sheet->getColumnDimension('A')->setWidth(50);
        foreach (range(2, 9) as $c) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setWidth(12);
        }
        $sheet->freezePane('A2');
    }

    /** @param list<array<string,mixed>> $rows @param list<string> $months */
    private function fillReconsiderationSummary(Worksheet $sheet, array $rows, array $months): void
    {
        $sheet->setShowGridlines(false);
        $sheet->setCellValue('A1', 'Agent');
        $pairs = [['B', 'C'], ['D', 'E'], ['F', 'G'], ['H', 'I']];
        foreach ($months as $i => $month) {
            [$left, $right] = $pairs[$i];
            $this->setMonthHeader($sheet, "{$left}1", $month);
            $sheet->mergeCells("{$left}1:{$right}1");
        }
        $this->styleHeader($sheet, 'A1:I1');

        $agents = [];
        foreach ($rows as $row) {
            $agent = trim((string) ($row['last_status_by'] ?? ''));
            if ($agent !== '') {
                $agents[$agent] = true;
            }
        }
        $agentNames = array_keys($agents);
        sort($agentNames, SORT_NATURAL | SORT_FLAG_CASE);

        $r = 2;
        foreach ($agentNames as $agent) {
            $sheet->setCellValue("A{$r}", $agent);
            foreach ($months as $i => $month) {
                [$countCol, $sumCol] = $pairs[$i];
                [$count, $sum] = $this->countSumReconSummary($rows, $agent, $month);
                $sheet->setCellValue("{$countCol}{$r}", $count);
                $sheet->setCellValue("{$sumCol}{$r}", $sum);
            }
            $r++;
        }

        $last = max(1, $r - 1);
        if ($last >= 2) {
            foreach (['C', 'E', 'G', 'I'] as $col) {
                $sheet->getStyle("{$col}2:{$col}{$last}")->getNumberFormat()->setFormatCode('$#,##0');
            }
        }
        $sheet->getStyle("A1:I{$last}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle("A1:I{$last}")->getFont()->setName('Calibri')->setSize(9);
        $sheet->getColumnDimension('A')->setWidth(50);
        foreach (range(2, 9) as $c) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setWidth(12);
        }
        $sheet->freezePane('A2');
    }

    /** @param list<array<string,mixed>> $rows @param list<string> $months */
    private function fillDroppedDetail(Worksheet $sheet, array $rows, array $months): void
    {
        $sheet->setShowGridlines(false);
        $sheet->setCellValue('A1', 'Agent');
        $sheet->setCellValue('B1', 'Dropped Reason');
        foreach ($months as $i => $month) {
            $col = Coordinate::stringFromColumnIndex($i + 3);
            $this->setMonthHeader($sheet, $col.'1', $month);
        }
        $this->styleHeader($sheet, 'A1:F1');

        $agents = [];
        $reasons = [];
        foreach ($rows as $row) {
            $agent = trim((string) ($row['dropped_by'] ?? ''));
            $reason = trim((string) ($row['dropped_reason'] ?? ''));
            if ($agent !== '') {
                $agents[$agent] = true;
            }
            if ($reason !== '') {
                $reasons[$reason] = true;
            }
        }
        $agentNames = array_keys($agents);
        $reasonNames = array_keys($reasons);
        sort($agentNames, SORT_NATURAL | SORT_FLAG_CASE);
        sort($reasonNames, SORT_NATURAL | SORT_FLAG_CASE);

        $matrix = [];
        foreach ($agentNames as $agent) {
            foreach ($reasonNames as $reason) {
                $counts = [];
                $total = 0;
                foreach ($months as $mi => $month) {
                    $c = $this->countReconDroppedDetail($rows, $agent, $reason, $month);
                    $counts[$mi] = $c;
                    // VBA deletes rows where sum of first 3 month columns is 0 (C:E)
                    if ($mi < 3) {
                        $total += $c;
                    }
                }
                if ($total === 0) {
                    continue;
                }
                $matrix[] = ['agent' => $agent, 'reason' => $reason, 'counts' => $counts];
            }
        }

        $r = 2;
        $prevAgent = null;
        foreach ($matrix as $item) {
            if ($prevAgent !== null && $item['agent'] !== $prevAgent) {
                $r++; // blank separator row like VBA insert
            }
            $sheet->setCellValue("A{$r}", $item['agent']);
            $sheet->setCellValue("B{$r}", $item['reason']);
            foreach ($item['counts'] as $mi => $count) {
                $col = Coordinate::stringFromColumnIndex($mi + 3);
                $sheet->setCellValue("{$col}{$r}", $count);
            }
            $prevAgent = $item['agent'];
            $r++;
        }

        $last = max(1, $r - 1);
        $sheet->getStyle("A1:F{$last}")->getFont()->setName('Calibri')->setSize(9);
        $sheet->getColumnDimension('A')->setWidth(20);
        $sheet->getColumnDimension('B')->setWidth(60);
        foreach (range(3, 6) as $c) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setWidth(12);
        }
        // light borders for non-empty agent rows
        for ($i = 2; $i <= $last; $i++) {
            if (trim((string) $sheet->getCell("A{$i}")->getValue()) !== '') {
                $sheet->getStyle("A{$i}:F{$i}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
            }
        }
    }

    /** @param list<array<string,mixed>> $rows */
    private function countDroppedByReasonMonth(array $rows, string $reason, string $monthStart): int
    {
        [$start, $end] = $this->monthRange($monthStart);
        $n = 0;
        foreach ($rows as $row) {
            if ((string) ($row['dropped_reason'] ?? '') !== $reason) {
                continue;
            }
            $d = (string) ($row['dropped_date'] ?? '');
            if ($d !== '' && $d >= $start && $d <= $end) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array{0:int,1:float}
     */
    private function countSumDroppedByAgentMonth(array $rows, string $agent, string $monthStart): array
    {
        [$start, $end] = $this->monthRange($monthStart);
        $n = 0;
        $sum = 0.0;
        foreach ($rows as $row) {
            if ((string) ($row['dropped_by'] ?? '') !== $agent) {
                continue;
            }
            $d = (string) ($row['dropped_date'] ?? '');
            if ($d !== '' && $d >= $start && $d <= $end) {
                $n++;
                $sum += (float) ($row['enrolled_debt'] ?? 0);
            }
        }

        return [$n, $sum];
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array{0:int,1:float}
     */
    private function countSumReconSummary(array $rows, string $agent, string $monthStart): array
    {
        [$start, $end] = $this->monthRange($monthStart);
        $n = 0;
        $sum = 0.0;
        foreach ($rows as $row) {
            if ((string) ($row['last_status_by'] ?? '') !== $agent) {
                continue;
            }
            if (strcasecmp((string) ($row['active_status'] ?? ''), 'Active') !== 0) {
                continue;
            }
            if ((string) ($row['current_status'] ?? '') === 'Enrolled (Reconsideration Pending)') {
                continue;
            }
            $d = (string) ($row['status_date'] ?? '');
            if ($d !== '' && $d >= $start && $d <= $end) {
                $n++;
                $sum += (float) ($row['enrolled_debt'] ?? 0);
            }
        }

        return [$n, $sum];
    }

    /** @param list<array<string,mixed>> $rows */
    private function countReconDroppedDetail(array $rows, string $agent, string $reason, string $monthStart): int
    {
        [$start, $end] = $this->monthRange($monthStart);
        $n = 0;
        foreach ($rows as $row) {
            if ((string) ($row['dropped_by'] ?? '') !== $agent) {
                continue;
            }
            if ((string) ($row['dropped_reason'] ?? '') !== $reason) {
                continue;
            }
            // VBA COUNTIFS uses enrolled_date (column C)
            $d = (string) ($row['enrolled_date'] ?? '');
            if ($d !== '' && $d >= $start && $d <= $end) {
                $n++;
            }
        }

        return $n;
    }

    /** @return array{0:string,1:string} */
    private function monthRange(string $monthStart): array
    {
        $start = Carbon::parse($monthStart)->startOfMonth();
        $end = $start->copy()->endOfMonth();

        return [$start->toDateString(), $end->toDateString()];
    }

    /** @return list<string> */
    private function defaultMonths(): array
    {
        $today = Carbon::today(self::REPORT_TIMEZONE);
        $months = [];
        for ($i = 3; $i >= 0; $i--) {
            $months[] = $today->copy()->startOfMonth()->subMonthsNoOverflow($i)->toDateString();
        }

        return $months;
    }

    private function setMonthHeader(Worksheet $sheet, string $cell, string $monthStart): void
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $monthStart);
        if ($date !== false) {
            $sheet->setCellValue($cell, ExcelDate::PHPToExcel($date));
            $sheet->getStyle($cell)->getNumberFormat()->setFormatCode('mmm yyyy');
        } else {
            $sheet->setCellValue($cell, $monthStart);
        }
    }

    private function setDateCell(Worksheet $sheet, string $cell, string $ymd): void
    {
        if ($ymd === '') {
            $sheet->setCellValue($cell, '');

            return;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($ymd, 0, 10));
        if ($date !== false) {
            $sheet->setCellValue($cell, ExcelDate::PHPToExcel($date));
        } else {
            $sheet->setCellValue($cell, $ymd);
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

    private function finishSheet(Worksheet $sheet, string $range, int $cols): void
    {
        $sheet->getStyle($range)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle($range)->getFont()->setName('Calibri')->setSize(9);
        for ($c = 1; $c <= $cols; $c++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setAutoSize(true);
            $dim = $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($c));
            // Excel autofit happens on open; enforce VBA min width ~12 where possible later.
        }
    }

    private function normalizeSource(string $source): string
    {
        $source = strtoupper(trim($source));
        if (! in_array($source, self::SOURCES, true)) {
            throw new \InvalidArgumentException('Invalid source: '.$source);
        }

        return $source;
    }

    private function truncateSheetTitle(string $title): string
    {
        return mb_substr($title, 0, 31);
    }
}
