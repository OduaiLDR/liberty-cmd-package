<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Services\SmsLegacyDataSourceMap;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SmsLegacyDataSourceMapTest extends TestCase
{
    public function test_reviewed_bytes_rows_and_ids_are_exact(): void
    {
        self::assertSame(SmsLegacyDataSourceMap::SOURCE_SHA256, hash_file('sha256', SmsLegacyDataSourceMap::path()));
        $map = SmsLegacyDataSourceMap::all();
        self::assertCount(142, $map);
        self::assertSame('DPP - Mass Post Source', $map['79071']);
        self::assertSame('DPP - Mass Post Source', $map['79086']);
        self::assertNotSame('79071', '79086');
    }

    public function test_current_name_wins_and_legacy_is_lt_only(): void
    {
        self::assertSame('Current Name', SmsLegacyDataSourceMap::resolve('lt','79071','Current Name',1));
        self::assertSame('DPP - Mass Post Source', SmsLegacyDataSourceMap::resolve('lt','79071',null,0));
        self::assertNull(SmsLegacyDataSourceMap::resolve('ldr','79071',null,0));
        self::assertNull(SmsLegacyDataSourceMap::resolve('lt','999999',null,0));
    }

    public function test_conflicting_current_names_and_invalid_ids_hold(): void
    {
        foreach ([fn()=>SmsLegacyDataSourceMap::resolve('lt','79071',null,2),
            fn()=>SmsLegacyDataSourceMap::resolve('lt','79 071',null,0),
            fn()=>SmsLegacyDataSourceMap::resolve('lt',null,'Current Name',1),
            fn()=>SmsLegacyDataSourceMap::resolve('lt','79071','Current Name',0)] as $action) {
            try {$action(); self::fail('Invalid source evidence accepted.');}
            catch (RuntimeException) {self::addToAssertionCount(1);}
        }
    }
}
