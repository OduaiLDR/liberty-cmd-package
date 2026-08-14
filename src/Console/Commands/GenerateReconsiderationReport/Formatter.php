<?php

namespace Cmd\Reports\Console\Commands\GenerateReconsiderationReport;

use Cmd\Reports\Services\DBConnector;
use Cmd\Reports\Services\EmailSenderService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
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
        $displaySource = $source === 'PLAW' ? 'Progress Law' : $source;
        $filename = 'Reconsideration Report - '.$displaySource.' - '.$now->format('m-d-Y').'.xlsx';
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

        $displaySource = $source === 'PLAW' ? 'Progress Law' : $source;
        $email = new EmailSenderService;
        $subject = $this->emailSubject($company);
        $body = 'Please see the attached Reconsideration Report for '.$displaySource.'.';

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
        $data = [self::DROPPED_HEADERS];
        foreach ($rows as $row) {
            $data[] = [
                (string) ($row['id'] ?? ''),
                (string) ($row['client'] ?? ''),
                $this->excelDateValue((string) ($row['enrolled_date'] ?? '')),
                $this->excelDateValue((string) ($row['dropped_date'] ?? '')),
                (string) ($row['dropped_by'] ?? ''),
                (string) ($row['dropped_reason'] ?? ''),
                (float) ($row['enrolled_debt'] ?? 0),
            ];
        }
        $this->fromArrayPreserveIdColumn($sheet, $data);
        $this->styleHeader($sheet, 'A1:G1');

        $last = max(1, count($data));
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
        $data = [self::RECON_HEADERS];
        foreach ($rows as $row) {
            $data[] = [
                (string) ($row['id'] ?? ''),
                (string) ($row['client'] ?? ''),
                $this->excelDateValue((string) ($row['enrolled_date'] ?? '')),
                $this->excelDateValue((string) ($row['dropped_date'] ?? '')),
                (string) ($row['dropped_by'] ?? ''),
                (string) ($row['dropped_reason'] ?? ''),
                (float) ($row['enrolled_debt'] ?? 0),
                (string) ($row['active_status'] ?? ''),
                (string) ($row['current_status'] ?? ''),
                $this->excelDateValue((string) ($row['status_date'] ?? '')),
                (string) ($row['last_status_by'] ?? ''),
                (string) ($row['retention_agent'] ?? ''),
                (string) ($row['reason_for_request'] ?? ''),
                (string) ($row['retention_immediate_results'] ?? ''),
                (string) ($row['assigned_to'] ?? ''),
                (string) ($row['cancel_request_date'] ?? ''),
            ];
        }
        $this->fromArrayPreserveIdColumn($sheet, $data);
        $this->styleHeader($sheet, 'A1:P1');

        $last = max(1, count($data));
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
        $data = [['CONTACT_ID', 'STATUS', 'STATUS_DATE']];
        foreach ($rows as $row) {
            $data[] = [
                (string) ($row['contact_id'] ?? ''),
                (string) ($row['status'] ?? ''),
                $this->excelDateValue((string) ($row['status_date'] ?? '')),
            ];
        }
        $this->fromArrayPreserveIdColumn($sheet, $data);
        $this->styleHeader($sheet, 'A1:C1');
        $last = max(1, count($data));
        if ($last >= 2) {
            $sheet->getStyle("C2:C{$last}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_DATE_XLSX14);
        }
        $this->finishSheet($sheet, "A1:C{$last}", 3);
    }

    /** @param list<array<string,mixed>> $rows */
    private function fillCurrentStatus(Worksheet $sheet, array $rows): void
    {
        $sheet->setShowGridlines(false);
        $data = [['CONTACT_ID', 'ENROLLED_BY', 'TITLE', 'STATUS_DATE']];
        foreach ($rows as $row) {
            $data[] = [
                (string) ($row['CONTACT_ID'] ?? ''),
                (string) ($row['ENROLLED_BY'] ?? ''),
                (string) ($row['TITLE'] ?? ''),
                $this->excelDateValue((string) ($row['STATUS_DATE'] ?? '')),
            ];
        }
        $this->fromArrayPreserveIdColumn($sheet, $data);
        $this->styleHeader($sheet, 'A1:D1');

        $last = max(1, count($data));
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

        $counts = $this->tallyDroppedByReason($rows, $months);
        $r = 2;
        foreach (self::REASON_LIST as $reason) {
            $sheet->setCellValue("A{$r}", $reason);
            foreach ($months as $i => $month) {
                $col = Coordinate::stringFromColumnIndex($i + 2);
                $sheet->setCellValue("{$col}{$r}", $counts[$reason][$i] ?? 0);
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

        [$agentNames, $stats] = $this->tallyDroppedByAgent($rows, $months);

        $r = 2;
        foreach ($agentNames as $agent) {
            $sheet->setCellValue("A{$r}", $agent);
            foreach ($months as $i => $month) {
                [$countCol, $sumCol] = $pairs[$i];
                $sheet->setCellValue("{$countCol}{$r}", $stats[$agent][$i][0] ?? 0);
                $sheet->setCellValue("{$sumCol}{$r}", $stats[$agent][$i][1] ?? 0.0);
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

        [$agentNames, $stats] = $this->tallyReconSummary($rows, $months);

        $r = 2;
        foreach ($agentNames as $agent) {
            $sheet->setCellValue("A{$r}", $agent);
            foreach ($months as $i => $month) {
                [$countCol, $sumCol] = $pairs[$i];
                $sheet->setCellValue("{$countCol}{$r}", $stats[$agent][$i][0] ?? 0);
                $sheet->setCellValue("{$sumCol}{$r}", $stats[$agent][$i][1] ?? 0.0);
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

        $matrix = $this->tallyDroppedDetail($rows, $months);

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

    /**
     * @param  list<array<string,mixed>>  $rows
     * @param  list<string>  $months
     * @return array<string, array<int, int>>
     */
    private function tallyDroppedByReason(array $rows, array $months): array
    {
        $ranges = $this->monthRanges($months);
        $monthCount = count($months);
        $counts = [];
        foreach (self::REASON_LIST as $reason) {
            $counts[$reason] = array_fill(0, $monthCount, 0);
        }
        foreach ($rows as $row) {
            $reason = (string) ($row['dropped_reason'] ?? '');
            if (! isset($counts[$reason])) {
                continue;
            }
            $mi = $this->monthIndex((string) ($row['dropped_date'] ?? ''), $ranges);
            if ($mi === null) {
                continue;
            }
            $counts[$reason][$mi]++;
        }

        return $counts;
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @param  list<string>  $months
     * @return array{0:list<string>,1:array<string, array<int, array{0:int,1:float}>>}
     */
    private function tallyDroppedByAgent(array $rows, array $months): array
    {
        $ranges = $this->monthRanges($months);
        $monthCount = count($months);
        $names = [];
        $rawStats = [];
        foreach ($rows as $row) {
            $rawAgent = (string) ($row['dropped_by'] ?? '');
            $trimmed = trim($rawAgent);
            if ($trimmed !== '') {
                $names[$trimmed] = true;
            }
            $mi = $this->monthIndex((string) ($row['dropped_date'] ?? ''), $ranges);
            if ($mi === null) {
                continue;
            }
            if (! isset($rawStats[$rawAgent])) {
                $rawStats[$rawAgent] = array_fill(0, $monthCount, [0, 0.0]);
            }
            $rawStats[$rawAgent][$mi][0]++;
            $rawStats[$rawAgent][$mi][1] += (float) ($row['enrolled_debt'] ?? 0);
        }

        $agentNames = array_keys($names);
        sort($agentNames, SORT_NATURAL | SORT_FLAG_CASE);

        $stats = [];
        foreach ($agentNames as $agent) {
            $stats[$agent] = $rawStats[$agent] ?? array_fill(0, $monthCount, [0, 0.0]);
        }

        return [$agentNames, $stats];
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @param  list<string>  $months
     * @return array{0:list<string>,1:array<string, array<int, array{0:int,1:float}>>}
     */
    private function tallyReconSummary(array $rows, array $months): array
    {
        $ranges = $this->monthRanges($months);
        $monthCount = count($months);
        $names = [];
        $rawStats = [];
        foreach ($rows as $row) {
            $rawAgent = (string) ($row['last_status_by'] ?? '');
            $trimmed = trim($rawAgent);
            if ($trimmed !== '') {
                $names[$trimmed] = true;
            }
            if (strcasecmp((string) ($row['active_status'] ?? ''), 'Active') !== 0) {
                continue;
            }
            if ((string) ($row['current_status'] ?? '') === 'Enrolled (Reconsideration Pending)') {
                continue;
            }
            $mi = $this->monthIndex((string) ($row['status_date'] ?? ''), $ranges);
            if ($mi === null) {
                continue;
            }
            if (! isset($rawStats[$rawAgent])) {
                $rawStats[$rawAgent] = array_fill(0, $monthCount, [0, 0.0]);
            }
            $rawStats[$rawAgent][$mi][0]++;
            $rawStats[$rawAgent][$mi][1] += (float) ($row['enrolled_debt'] ?? 0);
        }

        $agentNames = array_keys($names);
        sort($agentNames, SORT_NATURAL | SORT_FLAG_CASE);

        $stats = [];
        foreach ($agentNames as $agent) {
            $stats[$agent] = $rawStats[$agent] ?? array_fill(0, $monthCount, [0, 0.0]);
        }

        return [$agentNames, $stats];
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @param  list<string>  $months
     * @return list<array{agent:string, reason:string, counts:array<int,int>}>
     */
    private function tallyDroppedDetail(array $rows, array $months): array
    {
        $ranges = $this->monthRanges($months);
        $monthCount = count($months);
        $agentNames = [];
        $reasonNames = [];
        $rawCounts = [];
        foreach ($rows as $row) {
            $rawAgent = (string) ($row['dropped_by'] ?? '');
            $rawReason = (string) ($row['dropped_reason'] ?? '');
            $trimmedAgent = trim($rawAgent);
            $trimmedReason = trim($rawReason);
            if ($trimmedAgent !== '') {
                $agentNames[$trimmedAgent] = true;
            }
            if ($trimmedReason !== '') {
                $reasonNames[$trimmedReason] = true;
            }
            $mi = $this->monthIndex((string) ($row['enrolled_date'] ?? ''), $ranges);
            if ($mi === null) {
                continue;
            }
            $key = $rawAgent."\0".$rawReason;
            if (! isset($rawCounts[$key])) {
                $rawCounts[$key] = array_fill(0, $monthCount, 0);
            }
            $rawCounts[$key][$mi]++;
        }

        $agents = array_keys($agentNames);
        $reasons = array_keys($reasonNames);
        sort($agents, SORT_NATURAL | SORT_FLAG_CASE);
        sort($reasons, SORT_NATURAL | SORT_FLAG_CASE);

        $matrix = [];
        foreach ($agents as $agent) {
            foreach ($reasons as $reason) {
                $counts = $rawCounts[$agent."\0".$reason] ?? array_fill(0, $monthCount, 0);
                $total = 0;
                foreach ($counts as $mi => $c) {
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

        return $matrix;
    }

    /**
     * @param  list<string>  $months
     * @return list<array{0:string,1:string}>
     */
    private function monthRanges(array $months): array
    {
        $ranges = [];
        foreach ($months as $month) {
            $ranges[] = $this->monthRange($month);
        }

        return $ranges;
    }

    /**
     * @param  list<array{0:string,1:string}>  $ranges
     */
    private function monthIndex(string $ymd, array $ranges): ?int
    {
        if ($ymd === '') {
            return null;
        }
        $d = substr($ymd, 0, 10);
        foreach ($ranges as $i => [$start, $end]) {
            if ($d >= $start && $d <= $end) {
                return $i;
            }
        }

        return null;
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

    private function excelDateValue(string $ymd): mixed
    {
        if ($ymd === '') {
            return '';
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($ymd, 0, 10));
        if ($date !== false) {
            return ExcelDate::PHPToExcel($date);
        }

        return $ymd;
    }

    /**
     * Write a grid in one shot, but keep column A (contact IDs) as Excel strings
     * so large IDs are not coerced to numbers.
     *
     * @param  list<list<mixed>>  $rows
     */
    private function fromArrayPreserveIdColumn(Worksheet $sheet, array $rows): void
    {
        $previous = Cell::getValueBinder();
        Cell::setValueBinder(new class extends DefaultValueBinder
        {
            public function bindValue(Cell $cell, $value): bool
            {
                if ($cell->getColumn() === 'A' && $cell->getRow() >= 2) {
                    $cell->setValueExplicit((string) $value, DataType::TYPE_STRING);

                    return true;
                }

                return parent::bindValue($cell, $value);
            }
        });
        try {
            $sheet->fromArray($rows, null, 'A1', true);
        } finally {
            Cell::setValueBinder($previous);
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

    private function finishSheet(Worksheet $sheet, string $range, int $cols, bool $autoSize = false): void
    {
        $sheet->getStyle($range)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle($range)->getFont()->setName('Calibri')->setSize(9);
        for ($c = 1; $c <= $cols; $c++) {
            $dim = $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($c));
            if ($autoSize) {
                $dim->setAutoSize(true);
            } else {
                $dim->setWidth(18);
            }
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

    private function companyDisplayName(string $company): string
    {
        return $company === 'PLAW' ? 'Progress Law' : 'LDR';
    }

    private function emailSubject(string $company): string
    {
        return sprintf(
            'Reconsideration Report - %s - %s',
            $this->companyDisplayName($company),
            Carbon::now(self::REPORT_TIMEZONE)->format('m/d/Y')
        );
    }

    private function truncateSheetTitle(string $title): string
    {
        return mb_substr($title, 0, 31);
    }
}
