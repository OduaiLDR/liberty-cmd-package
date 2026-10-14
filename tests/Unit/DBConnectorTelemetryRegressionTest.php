<?php

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Observability\ObservedPDO;
use Cmd\Reports\Observability\Telemetry;
use Cmd\Reports\Services\DBConnector;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Exercises the real connector; all HTTP is mocked and all PDO writes are in-memory. */
final class DBConnectorTelemetryRegressionTest extends TestCase
{
    private array $history = [];
    private array $events = [];

    public static function modes(): array
    {
        return [['disabled'], ['enabled'], ['broken']];
    }

    protected function tearDown(): void
    {
        Telemetry::configure(null);
        parent::tearDown();
    }

    private function mode(string $mode): void
    {
        Telemetry::configure($mode === 'disabled' ? null : function (array $event) use ($mode): void {
            if ($mode === 'broken') { throw new \RuntimeException('fixture recorder unavailable'); }
            $this->events[] = $event;
        });
    }

    private function rows(string $column, array $data, array $extra = []): Response
    {
        return new Response(200, [], json_encode(array_replace_recursive([
            'resultSetMetaData' => ['rowType' => [['name' => $column]], 'numRows' => count($data)],
            'data' => $data,
        ], $extra), JSON_THROW_ON_ERROR));
    }

    private function connector(array $responses): DBConnector
    {
        $connector = new DBConnector(['account' => 'fixture.invalid', 'user' => 'fixture', 'private_key' => 'unused']);
        foreach (['accessToken' => 'fixture-token', 'tokenExpiry' => time() + 3600] as $key => $value) {
            (new \ReflectionProperty(DBConnector::class, $key))->setValue($connector, $value);
        }
        $client = (new \ReflectionProperty(DBConnector::class, 'client'))->getValue($connector);
        $stack = $client->getConfig('handler');
        $stack->setHandler(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));
        return $connector;
    }

    private function body(int $index): array
    {
        return json_decode((string) $this->history[$index]['request']->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }

    #[DataProvider('modes')]
    public function test_filter_metadata_cache_bindings_timeout_and_results_survive(string $mode): void
    {
        $this->mode($mode);
        $connector = $this->connector([
            $this->rows('TABLE_NAME', [['CONTACTS']]),
            $this->rows('ID', [['42']]), $this->rows('ID', [['43']]),
        ]);
        $result = $connector->query('/* fixture */ SELECT ID FROM CONTACTS WHERE ID = ?', [42], 17);
        self::assertSame(['data' => [['ID' => '42']], 'rowCount' => 1, 'columns' => ['ID']], $result);
        $connector->query('SELECT ID FROM CONTACTS', [], 19);
        self::assertCount(3, $this->history); // metadata is fetched once, without recursive metadata queries
        self::assertStringContainsString('INFORMATION_SCHEMA.COLUMNS', $this->body(0)['statement']);
        self::assertSame(['1' => ['type' => 'TEXT', 'value' => 'READER']], $this->body(0)['bindings']);
        self::assertSame(30, $this->body(0)['timeout']);
        self::assertSame(['1' => ['type' => 'FIXED', 'value' => '42']], $this->body(1)['bindings']);
        self::assertSame(17, $this->body(1)['timeout']);
        self::assertSame(19, $this->body(2)['timeout']);
        self::assertStringContainsString('_FIVETRAN_DELETED', $this->body(1)['statement']);
        self::assertStringContainsString('_FIVETRAN_DELETED', $this->body(2)['statement']);
        if ($mode === 'enabled') {
            self::assertNotEmpty($this->events);
            self::assertStringNotContainsString('fixture-token', json_encode($this->events));
            self::assertStringNotContainsString('SELECT ID', json_encode($this->events));
        }
    }

    #[DataProvider('modes')]
    public function test_metadata_failure_blocks_business_query_and_resets_recursion_guard(string $mode): void
    {
        $this->mode($mode);
        $connector = $this->connector([
            new Response(422, [], '{"message":"fixture metadata denied"}'),
            $this->rows('TABLE_NAME', [['CONTACTS']]), $this->rows('ID', [['42']]),
        ]);
        try {
            $connector->query('SELECT ID FROM CONTACTS');
            self::fail('Metadata failure must not permit an unfiltered query.');
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            self::assertSame(422, $e->getResponse()->getStatusCode());
        }
        self::assertCount(1, $this->history);
        self::assertFalse((new \ReflectionProperty(DBConnector::class, 'loadingFivetranDeletedTables'))->getValue($connector));
        self::assertSame([['ID' => '42']], $connector->query('SELECT ID FROM CONTACTS')['data']);
        self::assertCount(3, $this->history);
        self::assertStringContainsString('_FIVETRAN_DELETED', $this->body(2)['statement']);
    }

    #[DataProvider('modes')]
    public function test_pagination_and_incomplete_result_failure_are_preserved(string $mode): void
    {
        $this->mode($mode);
        $connector = $this->connector([
            $this->rows('ID', [['1']], ['nextRowUrl' => '/fixture/page', 'resultSetMetaData' => ['numRows' => 2]]),
            new Response(200, [], '{"data":[["2"]]}'),
            $this->rows('ID', [['1']], ['resultSetMetaData' => ['numRows' => 2]]),
        ]);
        self::assertSame([['ID' => '1'], ['ID' => '2']], $connector->query('SELECT 1')['data']);
        self::assertSame('GET', $this->history[1]['request']->getMethod());
        $this->expectExceptionMessage('Snowflake result incomplete');
        $connector->query('SELECT 1');
    }

    #[DataProvider('modes')]
    public function test_async_polling_and_partition_results_are_preserved(string $mode): void
    {
        $this->mode($mode);
        $connector = $this->connector([
            new Response(200, [], '{"statementStatusUrl":"/fixture/status"}'),
            $this->rows('ID', [['1']], [
                'statementHandle' => 'fixture-handle',
                'resultSetMetaData' => ['numRows' => 2, 'partitionInfo' => [[], []]],
            ]),
            new Response(200, [], '{"data":[["2"]]}'),
        ]);
        self::assertSame(['data' => [['ID' => '1'], ['ID' => '2']], 'rowCount' => 2, 'columns' => ['ID']], $connector->query('SELECT 1', [], 11));
        self::assertCount(3, $this->history);
        self::assertSame('/fixture/status', $this->history[1]['request']->getUri()->getPath());
        self::assertSame('partition=1', $this->history[2]['request']->getUri()->getQuery());
    }

    #[DataProvider('modes')]
    public function test_pdo_results_transactions_and_failure_contract_are_preserved(string $mode): void
    {
        $this->mode($mode);
        $pdo = new ObservedPDO('sqlite::memory:', options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
        $connector = $this->connector([]);
        (new \ReflectionProperty(DBConnector::class, 'sqlServerConnection'))->setValue($connector, $pdo);
        $pdo->exec('CREATE TABLE fixture (id INTEGER)');
        $pdo->beginTransaction();
        self::assertTrue($connector->querySqlServer('INSERT INTO fixture VALUES (?)', [7])['success']);
        $pdo->rollBack();
        self::assertSame([], $connector->querySqlServer('SELECT * FROM fixture')['data']);
        $pdo->beginTransaction();
        $connector->querySqlServer('INSERT INTO fixture VALUES (?)', [8]);
        $pdo->commit();
        self::assertSame([['id' => 8]], $connector->querySqlServer('SELECT * FROM fixture WHERE id = ?', [8])['data']);
        self::assertFalse($connector->querySqlServer('SELECT * FROM missing_fixture')['success']);
    }
}
