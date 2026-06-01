<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit\Pmod\Support;

use Cmd\Reports\Pmod\Enums\PmodActionType;
use Cmd\Reports\Pmod\Enums\PmodCompany;
use Cmd\Reports\Pmod\Support\PmodWorkItemFactory;
use Cmd\Reports\Tests\TestCase;
use Illuminate\Support\Carbon;

final class PmodWorkItemFactoryTest extends TestCase
{
    public function test_it_builds_a_work_item_from_webhook_payload_using_tenant_context_and_normalized_fields(): void
    {
        Carbon::setTestNow('2026-04-20 12:34:56 UTC');
        $this->setTenantContext(['alias' => 'Paramount West']);

        $normalizedPayload = [
            'action' => 'Action: Skip Payment',
            'requested_by' => '  Sam Aresheh  ',
            'customer_id' => ' 12345 ',
            'settlement_ids' => [' set-1 ', '', 'set-1', 'set-2 '],
            'settlement_id' => ' set-3 ',
            'original_dates' => [' 2026-04-30 ', ''],
            'target_dates' => [' 2026-05-30 '],
            'amounts' => [' 150.00 ', ''],
            'increase_amount' => ' 225.00 ',
            'frequency' => ' monthly ',
            'start_date' => ' 2026-06-01 ',
            'void_settlements' => true,
        ];

        $workItem = PmodWorkItemFactory::fromWebhookPayload(
            normalizedPayload: $normalizedPayload,
            rawPayload: ['source' => 'raw'],
            tenantId: 'lt',
            idempotencyKey: 'hash-123',
            executionOptions: ['queue' => 'cmd'],
            dryRun: true,
        );

        self::assertNotSame('', $workItem->requestId);
        self::assertSame('lt', $workItem->tenantId);
        self::assertSame(PmodCompany::PLAW, $workItem->company);
        self::assertSame(PmodActionType::SKIP_PAYMENT, $workItem->actionType);
        self::assertSame('Sam Aresheh', $workItem->requestedBy);
        self::assertSame('12345', $workItem->contactId);
        self::assertSame('2026-04-20T12:34:56+00:00', $workItem->receivedAt);
        self::assertSame(['set-1', 'set-2', 'set-3'], $workItem->settlementIds);
        self::assertSame(['2026-04-30'], $workItem->originalDates);
        self::assertSame(['2026-05-30'], $workItem->targetDates);
        self::assertSame(['150.00'], $workItem->amounts);
        self::assertSame('150.00', $workItem->amount);
        self::assertSame('225.00', $workItem->increaseAmount);
        self::assertSame('monthly', $workItem->frequency);
        self::assertSame('multi', $workItem->paymentChange['mode']);
        self::assertTrue($workItem->paymentChange['void_settlements']);
        self::assertSame(['queue' => 'cmd'], $workItem->executionOptions);
        self::assertTrue($workItem->dryRun);
        self::assertSame('pmod:PLAW:skip_payment:12345:hash-123', $workItem->queueKey());
    }

    public function test_it_builds_a_work_item_from_approval_payload_and_prefers_explicit_company(): void
    {
        Carbon::setTestNow('2026-04-20 08:00:00 UTC');
        $this->setTenantContext(['alias' => 'LDR']);

        $workItem = PmodWorkItemFactory::fromApprovalPayload(
            normalizedPayload: [
                'action' => 'Increase All Future Payments',
                'company' => 'PLAW',
                'requested_by' => ' Jude ',
                'customer_id' => '987',
                'amounts' => ['299.00'],
                'increase_amount' => '350.00',
            ],
            rawPayload: ['raw' => true],
            tenantId: 'plaw',
            idempotencyKey: 'idem-456',
        );

        self::assertSame(PmodCompany::PLAW, $workItem->company);
        self::assertSame(PmodActionType::INCREASE_ALL_FUTURE_PAYMENTS, $workItem->actionType);
        self::assertSame('Jude', $workItem->requestedBy);
        self::assertSame('987', $workItem->contactId);
        self::assertSame('recurring', $workItem->paymentChange['mode']);
        self::assertSame('350.00', $workItem->paymentChange['increase_amount']);
    }

    public function test_it_hydrates_tier3_flat_payload_fields(): void
    {
        $workItem = PmodWorkItemFactory::fromWebhookPayload(
            normalizedPayload: [
                'action' => 'add_creditor_and_extend_program',
                'company' => 'LDR',
                'requested_by' => 'Client',
                'customer_id' => '1153799588',
                'creditor_name' => 'Capital One',
                'account_number' => '9988',
                'balance' => '4200.10',
                'months_to_extend' => '6',
                'amount' => '325.50',
            ],
            rawPayload: ['source' => 'raw'],
            tenantId: 'lt',
            idempotencyKey: 'hash-tier-3',
        );

        self::assertSame(PmodActionType::ADD_CREDITOR_AND_EXTEND_PROGRAM, $workItem->actionType);
        self::assertSame('Capital One', $workItem->creditorChange['creditor_name']);
        self::assertSame('9988', $workItem->creditorChange['account_number']);
        self::assertSame('4200.10', $workItem->creditorChange['balance']);
        self::assertSame('6', $workItem->creditorChange['months_to_extend']);
        self::assertSame('325.50', $workItem->creditorChange['amount']);
    }
}
