<?php

namespace Cmd\Reports\Tests\Integration;

use Cmd\Reports\Services\SmsPhoneCounter;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Opt-in SELECT-only SQL Server fixtures: no tables or export tracking are written. */
class SmsPhoneCounterSqlServerTest extends TestCase
{
    #[DataProvider('cases')]
    public function test_phone_count_matches_export_identity_and_suppression(string $name, string $source, string $contacts, int $count, int $missing): void
    {
        if (getenv('CMD_SMS_SQLSERVER_READ_ONLY_TESTS') !== '1') {
            self::markTestSkipped('Requires a bootstrapped SQL Server host and explicit read-only fixture opt-in.');
        }
        $row = DB::connection('sqlsrv')->selectOne((new SmsPhoneCounter)->sql($source, $contacts));
        self::assertSame($count, (int) $row->Eligible_Phones, $name);
        self::assertSame($missing, (int) $row->Missing_Identity, $name);
    }

    public static function cases(): array
    {
        return [
        ['overlap', "SELECT * FROM (VALUES (N'a',N'2025550101'),(N'a',N'+1 (202) 555-0101')) v(External_ID,Phone)", 'SELECT CAST(NULL AS nvarchar(50)) Phone WHERE 1=0', 1, 0],
        ['separate-leads', "SELECT * FROM (VALUES (N'a',N'2025550101'),(N'b',N'2025550101')) v(External_ID,Phone)", 'SELECT CAST(NULL AS nvarchar(50)) Phone WHERE 1=0', 2, 0],
        ['whole-lead-contact', "SELECT * FROM (VALUES (N'a',N'2025550101'),(N'a',N'2025550102'),(N'b',N'2025550103')) v(External_ID,Phone)", "SELECT * FROM (VALUES(N'12025550102'),(N'12025550102')) v(Phone)", 1, 0],
        ['six-phones', "SELECT * FROM (VALUES (N'a',N'2025550101'),(N'a',N'2025550102'),(N'a',N'2025550103'),(N'a',N'2025550104'),(N'a',N'2025550105'),(N'a',N'2025550106')) v(External_ID,Phone)", 'SELECT CAST(NULL AS nvarchar(50)) Phone WHERE 1=0', 6, 0],
        ['invalid-phones', "SELECT * FROM (VALUES (N'a',N'2025550101'),(N'a',N'202555010'),(N'a',N'202555010x'),(N'a',NULL)) v(External_ID,Phone)", 'SELECT CAST(NULL AS nvarchar(50)) Phone WHERE 1=0', 1, 0],
        ['missing-identity', "SELECT * FROM (VALUES (N'',N'invalid'),(N'a',N'2025550101')) v(External_ID,Phone)", 'SELECT CAST(NULL AS nvarchar(50)) Phone WHERE 1=0', 1, 1],
        ['overflow-phone', "SELECT * FROM (VALUES (N'a',N'3147577081')) v(External_ID,Phone)", "SELECT * FROM (VALUES(N'13147577081')) v(Phone)", 0, 0],
        ['case-identity', "SELECT * FROM (VALUES (N'A ',N'2025550101'),(N'a',N'2025550101')) v(External_ID,Phone)", 'SELECT CAST(NULL AS nvarchar(50)) Phone WHERE 1=0', 1, 0],
        ['php-whitespace-identity', "SELECT * FROM (VALUES (NCHAR(9)+N'A'+NCHAR(13),N'2025550101'),(N'a',N'2025550101')) v(External_ID,Phone)", 'SELECT CAST(NULL AS nvarchar(50)) Phone WHERE 1=0', 1, 0],
        ['php-unicode-identity', "SELECT * FROM (VALUES (N'Á',N'2025550101'),(N'á',N'2025550101')) v(External_ID,Phone)", 'SELECT CAST(NULL AS nvarchar(50)) Phone WHERE 1=0', 2, 0],
    ];
    }
}
