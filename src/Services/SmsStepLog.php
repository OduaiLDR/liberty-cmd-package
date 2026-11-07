<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One log line per step of a run, with how long the step before it took. Nothing else times the
 * steps, and Query Store doesn't keep them. A log line only: no SQL, and a failure here never stops a run.
 */
final class SmsStepLog
{
    /** @var array<string, array{step:string, at:float}> */
    private static array $current = [];

    /** A new job starts its own clock, so a retry can't report the gap since the last attempt. */
    public static function start(string $run, string $step): void
    {
        unset(self::$current[strtolower($run)]);
        self::mark($run, $step);
    }

    public static function mark(string $run, string $step): void
    {
        try {
            $id = strtolower($run);
            $now = microtime(true);
            $previous = self::$current[$id] ?? null;
            if ($previous !== null && $previous['step'] === $step) return;
            self::$current[$id] = ['step' => $step, 'at' => $now];
            Log::info('SMS step', ['run' => $id, 'step' => $step, 'previous' => $previous['step'] ?? null,
                'previous_seconds' => $previous === null ? null : round($now - $previous['at'], 1)]);
        } catch (Throwable) {
        }
    }

    public static function finish(string $run): void
    {
        self::mark($run, 'finished');
        unset(self::$current[strtolower($run)]);
    }
}
