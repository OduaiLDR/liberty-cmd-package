<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit\Pmod\Actions;

use Cmd\Reports\Pmod\Actions\PmodExtendProgramAction;
use Cmd\Reports\Pmod\Enums\PmodActionType;
use Cmd\Reports\Tests\Support\FakePmodExecutionGateway;
use Cmd\Reports\Tests\TestCase;

final class PmodExtendProgramActionTest extends TestCase
{
    public function test_it_captures_when_amount_cannot_be_resolved(): void
    {
        $gateway = new FakePmodExecutionGateway();
        // No future drafts and no amount provided.
        $action = new PmodExtendProgramAction($gateway);

        $result = $action->handle($this->makeWorkItem([
            'actionType' => PmodActionType::PMOD_EXTEND_PROGRAM,
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

        $action = new PmodExtendProgramAction($gateway, allowLiveDraftUpdates: true);
        $result = $action->handle($this->makeWorkItem([
            'actionType' => PmodActionType::PMOD_EXTEND_PROGRAM,
            'amount' => '300.00',
        ]));

        self::assertSame('captured_for_manual_review', $result->status);
        self::assertSame('missing_extension_duration', $result->metadata['reason']);
    }

    public function test_it_captures_when_extension_exceeds_safety_cap(): void
    {
        $futureDate = date('Y-m-d', strtotime('+30 days'));
        $gateway = new FakePmodExecutionGateway();
        $gateway->transactions = [
            ['draft_id' => 'd1', 'type' => 'D', 'process_date' => $futureDate, 'amount' => '300.00'],
        ];

        $action = new PmodExtendProgramAction($gateway, allowLiveDraftUpdates: true);
        $result = $action->handle($this->makeWorkItem([
            'actionType' => PmodActionType::PMOD_EXTEND_PROGRAM,
            'amount' => '300.00',
            'paymentChange' => [
                'extend_months' => 120,
            ],
        ]));

        self::assertSame('captured_for_manual_review', $result->status);
        self::assertSame('extension_exceeds_safety_cap', $result->metadata['reason']);
    }

    public function test_it_captures_when_live_updates_are_disabled_with_planned_dates(): void
    {
        $futureDate = date('Y-m-d', strtotime('+30 days'));
        $gateway = new FakePmodExecutionGateway();
        $gateway->transactions = [
            ['draft_id' => 'd1', 'type' => 'D', 'process_date' => $futureDate, 'amount' => '300.00'],
        ];

        $action = new PmodExtendProgramAction($gateway, allowLiveDraftUpdates: false);
        $result = $action->handle($this->makeWorkItem([
            'actionType' => PmodActionType::PMOD_EXTEND_PROGRAM,
            'amount' => '300.00',
            'paymentChange' => [
                'extend_months' => 3,
            ],
        ]));

        self::assertSame('captured_for_manual_review', $result->status);
        self::assertSame('live_draft_updates_disabled', $result->metadata['reason']);
        self::assertCount(3, $result->metadata['planned_dates']);
        self::assertCount(0, $gateway->draftCreations);
    }

    public function test_it_creates_new_monthly_drafts_after_last_existing_draft(): void
    {
        $lastDate = date('Y-m-d', strtotime('+30 days'));
        $gateway = new FakePmodExecutionGateway();
        $gateway->transactions = [
            ['draft_id' => 'd1', 'type' => 'D', 'process_date' => date('Y-m-d', strtotime('+5 days')), 'amount' => '300.00'],
            ['draft_id' => 'd2', 'type' => 'D', 'process_date' => $lastDate, 'amount' => '300.00'],
        ];

        $action = new PmodExtendProgramAction($gateway, allowLiveDraftUpdates: true);
        $result = $action->handle($this->makeWorkItem([
            'actionType' => PmodActionType::PMOD_EXTEND_PROGRAM,
            'amount' => '300.00',
            'paymentChange' => [
                'extend_months' => 3,
            ],
        ]));

        self::assertSame('updated', $result->status);
        self::assertSame(3, $result->metadata['drafts_created']);
        self::assertCount(3, $gateway->draftCreations);
        // First extension date should be one month after the last future draft (anchor + 1 month).
        $expectedFirst = date('Y-m-d', strtotime($lastDate . ' +1 months'));
        self::assertSame($expectedFirst, $gateway->draftCreations[0]['payload']['process_date']);
        self::assertSame('300.00', $gateway->draftCreations[0]['payload']['amount']);
    }

    public function test_it_creates_drafts_using_explicit_extended_start_date_and_amount(): void
    {
        $futureDate = date('Y-m-d', strtotime('+30 days'));
        $extendedStart = date('Y-m-d', strtotime('+90 days'));
        $extendedEnd   = date('Y-m-d', strtotime($extendedStart . ' +2 months'));

        $gateway = new FakePmodExecutionGateway();
        $gateway->transactions = [
            ['draft_id' => 'd1', 'type' => 'D', 'process_date' => $futureDate, 'amount' => '300.00'],
        ];

        $action = new PmodExtendProgramAction($gateway, allowLiveDraftUpdates: true);
        $result = $action->handle($this->makeWorkItem([
            'actionType' => PmodActionType::PMOD_EXTEND_PROGRAM,
            'paymentChange' => [
                'extended_amount' => '475.50',
                'extended_start_date' => $extendedStart,
                'extended_end_date' => $extendedEnd,
            ],
        ]));

        self::assertSame('updated', $result->status);
        self::assertSame(3, $result->metadata['drafts_created']);
        self::assertSame('475.50', $gateway->draftCreations[0]['payload']['amount']);
        self::assertSame($extendedStart, $gateway->draftCreations[0]['payload']['process_date']);
    }

    public function test_it_records_partial_create_when_one_draft_fails(): void
    {
        $futureDate = date('Y-m-d', strtotime('+30 days'));
        $gateway = new FakePmodExecutionGateway();
        $gateway->transactions = [
            ['draft_id' => 'd1', 'type' => 'D', 'process_date' => $futureDate, 'amount' => '300.00'],
        ];
        $gateway->createDraftExceptionsByCall = [2 => 'CRM transient failure'];

        $action = new PmodExtendProgramAction($gateway, allowLiveDraftUpdates: true);
        $result = $action->handle($this->makeWorkItem([
            'actionType' => PmodActionType::PMOD_EXTEND_PROGRAM,
            'amount' => '300.00',
            'paymentChange' => [
                'extend_months' => 2,
            ],
        ]));

        self::assertSame('partial_update', $result->status);
        self::assertSame(1, $result->metadata['drafts_created']);
        self::assertCount(1, $result->metadata['errors']);
        self::assertSame('CRM transient failure', $result->metadata['errors'][0]['reason']);
    }
}
