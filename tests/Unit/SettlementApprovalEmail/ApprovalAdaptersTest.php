<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit\SettlementApprovalEmail;

use Cmd\Reports\Services\GraphMailboxClient;
use Cmd\Reports\SettlementApprovalEmail\{ApprovalEmail, ForthApprovalGateway};
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ApprovalAdaptersTest extends TestCase
{
    private function client(array $responses, array &$history): Client
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));
        return new Client(['handler' => $stack]);
    }

    private function json(array $data, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($data));
    }

    public function test_forth_catalog_is_company_specific_and_requests_match_documented_contract(): void
    {
        $history = []; $keyBrands = [];
        $client = $this->client([
            $this->json(['response' => ['id' => '456', 'contact_id' => '123', 'offer_status' => 1194, 'json' => ['pay_type' => 15]]]),
            $this->json(['response' => [['id' => '7', 'title' => 'Accepted'], ['id' => '99', 'title' => 'Client complete/ P-Creditor']]]),
            $this->json(['response' => ['id' => '456'], 'status' => ['code' => 200]]),
            $this->json(['response' => ['id' => '888'], 'status' => ['code' => 200]]),
            $this->json(['response' => [['id' => '44', 'title' => 'Accepted']]]),
        ], $history);
        $gateway = new ForthApprovalGateway($client, function ($brand) use (&$keyBrands) { $keyBrands[] = $brand; return 'fake-key-' . $brand; }, ['LDR' => ['type' => 1, 'public' => false]]);
        $email = new ApprovalEmail('LDR', '123', '456', 'Bank', '0012');
        self::assertSame('123', $gateway->offer($email)['contact_id']);
        self::assertSame('7', $gateway->statusId($email, 'Accepted'));
        $gateway->updateStatus($email, '7');
        $gateway->addNote($email);
        self::assertSame('44', $gateway->statusId(new ApprovalEmail('PLAW', '123', '456', 'Bank', '0012'), 'Accepted'));
        self::assertSame(['LDR', 'PLAW'], $keyBrands);
        self::assertSame('/v1/settlement_offers/456/status', $history[2]['request']->getUri()->getPath());
        self::assertSame(['status_id' => '7'], json_decode((string) $history[2]['request']->getBody(), true));
        self::assertSame('/v1/contacts/123/notes', $history[3]['request']->getUri()->getPath());
        self::assertSame(['content' => $email->note(), 'note_type' => 1, 'public' => false], json_decode((string) $history[3]['request']->getBody(), true));
    }

    public function test_ambiguous_status_or_missing_note_configuration_blocks_writes(): void
    {
        $history = [];
        $gateway = new ForthApprovalGateway($this->client([
            $this->json(['response' => [['id' => '7', 'title' => 'Accepted'], ['id' => '8', 'title' => 'Accepted']]]),
        ], $history), fn () => 'fake-key', []);
        $email = new ApprovalEmail('LDR', '123', '456', 'Bank', '0012');
        try { $gateway->statusId($email, 'Accepted'); self::fail('Expected ambiguous status.'); } catch (RuntimeException) {}
        try { $gateway->assertReady($email); self::fail('Expected missing note settings.'); } catch (RuntimeException) {}
        self::assertCount(1, $history);
        self::assertSame('GET', $history[0]['request']->getMethod());
    }

    public function test_live_grouped_status_catalog_resolves_system_and_custom_names(): void
    {
        $history = [];
        $gateway = new ForthApprovalGateway($this->client([
            $this->json(['response' => [
                'system_statuses' => [['id' => '7', 'status_name' => 'Accepted']],
                'custom_statuses' => [['id' => '99', 'status_name' => 'Client complete/ P-Creditor']],
                'status_event_mappings' => null, 'workflow' => null,
            ]]),
        ], $history), fn () => 'fake-key', []);
        $email = new ApprovalEmail('LDR', '123', '456', 'Bank', '0012');
        self::assertSame('7', $gateway->statusId($email, 'Accepted'));
        self::assertSame('99', $gateway->statusId($email, 'Client complete/ P-Creditor'));
        self::assertCount(1, $history);
    }

    public function test_grouped_catalog_ambiguity_or_malformed_group_blocks_resolution(): void
    {
        foreach ([
            ['system_statuses' => [['id' => '7', 'status_name' => 'Accepted']],
                'custom_statuses' => [['id' => '8', 'status_name' => 'Accepted']]],
            ['system_statuses' => [['id' => '7', 'status_name' => 'Accepted']], 'custom_statuses' => null],
        ] as $catalog) {
            $history = [];
            $gateway = new ForthApprovalGateway($this->client([$this->json(['response' => $catalog])], $history), fn () => 'fake-key', []);
            try {
                $gateway->statusId(new ApprovalEmail('LDR', '123', '456', 'Bank', '0012'), 'Accepted');
                self::fail('Ambiguous or malformed catalog was accepted.');
            } catch (RuntimeException) {
                self::assertCount(1, $history);
                self::assertSame('GET', $history[0]['request']->getMethod());
            }
        }
    }

    public function test_uncertain_mutation_is_not_retried_or_leaked_in_error_output(): void
    {
        $history = [];
        $gateway = new ForthApprovalGateway($this->client([
            new ConnectException('contains fake-secret', new Request('PUT', 'https://api.forthcrm.com/v1/settlement_offers/456/status')),
        ], $history), fn () => 'fake-key', []);
        try {
            $gateway->updateStatus(new ApprovalEmail('LDR', '123', '456', 'Bank', '0012'), '7');
            self::fail('Expected timeout.');
        } catch (RuntimeException $e) {
            self::assertStringNotContainsString('fake-secret', $e->getMessage());
        }
        self::assertCount(1, $history);
    }

    public function test_forth_http_success_with_application_error_is_not_accepted(): void
    {
        $history = [];
        $gateway = new ForthApprovalGateway($this->client([$this->json(['response' => [], 'status' => ['code' => 400]])], $history), fn () => 'fake-key', []);
        $this->expectException(RuntimeException::class);
        $gateway->updateStatus(new ApprovalEmail('LDR', '123', '456', 'Bank', '0012'), '7');
    }

    public function test_graph_body_pagination_and_top_level_archive_use_immutable_ids(): void
    {
        $history = [];
        $next = 'https://graph.microsoft.com/v1.0/users/automation%40libertydebtrelief.com/mailFolders/inbox/messages?$skiptoken=example';
        $mail = new GraphMailboxClient('tenant', 'client', 'fake-secret', $this->client([
            $this->json(['access_token' => 'fake-token']),
            $this->json(['value' => [['id' => 'id-1']], '@odata.nextLink' => $next]),
            $this->json(['value' => [['id' => 'id-2']]]),
            $this->json(['body' => ['contentType' => 'text', 'content' => 'body']]),
            new Response(204), $this->json(['id' => 'id-1'], 201),
        ], $history));
        self::assertSame($next, $mail->approvalInboxPage('automation@libertydebtrelief.com', ApprovalEmail::SUBJECT_PREFIX)['next']);
        self::assertCount(1, $mail->approvalInboxPage('automation@libertydebtrelief.com', ApprovalEmail::SUBJECT_PREFIX, $next)['messages']);
        self::assertSame('body', $mail->approvalMessage('automation@libertydebtrelief.com', 'id-1')['body']['content']);
        $mail->markRead('automation@libertydebtrelief.com', 'id-1');
        $mail->moveToArchive('automation@libertydebtrelief.com', 'id-1');
        self::assertSame('IdType="ImmutableId"', $history[1]['request']->getHeaderLine('Prefer'));
        self::assertStringContainsString('outlook.body-content-type="text"', $history[3]['request']->getHeaderLine('Prefer'));
        self::assertSame(['isRead' => true], json_decode((string) $history[4]['request']->getBody(), true));
        self::assertSame(['destinationId' => 'archive'], json_decode((string) $history[5]['request']->getBody(), true));
        self::assertStringContainsString('$skiptoken=example', (string) $history[2]['request']->getUri());
    }

    public function test_note_creation_without_a_confirmed_id_is_uncertain(): void
    {
        $history = [];
        $gateway = new ForthApprovalGateway($this->client([$this->json(['response' => [], 'status' => ['code' => 200]])], $history), fn () => 'fake-key', ['LDR' => ['type' => 1, 'public' => false]]);
        $this->expectException(RuntimeException::class);
        $gateway->addNote(new ApprovalEmail('LDR', '123', '456', 'Bank', '0012'));
    }

    public static function approvalPaginationPaths(): array
    {
        return [
            'server OData path' => ["/v1.0/users/automation@libertydebtrelief.com/mailFolders('inbox')/messages"],
            'encoded OData path' => ['/v1.0/users/automation%40libertydebtrelief.com/mailFolders%28%27inbox%27%29/messages'],
            'slash path' => ['/v1.0/users/automation@libertydebtrelief.com/mailFolders/inbox/messages'],
            'encoded slash path' => ['/v1.0/users/automation%40libertydebtrelief.com/mailFolders/inbox/messages'],
        ];
    }

    #[DataProvider('approvalPaginationPaths')]
    public function test_graph_follows_equivalent_inbox_paths_without_rewriting_the_continuation(string $path): void
    {
        $history = [];
        $next = 'https://graph.microsoft.com' . $path
            . '?%24select=id%2Csubject&%24top=50&%24filter=startswith%28subject%2C%27Settlement%20Approval%20Data%3A%27%29&%24skiptoken=opaque%2Bpage%2Fcursor%3D';
        $mail = new GraphMailboxClient('tenant', 'client', 'fake-secret', $this->client([
            $this->json(['access_token' => 'fake-token']),
            $this->json(['value' => [], '@odata.nextLink' => $next]),
            $this->json(['value' => [['id' => 'next-page-message']]]),
        ], $history));

        $first = $mail->approvalInboxPage('automation@libertydebtrelief.com', ApprovalEmail::SUBJECT_PREFIX);
        $second = $mail->approvalInboxPage('automation@libertydebtrelief.com', ApprovalEmail::SUBJECT_PREFIX, $first['next']);

        self::assertSame([['id' => 'next-page-message']], $second['messages']);
        self::assertNull($second['next']);
        self::assertCount(3, $history);
        self::assertSame($next, (string) $history[2]['request']->getUri());
        self::assertSame('GET', $history[2]['request']->getMethod());
        self::assertSame('IdType="ImmutableId"', $history[2]['request']->getHeaderLine('Prefer'));
    }

    public function test_graph_rejects_external_or_other_mailbox_pagination_before_sending_credentials(): void
    {
        $inbox = "/v1.0/users/automation@libertydebtrelief.com/mailFolders('inbox')/messages";
        foreach ([
            'https://evil.example/v1.0/users/a/mailFolders/inbox/messages',
            'https://graph.microsoft.com/v1.0/users/other/mailFolders/inbox/messages',
            'https://evil.example' . $inbox,
            'https://graph.microsoft.com.evil.example' . $inbox,
            'http://graph.microsoft.com' . $inbox,
            'https://user:password@graph.microsoft.com' . $inbox,
            'https://graph.microsoft.com:8443' . $inbox,
            "https://graph.microsoft.com/v1.0/users/other/mailFolders('inbox')/messages",
            "https://graph.microsoft.com/v1.0/users/automation@libertydebtrelief.com/mailFolders('archive')/messages",
            'https://graph.microsoft.com' . $inbox . '/delta',
            'https://graph.microsoft.com' . $inbox . '/../../sendMail',
            'https://graph.microsoft.com' . $inbox . '#fragment',
            'https://graph.microsoft.com/beta' . substr($inbox, strlen('/v1.0')),
        ] as $next) {
            $history = [];
            $mail = new GraphMailboxClient('tenant', 'client', 'secret', $this->client([], $history));
            try { $mail->approvalInboxPage('automation@libertydebtrelief.com', ApprovalEmail::SUBJECT_PREFIX, $next); self::fail('Expected URL rejection.'); }
            catch (RuntimeException) { self::assertSame([], $history); }
        }
    }
}
