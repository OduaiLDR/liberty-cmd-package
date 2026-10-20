<?php

declare(strict_types=1);

namespace Cmd\Reports\SettlementApprovalEmail;

use LogicException;

/** Saved-offer fixtures are preview-only; never an authority for live CRM writes. */
final class SnapshotApprovalGateway implements ApprovalGateway
{
    public function __construct(private ?array $snapshot) {}
    public function offer(ApprovalEmail $email): ?array { return $this->snapshot; }
    public function statusId(ApprovalEmail $email, string $label): string { throw new LogicException('Snapshot is read-only.'); }
    public function assertReady(ApprovalEmail $email): void { throw new LogicException('Snapshot is read-only.'); }
    public function updateStatus(ApprovalEmail $email, string $statusId): void { throw new LogicException('Snapshot is read-only.'); }
    public function addNote(ApprovalEmail $email): void { throw new LogicException('Snapshot is read-only.'); }
}
