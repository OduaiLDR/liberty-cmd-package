<?php

namespace Cmd\Reports\Tests\Feature;

use Cmd\Reports\Pmod\Enums\PmodActionType;
use Cmd\Reports\Pmod\Enums\PmodCompany;
use Cmd\Reports\Pmod\Support\PmodActionMapper;
use Cmd\Reports\Pmod\Support\PmodWorkItemFactory;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class PmodWorkItemFactoryTest extends TestCase
{
    public function testFactoryBuildsApprovalWorkItemFromNormalizedPayload(): void
    {
        $workItem = PmodWorkItemFactory::fromWebhookPayload(
            normalizedPayload: [
                'customer_id' => '392014299',
                'settlement_id' => '5538063',
                'settlement_ids' => ['5538063'],
                'action' => 'Increase Payments',
                'increase_amount' => '1100.00',
                'total_amount' => '1368.73',
                'start_date' => '2024-11-25',
                'end_date' => '2025-04-25',
                'requested_by' => 'Admin(Avinash)',
                'company' => 'PLAW',
            ],
            rawPayload: [
                'raw_text' => 'Action: Increase Payments',
            ],
            tenantId: 'tenant-123',
            idempotencyKey: 'idem-123',
        );

        $this->assertSame('tenant-123', $workItem->tenantId);
        $this->assertSame('392014299', $workItem->contactId);
        $this->assertSame('Admin(Avinash)', $workItem->requestedBy);
        $this->assertSame('idem-123', $workItem->idempotencyKey);
        $this->assertSame(PmodCompany::PLAW, $workItem->company);
        $this->assertSame(PmodActionType::PMOD_INCREASE_PAYMENTS, $workItem->actionType);
        $this->assertSame(['5538063'], $workItem->settlementIds);
        $this->assertSame('1100.00', $workItem->increaseAmount);
        $this->assertSame('1368.73', $workItem->totalAmount);
        $this->assertSame('2024-11-25', $workItem->paymentChange['start_date']);
        $this->assertSame('2025-04-25', $workItem->paymentChange['end_date']);
        $this->assertSame('range', $workItem->paymentChange['mode']);
    }

    public function testFactoryBuildsChangePaymentWorkItemWithArrayFields(): void
    {
        $workItem = PmodWorkItemFactory::fromWebhookPayload(
            normalizedPayload: [
                'customer_id' => '392014299',
                'action' => 'Reschedule Payment',
                'requested_by' => 'Admin(Avinash)',
                'company' => 'LDR',
                'settlement_ids' => ['5538063', '5539001'],
                'amount' => '250.50',
                'amounts' => ['250.50'],
                'original_date' => '2024-11-25',
                'original_dates' => ['2024-11-25'],
                'target_date' => '2024-12-03',
                'target_dates' => ['2024-12-03'],
                'void_settlements' => true,
            ],
            rawPayload: [
                'raw_text' => 'Action: Reschedule Payment',
            ],
            tenantId: 'tenant-123',
            idempotencyKey: 'idem-456',
        );

        $this->assertSame(PmodActionType::CHANGE_PAYMENT, $workItem->actionType);
        $this->assertSame(PmodCompany::LDR, $workItem->company);
        $this->assertSame(['5538063', '5539001'], $workItem->settlementIds);
        $this->assertSame(['2024-11-25'], $workItem->originalDates);
        $this->assertSame(['2024-12-03'], $workItem->targetDates);
        $this->assertSame(['250.50'], $workItem->amounts);
        $this->assertSame('250.50', $workItem->amount);
        $this->assertSame('single', $workItem->paymentChange['mode']);
        $this->assertTrue($workItem->paymentChange['void_settlements']);
    }

    public function testActionMapperRejectsUnsupportedActionLabel(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PmodActionMapper::fromLabel('Something Else');
    }
}
