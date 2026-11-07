<?php

namespace Cmd\Reports\Jobs;

use Cmd\Reports\Services\SmsRunService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Removes a deleted big run's member rows in small pages. The run is already closed, so a retry just carries on. */
class SmsPurgeDiscardedRunJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 3600;

    public function __construct(public string $requestId)
    {
        $this->onQueue((string) config('sms-exports.queue', 'tu-export'));
    }

    public function handle(SmsRunService $runs): void
    {
        $runs->purgeDiscardedMembers($this->requestId);
    }
}
