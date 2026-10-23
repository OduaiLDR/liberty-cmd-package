<?php

declare(strict_types=1);

use Cmd\Reports\Services\SmsRunHandoffPreview;
use Cmd\Reports\Services\SmsRunOptions;

function smsHandoffPreviewFixture(): array
{
    return [
        'request_id' => 'dd5bfa17-c1fa-4a9d-84f0-c308f4db615e', 'status' => 'file_ready',
        'target' => 10, 'selected' => 3,
        'options' => ['options_version' => SmsRunOptions::PLANNED_DATE_VERSION, 'send_date' => '2026-10-26', 'csv_format' => SmsRunOptions::CSV_FORMAT],
        'file' => ['format' => 'zip', 'parts' => 2, 'files' => [
            ['name' => 'SMS Export - 2026-10-26 - Part 1 of 2.csv', 'rows' => 2],
            ['name' => 'SMS Export - 2026-10-26 - Part 2 of 2.csv', 'rows' => 1],
        ]],
    ];
}

function smsDropRangeHandoffPreviewFixture(string $range = 'SMS0001-SMS0009'): array
{
    $view = smsHandoffPreviewFixture();
    $view['options']['filename_version'] = SmsRunOptions::DROP_RANGE_FILENAME_VERSION;
    $view['file']['drop_name_range'] = $range;
    foreach ($view['file']['files'] as $index => &$part) {
        $part['name'] = 'SMS Export - 2026-10-26 - '.$range.' - Part '.($index + 1).' of 2.csv';
    }
    unset($part);

    return $view;
}

test('planned SMS handoff is an honest preview with dynamic recipients unresolved and actual counts', function (): void {
    $view = smsHandoffPreviewFixture();
    $result = SmsRunHandoffPreview::forRun($view);
    expect($result['email_preview'])->toBe([
        'from' => 'admin@lendingtower.com',
        'to' => [],
        'body' => "Here are the SMS files to process starting on 2026-10-26. There are 3 records in 2 file(s).\n\nDownload Links\nFile 1 - SMS Export - 2026-10-26 - Part 1 of 2.csv: [Download link pending]\nFile 2 - SMS Export - 2026-10-26 - Part 2 of 2.csv: [Download link pending]\n\nLet us know if there are any issues.",
        'files' => [
            ['name' => 'SMS Export - 2026-10-26 - Part 1 of 2.csv', 'rows' => 2, 'link' => null],
            ['name' => 'SMS Export - 2026-10-26 - Part 2 of 2.csv', 'rows' => 1, 'link' => null],
        ], 'available' => true, 'reason' => null,
    ])->and($result['email']['status'])->toBe('not_configured')
        ->and($result['debtplete_import']['status'])->toBe('not_configured')
        ->and($result['debtplete_import']['processed'])->toBeNull()
        ->and($result['debtplete_import']['total'])->toBe(3)
        ->and($result['email']['note'])->toContain('does not send email', 'does not establish past email activity')
        ->and($result['debtplete_import']['note'])->toContain('No records have been uploaded')
        ->and($view)->toBe(smsHandoffPreviewFixture());
});

test('old SMS runs never acquire inferred handoff information', function (mixed $options): void {
    $view = smsHandoffPreviewFixture();
    $view['options'] = $options;
    expect(SmsRunHandoffPreview::forRun($view))->toBeNull();
})->with([[null], [[]], [['send_date' => '2026-10-26']], [['options_version' => 'unknown']], [(object) ['options_version' => SmsRunOptions::PLANNED_DATE_VERSION]]]);

test('malformed planned SMS run data remains unavailable without fabricated progress', function (array $changes): void {
    $view = array_replace_recursive(smsHandoffPreviewFixture(), $changes);
    $result = SmsRunHandoffPreview::forRun($view);
    expect($result['email_preview']['available'])->toBeFalse()
        ->and($result['email_preview']['body'])->toBeNull()
        ->and($result['email_preview']['files'])->toBe([])
        ->and($result['email_preview']['reason'])->toBeString()
        ->and($result['email']['status'])->toBe('not_configured')
        ->and($result['debtplete_import']['status'])->toBe('not_configured')
        ->and($result['debtplete_import']['processed'])->toBeNull();
})->with([
    'null selected' => [['selected' => null]],
    'zero selected' => [['selected' => 0]],
    'negative selected' => [['selected' => -1]],
    'string selected' => [['selected' => '3']],
    'float selected' => [['selected' => 3.0]],
    'boolean selected' => [['selected' => true]],
    'oversize selected' => [['selected' => 10000001]],
    'absent date' => [['options' => ['send_date' => null]]],
    'invalid date' => [['options' => ['send_date' => '2026-02-30']]],
    'date injection' => [['options' => ['send_date' => "2026-10-26\nBcc: other@example.com"]]],
    'datetime not date' => [['options' => ['send_date' => '2026-10-26T00:00:00Z']]],
    'missing format' => [['options' => ['csv_format' => null]]],
    'legacy format' => [['options' => ['csv_format' => 'legacy-nine-column-v1']]],
    'unbuilt status' => [['status' => 'review']],
    'discarded status' => [['status' => 'discarded']],
    'unknown status' => [['status' => 'sent']],
    'missing file' => [['file' => null]],
    'bad file list' => [['file' => ['files' => 'not a list']]],
    'parts mismatch' => [['file' => ['parts' => 3]]],
    'parts string' => [['file' => ['parts' => '2']]],
    'format mismatch' => [['file' => ['format' => 'csv']]],
    'row sum mismatch' => [['file' => ['files' => [['rows' => 1]]]]],
    'negative rows' => [['file' => ['files' => [['rows' => -1]]]]],
    'zero rows' => [['file' => ['files' => [['rows' => 0]]]]],
    'string rows' => [['file' => ['files' => [['rows' => '2']]]]],
    'object part' => [['file' => ['files' => [(object) ['name' => 'ignored', 'rows' => 2]]]]],
    'path traversal' => [['file' => ['files' => [['name' => '../SMS Export - 2026-10-26 - Part 1 of 2.csv']]]]],
    'wrong date in name' => [['file' => ['files' => [['name' => 'SMS Export - 2026-10-27 - Part 1 of 2.csv']]]]],
    'wrong part in name' => [['file' => ['files' => [['name' => 'SMS Export - 2026-10-26 - Part 2 of 2.csv']]]]],
]);

test('planned SMS preview accepts one file and historical calendar dates without host clock inference', function (): void {
    $view = smsHandoffPreviewFixture();
    $view['status'] = 'approved';
    $view['options']['send_date'] = '2024-02-29';
    $view['file'] = ['format' => 'csv', 'parts' => 1, 'files' => [['name' => 'SMS Export - 2024-02-29 - Part 1 of 1.csv', 'rows' => 3]]];
    $result = SmsRunHandoffPreview::forRun($view);
    expect($result['email_preview']['available'])->toBeTrue()
        ->and($result['email_preview']['body'])->toContain('starting on 2024-02-29', '3 records in 1 file(s)')
        ->and($result['email']['status'])->toBe('not_configured');
});

test('planned SMS preview never forwards internal links secrets filenames or unknown state fields', function (): void {
    $view = smsHandoffPreviewFixture();
    $view['file']['url'] = 'https://internal.invalid/download?signature=private';
    $view['file']['name'] = 'private-internal.zip';
    $view['file']['files'][0]['link'] = 'https://internal.invalid/part?signature=private';
    $view['email'] = ['status' => 'sent'];
    $view['debtplete_import'] = ['status' => 'completed', 'processed' => 3];
    $encoded = json_encode(SmsRunHandoffPreview::forRun($view), JSON_THROW_ON_ERROR);
    expect($encoded)->not->toContain('internal.invalid', 'signature', 'private-internal', 'completed', '"sent"')
        ->and($encoded)->toContain('[Download link pending]', 'not_configured');
});

test('planned SMS preview rejects empty associative sparse or excessive file lists', function (): void {
    foreach ([[], ['part' => ['name' => 'no', 'rows' => 3]], [1 => ['name' => 'no', 'rows' => 3]], array_fill(0, 1001, ['name' => 'no', 'rows' => 1])] as $files) {
        $view = smsHandoffPreviewFixture();
        $view['file']['files'] = $files;
        $view['file']['parts'] = count($files);
        expect(SmsRunHandoffPreview::forRun($view)['email_preview']['available'])->toBeFalse();
    }
});

test('planned SMS preview distinguishes unknown selection from a known selected total', function (): void {
    $view = smsHandoffPreviewFixture();
    $view['file'] = null;
    expect(SmsRunHandoffPreview::forRun($view)['debtplete_import']['total'])->toBe(3);
    $view['selected'] = null;
    expect(SmsRunHandoffPreview::forRun($view)['debtplete_import']['total'])->toBeNull();
});

test('drop-range SMS preview uses the validated run-wide saved range on every part', function (): void {
    $view = smsDropRangeHandoffPreviewFixture();
    $result = SmsRunHandoffPreview::forRun($view);
    expect($result['email_preview']['available'])->toBeTrue()
        ->and(array_column($result['email_preview']['files'], 'name'))->toBe([
            'SMS Export - 2026-10-26 - SMS0001-SMS0009 - Part 1 of 2.csv',
            'SMS Export - 2026-10-26 - SMS0001-SMS0009 - Part 2 of 2.csv',
        ])->and($result['email_preview']['body'])->toContain('There are 3 records in 2 file(s).',
            'File 1 - SMS Export - 2026-10-26 - SMS0001-SMS0009 - Part 1 of 2.csv: [Download link pending]',
            'File 2 - SMS Export - 2026-10-26 - SMS0001-SMS0009 - Part 2 of 2.csv: [Download link pending]')
        ->and($result['email']['status'])->toBe('not_configured')
        ->and($result['debtplete_import']['processed'])->toBeNull()
        ->and($view)->toBe(smsDropRangeHandoffPreviewFixture());
});

test('drop-range SMS preview preserves a single saved drop and larger saved number padding', function (string $range): void {
    $view = smsDropRangeHandoffPreviewFixture($range);
    $view['file']['format'] = 'csv';
    $view['file']['parts'] = 1;
    $view['file']['files'] = [['name' => 'SMS Export - 2026-10-26 - '.$range.' - Part 1 of 1.csv', 'rows' => 3]];
    $result = SmsRunHandoffPreview::forRun($view);
    expect($result['email_preview']['available'])->toBeTrue()
        ->and($result['email_preview']['files'][0]['name'])->toBe($view['file']['files'][0]['name'])
        ->and($result['email_preview']['body'])->toContain('3 records in 1 file(s)');
})->with(['SMS0007-SMS0007', 'SMS09999-SMS10000', 'SMS99999999999999999999999999998-SMS99999999999999999999999999999']);

test('drop-range SMS preview fails closed for missing invalid descending or injected metadata', function (mixed $range): void {
    $view = smsDropRangeHandoffPreviewFixture();
    $view['file']['drop_name_range'] = $range;
    $result = SmsRunHandoffPreview::forRun($view);
    expect($result['email_preview']['available'])->toBeFalse()
        ->and($result['email_preview']['body'])->toBeNull()
        ->and($result['email_preview']['files'])->toBe([])
        ->and($result['debtplete_import']['total'])->toBe(3);
})->with([[null], [''], [false], [1], [[]], [(object) ['first' => 'SMS0001', 'last' => 'SMS0009']],
    ['SMS0009-SMS0001'], ['SMS0000-SMS0009'], ['SMS0001-SMS0000'], ['SMS0001'],
    ['sms0001-sms0009'], ['SMS0001 - SMS0009'], ['../SMS0001-SMS0009'],
    ["SMS0001-SMS0009\n"], ['SMS0001-SMS999999999999999999999999999999']]);

test('drop-range SMS preview rejects mismatched filenames dates ranges and ordering', function (int $index, string $name): void {
    $view = smsDropRangeHandoffPreviewFixture();
    $view['file']['files'][$index]['name'] = $name;
    $result = SmsRunHandoffPreview::forRun($view);
    expect($result['email_preview']['available'])->toBeFalse()
        ->and($result['email_preview']['body'])->toBeNull();
})->with([
    [0, 'SMS Export - 2026-10-27 - SMS0001-SMS0009 - Part 1 of 2.csv'],
    [0, 'SMS Export - 2026-10-26 - SMS0002-SMS0009 - Part 1 of 2.csv'],
    [1, 'SMS Export - 2026-10-26 - SMS0001-SMS0008 - Part 2 of 2.csv'],
    [0, 'SMS Export - 2026-10-26 - SMS0001-SMS0009 - Part 2 of 2.csv'],
    [1, 'SMS Export - 2026-10-26 - SMS0001-SMS0009 - Part 1 of 2.csv'],
    [0, 'SMS Export - 2026-10-26 - SMS1-SMS9 - Part 1 of 2.csv'],
    [0, 'SMS Export - 2026-10-26 - Part 1 of 2.csv'],
]);

test('drop-range filename version is explicit and never upgrades date-only retained runs', function (): void {
    $old = smsHandoffPreviewFixture();
    $old['file']['drop_name_range'] = 'SMS0001-SMS0009';
    expect(SmsRunHandoffPreview::forRun($old)['email_preview']['available'])->toBeTrue();

    $new = smsDropRangeHandoffPreviewFixture();
    unset($new['options']['filename_version']);
    expect(SmsRunHandoffPreview::forRun($new)['email_preview']['available'])->toBeFalse();
    foreach ([null, false, '', 'unknown'] as $version) {
        $new['options']['filename_version'] = $version;
        expect(SmsRunHandoffPreview::forRun($new)['email_preview']['available'])->toBeFalse();
    }
    $new = smsDropRangeHandoffPreviewFixture();
    unset($new['file']['drop_name_range']);
    $new['drops'] = [['Drop_Name' => 'SMS0001'], ['Drop_Name' => 'SMS0009']];
    expect(SmsRunHandoffPreview::forRun($new)['email_preview']['available'])->toBeFalse();
});

test('approval-time preview uses ID Pending until the final approved publication exists', function (string $status): void {
    $view = smsHandoffPreviewFixture();
    $view['status'] = $status;
    $view['options']['filename_version'] = SmsRunOptions::APPROVAL_FILENAME_VERSION;
    $view['file']['drop_name_range'] = null;
    foreach ($view['file']['files'] as $index => &$part) {
        $part['name'] = 'SMS Export - 2026-10-26 - ID Pending - Part '.($index + 1).' of 2.csv';
    }
    unset($part);
    $result = SmsRunHandoffPreview::forRun($view);
    expect($result['email_preview']['available'])->toBeTrue()
        ->and($result['email_preview']['body'])->toContain('ID Pending - Part 1 of 2.csv', 'ID Pending - Part 2 of 2.csv')
        ->and($result['email']['note'])->toContain('SMS drop IDs are assigned only on approval')
        ->and($result['email']['status'])->toBe('not_configured');
    $view['file']['drop_name_range'] = 'SMS0001-SMS0009';
    expect(SmsRunHandoffPreview::forRun($view)['email_preview']['available'])->toBeFalse();
})->with(['file_ready', 'approving']);

test('approved-time preview requires final range names and never sends the pending preview as final', function (): void {
    $view = smsDropRangeHandoffPreviewFixture();
    $view['options']['filename_version'] = SmsRunOptions::APPROVAL_FILENAME_VERSION;
    $view['status'] = 'approved';
    expect(SmsRunHandoffPreview::forRun($view)['email_preview']['available'])->toBeTrue();
    $view['file']['drop_name_range'] = null;
    expect(SmsRunHandoffPreview::forRun($view)['email_preview']['available'])->toBeFalse();
    $view['file']['drop_name_range'] = 'SMS0001-SMS0009';
    $view['file']['files'][0]['name'] = 'SMS Export - 2026-10-26 - ID Pending - Part 1 of 2.csv';
    expect(SmsRunHandoffPreview::forRun($view)['email_preview']['available'])->toBeFalse();
});
