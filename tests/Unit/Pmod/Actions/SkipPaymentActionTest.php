<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit\Pmod\Actions;

use Cmd\Reports\Pmod\Actions\SkipPaymentAction;
use Cmd\Reports\Tests\Support\FakePmodExecutionGateway;
use Cmd\Reports\Tests\TestCase;

final class SkipPaymentActionTest extends TestCase
{
    public function test_it_captures_manual_review_when_original_dates_are_missing(): void
    {
        $gateway = new FakePmodExecutionGateway();
        $action = new SkipPaymentAction($gateway);

        $result = $action->handle($this->makeWorkItem());

        self::assertSame('captured_for_manual_review', $result->status);
        self::assertSame('Skip Payment request is missing original dates.', $result->message);
        self::assertSame('missing_required_fields', $result->metadata['reason']);
        self::assertCount(1, $gateway->notes);
        self::assertStringContainsString('requires manual review', $gateway->notes[0]['content']);
    }

    public function test_it_updates_matched_drafts_and_voids_linked_settlements_when_live_updates_are_enabled(): void
    {
        $gateway = new FakePmodExecutionGateway();
        $gateway->transactions = [[
            'draft_id' => 'draft-1',
            'process_date' => '2026-05-15',
            'amount' => '150.00',
        ]];
        $gateway->updateDraftResponses['draft-1'] = ['draft_id' => 'draft-1', 'status' => 'updated'];
        $gateway->voidSettlementResponses['set-9'] = ['settlement_id' => 'set-9', 'status' => 'voided'];

        $action = new SkipPaymentAction($gateway, allowLiveDraftUpdates: true);
        $workItem = $this->makeWorkItem([
            'settlementIds' => ['set-9'],
            'originalDates' => ['2026-05-15'],
            'targetDates' => ['2026-06-15'],
            'amounts' => ['150.00'],
            'amount' => '150.00',
        ]);

        $result = $action->handle($workItem);

        self::assertSame('updated', $result->status);
        self::assertSame(1, $result->metadata['drafts_updated']);
        self::assertSame(1, $result->metadata['settlements_voided']);
        self::assertCount(1, $gateway->draftUpdates);
        self::assertSame('draft-1', $gateway->draftUpdates[0]['draft_id']);
        self::assertSame('2026-06-15', $gateway->draftUpdates[0]['payload']['process_date']);
        self::assertSame('150.00', $gateway->draftUpdates[0]['payload']['amount']);
        self::assertCount(1, $gateway->settlementVoids);
        self::assertSame('set-9', $gateway->settlementVoids[0]['settlement_id']);
        self::assertCount(1, $gateway->notes);
        self::assertStringContainsString('Request Status: Successful', $gateway->notes[0]['content']);
        self::assertStringContainsString('Action: Skip Payment', $gateway->notes[0]['content']);
    }
}
