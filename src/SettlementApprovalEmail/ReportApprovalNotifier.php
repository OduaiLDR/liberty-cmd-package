<?php

declare(strict_types=1);

namespace Cmd\Reports\SettlementApprovalEmail;

use Cmd\Reports\Services\DBConnector;
use Cmd\Reports\Services\EmailSenderService;
use RuntimeException;

final class ReportApprovalNotifier implements ApprovalNotifier
{
    private ?array $recipients = null;

    public function __construct(private DBConnector $sql, private EmailSenderService $sender) {}

    public function assertReady(): void
    {
        $this->recipients ??= $this->sender->recipientGroupsForReports($this->sql, ['Settlement Approval', 'Client Approved Settlement']);
        if (!array_filter($this->recipients)) throw new RuntimeException('Configure Settlement Approval recipients in TblReports before live processing.');
        foreach ($this->recipients as $group) {
            foreach ($group as $address) {
                if (!is_string($address) || !filter_var(trim($address), FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException('Settlement Approval recipients contain an invalid email address; correct TblReports before live processing.');
                }
            }
        }
    }

    public function send(string $subject, string $body): void
    {
        $this->assertReady();
        // Preserve the body content without letting an email inject markup into the notification.
        $html = nl2br(htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        if (!$this->sender->sendMailHtml($subject, $html, $this->recipients['to'], $this->recipients['cc'], $this->recipients['bcc'], [], 'admin@libertydebtrelief.com')) {
            throw new RuntimeException('Approval notification delivery is uncertain; reconcile before resending.');
        }
    }
}
