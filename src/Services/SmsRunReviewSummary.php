<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Tier split and lowest debt of a pending batch. The selection worker works it out once and
 * the page reads it from the local file cache, never SQL, so GET /runs/{id} stays fast at 3M.
 * Advisory only: a failure never stops a selection, and a missing value stays unknown.
 */
class SmsRunReviewSummary
{
    public const TTL_SECONDS = 1209600;

    public function record(string $requestId): void
    {
        try {
            $value = self::shape($this->tierRows($requestId));
            if ($value === null) return;
            Cache::store('file')->put($this->key($requestId),
                ['request_id' => strtolower($requestId), 'computed_at' => Carbon::now('UTC')->toIso8601String()] + $value, self::TTL_SECONDS);
        } catch (Throwable) {
            // The review still works without it; the page says the split isn't available.
        }
    }

    public function read(string $requestId): ?array
    {
        try {
            $stored = Cache::store('file')->get($this->key($requestId));
            if (! is_array($stored) || ($stored['request_id'] ?? null) !== strtolower($requestId)) return null;
            $value = self::shape($stored['tiers'] ?? []);
            if ($value === null) return null;

            return $value + ['computed_at' => $stored['computed_at'] ?? null];
        } catch (Throwable) {
            return null;
        }
    }

    public function forget(string $requestId): void
    {
        try {
            Cache::store('file')->forget($this->key($requestId));
        } catch (Throwable) {
        }
    }

    /** @return iterable<int, array{tier:?string, records:int, lowest:?int}> */
    protected function tierRows(string $requestId): iterable
    {
        return DB::connection('sqlsrv')->table('TblProspectsExport')->where('Request_ID', $requestId)->groupBy('Debt_Tier')
            ->selectRaw('Debt_Tier AS tier,COUNT(*) AS records,MIN(Debt_Amount) AS lowest')->get();
    }

    /** One row per tier, in tier order. Anything malformed means no summary, not a partial one. */
    private static function shape(iterable $rows): ?array
    {
        $tiers = [];
        $lowest = null;
        foreach ($rows as $row) {
            $row = (array) $row;
            $tier = $row['tier'] ?? null;
            $records = filter_var($row['records'] ?? null, FILTER_VALIDATE_INT);
            if ($records === false || $records < 1 || ($tier !== null && ! preg_match('/^T[1-9]$/D', (string) $tier))) return null;
            $amount = $row['lowest'] ?? null;
            if ($amount !== null) {
                if (! is_numeric($amount)) return null;
                $lowest = $lowest === null ? (float) $amount : min($lowest, (float) $amount);
            }
            $tiers[] = ['tier' => $tier === null ? null : (string) $tier, 'records' => $records, 'lowest' => $amount === null ? null : (float) $amount];
        }
        if ($tiers === []) return null;
        usort($tiers, static fn (array $a, array $b): int => ($a['tier'] ?? 'Z') <=> ($b['tier'] ?? 'Z'));

        return ['tiers' => $tiers, 'lowest_debt' => $lowest];
    }

    private function key(string $requestId): string
    {
        return 'sms-run-review:'.strtolower($requestId);
    }
}
