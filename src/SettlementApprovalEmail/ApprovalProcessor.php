<?php

declare(strict_types=1);

namespace Cmd\Reports\SettlementApprovalEmail;

use RuntimeException;

final class ApprovalProcessor
{
    public function __construct(
        private ApprovalGateway $crm,
        private ?ApprovalLedger $ledger = null,
        private ?ApprovalNotifier $mail = null,
    ) {}

    /** Read-only: no ledger, notification, note, status or mailbox writes. */
    public function preview(ApprovalEmail $email, ?ApprovalRunLog $log = null): array
    {
        $log?->event('offer_read_started');
        $offer = $this->crm->offer($email);
        if ($offer === null) {
            $log?->event('offer_missing', ['reason' => 'retry_later'], 'warning');
            return ['outcome' => 'retry_offer_missing'];
        }
        $status = $this->offerStatus($offer, $email);
        $log?->event('offer_checked', ['current_status' => $status, 'required_status' => 1194]);
        if ($status !== 1194) {
            $log?->event('approval_decision', ['outcome' => 'would_skip', 'reason' => 'status_not_1194', 'current_status' => $status, 'required_status' => 1194]);
            return ['outcome' => 'would_skip', 'current_status' => $status];
        }
        $log?->event('payment_type_read_started');
        $paymentType = $this->paymentType($offer);
        $target = ApprovalEmail::targetLabel($paymentType);
        $log?->event('approval_decision', ['outcome' => 'would_approve', 'current_status' => $status, 'payment_type' => $paymentType, 'target' => $target]);
        return ['outcome' => 'would_approve', 'target' => $target];
    }

    public function process(ApprovalEmail $email, string $body, ?ApprovalRunLog $log = null): string
    {
        if ($this->ledger === null || $this->mail === null) throw new RuntimeException('Live processing dependencies are missing.');
        $log?->event('ledger_claim_started');
        $state = $this->ledger->claim($email);
        if ($state === null) {
            $log?->event('ledger_busy', ['reason' => 'another_worker_holds_lease'], 'warning');
            return 'busy';
        }
        try {
            $stage = $state['stage'];
            $knownStages = ['ready', 'status_attempting', 'status_updated', 'note_attempting', 'notification_ready', 'mail_attempting', 'completed', 'skipped', 'needs_review'];
            $log?->event('ledger_claimed', ['stage' => in_array($stage, $knownStages, true) ? $stage : 'unknown']);
            if (in_array($stage, ['completed', 'skipped', 'needs_review'], true)) {
                $log?->event('ledger_terminal', ['outcome' => $stage, 'reason' => $stage === 'needs_review' ? 'manual_review_required' : 'previously_processed']);
                return $stage;
            }
            // Graph sendMail and note creation are not safely replayable after an uncertain result.
            if (in_array($stage, ['note_attempting', 'mail_attempting'], true)) {
                $this->ledger->save($email, ['stage' => 'needs_review', 'error_code' => 'uncertain_' . $stage]);
                $log?->event('manual_review_required', ['reason' => 'uncertain_' . $stage], 'warning');
                return 'needs_review';
            }

            if (in_array($stage, ['ready', 'status_attempting'], true)) {
                $log?->event('offer_read_started');
                $offer = $this->crm->offer($email);
                if ($offer === null) {
                    $log?->event('offer_missing', ['reason' => 'retry_later'], 'warning');
                    return 'retry_offer_missing';
                }
                $status = $this->offerStatus($offer, $email);
                $log?->event('offer_checked', ['current_status' => $status, 'required_status' => 1194]);
                if ($stage === 'status_attempting') {
                    // A PUT may have succeeded before a timeout/process crash. Only reconcile it.
                    if ((string) $status !== (string) $state['target_status']) {
                        $this->ledger->save($email, ['stage' => 'needs_review', 'error_code' => 'uncertain_status_update']);
                        $log?->event('manual_review_required', ['reason' => 'uncertain_status_update'], 'warning');
                        return 'needs_review';
                    }
                    $this->ledger->save($email, ['stage' => 'status_updated']);
                    $stage = 'status_updated';
                    $log?->event('status_reconciled', ['current_status' => $status]);
                } elseif ($status !== 1194) {
                    $log?->event('approval_decision', ['outcome' => 'skipped', 'reason' => 'status_not_1194', 'current_status' => $status, 'required_status' => 1194]);
                    $this->ledger->save($email, ['stage' => 'notification_ready', 'result' => 'skipped']);
                    $stage = 'notification_ready';
                    $state['result'] = 'skipped';
                } else {
                    $log?->event('payment_type_read_started');
                    $paymentType = $this->paymentType($offer);
                    $targetLabel = ApprovalEmail::targetLabel($paymentType);
                    $log?->event('status_catalog_read_started', ['payment_type' => $paymentType, 'target' => $targetLabel]);
                    $target = $this->crm->statusId($email, $targetLabel);
                    $log?->event('approval_decision', ['current_status' => $status, 'payment_type' => $paymentType, 'target' => $targetLabel, 'target_status' => $target]);
                    $log?->event('live_configuration_check_started');
                    $this->crm->assertReady($email);
                    $this->mail->assertReady();
                    $this->ledger->save($email, ['stage' => 'status_attempting', 'target_status' => $target, 'result' => 'completed']);
                    $log?->event('status_update_started', ['target_status' => $target]);
                    $this->crm->updateStatus($email, $target);
                    $log?->event('status_verification_started', ['target_status' => $target]);
                    $saved = $this->crm->offer($email);
                    if ($saved === null || (string) $this->offerStatus($saved, $email) !== $target) {
                        throw new RuntimeException('Settlement status could not be verified; reconcile before repeating.');
                    }
                    $this->ledger->save($email, ['stage' => 'status_updated']);
                    $stage = 'status_updated';
                    $state['result'] = 'completed';
                    $state['target_status'] = $target;
                    $log?->event('status_verified', ['target_status' => $target]);
                }
            }

            if ($stage === 'status_updated') {
                // A resumed job may have sat overnight. Do not attest approval after a later void/status change.
                $log?->event('status_before_note_check_started');
                $current = $this->crm->offer($email);
                if ($current === null || (string) $this->offerStatus($current, $email) !== (string) $state['target_status']) {
                    $this->ledger->save($email, ['stage' => 'needs_review', 'error_code' => 'status_changed_before_note']);
                    $log?->event('manual_review_required', ['reason' => 'status_changed_before_note'], 'warning');
                    return 'needs_review';
                }
                $this->crm->assertReady($email);
                $this->ledger->save($email, ['stage' => 'note_attempting']);
                $log?->event('note_create_started');
                $this->crm->addNote($email);
                $log?->event('note_created');
                $this->ledger->save($email, ['stage' => 'notification_ready']);
                $stage = 'notification_ready';
            }
            if ($stage === 'notification_ready') {
                $log?->event('notification_configuration_check_started');
                $this->mail->assertReady();
                $this->ledger->save($email, ['stage' => 'mail_attempting']);
                $result = $state['result'] ?? 'completed';
                $log?->event('notification_send_started', ['outcome' => $result]);
                $this->mail->send('Client Approved Settlement' . ($result === 'skipped' ? ' - Skipped' : ''), $body);
                $log?->event('notification_accepted', ['outcome' => $result]);
                $this->ledger->save($email, ['stage' => $result, 'error_code' => null]);
                return $result;
            }
            throw new RuntimeException('Unrecognized settlement approval processing stage.');
        } finally {
            $this->ledger->release($email);
        }
    }

    private function offerStatus(array $offer, ApprovalEmail $email): int
    {
        $contact = $offer['contact_id'] ?? null;
        $offerId = $offer['id'] ?? null;
        $status = $offer['offer_status'] ?? null;
        // Live Forth offers use status_id; keep legacy id and flat fixtures supported.
        if (is_array($status)) $status = $status['status_id'] ?? $status['id'] ?? null;
        if ((!is_int($offerId) && !is_string($offerId)) || (string) $offerId !== $email->settlementId
            || (!is_int($contact) && !is_string($contact)) || (string) $contact !== $email->contactId) {
            throw new RuntimeException('Settlement/customer mismatch; no approval will be applied.');
        }
        if ((!is_int($status) && !is_string($status)) || !ctype_digit((string) $status)) {
            throw new RuntimeException('Offer status is missing or invalid.');
        }
        return (int) $status;
    }

    private function paymentType(array $offer): int
    {
        $data = $offer['json'] ?? $offer['JSON'] ?? [];
        if (is_string($data)) $data = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
        $type = is_array($data) ? ($data['pay_type'] ?? null) : null;
        if ((!is_int($type) && !is_string($type)) || !ctype_digit((string) $type) || (int) $type < 1) {
            throw new RuntimeException('Offer payment type is missing or invalid.');
        }
        return (int) $type;
    }
}
