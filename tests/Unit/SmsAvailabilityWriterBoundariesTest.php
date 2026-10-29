<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Closure;
use Cmd\Reports\Console\Commands\SyncPhoneNumbers;
use Cmd\Reports\Repositories\SmsProspectLoadRepository;
use Cmd\Reports\Services\SmsAvailabilityService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Mockery;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Synthetic SQLite, a fake PDO and temporary JSONL only. No application bootstrap,
 * .env, SQL Server, provider, network or actual availability worker is used.
 *
 * From the GitHub workspace root:
 * php cmd-runner/vendor/bin/phpunit --no-configuration --do-not-cache-result \
 *   --bootstrap liberty-cmd-package/tests/bootstrap-workspace.php \
 *   liberty-cmd-package/tests/Unit/SmsAvailabilityWriterBoundariesTest.php
 */
final class SmsAvailabilityWriterBoundariesTest extends TestCase
{
    private Container $previous;
    private Connection $db;
    private SmsProspectLoadRepository $load;
    private array $notificationLevels = [];
    private ?Closure $onInvalidate = null;
    private array $stagedFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->previous = Container::getInstance();
        $app = new Container;
        Container::setInstance($app);
        $app->instance('config', new Repository(['database' => ['default' => 'sqlsrv']]));
        $capsule = new Manager($app);
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:'], 'sqlsrv');
        $capsule->getDatabaseManager()->setDefaultConnection('sqlsrv');
        $app->instance('db', $capsule->getDatabaseManager());
        $this->db = $capsule->getConnection('sqlsrv');
        $this->db->setTransactionManager(new DatabaseTransactionsManager);
        $this->db->statement('CREATE TABLE TblProspects (Phone TEXT, Contacted INTEGER DEFAULT 0,
            SMS_Attempts INTEGER DEFAULT 0, Latest_Export_Date TEXT)');
        $this->db->statement('CREATE TABLE TblSMSLegacySends (Phone TEXT, Send_Date TEXT,
            UNIQUE (Phone, Send_Date))');
        $this->notificationLevels = [];
        $this->onInvalidate = null;
        $this->stagedFiles = [];
        $summary = Mockery::mock(SmsAvailabilityService::class);
        $summary->shouldReceive('invalidate')->andReturnUsing(function (): void {
            // The production helper catches Throwable: record observations here and
            // assert outside it, so an assertion failure cannot be swallowed.
            $this->notificationLevels[] = $this->db->transactionLevel();
            if ($this->onInvalidate !== null) ($this->onInvalidate)();
        });
        $app->instance(SmsAvailabilityService::class, $summary);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
        $this->load = new SmsProspectLoadRepository;
    }

    protected function tearDown(): void
    {
        try {
            Mockery::close();
        } finally {
            $this->db->disconnect();
            foreach ($this->stagedFiles as $path) {
                if (is_file($path)) unlink($path);
            }
            Facade::clearResolvedInstances();
            Container::setInstance($this->previous);
            Facade::setFacadeApplication($this->previous);
            parent::tearDown();
        }
    }

    private function phones(): array
    {
        return array_map(static fn (int $n): string => sprintf('303555%04d', $n), range(0, 999));
    }

    private function prospect(): array
    {
        return (array) $this->db->table('TblProspects')->where('Phone', '3035550001')->first();
    }

    public function test_committed_history_inserts_notify_even_when_the_later_prospect_update_fails(): void
    {
        $this->db->table('TblProspects')->insert(['Phone' => '3035550001']);
        $this->db->statement("CREATE TRIGGER fail_update BEFORE UPDATE ON TblProspects
            BEGIN SELECT RAISE(FAIL, 'synthetic prospect update failure'); END");
        $committedCounts = [];
        $this->onInvalidate = function () use (&$committedCounts): void {
            $committedCounts[] = $this->db->table('TblSMSLegacySends')->count();
        };
        $error = null;
        try {
            $this->load->markTexted($this->phones(), '2026-10-19');
        } catch (QueryException $error) {}

        self::assertInstanceOf(QueryException::class, $error);
        self::assertStringContainsString('synthetic prospect update failure', $error->getMessage());
        self::assertSame([500, 1000], $committedCounts, 'Notify each committed history insert before the failed update.');
        self::assertSame([0, 0], $this->notificationLevels);
        self::assertSame(1000, $this->db->table('TblSMSLegacySends')->count());
        self::assertTrue($this->db->table('TblSMSLegacySends')->where('Phone', '3035550001')->exists());
        self::assertSame(0, $this->prospect()['SMS_Attempts']);
        self::assertNull($this->prospect()['Latest_Export_Date']);
        self::assertFalse($this->db->getPdo()->inTransaction());
    }

    public function test_the_first_committed_insert_notifies_when_the_next_history_batch_fails(): void
    {
        $this->db->statement("CREATE TRIGGER fail_second_batch BEFORE INSERT ON TblSMSLegacySends
            WHEN NEW.Phone = '3035550500'
            BEGIN SELECT RAISE(FAIL, 'synthetic second history insert failure'); END");
        $error = null;
        try {
            $this->load->markTexted($this->phones(), '2026-10-19');
        } catch (QueryException $error) {}

        self::assertInstanceOf(QueryException::class, $error);
        self::assertStringContainsString('synthetic second history insert failure', $error->getMessage());
        self::assertSame(500, $this->db->table('TblSMSLegacySends')->count());
        self::assertTrue($this->db->table('TblSMSLegacySends')->where('Phone', '3035550499')->exists());
        self::assertFalse($this->db->table('TblSMSLegacySends')->where('Phone', '3035550500')->exists());
        self::assertSame([0], $this->notificationLevels, 'Only the successful insert may notify.');
        self::assertFalse($this->db->getPdo()->inTransaction());
    }

    public function test_a_committed_prospect_update_notifies_before_a_later_chunk_validation_failure(): void
    {
        // Older deployments have no legacy ledger; the positive prospect update
        // still needs its own notification, independent of history insert hooks.
        $this->db->statement('DROP TABLE TblSMSLegacySends');
        $this->db->table('TblProspects')->insert(['Phone' => '3035550001']);
        $error = null;
        try {
            $this->load->markTexted([...$this->phones(), 'invalid-phone'], '2026-10-19');
        } catch (InvalidArgumentException $error) {}

        self::assertInstanceOf(InvalidArgumentException::class, $error);
        self::assertSame(1, $this->prospect()['SMS_Attempts']);
        self::assertSame('2026-10-19', $this->prospect()['Latest_Export_Date']);
        self::assertSame([0], $this->notificationLevels);
        self::assertFalse($this->db->getPdo()->inTransaction());
    }

    public function test_an_identical_legacy_replay_neither_changes_data_nor_notifies(): void
    {
        $this->db->table('TblProspects')->insert(['Phone' => '3035550001']);
        self::assertSame(1, $this->load->markTexted(['3035550001'], '2026-10-19'));
        self::assertSame([0, 0], $this->notificationLevels, 'The first history insert and prospect update both notify.');
        $before = $this->prospect();
        $history = $this->db->table('TblSMSLegacySends')->get()->all();
        $this->notificationLevels = [];

        self::assertSame(0, $this->load->markTexted(['3035550001'], '2026-10-19'));
        self::assertSame([], $this->notificationLevels);
        self::assertSame($before, $this->prospect());
        self::assertEquals($history, $this->db->table('TblSMSLegacySends')->get()->all());
    }

    public function test_a_dry_run_only_counts_and_never_inserts_updates_or_notifies(): void
    {
        $this->db->table('TblProspects')->insert(['Phone' => '3035550001']);
        $before = $this->prospect();

        self::assertSame(1, $this->load->markTexted(['3035550001', '3035550002'], '2026-10-19', true));
        self::assertSame([], $this->notificationLevels);
        self::assertSame(0, $this->db->table('TblSMSLegacySends')->count());
        self::assertSame($before, $this->prospect());
    }

    public function test_an_outer_rollback_discards_all_nested_legacy_writer_notifications(): void
    {
        $this->db->table('TblProspects')->insert(['Phone' => '3035550001']);
        $before = $this->prospect();
        $observedNotifications = [];
        $error = null;
        try {
            $this->db->transaction(function () use (&$observedNotifications): void {
                $this->db->transaction(function () use (&$observedNotifications): void {
                    $this->load->markTexted(['3035550001'], '2026-10-19');
                    $observedNotifications[] = count($this->notificationLevels);
                });
                $observedNotifications[] = count($this->notificationLevels);
                throw new RuntimeException('synthetic outer rollback');
            });
        } catch (RuntimeException $error) {}

        self::assertInstanceOf(RuntimeException::class, $error);
        self::assertSame('synthetic outer rollback', $error->getMessage());
        self::assertSame([0, 0], $observedNotifications);
        self::assertSame([], $this->notificationLevels);
        self::assertSame(0, $this->db->table('TblSMSLegacySends')->count());
        self::assertSame($before, $this->prospect());
    }

    public function test_nested_legacy_writer_notifications_run_only_after_the_outer_commit(): void
    {
        $this->db->table('TblProspects')->insert(['Phone' => '3035550001']);
        $observedNotifications = [];
        $this->db->transaction(function () use (&$observedNotifications): void {
            $this->db->transaction(function () use (&$observedNotifications): void {
                $this->load->markTexted(['3035550001'], '2026-10-19');
                $observedNotifications[] = count($this->notificationLevels);
            });
            $observedNotifications[] = count($this->notificationLevels);
        });

        self::assertSame([0, 0], $observedNotifications);
        self::assertSame([0, 0], $this->notificationLevels);
        self::assertSame(1, $this->db->table('TblSMSLegacySends')->count());
        self::assertSame(1, $this->prospect()['SMS_Attempts']);
    }

    private function phoneCommand(object $trace): SyncPhoneNumbers
    {
        config(['sms-exports.wide_phone_contacts_enabled'=>true]);
        return new class($trace) extends SyncPhoneNumbers {
            public function __construct(private object $trace) { parent::__construct(); $this->withCreatedDate = true; }
            public function info($string, $verbosity = null): void {}
            public function replace(PDO $pdo, string $path): array { return $this->replacePhonesFromFile($pdo, $path, 1); }
            public function flagAfterReplacement(): ?int { return $this->flagContactedProspects(); }
            protected function flagContactedProspects(): ?int
            {
                $this->trace->events[] = 'flagging';
                throw new RuntimeException('synthetic later flagging failure');
            }
        };
    }

    private function phonePdo(object $trace, bool $failInsert = false): PDO
    {
        return new class($trace, $failInsert) extends PDO {
            private bool $active = false;
            public function __construct(private object $trace, private bool $failInsert) {}
            public function beginTransaction(): bool
            {
                $this->trace->events[] = 'begin';
                return $this->active = true;
            }
            public function exec(string $statement): int|false
            {
                $this->trace->statements[] = $statement;
                if (str_starts_with($statement, 'INSERT')) {
                    $this->trace->events[] = 'insert';
                    if ($this->failInsert) throw new RuntimeException('synthetic phone insert failure');
                    return 1;
                }
                if (str_contains($statement, "AND Phone = ''")) {
                    $this->trace->events[] = 'cleanup';
                    return 0;
                }
                $this->trace->events[] = 'delete';
                return 2;
            }
            public function commit(): bool
            {
                $this->trace->events[] = 'commit';
                $this->active = false;
                return true;
            }
            public function inTransaction(): bool { return $this->active; }
            public function rollBack(): bool
            {
                $this->trace->events[] = 'rollback';
                $this->active = false;
                return true;
            }
        };
    }

    private function phoneStage(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'sms-writer-boundary-');
        if ($path === false) throw new RuntimeException('Could not create synthetic phone fixture.');
        $this->stagedFiles[] = $path;
        $json = json_encode(['phone' => '2025550199', 'cid' => 1, 'created' => '2026-10-12'], JSON_THROW_ON_ERROR)."\n";
        if (file_put_contents($path, $json) !== strlen($json)) throw new RuntimeException('Could not write synthetic phone fixture.');
        return $path;
    }

    public function test_phone_replacement_notifies_after_pdo_commit_before_later_flagging_can_fail(): void
    {
        $trace = (object) ['events' => [], 'statements' => []];
        $pdo = $this->phonePdo($trace);
        $activeAtNotification = [];
        $this->onInvalidate = static function () use ($trace, $pdo, &$activeAtNotification): void {
            $trace->events[] = 'invalidate';
            $activeAtNotification[] = $pdo->inTransaction();
        };
        $command = $this->phoneCommand($trace);

        self::assertSame([2, 1, 0], $command->replace($pdo, $this->phoneStage()));
        self::assertSame(['begin', 'delete', 'insert', 'cleanup', 'commit', 'invalidate'], $trace->events);
        self::assertSame([false], $activeAtNotification);
        self::assertSame([0], $this->notificationLevels);
        self::assertStringContainsString("('2025550199', 'DP_LT', '1', '2026-10-12', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2025550199', NULL, NULL, NULL)", $trace->statements[1]);
        $error = null;
        try { $command->flagAfterReplacement(); } catch (RuntimeException $error) {}

        self::assertInstanceOf(RuntimeException::class, $error);
        self::assertSame('synthetic later flagging failure', $error->getMessage());
        self::assertSame(['begin', 'delete', 'insert', 'cleanup', 'commit', 'invalidate', 'flagging'], $trace->events);
        self::assertSame([0], $this->notificationLevels, 'The later failure cannot erase or defer the committed replacement notification.');
        self::assertFalse($pdo->inTransaction());
    }

    public function test_a_failed_phone_replacement_rolls_back_without_notification(): void
    {
        $trace = (object) ['events' => [], 'statements' => []];
        $pdo = $this->phonePdo($trace, true);
        $error = null;
        try {
            $this->phoneCommand($trace)->replace($pdo, $this->phoneStage());
        } catch (RuntimeException $error) {}

        self::assertInstanceOf(RuntimeException::class, $error);
        self::assertSame('synthetic phone insert failure', $error->getMessage());
        self::assertSame(['begin', 'delete', 'insert', 'rollback'], $trace->events);
        self::assertSame([], $this->notificationLevels);
        self::assertFalse($pdo->inTransaction());
    }

    public function test_refresh_and_logger_failure_do_not_fail_an_already_committed_phone_replacement(): void
    {
        $trace = (object) ['events' => [], 'statements' => []];
        $pdo = $this->phonePdo($trace);
        $activeAtNotification = [];
        $summary = Mockery::mock(SmsAvailabilityService::class);
        $summary->shouldReceive('invalidate')->once()->andReturnUsing(static function () use ($trace, $pdo, &$activeAtNotification): void {
            $trace->events[] = 'invalidate';
            $activeAtNotification[] = $pdo->inTransaction();
            throw new RuntimeException('synthetic summary cache failure');
        });
        Container::getInstance()->instance(SmsAvailabilityService::class, $summary);
        $logger = Mockery::mock();
        $logger->shouldReceive('warning')->once()->andThrow(new RuntimeException('synthetic logger failure'));
        Log::swap($logger);

        self::assertSame([2, 1, 0], $this->phoneCommand($trace)->replace($pdo, $this->phoneStage()));
        self::assertSame(['begin', 'delete', 'insert', 'cleanup', 'commit', 'invalidate'], $trace->events);
        self::assertSame([false], $activeAtNotification);
        self::assertFalse($pdo->inTransaction());
    }
}
