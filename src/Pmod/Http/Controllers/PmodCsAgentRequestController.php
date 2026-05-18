<?php

declare(strict_types=1);

namespace Cmd\Reports\Pmod\Http\Controllers;

use Cmd\Reports\Pmod\Exceptions\UnsupportedPmodActionException;
use Cmd\Reports\Pmod\Http\Requests\PmodWebhookRequest;
use Cmd\Reports\Pmod\Services\PmodDispatcher;
use Cmd\Reports\Pmod\Support\PmodActionMapper;
use Cmd\Reports\Pmod\Support\PmodIdempotency;
use Cmd\Reports\Pmod\Support\PmodWorkItemFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use InvalidArgumentException;
use Throwable;

final class PmodCsAgentRequestController extends Controller
{
    public function __invoke(PmodWebhookRequest $request, PmodDispatcher $dispatcher): JsonResponse
    {
        if (!config('services.pmod.enabled', false)) {
            return response()->json([
                'message' => 'PMOD processing is disabled.',
            ], 503);
        }

        $normalizedPayload = $request->normalizedPayload();
        $action = trim((string) ($normalizedPayload['action'] ?? ''));

        if (!$this->actionIsSupported($action)) {
            return response()->json([
                'message' => sprintf('PMOD action [%s] is not enabled in this environment.', $action),
            ], 422);
        }

        $idempotencyKey = PmodIdempotency::fromNormalizedPayload($normalizedPayload);
        $dryRun = !((bool) config('services.pmod.live_draft_updates', false));

        try {
            $workItem = PmodWorkItemFactory::fromWebhookPayload(
                normalizedPayload: [
                    ...$normalizedPayload,
                    'idempotency_key' => $idempotencyKey,
                ],
                rawPayload: $request->rawPayloadSnapshot(),
                tenantId: $this->tenantId(),
                idempotencyKey: $idempotencyKey,
                source: 'api:pmod-request',
                executionOptions: ['flow' => 'cs_agent'],
                dryRun: $dryRun,
            );

            $result = $dispatcher->dispatch($workItem);
        } catch (InvalidArgumentException|UnsupportedPmodActionException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'status' => $result->status,
            'message' => $result->message,
            'metadata' => $result->metadata,
            'idempotency_key' => $idempotencyKey,
            'dry_run' => $dryRun,
        ], $result->isFailed() ? 422 : 200);
    }

    private function actionIsSupported(string $action): bool
    {
        $supportedActions = array_values(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            (array) config('services.pmod.supported_actions', []),
        )));

        if ($supportedActions === []) {
            return true;
        }

        if (in_array($action, $supportedActions, true)) {
            return true;
        }

        try {
            $mappedAction = PmodActionMapper::fromLabel($action)->value;
        } catch (Throwable) {
            return false;
        }

        foreach ($supportedActions as $supportedAction) {
            try {
                if (PmodActionMapper::fromLabel($supportedAction)->value === $mappedAction) {
                    return true;
                }
            } catch (Throwable) {
                if ($supportedAction === $mappedAction) {
                    return true;
                }
            }
        }

        return false;
    }

    private function tenantId(): string
    {
        if (!function_exists('tenant')) {
            return '';
        }

        try {
            return (string) (tenant('id') ?? '');
        } catch (Throwable) {
            return '';
        }
    }
}
