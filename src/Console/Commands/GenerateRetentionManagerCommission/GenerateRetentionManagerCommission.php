<?php

declare(strict_types=1);

namespace Cmd\Reports\Console\Commands\GenerateRetentionManagerCommission;

use Cmd\Reports\Services\DBConnector;
use Cmd\Reports\Services\EmailSenderService;
use Cmd\Reports\Services\RetentionCommissionReportBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as XlDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Manager-level retention commission workbooks from the Retention Manager Commission.xlsx rules.
 *
 * Supported reports:
 *   - Rama - Retention & NSF Manager
 *   - Nick - Retention Team Leader
 *   - Anthony - NSF Team Leader
 */
class GenerateRetentionManagerCommission extends Command
{
    protected $signature = 'reports:retention-manager-commission
                            {report=both : rama | nick | anthony | both | all | reconcile}
                            {period? : Period start date YYYY-MM-01; defaults to first day of last month}
                            {--jacob= : Path to Jacob Retention Manager Commission.xlsx for reconcile}
                            {--snapshot= : Directory with ldr_rows.json and plaw_rows.json from reports:retention-commission --save-snapshot}
                            {--retention-ldr= : Path to generated LDR Retention Commission Report xlsx}
                            {--retention-plaw= : Path to generated PLAW Retention Commission Report xlsx}
                            {--all-data-xlsx= : Test mode path to existing manager All Data / Rama tab workbook}
                            {--all-data-sheet= : Optional sheet name for --all-data-xlsx; defaults to first sheet}
                            {--email : Email generated reports using RETENTION_MANAGER_* env recipients}
                            {--email-dry-run : Show email routing but do not send}';

    protected $description = 'Generate Rama - Retention & NSF Manager, Nick - Retention Team Leader, and/or Anthony - NSF Team Leader workbooks.';

    private const SOURCE_CONFIG = [
        'ldr' => [
            'display'               => 'LDR',
            'custom_agent'          => 742096,
            'custom_date'           => 742101,
            'custom_results'        => 742105,
            'recon_status_id'       => 377650,
            'cancel_request_custom' => 742098,
            'agents' => [
                'Alice Kennedy', 'Andrea Mendoza', 'Gracia Rivera', 'Javier Deras',
                'John Pozuelos', 'Jose Melgar', 'Ken Smith', 'Marco Gonzalez',
                'Mike Wexford', 'Rick Mills',
            ],
            'excluded_agents' => ['WENDY KAZEM'],
        ],
        'plaw' => [
            'display'               => 'Progress Law',
            'custom_agent'          => 742097,
            'custom_date'           => 742102,
            'custom_results'        => 742106,
            'recon_status_id'       => 377687,
            'cancel_request_custom' => 742100,
            'agents' => [
                'Alexander Malone', 'Andrea Galvez', 'Edgar Gonzalez', 'Maria Lezana',
                'Melody Martinez', 'Nick Jones', 'Theo Clayton', 'Tony Walker',
                'Vicente Gonzalez', 'Alfred Brown',
            ],
            'excluded_agents' => [],
        ],
    ];

    private const TIER_AMOUNTS = [
        ['max' => 15000,       't1' => 2,  't2' => 5,  't3' => 10, 't4' => 40],
        ['max' => 30000,       't1' => 5,  't2' => 10, 't3' => 20, 't4' => 60],
        ['max' => 60000,       't1' => 15, 't2' => 30, 't3' => 40, 't4' => 80],
        ['max' => 100000,      't1' => 20, 't2' => 40, 't3' => 60, 't4' => 100],
        ['max' => PHP_INT_MAX, 't1' => 20, 't2' => 40, 't3' => 60, 't4' => 150],
    ];

    private const NICK_RATE_BANDS = [
        ['min' => 0.50, 'rates' => [8.0, 9.0, 10.0, 11.0]],
        ['min' => 0.40, 'rates' => [4.0, 5.0, 6.0, 7.0]],
        ['min' => 0.30, 'rates' => [0.9, 1.0, 2.0, 3.0]],
    ];

    /** @var array<string,string> */
    /**
     * NSF Team Leader sheet roster order (Jacob workbook NSF Team Leader - Anthony).
     * Row 13 is a second Lucas Wright placeholder (0/0/0) in the template.
     */
    private const ANTHONY_NSF_ROSTER = [
        'Anthony Clark',
        'June Brock',
        'Lucas Wright',
        'Marlon Solorzano',
        'Lilith Bailey',
        'Oaklynn Edwards',
        'Bill Mendoza',
        'Gabriel Yol',
        'Harry Gardner',
        'Jose Zuniga',
        'Luna Bradford',
        'Lucas Wright',
        'Samantha Lotz',
        'Timothy Phillips',
        'Katherine Caceres',
    ];

    private const RAMA_TRANCHE_ASSIGNMENTS = [
        'ALFRED BROWN' => 'NGF',
        'ALEXANDER MALONE' => 'NGF',
        'ANDREA GALVEZ' => 'NGF',
        'EDGAR GONZALEZ' => 'NGF',
        'MARIA LEZANA' => 'NGF',
        'MELODY MARTINEZ' => 'NGF',
        'NICK JONES' => 'NGF',
        'THEO CLAYTON' => 'NGF',
        'TONY WALKER' => 'NGF',
        'VICENTE GONZALEZ' => 'NGF',
    ];

    public function handle(): int
    {
        ini_set('memory_limit', '1024M');

        $report = strtolower((string) $this->argument('report'));
        if (!in_array($report, ['rama', 'nick', 'anthony', 'both', 'all', 'reconcile'], true)) {
            $this->error('Unknown report. Use rama, nick, anthony, both, all, or reconcile.');
            return Command::FAILURE;
        }

        $periodArg = (string) ($this->argument('period') ?? '');
        $startDate = $periodArg !== '' ? date('Y-m-01', strtotime($periodArg)) : date('Y-m-01', strtotime('first day of last month'));
        $endDate   = date('Y-m-t', strtotime($startDate));
        $this->info("[INFO] Period: {$startDate} → {$endDate}");

        if ($report === 'reconcile') {
            return $this->runReconcile($startDate, $endDate);
        }

        $needRetention = in_array($report, ['rama', 'nick', 'both', 'all'], true);
        $rows = [];
        if ($needRetention) {
            try {
                $rows = $this->buildAllData($startDate, $endDate);
            } catch (\Throwable $e) {
                $this->error('Build retention data failed: ' . $e->getMessage());
                Log::error('GenerateRetentionManagerCommission retention data failed', [
                    'exception' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                if (in_array($report, ['rama', 'nick', 'both'], true)) {
                    return Command::FAILURE;
                }
            }
        }

        if ($report === 'rama' || $report === 'both' || $report === 'all') {
            $this->generateReport('rama', 'Rama - Retention & NSF Manager', $rows, $startDate, $endDate);
        }
        if ($report === 'nick' || $report === 'both' || $report === 'all') {
            $this->generateReport('nick', 'Nick - Retention Team Leader', $rows, $startDate, $endDate);
        }
        if ($report === 'anthony' || $report === 'all') {
            $this->generateAnthonyReport('Anthony - NSF Team Leader', $startDate, $endDate);
        }

        return Command::SUCCESS;
    }

    /** @return array<int,array<string,mixed>> */
    private function buildAllData(string $startDate, string $endDate): array
    {
        $allDataXlsx = (string) ($this->option('all-data-xlsx') ?? '');
        if ($allDataXlsx !== '') {
            $sheetName = (string) ($this->option('all-data-sheet') ?? '');
            $this->info('[INFO] Loading existing manager first-tab data as All Data test source');
            $all = $this->loadManagerAllDataRowsFromXlsx($allDataXlsx, $sheetName !== '' ? $sheetName : null);
            $all = $this->filterRetentionRowsForPeriod($all, $startDate, $endDate);
            if (!$this->rowsAlreadyHaveBonusData($all)) {
                $this->applyTrancheAssignments($all);
            }
            $this->info('[INFO] Existing All Data rows after period filter: ' . count($all));

            return $all;
        }

        $ldrReport = (string) ($this->option('retention-ldr') ?? '');
        $plawReport = (string) ($this->option('retention-plaw') ?? '');

        if ($ldrReport !== '' || $plawReport !== '') {
            if ($ldrReport === '' || $plawReport === '') {
                throw new \InvalidArgumentException('Use both --retention-ldr=path and --retention-plaw=path.');
            }
            $this->info('[INFO] Loading generated retention report XLSX files as All Data');
            $all = array_merge(
                $this->loadRetentionReportRowsFromXlsx($ldrReport, 'LDR'),
                $this->loadRetentionReportRowsFromXlsx($plawReport, 'Progress Law')
            );
            $all = $this->filterRetentionRowsForPeriod($all, $startDate, $endDate);
            $all = $this->dedupeRetentionRowsByContactId($all);
            $this->sortRetentionRows($all);
            $this->applyTrancheAssignments($all);

            return $all;
        }

        $snapshot = (string) ($this->option('snapshot') ?? '');
        $builder = new RetentionCommissionReportBuilder();
        $all = $builder->buildAllDataForPeriod(
            $startDate,
            $endDate,
            $snapshot !== '' ? $snapshot : null,
            fn (string $m) => $this->info($m)
        );
        $this->applyTrancheAssignments($all);

        return $all;
    }

    /** @return array<int,array<string,mixed>> */
    private function loadManagerAllDataRowsFromXlsx(string $path, ?string $sheetName = null): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException("All Data workbook not found: {$path}");
        }

        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($path);
        $sheet = $sheetName !== null ? $spreadsheet->getSheetByName($sheetName) : null;
        $sheet ??= $spreadsheet->getSheet(0);
        if ($sheet === null) {
            throw new \RuntimeException('No worksheet found in All Data workbook.');
        }

        $highestRow = $sheet->getHighestDataRow();
        $highestCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
        $headerMap = [];
        for ($c = 1; $c <= $highestCol; $c++) {
            $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c) . '1';
            $header = trim((string) $sheet->getCell($cell)->getValue());
            if ($header !== '') {
                $headerMap[$c] = $this->managerHeaderToKey($header);
            }
        }

        $dateKeys = [
            'RETENTION_DATE' => true,
            'RECONSIDERATION_DATE' => true,
            'DROPPED_DATE' => true,
            'RETAINED_DATE' => true,
            'RETENTION_PAYMENT_DATE' => true,
            'CANCEL_REQUEST_DATE' => true,
            'CUT_OFF' => true,
        ];
        $numericKeys = [
            'ENROLLED_DEBT' => true,
            'CLEARED_PAYMENTS' => true,
            'T1' => true,
            'T2' => true,
            'T3' => true,
            'T4' => true,
        ];

        $rows = [];
        for ($r = 2; $r <= $highestRow; $r++) {
            $row = ['SOURCE' => 'Manager Source'];
            foreach ($headerMap as $c => $key) {
                if ($key === '') {
                    continue;
                }
                $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c) . (string) $r;
                $value = $sheet->getCell($cell)->getValue();
                if (isset($dateKeys[$key])) {
                    $value = $this->excelOrStringToDate($value);
                } elseif (isset($numericKeys[$key]) && $value !== null && $value !== '') {
                    $value = is_numeric($value) ? (float) $value : $value;
                } elseif ($key === 'MADE_CUT_OFF') {
                    $value = trim((string) $value) !== '';
                }
                $row[$key] = $value;
            }
            $id = trim((string) ($row['ID'] ?? ''));
            if ($id === '' || strcasecmp($id, 'ID') === 0) {
                continue;
            }
            $rows[] = $row;
        }

        $title = $sheet->getTitle();
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);
        $this->info("[INFO] Manager All Data rows loaded from '{$title}': " . count($rows));

        return $rows;
    }

    /** @return array<int,array<string,mixed>> */
    private function loadRetentionReportRowsFromXlsx(string $path, string $sourceDisplay): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException("Retention report file not found: {$path}");
        }

        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($path);
        $sheet = $spreadsheet->getSheetByName('Retention Commission Report') ?? $spreadsheet->getActiveSheet();
        $highestRow = $sheet->getHighestDataRow();
        $highestCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestDataColumn());

        $headerMap = [];
        for ($c = 1; $c <= $highestCol; $c++) {
            $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c) . '1';
            $header = trim((string) $sheet->getCell($cell)->getValue());
            if ($header !== '') {
                $headerMap[$c] = $this->retentionHeaderToKey($header);
            }
        }

        $dateKeys = [
            'RETENTION_DATE' => true,
            'RECONSIDERATION_DATE' => true,
            'DROPPED_DATE' => true,
            'RETAINED_DATE' => true,
            'RETENTION_PAYMENT_DATE' => true,
            'CANCEL_REQUEST_DATE' => true,
        ];
        $numericKeys = [
            'ENROLLED_DEBT' => true,
            'CLEARED_PAYMENTS' => true,
            'T1' => true,
            'T2' => true,
            'T3' => true,
            'T4' => true,
        ];

        $rows = [];
        for ($r = 2; $r <= $highestRow; $r++) {
            $row = ['SOURCE' => $sourceDisplay];
            foreach ($headerMap as $c => $key) {
                if ($key === '') {
                    continue;
                }
                $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c) . (string) $r;
                $value = $sheet->getCell($cell)->getValue();
                if (isset($dateKeys[$key])) {
                    $value = $this->excelOrStringToDate($value);
                } elseif (isset($numericKeys[$key]) && $value !== null && $value !== '') {
                    $value = is_numeric($value) ? (float) $value : $value;
                }
                $row[$key] = $value;
            }
            $id = trim((string) ($row['ID'] ?? ''));
            if ($id === '' || strcasecmp($id, 'ID') === 0) {
                continue;
            }
            $rows[] = $row;
        }

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);
        $this->info("[INFO] {$sourceDisplay} retention report rows loaded: " . count($rows));

        return $rows;
    }

    private function managerHeaderToKey(string $header): string
    {
        $base = $this->retentionHeaderToKey($header);
        if ($base !== '') {
            return $base;
        }

        return match (strtoupper(trim($header))) {
            'TRANCHE' => 'TRANCHE',
            'CUT OFF' => 'CUT_OFF',
            'MADE CUT OFF' => 'MADE_CUT_OFF',
            'BONUS' => 'BONUS_FLAG',
            default => '',
        };
    }

    private function retentionHeaderToKey(string $header): string
    {
        return match (strtoupper(trim($header))) {
            'ID' => 'ID',
            'CLIENT' => 'CLIENT',
            'RETENTION AGENT' => 'RETENTION_AGENT',
            'RETENTION DATE' => 'RETENTION_DATE',
            'IMMEDIATE RESULTS' => 'IMMEDIATE_RESULTS',
            'ENROLLED DEBT' => 'ENROLLED_DEBT',
            'CLEARED PAYMENTS' => 'CLEARED_PAYMENTS',
            'RECONSIDERATION DATE' => 'RECONSIDERATION_DATE',
            'DROPPED DATE' => 'DROPPED_DATE',
            'RETAINED DATE' => 'RETAINED_DATE',
            'RETENTION PAYMENT DATE' => 'RETENTION_PAYMENT_DATE',
            'RETENTION COMMISSION T1' => 'T1',
            'RETENTION COMMISSION T2' => 'T2',
            'RETENTION COMMISSION T3' => 'T3',
            'RETENTION COMMISSION T4' => 'T4',
            'CANCEL REQUEST DATE' => 'CANCEL_REQUEST_DATE',
            default => '',
        };
    }

    private function excelOrStringToDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (is_numeric($value)) {
            return XlDate::excelToDateTimeObject((float) $value)->format('Y-m-d');
        }

        return $this->toDate($value);
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function rowsAlreadyHaveBonusData(array $rows): bool
    {
        foreach ($rows as $row) {
            if ((string) $this->col($row, 'BONUS_FLAG', '') !== '' || (string) $this->col($row, 'TRANCHE', '') !== '') {
                return true;
            }
        }

        return false;
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function filterRetentionRowsForPeriod(array $rows, string $startDate, string $endDate): array
    {
        return array_values(array_filter($rows, function (array $row) use ($startDate, $endDate): bool {
            $cancelRequestDate = $this->toDate($this->col($row, 'CANCEL_REQUEST_DATE'));
            return $cancelRequestDate !== null && $cancelRequestDate >= $startDate && $cancelRequestDate <= $endDate;
        }));
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function dedupeRetentionRowsByContactId(array $rows): array
    {
        $byId = [];
        foreach ($rows as $row) {
            $id = (string) $this->col($row, 'ID', '');
            if ($id === '') {
                continue;
            }
            if (!isset($byId[$id])) {
                $byId[$id] = $row;
                continue;
            }
            $keepCancel = $this->toDate($this->col($byId[$id], 'CANCEL_REQUEST_DATE'));
            $newCancel = $this->toDate($this->col($row, 'CANCEL_REQUEST_DATE'));
            if ($newCancel !== null && ($keepCancel === null || $newCancel < $keepCancel)) {
                $byId[$id] = $row;
            }
        }

        return array_values($byId);
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function sortRetentionRows(array &$rows): void
    {
        usort($rows, function (array $a, array $b): int {
            $sourceOrder = ['LDR' => 0, 'PROGRESS LAW' => 1];
            return [
                $sourceOrder[strtoupper((string) $this->col($a, 'SOURCE', ''))] ?? 9,
                strtoupper((string) $this->col($a, 'RETENTION_AGENT', '')),
                (string) $this->col($a, 'ID', ''),
            ] <=> [
                $sourceOrder[strtoupper((string) $this->col($b, 'SOURCE', ''))] ?? 9,
                strtoupper((string) $this->col($b, 'RETENTION_AGENT', '')),
                (string) $this->col($b, 'ID', ''),
            ];
        });
    }

    private function generateReport(string $key, string $reportName, array $rows, string $startDate, string $endDate): void
    {
        try {
            $file = $this->buildWorkbook($key, $reportName, $rows, $startDate, $endDate);
            $this->info("[INFO] {$reportName}: {$file['filename']}");
            $this->info("[INFO] Saved: {$file['path']}");
            $this->maybeEmailManagerReport($key, $reportName, $file, $startDate, $endDate);
        } catch (\Throwable $e) {
            $this->error("{$reportName} failed: " . $e->getMessage());
            Log::error('GenerateRetentionManagerCommission report failed', [
                'report' => $reportName,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    private function generateAnthonyReport(string $reportName, string $startDate, string $endDate): void
    {
        try {
            $nsfRows = $this->fetchNsfRows($startDate, $endDate);
            $this->info("[INFO] NSF rows: " . count($nsfRows));
            $file = $this->buildAnthonyWorkbook($reportName, $nsfRows);
            $this->info("[INFO] {$reportName}: {$file['filename']}");
            $this->info("[INFO] Saved: {$file['path']}");
            $this->maybeEmailManagerReport('anthony', $reportName, $file, $startDate, $endDate);
        } catch (\Throwable $e) {
            $this->error("{$reportName} failed: " . $e->getMessage());
            Log::error('GenerateRetentionManagerCommission anthony failed', [
                'report' => $reportName,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    // ─── NSF Team Leader (Anthony) source config ──────────────────────────
    // Snowflake CONTACTS_USERFIELDS custom IDs that drive the NSF commission
    // "Data" sheet in Jacob's workbook. Ported from the VBA macro
    // `GenerateNSFCommissionReport` (Progress Law uses:
    //   AGENT=742135, NSF_RETURNED_DATE=742149, NSF_ACTION=742137,
    //   NSF_RECOUP_DATE=742147).
    // LDR custom IDs are inferred from the same -1 offset pattern used by the
    // lead-assignment code (LDR agent=742134 vs PLAW agent=742135).
    private const NSF_SOURCE_CONFIG = [
        'plaw' => [
            'agent'    => 742135,
            'returned' => 742149,
            'action'   => 742137,
            'recoup'   => 742147,
        ],
        'ldr' => [
            'agent'    => 742134,
            'returned' => 742148,
            'action'   => 742136,
            'recoup'   => 742146,
        ],
    ];

    /** @return array<int,array<string,mixed>> */
    private function fetchNsfRows(string $startDate, string $endDate): array
    {
        $endExclusive = date('Y-m-d', strtotime($endDate . ' +1 day'));
        $all = [];
        foreach (self::NSF_SOURCE_CONFIG as $source => $ids) {
            $this->info("[INFO] NSF source: {$source}");
            $sf = DBConnector::fromEnvironment($source);
            $rows = $this->fetchNsfCommissionRows($sf, $ids, $startDate, $endExclusive);
            $this->info("[INFO] [{$source}] NSF commission rows: " . count($rows));
            array_push($all, ...$rows);
        }
        return $all;
    }

    /** @param array<string,int> $ids */
    private function fetchNsfCommissionRows(DBConnector $sf, array $ids, string $startDate, string $endExclusive): array
    {
        $sql = "
            SELECT * FROM (
                SELECT
                    c.ID,
                    CU1.AGENT,
                    CU2.NSF_RETURNED_DATE,
                    CU3.NSF_ACTION,
                    CU4.NSF_RECOUP_DATE,
                    T.CLEARED_DATE,
                    T.RN
                FROM CONTACTS c
                LEFT JOIN (
                    SELECT CONTACT_ID, F_SHORTSTRING AS AGENT
                    FROM CONTACTS_USERFIELDS
                    WHERE CUSTOM_ID = {$ids['agent']}
                ) CU1 ON c.ID = CU1.CONTACT_ID
                LEFT JOIN (
                    SELECT CONTACT_ID, F_DATE AS NSF_RETURNED_DATE
                    FROM CONTACTS_USERFIELDS
                    WHERE CUSTOM_ID = {$ids['returned']}
                ) CU2 ON c.ID = CU2.CONTACT_ID
                LEFT JOIN (
                    SELECT CONTACT_ID, F_STRING AS NSF_ACTION
                    FROM CONTACTS_USERFIELDS
                    WHERE CUSTOM_ID = {$ids['action']}
                ) CU3 ON c.ID = CU3.CONTACT_ID
                LEFT JOIN (
                    SELECT CONTACT_ID, F_DATE AS NSF_RECOUP_DATE
                    FROM CONTACTS_USERFIELDS
                    WHERE CUSTOM_ID = {$ids['recoup']}
                ) CU4 ON c.ID = CU4.CONTACT_ID
                LEFT JOIN (
                    SELECT CONTACT_ID, CLEARED_DATE,
                           ROW_NUMBER() OVER (PARTITION BY CONTACT_ID ORDER BY PROCESS_DATE DESC) AS RN
                    FROM TRANSACTIONS
                    WHERE TRANS_TYPE = 'D'
                      AND CLEARED_DATE IS NOT NULL
                      AND RETURNED_DATE IS NULL
                ) T ON c.ID = T.CONTACT_ID
            )
            WHERE NSF_RETURNED_DATE >= '{$startDate}'
              AND NSF_RETURNED_DATE < '{$endExclusive}'
              AND RN = 1
        ";

        return $sf->query($sql)['data'] ?? [];
    }

    /**
     * Replicates the VBA "Valid Commission" formula:
     *   AND(MONTH(NSF_RETURNED_DATE)=MONTH(NSF_RECOUP_DATE),
     *       CLEARED_DATE <= DATE(YEAR(NSF_RETURNED_DATE), MONTH(NSF_RETURNED_DATE)+1, 5),
     *       CLEARED_DATE > NSF_RECOUP_DATE)
     */
    private function isValidCommission(?string $returned, ?string $recoup, ?string $cleared): bool
    {
        $returned = $this->forthToDate($returned);
        $recoup   = $this->forthToDate($recoup);
        $cleared  = $this->forthToDate($cleared);
        if ($returned === null || $recoup === null || $cleared === null) {
            return false;
        }

        // NSF_RECOUP_DATE same month+year as NSF_RETURNED_DATE
        if (date('Y-m', strtotime($returned)) !== date('Y-m', strtotime($recoup))) {
            return false;
        }

        // CLEARED_DATE <= 5th of next month after NSF_RETURNED_DATE
        $cutoff = date('Y-m-d', mktime(0, 0, 0, (int) date('m', strtotime($returned)) + 1, 5, (int) date('Y', strtotime($returned))));
        if ($cleared > $cutoff) {
            return false;
        }

        // CLEARED_DATE > NSF_RECOUP_DATE
        return $cleared > $recoup;
    }

    /** @param array<int,array<string,mixed>> $nsfRows */
    private function buildAnthonyWorkbook(string $reportName, array $nsfRows): array
    {
        $byAgent = [];
        foreach ($nsfRows as $row) {
            $agent = trim((string) ($row['AGENT'] ?? $row['agent'] ?? ''));
            if ($agent === '') {
                continue;
            }
            $key = $this->anthonyAgentKey($agent);
            if ($key === '') {
                continue;
            }
            if (!isset($byAgent[$key])) {
                $byAgent[$key] = ['assignments' => 0, 'actions' => 0, 'clears' => 0];
            }
            $byAgent[$key]['assignments']++;

            $action = trim((string) ($row['NSF_ACTION'] ?? $row['nsf_action'] ?? ''));
            if ($action !== '') {
                $byAgent[$key]['actions']++;
            }

            $valid = $this->isValidCommission(
                $row['NSF_RETURNED_DATE'] ?? $row['nsf_returned_date'] ?? null,
                $row['NSF_RECOUP_DATE'] ?? $row['nsf_recoup_date'] ?? null,
                $row['CLEARED_DATE'] ?? $row['cleared_date'] ?? null,
            );
            if ($valid) {
                $byAgent[$key]['clears']++;
            }
        }

        $sp = new Spreadsheet();
        $sheet = $sp->getActiveSheet();
        $sheet->setTitle('Anthony');
        $sheet->setShowGridlines(false);

        $sheet->fromArray([['NGO', 'Assignments', 'Actions', 'Ratio', 'Clears']], null, 'A1');
        $this->headerStyle($sheet, 'A1:E1');

        $rosterSeen = [];
        $dataRowEnd = 21;
        for ($r = 2; $r <= $dataRowEnd; $r++) {
            $agent = self::ANTHONY_NSF_ROSTER[$r - 2] ?? '';
            $sheet->setCellValue("A{$r}", $agent);
            $key = $this->anthonyAgentKey($agent);
            if ($key !== '' && !isset($rosterSeen[$key])) {
                $rosterSeen[$key] = true;
                $stats = $byAgent[$key] ?? null;
            } else {
                $stats = null;
            }
            if ($stats !== null) {
                $sheet->setCellValue("B{$r}", $stats['assignments']);
                $sheet->setCellValue("C{$r}", $stats['actions']);
                $sheet->setCellValue("D{$r}", $stats['assignments'] > 0 ? $stats['actions'] / $stats['assignments'] : 0);
                $sheet->setCellValue("E{$r}", $stats['clears']);
            } elseif ($agent !== '') {
                // Placeholder roster rows (e.g. Anthony Clark, duplicate Lucas Wright)
                $sheet->setCellValue("B{$r}", 0);
                $sheet->setCellValue("C{$r}", 0);
                $sheet->setCellValue("D{$r}", 0);
                $sheet->setCellValue("E{$r}", 0);
            }
            // Rows 17–21: name blank in template — leave B:E empty like Jacob
        }

        $totalRow = 22;
        $sheet->setCellValue("A{$totalRow}", 'Total');
        $sheet->setCellValue("B{$totalRow}", '=SUM(B2:B21)');
        $sheet->setCellValue("C{$totalRow}", '=SUM(C2:C21)');
        $sheet->setCellValue("D{$totalRow}", '=C22/B22');
        $sheet->setCellValue("E{$totalRow}", '=SUM(E2:E21)');
        $sheet->getStyle("A{$totalRow}:E{$totalRow}")->getFont()->setBold(true);

        // Tier table - matches Jacob's current workbook (NSF Team Leader sheet)
        // Ratio thresholds across columns I/J/K = 0.40 / 0.55 / 0.65
        // Clears thresholds down rows H8/H9/H10 = 200 / 300 / 500
        $sheet->setCellValue('H6', 'Commission Tiers');
        $sheet->fromArray([['', 0.40, 0.55, 0.65]], null, 'H7');
        $sheet->fromArray([[200, 0.0, 0.07, 0.40]], null, 'H8');
        $sheet->fromArray([[300, 0.0, 0.10, 0.50]], null, 'H9');
        $sheet->fromArray([[500, 0.0, 0.30, 0.70]], null, 'H10');

        $rosterKeys = [];
        foreach (self::ANTHONY_NSF_ROSTER as $name) {
            $k = $this->anthonyAgentKey($name);
            if ($k !== '') {
                $rosterKeys[$k] = true;
            }
        }
        $totalAssignments = 0;
        $totalActions = 0;
        $totalClears = 0;
        foreach ($byAgent as $key => $stats) {
            if (!isset($rosterKeys[$key])) {
                continue;
            }
            $totalAssignments += $stats['assignments'];
            $totalActions += $stats['actions'];
            $totalClears += $stats['clears'];
        }
        $ratioTotal = $totalAssignments > 0 ? $totalActions / $totalAssignments : 0.0;
        $clearsTotal = (float) $totalClears;
        $rate = $this->anthonyRate($ratioTotal, $clearsTotal);

        $sheet->setCellValue('H14', 'Clears');
        $sheet->setCellValue("I14", $clearsTotal);
        $sheet->setCellValue('H15', 'Rate');
        $sheet->setCellValue('I15', $rate);
        $sheet->setCellValue('H16', 'Commission');
        $sheet->setCellValue('I16', $clearsTotal * $rate);

        $sheet->getStyle("D2:D{$totalRow}")->getNumberFormat()->setFormatCode('0.00%');
        $sheet->getStyle("I14")->getNumberFormat()->setFormatCode('0');
        $sheet->getStyle("I16")->getNumberFormat()->setFormatCode('$#,##0.00');

        foreach (range('A', 'J') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        $sheet->freezePane('A2');

        $folder = $this->downloadReportFolder($reportName);
        if (!is_dir($folder)) {
            mkdir($folder, 0775, true);
        }
        $filename = "{$reportName}.xlsx";
        $path = $folder . DIRECTORY_SEPARATOR . $filename;
        (new Xlsx($sp))->save($path);

        return ['filename' => $filename, 'path' => $path];
    }

    private function anthonyAgentKey(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '';
        }

        return mb_strtoupper($name, 'UTF-8');
    }

    private function anthonyRate(float $ratio, float $clears): float
    {
        if ($ratio >= 0.55) {
            $col = 2;
        } elseif ($ratio >= 0.40) {
            $col = 1;
        } else {
            $col = 0;
        }

        if ($clears >= 500) {
            $row = 2;
        } elseif ($clears >= 300) {
            $row = 1;
        } elseif ($clears >= 200) {
            $row = 0;
        } else {
            return 0.0;
        }

        $grid = [
            [0.0,  0.07, 0.40], // 200+ clears
            [0.0,  0.10, 0.50], // 300+ clears
            [0.0,  0.30, 0.70], // 500+ clears
        ];

        return $grid[$row][$col];
    }

    /**
     * Forth CONTACTS_USERFIELDS.F_DATE values come back from Snowflake as
     * days-since-1970-01-01 integers (e.g. 20616 = 2026-06-21), not ISO
     * strings. Convert those to YYYY-MM-DD; passthrough everything else.
     */
    private function forthToDate(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            $n = (int) $value;
            if ($n >= 10000 && $n < 50000) {
                return date('Y-m-d', $n * 86400);
            }
            return $n > 0 ? date('Y-m-d', $n) : null;
        }
        $ts = strtotime((string) $value);
        return $ts === false ? null : date('Y-m-d', $ts);
    }

    /** @return array{filename:string,path:string} */
    private function buildWorkbook(string $key, string $reportName, array $rows, string $startDate, string $endDate): array
    {
        $sp = new Spreadsheet();
        $sheet = $sp->getActiveSheet();
        $sheet->setTitle($key === 'rama' ? 'Rama' : 'Nick');
        $sheet->setShowGridlines(false);

        $headers = $key === 'rama'
            ? ['ID', 'CLIENT', 'RETENTION AGENT', 'RETENTION DATE', 'IMMEDIATE RESULTS', 'ENROLLED DEBT', 'CLEARED PAYMENTS', 'RECONSIDERATION DATE', 'DROPPED DATE', 'RETAINED DATE', 'RETENTION PAYMENT DATE', 'RETENTION COMMISSION T1', 'RETENTION COMMISSION T2', 'RETENTION COMMISSION T3', 'RETENTION COMMISSION T4', 'CANCEL REQUEST DATE', 'Tranche', 'Cut Off', 'Made Cut Off', 'Bonus']
            : ['ID', 'CLIENT', 'RETENTION AGENT', 'RETENTION DATE', 'IMMEDIATE RESULTS', 'ENROLLED DEBT', 'CLEARED PAYMENTS', 'RECONSIDERATION DATE', 'DROPPED DATE', 'RETAINED DATE', 'RETENTION PAYMENT DATE', 'RETENTION COMMISSION T1', 'RETENTION COMMISSION T2', 'RETENTION COMMISSION T3', 'RETENTION COMMISSION T4', 'CANCEL REQUEST DATE', 'Commission Rate', 'Commission Earned'];
        foreach ($headers as $i => $header) {
            $sheet->setCellValue($this->cell($i + 1, 1), $header);
        }
        $lastHeaderCol = $key === 'rama' ? 'T' : 'R';
        $this->headerStyle($sheet, "A1:{$lastHeaderCol}1");

        $nickPct = $this->overallPct($rows);

        $r = 2;
        foreach ($rows as $row) {
            $sheet->setCellValue("A{$r}", $this->col($row, 'ID', ''));
            $sheet->setCellValue("B{$r}", $this->col($row, 'CLIENT', ''));
            $sheet->setCellValue("C{$r}", $this->col($row, 'RETENTION_AGENT', ''));
            $this->setDate($sheet, "D{$r}", $this->col($row, 'RETENTION_DATE'));
            $sheet->setCellValue("E{$r}", $this->col($row, 'IMMEDIATE_RESULTS', ''));
            $sheet->setCellValue("F{$r}", (float) $this->col($row, 'ENROLLED_DEBT', 0));
            $sheet->setCellValue("G{$r}", (int) $this->col($row, 'CLEARED_PAYMENTS', 0));
            $this->setDate($sheet, "H{$r}", $this->col($row, 'RECONSIDERATION_DATE'));
            $this->setDate($sheet, "I{$r}", $this->col($row, 'DROPPED_DATE'));
            $this->setDate($sheet, "J{$r}", $this->col($row, 'RETAINED_DATE'));
            $this->setDate($sheet, "K{$r}", $this->col($row, 'RETENTION_PAYMENT_DATE'));
            $sheet->setCellValue("L{$r}", $this->col($row, 'T1', ''));
            $sheet->setCellValue("M{$r}", $this->col($row, 'T2', ''));
            $sheet->setCellValue("N{$r}", $this->col($row, 'T3', ''));
            $sheet->setCellValue("O{$r}", $this->col($row, 'T4', ''));
            $this->setDate($sheet, "P{$r}", $this->col($row, 'CANCEL_REQUEST_DATE'));
            if ($key === 'rama') {
                $sheet->setCellValue("Q{$r}", $this->col($row, 'TRANCHE', ''));
                $this->setDate($sheet, "R{$r}", $this->col($row, 'CUT_OFF'));
                $sheet->setCellValue("S{$r}", $this->col($row, 'MADE_CUT_OFF', false) ? 'x' : '');
                $sheet->setCellValue("T{$r}", $this->col($row, 'BONUS_FLAG', ''));
            } else {
                $rate = $this->nickCommissionRate((float) $this->col($row, 'ENROLLED_DEBT', 0), $nickPct);
                $sheet->setCellValue("Q{$r}", $rate);
                $sheet->setCellValue("R{$r}", strtoupper((string) $this->col($row, 'IMMEDIATE_RESULTS', '')) === 'RETAINED' ? $rate : '');
            }
            $r++;
        }
        $last = max($r - 1, 1);
        foreach (['D', 'H', 'I', 'J', 'K', 'O', 'R'] as $col) {
            if ($key === 'nick' && in_array($col, ['O', 'R'], true)) {
                continue;
            }
            $sheet->getStyle("{$col}2:{$col}{$last}")->getNumberFormat()->setFormatCode('mm/dd/yyyy');
        }
        $sheet->getStyle("F2:F{$last}")->getNumberFormat()->setFormatCode('$#,##0');
        $sheet->getStyle("L2:O{$last}")->getNumberFormat()->setFormatCode('$#,##0.00');
        $sheet->getStyle("A1:{$lastHeaderCol}1")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle("A1:{$lastHeaderCol}1")->getFont()->setName('Calibri')->setSize(9);
        foreach (range('A', $lastHeaderCol) as $col) {
            $sheet->getColumnDimension($col)->setWidth(16);
        }
        $sheet->freezePane('A2');

        if ($key === 'rama') {
            $this->buildRamaSummary($sheet, $rows, $startDate, $endDate);
        } else {
            $this->buildNickSummary($sheet, $rows, $startDate, $endDate);
        }

        $sp->setActiveSheetIndex(0);
        $filename = "{$reportName}.xlsx";
        $folder = $this->downloadReportFolder($reportName);
        if (!is_dir($folder)) {
            mkdir($folder, 0775, true);
        }
        $path = $folder . DIRECTORY_SEPARATOR . $filename;
        (new Xlsx($sp))->save($path);

        return ['filename' => $filename, 'path' => $path];
    }

    private function buildRamaSummary(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, array $rows, string $startDate, string $endDate): void
    {
        $all = $this->ramaBucket($rows, false);
        $ngf = $this->ramaBucket($rows, true);
        $c1 = $this->ramaCommission($all['pct'], false);
        $c2 = $this->ramaCommission($ngf['pct'], true);

        $sheet->setCellValue('W2', 'Rama');
        $sheet->setCellValue('W3', 'Summary');
        $sheet->setCellValue('W4', 'Reconsideration Pending');
        $sheet->setCellValue('X4', $all['assigned']);
        $sheet->setCellValue('W5', 'Retained');
        $sheet->setCellValue('X5', $all['retained']);
        $sheet->setCellValue('W6', 'Rate');
        $sheet->setCellValue('X6', $all['pct']);
        $sheet->setCellValue('W7', 'Commission 1');
        $sheet->setCellValue('X7', $c1);
        $sheet->setCellValue('W9', 'Bonus Reconsideration');
        $sheet->setCellValue('X9', $ngf['assigned']);
        $sheet->setCellValue('W10', 'Bonus Retained');
        $sheet->setCellValue('X10', $ngf['retained']);
        $sheet->setCellValue('W11', 'Rate');
        $sheet->setCellValue('X11', $ngf['pct']);
        $sheet->setCellValue('W12', 'Commission 2');
        $sheet->setCellValue('X12', $c2);
        $sheet->setCellValue('W14', 'Commission Total');
        $sheet->setCellValue('X14', $c1 + $c2);
        $sheet->getStyle('X6:X11')->getNumberFormat()->setFormatCode('0%');
        $sheet->getStyle('X7:X14')->getNumberFormat()->setFormatCode('$#,##0');
        foreach (range('W', 'X') as $col) {
            $sheet->getColumnDimension($col)->setWidth(22);
        }
    }

    private function buildNickSummary(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, array $rows, string $startDate, string $endDate): void
    {
        $assigned = count($rows);
        $retained = $this->retainedCount($rows);
        $pct = $assigned > 0 ? $retained / $assigned : 0.0;
        $tier = $this->nickTier($pct);
        $commission = 0.0;
        foreach ($rows as $row) {
            if (strtoupper((string) $this->col($row, 'IMMEDIATE_RESULTS', '')) === 'RETAINED') {
                $commission += $this->nickCommissionRate((float) $this->col($row, 'ENROLLED_DEBT', 0), $pct);
            }
        }

        $sheet->setCellValue('W2', 'Nick');
        $sheet->setCellValue('W3', 'Reconsideration Pending');
        $sheet->setCellValue('X3', $assigned);
        $sheet->setCellValue('W4', 'Retained');
        $sheet->setCellValue('X4', $retained);
        $sheet->setCellValue('W5', 'Rate');
        $sheet->setCellValue('X5', $pct);
        $sheet->setCellValue('W6', 'Tier');
        $sheet->setCellValue('X6', $tier);
        $sheet->setCellValue('W7', 'Commission');
        $sheet->setCellValue('X7', $commission);
        $sheet->fromArray([['Rate 1', 'Rate 2', 'Rate 3', 'Rate 4']], null, 'Z5');
        $sheet->fromArray([[$this->nickRateForTier($tier, 0), $this->nickRateForTier($tier, 1), $this->nickRateForTier($tier, 2), $this->nickRateForTier($tier, 3)]], null, 'Z6');
        $sheet->getStyle('X5')->getNumberFormat()->setFormatCode('0%');
        $sheet->getStyle('X7')->getNumberFormat()->setFormatCode('$#,##0');
        foreach (['W', 'X', 'Z', 'AA', 'AB', 'AC'] as $col) {
            $sheet->getColumnDimension($col)->setWidth(16);
        }
    }

    /** @return array{assigned:int,retained:int,pct:float} */
    private function ramaBucket(array $rows, bool $ngfOnly): array
    {
        $assigned = 0;
        $retained = 0;
        foreach ($rows as $row) {
            if ($ngfOnly && $this->col($row, 'BONUS_FLAG', '') === '') {
                continue;
            }
            $assigned++;
            if (strtoupper((string) $this->col($row, 'IMMEDIATE_RESULTS', '')) === 'RETAINED') {
                if (!$ngfOnly || $this->col($row, 'BONUS_FLAG', '') === 'Bonus') {
                    $retained++;
                }
            }
        }

        return ['assigned' => $assigned, 'retained' => $retained, 'pct' => $assigned > 0 ? $retained / $assigned : 0.0];
    }

    private function ramaCommission(float $pct, bool $ngf): int
    {
        if ($ngf) {
            return match (true) {
                $pct < 0.40 => 0,
                $pct < 0.45 => 250,
                $pct < 0.50 => 500,
                $pct < 0.60 => 750,
                default => 1000,
            };
        }

        return match (true) {
            $pct < 0.30 => 0,
            $pct < 0.35 => 250,
            $pct < 0.40 => 500,
            $pct < 0.45 => 750,
            default => 1000,
        };
    }

    /** @return array{assigned:int,retained:int,pct:float,tier:int,commission:float} */
    private function nickAgentSummary(array $rows, string $agent, string $startDate, string $endDate): array
    {
        $assigned = 0;
        $retained = 0;
        $agentUpper = strtoupper($agent);

        foreach ($rows as $row) {
            if (strtoupper((string) $this->col($row, 'RETENTION_AGENT', '')) !== $agentUpper) {
                continue;
            }
            $cancelDate = $this->toDate($this->col($row, 'CANCEL_REQUEST_DATE'));
            if ($cancelDate && $cancelDate >= $startDate && $cancelDate <= $endDate) {
                $assigned++;
            }
            $retentionDate = $this->toDate($this->col($row, 'RETENTION_DATE'));
            if ($retentionDate && $retentionDate >= $startDate && $retentionDate <= $endDate) {
                $retained++;
            }
        }

        $pct = $assigned > 0 ? $retained / $assigned : 0.0;
        $tier = $this->nickTier($pct);
        $commission = 0.0;
        if ($tier > 0) {
            foreach ($rows as $row) {
                if (strtoupper((string) $this->col($row, 'RETENTION_AGENT', '')) !== $agentUpper) {
                    continue;
                }
                $payDate = $this->toDate($this->col($row, 'RETENTION_PAYMENT_DATE'));
                if ($payDate && $payDate >= $startDate && $payDate <= $endDate && strtoupper((string) $this->col($row, 'IMMEDIATE_RESULTS', '')) === 'RETAINED') {
                    $commission += $this->nickCommissionRate((float) $this->col($row, 'ENROLLED_DEBT', 0), $pct);
                }
            }
        }

        return ['assigned' => $assigned, 'retained' => $retained, 'pct' => $pct, 'tier' => $tier, 'commission' => $commission];
    }

    private function nickTier(float $pct): int
    {
        return match (true) {
            $pct >= 0.50 => 3,
            $pct >= 0.40 => 2,
            $pct >= 0.30 => 1,
            default => 0,
        };
    }

    private function nickCommissionRate(float $debt, float $pct): float
    {
        $bracket = match (true) {
            $debt <= 15000 => 0,
            $debt <= 30000 => 1,
            $debt <= 60000 => 2,
            default => 3,
        };

        foreach (self::NICK_RATE_BANDS as $band) {
            if ($pct >= $band['min']) {
                return $band['rates'][$bracket];
            }
        }

        return 0.0;
    }

    private function nickRateForTier(int $tier, int $bracket): float
    {
        return match ($tier) {
            3 => [8.0, 9.0, 10.0, 11.0][$bracket],
            2 => [4.0, 5.0, 6.0, 7.0][$bracket],
            1 => [0.9, 1.0, 2.0, 3.0][$bracket],
            default => 0.0,
        };
    }

    private function overallPct(array $rows): float
    {
        $assigned = count($rows);
        return $assigned > 0 ? $this->retainedCount($rows) / $assigned : 0.0;
    }

    private function retainedCount(array $rows): int
    {
        $count = 0;
        foreach ($rows as $row) {
            if (strtoupper((string) $this->col($row, 'IMMEDIATE_RESULTS', '')) === 'RETAINED') {
                $count++;
            }
        }
        return $count;
    }

    private function applyTrancheAssignments(array &$rows): void
    {
        if (empty($rows)) {
            return;
        }

        $ids = [];
        foreach ($rows as $row) {
            $id = (string) $this->col($row, 'ID', '');
            if ($id !== '') {
                $ids[] = "'LLG-" . str_replace("'", "''", $id) . "'";
            }
        }
        $ids = array_values(array_unique($ids));
        $map = [];

        try {
            $sql = $this->initSqlServer('ldr');
            foreach (array_chunk($ids, 1000) as $chunk) {
                $res = $sql->querySqlServer(
                    "SELECT e.LLG_ID, e.Tranche, ts.Payment_Date
                     FROM TblEnrollment e
                     LEFT JOIN TblDebtTrancheSales ts ON e.Tranche = ts.Tranche
                     WHERE e.LLG_ID IN (" . implode(',', $chunk) . ")"
                );
                foreach ($res['data'] ?? [] as $record) {
                    $llg = strtoupper((string) ($record['LLG_ID'] ?? $record['llg_id'] ?? ''));
                    if ($llg !== '') {
                        $map[$llg] = [
                            'tranche' => $record['Tranche'] ?? $record['tranche'] ?? '',
                            'payment_date' => $this->toDate($record['Payment_Date'] ?? $record['payment_date'] ?? null),
                        ];
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->warn('Tranche assignment lookup failed: ' . $e->getMessage());
        }

        foreach ($rows as &$row) {
            $llg = 'LLG-' . (string) $this->col($row, 'ID', '');
            $assignment = $map[strtoupper($llg)] ?? null;
            $row['TRANCHE'] = $assignment['tranche'] ?? '';
            $paymentDate = $assignment['payment_date'] ?? null;
            $row['CUT_OFF'] = $paymentDate ? date('Y-m-d', strtotime('+60 days', strtotime($paymentDate))) : null;
            $cancelDate = $this->toDate($this->col($row, 'CANCEL_REQUEST_DATE'));
            $made = $cancelDate !== null && $row['CUT_OFF'] !== null && $cancelDate <= $row['CUT_OFF'];
            $row['MADE_CUT_OFF'] = $made;
            $row['BONUS_FLAG'] = $made ? (strtoupper((string) $this->col($row, 'IMMEDIATE_RESULTS', '')) === 'RETAINED' ? 'Bonus' : 'No Bonus') : '';
        }
        unset($row);
    }

    /** @return array{t1:int,t2:int,t3:int,t4:int} */
    private function tierAmounts(float $debt): array
    {
        foreach (self::TIER_AMOUNTS as $bracket) {
            if ($debt <= $bracket['max']) {
                return ['t1' => $bracket['t1'], 't2' => $bracket['t2'], 't3' => $bracket['t3'], 't4' => $bracket['t4']];
            }
        }

        return ['t1' => 20, 't2' => 40, 't3' => 60, 't4' => 150];
    }

    private function col(array $row, string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $row)) {
            return $row[$key];
        }
        $lower = strtolower($key);
        foreach ($row as $k => $v) {
            if (strtolower((string) $k) === $lower) {
                return $v;
            }
        }
        return $default;
    }

    private function toDate(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            $ts = (int) $value;
            return $ts > 0 ? date('Y-m-d', $ts) : null;
        }
        $ts = strtotime((string) $value);
        return $ts === false ? null : date('Y-m-d', $ts);
    }

    private function setDate(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, string $cell, mixed $value): void
    {
        $date = $this->toDate($value);
        if ($date !== null) {
            $sheet->setCellValue($cell, XlDate::PHPToExcel(strtotime($date)));
            $sheet->getStyle($cell)->getNumberFormat()->setFormatCode('mm/dd/yyyy');
        }
    }

    private function headerStyle(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF17853B']],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        ]);
    }

    private function cell(int $column, int $row): string
    {
        $name = '';
        while ($column > 0) {
            $mod = ($column - 1) % 26;
            $name = chr(65 + $mod) . $name;
            $column = intdiv($column - $mod, 26);
        }
        return $name . $row;
    }

    private function initSqlServer(string $source): DBConnector
    {
        $connector = DBConnector::fromEnvironment($source);
        $connector->initializeSqlServer();
        return $connector;
    }

    private function downloadReportFolder(string $reportName): string
    {
        $home = rtrim((string) (getenv('USERPROFILE') ?: getenv('HOME') ?: storage_path('app')), DIRECTORY_SEPARATOR . '/');
        $downloads = $home . DIRECTORY_SEPARATOR . 'Downloads';

        return $downloads . DIRECTORY_SEPARATOR . $reportName;
    }

    private function runReconcile(string $startDate, string $endDate): int
    {
        $jacobPath = (string) ($this->option('jacob') ?: '');
        if ($jacobPath === '') {
            $jacobPath = dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'Retention Manager Commission.xlsx';
            if (!is_file($jacobPath)) {
                $jacobPath = 'C:' . DIRECTORY_SEPARATOR . 'Users' . DIRECTORY_SEPARATOR . 'Hesham'
                    . DIRECTORY_SEPARATOR . 'Documents' . DIRECTORY_SEPARATOR . 'cmdproj'
                    . DIRECTORY_SEPARATOR . 'Retention Manager Commission.xlsx';
            }
        }
        if (!is_file($jacobPath)) {
            $this->error('Jacob workbook not found. Use --jacob=path');
            return Command::FAILURE;
        }

        $this->info('[INFO] Building June retention rows from Snowflake...');
        $rows = $this->buildAllData($startDate, $endDate);
        $genIds = [];
        foreach ($rows as $row) {
            $id = (string) $this->col($row, 'ID', '');
            if ($id !== '') {
                $genIds[$id] = true;
            }
        }

        $jacobIds = $this->loadJacobRamaContactIds($jacobPath);
        $onlyJacob = array_values(array_diff(array_keys($jacobIds), array_keys($genIds)));
        $onlyGen = array_values(array_diff(array_keys($genIds), array_keys($jacobIds)));

        $outDir = storage_path('app/retention-reconcile');
        if (!is_dir($outDir)) {
            mkdir($outDir, 0775, true);
        }
        $stamp = date('Y-m-d_His');
        $jFile = $outDir . DIRECTORY_SEPARATOR . "only_in_jacob_{$stamp}.txt";
        $gFile = $outDir . DIRECTORY_SEPARATOR . "only_in_generated_{$stamp}.txt";
        file_put_contents($jFile, implode(PHP_EOL, $onlyJacob) . (count($onlyJacob) ? PHP_EOL : ''));
        file_put_contents($gFile, implode(PHP_EOL, $onlyGen) . (count($onlyGen) ? PHP_EOL : ''));

        $retained = $this->retainedCount($rows);
        $this->info('[INFO] Generated rows: ' . count($rows) . ' | Retained: ' . $retained);
        $this->info('[INFO] Jacob Rama IDs: ' . count($jacobIds));
        $this->info('[INFO] Only in Jacob: ' . count($onlyJacob) . ' -> ' . $jFile);
        $this->info('[INFO] Only in generated: ' . count($onlyGen) . ' -> ' . $gFile);

        return Command::SUCCESS;
    }

    /** @return array<string,true> */
    private function loadJacobRamaContactIds(string $path): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \RuntimeException("Cannot open $path");
        }
        $shared = [];
        if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
            $sx = simplexml_load_string($xml);
            foreach ($sx->si as $si) {
                $parts = [];
                if (isset($si->t)) {
                    $parts[] = (string) $si->t;
                }
                foreach ($si->r ?? [] as $r) {
                    $parts[] = (string) $r->t;
                }
                $shared[] = implode('', $parts);
            }
        }
        $rels = [];
        $rx = simplexml_load_string((string) $zip->getFromName('xl/_rels/workbook.xml.rels'));
        $rx->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/package/2006/relationships');
        foreach ($rx->xpath('//r:Relationship') as $rel) {
            $a = $rel->attributes();
            $rels[(string) $a['Id']] = 'xl/' . ltrim((string) $a['Target'], '/');
        }
        $wb = simplexml_load_string((string) $zip->getFromName('xl/workbook.xml'));
        $wb->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $sheetPath = null;
        foreach ($wb->xpath('//m:sheets/m:sheet') as $sn) {
            $a = $sn->attributes();
            if (stripos((string) $a['name'], 'Rama') === false) {
                continue;
            }
            $ra = $sn->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
            $sheetPath = $rels[(string) $ra['id']] ?? null;
            break;
        }
        if ($sheetPath === null) {
            $zip->close();
            throw new \RuntimeException('Rama sheet not found in Jacob workbook');
        }
        $sx = simplexml_load_string((string) $zip->getFromName($sheetPath));
        $ids = [];
        foreach ($sx->sheetData->row as $row) {
            $rn = (int) $row['r'];
            if ($rn < 2) {
                continue;
            }
            foreach ($row->c as $c) {
                if ((string) $c['r'] !== 'A' . $rn) {
                    continue;
                }
                $val = isset($c->v) ? (string) $c->v : '';
                if (($c['t'] ?? '') === 's') {
                    $val = $shared[(int) $val] ?? $val;
                }
                if ($val !== '') {
                    $ids[$val] = true;
                }
                break;
            }
        }
        $zip->close();

        return $ids;
    }
}
