<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

use InvalidArgumentException;

/**
 * Pure ordering of already-scoped record memberships. The caller owns source
 * authenticity/coverage, current-phone checks, future-event checks and locking.
 * A hold never chooses an arbitrary campaign or falls back to an older one.
 */
final class SmsContactAttributionPlan
{
    /**
     * @param list<array{request_id:string, approved_at:?string, precision:?int}> $approved
     * @param list<array{request_id:string, approved_at:?string, precision:?int}> $pending
     * @return array{state:'ready', request_id:?string}|array{state:'hold', reason:string}
     */
    public static function choose(SmsContactEvent $event, array $approved, array $pending): array
    {
        if (! array_is_list($approved) || ! array_is_list($pending)) {
            return self::hold('invalid_approval_metadata');
        }

        // Validate every identity before ordering, including future entries and
        // duplicates across the two states. A partial valid subset is not proof.
        $seen = [];
        foreach ([...$approved, ...$pending] as $row) {
            if (! is_array($row) || ! array_key_exists('request_id', $row)
                || ! array_key_exists('approved_at', $row) || ! array_key_exists('precision', $row)
                || ! is_string($row['request_id'])
                || ! preg_match('/\A[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}\z/', $row['request_id'])
                || isset($seen[$row['request_id']])) {
                return self::hold('invalid_approval_metadata');
            }
            $seen[$row['request_id']] = true;
        }

        $approvedTimes = $pendingTimes = [];
        foreach (['approved' => $approved, 'pending' => $pending] as $kind => $rows) {
            foreach ($rows as $row) {
                $time = self::normalize($row);
                if (isset($time['reason'])) return $time;
                if ($kind === 'approved') $approvedTimes[] = $time;
                else $pendingTimes[] = $time;
            }
        }

        $contactSecond = substr($event->utcExact, 0, 19);
        foreach ($pendingTimes as $row) {
            // An old receipt identifies only a second-wide interval. Even its
            // same-second possibility must settle before first attribution.
            if ($row['precision'] === null ? strcmp($row['second'], $contactSecond) <= 0
                : strcmp($row['utc'], $event->utcExact) <= 0) {
                return self::hold('approval_in_progress');
            }
        }

        $candidates = [];
        foreach ($approvedTimes as $row) {
            $secondOrder = strcmp($row['second'], $contactSecond);
            if ($row['precision'] === null) {
                if ($secondOrder === 0) return self::hold('historical_approval_precision');
                if ($secondOrder < 0) $candidates[] = $row;
            } elseif (strcmp($row['utc'], $event->utcExact) <= 0) {
                $candidates[] = $row;
            }
        }
        if ($candidates === []) return ['state' => 'ready', 'request_id' => null];

        // Earlier seconds cannot affect the winner, even if their own internal
        // order is ambiguous. Within the latest second, old and exact clocks
        // cannot be compared as if the old zero fraction were a measured instant.
        $latestSecond = max(array_column($candidates, 'second'));
        $latest = array_values(array_filter($candidates, fn (array $row): bool => $row['second'] === $latestSecond));
        $historical = array_values(array_filter($latest, fn (array $row): bool => $row['precision'] === null));
        if ($historical !== []) {
            if (count($historical) !== count($latest)) return self::hold('ambiguous_approval_order');
            if (count($latest) !== 1) return self::hold('ambiguous_latest_approval');
            return ['state' => 'ready', 'request_id' => $latest[0]['request_id']];
        }

        $latestInstant = max(array_column($latest, 'utc'));
        $winners = array_values(array_filter($latest, fn (array $row): bool => $row['utc'] === $latestInstant));
        if (count($winners) !== 1) return self::hold('ambiguous_latest_approval');
        return ['state' => 'ready', 'request_id' => $winners[0]['request_id']];
    }

    /** @return array{request_id:string, utc:string, second:string, precision:?int}|array{state:'hold', reason:string} */
    private static function normalize(array $row): array
    {
        if (! is_string($row['approved_at'])
            || ! preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]{1,7})?\z/', $row['approved_at'])) {
            return self::hold('invalid_approval_metadata');
        }
        try {
            $utc = SmsContactEvent::canonicalUtc(str_replace(' ', 'T', $row['approved_at']).'Z');
        } catch (InvalidArgumentException) {
            return self::hold('invalid_approval_metadata');
        }
        if ($row['precision'] !== null && $row['precision'] !== 6) {
            return self::hold('unverified_approval_precision');
        }
        if (($row['precision'] === null && substr($utc, 20, 9) !== '000000000')
            // A measured microsecond cannot contain a nonzero 100-nanosecond digit.
            || ($row['precision'] === 6 && substr($utc, 26, 3) !== '000')) {
            return self::hold('unverified_approval_precision');
        }

        return ['request_id' => $row['request_id'], 'utc' => $utc,
            'second' => substr($utc, 0, 19), 'precision' => $row['precision']];
    }

    /** @return array{state:'hold', reason:string} */
    private static function hold(string $reason): array
    {
        return ['state' => 'hold', 'reason' => $reason];
    }
}
