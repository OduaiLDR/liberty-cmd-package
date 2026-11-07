<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Fills stored debt and keeps it current. Every step can stop at any point and pick up again,
 * because a person already filled is skipped and a second write changes nothing.
 *
 * It gives way to everything else: it waits while an SMS run or TU import is working and during
 * the nightly jobs. It never touches a TU batch, a run or the paid path.
 */
final class SmsStoredDebtWorker
{
    /** Which people, in the same terms as the two priority indexes. */
    public const SCOPES = ['never' => 'Last_SMS_Date IS NULL', 'previous' => 'Last_SMS_Date IS NOT NULL'];

    private const ELIGIBLE = 'Contacted=0 AND Opt_Out=0 AND Invalid_Phone=0 AND Phone IS NOT NULL';

    private const MAX_FAILURES = 5;

    private readonly Closure $sleep;

    private readonly Closure $now;

    private readonly Closure $report;

    public function __construct(private readonly ConnectionInterface $db, private readonly string $statePath,
        ?callable $report = null, ?callable $sleep = null, ?callable $now = null)
    {
        $this->report = $report ? Closure::fromCallable($report) : static function (string $line): void {};
        $this->sleep = $sleep ? Closure::fromCallable($sleep) : static function (int $seconds): void { sleep($seconds); };
        $this->now = $now ? Closure::fromCallable($now) : static fn (): int => time();
    }

    /** Why work should wait right now, or null when the database is free. */
    public function pauseReason(): ?string
    {
        $busy = $this->db->selectOne('SELECT TOP (1) Status FROM dbo.TblSMSRuns WHERE Status IN ('.implode(',', array_fill(0, count(SmsRunService::BUSY), '?')).')', SmsRunService::BUSY);
        if ($busy !== null) return 'an SMS run is working';
        $owner = $this->db->selectOne('SELECT TOP (1) Batch_ID FROM dbo.TblTUPipelineOwner WHERE Batch_ID IS NOT NULL');
        if ($owner !== null) return 'a TU import is working';
        if ($this->insideNightlyJobs()) return 'the nightly jobs are running';

        return null;
    }

    /**
     * Walks one scope in PK order, filling everyone with no value yet.
     *
     * @return array{scope:string, people:int, written:int, no_value:int, finished:bool, stopped:?string, cursor:int}
     */
    public function backfill(string $scope, int $batch = 5000, int $limit = 0, int $minutes = 0): array
    {
        $this->assertScope($scope);
        $this->assertBatch($batch);
        SmsStoredDebt::assertReady($this->db);
        $this->db->unprepared('SET LOCK_TIMEOUT 30000');
        $state = $this->state();
        // Anything TU adds from here on is picked up by the refresh, so nobody filled early goes stale.
        $state['tu_watermark'] ??= (int) ($this->db->selectOne('SELECT MAX(PK) AS n FROM dbo.TblTUData')->n ?? 0);
        $state['cursor'][$scope] ??= 0;
        $this->save($state);
        $deadline = $minutes > 0 ? ($this->now)() + $minutes * 60 : null;
        $totals = ['scope' => $scope, 'people' => 0, 'written' => 0, 'no_value' => 0, 'finished' => false, 'stopped' => null, 'cursor' => (int) $state['cursor'][$scope]];
        $refresh = new SmsStoredDebtRefresh($this->db);
        $failures = 0;
        while (true) {
            if ($deadline !== null && ($this->now)() >= $deadline) { $totals['stopped'] = 'time limit'; break; }
            if ($limit > 0 && $totals['people'] >= $limit) { $totals['stopped'] = 'limit'; break; }
            if ($this->waitIfBusy($deadline) === false) { $totals['stopped'] = 'time limit'; break; }
            $size = $limit > 0 ? min($batch, $limit - $totals['people']) : $batch;
            $pks = $this->nextPeople($scope, (int) $state['cursor'][$scope], $size);
            if ($pks === []) { $totals['finished'] = true; break; }
            $started = microtime(true);
            try {
                $result = $refresh->refresh($pks);
            } catch (QueryException $error) {
                if (++$failures >= self::MAX_FAILURES) throw new RuntimeException('Stored debt stopped after repeated database errors.', 0, $error);
                ($this->report)('Database error, retrying the same people: '.$error->getCode());
                ($this->sleep)(30);
                continue;
            }
            $failures = 0;
            $state['cursor'][$scope] = (int) $pks[count($pks) - 1];
            $this->save($state);
            $totals['people'] += $result['people'];
            $totals['written'] += $result['written'];
            $totals['no_value'] += $result['no_value'];
            $totals['cursor'] = (int) $state['cursor'][$scope];
            ($this->report)(sprintf('%s: %d people in %.1f s, %d total, cursor %d', $scope, $result['people'], microtime(true) - $started, $totals['people'], $totals['cursor']));
        }
        if ($totals['finished']) {
            $state['finished'][$scope] = true;
            $this->save($state);
        }

        return $totals;
    }

    /**
     * The people new TU rows belong to, then anyone new with no value yet. New mailers never start a refresh.
     *
     * @return array{tu_rows:int, tu_people:int, written:int, stopped:?string}
     */
    public function refresh(int $batch = 5000, int $minutes = 25): array
    {
        $this->assertBatch($batch);
        $state = $this->state();
        if (! isset($state['tu_watermark'])) throw new RuntimeException('Run the stored debt back fill before the refresh.');
        $result = ['tu_rows' => 0, 'tu_people' => 0, 'written' => 0, 'stopped' => null];
        $reason = $this->pauseReason();
        if ($reason !== null) return ['stopped' => $reason] + $result;
        SmsStoredDebt::assertReady($this->db);
        $this->db->unprepared('SET LOCK_TIMEOUT 30000');
        $deadline = $minutes > 0 ? ($this->now)() + $minutes * 60 : null;
        $from = (int) $state['tu_watermark'];
        $to = (int) ($this->db->selectOne('SELECT MAX(PK) AS n FROM dbo.TblTUData')->n ?? 0);
        if ($to > $from) {
            $people = $this->tuPeople($from, $to);
            $refresh = new SmsStoredDebtRefresh($this->db);
            foreach (array_chunk($people, $batch) as $page) {
                if ($this->waitIfBusy($deadline) === false) return ['stopped' => 'time limit'] + $result;
                $done = $refresh->refresh($page);
                $result['tu_people'] += $done['people'];
                $result['written'] += $done['written'];
            }
            // Only once every person is done, so a stop part way simply repeats this step.
            $state['tu_watermark'] = $to;
            $this->save($state);
            $result['tu_rows'] = $to - $from;
        }
        foreach (array_keys(self::SCOPES) as $scope) {
            if (($state['finished'][$scope] ?? false) !== true) continue;
            $fill = $this->backfill($scope, $batch, 0, $deadline === null ? 0 : max(1, (int) ceil(($deadline - ($this->now)()) / 60)));
            $result['written'] += $fill['written'];
            if ($fill['stopped'] !== null) return ['stopped' => $fill['stopped']] + $result;
        }

        return $result;
    }

    /** Once a night: walk everyone again, for a person who gained a phone after being passed over. */
    public function restartSweep(): void
    {
        $state = $this->state();
        foreach (array_keys(self::SCOPES) as $scope) {
            if (($state['finished'][$scope] ?? false) === true) $state['cursor'][$scope] = 0;
        }
        $this->save($state);
    }

    /**
     * The one comparison after the back fill: a random sample against the live rule. Writes nothing.
     *
     * @return array{people:int, same:int, never_filled:int, debt_differs:int, tier_differs:int, source_differs:int}
     */
    public function compare(string $scope, int $sample, int $batch = 5000): array
    {
        $this->assertScope($scope);
        $this->assertBatch($batch);
        if ($sample < 1) throw new InvalidArgumentException('Choose a sample size.');
        $filter = self::ELIGIBLE.' AND '.self::SCOPES[$scope];
        $population = (int) $this->db->selectOne("SELECT COUNT_BIG(*) AS n FROM dbo.TblProspects WHERE {$filter}")->n;
        $every = max(1, (int) floor($population / $sample));
        $pks = array_map('intval', array_column(array_map(fn ($row) => (array) $row, $this->db->select(
            "SELECT TOP (?) PK FROM dbo.TblProspects WHERE {$filter} AND PK%?=0 ORDER BY PK", [$sample, $every])), 'PK'));
        $totals = ['people' => 0, 'same' => 0, 'never_filled' => 0, 'debt_differs' => 0, 'tier_differs' => 0, 'source_differs' => 0];
        $refresh = new SmsStoredDebtRefresh($this->db);
        foreach (array_chunk($pks, $batch) as $page) {
            foreach ($refresh->compare($page) as $key => $value) $totals[$key] += $value;
        }

        return $totals;
    }

    /** A 1% read, scaled up. Cheap enough to run during the back fill. */
    public function status(): array
    {
        $rows = [];
        foreach (self::SCOPES as $scope => $filter) {
            $row = $this->db->selectOne('SELECT COUNT_BIG(*) AS people, COALESCE(SUM(CASE WHEN Current_Debt_Source IS NULL THEN 1 ELSE 0 END),0) AS blank,
                COALESCE(SUM(CASE WHEN Current_Debt_Source=? THEN 1 ELSE 0 END),0) AS no_value
                FROM dbo.TblProspects WHERE '.self::ELIGIBLE." AND {$filter} AND PK%100=7", [SmsStoredDebt::NO_VALUE]);
            $rows[$scope] = ['people' => (int) $row->people * 100, 'blank' => (int) $row->blank * 100, 'no_value' => (int) $row->no_value * 100];
        }

        return ['scopes' => $rows, 'state' => $this->state(), 'paused' => $this->pauseReason()];
    }

    /** @return list<int> */
    private function nextPeople(string $scope, int $after, int $size): array
    {
        $rows = $this->db->select('SELECT TOP (?) PK FROM dbo.TblProspects WITH (INDEX(1)) WHERE PK>? AND '.self::ELIGIBLE.' AND '.self::SCOPES[$scope]
            .' AND Current_Debt_Source IS NULL ORDER BY PK', [$size, $after]);

        return array_map(static fn ($row): int => (int) $row->PK, $rows);
    }

    /**
     * Same match as the debt rule's TU step: address, ZIP5, first name at the start of Client and last name at the end.
     *
     * @return list<int>
     */
    private function tuPeople(int $from, int $to): array
    {
        $rows = $this->db->select('SELECT DISTINCT p.PK FROM dbo.TblTUData t
JOIN dbo.TblProspects p ON p.Address=t.ncoaAddr_addrLine1Txt AND LEFT(p.Zip,5)=t.SMS_Zip5
WHERE t.PK>? AND t.PK<=? AND t.ncoaAddr_frstNme<>\'\' AND t.ncoaAddr_lastNme<>\'\'
  AND LEFT(p.Client,LEN(t.ncoaAddr_frstNme)+1)=t.ncoaAddr_frstNme+\' \'
  AND RIGHT(p.Client,LEN(t.ncoaAddr_lastNme)+1)=\' \'+t.ncoaAddr_lastNme
  AND p.Contacted=0 AND p.Opt_Out=0 AND p.Invalid_Phone=0 AND p.Phone IS NOT NULL
OPTION (HASH JOIN, MAXDOP 4)', [$from, $to]);

        return array_map(static fn ($row): int => (int) $row->PK, $rows);
    }

    private function waitIfBusy(?int $deadline): bool
    {
        while (($reason = $this->pauseReason()) !== null) {
            if ($deadline !== null && ($this->now)() >= $deadline) return false;
            ($this->report)("Waiting: {$reason}");
            ($this->sleep)(60);
        }

        return true;
    }

    private function insideNightlyJobs(): bool
    {
        $window = (string) config('sms-exports.stored_debt_pause_utc', '09:20-10:25');
        if (! preg_match('/^(\d{2}):(\d{2})-(\d{2}):(\d{2})$/', $window, $m)) return false;
        $now = (int) gmdate('H', ($this->now)()) * 60 + (int) gmdate('i', ($this->now)());

        return $now >= (int) $m[1] * 60 + (int) $m[2] && $now < (int) $m[3] * 60 + (int) $m[4];
    }

    private function assertScope(string $scope): void
    {
        if (! isset(self::SCOPES[$scope])) throw new InvalidArgumentException('Scope must be never or previous.');
    }

    private function assertBatch(int $batch): void
    {
        if ($batch < 1 || $batch > 50000) throw new InvalidArgumentException('Batch must be from 1 to 50,000.');
    }

    private function state(): array
    {
        if (! is_file($this->statePath)) return [];
        $state = json_decode((string) file_get_contents($this->statePath), true);
        if (! is_array($state)) throw new RuntimeException('The stored debt state file is unreadable. Stop and look at it.');

        return $state;
    }

    private function save(array $state): void
    {
        $directory = dirname($this->statePath);
        if (! is_dir($directory) && ! mkdir($directory, 0770, true) && ! is_dir($directory)) throw new RuntimeException('Cannot create the stored debt state folder.');
        $temporary = $this->statePath.'.tmp';
        try {
            if (file_put_contents($temporary, json_encode($state, JSON_THROW_ON_ERROR)) === false) throw new RuntimeException('Cannot write the stored debt state.');
            if (! rename($temporary, $this->statePath)) throw new RuntimeException('Cannot save the stored debt state.');
        } catch (Throwable $error) {
            @unlink($temporary);
            throw $error;
        }
    }
}
