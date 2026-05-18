<?php

declare(strict_types=1);

namespace Cmd\Reports\Pmod\Services;

use Cmd\Reports\Pmod\Contracts\PmodExecutionGateway;
use Cmd\Reports\Pmod\Data\PmodWorkItem;
use Cmd\Reports\Pmod\Support\PmodBusinessDateResolver;
use Modules\Services\DPP\Contact\DppContactService;
use Modules\Services\DPP\Servicing\DppServicingService;
use Modules\Services\ForthPay\Draft\ForthPayDraftService;

final class DebtPletePmodExecutionGateway implements PmodExecutionGateway
{
    public function __construct(
        private readonly DppContactService $contactService,
        private readonly DppServicingService $servicingService,
        private readonly ForthPayDraftService $draftService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function createContactNote(PmodWorkItem $workItem, string $content, bool $public = true): array
    {
        $noteData = $this->contactService->createNote(
            contactId: $workItem->contactId,
            content: $content,
            public: $public,
        );

        return [
            'success' => true,
            'note_id' => $noteData->note_id ?? null,
            'contact_id' => $workItem->contactId,
            'response' => method_exists($noteData, 'toArray') ? $noteData->toArray() : (array) $noteData,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createDraft(PmodWorkItem $workItem, array $payload): array
    {
        $payload = $this->withBusinessProcessDate($payload);

        $response = $this->callWithDateRetry($payload, function (array $attemptPayload) use ($workItem): array {
            return $this->draftService->createDraft([
                'client_id' => $attemptPayload['client_id'] ?? $workItem->contactId,
                'amount' => (float) $attemptPayload['amount'],
                'process_date' => $attemptPayload['process_date'],
                'memo' => $attemptPayload['memo'] ?? '',
                'type' => $attemptPayload['type'] ?? 'monthly_draft',
            ]);
        });

        return [
            'success' => true,
            'draft_id' => $response['response']['id'] ?? $response['id'] ?? null,
            'response' => $response,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getContactTransactions(PmodWorkItem $workItem): array
    {
        $response = $this->servicingService->getContactTransactions(
            contactId: $workItem->contactId,
        );

        $transactions = [];
        foreach ($response as $transaction) {
            $transactions[] = [
                'draft_id' => $transaction['id'] ?? $transaction['draft_id'] ?? null,
                'process_date' => $transaction['process_date'] ?? $transaction['date'] ?? null,
                'amount' => $transaction['amount'] ?? null,
                'status' => $transaction['status'] ?? null,
                'type' => $transaction['type'] ?? null,
                'memo' => $transaction['memo'] ?? null,
                'raw' => $transaction,
            ];
        }

        return $transactions;
    }

    public function getContactDebts(PmodWorkItem $workItem): array
    {
        return [];
    }

    public function getContactSummary(PmodWorkItem $workItem): array
    {
        return ['contact_id' => $workItem->contactId];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function updateDraft(PmodWorkItem $workItem, string $draftId, array $payload): array
    {
        $updatePayload = [];

        foreach (['client_id', 'amount', 'process_date', 'memo'] as $field) {
            if (array_key_exists($field, $payload)) {
                $updatePayload[$field] = $field === 'amount' ? (float) $payload[$field] : $payload[$field];
            }
        }

        $updatePayload = $this->withBusinessProcessDate($updatePayload);
        $response = $this->callWithDateRetry($updatePayload, fn (array $attemptPayload): array => $this->draftService->updateDraft($draftId, $attemptPayload));

        return [
            'success' => true,
            'draft_id' => $draftId,
            'response' => $response,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function voidSettlementOffer(PmodWorkItem $workItem, string $settlementId): array
    {
        $response = $this->servicingService->voidSettlementOffer(
            settlementId: $settlementId,
        );

        return [
            'success' => true,
            'settlement_id' => $settlementId,
            'response' => $response,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function withBusinessProcessDate(array $payload): array
    {
        if (! empty($payload['process_date'])) {
            $payload['process_date'] = PmodBusinessDateResolver::nextBusinessDay((string) $payload['process_date']);
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     * @param callable(array<string, mixed>): array<string, mixed> $callback
     * @return array<string, mixed>
     */
    private function callWithDateRetry(array $payload, callable $callback): array
    {
        $last = null;

        for ($attempt = 1; $attempt <= 7; $attempt++) {
            try {
                return $callback($payload);
            } catch (\Throwable $e) {
                $last = $e;
                if (empty($payload['process_date']) || ! PmodBusinessDateResolver::looksLikeDateRejection($e->getMessage())) {
                    throw $e;
                }

                $payload['process_date'] = PmodBusinessDateResolver::nextBusinessDay(
                    PmodBusinessDateResolver::nextDay((string) $payload['process_date'])
                );
            }
        }

        throw $last ?? new \RuntimeException('Date retry failed.');
    }

    public function cancelDraft(PmodWorkItem $workItem, string $draftId): array
    {
        $response = $this->draftService->cancelUnprocessedDraft($draftId);

        return [
            'success' => true,
            'draft_id' => $draftId,
            'response' => $response,
        ];
    }

    public function createRefund(PmodWorkItem $workItem, array $payload): array
    {
        $draftId = trim((string) ($payload['draft_id'] ?? ''));
        if ($draftId === '') {
            throw new \InvalidArgumentException('createRefund requires draft_id.');
        }

        return $this->refundDraft($workItem, $draftId, $payload);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function refundDraft(PmodWorkItem $workItem, string $draftId, array $payload): array
    {
        $response = $this->draftService->cancelUnprocessedDraft($draftId);

        $this->contactService->createNote(
            contactId: $workItem->contactId,
            content: "Draft {$draftId} refunded. Reason: " . ($payload['reason'] ?? 'Payment refund requested'),
            public: true,
        );

        return [
            'success' => true,
            'draft_id' => $draftId,
            'response' => $response,
        ];
    }

    public function addBankAccount(PmodWorkItem $workItem, array $payload): array
    {
        throw new \RuntimeException('Bank account updates require the DocuSign banking pipeline.');
    }

    public function uploadContactDocument(PmodWorkItem $workItem, string $base64Content, string $fileName, string $description): array
    {
        throw new \RuntimeException('Contact document upload is not implemented in the DebtPlete PMOD gateway.');
    }

    public function createDebt(PmodWorkItem $workItem, array $payload): array
    {
        throw new \RuntimeException('Creditor PMOD actions are not ready for automation.');
    }

    public function cancelDebt(PmodWorkItem $workItem, string $debtId): array
    {
        throw new \RuntimeException('Creditor PMOD actions are not ready for automation.');
    }
}
