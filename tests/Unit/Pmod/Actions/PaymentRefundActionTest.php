<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit\Pmod\Actions;

use Cmd\Reports\Pmod\Actions\PaymentRefundAction;
use Cmd\Reports\Pmod\Enums\PmodActionType;
use Cmd\Reports\Tests\Support\FakePmodExecutionGateway;
use Cmd\Reports\Tests\TestCase;

final class PaymentRefundActionTest extends TestCase
{
    public function test_it_captures_when_original_dates_or_amounts_are_missing(): void
    {
        $gateway = new FakePmodExecutionGateway();
        $action  = new PaymentRefundAction($gateway);

        $result = $action->handle($this->makeWorkItem([
            'actionType' => PmodActionType::PAYMENT_REFUND,
        ]));

        self::assertSame('captured_for_manual_review', $result->status);
        self::assertSame('missing_required_fields', $result->metadata['reason']);
    }

    public function test_it_captures_when_date_and_amount_counts_mismatch(): void
    {
        $gateway = new FakePmodExecutionGateway();
        $action  = new PaymentRefundAction($gateway);

        $result = $action->handle($this->makeWorkItem([
            'actionType' => PmodActionType::PAYMENT_REFUND,
            'originalDates' => ['2026-05-10', '2026-06-10'],
            'amounts' => ['300.00'],
        ]));

        self::assertSame('captured_for_manual_review', $result->status);
        self::assertSame('date_amount_count_mismatch', $result->metadata['reason']);
    }

    public function test_it_matches_drafts_and_records_them_for_manual_refund(): void
    {
        $gateway = new FakePmodExecutionGateway();
        $gateway->transactions = [
            ['draft_id' => 'draft-7', 'type' => 'D', 'process_date' => '2026-05-10', 'amount' => '300.00', 'cleared_date' => '2026-05-10'],
            ['draft_id' => 'draft-8', 'type' => 'D', 'process_date' => '2026-06-10', 'amount' => '300.00'],
        ];

        $action = new PaymentRefundAction($gateway, allowLiveDraftUpdates: true);
        $result = $action->handle($this->makeWorkItem([
            'actionType' => PmodActionType::PAYMENT_REFUND,
            'originalDates' => ['2026-05-10'],
            'amounts' => ['300.00'],
            'targetDates' => ['2026-08-10'],
        ]));

        self::assertSame('captured_for_manual_review', $result->status);
        self::assertSame('awaiting_manual_refund', $result->metadata['reason']);
        self::assertCount(1, $result->metadata['matched']);
        self::assertSame('draft-7', $result->metadata['matched'][0]['draft_id']);
        self::assertSame('2026-08-10', $result->metadata['matched'][0]['replacement_date']);
        // No live mutation - refunds always require operator review in v1.
        self::assertCount(0, $gateway->draftUpdates);
        self::assertCount(0, $gateway->draftCreations);
        self::assertCount(1, $gateway->notes);
        self::assertStringContainsString('Awaiting Manual Refund', $gateway->notes[0]['content']);
    }

    public function test_it_records_partial_match_failure_when_some_drafts_do_not_match(): void
    {
        $gateway = new FakePmodExecutionGateway();
        $gateway->transactions = [
            ['draft_id' => 'draft-7', 'type' => 'D', 'process_date' => '2026-05-10', 'amount' => '300.00'],
        ];

        $action = new PaymentRefundAction($gateway);
        $result = $action->handle($this->makeWorkItem([
            'actionType' => PmodActionType::PAYMENT_REFUND,
            'originalDates' => ['2026-05-10', '2026-06-10'],
            'amounts' => ['300.00', '300.00'],
        ]));

        self::assertSame('captured_for_manual_review', $result->status);
        self::assertSame('partial_match_failure', $result->metadata['reason']);
        self::assertCount(1, $result->metadata['matched']);
        self::assertCount(1, $result->metadata['errors']);
        self::assertSame('no_match', $result->metadata['errors'][0]['reason']);
    }

    public function test_it_flags_ambiguous_matches(): void
    {
        $gateway = new FakePmodExecutionGateway();
        $gateway->transactions = [
            ['draft_id' => 'draft-7', 'type' => 'D', 'process_date' => '2026-05-10', 'amount' => '300.00'],
            ['draft_id' => 'draft-8', 'type' => 'D', 'process_date' => '2026-05-10', 'amount' => '300.00'],
        ];

        $action = new PaymentRefundAction($gateway);
        $result = $action->handle($this->makeWorkItem([
            'actionType' => PmodActionType::PAYMENT_REFUND,
            'originalDates' => ['2026-05-10'],
            'amounts' => ['300.00'],
        ]));

        self::assertSame('captured_for_manual_review', $result->status);
        self::assertSame('partial_match_failure', $result->metadata['reason']);
        self::assertCount(0, $result->metadata['matched']);
        self::assertSame('ambiguous_match', $result->metadata['errors'][0]['reason']);
        self::assertSame(2, $result->metadata['errors'][0]['match_count']);
    }

    public function test_it_never_mutates_even_when_live_updates_are_enabled(): void
    {
        $gateway = new FakePmodExecutionGateway();
        $gateway->transactions = [
            ['draft_id' => 'draft-7', 'type' => 'D', 'process_date' => '2026-05-10', 'amount' => '300.00'],
        ];

        $action = new PaymentRefundAction($gateway, allowLiveDraftUpdates: true);
        $result = $action->handle($this->makeWorkItem([
            'actionType' => PmodActionType::PAYMENT_REFUND,
            'originalDates' => ['2026-05-10'],
            'amounts' => ['300.00'],
        ]));

        self::assertSame('captured_for_manual_review', $result->status);
        self::assertCount(0, $gateway->draftUpdates);
        self::assertCount(0, $gateway->draftCreations);
        self::assertTrue($result->metadata['live_draft_updates_enabled']);
    }
}
