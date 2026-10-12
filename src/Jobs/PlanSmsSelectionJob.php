<?php

namespace Cmd\Reports\Jobs;

use Cmd\Reports\Repositories\MailDropExportRepository;
use Cmd\Reports\Services\SmsExportQueue;
use Cmd\Reports\Services\SmsSelectionProgress;
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

    public function __construct(public string $requestId, public int $expectedCursor = 0, public ?string $workerToken = null)
    {
        $this->workerToken ??= bin2hex(random_bytes(16));
        $this->onQueue('tu-export');
    }

    public function handle(MailDropExportRepository $drops, SmsExportQueue $queue): void
    {
        // Serialized jobs queued before batching lack these promoted properties.
        $this->expectedCursor ??= 0;
        $this->workerToken ??= bin2hex(random_bytes(16));
        $queue->assertReady();
        $table = DB::connection('sqlsrv')->table('TblSmsSelectionRequests');
        $request = (clone $table)->where('Request_ID', $this->requestId)->first();
        if ($request === null || $request->Status !== 'queued') return;
        $started = microtime(true);
        $snapshots = app(SmsSelectionProgress::class);
        try {
            $previous = $request->Result === null ? [] : json_decode($request->Result, true, 512, JSON_THROW_ON_ERROR);
            $state = $previous['checkpoint'] ?? $drops->startSelectionCheckpoint((int) $request->Target,
                $request->Selection_Mode === 'manual' ? json_decode($request->Drop_PKs, true, 512, JSON_THROW_ON_ERROR) : null);
        } catch (Throwable $error) {
            $unchanged = (clone $table)->where('Request_ID', $this->requestId)->where('Status', 'queued');
            $request->Result === null ? $unchanged->whereNull('Result') : $unchanged->where('Result', $request->Result);
            $unchanged->update(['Status' => 'failed', 'Error' => mb_substr($error->getMessage(), 0, 500), 'Updated_At' => now()->toDateTimeString()]);
            $snapshots->forget($this->requestId);
            throw $error;
        }
        if (($state['version'] ?? null) !== 1 || (int) $state['cursor'] !== $this->expectedCursor) return;
        $payload = ['checkpoint' => $state, 'worker_token' => $this->workerToken,
            'progress' => $this->progress($state)];
        $ownedResult = json_encode($payload, JSON_THROW_ON_ERROR);
        $claim = (clone $table)->where('Request_ID', $this->requestId)->where('Status', 'queued');
        $request->Result === null ? $claim->whereNull('Result') : $claim->where('Result', $request->Result);
        if ($claim->update(['Status' => 'running', 'Result' => $ownedResult, 'Error' => null,
            'Updated_At' => now()->toDateTimeString()]) !== 1) return;
        $snapshots->write($this->requestId, $request->Actor_Email, $this->publicStatus($request, 'running', $payload['progress']));
        try {
            $state = $drops->advanceSelectionCheckpoint($state);
            $state['elapsed_seconds'] += microtime(true) - $started;
            // Save the finished count before final validation or dispatch, so
            // either operation can fail without losing completed work.
            $checkpointResult = json_encode(['checkpoint' => $state, 'worker_token' => $this->workerToken,
                'progress' => $this->progress($state)], JSON_THROW_ON_ERROR);
            if ((clone $table)->where('Request_ID', $this->requestId)->where('Status', 'running')
                ->where('Result', $ownedResult)->update(['Result' => $checkpointResult,
                    'Updated_At' => now()->toDateTimeString()]) !== 1) {
                throw new \RuntimeException('The finished drop count could not be saved.');
            }
            $ownedResult = $checkpointResult;
            $result = $state['complete'] ? $drops->selectionCheckpointResult($state)
                : ['checkpoint' => $state, 'worker_token' => $this->workerToken, 'progress' => $this->progress($state)];
            $status = $state['complete'] ? 'ready' : 'queued';
            $encoded = json_encode($result, JSON_THROW_ON_ERROR);
            if ((clone $table)->where('Request_ID', $this->requestId)->where('Status', 'running')
                ->where('Result', $ownedResult)->update(['Status' => $status, 'Result' => $encoded,
                    'Error' => null, 'Updated_At' => now()->toDateTimeString()]) !== 1) {
                throw new \RuntimeException('The completed selection batch could not be recorded.');
            }
            $ownedResult = $encoded;
            $snapshots->write($this->requestId, $request->Actor_Email, $state['complete']
                ? ['request_id' => $this->requestId, 'status' => 'ready'] + $result
                : $this->publicStatus($request, 'queued', $result['progress']));
            if (! $state['complete']) $this->dispatchNext((int) $state['cursor']);
        } catch (Throwable $error) {
            $changed = (clone $table)->where('Request_ID', $this->requestId)->whereIn('Status', ['running', 'queued'])
                ->where('Result', $ownedResult)->update([
                'Status' => 'failed', 'Error' => mb_substr($error->getMessage(), 0, 500),
                'Updated_At' => now()->toDateTimeString(),
            ]);
            if ($changed === 1) {
                $saved = json_decode($ownedResult, true, 512, JSON_THROW_ON_ERROR);
                $snapshots->write($this->requestId, $request->Actor_Email,
                    $this->publicStatus($request, 'failed', $saved['progress'] ?? $this->progress($state))
                    + ['can_resume' => isset($saved['checkpoint']), 'error' => mb_substr($error->getMessage(), 0, 500)]);
            }
            Log::error('SMS selection failed', ['request_id' => $this->requestId, 'error' => $error->getMessage()]);
            throw $error;
        }
    }

    public function failed(?Throwable $error): void
    {
        // Old serialized callbacks cannot establish ownership of a current batch.
        if (! isset($this->workerToken)) return;
        $this->expectedCursor ??= 0;
        $table = DB::connection('sqlsrv')->table('TblSmsSelectionRequests');
        $row = (clone $table)->where('Request_ID', $this->requestId)->first();
        if ($row === null || $row->Status !== 'running') return;
        $saved = $row->Result === null ? [] : json_decode($row->Result, true);
        // An older job must never fail a continuation already claimed by another worker.
        if (($saved['worker_token'] ?? null) !== $this->workerToken) return;
        $cursor = (int) ($saved['checkpoint']['cursor'] ?? -1);
        if (! in_array($cursor, [$this->expectedCursor, $this->expectedCursor + 1], true)) return;
        app(SmsSelectionProgress::class)->forget($this->requestId);
        (clone $table)
            ->where('Request_ID', $this->requestId)->where('Status', 'running')
            ->where('Result', $row->Result)
            ->update(['Status' => 'failed', 'Error' => mb_substr($error?->getMessage() ?? 'The selection worker stopped unexpectedly.', 0, 500),
                'Updated_At' => now()->toDateTimeString()]);
    }

    protected function dispatchNext(int $cursor): void
    {
        self::dispatch($this->requestId, $cursor);
    }

    protected function progress(array $state): array
    {
        return ['processed_drops' => (int) $state['cursor'], 'total_drops' => count($state['candidates']),
            'eligible_phones' => (int) $state['subtotal'], 'target' => (int) $state['target'],
            'phase' => $state['crossing'] === null ? 'counting' : 'refining',
            'current_drop' => $state['candidates'][$state['cursor']]['Drop_Name'] ?? null,
            'lookahead_checked' => (int) $state['looked_ahead'], 'lookahead_total' => 30,
            'heartbeat_at' => now()->utc()->toIso8601String(), 'elapsed_seconds' => (int) $state['elapsed_seconds']];
    }

    protected function publicStatus(object $request, string $status, array $progress): array
    {
        return ['request_id' => $this->requestId, 'status' => $status,
            'selection_mode' => $request->Selection_Mode, 'progress' => $progress];
    }
}
