<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Worker-written, page-read progress for a busy SMS run. Same local file cache as
 * SmsSelectionProgress, never SQL: selection must not update TblSMSRuns inside its
 * snapshot. Advisory only; every cache failure is swallowed and SQL stays authoritative.
 */
class SmsRunProgress
{
    public const SELECTION_PHASES = ['finding_candidates', 'selecting', 'finalizing_selection'];

    public const BUILD_PHASES = ['checking', 'writing', 'packaging', 'verifying', 'final_check'];

    public const APPROVAL_PHASES = ['approving'];

    public const TTL_SECONDS = 21600;

    public const THROTTLE_SECONDS = 1.0;

    private const SAFE_INTEGER = 9007199254740991;

    /** @var array<string, array{phase:string, at:float}> */
    private array $written = [];

    /** Phase changes always write; repeats of one phase write at most once per second. */
    public function write(string $id, array $snapshot): void
    {
        try {
            $id = strtolower($id);
            $value = self::shape(['updated_at' => Carbon::now('UTC')->toIso8601String()] + $snapshot);
            if ($value === null) return;
            $now = $this->clock();
            $last = $this->written[$id] ?? null;
            if ($last === null || $last['phase'] !== $value['phase']) SmsStepLog::mark($id, $value['phase']);
            if ($last !== null && $last['phase'] === $value['phase'] && $now - $last['at'] < self::THROTTLE_SECONDS) return;
            $this->written[$id] = ['phase' => $value['phase'], 'at' => $now];
            Cache::store('file')->put($this->key($id), ['request_id' => $id] + $value, self::TTL_SECONDS);
        } catch (Throwable) {
            // Progress is advisory; it never fails a selection or a file build.
        }
    }

    public function read(string $id): ?array
    {
        try {
            $id = strtolower($id);
            $stored = Cache::store('file')->get($this->key($id));
            if (! is_array($stored) || ($stored['request_id'] ?? null) !== $id) return null;

            return self::shape($stored);
        } catch (Throwable) {
            return null;
        }
    }

    public function forget(string $id): void
    {
        unset($this->written[strtolower($id)]);
        try { Cache::store('file')->forget($this->key($id)); } catch (Throwable) {}
    }

    /** Only the run's current busy step; stale, foreign or mismatched snapshots read as null. */
    public function forRun(object $run): ?array
    {
        $phases = match ($run->Status ?? null) {
            'queued', 'selecting' => self::SELECTION_PHASES,
            'building_file' => self::BUILD_PHASES,
            'approving' => self::APPROVAL_PHASES,
            default => [],
        };
        $snapshot = $phases === [] ? null : $this->read((string) ($run->Request_ID ?? ''));
        if ($snapshot === null || ! in_array($snapshot['phase'], $phases, true)) return null;
        try {
            // Written before the last status change: an earlier attempt's leftover.
            return Carbon::parse($snapshot['updated_at'])->lt(Carbon::parse($run->Updated_At)) ? null : $snapshot;
        } catch (Throwable) {
            return null;
        }
    }

    protected function clock(): float
    {
        return microtime(true);
    }

    /** Fixed public shape with non-negative JavaScript-safe integers. */
    private static function shape(array $value): ?array
    {
        if (! in_array($value['phase'] ?? null, [...self::SELECTION_PHASES, ...self::BUILD_PHASES, ...self::APPROVAL_PHASES], true)
            || ! is_int($value['done'] ?? null) || ! is_int($value['total'] ?? null) || ! is_string($value['updated_at'] ?? null)) return null;
        $count = static fn (?int $number): ?int => $number === null ? null : max(0, min(self::SAFE_INTEGER, $number));
        $shape = ['phase' => $value['phase'], 'done' => $count($value['done']), 'total' => $count($value['total'])];
        foreach (['scanned', 'parts_done', 'parts_total'] as $key) {
            $number = $value[$key] ?? null;
            if ($number !== null && ! is_int($number)) return null;
            $shape[$key] = $count($number);
        }

        return $shape + ['updated_at' => $value['updated_at']];
    }

    private function key(string $id): string
    {
        return 'cmd-sms-run-progress:'.strtolower($id);
    }
}
