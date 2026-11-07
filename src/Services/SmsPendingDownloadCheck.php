<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

use Cmd\Reports\Jobs\SmsPendingDownloadCheckJob;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * The load balancer in front of CMD runner drops a request after 60 seconds without a byte, and
 * the eligibility check on a big pending file takes about 90. So a big file's check runs on the
 * worker, the page waits on the link route, and the download starts right after the check passes.
 * Same local file cache as SmsRunProgress, because worker and web share the one server.
 */
class SmsPendingDownloadCheck
{
    /** How long a passed check can stand in for the one in the download request. */
    public const PASS_SECONDS = 120;

    private const OUTCOME_SECONDS = 300;

    /** Longer than any check, so a worker that dies during one clears itself. */
    private const RUNNING_SECONDS = 900;

    public function runsInBackground(object $run): bool
    {
        return (int) ($run->Selected ?? 0) > (int) config('sms-exports.pending_check_background_rows', 500000);
    }

    /** True once the file can go, false while the check runs. A held or failed check throws once. */
    public function ready(string $requestId): bool
    {
        $key = $this->key($requestId);
        $saved = Cache::store('file')->get($key);
        $state = is_array($saved) ? ($saved['state'] ?? null) : null;
        if ($state === 'passed') return true;
        if ($state === 'held' || $state === 'failed') {
            Cache::store('file')->forget($key);
            throw new HttpException(409, (string) ($saved['message'] ?? 'The final check could not finish. Try again.'));
        }
        if ($state === null && Cache::store('file')->add($key, ['state' => 'running'], self::RUNNING_SECONDS)) {
            try {
                $this->start(strtolower($requestId));
            } catch (Throwable $error) {
                Cache::store('file')->forget($key);
                throw $error;
            }
        }

        return false;
    }

    public function passed(string $requestId): bool
    {
        try {
            $saved = Cache::store('file')->get($this->key($requestId));
        } catch (Throwable) {
            // No cache means no proof of a pass, so the download runs the check itself.
            return false;
        }

        return is_array($saved) && ($saved['state'] ?? null) === 'passed';
    }

    public function recordPass(string $requestId): void
    {
        Cache::store('file')->put($this->key($requestId), ['state' => 'passed'], self::PASS_SECONDS);
    }

    public function recordHeld(string $requestId, string $message): void
    {
        Cache::store('file')->put($this->key($requestId), ['state' => 'held', 'message' => $message], self::OUTCOME_SECONDS);
    }

    public function recordFailure(string $requestId): void
    {
        Cache::store('file')->put($this->key($requestId), ['state' => 'failed'], self::OUTCOME_SECONDS);
    }

    protected function start(string $requestId): void
    {
        SmsPendingDownloadCheckJob::dispatch($requestId);
    }

    private function key(string $requestId): string
    {
        return 'sms-pending-download-check:'.strtolower($requestId);
    }
}
