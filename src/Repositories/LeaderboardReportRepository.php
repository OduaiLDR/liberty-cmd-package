<?php

namespace Cmd\Reports\Repositories;

use Carbon\Carbon;
use Illuminate\Support\Collection;


class LeaderboardReportRepository extends SqlSrvRepository
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

    /**
     * Sort direction for a category: 'asc' for cancellation/NSF (lower is better), else 'desc'.
     */
    public function direction(string $category): string
    {
        return in_array($category, self::RATIO_ASC, true) ? 'asc' : 'desc';
    }

    /**
     * @return array{amount_label:string,amount_format:string,show_contacts:bool,show_deals:bool,deals_label:string,show_debt:bool,individual:bool}
     */
    public function layout(string $category): array
    {
        $base = [
            'amount_label' => 'Amount',
            'amount_format' => 'int',
            'show_contacts' => true,
            'show_deals' => true,
            'deals_label' => 'Deals',
            'show_debt' => true,
            'individual' => false,
        ];

        return match ($category) {
            'Deals Enrolled' => ['amount_label' => 'Deals', 'amount_format' => 'int', 'show_deals' => false] + $base,
            'Debt Enrolled' => ['amount_label' => 'Debt', 'amount_format' => 'currency', 'show_debt' => false] + $base,
            'Same Month Pay' => ['amount_label' => 'Same-Month Pays', 'amount_format' => 'int'] + $base,
            'Conversion Ratio' => ['amount_label' => 'Conversion %', 'amount_format' => 'percent', 'deals_label' => 'Enrolled'] + $base,
            'Cancellation Ratio' => ['amount_label' => 'Cancellation %', 'amount_format' => 'percent'] + $base,
            'NSF Ratio' => ['amount_label' => 'NSF %', 'amount_format' => 'percent'] + $base,
            'Active Clients' => ['amount_label' => 'Active Clients', 'amount_format' => 'int', 'show_deals' => false] + $base,
            'Individual Debt' => ['amount_label' => 'Individual Debt', 'amount_format' => 'currency', 'show_deals' => false, 'show_debt' => false, 'individual' => true] + $base,
            default => $base,
        };
    }

    public function periodsFor(string $category): Collection
    {
        $rows = $this->connection()->select(
            "SELECT Period FROM TblLeaderboardSettings WHERE Category = ? AND Active = 'TRUE' ORDER BY PK",
            [$category]
        );

        return collect($rows)->pluck('Period')->filter()->unique()->values();
    }


    public function settings(string $category, string $period): ?object
    {
        return $this->connection()->selectOne(
            'SELECT Bonus_1, Bonus_2, Bonus_3, Bonus_4, Threshold, Activity_Cutoff, Tiebreaker, Notes '
            . 'FROM TblLeaderboardSettings WHERE Category = ? AND Period = ?',
            [$category, $period]
        );
    }


    public function resolveWindow(string $period): array
    {
        $report = Carbon::today()->subDays(3);

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
                $cutoff = $report->copy()->endOfMonth(); // VBA 118: end of CURRENT month
                break;
            case 'Yearly':
                $from = $report->copy()->startOfYear();
                $to = $report->copy()->endOfYear();
                $cutoff = $report->copy()->endOfMonth(); // VBA 123: end of CURRENT month
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
     * Top-of-page title, e.g. "Daily Individual Debt Leaderboard".
     */
    public function titleLabel(string $category, string $period): string
    {
        return "{$period} {$category} Leaderboard";
    }

    /**
     * Current-leaders header — VBA 98-124. "Current" prefix on every period except Monthly;
     * Daily shows a single date, the rest a "from to to" range. Dates as m/d/Y (no leading zeros).
     */
    public function currentHeader(string $category, string $period, array $window): string
    {
        $from = $window['from']->format('n/j/Y');
        $to = $window['to']->format('n/j/Y');

        return match ($period) {
            'Daily' => "Current Daily {$category} Leaders - {$from}",
            'Weekly' => "Current Weekly {$category} Leaders - {$from} to {$to}",
            'Monthly' => "Monthly {$category} Leaders - {$from} to {$to}",
            'Quarterly' => "Current Quarterly {$category} Leaders - {$from} to {$to}",
            'Yearly' => "Current Yearly {$category} Leaders - {$from} to {$to}",
            default => "{$period} {$category} Leaders - {$from} to {$to}",
        };
    }

    public function currentLeaders(string $category, string $period): Collection
    {
        $w = $this->resolveWindow($period);
        $from = $w['from']->format('Y-m-d');
        $to = $w['to']->format('Y-m-d');
        $cutoff = $w['cutoff']->format('Y-m-d');
        $half = $this->halfThreshold($category, $period);

        $rows = match ($category) {
            'Deals Enrolled' => $this->connection()->select(
                'SELECT TOP (4) Agent AS agent, COUNT(*) AS amount, COUNT(*) AS deals, SUM(Debt_Amount) AS debt '
                . 'FROM TblEnrollment '
                . 'WHERE Submitted_Date >= ? AND Submitted_Date <= ? '
                . 'AND (Cancel_Date IS NULL OR Cancel_Date > ?) AND (NSF_Date IS NULL OR NSF_Date > ?) '
                . 'GROUP BY Agent ORDER BY COUNT(*) DESC, SUM(Debt_Amount) DESC',
                [$from, $to, $cutoff, $cutoff]
            ),
            'Debt Enrolled' => $this->connection()->select(
                'SELECT TOP (4) Agent AS agent, SUM(Debt_Amount) AS amount, COUNT(*) AS deals, SUM(Debt_Amount) AS debt '
                . 'FROM TblEnrollment '
                . 'WHERE Submitted_Date >= ? AND Submitted_Date <= ? '
                . 'AND (Cancel_Date IS NULL OR Cancel_Date > ?) AND (NSF_Date IS NULL OR NSF_Date > ?) '
                . 'GROUP BY Agent ORDER BY SUM(Debt_Amount) DESC, COUNT(*) DESC',
                [$from, $to, $cutoff, $cutoff]
            ),
            'Same Month Pay' => $this->connection()->select(
                'SELECT TOP (4) Agent AS agent, '
                . 'SUM(CASE WHEN COALESCE(Payment_Date_2, Payment_Date_1) <= EOMONTH(Submitted_Date) THEN 1 ELSE 0 END) AS amount, '
                . 'COUNT(*) AS deals, SUM(Debt_Amount) AS debt '
                . 'FROM TblEnrollment '
                . 'WHERE Submitted_Date >= ? AND Submitted_Date <= ? '
                . 'AND (Cancel_Date IS NULL OR Cancel_Date > EOMONTH(Submitted_Date)) '
                . 'AND (NSF_Date IS NULL OR NSF_Date > EOMONTH(Submitted_Date)) '
                . 'GROUP BY Agent '
                . 'HAVING SUM(CASE WHEN COALESCE(Payment_Date_2, Payment_Date_1) <= EOMONTH(Submitted_Date) THEN 1 ELSE 0 END) > 0 '
                . 'ORDER BY amount DESC, debt DESC',
                [$from, $to]
            ),
            // VBA 258-303: contacts keyed by TblContacts.Agent (HAVING >= Threshold/2),
            // enrolled+debt keyed by TblEnrollment.Agent, merged by agent name,
            // ranked by debt DESC then ratio DESC (VBA sort Key1=debt, Key2=ratio).
            'Conversion Ratio' => $this->connection()->select(
                'SELECT TOP (4) c.Agent AS agent, c.contacts AS contacts, '
                . 'COALESCE(en.enrolled, 0) AS deals, COALESCE(en.debt, 0) AS debt, '
                . 'CAST(COALESCE(en.enrolled, 0) AS FLOAT) / NULLIF(c.contacts, 0) AS amount '
                . 'FROM ('
                . 'SELECT Agent, COUNT(*) AS contacts FROM TblContacts '
                . 'WHERE COALESCE(Assigned_Date, Created_Date) >= ? AND COALESCE(Assigned_Date, Created_Date) < ? '
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
            'Cancellation Ratio', 'NSF Ratio' => $this->connection()->select(
                'SELECT TOP (4) Agent AS agent, '
                . 'CAST(SUM(CASE WHEN ' . $this->ratioColumn($category) . ' IS NOT NULL THEN 1 ELSE 0 END) AS FLOAT) / NULLIF(COUNT(*), 0) AS amount, '
                . 'COUNT(*) AS deals, SUM(Debt_Amount) AS debt '
                . 'FROM TblEnrollment '
                . 'WHERE Submitted_Date >= ? AND Submitted_Date <= ? '
                . 'GROUP BY Agent HAVING COUNT(*) >= ? '
                . 'ORDER BY amount ASC, COUNT(*) DESC',
                [$from, $to, $half]
            ),
            'Active Clients' => $this->connection()->select(
                'SELECT TOP (4) Agent AS agent, COUNT(*) AS amount, COUNT(*) AS deals, SUM(Debt_Amount) AS debt '
                . 'FROM TblEnrollment '
                . "WHERE Cancel_Date IS NULL AND NSF_Date IS NULL AND Category = 'LDR' "
                . 'GROUP BY Agent ORDER BY COUNT(*) DESC, SUM(Debt_Amount) DESC',
                []
            ),
            'Individual Debt' => $this->connection()->select(
                'SELECT TOP (4) Agent AS agent, Debt_Amount AS amount, Submitted_Date AS submitted_date, '
                . 'COALESCE(Payment_Date_2, Payment_Date_1) AS payment_date, LLG_ID AS llg_id, Client AS client, Payments AS payments '
                . 'FROM TblEnrollment '
                . 'WHERE COALESCE(Payment_Date_2, Payment_Date_1) >= ? AND Submitted_Date <= ? '
                . 'AND Cancel_Date IS NULL AND NSF_Date IS NULL '
                . 'ORDER BY Debt_Amount DESC',
                [$w['report']->copy()->startOfMonth()->subMonths(4)->format('Y-m-d'), $to]
            ),
            default => [],
        };

        $leaders = collect($rows);

        // Contacts (col E) recomputed per agent over the current window — VBA 607-616.
        foreach ($leaders as $row) {
            $row->contacts = $this->contactsFor($row->agent ?? null, $w['from'], $w['to']);
            if ($category === 'Individual Debt') {
                $row->note = $this->individualDebtNote($row);
            }
        }

        return $leaders;
    }

    /**
     * Company-Wide current row (the second, un-grouped query in each VBA branch). Null for Individual Debt.
     */
    public function currentCompany(string $category, string $period): ?object
    {
        if ($category === 'Individual Debt') {
            return null;
        }

        $w = $this->resolveWindow($period);
        $from = $w['from']->format('Y-m-d');
        $to = $w['to']->format('Y-m-d');
        $cutoff = $w['cutoff']->format('Y-m-d');
        $threshold = $this->fullThreshold($category, $period);

        $row = match ($category) {
            'Deals Enrolled' => $this->connection()->selectOne(
                'SELECT COUNT(*) AS amount, COUNT(*) AS deals, SUM(Debt_Amount) AS debt FROM TblEnrollment '
                . 'WHERE Submitted_Date >= ? AND Submitted_Date <= ? '
                . 'AND (Cancel_Date IS NULL OR Cancel_Date > ?) AND (NSF_Date IS NULL OR NSF_Date > ?)',
                [$from, $to, $cutoff, $cutoff]
            ),
            'Debt Enrolled' => $this->connection()->selectOne(
                'SELECT SUM(Debt_Amount) AS amount, COUNT(*) AS deals, SUM(Debt_Amount) AS debt FROM TblEnrollment '
                . 'WHERE Submitted_Date >= ? AND Submitted_Date <= ? '
                . 'AND (Cancel_Date IS NULL OR Cancel_Date > ?) AND (NSF_Date IS NULL OR NSF_Date > ?)',
                [$from, $to, $cutoff, $cutoff]
            ),
            'Same Month Pay' => $this->connection()->selectOne(
                'SELECT SUM(CASE WHEN COALESCE(Payment_Date_2, Payment_Date_1) <= EOMONTH(Submitted_Date) THEN 1 ELSE 0 END) AS amount, '
                . 'COUNT(*) AS deals, SUM(Debt_Amount) AS debt FROM TblEnrollment '
                . 'WHERE Submitted_Date >= ? AND Submitted_Date <= ? '
                . 'AND (Cancel_Date IS NULL OR Cancel_Date > EOMONTH(Submitted_Date)) '
                . 'AND (NSF_Date IS NULL OR NSF_Date > EOMONTH(Submitted_Date))',
                [$from, $to]
            ),
            'Conversion Ratio' => $this->conversionCompany($from, $w['to']->copy()->addDay()->format('Y-m-d'), $threshold),
            'Cancellation Ratio', 'NSF Ratio' => $this->ratioCompany($category, $from, $to, $threshold),
            'Active Clients' => $this->connection()->selectOne(
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
        $contacts = (int) ($this->connection()->selectOne(
            'SELECT COUNT(*) AS c FROM TblContacts '
            . 'WHERE COALESCE(Assigned_Date, Created_Date) >= ? AND COALESCE(Assigned_Date, Created_Date) < ?',
            [$from, $toExclusive]
        )->c ?? 0);

        if ($contacts < $threshold) {
            return null;
        }

        $enrolled = $this->connection()->selectOne(
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
        $totals = $this->connection()->selectOne(
            'SELECT COUNT(*) AS deals, SUM(Debt_Amount) AS debt FROM TblEnrollment '
            . 'WHERE Submitted_Date >= ? AND Submitted_Date <= ?',
            [$from, $to]
        );

        $count = (int) ($totals->deals ?? 0);
        if ($count < $threshold) {
            return null;
        }

        $hits = (int) ($this->connection()->selectOne(
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

    // ---------------------------------------------------------------------
    // Record Holders (all-time, from TblLeaderboard) — VBA 468-511
    // ---------------------------------------------------------------------

    /**
     * @return Collection<int, object>
     */
    public function recordHolders(string $category, string $period): Collection
    {
        $dir = strtoupper($this->direction($category));

        $rows = $this->connection()->select(
            'SELECT TOP (4) e.Employee_Name AS agent, l.Amount AS amount, l.Tiebreaker_1 AS tb1, l.Tiebreaker_2 AS tb2, l.Leaderboard_Date AS record_date '
            . 'FROM TblLeaderboard AS l JOIN TblEmployees AS e ON l.Agent_ID = e.PK '
            . 'WHERE l.Category = ? AND l.Period = ? '
            . "ORDER BY l.Amount {$dir}, l.Tiebreaker_1 DESC, l.Tiebreaker_2 DESC",
            [$category, $period]
        );

        $records = collect($rows);
        foreach ($records as $row) {
            // Records are a stored snapshot from TblLeaderboard — never recomputed live.
            $this->mapRecordRow($category, $row);
        }

        return $records;
    }

    /**
     * Company-Wide all-time record (VBA 493-511).
     */
    public function companyRecord(string $category, string $period): ?object
    {
        $dir = strtoupper($this->direction($category));

        $row = $this->connection()->selectOne(
            'SELECT Amount AS amount, Tiebreaker_1 AS tb1, Tiebreaker_2 AS tb2, Leaderboard_Date AS record_date '
            . 'FROM TblLeaderboard WHERE Category = ? AND Period = ? '
            . "ORDER BY Amount {$dir}, Tiebreaker_1 DESC, Tiebreaker_2 DESC",
            [$category . ' - Company-Wide', $period]
        );

        if ($row) {
            $row->agent = 'Company-Wide';
            $this->mapRecordRow($category, $row);
        }

        return $row;
    }

    /**
     * Map TblLeaderboard Amount/Tiebreaker_1/Tiebreaker_2 onto deals/debt exactly as VBA 479-489 & 500-510.
     */
    private function mapRecordRow(string $category, object $row): void
    {
        $amount = $row->amount ?? null;
        $tb1 = $row->tb1 ?? null;
        $tb2 = $row->tb2 ?? null;

        switch ($category) {
            case 'Deals Enrolled':
            case 'Active Clients':
                $row->deals = $amount;   // F = Amount
                $row->debt = $tb1;       // G = Tiebreaker_1
                break;
            case 'Debt Enrolled':
                $row->deals = $tb1;      // F = Tiebreaker_1 (count)
                $row->debt = $amount;    // G = Amount (debt)
                break;
            default:                     // Same Month Pay, Conversion, Cancellation, NSF, Individual Debt
                $row->deals = $tb1;      // F = Tiebreaker_1
                $row->debt = $tb2;       // G = Tiebreaker_2
                break;
        }
    }

    public function totalRecords(): Collection
    {
        $sql = <<<'SQL'
SELECT agent,
       COUNT(*) AS records,
       SUM(CASE WHEN place = 1 THEN 1 ELSE 0 END) AS first_count,
       SUM(CASE WHEN place = 2 THEN 1 ELSE 0 END) AS second_count,
       SUM(CASE WHEN place = 3 THEN 1 ELSE 0 END) AS third_count,
       SUM(CASE WHEN place = 4 THEN 1 ELSE 0 END) AS fourth_count
FROM (
    SELECT e.Employee_Name AS agent,
           ROW_NUMBER() OVER (
               PARTITION BY l.Category, l.Period
               ORDER BY CASE WHEN l.Category IN ('Cancellation Ratio','NSF Ratio') THEN l.Amount END ASC,
                        CASE WHEN l.Category NOT IN ('Cancellation Ratio','NSF Ratio') THEN l.Amount END DESC,
                        l.Tiebreaker_1 DESC, l.Tiebreaker_2 DESC
           ) AS place
    FROM TblLeaderboard l
    JOIN TblEmployees e ON l.Agent_ID = e.PK
    WHERE l.Category NOT LIKE '% - Company-Wide'
) ranked
WHERE place <= 4
GROUP BY agent
ORDER BY COUNT(*) DESC,
         SUM(CASE WHEN place = 1 THEN 4 WHEN place = 2 THEN 3 WHEN place = 3 THEN 2 ELSE 1 END) DESC,
         agent ASC
SQL;

        return collect($this->connection()->select($sql));
    }


    public function all(string $category, string $period): Collection
    {
        return $this->currentLeaders($category, $period);
    }


    private function ratioColumn(string $category): string
    {
        return $category === 'Cancellation Ratio' ? 'Cancel_Date' : 'NSF_Date';
    }

    private function fullThreshold(string $category, string $period): int
    {
        $settings = $this->settings($category, $period);

        return (int) ($settings->Threshold ?? 0);
    }

    private function halfThreshold(string $category, string $period): int
    {
        return (int) floor($this->fullThreshold($category, $period) / 2);
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

        return (int) ($this->connection()->selectOne($sql, $bindings)->c ?? 0);
    }

    private function individualDebtNote(object $row): string
    {
        $fmt = fn($d) => $d ? Carbon::parse($d)->format('m/d/Y') : '';

        return sprintf(
            'LLG-ID: %s | Client: %s | Welcome Call: %s | Payment: %s | Payments: %s',
            $row->llg_id ?? '',
            $row->client ?? '',
            $fmt($row->submitted_date ?? null),
            $fmt($row->payment_date ?? null),
            $row->payments ?? ''
        );
    }
}
