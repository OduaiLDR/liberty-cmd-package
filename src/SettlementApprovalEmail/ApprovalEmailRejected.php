<?php

declare(strict_types=1);

namespace Cmd\Reports\SettlementApprovalEmail;

use InvalidArgumentException;

/** Expected input rejection. Diagnostics contain field names and rules, never email values. */
final class ApprovalEmailRejected extends InvalidArgumentException
{
    public function __construct(public readonly string $reason, public readonly array $fields = [])
    {
        parent::__construct(match ($reason) {
            'unrecognized_email' => 'Not a recognized settlement approval email.',
            'conflicting_fields' => 'Conflicting settlement approval fields.',
            'invalid_fields' => 'Approval email requires numeric customer/settlement IDs, creditor and four account digits.',
            default => throw new InvalidArgumentException('Unknown approval rejection reason.'),
        });
    }
}
