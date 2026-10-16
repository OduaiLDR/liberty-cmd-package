<?php

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

/** One long step of an SMS run: prepare (select, debts, drops), file, or send. One attempt only. */
class SmsRunStepJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = SmsExportQueue::JOB_TIMEOUT;

    public function __construct(public string $requestId, public string $step)
    {
        $this->onQueue('tu-export');
    }

    public function handle(SmsRunService $runs): void
    {
        try {
            match ($this->step) {
                'prepare' => $runs->prepare($this->requestId),
                'file' => $runs->buildFile($this->requestId),
                'send' => $runs->markSent($this->requestId),
            };
        } catch (Throwable $error) {
            Log::error('SMS run step failed', ['request_id' => $this->requestId, 'step' => $this->step, 'error' => $error->getMessage()]);
            $runs->fail($this->requestId, $this->step, $error->getMessage());
            throw $error;
        }
    }

    public function failed(?Throwable $error): void
    {
        app(SmsRunService::class)->fail($this->requestId, $this->step, $error?->getMessage() ?? 'The SMS step stopped unexpectedly.');
    }
}
