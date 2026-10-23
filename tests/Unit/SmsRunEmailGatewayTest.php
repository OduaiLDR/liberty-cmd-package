<?php

declare(strict_types=1);

use Cmd\Reports\Services\DBConnector;
use Cmd\Reports\Services\EmailSenderService;
use Cmd\Reports\Services\SmsRunEmailMessage;
use Cmd\Reports\Services\SmsRunOptions;
use Cmd\Reports\Services\SmsTableRunEmailGateway;
use Dotenv\Repository\Adapter\ArrayAdapter;
use Dotenv\Repository\RepositoryBuilder;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Facade;
use Psr\Log\NullLogger;

final class SmsEmailGatewayFixtureSql extends DBConnector
{
    public array $queries = [];
    public array $columns = ['Report_Name', 'Company', 'Send_To', 'Send_CC', 'Send_BCC'];
    public array $rows = [['Send_To' => 'Jude@lendingtower.com;Jeff@gozuvo.com|Audrey@gozuvo.com',
        'Send_CC' => 'jude@lendingtower.com,copy@example.com', 'Send_BCC' => 'copy@example.com;archive@example.com']];
    public bool $failed = false;
    public function __construct() {}
    public function querySqlServer(string $sql, array $params = []): array
    {
        $this->queries[] = $sql;
        if ($this->failed) return ['success' => false, 'data' => []];
        if (str_contains($sql, 'INFORMATION_SCHEMA.COLUMNS')) {
            return ['success' => true, 'data' => array_map(fn ($name) => ['COLUMN_NAME' => $name], $this->columns)];
        }
        if (str_contains($sql, 'FROM dbo.TblReports')) return ['success' => true, 'data' => $this->rows];
        throw new RuntimeException('Unexpected SQL in the closed SMS email fixture.');
    }
}

final class SmsEmailGatewayFixtureSender extends EmailSenderService
{
    public array $sends = [];
    public bool $result = true;
    public function __construct() {}
    public function sendMailHtml(string $subject, string $body, array $to, array $cc = [], array $bcc = [], array $attachments = [], ?string $fromAddress = null): bool
    {
        $this->sends[] = compact('subject', 'body', 'to', 'cc', 'bcc', 'attachments', 'fromAddress');
        return $this->result;
    }
}

function smsEmailGatewayFixtureMessage(array $configuration): array
{
    $options = SmsRunOptions::normalize(1, 1, ['T1'], SmsRunOptions::TIER_SELECTION_POLICY,
        SmsRunOptions::PLANNED_DATE_VERSION, '2026-10-26', SmsRunOptions::APPROVAL_FILENAME_VERSION);
    $name = 'SMS Export - 2026-10-26 - SMS0001-SMS0001 - Part 1 of 1.csv';
    $hash = hash('sha256', 'synthetic CSV');
    $manifest = ['version' => 1, 'options_sha256' => hash('sha256', json_encode($options, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
        'rows' => 1, 'rows_per_file' => 1, 'csv_format' => SmsRunOptions::CSV_FORMAT,
        'parts' => [['part' => 1, 'name' => $name, 'rows' => 1, 'bytes' => 100, 'sha256' => $hash]],
        'download' => ['name' => $name, 'format' => 'csv', 'bytes' => 100, 'sha256' => $hash]];
    return SmsRunEmailMessage::build($configuration, $manifest, $options, 1,
        [['name' => $name, 'url' => 'https://files.example.com/part1', 'sha256' => $hash, 'bytes' => 100, 'rows' => 1]], ['files.example.com']);
}

beforeEach(function (): void {
    $this->previousContainer = Container::getInstance();
    $this->previousFacadeApplication = Facade::getFacadeApplication();
    $this->envProperty = new ReflectionProperty(Env::class, 'repository');
    // Swap the repository object itself: never read/write real environment or credential values.
    $this->previousEnvRepository = $this->envProperty->getValue();
    $this->env = RepositoryBuilder::createWithNoAdapters()->addAdapter(ArrayAdapter::class)->make();
    $this->envProperty->setValue(null, $this->env);
    foreach (['GRAPH_LT_TENANT_ID', 'GRAPH_LT_CLIENT_ID', 'GRAPH_LT_CLIENT_SECRET'] as $key) $this->env->set($key, 'fixture-only');
    $this->env->set('REPORT_EXTRA_RECIPIENTS', 'must-not-appear@example.com');
    $this->container = new Container;
    Container::setInstance($this->container);
    $this->config = new Repository(['sms-exports' => ['email_report_name' => 'SMS Export',
        'email_report_company' => 'fixture-company', 'email_report_source' => 'plaw', 'email_allowed_link_hosts' => ['files.example.com']]]);
    $this->container->instance('config', $this->config);
    $this->container->instance('log', new NullLogger);
    Facade::clearResolvedInstances(); Facade::setFacadeApplication($this->container);
    $this->sql = new SmsEmailGatewayFixtureSql;
    $this->sender = new SmsEmailGatewayFixtureSender;
    $this->gateway = new SmsTableRunEmailGateway($this->sql, $this->sender);
});

afterEach(function (): void {
    $this->envProperty->setValue(null, $this->previousEnvRepository);
    Facade::clearResolvedInstances(); Facade::setFacadeApplication($this->previousFacadeApplication);
    Container::setInstance($this->previousContainer);
});

test('SMS table email gateway constructor is lazy and readiness has no SQL or transport calls', function (): void {
    $this->env->clear('GRAPH_LT_CLIENT_SECRET');
    $gateway = new SmsTableRunEmailGateway;
    expect($gateway)->toBeInstanceOf(SmsTableRunEmailGateway::class)
        ->and(fn () => $this->gateway->assertReady())->toThrow(RuntimeException::class)
        ->and($this->sql->queries)->toBe([])->and($this->sender->sends)->toBe([]);
});

test('SMS email recipients come from strict company filtered TblReports with no fallback or extras', function (): void {
    $configuration = $this->gateway->configuration();
    expect($configuration)->toBe(['from' => 'admin@lendingtower.com',
        'to' => ['Jude@lendingtower.com', 'Jeff@gozuvo.com', 'Audrey@gozuvo.com'],
        'cc' => ['copy@example.com'], 'bcc' => ['archive@example.com'], 'report_name' => 'SMS Export', 'companies' => ['fixture-company']])
        ->and($this->sql->queries)->toHaveCount(2)
        ->and($this->sql->queries[1])->toContain("IN ('SMS Export')", "IN ('fixture-company')")
        ->and($this->sender->sends)->toBe([]);
    $this->gateway->assertReady();
    expect($this->sql->queries)->toHaveCount(2)->and($this->sender->sends)->toBe([]);
});

test('SMS email send uses exact frozen recipients content and sender without re-reading configuration', function (): void {
    $configuration = $this->gateway->configuration();
    $message = smsEmailGatewayFixtureMessage($configuration);
    $this->sql->rows = [['Send_To' => 'new-person@example.com', 'Send_CC' => '', 'Send_BCC' => '']];
    $this->config->set('sms-exports.email_report_company', null);
    expect($this->gateway->send($message))->toBeTrue()->and($this->sql->queries)->toHaveCount(2)
        ->and($this->sender->sends)->toBe([['subject' => $message['subject'], 'body' => $message['html'],
            'to' => $configuration['to'], 'cc' => $configuration['cc'], 'bcc' => $configuration['bcc'],
            'attachments' => [], 'fromAddress' => 'admin@lendingtower.com']]);
});

test('SMS email send preserves uncertain false outcome and makes no automatic transport retry', function (): void {
    $message = smsEmailGatewayFixtureMessage($this->gateway->configuration());
    $this->sender->result = false;
    expect($this->gateway->send($message))->toBeFalse()->and($this->sender->sends)->toHaveCount(1);
});

test('SMS email configuration requires an explicit company before any SQL lookup', function (mixed $company): void {
    $this->config->set('sms-exports.email_report_company', $company);
    expect(fn () => $this->gateway->configuration())->toThrow(RuntimeException::class)
        ->and($this->sql->queries)->toBe([])->and($this->sender->sends)->toBe([]);
})->with([null, '', ' ', "bad\ncompany", 123]);

test('SMS email table lookup fails closed for missing company column missing rows and invalid addresses', function (string $case): void {
    match ($case) {
        'missing company column' => $this->sql->columns = ['Report_Name', 'Send_To', 'Send_CC', 'Send_BCC'],
        'empty company result' => $this->sql->rows = [],
        'failed query' => $this->sql->failed = true,
        'invalid email' => $this->sql->rows[0]['Send_To'] = 'not-email',
        'only CC' => $this->sql->rows[0]['Send_To'] = '',
    };
    expect(fn () => $this->gateway->configuration())->toThrow(InvalidArgumentException::class)
        ->and($this->sql->queries)->toHaveCount(in_array($case, ['missing company column', 'failed query'], true) ? 1 : 2)
        ->and($this->sender->sends)->toBe([]);
})->with(['missing company column', 'empty company result', 'failed query', 'invalid email', 'only CC']);

test('SMS email malformed stored content and removed link allowlist cannot reach the sender', function (string $case): void {
    $message = smsEmailGatewayFixtureMessage($this->gateway->configuration());
    if ($case === 'changed content') $message['html'] .= 'changed';
    else $this->config->set('sms-exports.email_allowed_link_hosts', []);
    expect(fn () => $this->gateway->send($message))->toThrow(InvalidArgumentException::class)
        ->and($this->sender->sends)->toBe([]);
})->with(['changed content', 'removed hosts']);

test('existing recipient reader remains backward compatible without explicit companies', function (): void {
    $groups = $this->sender->recipientGroupsForReports($this->sql, ['Existing Report']);
    expect($groups['to'])->toHaveCount(3)->and($this->sql->queries[1])->not->toContain("IN ('fixture-company')");
});
