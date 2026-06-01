<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit\Pmod\Actions;

use Cmd\Reports\Pmod\Actions\RescheduleAllPaymentsAction;
use Cmd\Reports\Pmod\Enums\PmodActionType;
use Cmd\Reports\Tests\Support\FakePmodExecutionGateway;
use Cmd\Reports\Tests\TestCase;

final class RescheduleAllPaymentsActionTest extends TestCase
{
    public function test_it_captures_manual_review_when_the_start_date_is_missing(): void
    {
        $gateway = new FakePmodExecutionGateway();
        $action = new RescheduleAllPaymentsAction($gateway);

        $result = $action->handle($this->makeWorkItem([
            'actionType' => PmodActionType::RESCHEDULE_ALL_PAYMENTS,
        ]));

        self::assertSame('captured_for_manual_review', $result->status);
        self::assertSame('Reschedule All Payments is missing required start date.', $result->message);
        self::assertSame('missing_required_fields', $result->metadata['reason']);
        self::assertCount(1, $gateway->notes);
    }

    public function test_it_reschedules_future_drafts_in_existing_date_order(): void
    {
        $futureOne = date('Y-m-d', strtotime('+5 days'));
        $futureTwo = date('Y-m-d', strtotime('+15 days'));

        $gateway = new FakePmodExecutionGateway();
        $gateway->transactions = [
            ['draft_id' => 'draft-late', 'type' => 'D', 'process_date' => $futureTwo, 'amount' => '125.00'],
            ['draft_id' => 'draft-early', 'type' => 'D', 'process_date' => $futureOne, 'amount' => '125.00'],
        ];

        $action = new RescheduleAllPaymentsAction($gateway, allowLiveDraftUpdates: true);
        $workItem = $this->makeWorkItem([
            'actionType' => PmodActionType::RESCHEDULE_ALL_PAYMENTS,
            'frequency' => 'quarterly',
            'paymentChange' => ['start_date' => '2026-08-01'],
        ]);

        $result = $action->handle($workItem);

        self::assertSame('updated', $result->status);
        self::assertSame(2, $result->metadata['drafts_updated']);
        self::assertCount(2, $gateway->draftUpdates);
        self::assertSame('draft-early', $gateway->draftUpdates[0]['draft_id']);
        self::assertSame('2026-08-01', $gateway->draftUpdates[0]['payload']['process_date']);
        self::assertSame('draft-late', $gateway->draftUpdates[1]['draft_id']);
        self::assertSame('2026-11-01', $gateway->draftUpdates[1]['payload']['process_date']);
        self::assertCount(1, $gateway->notes);
        self::assertStringContainsString('Current Frequency: Quarterly', $gateway->notes[0]['content']);
    }
}
