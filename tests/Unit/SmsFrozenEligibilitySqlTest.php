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
    private $previous;

    protected function setUp(): void
    {
        $this->previous = \Illuminate\Container\Container::getInstance();
        $container = new \Illuminate\Container\Container;
        $container->instance('config', new \Illuminate\Config\Repository);
        \Illuminate\Container\Container::setInstance($container);
    }

    protected function tearDown(): void
    {
        \Illuminate\Container\Container::setInstance($this->previous);
    }

    public function test_native_guard_uses_common_unicode_width_without_weakening_safety_checks(): void
    {
        $db = new SqlServerConnection(new PDO('sqlite::memory:'), 'owned', '', ['driver' => 'sqlsrv']);
        $queries = $db->pretend(fn () => SmsFrozenEligibility::assertPending($db, 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee'));
        self::assertGreaterThanOrEqual(2, count($queries));
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
        $suppression = $queries[2]['query'];
        self::assertStringNotContainsString('TblPhoneNumbers', $phone);
        self::assertStringContainsString('JOIN TblSMSSuppressions AS s', $suppression);
        self::assertStringContainsString('JOIN TblPhoneNumbers as c', $suppression);
        self::assertStringContainsString('ON c.Phone=x.Phone', $suppression);
        self::assertStringContainsString("ON c.Phone='1'+x.Phone", $suppression);
        self::assertStringContainsString('OPTION (HASH JOIN)', $suppression);
        self::assertSame(array_fill(0, 3, 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee'), $queries[2]['bindings']);
    }
}
