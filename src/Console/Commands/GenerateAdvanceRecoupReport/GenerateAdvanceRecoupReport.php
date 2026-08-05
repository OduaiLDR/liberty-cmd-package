<?php

namespace Cmd\Reports\Console\Commands\GenerateAdvanceRecoupReport;

use Cmd\Reports\Services\DBConnector;
use Cmd\Reports\Services\EmailSenderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Monthly EPF adjustments workbook for Jacob/Aaron/Jessica (Progress Law),
 * built from his exact Snowflake TRANSACTIONS queries (Slack, 2026-07-16 /
 * 2026-08-02). One workbook, sheets in order: Summary, Advances, Refunds,
 * Recoups. The month window filters on CLEARED_DATE, not PROCESS_DATE
 * (per Jacob, 2026-08-05: "this data is all based on cleared date") --
 * rows that haven't cleared yet (NULL CLEARED_DATE) are excluded.
 *
 * Detail sheets (Advances/Refunds/Recoups) each get: Contact ID, Client
 * Name (Snowflake CONTACTS.FIRSTNAME + LASTNAME), Trans Type, Amount, EPF
 * Rate, Prorated Debt (= Amount / Rate, 2dp), Process Date, Cleared Date,
 * Memo. EPF Rate comes from dbo.TblEnrollment.EPF_Rate (SQL Server),
 * matched via LLG_ID = 'LLG-' + Snowflake CONTACT_ID -- defaults to 0.29
 * when no match exists, per Jacob's "usually 29% but can vary." Amount is
 * forced negative for Advances/Refunds and positive for Recoups.
 *
 * Summary sheet: one row per detail sheet (Category, Amount = that sheet's
 * fee total, Effective Debt = sum of that sheet's Prorated Debt -- shown
 * for reference only, Primary Account = 3% of Effective Debt, Operation
 * Account = Amount minus Primary Account), plus a fixed Rent row (-400,
 * booked entirely under Operation Account) and a Total row summing
 * everything above it.
 */
class GenerateAdvanceRecoupReport extends Command
{
    protected $signature = 'Generate:advance-recoup-report
                            {--test : Send only to jacob@progresslaw.com instead of the full recipient list}
                            {--month= : Month to report on as YYYY-MM (defaults to last full calendar month)}';

    protected $description = 'Email the monthly Advances/Refunds/Recoups EPF adjustments workbook.';

    private const PAID_TO = [35564, 35289];
    private const DEFAULT_EPF_RATE = 0.29;
    private const RENT_AMOUNT = -400.0;
    private const PRIMARY_ACCOUNT_RATE = 0.03;
    private const RECIPIENTS = ['Jacob@progresslaw.com', 'Aaron@progresslaw.com', 'Jessica@cdp.law'];
    private const TEST_RECIPIENT = 'oduai@libertydebtrelief.com';

    public function handle(): int
    {
        $this->info('[INFO] Advance/Recoup report: starting.');

        try {
            $snowflake = $this->initializeSnowflakeConnector();
        } catch (\Throwable $e) {
            $this->error('Failed to initialize Snowflake connector: ' . $e->getMessage());
            Log::error('GenerateAdvanceRecoupReport: Snowflake init failed', ['exception' => $e]);
            return Command::FAILURE;
        }

        try {
            $sqlServer = $this->initializeSqlServerConnector();
        } catch (\Throwable $e) {
            $this->error('Failed to initialize SQL Server connector: ' . $e->getMessage());
            Log::error('GenerateAdvanceRecoupReport: SQL Server init failed', ['exception' => $e]);
            return Command::FAILURE;
        }

        try {
            $window = $this->resolveMonthWindow();
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return Command::FAILURE;
        }

        try {
            $refunds = $this->fetchRows($snowflake, $this->buildQuery(
                "AND TRANS_TYPE = 'T' AND MEMO LIKE '%Earned Fee%'",
                $window['start'],
                $window['endExclusive']
            ));

            $advancesSA = $this->fetchRows($snowflake, $this->buildQuery(
                "AND TRANS_TYPE = 'SA'",
                $window['start'],
                $window['endExclusive']
            ));

            $advancesT = $this->fetchRows($snowflake, $this->buildQuery(
                "AND TRANS_TYPE = 'T' AND MEMO NOT LIKE '%Earned Fee%' AND MEMO LIKE '%Advance%'",
                $window['start'],
                $window['endExclusive']
            ));

            $advances = array_merge($advancesSA, $advancesT);
            usort($advances, function (array $a, array $b): int {
                return [(string) ($a['TRANS_TYPE'] ?? ''), (string) ($a['PROCESS_DATE'] ?? ''), (float) ($a['AMOUNT'] ?? 0)]
                    <=> [(string) ($b['TRANS_TYPE'] ?? ''), (string) ($b['PROCESS_DATE'] ?? ''), (float) ($b['AMOUNT'] ?? 0)];
            });

            $recoups = $this->fetchRows($snowflake, $this->buildQuery(
                "AND TRANS_TYPE = 'C' AND MEMO LIKE '%Recoup%'",
                $window['start'],
                $window['endExclusive']
            ));
        } catch (\Throwable $e) {
            $this->error('Failed to fetch transactions: ' . $e->getMessage());
            Log::error('GenerateAdvanceRecoupReport: Snowflake query failed', ['exception' => $e]);
            return Command::FAILURE;
        }

        $allRows = array_merge($advances, $refunds, $recoups);
        $rates = $this->fetchEpfRates($sqlServer, $allRows);
        $names = $this->fetchClientNames($snowflake, $allRows);

        // Advances/Refunds are shown as negative (money going out), Recoups
        // as positive -- per Jacob's explicit sign convention, applied here
        // so the detail sheets and the Summary totals stay consistent with
        // each other rather than only forcing the sign at the summary level.
        $advances = $this->applyRatesAndProration($advances, $rates, $names, -1);
        $refunds = $this->applyRatesAndProration($refunds, $rates, $names, -1);
        $recoups = $this->applyRatesAndProration($recoups, $rates, $names, 1);

        $sheets = [
            'Advances' => $advances,
            'Refunds' => $refunds,
            'Recoups' => $recoups,
        ];

        $summaryRows = $this->buildSummaryRows($sheets);

        $formatter = new Formatter();
        $report = $formatter->buildWorkbook($sheets, $summaryRows, $window['label']);
        $this->info('[INFO] Workbook written to: ' . $report['path']);

        $isTest = (bool) $this->option('test');
        $recipients = $isTest ? [self::TEST_RECIPIENT] : self::RECIPIENTS;

        $subject = ($isTest ? '[TEST] ' : '') . 'EPF Adjustments - ' . $window['label'];
        $body = 'Please review the EPF adjustments for ' . $window['label'] . '. '
            . 'Line item detail is attached. '
            . 'Thanks';

        $attachments = [[
            'name' => $report['filename'],
            'contentType' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'contentBytes' => base64_encode(file_get_contents($report['path'])),
        ]];

        $email = new EmailSenderService();
        $sent = $email->sendMailHtml($subject, nl2br(htmlspecialchars($body)), $recipients, [], [], $attachments);

        if ($sent) {
            $this->info('[INFO] Advance/Recoup report sent to ' . implode(', ', $recipients) . '.');
        } else {
            $this->warn('[WARN] Advance/Recoup report failed to send.');
            Log::warning('GenerateAdvanceRecoupReport: email send failed.');
        }

        return $sent ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * @return array{label:string,start:string,endExclusive:string}
     */
    private function resolveMonthWindow(): array
    {
        $monthOpt = $this->option('month');

        if ($monthOpt) {
            if (!preg_match('/^\d{4}-\d{2}$/', (string) $monthOpt)) {
                throw new \InvalidArgumentException("Invalid --month value '{$monthOpt}', expected YYYY-MM.");
            }
            $start = \Carbon\Carbon::createFromFormat('Y-m-d', $monthOpt . '-01')->startOfMonth();
        } else {
            $start = now()->subMonthNoOverflow()->startOfMonth();
        }

        $endExclusive = $start->copy()->addMonthNoOverflow()->startOfMonth();

        return [
            'label' => $start->format('F Y'),
            'start' => $start->format('Y-m-d'),
            'endExclusive' => $endExclusive->format('Y-m-d'),
        ];
    }

    private function buildQuery(string $extraWhere, string $start, string $endExclusive): string
    {
        $paidTo = implode(', ', self::PAID_TO);
        $startEsc = $this->esc($start);
        $endEsc = $this->esc($endExclusive);

        // Windowed on CLEARED_DATE, not PROCESS_DATE -- per Jacob (2026-08-05):
        // "this data is all based on cleared date." Filtering by PROCESS_DATE
        // let in rows that hadn't cleared yet, which is why some Recoups
        // showed a blank Cleared Date. NULL CLEARED_DATE rows now fall
        // outside any range and are excluded automatically.
        return "
            SELECT CONTACT_ID, TRANS_TYPE, AMOUNT, PROCESS_DATE, CLEARED_DATE, MEMO
            FROM TRANSACTIONS
            WHERE Paid_To IN ({$paidTo})
              AND CLEARED_DATE >= '{$startEsc}'
              AND CLEARED_DATE < '{$endEsc}'
              {$extraWhere}
            ORDER BY TRANS_TYPE, PROCESS_DATE ASC, AMOUNT ASC
        ";
    }

    private function fetchRows(DBConnector $connector, string $sql): array
    {
        $result = $connector->query($sql);
        return $result['data'] ?? [];
    }

    /**
     * Looks up dbo.TblEnrollment.EPF_Rate for every distinct CONTACT_ID
     * across all three sheets in one batched query, matching via
     * LLG_ID = 'LLG-' + CONTACT_ID (same convention used throughout this
     * package -- see SyncEPFData/SyncEnrollmentPlans).
     *
     * @return array<string,float> CONTACT_ID => rate
     */
    private function fetchEpfRates(DBConnector $connector, array $rows): array
    {
        $contactIds = [];
        foreach ($rows as $row) {
            $id = (string) ($row['CONTACT_ID'] ?? '');
            if ($id !== '') {
                $contactIds[$id] = true;
            }
        }
        $contactIds = array_keys($contactIds);

        if (empty($contactIds)) {
            return [];
        }

        $rates = [];
        foreach (array_chunk($contactIds, 1000) as $chunk) {
            $idList = implode(', ', array_map(fn (string $id): string => "'" . $this->esc($id) . "'", $chunk));
            $sql = "
                SELECT REPLACE(LLG_ID, 'LLG-', '') AS CONTACT_ID, EPF_Rate
                FROM dbo.TblEnrollment
                WHERE REPLACE(LLG_ID, 'LLG-', '') IN ({$idList})
            ";
            $result = $connector->querySqlServer($sql);
            foreach ($result['data'] ?? [] as $row) {
                $id = (string) ($row['CONTACT_ID'] ?? '');
                $rate = $row['EPF_Rate'] ?? null;
                if ($id !== '' && $rate !== null && $rate !== '' && !isset($rates[$id])) {
                    $rates[$id] = (float) $rate;
                }
            }
        }

        return $rates;
    }

    /**
     * Batch-looks up FIRSTNAME/LASTNAME from Snowflake CONTACTS (same
     * environment as TRANSACTIONS) for every distinct CONTACT_ID, so the
     * detail sheets can show a human-readable client name next to the ID.
     *
     * @return array<string,string> CONTACT_ID => "First Last"
     */
    private function fetchClientNames(DBConnector $connector, array $rows): array
    {
        $contactIds = [];
        foreach ($rows as $row) {
            $id = (string) ($row['CONTACT_ID'] ?? '');
            if ($id !== '') {
                $contactIds[$id] = true;
            }
        }
        $contactIds = array_keys($contactIds);

        if (empty($contactIds)) {
            return [];
        }

        $names = [];
        foreach (array_chunk($contactIds, 1000) as $chunk) {
            $idList = implode(', ', $chunk);
            $sql = "SELECT ID, FIRSTNAME, LASTNAME FROM CONTACTS WHERE ID IN ({$idList})";
            $result = $connector->query($sql);
            foreach ($result['data'] ?? [] as $row) {
                $id = (string) ($row['ID'] ?? '');
                if ($id === '') {
                    continue;
                }
                $name = trim(trim((string) ($row['FIRSTNAME'] ?? '')) . ' ' . trim((string) ($row['LASTNAME'] ?? '')));
                $names[$id] = $name;
            }
        }

        return $names;
    }

    /**
     * @param array<string,float> $rates
     * @param array<string,string> $names
     */
    private function applyRatesAndProration(array $rows, array $rates, array $names, int $sign): array
    {
        foreach ($rows as &$row) {
            $contactId = (string) ($row['CONTACT_ID'] ?? '');
            $rate = $rates[$contactId] ?? self::DEFAULT_EPF_RATE;
            $rate = $rate > 0 ? $rate : self::DEFAULT_EPF_RATE;
            $amount = $sign * abs((float) ($row['AMOUNT'] ?? 0));

            $row['AMOUNT'] = $amount;
            $row['EPF_RATE'] = $rate;
            $row['PRORATED_DEBT'] = round($amount / $rate, 2);
            $row['CLIENT_NAME'] = $names[$contactId] ?? '';
        }
        unset($row);

        return $rows;
    }

    /**
     * @param array<string,array<int,array<string,mixed>>> $sheets
     * @return array<int,array{category:string,amount:?float,effective_debt:?float,primary_account:?float,operation_account:?float}>
     */
    private function buildSummaryRows(array $sheets): array
    {
        $rows = [];
        $totalAmount = 0.0;
        $totalEffectiveDebt = 0.0;
        $totalPrimary = 0.0;
        $totalOperation = 0.0;

        foreach ($sheets as $category => $sheetRows) {
            // Sign convention (Advances/Refunds negative, Recoups positive)
            // was already applied per-row in applyRatesAndProration(), so
            // AMOUNT/PRORATED_DEBT here sum straight through correctly signed.
            $amount = array_sum(array_map(fn (array $r): float => (float) ($r['AMOUNT'] ?? 0), $sheetRows));
            $effectiveDebt = array_sum(array_map(fn (array $r): float => (float) ($r['PRORATED_DEBT'] ?? 0), $sheetRows));

            // Primary Account is 3% of the prorated (effective) debt, but
            // Operation Account is the remainder of the real dollar Amount
            // after Primary's cut -- not the remainder of the prorated
            // debt figure, which is a much larger derived number.
            $primary = round($effectiveDebt * self::PRIMARY_ACCOUNT_RATE, 2);
            $operation = round($amount - $primary, 2);

            $rows[] = [
                'category' => $category,
                'amount' => $amount,
                'effective_debt' => $effectiveDebt,
                'primary_account' => $primary,
                'operation_account' => $operation,
            ];

            $totalAmount += $amount;
            $totalEffectiveDebt += $effectiveDebt;
            $totalPrimary += $primary;
            $totalOperation += $operation;
        }

        $rows[] = [
            'category' => 'Rent',
            'amount' => self::RENT_AMOUNT,
            'effective_debt' => null,
            'primary_account' => null,
            'operation_account' => self::RENT_AMOUNT,
        ];
        $totalAmount += self::RENT_AMOUNT;
        $totalOperation += self::RENT_AMOUNT;

        $rows[] = [
            'category' => 'Total',
            'amount' => $totalAmount,
            'effective_debt' => $totalEffectiveDebt,
            'primary_account' => $totalPrimary,
            'operation_account' => $totalOperation,
        ];

        return $rows;
    }

    /**
     * Paid_To 35564/35289 are Progress Law codes and only have matching
     * TRANSACTIONS rows in the 'plaw' Snowflake environment (confirmed:
     * 'ldr' connects fine but has 0 matching rows; 'plaw' has 420k+).
     * 'plaw' must be tried first, not just any environment that connects.
     */
    private function initializeSnowflakeConnector(): DBConnector
    {
        $candidates = ['plaw', 'ldr'];
        $errors = [];

        foreach ($candidates as $env) {
            try {
                return DBConnector::fromEnvironment($env);
            } catch (\Throwable $e) {
                $errors[] = "{$env}: {$e->getMessage()}";
            }
        }

        throw new \RuntimeException('Unable to initialize Snowflake connector. Tried: ' . implode('; ', $errors));
    }

    private function initializeSqlServerConnector(): DBConnector
    {
        $candidates = ['ldr', 'plaw', 'production', 'sandbox'];
        $errors = [];

        foreach ($candidates as $env) {
            try {
                $connector = DBConnector::fromEnvironment($env);
                $connector->initializeSqlServer();
                return $connector;
            } catch (\Throwable $e) {
                $errors[] = "{$env}: {$e->getMessage()}";
            }
        }

        throw new \RuntimeException('Unable to initialize SQL Server connector. Tried: ' . implode('; ', $errors));
    }

    private function esc(string $value): string
    {
        return str_replace("'", "''", $value);
    }
}
