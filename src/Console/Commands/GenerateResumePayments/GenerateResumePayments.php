<?php

declare(strict_types=1);

namespace Cmd\Reports\Console\Commands\GenerateResumePayments;

use App\Models\Automation;
use App\Models\AutomationLog;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Read-only health check for the nightly `Generate:resume-payments` automations.
 *
 * A run recorded as `status=success` only means the artisan command DIDN'T THROW —
 * GenerateResumePayments::handle() catches every internal error (a failed void, a dead DPP
 * session, an irreversible partial void, a company that blew up) and still returns SUCCESS.
 * The real signal is the machine-readable HEALTH line each company prints into the captured
 * output (automation_logs.output), e.g.:
 *
 *   [INFO] [LDR] HEALTH resolved=12 nsf=340 grace=49 manual=26 queued=938 completed=24 \
 *          voids_verified=1 alerts partial_void=0 session_failure=0 company_failed=0 drop_not_land=0
 *
 * This command reads those lines back from automation_logs and flags anomalies. It issues SELECTs
 * only — no Snowflake, no DPP, no writes. Weekday-aware: cancels (and therefore voids) run only on
 * weekdays (America/Los_Angeles), so a Sat/Sun run doing 0 cancels/voids is correct, not a failure.
 *
 * NOTE: this command reads the host app's App\Models\Automation + App\Models\AutomationLog (the
 * Automations panel schedules/logs live in the app, not this package). It is intended to run inside
 * the cmd-runner app; handle() guards for those models and exits cleanly if they are absent.
 */
class ResumePaymentsHealth extends Command
{
    protected $signature = 'resume-payments:health
        {--days=7 : Business days (America/Los_Angeles) to look back, inclusive of today}
        {--company=* : Limit to specific companies (LDR/PLAW); default all}';

    protected $description = 'Summarize recent Generate:resume-payments automation runs (voids/cancels health) and flag anomalies. Read-only.';

    private const TZ = 'America/Los_Angeles';

    /** Anomaly severity rank (higher = worse). */
    private const RANK = ['WARN' => 1, 'CRITICAL' => 2, 'FAIL' => 3];

    public function handle(): int
    {
        // This command depends on the host app's Automations schema. Fail clean if it isn't present
        // (e.g. the package is ever used outside cmd-runner) instead of a raw class-not-found fatal.
        if (! class_exists(Automation::class) || ! class_exists(AutomationLog::class)) {
            $this->error('resume-payments:health requires App\\Models\\Automation + App\\Models\\AutomationLog (run it inside the cmd-runner app).');

            return self::FAILURE;
        }

        $days   = max(1, (int) $this->option('days'));
        $filter = array_values(array_filter(
            array_map(static fn($c): string => strtoupper(trim((string) $c)), (array) $this->option('company')),
            static fn(string $c): bool => $c !== '',
        ));

        $todayPt  = Carbon::now(self::TZ)->startOfDay();
        $sincePt  = $todayPt->copy()->subDays($days - 1);
        $sinceUtc = $sincePt->copy()->utc();

        $automations = Automation::where('command', 'like', '%resume-payments%')
            ->orderBy('name')
            ->get();

        if ($automations->isEmpty()) {
            $this->warn('No automations whose command contains "resume-payments" were found.');

            return self::SUCCESS;
        }

        // PT business dates across the window (oldest → newest), for missing-run detection.
        $windowDates = [];
        for ($d = $sincePt->copy(); $d->lte($todayPt); $d->addDay()) {
            $windowDates[] = $d->toDateString();
        }
        $todayStr = $todayPt->toDateString();

        $rows      = [];
        $anomalies = [];

        foreach ($automations as $automation) {
            $command = (string) $automation->command;

            // Companies this automation targets (from --company=X). None given = both (the default).
            preg_match_all('/--company=([A-Za-z]+)/', $command, $cm);
            $companies = array_values(array_unique(array_map('strtoupper', $cm[1] ?? [])));
            if ($companies === []) {
                $companies = ['LDR', 'PLAW'];
            }

            // Skip automations that touch none of the requested companies.
            if ($filter !== [] && array_intersect($filter, $companies) === []) {
                continue;
            }

            $expectsCancels = stripos($command, '--execute-cancels') !== false;

            $logs = AutomationLog::where('automation_id', $automation->id)
                ->where('started_at', '>=', $sinceUtc)
                ->orderBy('started_at')
                ->get();

            // Bucket logs by their PT business date.
            $byDate = [];
            foreach ($logs as $log) {
                if ($log->started_at === null) {
                    continue;
                }
                $byDate[$log->started_at->copy()->setTimezone(self::TZ)->toDateString()][] = $log;
            }

            foreach ($windowDates as $date) {
                $isWeekend   = Carbon::parse($date, self::TZ)->isWeekend();
                $isToday     = $date === $todayStr;
                $logsForDate = $byDate[$date] ?? [];

                // No run recorded for this automation on this PT date.
                if ($logsForDate === []) {
                    foreach ($this->scopeCompanies($companies, $filter) as $co) {
                        if ($isToday) {
                            // The scheduled run may simply not have happened yet — not an anomaly.
                            $rows[] = $this->missingRow($date, $co, 'pending');
                        } else {
                            $rows[] = $this->missingRow($date, $co, 'MISSING');
                            $anomalies[] = $this->anomaly('FAIL', $date, $co, 'No automation run recorded (runs 7 days/week; a night was skipped).');
                        }
                    }

                    continue;
                }

                foreach ($logsForDate as $log) {
                    $status = (string) $log->status;

                    // One HEALTH line per company in the run's output, keyed by its [CO] tag.
                    $healthByCo = [];
                    foreach ($this->parseHealthLines((string) $log->output) as $h) {
                        $healthByCo[$h['company']] = $h['counts'];
                    }

                    // Attribute rows to the companies that actually emitted HEALTH; if none did
                    // (old build / crash), fall back to the automation's configured companies.
                    $targetCompanies = $healthByCo !== [] ? array_keys($healthByCo) : $companies;

                    foreach ($this->scopeCompanies($targetCompanies, $filter) as $co) {
                        $counts = $healthByCo[$co] ?? null;
                        $anoms  = $this->rowAnomalies($date, $co, $status, $counts, $isWeekend, $expectsCancels, $log);
                        $rows[] = $this->buildRow($date, $co, $log, $counts, $this->worstLevel($anoms, $status));
                        foreach ($anoms as $a) {
                            $anomalies[] = $a;
                        }
                    }
                }
            }
        }

        $this->render($days, $sincePt, $todayPt, $rows, $anomalies);

        $hasFailCrit = false;
        foreach ($anomalies as $a) {
            if ($a['level'] === 'FAIL' || $a['level'] === 'CRITICAL') {
                $hasFailCrit = true;
                break;
            }
        }

        return $hasFailCrit ? self::FAILURE : self::SUCCESS;
    }

    /** Intersect a company list with the --company filter (empty filter = keep all). */
    private function scopeCompanies(array $companies, array $filter): array
    {
        return $filter === [] ? $companies : array_values(array_intersect($companies, $filter));
    }

    /**
     * Parse every `[CO] HEALTH key=val …` line from a run's captured output. Order-independent:
     * grabs the [CO] tag then every key=integer pair (the literal word "alerts" has no `=`, so it
     * is skipped). Returns a list of ['company' => 'LDR', 'counts' => ['resolved' => 12, …]].
     *
     * @return list<array{company: string, counts: array<string, int>}>
     */
    private function parseHealthLines(string $output): array
    {
        if ($output === '' || stripos($output, 'HEALTH') === false) {
            return [];
        }

        // [INFO] precedes [LDR]; the engine skips [INFO] because it isn't followed by "HEALTH".
        if (! preg_match_all('/\[([A-Za-z]+)\]\s+HEALTH\s+(.+)/', $output, $lines, PREG_SET_ORDER)) {
            return [];
        }

        $out = [];
        foreach ($lines as $line) {
            preg_match_all('/(\w+)=(\d+)/', $line[2], $pairs, PREG_SET_ORDER);
            $counts = [];
            foreach ($pairs as $p) {
                $counts[$p[1]] = (int) $p[2];
            }
            $out[] = ['company' => strtoupper($line[1]), 'counts' => $counts];
        }

        return $out;
    }

    /**
     * Anomalies for one (date, company, log) row. Weekday-aware.
     *
     * @param array<string, int>|null $counts
     * @return list<array{level: string, date: string, company: string, message: string}>
     */
    private function rowAnomalies(string $date, string $co, string $status, ?array $counts, bool $isWeekend, bool $expectsCancels, AutomationLog $log): array
    {
        // Still running (or an unknown state) — don't judge an in-flight run.
        if ($status === 'running') {
            return [];
        }

        $out = [];

        if ($status === 'failed') {
            $err = $log->error !== null ? ': ' . $this->firstLine((string) $log->error) : '.';
            $out[] = $this->anomaly('FAIL', $date, $co, 'Run status=failed' . $err);
        }

        if ($counts === null) {
            // A success with no HEALTH line = old build, or crashed before the per-company summary.
            if ($status === 'success') {
                $out[] = $this->anomaly('WARN', $date, $co, 'Run succeeded but emitted no HEALTH line (old build, or crashed before the per-company summary).');
            }

            return $out;
        }

        $partialVoid    = $counts['partial_void'] ?? 0;
        $sessionFailure = $counts['session_failure'] ?? 0;
        $companyFailed  = $counts['company_failed'] ?? 0;
        $dropNotLand    = $counts['drop_not_land'] ?? 0;
        $completed      = $counts['completed'] ?? 0;

        if ($companyFailed > 0) {
            $out[] = $this->anomaly('FAIL', $date, $co, 'company_failed=1 — the company loop threw before finishing (recap/cancels may be incomplete).');
        }
        if ($partialVoid > 0) {
            $out[] = $this->anomaly('CRITICAL', $date, $co, "partial_void={$partialVoid} — IRREVERSIBLE partial settlement void; client not dropped/refunded. Manual reconcile required.");
        }
        if ($sessionFailure > 0) {
            $out[] = $this->anomaly('CRITICAL', $date, $co, "session_failure={$sessionFailure} — DPP session died mid-batch; remaining cancels were aborted (clients pushed to Queue).");
        }
        if ($dropNotLand > 0) {
            $out[] = $this->anomaly('WARN', $date, $co, "drop_not_land={$dropNotLand} — drop didn't take effect (Returned-Payments Hold / refund rejection); routed to the Release-Hold sheet.");
        }
        if ($expectsCancels && ! $isWeekend && $completed === 0) {
            $out[] = $this->anomaly('WARN', $date, $co, 'completed=0 on a weekday --execute-cancels run — expected ≥1 cancel; verify the run actually cancelled.');
        }

        return $out;
    }

    /** @return array{level: string, date: string, company: string, message: string} */
    private function anomaly(string $level, string $date, string $company, string $message): array
    {
        return ['level' => $level, 'date' => $date, 'company' => $company, 'message' => $message];
    }

    /** Worst anomaly level for a row → its Flag cell label. */
    private function worstLevel(array $anoms, string $status): string
    {
        if ($status === 'running') {
            return 'running';
        }

        $best  = 0;
        $label = 'ok';
        foreach ($anoms as $a) {
            $rank = self::RANK[$a['level']] ?? 0;
            if ($rank > $best) {
                $best  = $rank;
                $label = $a['level'];
            }
        }

        return $label;
    }

    /**
     * @param array<string, int>|null $counts
     * @return array<string, string>
     */
    private function buildRow(string $date, string $co, AutomationLog $log, ?array $counts, string $level): array
    {
        $get = static fn(string $k): string => $counts === null ? '—' : (string) ($counts[$k] ?? 0);

        return [
            'date'    => $date,
            'co'      => $co,
            'status'  => (string) $log->status,
            'start'   => $log->started_at?->copy()->setTimezone(self::TZ)->format('H:i') ?? '—',
            'runtime' => $this->formatRuntime($log->runtime_ms),
            'rslv'    => $get('resolved'),
            'nsf'     => $get('nsf'),
            'grace'   => $get('grace'),
            'man'     => $get('manual'),
            'queue'   => $get('queued'),
            'compl'   => $get('completed'),
            'voids'   => $get('voids_verified'),
            'alerts'  => $this->alertsCell($counts),
            'flag'    => $this->flagLabel($level),
        ];
    }

    /** A blank stat row for a date with no run (Flag carries 'MISSING' or 'pending'). */
    private function missingRow(string $date, string $co, string $flag): array
    {
        return [
            'date'    => $date,
            'co'      => $co,
            'status'  => '—',
            'start'   => '—',
            'runtime' => '—',
            'rslv'    => '—',
            'nsf'     => '—',
            'grace'   => '—',
            'man'     => '—',
            'queue'   => '—',
            'compl'   => '—',
            'voids'   => '—',
            'alerts'  => '—',
            'flag'    => $flag,
        ];
    }

    /** Compact cell of only the NON-zero alert counters, e.g. "sf1 dnl2"; "—" when clean/unknown. */
    private function alertsCell(?array $counts): string
    {
        if ($counts === null) {
            return '—';
        }

        $abbrev = ['partial_void' => 'pv', 'session_failure' => 'sf', 'company_failed' => 'cf', 'drop_not_land' => 'dnl'];
        $parts  = [];
        foreach ($abbrev as $key => $short) {
            $n = $counts[$key] ?? 0;
            if ($n > 0) {
                $parts[] = $short . $n;
            }
        }

        return $parts === [] ? '—' : implode(' ', $parts);
    }

    private function flagLabel(string $level): string
    {
        return match ($level) {
            'CRITICAL' => 'CRIT',
            'FAIL'     => 'FAIL',
            'WARN'     => 'WARN',
            'running'  => 'run…',
            default    => 'ok',
        };
    }

    private function formatRuntime(?int $ms): string
    {
        if ($ms === null || $ms <= 0) {
            return '—';
        }

        $s = (int) round($ms / 1000);
        if ($s < 60) {
            return $s . 's';
        }

        $m   = intdiv($s, 60);
        $rem = $s % 60;
        if ($m < 60) {
            return $m . 'm' . ($rem > 0 ? $rem . 's' : '');
        }

        $h  = intdiv($m, 60);
        $mm = $m % 60;

        return $h . 'h' . ($mm > 0 ? $mm . 'm' : '');
    }

    private function firstLine(string $text): string
    {
        $line = trim(strtok($text, "\n") ?: $text);

        return mb_strlen($line) > 160 ? mb_substr($line, 0, 157) . '…' : $line;
    }

    /**
     * @param list<array<string, string>> $rows
     * @param list<array{level: string, date: string, company: string, message: string}> $anomalies
     */
    private function render(int $days, Carbon $sincePt, Carbon $todayPt, array $rows, array $anomalies): void
    {
        $this->info(sprintf(
            'resume-payments health — last %d day(s) [%s → %s] %s',
            $days,
            $sincePt->toDateString(),
            $todayPt->toDateString(),
            self::TZ,
        ));
        $this->newLine();

        if ($rows === []) {
            $this->warn('No runs in the window.');
        } else {
            $headers = ['Date (PT)', 'Co', 'Status', 'Start', 'Runtime', 'Rslv', 'NSF', 'Grace', 'Man', 'Queue', 'Compl', 'Voids', 'Alerts', 'Flag'];
            $this->table($headers, array_map(static fn(array $r): array => array_values($r), $rows));
        }

        $this->newLine();

        if ($anomalies === []) {
            $this->info('✓ No anomalies. (Weekend runs legitimately do 0 cancels/voids.)');

            return;
        }

        // Worst first, then by date.
        usort($anomalies, static function (array $a, array $b): int {
            $ra = self::RANK[$a['level']] ?? 0;
            $rb = self::RANK[$b['level']] ?? 0;

            return $rb <=> $ra ?: strcmp($a['date'], $b['date']);
        });

        $this->error(sprintf('Anomalies (%d):', count($anomalies)));
        foreach ($anomalies as $a) {
            $line = sprintf('  [%s] %s %s — %s', $a['level'], $a['date'], $a['company'], $a['message']);
            if ($a['level'] === 'WARN') {
                $this->warn($line);
            } else {
                $this->error($line);
            }
        }
    }
}
