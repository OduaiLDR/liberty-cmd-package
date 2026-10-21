<?php

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Repositories\SqlSrvRepository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\SqlServerConnection;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Exercise Laravel's real begin/reconnect handling without a database or app. */
final class SmsReadSnapshotTest extends TestCase
{
    private function repository(ConnectionInterface $db): SqlSrvRepository
    {
        return new class($db) extends SqlSrvRepository {
            public function __construct(private ConnectionInterface $db) {}
            protected function connection(): ConnectionInterface { return $this->db; }
            public function snapshot(callable $work): mixed { return $this->withReadSnapshot($work); }
        };
    }

    private function connection(SnapshotTestPdo $pdo): SqlServerConnection
    {
        return new class($pdo, 'synthetic', '', ['driver' => 'sqlsrv']) extends SqlServerConnection {
            public function selectOne($query, $bindings = [], $useReadPdo = true)
            {
                TestCase::assertFalse($useReadPdo, 'Read isolation metadata on the writer session.');
                return (object) ['isolation_level' => $this->getPdo()->isolation];
            }
            public function unprepared($query)
            {
                $levels = ['READ UNCOMMITTED' => 1, 'READ COMMITTED' => 2, 'REPEATABLE READ' => 3, 'SERIALIZABLE' => 4, 'SNAPSHOT' => 5];
                $this->getPdo()->isolation = $levels[substr($query, strlen('SET TRANSACTION ISOLATION LEVEL '))];
                return true;
            }
        };
    }

    public static function outcomes(): array
    {
        return [[2, false], [4, false], [5, false], [2, true], [4, true]];
    }

    #[DataProvider('outcomes')]
    public function test_snapshot_and_original_isolation_are_preserved_on_success_and_failure(int $isolation, bool $fail): void
    {
        $pdo = new SnapshotTestPdo($isolation);
        $db = $this->connection($pdo);
        try {
            $result = $this->repository($db)->snapshot(function () use ($pdo, $fail): string {
                self::assertTrue($pdo->active);
                self::assertSame(5, $pdo->isolation);
                if ($fail) throw new RuntimeException('synthetic read failure');
                return 'snapshot result';
            });
            self::assertFalse($fail);
            self::assertSame('snapshot result', $result);
        } catch (RuntimeException $error) {
            self::assertTrue($fail);
            self::assertSame('synthetic read failure', $error->getMessage());
        }
        self::assertSame($isolation, $pdo->isolation);
        self::assertFalse($pdo->active);
        self::assertSame(0, $db->transactionLevel());
    }

    public function test_laravel_reconnection_at_begin_cannot_silently_count_under_read_committed(): void
    {
        $original = new SnapshotTestPdo(2, true);
        $replacement = new SnapshotTestPdo(2);
        $db = $this->connection($original);
        $reconnections = 0;
        $db->setReconnector(function ($connection) use ($replacement, &$reconnections): void {
            $reconnections++;
            $connection->setPdo($replacement);
        });
        $called = false;
        try {
            $this->repository($db)->snapshot(function () use (&$called, $db): void {
                $called = true;
                self::assertSame(5, $db->getPdo()->isolation);
            });
            self::fail('A replacement session must not count with weaker isolation.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('connection changed', $error->getMessage());
        }
        self::assertSame(1, $reconnections, 'Exercise the actual Laravel lost-connection begin retry.');
        self::assertFalse($called);
        self::assertSame(2, $replacement->isolation);
        self::assertFalse($replacement->active);
        self::assertSame(0, $db->transactionLevel());
    }

    public function test_existing_business_transaction_is_rejected_before_changing_isolation(): void
    {
        $pdo = new SnapshotTestPdo(4);
        $db = $this->connection($pdo);
        $db->beginTransaction();
        try {
            $this->repository($db)->snapshot(fn () => self::fail('Must not run in the business transaction.'));
            self::fail('Expected ambient transaction rejection.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('outside a business transaction', $error->getMessage());
        }
        self::assertSame(4, $pdo->isolation);
        self::assertTrue($pdo->active);
        $db->rollBack();
    }
}

final class SnapshotTestPdo extends PDO
{
    public bool $active = false;
    public function __construct(public int $isolation, private bool $loseAtBegin = false) {}
    public function inTransaction(): bool { return $this->active; }
    public function beginTransaction(): bool
    {
        if ($this->loseAtBegin) throw new PDOException('server has gone away');
        $this->active = true;
        return true;
    }
    public function commit(): bool { $this->active = false; return true; }
    public function rollBack(): bool { $this->active = false; return true; }
}
