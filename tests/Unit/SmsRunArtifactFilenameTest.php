<?php

declare(strict_types=1);

use Cmd\Reports\Services\SmsRunArtifactFilename;

test('saved SMS drop ranges use numeric order preserve padding and repeat a single drop', function (): void {
    expect(SmsRunArtifactFilename::rangeFromDrops([['Drop_Name' => 'SMS10000'], ['Drop_Name' => 'SMS9999'], ['Drop_Name' => 'SMS0002']]))
        ->toBe('SMS0002-SMS10000')
        ->and(SmsRunArtifactFilename::rangeFromDrops([['Drop_Name' => 'SMS0042']]))->toBe('SMS0042-SMS0042')
        ->and(SmsRunArtifactFilename::isDropRange('SMS99999999999999999999999999998-SMS99999999999999999999999999999'))->toBeTrue();
});

test('saved SMS drop names reject missing ambiguous or unsafe numbers before publication', function (array $drops): void {
    expect(fn () => SmsRunArtifactFilename::rangeFromDrops($drops))->toThrow(InvalidArgumentException::class);
})->with([[[]], [[['Drop_Name' => 'SMS0000']]], [[['Drop_Name' => 'SMS0001'], ['Drop_Name' => 'SMS1']]],
    [[['Drop_Name' => '../SMS0001']]], [[['Drop_Name' => 'SMS0001 ']]], [[['Drop_Name' => 'sms0001']]],
    [[['Drop_Name' => 'SMS'.str_repeat('9', 30)]]], [[['Drop_Name' => null]]]]);

test('drop range parser fails closed for reversed zero invalid or injected names', function (mixed $range): void {
    expect(SmsRunArtifactFilename::isDropRange($range))->toBeFalse();
})->with([['SMS10000-SMS9999'], ['SMS0000-SMS0001'], ['SMS0001-SMS0000'], ['SMS0001-SMS0009 '],
    ['SMS0001/SMS0009'], ['SMS0001-../SMS0009'], [null], [false], [1], [[]]]);

test('planned stems preserve old names and parse only exact bounded drop-range names', function (): void {
    expect(SmsRunArtifactFilename::parseStem('SMS Export - 2026-10-26'))->toBe(['send_date' => '2026-10-26', 'drop_name_range' => null])
        ->and(SmsRunArtifactFilename::parseStem('SMS Export - 2026-10-26 - SMS0001-SMS0009'))->toBe(['send_date' => '2026-10-26', 'drop_name_range' => 'SMS0001-SMS0009'])
        ->and(SmsRunArtifactFilename::stemFromDownload('SMS Export - 2026-10-26 - SMS0001-SMS0009 - Part 1 of 1.csv', 'csv'))->toBe('SMS Export - 2026-10-26 - SMS0001-SMS0009')
        ->and(SmsRunArtifactFilename::parseStem('SMS Export - 2026-02-29 - SMS0001-SMS0009'))->toBeNull()
        ->and(SmsRunArtifactFilename::parseStem('SMS Export - 2026-10-26 - SMS0009-SMS0001'))->toBeNull();
});

test('pending approval stems recognize only the literal placeholder and a strict calendar date', function (): void {
    expect(SmsRunArtifactFilename::parseStem('SMS Export - 2026-10-26 - ID Pending'))
        ->toBe(['send_date' => '2026-10-26', 'drop_name_range' => null, 'id_pending' => true])
        ->and(SmsRunArtifactFilename::stemFromDownload('SMS Export - 2026-10-26 - ID Pending - Part 1 of 1.csv', 'csv'))
        ->toBe('SMS Export - 2026-10-26 - ID Pending');
    foreach (['SMS Export - 2026-02-29 - ID Pending', 'SMS Export - 2026-10-26 - ID pending',
        'SMS Export - 2026-10-26 - ID Pending ', 'SMS Export - 2026-10-26 - ../ID Pending'] as $stem) {
        expect(SmsRunArtifactFilename::parseStem($stem))->toBeNull();
    }
});
