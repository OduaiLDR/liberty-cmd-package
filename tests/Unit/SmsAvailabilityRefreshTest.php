<?php

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Services\SmsAvailabilityRefresh;
use Cmd\Reports\Services\SmsAvailabilityService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** No application bootstrap, .env, SQL Server, real worker or provider is used. */
final class SmsAvailabilityRefreshTest extends TestCase
{
    private Container $previous;
    private Connection $db;
    private int $notifications = 0;

    protected function setUp(): void
    {
        $this->previous = Container::getInstance();
        $app = new Container;
        Container::setInstance($app);
        $app->instance('config', new Repository([]));
        $capsule = new Manager($app);
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:'], 'sqlsrv');
        $this->db = $capsule->getConnection('sqlsrv');
        $this->db->setTransactionManager(new DatabaseTransactionsManager);
        $this->db->statement('CREATE TABLE committed_changes (value INTEGER)');
        $summary = Mockery::mock(SmsAvailabilityService::class);
        $summary->shouldReceive('invalidate')->andReturnUsing(function (): array {
            self::assertSame(0, $this->db->transactionLevel(), 'Never refresh a summary of uncommitted data.');
            $this->notifications++;
            return ['state' => 'pending'];
        });
        $app->instance(SmsAvailabilityService::class, $summary);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
    }

    protected function tearDown(): void
    {
        $this->db->disconnect();
        Mockery::close();
        Facade::clearResolvedInstances();
        Container::setInstance($this->previous);
        Facade::setFacadeApplication($this->previous);
    }

    public function test_autocommitted_changes_request_a_background_refresh_immediately(): void
    {
        $this->db->insert('INSERT INTO committed_changes VALUES (1)');
        SmsAvailabilityRefresh::afterCommit($this->db);
        self::assertSame(1, $this->notifications);
    }

    public function test_nested_writes_wait_until_the_outermost_commit(): void
    {
        $this->db->transaction(function (): void {
            $this->db->transaction(function (): void {
                $this->db->insert('INSERT INTO committed_changes VALUES (1)');
                SmsAvailabilityRefresh::afterCommit($this->db);
                self::assertSame(0, $this->notifications);
            });
            self::assertSame(0, $this->notifications);
        });
        self::assertSame(1, $this->notifications);
        self::assertSame(1, $this->db->table('committed_changes')->count());
    }

    public function test_rolling_back_an_outer_write_does_not_publish_a_summary_refresh(): void
    {
        try {
            $this->db->transaction(function (): void {
                $this->db->transaction(function (): void {
                    $this->db->insert('INSERT INTO committed_changes VALUES (1)');
                    SmsAvailabilityRefresh::afterCommit($this->db);
                });
                throw new RuntimeException('Synthetic rollback');
            });
        } catch (RuntimeException $error) {
            self::assertSame('Synthetic rollback', $error->getMessage());
        }
        self::assertSame(0, $this->notifications);
        self::assertSame(0, $this->db->table('committed_changes')->count());
    }

    public function test_refresh_or_logging_failure_cannot_turn_a_committed_write_into_a_failed_send(): void
    {
        $summary = Mockery::mock(SmsAvailabilityService::class);
        $summary->shouldReceive('invalidate')->once()->andThrow(new RuntimeException('Synthetic cache outage'));
        Container::getInstance()->instance(SmsAvailabilityService::class, $summary);
        $logger = Mockery::mock();
        $logger->shouldReceive('warning')->once()->andThrow(new RuntimeException('Synthetic logger outage'));
        Log::swap($logger);
        $this->db->transaction(function (): void {
            $this->db->insert('INSERT INTO committed_changes VALUES (1)');
            SmsAvailabilityRefresh::afterCommit($this->db);
        });
        self::assertSame(1, $this->db->table('committed_changes')->count());
    }
}
