<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Services\SmsFrozenEligibility;
use Illuminate\Database\SqlServerConnection;
use PDO;
use PHPUnit\Framework\TestCase;

/** SQL compilation only: an owned SQLite PDO is never queried with SQL Server SQL. */
final class SmsFrozenEligibilitySqlTest extends TestCase
{
    public function test_native_guard_uses_common_unicode_width_without_weakening_safety_checks(): void
    {
        $db = new SqlServerConnection(new PDO('sqlite::memory:'), 'owned', '', ['driver' => 'sqlsrv']);
        $queries = $db->pretend(fn () => SmsFrozenEligibility::assertPending($db, 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee'));
        self::assertCount(2, $queries);
        $state = $queries[0]['query'];
        $phone = $queries[1]['query'];
        self::assertStringContainsString('p.State', $state);
        self::assertStringContainsString('x.State', $state);
        self::assertStringContainsString('DATALENGTH(CONVERT(nvarchar(max),x.Phone))=20', $phone);
        self::assertStringContainsString("CONVERT(nvarchar(max),x.Phone) COLLATE Latin1_General_100_BIN2 LIKE '".str_repeat('[0-9]', 10)."'", $phone);
        self::assertStringContainsString('CONVERT(nvarchar(max),p.Phone) COLLATE Latin1_General_100_BIN2 = CONVERT(nvarchar(max),x.Phone) COLLATE Latin1_General_100_BIN2', $phone);
        self::assertStringContainsString('DATALENGTH(CONVERT(nvarchar(max),p.Phone))=DATALENGTH(CONVERT(nvarchar(max),x.Phone))', $phone);
        self::assertStringNotContainsString('DATALENGTH(x.Phone)=10', $phone);
        self::assertStringNotContainsString('DATALENGTH(p.Phone)=DATALENGTH(x.Phone)', $phone);
        self::assertStringNotContainsString('x.Phone COLLATE Latin1_General_100_BIN2', $phone);
        self::assertStringNotContainsString('p.Phone COLLATE Latin1_General_100_BIN2', $phone);
        foreach (['Contacted', 'Opt_Out', 'Invalid_Phone'] as $flag) self::assertStringContainsString('[p].['.$flag.']', $phone);
        self::assertStringContainsString('TblSMSSuppressions as s', $phone);
        self::assertStringContainsString('TblPhoneNumbers as c', $phone);
        self::assertStringContainsString("c.Phone=x.Phone OR c.Phone='1'+x.Phone", $phone);
    }
}
