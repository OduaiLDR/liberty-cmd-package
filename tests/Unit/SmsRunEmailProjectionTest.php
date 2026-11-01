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
        ->and($projected['email']['note'])->toContain('TblReports', 'Approval alone does not send')
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
