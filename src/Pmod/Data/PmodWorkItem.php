<?php

declare(strict_types=1);

namespace Cmd\Reports\Pmod\Data;

use Cmd\Reports\Pmod\Enums\PmodActionType;
use Cmd\Reports\Pmod\Enums\PmodCompany;

final class PmodWorkItem
{
    /**
     * @param array<string, mixed> $rawPayload
     * @param array<string, mixed> $normalizedPayload
     * @param list<string> $settlementIds
     * @param list<string> $originalDates
     * @param list<string> $targetDates
     * @param list<string> $amounts
     * @param array<int, array<string, mixed>> $attachments
     * @param array<string, mixed> $paymentChange
     * @param array<string, mixed> $bankingUpdate
     * @param array<string, mixed> $creditorChange
     * @param array<string, mixed> $sponsorUpdate
     * @param array<string, mixed> $executionOptions
     */
    public function __construct(
        public readonly string $requestId,
        public readonly string $tenantId,
        public readonly string $source,
        public readonly PmodCompany $company,
        public readonly PmodActionType $actionType,
        public readonly string $requestedBy,
        public readonly string $contactId,
        public readonly string $idempotencyKey,
        public readonly string $receivedAt,
        public readonly array $rawPayload,
        public readonly array $normalizedPayload,
        public readonly array $settlementIds = [],
        public readonly array $originalDates = [],
        public readonly array $targetDates = [],
        public readonly array $amounts = [],
        public readonly ?string $amount = null,
        public readonly ?string $increaseAmount = null,
        public readonly ?string $totalAmount = null,
        public readonly ?string $frequency = null,
        public readonly array $attachments = [],
        public readonly array $paymentChange = [],
        public readonly array $bankingUpdate = [],
        public readonly array $creditorChange = [],
        public readonly array $sponsorUpdate = [],
        public readonly array $executionOptions = [],
        public readonly bool $dryRun = false,
    ) {
    }

    public function queueKey(): string
    {
        return sprintf(
            'pmod:%s:%s:%s:%s',
            $this->company->value,
            $this->actionType->value,
            $this->contactId,
            $this->idempotencyKey,
        );
    }
}
