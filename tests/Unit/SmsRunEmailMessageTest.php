<?php

declare(strict_types=1);

use Cmd\Reports\Services\SmsRunEmailMessage;
use Cmd\Reports\Services\SmsRunOptions;

function smsEmailMessageFixture(): array
{
    $configuration = ['from' => 'admin@lendingtower.com', 'to' => ['Jude@lendingtower.com', 'Jeff@gozuvo.com', 'Audrey@gozuvo.com'],
        'cc' => [], 'bcc' => [], 'report_name' => 'SMS Export', 'companies' => ['fixture-company']];
    $options = SmsRunOptions::normalize(5, 2, ['T1'], SmsRunOptions::TIER_SELECTION_POLICY,
        SmsRunOptions::PLANNED_DATE_VERSION, '2026-10-26', SmsRunOptions::APPROVAL_FILENAME_VERSION);
    $links = []; $parts = [];
    foreach ([2, 1] as $index => $rows) {
        $name = 'SMS Export - 2026-10-26 - SMS0001-SMS0009 - Part '.($index + 1).' of 2.csv';
        $hash = hash('sha256', 'synthetic part '.($index + 1));
        $links[] = ['name' => $name, 'url' => 'https://files.example.com/SMS%20Export/part'.($index + 1).'?a=one&b=two',
            'sha256' => $hash, 'bytes' => 123 + $index, 'rows' => $rows];
        $parts[] = ['part' => $index + 1, 'name' => $name, 'rows' => $rows, 'bytes' => 123 + $index, 'sha256' => $hash];
    }
    $manifest = ['version' => 1, 'request_id' => 'dd5bfa17-c1fa-4a9d-84f0-c308f4db615e',
        'options_sha256' => hash('sha256', json_encode($options, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
        'rows' => 3, 'rows_per_file' => 2, 'csv_format' => SmsRunOptions::CSV_FORMAT,
        'download' => ['name' => 'SMS Export - 2026-10-26 - SMS0001-SMS0009.zip', 'format' => 'zip', 'bytes' => 500, 'sha256' => hash('sha256', 'synthetic zip')],
        'parts' => $parts];
    return [$configuration, $manifest, $options, 3, $links, ['files.example.com']];
}

test('SMS email uses verified approved parts actual selected count and Jacob wording deterministically', function (): void {
    $fixture = smsEmailMessageFixture();
    $message = SmsRunEmailMessage::build(...$fixture);
    expect($message['from'])->toBe('admin@lendingtower.com')
        ->and($message['to'])->toBe($fixture[0]['to'])
        ->and($message['subject'])->toBe('SMS files to process starting on 2026-10-26')
        ->and($message['text'])->toStartWith('Here are the SMS files to process starting on 2026-10-26. There are 3 records in 2 file(s).')
        ->and($message['text'])->toContain('Download Links', 'File 1 - SMS Export - 2026-10-26 - SMS0001-SMS0009 - Part 1 of 2.csv:', 'Let us know if there are any issues.')
        ->and($message['html'])->toContain('a=one&amp;b=two')
        ->and($message['files'])->toBe($fixture[4])
        ->and($message)->toBe(SmsRunEmailMessage::build(...$fixture));
    SmsRunEmailMessage::validate($message, ['files.example.com']);
});

test('SMS email accepts a single final CSV and historical frozen date without host clock inference', function (): void {
    [$configuration, $manifest, $options, , $links] = smsEmailMessageFixture();
    $options = SmsRunOptions::normalize(5, 5, ['T1'], SmsRunOptions::TIER_SELECTION_POLICY,
        SmsRunOptions::PLANNED_DATE_VERSION, '2024-02-29', SmsRunOptions::APPROVAL_FILENAME_VERSION);
    $name = 'SMS Export - 2024-02-29 - SMS0099-SMS0099 - Part 1 of 1.csv';
    $links = [array_replace($links[0], ['name' => $name, 'rows' => 3])];
    $manifest['options_sha256'] = hash('sha256', json_encode($options, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    $manifest['rows_per_file'] = 5;
    $manifest['parts'] = [['part' => 1, 'name' => $name, 'rows' => 3, 'bytes' => $links[0]['bytes'], 'sha256' => $links[0]['sha256']]];
    $manifest['download'] = ['name' => $name, 'format' => 'csv', 'bytes' => $links[0]['bytes'], 'sha256' => $links[0]['sha256']];
    $message = SmsRunEmailMessage::build($configuration, $manifest, $options, 3, $links, ['files.example.com']);
    expect($message['parts'])->toBe(1)->and($message['text'])->toContain('starting on 2024-02-29', '3 records in 1 file(s)');
    SmsRunEmailMessage::validate($message, ['files.example.com']);
});

test('SMS email rejects unsafe or unapproved external link URLs', function (string $url): void {
    $fixture = smsEmailMessageFixture(); $fixture[4][0]['url'] = $url;
    expect(fn () => SmsRunEmailMessage::build(...$fixture))->toThrow(InvalidArgumentException::class);
})->with([
    'plain http' => 'http://files.example.com/part1', 'other host' => 'https://other.example.com/part1',
    'subdomain bypass' => 'https://evil.files.example.com/part1', 'suffix bypass' => 'https://files.example.com.evil.com/part1',
    'userinfo' => 'https://someone@files.example.com/part1', 'password' => 'https://user:pass@files.example.com/part1',
    'port' => 'https://files.example.com:8443/part1', 'fragment' => 'https://files.example.com/part1#secret',
    'empty fragment' => 'https://files.example.com/part1#', 'localhost' => 'https://localhost/part1',
    'local subdomain' => 'https://files.localhost/part1', 'IPv4' => 'https://127.0.0.1/part1',
    'IPv6' => 'https://[::1]/part1', 'backslash' => 'https://files.example.com\\evil/part1',
    'whitespace' => 'https://files.example.com/part 1', 'newline' => "https://files.example.com/part1\n",
    'encoded newline' => 'https://files.example.com/part1%0aHeader', 'encoded backslash' => 'https://files.example.com/%5cevil',
    'javascript' => 'javascript:alert(1)', 'data' => 'data:text/html,anything',
    'trailing DNS dot' => 'https://files.example.com./part1',
]);

test('SMS email accepts explicit standard HTTPS port and escaped URL markup safely', function (): void {
    $fixture = smsEmailMessageFixture();
    $fixture[4][0]['url'] = 'https://files.example.com:443/part1?token="<tag>&v=1';
    $message = SmsRunEmailMessage::build(...$fixture);
    expect($message['html'])->toContain('token=&quot;&lt;tag&gt;&amp;v=1')->not->toContain('token="<tag>');
});

test('SMS email requires an exact nonempty DNS host allowlist', function (array $hosts): void {
    $fixture = smsEmailMessageFixture(); $fixture[5] = $hosts;
    expect(fn () => SmsRunEmailMessage::build(...$fixture))->toThrow(InvalidArgumentException::class);
})->with([[[]], [['*.example.com']], [['localhost']], [['files.localhost']], [['127.0.0.1']], [['files.example.com:443']], [[' files.example.com']], [['files.example.com.']], [['https://files.example.com']], [[null]]]);

test('SMS email rejects changed or pending file receipts and option contracts', function (Closure $change): void {
    $fixture = smsEmailMessageFixture(); $change($fixture);
    expect(fn () => SmsRunEmailMessage::build(...$fixture))->toThrow(InvalidArgumentException::class);
})->with([
    'legacy options' => fn (&$f) => $f[2] = SmsRunOptions::normalize(5, 2),
    'wrong version' => fn (&$f) => $f[2]['filename_version'] = SmsRunOptions::DROP_RANGE_FILENAME_VERSION,
    'zero selected' => fn (&$f) => $f[3] = 0,
    'exceeds target' => fn (&$f) => $f[3] = 6,
    'changed options hash' => fn (&$f) => $f[1]['options_sha256'] = str_repeat('a', 64),
    'changed row count' => fn (&$f) => $f[1]['rows'] = 5,
    'changed rows per file' => fn (&$f) => $f[1]['rows_per_file'] = 3,
    'changed CSV format' => fn (&$f) => $f[1]['csv_format'] = 'legacy',
    'missing link' => fn (&$f) => array_pop($f[4]),
    'extra link key' => fn (&$f) => $f[4][0]['secret'] = 'not allowed',
    'string bytes' => fn (&$f) => $f[4][0]['bytes'] = '123',
    'zero bytes' => fn (&$f) => $f[4][0]['bytes'] = 0,
    'bad hash' => fn (&$f) => $f[4][0]['sha256'] = 'unknown',
    'changed hash' => fn (&$f) => $f[4][0]['sha256'] = str_repeat('b', 64),
    'changed part' => fn (&$f) => $f[1]['parts'][0]['part'] = 2,
    'wrong rows' => fn (&$f) => $f[4][0]['rows'] = 1,
    'duplicate URL' => fn (&$f) => $f[4][1]['url'] = $f[4][0]['url'],
    'pending name' => fn (&$f) => $f[4][0]['name'] = str_replace('SMS0001-SMS0009', 'ID Pending', $f[4][0]['name']),
    'per-file range' => fn (&$f) => $f[4][1]['name'] = str_replace('SMS0001-SMS0009', 'SMS0002-SMS0009', $f[4][1]['name']),
    'download receipt' => fn (&$f) => $f[1]['download']['name'] = 'pending.zip',
]);

test('SMS email rejects malformed recipients or sender rather than silently dropping recipients', function (Closure $change): void {
    $fixture = smsEmailMessageFixture(); $change($fixture[0]);
    expect(fn () => SmsRunEmailMessage::build(...$fixture))->toThrow(InvalidArgumentException::class);
})->with([
    'wrong sender' => fn (&$c) => $c['from'] = 'other@lendingtower.com',
    'no To' => fn (&$c) => $c['to'] = [],
    'invalid email' => fn (&$c) => $c['to'][] = 'not-email',
    'whitespace email' => fn (&$c) => $c['to'][] = ' someone@example.com',
    'duplicate case' => fn (&$c) => $c['cc'][] = 'JUDE@LENDINGTOWER.COM',
    'header injection' => fn (&$c) => $c['to'][] = "someone@example.com\nBcc:other@example.com",
    'no companies' => fn (&$c) => $c['companies'] = [],
    'blank company' => fn (&$c) => $c['companies'] = [''],
    'blank report' => fn (&$c) => $c['report_name'] = '',
]);

test('SMS email stored message validation detects tampered content and extra fields', function (string $field): void {
    $message = SmsRunEmailMessage::build(...smsEmailMessageFixture());
    $message[$field] = $field === 'parts' ? 3 : 'tampered';
    expect(fn () => SmsRunEmailMessage::validate($message, ['files.example.com']))->toThrow(InvalidArgumentException::class);
})->with(['subject', 'text', 'html', 'parts', 'extra']);
