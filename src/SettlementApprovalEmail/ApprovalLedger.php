<?php

declare(strict_types=1);

namespace Cmd\Reports\SettlementApprovalEmail;

interface ApprovalLedger
{
    /** Return null when another worker owns the settlement. */
    public function claim(ApprovalEmail $email): ?array;
    public function save(ApprovalEmail $email, array $changes): void;
    public function release(ApprovalEmail $email): void;
}
