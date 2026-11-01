<?php

declare(strict_types=1);

namespace Cmd\Reports\Jobs;

use Cmd\Reports\Services\SmsExportQueue;
use Cmd\Reports\Services\SmsRunService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/** An accepted request stays durable until its SQL terminal receipt is confirmed. */
final class CancelSmsSelectionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 0;
    public int $timeout = SmsExportQueue::JOB_TIMEOUT;

    public function __construct(public string $requestId)
    {
        $this->onConnection('database');
        $this->onQueue((string) config('sms-exports.queue', 'tu-export'));
        // Its INSERT and the accepted control receipt must commit in the same MySQL transaction.
        $this->beforeCommit();
    }

    public function handle(SmsRunService $runs): void
    {
        try {
            $runs->completeSelectionCancellation($this->requestId);
        } catch (Throwable $error) {
            // Never acknowledge a failed cleanup or leak SQL/bindings into logs.
            try {
                Log::warning('SMS selection cancellation is awaiting recovery.', [
                    'request_id' => $this->requestId, 'exception_class' => get_class($error),
                ]);
            } catch (Throwable) { /* A logger outage must not acknowledge pending recovery. */ }
            $this->release(5);
        }
    }
}
