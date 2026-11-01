<?php

declare(strict_types=1);

namespace Cmd\Reports\Jobs;

use Cmd\Reports\Services\SmsExportQueue;
use Cmd\Reports\Services\SmsRunPublicationWorkflow;
use Cmd\Reports\Services\SmsWorkflowBusyException;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Explicitly queued publication only; never changes business approval or sends email. */
final class SmsRunPublicationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 0;
    public int $maxExceptions = 1;
    public int $timeout = SmsExportQueue::JOB_TIMEOUT;
    public int $createdAt;

    public function __construct(public string $requestId, public string $actor)
    {
        $this->createdAt = now()->getTimestamp();
        $this->onQueue((string) config('sms-exports.queue', 'tu-export'));
    }

    public function retryUntil(): DateTimeInterface
    {
        return (new DateTimeImmutable('@'.$this->createdAt))->modify('+3 hours');
    }

    public function handle(SmsRunPublicationWorkflow $publications): void
    {
        try {
            $publications->publish($this->requestId, $this->actor);
        } catch (SmsWorkflowBusyException) {
            $this->release(5);
        }
    }
}
