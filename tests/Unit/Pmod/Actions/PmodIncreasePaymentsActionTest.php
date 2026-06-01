<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit\Pmod\Actions;

use Cmd\Reports\Pmod\Actions\PmodIncreasePaymentsAction;
use Cmd\Reports\Pmod\Enums\PmodActionType;
use Cmd\Reports\Tests\Support\FakePmodExecutionGateway;
use Cmd\Reports\Tests\TestCase;

final class PmodIncreasePaymentsActionTest extends TestCase
{
    public function test_it_captures_when_amount_is_missing(): void
    {
        $gateway = new FakePmodExecutionGateway();
        $action  = new PmodIncreasePaymentsAction($gateway);

        $result = $action->handle($this->makeWorkItem([
            'actionType' => PmodActionType::PMOD_INCREASE_PAYMENTS,
        ]));

        self::assertSame('captured_for_manual_review', $result->status);
        self::assertSame('missing_required_fields', $result->metadata['reason']);
        self::assertCount(1, $gateway->notes);
    }

    public function test_it_captures_when_date_range_is_inverted(): void
    {
        $gateway = new FakePmodExecutionGateway();
        $action  = new PmodIncreasePaymentsAction($gateway, allowLiveDraftUpdates: true);

        $result = $action->handle($this->makeWorkItem([
            'actionType' => PmodActionType::PMOD_INCREASE_PAYMENTS,
            'increaseAmount' => '450.00',
            'paymentChange' => [
                'start_date' => '2026-12-01',
                'end_date' => '2026-06-01',
            ],
        ]));

        self::assertSame('captured_for_manual_review', $result->status);
        self::assertSame('inverted_date_range', $result->metadata['reason']);
        self::assertSame(0, count($gateway->draftUpdates));
    }

    public function test_it_captures_when_no_drafts_are_in_the_requested_range(): void
    {
        $futureDate = date('Y-m-d', strtotime('+30 days'));
        $gateway = new FakePmodExecutionGateway();
        $gateway->transactions = [
            ['draft_id' => 'd1', 'type' => 'D', 'process_date' => $futureDate, 'amount' => '300.00'],
        ];

        $action = new PmodIncreasePaymentsAction($gateway, allowLiveDraftUpdates: true);
        $result = $action->handle($this->makeWorkItem([
            'actionType' => PmodActionType::PMOD_INCREASE_PAYMENTS,
            'increaseAmount' => '500.00',
            'paymentChange' => [
                'start_date' => date('Y-m-d', strtotime('+90 days')),
                'end_date' => date('Y-m-d', strtotime('+120 days')),
            ],
        ]));

        self::assertSame('captured_for_manual_review', $result->status);
        self::assertSame('no_drafts_in_range', $result->metadata['reason']);
        self::assertSame(1, $result->metadata['future_draft_count']);
        self::assertCount(0, $gateway->draftUpdates);
    }

    public function test_it_captures_when_live_updates_are_disabled(): void
    {
        $gateway = new FakePmodExecutionGateway();
        $gateway->transactions = [
            ['draft_id' => 'd1', 'type' => 'D', 'process_date' => date('Y-m-d', strtotime('+5 days')), 'amount' => '300.00'],
        ];

        $action = new PmodIncreasePaymentsAction($gateway, allowLiveDraftUpdates: false);
        $result = $action->handle($this->makeWorkItem([
            'actionType' => PmodActionType::PMOD_INCREASE_PAYMENTS,
            'increaseAmount' => '500.00',
        ]));

        self::assertSame('captured_for_manual_review', $result->status);
        self::assertSame('live_draft_updates_disabled', $result->metadata['reason']);
        self::assertCount(0, $gateway->draftUpdates);
    }

    public function test_it_filters_drafts_by_date_range_and_updates_only_matching(): void
    {
        $inRangeOne = date('Y-m-d', strtotime('+30 days'));
        $inRangeTwo = date('Y-m-d', strtotime('+45 days'));
        $outOfRange = date('Y-m-d', strtotime('+90 days'));
        $past       = date('Y-m-d', strtotime('-5 days'));

        $gateway = new FakePmodExecutionGateway();
        $gateway->transactions = [
            ['draft_id' => 'past',     'type' => 'D', 'process_date' => $past,        'amount' => '300.00'],
            ['draft_id' => 'in1',      'type' => 'D', 'process_date' => $inRangeOne, 'amount' => '300.00'],
            ['draft_id' => 'in2',      'type' => 'D', 'process_date' => $inRangeTwo, 'amount' => '300.00'],
            ['draft_id' => 'out',      'type' => 'D', 'process_date' => $outOfRange, 'amount' => '300.00'],
            ['draft_id' => 'credit',   'type' => 'C', 'process_date' => $inRangeOne, 'amount' => '300.00'],
            ['draft_id' => 'cancelled','type' => 'D', 'process_date' => $inRangeOne, 'amount' => '300.00', 'cancelled' => true],
        ];

        $action = new PmodIncreasePaymentsAction($gateway, allowLiveDraftUpdates: true);
        $result = $action->handle($this->makeWorkItem([
            'actionType' => PmodActionType::PMOD_INCREASE_PAYMENTS,
            'increaseAmount' => '500.00',
            'paymentChange' => [
                'start_date' => date('Y-m-d', strtotime('+10 days')),
                'end_date' => date('Y-m-d', strtotime('+60 days')),
            ],
        ]));

        self::assertSame('updated', $result->status);
        self::assertSame(2, $result->metadata['drafts_updated']);
        self::assertCount(2, $gateway->draftUpdates);
        self::assertSame('in1', $gateway->draftUpdates[0]['draft_id']);
        self::assertSame('in2', $gateway->draftUpdates[1]['draft_id']);
        self::assertSame('500.00', $gateway->draftUpdates[0]['payload']['amount']);
    }

    public function test_it_updates_all_future_drafts_when_no_range_provided(): void
    {
        $gateway = new FakePmodExecutionGateway();
        $gateway->transactions = [
            ['draft_id' => 'a', 'type' => 'D', 'process_date' => date('Y-m-d', strtotime('+5 days')), 'amount' => '300.00'],
            ['draft_id' => 'b', 'type' => 'D', 'process_date' => date('Y-m-d', strtotime('+35 days')), 'amount' => '300.00'],
        ];

        $action = new PmodIncreasePaymentsAction($gateway, allowLiveDraftUpdates: true);
        $result = $action->handle($this->makeWorkItem([
            'actionType' => PmodActionType::PMOD_INCREASE_PAYMENTS,
            'increaseAmount' => '600.00',
        ]));

        self::assertSame('updated', $result->status);
        self::assertSame(2, $result->metadata['drafts_updated']);
        self::assertCount(2, $gateway->draftUpdates);
    }

    public function test_it_records_partial_update_when_one_draft_fails(): void
    {
        $gateway = new FakePmodExecutionGateway();
        $gateway->transactions = [
            ['draft_id' => 'good', 'type' => 'D', 'process_date' => date('Y-m-d', strtotime('+5 days')), 'amount' => '300.00'],
            ['draft_id' => 'bad',  'type' => 'D', 'process_date' => date('Y-m-d', strtotime('+10 days')), 'amount' => '300.00'],
        ];
        $gateway->updateDraftExceptions['bad'] = 'Forth gateway 500';

        $action = new PmodIncreasePaymentsAction($gateway, allowLiveDraftUpdates: true);
        $result = $action->handle($this->makeWorkItem([
            'actionType' => PmodActionType::PMOD_INCREASE_PAYMENTS,
            'increaseAmount' => '450.00',
        ]));

        self::assertSame('partial_update', $result->status);
        self::assertSame(1, $result->metadata['drafts_updated']);
        self::assertCount(1, $result->metadata['errors']);
        self::assertSame('bad', $result->metadata['errors'][0]['draft_id']);
    }

    public function test_dry_run_short_circuits_before_any_mutation(): void
    {
        $gateway = new FakePmodExecutionGateway();
        $gateway->transactions = [
            ['draft_id' => 'd1', 'type' => 'D', 'process_date' => date('Y-m-d', strtotime('+5 days')), 'amount' => '300.00'],
        ];

        $action = new PmodIncreasePaymentsAction($gateway, allowLiveDraftUpdates: true);
        $result = $action->handle($this->makeWorkItem([
            'actionType' => PmodActionType::PMOD_INCREASE_PAYMENTS,
            'increaseAmount' => '500.00',
            'dryRun' => true,
        ]));

        self::assertSame('captured_for_manual_review', $result->status);
        self::assertSame('dry_run_only', $result->metadata['reason']);
        self::assertCount(0, $gateway->draftUpdates);
    }
}
