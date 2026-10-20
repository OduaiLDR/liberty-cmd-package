<?php

namespace Cmd\Reports\Jobs;

use Cmd\Reports\Repositories\SmsProspectExportRepository;
use Cmd\Reports\Services\SmsAvailabilityService;
use Cmd\Reports\Services\SmsExportQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class CountSmsAvailabilityJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = SmsExportQueue::JOB_TIMEOUT;
    public bool $failOnTimeout = true;

    public function __construct(public string $scope, public string $generation, public string $owner)
    {
        $this->onConnection('database');
        $this->onQueue((string) config('sms-exports.queue', 'tu-export'));
    }

    public function handle(SmsAvailabilityService $availability, SmsProspectExportRepository $export): void
    {
        try {
            $availability->count($this->scope, $this->generation, $this->owner, $export);
        } catch (Throwable $error) {
            $availability->fail($this->scope, $this->generation, $this->owner);
            throw $error;
        }
    }

    public function failed(?Throwable $error): void
    {
        app(SmsAvailabilityService::class)->fail($this->scope, $this->generation, $this->owner);
    }
}
