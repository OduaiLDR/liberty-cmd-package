<?php

namespace Cmd\Reports\Console\Commands;

use Cmd\Reports\Services\SmsDurableAvailabilityService;
use Illuminate\Console\Command;

/** Schedule every minute: leases/retry dates coalesce refreshes and recover dead workers. */
class RefreshSmsAvailability extends Command
{
    protected $signature = 'sms:refresh-availability {--force : Request a new count even when the stored result is fresh}';
    protected $description = 'Queue a durable SMS summary refresh without counting in the command or HTTP request.';

    public function handle(SmsDurableAvailabilityService $service): int
    {
        if (! config('sms-exports.durable_availability_enabled', false)) {
            $this->line('Durable SMS summary refresh is disabled.');
            return self::SUCCESS;
        }
        $result = $service->request((bool) $this->option('force'));
        $this->line(json_encode(array_intersect_key($result, array_flip(['state', 'queued_now', 'counted_at', 'stale'])), JSON_THROW_ON_ERROR));
        return $result['state'] === 'failed' ? self::FAILURE : self::SUCCESS;
    }
}
