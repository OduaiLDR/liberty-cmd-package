<?php

declare(strict_types=1);

namespace Cmd\Reports\Jobs;

use Cmd\Reports\Services\SmsDebtPleteImportPrivacy;
use Cmd\Reports\Services\SmsExportQueue;
use Cmd\Reports\Services\SmsRunAutomaticHandoffWorkflow;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/** Only the captured approval UUID is queued; recipients, credentials and URLs never are. */
class SmsRunAutomaticHandoffJob implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Dispatchable, InteractsWithQueue, Queueable;
    public int $tries = 1;
    public int $timeout = 7000;
    public int $uniqueFor = 900;
    public bool $failOnTimeout = true;
    public function __construct(public readonly string $requestId)
    {
        if (!preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/D', $requestId)) throw new InvalidArgumentException('Use the existing approved SMS UUID.');
        (new SmsExportQueue)->assertReady(); $this->onConnection('database');
        $queue = config('sms-exports.queue', 'tu-export');
        if (!is_string($queue) || !preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/D', $queue)) throw new InvalidArgumentException('Use the asynchronous SMS queue.');
        $this->onQueue($queue);
    }
    public function uniqueId(): string { return 'sms-automatic-handoff:'.$this->requestId; }
    public function tags(): array { return ['sms-automatic-handoff', 'run:'.$this->requestId]; }
    public function handle(): void
    {
        SmsDebtPleteImportPrivacy::run(function (): void {
            if (config('sms-exports.handoff_automatic', false) !== true || config('sms-exports.file_publication_enabled', false) !== true
                || config('sms-exports.email_handoff_enabled', false) !== true) return;
            try {
                $queue = config('queue.connections.'.$this->connection);
                if ($this->job === null || !is_array($queue) || ($queue['driver'] ?? null) !== 'database'
                    || !is_int($queue['retry_after'] ?? null) || $queue['retry_after'] <= $this->timeout
                    || $this->job->getConnectionName() !== $this->connection || $this->job->getQueue() !== $this->queue) throw new InvalidArgumentException;
                $status = $this->workflow()->step($this->requestId);
                if (($status['status'] ?? null) === 'pending' && ($status['error_code'] ?? null) === null && ($status['again'] ?? null) === true) {
                    static::dispatch($this->requestId)->afterCommit();
                }
            } catch (Throwable) { $this->pending('pending_recovery'); }
        });
    }
    public function failed(?Throwable $exception): void { SmsDebtPleteImportPrivacy::run(fn () => $this->pending('worker_stopped')); }
    protected function workflow(): SmsRunAutomaticHandoffWorkflow { return app(SmsRunAutomaticHandoffWorkflow::class); }
    private function pending(string $code): void
    {
        try { Log::warning('Automatic SMS handoff needs its saved receipts reconciled.', ['request_id' => $this->requestId, 'code' => $code]); } catch (Throwable) {}
    }
}
