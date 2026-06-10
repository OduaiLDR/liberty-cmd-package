<?php

declare(strict_types=1);

namespace Illuminate\Foundation\Bus {
    if (!trait_exists(Dispatchable::class)) {
        trait Dispatchable
        {
        }
    }
}

namespace Illuminate\Bus {
    if (!trait_exists(Queueable::class)) {
        trait Queueable
        {
        }
    }
}

namespace Illuminate\Contracts\Queue {
    if (!interface_exists(ShouldQueue::class)) {
        interface ShouldQueue
        {
        }
    }
}

namespace Illuminate\Queue {
    if (!trait_exists(InteractsWithQueue::class)) {
        trait InteractsWithQueue
        {
        }
    }

    if (!trait_exists(SerializesModels::class)) {
        trait SerializesModels
        {
        }
    }
}

namespace Cmd\Reports\Tests\Unit\Pmod\Jobs {
    use Cmd\Reports\Pmod\Jobs\ForwardPmodToCmdRunnerJob;
    use Cmd\Reports\Tests\TestCase;

    final class ForwardPmodToCmdRunnerJobTest extends TestCase
    {
        public function test_it_accepts_configurable_path_and_timeout(): void
        {
            $job = new ForwardPmodToCmdRunnerJob(
                payload: ['customer_id' => 'contact-123'],
                idempotencyKey: 'idem-123',
                baseUrl: 'https://cmd-runner.test',
                token: 'token-123',
                path: '/custom/pmod-webhook',
                timeout: 12,
            );

            self::assertSame('/custom/pmod-webhook', $job->path);
            self::assertSame(12, $job->timeout);
        }

        public function test_it_keeps_defaults_for_existing_callers(): void
        {
            $job = new ForwardPmodToCmdRunnerJob(
                payload: ['customer_id' => 'contact-123'],
                idempotencyKey: 'idem-123',
                baseUrl: 'https://cmd-runner.test',
                token: 'token-123',
            );

            self::assertSame('/api/payment-adjustments/webhook', $job->path);
            self::assertSame(30, $job->timeout);
        }
    }
}
