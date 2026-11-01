<?php

declare(strict_types=1);

use Cmd\Reports\Services\SmsDeliveryPolicy;
use Cmd\Reports\Services\SmsRunOptions;

test('the versioned vendor format has exactly the four approved columns', function (): void {
    expect(SmsDeliveryPolicy::headerForFormat(SmsRunOptions::CSV_FORMAT))
        ->toBe(['First name', 'debt load', 'phone1', 'send date']);

    $row = SmsDeliveryPolicy::rowForFormat(SmsRunOptions::CSV_FORMAT, '  Synthetic Person  ',
        'PRIVATE ADDRESS, OMIT THIS', '13500.01', '0202555010', '2026-10-26');
    expect($row)->toBe(['Synthetic', '14000', '0202555010', '2026-10-26'])
        ->toHaveCount(4)->not->toContain('PRIVATE ADDRESS, OMIT THIS');
});

test('the four-column format preserves exact debt boundaries and field positions', function (string $amount, string $rounded): void {
    $row = SmsDeliveryPolicy::rowForFormat(SmsRunOptions::CSV_FORMAT, 'Ann Lee', '1, Test St', $amount, '0123456789', '2026-11-02');
    expect($row)->toBe(['Ann', $rounded, '0123456789', '2026-11-02']);
})->with([
    ['0.01', '1000'], ['999.99', '1000'], ['1000', '1000'], ['1000.00', '1000'],
    ['1000.01', '2000'], ['10499.99', '11000'], ['10500', '11000'],
    ['000010.10', '1000'], ['9999999000.00', '9999999000'],
]);

test('the four-column format refuses invalid or overflowing debt instead of emitting it', function (string $amount): void {
    expect(fn () => SmsDeliveryPolicy::rowForFormat(SmsRunOptions::CSV_FORMAT, 'Ann Lee', '', $amount, '0123456789', '2026-10-26'))
        ->toThrow(InvalidArgumentException::class);
})->with(['0', '-1', '1e4', '', '1000.001', 'NaN', '9999999000.01', '9999999999.99', '10000000000']);

test('unknown or absent versioned formats cannot silently select a layout', function (string $format): void {
    expect(fn () => SmsDeliveryPolicy::headerForFormat($format))->toThrow(InvalidArgumentException::class, 'format');
    expect(fn () => SmsDeliveryPolicy::rowForFormat($format, 'Ann Lee', '1 Test St', '1000', '0123456789', '2026-10-26'))
        ->toThrow(InvalidArgumentException::class, 'format');
})->with(['', 'four', 'sms-four-column-v2', 'SMS-FOUR-COLUMN-V1', ' sms-four-column-v1', 'legacy', 'legacy-nine-column-v1 ']);

test('explicit legacy formats retain byte-equivalent headers and rows', function (bool $singlePhone, string $format): void {
    $header = SmsDeliveryPolicy::header($singlePhone);
    $row = SmsDeliveryPolicy::row('Ann Lee', '1, Test St', '1000.01', '0123456789', '2026-10-26', $singlePhone);
    $expectedHeader = ['First name', 'address', 'debt load', 'phone1', ...($singlePhone ? [] : ['phone2', 'phone3', 'phone4', 'phone5']), 'send date'];
    $expectedRow = ['Ann', '1, Test St', '2000', '0123456789', ...($singlePhone ? [] : ['', '', '', '']), '2026-10-26'];
    expect($header)->toBe($expectedHeader)->and($row)->toBe($expectedRow);
    expect(SmsDeliveryPolicy::headerForFormat($format))->toBe($header);
    expect(SmsDeliveryPolicy::rowForFormat($format, 'Ann Lee', '1, Test St', '1000.01', '0123456789', '2026-10-26'))->toBe($row);

    $csv = static function (array $header, array $row): string {
        $stream = fopen('php://memory', 'w+');
        try {
            fputcsv($stream, $header, ',', '"', '', "\r\n");
            fputcsv($stream, $row, ',', '"', '', "\r\n");
            rewind($stream);
            return stream_get_contents($stream);
        } finally { fclose($stream); }
    };
    expect($csv(SmsDeliveryPolicy::headerForFormat($format),
        SmsDeliveryPolicy::rowForFormat($format, 'Ann Lee', '1, Test St', '1000.01', '0123456789', '2026-10-26')))
        ->toBe($csv($expectedHeader, $expectedRow));
})->with([[false, 'legacy-nine-column-v1'], [true, 'legacy-five-column-v1']]);
