<?php

declare(strict_types=1);

namespace Cmd\Reports\Pmod\Actions;

use Cmd\Reports\Pmod\Contracts\PmodActionHandler;
use Cmd\Reports\Pmod\Contracts\PmodExecutionGateway;
use Cmd\Reports\Pmod\Data\PmodResult;
use Cmd\Reports\Pmod\Data\PmodWorkItem;
use Cmd\Reports\Pmod\Enums\PmodActionType;
use Cmd\Reports\Pmod\Support\PmodDebtMatcher;

final class RemoveCreditorAndDecreaseTermAction implements PmodActionHandler
{
    public function __construct(
        private readonly PmodExecutionGateway $gateway,
        private readonly bool $allowLiveDraftUpdates = false,
    ) {}

    public function actionType(): PmodActionType
    {
        return PmodActionType::REMOVE_CREDITOR_AND_DECREASE_TERM;
    }

    public function handle(PmodWorkItem $workItem): PmodResult
    {
        $creditorChange  = $workItem->creditorChange;
        $monthsToDecrease = (int) ($workItem->normalizedPayload['months_to_decrease'] ?? $creditorChange['months_to_decrease'] ?? 0);
        $creditorName    = $creditorChange['creditor_name'] ?? null;
        $creditorId      = $creditorChange['creditor_id'] ?? null;

        if (empty($creditorChange) || ($creditorName === null && $creditorId === null)) {
            return $this->capture($workItem, 'Remove Creditor and Decrease Term requires creditor information.', ['reason' => 'missing_creditor_info']);
        }

        // months_to_decrease is recorded, not acted on. This action used to REQUIRE
        // it (1-120) and capture without it; that requirement is gone along with
        // the draft cancelling it drove — see the note on Jacob's decision below.
        // It is still carried into the note and metadata because the client asked
        // for a specific reduction and a reviewer should be able to see it.

        // Matched BEFORE the live-updates gate so a dry run proves which debt would
        // be removed; reading debts is read-only. See §8.6 for the same fix on the
        // Add Creditor pair.
        $debts = $this->gateway->getContactDebts($workItem);
        $match = PmodDebtMatcher::match($debts, $creditorChange);
        $debt  = $match['debt'];

        if ($debt === null) {
            return $this->capture($workItem, sprintf('Remove Creditor and Decrease Term could not identify a single debt for creditor [%s].', $creditorName ?? $creditorId), [
                'reason'      => $match['reason'],
                'creditor'    => $creditorName ?? $creditorId,
                'debts_found' => count($debts),
                'candidates'  => $match['candidates'],
            ]);
        }

        if (!$this->allowLiveDraftUpdates || $workItem->dryRun) {
            return $this->capture($workItem, 'Remove Creditor and Decrease Term matched but live updates are disabled.', [
                'reason'             => $workItem->dryRun ? 'dry_run_only' : 'live_draft_updates_disabled',
                'creditor'           => $creditorName,
                'months_to_decrease' => $monthsToDecrease,
                // Stated explicitly so a dry run cannot be misread as "it will also
                // shorten the schedule by this many drafts". It will not: the debt
                // goes, and the CRM recalculates.
                'would_cancel_drafts' => 0,
                'would_remove'       => [
                    'debt_id'  => $debt['id'] ?? null,
                    'creditor' => $debt['creditor']['company_name'] ?? null,
                    'account'  => $debt['og_account_num'] ?? null,
                    'balance'  => $debt['current_debt_amount'] ?? null,
                    'enrolled' => $debt['enrolled'] ?? null,
                ],
            ]);
        }

        // Remove the debt, and nothing else.
        //
        // **Jacob, 2026-09-02, asked directly: "yes it should remove the debt and
        // recalculate."** This action used to cancel the last N future drafts to
        // shorten the program itself. That was a divergence from the legacy bot,
        // which had no month count in this sub at all and never touched a draft —
        // it removed the debt and left the CRM to recalculate the term (§6, §4.4).
        // Both produce a shorter program; they produce DIFFERENT schedules, and
        // this is the one Liberty wants.
        //
        // Cancelling drafts here was also the riskiest thing this action did: it
        // deleted real scheduled payments off the end of a client's plan based on a
        // number the consumer supplied, with no way back.
        $debtId       = (string) ($debt['id'] ?? '');
        $cancelResult = $this->gateway->cancelDebt($workItem, $debtId);

        $noteLines = [
            'Remove Creditor and Decrease Term Request:',
            'Request Status: Successful',
            'Name: ' . ($workItem->normalizedPayload['name'] ?? 'Client'),
            'Customer Id: ' . $workItem->contactId,
            'Action: Remove Creditor and Decrease Term',
            'Creditor Removed: ' . ($creditorName ?? $creditorId ?? 'N/A'),
            'Requested Reduction: ' . ($monthsToDecrease > 0 ? $monthsToDecrease . ' payment(s)' : 'not specified'),
            // No claim about the term. It used to read "recalculated by the CRM
            // from the remaining enrolled debt", which §8.22 disproved: Forth
            // recalculates only the enrolled-debt total, never the program Length
            // or the drafts. createContactNote() posts with public = true, so the
            // client reads this - telling them their term was recalculated when
            // their payments and dates are unchanged is worse than saying nothing.
            'User: ' . ($workItem->requestedBy ?? 'Client'),
        ];

        $this->gateway->createContactNote($workItem, implode("\n", $noteLines));

        return new PmodResult(
            status: 'updated',
            message: sprintf('Remove Creditor and Decrease Term: creditor excluded from the program for contact [%s]; the draft schedule is unchanged.', $workItem->contactId),
            metadata: [
                'action_type'        => $workItem->actionType->value,
                'contact_id'         => $workItem->contactId,
                'debt_id'            => $debtId,
                'creditor_name'      => $creditorName,
                'months_to_decrease' => $monthsToDecrease,
                'drafts_cancelled'   => 0,
                'cancel_result'      => $cancelResult,
            ],
        );
    }

    /** @param array<string, mixed> $metadata */
    private function capture(PmodWorkItem $workItem, string $message, array $metadata): PmodResult
    {
        $this->gateway->createContactNote($workItem, implode("\n", [
            'PMOD Remove Creditor and Decrease Term requires manual review.',
            'Reason: ' . $message,
            'Contact ID: ' . $workItem->contactId,
            'Requested By: ' . $workItem->requestedBy,
        ]));

        return new PmodResult(
            status: 'captured_for_manual_review',
            message: $message,
            metadata: [...$metadata, 'action_type' => $workItem->actionType->value, 'contact_id' => $workItem->contactId],
        );
    }

    /**
     * @param list<array<string, mixed>> $debts
     * @return array<string, mixed>|null
     */
}
