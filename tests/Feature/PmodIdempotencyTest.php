<?php

namespace Cmd\Reports\Tests\Feature;

use Cmd\Reports\Pmod\Support\PmodIdempotency;
use PHPUnit\Framework\TestCase;

class PmodIdempotencyTest extends TestCase
{
    public function testIdempotencyKeyIgnoresAssociativeKeyOrder(): void
    {
        $payloadA = [
            'customer_id' => '392014299',
            'action' => 'Reschedule Payment',
            'requested_by' => 'Admin(Avinash)',
            'amount' => '250.50',
            'original_dates' => ['2024-11-25'],
            'target_dates' => ['2024-12-03'],
            'settlement_ids' => ['5538063', '5539001'],
        ];

        $payloadB = [
            'settlement_ids' => ['5538063', '5539001'],
            'target_dates' => ['2024-12-03'],
            'original_dates' => ['2024-11-25'],
            'amount' => '250.50',
            'requested_by' => 'Admin(Avinash)',
            'action' => 'Reschedule Payment',
            'customer_id' => '392014299',
        ];

        $this->assertSame(
            PmodIdempotency::fromNormalizedPayload($payloadA),
            PmodIdempotency::fromNormalizedPayload($payloadB),
        );
    }
}
