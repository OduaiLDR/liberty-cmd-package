<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Console\Commands\SyncPhoneNumbers;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

final class SmsPhoneSourceValidityTest extends TestCase
{
    public static function invalidIds(): array
    {
        return [[null], [''], [' '], [1.5], ['1e30'], [str_repeat('9', 39)], [[]]];
    }

    #[DataProvider('invalidIds')]
    public function test_missing_or_invalid_source_identity_is_rejected(mixed $id): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unexpected contact ID format; the phone snapshot was not replaced.');
        (new ReflectionMethod(SyncPhoneNumbers::class, 'normalizePhones'))->invoke(new SyncPhoneNumbers,
            [['PHONE' => '2025550199', 'ID' => $id, 'CREATED_DATE' => '2026-10-12']]);
    }

    public static function invalidDates(): array
    {
        return [[null], [''], ['2026-02-30'], ['2025-02-29'], ['2026-13-01'], ['0000-01-01'],
            ['10000-01-01'], ['2026-1-01'], ['2026-10-12x'], ['2026-10-12 '], [20261012], [[]]];
    }

    #[DataProvider('invalidDates')]
    public function test_missing_or_invalid_calendar_date_is_rejected(mixed $date): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unexpected contact creation date; the phone snapshot was not replaced.');
        (new ReflectionMethod(SyncPhoneNumbers::class, 'normalizePhones'))->invoke(new SyncPhoneNumbers,
            [['PHONE' => '2025550199', 'ID' => '123', 'CREATED_DATE' => $date]]);
    }

    public function test_exact_decimal_ids_calendar_boundaries_and_phone_behavior_are_preserved(): void
    {
        $rows = (new ReflectionMethod(SyncPhoneNumbers::class, 'normalizePhones'))->invoke(new SyncPhoneNumbers, [
            ['PHONE' => '(202) 555-0199', 'ID' => '12345678901234567890123456789012345678', 'CREATED_DATE' => '2024-02-29'],
            ['PHONE' => '1', 'ID' => '000123', 'CREATED_DATE' => '0001-01-01'],
            ['PHONE' => '12025550199', 'ID' => 123, 'CREATED_DATE' => '9999-12-31'],
            ['PHONE' => '', 'ID' => null, 'CREATED_DATE' => null],
        ]);
        self::assertSame([
            ['phone' => '2025550199', 'cid' => '12345678901234567890123456789012345678', 'created' => '2024-02-29', 'data_source' => null, 'external_id' => null],
            ['phone' => '0000000001', 'cid' => '000123', 'created' => '0001-01-01', 'data_source' => null, 'external_id' => null],
            ['phone' => '2025550199', 'cid' => '123', 'created' => '9999-12-31', 'data_source' => null, 'external_id' => null],
        ], $rows);
    }
}
