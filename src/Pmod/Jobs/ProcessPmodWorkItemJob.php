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

    /**
     * NEVER retry. PMOD work is irreversible: it creates debts and bank drafts.
     *
     * This was `3` with a 30/60/120 s back-off, and it stacked. Proven live on
     * 2026-08-31 (working reference §8.6): the same request run twice 30 seconds
     * apart produced TWO debts (426018780, 426018849) and TWO drafts, because
     * nothing in any handler detects that a previous attempt already did the
     * work. A job that creates the debt and then fails on a later step gets
     * retried and creates a second one.
     *
     * resume-payments runs `--tries=1` for exactly this reason. A transient
     * failure now surfaces as one `failed` row, which `pmod:alert-unprocessed`
     * reports, and a human decides whether to re-run it — the right trade when
     * the alternative is charging a client twice.
     */
    public int $tries = 1;

    /**
     * Was 120 s. Raised because a timeout mid-job now leaves partial state with
     * no retry to finish it, so being too tight costs more than it did. One
     * action can walk ~60 drafts a PUT at a time, and the creditor catalogue
     * fetch (10,077 rows) lands on the first request after its 24 h cache
     * expires. The `default` worker allows 600 s (cmd-runner-worker.conf), so the
     * worker was never the constraint — this property was.
     */
    public int $timeout = 300;

    public function __construct(public readonly PmodWorkItem $workItem) {}

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
            if ($this->alreadyExecuted()) {
                Log::warning('Skipped PMOD work item that has already been executed.', [
                    'queue_key'       => $this->workItem->queueKey(),
                    'idempotency_key' => $this->workItem->idempotencyKey,
                    'action_type'     => $this->workItem->actionType->value,
                    'contact_id'      => $this->workItem->contactId,
                ]);

                $this->markDuplicateIgnored();

                return;
            }

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
     * Has the dispatcher already run to completion for this idempotency key?
     *
     * `$uniqueFor` and the cache lock above both expire, so neither protects
     * against the same request arriving again hours or days later — a portal
     * re-send, a re-posted webhook, or a mailbox reader re-reading a message it
     * already handled. The tracking row is the only durable record, and
     * re-applying a PMOD means a second debt or a second draft on a real client.
     *
     * `result_type` is the column to test: it is written ONLY by
     * updateTrackedRequest() below, i.e. only once the dispatcher has returned.
     * `processed_at` looks tempting and is wrong — PmodTrackingWebhookController
     * stamps it the moment the webhook is ACCEPTED (202), before the job runs, so
     * a guard on it would skip every job.
     *
     * A previous `failed` counts as executed too. That is deliberate: a failure
     * may have been PARTIAL — debt created, draft not — and the safe response is
     * a human looking at it, not an automatic replay. `pmod:alert-unprocessed`
     * already reports failed rows. To re-run one deliberately, clear its
     * result_type, or use `pmod:test`, which calls the dispatcher directly and
     * never touches this table.
     */
    private function alreadyExecuted(): bool
    {
        try {
            $schema = DB::getSchemaBuilder();

            // No tracking table (the DebtPlete forwarder has none) means no guard
            // is possible; behave exactly as before rather than blocking work.
            if (! $schema->hasTable('pmod_requests') || ! $schema->hasColumn('pmod_requests', 'result_type')) {
                return false;
            }

            return DB::table('pmod_requests')
                ->where('idempotency_key', $this->workItem->idempotencyKey)
                ->whereNotNull('result_type')
                ->exists();
        } catch (Throwable $e) {
            // Fail CLOSED. If we cannot tell whether this already ran, skipping
            // leaves a row in `accepted` that pmod:alert-unprocessed reports and a
            // human can replay; running it risks charging a client twice.
            Log::error('PMOD execution guard could not be evaluated — skipping the work item.', [
                'idempotency_key' => $this->workItem->idempotencyKey,
                'action_type'     => $this->workItem->actionType->value,
                'contact_id'      => $this->workItem->contactId,
                'error'           => $e->getMessage(),
            ]);

            return true;
        }
    }

    /**
     * Put the tracking row back to the outcome the FIRST run reached.
     *
     * PmodTrackingWebhookController re-stamps the row `received` then `accepted`
     * on every delivery, so after a duplicate the row reads `accepted` while
     * carrying a `result_type` from the original run. `pmod:alert-unprocessed`
     * scans exactly that status and would email a "PMOD request not processed"
     * alert for work that was completed and then correctly ignored. Verified live
     * 2026-09-01: attempts=2, status=accepted, result_type=captured_for_manual_review.
     *
     * False alarms are not harmless here - the legacy check-in alarm was silenced
     * with a 2030 sentinel rather than fixed (§8.8), and that is how nine weeks of
     * outage went unnoticed.
     */
    private function markDuplicateIgnored(): void
    {
        try {
            $schema = DB::getSchemaBuilder();

            if (! $schema->hasTable('pmod_requests') || ! $schema->hasColumn('pmod_requests', 'result_type')) {
                return;
            }

            $row = DB::table('pmod_requests')
                ->where('idempotency_key', $this->workItem->idempotencyKey)
                ->first();

            $resultType = $row->result_type ?? null;

            if (! is_string($resultType) || $resultType === '') {
                return;
            }

            DB::table('pmod_requests')
                ->where('idempotency_key', $this->workItem->idempotencyKey)
                ->update([
                    'status' => $this->statusForResult($resultType),
                    'updated_at' => now(),
                ]);
        } catch (Throwable $e) {
            Log::warning('Could not restore the tracked status of an ignored duplicate PMOD request.', [
                'idempotency_key' => $this->workItem->idempotencyKey,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The `pmod_requests.status` that corresponds to a dispatcher result.
     *
     * `partial_update` counts as processed. It used to fall through to `failed`,
     * which stamped `failed_at` and wrote the *success* message ("updated 18 of 20
     * draft(s)") into `error_message`, so the dashboard reported every partial as
     * a failure and the error column contained no error (§7.6).
     *
     * A partial is still surfaced, just not as a lie: `result_type` records it
     * exactly, the per-draft errors are in `response_payload`, and
     * PmodEmailNotificationService already treats anything that is not
     * updated/success/captured as a Failure, so it goes out as
     * "HIGH PRIORITY: PMOD Failure" and a human is told.
     */
    private function statusForResult(string $resultType): string
    {
        return match (true) {
            in_array($resultType, ['updated', 'success', 'partial_update'], true) => 'processed',
            $resultType === 'captured_for_manual_review' => 'captured',
            default => 'failed',
        };
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
                    'status' => $this->statusForResult($status),
                    'result_type' => $status,
                    'failure_type' => $metadata['failure_type'] ?? $metadata['reason'] ?? null,
                    'notification_status' => $notified ? 'sent' : 'not_sent',
                    'notification_sent_at' => $notified ? now() : null,
                    'response_payload' => json_encode(['status' => $status, 'message' => $message, 'metadata' => $metadata]),
                    // Only a genuine failure carries an error. A capture and a
                    // partial both have a *descriptive* message, and writing it
                    // here put text like "updated 18 of 20 draft(s)" in the error
                    // column of the dashboard (§7.6).
                    'error_message' => $this->statusForResult($status) === 'failed' ? mb_substr($message, 0, 2000) : null,
                    'processed_at' => now(),
                    'failed_at' => $this->statusForResult($status) === 'failed' ? now() : null,
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
