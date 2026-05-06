<?php

declare(strict_types=1);

namespace Cmd\Reports\Pmod\Http\Controllers;

use Cmd\Reports\Pmod\Http\Requests\PmodWebhookRequest;
use Cmd\Reports\Pmod\Jobs\ForwardPmodToCmdRunnerJob;
use Cmd\Reports\Pmod\Support\PmodActionMapper;
use Cmd\Reports\Pmod\Support\PmodIdempotency;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Throwable;

final class PmodPortalWebhookController extends Controller
{
    public function __invoke(PmodWebhookRequest $request): JsonResponse
    {
        if (!config('services.pmod.enabled', false)) {
            return response()->json([
                'message' => 'PMOD webhook is disabled.',
            ], 503);
        }

        $normalizedPayload = $request->normalizedPayload();
        $action = trim((string) ($normalizedPayload['action'] ?? ''));

        if (!$this->actionIsSupported($action)) {
            return response()->json([
                'message' => sprintf('PMOD action [%s] is not enabled in this environment.', $action),
            ], 422);
        }

        $tenantId = $this->tenantId();
        $idempotencyKey = PmodIdempotency::fromNormalizedPayload($normalizedPayload);
        $forwardPayload = [
            ...$normalizedPayload,
            'tenant_id' => $tenantId,
            'idempotency_key' => $idempotencyKey,
        ];

        ForwardPmodToCmdRunnerJob::dispatch(
            payload: $forwardPayload,
            idempotencyKey: $idempotencyKey,
            baseUrl: (string) config('services.cmd_runner.base_url', ''),
            token: (string) config('services.cmd_runner.internal_token', ''),
            path: (string) config('services.cmd_runner.pmod_path', '/api/payment-adjustments/webhook'),
            timeout: (int) config('services.cmd_runner.timeout', 30),
        );

        $this->logger()->info('PMOD webhook accepted and forwarding to CMD Runner', [
            'endpoint' => '/webhooks/pmod-approval',
            'tenant_id' => $tenantId,
            'customer_id' => $normalizedPayload['customer_id'] ?? null,
            'action' => $action,
            'idempotency_key' => $idempotencyKey,
            'status' => 'accepted',
        ]);

        return response()->json([
            'status' => 'accepted',
            'idempotency_key' => $idempotencyKey,
            'normalized_payload_preview' => $forwardPayload,
        ], 202);
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

    private function logger(): LoggerInterface
    {
        try {
            return Log::channel('pmod_webhook');
        } catch (InvalidArgumentException) {
            return Log::channel('single');
        }
    }
}
