<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Services\SmsAvailabilityLedger;
use Cmd\Reports\Services\SmsExportQueue;
use Cmd\Reports\Services\SmsStatePolicy;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\CacheManager;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\SqlServerConnection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use InvalidArgumentException;
use LogicException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Real SQLite transactions, no application bootstrap, SQL Server, network or worker. */
final class SmsAvailabilityLedgerTest extends TestCase
{
    private const COUNTS = ['eligible' => 11, 'never_texted' => 7, 'texted_before' => 4];

    private Container $previous;
    private Container $app;
    private Connection $db;
    private SmsAvailabilityLedger $ledger;
    private Migration $migration;

    protected function setUp(): void
    {
        $this->previous = Container::getInstance();
        $this->app = new Container;
        Container::setInstance($this->app);
        $this->app->instance('config', new Repository([
            'app' => ['env' => 'testing', 'timezone' => 'America/Los_Angeles'],
            'database' => ['default' => 'sqlsrv', 'connections' => ['sqlsrv' => [
                'host' => 'synthetic.invalid', 'port' => '1433', 'database' => 'sms_ledger_test',
            ]]],
            'sms-exports' => ['demo' => false],
            'cache' => ['default' => 'array', 'stores' => ['array' => ['driver' => 'array']]],
        ]));
        $scopeConfig = $this->app['config']->get('database.connections.sqlsrv');
        $capsule = new Manager($this->app);
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'sqlsrv');
        $capsule->getDatabaseManager()->setDefaultConnection('sqlsrv');
        $this->app->instance('db', $capsule->getDatabaseManager());
        $this->db = $capsule->getConnection('sqlsrv');
        // Capsule stores its SQLite connection config in the container too. Keep the
        // configured production-style scope identity after opening the test connection.
        $this->app['config']->set('database.connections.sqlsrv', $scopeConfig);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);
        Carbon::setTestNow('2026-10-20 01:00:00 UTC');
        $this->migration = require __DIR__.'/../../database/sms-migrations/2026_10_20_000001_create_sms_availability_summary.php';
        $this->migration->up();
        $this->db->statement('CREATE TABLE business_changes (value INTEGER)');
        $this->ledger = new SmsAvailabilityLedger;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->db->disconnect();
        Facade::clearResolvedInstances();
        Container::setInstance($this->previous);
        Facade::setFacadeApplication($this->previous);
    }

    private function advance(int $seconds): void
    {
        Carbon::setTestNow(Carbon::now('UTC')->addSeconds($seconds));
    }

    private function dirty(): void
    {
        $this->db->transaction(fn () => $this->ledger->dirty($this->db));
    }

    private function running(bool $force = false): array
    {
        $claim = $this->ledger->claim($force);
        self::assertNotNull($claim);
        $row = $this->ledger->start($claim['scope'], $claim['generation']);
        self::assertNotNull($row);

        return $row;
    }

    private function ready(): array
    {
        $row = $this->running();
        self::assertTrue($this->ledger->complete($row['scope'], $row['generation'], self::COUNTS));

        return $this->ledger->read();
    }

    private function lastGood(array $row): array
    {
        return array_intersect_key($row, array_flip([
            'eligible', 'never_texted', 'texted_before', 'counted_at', 'published_revision', 'latest_data_date',
        ]));
    }

    public function test_scope_is_the_fixed_order_policy_versioned_digest(): void
    {
        self::assertSame(hash('sha256', json_encode([
            'testing', 'synthetic.invalid', '1433', 'sms_ledger_test', false, 'legacy-phone-v1', SmsStatePolicy::VERSION,
        ], JSON_THROW_ON_ERROR)), $this->ledger->scope());
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $this->ledger->scope());
    }

    public function test_writes_in_either_policy_invalidate_both_previously_published_scopes(): void
    {
        $legacy = $this->ready();
        config(['sms-exports.record_workflow_enabled' => true]);
        $record = $this->ready();
        self::assertNotSame($legacy['scope'], $record['scope']);
        config(['sms-exports.record_workflow_enabled' => false]);
        $this->dirty();
        self::assertSame([1,0], [$this->ledger->read()['revision'], $this->ledger->read()['published_revision']]);
        config(['sms-exports.record_workflow_enabled' => true]);
        self::assertSame([1,0], [$this->ledger->read()['revision'], $this->ledger->read()['published_revision']]);
        $this->ready(); // A record count is recalculated at its new revision.
        $this->dirty(); // Now an eligibility write occurs under record mode.
        config(['sms-exports.record_workflow_enabled' => false]);
        self::assertSame([2,0], [$this->ledger->read()['revision'], $this->ledger->read()['published_revision']]);
        self::assertNotNull($this->ledger->claim(), 'Switching back cannot reuse a fresh-looking old snapshot.');
    }

    public function test_each_scope_input_isolated_but_unrelated_configuration_is_ignored(): void
    {
        $config = $this->app['config'];
        $scope = $this->ledger->scope();
        foreach ([
            'app.env' => 'production',
            'database.connections.sqlsrv.host' => 'other.invalid',
            'database.connections.sqlsrv.port' => '1434',
            'database.connections.sqlsrv.database' => 'other_database',
            'sms-exports.demo' => true,
        ] as $key => $value) {
            $old = $config->get($key);
            $config->set($key, $value);
            self::assertNotSame($scope, $this->ledger->scope(), $key);
            $config->set($key, $old);
        }
        $config->set('cache.default', 'unavailable');
        $config->set('app.timezone', 'Pacific/Auckland');
        self::assertSame($scope, $this->ledger->scope());
    }

    public function test_read_does_not_create_a_row_and_initial_claim_has_raw_schema_defaults(): void
    {
        self::assertNull($this->ledger->read());
        $row = $this->ledger->claim();
        self::assertSame($this->ledger->scope(), $row['scope']);
        self::assertSame('queued', $row['status']);
        self::assertSame([0, 0, null], [$row['revision'], $row['published_revision'], $row['count_revision']]);
        self::assertMatchesRegularExpression('/^[a-f0-9-]{36}$/', $row['generation']);
        self::assertSame('2026-10-20 01:00:00', $row['requested_at']);
        self::assertSame('2026-10-20 03:01:40', $row['lease_until']);
        self::assertSame(SmsExportQueue::JOB_TIMEOUT + 300, SmsAvailabilityLedger::LEASE_SECONDS);
        foreach (['started_at', 'retry_at', 'counted_at', 'eligible', 'never_texted', 'texted_before', 'latest_data_date', 'error_code'] as $key) {
            self::assertNull($row[$key], $key);
        }
        self::assertSame($row, $this->ledger->read());
        self::assertSame(0, $this->db->transactionLevel());
        self::assertFalse($this->db->getPdo()->inTransaction());
    }

    public function test_dirty_requires_an_active_writer_transaction(): void
    {
        try {
            $this->ledger->dirty($this->db);
            self::fail('Expected transaction requirement.');
        } catch (LogicException $error) {
            self::assertStringContainsString('active writer transaction', $error->getMessage());
        }
        self::assertNull($this->ledger->read());
    }

    public function test_dirty_is_atomic_with_business_rollback_including_first_row_creation(): void
    {
        try {
            $this->db->transaction(function (): void {
                $this->db->table('business_changes')->insert(['value' => 1]);
                $this->ledger->dirty($this->db);
                self::assertSame(1, $this->db->table(SmsAvailabilityLedger::TABLE)->value('revision'));
                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException $error) {
            self::assertSame('rollback', $error->getMessage());
        }
        self::assertNull($this->ledger->read());
        self::assertSame(0, $this->db->table('business_changes')->count());
    }

    public function test_revisions_increment_once_per_dirty_and_nested_rollback_keeps_committed_value(): void
    {
        $this->dirty();
        $this->db->beginTransaction();
        $this->ledger->dirty($this->db);
        $this->db->beginTransaction();
        $this->ledger->dirty($this->db);
        self::assertSame(3, $this->db->table(SmsAvailabilityLedger::TABLE)->value('revision'));
        $this->db->commit();
        $this->db->rollBack();
        self::assertSame(1, $this->ledger->read()['revision']);
        $this->dirty();
        self::assertSame(2, $this->ledger->read()['revision']);
        self::assertSame(2, $this->db->table(SmsAvailabilityLedger::TABLE)->count(), 'Both shared-data policies must be invalidated.');
    }

    public function test_dirty_joins_native_pdo_transaction_without_beginning_or_committing_one(): void
    {
        $pdo = $this->db->getPdo();
        foreach ([false, true] as $commit) {
            $pdo->beginTransaction();
            self::assertSame(0, $this->db->transactionLevel());
            $this->db->table('business_changes')->insert(['value' => 1]);
            $this->ledger->dirty($this->db);
            $this->ledger->dirty($this->db);
            self::assertSame(0, $this->db->transactionLevel());
            self::assertTrue($pdo->inTransaction());
            self::assertSame(2, $this->db->table(SmsAvailabilityLedger::TABLE)->value('revision'));
            $commit ? $pdo->commit() : $pdo->rollBack();
            if (! $commit) self::assertNull($this->ledger->read());
        }
        self::assertSame(2, $this->ledger->read()['revision']);
        self::assertSame(1, $this->db->table('business_changes')->count());
    }

    public function test_dirty_uses_the_supplied_writer_instead_of_the_facade_connection(): void
    {
        $capsule = new Manager;
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => 'writer_']);
        $writer = $capsule->getConnection();
        $writer->statement('CREATE TABLE writer_TblSMSAvailabilitySummary (scope CHAR(64) PRIMARY KEY, revision BIGINT DEFAULT 0)');
        try {
            $writer->transaction(fn () => $this->ledger->dirty($writer));
            self::assertSame(1, $writer->table(SmsAvailabilityLedger::TABLE)->value('revision'));
            self::assertNull($this->ledger->read());
        } finally {
            $writer->disconnect();
        }
    }

    public function test_claim_coalesces_queued_and_running_work_even_when_forced(): void
    {
        $queued = $this->ledger->claim();
        foreach ([false, true] as $force) self::assertNull((new SmsAvailabilityLedger)->claim($force));
        self::assertSame($queued, $this->ledger->read());
        $running = $this->ledger->start($queued['scope'], $queued['generation']);
        foreach ([false, true] as $force) self::assertNull((new SmsAvailabilityLedger)->claim($force));
        self::assertSame($running, $this->ledger->read());
        self::assertSame(1, $this->db->table(SmsAvailabilityLedger::TABLE)->count());
    }

    public function test_start_freezes_current_revision_after_queued_dirty_and_renews_the_lease(): void
    {
        $queued = $this->ledger->claim();
        $this->advance(10);
        $this->dirty();
        $dirty = $this->ledger->read();
        self::assertSame(array_replace($queued, ['revision' => 1]), $dirty);
        $running = $this->ledger->start($queued['scope'], $queued['generation']);
        self::assertSame(['running', 1, 1], [$running['status'], $running['revision'], $running['count_revision']]);
        self::assertSame('2026-10-20 01:00:10', $running['started_at']);
        self::assertSame('2026-10-20 03:01:50', $running['lease_until']);
        self::assertNull($this->ledger->start($queued['scope'], $queued['generation']));
        self::assertSame($running, $this->ledger->read());
        self::assertTrue($this->ledger->complete($queued['scope'], $queued['generation'], self::COUNTS));
        self::assertSame(1, $this->ledger->read()['published_revision']);
    }

    public function test_success_publishes_counts_and_metadata_together_and_late_callbacks_are_fenced(): void
    {
        $this->dirty();
        $row = $this->running();
        self::assertFalse($this->db->getPdo()->inTransaction(), 'No ledger lock may span the source count.');
        $this->advance(2);
        self::assertTrue($this->ledger->complete($row['scope'], $row['generation'], self::COUNTS));
        $ready = $this->ledger->read();
        self::assertSame('ready', $ready['status']);
        self::assertSame(self::COUNTS, array_intersect_key($ready, self::COUNTS));
        self::assertSame(1, $ready['published_revision']);
        self::assertSame('2026-10-20 01:00:02', $ready['counted_at']);
        self::assertSame([null, null, null, null], [$ready['lease_until'], $ready['retry_at'], $ready['error_code'], $ready['latest_data_date']]);
        $this->ledger->fail($row['scope'], $row['generation']);
        self::assertFalse($this->ledger->complete($row['scope'], $row['generation'], ['eligible' => 0, 'never_texted' => 0, 'texted_before' => 0]));
        self::assertNull($this->ledger->start($row['scope'], $row['generation']));
        self::assertSame($ready, $this->ledger->read());
    }

    public function test_dirty_preserves_terminal_last_good_and_running_generation_until_completion(): void
    {
        $ready = $this->ready();
        $this->dirty();
        self::assertSame(array_replace($ready, ['revision' => 1]), $this->ledger->read());
        $running = $this->running();
        self::assertSame($this->lastGood($ready), $this->lastGood($running));
        $this->dirty();
        self::assertSame(array_replace($running, ['revision' => 2]), $this->ledger->read());
        self::assertNull($this->ledger->claim(true));
        self::assertFalse($this->ledger->complete($running['scope'], $running['generation'], [
            'eligible' => 25, 'never_texted' => 20, 'texted_before' => 5,
        ]));
        $idle = $this->ledger->read();
        self::assertSame($this->lastGood($ready), $this->lastGood($idle));
        self::assertSame(['idle', 2, null, null, '2026-10-20 01:00:00'], [
            $idle['status'], $idle['revision'], $idle['generation'], $idle['lease_until'], $idle['retry_at'],
        ]);
        $this->ledger->fail($running['scope'], $running['generation']);
        self::assertSame($idle, $this->ledger->read());
        $next = $this->running();
        self::assertNotSame($running['generation'], $next['generation']);
        self::assertSame(2, $next['count_revision']);
        self::assertTrue($this->ledger->complete($next['scope'], $next['generation'], self::COUNTS));
        self::assertSame(2, $this->ledger->read()['published_revision']);
    }

    public function test_dirty_first_count_publishes_no_partial_aggregate_and_is_immediately_retryable(): void
    {
        $row = $this->running();
        $this->dirty();
        self::assertFalse($this->ledger->complete($row['scope'], $row['generation'], self::COUNTS));
        $idle = $this->ledger->read();
        foreach (['eligible', 'never_texted', 'texted_before', 'counted_at', 'latest_data_date'] as $key) {
            self::assertNull($idle[$key], $key);
        }
        self::assertSame(0, $idle['published_revision']);
        self::assertNotNull($this->ledger->claim());
    }

    public function test_ready_is_fresh_for_300_seconds_but_force_or_revision_change_can_refresh(): void
    {
        $this->ready();
        $this->advance(SmsAvailabilityLedger::FRESH_SECONDS - 1);
        self::assertNull($this->ledger->claim());
        $this->advance(1);
        self::assertNotNull($this->ledger->claim());
    }

    public function test_force_refresh_preserves_last_good_even_while_fresh(): void
    {
        $ready = $this->ready();
        self::assertNull($this->ledger->claim());
        $forced = $this->ledger->claim(true);
        self::assertNotNull($forced);
        self::assertSame($this->lastGood($ready), $this->lastGood($forced));
    }

    public static function activeStatuses(): array
    {
        return [['queued'], ['running']];
    }

    #[DataProvider('activeStatuses')]
    public function test_expired_lease_can_be_replaced_and_every_old_delivery_is_fenced(string $status): void
    {
        $old = $status === 'running' ? $this->running() : $this->ledger->claim();
        $this->advance(SmsAvailabilityLedger::LEASE_SECONDS);
        $before = $this->ledger->read();
        self::assertNull($this->ledger->start($old['scope'], $old['generation']));
        self::assertFalse($this->ledger->complete($old['scope'], $old['generation'], self::COUNTS));
        $this->ledger->fail($old['scope'], $old['generation']);
        self::assertSame($before, $this->ledger->read());
        $next = $this->ledger->claim();
        self::assertNotSame($old['generation'], $next['generation']);
        self::assertNull($this->ledger->start($old['scope'], $old['generation']));
        self::assertFalse($this->ledger->complete($old['scope'], $old['generation'], self::COUNTS));
        $this->ledger->fail($old['scope'], $old['generation']);
        self::assertSame($next, $this->ledger->read());
        self::assertNotNull($this->ledger->start($next['scope'], $next['generation']));
        self::assertTrue($this->ledger->complete($next['scope'], $next['generation'], self::COUNTS));
    }

    #[DataProvider('activeStatuses')]
    public function test_failure_retains_last_good_and_force_cannot_bypass_or_extend_retry_cooldown(string $status): void
    {
        $ready = $this->ready();
        $row = $status === 'running' ? $this->running(true) : $this->ledger->claim(true);
        $this->ledger->fail($row['scope'], $row['generation']);
        $failed = $this->ledger->read();
        self::assertSame('failed', $failed['status']);
        self::assertSame('count_failed', $failed['error_code']);
        self::assertSame('2026-10-20 01:01:00', $failed['retry_at']);
        self::assertSame($this->lastGood($ready), $this->lastGood($failed));
        self::assertSame([$row['requested_at'], $row['started_at']], [$failed['requested_at'], $failed['started_at']]);
        $this->advance(SmsAvailabilityLedger::RETRY_SECONDS - 1);
        $this->ledger->fail($row['scope'], $row['generation']);
        self::assertSame($failed, $this->ledger->read());
        $this->dirty();
        foreach ([false, true] as $force) self::assertNull($this->ledger->claim($force));
        $this->advance(1);
        $next = $this->ledger->claim(true);
        self::assertNotNull($next);
        self::assertNotSame($row['generation'], $next['generation']);
        self::assertNull($next['error_code']);
        self::assertNull($next['retry_at']);
        $this->ledger->fail($row['scope'], $row['generation']);
        self::assertSame($next, $this->ledger->read());
    }

    public function test_complete_requires_running_and_wrong_scope_or_generation_cannot_mutate_state(): void
    {
        $queued = $this->ledger->claim();
        self::assertFalse($this->ledger->complete($queued['scope'], $queued['generation'], self::COUNTS));
        foreach ([['other-scope', $queued['generation']], [$queued['scope'], 'other-generation']] as [$scope, $generation]) {
            self::assertNull($this->ledger->start($scope, $generation));
            self::assertFalse($this->ledger->complete($scope, $generation, self::COUNTS));
            $this->ledger->fail($scope, $generation);
        }
        self::assertSame($queued, $this->ledger->read());
        $this->app['config']->set('sms-exports.demo', true);
        self::assertNull($this->ledger->read());
        $demo = $this->ledger->claim();
        self::assertNotSame($queued['scope'], $demo['scope']);
        self::assertSame(2, $this->db->table(SmsAvailabilityLedger::TABLE)->count());
        self::assertNull($this->ledger->start($queued['scope'], $queued['generation']));
        $this->ledger->fail($queued['scope'], $queued['generation']);
        self::assertSame($demo, $this->ledger->read());
    }

    public static function invalidCounts(): array
    {
        return [
            'missing' => [['eligible' => 11, 'never_texted' => 7]],
            'null' => [['eligible' => 11, 'never_texted' => null, 'texted_before' => 4]],
            'numeric string' => [['eligible' => '11', 'never_texted' => 7, 'texted_before' => 4]],
            'float' => [['eligible' => 11, 'never_texted' => 7.0, 'texted_before' => 4]],
            'bool' => [['eligible' => 1, 'never_texted' => true, 'texted_before' => 0]],
            'negative' => [['eligible' => 3, 'never_texted' => -1, 'texted_before' => 4]],
            'inconsistent' => [['eligible' => 12, 'never_texted' => 7, 'texted_before' => 4]],
            'overflow' => [['eligible' => PHP_INT_MAX, 'never_texted' => PHP_INT_MAX, 'texted_before' => 1]],
        ];
    }

    #[DataProvider('invalidCounts')]
    public function test_invalid_counts_never_partially_publish(array $counts): void
    {
        $this->ready();
        $row = $this->running(true);
        try {
            $this->ledger->complete($row['scope'], $row['generation'], $counts);
            self::fail('Expected invalid aggregate rejection.');
        } catch (InvalidArgumentException) {
            self::assertSame($row, $this->ledger->read());
        }
    }

    public function test_zero_counts_are_valid_and_extra_fields_cannot_set_dates_or_error_metadata(): void
    {
        $row = $this->running();
        self::assertTrue($this->ledger->complete($row['scope'], $row['generation'], [
            'eligible' => 0, 'never_texted' => 0, 'texted_before' => 0,
            'latest_data_date' => '2099-12-31', 'error_code' => 'PRIVATE details', 'revision' => 999,
        ]));
        $ready = $this->ledger->read();
        self::assertSame([0, 0, 0], [$ready['eligible'], $ready['never_texted'], $ready['texted_before']]);
        self::assertNull($ready['latest_data_date']);
        self::assertNull($ready['error_code']);
        self::assertSame(0, $ready['revision']);
    }

    public function test_publication_failure_rolls_back_all_counts_and_metadata_preserving_last_good(): void
    {
        $this->ready();
        $row = $this->running(true);
        $this->db->statement("CREATE TRIGGER reject_publication AFTER UPDATE OF eligible ON TblSMSAvailabilitySummary
            BEGIN SELECT RAISE(ABORT, 'synthetic publication failure'); END");
        try {
            $this->ledger->complete($row['scope'], $row['generation'], [
                'eligible' => 3, 'never_texted' => 2, 'texted_before' => 1,
            ]);
            self::fail('Expected database rejection.');
        } catch (\Illuminate\Database\QueryException $error) {
            self::assertStringContainsString('synthetic publication failure', $error->getMessage());
        }
        self::assertSame($row, $this->ledger->read());
        self::assertSame(0, $this->db->transactionLevel());
        $this->ledger->fail($row['scope'], $row['generation']);
        self::assertSame($this->lastGood($row), $this->lastGood($this->ledger->read()));
    }

    public function test_cache_eviction_and_unavailable_cache_have_no_effect_on_durable_state(): void
    {
        $ready = $this->ready();
        $cache = new CacheManager($this->app);
        $this->app->instance('cache', $cache);
        self::assertInstanceOf(ArrayStore::class, $cache->store()->getStore());
        $cache->store()->put('unrelated', 'value', 60);
        $cache->store()->flush();
        self::assertSame($ready, (new SmsAvailabilityLedger)->read());
        $this->app->instance('cache', new class {
            public function __call(string $name, array $arguments): never { throw new RuntimeException('cache unavailable'); }
        });
        self::assertNull($this->ledger->claim());
        $this->dirty();
        $row = $this->running();
        self::assertTrue($this->ledger->complete($row['scope'], $row['generation'], self::COUNTS));
        self::assertSame(1, $this->ledger->read()['published_revision']);
    }

    public function test_state_operations_use_writer_pdo_even_when_read_replica_is_empty(): void
    {
        $this->db->setReadPdo(new PDO('sqlite::memory:'));
        $row = $this->running();
        self::assertTrue($this->ledger->complete($row['scope'], $row['generation'], self::COUNTS));
        self::assertSame('ready', $this->ledger->read()['status']);
    }

    public function test_sql_server_initialization_uses_range_lock_and_honors_the_writer_table_prefix(): void
    {
        // Compile SQL Server SQL over a local PDO; pretend() never executes the statements.
        $sqlsrv = new SqlServerConnection(new PDO('sqlite::memory:'), '', 'writer_', ['driver' => 'sqlsrv']);
        $sqlsrv->beginTransaction();
        try {
            $queries = $sqlsrv->pretend(fn () => $this->ledger->dirty($sqlsrv));
            self::assertCount(4, $queries);
            self::assertStringContainsString('INSERT INTO [writer_TblSMSAvailabilitySummary] ([scope]) SELECT ', $queries[0]['query']);
            self::assertStringContainsString('WITH (UPDLOCK, HOLDLOCK) WHERE [scope] = ', $queries[0]['query']);
            $first = $queries[0]['bindings'][0]; $second = $queries[2]['bindings'][0];
            self::assertSame([$first, $first], $queries[0]['bindings']);
            self::assertSame([$second, $second], $queries[2]['bindings']);
            self::assertLessThan(0, strcmp($first, $second), 'Policy scopes always lock in a stable order.');
            self::assertContains($this->ledger->scope(), [$first, $second]);
            self::assertStringContainsString('[revision] = [revision] + 1', $queries[1]['query']);
            self::assertSame(1, $sqlsrv->transactionLevel());
        } finally {
            $sqlsrv->rollBack();
        }

        DB::swap(new class($sqlsrv) {
            public function __construct(private Connection $db) {}
            public function connection(string $name): Connection
            {
                TestCase::assertSame('sqlsrv', $name);
                return $this->db;
            }
        });
        $queries = $sqlsrv->pretend(fn () => $this->ledger->read());
        self::assertCount(1, $queries);
        self::assertStringContainsString('with(rowlock,updlock,holdlock)', $queries[0]['query']);
        self::assertStringContainsString('[writer_TblSMSAvailabilitySummary]', $queries[0]['query']);
        self::assertSame(0, $sqlsrv->transactionLevel());
    }

    public function test_coordination_rejects_ambient_laravel_or_native_business_transactions(): void
    {
        foreach ([false, true] as $native) {
            $native ? $this->db->getPdo()->beginTransaction() : $this->db->beginTransaction();
            try {
                foreach (['read', 'claim'] as $method) {
                    try {
                        $this->ledger->{$method}();
                        self::fail('Expected business transaction guard.');
                    } catch (LogicException $error) {
                        self::assertStringContainsString('outside the writer transaction', $error->getMessage());
                        self::assertTrue($this->db->getPdo()->inTransaction());
                    }
                }
            } finally {
                $native ? $this->db->getPdo()->rollBack() : $this->db->rollBack();
            }
        }
        self::assertNull($this->ledger->read());
    }

    public function test_timestamps_are_utc_when_application_and_process_timezones_differ(): void
    {
        $timezone = date_default_timezone_get();
        date_default_timezone_set('America/Los_Angeles');
        try {
            Carbon::setTestNow(Carbon::parse('2026-10-19 18:30:00', 'America/Los_Angeles'));
            $row = $this->running();
            self::assertSame('2026-10-20 01:30:00', $row['requested_at']);
            self::assertSame('2026-10-20 01:30:00', $row['started_at']);
            self::assertSame('2026-10-20 03:31:40', $row['lease_until']);
            self::assertTrue($this->ledger->complete($row['scope'], $row['generation'], self::COUNTS));
            self::assertSame('2026-10-20 01:30:00', $this->ledger->read()['counted_at']);
        } finally {
            date_default_timezone_set($timezone);
        }
    }

    public function test_migration_up_down_is_additive_and_enforces_one_row_per_scope(): void
    {
        self::assertSame('sqlsrv', $this->migration->getConnection());
        $row = $this->ledger->claim();
        try {
            $this->db->table(SmsAvailabilityLedger::TABLE)->insert(['scope' => $row['scope']]);
            self::fail('Expected primary key constraint.');
        } catch (\Illuminate\Database\QueryException) {
            self::assertSame(1, $this->db->table(SmsAvailabilityLedger::TABLE)->count());
        }
        $this->db->table('business_changes')->insert(['value' => 7]);
        $this->migration->down();
        self::assertFalse($this->db->getSchemaBuilder()->hasTable(SmsAvailabilityLedger::TABLE));
        self::assertSame(7, $this->db->table('business_changes')->value('value'));
        $this->migration->up();
        self::assertTrue($this->db->getSchemaBuilder()->hasTable(SmsAvailabilityLedger::TABLE));
        self::assertNull($this->ledger->read());
    }
}
