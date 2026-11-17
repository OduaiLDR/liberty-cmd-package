<?php

declare(strict_types=1);

use Cmd\Reports\Services\SmsRunEmailProjection;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;

test('disabled email projection never resolves a gateway or outbox', function (): void {
    $previous = Container::getInstance();
    $container = new Container;
    $container->instance('config', new Repository(['sms-exports' => ['email_handoff_enabled' => false]]));
    Container::setInstance($container);
    try {
        expect(SmsRunEmailProjection::forRun(['options' => null]))->toBeNull();
    } finally {
        Container::setInstance($previous);
    }
});

test('unprepared email has no fabricated attempt or dynamic recipients', function (): void {
    $preview = ['email_preview' => ['to' => [], 'body' => 'Pending links'],
        'email' => ['status' => 'not_configured'], 'debtplete_import' => ['status' => 'not_configured']];
    $projected = SmsRunEmailProjection::apply($preview, null, null);
    expect($projected['email']['status'])->toBe('awaiting_links')
        ->and($projected['email']['note'])->toBe('No email is prepared yet. It needs the published file links.')
        ->and($projected['email_preview']['to'])->toBe([])
        ->and($projected['debtplete_import'])->toBe($preview['debtplete_import']);
});

test('saved email projection uses frozen dynamic groups but never exposes capability URLs or raw payload', function (): void {
    $hash = str_repeat('a', 64);
    $preview = ['email_preview' => ['to' => []], 'email' => [], 'debtplete_import' => ['status' => 'not_configured']];
    $message = ['from' => 'admin@lendingtower.com', 'to' => ['dynamic@example.com'],
        'cc' => ['copy@example.com'], 'bcc' => ['archive@example.com'],
        'text' => 'File 1: https://files.example.com/part?key=private-one'."\n".'File 2: https://files.example.com/part?key=private-one-long',
        'html' => '<a href="secret">not for browser</a>',
        'files' => [['name' => 'Part 1.csv', 'rows' => 2, 'url' => 'https://files.example.com/part?key=private-one'],
            ['name' => 'Part 2.csv', 'rows' => 1, 'url' => 'https://files.example.com/part?key=private-one-long']]];
    $status = ['status' => 'accepted', 'payload_sha256' => $hash, 'note' => 'Provider acceptance, not delivery.',
        'raw_response' => 'provider-secret', 'Payload_JSON' => 'private-payload'];
    $projected = SmsRunEmailProjection::apply($preview, $status, $message);
    $json = json_encode($projected, JSON_THROW_ON_ERROR);
    expect($projected['email_preview']['to'])->toBe(['dynamic@example.com'])
        ->and($projected['email_preview']['cc'])->toBe(['copy@example.com'])
        ->and($projected['email_preview']['bcc'])->toBe([])
        ->and($projected['email_preview']['bcc_configured'])->toBeTrue()
        ->and($projected['email_preview']['body'])->toBe("File 1: [Approved external link stored]\nFile 2: [Approved external link stored]")
        ->and($projected['email_preview']['files'][0]['link'])->toBeNull()
        ->and($projected['email']['status'])->toBe('accepted')
        ->and($projected['debtplete_import']['status'])->toBe('not_configured')
        ->and($json)->not->toContain('private-one', 'files.example.com', 'secret', 'private-payload', '<a', 'archive@example.com');
});

test('unverified saved email state cannot claim delivery in projection', function (array $state): void {
    SmsRunEmailProjection::apply([], $state, []);
})->with([
    [['status' => 'delivered', 'payload_sha256' => str_repeat('a', 64)]],
    [['status' => 'completed', 'payload_sha256' => str_repeat('a', 64)]],
    [['status' => 'accepted', 'payload_sha256' => "bad\n"]],
])->throws(RuntimeException::class);

test('an open batch with the email switched on says it starts after approval, without an outbox, and shows no list when none can be read', function (): void {
    $previous = Container::getInstance();
    $container = new Container;
    $container->instance('config', new Repository(['sms-exports' => ['email_handoff_enabled' => true, 'file_publication_enabled' => true]]));
    $container->bind(Cmd\Reports\Services\SmsRunEmailWorkflow::class, fn () => throw new RuntimeException('Unexpected outbox resolution.'));
    $container->bind(Cmd\Reports\Services\SmsRunPublicationWorkflow::class, fn () => throw new RuntimeException('Unexpected publication resolution.'));
    Container::setInstance($container);
    try {
        $view = ['request_id' => 'dd5bfa17-c1fa-4a9d-84f0-c308f4db615e', 'status' => 'file_ready', 'target' => 10, 'selected' => 3,
            'options' => ['options_version' => Cmd\Reports\Services\SmsRunOptions::PLANNED_DATE_VERSION, 'send_date' => '2026-10-26',
                'csv_format' => Cmd\Reports\Services\SmsRunOptions::FIELD_NAME_CSV_FORMAT, 'filename_version' => Cmd\Reports\Services\SmsRunOptions::APPROVAL_FILENAME_VERSION],
            'file' => ['format' => 'zip', 'parts' => 2, 'files' => [
                ['name' => 'SMS Export - 2026-10-26 - ID Pending - Part 1 of 2.csv', 'rows' => 2],
                ['name' => 'SMS Export - 2026-10-26 - ID Pending - Part 2 of 2.csv', 'rows' => 1]]]];
        $projected = SmsRunEmailProjection::forRun($view);
        expect($projected)->not->toBeNull();
        expect($projected['email']['status'])->toBe('awaiting_approval')->and($projected['email']['note'])->toContain('after this batch is approved')
            ->and($projected['file_publication']['status'])->toBe('awaiting_approval')
            ->and($projected['email_preview']['body'])->toContain('[Download link pending]');
        // A closed batch that was never approved keeps the plain "not set up" wording.
        $discarded = SmsRunEmailProjection::forRun(['status' => 'discarded'] + $view);
        expect($discarded === null || $discarded['email']['status'] === 'not_configured')->toBeTrue();
        // Switched off: unchanged.
        config(['sms-exports.email_handoff_enabled' => false, 'sms-exports.file_publication_enabled' => false]);
        $off = SmsRunEmailProjection::forRun($view);
        expect($off['email']['status'])->toBe('not_configured')->and($off['file_publication']['status'])->toBe('not_configured');
    } finally {
        Container::setInstance($previous);
    }
});

/** A gateway that counts how often the list is read, and a tiny cache, for the open-batch recipient preview. */
function smsRecipientPreviewContainer(object $gateway, ?object $cache): Container
{
    $container = new Container;
    $container->instance('config', new Repository(['sms-exports' => ['email_handoff_enabled' => true, 'file_publication_enabled' => true,
        'email_report_name' => 'SMS Export', 'email_report_company' => 'LT']]));
    $container->bind(Cmd\Reports\Services\SmsRunEmailWorkflow::class, fn () => throw new RuntimeException('Unexpected outbox resolution.'));
    $container->bind(Cmd\Reports\Services\SmsRunPublicationWorkflow::class, fn () => throw new RuntimeException('Unexpected publication resolution.'));
    $container->instance(Cmd\Reports\Services\SmsRunEmailGateway::class, $gateway);
    if ($cache !== null) $container->instance('cache', $cache);

    return $container;
}

function smsRecipientPreviewGateway(array|Throwable $answer): Cmd\Reports\Services\SmsRunEmailGateway
{
    return new class($answer) implements Cmd\Reports\Services\SmsRunEmailGateway {
        public int $calls = 0;
        public function __construct(private array|Throwable $answer) {}
        public function configuration(): array { $this->calls++; if ($this->answer instanceof Throwable) throw $this->answer; return $this->answer; }
        public function assertReady(): void { throw new RuntimeException('The preview must not check the mailbox.'); }
        public function send(array $message): bool { throw new RuntimeException('The preview must not send.'); }
    };
}

function smsRecipientPreviewCache(): object
{
    return new class {
        public array $items = [];
        public array $seconds = [];
        public function get(string $key): mixed { return $this->items[$key] ?? null; }
        public function put(string $key, mixed $value, int $seconds): bool { $this->items[$key] = $value; $this->seconds[$key] = $seconds; return true; }
    };
}

function smsRecipientPreviewView(string $status = 'file_ready'): array
{
    return ['request_id' => 'dd5bfa17-c1fa-4a9d-84f0-c308f4db615e', 'status' => $status, 'target' => 10, 'selected' => 3,
        'options' => ['options_version' => Cmd\Reports\Services\SmsRunOptions::PLANNED_DATE_VERSION, 'send_date' => '2026-10-26',
            'csv_format' => Cmd\Reports\Services\SmsRunOptions::FIELD_NAME_CSV_FORMAT, 'filename_version' => Cmd\Reports\Services\SmsRunOptions::APPROVAL_FILENAME_VERSION],
        'file' => ['format' => 'zip', 'parts' => 2, 'files' => [
            ['name' => 'SMS Export - 2026-10-26 - ID Pending - Part 1 of 2.csv', 'rows' => 2],
            ['name' => 'SMS Export - 2026-10-26 - ID Pending - Part 2 of 2.csv', 'rows' => 1]]]];
}

function smsRecipientPreviewRun(Container $container, callable $check): void
{
    $previous = Container::getInstance();
    $previousFacade = Illuminate\Support\Facades\Facade::getFacadeApplication();
    Container::setInstance($container);
    Illuminate\Support\Facades\Facade::clearResolvedInstances();
    Illuminate\Support\Facades\Facade::setFacadeApplication($container);
    try {
        $check();
    } finally {
        Illuminate\Support\Facades\Facade::clearResolvedInstances();
        Illuminate\Support\Facades\Facade::setFacadeApplication($previousFacade);
        Container::setInstance($previous);
    }
}

test('an open batch shows the current To and CC list, keeps BCC private, and reads the list once while it is kept', function (): void {
    $gateway = smsRecipientPreviewGateway(['from' => 'admin@lendingtower.com', 'to' => ['one@example.com', 'two@example.com'],
        'cc' => ['copy@example.com'], 'bcc' => ['archive@example.com'], 'report_name' => 'SMS Export', 'companies' => ['LT']]);
    $cache = smsRecipientPreviewCache();
    smsRecipientPreviewRun(smsRecipientPreviewContainer($gateway, $cache), function () use ($gateway, $cache): void {
        $first = SmsRunEmailProjection::forRun(smsRecipientPreviewView());
        $second = SmsRunEmailProjection::forRun(smsRecipientPreviewView('review'));
        expect($first['email']['status'])->toBe('awaiting_approval')
            ->and($first['email_preview']['to'])->toBe(['one@example.com', 'two@example.com'])
            ->and($first['email_preview']['cc'])->toBe(['copy@example.com'])
            ->and($first['email_preview']['bcc'])->toBe([])
            ->and($first['email_preview']['bcc_configured'])->toBeTrue()
            ->and($first['email_preview']['recipients_current'])->toBeTrue()
            ->and($first['email_preview']['from'])->toBe('admin@lendingtower.com')
            ->and($first['email_preview']['body'])->toContain('[Download link pending]')
            ->and(json_encode($first, JSON_THROW_ON_ERROR))->not->toContain('archive@example.com')
            ->and($second['email_preview']['to'])->toBe(['one@example.com', 'two@example.com'])
            ->and($gateway->calls)->toBe(1)
            ->and(array_values($cache->seconds))->toBe([300]);
        // Closed, or switched off: the list is not read at all.
        SmsRunEmailProjection::forRun(smsRecipientPreviewView('discarded'));
        config(['sms-exports.email_handoff_enabled' => false]);
        $off = SmsRunEmailProjection::forRun(smsRecipientPreviewView());
        expect($off['email_preview']['to'])->toBe([])->and(array_key_exists('recipients_current', $off['email_preview']))->toBeFalse()
            ->and($gateway->calls)->toBe(1);
    });
});

test('an open batch without a cache still shows the current list', function (): void {
    $gateway = smsRecipientPreviewGateway(['from' => 'admin@lendingtower.com', 'to' => ['one@example.com'], 'cc' => [], 'bcc' => [],
        'report_name' => 'SMS Export', 'companies' => ['LT']]);
    smsRecipientPreviewRun(smsRecipientPreviewContainer($gateway, null), function () use ($gateway): void {
        $projected = SmsRunEmailProjection::forRun(smsRecipientPreviewView());
        expect($projected['email_preview']['to'])->toBe(['one@example.com'])->and($projected['email_preview']['cc'])->toBe([])
            ->and($projected['email_preview']['bcc_configured'])->toBeFalse()->and($projected['email_preview']['recipients_current'])->toBeTrue()
            ->and($gateway->calls)->toBe(1);
    });
});

test('a list that cannot be read or is not valid leaves the preview as it was and is not retried for a minute', function (array|Throwable $answer): void {
    $gateway = smsRecipientPreviewGateway($answer);
    $cache = smsRecipientPreviewCache();
    smsRecipientPreviewRun(smsRecipientPreviewContainer($gateway, $cache), function () use ($gateway, $cache): void {
        $first = SmsRunEmailProjection::forRun(smsRecipientPreviewView());
        $second = SmsRunEmailProjection::forRun(smsRecipientPreviewView());
        expect($first['email']['status'])->toBe('awaiting_approval')
            ->and($first['email_preview']['to'])->toBe([])
            ->and(array_key_exists('recipients_current', $first['email_preview']))->toBeFalse()
            ->and($first['email_preview']['body'])->toContain('[Download link pending]')
            ->and($second['email_preview']['to'])->toBe([])
            ->and($gateway->calls)->toBe(1)
            ->and(array_values($cache->items))->toBe(['failed'])
            ->and(array_values($cache->seconds))->toBe([60]);
    });
})->with([
    'the table cannot be reached' => [new RuntimeException('TblReports is not reachable.')],
    'no To address' => [['from' => 'admin@lendingtower.com', 'to' => [], 'cc' => ['copy@example.com'], 'bcc' => [], 'report_name' => 'SMS Export', 'companies' => ['LT']]],
    'a bad address' => [['from' => 'admin@lendingtower.com', 'to' => ["bad\nBcc: hidden@example.com"], 'cc' => [], 'bcc' => [], 'report_name' => 'SMS Export', 'companies' => ['LT']]],
    'the same address twice' => [['from' => 'admin@lendingtower.com', 'to' => ['one@example.com'], 'cc' => ['ONE@example.com'], 'bcc' => [], 'report_name' => 'SMS Export', 'companies' => ['LT']]],
]);

test('a saved list that is not in the expected shape is read again instead of being shown', function (): void {
    $gateway = smsRecipientPreviewGateway(['from' => 'admin@lendingtower.com', 'to' => ['one@example.com'], 'cc' => [], 'bcc' => [],
        'report_name' => 'SMS Export', 'companies' => ['LT']]);
    $cache = smsRecipientPreviewCache();
    smsRecipientPreviewRun(smsRecipientPreviewContainer($gateway, $cache), function () use ($gateway, $cache): void {
        SmsRunEmailProjection::forRun(smsRecipientPreviewView());
        $key = array_key_first($cache->items);
        $cache->items[$key] = ['to' => ['not an address'], 'cc' => [], 'bcc_configured' => false];
        $projected = SmsRunEmailProjection::forRun(smsRecipientPreviewView());
        expect($projected['email_preview']['to'])->toBe(['one@example.com'])->and($gateway->calls)->toBe(2);
    });
});
