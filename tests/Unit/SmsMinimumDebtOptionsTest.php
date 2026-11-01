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
