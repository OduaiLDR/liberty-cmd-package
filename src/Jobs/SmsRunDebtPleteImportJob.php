<?php

declare(strict_types=1);

namespace Cmd\Reports\Jobs;

use Cmd\Reports\Services\SmsDebtPleteImportPrivacy;
use Cmd\Reports\Services\SmsExportQueue;
use Cmd\Reports\Services\SmsRunDebtPleteImportWorkflow;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/** Only an approved run UUID is serialized; the durable outbox owns the destination and plan. */
class SmsRunDebtPleteImportJob implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;
    public int $timeout = 1200;
    public int $uniqueFor = 900;
    public bool $failOnTimeout = true;

    public function __construct(public readonly string $requestId)
    {
        if (preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/D', $requestId) !== 1) {
            throw new InvalidArgumentException('Use the existing lower-case approved SMS request UUID.');
        }
        (new SmsExportQueue)->assertReady();
        $this->onConnection('database');
        $queue = config('sms-exports.queue', 'tu-export');
        if (!is_string($queue) || !preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/D', $queue)) {
            throw new InvalidArgumentException('An explicit asynchronous SMS import queue is required.');
        }
        $this->onQueue($queue);
    }

    public function uniqueId(): string { return 'sms-debtplete-import:'.$this->requestId; }
    public function tags(): array { return ['sms-debtplete-import', 'run:'.$this->requestId]; }

    public function handle(): void
    {
        SmsDebtPleteImportPrivacy::run(function (): void {
            if (config('sms-exports.debtplete_import_enabled', false) !== true) return;
            try {
                $queue = config('queue.connections.'.$this->connection);
                if ($this->job === null || !is_array($queue) || ($queue['driver'] ?? null) !== 'database'
                    || !is_int($queue['retry_after'] ?? null) || $queue['retry_after'] <= $this->timeout
                    || $this->job->getConnectionName() !== $this->connection || $this->job->getQueue() !== $this->queue) {
                    throw new InvalidArgumentException('SMS import queue visibility changed.');
                }
                $result = $this->workflow()->step($this->requestId);
                if (($result['status'] ?? null) === 'pending' && ($result['error_code'] ?? null) === null
                    && ($result['remote_status'] ?? null) === 'queued'
                    && is_int($result['files_accepted'] ?? null) && is_int($result['files_total'] ?? null)
                    && $result['files_accepted'] > 0 && $result['files_accepted'] < $result['files_total']) {
                    static::dispatch($this->requestId)->afterCommit();
                }
                // Once all files are accepted, recovery observes progress later;
                // it never spins on an importing or uncertain remote result.
            } catch (Throwable) { $this->pending('pending_recovery'); }
        });
    }

    public function failed(?Throwable $exception): void
    {
        SmsDebtPleteImportPrivacy::run(fn () => $this->pending('worker_stopped'));
    }

    protected function workflow(): SmsRunDebtPleteImportWorkflow { return app(SmsRunDebtPleteImportWorkflow::class); }

    private function pending(string $code): void
    {
        try { Log::warning('SMS import needs its saved outbox reconciled.', ['request_id' => $this->requestId, 'code' => $code]); }
        catch (Throwable) {}
    }
}
