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
    public function preview(ApprovalEmail $email): array
    {
        $offer = $this->crm->offer($email);
        if ($offer === null) return ['outcome' => 'retry_offer_missing'];
        $status = $this->offerStatus($offer, $email);
        if ($status !== 1194) return ['outcome' => 'would_skip', 'current_status' => $status];
        return ['outcome' => 'would_approve', 'target' => ApprovalEmail::targetLabel($this->paymentType($offer))];
    }

    public function process(ApprovalEmail $email, string $body): string
    {
        if ($this->ledger === null || $this->mail === null) throw new RuntimeException('Live processing dependencies are missing.');
        $state = $this->ledger->claim($email);
        if ($state === null) return 'busy';
        try {
            $stage = $state['stage'];
            if (in_array($stage, ['completed', 'skipped', 'needs_review'], true)) return $stage;
            // Graph sendMail and note creation are not safely replayable after an uncertain result.
            if (in_array($stage, ['note_attempting', 'mail_attempting'], true)) {
                $this->ledger->save($email, ['stage' => 'needs_review', 'error_code' => 'uncertain_' . $stage]);
                return 'needs_review';
            }

            if (in_array($stage, ['ready', 'status_attempting'], true)) {
                $offer = $this->crm->offer($email);
                if ($offer === null) return 'retry_offer_missing';
                $status = $this->offerStatus($offer, $email);
                if ($stage === 'status_attempting') {
                    // A PUT may have succeeded before a timeout/process crash. Only reconcile it.
                    if ((string) $status !== (string) $state['target_status']) {
                        $this->ledger->save($email, ['stage' => 'needs_review', 'error_code' => 'uncertain_status_update']);
                        return 'needs_review';
                    }
                    $this->ledger->save($email, ['stage' => 'status_updated']);
                    $stage = 'status_updated';
                } elseif ($status !== 1194) {
                    $this->ledger->save($email, ['stage' => 'notification_ready', 'result' => 'skipped']);
                    $stage = 'notification_ready';
                    $state['result'] = 'skipped';
                } else {
                    $target = $this->crm->statusId($email, ApprovalEmail::targetLabel($this->paymentType($offer)));
                    $this->crm->assertReady($email);
                    $this->mail->assertReady();
                    $this->ledger->save($email, ['stage' => 'status_attempting', 'target_status' => $target, 'result' => 'completed']);
                    $this->crm->updateStatus($email, $target);
                    $saved = $this->crm->offer($email);
                    if ($saved === null || (string) $this->offerStatus($saved, $email) !== $target) {
                        throw new RuntimeException('Settlement status could not be verified; reconcile before repeating.');
                    }
                    $this->ledger->save($email, ['stage' => 'status_updated']);
                    $stage = 'status_updated';
                    $state['result'] = 'completed';
                    $state['target_status'] = $target;
                }
            }

            if ($stage === 'status_updated') {
                // A resumed job may have sat overnight. Do not attest approval after a later void/status change.
                $current = $this->crm->offer($email);
                if ($current === null || (string) $this->offerStatus($current, $email) !== (string) $state['target_status']) {
                    $this->ledger->save($email, ['stage' => 'needs_review', 'error_code' => 'status_changed_before_note']);
                    return 'needs_review';
                }
                $this->crm->assertReady($email);
                $this->ledger->save($email, ['stage' => 'note_attempting']);
                $this->crm->addNote($email);
                $this->ledger->save($email, ['stage' => 'notification_ready']);
                $stage = 'notification_ready';
            }
            if ($stage === 'notification_ready') {
                $this->mail->assertReady();
                $this->ledger->save($email, ['stage' => 'mail_attempting']);
                $result = $state['result'] ?? 'completed';
                $this->mail->send('Client Approved Settlement' . ($result === 'skipped' ? ' - Skipped' : ''), $body);
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
