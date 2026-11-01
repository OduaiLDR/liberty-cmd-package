<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

use RuntimeException;

/** Existing TblReports distribution lists and Lending Tower's existing Graph sender. */
final class SmsTableRunEmailGateway implements SmsRunEmailGateway
{
    public function __construct(private ?DBConnector $connector = null, private ?EmailSenderService $sender = null) {}

    public function configuration(): array
    {
        $report = config('sms-exports.email_report_name', 'SMS Export');
        $company = config('sms-exports.email_report_company');
        if (! is_string($report) || $report === '' || $report !== trim($report) || strlen($report) > 128
            || preg_match('/[\x00-\x1f\x7f]/', $report)
            || ! is_string($company) || $company === '' || $company !== trim($company) || strlen($company) > 128
            || preg_match('/[\x00-\x1f\x7f]/', $company)) {
            throw new RuntimeException('Configure an explicit SMS email report name and company before sending.');
        }
        $source = config('sms-exports.email_report_source', 'plaw');
        if (! is_string($source) || ! in_array($source, ['ldr', 'plaw'], true)) {
            throw new RuntimeException('Configure a supported source for the shared SMS email distribution table.');
        }
        if ($this->connector === null) {
            $connector = DBConnector::fromEnvironment($source);
            // fromEnvironment configures Snowflake only. The shared recipient
            // reader requires an explicitly initialized SQL Server connection.
            $connector->initializeSqlServer();
            $this->connector = $connector;
        }
        $this->sender ??= new EmailSenderService('GRAPH_LT');
        $groups = $this->sender->recipientGroupsForReports($this->connector, [$report], [$company], true);
        $configuration = ['from' => 'admin@lendingtower.com', 'to' => $groups['to'], 'cc' => $groups['cc'],
            'bcc' => $groups['bcc'], 'report_name' => $report, 'companies' => [$company]];
        SmsRunEmailMessage::assertConfiguration($configuration);
        return $configuration;
    }

    public function assertReady(): void
    {
        if (! GraphMailboxClient::isConfigured('GRAPH_LT')) {
            throw new RuntimeException('Lending Tower Graph mail configuration is incomplete; no SMS email was attempted.');
        }
    }

    public function send(array $message): bool
    {
        SmsRunEmailMessage::validate($message, (array) config('sms-exports.email_allowed_link_hosts', []));
        $this->assertReady();
        $this->sender ??= new EmailSenderService('GRAPH_LT');
        // Never resolve TblReports again here: the ledger owns the exact recipients/content being attempted.
        return $this->sender->sendMailHtml($message['subject'], $message['html'], $message['to'], $message['cc'],
            $message['bcc'], [], $message['from']);
    }
}
