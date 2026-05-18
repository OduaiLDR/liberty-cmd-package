<?php

declare(strict_types=1);

namespace Cmd\Reports\Pmod\Jobs;

use Cmd\Reports\Pmod\Data\PmodWorkItem;
use Cmd\Reports\Pmod\Services\PmodDispatcher;
use Cmd\Reports\Pmod\Services\PmodEmailNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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

    public function handle(PmodDispatcher $dispatcher, PmodEmailNotificationService $emails): void
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
            $notified = $emails->sendResult($this->workItem, $result);
            $this->updateTrackedRequest($result->status, $result->message, $result->metadata, notified: $notified);

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
            $notified = $emails->sendException($this->workItem, $exception);
            $this->updateTrackedRequest('failed', $exception->getMessage(), [
                'failure_type' => 'api_failure',
                'exception' => $exception::class,
            ], notified: $notified);

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

    /**
     * @param array<string, mixed> $metadata
     */
    private function updateTrackedRequest(string $status, string $message, array $metadata, bool $notified): void
    {
        try {
            if (! DB::getSchemaBuilder()->hasTable('pmod_requests')) {
                return;
            }

            DB::table('pmod_requests')
                ->where('idempotency_key', $this->workItem->idempotencyKey)
                ->update([
                    'status' => in_array($status, ['updated', 'success'], true) ? 'processed' : ($status === 'captured_for_manual_review' ? 'captured' : 'failed'),
                    'result_type' => $status,
                    'failure_type' => $metadata['failure_type'] ?? $metadata['reason'] ?? null,
                    'notification_status' => $notified ? 'sent' : 'not_sent',
                    'notification_sent_at' => $notified ? now() : null,
                    'response_payload' => json_encode(['status' => $status, 'message' => $message, 'metadata' => $metadata]),
                    'error_message' => in_array($status, ['updated', 'success'], true) ? null : mb_substr($message, 0, 2000),
                    'processed_at' => now(),
                    'failed_at' => in_array($status, ['updated', 'success'], true) ? null : now(),
                    'updated_at' => now(),
                ]);
        } catch (Throwable $e) {
            Log::warning('Could not update tracked PMOD request from package job.', [
                'idempotency_key' => $this->workItem->idempotencyKey,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
