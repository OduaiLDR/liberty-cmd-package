<?php

namespace Cmd\Reports\Console\Commands\GenerateLendingTowerInvoices;

use Cmd\Reports\Invoices\InvoiceRegistryGate;
use Cmd\Reports\Services\DBConnector;
use Cmd\Reports\Services\EmailSenderService;
use Cmd\Reports\Services\GraphMailboxClient;
use Cmd\Reports\Services\ReviewInvoiceNumbers;
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
 * Progress Law is billed for Lending Tower contacts in a PLAW-classified state, once in the month of
 * its first qualifying status (Pacific Time). Current status is shown separately in the backup.
 * Deleted contacts and co-applicants are excluded.
 *
 * LDR is billed on enrollments: 5% of the debt basis (Sold_Debt, else Debt_Amount) of LDR clients
 * whose first scheduled payment is in the month and clears by the following fifth. Clients cancelled
 * through the fourth are excluded. TblEnrollment holds both firms' enrollments, so LDR is
 * scoped by TblContactsLDR; Progress Law's clients are billed per lead on their own invoice.
 * The first billing cohort is October 2026. Lookback stays at zero until February 2027,
 * when October's actually billed cohort is due for review.
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
                            {--send-to-me : Send only to oduai@libertydebtrelief.com with normal invoice wording and no CC or BCC}
                            {--review-terms : Use separate company-to-company review numbers; only for private or saved previews}
                            {--test-to= : Email the invoices only to this address, from the normal report sender, marked TEST}
                            {--force : Send even if this invoice number was already sent}';

    protected $description = 'Generate the monthly Lending Tower invoices: Progress Law per lead, LDR per enrollment less lookback.';

    private const TIMEZONE = 'America/Los_Angeles';

    private const PLAW_RATE_PER_LEAD_CENTS = 30000;

    /** 5%, in basis points so the fee stays in integer arithmetic. */
    private const LDR_FEE_BASIS_POINTS = 500;
    private ?ReviewInvoiceNumbers $reviewNumbers = null;

    public function useReviewInvoiceNumbers(ReviewInvoiceNumbers $numbers): void
    {
        $this->reviewNumbers = $numbers;
    }


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

    /**
     * Invoice registry (TASK-006). Each invoice has its OWN switch, default off; when off, the number,
     * files, email and marker are exactly what they were before. Only live sends are registered.
     */
    private const REGISTRY_SERIES = ['PLAW' => 'LT-PL', 'LDR' => 'LT-LDR'];

    private const REGISTRY_SWITCH = ['PLAW' => 'INVOICE_LT_PL_ENABLED', 'LDR' => 'INVOICE_LT_LDR_ENABLED'];

    /** @var array<string, InvoiceRegistryGate|null> */
    private array $gates = [];

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
               CONVERT(char(10), e.First_Payment_Date, 23) AS First_Payment_Date,
               CONVERT(char(10), e.First_Payment_Cleared_Date, 23) AS First_Payment_Cleared_Date,
               CONVERT(char(10), e.Cancel_Date, 23) AS Cancel_Date,
               CASE WHEN e.Sold_Debt IS NULL THEN 'Debt_Amount' ELSE 'Sold_Debt' END AS Debt_Source,
               CAST(COALESCE(e.Sold_Debt, e.Debt_Amount) AS DECIMAL(18, 2)) AS Debt_Basis
        FROM TblEnrollment e
        WHERE e.First_Payment_Date >= ?
          AND e.First_Payment_Date < ?
          AND e.First_Payment_Cleared_Date IS NOT NULL
          AND e.First_Payment_Cleared_Date < ?
          AND (e.Cancel_Date IS NULL OR e.Cancel_Date >= ?)
          AND EXISTS (SELECT 1 FROM TblContactsLDR l WHERE l.LLG_ID = e.LLG_ID)
        ORDER BY e.First_Payment_Date, e.LLG_ID
        SQL;

    /** Inject a registry gate (tests); null forces the old behaviour for that company. */
    public function useInvoiceGate(string $company, ?InvoiceRegistryGate $gate): void
    {
        $this->gates[$company] = $gate;
    }

    private function invoiceGate(string $company): ?InvoiceRegistryGate
    {
        if (! isset(self::REGISTRY_SWITCH[$company])) {
            return null;
        }

        return $this->gates[$company] ??= InvoiceRegistryGate::forGenerator(
            self::REGISTRY_SWITCH[$company],
            alert: function (string $message, array $context): void {
                $this->error('[INVOICE REGISTRY] ' . $message);
                Log::error('[INVOICE REGISTRY] ' . $message, $context);
            }
        );
    }

    /** Marker of a live send, named for the period (not the number) so reruns and billingStart() keep working. */
    private function sentMarker(string $company, array $period): string
    {
        return self::ARCHIVE_PREFIX . '/' . $period['suffix'] . '/' . self::INVOICE_PREFIX[$company] . '-' . $period['suffix'] . '.sent.json';
    }

    /**
     * Reserve the new-style number for a live send that is really going out. Null (old number, nothing
     * changes) when the switch is off, the invoice was already sent, or the registry fails.
     *
     * @return array{gate: InvoiceRegistryGate, series: string, number: string, date: string}|null
     */
    private function registerNumber(string $company, array $period): ?array
    {
        $gate = $this->invoiceGate($company);
        if ($gate === null || (Storage::disk('local')->exists($this->sentMarker($company, $period)) && ! $this->option('force'))) {
            return null;
        }
        // Match the printed issue date: the first for PLAW, the fifth for LDR.
        $date = $this->invoiceDate($company, $period);
        $series = self::REGISTRY_SERIES[$company];
        $number = $gate->reserve($series, $date, ['generator' => 'lending-tower-invoices', 'company' => $company, 'period' => $period['month']]);

        return $number === null ? null : ['gate' => $gate, 'series' => $series, 'number' => $number, 'date' => $date];
    }

    /** @param array<string, string> $files */
    private function recordRegistered(array $registered, string $status, array $files, array $period, int $cents, array $extra = []): void
    {
        $number = $registered['number'];
        $registered['gate']->record($registered['series'], $number, [
            'amount' => $cents / 100,
            'invoiceDate' => $registered['date'],
            'status' => $status,
            'generator' => 'lending-tower-invoices',
            'period' => $period['month'],
        ] + $extra, [
            ['name' => "{$number}.pdf", 'kind' => 'pdf', 'bytes' => $files["{$number}.pdf"], 'contentType' => 'application/pdf'],
            ['name' => "{$number}-backup.xlsx", 'kind' => 'backup', 'bytes' => $files["{$number} backup.xlsx"], 'contentType' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        ]);
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $saveDir = trim((string) $this->option('save'));
        $testTo = trim((string) $this->option('test-to'));
        if ($this->option('send-to-me')) {
            if ($testTo !== '' && strcasecmp($testTo, 'oduai@libertydebtrelief.com') !== 0) {
                throw new \InvalidArgumentException('--send-to-me cannot be combined with a different --test-to recipient.');
            }
            $testTo = 'oduai@libertydebtrelief.com';
        }
        $live = ! $dryRun && $saveDir === '' && $testTo === '';
        if ($this->usingReviewTerms() && ($live || ($testTo !== '' && strcasecmp($testTo, 'oduai@libertydebtrelief.com') !== 0))) {
            $this->error('--review-terms is restricted to --send-to-me, --save or --dry-run; scheduled live delivery is unchanged.');
            return self::FAILURE;
        }

        if ($testTo !== '' && filter_var($testTo, FILTER_VALIDATE_EMAIL) === false) {
            $this->error("--test-to must be an email address, got '{$testTo}'.");

            return self::FAILURE;
        }

        if ($live && ! filter_var(env('LENDING_TOWER_INVOICES_LIVE', false), FILTER_VALIDATE_BOOLEAN)) {
            $this->error('Live sending is off. Use --dry-run, --save=DIR or --test-to=ADDRESS, or set '
                . 'LENDING_TOWER_INVOICES_LIVE=true once the invoices are approved.');

            return self::FAILURE;
        }

        $lock = $dryRun ? null : Cache::lock($live ? 'lending-tower-invoices' : 'lending-tower-invoices-review', 1800);
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

            if (in_array('LDR', $companies, true)) {
                $ldrDate = $this->invoiceDate('LDR', $period);
                if ($today < $ldrDate && $live) {
                    if (in_array('PLAW', $companies, true)) {
                        $companies = ['PLAW'];
                        $this->warn("[SKIP] LDR is due on {$ldrDate}. Continuing with Progress Law only.");
                    } else {
                        throw new RuntimeException("The LDR cutoff is {$ldrDate}; production invoices cannot be sent before the fifth.");
                    }
                }
                if ($today < $ldrDate) {
                    $this->warn("[WARN] LDR is a preview until {$ldrDate}; additional first payments may still clear.");
                }
            }

            $azure = DBConnector::fromEnvironment('ldr');
            $azure->initializeSqlServer();

            $invoices = [];

            if (in_array('PLAW', $companies, true)) {
                $invoices['PLAW'] = $this->progressLawInvoice($start, $end);
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
     * Shared billing-period labels. invoiceDate() resolves each company's fixed issue date.
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
            'range' => $first->format('F j') . ' - ' . $last->format('j, Y'),
            'last_day' => $last->format('Y-m-d'),
            'suffix' => $first->format('Y-m'),
            'issue_date' => $issue->format('F j, Y'),
            'due_date' => 'Upon Receipt',
        ];
    }

    private function invoiceDate(string $company, array $period): string
    {
        if ($company === 'LDR') {
            return LdrInvoiceWindow::forMonth($period['suffix'])['invoice_date'];
        }

        return (new DateTimeImmutable($period['last_day']))->modify('+1 day')->format('Y-m-d');
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

    /** @return array{leads: array, lead_count: int, total_cents: int} */
    private function progressLawInvoice(string $start, string $end): array
    {
        $states = UsStateClassification::progressLawStates();
        $leads = $this->snowflakeRows(
            DBConnector::fromEnvironment(ProgressLawLeadQuery::SOURCE),
            ProgressLawLeadQuery::sql($start, $end, $states)
        );

        return $this->summarizeProgressLaw($leads);
    }

    private function summarizeProgressLaw(array $leads): array
    {
        $repeated = $this->duplicateValues(array_column($leads, 'CONTACT_ID'));
        if ($repeated !== []) {
            throw new RuntimeException('Progress Law lead query returned a contact more than once: '
                . implode(', ', array_slice($repeated, 0, 10)));
        }

        return [
            'leads' => $leads,
            'lead_count' => count($leads),
            'total_cents' => count($leads) * $this->progressLawRateCents(),
        ];
    }

    private function printProgressLaw(array $invoice): void
    {
        $this->newLine();
        $this->line('<options=bold>Progress Law</>');
        $this->row('Leads (first qualifying status)', number_format($invoice['lead_count']));
        $this->row('Rate per lead', $this->money($this->progressLawRateCents()));
        $this->row('Total due', $this->money($invoice['total_cents']));
    }

    /** @return array<string, mixed> The invoice as InvoicePdf expects it. */
    private function progressLawDocument(array $invoice, array $period, ?string $registered = null): array
    {
        $number = $registered ?? self::INVOICE_PREFIX['PLAW'] . '-' . $period['suffix'];

        return $this->documentBase('PLAW', $number, $period) + [
            'columns' => ['quantity' => 'Leads', 'rate' => 'Rate'],
            'lines' => [[
                'description' => 'Lead generation',
                'detail' => "First qualifying status in {$period['range']} (Pacific Time)",
                'quantity' => number_format($invoice['lead_count']),
                'rate' => $this->money($this->progressLawRateCents()),
                'amount' => $this->money($invoice['total_cents']),
            ]],
            'totals' => [],
            'total_due' => $this->money($invoice['total_cents']),
            'notes' => [
                'Each contact in a PLAW-classified state is counted once, using its first qualifying status. Deleted contacts and co-applicants are excluded.',
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
                $this->money($this->progressLawRateCents()),
                $this->money($invoice['total_cents'])
            ),
            ['Contact ID', 'Client', 'State', 'Qualifying Status Date', 'Billable Status', 'Current Status'],
            array_map(static fn (array $lead): array => [
                (string) ($lead['CONTACT_ID'] ?? ''),
                (string) ($lead['CLIENT'] ?? ''),
                (string) ($lead['STATE'] ?? ''),
                substr((string) ($lead['STATUS_LOCAL'] ?? ''), 0, 10),
                (string) ($lead['BILLABLE_STATUS'] ?? ''),
                (string) ($lead['CURRENT_STATUS'] ?? ''),
            ], $invoice['leads'])
        );

        return $book->toBytes();
    }

    // -------------------------------------------------------------------------
    // LDR
    // -------------------------------------------------------------------------

    private function ldrInvoice(DBConnector $azure, string $start, string $end): array
    {
        $window = LdrInvoiceWindow::forMonth(substr($start, 0, 7));
        if ($start < LdrInvoiceWindow::FIRST_BILLING_MONTH . '-01') {
            throw new RuntimeException('The first LDR billing month is October 2026.');
        }

        $enrollments = $this->sqlServerRows($azure, self::LDR_ENROLLMENTS_SQL, [
            $start, $end, $window['cleared_before'], $window['cancelled_before'],
        ]);
        if ($this->duplicateValues(array_column($enrollments, 'LLG_ID')) !== []) {
            throw new RuntimeException('LDR enrollment query returned duplicate clients; invoice was not generated.');
        }
        $deductions = $window['lookback_enabled'] ? $this->ldrLookback($azure, $window) : [];

        return $this->summarizeLdr($enrollments, $deductions, $start, $end) + ['window' => $window];
    }

    private function ldrLookback(DBConnector $azure, array $window): array
    {
        $today = (new DateTimeImmutable('now', new DateTimeZone(self::TIMEZONE)))->format('Y-m-d');
        if ($today < $window['invoice_date']) {
            throw new RuntimeException('The LDR lookback cannot be finalized before ' . $window['invoice_date'] . '.');
        }
        $population = $this->billedLdrPopulation($window['lookback_month']);
        if ($population === []) {
            return [];
        }
        $current = [];
        foreach (array_chunk(array_column($population, 'LLG_ID'), 400) as $ids) {
            $placeholders = implode(', ', array_fill(0, count($ids), '?'));
            $rows = $this->sqlServerRows($azure, "SELECT LLG_ID,
                CONVERT(char(10), Cancel_Date, 23) AS Cancel_Date,
                Payments, Payment_Frequency FROM TblEnrollment WHERE LLG_ID IN ({$placeholders})", $ids);
            foreach ($rows as $row) {
                $id = (string) $row['LLG_ID'];
                if (isset($current[$id])) {
                    throw new RuntimeException("Azure has duplicate enrollment rows for billed client {$id}.");
                }
                $current[$id] = $row;
            }
        }
        // Match SyncEnrollmentData's two-source reconciliation and monthly-equivalent normalization,
        // but stop each transaction history at that client's original first-clear + 3 months.
        $counts = [];
        foreach (['ldr', 'plaw'] as $source) {
            $connector = DBConnector::fromEnvironment($source);
            foreach (array_chunk($population, 200) as $batch) {
                foreach ($this->snowflakeRows($connector, LdrInvoiceLookback::paymentSql($batch)) as $row) {
                    $id = (string) $row['CONTACT_ID'];
                    $counts[$id] = max($counts[$id] ?? 0, (int) $row['PAYMENT_COUNT']);
                }
            }
        }
        return LdrInvoiceLookback::evaluate($population, $current, $counts);
    }

    private function billedLdrPopulation(string $month): array
    {
        $disk = Storage::disk('local');
        $folder = self::ARCHIVE_PREFIX . '/' . $month;
        $marker = $folder . '/LT-LDR-' . $month . '.sent.json';
        if (!$disk->exists($marker)) {
            $this->warn("[WARN] No sent LDR invoice is recorded for {$month}; no clients from that month will be taken back.");
            return [];
        }
        $receipt = json_decode($disk->get($marker), true, 512, JSON_THROW_ON_ERROR);
        $path = $receipt['population_path'] ?? '';
        if (!is_string($path) || !str_starts_with($path, $folder . '/') || str_contains($path, '..') || !$disk->exists($path)) {
            throw new RuntimeException("The {$month} invoice has no retained billed population. Reconcile its actual billed clients before applying any lookback.");
        }
        $snapshot = json_decode($disk->get($path), true, 512, JSON_THROW_ON_ERROR);
        $expected = LdrInvoiceWindow::forMonth($month);
        if (($snapshot['version'] ?? null) !== 1 || ($snapshot['billing_month'] ?? null) !== $month
            || ($snapshot['invoice_number'] ?? null) !== ($receipt['number'] ?? null)
            || ($snapshot['window'] ?? null) !== $expected
            || ($snapshot['fee_basis_points'] ?? null) !== $this->ldrBasisPoints()
            || !is_array($snapshot['enrollments'] ?? null)) {
            throw new RuntimeException("The {$month} billed population does not match its invoice receipt and billing policy.");
        }
        $population = $snapshot['enrollments'];
        if ($this->duplicateValues(array_column($population, 'LLG_ID')) !== []) {
            throw new RuntimeException('The retained LDR billed population contains duplicate clients.');
        }
        foreach ($population as $row) {
            $first = (string) ($row['First_Payment_Date'] ?? '');
            $clear = (string) ($row['First_Payment_Cleared_Date'] ?? '');
            $cancel = (string) ($row['Cancel_Date'] ?? '');
            LdrInvoiceWindow::paymentCutoff($clear);
            if (empty($row['LLG_ID']) || $first < $expected['start'] || $first >= $expected['end']
                || $clear >= $expected['cleared_before'] || ($cancel !== '' && $cancel < $expected['cancelled_before'])) {
                throw new RuntimeException('The retained LDR population contains a client outside the original billing rules.');
            }
        }
        if ($this->sumBasis($population, 'retained LDR enrollment')['cents'] !== ($snapshot['gross_basis_cents'] ?? null)) {
            throw new RuntimeException('The retained LDR debt basis does not reconcile to its billed population.');
        }
        return $population;
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
            $lookback = (bool) ($row['Insufficient_Payments'] ?? false);
            $cancelled = (bool) ($row['Cancelled_By_Cutoff'] ?? false);
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
        $this->row('Enrollments (first payment due in billing month)', sprintf(
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
        $window = $invoice['window'];
        $this->row('First payment clears by', (new DateTimeImmutable($window['invoice_date']))->format('F j, Y'));
        $this->row('Exclude cancellations through', (new DateTimeImmutable($window['cancelled_before']))->modify('-1 day')->format('F j, Y'));
        $this->row('Lookback', $window['lookback_enabled'] ? $window['lookback_month'] : '$0 until the February 5, 2027 invoice');

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
    private function ldrDocument(array $invoice, array $period, ?string $registered = null): array
    {
        $number = $registered ?? self::INVOICE_PREFIX['LDR'] . '-' . $period['suffix'];
        $rate = $this->ldrRateLabel();

        return $this->documentBase('LDR', $number, $period) + [
            'columns' => ['quantity' => 'Debt basis', 'rate' => 'Rate'],
            'lines' => [[
                'description' => 'Enrollment fee',
                'detail' => number_format(count($invoice['enrollments'])) . " enrollments with first payment due in {$period['month']}, cleared by "
                    . (new DateTimeImmutable($invoice['window']['invoice_date']))->format('F j, Y')
                    . '; excludes cancellations through '
                    . (new DateTimeImmutable($invoice['window']['cancelled_before']))->modify('-1 day')->format('F j, Y'),
                'quantity' => $this->money($invoice['gross_basis_cents']),
                'rate' => $rate,
                'amount' => $this->money($invoice['gross_cents']),
            ]],
            'totals' => [
                ['label' => 'Gross bill', 'amount' => $this->money($invoice['gross_cents'])],
                [
                    'label' => 'Lookback Deduction',
                    'amount' => $this->money(-$invoice['deduction_cents']),
                    'detail' => !$invoice['window']['lookback_enabled'] ? 'No lookback applies before the February 5, 2027 invoice.' : sprintf(
                        '%s of %s for %s clients billed for %s who cancelled or had fewer than 3 payment equivalents by their first cleared payment + 3 months',
                        $rate,
                        $this->money($invoice['deduction_basis_cents']),
                        number_format(count($invoice['deductions'])),
                        (new DateTimeImmutable($invoice['window']['lookback_start']))->format('F Y')
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
            ['LLG ID', 'Client', 'First Payment Date', 'First Payment Cleared', 'Cancel Date', 'Debt Source', 'Debt Basis'],
            array_map(fn (array $row): array => [
                (string) ($row['LLG_ID'] ?? ''),
                (string) ($row['Client'] ?? ''),
                (string) ($row['First_Payment_Date'] ?? ''),
                (string) ($row['First_Payment_Cleared_Date'] ?? ''),
                (string) ($row['Cancel_Date'] ?? ''),
                (string) ($row['Debt_Source'] ?? ''),
                $this->dollars($row['Debt_Basis'] ?? null),
            ], $invoice['enrollments']),
            [6]
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
            ['LLG ID', 'Client', 'First Payment Date', 'First Payment Cleared', 'Individual Cutoff',
                'Cancel Date', 'Payments at Cutoff', 'Azure Payments (Current)', 'Payment Frequency', 'Reason', 'Billed Debt Basis'],
            array_map(fn (array $row): array => [
                (string) ($row['LLG_ID'] ?? ''),
                (string) ($row['Client'] ?? ''),
                (string) ($row['First_Payment_Date'] ?? ''),
                (string) ($row['First_Payment_Cleared_Date'] ?? ''),
                (string) ($row['Lookback_Cutoff'] ?? ''),
                (string) ($row['Lookback_Cancel_Date'] ?? ''),
                $row['Payments_At_Cutoff'] ?? 0,
                $row['Azure_Payments_Current'] ?? '',
                (string) ($row['Payment_Frequency'] ?? ''),
                (string) ($row['Deduction_Reason'] ?? ''),
                $this->dollars($row['Debt_Basis'] ?? null),
            ], $invoice['deductions']),
            [10]
        );

        return $book->toBytes();
    }

    private function ldrRateLabel(): string
    {
        return rtrim(rtrim(number_format($this->ldrBasisPoints() / 100, 2), '0'), '.') . '%';
    }

    private function usingReviewTerms(): bool
    {
        return $this->input !== null && (bool) $this->option('review-terms');
    }

    private function ldrBasisPoints(): int
    {
        return self::LDR_FEE_BASIS_POINTS;
    }

    private function progressLawRateCents(): int
    {
        return self::PLAW_RATE_PER_LEAD_CENTS;
    }

    // -------------------------------------------------------------------------
    // Documents and delivery
    // -------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function documentBase(string $company, string $number, array $period): array
    {
        return [
            'number' => $number,
            'issue_date' => (new DateTimeImmutable($this->invoiceDate($company, $period)))->format('F j, Y'),
            'due_date' => $period['due_date'],
            'terms' => 'Upon Receipt',
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
            if ($this->usingReviewTerms() && ($live || ($testTo !== '' && strcasecmp($testTo, 'oduai@libertydebtrelief.com') !== 0))) {
                throw new RuntimeException('Review terms cannot be used for live or third-party delivery.');
            }
            $registered = $live ? $this->registerNumber($company, $period) : null;
            $reviewNumber = null;
            if ($this->usingReviewTerms()) {
                $this->reviewNumbers ??= ReviewInvoiceNumbers::fromLocalStorage();
                $reviewNumber = $this->reviewNumbers->next('LT', $company === 'PLAW' ? 'PL' : 'LDR',
                    $this->invoiceDate($company, $period), $testTo !== '');
            }
            $document = $company === 'PLAW'
                ? $this->progressLawDocument($invoice, $period, $reviewNumber ?? $registered['number'] ?? null)
                : $this->ldrDocument($invoice, $period, $reviewNumber ?? $registered['number'] ?? null);
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
                $population = $company === 'LDR' ? [
                    'version' => 1,
                    'invoice_number' => $number,
                    'billing_month' => $period['suffix'],
                    'window' => $invoice['window'],
                    'fee_basis_points' => $this->ldrBasisPoints(),
                    'gross_basis_cents' => $invoice['gross_basis_cents'],
                    'enrollments' => $invoice['enrollments'],
                ] : null;
                $allSent = $this->sendLive($company, $document, $files, $period, $azure,
                    $registered === null ? null : $registered + ['cents' => (int) $invoice['total_cents']], $population) && $allSent;
            }
        }

        return $allSent;
    }

    /** @param array<string, string> $files */
    private function sendTest(string $company, array $document, array $files, array $period, string $to): bool
    {
        $unmarked = (bool) $this->option('send-to-me');
        $notice = $unmarked ? null : sprintf(
            'Review copy sent only to %s, from the same mailbox as the real invoice. The real one goes to the recipients in TblReports under "%s".',
            $to,
            self::REPORT_NAMES[$company]
        );

        $sent = $this->mailer()->sendMailHtml(
            ($unmarked ? '' : '[TEST] ') . $this->subject($company, $document, $period),
            $this->emailBody($company, $document, $period, $notice),
            [$to],
            [],
            [],
            $this->attachments($files),
            $this->liveSender()
        );

        $sent
            ? $this->info(($unmarked ? '[INFO] ' : '[TEST] ') . "{$document['number']} ({$document['total_due']}) sent only to {$to}")
            : $this->error("[ERROR] Invoice email for {$document['number']} was not sent; see laravel.log for the Graph error.");

        return $sent;
    }

    /** @param array<string, string> $files */
    private function sendLive(string $company, array $document, array $files, array $period, DBConnector $azure, ?array $registered = null, ?array $population = null): bool
    {
        $number = $document['number'];
        $disk = Storage::disk('local');
        $folder = self::ARCHIVE_PREFIX . '/' . $period['suffix'];
        $marker = $this->sentMarker($company, $period);

        if ($disk->exists($marker) && ! $this->option('force')) {
            $this->warn("[SKIP] {$number} was already sent (see storage/app/private/{$marker}). Use --force to send it again.");

            return true;
        }

        // A receipt links this exact population only after email succeeds. A failed send's
        // unlinked snapshot is never evidence that any client was billed.
        $populationPath = $population === null ? null : "{$folder}/{$number}." . bin2hex(random_bytes(8)) . '.population.json';
        if ($populationPath !== null && !$disk->put($populationPath, json_encode($population, JSON_THROW_ON_ERROR))) {
            throw new RuntimeException('Could not retain the LDR billed population; invoice email was not sent.');
        }

        $sent = $this->mailer()->sendMailUsingTblReportsHtml(
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
            if ($registered !== null) {
                // The number is spent even though the email failed; show it in the log as issued, not sent.
                $this->recordRegistered($registered, 'issued', $files, $period, $registered['cents'], ['notes' => 'Email was not sent.']);
            }

            return false;
        }

        // Archive exactly what went out, then the marker that stops a second send.
        foreach ($files as $name => $bytes) {
            $disk->put("{$folder}/{$name}", $bytes);
        }
        $receiptWritten = $disk->put($marker, (string) json_encode([
            'number' => $number,
            'sent_at' => (new DateTimeImmutable('now', new DateTimeZone(self::TIMEZONE)))->format(DATE_ATOM),
            'total_due' => $document['total_due'],
            'report_name' => self::REPORT_NAMES[$company],
            'from' => $this->liveSender(),
            ...($populationPath === null ? [] : ['population_path' => $populationPath]),
        ] + ($registered === null ? [] : ['invoice_number' => $number]), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        if (!$receiptWritten && $populationPath !== null) {
            throw new RuntimeException('LDR invoice email was sent, but its receipt could not be saved. Check delivery before any retry.');
        }
        if ($registered !== null) {
            $this->recordRegistered($registered, 'sent', $files, $period, $registered['cents'], ['sentAt' => (new DateTimeImmutable('now', new DateTimeZone(self::TIMEZONE)))->format(DATE_ATOM)]);
        }

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

    /**
     * The Graph app to send through. A lendingtower.com mailbox lives in Lending Tower's own
     * Microsoft 365 tenant, which only its app registration can send from (GRAPH_LT_*, the one the
     * Monevo reader already uses); any other sender goes through the Liberty app (GRAPH_*).
     */
    protected function mailer(): EmailSenderService
    {
        $prefix = str_ends_with(strtolower($this->liveSender()), '@lendingtower.com') ? 'GRAPH_LT' : 'GRAPH';

        if (! GraphMailboxClient::isConfigured($prefix)) {
            throw new RuntimeException("{$prefix}_TENANT_ID / _CLIENT_ID / _CLIENT_SECRET are not all set, so "
                . $this->liveSender() . ' cannot be sent from.');
        }

        return new EmailSenderService($prefix);
    }

    protected function logoDataUri(): ?string
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

        return intdiv($basisCents * $this->ldrBasisPoints() + 5000, 10000);
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
