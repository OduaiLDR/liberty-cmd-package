<?php

namespace Cmd\Reports\Console\Commands\GenerateLendingTowerInvoices;

use Cmd\Reports\Services\DBConnector;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
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
 */
class GenerateLendingTowerInvoices extends Command
{
    protected $signature = 'reports:generate-lending-tower-invoices
                            {--month= : Billing month as YYYY-MM (defaults to the previous month, LA time)}
                            {--company=all : PLAW, LDR or all}
                            {--dry-run : Calculate and print the figures without creating or sending anything}';

    protected $description = 'Generate the monthly Lending Tower invoices: Progress Law per lead, LDR per enrollment less lookback.';

    private const TIMEZONE = 'America/Los_Angeles';

    private const PLAW_RATE_PER_LEAD_CENTS = 27500;

    /** 7%, in basis points so the fee stays in integer arithmetic. */
    private const LDR_FEE_BASIS_POINTS = 700;

    private const DUPLICATE_LEAD_STATUS = 'Duplicate Lead';

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
        if (! $this->option('dry-run')) {
            $this->error('Invoice PDF and email delivery are not built yet. Run with --dry-run to see the figures.');

            return self::FAILURE;
        }

        try {
            [$start, $end, $label] = $this->resolvePeriod((string) $this->option('month'));
            $companies = $this->resolveCompanies((string) $this->option('company'));

            $lastDay = (new DateTimeImmutable($end))->modify('-1 day')->format('Y-m-d');
            $this->info("Lending Tower invoices for {$label} ({$start} to {$lastDay}, " . self::TIMEZONE . ')');

            $today = (new DateTimeImmutable('now', new DateTimeZone(self::TIMEZONE)))->format('Y-m-d');
            if ($end > $today) {
                $this->warn('[WARN] This month has not ended yet, so the figures are partial.');
            }

            $azure = DBConnector::fromEnvironment('ldr');
            $azure->initializeSqlServer();

            if (in_array('PLAW', $companies, true)) {
                $this->printProgressLaw($this->progressLawInvoice($azure, $start, $end));
            }

            if (in_array('LDR', $companies, true)) {
                $this->printLdr($this->ldrInvoice($azure, $start, $end));
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Lending Tower invoices failed: ' . $e->getMessage());
            Log::error('GenerateLendingTowerInvoices failed', ['exception' => $e]);

            return self::FAILURE;
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
        $rate = rtrim(rtrim(number_format(self::LDR_FEE_BASIS_POINTS / 100, 2), '0'), '.') . '%';
        $events = $invoice['deduction_events'];

        $this->newLine();
        $this->line('<options=bold>LDR</>');
        $this->row('Enrollments (first payment cleared)', sprintf(
            '%s (%s used Debt_Amount because Sold_Debt is empty)',
            number_format(count($invoice['enrollments'])),
            number_format($invoice['used_debt_amount'])
        ));
        $this->row('Debt basis', $this->money($invoice['gross_basis_cents']));
        $this->row("Gross at {$rate}", $this->money($invoice['gross_cents']));
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
