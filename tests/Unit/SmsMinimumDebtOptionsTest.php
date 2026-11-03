<?php

declare(strict_types=1);

use Cmd\Reports\Services\SmsDebtPolicy;
use Cmd\Reports\Services\SmsRunOptions;

it('freezes a chosen minimum without upgrading old frozen options', function (): void {
    $old = SmsRunOptions::normalize(3000000, 1000000, null, SmsRunOptions::WINDOWED_TIER_SELECTION_POLICY,
        SmsRunOptions::PLANNED_DATE_VERSION, '2026-11-02', SmsRunOptions::APPROVAL_FILENAME_VERSION);
    expect($old)->not->toHaveKey('minimum_debt_amount');
    expect(SmsRunOptions::fromStored(json_encode($old, JSON_THROW_ON_ERROR), 3000000))->toBe($old);
    $new = SmsRunOptions::normalize(3000000, 1000000, null, SmsRunOptions::WINDOWED_TIER_SELECTION_POLICY,
        SmsRunOptions::PLANNED_DATE_VERSION, '2026-11-02', SmsRunOptions::APPROVAL_FILENAME_VERSION, 31000);
    expect($new['minimum_debt_amount'])->toBe(31000);
    expect(SmsRunOptions::fromStored(json_encode($new, JSON_THROW_ON_ERROR), 3000000))->toBe($new);
    expect(SmsDebtPolicy::minimum($old))->toBe(25000);
    expect(SmsDebtPolicy::minimum($new))->toBe(31000);
    expect(hash('sha256', json_encode($new)))->not->toBe(hash('sha256', json_encode($old)));
});

it('rejects minimums outside the business floor or supported source debt range', function (int $minimum): void {
    expect(fn () => SmsRunOptions::normalize(10, 10, null, SmsRunOptions::TIER_SELECTION_POLICY,
        null, null, null, $minimum))->toThrow(InvalidArgumentException::class);
})->with([24999, 0, -1, 10000000000]);

it('rejects a debt minimum without canonical source selection before quantity', function (): void {
    expect(fn () => SmsRunOptions::normalize(10, 10, null, null, null, null, null, 25000))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects corrupt stored minimums instead of treating them as absent', function (mixed $value): void {
    $options = SmsRunOptions::normalize(10, 10, null, SmsRunOptions::TIER_SELECTION_POLICY);
    $options['minimum_debt_amount'] = $value;
    expect(fn () => SmsRunOptions::fromStored(json_encode($options, JSON_THROW_ON_ERROR), 10))
        ->toThrow(InvalidArgumentException::class);
})->with([null, '25000', 25000.5, false, 24999]);

it('freezes an optional maximum after the minimum without upgrading minimum-only options', function (): void {
    $minimumOnly = SmsRunOptions::normalize(3000000, 1000000, null, SmsRunOptions::WINDOWED_TIER_SELECTION_POLICY,
        SmsRunOptions::PLANNED_DATE_VERSION, '2026-11-02', SmsRunOptions::APPROVAL_FILENAME_VERSION, 31000);
    expect($minimumOnly)->not->toHaveKey('maximum_debt_amount')
        ->and(SmsDebtPolicy::maximum($minimumOnly))->toBeNull()
        ->and(SmsDebtPolicy::bindings($minimumOnly))->toBe([31000]);
    $range = SmsRunOptions::normalize(3000000, 1000000, null, SmsRunOptions::WINDOWED_TIER_SELECTION_POLICY,
        SmsRunOptions::PLANNED_DATE_VERSION, '2026-11-02', SmsRunOptions::APPROVAL_FILENAME_VERSION, 31000, 85000);
    expect(array_slice(array_keys($range), -2))->toBe(['minimum_debt_amount', 'maximum_debt_amount'])
        ->and($range['maximum_debt_amount'])->toBe(85000)
        ->and(SmsRunOptions::fromStored(json_encode($range, JSON_THROW_ON_ERROR), 3000000))->toBe($range)
        ->and(SmsDebtPolicy::maximum($range))->toBe(85000)
        ->and(SmsDebtPolicy::bindings($range))->toBe([31000, 85000])
        ->and(SmsDebtPolicy::bindings(null))->toBe([25000]);
    // Equal bounds and both supported extremes are valid whole-thousand ranges.
    foreach ([[25000, 25000], [31000, 31000], [25000, SmsDebtPolicy::MAX], [SmsDebtPolicy::MAX, SmsDebtPolicy::MAX]] as [$minimum, $maximum]) {
        expect(SmsRunOptions::normalize(10, 10, null, SmsRunOptions::TIER_SELECTION_POLICY, null, null, null, $minimum, $maximum)['maximum_debt_amount'])->toBe($maximum);
    }
});

it('rejects maximums that are not whole thousands in range and at or above the minimum', function (int $minimum, int $maximum): void {
    expect(fn () => SmsRunOptions::normalize(10, 10, null, SmsRunOptions::TIER_SELECTION_POLICY, null, null, null, $minimum, $maximum))
        ->toThrow(InvalidArgumentException::class, SmsDebtPolicy::MAXIMUM_MESSAGE);
})->with([
    'not whole thousands' => [25000, 35500],
    'one dollar over a thousand' => [25000, 35001],
    'below floor' => [25000, 24000],
    'zero' => [25000, 0],
    'negative' => [25000, -1000],
    'above supported max' => [25000, SmsDebtPolicy::MAX + 1000],
    'below minimum' => [31000, 30000],
    'below non-thousand minimum' => [25500, 25000],
]);

it('rejects a maximum without a frozen minimum or source selection', function (): void {
    expect(fn () => SmsRunOptions::normalize(10, 10, null, SmsRunOptions::TIER_SELECTION_POLICY, null, null, null, null, 35000))
        ->toThrow(InvalidArgumentException::class, 'maximum debt');
    expect(fn () => SmsRunOptions::normalize(10, 10, null, null, null, null, null, null, 35000))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => SmsRunOptions::normalize(10, 10, null, null, null, null, null, 25000, 35000))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => SmsDebtPolicy::maximum(['maximum_debt_amount' => 35000]))->toThrow(InvalidArgumentException::class);
});

it('rejects corrupt or reordered stored maximums instead of treating them as absent', function (mixed $value): void {
    $options = SmsRunOptions::normalize(10, 10, null, SmsRunOptions::TIER_SELECTION_POLICY, null, null, null, 31000);
    $options['maximum_debt_amount'] = $value;
    expect(fn () => SmsRunOptions::fromStored(json_encode($options, JSON_THROW_ON_ERROR), 10))
        ->toThrow(InvalidArgumentException::class);
})->with([null, '35000', 35000.5, false, 35500, 30000, 10000000000]);

it('rejects a stored maximum without its minimum or ahead of it', function (): void {
    $range = SmsRunOptions::normalize(10, 10, null, SmsRunOptions::TIER_SELECTION_POLICY, null, null, null, 31000, 35000);
    $withoutMinimum = array_diff_key($range, ['minimum_debt_amount' => true]);
    $reordered = array_diff_key($range, ['minimum_debt_amount' => true]) + ['minimum_debt_amount' => 31000];
    foreach ([$withoutMinimum, $reordered] as $stored) {
        expect(fn () => SmsRunOptions::fromStored(json_encode($stored, JSON_THROW_ON_ERROR), 10))->toThrow(InvalidArgumentException::class);
    }
});
