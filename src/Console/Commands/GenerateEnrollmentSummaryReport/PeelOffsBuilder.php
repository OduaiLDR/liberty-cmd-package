<?php

namespace Cmd\Reports\Console\Commands\GenerateEnrollmentSummaryReport;

use Cmd\Reports\Services\DBConnector;
use Illuminate\Support\Facades\Log;

/**
 * Peel-off detail for the report window's FIRST month (the month being tranched) — Jacob's
 * "Enrollment Summary Report — Peel Offs Update" PRD, 2026-09-10.
 *
 * Three populations, all restricted to clients whose coalesced payment date falls in month 1:
 *
 *   nsf          NSF_Date    = report date
 *   cancel       Cancel_Date = report date
 *   unprocessed  the payment NEWLY became "unprocessed" since the previous report date — not cleared,
 *                no cancel, no NSF, and the grace period has run out (see UNPROCESSED_GRACE_DAYS).
 *
 * "Newly" is a date window ending on the report date and starting after the previous weekday, so a
 * Monday report picks up Saturday and Sunday (PRD §1.3). The report does not run on weekends.
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

    public function __construct(
        private readonly DBConnector $connector,
        private readonly string $reportDate,
        private readonly string $monthStart,
        private readonly string $monthEnd,
        private readonly string $criteria,
    ) {
        foreach (['reportDate' => $reportDate, 'monthStart' => $monthStart, 'monthEnd' => $monthEnd] as $name => $value) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                throw new \InvalidArgumentException("PeelOffsBuilder: {$name} must be Y-m-d, got '{$value}'.");
            }
        }
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

        $nsf = $this->fetch("{$select}
            WHERE NSF_Date = ?
              AND {$paid} >= ? AND {$paid} <= ? {$this->criteria} {$exclusion}
        ", [$this->reportDate, $this->monthStart, $this->monthEnd]);

        $cancel = $this->fetch("{$select}
            WHERE Cancel_Date = ?
              AND {$paid} >= ? AND {$paid} <= ? {$this->criteria} {$exclusion}
        ", [$this->reportDate, $this->monthStart, $this->monthEnd]);

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
            $this->monthStart,
            $this->monthEnd,
            self::unprocessedCutoff($this->reportDate),
            self::unprocessedCutoff(self::previousReportDate($this->reportDate)),
        ]);

        $this->rows = [
            'nsf' => $this->normalize($nsf),
            'cancel' => $this->normalize($cancel),
            'unprocessed' => $this->normalize($newlyUnprocessed),
        ];

        return $this->rows;
    }

    /**
     * Count and debt for one peel-off type as seen from one report column (Total / LDR / Legal),
     * using the same Enrollment_Plan split as the column criteria.
     *
     * @return array{count: int, debt: float}
     */
    public function aggregate(string $type, string $columnKey): array
    {
        $count = 0;
        $debt = 0.0;
        foreach ($this->collect()[$type] ?? [] as $row) {
            if (!$this->rowInColumn($row, $columnKey)) {
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
                $this->monthStart,
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
