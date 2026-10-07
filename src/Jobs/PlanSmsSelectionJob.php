<?php

namespace Cmd\Reports\Jobs;

use Cmd\Reports\Repositories\MailDropExportRepository;
use Cmd\Reports\Services\SmsExportQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class PlanSmsSelectionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = SmsExportQueue::JOB_TIMEOUT;

    public function __construct(public string $requestId)
    {
        $this->onQueue('tu-export');
    }

    public function handle(MailDropExportRepository $drops, SmsExportQueue $queue): void
    {
        $queue->assertReady();
        $table = DB::connection('sqlsrv')->table('TblSmsSelectionRequests');
        $request = $table->where('Request_ID', $this->requestId)->first();
        if ($request === null || $request->Status !== 'queued') return;
        if ($table->where('Request_ID', $this->requestId)->where('Status', 'queued')->update([
            'Status' => 'running', 'Updated_At' => now()->toDateTimeString(),
        ]) !== 1) return;
        try {
            $manual = $request->Selection_Mode === 'manual';
            $dropsResult = $manual
                ? $drops->selectDropsByIds(json_decode($request->Drop_PKs, true, 512, JSON_THROW_ON_ERROR))
                : $drops->selectDrops((int) $request->Target);
            $total = (int) $dropsResult->sum('Amount_Dropped');
            if ($total > 10000000) {
                throw new \RuntimeException('Selected drops exceed the 10,000,000 phone export limit.');
            }
            $target = $manual ? $total : (int) $request->Target;
            $result = ['drops' => $dropsResult->values()->all(), 'target' => $target,
                'total' => $total, 'shortfall' => max(0, $target - $total),
                'selection_mode' => $request->Selection_Mode];
            $table->where('Request_ID', $this->requestId)->update([
                'Status' => 'ready', 'Result' => json_encode($result, JSON_THROW_ON_ERROR),
                'Updated_At' => now()->toDateTimeString(),
            ]);
        } catch (Throwable $error) {
            $table->where('Request_ID', $this->requestId)->where('Status', 'running')->update([
                'Status' => 'failed', 'Error' => mb_substr($error->getMessage(), 0, 500),
                'Updated_At' => now()->toDateTimeString(),
            ]);
            Log::error('SMS selection failed', ['request_id' => $this->requestId, 'error' => $error->getMessage()]);
            throw $error;
        }
    }

    public function failed(?Throwable $error): void
    {
        DB::connection('sqlsrv')->table('TblSmsSelectionRequests')
            ->where('Request_ID', $this->requestId)->whereIn('Status', ['queued', 'running'])
            ->update(['Status' => 'failed', 'Error' => mb_substr($error?->getMessage() ?? 'The selection worker stopped unexpectedly.', 0, 500),
                'Updated_At' => now()->toDateTimeString()]);
    }
}
