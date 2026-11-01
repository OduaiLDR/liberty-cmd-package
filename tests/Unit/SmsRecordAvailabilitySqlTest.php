<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Repositories\SmsRecordExportRepository;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Schema\SqlServerBuilder;
use Illuminate\Database\SqlServerConnection;
use Illuminate\Support\Facades\Facade;
use Mockery;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

/** SQL compilation/captured execution only: no application bootstrap or database connection. */
final class SmsRecordAvailabilitySqlTest extends TestCase
{
    private Container $previous;
    private SqlServerConnection $db;

    protected function setUp(): void
    {
        $this->previous = Container::getInstance();
        $container = new Container;
        Container::setInstance($container);
        $container->instance('config', new Repository(['sms-exports' => [
            'record_workflow_enabled' => true, 'durable_availability_enabled' => true,
        ]]));
        $schema = Mockery::mock(SqlServerBuilder::class);
        $schema->shouldReceive('hasTable', 'hasColumns')->andReturnTrue();
        $this->db = new class(fn () => throw new RuntimeException('No database access is allowed.'), 'synthetic_sms', '', ['driver' => 'sqlsrv']) extends SqlServerConnection {
            public SqlServerBuilder $schema;
            public array $executed = [];
            public object $result;
            public function getSchemaBuilder() { return $this->schema; }
            public function selectOne($query, $bindings = [], $useReadPdo = true)
            {
                $this->executed[] = [$query, $bindings];
                return $this->result;
            }
        };
        $this->db->schema = $schema;
        $database = Mockery::mock();
        $database->shouldReceive('connection')->with('sqlsrv')->andReturn($this->db);
        $container->instance('db', $database);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        Facade::clearResolvedInstances();
        Container::setInstance($this->previous);
        Facade::setFacadeApplication($this->previous);
    }

    public function test_sql_server_hints_the_unchanged_eligible_record_aggregate_and_preserves_bindings(): void
    {
        $repository = new SmsRecordExportRepository;
        $original = (new ReflectionMethod($repository, 'eligible'))->invoke($repository)
            ->selectRaw('COUNT(*) AS Eligible, COALESCE(SUM(CASE WHEN Last_SMS_Date IS NULL THEN 1 ELSE 0 END),0) AS Never_Texted')
            ->limit(1);
        $this->db->result = (object) ['Eligible' => '13', 'Never_Texted' => '9'];

        self::assertSame(['eligible' => 13, 'never_texted' => 9, 'texted_before' => 4], $repository->availability());
        self::assertSame([[$original->toSql().' OPTION (HASH JOIN)', $original->getBindings()]], $this->db->executed);
        self::assertSame([0, 0, 0, 1, 1], $this->db->executed[0][1]);
        self::assertStringContainsString('select top 1 COUNT(*)', $this->db->executed[0][0]);
        self::assertStringContainsString('DATALENGTH(Phone)=10', $this->db->executed[0][0]);
        self::assertStringContainsString('SUBSTRING(c.Phone, 2, 10)', $this->db->executed[0][0]);
        self::assertStringNotContainsString('DISTINCT', $this->db->executed[0][0]);
        self::assertSame(1, substr_count($this->db->executed[0][0], 'OPTION (HASH JOIN)'));
    }

    public function test_sql_server_empty_aggregate_still_returns_verified_integer_zero_counts(): void
    {
        $this->db->result = (object) ['Eligible' => '0', 'Never_Texted' => '0'];
        self::assertSame(['eligible' => 0, 'never_texted' => 0, 'texted_before' => 0], (new SmsRecordExportRepository)->availability());
        self::assertCount(1, $this->db->executed);
    }
}
