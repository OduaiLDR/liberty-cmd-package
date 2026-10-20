<?php

declare(strict_types=1);

namespace Cmd\Reports\SettlementApprovalEmail;

interface ApprovalNotifier
{
    public function assertReady(): void;
    public function send(string $subject, string $body): void;
}
