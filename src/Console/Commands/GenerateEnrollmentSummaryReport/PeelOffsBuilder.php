<?php

namespace Cmd\Reports\Console\Commands\GenerateEnrollmentSummaryReport;

use Cmd\Reports\Services\DBConnector;
use Illuminate\Support\Facades\Log;

/**
 * Peel-off detail for the report window (the tranche month plus the following months) — Jacob's
 * "Enrollment Summary Report — Peel Offs Update" PRD, 2026-09-10, widened on 2026-09-14 12:08 from
 * the first month to every month in the window ("since we have the report stay a month behind for
 * a while … add the unprocessed to the second month and for consistency … the 3rd").
 *
 * Three populations, all restricted to clients whose coalesced payment date falls in the window;
 * each row carries the month it is paying in so the summary rows can be split per month:
 *
 *   cancel       Cancel_Date = report date
 *   nsf          NSF_Date    = report date, and no Cancel_Date — a cancel outranks an NSF
 *   unprocessed  the payment NEWLY became "unprocessed" since the previous report date — no cancel,
 *                no NSF, not cleared, the grace period has run out (see UNPROCESSED_GRACE_DAYS),
 *                AND Forth shows no draft that cleared or returned (see dropResolvedInForth()).
 *
 * The priority is Jacob's (2026-09-14 13:07): "If someone Cancels then the Cancel takes priority …
 * If they NSF then it counts there, and if neither then we would show as unprocessed." The Forth
 * check exists because TblEnrollment learns of a bounce days after Forth does (ACH returns post 2–4
 * business days after the draft, then resume-payments and sync:enrollment-status have to run):
 * measured on 14 Sep, 40 of 72 "unprocessed" candidates had in fact returned and 1 had cleared.
 * Without the check those 40 would be peeled as Unprocessed and never as NSF.
 *
 * "Newly" is a date window ending on the report date and starting after the previous weekday, so a
 * Monday report picks up Saturday and Sunday (PRD §1.3). The report does not run on weekends.
 * Because a payment only becomes unprocessed GRACE + 1 days after its date, the newly-unprocessed
 * rows always sit in the calendar month of "a few days ago" — the tranche month while it is still
 * running, the following month once the report is anchored a month behind, and never the third.
 * It is not a rolling total: each day shows only what that day removed from the sellable amount.
 *
 * The NSF and Cancel sets EXCLUDE any client already recorded as an unprocessed peel off on an
 * earlier report date (PRD §2) — that record lives in the ledger table below, because it cannot be
 * reconstructed from TblEnrollment later: First_Payment_Date is recomputed nightly to the first
 * cleared-or-returned draft, so it moves once a rescheduled draft processes, and NSF_Date /
 * Cancel_Date are cleared whenever the client returns to Enrolled.
 */
class PeelOffsBuilder
{
    /**
     * Grace period of the existing "never processed" rule (GenerateEnrollmentSummaryReport, Jacob
     * 2026-08-17): a payment dated P still counts on report date D while D <= P + GRACE, and is
     * treated as unprocessed from D = P + GRACE + 1. With GRACE = 3 a payment scheduled 8/15 becomes
     * unprocessed on 8/19 — exactly the PRD's worked example.
     */
    public const UNPROCESSED_GRACE_DAYS = 3;

    public const LEDGER_TABLE = 'dbo.TblEnrollmentPeelOffs';

    public const TYPE_UNPROCESSED = 'Unprocessed';

    /** Same expression the month buckets use to decide which month a client is "paying in". */
    public const PAID_COALESCE = 'COALESCE(First_Payment_Date, Payment_Date_2, Payment_Date_1)';

    public const COMPANY_PROGRESS_LAW = 'Progress Law';
    public const COMPANY_LDR = 'LDR';

    public const TYPES = ['nsf', 'cancel', 'unprocessed'];

    private ?bool $ledgerAvailable = null;

    /** @var array<string, array<int, array<string, mixed>>>|null */
    private ?array $rows = null;

    /** @var list<string> */
    private array $warnings = [];

    /**
     * Looks up, per Forth account, whether each contact has any draft up to the report date that
     * cleared or returned. Signature:
     *   fn (string $source /* 'ldr' | 'plaw' *\/, list<string> $contactIds, string $reportDate)
     *     : array<string, array{cleared: bool, returned: bool}>   keyed by contact id; absent = no draft
     * Defaults to a Snowflake query; injectable so tests need no Snowflake.
     *
     * @var callable
     */
    private $draftActivityLookup;

    /** @var array{returned: int, cleared: int} Candidates the Forth check removed on this run. */
    private array $forthDropped = ['returned' => 0, 'cleared' => 0];

    /**
     * @param string $windowStart First day of the window's first month (Y-m-d)
     * @param string $windowEnd   Last day of the window's last month (Y-m-d)
     */
    public function __construct(
        private readonly DBConnector $connector,
        private readonly string $reportDate,
        private readonly string $windowStart,
        private readonly string $windowEnd,
        private readonly string $criteria,
        ?callable $draftActivityLookup = null,
    ) {
        foreach (['reportDate' => $reportDate, 'windowStart' => $windowStart, 'windowEnd' => $windowEnd] as $name => $value) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                throw new \InvalidArgumentException("PeelOffsBuilder: {$name} must be Y-m-d, got '{$value}'.");
            }
        }
        $this->draftActivityLookup = $draftActivityLookup ?? \Closure::fromCallable([$this, 'lookupDraftActivityInForth']);
    }

    /** @return array{returned: int, cleared: int} */
    public function forthDropped(): array
    {
        return $this->forthDropped;
    }

    /**
     * Payments dated before this are "unprocessed" on the given report date (the existing rule
     * keeps `paid >= cutoff`). Kept here so the month buckets and the peel-off rows can never drift.
     */
    public static function unprocessedCutoff(string $reportDate): string
    {
        return (new \DateTimeImmutable($reportDate))
            ->modify('-' . self::UNPROCESSED_GRACE_DAYS . ' days')
            ->format('Y-m-d');
    }

    /**
     * The report runs Mon–Fri, so the previous report date is the previous weekday: Friday for a
     * Monday report, otherwise yesterday. Weekend dates are never returned.
     */
    public static function previousReportDate(string $reportDate): string
    {
        $date = new \DateTimeImmutable($reportDate);
        do {
            $date = $date->modify('-1 day');
        } while ((int) $date->format('N') >= 6);

        return $date->format('Y-m-d');
    }

    /**
     * SQL fragment that drops clients already recorded as an unprocessed peel off BEFORE the report
     * date — while their payment is STILL unprocessed. Once a first payment clears
     * (First_Payment_Cleared_Date set) the debt is back in the sellable population under the
     * existing 3-day rule, so a later NSF or cancel is a new peel off, not the same one twice.
     *
     * Empty when the ledger is unavailable, in which case the caller has been warned and the
     * NSF/Cancel rows fall back to the pre-PRD behaviour (no exclusion).
     */
    public function ledgerExclusionSql(): string
    {
        if (!$this->ledgerAvailable()) {
            return '';
        }

        $table = self::LEDGER_TABLE;
        $type = self::TYPE_UNPROCESSED;

        return "AND (First_Payment_Cleared_Date IS NOT NULL OR NOT EXISTS (
                    SELECT 1 FROM {$table} l
                    WHERE l.LLG_ID = TblEnrollment.LLG_ID
                      AND l.Peel_Off_Type = '{$type}'
                      AND l.Report_Date < '{$this->reportDate}'
                )) ";
    }

    public function ledgerAvailable(): bool
    {
        if ($this->ledgerAvailable === null) {
            $this->ledgerAvailable = $this->ensureLedgerTable();
        }

        return $this->ledgerAvailable;
    }

    /** @return list<string> */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * @return array{nsf: array<int, array<string, mixed>>, cancel: array<int, array<string, mixed>>, unprocessed: array<int, array<string, mixed>>}
     */
    public function collect(): array
    {
        if ($this->rows !== null) {
            return $this->rows;
        }

        $paid = self::PAID_COALESCE;
        $exclusion = $this->ledgerExclusionSql();
        $select = "SELECT LLG_ID, Client, Agent, Debt_Amount, First_Payment_Date, Cancel_Date, NSF_Date,
                          Enrollment_Plan, {$paid} AS Payment_Date
                   FROM TblEnrollment ";

        // Cancel outranks NSF (Jacob 2026-09-14 13:07): a cancelled client is a Cancel peel off only.
        $nsf = $this->fetch("{$select}
            WHERE NSF_Date = ? AND Cancel_Date IS NULL
              AND {$paid} >= ? AND {$paid} <= ? {$this->criteria} {$exclusion}
        ", [$this->reportDate, $this->windowStart, $this->windowEnd]);

        $cancel = $this->fetch("{$select}
            WHERE Cancel_Date = ?
              AND {$paid} >= ? AND {$paid} <= ? {$this->criteria} {$exclusion}
        ", [$this->reportDate, $this->windowStart, $this->windowEnd]);

        // Newly unprocessed = unprocessed on the report date but NOT on the previous report date:
        //   paid <  cutoff(reportDate)      (unprocessed today)
        //   paid >= cutoff(previousReport)  (was still inside the grace period last time)
        // Both cutoffs are exclusive-upper bounds of the same rule, so the pair is the exact window
        // of payment dates whose grace period expired between the two reports — weekends included.
        $newlyUnprocessed = $this->fetch("{$select}
            WHERE Cancel_Date IS NULL AND NSF_Date IS NULL
              AND First_Payment_Cleared_Date IS NULL
              AND {$paid} >= ? AND {$paid} <= ?
              AND {$paid} <  ?
              AND {$paid} >= ? {$this->criteria} {$exclusion}
        ", [
            $this->windowStart,
            $this->windowEnd,
            self::unprocessedCutoff($this->reportDate),
            self::unprocessedCutoff(self::previousReportDate($this->reportDate)),
        ]);

        $this->rows = [
            'nsf' => $this->normalize($nsf),
            'cancel' => $this->normalize($cancel),
            'unprocessed' => $this->dropResolvedInForth($this->normalize($newlyUnprocessed)),
        ];

        return $this->rows;
    }

    /**
     * Count and debt for one peel-off type as seen from one report column (Total / LDR / Legal),
     * using the same Enrollment_Plan split as the column criteria — for one "paying in" month when
     * $monthStart (Y-m-d, any day of the month) is given, otherwise across the whole window.
     *
     * @return array{count: int, debt: float}
     */
    public function aggregate(string $type, string $columnKey, ?string $monthStart = null): array
    {
        $month = $monthStart === null ? null : substr($monthStart, 0, 7);
        $count = 0;
        $debt = 0.0;
        foreach ($this->collect()[$type] ?? [] as $row) {
            if (!$this->rowInColumn($row, $columnKey) || ($month !== null && $row['Paying_In'] !== $month)) {
                continue;
            }
            $count++;
            $debt += (float) $row['Debt_Amount'];
        }

        return ['count' => $count, 'debt' => $debt];
    }

    /**
     * Records today's newly unprocessed rows so later reports exclude them from NSF / Cancel.
     * Idempotent: a client is written once, so a same-day re-run changes nothing.
     *
     * @return array{attempted: int, written: int, failed: int}
     */
    public function writeLedger(): array
    {
        $rows = $this->collect()['unprocessed'];
        $result = ['attempted' => count($rows), 'written' => 0, 'failed' => 0];

        if ($rows === [] || !$this->ledgerAvailable()) {
            return $result;
        }

        $table = self::LEDGER_TABLE;
        $type = self::TYPE_UNPROCESSED;

        foreach ($rows as $row) {
            $insert = $this->connector->querySqlServer("
                INSERT INTO {$table} (LLG_ID, Peel_Off_Type, Report_Date, Payment_Date, Unprocessed_Date, Debt_Amount, Window_Month, Created_At)
                SELECT CAST(? AS NVARCHAR(50)), CAST(? AS NVARCHAR(20)), CAST(? AS DATE), CAST(? AS DATE), CAST(? AS DATE),
                       CAST(? AS DECIMAL(18,2)), CAST(? AS DATE), SYSUTCDATETIME()
                WHERE NOT EXISTS (
                    SELECT 1 FROM {$table} WHERE LLG_ID = ? AND Peel_Off_Type = ?
                )
            ", [
                $row['LLG_ID'],
                $type,
                $this->reportDate,
                $row['Payment_Date'],
                $row['Unprocessed_Date'],
                $row['Debt_Amount'],
                $row['Paying_In'] === null ? null : $row['Paying_In'] . '-01',   // Window_Month = the month the payment is in
                $row['LLG_ID'],
                $type,
            ]);

            if (($insert['success'] ?? false) !== true) {
                $result['failed']++;
                Log::error('GenerateEnrollmentSummaryReport: peel-off ledger insert failed', [
                    'llg_id' => $row['LLG_ID'],
                    'error' => $insert['error'] ?? 'unknown',
                ]);
                continue;
            }

            $result['written'] += (int) ($insert['row_count'] ?? 0);
        }

        return $result;
    }

    /**
     * Keeps only candidates whose payment really never processed: Forth (Snowflake, the client's
     * own account) must show no draft up to the report date that cleared or returned. A returned
     * draft is an NSF that TblEnrollment has not caught up with — it is reported as NSF once
     * NSF_Date lands, and since it is never ledgered nothing suppresses it then. A cleared draft
     * is a payment First_Payment_Cleared_Date has not caught up with. A cancelled/rescheduled draft
     * with nothing else still counts as "didn't process".
     *
     * A lookup failure throws: guessing here is exactly the over-count Jacob does not want, and a
     * failed run is recoverable with --snapshot-date=<day> --ledger-write=on.
     *
     * @param array<int, array<string, mixed>> $rows normalised candidates
     * @return array<int, array<string, mixed>>
     */
    private function dropResolvedInForth(array $rows): array
    {
        $this->forthDropped = ['returned' => 0, 'cleared' => 0];
        if ($rows === []) {
            return $rows;
        }

        $bySource = [];
        foreach ($rows as $index => $row) {
            $contactId = preg_replace('/^LLG-/i', '', $row['LLG_ID']);
            if (!preg_match('/^\d+$/', $contactId)) {
                continue;   // not a Forth id; nothing to check, stays unprocessed
            }
            $source = $row['Company'] === self::COMPANY_PROGRESS_LAW ? 'plaw' : 'ldr';
            $bySource[$source][$contactId] = $index;
        }

        $keep = $rows;
        foreach ($bySource as $source => $indexByContact) {
            $activity = ($this->draftActivityLookup)($source, array_keys($indexByContact), $this->reportDate);
            foreach ($indexByContact as $contactId => $index) {
                $draft = $activity[(string) $contactId] ?? null;
                if ($draft === null) {
                    continue;
                }
                if (!empty($draft['cleared'])) {
                    $this->forthDropped['cleared']++;
                    unset($keep[$index]);
                } elseif (!empty($draft['returned'])) {
                    $this->forthDropped['returned']++;
                    unset($keep[$index]);
                }
            }
        }

        return array_values($keep);
    }

    /**
     * Default draft-activity lookup: one Snowflake aggregate per account, chunked. The same
     * cleared / returned test SyncFirstPaymentDate uses (RETURN_CODE = '' is not a return).
     *
     * @param list<string> $contactIds
     * @return array<string, array{cleared: bool, returned: bool}>
     */
    private function lookupDraftActivityInForth(string $source, array $contactIds, string $reportDate): array
    {
        $connector = DBConnector::fromEnvironment($source);
        $out = [];

        foreach (array_chunk($contactIds, 500) as $chunk) {
            $in = implode(',', array_map('intval', $chunk));
            // "As of the report date": a clear or return dated after it does not count, so a review
            // copy of a past day sees what that day's run would have seen.
            $sql = "SELECT TO_VARCHAR(CONTACT_ID) AS CONTACT_ID,
                           MAX(CASE WHEN CLEARED_DATE IS NOT NULL AND CAST(CLEARED_DATE AS DATE) <= '{$reportDate}' THEN 1 ELSE 0 END) AS CLEARED,
                           MAX(CASE WHEN (RETURNED_DATE IS NOT NULL AND CAST(RETURNED_DATE AS DATE) <= '{$reportDate}')
                                      OR (RETURNED_DATE IS NULL AND RETURN_CODE IS NOT NULL AND RETURN_CODE <> '') THEN 1 ELSE 0 END) AS RETURNED
                    FROM TRANSACTIONS
                    WHERE TRANS_TYPE = 'D' AND _FIVETRAN_DELETED = FALSE
                      AND CONTACT_ID IN ({$in})
                      AND CAST(CONVERT_TIMEZONE('America/Los_Angeles', PROCESS_DATE) AS DATE) <= '{$reportDate}'
                    GROUP BY CONTACT_ID";

            $result = $connector->query($sql);
            if (!is_array($result) || (($result['success'] ?? true) === false)) {
                throw new \RuntimeException(sprintf(
                    'Peel-off Forth check failed for %s: %s',
                    strtoupper($source),
                    is_array($result) ? ($result['error'] ?? 'unknown error') : 'no response'
                ));
            }

            foreach ($result['data'] ?? [] as $row) {
                $out[(string) $row['CONTACT_ID']] = [
                    'cleared' => (int) ($row['CLEARED'] ?? 0) === 1,
                    'returned' => (int) ($row['RETURNED'] ?? 0) === 1,
                ];
            }
        }

        return $out;
    }

    public static function companyFor(?string $enrollmentPlan): string
    {
        // PRD §4: Enrollment_Plan contains "Progress" => Progress Law, otherwise LDR. Same split as
        // the report's LDR / Legal columns (`Enrollment_Plan LIKE '%Progress%'`).
        return stripos((string) $enrollmentPlan, 'Progress') !== false
            ? self::COMPANY_PROGRESS_LAW
            : self::COMPANY_LDR;
    }

    private function rowInColumn(array $row, string $columnKey): bool
    {
        return match ($columnKey) {
            'LDR' => $row['Company'] === self::COMPANY_LDR,
            'Legal' => $row['Company'] === self::COMPANY_PROGRESS_LAW,
            default => true,
        };
    }

    private function ensureLedgerTable(): bool
    {
        $table = self::LEDGER_TABLE;
        $result = $this->connector->querySqlServer("
            IF OBJECT_ID('{$table}', 'U') IS NULL
            CREATE TABLE {$table} (
                LLG_ID           NVARCHAR(50)  NOT NULL,
                Peel_Off_Type    NVARCHAR(20)  NOT NULL,
                Report_Date      DATE          NOT NULL,
                Payment_Date     DATE          NULL,
                Unprocessed_Date DATE          NULL,
                Debt_Amount      DECIMAL(18,2) NULL,
                Window_Month     DATE          NULL,
                Created_At       DATETIME2     NOT NULL,
                CONSTRAINT PK_TblEnrollmentPeelOffs PRIMARY KEY (LLG_ID, Peel_Off_Type, Report_Date)
            )
        ");

        if (($result['success'] ?? false) === true) {
            return true;
        }

        $error = $result['error'] ?? 'unknown';
        $this->warnings[] = "Peel-off ledger {$table} is unavailable ({$error}); NSF/Cancel peel offs are NOT excluding prior unprocessed peel offs this run, and nothing will be recorded.";
        Log::error('GenerateEnrollmentSummaryReport: peel-off ledger unavailable', ['error' => $error]);

        return false;
    }

    /**
     * @param array<int, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    private function fetch(string $sql, array $params): array
    {
        $result = $this->connector->querySqlServer($sql, $params);
        if (($result['success'] ?? false) !== true) {
            throw new \RuntimeException('Peel-off query failed: ' . ($result['error'] ?? 'unknown error'));
        }

        return $result['data'] ?? [];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function normalize(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $paymentDate = $this->dateOrNull($row['Payment_Date'] ?? null);
            $out[] = [
                'LLG_ID' => (string) ($row['LLG_ID'] ?? ''),
                'Client' => (string) ($row['Client'] ?? ''),
                'Agent' => (string) ($row['Agent'] ?? ''),
                'Debt_Amount' => (float) ($row['Debt_Amount'] ?? 0),
                'First_Payment_Date' => $this->dateOrNull($row['First_Payment_Date'] ?? null),
                'Cancel_Date' => $this->dateOrNull($row['Cancel_Date'] ?? null),
                'NSF_Date' => $this->dateOrNull($row['NSF_Date'] ?? null),
                'Enrollment_Plan' => (string) ($row['Enrollment_Plan'] ?? ''),
                'Payment_Date' => $paymentDate,
                'Unprocessed_Date' => $paymentDate === null ? null : (new \DateTimeImmutable($paymentDate))
                    ->modify('+' . (self::UNPROCESSED_GRACE_DAYS + 1) . ' days')
                    ->format('Y-m-d'),
                'Company' => self::companyFor($row['Enrollment_Plan'] ?? null),
                // The month bucket this client's payment falls in: 'Y-m' for matching the summary
                // rows, and the month name the sheet prints ("Paying in August" uses the same).
                'Paying_In' => $paymentDate === null ? null : substr($paymentDate, 0, 7),
                'Paying_In_Label' => $paymentDate === null ? '' : (new \DateTimeImmutable($paymentDate))->format('F'),
            ];
        }

        // PRD §5: within each table sort by Company, then Debt Amount. Progress Law is listed first
        // because that is the order the PRD uses for every subtotal and summary column; largest debt
        // first within a company.
        usort($out, static function (array $a, array $b): int {
            if ($a['Company'] !== $b['Company']) {
                return $a['Company'] === self::COMPANY_PROGRESS_LAW ? -1 : 1;
            }

            return $b['Debt_Amount'] <=> $a['Debt_Amount'] ?: strcmp($a['LLG_ID'], $b['LLG_ID']);
        });

        return $out;
    }

    private function dateOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        $timestamp = strtotime((string) $value);

        return $timestamp === false ? null : date('Y-m-d', $timestamp);
    }
}
