<?php

namespace Cmd\Reports\Jobs;

use Cmd\Reports\Repositories\SmsProspectLoadRepository;
use Cmd\Reports\Repositories\SmsProspectExportRepository;
use Cmd\Reports\Services\SmsWorkflowLock;
use Cmd\Reports\Services\SmsWorkflowBusyException;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

class SmsProspectMaintenanceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 0;
    public int $maxExceptions = 1;
    public int $createdAt;
    public int $timeout = 7000;

    public function __construct(public string $requestId)
    {
        $this->createdAt = now()->getTimestamp();
        $this->onQueue((string) config('sms-exports.queue', 'tu-export'));
    }

    public function retryUntil(): DateTimeInterface
    {
        return (new DateTimeImmutable('@'.$this->createdAt))->modify('+3 hours');
    }

    public function handle(SmsProspectLoadRepository $load, SmsProspectExportRepository $export): void
    {
        $claimed = false;
        try {
            SmsWorkflowLock::run('prospect-maintenance', function () use ($load, $export, &$claimed): void {
                $db = DB::connection('sqlsrv');
                $jobs = $db->table('TblSMSMaintenanceJobs')->where('Request_ID', $this->requestId);
                $job = (clone $jobs)->first();
                if (! $job || $job->Status !== 'queued') return;
                $claimed = true;
                $drops = $job->Drop_List
                    ? json_decode($job->Drop_List, false, 512, JSON_THROW_ON_ERROR)
                    : array_values(array_filter($load->drops(), fn ($drop) => $drop->Send_Date && $drop->Send_Date >= $job->Since_Date));
                $jobs->update(['Status' => 'running', 'Drops_Total' => count($drops), 'Drop_List' => json_encode($drops, JSON_THROW_ON_ERROR), 'Updated_At' => now()->toDateTimeString()]);
                foreach (array_slice($drops, (int) $job->Drops_Done) as $drop) {
                    $jobs->update(['Current_Drop' => $drop->Drop_Name, 'Updated_At' => now()->toDateTimeString()]);
                    // The drop transaction and progress checkpoint commit together, so a worker
                    // crash can neither skip a drop nor double-count its additions on retry.
                    $db->transaction(function () use ($load, $drop, $jobs): void {
                        $result = $load->loadDrop($drop->Drop_Name, $drop->Send_Date);
                        $jobs->update(['Drops_Done' => DB::raw('Drops_Done + 1'), 'Added' => DB::raw('Added + '.(int) $result['added']),
                            'Filled' => DB::raw('Filled + '.(int) $result['filled']), 'Updated_At' => now()->toDateTimeString()]);
                    });
                }
                $export->markContacted();
                $jobs->update(['Status' => 'completed', 'Updated_At' => now()->toDateTimeString()]);
            });
        } catch (SmsWorkflowBusyException $error) {
            // A nested admission lock can be busy after we claimed the load. Keep its
            // committed checkpoint retryable, but never change another worker's state.
            if ($claimed) {
                DB::connection('sqlsrv')->table('TblSMSMaintenanceJobs')->where('Request_ID', $this->requestId)->where('Status', 'running')
                    ->update(['Status' => 'queued', 'Updated_At' => now()->toDateTimeString()]);
            }
            $this->release(5);
        } catch (Throwable $error) {
            $this->failState($error->getMessage());
            throw $error;
        }
    }

    public function failed(?Throwable $error): void
    {
        $this->failState($error?->getMessage() ?? 'The load worker stopped. Retry resumes the saved checkpoint.');
    }

    private function failState(string $message): void
    {
        DB::connection('sqlsrv')->table('TblSMSMaintenanceJobs')->where('Request_ID', $this->requestId)->whereIn('Status', ['queued', 'running'])
            ->update(['Status' => 'failed', 'Error' => mb_substr($message, 0, 500), 'Updated_At' => now()->toDateTimeString()]);
    }
}
