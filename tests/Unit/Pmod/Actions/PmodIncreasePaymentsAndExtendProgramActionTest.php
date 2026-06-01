<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit\Pmod\Actions;

use Cmd\Reports\Pmod\Actions\PmodIncreasePaymentsAndExtendProgramAction;
use Cmd\Reports\Pmod\Enums\PmodActionType;
use Cmd\Reports\Tests\Support\FakePmodExecutionGateway;
use Cmd\Reports\Tests\TestCase;

final class PmodIncreasePaymentsAndExtendProgramActionTest extends TestCase
{
    public function test_it_captures_when_amount_is_missing(): void
    {
        $gateway = new FakePmodExecutionGateway();
        $action  = new PmodIncreasePaymentsAndExtendProgramAction($gateway);

        $result = $action->handle($this->makeWorkItem([
            'actionType' => PmodActionType::PMOD_INCREASE_PAYMENTS_AND_EXTEND_PROGRAM,
            'paymentChange' => [
                'extend_months' => 3,
            ],
        ]));

        self::assertSame('captured_for_manual_review', $result->status);
        self::assertSame('missing_required_fields', $result->metadata['reason']);
    }

    public function test_it_captures_when_extension_duration_missing(): void
    {
        $futureDate = date('Y-m-d', strtotime('+30 days'));
        $gateway = new FakePmodExecutionGateway();
        $gateway->transactions = [
            ['draft_id' => 'd1', 'type' => 'D', 'process_date' => $futureDate, 'amount' => '300.00'],
        ];

        $action = new PmodIncreasePaymentsAndExtendProgramAction($gateway, allowLiveDraftUpdates: true);
        $result = $action->handle($this->makeWorkItem([
            'actionType' => PmodActionType::PMOD_INCREASE_PAYMENTS_AND_EXTEND_PROGRAM,
            'increaseAmount' => '500.00',
        ]));

        self::assertSame('captured_for_manual_review', $result->status);
        self::assertSame('missing_extension_duration', $result->metadata['reason']);
    }

    public function test_it_captures_when_no_future_drafts_and_no_extension_start_provided(): void
    {
        $gateway = new FakePmodExecutionGateway();
        // No future drafts at all.
        $action = new PmodIncreasePaymentsAndExtendProgramAction($gateway, allowLiveDraftUpdates: true);

        $result = $action->handle($this->makeWorkItem([
            'actionType' => PmodActionType::PMOD_INCREASE_PAYMENTS_AND_EXTEND_PROGRAM,
            'increaseAmount' => '500.00',
            'paymentChange' => [
                'extend_months' => 3,
            ],
        ]));

        self::assertSame('captured_for_manual_review', $result->status);
        self::assertSame('cannot_resolve_extension_start', $result->metadata['reason']);
    }

    public function test_it_captures_when_live_updates_are_disabled(): void
    {
        $futureDate = date('Y-m-d', strtotime('+30 days'));
        $gateway = new FakePmodExecutionGateway();
        $gateway->transactions = [
            ['draft_id' => 'd1', 'type' => 'D', 'process_date' => $futureDate, 'amount' => '300.00'],
        ];

        $action = new PmodIncreasePaymentsAndExtendProgramAction($gateway, allowLiveDraftUpdates: false);
        $result = $action->handle($this->makeWorkItem([
            'actionType' => PmodActionType::PMOD_INCREASE_PAYMENTS_AND_EXTEND_PROGRAM,
            'increaseAmount' => '600.00',
            'paymentChange' => [
                'extend_months' => 3,
            ],
        ]));

        self::assertSame('captured_for_manual_review', $result->status);
        self::assertSame('live_draft_updates_disabled', $result->metadata['reason']);
        self::assertSame(1, $result->metadata['existing_draft_count']);
        self::assertSame(3, $result->metadata['extension_count']);
        self::assertCount(0, $gateway->draftUpdates);
        self::assertCount(0, $gateway->draftCreations);
    }

    public function test_it_updates_existing_drafts_and_creates_extension_drafts(): void
    {
        $firstDate = date('Y-m-d', strtotime('+5 days'));
        $lastDate  = date('Y-m-d', strtotime('+30 days'));

        $gateway = new FakePmodExecutionGateway();
        $gateway->transactions = [
            ['draft_id' => 'd1', 'type' => 'D', 'process_date' => $firstDate, 'amount' => '300.00'],
            ['draft_id' => 'd2', 'type' => 'D', 'process_date' => $lastDate, 'amount' => '300.00'],
        ];

        $action = new PmodIncreasePaymentsAndExtendProgramAction($gateway, allowLiveDraftUpdates: true);
        $result = $action->handle($this->makeWorkItem([
            'actionType' => PmodActionType::PMOD_INCREASE_PAYMENTS_AND_EXTEND_PROGRAM,
            'increaseAmount' => '500.00',
            'paymentChange' => [
                'extend_months' => 2,
            ],
        ]));

        self::assertSame('updated', $result->status);
        self::assertSame(2, $result->metadata['drafts_updated']);
        self::assertSame(2, $result->metadata['drafts_created']);
        self::assertCount(2, $gateway->draftUpdates);
        self::assertCount(2, $gateway->draftCreations);
        self::assertSame('500.00', $gateway->draftUpdates[0]['payload']['amount']);
        self::assertSame('500.00', $gateway->draftCreations[0]['payload']['amount']);
        $expectedExtensionStart = date('Y-m-d', strtotime($lastDate . ' +1 months'));
        self::assertSame($expectedExtensionStart, $gateway->draftCreations[0]['payload']['process_date']);
    }

    public function test_extended_amount_can_differ_from_increase_amount(): void
    {
        $futureDate = date('Y-m-d', strtotime('+15 days'));
        $gateway = new FakePmodExecutionGateway();
        $gateway->transactions = [
            ['draft_id' => 'd1', 'type' => 'D', 'process_date' => $futureDate, 'amount' => '300.00'],
        ];

        $action = new PmodIncreasePaymentsAndExtendProgramAction($gateway, allowLiveDraftUpdates: true);
        $result = $action->handle($this->makeWorkItem([
            'actionType' => PmodActionType::PMOD_INCREASE_PAYMENTS_AND_EXTEND_PROGRAM,
            'increaseAmount' => '500.00',
            'paymentChange' => [
                'extend_months' => 2,
                'extended_amount' => '425.00',
            ],
        ]));

        self::assertSame('updated', $result->status);
        self::assertSame('500.00', $gateway->draftUpdates[0]['payload']['amount']);
        self::assertSame('425.00', $gateway->draftCreations[0]['payload']['amount']);
        self::assertSame('425.00', $gateway->draftCreations[1]['payload']['amount']);
    }
}
