<?php

declare(strict_types=1);

namespace Cmd\Reports\Jobs;

use Cmd\Reports\Services\SmsRunEmailWorkflow;
use Cmd\Reports\Services\SmsWorkflowBusyException;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Independent of business approval: a mail error must never fail an approved run. */
final class SmsRunEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 0;
    public int $maxExceptions = 1;
    public int $timeout = 180;
    public int $createdAt;

    public function __construct(public string $requestId, public string $payloadSha256)
    {
        $this->createdAt = now()->getTimestamp();
        $this->onQueue((string) config('sms-exports.queue', 'tu-export'));
    }

    public function retryUntil(): DateTimeInterface
    {
        return (new DateTimeImmutable('@'.$this->createdAt))->modify('+3 hours');
    }

    public function handle(SmsRunEmailWorkflow $emails): void
    {
        try {
            $emails->send($this->requestId, $this->payloadSha256);
        } catch (SmsWorkflowBusyException) {
            $this->release(5);
        }
        // Uncertain provider outcomes are held by the outbox, never retried by
        // resetting business approval or by placing recipients/URLs in a log.
    }
}
