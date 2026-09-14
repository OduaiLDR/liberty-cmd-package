<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit\Pmod\Actions;

use Cmd\Reports\Pmod\Actions\SettlementApprovalAction;
use Cmd\Reports\Pmod\Enums\PmodActionType;
use Cmd\Reports\Pmod\Enums\PmodCompany;
use Cmd\Reports\Pmod\Support\SettlementApprovalRules;
use Cmd\Reports\Tests\Support\FakePmodExecutionGateway;
use Cmd\Reports\Tests\TestCase;

final class SettlementApprovalActionTest extends TestCase
{
    public function test_path_a_same_day_is_in_timestamp(): void
    {
        self::assertSame('A', SettlementApprovalRules::path('2026-09-11', '2026-09-11', '2026-09-11'));
    }

    public function test_path_b_when_valid_until_or_start_date_is_past(): void
    {
        self::assertSame('B', SettlementApprovalRules::path('2026-09-11', '2026-09-10', '2026-09-20'));
        self::assertSame('B', SettlementApprovalRules::path('2026-09-11', '2026-09-20', '2026-09-10'));
    }

    public function test_plaw_stays_capture_only(): void
    {
        $gateway = new FakePmodExecutionGateway();
        $action = new SettlementApprovalAction($gateway, allowLiveDraftUpdates: true);

        $result = $action->handle($this->makeWorkItem([
            'company' => PmodCompany::PLAW,
            'tenantId' => 'plaw',
            'actionType' => PmodActionType::SETTLEMENT_APPROVAL,
            'settlementIds' => ['sid-1'],
        ]));

        self::assertSame('captured_for_manual_review', $result->status);
        self::assertSame('company_not_ldr', $result->metadata['reason']);
        self::assertSame([], $gateway->settlementStatusUpdates);
        self::assertCount(1, $gateway->notes);
    }

    public function test_ineligible_status_does_not_write_or_note(): void
    {
        $gateway = $this->gatewayWithOffer(['offer_status' => '7']);
        $action = new SettlementApprovalAction($gateway, allowLiveDraftUpdates: true);

        $result = $action->handle($this->ldrItem());

        self::assertSame('captured_for_manual_review', $result->status);
        self::assertSame('ineligible_status', $result->metadata['reason']);
        self::assertSame([], $gateway->settlementStatusUpdates);
        self::assertCount(0, $gateway->notes);
    }

    public function test_live_path_a_sets_accepted(): void
    {
        $gateway = $this->gatewayWithOffer([
            'offer_status' => '2075',
            'offer_valid_date' => '2026-09-20',
            'start_date' => '2026-09-15',
            'debt_id' => 'debt-9',
        ]);
        $action = new SettlementApprovalAction($gateway, allowLiveDraftUpdates: true);

        $result = $action->handle($this->ldrItem());

        self::assertSame('updated', $result->status);
        self::assertSame('A', $result->metadata['path']);
        self::assertSame('7', $gateway->settlementStatusUpdates[0]['status_id']);
        self::assertStringContainsString('SETTLEMENT ACCEPTED VIA CLIENT PORTAL', $gateway->notes[0]['content']);
    }

    public function test_live_path_b_sets_out_of_timestamp(): void
    {
        $gateway = $this->gatewayWithOffer([
            'offer_status' => '2093',
            'offer_valid_date' => '2026-09-01',
            'json' => json_encode(['start_date' => '2026-09-20']),
        ]);
        $action = new SettlementApprovalAction($gateway, allowLiveDraftUpdates: true);

        $result = $action->handle($this->ldrItem());

        self::assertSame('captured_for_manual_review', $result->status);
        self::assertSame('B', $result->metadata['path']);
        self::assertSame('2590', $gateway->settlementStatusUpdates[0]['status_id']);
        self::assertStringContainsString('SETTLEMENT ACCEPTED OUT OF TIMESTAMP', $gateway->notes[0]['content']);
    }

    public function test_live_off_does_not_put_status(): void
    {
        $gateway = $this->gatewayWithOffer([
            'offer_status' => '2075',
            'offer_valid_date' => '2026-09-20',
            'start_date' => '2026-09-15',
        ]);
        $action = new SettlementApprovalAction($gateway, allowLiveDraftUpdates: false);

        $result = $action->handle($this->ldrItem());

        self::assertSame('captured_for_manual_review', $result->status);
        self::assertSame('live_draft_updates_disabled', $result->metadata['reason']);
        self::assertSame('7', $result->metadata['target_status_id']);
        self::assertSame([], $gateway->settlementStatusUpdates);
        self::assertCount(0, $gateway->notes);
    }

    public function test_forth_offer_status_object_uses_status_id(): void
    {
        $gateway = $this->gatewayWithOffer([
            'offer_status' => [
                'object' => 'settlement_offer_status',
                'status_id' => '2075',
                'label' => 'QC complete/ P-Client auth',
            ],
            'offer_valid_date' => '2026-03-31',
            'json' => ['start_date' => '2026-04-15'],
        ]);
        $action = new SettlementApprovalAction($gateway, allowLiveDraftUpdates: false);

        $result = $action->handle($this->ldrItem());

        self::assertSame('captured_for_manual_review', $result->status);
        self::assertSame('B', $result->metadata['path']);
        self::assertSame('2590', $result->metadata['target_status_id']);
        self::assertSame('valid_until', $result->metadata['failed_check']);
        self::assertSame([], $gateway->settlementStatusUpdates);
    }

    /**
     * @param array<string, mixed> $offer
     */
    private function gatewayWithOffer(array $offer): FakePmodExecutionGateway
    {
        $gateway = new FakePmodExecutionGateway();
        $gateway->settlementOffers['sid-1'] = $offer;

        return $gateway;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function ldrItem(array $overrides = []): \Cmd\Reports\Pmod\Data\PmodWorkItem
    {
        return $this->makeWorkItem(array_merge([
            'tenantId' => 'ldr',
            'company' => PmodCompany::LDR,
            'actionType' => PmodActionType::SETTLEMENT_APPROVAL,
            'settlementIds' => ['sid-1'],
            'receivedAt' => '2026-09-11T18:00:00+00:00',
        ], $overrides));
    }
}
