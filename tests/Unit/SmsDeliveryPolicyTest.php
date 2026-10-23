<?php

use Carbon\Carbon;
use Cmd\Reports\Services\SmsCurrentDebtSql;
use Cmd\Reports\Services\SmsDeliveryPolicy;

test('debt load rounds upward with exact decimal boundaries', function ($input, $expected) {
    expect(SmsDeliveryPolicy::debtLoad($input))->toBe($expected);
})->with([
    ['0.01', '1000'], ['999.99', '1000'], ['1000', '1000'], ['1000.00', '1000'],
    ['1000.01', '2000'], ['10499.99', '11000'], ['10500', '11000'], ['10000.01', '11000'],
    ['000010.10', '1000'], ['9999999000.00', '9999999000'],
]);

test('invalid and overflowing debts cannot appear in a vendor file', function ($input) {
    expect(fn () => SmsDeliveryPolicy::debtLoad($input))->toThrow(InvalidArgumentException::class);
})->with(['0', '-1', '1e4', '', '1000.001', 'NaN', '9999999000.01', '9999999999.99', '10000000000']);

test('send date is strictly the following California Monday including DST and UTC boundary', function ($instant, $expected) {
    expect(SmsDeliveryPolicy::followingMonday(Carbon::parse($instant))->toDateString())->toBe($expected);
})->with([
    ['2026-10-21T16:00:00Z', '2026-10-26'],
    ['2026-10-26T17:00:00Z', '2026-11-02'],
    ['2026-10-26T06:30:00Z', '2026-10-26'],
    ['2026-11-01T09:30:00Z', '2026-11-02'],
    ['2026-12-31T18:00:00Z', '2027-01-04'],
]);

test('next Monday does not mutate its caller', function () {
    $at = Carbon::parse('2026-10-21T16:00:00Z');
    SmsDeliveryPolicy::followingMonday($at);
    expect($at->toIso8601String())->toBe('2026-10-21T16:00:00+00:00');
});

test('explicit SMS dates accept California today or a future non-Monday without changing the date', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-26T06:30:00Z'));
    try {
        expect(SmsDeliveryPolicy::assertNotPast('2026-10-25')->toDateString())->toBe('2026-10-25')
            ->and(SmsDeliveryPolicy::assertNotPast('2026-11-03')->toDateString())->toBe('2026-11-03')
            ->and(SmsDeliveryPolicy::plannedDate('2024-02-29')->toDateString())->toBe('2024-02-29');
        expect(fn () => SmsDeliveryPolicy::assertNotPast('2026-10-24'))->toThrow(InvalidArgumentException::class, 'California');
    } finally { Carbon::setTestNow(); }
});

test('planned SMS dates reject permissive date parsing or invalid calendar dates', function (string $date): void {
    expect(fn () => SmsDeliveryPolicy::plannedDate($date))->toThrow(InvalidArgumentException::class);
})->with(['2026-02-29', '2026-11-31', '2026-1-01', '2026-01-1', '2026-10-26T00:00:00Z',
    'next monday', '', ' 2026-10-26', '2026-10-26 ', "2026-10-26\n", '0000-01-01']);

test('both vendor layouts contain only one populated phone and rounded debt', function () {
    foreach ([false => 9, true => 5] as $single => $count) {
        $row = SmsDeliveryPolicy::row('Synthetic Person', '1 Test St', '13500.01', '2025550100', '2026-10-26', (bool) $single);
        expect(SmsDeliveryPolicy::header((bool) $single))->toHaveCount($count);
        expect($row)->toHaveCount($count)->and($row[2])->toBe('14000')->and($row[3])->toBe('2025550100');
        expect($row[$count - 1])->toBe('2026-10-26');
    }
});

test('debt resolution uses the confirmed waterfall and rejects obsolete mailer clocks', function () {
    foreach (['', 'Update_Date', 'Creation_Date', 'PK', 'Send_Date;DROP TABLE TblProspects'] as $date) {
        expect(fn () => SmsCurrentDebtSql::resolve($date))->toThrow(RuntimeException::class);
    }
    $sql = SmsCurrentDebtSql::resolve();
    expect($sql)->toBe(SmsCurrentDebtSql::resolve('Send_Date'))
        ->toContain('MIN(Send_Date)', 'Source_Date DESC', 'r.Unknown_Date=0', 'r.Min_Debt=r.Max_Debt',
            'WHERE NOT EXISTS(SELECT 1 FROM TU', 'FROM Mailers WHERE Debt>0', 'WHERE Stage=Chosen_Stage', "'Tier', m.Source_PK")
        ->not->toContain('p.Tier', 'p.Debt_Tier', 'Update_Date', 'NOLOCK');
    expect(SmsCurrentDebtSql::TIER_AMOUNTS)->toBe([
        'T1' => 10000, 'T2' => 13500, 'T3' => 17000, 'T4' => 22500,
        'T5' => 30500, 'T6' => 42500, 'T7' => 57500, 'T8' => 72500, 'T9' => 85000,
    ]);
});
