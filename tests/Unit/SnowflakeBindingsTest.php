<?php

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Services\DBConnector;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/Services/DBConnector.php';

class SnowflakeBindingsTest extends TestCase
{
    public function test_list_bindings_become_one_based_keyed_map_the_sql_api_accepts(): void
    {
        // The Fivetran metadata lookup passes [$schema]; the SQL API rejected the raw list (391917).
        $json = json_encode(['bindings' => DBConnector::snowflakeBindings(['READER'])]);

        self::assertSame('{"bindings":{"1":{"type":"TEXT","value":"READER"}}}', $json);
    }

    public function test_values_keep_order_and_get_snowflake_types(): void
    {
        $keyed = DBConnector::snowflakeBindings(['x', 7, 1.5, true, null]);

        self::assertSame([
            '1' => ['type' => 'TEXT', 'value' => 'x'],
            '2' => ['type' => 'FIXED', 'value' => '7'],
            '3' => ['type' => 'REAL', 'value' => '1.5'],
            '4' => ['type' => 'BOOLEAN', 'value' => 'true'],
            '5' => ['type' => 'TEXT', 'value' => null],
        ], $keyed);
    }

    public function test_already_keyed_bindings_pass_through_unchanged(): void
    {
        $keyed = ['1' => ['type' => 'TEXT', 'value' => 'READER']];

        self::assertSame($keyed, DBConnector::snowflakeBindings($keyed));
    }
}
