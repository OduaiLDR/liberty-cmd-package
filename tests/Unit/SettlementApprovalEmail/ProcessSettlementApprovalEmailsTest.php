<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit\SettlementApprovalEmail;

use Cmd\Reports\Console\Commands\SettlementApprovalEmails\ProcessSettlementApprovalEmails;
use Cmd\Reports\Services\GraphMailboxClient;
use Cmd\Reports\SettlementApprovalEmail\{ApprovalEmail, ApprovalGateway, ApprovalLedger, ApprovalNotifier, ApprovalProcessor, SnapshotApprovalGateway};
use Illuminate\Console\OutputStyle;
use Illuminate\Container\Container;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

final class ProcessSettlementApprovalEmailsTest extends TestCase
{
    private $previousApp;
    private $previousLive;

    protected function setUp(): void
    {
        $this->previousApp = Facade::getFacadeApplication();
        $this->previousLive = Env::getRepository()->get('SETTLEMENT_APPROVAL_EMAIL_LIVE_ENABLED');
        Env::getRepository()->set('SETTLEMENT_APPROVAL_EMAIL_LIVE_ENABLED', 'false');
        $app = new Container();
        $app->instance('db', new class {
            public function connection() { return $this; }
            public function getSchemaBuilder() { return $this; }
            public function hasTable($name) { return true; }
        });
        Facade::clearResolvedInstance('db');
        Facade::setFacadeApplication($app);
    }

    protected function tearDown(): void
    {
        if ($this->previousLive === null) Env::getRepository()->clear('SETTLEMENT_APPROVAL_EMAIL_LIVE_ENABLED');
        else Env::getRepository()->set('SETTLEMENT_APPROVAL_EMAIL_LIVE_ENABLED', $this->previousLive);
        Facade::clearResolvedInstance('db');
        Facade::setFacadeApplication($this->previousApp);
    }

    private function message(string $id = 'one', string $sender = 'no-reply@progresslaw.com', ?string $body = null): array
    {
        return ['id' => $id, 'subject' => 'Settlement Approval Data: 456', 'sender' => ['emailAddress' => ['address' => $sender]],
            'body' => ['contentType' => 'text', 'content' => $body ?? "Settlement Approval For Customer Id: LLG-123\nCreditor Name: Bank\nSettlement ID: 456\nLast 4 Account Number: 0012"]];
    }

    private function command(array $pages): FakeEmailApprovalCommand
    {
        $command = new FakeEmailApprovalCommand();
        $command->mail = new FakeApprovalMailbox($pages);
        $command->flow = new ApprovalProcessor(new SnapshotApprovalGateway(['id' => '456', 'contact_id' => '123', 'offer_status' => 1194, 'json' => ['pay_type' => 15]]));
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput()));
        return $command;
    }

    public function test_default_preview_pages_past_other_routes_without_writes(): void
    {
        $irrelevant = $this->message('other', 'external@example.com');
        $command = $this->command([
            ['messages' => [$irrelevant, $this->message('ldr', 'no-reply@libertydebtrelief.com')], 'next' => 'page-two'],
            ['messages' => [$this->message()], 'next' => null],
        ]);
        $command->options['company'] = 'plaw';
        self::assertSame(0, $command->handle());
        self::assertSame(['one'], $command->mail->fetched);
        self::assertSame([], $command->mail->writes);
        self::assertSame([false], $command->processorCalls);
    }

    public function test_live_switch_is_required_before_any_mailbox_access(): void
    {
        $command = $this->command([]);
        $command->options['live'] = true;
        self::assertSame(1, $command->handle());
        self::assertSame(0, $command->mailboxCalls);
        self::assertSame([], $command->processorCalls);
    }

    public function test_saved_file_cannot_be_used_for_live_mutations(): void
    {
        Env::getRepository()->set('SETTLEMENT_APPROVAL_EMAIL_LIVE_ENABLED', 'true');
        $command = $this->command([]);
        $command->options['live'] = true;
        $command->options['file'] = 'not-read';
        self::assertSame(1, $command->handle());
        self::assertSame(0, $command->mailboxCalls);
    }

    public function test_malformed_email_stays_in_inbox_and_later_valid_message_is_previewed(): void
    {
        $command = $this->command([['messages' => [$this->message('bad', body: 'invalid'), $this->message('good')], 'next' => null]]);
        self::assertSame(1, $command->handle());
        self::assertSame(['bad', 'good'], $command->mail->fetched);
        self::assertSame([], $command->mail->writes);
        self::assertSame([false], $command->processorCalls);
    }

    public function test_html_body_is_normalized_before_parsing(): void
    {
        $message = $this->message();
        $message['body'] = ['contentType' => 'html', 'content' => '<div>Settlement Approval For Customer Id: LLG-123</div><div>Creditor Name: Bank</div><div>Settlement ID: 456</div><div>Last 4 Account Number: 0012</div>'];
        $command = $this->command([['messages' => [$message], 'next' => null]]);
        self::assertSame(0, $command->handle());
    }

    public function test_archive_failure_resumes_without_repeating_crm_or_notification(): void
    {
        Env::getRepository()->set('SETTLEMENT_APPROVAL_EMAIL_LIVE_ENABLED', 'true');
        $command = $this->command([['messages' => [$this->message()], 'next' => null]]);
        $crm = new class implements ApprovalGateway {
            public array $writes = [];
            public int $status = 1194;
            public function offer(ApprovalEmail $e): ?array { return ['id' => '456', 'contact_id' => '123', 'offer_status' => $this->status, 'json' => ['pay_type' => 15]]; }
            public function statusId(ApprovalEmail $e, string $label): string { return '7'; }
            public function assertReady(ApprovalEmail $e): void {}
            public function updateStatus(ApprovalEmail $e, string $id): void { $this->writes[] = 'status'; $this->status = (int) $id; }
            public function addNote(ApprovalEmail $e): void { $this->writes[] = 'note'; }
        };
        $ledger = new class implements ApprovalLedger {
            public array $state = ['stage' => 'ready'];
            public function claim(ApprovalEmail $e): ?array { return $this->state; }
            public function save(ApprovalEmail $e, array $changes): void { $this->state = array_replace($this->state, $changes); }
            public function release(ApprovalEmail $e): void {}
        };
        $mail = new class implements ApprovalNotifier {
            public int $sent = 0;
            public function assertReady(): void {}
            public function send(string $subject, string $body): void { $this->sent++; }
        };
        $command->flow = new ApprovalProcessor($crm, $ledger, $mail);
        $command->options['live'] = true;
        $command->mail->archiveFails = true;
        self::assertSame(1, $command->handle());
        $command->mail->archiveFails = false;
        self::assertSame(0, $command->handle());
        self::assertSame(['status', 'note'], $crm->writes);
        self::assertSame(1, $mail->sent);
        self::assertSame(['read', 'archive', 'read', 'archive'], $command->mail->writes);
    }

    public function test_selected_message_and_invalid_options_are_bounded(): void
    {
        $command = $this->command([['messages' => [$this->message('not-selected'), $this->message('selected')], 'next' => null]]);
        $command->options['message-id'] = 'selected';
        self::assertSame(0, $command->handle());
        self::assertSame(['selected'], $command->mail->fetched);
        $command->options['limit'] = 0;
        self::assertSame(1, $command->handle());
    }

    public function test_real_offline_preview_needs_no_mailbox_database_or_credentials(): void
    {
        $bodyFile = tempnam(sys_get_temp_dir(), 'approval-body-');
        $offerFile = tempnam(sys_get_temp_dir(), 'approval-offer-');
        file_put_contents($bodyFile, $this->message()['body']['content']);
        file_put_contents($offerFile, json_encode(['id' => '456', 'contact_id' => '123', 'offer_status' => 1194, 'json' => ['pay_type' => 21]]));
        try {
            $command = new class extends ProcessSettlementApprovalEmails {
                public array $options = [];
                public function option($key = null) { return $this->options[$key] ?? null; }
                protected function mailbox(): GraphMailboxClient { throw new RuntimeException('Offline preview accessed mailbox.'); }
                protected function sql(): \Cmd\Reports\Services\DBConnector { throw new RuntimeException('Offline preview accessed SQL.'); }
            };
            $command->options = ['company' => 'all', 'limit' => 10, 'file' => $bodyFile, 'sender' => 'no-reply@progresslaw.com', 'offer-file' => $offerFile];
            $output = new BufferedOutput();
            $command->setOutput(new OutputStyle(new ArrayInput([]), $output));
            self::assertSame('settlement-approval:process', $command->getName());
            self::assertSame(0, $command->handle());
            self::assertStringContainsString('PLAW settlement 456: would_approve -> Client complete/ P-Creditor', $output->fetch());
        } finally {
            unlink($bodyFile); unlink($offerFile);
        }
    }

    public function test_actual_cli_dispatch_uses_registered_signature_and_offline_snapshot(): void
    {
        $bodyFile = tempnam(sys_get_temp_dir(), 'approval-cli-body-');
        $offerFile = tempnam(sys_get_temp_dir(), 'approval-cli-offer-');
        file_put_contents($bodyFile, $this->message()['body']['content']);
        file_put_contents($offerFile, json_encode(['id' => '456', 'contact_id' => '123', 'offer_status' => 1194, 'json' => ['pay_type' => 15]]));
        try {
            $command = new ProcessSettlementApprovalEmails();
            $command->setLaravel(new class extends Container {
                public function runningUnitTests(): bool { return true; }
            });
            $app = new \Symfony\Component\Console\Application();
            $app->setAutoExit(false);
            $app->add($command);
            $input = new ArrayInput([
                'command' => 'settlement-approval:process', '--dry-run' => true,
                '--file' => $bodyFile, '--offer-file' => $offerFile,
                '--sender' => 'no-reply@progresslaw.com', '--company' => 'plaw',
            ]);
            $output = new BufferedOutput();
            self::assertSame(0, $app->run($input, $output));
            self::assertStringContainsString('PLAW settlement 456: would_approve -> Accepted', $output->fetch());
        } finally {
            unlink($bodyFile); unlink($offerFile);
        }
    }
}

final class FakeEmailApprovalCommand extends ProcessSettlementApprovalEmails
{
    public array $options = ['live' => false, 'dry-run' => false, 'company' => 'all', 'limit' => 100, 'message-id' => null, 'keep-in-inbox' => false, 'file' => null, 'sender' => null, 'offer-file' => null];
    public FakeApprovalMailbox $mail;
    public ApprovalProcessor $flow;
    public int $mailboxCalls = 0;
    public array $processorCalls = [];
    public function option($key = null) { return $key === null ? $this->options : ($this->options[$key] ?? null); }
    protected function mailbox(): GraphMailboxClient { $this->mailboxCalls++; return $this->mail; }
    protected function processor(bool $live): ApprovalProcessor { $this->processorCalls[] = $live; return $this->flow; }
}

final class FakeApprovalMailbox extends GraphMailboxClient
{
    public array $fetched = [];
    public array $writes = [];
    public bool $archiveFails = false;
    public function __construct(private array $pages) {}
    public function approvalInboxPage(string $mailbox, string $subjectPrefix, ?string $nextLink = null): array { return $this->pages[$nextLink === null ? 0 : 1]; }
    public function approvalMessage(string $mailbox, string $messageId): array
    {
        $this->fetched[] = $messageId;
        foreach ($this->pages as $page) foreach ($page['messages'] as $message) if ($message['id'] === $messageId) return $message;
        throw new RuntimeException('missing mock message');
    }
    public function markRead(string $mailbox, string $messageId): void { $this->writes[] = 'read'; }
    public function moveToArchive(string $mailbox, string $messageId): void
    {
        $this->writes[] = 'archive';
        if ($this->archiveFails) throw new RuntimeException('archive failed');
    }
}
