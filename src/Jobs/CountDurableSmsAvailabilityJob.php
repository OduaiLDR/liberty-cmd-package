<?php

namespace Cmd\Reports\Jobs;

use Cmd\Reports\Repositories\SmsProspectExportRepository;
use Cmd\Reports\Services\SmsDurableAvailabilityService;
use Cmd\Reports\Services\SmsExportQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class CountDurableSmsAvailabilityJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = SmsExportQueue::JOB_TIMEOUT;
    public bool $failOnTimeout = true;

    public function __construct(public string $scope, public string $generation)
    {
        $this->onConnection('database');
        $this->onQueue((string) config('sms-exports.queue', 'tu-export'));
    }

    public function handle(SmsDurableAvailabilityService $service, SmsProspectExportRepository $export): void
    {
        if (! config('sms-exports.durable_availability_enabled', false)) return;
        $service->count($this->scope, $this->generation, $export);
    }

    public function failed(?Throwable $error): void
    {
        if (config('sms-exports.durable_availability_enabled', false)) {
            app(SmsDurableAvailabilityService::class)->fail($this->scope, $this->generation);
        }
    }
}
