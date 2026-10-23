<?php

declare(strict_types=1);

use Cmd\Reports\Services\SmsRunOptions;

test('new run options default to one file and every tier with an explicit new format', function (): void {
    expect(SmsRunOptions::normalize(3000000))->toBe([
        'target' => 3000000,
        'rows_per_file' => 3000000,
        'tiers' => ['T1', 'T2', 'T3', 'T4', 'T5', 'T6', 'T7', 'T8', 'T9'],
        'csv_format' => 'sms-four-column-v1',
    ]);
    expect(SmsRunOptions::normalize(3000000, null, null))->toBe(SmsRunOptions::normalize(3000000));
});

test('file size is independent of total quantity at both allowed boundaries', function (int $target, int $rowsPerFile): void {
    $options = SmsRunOptions::normalize($target, $rowsPerFile);
    expect($options['target'])->toBe($target)->and($options['rows_per_file'])->toBe($rowsPerFile);
})->with([[1, 1], [1, 10000000], [10000000, 1], [10000000, 10000000], [3000000, 1000000], [7, 3]]);

test('invalid targets are rejected', function (int $target): void {
    expect(fn () => SmsRunOptions::normalize($target))->toThrow(InvalidArgumentException::class, 'Target');
})->with([0, -1, 10000001, PHP_INT_MAX, PHP_INT_MIN]);

test('invalid file sizes are rejected rather than clamped or replaced by the target', function (int $rowsPerFile): void {
    expect(fn () => SmsRunOptions::normalize(5, $rowsPerFile))->toThrow(InvalidArgumentException::class, 'Rows per file');
})->with([0, -1, 10000001, PHP_INT_MAX, PHP_INT_MIN]);

test('tier order is canonical without mutating the caller choices', function (): void {
    $tiers = ['T9', 'T2', 'T1'];
    expect(SmsRunOptions::normalize(5, 2, $tiers)['tiers'])->toBe(['T1', 'T2', 'T9']);
    expect($tiers)->toBe(['T9', 'T2', 'T1']);
    foreach (SmsRunOptions::TIERS as $tier) {
        expect(SmsRunOptions::normalize(1, 1, [$tier])['tiers'])->toBe([$tier]);
    }
});

test('explicit tier choices must be nonempty unique allowed strings in a list', function (array $tiers): void {
    expect(fn () => SmsRunOptions::normalize(5, 2, $tiers))->toThrow(InvalidArgumentException::class);
})->with([
    'empty' => [[]],
    'duplicate' => [['T1', 'T1']],
    'unknown high' => [['T10']],
    'unknown low' => [['T0']],
    'wrong case' => [['t1']],
    'leading space' => [[' T1']],
    'trailing space' => [['T1 ']],
    'numeric' => [[1]],
    'null member' => [[null]],
    'boolean' => [[true]],
    'nested array' => [[['T1']]],
    'object member' => [[(object) ['tier' => 'T1']]],
    'associative' => [['tier' => 'T1']],
    'sparse' => [[1 => 'T1']],
]);

test('canonical serialization compares every frozen choice for request-ID idempotency', function (): void {
    $serialize = static fn (array $options): string => json_encode($options, JSON_THROW_ON_ERROR);
    $base = SmsRunOptions::normalize(7, 3, ['T1', 'T9']);
    expect($serialize(SmsRunOptions::normalize(7, 3, ['T9', 'T1'])))->toBe($serialize($base));
    foreach ([SmsRunOptions::normalize(8, 3, ['T1', 'T9']), SmsRunOptions::normalize(7, 4, ['T1', 'T9']),
        SmsRunOptions::normalize(7, 3, ['T1']), array_replace($base, ['csv_format' => 'legacy-nine-column-v1']),
        array_diff_key($base, ['csv_format' => true])] as $changed) {
        expect($serialize($changed))->not->toBe($serialize($base));
    }
    expect($serialize(SmsRunOptions::normalize(7)))->toBe($serialize(SmsRunOptions::normalize(7, 7, array_reverse(SmsRunOptions::TIERS))));
});

test('source-first selector is explicit and never inferred for old options or their hashes', function (): void {
    $old = SmsRunOptions::normalize(7, 3);
    $oldJson = json_encode($old, JSON_THROW_ON_ERROR);
    $new = SmsRunOptions::normalize(7, 3, ['T9', 'T2'], SmsRunOptions::TIER_SELECTION_POLICY);
    expect(array_keys($new))->toBe(['target','rows_per_file','tiers','csv_format','tier_selection_policy'])
        ->and($new['tiers'])->toBe(['T2','T9'])
        ->and(SmsRunOptions::fromStored(json_encode($new), 7))->toBe($new)
        ->and(SmsRunOptions::fromStored($oldJson, 7))->toBe($old)
        ->and(json_encode(SmsRunOptions::fromStored($oldJson, 7), JSON_THROW_ON_ERROR))->toBe($oldJson)
        ->and(SmsRunOptions::fromStored(null, 7))->toBeNull();
    expect(SmsRunOptions::normalize(7, 3, null, SmsRunOptions::TIER_SELECTION_POLICY)['tiers'])->toBe(SmsRunOptions::TIERS);
    expect(fn () => SmsRunOptions::normalize(7, 3, ['T2'], 'unknown'))->toThrow(InvalidArgumentException::class);
});

test('stored selector shape and marker cannot be null unknown or silently reordered', function (mixed $marker): void {
    $options = SmsRunOptions::normalize(7, 3);
    $options['tier_selection_policy'] = $marker;
    expect(fn () => SmsRunOptions::fromStored(json_encode($options), 7))->toThrow(InvalidArgumentException::class);
})->with([[null], [''], ['unknown'], [1], [false], [[]]]);

test('planned-date options append an explicit frozen version without changing historical serialization', function (): void {
    $old = SmsRunOptions::normalize(7, 3, null, SmsRunOptions::TIER_SELECTION_POLICY);
    $json = json_encode($old, JSON_THROW_ON_ERROR);
    $new = SmsRunOptions::normalize(7, 3, null, SmsRunOptions::TIER_SELECTION_POLICY, SmsRunOptions::PLANNED_DATE_VERSION, '2026-11-03');
    expect(array_keys($new))->toBe([...array_keys($old), 'options_version', 'send_date'])
        ->and($new['send_date'])->toBe('2026-11-03')
        ->and(SmsRunOptions::fromStored(json_encode($new), 7))->toBe($new)
        ->and(json_encode(SmsRunOptions::fromStored($json, 7), JSON_THROW_ON_ERROR))->toBe($json);
    // Historical dates remain readable. Eligibility for NEW work is a service decision.
    $historical = SmsRunOptions::normalize(7, 3, null, null, SmsRunOptions::PLANNED_DATE_VERSION, '2020-01-01');
    expect(SmsRunOptions::fromStored(json_encode($historical), 7))->toBe($historical);
});

test('stored planned-date options fail closed for incomplete or noncanonical contracts', function (array $extra): void {
    $options = SmsRunOptions::normalize(7, 3) + $extra;
    expect(fn () => SmsRunOptions::fromStored(json_encode($options), 7))->toThrow(InvalidArgumentException::class);
})->with([
    [['send_date' => '2026-10-26']],
    [['options_version' => SmsRunOptions::PLANNED_DATE_VERSION]],
    [['options_version' => null, 'send_date' => '2026-10-26']],
    [['options_version' => 'unknown', 'send_date' => '2026-10-26']],
    [['options_version' => SmsRunOptions::PLANNED_DATE_VERSION, 'send_date' => '2026-02-29']],
    [['options_version' => SmsRunOptions::PLANNED_DATE_VERSION, 'send_date' => null]],
    [['send_date' => '2026-10-26', 'options_version' => SmsRunOptions::PLANNED_DATE_VERSION]],
]);

test('drop-range filename version is explicit appended and never inferred for older options', function (): void {
    $old = SmsRunOptions::normalize(7, 3, null, null, SmsRunOptions::PLANNED_DATE_VERSION, '2026-10-26');
    $new = SmsRunOptions::normalize(7, 3, null, null, SmsRunOptions::PLANNED_DATE_VERSION, '2026-10-26', SmsRunOptions::DROP_RANGE_FILENAME_VERSION);
    expect($new)->toBe($old + ['filename_version' => SmsRunOptions::DROP_RANGE_FILENAME_VERSION])
        ->and(SmsRunOptions::fromStored(json_encode($new), 7))->toBe($new)
        ->and(SmsRunOptions::fromStored(json_encode($old), 7))->toBe($old);
    foreach ([null, '', 'unknown', 1, false] as $bad) {
        expect(fn () => SmsRunOptions::fromStored(json_encode($old + ['filename_version' => $bad]), 7))->toThrow(InvalidArgumentException::class);
    }
    expect(fn () => SmsRunOptions::normalize(7, 3, null, null, null, null, SmsRunOptions::DROP_RANGE_FILENAME_VERSION))->toThrow(InvalidArgumentException::class);
});

test('approval-time filename version is explicit and preserves every older canonical options hash', function (): void {
    $base = SmsRunOptions::normalize(7, 3, null, null, SmsRunOptions::PLANNED_DATE_VERSION, '2026-10-26');
    $old = $base + ['filename_version' => SmsRunOptions::DROP_RANGE_FILENAME_VERSION];
    $new = SmsRunOptions::normalize(7, 3, null, null, SmsRunOptions::PLANNED_DATE_VERSION, '2026-10-26', SmsRunOptions::APPROVAL_FILENAME_VERSION);
    expect($new)->toBe($base + ['filename_version' => SmsRunOptions::APPROVAL_FILENAME_VERSION])
        ->and(SmsRunOptions::fromStored(json_encode($new), 7))->toBe($new)
        ->and(SmsRunOptions::fromStored(json_encode($old), 7))->toBe($old)
        ->and(SmsRunOptions::fromStored(json_encode($base), 7))->toBe($base);
});
