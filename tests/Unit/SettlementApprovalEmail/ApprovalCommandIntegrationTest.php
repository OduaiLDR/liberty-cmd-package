<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit\SettlementApprovalEmail;

use Cmd\Reports\Console\Commands\SettlementApprovalEmails\ProcessSettlementApprovalEmails;
use Cmd\Reports\Services\{DBConnector, EmailSenderService, GraphMailboxClient};
use Cmd\Reports\SettlementApprovalEmail\{ApprovalEmail, ApprovalProcessor, DatabaseApprovalLedger, ForthApprovalGateway, ReportApprovalNotifier};
use GuzzleHttp\{Client, HandlerStack};
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Log\AbstractLogger;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Dispatches the real command with all four production adapters assembled.
 * Only the SQL boundary and HTTP transport are replaced; no socket is opened.
 */
final class ApprovalCommandIntegrationTest extends TestCase
{
    private mixed $previousApp;
    private mixed $previousLive;
    private Container $app;
    private Manager $database;
    private ApprovalTestLogger $logger;

    protected function setUp(): void
    {
        $this->previousApp = Facade::getFacadeApplication();
        $this->previousLive = Env::getRepository()->get('SETTLEMENT_APPROVAL_EMAIL_LIVE_ENABLED');
        Env::getRepository()->set('SETTLEMENT_APPROVAL_EMAIL_LIVE_ENABLED', 'true');
        $this->app = new class extends Container {
            public function runningUnitTests(): bool { return true; }
        };
        $this->database = new Manager($this->app);
        $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->app->instance('db', $this->database->getDatabaseManager());
        $this->app->instance('db.schema', $this->database->getConnection()->getSchemaBuilder());
        $this->logger = new ApprovalTestLogger();
        $this->app->instance('log', $this->logger);
        foreach (['db', 'db.schema', 'log'] as $accessor) Facade::clearResolvedInstance($accessor);
        Facade::setFacadeApplication($this->app);
        $migration = require dirname(__DIR__, 3) . '/database/settlement-approval-email-migrations/2026_10_19_000001_create_settlement_approval_email_jobs.php';
        $migration->up();
    }

    protected function tearDown(): void
    {
        $this->database->getConnection()->disconnect();
        if ($this->previousLive === null) Env::getRepository()->clear('SETTLEMENT_APPROVAL_EMAIL_LIVE_ENABLED');
        else Env::getRepository()->set('SETTLEMENT_APPROVAL_EMAIL_LIVE_ENABLED', $this->previousLive);
        foreach (['db', 'db.schema', 'log'] as $accessor) Facade::clearResolvedInstance($accessor);
        Facade::setFacadeApplication($this->previousApp);
    }

    public static function approvalBranches(): array
    {
        return [
            'LDR accepted' => ['LDR', 15, '7'],
            'LDR creditor completion' => ['LDR', 21, '99'],
            'Progress accepted' => ['PLAW', 132, '17'],
            'Progress creditor completion' => ['PLAW', 20, '199'],
        ];
    }

    #[DataProvider('approvalBranches')]
    public function test_dispatch_updates_notes_notifies_and_archives_with_real_adapters(string $company, int $paymentType, string $target): void
    {
        $transport = new ApprovalIntegrationTransport($company, $paymentType);
        [$command, $sql] = $this->command($transport);
        [$exit, $output] = $this->dispatch($command, $company);

        self::assertSame(0, $exit, $output);
        self::assertStringContainsString($company . ' settlement 456: completed', $output);
        self::assertSame($target, $transport->status);
        self::assertSame(['status', 'note', 'notification', 'read', 'archive'], $transport->effects);
        self::assertSame(['status_id' => $target], $transport->statusPayload);
        self::assertSame([
            'content' => '#0012' . ($company === 'PLAW' ? ' - CLIENT AUTHORIZATION OBTAINED - ' : ' - CLIENT AUTHORIZATION OBTAINED- ') . 'Client approved the settlement',
            'note_type' => 1, 'public' => false,
        ], $transport->notePayload);
        self::assertTrue($transport->archived);
        $this->assertCompletedLedger($company);

        // Exercise actual TblReports discovery, list parsing and To > CC > BCC deduplication.
        self::assertCount(2, $sql->queries);
        self::assertStringContainsString('INFORMATION_SCHEMA.COLUMNS', $sql->queries[0]);
        self::assertStringContainsString("'Settlement Approval'", $sql->queries[1]);
        self::assertStringContainsString("'Client Approved Settlement'", $sql->queries[1]);
        $mail = $transport->mailPayload['message'];
        self::assertSame('Client Approved Settlement', $mail['subject']);
        self::assertSame('HTML', $mail['body']['contentType']);
        self::assertStringContainsString('Bank &lt;script&gt; &amp; Co', $mail['body']['content']);
        self::assertStringNotContainsString('<script>', $mail['body']['content']);
        self::assertSame([['emailAddress' => ['address' => 'First@example.com']]], $mail['toRecipients']);
        self::assertSame([['emailAddress' => ['address' => 'second@example.com']]], $mail['ccRecipients']);
        self::assertSame([['emailAddress' => ['address' => 'third@example.com']]], $mail['bccRecipients']);
        self::assertSame('/v1.0/users/admin@libertydebtrelief.com/sendMail', $transport->sendPath);
        self::assertSame(['destinationId' => 'archive'], $transport->archivePayload);
        self::assertSame(['isRead' => true], $transport->readPayload);
        self::assertSame(['fake-forth-' . $company], array_values(array_unique($transport->forthKeys)));
        $events = array_column($this->logger->records, 'message');
        $expectedSteps = [
            'status_update_started', 'status_verified', 'note_create_started', 'note_created',
            'notification_send_started', 'notification_accepted', 'message_marked_read', 'message_archived',
        ];
        $positions = array_map(fn ($step) => array_search('settlement_approval.' . $step, $events, true), $expectedSteps);
        self::assertNotContains(false, $positions);
        $sorted = $positions;
        sort($sorted);
        self::assertSame($sorted, $positions, 'The audit sequence must match the actual effect order.');
        $summary = $this->logger->last('run_finished')['context'];
        self::assertSame(1, $summary['counts']['completed']);
        self::assertSame(1, $summary['counts']['archived']);
        self::assertSame(0, $summary['counts']['errors']);
    }

    #[DataProvider('approvalBranches')]
    public function test_preview_reads_nested_forth_status_without_side_effects(string $company, int $paymentType, string $target): void
    {
        $transport = new ApprovalIntegrationTransport($company, $paymentType);
        [$command, $sql] = $this->command($transport);
        [$exit, $output] = $this->dispatch($command, $company, live: false);

        self::assertSame(0, $exit, $output);
        $label = in_array($paymentType, [15, 16, 132], true) ? 'Accepted' : 'Client complete/ P-Creditor';
        self::assertStringContainsString('would_approve -> ' . $label, $output);
        self::assertSame([], $transport->effects);
        self::assertSame([], $sql->queries);
        self::assertSame(0, $this->database->getConnection()->table(DatabaseApprovalLedger::TABLE)->count());
        self::assertFalse($transport->archived);
    }

    public function test_preview_skips_nested_ineligible_status_without_reading_payment_details(): void
    {
        $transport = new ApprovalIntegrationTransport('LDR', 15);
        $transport->status = '2075';
        $transport->omitPaymentType = true;
        [$command] = $this->command($transport);
        [$exit, $output] = $this->dispatch($command, 'LDR', live: false);

        self::assertSame(0, $exit, $output);
        self::assertStringContainsString('would_skip', $output);
        self::assertStringContainsString('current_status=2075 required_status=1194 reason=status_not_1194', $output);
        self::assertSame([], $transport->effects);
        self::assertSame(0, $this->database->getConnection()->table(DatabaseApprovalLedger::TABLE)->count());
        $decision = $this->logger->last('approval_decision')['context'];
        self::assertSame(2075, $decision['current_status']);
        self::assertSame(1194, $decision['required_status']);
        self::assertSame('status_not_1194', $decision['reason']);
        self::assertSame(1, $this->logger->last('run_finished')['context']['counts']['would_skip']);
    }

    public function test_plaw_preview_reaches_the_next_page_after_fifty_ldr_messages(): void
    {
        $transport = new ApprovalIntegrationTransport('PLAW', 132);
        // Path captured on the target CMD Runner; query values are synthetic.
        $transport->approvalNextLink = "https://graph.microsoft.com/v1.0/users/automation@libertydebtrelief.com/mailFolders('inbox')/messages"
            . '?%24select=id%2CinternetMessageId%2Csubject%2Csender%2Cfrom&%24top=50&%24filter=startswith%28subject%2C%27Settlement%20Approval%20Data%3A%27%29&%24skip=50';
        [$command, $sql] = $this->command($transport);
        [$exit, $output] = $this->dispatch($command, 'PLAW', live: false, limit: 5, verbose: true);

        self::assertSame(0, $exit, $output);
        self::assertStringContainsString('PLAW settlement 456: would_approve -> Accepted', $output);
        self::assertStringNotContainsString('LDR settlement', $output);
        self::assertCount(2, $transport->approvalPageUrls);
        self::assertSame($transport->approvalNextLink, $transport->approvalPageUrls[1]);
        self::assertSame(['fake-forth-PLAW'], $transport->forthKeys);
        self::assertSame([], $transport->effects);
        self::assertSame([], $sql->queries);
        self::assertSame(0, $this->database->getConnection()->table(DatabaseApprovalLedger::TABLE)->count());
        self::assertStringContainsString('[settlement-approval] inbox_page_received', $output);
        self::assertStringContainsString('"payment_type":132', $output);
        self::assertStringContainsString('Summary: pages=2 scanned=51 selected=1 filtered=50', $output);
        $summary = $this->logger->last('run_finished')['context'];
        self::assertSame(2, $summary['counts']['pages']);
        self::assertSame(50, $summary['counts']['filtered']);
        self::assertSame(1, $summary['counts']['kept_in_inbox']);
        foreach (['original-message', 'Bank <script>', 'Last 4 Account Number', 'LLG-123', 'fixture-secret', '%24skip=50'] as $privateValue) {
            self::assertStringNotContainsString($privateValue, $output);
            self::assertStringNotContainsString($privateValue, json_encode($this->logger->records));
        }
        self::assertCount(1, array_unique(array_column(array_column($this->logger->records, 'context'), 'run_id')));
    }

    public function test_archive_failure_then_duplicate_message_does_not_repeat_any_crm_or_mail_effect(): void
    {
        $transport = new ApprovalIntegrationTransport('LDR', 16);
        $transport->failArchiveOnce = true;
        [$command] = $this->command($transport);
        [$exit, $output] = $this->dispatch($command, 'LDR');
        self::assertSame(1, $exit, $output);
        self::assertFalse($transport->archived);
        $this->assertCompletedLedger('LDR');
        $failure = $this->logger->last('message_failed')['context'];
        self::assertSame('message_archive_started', $failure['stage']);
        self::assertSame(503, $failure['http_status']);
        self::assertSame(1, $this->logger->last('run_finished')['context']['counts']['errors']);
        $forthReads = count($transport->forthKeys);

        [$exit, $output] = $this->dispatch($command, 'LDR');
        self::assertSame(0, $exit, $output);
        self::assertTrue($transport->archived);

        // A duplicate system email gets a different Graph/internetMessageId.
        $transport->archived = false;
        $transport->messageId = 'duplicate-message';
        [$exit, $output] = $this->dispatch($command, 'LDR');
        self::assertSame(0, $exit, $output);
        self::assertTrue($transport->archived);
        self::assertSame($forthReads, count($transport->forthKeys), 'Completed jobs must not call Forth again.');
        self::assertSame(['status', 'note', 'notification', 'read', 'archive_failed', 'read', 'archive', 'read', 'archive'], $transport->effects);
        self::assertSame(1, $this->database->getConnection()->table(DatabaseApprovalLedger::TABLE)->count());
        self::assertSame('previously_processed', $this->logger->last('ledger_terminal')['context']['reason']);
        $runs = array_filter($this->logger->records, fn ($record) => $record['message'] === 'settlement_approval.run_started');
        self::assertCount(3, array_unique(array_column(array_column($runs, 'context'), 'run_id')));
    }

    public function test_pagination_error_has_a_summary_without_exposing_the_next_link(): void
    {
        $transport = new ApprovalIntegrationTransport('PLAW', 132);
        $transport->approvalNextLink = 'https://untrusted.example/inbox?token=private-cursor';
        [$command] = $this->command($transport);
        [$exit, $output] = $this->dispatch($command, 'PLAW', live: false, verbose: true);

        self::assertSame(1, $exit);
        self::assertStringContainsString('Unexpected Graph approval pagination URL.', $output);
        self::assertStringContainsString('stage=inbox_page_requested', $output);
        self::assertStringNotContainsString('private-cursor', $output);
        self::assertStringNotContainsString('untrusted.example', json_encode($this->logger->records));
        $summary = $this->logger->last('run_finished')['context'];
        self::assertSame('error', $summary['stop_reason']);
        self::assertSame(1, $summary['counts']['pages']);
        self::assertSame(50, $summary['counts']['scanned']);
        self::assertSame(0, $summary['counts']['selected']);
        self::assertSame(1, $summary['counts']['errors']);
        self::assertSame([], $transport->effects);
    }

    public function test_verbose_http_failure_redacts_provider_details_and_identifies_the_message(): void
    {
        $transport = new ApprovalIntegrationTransport('LDR', 15);
        $transport->failArchiveOnce = true;
        [$command] = $this->command($transport);
        [$exit, $output] = $this->dispatch($command, 'LDR', verbose: true);

        self::assertSame(1, $exit);
        $failure = $this->logger->last('message_failed')['context'];
        self::assertSame('message_archive_started', $failure['stage']);
        self::assertSame('456', $failure['settlement_id']);
        self::assertSame(substr(hash('sha256', 'original-message'), 0, 16), $failure['message_ref']);
        self::assertSame(503, $failure['http_status']);
        foreach (['private-provider-details', 'original-message', 'fixture-token', 'fixture-secret', 'Bank <script>', 'LLG-123'] as $value) {
            self::assertStringNotContainsString($value, $output);
            self::assertStringNotContainsString($value, json_encode($this->logger->records));
        }
        self::assertSame(1, $this->logger->last('run_finished')['context']['counts']['kept_in_inbox']);
    }

    public function test_approval_log_sink_failure_does_not_interrupt_or_duplicate_live_effects(): void
    {
        $this->logger->fail = true;
        $transport = new ApprovalIntegrationTransport('LDR', 15);
        [$command] = $this->command($transport);
        [$exit, $output] = $this->dispatch($command, 'LDR', verbose: true);

        self::assertSame(0, $exit, $output);
        self::assertSame(['status', 'note', 'notification', 'read', 'archive'], $transport->effects);
        $this->assertCompletedLedger('LDR');
        self::assertSame(1, substr_count($output, 'Settlement approval logging unavailable'));
        self::assertStringContainsString('[settlement-approval] run_finished', $output);
        self::assertStringNotContainsString('private-logger-details', $output);
    }

    public function test_empty_preview_has_zero_counts_and_no_offer_reads(): void
    {
        $transport = new ApprovalIntegrationTransport('PLAW', 15);
        $transport->archived = true;
        [$command] = $this->command($transport);
        [$exit, $output] = $this->dispatch($command, 'PLAW', live: false);

        self::assertSame(0, $exit);
        self::assertStringContainsString('Summary: pages=1 scanned=0 selected=0', $output);
        self::assertSame([], $transport->forthKeys);
        self::assertSame([], $transport->effects);
        self::assertSame('exhausted', $this->logger->last('run_finished')['context']['stop_reason']);
    }

    public function test_failed_notification_keeps_message_and_uncertain_send_is_not_replayed(): void
    {
        $transport = new ApprovalIntegrationTransport('PLAW', 15);
        $transport->failNotification = true;
        [$command] = $this->command($transport);
        [$exit] = $this->dispatch($command, 'PLAW');
        self::assertSame(1, $exit);
        self::assertSame('mail_attempting', $this->database->getConnection()->table(DatabaseApprovalLedger::TABLE)->value('stage'));
        self::assertFalse($transport->archived);

        $transport->failNotification = false;
        [$exit, $output] = $this->dispatch($command, 'PLAW');
        self::assertSame(1, $exit, $output);
        self::assertStringContainsString('needs_review', $output);
        self::assertSame('needs_review', $this->database->getConnection()->table(DatabaseApprovalLedger::TABLE)->value('stage'));
        self::assertSame(['status', 'note', 'notification'], $transport->effects);
        self::assertFalse($transport->archived);
    }

    public function test_invalid_report_recipients_block_business_updates_before_status_put(): void
    {
        $transport = new ApprovalIntegrationTransport('LDR', 15);
        [$command, $sql] = $this->command($transport);
        $sql->recipients = ['Send_To' => 'not-an-email', 'Send_CC' => '', 'Send_BCC' => ''];
        [$exit, $output] = $this->dispatch($command, 'LDR');
        self::assertSame(1, $exit, $output);
        self::assertSame([], $transport->effects);
        self::assertSame('1194', $transport->status);
        self::assertFalse($transport->archived);
        self::assertSame('ready', $this->database->getConnection()->table(DatabaseApprovalLedger::TABLE)->value('stage'));
    }

    private function assertCompletedLedger(string $company): void
    {
        $row = $this->database->getConnection()->table(DatabaseApprovalLedger::TABLE)->first();
        self::assertSame($company, $row->company);
        self::assertSame('completed', $row->stage);
        self::assertSame('completed', $row->result);
        self::assertNull($row->lease_token);
        self::assertNull($row->lease_until);
    }

    private function command(ApprovalIntegrationTransport $transport): array
    {
        $http = new Client(['handler' => HandlerStack::create(
            static fn (RequestInterface $request, array $options) => Create::promiseFor($transport->respond($request))
        )]);
        $sql = new class extends DBConnector {
            public array $queries = [];
            public array $recipients = ['Send_To' => 'First@example.com; first@example.com', 'Send_CC' => 'first@example.com; second@example.com', 'Send_BCC' => 'second@example.com; third@example.com'];
            public function __construct() {}
            public function querySqlServer(string $sql, array $params = []): array
            {
                $this->queries[] = $sql;
                if (str_contains($sql, 'INFORMATION_SCHEMA.COLUMNS')) {
                    return ['success' => true, 'data' => array_map(fn ($name) => ['COLUMN_NAME' => $name], ['Report_Name', 'Send_To', 'Send_CC', 'Send_BCC'])];
                }
                if (str_contains($sql, 'FROM dbo.TblReports')) {
                    return ['success' => true, 'data' => [$this->recipients]];
                }
                throw new RuntimeException('Unexpected SQL query in offline integration test.');
            }
        };
        // Keep the real notification adapter and formatting implementation; inject only its transport/config.
        $reflection = new ReflectionClass(EmailSenderService::class);
        $sender = $reflection->newInstanceWithoutConstructor();
        foreach (['client' => $http, 'tenantId' => 'fixture-tenant', 'clientId' => 'fixture-client', 'clientSecret' => 'fixture-secret', 'fromAddress' => 'unused@example.com'] as $key => $value) {
            $reflection->getProperty($key)->setValue($sender, $value);
        }
        $mailbox = new GraphMailboxClient('fixture-tenant', 'fixture-client', 'fixture-secret', $http);
        $crm = new ForthApprovalGateway($http, fn ($company) => 'fake-forth-' . $company, [
            'LDR' => ['type' => 1, 'public' => false], 'PLAW' => ['type' => 1, 'public' => false],
        ]);
        $flow = new ApprovalProcessor($crm, new DatabaseApprovalLedger($this->database->getConnection()), new ReportApprovalNotifier($sql, $sender));
        $command = new class($mailbox, $flow) extends ProcessSettlementApprovalEmails {
            public function __construct(private GraphMailboxClient $testMailbox, private ApprovalProcessor $testFlow) { parent::__construct(); }
            protected function mailbox(): GraphMailboxClient { return $this->testMailbox; }
            protected function processor(bool $live): ApprovalProcessor { return $this->testFlow; }
        };
        $command->setLaravel($this->app);
        return [$command, $sql];
    }

    private function dispatch(ProcessSettlementApprovalEmails $command, string $company, bool $live = true, int $limit = 100, bool $verbose = false): array
    {
        $console = new Application();
        $console->setAutoExit(false);
        $console->add($command);
        $output = new BufferedOutput($verbose ? BufferedOutput::VERBOSITY_VERBOSE : BufferedOutput::VERBOSITY_NORMAL);
        $exit = $console->run(new ArrayInput(['command' => 'settlement-approval:process', $live ? '--live' : '--dry-run' => true, '--company' => strtolower($company), '--limit' => $limit]), $output);
        return [$exit, $output->fetch()];
    }
}

/** A fully closed HTTP boundary. Unknown hosts, methods and paths always fail the test. */
final class ApprovalIntegrationTransport
{
    public string $status = '1194';
    public string $messageId = 'original-message';
    public bool $archived = false;
    public bool $failArchiveOnce = false;
    public bool $failNotification = false;
    public bool $omitPaymentType = false;
    public ?string $approvalNextLink = null;
    public array $approvalPageUrls = [];
    public array $effects = [];
    public array $statusPayload = [];
    public array $notePayload = [];
    public array $mailPayload = [];
    public array $readPayload = [];
    public array $archivePayload = [];
    public array $forthKeys = [];
    public string $sendPath = '';

    public function __construct(private string $company, private int $paymentType) {}

    public function respond(RequestInterface $request): Response
    {
        $method = $request->getMethod();
        $host = $request->getUri()->getHost();
        $path = rawurldecode($request->getUri()->getPath());
        $payload = json_decode((string) $request->getBody(), true) ?? [];
        if ($host === 'login.microsoftonline.com' && $method === 'POST' && $path === '/fixture-tenant/oauth2/v2.0/token') {
            return $this->json(['access_token' => 'fixture-token']);
        }
        if ($host === 'api.forthcrm.com') {
            $this->forthKeys[] = $request->getHeaderLine('Api-Key');
            if ($method === 'GET' && $path === '/v1/settlement_offers/456') {
                // Forth's live offer response nests the numeric ID under status_id.
                return $this->json(['response' => [
                    'id' => '456', 'contact_id' => '123',
                    'offer_status' => ['object' => 'settlement_offer_status', 'status_id' => $this->status, 'label' => 'Fixture status'],
                    'json' => $this->omitPaymentType ? [] : ['pay_type' => $this->paymentType],
                ]]);
            }
            if ($method === 'GET' && $path === '/v1/settlement_offer_status') {
                return $this->json(['response' => [
                    'system_statuses' => [['id' => $this->company === 'LDR' ? '7' : '17', 'status_name' => 'Accepted']],
                    'custom_statuses' => [['id' => $this->company === 'LDR' ? '99' : '199', 'status_name' => 'Client complete/ P-Creditor']],
                    'status_event_mappings' => null, 'workflow' => null,
                ]]);
            }
            if ($method === 'PUT' && $path === '/v1/settlement_offers/456/status') {
                $this->effects[] = 'status';
                $this->statusPayload = $payload;
                $this->status = (string) $payload['status_id'];
                return $this->json(['response' => ['id' => '456']]);
            }
            if ($method === 'POST' && $path === '/v1/contacts/123/notes') {
                $this->effects[] = 'note';
                $this->notePayload = $payload;
                return $this->json(['response' => ['id' => '888']]);
            }
        }
        if ($host === 'graph.microsoft.com') {
            $mailPath = '/v1.0/users/automation@libertydebtrelief.com';
            if ($method === 'GET' && $this->approvalNextLink !== null && (string) $request->getUri() === $this->approvalNextLink) {
                $this->approvalPageUrls[] = (string) $request->getUri();
                return $this->json(['value' => [$this->message()]]);
            }
            if ($method === 'GET' && $path === $mailPath . '/mailFolders/inbox/messages') {
                $this->approvalPageUrls[] = (string) $request->getUri();
                if ($this->approvalNextLink !== null) {
                    $otherCompanyMessages = array_map(fn (int $index) => [
                        'id' => 'ldr-message-' . $index,
                        'subject' => ApprovalEmail::SUBJECT_PREFIX . ' ' . $index,
                        'sender' => ['emailAddress' => ['address' => 'no-reply@libertydebtrelief.com']],
                    ], range(1, 50));
                    return $this->json(['value' => $otherCompanyMessages, '@odata.nextLink' => $this->approvalNextLink]);
                }
                return $this->json(['value' => $this->archived ? [] : [$this->message()]]);
            }
            if ($method === 'GET' && $path === $mailPath . '/messages/' . $this->messageId) {
                return $this->json($this->message());
            }
            if ($method === 'PATCH' && $path === $mailPath . '/messages/' . $this->messageId) {
                $this->effects[] = 'read';
                $this->readPayload = $payload;
                return new Response(204);
            }
            if ($method === 'POST' && $path === $mailPath . '/messages/' . $this->messageId . '/move') {
                $this->archivePayload = $payload;
                if ($this->failArchiveOnce) {
                    $this->failArchiveOnce = false;
                    $this->effects[] = 'archive_failed';
                    return $this->json(['error' => ['code' => 'ServiceUnavailable', 'message' => 'private-provider-details']], 503);
                }
                $this->effects[] = 'archive';
                $this->archived = true;
                return $this->json(['id' => $this->messageId], 201);
            }
            if ($method === 'POST' && $path === '/v1.0/users/admin@libertydebtrelief.com/sendMail') {
                $this->effects[] = 'notification';
                $this->mailPayload = $payload;
                $this->sendPath = $path;
                return $this->failNotification ? $this->json(['error' => ['code' => 'ServiceUnavailable']], 503) : new Response(202);
            }
        }
        throw new RuntimeException('Unexpected HTTP request in offline integration test: ' . $method . ' ' . $host . $path);
    }

    private function message(): array
    {
        return [
            'id' => $this->messageId, 'internetMessageId' => '<' . $this->messageId . '@example.com>',
            'subject' => ApprovalEmail::SUBJECT_PREFIX . ' 456',
            'sender' => ['emailAddress' => ['address' => $this->company === 'LDR' ? 'no-reply@libertydebtrelief.com' : 'no-reply@progresslaw.com']],
            'body' => ['contentType' => 'text', 'content' => "Settlement Approval For Customer Id: LLG-123\nCreditor Name: Bank <script> & Co\nSettlement ID: 456\nLast 4 Account Number: 0012"],
        ];
    }

    private function json(array $data, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($data, JSON_THROW_ON_ERROR));
    }
}

final class ApprovalTestLogger extends AbstractLogger
{
    public array $records = [];
    public bool $fail = false;

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        // Fault only the new tracing writes; the shared email sender has independent existing logs.
        if ($this->fail && str_starts_with((string) $message, 'settlement_approval.')) {
            throw new RuntimeException('private-logger-details');
        }
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }

    public function last(string $event): array
    {
        foreach (array_reverse($this->records) as $record) {
            if ($record['message'] === 'settlement_approval.' . $event) return $record;
        }
        throw new RuntimeException('Expected log event missing: ' . $event);
    }
}
