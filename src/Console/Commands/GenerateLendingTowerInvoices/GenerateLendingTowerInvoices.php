<?php

namespace Cmd\Reports\Console\Commands\GenerateLendingTowerInvoices;

use Cmd\Reports\Services\DBConnector;
use Cmd\Reports\Services\EmailSenderService;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Monthly Lending Tower invoices to Progress Law and LDR (ticket cc039fe6, Jacob 2026-09-24).
 *
 * Both invoices cover one calendar month in Los Angeles time, by default the previous one.
 *
 * Progress Law is billed per lead: contacts created in the month on the Progress Law Forth account,
 * excluding deleted records, co-applicants, 'Duplicate Lead' status and records with no first name
 * (four junk records in August 2026 had no name or email). TblContactsPLAW is counted alongside as
 * a cross-check. It is a synced copy of the same contacts, so a difference means the contact sync
 * has not caught up, and the Snowflake count is the one billed.
 *
 * LDR is billed on enrollments: 7% of the debt basis (Sold_Debt, else Debt_Amount) of LDR clients
 * whose first payment cleared in the month. TblEnrollment holds both firms' enrollments, so LDR is
 * scoped by TblContactsLDR; Progress Law's clients are billed per lead on their own invoice. A
 * Lookback Deduction then comes off for clients already billed whose first lookback or cancel date
 * falls in the month. Each client is deducted once, in the month of whichever came first, and
 * clients who cancelled before any payment cleared are left out because they were never billed.
 *
 * Money is carried as integer cents. The fee is taken once from the total debt basis, so the
 * per-client debt rows always add up to the figure the fee is computed on.
 *
 * Delivery. Nothing reaches a client by accident: --dry-run only prints the figures, --save writes
 * the files to a folder, and --test-to emails them to one address from the normal report sender.
 * Only a run with none of those sends for real, and that needs LENDING_TOWER_INVOICES_LIVE=true,
 * recipients in TblReports under the invoice's report name, and a sender mailbox Graph can use.
 * Every live send is archived with a marker under storage/app/private, so a retried or repeated
 * run cannot bill anyone twice (--force overrides). The marker is a file rather than a cache entry
 * because the deploy's optimize:clear empties the cache.
 */
class GenerateLendingTowerInvoices extends Command
{
    protected $signature = 'reports:generate-lending-tower-invoices
                            {--month= : Billing month as YYYY-MM (defaults to the previous month, LA time)}
                            {--company=all : PLAW, LDR or all}
                            {--dry-run : Calculate and print the figures without creating or sending anything}
                            {--save= : Write the invoice PDFs and backup workbooks to this folder instead of emailing}
                            {--test-to= : Email the invoices only to this address, from the normal report sender, marked TEST}
                            {--force : Send even if this invoice number was already sent}';

    protected $description = 'Generate the monthly Lending Tower invoices: Progress Law per lead, LDR per enrollment less lookback.';

    private const TIMEZONE = 'America/Los_Angeles';

    private const PLAW_RATE_PER_LEAD_CENTS = 27500;

    /** 7%, in basis points so the fee stays in integer arithmetic. */
    private const LDR_FEE_BASIS_POINTS = 700;

    private const DUPLICATE_LEAD_STATUS = 'Duplicate Lead';

    private const TERMS_DAYS = 30;

    /**
     * Issuer and bill-to blocks, from cmd-runner's ExpenseInvoiceService::BILL_TO (confirmed by Bryan
     * for the expense invoices); the package cannot read app code, so keep them in step. That source
     * has no city for Lending Tower; its head office is Newport Beach (checked 2026-09-24).
     */
    private const LENDING_TOWER = ['name' => 'Lending Tower', 'lines' => ['5000 Birch St Suite 3000', 'Newport Beach, CA 92660']];

    private const BILL_TO = [
        'PLAW' => ['name' => 'Progress Law', 'lines' => ['3030 Euclid Ave Suite LL1', 'Cleveland, OH 44115']],
        'LDR' => ['name' => 'Liberty Debt Relief', 'lines' => ['333 City Blvd W 17th Fl', 'Orange, CA 92868']],
    ];

    private const INVOICE_PREFIX = ['PLAW' => 'LT-PL', 'LDR' => 'LT-LDR'];

    /** TblReports.Report_Name rows holding each invoice's To / CC / BCC for live sends. */
    private const REPORT_NAMES = [
        'PLAW' => 'Lending Tower Invoice - Progress Law',
        'LDR' => 'Lending Tower Invoice - LDR',
    ];

    /**
     * Default sender for live invoices, overridden by LENDING_TOWER_INVOICE_FROM. The invoice prints
     * the same address as its contact, so it never tells a client to write to a mailbox that cannot
     * receive mail.
     */
    private const INVOICE_CONTACT = 'invoices@lendingtower.com';

    /** Where live sends are archived on the local disk (storage/app/private), next to the expense invoices. */
    private const ARCHIVE_PREFIX = 'cmd/lending-tower-invoices';

    /**
     * LDR enrollments billed in the month; parameters are the period start and end. Dates come back
     * as YYYY-MM-DD (style 23) and the basis as DECIMAL(18,2), so both parse the same way whatever
     * the driver's defaults are.
     */
    private const LDR_ENROLLMENTS_SQL = <<<'SQL'
        SELECT e.LLG_ID,
               e.Client,
               CONVERT(char(10), e.First_Payment_Cleared_Date, 23) AS First_Payment_Cleared_Date,
               CASE WHEN e.Sold_Debt IS NULL THEN 'Debt_Amount' ELSE 'Sold_Debt' END AS Debt_Source,
               CAST(COALESCE(e.Sold_Debt, e.Debt_Amount) AS DECIMAL(18, 2)) AS Debt_Basis
        FROM TblEnrollment e
        WHERE e.First_Payment_Cleared_Date >= ?
          AND e.First_Payment_Cleared_Date < ?
          AND EXISTS (SELECT 1 FROM TblContactsLDR l WHERE l.LLG_ID = e.LLG_ID)
        ORDER BY e.First_Payment_Cleared_Date, e.LLG_ID
        SQL;

    /**
     * LDR clients deducted in the month. Parameters: period end; start and end for the lookback
     * window; start and end for the cancel window; start twice for the first-event rule.
     *
     * "Billed" means the first payment cleared before the period ended. The last two conditions
     * leave out a client whose other event fell in an earlier month, which is the month that
     * deducts them. The explanation lives here rather than in SQL comments because PDO's
     * placeholder scan does not reliably skip comments, and a stray quote in one would break it.
     */
    private const LDR_DEDUCTIONS_SQL = <<<'SQL'
        SELECT e.LLG_ID,
               e.Client,
               CONVERT(char(10), e.First_Payment_Cleared_Date, 23) AS First_Payment_Cleared_Date,
               CONVERT(char(10), e.Lookback_Date, 23) AS Lookback_Date,
               CONVERT(char(10), e.Cancel_Date, 23) AS Cancel_Date,
               CAST(COALESCE(e.Sold_Debt, e.Debt_Amount) AS DECIMAL(18, 2)) AS Debt_Basis
        FROM TblEnrollment e
        WHERE EXISTS (SELECT 1 FROM TblContactsLDR l WHERE l.LLG_ID = e.LLG_ID)
          AND e.First_Payment_Cleared_Date IS NOT NULL
          AND e.First_Payment_Cleared_Date < ?
          AND ((e.Lookback_Date >= ? AND e.Lookback_Date < ?) OR (e.Cancel_Date >= ? AND e.Cancel_Date < ?))
          AND (e.Lookback_Date IS NULL OR e.Lookback_Date >= ?)
          AND (e.Cancel_Date IS NULL OR e.Cancel_Date >= ?)
        ORDER BY e.LLG_ID
        SQL;

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $saveDir = trim((string) $this->option('save'));
        $testTo = trim((string) $this->option('test-to'));
        $live = ! $dryRun && $saveDir === '' && $testTo === '';

        if ($testTo !== '' && filter_var($testTo, FILTER_VALIDATE_EMAIL) === false) {
            $this->error("--test-to must be an email address, got '{$testTo}'.");

            return self::FAILURE;
        }

        if ($live && ! filter_var(env('LENDING_TOWER_INVOICES_LIVE', false), FILTER_VALIDATE_BOOLEAN)) {
            $this->error('Live sending is off. Use --dry-run, --save=DIR or --test-to=ADDRESS, or set '
                . 'LENDING_TOWER_INVOICES_LIVE=true once the invoices are approved.');

            return self::FAILURE;
        }

        $lock = $dryRun ? null : Cache::lock('lending-tower-invoices', 1800);
        if ($lock !== null && ! $lock->get()) {
            $this->error('Another Lending Tower invoice run is in progress.');

            return self::FAILURE;
        }

        try {
            [$start, $end, $label] = $this->resolvePeriod((string) $this->option('month'));
            $companies = $this->resolveCompanies((string) $this->option('company'));
            $period = $this->periodDetails($start, $end);

            $this->info("Lending Tower invoices for {$label} ({$start} to {$period['last_day']}, " . self::TIMEZONE . ')');

            $today = (new DateTimeImmutable('now', new DateTimeZone(self::TIMEZONE)))->format('Y-m-d');
            if ($end > $today) {
                if ($live) {
                    $this->error("{$label} has not ended yet. Refusing to bill a partial month.");

                    return self::FAILURE;
                }
                $this->warn('[WARN] This month has not ended yet, so the figures are partial.');
            }

            $azure = DBConnector::fromEnvironment('ldr');
            $azure->initializeSqlServer();

            $invoices = [];

            if (in_array('PLAW', $companies, true)) {
                $invoices['PLAW'] = $this->progressLawInvoice($azure, $start, $end);
                $this->printProgressLaw($invoices['PLAW']);
            }

            if (in_array('LDR', $companies, true)) {
                $invoices['LDR'] = $this->ldrInvoice($azure, $start, $end);
                $this->printLdr($invoices['LDR']);
            }

            if ($dryRun) {
                return self::SUCCESS;
            }

            return $this->deliver($invoices, $period, $azure, $saveDir, $testTo, $live) ? self::SUCCESS : self::FAILURE;
        } catch (\Throwable $e) {
            $this->error('Lending Tower invoices failed: ' . $e->getMessage());
            Log::error('GenerateLendingTowerInvoices failed', ['exception' => $e]);

            return self::FAILURE;
        } finally {
            $lock?->release();
        }
    }

    // -------------------------------------------------------------------------
    // Period and options
    // -------------------------------------------------------------------------

    /**
     * @return array{0: string, 1: string, 2: string} Inclusive start and exclusive end as Y-m-d,
     *                                                and a label such as "August 2026".
     */
    private function resolvePeriod(string $month): array
    {
        $tz = new DateTimeZone(self::TIMEZONE);

        if ($month === '') {
            $first = (new DateTimeImmutable('first day of this month', $tz))->modify('-1 month');
        } elseif (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) === 1) {
            $first = new DateTimeImmutable($month . '-01', $tz);
        } else {
            throw new RuntimeException("--month must be YYYY-MM, got '{$month}'.");
        }

        $first = $first->setTime(0, 0);
        $next = $first->modify('first day of next month');

        return [$first->format('Y-m-d'), $next->format('Y-m-d'), $first->format('F Y')];
    }

    /**
     * Labels and dates printed on the invoices. An invoice is dated the day after its period ends,
     * so re-running a past month produces the same document.
     *
     * @return array{month: string, range: string, last_day: string, suffix: string, issue_date: string, due_date: string}
     */
    private function periodDetails(string $start, string $end): array
    {
        $first = new DateTimeImmutable($start);
        $issue = new DateTimeImmutable($end);
        $last = $issue->modify('-1 day');

        return [
            'month' => $first->format('F Y'),
            'range' => $first->format('F j') . '–' . $last->format('j, Y'),
            'last_day' => $last->format('Y-m-d'),
            'suffix' => $first->format('Y-m'),
            'issue_date' => $issue->format('F j, Y'),
            'due_date' => $issue->modify('+' . self::TERMS_DAYS . ' days')->format('F j, Y'),
        ];
    }

    /** @return list<string> */
    private function resolveCompanies(string $option): array
    {
        $company = strtoupper(trim($option));

        return match ($company) {
            '', 'ALL' => ['PLAW', 'LDR'],
            'PLAW', 'LDR' => [$company],
            default => throw new RuntimeException("--company must be PLAW, LDR or all, got '{$option}'."),
        };
    }

    // -------------------------------------------------------------------------
    // Progress Law
    // -------------------------------------------------------------------------

    /**
     * @return array{leads: array<int, array<string, mixed>>, lead_count: int, total_cents: int, azure_count: int}
     */
    private function progressLawInvoice(DBConnector $azure, string $start, string $end): array
    {
        $leads = $this->snowflakeRows(
            DBConnector::fromEnvironment('plaw'),
            $this->progressLawLeadsSql($start, $end)
        );

        // One row per contact by construction, so a repeat is a query bug, not a data problem.
        $repeated = $this->duplicateValues(array_column($leads, 'CONTACT_ID'));
        if ($repeated !== []) {
            throw new RuntimeException('Progress Law lead query returned a contact more than once: '
                . implode(', ', array_slice($repeated, 0, 10)));
        }

        $azureCount = (int) ($this->sqlServerRows(
            $azure,
            'SELECT COUNT(*) AS n FROM TblContactsPLAW
             WHERE Created_Date >= ? AND Created_Date < ? AND COALESCE(Status, ?) <> ?',
            [$start, $end, '', self::DUPLICATE_LEAD_STATUS]
        )[0]['n'] ?? 0);

        return [
            'leads' => $leads,
            'lead_count' => count($leads),
            'total_cents' => count($leads) * self::PLAW_RATE_PER_LEAD_CENTS,
            'azure_count' => $azureCount,
        ];
    }

    /**
     * Contacts created in the month on the Progress Law account, less the invalid ones.
     *
     * CREATED is TIMESTAMP_TZ. Converting it to LA and casting to NTZ gives LA wall-clock time,
     * which compares with the NTZ month boundaries without depending on the session time zone.
     * Status is the latest CONTACTS_STATUS row, with ID breaking STAMP ties.
     */
    private function progressLawLeadsSql(string $start, string $end): string
    {
        $tz = self::TIMEZONE;
        $duplicate = self::DUPLICATE_LEAD_STATUS;

        return "
            WITH period_contacts AS (
                SELECT c.ID, c.FIRSTNAME, c.LASTNAME,
                       CONVERT_TIMEZONE('{$tz}', c.CREATED)::TIMESTAMP_NTZ AS CREATED_LOCAL
                FROM CONTACTS AS c
                WHERE CONVERT_TIMEZONE('{$tz}', c.CREATED)::TIMESTAMP_NTZ >= '{$start}'::TIMESTAMP_NTZ
                  AND CONVERT_TIMEZONE('{$tz}', c.CREATED)::TIMESTAMP_NTZ <  '{$end}'::TIMESTAMP_NTZ
                  AND c.DEL = 'FALSE'
                  AND c.ISCOAPP = 0
                  AND COALESCE(c.FIRSTNAME, '') <> ''
            ),
            period_status AS (
                SELECT s.CONTACT_ID, cls.TITLE AS STATUS
                FROM CONTACTS_STATUS AS s
                JOIN period_contacts AS p             ON s.CONTACT_ID = p.ID
                LEFT JOIN CONTACTS_LEAD_STATUS AS cls ON s.STATUS_ID = cls.ID
                QUALIFY ROW_NUMBER() OVER (PARTITION BY s.CONTACT_ID ORDER BY s.STAMP DESC, s.ID DESC) = 1
            )
            SELECT p.ID AS CONTACT_ID,
                   CONCAT(p.FIRSTNAME, ' ', COALESCE(p.LASTNAME, '')) AS CLIENT,
                   TO_CHAR(p.CREATED_LOCAL, 'YYYY-MM-DD HH24:MI:SS') AS CREATED_LOCAL,
                   ps.STATUS
            FROM period_contacts AS p
            LEFT JOIN period_status AS ps ON ps.CONTACT_ID = p.ID
            WHERE COALESCE(ps.STATUS, '') <> '{$duplicate}'
            ORDER BY p.ID
        ";
    }

    private function printProgressLaw(array $invoice): void
    {
        $this->newLine();
        $this->line('<options=bold>Progress Law</>');
        $this->row('Leads', number_format($invoice['lead_count']));
        $this->row('Rate per lead', $this->money(self::PLAW_RATE_PER_LEAD_CENTS));
        $this->row('Total due', $this->money($invoice['total_cents']));

        if ($invoice['azure_count'] === $invoice['lead_count']) {
            $this->row('Cross-check', 'TblContactsPLAW has ' . number_format($invoice['azure_count']) . ', matches');

            return;
        }

        $this->warn(sprintf(
            '  [WARN] TblContactsPLAW has %s leads against %s in Snowflake. The contact sync may not have '
                . 'caught up; the Snowflake figure is the one billed.',
            number_format($invoice['azure_count']),
            number_format($invoice['lead_count'])
        ));
    }

    /** @return array<string, mixed> The invoice as InvoicePdf expects it. */
    private function progressLawDocument(array $invoice, array $period): array
    {
        $number = self::INVOICE_PREFIX['PLAW'] . '-' . $period['suffix'];

        return $this->documentBase('PLAW', $number, $period) + [
            'columns' => ['quantity' => 'Leads', 'rate' => 'Rate'],
            'lines' => [[
                'description' => 'Lead generation',
                'detail' => "Leads created {$period['range']} (Pacific Time)",
                'quantity' => number_format($invoice['lead_count']),
                'rate' => $this->money(self::PLAW_RATE_PER_LEAD_CENTS),
                'amount' => $this->money($invoice['total_cents']),
            ]],
            'totals' => [],
            'total_due' => $this->money($invoice['total_cents']),
            'notes' => [
                'Lead count excludes deleted, co-applicant, duplicate and incomplete records.',
                "Please include invoice number {$number} with your payment. Questions: " . $this->liveSender(),
            ],
        ];
    }

    private function progressLawBackup(array $invoice, array $period, string $number): string
    {
        $book = new InvoiceBackupWorkbook();
        $book->addSheet(
            'Leads',
            "Lending Tower invoice {$number}: Progress Law, {$period['month']}",
            sprintf(
                '%s leads x %s = %s',
                number_format($invoice['lead_count']),
                $this->money(self::PLAW_RATE_PER_LEAD_CENTS),
                $this->money($invoice['total_cents'])
            ),
            ['Contact ID', 'Client', 'Created (Pacific)', 'Status'],
            array_map(static fn (array $lead): array => [
                (string) ($lead['CONTACT_ID'] ?? ''),
                (string) ($lead['CLIENT'] ?? ''),
                (string) ($lead['CREATED_LOCAL'] ?? ''),
                (string) ($lead['STATUS'] ?? ''),
            ], $invoice['leads'])
        );

        return $book->toBytes();
    }

    // -------------------------------------------------------------------------
    // LDR
    // -------------------------------------------------------------------------

    private function ldrInvoice(DBConnector $azure, string $start, string $end): array
    {
        $enrollments = $this->sqlServerRows($azure, self::LDR_ENROLLMENTS_SQL, [$start, $end]);
        $deductions = $this->sqlServerRows(
            $azure,
            self::LDR_DEDUCTIONS_SQL,
            [$end, $start, $end, $start, $end, $start, $start]
        );

        return $this->summarizeLdr($enrollments, $deductions, $start, $end);
    }

    /**
     * The LDR invoice figures from the fetched rows. Kept apart from the queries so the arithmetic
     * can be checked without a database.
     *
     * @param  array<int, array<string, mixed>>  $enrollments
     * @param  array<int, array<string, mixed>>  $deductions
     */
    private function summarizeLdr(array $enrollments, array $deductions, string $start, string $end): array
    {
        $grossBasis = $this->sumBasis($enrollments, 'LDR enrollment');
        $deductionBasis = $this->sumBasis($deductions, 'LDR lookback deduction');
        $gross = $this->ldrFee($grossBasis['cents']);
        $deduction = $this->ldrFee($deductionBasis['cents']);

        $events = ['lookback' => 0, 'cancelled' => 0, 'both' => 0];
        foreach ($deductions as $row) {
            $lookback = $this->inPeriod($row['Lookback_Date'] ?? null, $start, $end);
            $cancelled = $this->inPeriod($row['Cancel_Date'] ?? null, $start, $end);
            $events[$lookback && $cancelled ? 'both' : ($lookback ? 'lookback' : 'cancelled')]++;
        }

        return [
            'enrollments' => $enrollments,
            'deductions' => $deductions,
            'gross_basis_cents' => $grossBasis['cents'],
            'deduction_basis_cents' => $deductionBasis['cents'],
            'gross_cents' => $gross,
            'deduction_cents' => $deduction,
            'total_cents' => $gross - $deduction,
            'deduction_events' => $events,
            'used_debt_amount' => count(array_filter(
                $enrollments,
                static fn (array $row): bool => ($row['Debt_Source'] ?? '') === 'Debt_Amount'
            )),
            'zero_basis' => $grossBasis['zero'],
            'missing_basis' => array_merge($grossBasis['missing'], $deductionBasis['missing']),
            'repeated_enrollments' => $this->duplicateValues(array_column($enrollments, 'LLG_ID')),
            'repeated_deductions' => $this->duplicateValues(array_column($deductions, 'LLG_ID')),
        ];
    }

    private function printLdr(array $invoice): void
    {
        $events = $invoice['deduction_events'];

        $this->newLine();
        $this->line('<options=bold>LDR</>');
        $this->row('Enrollments (first payment cleared)', sprintf(
            '%s (%s used Debt_Amount because Sold_Debt is empty)',
            number_format(count($invoice['enrollments'])),
            number_format($invoice['used_debt_amount'])
        ));
        $this->row('Debt basis', $this->money($invoice['gross_basis_cents']));
        $this->row('Gross at ' . $this->ldrRateLabel(), $this->money($invoice['gross_cents']));
        $this->row('Lookback deduction', $this->money(-$invoice['deduction_cents']));
        $this->row('', sprintf(
            '%s clients, debt %s (lookback %d, cancelled %d, both %d)',
            number_format(count($invoice['deductions'])),
            $this->money($invoice['deduction_basis_cents']),
            $events['lookback'],
            $events['cancelled'],
            $events['both']
        ));
        $this->row('Total due', $this->money($invoice['total_cents']));

        if ($invoice['repeated_enrollments'] !== []) {
            $this->warn(sprintf(
                '  [WARN] %d client(s) appear more than once in the billed enrollments and would be billed twice: %s',
                count($invoice['repeated_enrollments']),
                implode(', ', array_slice($invoice['repeated_enrollments'], 0, 10))
            ));
        }

        if ($invoice['repeated_deductions'] !== []) {
            $this->warn(sprintf(
                '  [WARN] %d client(s) appear more than once in the deductions and would be deducted twice: %s',
                count($invoice['repeated_deductions']),
                implode(', ', array_slice($invoice['repeated_deductions'], 0, 10))
            ));
        }

        if ($invoice['missing_basis'] !== []) {
            $this->warn(sprintf(
                '  [WARN] %d row(s) have neither Sold_Debt nor Debt_Amount and count as $0: %s',
                count($invoice['missing_basis']),
                implode(', ', array_slice($invoice['missing_basis'], 0, 10))
            ));
        }

        if ($invoice['zero_basis'] > 0) {
            $this->warn("  [WARN] {$invoice['zero_basis']} billed enrollment(s) have a debt basis of exactly \$0.");
        }

        if ($invoice['total_cents'] < 0) {
            $this->warn('  [WARN] The deduction is larger than the gross, so the total is a credit.');
        }
    }

    /** @return array<string, mixed> The invoice as InvoicePdf expects it. */
    private function ldrDocument(array $invoice, array $period): array
    {
        $number = self::INVOICE_PREFIX['LDR'] . '-' . $period['suffix'];
        $rate = $this->ldrRateLabel();

        return $this->documentBase('LDR', $number, $period) + [
            'columns' => ['quantity' => 'Debt basis', 'rate' => 'Rate'],
            'lines' => [[
                'description' => 'Enrollment fee',
                'detail' => number_format(count($invoice['enrollments'])) . " enrollments with first payment cleared in {$period['month']}",
                'quantity' => $this->money($invoice['gross_basis_cents']),
                'rate' => $rate,
                'amount' => $this->money($invoice['gross_cents']),
            ]],
            'totals' => [
                ['label' => 'Gross bill', 'amount' => $this->money($invoice['gross_cents'])],
                [
                    'label' => 'Lookback Deduction',
                    'amount' => $this->money(-$invoice['deduction_cents']),
                    'detail' => sprintf(
                        '%s of %s for %s previously billed clients with a lookback or cancellation in %s',
                        $rate,
                        $this->money($invoice['deduction_basis_cents']),
                        number_format(count($invoice['deductions'])),
                        $period['month']
                    ),
                ],
            ],
            'total_due' => $this->money($invoice['total_cents']),
            'notes' => [
                'Debt basis is sold debt, or the enrolled debt amount where no sold debt is recorded.',
                "Please include invoice number {$number} with your payment. Questions: " . $this->liveSender(),
            ],
        ];
    }

    private function ldrBackup(array $invoice, array $period, string $number): string
    {
        $rate = $this->ldrRateLabel();
        $title = "Lending Tower invoice {$number}: Liberty Debt Relief, {$period['month']}";

        $book = new InvoiceBackupWorkbook();
        $book->addSheet(
            'Enrollments',
            $title,
            sprintf(
                '%s enrollments, debt basis %s, gross at %s = %s',
                number_format(count($invoice['enrollments'])),
                $this->money($invoice['gross_basis_cents']),
                $rate,
                $this->money($invoice['gross_cents'])
            ),
            ['LLG ID', 'Client', 'First Payment Cleared', 'Debt Source', 'Debt Basis'],
            array_map(fn (array $row): array => [
                (string) ($row['LLG_ID'] ?? ''),
                (string) ($row['Client'] ?? ''),
                (string) ($row['First_Payment_Cleared_Date'] ?? ''),
                (string) ($row['Debt_Source'] ?? ''),
                $this->dollars($row['Debt_Basis'] ?? null),
            ], $invoice['enrollments']),
            [4]
        );
        $book->addSheet(
            'Lookback Deduction',
            $title,
            sprintf(
                '%s clients, debt basis %s, deduction at %s = %s',
                number_format(count($invoice['deductions'])),
                $this->money($invoice['deduction_basis_cents']),
                $rate,
                $this->money($invoice['deduction_cents'])
            ),
            ['LLG ID', 'Client', 'First Payment Cleared', 'Lookback Date', 'Cancel Date', 'Debt Basis'],
            array_map(fn (array $row): array => [
                (string) ($row['LLG_ID'] ?? ''),
                (string) ($row['Client'] ?? ''),
                (string) ($row['First_Payment_Cleared_Date'] ?? ''),
                (string) ($row['Lookback_Date'] ?? ''),
                (string) ($row['Cancel_Date'] ?? ''),
                $this->dollars($row['Debt_Basis'] ?? null),
            ], $invoice['deductions']),
            [5]
        );

        return $book->toBytes();
    }

    private function ldrRateLabel(): string
    {
        return rtrim(rtrim(number_format(self::LDR_FEE_BASIS_POINTS / 100, 2), '0'), '.') . '%';
    }

    // -------------------------------------------------------------------------
    // Documents and delivery
    // -------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function documentBase(string $company, string $number, array $period): array
    {
        return [
            'number' => $number,
            'issue_date' => $period['issue_date'],
            'due_date' => $period['due_date'],
            'terms' => 'Net ' . self::TERMS_DAYS,
            'period' => $period['range'],
            'from' => self::LENDING_TOWER + ['email' => $this->liveSender()],
            'bill_to' => self::BILL_TO[$company],
            'logo_data_uri' => null,
        ];
    }

    /**
     * Render each invoice and its backup, then save, test-send or send live.
     *
     * @param  array<string, array<string, mixed>>  $invoices
     */
    private function deliver(array $invoices, array $period, DBConnector $azure, string $saveDir, string $testTo, bool $live): bool
    {
        $renderer = new InvoicePdf($this->pdfWorkDir());
        $logo = $this->logoDataUri();
        $allSent = true;

        $this->newLine();

        foreach ($invoices as $company => $invoice) {
            $document = $company === 'PLAW'
                ? $this->progressLawDocument($invoice, $period)
                : $this->ldrDocument($invoice, $period);
            $document['logo_data_uri'] = $logo;
            $number = $document['number'];

            $backup = $company === 'PLAW'
                ? $this->progressLawBackup($invoice, $period, $number)
                : $this->ldrBackup($invoice, $period, $number);

            $files = [
                "{$number}.pdf" => $renderer->render($document),
                "{$number} backup.xlsx" => $backup,
            ];

            if ($saveDir !== '') {
                $this->saveFiles($saveDir, $files);
            }

            if ($testTo !== '') {
                $allSent = $this->sendTest($company, $document, $files, $period, $testTo) && $allSent;
            } elseif ($live) {
                $allSent = $this->sendLive($company, $document, $files, $period, $azure) && $allSent;
            }
        }

        return $allSent;
    }

    /** @param array<string, string> $files */
    private function sendTest(string $company, array $document, array $files, array $period, string $to): bool
    {
        $notice = sprintf(
            'Review copy sent only to %s. The real invoice goes to the recipients in TblReports under "%s", from %s.',
            $to,
            self::REPORT_NAMES[$company],
            $this->liveSender()
        );

        $sent = (new EmailSenderService())->sendMailHtml(
            '[TEST] ' . $this->subject($company, $document, $period),
            $this->emailBody($company, $document, $period, $notice),
            [$to],
            [],
            [],
            $this->attachments($files)
        );

        $sent
            ? $this->info("[TEST] {$document['number']} ({$document['total_due']}) sent to {$to}")
            : $this->error("[ERROR] Test email for {$document['number']} was not sent; see laravel.log for the Graph error.");

        return $sent;
    }

    /** @param array<string, string> $files */
    private function sendLive(string $company, array $document, array $files, array $period, DBConnector $azure): bool
    {
        $number = $document['number'];
        $disk = Storage::disk('local');
        $folder = self::ARCHIVE_PREFIX . '/' . $period['suffix'];
        $marker = "{$folder}/{$number}.sent.json";

        if ($disk->exists($marker) && ! $this->option('force')) {
            $this->warn("[SKIP] {$number} was already sent (see storage/app/private/{$marker}). Use --force to send it again.");

            return true;
        }

        $sent = (new EmailSenderService())->sendMailUsingTblReportsHtml(
            $azure,
            [self::REPORT_NAMES[$company]],
            [],
            $this->subject($company, $document, $period),
            $this->emailBody($company, $document, $period, null),
            $this->attachments($files),
            includeEnvExtras: false, // never add internal extra recipients to another company's invoice
            strictCompany: false,
            fromAddress: $this->liveSender()
        );

        if (! $sent) {
            $this->error(sprintf(
                '[ERROR] %s was not sent. Check TblReports has a row for "%s" and that %s is a mailbox Graph can send from.',
                $number,
                self::REPORT_NAMES[$company],
                $this->liveSender()
            ));

            return false;
        }

        // Archive exactly what went out, then the marker that stops a second send.
        foreach ($files as $name => $bytes) {
            $disk->put("{$folder}/{$name}", $bytes);
        }
        $disk->put($marker, (string) json_encode([
            'number' => $number,
            'sent_at' => (new DateTimeImmutable('now', new DateTimeZone(self::TIMEZONE)))->format(DATE_ATOM),
            'total_due' => $document['total_due'],
            'report_name' => self::REPORT_NAMES[$company],
            'from' => $this->liveSender(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        Log::info('GenerateLendingTowerInvoices: invoice sent', ['number' => $number, 'total_due' => $document['total_due']]);
        $this->info("[SENT] {$number} ({$document['total_due']})");

        return true;
    }

    private function subject(string $company, array $document, array $period): string
    {
        return $company === 'PLAW'
            ? "Lending Tower Invoice {$document['number']} - Lead Generation, {$period['month']}"
            : "Lending Tower Invoice {$document['number']} - {$period['month']}";
    }

    private function emailBody(string $company, array $document, array $period, ?string $testNotice): string
    {
        $e = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $subject = $company === 'PLAW' ? "lead generation for {$period['month']}" : "{$period['month']} enrollments";

        $notice = $testNotice === null ? '' : '<p style="padding:8px 12px;background:#fff4d6;border:1px solid #f0c36d;">'
            . '<strong>TEST</strong> ' . $e($testNotice) . '</p>';

        return $notice
            . '<p>Hello,</p>'
            . '<p>Please refer to the attached invoice for ' . $e($subject) . '.</p>'
            . '<p>Invoice ' . $e($document['number']) . '<br>Total due ' . $e($document['total_due'])
            . '<br>Due ' . $e($document['due_date']) . '</p>'
            . '<p>The attached spreadsheet lists the clients behind the total.</p>'
            . '<p>Thank you,<br>Lending Tower</p>';
    }

    /**
     * @param  array<string, string>  $files
     * @return list<array{name: string, contentType: string, contentBytes: string}>
     */
    private function attachments(array $files): array
    {
        $attachments = [];

        foreach ($files as $name => $bytes) {
            $attachments[] = [
                'name' => $name,
                'contentType' => str_ends_with($name, '.pdf')
                    ? 'application/pdf'
                    : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'contentBytes' => base64_encode($bytes),
            ];
        }

        return $attachments;
    }

    /** @param array<string, string> $files */
    private function saveFiles(string $dir, array $files): void
    {
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException("Cannot create {$dir}.");
        }

        foreach ($files as $name => $bytes) {
            $path = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $name;

            if (file_put_contents($path, $bytes) === false) {
                throw new RuntimeException("Cannot write {$path}.");
            }

            $this->line("  Saved {$path} (" . number_format(strlen($bytes)) . ' bytes)');
        }
    }

    private function liveSender(): string
    {
        return (string) env('LENDING_TOWER_INVOICE_FROM', self::INVOICE_CONTACT);
    }

    private function logoDataUri(): ?string
    {
        $path = __DIR__ . '/assets/lending-tower-logo.png';

        if (! is_file($path)) {
            $this->warn("[WARN] Logo not found at {$path}; the invoices will show a text wordmark instead.");

            return null;
        }

        return 'data:image/png;base64,' . base64_encode((string) file_get_contents($path));
    }

    /** Per OS user, so a manual run never leaves dompdf files the scheduler's user cannot write. */
    private function pdfWorkDir(): string
    {
        $user = function_exists('posix_geteuid')
            ? (string) posix_geteuid()
            : (string) preg_replace('/\W/', '', (string) get_current_user());

        return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lending-tower-invoices-' . $user;
    }

    // -------------------------------------------------------------------------
    // Money
    // -------------------------------------------------------------------------

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{cents: int, missing: list<string>, zero: int}
     */
    private function sumBasis(array $rows, string $context): array
    {
        $cents = 0;
        $missing = [];
        $zero = 0;

        foreach ($rows as $row) {
            $id = (string) ($row['LLG_ID'] ?? '?');
            $value = $this->toCents($row['Debt_Basis'] ?? null, "{$context} {$id}");

            if ($value === null) {
                $missing[] = $id;
                continue;
            }

            if ($value === 0) {
                $zero++;
            }

            $cents += $value;
        }

        return ['cents' => $cents, 'missing' => $missing, 'zero' => $zero];
    }

    /** The LDR fee on a debt basis, rounded half up to the cent. */
    private function ldrFee(int $basisCents): int
    {
        if ($basisCents < 0) {
            throw new RuntimeException('LDR debt basis is negative (' . $this->money($basisCents) . ').');
        }

        return intdiv($basisCents * self::LDR_FEE_BASIS_POINTS + 5000, 10000);
    }

    /**
     * Parse a DECIMAL(18,2) value into integer cents without going through a float.
     *
     * pdo_sqlsrv returns decimals as strings and drops the leading zero below one (".67"), so
     * both shapes are accepted. More than two non-zero decimal places is refused rather than
     * silently truncated.
     */
    private function toCents(mixed $value, string $context): ?int
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            return $value * 100;
        }

        $text = trim((string) $value);

        if (preg_match('/^(-?)(\d*)(?:\.(\d*))?$/', $text, $m) !== 1 || ($m[2] === '' && ($m[3] ?? '') === '')) {
            throw new RuntimeException("Unexpected money value '{$text}' for {$context}.");
        }

        $fraction = $m[3] ?? '';

        if (strlen($fraction) > 2 && trim(substr($fraction, 2), '0') !== '') {
            throw new RuntimeException("Money value '{$text}' for {$context} has more than two decimal places.");
        }

        $cents = (int) ($m[2] === '' ? '0' : $m[2]) * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');

        return $m[1] === '-' ? -$cents : $cents;
    }

    /** A DECIMAL(18,2) value as a float for the backup workbook, where it is only displayed. */
    private function dollars(mixed $value): ?float
    {
        $cents = $this->toCents($value, 'backup workbook');

        return $cents === null ? null : $cents / 100;
    }

    private function money(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);

        return sprintf('%s$%s.%02d', $sign, number_format(intdiv($cents, 100)), $cents % 100);
    }

    // -------------------------------------------------------------------------
    // Data access and helpers
    // -------------------------------------------------------------------------

    /**
     * query() throws on any failure, so an array here is a complete result.
     *
     * @return array<int, array<string, mixed>>
     */
    private function snowflakeRows(DBConnector $connector, string $sql): array
    {
        $rows = $connector->query($sql)['data'] ?? null;

        if (! is_array($rows)) {
            throw new RuntimeException('Snowflake returned no result set.');
        }

        return $rows;
    }

    /**
     * querySqlServer() reports failure as success=false with an empty data array. Reading only
     * `data` would turn a failed query into zero rows, which on an invoice means a $0 deduction or
     * a missing charge, so failure is raised instead.
     *
     * @return array<int, array<string, mixed>>
     */
    private function sqlServerRows(DBConnector $connector, string $sql, array $params): array
    {
        $result = $connector->querySqlServer($sql, $params);

        if (($result['success'] ?? false) !== true) {
            throw new RuntimeException('SQL Server query failed: ' . ($result['error'] ?? 'unknown error'));
        }

        return $result['data'] ?? [];
    }

    private function inPeriod(mixed $date, string $start, string $end): bool
    {
        $date = $date === null ? '' : trim((string) $date);

        return $date !== '' && $date >= $start && $date < $end;
    }

    /** @return list<string> */
    private function duplicateValues(array $values): array
    {
        $counts = array_count_values(array_map('strval', $values));

        return array_map('strval', array_keys(array_filter($counts, static fn (int $n): bool => $n > 1)));
    }

    private function row(string $label, string $value): void
    {
        $this->line(sprintf('  %-38s %s', $label, $value));
    }
}
