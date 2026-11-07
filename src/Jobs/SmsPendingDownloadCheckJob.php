<?php

namespace Cmd\Reports\Jobs;

use Cmd\Reports\Services\SmsPendingDownloadCheck;
use Cmd\Reports\Services\SmsRunService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/** Read-only eligibility check for a big pending file. Writes only its outcome to the file cache. */
class SmsPendingDownloadCheckJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public string $requestId)
    {
        $this->onQueue((string) config('sms-exports.queue', 'tu-export'));
    }

    public function handle(SmsRunService $runs): void
    {
        $runs->runPendingDownloadCheck($this->requestId);
    }

    public function failed(?Throwable $error): void
    {
        app(SmsPendingDownloadCheck::class)->recordFailure($this->requestId);
    }
}
