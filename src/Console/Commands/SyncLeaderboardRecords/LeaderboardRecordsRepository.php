<?php

namespace Cmd\Reports\Console\Commands\SyncLeaderboardRecords;

use Carbon\Carbon;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Self-contained data layer for the leaderboard record-sync command.
 *
 * It deliberately does NOT depend on Cmd\Reports\Repositories\LeaderboardReportRepository
 * (that class is the web report and lives on a different branch). The leader-computation
 * SQL is the validated copy of that repository, with one change: every window is computed
 * for an injected "as of" date instead of today-3, so the command can finalise any past
 * period (replay). When as-of = the period's END date the existing window/cutoff maths
 * yield the exact complete period.
 */
class LeaderboardRecordsRepository
{
    public const CATEGORIES = [
        'Deals Enrolled',
        'Debt Enrolled',
        'Same Month Pay',
        'Conversion Ratio',
        'Cancellation Ratio',
        'NSF Ratio',
        'Active Clients',
        'Individual Debt',
    ];

    /** Categories where a LOWER amount wins (sort ascending). */
    public const RATIO_ASC = ['Cancellation Ratio', 'NSF Ratio'];

    protected function conn(): ConnectionInterface
    {
        return DB::connection('sqlsrv');
    }

    public function direction(string $category): string
    {
        return in_array($category, self::RATIO_ASC, true) ? 'asc' : 'desc';
    }

    public function isRatio(string $category): bool
    {
        return in_array($category, self::RATIO_ASC, true);
    }

    private function ratioColumn(string $category): string
    {
        return $category === 'Cancellation Ratio' ? 'Cancel_Date' : 'NSF_Date';
    }

    /**
     * Active periods configured for a category (TblLeaderboardSettings.Active = 'TRUE').
     *
     * @return Collection<int, string>
     */
    public function periodsFor(string $category): Collection
    {
        $rows = $this->conn()->select(
            "SELECT Period FROM TblLeaderboardSettings WHERE Category = ? AND Active = 'TRUE' ORDER BY PK",
            [$category]
        );

        return collect($rows)->pluck('Period')->filter()->unique()->values();
    }

    public function settings(string $category, string $period): ?object
    {
        return $this->conn()->selectOne(
            'SELECT Bonus_1, Bonus_2, Bonus_3, Bonus_4, Threshold, Activity_Cutoff, Tiebreaker, Notes '
            . 'FROM TblLeaderboardSettings WHERE Category = ? AND Period = ?',
            [$category, $period]
        );
    }

    public function fullThreshold(string $category, string $period): int
    {
        return (int) ($this->settings($category, $period)->Threshold ?? 0);
    }

    private function halfThreshold(string $category, string $period): int
    {
        return (int) floor($this->fullThreshold($category, $period) / 2);
    }

    /**
     * Period window for an arbitrary "as of" date (the period's reference/END date).
     * Mirrors LeaderboardReportRepository::resolveWindow but with NO today-3 lag.
     *
     * @return array{from:Carbon,to:Carbon,cutoff:Carbon,report:Carbon}
     */
    public function resolveWindow(string $period, Carbon $asOf): array
    {
        $report = $asOf->copy();

        switch ($period) {
            case 'Daily':
                $from = $report->copy();
                $to = $report->copy();
                $cutoff = $report->copy();
                break;
            case 'Weekly':
                $from = $report->copy()->startOfWeek(Carbon::MONDAY);
                $to = $from->copy()->addDays(6);
                $cutoff = $to->copy();
                break;
            case 'Quarterly':
                $from = $report->copy()->firstOfQuarter();
                $to = $report->copy()->lastOfQuarter();
                $cutoff = $report->copy()->endOfMonth();
                break;
            case 'Yearly':
                $from = $report->copy()->startOfYear();
                $to = $report->copy()->endOfYear();
                $cutoff = $report->copy()->endOfMonth();
                break;
            case 'Monthly':
            default:
                $from = $report->copy()->startOfMonth();
                $to = $report->copy()->endOfMonth();
                $cutoff = $to->copy();
                break;
        }

        return ['from' => $from, 'to' => $to, 'cutoff' => $cutoff, 'report' => $report];
    }

    /**
     * Top-4 current leaders for (category, period) computed as of $asOf.
     * Rows carry: agent, amount, deals, debt, contacts (+ note for Individual Debt).
     *
     * @return Collection<int, object>
     */
    public function currentLeaders(string $category, string $period, Carbon $asOf): Collection
    {
        $w = $this->resolveWindow($period, $asOf);
        $from = $w['from']->format('Y-m-d');
        $to = $w['to']->format('Y-m-d');
        $cutoff = $w['cutoff']->format('Y-m-d');
        $half = $this->halfThreshold($category, $period);

        $rows = match ($category) {
            'Deals Enrolled' => $this->conn()->select(
                'SELECT TOP (4) Agent AS agent, COUNT(*) AS amount, COUNT(*) AS deals, SUM(Debt_Amount) AS debt '
                . 'FROM TblEnrollment '
                . 'WHERE Submitted_Date >= ? AND Submitted_Date <= ? '
                . 'AND (Cancel_Date IS NULL OR Cancel_Date > ?) AND (NSF_Date IS NULL OR NSF_Date > ?) '
                . "AND Agent IS NOT NULL AND Agent <> '' "
                . 'GROUP BY Agent ORDER BY COUNT(*) DESC, SUM(Debt_Amount) DESC',
                [$from, $to, $cutoff, $cutoff]
            ),
            'Debt Enrolled' => $this->conn()->select(
                'SELECT TOP (4) Agent AS agent, SUM(Debt_Amount) AS amount, COUNT(*) AS deals, SUM(Debt_Amount) AS debt '
                . 'FROM TblEnrollment '
                . 'WHERE Submitted_Date >= ? AND Submitted_Date <= ? '
                . 'AND (Cancel_Date IS NULL OR Cancel_Date > ?) AND (NSF_Date IS NULL OR NSF_Date > ?) '
                . "AND Agent IS NOT NULL AND Agent <> '' "
                . 'GROUP BY Agent ORDER BY SUM(Debt_Amount) DESC, COUNT(*) DESC',
                [$from, $to, $cutoff, $cutoff]
            ),
            'Same Month Pay' => $this->conn()->select(
                'SELECT TOP (4) Agent AS agent, '
                . 'SUM(CASE WHEN COALESCE(Payment_Date_2, Payment_Date_1) <= EOMONTH(Submitted_Date) THEN 1 ELSE 0 END) AS amount, '
                . 'COUNT(*) AS deals, SUM(Debt_Amount) AS debt '
                . 'FROM TblEnrollment '
                . 'WHERE Submitted_Date >= ? AND Submitted_Date <= ? '
                . 'AND (Cancel_Date IS NULL OR Cancel_Date > EOMONTH(Submitted_Date)) '
                . 'AND (NSF_Date IS NULL OR NSF_Date > EOMONTH(Submitted_Date)) '
                . "AND Agent IS NOT NULL AND Agent <> '' "
                . 'GROUP BY Agent '
                . 'HAVING SUM(CASE WHEN COALESCE(Payment_Date_2, Payment_Date_1) <= EOMONTH(Submitted_Date) THEN 1 ELSE 0 END) > 0 '
                . 'ORDER BY amount DESC, debt DESC',
                [$from, $to]
            ),
            'Conversion Ratio' => $this->conn()->select(
                'SELECT TOP (4) c.Agent AS agent, c.contacts AS contacts, '
                . 'COALESCE(en.enrolled, 0) AS deals, COALESCE(en.debt, 0) AS debt, '
                . 'CAST(COALESCE(en.enrolled, 0) AS FLOAT) / NULLIF(c.contacts, 0) AS amount '
                . 'FROM ('
                . 'SELECT Agent, COUNT(*) AS contacts FROM TblContacts '
                . 'WHERE COALESCE(Assigned_Date, Created_Date) >= ? AND COALESCE(Assigned_Date, Created_Date) < ? '
                . "AND Agent IS NOT NULL AND Agent <> '' "
                . 'GROUP BY Agent HAVING COUNT(*) >= ?'
                . ') c '
                . 'LEFT JOIN ('
                . 'SELECT Agent, COUNT(*) AS enrolled, SUM(Debt_Amount) AS debt FROM TblEnrollment '
                . 'WHERE LLG_ID IN ('
                . 'SELECT LLG_ID FROM TblContacts '
                . 'WHERE COALESCE(Assigned_Date, Created_Date) >= ? AND COALESCE(Assigned_Date, Created_Date) < ?'
                . ') AND Cancel_Date IS NULL AND NSF_Date IS NULL '
                . 'GROUP BY Agent'
                . ') en ON en.Agent = c.Agent '
                . 'ORDER BY COALESCE(en.debt, 0) DESC, amount DESC',
                [$from, $w['to']->copy()->addDay()->format('Y-m-d'), $half, $from, $w['to']->copy()->addDay()->format('Y-m-d')]
            ),
            'Cancellation Ratio', 'NSF Ratio' => $this->conn()->select(
                'SELECT TOP (4) Agent AS agent, '
                . 'CAST(SUM(CASE WHEN ' . $this->ratioColumn($category) . ' IS NOT NULL THEN 1 ELSE 0 END) AS FLOAT) / NULLIF(COUNT(*), 0) AS amount, '
                . 'COUNT(*) AS deals, SUM(Debt_Amount) AS debt '
                . 'FROM TblEnrollment '
                . 'WHERE Submitted_Date >= ? AND Submitted_Date <= ? '
                . "AND Agent IS NOT NULL AND Agent <> '' "
                . 'GROUP BY Agent HAVING COUNT(*) >= ? '
                . 'ORDER BY amount ASC, COUNT(*) DESC',
                [$from, $to, $half]
            ),
            'Active Clients' => $this->conn()->select(
                // NOTE: no date filter — Active Clients is a live "currently active" snapshot,
                // exactly as the report computes it. Replayed instances all see current standings.
                'SELECT TOP (4) Agent AS agent, COUNT(*) AS amount, COUNT(*) AS deals, SUM(Debt_Amount) AS debt '
                . 'FROM TblEnrollment '
                . "WHERE Cancel_Date IS NULL AND NSF_Date IS NULL AND Category = 'LDR' "
                . "AND Agent IS NOT NULL AND Agent <> '' "
                . 'GROUP BY Agent ORDER BY COUNT(*) DESC, SUM(Debt_Amount) DESC',
                []
            ),
            'Individual Debt' => $this->conn()->select(
                'SELECT TOP (4) Agent AS agent, Debt_Amount AS amount, Submitted_Date AS submitted_date, '
                . 'COALESCE(Payment_Date_2, Payment_Date_1) AS payment_date, LLG_ID AS llg_id, Client AS client, Payments AS payments '
                . 'FROM TblEnrollment '
                . 'WHERE COALESCE(Payment_Date_2, Payment_Date_1) >= ? AND Submitted_Date <= ? '
                // Cancel-seasoning (Jacob): keep a deal unless it cancelled within 3 months of its
                // first cleared payment. First_Payment_Cleared_Date NULL → DATEADD is NULL → a cancelled
                // deal with no cleared payment is excluded.
                . 'AND (Cancel_Date IS NULL OR Cancel_Date > DATEADD(MONTH, 3, First_Payment_Cleared_Date)) '
                . 'AND NSF_Date IS NULL '
                . "AND Agent IS NOT NULL AND Agent <> '' "
                . 'ORDER BY Debt_Amount DESC',
                [$w['report']->copy()->startOfMonth()->subMonths(4)->format('Y-m-d'), $to]
            ),
            default => [],
        };

        $leaders = collect($rows);

        foreach ($leaders as $row) {
            if (!isset($row->contacts)) {
                $row->contacts = $this->contactsFor($row->agent ?? null, $w['from'], $w['to']);
            }
        }

        return $leaders;
    }

    /**
     * Company-Wide current aggregate for (category, period) as of $asOf. Null for Individual Debt.
     */
    public function currentCompany(string $category, string $period, Carbon $asOf): ?object
    {
        if ($category === 'Individual Debt') {
            return null;
        }

        $w = $this->resolveWindow($period, $asOf);
        $from = $w['from']->format('Y-m-d');
        $to = $w['to']->format('Y-m-d');
        $cutoff = $w['cutoff']->format('Y-m-d');
        $threshold = $this->fullThreshold($category, $period);

        $row = match ($category) {
            'Deals Enrolled' => $this->conn()->selectOne(
                'SELECT COUNT(*) AS amount, COUNT(*) AS deals, SUM(Debt_Amount) AS debt FROM TblEnrollment '
                . 'WHERE Submitted_Date >= ? AND Submitted_Date <= ? '
                . 'AND (Cancel_Date IS NULL OR Cancel_Date > ?) AND (NSF_Date IS NULL OR NSF_Date > ?)',
                [$from, $to, $cutoff, $cutoff]
            ),
            'Debt Enrolled' => $this->conn()->selectOne(
                'SELECT SUM(Debt_Amount) AS amount, COUNT(*) AS deals, SUM(Debt_Amount) AS debt FROM TblEnrollment '
                . 'WHERE Submitted_Date >= ? AND Submitted_Date <= ? '
                . 'AND (Cancel_Date IS NULL OR Cancel_Date > ?) AND (NSF_Date IS NULL OR NSF_Date > ?)',
                [$from, $to, $cutoff, $cutoff]
            ),
            'Same Month Pay' => $this->conn()->selectOne(
                'SELECT SUM(CASE WHEN COALESCE(Payment_Date_2, Payment_Date_1) <= EOMONTH(Submitted_Date) THEN 1 ELSE 0 END) AS amount, '
                . 'COUNT(*) AS deals, SUM(Debt_Amount) AS debt FROM TblEnrollment '
                . 'WHERE Submitted_Date >= ? AND Submitted_Date <= ? '
                . 'AND (Cancel_Date IS NULL OR Cancel_Date > EOMONTH(Submitted_Date)) '
                . 'AND (NSF_Date IS NULL OR NSF_Date > EOMONTH(Submitted_Date))',
                [$from, $to]
            ),
            'Conversion Ratio' => $this->conversionCompany($from, $w['to']->copy()->addDay()->format('Y-m-d'), $threshold),
            'Cancellation Ratio', 'NSF Ratio' => $this->ratioCompany($category, $from, $to, $threshold),
            'Active Clients' => $this->conn()->selectOne(
                'SELECT COUNT(*) AS amount, COUNT(*) AS deals, SUM(Debt_Amount) AS debt FROM TblEnrollment '
                . "WHERE Cancel_Date IS NULL AND NSF_Date IS NULL AND Category = 'LDR'",
                []
            ),
            default => null,
        };

        if ($row) {
            $row->contacts = $this->contactsFor(null, $w['from'], $w['to']);
        }

        return $row;
    }

    private function conversionCompany(string $from, string $toExclusive, int $threshold): ?object
    {
        $contacts = (int) ($this->conn()->selectOne(
            'SELECT COUNT(*) AS c FROM TblContacts '
            . 'WHERE COALESCE(Assigned_Date, Created_Date) >= ? AND COALESCE(Assigned_Date, Created_Date) < ?',
            [$from, $toExclusive]
        )->c ?? 0);

        if ($contacts < $threshold) {
            return null;
        }

        $enrolled = $this->conn()->selectOne(
            'SELECT COUNT(*) AS deals, SUM(Debt_Amount) AS debt FROM TblEnrollment '
            . 'WHERE LLG_ID IN (SELECT LLG_ID FROM TblContacts '
            . 'WHERE COALESCE(Assigned_Date, Created_Date) >= ? AND COALESCE(Assigned_Date, Created_Date) < ?) '
            . 'AND Cancel_Date IS NULL AND NSF_Date IS NULL',
            [$from, $toExclusive]
        );

        return (object) [
            'amount' => $contacts > 0 ? ((int) ($enrolled->deals ?? 0)) / $contacts : null,
            'deals' => (int) ($enrolled->deals ?? 0),
            'debt' => (float) ($enrolled->debt ?? 0),
        ];
    }

    private function ratioCompany(string $category, string $from, string $to, int $threshold): ?object
    {
        $totals = $this->conn()->selectOne(
            'SELECT COUNT(*) AS deals, SUM(Debt_Amount) AS debt FROM TblEnrollment '
            . 'WHERE Submitted_Date >= ? AND Submitted_Date <= ?',
            [$from, $to]
        );

        $count = (int) ($totals->deals ?? 0);
        if ($count < $threshold) {
            return null;
        }

        $hits = (int) ($this->conn()->selectOne(
            'SELECT COUNT(*) AS c FROM TblEnrollment '
            . 'WHERE Submitted_Date >= ? AND Submitted_Date <= ? AND ' . $this->ratioColumn($category) . ' IS NOT NULL',
            [$from, $to]
        )->c ?? 0);

        return (object) [
            'amount' => $count > 0 ? $hits / $count : null,
            'deals' => $count,
            'debt' => (float) ($totals->debt ?? 0),
        ];
    }

    private function contactsFor(?string $agent, Carbon $from, Carbon $to): int
    {
        $sql = 'SELECT COUNT(*) AS c FROM TblContacts '
            . 'WHERE COALESCE(Assigned_Date, Created_Date) >= ? AND COALESCE(Assigned_Date, Created_Date) < ?';
        $bindings = [$from->format('Y-m-d'), $to->copy()->addDay()->format('Y-m-d')];

        if ($agent !== null && $agent !== '') {
            $sql .= ' AND Agent = ?';
            $bindings[] = $agent;
        }

        return (int) ($this->conn()->selectOne($sql, $bindings)->c ?? 0);
    }

    /**
     * Existing top-4 stored records for (category, period) — the comparison set.
     *
     * @return array<int, object> rows of {agent_id, amount, tb1, tb2}
     */
    public function recordRowsRaw(string $category, string $period): array
    {
        $dir = strtoupper($this->direction($category));

        return $this->conn()->select(
            'SELECT TOP (4) Agent_ID AS agent_id, Amount AS amount, Tiebreaker_1 AS tb1, Tiebreaker_2 AS tb2 '
            . 'FROM TblLeaderboard WHERE Category = ? AND Period = ? '
            . "ORDER BY Amount {$dir}, Tiebreaker_1 DESC, Tiebreaker_2 DESC",
            [$category, $period]
        );
    }

    /**
     * Existing Company-Wide record for (category, period), or null.
     */
    public function companyRecordRaw(string $category, string $period): ?object
    {
        $dir = strtoupper($this->direction($category));

        return $this->conn()->selectOne(
            'SELECT TOP (1) Amount AS amount, Tiebreaker_1 AS tb1, Tiebreaker_2 AS tb2 '
            . 'FROM TblLeaderboard WHERE Category = ? AND Period = ? '
            . "ORDER BY Amount {$dir}, Tiebreaker_1 DESC, Tiebreaker_2 DESC",
            [$category . ' - Company-Wide', $period]
        );
    }

    /**
     * Inverse of LeaderboardReportRepository::mapRecordRow — turn a live leader row
     * into the stored (Amount, Tiebreaker_1, Tiebreaker_2) triple.
     *
     * @return array{0: float|int|null, 1: float|int|null, 2: float|int|null}
     */
    public function inverseMap(string $category, object $row): array
    {
        $amount = $row->amount ?? null;
        $deals = $row->deals ?? null;
        $debt = $row->debt ?? null;

        return match ($category) {
            'Deals Enrolled' => [$amount, $debt, null],   // Amount=count, Tiebreaker_1=debt
            'Debt Enrolled' => [$amount, $deals, null],   // Amount=debt,  Tiebreaker_1=count
            'Active Clients' => [$amount, null, null],    // both tiebreakers NULL
            default => [$amount, $deals, $debt],          // Tiebreaker_1=deals, Tiebreaker_2=debt
        };
    }

    /** Name (upper-cased, trimmed) → TblEmployees.PK map for Agent_ID resolution. */
    public function agentIdMap(): array
    {
        $map = [];
        foreach ($this->conn()->select('SELECT PK, Employee_Name FROM TblEmployees') as $r) {
            $name = strtoupper(trim((string) $r->Employee_Name));
            if ($name !== '') {
                $map[$name] = $r->PK;
            }
        }

        return $map;
    }
}
