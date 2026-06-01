<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit\Pmod\Actions;

use Cmd\Reports\Pmod\Actions\IncreaseAllFuturePaymentsAction;
use Cmd\Reports\Pmod\Enums\PmodActionType;
use Cmd\Reports\Tests\Support\FakePmodExecutionGateway;
use Cmd\Reports\Tests\TestCase;

final class IncreaseAllFuturePaymentsActionTest extends TestCase
{
    public function test_it_captures_manual_review_when_the_new_amount_is_missing(): void
    {
        $gateway = new FakePmodExecutionGateway();
        $action = new IncreaseAllFuturePaymentsAction($gateway);

        $result = $action->handle($this->makeWorkItem([
            'actionType' => PmodActionType::INCREASE_ALL_FUTURE_PAYMENTS,
        ]));

        self::assertSame('captured_for_manual_review', $result->status);
        self::assertSame('Increase All Future Payments is missing the new payment amount.', $result->message);
        self::assertSame('missing_required_fields', $result->metadata['reason']);
        self::assertCount(1, $gateway->notes);
    }

    public function test_it_updates_only_future_drafts_with_the_new_amount(): void
    {
        $futureOne = date('Y-m-d', strtotime('+1 day'));
        $futureTwo = date('Y-m-d', strtotime('+10 days'));
        $pastDate = date('Y-m-d', strtotime('-1 day'));

        $gateway = new FakePmodExecutionGateway();
        $gateway->transactions = [
            ['draft_id' => 'draft-1', 'type' => 'D', 'process_date' => $futureOne, 'amount' => '100.00'],
            ['draft_id' => 'draft-2', 'trans_type' => 'D', 'process_date' => $futureTwo, 'amount' => '100.00'],
            ['draft_id' => 'draft-3', 'type' => 'D', 'process_date' => $pastDate, 'amount' => '100.00'],
            ['draft_id' => 'draft-4', 'type' => 'C', 'process_date' => $futureTwo, 'amount' => '100.00'],
            ['draft_id' => 'draft-5', 'type' => 'D', 'process_date' => $futureTwo, 'amount' => '100.00', 'cancelled' => true],
        ];

        $action = new IncreaseAllFuturePaymentsAction($gateway, allowLiveDraftUpdates: true);
        $workItem = $this->makeWorkItem([
            'actionType' => PmodActionType::INCREASE_ALL_FUTURE_PAYMENTS,
            'increaseAmount' => '275.00',
        ]);

        $result = $action->handle($workItem);

        self::assertSame('updated', $result->status);
        self::assertSame(2, $result->metadata['drafts_updated']);
        self::assertCount(2, $gateway->draftUpdates);
        self::assertSame('275.00', $gateway->draftUpdates[0]['payload']['amount']);
        self::assertSame('275.00', $gateway->draftUpdates[1]['payload']['amount']);
        self::assertCount(1, $gateway->notes);
        self::assertStringContainsString('Request Status: Successful', $gateway->notes[0]['content']);
        self::assertStringContainsString('New Monthly Payment: $275.00', $gateway->notes[0]['content']);
    }
}
