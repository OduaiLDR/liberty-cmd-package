<?php

namespace Cmd\Reports\Console\Commands\SyncLeaderboardRecords;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Rebuilds TblLeaderboard record snapshots — PHP port of the VBA macro
 * `UpdateLeaderboardRecords` + the new-record detection inside `GenerateLeaderboard`.
 *
 * Runs monthly (scheduled from the cmd-runner GUI, no args → finalises the previous month).
 * A one-time historical backfill is a manual run with --from / --to.
 *
 * The DB is treated read-only by the developer: this command performs the only writes,
 * and only when run by the user (dry-run is available for preview).
 */
class SyncLeaderboardRecords extends Command
{
    protected $signature = 'leaderboard:sync-records
        {--from= : Span start YYYY-MM-DD (default: 4 months back, start of month)}
        {--to= : Span end YYYY-MM-DD (default: last day of previous month)}
        {--periods= : Comma list of period types to run (Daily,Weekly,Monthly,Quarterly,Yearly); default = all active}
        {--category= : Comma list of categories to run (default = all 8); e.g. --category="Individual Debt"}
        {--dry-run : Preview only — list records that WOULD be written, no DB writes}
        {--emails : Send "New Record" congratulations emails (default off)}';

    protected $description = 'Finalise leaderboard records for completed periods (port of UpdateLeaderboardRecords). Runs monthly on the 15th; Individual Debt lands ~4 months back so deals season.';

    /**
     * Payroll bonus writes are DISABLED for now (Jacob: keep off, but coded so a bonus would
     * post on the FOLLOWING month's 10th). Flip to true only once payroll is signed off.
     */
    private const PAYROLL_ENABLED = false;

    private const BONUS_STEP = 62.5; // VBA: +62.5 per new record for Active Clients / Conversion Ratio

    private LeaderboardRecordsRepository $repo;

    /** @var array<string,int> name(upper) => Agent_ID */
    private array $agentIds = [];

    private bool $dryRun = false;
    private bool $sendEmails = false;

    /** @var array<int,string> period types to restrict to (empty = all active) */
    private array $onlyPeriods = [];

    /** @var array<int,string> categories to restrict to (empty = all 8) */
    private array $onlyCategories = [];

    /** In-memory record accumulation so a single run (incl. dry-run) sees its own additions. */
    private array $recordCache = [];
    private array $recordLoaded = [];
    private array $companyCache = [];
    private array $companyLoaded = [];

    private int $instances = 0;
    private int $inserted = 0;
    private int $duplicates = 0;
    private int $skippedThreshold = 0;
    private int $unresolvedAgents = 0;

    public function handle(): int
    {
        $this->repo = new LeaderboardRecordsRepository();
        $this->dryRun = (bool) $this->option('dry-run');
        $this->sendEmails = (bool) $this->option('emails') && ! $this->dryRun;
        $this->onlyPeriods = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('periods')))));
        $this->onlyCategories = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('category')))));

        [$from, $to] = $this->resolveSpan();
        if ($from->gt($to)) {
            $this->error("--from ({$from->toDateString()}) is after --to ({$to->toDateString()}).");
            return self::FAILURE;
        }

        $this->line(sprintf(
            '<info>Leaderboard record sync</info> — span %s … %s%s%s',
            $from->toDateString(),
            $to->toDateString(),
            $this->dryRun ? '  [DRY RUN]' : '  [LIVE]',
            self::PAYROLL_ENABLED ? '' : '  (payroll disabled)'
        ));

        try {
            $this->agentIds = $this->repo->agentIdMap();
        } catch (Throwable $e) {
            $this->error('Could not load TblEmployees: ' . $e->getMessage());
            return self::FAILURE;
        }

        foreach (LeaderboardRecordsRepository::CATEGORIES as $category) {
            if (! empty($this->onlyCategories) && ! in_array($category, $this->onlyCategories, true)) {
                continue;
            }
            $periods = $this->repo->periodsFor($category);
            foreach ($periods as $period) {
                if (! empty($this->onlyPeriods) && ! in_array($period, $this->onlyPeriods, true)) {
                    continue;
                }
                $this->processCategoryPeriod($category, $period, $from, $to);
            }
        }

        $this->newLine();
        $this->line('<info>Done.</info>');
        $this->table(
            ['Instances', 'Records written', 'Duplicates skipped', 'Below threshold', 'Unresolved agents'],
            [[$this->instances, $this->inserted, $this->duplicates, $this->skippedThreshold, $this->unresolvedAgents]]
        );
        if ($this->dryRun) {
            $this->warn('DRY RUN — no rows were written. Re-run without --dry-run to commit.');
        }

        return self::SUCCESS;
    }

    /**
     * Process every completed instance of (category, period) inside the span, chronologically.
     */
    private function processCategoryPeriod(string $category, string $period, Carbon $from, Carbon $to): void
    {
        $newRecordsThisInstance = 0;

        foreach ($this->enumerateInstances($period, $from, $to) as [$start, $end]) {
            // Individual Debt lands ~4 months back: only finalise once the period has had 3 months to
            // season (so each deal's cancel window — First_Payment_Cleared_Date + 3 months — has elapsed).
            if ($category === 'Individual Debt' && $end->copy()->addMonthsNoOverflow(3)->gt(Carbon::today())) {
                continue;
            }

            $this->instances++;

            $threshold = $this->repo->fullThreshold($category, $period);
            $dir = $this->repo->direction($category);

            $leaders = $this->repo->currentLeaders($category, $period, $end);
            $records = $this->getRecords($category, $period);

            $new = $this->detectNewRecords($category, $leaders, $records, $threshold, $dir);

            foreach ($new as $rec) {
                $agentId = $this->resolveAgentId($rec['agent']);
                if ($agentId === null) {
                    $this->unresolvedAgents++;
                    $this->warn("  · {$category}/{$period} {$start->toDateString()}: no Agent_ID for \"{$rec['agent']}\" — skipped");
                    continue;
                }

                $status = $this->writeRecord(
                    $category, $period, $agentId,
                    $rec['amount'], $rec['tb1'], $rec['tb2'],
                    $start->toDateString(), $rec['place']
                );

                if ($status === 'inserted') {
                    $newRecordsThisInstance++;
                    $this->pushRecord($category, $period, $rec['amount'], $rec['tb1'], $rec['tb2'], $dir);
                    $this->reportRecord($category, $period, $start, $rec);
                    if (self::PAYROLL_ENABLED) {
                        $this->applyPayroll($category, $period, $agentId, $rec['place'], $start);
                    }
                    if ($this->sendEmails) {
                        $this->sendCongrats($category, $period, $rec, false);
                    }
                }
            }

            // Bonus auto-increase (Active Clients / Conversion Ratio) — VBA 166-178. Payroll-gated.
            if (self::PAYROLL_ENABLED && $newRecordsThisInstance > 0
                && in_array($category, ['Active Clients', 'Conversion Ratio'], true)) {
                $this->bumpBonus($category, $period, $newRecordsThisInstance);
            }

            $this->processCompanyRecord($category, $period, $start, $end, $dir);
        }
    }

    /**
     * Company-Wide record (VBA 180-220). Gate ≈ company volume > Threshold×5, then beat the record.
     */
    private function processCompanyRecord(string $category, string $period, Carbon $start, Carbon $end, string $dir): void
    {
        if ($category === 'Individual Debt') {
            return;
        }

        $company = $this->repo->currentCompany($category, $period, $end);
        if (! $company || ($company->amount ?? null) === null) {
            return;
        }

        $threshold = $this->repo->fullThreshold($category, $period);
        $volume = $this->repo->isRatio($category) ? (int) ($company->contacts ?? 0) : (int) ($company->deals ?? 0);
        if ($volume <= $threshold * 5) {
            return;
        }

        [$amount, $tb1, $tb2] = $this->repo->inverseMap($category, $company);
        $existing = $this->getCompanyRecord($category, $period);

        $isNew = false;
        if ($existing === null) {
            $isNew = true;
        } elseif (! $this->sameTriple(['amount' => $amount, 'tb1' => $tb1, 'tb2' => $tb2], $existing)) {
            $cur = ['amount' => $amount, 'tb1' => $tb1, 'tb2' => $tb2];
            $old = ['amount' => $existing->amount, 'tb1' => $existing->tb1, 'tb2' => $existing->tb2];
            $isNew = $this->rankCompare($cur, $old, $dir) < 0;
        }

        if (! $isNew) {
            return;
        }

        $status = $this->writeRecord(
            $category . ' - Company-Wide', $period, null,
            $amount, $tb1, $tb2, $start->toDateString(), 1
        );

        if ($status === 'inserted') {
            $this->pushCompanyRecord($category, $period, $amount, $tb1, $tb2);
            $this->reportRecord($category . ' - Company-Wide', $period, $start, ['place' => 1, 'agent' => 'Company-Wide', 'amount' => $amount, 'tb1' => $tb1, 'tb2' => $tb2]);
            if ($this->sendEmails) {
                $this->sendCongrats($category, $period, ['place' => 1, 'agent' => 'Company-Wide', 'amount' => $amount, 'tb1' => $tb1, 'tb2' => $tb2], true);
            }
        }
    }

    // ------------------------------------------------------------------
    // New-record detection (GenerateLeaderboard 513-596)
    // ------------------------------------------------------------------

    /**
     * @param  array<int,object>  $records  existing top-4 {agent_id, amount, tb1, tb2}
     * @return array<int, array{place:int,agent:string,amount:mixed,tb1:mixed,tb2:mixed}>
     */
    private function detectNewRecords(string $category, $leaders, array $records, int $threshold, string $dir): array
    {
        $candidates = [];

        foreach ($leaders as $row) {
            // Threshold gate ("*", gen 717-728): ratio categories gate on deals (denominator),
            // others on contacts. Below full Threshold → not eligible.
            $gate = $this->repo->isRatio($category) ? (int) ($row->deals ?? 0) : (int) ($row->contacts ?? 0);
            if ($gate < $threshold) {
                $this->skippedThreshold++;
                continue;
            }

            [$amount, $tb1, $tb2] = $this->repo->inverseMap($category, $row);
            if ($amount === null) {
                continue;
            }

            $cand = ['source' => 'current', 'agent' => (string) ($row->agent ?? ''), 'amount' => $amount, 'tb1' => $tb1, 'tb2' => $tb2];

            // Drop if it exactly equals an existing record (already held → not new).
            $dup = false;
            foreach ($records as $r) {
                if ($this->sameTriple($cand, $r)) {
                    $dup = true;
                    break;
                }
            }
            if (! $dup) {
                $candidates[] = $cand;
            }
        }

        if (empty($candidates)) {
            return [];
        }

        $pool = [];
        foreach ($records as $r) {
            $pool[] = ['source' => 'record', 'agent' => '', 'amount' => $r->amount, 'tb1' => $r->tb1, 'tb2' => $r->tb2];
        }
        foreach ($candidates as $c) {
            $pool[] = $c;
        }

        usort($pool, fn($a, $b) => $this->rankCompare($a, $b, $dir));

        $new = [];
        foreach (array_slice($pool, 0, 4) as $i => $entry) {
            if ($entry['source'] === 'current') {
                $new[] = ['place' => $i + 1, 'agent' => $entry['agent'], 'amount' => $entry['amount'], 'tb1' => $entry['tb1'], 'tb2' => $entry['tb2']];
            }
        }

        return $new;
    }

    /** Rank comparator: best entry first. Amount by $dir, then Tiebreaker_1, Tiebreaker_2 DESC. */
    private function rankCompare(array $a, array $b, string $dir): int
    {
        $c = $this->cmpNum($a['amount'], $b['amount'], $dir === 'desc');
        if ($c !== 0) {
            return $c;
        }
        $c = $this->cmpNum($a['tb1'], $b['tb1'], true);
        if ($c !== 0) {
            return $c;
        }
        return $this->cmpNum($a['tb2'], $b['tb2'], true);
    }

    /** -1 if $x ranks before $y, 1 if after, 0 if equal. NULL always sorts last. */
    private function cmpNum($x, $y, bool $desc): int
    {
        $xn = $x === null;
        $yn = $y === null;
        if ($xn && $yn) {
            return 0;
        }
        if ($xn) {
            return 1;
        }
        if ($yn) {
            return -1;
        }
        $x = (float) $x;
        $y = (float) $y;
        if (abs($x - $y) < 0.0000001) {
            return 0;
        }
        if ($desc) {
            return $x > $y ? -1 : 1;
        }
        return $x < $y ? -1 : 1;
    }

    private function sameTriple(array $cand, object $rec): bool
    {
        return $this->eqNum($cand['amount'], $rec->amount ?? null)
            && $this->eqNum($cand['tb1'], $rec->tb1 ?? null)
            && $this->eqNum($cand['tb2'], $rec->tb2 ?? null);
    }

    private function eqNum($x, $y): bool
    {
        if ($x === null && $y === null) {
            return true;
        }
        if ($x === null || $y === null) {
            return false;
        }
        return abs((float) $x - (float) $y) < 0.0000001;
    }

    // ------------------------------------------------------------------
    // Writes
    // ------------------------------------------------------------------

    /** @return 'inserted'|'duplicate' */
    private function writeRecord(string $category, string $period, $agentId, $amount, $tb1, $tb2, string $date, int $place): string
    {
        $conn = $this->conn();

        $where = 'Category = ? AND Period = ? AND Leaderboard_Date = ? AND Amount = ?';
        $bindings = [$category, $period, $date, $amount];
        if ($agentId === null) {
            $where .= ' AND Agent_ID IS NULL';
        } else {
            $where .= ' AND Agent_ID = ?';
            $bindings[] = $agentId;
        }

        $exists = (int) ($conn->selectOne("SELECT COUNT(*) AS c FROM TblLeaderboard WHERE {$where}", $bindings)->c ?? 0);
        if ($exists > 0) {
            $this->duplicates++;
            return 'duplicate';
        }

        if (! $this->dryRun) {
            // Current_Record still populated (with the rank) so the existing column is satisfied.
            // Nothing reads it — records order dynamically by Amount. Drop it from this INSERT once
            // the column is removed from TblLeaderboard.
            $conn->insert(
                'INSERT INTO TblLeaderboard (Category, Period, Agent_ID, Amount, Tiebreaker_1, Tiebreaker_2, Leaderboard_Date, Current_Record) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$category, $period, $agentId, $amount, $tb1, $tb2, $date, $place]
            );
        }

        $this->inserted++;
        return 'inserted';
    }

    // ------------------------------------------------------------------
    // In-run record accumulation — keeps a single run (esp. dry-run) consistent: each instance
    // sees the records added by earlier instances in the same run, just as a live run would via the DB.
    // ------------------------------------------------------------------

    /** @return array<int,object> existing top-4 records for (category, period), accumulating this run. */
    private function getRecords(string $category, string $period): array
    {
        $key = $category . '|' . $period;
        if (! ($this->recordLoaded[$key] ?? false)) {
            $this->recordCache[$key] = $this->repo->recordRowsRaw($category, $period);
            $this->recordLoaded[$key] = true;
        }
        return $this->recordCache[$key];
    }

    private function pushRecord(string $category, string $period, $amount, $tb1, $tb2, string $dir): void
    {
        $key = $category . '|' . $period;
        $list = $this->recordCache[$key] ?? [];
        $list[] = (object) ['amount' => $amount, 'tb1' => $tb1, 'tb2' => $tb2, 'agent_id' => null];
        usort($list, fn($a, $b) => $this->rankCompare(
            ['amount' => $a->amount, 'tb1' => $a->tb1, 'tb2' => $a->tb2],
            ['amount' => $b->amount, 'tb1' => $b->tb1, 'tb2' => $b->tb2],
            $dir
        ));
        $this->recordCache[$key] = array_slice($list, 0, 4);
        $this->recordLoaded[$key] = true;
    }

    private function getCompanyRecord(string $category, string $period): ?object
    {
        $key = $category . '|' . $period;
        if (! ($this->companyLoaded[$key] ?? false)) {
            $this->companyCache[$key] = $this->repo->companyRecordRaw($category, $period);
            $this->companyLoaded[$key] = true;
        }
        return $this->companyCache[$key];
    }

    private function pushCompanyRecord(string $category, string $period, $amount, $tb1, $tb2): void
    {
        $key = $category . '|' . $period;
        $this->companyCache[$key] = (object) ['amount' => $amount, 'tb1' => $tb1, 'tb2' => $tb2];
        $this->companyLoaded[$key] = true;
    }

    // ------------------------------------------------------------------
    // Payroll (DISABLED — PAYROLL_ENABLED = false). VBA 108-162, 166-178.
    // ------------------------------------------------------------------

    private function applyPayroll(string $category, string $period, $agentId, int $place, Carbon $recordDate): void
    {
        $settings = $this->repo->settings($category, $period);
        $bonus = (float) ($settings->{'Bonus_' . $place} ?? 0);
        if ($bonus <= 0) {
            return;
        }

        $placeLabel = [1 => '1st', 2 => '2nd', 3 => '3rd', 4 => '4th'][$place] ?? (string) $place;
        $note = sprintf('%s - %s - %s Place - %s', $category, $period, $placeLabel, $recordDate->toDateString());

        // Following month's 10th relative to the actual run date (managers get time to flag errors).
        $payrollDate = Carbon::today()->addMonthNoOverflow()->day(10)->toDateString();

        $conn = $this->conn();
        $dupe = (int) ($conn->selectOne(
            'SELECT COUNT(*) AS c FROM TblPayrollAdjustments WHERE Agent_ID = ? AND Notes = ?',
            [$agentId, $note]
        )->c ?? 0);
        if ($dupe > 0) {
            return;
        }

        if (! $this->dryRun) {
            $conn->insert(
                'INSERT INTO TblPayrollAdjustments (Agent_ID, Category, Payroll_Date, Amount, Notes) VALUES (?, ?, ?, ?, ?)',
                [$agentId, 'Bonus', $payrollDate, $bonus, $note]
            );
        }
    }

    private function bumpBonus(string $category, string $period, int $newRecords): void
    {
        if ($this->dryRun) {
            return;
        }
        $delta = self::BONUS_STEP * $newRecords;
        $this->conn()->update(
            'UPDATE TblLeaderboardSettings SET Bonus_1 = Bonus_1 + ?, Bonus_2 = Bonus_2 + ?, Bonus_3 = Bonus_3 + ?, Bonus_4 = Bonus_4 + ? '
            . 'WHERE Category = ? AND Period = ?',
            [$delta, $delta, $delta, $delta, $category, $period]
        );
    }

    // ------------------------------------------------------------------
    // Email (off unless --emails). VBA 119-138, 216-219.
    // ------------------------------------------------------------------

    private function sendCongrats(string $category, string $period, array $rec, bool $companyWide): void
    {
        // Congratulations emails are NOT wired yet — pending Jacob's confirmation (fire going
        // forward, or stay off?) and a TblReports row + DBConnector for the managers recipient list.
        // The subject/body below mirror VBA 119-138 / 216-219 and are ready to send once approved.
        $placeLabel = [1 => '1st', 2 => '2nd', 3 => '3rd', 4 => '4th'][$rec['place']] ?? (string) $rec['place'];
        $subject = "New Company Record Set: {$category} - {$period}";
        $body = $companyWide
            ? "Congratulations for setting a new company-wide record for {$category} - {$period} ({$placeLabel} Place)."
            : "Congratulations {$rec['agent']} for setting a new company record for {$category} - {$period} ({$placeLabel} Place).";

        $this->warn("  · email pending sign-off (not sent): {$subject} — {$body}");
    }

    // ------------------------------------------------------------------
    // Span / instance enumeration
    // ------------------------------------------------------------------

    /** @return array{0:Carbon,1:Carbon} */
    private function resolveSpan(): array
    {
        $fromOpt = $this->option('from');
        $toOpt = $this->option('to');

        // Default reaches back ~4 months so Individual Debt's seasoned month is in range. Idempotency
        // makes the overlap with already-finalised months free (they're skipped) + provides catch-up.
        $from = $fromOpt
            ? Carbon::parse($fromOpt)->startOfDay()
            : Carbon::today()->subMonthsNoOverflow(4)->startOfMonth();

        $to = $toOpt
            ? Carbon::parse($toOpt)->startOfDay()
            : Carbon::today()->subMonthNoOverflow()->endOfMonth();

        return [$from, $to];
    }

    /**
     * Completed instances of $period within [$from, $to], chronologically. Weekly is keyed by its
     * Monday (start); the others by their end. Each entry is [start, end]; start = Leaderboard_Date,
     * end = the as-of date.
     *
     * @return array<int, array{0:Carbon,1:Carbon}>
     */
    private function enumerateInstances(string $period, Carbon $from, Carbon $to): array
    {
        $out = [];

        switch ($period) {
            case 'Daily':
                for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
                    $out[] = [$d->copy(), $d->copy()];
                }
                break;

            case 'Weekly':
                // Weeks owned by their Monday (Jacob): include weeks whose MONDAY is in [from, to].
                // The Sunday end may fall just past `to` — fine, the 15th-of-month run has cleared data.
                $start = $from->copy()->startOfWeek(Carbon::MONDAY);
                while ($start->lt($from)) {
                    $start->addDays(7);
                }
                while ($start->lte($to)) {
                    $out[] = [$start->copy(), $start->copy()->addDays(6)];
                    $start->addDays(7);
                }
                break;

            case 'Monthly':
                $cur = $from->copy()->startOfMonth();
                while (true) {
                    $end = $cur->copy()->endOfMonth();
                    if ($end->gt($to)) {
                        break;
                    }
                    if ($end->gte($from)) {
                        $out[] = [$cur->copy()->startOfMonth(), $end->copy()];
                    }
                    $cur->addMonthNoOverflow();
                }
                break;

            case 'Quarterly':
                $cur = $from->copy()->firstOfQuarter();
                while (true) {
                    $end = $cur->copy()->lastOfQuarter();
                    if ($end->gt($to)) {
                        break;
                    }
                    if ($end->gte($from)) {
                        $out[] = [$cur->copy()->firstOfQuarter(), $end->copy()];
                    }
                    $cur->addQuarterNoOverflow();
                }
                break;

            case 'Yearly':
                $cur = $from->copy()->startOfYear();
                while (true) {
                    $end = $cur->copy()->endOfYear();
                    if ($end->gt($to)) {
                        break;
                    }
                    if ($end->gte($from)) {
                        $out[] = [$cur->copy()->startOfYear(), $end->copy()];
                    }
                    $cur->addYearNoOverflow();
                }
                break;
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function resolveAgentId(string $name): mixed
    {
        return $this->agentIds[strtoupper(trim($name))] ?? null;
    }

    private function conn(): ConnectionInterface
    {
        return DB::connection('sqlsrv');
    }

    private function reportRecord(string $category, string $period, Carbon $date, array $rec): void
    {
        $tag = $this->dryRun ? 'WOULD ADD' : 'ADDED';
        $this->line(sprintf(
            '  <comment>%s</comment> %s / %s  %s  #%d  %s  amt=%s',
            $tag,
            $category,
            $period,
            $date->toDateString(),
            $rec['place'],
            $rec['agent'],
            $this->fmt($rec['amount'])
        ));
    }

    private function fmt($v): string
    {
        if ($v === null) {
            return 'N/A';
        }
        if (is_float($v) && $v < 1 && $v > 0) {
            return number_format($v * 100, 2) . '%';
        }
        return is_numeric($v) ? number_format((float) $v, ((float) $v == (int) $v) ? 0 : 2) : (string) $v;
    }
}
