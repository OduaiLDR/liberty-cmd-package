<?php

declare(strict_types=1);

namespace Cmd\Reports\Pmod\Jobs;

use Cmd\Reports\Pmod\Data\PmodWorkItem;
use Cmd\Reports\Pmod\Services\PmodDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ProcessPmodWorkItemJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Idempotency window: one hour. Duplicate dispatches within this window are no-ops. */
    public int $uniqueFor = 3600;

    /** Retry up to 3 times on transient failures (network blip, API rate limit). */
    public int $tries = 3;

    /** Hard cap: kill the job if it has not finished within 2 minutes. */
    public int $timeout = 120;

    /** Exponential back-off: 30 s → 60 s → 120 s between retries. */
    public array $backoff = [30, 60, 120];

    public function __construct(public readonly PmodWorkItem $workItem)
    {
    }

    public function uniqueId(): string
    {
        return $this->workItem->queueKey();
    }

    public function handle(PmodDispatcher $dispatcher): void
    {
        $lock = Cache::lock($this->workItem->queueKey(), 600);

        if (!$lock->get()) {
            Log::warning('Skipped overlapping PMOD job.', [
                'queue_key' => $this->workItem->queueKey(),
            ]);

            return;
        }

        try {
            $result = $dispatcher->dispatch($this->workItem);

            Log::info('Processed PMOD work item.', [
                'queue_key'       => $this->workItem->queueKey(),
                'idempotency_key' => $this->workItem->idempotencyKey,
                'action_type' => $this->workItem->actionType->value,
                'contact_id'  => $this->workItem->contactId,
                'status'      => $result->status,
                'message'     => $result->message,
                'metadata'    => $result->metadata,
            ]);
        } catch (Throwable $exception) {
            Log::error('PMOD work item failed.', [
                'queue_key'       => $this->workItem->queueKey(),
                'idempotency_key' => $this->workItem->idempotencyKey,
                'action_type' => $this->workItem->actionType->value,
                'contact_id'  => $this->workItem->contactId,
                'error'       => $exception->getMessage(),
                'attempt'     => $this->attempts(),
            ]);

            throw $exception;
        } finally {
            $lock->release();
        }
    }
}
