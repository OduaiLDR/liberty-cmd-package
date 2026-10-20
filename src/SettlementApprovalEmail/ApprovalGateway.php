<?php

declare(strict_types=1);

namespace Cmd\Reports\SettlementApprovalEmail;

interface ApprovalGateway
{
    public function offer(ApprovalEmail $email): ?array;
    public function statusId(ApprovalEmail $email, string $label): string;
    public function assertReady(ApprovalEmail $email): void;
    public function updateStatus(ApprovalEmail $email, string $statusId): void;
    public function addNote(ApprovalEmail $email): void;
}
