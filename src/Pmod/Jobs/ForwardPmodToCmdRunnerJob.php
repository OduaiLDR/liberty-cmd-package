<?php

declare(strict_types=1);

namespace Cmd\Reports\Pmod\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ForwardPmodToCmdRunnerJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly array $payload,
        public readonly string $idempotencyKey,
        public readonly string $baseUrl,
        public readonly string $token,
    ) {
    }

    public function handle(): void
    {
        $path = '/api/payment-adjustments/webhook';
        $timeout = 30;

        if (empty($this->baseUrl) || empty($this->token)) {
            Log::channel($this->logChannel())->error('CMD Runner not configured', [
                'idempotency_key' => $this->idempotencyKey,
                'missing' => empty($this->baseUrl) ? 'base_url' : 'token',
            ]);

            throw new \RuntimeException('CMD Runner is not configured. Check base_url and token.');
        }

        $url = rtrim($this->baseUrl, '/') . $path;

        $response = Http::withToken($this->token)
            ->timeout($timeout)
            ->retry(3, function (int $attempt, Throwable $exception) {
                $delay = $attempt === 1 ? 500 : 1000;

                Log::channel($this->logChannel())->warning('Retrying PMOD forward', [
                    'idempotency_key' => $this->idempotencyKey,
                    'attempt' => $attempt,
                    'delay_ms' => $delay,
                    'error' => $exception->getMessage(),
                ]);

                return $delay;
            }, function (Throwable $exception, $response) {
                if ($response?->status() === 429) {
                    return true;
                }

                if ($response?->serverError()) {
                    return true;
                }

                return $exception instanceof \Illuminate\Http\Client\ConnectionException;
            })
            ->post($url, $this->payload);

        if ($response->successful()) {
            Log::channel($this->logChannel())->info('PMOD forwarded to CMD Runner', [
                'idempotency_key' => $this->idempotencyKey,
                'status' => $response->status(),
                'response' => $response->json(),
            ]);

            return;
        }

        Log::channel($this->logChannel())->error('PMOD forward failed', [
            'idempotency_key' => $this->idempotencyKey,
            'status' => $response->status(),
            'response' => $response->body(),
        ]);

        throw new \RuntimeException(sprintf(
            'CMD Runner returned status %d: %s',
            $response->status(),
            $response->body(),
        ));
    }

    private function logChannel(): string
    {
        try {
            Log::channel('pmod_webhook');
            return 'pmod_webhook';
        } catch (\InvalidArgumentException) {
            return 'single';
        }
    }
}
