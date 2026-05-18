<?php

declare(strict_types=1);

namespace Cmd\Reports\Pmod\Http\Controllers;

use Cmd\Reports\Pmod\Jobs\ProcessPmodWorkItemJob;
use Cmd\Reports\Pmod\Services\PmodDispatcher;
use Cmd\Reports\Pmod\Support\PmodWorkItemFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Receives PMOD webhooks from portals/proxies and dispatches queue jobs for execution.
 * This is the cmd-runner worker entry point — distinct from PmodPortalWebhookController
 * which is used by DebtPlete/portal to forward jobs to cmd-runner.
 */
final class PmodExecutionWebhookController extends Controller
{
    public function __invoke(Request $request, PmodDispatcher $dispatcher): JsonResponse
    {
        $payload = $request->all();

        $tenantId = trim((string) ($payload['tenant_id'] ?? ''));
        $idempotencyKey = trim((string) ($payload['idempotency_key'] ?? ''));

        if ($tenantId === '' || $idempotencyKey === '') {
            return response()->json([
                'message' => 'Missing required fields: tenant_id and idempotency_key.',
            ], 422);
        }

        $dryRun = filter_var($payload['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN);

        try {
            $workItem = PmodWorkItemFactory::fromWebhookPayload(
                normalizedPayload: $payload,
                rawPayload: $payload,
                tenantId: $tenantId,
                idempotencyKey: $idempotencyKey,
                source: 'pmod-execution-webhook',
                executionOptions: [],
                dryRun: $dryRun,
            );
        } catch (InvalidArgumentException $exception) {
            Log::error('PMOD work item creation failed', [
                'idempotency_key' => $idempotencyKey,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        if (!$dispatcher->supports($workItem->actionType)) {
            Log::warning('PMOD action not supported, capturing for manual review', [
                'idempotency_key' => $idempotencyKey,
                'action_type' => $workItem->actionType->value,
            ]);

            return response()->json([
                'message' => sprintf(
                    'PMOD action [%s] is not implemented yet. Captured for manual review.',
                    $workItem->actionType->value,
                ),
                'status' => 'captured',
            ], 202);
        }

        // Always returns 202. With async queues (production) the job runs in a worker.
        // With sync queue (dev/test) the job runs inline — we catch exceptions to
        // preserve the 202 contract regardless of queue driver.
        try {
            ProcessPmodWorkItemJob::dispatch($workItem);
        } catch (\Throwable $jobException) {
            Log::error('PMOD job execution failed (sync queue mode).', [
                'idempotency_key' => $idempotencyKey,
                'action_type' => $workItem->actionType->value,
                'contact_id' => $workItem->contactId,
                'error' => $jobException->getMessage(),
            ]);
        }

        Log::info('PMOD work item dispatched', [
            'idempotency_key' => $idempotencyKey,
            'action_type' => $workItem->actionType->value,
            'contact_id' => $workItem->contactId,
        ]);

        return response()->json([
            'status' => 'accepted',
            'idempotency_key' => $idempotencyKey,
            'action_type' => $workItem->actionType->value,
        ], 202);
    }
}