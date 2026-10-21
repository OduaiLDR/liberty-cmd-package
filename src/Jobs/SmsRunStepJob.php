<?php

namespace Cmd\Reports\Jobs;

use Cmd\Reports\Services\SmsExportQueue;
use Cmd\Reports\Services\SmsRunService;
use Cmd\Reports\Services\SmsWorkflowBusyException;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/** One long step; retry lock contention, but fail on the first real exception. */
class SmsRunStepJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 0;

    public int $maxExceptions = 1;

    public int $createdAt;

    public int $timeout = SmsExportQueue::JOB_TIMEOUT;

    public function __construct(public string $requestId, public string $step)
    {
        $this->createdAt = now()->getTimestamp();
        $this->onQueue((string) config('sms-exports.queue', 'tu-export'));
    }

    public function retryUntil(): DateTimeInterface
    {
        return (new DateTimeImmutable('@'.$this->createdAt))->modify('+3 hours');
    }

    public function handle(SmsRunService $runs): void
    {
        try {
            match ($this->step) {
                'prepare' => $runs->prepare($this->requestId),
                'file' => $runs->buildFile($this->requestId),
                'send' => $runs->markSent($this->requestId),
                'reverse' => $runs->reverseSent($this->requestId),
                'approve' => $runs->markApproved($this->requestId),
            };
        } catch (SmsWorkflowBusyException $error) {
            $this->release(5);
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
