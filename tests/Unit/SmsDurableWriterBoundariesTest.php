<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Carbon\Carbon;
use Cmd\Reports\Console\Commands\SyncPhoneNumbers;
use Cmd\Reports\Repositories\SmsEnrichmentRepository;
use Cmd\Reports\Repositories\SmsProspectExportRepository;
use Cmd\Reports\Repositories\SmsProspectLoadRepository;
use Cmd\Reports\Services\SmsAvailabilityLedger;
use Cmd\Reports\Services\SmsAvailabilityRefresh;
use Cmd\Reports\Services\SmsAvailabilityService;
use Cmd\Reports\Services\SmsDurableAvailabilityService;
use Cmd\Reports\Services\SmsFeedbackService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\QueryException;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use LogicException;
use Mockery;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** SQLite and synthetic ledger/request bindings only: no provider, worker, .env or network. */
final class SmsDurableWriterBoundariesTest extends TestCase
{
    private Container $previous;
    private Connection $db;
    private object $ledger;
    private object $requests;
    private array $files = [];

    protected function setUp(): void
    {
        $this->previous = Container::getInstance();
        $app = new Container;
        Container::setInstance($app);
        $app->instance('config', new Repository([
            'database' => ['default' => 'sqlsrv'],
            'sms-exports' => ['durable_availability_enabled' => true],
        ]));
        $capsule = new Manager($app);
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:'], 'sqlsrv');
        $capsule->getDatabaseManager()->setDefaultConnection('sqlsrv');
        $app->instance('db', $capsule->getDatabaseManager());
        $this->db = $capsule->getConnection('sqlsrv');
        $this->db->setTransactionManager(new DatabaseTransactionsManager);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
        $this->db->statement('CREATE TABLE TblPhoneNumbers (Phone TEXT, Source TEXT, CID INTEGER, Data_Source TEXT, External_ID TEXT)');
        foreach (['2026_10_12_000001_create_sms_prospects_tables.php', '2026_10_19_000001_complete_sms_workflow.php',
            '2026_10_19_000002_create_sms_enrichment_tables.php',
            '2026_10_27_000001_add_sms_state_holds.php'] as $file) {
            (require __DIR__.'/../../database/sms-migrations/'.$file)->up();
        }
        $this->db->statement('CREATE TABLE committed_changes (value INTEGER)');
        $this->db->statement('CREATE TABLE test_availability_revisions (revision INTEGER PRIMARY KEY AUTOINCREMENT)');
        // Test only the supplied dirty(ConnectionInterface) contract, not the parent's store layout.
        $this->ledger = new class {
            public array $observations = [];
            public int $failAt = 0;
            public function dirty(ConnectionInterface $db): void
            {
                $this->observations[] = [$db, $db->transactionLevel(), $db->getPdo()->inTransaction()];
                $db->statement('INSERT INTO test_availability_revisions DEFAULT VALUES');
                if (count($this->observations) === $this->failAt) throw new RuntimeException('synthetic revision failure');
            }
        };
        $app->instance(SmsAvailabilityLedger::class, $this->ledger);
        $this->requests = new class($this->db) {
            public array $observations = [];
            public bool $fail = false;
            public function __construct(public Connection $db) {}
            public function request(bool $force = false): array
            {
                $this->observations[] = [$this->db->transactionLevel(), $this->db->getPdo()->inTransaction(),
                    $this->db->table('test_availability_revisions')->count(), $this->db->table('TblSMSLegacySends')->count(), $force];
                if ($this->fail) throw new RuntimeException('synthetic dispatch failure');
                return ['state' => 'pending'];
            }
        };
        $app->instance(SmsDurableAvailabilityService::class, $this->requests);
        $legacy = Mockery::mock(SmsAvailabilityService::class);
        $legacy->shouldNotReceive('invalidate');
        $app->instance(SmsAvailabilityService::class, $legacy);
    }

    protected function tearDown(): void
    {
        try {
            Mockery::close();
        } finally {
            if ($this->db->getPdo()->inTransaction() && $this->db->transactionLevel() === 0) $this->db->getPdo()->rollBack();
            $this->db->disconnect();
            foreach ($this->files as $path) if (is_file($path)) unlink($path);
            Facade::clearResolvedInstances();
            Container::setInstance($this->previous);
            Facade::setFacadeApplication($this->previous);
        }
    }

    private function revisions(): int { return $this->db->table('test_availability_revisions')->count(); }

    private function change(): void
    {
        $this->db->table('committed_changes')->insert(['value' => 1]);
        SmsAvailabilityRefresh::afterCommit($this->db);
    }

    private function assertDirtyConnections(array $levels): void
    {
        self::assertSame($levels, array_column($this->ledger->observations, 1));
        foreach ($this->ledger->observations as [$db, $level, $active]) {
            self::assertSame($this->db, $db);
            self::assertTrue($active);
        }
    }

    public function test_durable_notification_rejects_an_autocommit_boundary(): void
    {
        $this->expectException(LogicException::class);
        try { SmsAvailabilityRefresh::afterCommit($this->db); }
        finally {
            self::assertSame([], $this->ledger->observations);
            self::assertSame([], $this->requests->observations);
        }
    }

    public function test_dirty_is_synchronous_on_the_supplied_connection_and_request_waits_for_outer_commit(): void
    {
        $this->db->transaction(function (): void {
            $this->db->transaction(function (): void {
                $this->change();
                self::assertSame(1, $this->revisions());
                self::assertSame([], $this->requests->observations);
            });
            self::assertSame([], $this->requests->observations);
        });
        $this->assertDirtyConnections([2]);
        self::assertSame([[0, false, 1, 0, false]], $this->requests->observations);
    }

    public function test_outer_rollback_discards_business_revision_and_callback(): void
    {
        $this->expectExceptionMessage('outer rollback');
        try {
            $this->db->transaction(function (): void {
                $this->db->transaction(fn () => $this->change());
                throw new RuntimeException('outer rollback');
            });
        } finally {
            self::assertSame(0, $this->db->table('committed_changes')->count());
            self::assertSame(0, $this->revisions());
            self::assertSame([], $this->requests->observations);
        }
    }

    public function test_inner_rollback_leaves_only_the_outer_revision_and_callback(): void
    {
        $this->db->transaction(function (): void {
            $this->change();
            try {
                $this->db->transaction(function (): void {
                    $this->change();
                    throw new RuntimeException('inner rollback');
                });
            } catch (RuntimeException $error) { self::assertSame('inner rollback', $error->getMessage()); }
        });
        $this->assertDirtyConnections([1, 2]);
        self::assertSame(1, $this->db->table('committed_changes')->count());
        self::assertSame([[0, false, 1, 0, false]], $this->requests->observations);
    }

    public function test_dirty_failure_rolls_back_business_and_any_partially_written_revision(): void
    {
        $this->ledger->failAt = 1;
        $this->expectExceptionMessage('synthetic revision failure');
        try { SmsAvailabilityRefresh::transaction($this->db, fn () => $this->change()); }
        finally {
            self::assertSame(0, $this->db->table('committed_changes')->count());
            self::assertSame(0, $this->revisions());
            self::assertSame([], $this->requests->observations);
            self::assertFalse($this->db->getPdo()->inTransaction());
        }
    }

    public function test_request_and_logging_failure_do_not_fail_a_committed_durable_write(): void
    {
        $this->requests->fail = true;
        $logger = Mockery::mock();
        $logger->shouldReceive('warning')->once()->andThrow(new RuntimeException('logger failure'));
        Log::swap($logger);
        SmsAvailabilityRefresh::transaction($this->db, fn () => $this->change());
        self::assertSame(1, $this->db->table('committed_changes')->count());
        self::assertSame(1, $this->revisions());
        self::assertSame([[0, false, 1, 0, false]], $this->requests->observations);
    }

    private function phones(): array
    {
        return array_map(static fn (int $n): string => sprintf('303555%04d', $n), range(0, 999));
    }

    public function test_history_chunks_commit_and_notify_before_a_later_prospect_update_failure(): void
    {
        $this->db->table('TblProspects')->insert(['Phone' => '3035550001']);
        $this->db->statement("CREATE TRIGGER fail_update BEFORE UPDATE ON TblProspects BEGIN SELECT RAISE(FAIL, 'prospect failure'); END");
        $this->expectException(QueryException::class);
        try { (new SmsProspectLoadRepository)->markTexted($this->phones(), '2026-10-19'); }
        finally {
            self::assertSame(1000, $this->db->table('TblSMSLegacySends')->count());
            self::assertSame(0, $this->db->table('TblProspects')->value('SMS_Attempts'));
            self::assertSame(2, $this->revisions());
            self::assertSame([[0, false, 1, 500, false], [0, false, 2, 1000, false]], $this->requests->observations);
            $this->assertDirtyConnections([1, 1]);
        }
    }

    public function test_revision_failure_on_a_later_history_chunk_preserves_the_first_commit(): void
    {
        $this->ledger->failAt = 2;
        $this->expectExceptionMessage('synthetic revision failure');
        try { (new SmsProspectLoadRepository)->markTexted($this->phones(), '2026-10-19'); }
        finally {
            self::assertSame(500, $this->db->table('TblSMSLegacySends')->count());
            self::assertSame(1, $this->revisions());
            self::assertSame([[0, false, 1, 500, false]], $this->requests->observations);
            self::assertFalse($this->db->getPdo()->inTransaction());
        }
    }

    public function test_a_failed_history_statement_does_not_dirty_or_commit_its_partial_rows(): void
    {
        $this->db->statement("CREATE TRIGGER fail_history BEFORE INSERT ON TblSMSLegacySends WHEN NEW.Phone = '3035550501' BEGIN SELECT RAISE(FAIL, 'history failure'); END");
        $this->expectException(QueryException::class);
        try { (new SmsProspectLoadRepository)->markTexted($this->phones(), '2026-10-19'); }
        finally {
            self::assertSame(500, $this->db->table('TblSMSLegacySends')->count());
            self::assertSame(1, $this->revisions());
            $this->assertDirtyConnections([1]);
            self::assertSame([[0, false, 1, 500, false]], $this->requests->observations);
        }
    }

    public function test_dry_run_replay_and_empty_input_do_not_dirty(): void
    {
        $this->db->table('TblProspects')->insert(['Phone' => '3035550001']);
        $load = new SmsProspectLoadRepository;
        self::assertSame(1, $load->markTexted(['3035550001'], '2026-10-19', true));
        self::assertSame(0, $load->markTexted([], '2026-10-19'));
        self::assertSame(0, $this->revisions());
        self::assertSame([], $this->requests->observations);
        self::assertSame(1, $load->markTexted(['3035550001'], '2026-10-19'));
        $before = $this->db->table('TblProspects')->first();
        self::assertSame(0, $load->markTexted(['3035550001'], '2026-10-19'));
        self::assertSame(2, $this->revisions());
        self::assertCount(2, $this->requests->observations);
        self::assertEquals($before, $this->db->table('TblProspects')->first());
        $this->assertDirtyConnections([1, 1]);
    }

    public function test_without_legacy_table_the_prospect_commit_survives_later_chunk_validation_failure(): void
    {
        $this->db->statement('DROP TABLE TblSMSLegacySends');
        // The request fake reads history; retain an empty view to let the schema check skip writes.
        $this->db->statement('CREATE VIEW TblSMSLegacySends AS SELECT NULL AS Phone WHERE 0');
        $this->db->table('TblProspects')->insert(['Phone' => '3035550001']);
        $this->expectException(InvalidArgumentException::class);
        try { (new SmsProspectLoadRepository)->markTexted([...$this->phones(), 'invalid'], '2026-10-19'); }
        finally {
            self::assertSame(1, $this->db->table('TblProspects')->value('SMS_Attempts'));
            self::assertSame(1, $this->revisions());
            self::assertCount(1, $this->requests->observations);
        }
    }

    public static function releaseMethods(): array { return [['releaseReview'], ['discard']]; }

    #[DataProvider('releaseMethods')]
    public function test_release_writes_are_atomic_and_noop_repeats_do_not_dirty(string $method): void
    {
        $this->db->table('TblProspectsExport')->insert(['Request_ID' => 'review', 'Prospect_PK' => 1, 'Phone' => '3035550001']);
        $repo = new SmsProspectExportRepository;
        $this->ledger->failAt = 1;
        try { $repo->{$method}('review'); self::fail('Expected dirty failure.'); }
        catch (RuntimeException $error) { self::assertSame('synthetic revision failure', $error->getMessage()); }
        self::assertSame(1, $this->db->table('TblProspectsExport')->count());
        self::assertSame(0, $this->revisions());
        self::assertSame([], $this->requests->observations);
        $this->ledger->failAt = 0;
        self::assertSame(1, $repo->{$method}('review'));
        self::assertSame(0, $repo->{$method}('review'));
        self::assertSame(1, $this->revisions());
        self::assertCount(1, $this->requests->observations);
    }

    public function test_feedback_dirties_new_suppressions_but_not_replays_or_redundant_flags(): void
    {
        $feedback = new SmsFeedbackService;
        $rows = [['phone' => '3035550001', 'status' => 'opt_out']];
        self::assertFalse($feedback->import('feedback', 0, $rows, 'test')['replayed']);
        self::assertTrue($feedback->import('feedback', 0, $rows, 'test')['replayed']);
        self::assertFalse($feedback->import('feedback', 1, $rows, 'test')['replayed']);
        self::assertSame(1, $this->revisions(), 'A suppression matters even before its prospect exists.');
        self::assertCount(1, $this->requests->observations);
        $feedback->import('feedback', 2, [['phone' => '3035550001', 'status' => 'invalid_phone']], 'test');
        self::assertSame(2, $this->revisions());
        $this->assertDirtyConnections([1, 1]);
    }

    public function test_feedback_revision_failure_rolls_back_suppression_flags_and_import_receipt(): void
    {
        $this->db->table('TblProspects')->insert(['Phone' => '3035550001']);
        $this->ledger->failAt = 1;
        $this->expectExceptionMessage('synthetic revision failure');
        try { (new SmsFeedbackService)->import('feedback', 0, [['phone' => '3035550001', 'status' => 'opt_out']], 'test'); }
        finally {
            self::assertSame(0, $this->db->table('TblProspects')->value('Opt_Out'));
            self::assertSame(0, $this->db->table('TblSMSSuppressions')->count());
            self::assertSame(0, $this->db->table('TblSMSFeedbackImports')->count());
            self::assertSame(0, $this->revisions());
            self::assertSame([], $this->requests->observations);
        }
    }

    public function test_enrichment_admission_dirties_inside_its_transaction_and_replay_does_not(): void
    {
        $repo = new SmsEnrichmentRepository;
        $repo->stage('enrich', [['Client' => 'Test Person', 'Address' => '1 Test Way', 'State' => 'CA', 'Zip' => '90210']], 'test', 0, false);
        self::assertSame(0, $this->revisions());
        $id = $this->db->table('TblSMSEnrichmentPeople')->value('PK');
        $result = [['Record_ID' => 'SMSP-'.$id, 'Phone' => '3035550001']];
        $this->ledger->failAt = 1;
        try { $repo->returned('enrich', $result, false, false); self::fail('Expected dirty failure.'); }
        catch (RuntimeException $error) { self::assertSame('synthetic revision failure', $error->getMessage()); }
        self::assertSame(0, $this->db->table('TblProspects')->count());
        self::assertNull($this->db->table('TblSMSEnrichmentPeople')->value('Returned_At'));
        self::assertSame(0, $this->revisions());
        $this->ledger->failAt = 0;
        self::assertSame(1, $repo->returned('enrich', $result, false, false)['added']);
        self::assertSame(1, $repo->returned('enrich', $result, true, false)['replayed']);
        self::assertSame(1, $this->revisions());
        self::assertCount(1, $this->requests->observations);
        $this->assertDirtyConnections([1, 1]);
    }

    /** Intercept only SQL Server dialect writes; transactions and remaining SQL are real SQLite. */
    private function dialectDb(): Connection
    {
        $db = Mockery::mock(new SQLiteConnection($this->db->getPdo(), ':memory:', '', ['name' => 'sqlsrv']))->makePartial();
        $db->setTransactionManager(new DatabaseTransactionsManager);
        $this->db = $db;
        $this->requests->db = $db;
        return $db;
    }

    private function exportRepository(Connection $db): SmsProspectExportRepository
    {
        return new class($db) extends SmsProspectExportRepository {
            public function __construct(private Connection $db) {}
            protected function connection(): ConnectionInterface { return $this->db; }
        };
    }

    public function test_contact_flagging_and_selection_keep_separate_atomic_commits(): void
    {
        $pk = $this->db->table('TblProspects')->insertGetId(['Phone' => '3035550001']);
        $db = $this->dialectDb();
        $db->shouldReceive('update')->withArgs(fn ($sql) => str_contains($sql, 'UPDATE p SET Contacted'))
            ->andReturnUsing(fn () => $db->table('TblProspects')->where('PK', $pk)->update(['Contacted' => 1]));
        $db->shouldReceive('affectingStatement')->withArgs(fn ($sql) => str_contains($sql, 'INSERT INTO TblProspectsExport'))
            ->andReturnUsing(function () use ($db, $pk): int {
                $db->table('TblProspectsExport')->insert(['Request_ID' => 'select', 'Prospect_PK' => $pk, 'Phone' => '3035550001']);
                return 1;
            });
        $this->ledger->failAt = 2;
        $this->expectExceptionMessage('synthetic revision failure');
        try { $this->exportRepository($db)->select('select', 1); }
        finally {
            self::assertSame(1, $db->table('TblProspects')->value('Contacted'));
            self::assertSame(0, $db->table('TblProspectsExport')->count());
            self::assertSame(1, $this->revisions());
            $this->assertDirtyConnections([1, 1]);
            self::assertCount(1, $this->requests->observations);
        }
    }

    public function test_contact_and_selection_noop_do_not_dirty(): void
    {
        $db = $this->dialectDb();
        $db->shouldReceive('update')->withArgs(fn ($sql) => str_contains($sql, 'UPDATE p SET Contacted'))->andReturn(0);
        $db->shouldReceive('affectingStatement')->withArgs(fn ($sql) => str_contains($sql, 'INSERT INTO TblProspectsExport'))->andReturn(0);
        self::assertSame(['selected' => 0, 'target' => 1, 'shortfall' => 1, 'contacted_flagged' => 0], $this->exportRepository($db)->select('select', 1));
        self::assertSame([], $this->ledger->observations);
        self::assertSame([], $this->requests->observations);
    }

    public function test_drop_load_revision_failure_rolls_back_admission_and_fill_together(): void
    {
        $pk = $this->db->table('TblProspects')->insertGetId(['Client' => 'Existing']);
        $db = $this->dialectDb();
        $db->shouldReceive('affectingStatement')->withArgs(fn ($sql) => str_contains($sql, 'INSERT INTO TblProspects ('))
            ->andReturnUsing(function () use ($db): int {
                $db->table('TblProspects')->insert(['Client' => 'New', 'Phone' => '3035550001']);
                return 1;
            });
        $db->shouldReceive('affectingStatement')->withArgs(fn ($sql) => str_contains($sql, 'UPDATE q SET Phone'))
            ->andReturnUsing(fn () => $db->table('TblProspects')->where('PK', $pk)->update(['Phone' => '3035550002']));
        $repo = new class($db) extends SmsProspectLoadRepository {
            public function __construct(private Connection $db) {}
            protected function connection(): ConnectionInterface { return $this->db; }
        };
        $this->ledger->failAt = 1;
        try { $repo->loadDrop('drop', '2026-10-19'); self::fail('Expected dirty failure.'); }
        catch (RuntimeException $error) { self::assertSame('synthetic revision failure', $error->getMessage()); }
        self::assertSame(1, $db->table('TblProspects')->count());
        self::assertNull($db->table('TblProspects')->value('Phone'));
        self::assertSame(0, $this->revisions());
        self::assertSame([], $this->requests->observations);
        $this->ledger->failAt = 0;
        self::assertSame(['people' => null, 'added' => 1, 'filled' => 1], $repo->loadDrop('drop', '2026-10-19'));
        self::assertSame(1, $this->revisions());
        $this->assertDirtyConnections([1, 1]);
        self::assertCount(1, $this->requests->observations);
    }

    public function test_mark_sent_dirty_failure_rolls_back_send_and_successful_replay_does_not_dirty(): void
    {
        $pk = $this->db->table('TblProspects')->insertGetId(['Phone' => '3035550001']);
        $this->db->table('TblProspectsExport')->insert(['Request_ID' => 'send', 'Prospect_PK' => $pk, 'Phone' => '3035550001']);
        $db = $this->dialectDb();
        $db->shouldReceive('update')->withArgs(fn ($sql) => str_contains($sql, 'INTO TblSMSAttemptAudit'))
            ->andReturnUsing(fn () => $db->table('TblProspects')->where('PK', $pk)->update(['SMS_Attempts' => 1, 'Latest_Export_Date' => '2026-10-19']));
        $repo = $this->exportRepository($db);
        $this->ledger->failAt = 1;
        try { $repo->markSent('send', Carbon::parse('2026-10-19 20:00:00 UTC')); self::fail('Expected dirty failure.'); }
        catch (RuntimeException $error) { self::assertSame('synthetic revision failure', $error->getMessage()); }
        self::assertSame(0, $db->table('TblProspects')->value('SMS_Attempts'));
        self::assertSame(1, $db->table('TblProspectsExport')->count());
        self::assertSame(0, $db->table('TblSMSHistory')->count());
        self::assertSame(0, $db->table('TblSMSLegacySends')->count());
        self::assertSame(0, $this->revisions());
        $this->ledger->failAt = 0;
        self::assertSame(1, $repo->markSent('send', Carbon::parse('2026-10-19 20:00:00 UTC')));
        self::assertSame(0, $repo->markSent('send', Carbon::parse('2026-10-19 20:00:00 UTC')));
        self::assertSame(1, $this->revisions());
        $this->assertDirtyConnections([1, 1]);
        self::assertCount(1, $this->requests->observations);
    }

    public function test_reverse_sent_dirty_failure_rolls_back_prospect_and_history_correction(): void
    {
        $pk = $this->db->table('TblProspects')->insertGetId(['Phone' => '3035550001', 'SMS_Attempts' => 1, 'Latest_Export_Date' => '2026-10-19']);
        $this->db->table('TblSMSAttemptAudit')->insert(['Request_ID' => 'reverse', 'Prospect_PK' => $pk,
            'Previous_Attempts' => 0, 'Recorded_Attempts' => 1, 'Recorded_Date' => '2026-10-19']);
        $this->db->table('TblSMSLegacySends')->insert(['Request_ID' => 'reverse', 'Phone' => '3035550001', 'Send_Date' => '2026-10-19']);
        $db = $this->dialectDb();
        $db->shouldReceive('selectOne')->withArgs(fn ($sql) => str_contains($sql, 'UPDLOCK, HOLDLOCK'))->andReturn((object) ['n' => 0]);
        $db->shouldReceive('update')->withArgs(fn ($sql) => str_contains($sql, 'UPDATE p SET SMS_Attempts = a.Previous_Attempts'))
            ->andReturnUsing(fn () => $db->table('TblProspects')->where('PK', $pk)->update(['SMS_Attempts' => 0, 'Latest_Export_Date' => null]));
        $this->ledger->failAt = 1;
        $this->expectExceptionMessage('synthetic revision failure');
        try { $this->exportRepository($db)->reverseSent('reverse'); }
        finally {
            self::assertSame(1, $db->table('TblProspects')->value('SMS_Attempts'));
            self::assertSame('2026-10-19', $db->table('TblProspects')->value('Latest_Export_Date'));
            self::assertSame(1, $db->table('TblSMSLegacySends')->count());
            self::assertSame(0, $this->revisions());
            $this->assertDirtyConnections([1]);
            self::assertSame([], $this->requests->observations);
        }
    }

    private function phoneCommand(): SyncPhoneNumbers
    {
        return new class extends SyncPhoneNumbers {
            public function __construct() { parent::__construct(); $this->withCreatedDate = true; }
            public function info($string, $verbosity = null): void {}
            public function replace(PDO $pdo, string $path): array { return $this->replacePhonesFromFile($pdo, $path, 1); }
        };
    }

    private function phoneStage(bool $empty = false): string
    {
        $path = tempnam(sys_get_temp_dir(), 'sms-durable-boundary-');
        if ($path === false) throw new RuntimeException('Unable to create fixture.');
        $this->files[] = $path;
        file_put_contents($path, $empty ? '' : json_encode(['phone' => '3035550001', 'cid' => 1, 'created' => '2026-10-12'], JSON_THROW_ON_ERROR)."\n");
        return $path;
    }

    public function test_native_phone_commit_persists_revision_before_request_and_dispatch_failure_does_not_rollback(): void
    {
        $this->requests->fail = true;
        $logger = Mockery::mock();
        $logger->shouldReceive('warning')->once()->andThrow(new RuntimeException('logger failure'));
        Log::swap($logger);
        self::assertSame([0, 1, 0], $this->phoneCommand()->replace($this->db->getPdo(), $this->phoneStage()));
        $this->assertDirtyConnections([0]);
        self::assertSame(1, $this->db->table('TblPhoneNumbers')->count());
        self::assertSame(1, $this->revisions());
        self::assertSame([[0, false, 1, 0, false]], $this->requests->observations);
    }

    public function test_native_phone_revision_failure_rolls_back_replacement(): void
    {
        $this->db->table('TblPhoneNumbers')->insert(['Phone' => '3035550099', 'Source' => 'DP_LT']);
        $this->ledger->failAt = 1;
        $this->expectExceptionMessage('synthetic revision failure');
        try { $this->phoneCommand()->replace($this->db->getPdo(), $this->phoneStage()); }
        finally {
            self::assertSame(['3035550099'], $this->db->table('TblPhoneNumbers')->pluck('Phone')->all());
            self::assertSame(0, $this->revisions());
            self::assertSame([], $this->requests->observations);
            self::assertFalse($this->db->getPdo()->inTransaction());
        }
    }

    public function test_native_begin_failure_cannot_fall_through_to_autocommitted_phone_writes(): void
    {
        $pdo = new class extends PDO {
            public int $writes = 0;
            public function __construct() {}
            public function beginTransaction(): bool { return false; }
            public function exec(string $statement): int|false { $this->writes++; return 1; }
        };
        $original = $this->db->getPdo();
        $this->db->setPdo($pdo);
        $this->expectExceptionMessage('SQL Server phone replacement transaction could not start.');
        try { $this->phoneCommand()->replace($pdo, $this->phoneStage()); }
        finally {
            $this->db->setPdo($original);
            self::assertSame(0, $pdo->writes);
            self::assertSame([], $this->ledger->observations);
            self::assertSame([], $this->requests->observations);
        }
    }

    public function test_native_phone_replacement_rejects_a_different_pdo_before_starting_writes(): void
    {
        $other = new PDO('sqlite::memory:');
        $this->expectException(LogicException::class);
        try { $this->phoneCommand()->replace($other, $this->phoneStage()); }
        finally {
            self::assertFalse($other->inTransaction());
            self::assertSame([], $this->ledger->observations);
            self::assertSame([], $this->requests->observations);
        }
    }

    public function test_native_precommit_helper_rejects_identity_mismatch_and_inactive_pdo(): void
    {
        $other = new PDO('sqlite::memory:');
        $other->beginTransaction();
        try {
            try { SmsAvailabilityRefresh::beforeNativeCommit($this->db, $other); self::fail('Expected identity rejection.'); }
            catch (LogicException) {}
        } finally { $other->rollBack(); }
        $this->expectException(LogicException::class);
        try { SmsAvailabilityRefresh::beforeNativeCommit($this->db, $this->db->getPdo()); }
        finally { self::assertSame([], $this->ledger->observations); }
    }

    public function test_native_replacement_cannot_commit_an_ambient_laravel_transaction(): void
    {
        $this->db->transaction(function (): void {
            $this->expectException(LogicException::class);
            $this->phoneCommand()->replace($this->db->getPdo(), $this->phoneStage());
        });
    }

    public function test_native_noop_does_not_dirty_or_request(): void
    {
        self::assertSame([0, 0, 0], $this->phoneCommand()->replace($this->db->getPdo(), $this->phoneStage(true)));
        self::assertSame([], $this->ledger->observations);
        self::assertSame([], $this->requests->observations);
    }

    public function test_snapshot_entry_point_uses_read_transaction_and_leaves_legacy_entry_point_unchanged(): void
    {
        $repo = new class($this->db) extends SmsProspectExportRepository {
            public array $levels = [];
            public function __construct(private Connection $db) {}
            protected function connection(): ConnectionInterface { return $this->db; }
            public function availability(): array
            {
                $this->levels[] = $this->db->transactionLevel();
                return ['eligible' => 7, 'never_texted' => 5, 'texted_before' => 2];
            }
        };
        self::assertSame($repo->availabilityWithTimeout(), $repo->availabilitySnapshotWithTimeout());
        self::assertSame([0, 1], $repo->levels);
        self::assertSame(0, $this->db->transactionLevel());
        self::assertSame([], $this->ledger->observations);
    }
}
