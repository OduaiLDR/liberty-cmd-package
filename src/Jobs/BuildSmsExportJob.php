<?php

namespace Cmd\Reports\Jobs;

use Cmd\Reports\Repositories\MailDropExportRepository;
use Cmd\Reports\Services\SmsExportArtifacts;
use Cmd\Reports\Services\SmsExportQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/** One attempt only: after an ambiguous commit, a blind retry could SMS the same drop again. */
class BuildSmsExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = SmsExportQueue::JOB_TIMEOUT;

    public function __construct(public string $requestId)
    {
        $this->onQueue('tu-export');
    }

    public function handle(MailDropExportRepository $drops, SmsExportArtifacts $artifacts, SmsExportQueue $queue): void
    {
        $queue->assertReady();
        $table = DB::connection('sqlsrv')->table('TblSmsExportRequests');
        $request = $table->where('Request_ID', $this->requestId)->first();
        if ($request === null || $request->Status === 'ready') return;
        if ($request->Status !== 'queued') return;

        if ($table->where('Request_ID', $this->requestId)->where('Status', 'queued')
            ->update(['Status' => 'running', 'Updated_At' => now()->toDateTimeString()]) !== 1) return;
        $path = null;
        try {
            $artifacts->assertConfigured();
            if (DB::connection('sqlsrv')->table('TblSmsExports')->where('Request_ID', $this->requestId)->exists()) {
                throw new RuntimeException('This request already has SMS tracking. Its artifact must be reconciled before retry.');
            }
            $ids = $request->Drop_PKs === null ? null : json_decode($request->Drop_PKs, true, 512, JSON_THROW_ON_ERROR);
            $drops->prepareExport((int) $request->Target, $this->requestId, $ids,
                function (array $export) use ($artifacts, &$path): void {
                    $path = $export['path'];
                    $published = $artifacts->publish($this->requestId, $export);
                    DB::connection('sqlsrv')->table('TblSmsExportRequests')
                        ->where('Request_ID', $this->requestId)->update([
                            'Status' => 'ready', 'Artifact_Key' => $published['key'],
                            'Artifact_Format' => ($export['format'] ?? 'csv') === 'zip' ? 'zip' : 'csv',
                            'Artifact_Bytes' => $published['bytes'], 'SMS_Count' => $export['count'],
                            'Part_Count' => $export['part_count'] ?? 1,
                            'Error' => null, 'Updated_At' => now()->toDateTimeString(),
                        ]);
                });
        } catch (Throwable $error) {
            // The repository rolls back tracking if generation, upload, or verification fails.
            // If the SQL commit succeeded, never overwrite its ready state with a failure.
            $table->where('Request_ID', $this->requestId)->where('Status', 'running')
                ->update(['Status' => 'failed', 'Error' => mb_substr($error->getMessage(), 0, 500),
                    'Updated_At' => now()->toDateTimeString()]);
            Log::error('SMS export failed', ['request_id' => $this->requestId, 'error' => $error->getMessage()]);
            throw $error;
        } finally {
            if ($path !== null && is_file($path)) unlink($path);
        }
    }

    public function failed(?Throwable $error): void
    {
        DB::connection('sqlsrv')->table('TblSmsExportRequests')
            ->where('Request_ID', $this->requestId)->whereIn('Status', ['queued', 'running'])
            ->update(['Status' => 'failed', 'Error' => mb_substr($error?->getMessage() ?? 'The SMS export worker stopped unexpectedly.', 0, 500),
                'Updated_At' => now()->toDateTimeString()]);
    }
}
