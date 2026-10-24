<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Repositories\SmsProspectExportRepository;
use Cmd\Reports\Services\SmsContactAttribution;
use Cmd\Reports\Services\SmsContactEvent;
use Cmd\Reports\Services\SmsExternalEventService;
use Cmd\Reports\Services\SmsRunService;
use Cmd\Reports\Services\SmsWorkflowBusyException;
use Cmd\Reports\Services\SmsWorkflowLock;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\SqlServerConnection;
use Illuminate\Support\Facades\Facade;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Real Laravel begin/reconnect/rollback control flow, entirely fake PDO/SQL. */
final class SmsContactCoordinationTest extends TestCase
{
    private const ID = '11111111-2222-4333-8444-555555555555';
    private const SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private Container $previous;
    private Container $container;

    protected function setUp(): void
    {
        $this->previous = Container::getInstance();
        $this->container = new Container;
        Container::setInstance($this->container);
        $this->container->instance('config', new Repository(['sms-exports' => [
            'contact_attribution_enabled' => true, 'record_workflow_enabled' => true,
            'durable_availability_enabled' => true, 'contact_source_system' => 'fixture.contacts',
            'contact_source_contract_sha256' => self::SHA,
        ]]));
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->container);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Container::setInstance($this->previous);
        Facade::setFacadeApplication($this->previous);
    }

    private function bind(ContactCoordinationConnection $db): void
    {
        $this->container->instance('db', new class($db) {
            public function __construct(private ContactCoordinationConnection $db) {}
            public function connection($name): ContactCoordinationConnection
            {
                TestCase::assertSame('sqlsrv', $name);
                return $this->db;
            }
        });
        Facade::clearResolvedInstance('db');
    }

    private function action(string $action): mixed
    {
        if ($action === 'record') {
            return (new SmsContactAttribution)->record(SmsContactEvent::fromCanonical(1, '0005550100',
                '2000-01-01T12:00:00.123456789Z', 'fixture.contacts', 'synthetic-1', self::SHA, 1, true));
        }
        $runs = new class(new SmsProspectExportRepository) extends SmsRunService {
            // Read-only version classification is distinct from the first
            // coordinated business query, whose real callback stays exercised.
            protected function mustRun(string $requestId): object
            {
                $db = \Illuminate\Support\Facades\DB::connection('sqlsrv');
                $db->trace[] = 'readonly-version-preflight';
                return (object) ['Target' => 1, 'Run_Options_JSON' => null, 'Status' => 'file_ready'];
            }
            public function phaseA(string $id, PDO $session): mixed { return $this->allocateApprovalDrops($id, (object) [], [], $session); }
            public function phaseC(string $id): void { $this->commitApprovedFiles($id, []); }
        };
        if ($action === 'allocate') {
            $session = $this->container->make('db')->connection('sqlsrv')->getPdo();
            return SmsWorkflowLock::run('run:'.self::ID, fn () => $runs->phaseA(self::ID, $session));
        }
        if ($action === 'commit') { $runs->phaseC(self::ID); return null; }
        if ($action === 'requestApprove') return $runs->requestApprove(self::ID, 'synthetic@example.invalid');
        $runs->markApproved(self::ID);
        return null;
    }

    public static function businessActions(): array
    {
        return [['record', 1], ['requestApprove', 2], ['markApproved', 2]];
    }

    public static function approvalFilePhases(): array { return [['allocate', 2], ['commit', 1]]; }

    public function test_flag_off_passes_null_without_reading_connection_pdo_or_application_lock(): void
    {
        config(['sms-exports.contact_attribution_enabled' => false]);
        $this->container->instance('db', new class {
            public function connection($name): never { throw new RuntimeException('Unexpected database access with flag off.'); }
        });
        self::assertSame('legacy result', SmsContactAttribution::coordinate(function (?PDO $session): string {
            self::assertNull($session);
            SmsContactAttribution::assertPinnedSession($session);
            return 'legacy result';
        }));
    }

    public function test_sql_driver_pins_writer_before_lock_and_passes_that_exact_session_to_callback(): void
    {
        $pdo = new ContactCoordinationPdo('original');
        $db = new ContactCoordinationConnection($pdo); $this->bind($db);
        self::assertSame('coordinated result', SmsContactAttribution::coordinate(function (PDO $session) use ($pdo, $db): string {
            self::assertSame($pdo, $session);
            self::assertSame(0, $db->transactionLevel());
            self::assertSame(['pdo:original', 'acquire:sms:contact-attribution', 'pdo:original'], $db->trace);
            return 'coordinated result';
        }));
        self::assertSame('release:sms:contact-attribution', $db->trace[array_key_last($db->trace)]);
        self::assertSame(['sms:contact-attribution'], $db->acquired);
        self::assertSame($db->acquired, $db->released);
        self::assertSame(0, $pdo->beginCalls);
    }

    public function test_replacement_during_application_lock_acquisition_never_reaches_callback(): void
    {
        $original = new ContactCoordinationPdo('original');
        $replacement = new ContactCoordinationPdo('replacement');
        $db = new ContactCoordinationConnection($original);
        $db->replaceDuringAcquisition = $replacement; $this->bind($db);
        $called = false;
        try {
            SmsContactAttribution::coordinate(function () use (&$called): void { $called = true; });
            self::fail('A newly acquired lock must not silently replace the captured writer session.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('coordination session changed', $error->getMessage());
        }
        self::assertFalse($called);
        self::assertSame(['pdo:original', 'acquire:sms:contact-attribution', 'pdo:replacement',
            'release:sms:contact-attribution'], $db->trace);
        self::assertSame(0, $original->beginCalls);
        self::assertSame(0, $replacement->beginCalls);
        self::assertSame(0, $db->businessActions);
        self::assertSame(['sms:contact-attribution'], $db->released);
    }

    public function test_lock_contention_does_not_enter_callback_or_release_an_unacquired_lock(): void
    {
        $db = new ContactCoordinationConnection(new ContactCoordinationPdo('original'));
        $db->lockResult = -1; $this->bind($db);
        $called = false;
        try {
            SmsContactAttribution::coordinate(function () use (&$called): void { $called = true; });
            self::fail('Expected application-lock contention refusal.');
        } catch (SmsWorkflowBusyException $error) { self::assertSame(409, $error->getStatusCode()); }
        self::assertFalse($called);
        self::assertSame([], $db->released);
        self::assertSame(0, $db->businessActions);
    }

    #[DataProvider('businessActions')]
    public function test_each_real_business_callback_checks_stable_session_after_begin_before_first_table_action(string $action, int $locks): void
    {
        $pdo = new ContactCoordinationPdo('original');
        $db = new ContactCoordinationConnection($pdo); $this->bind($db);
        try {
            $this->action($action);
            self::fail('The synthetic business sentinel must stop the callback without querying a database.');
        } catch (RuntimeException $error) { self::assertSame('Synthetic business sentinel.', $error->getMessage()); }
        self::assertSame(1, $db->businessActions);
        self::assertSame([['level' => $action === 'record' ? 1 : 0, 'session' => 'original']], $db->externalChecks,
            'An empty external ledger permits the real callback; contact checks stay inside its pinned transaction.');
        self::assertSame(1, $pdo->beginCalls);
        self::assertSame(1, $pdo->rollbackCalls);
        self::assertSame(0, $pdo->commitCalls);
        self::assertFalse($pdo->active);
        self::assertSame(0, $db->transactionLevel());
        self::assertCount($locks, $db->acquired);
        self::assertSame(array_reverse($db->acquired), $db->released);
        $business = array_search('business', $db->trace, true);
        self::assertIsInt($business);
        self::assertSame('pdo:original', $db->trace[$business - 1], 'Pin guard must precede the first business table action.');
    }

    #[DataProvider('businessActions')]
    public function test_laravel_real_begin_reconnect_is_rejected_before_each_business_callback_can_act(string $action, int $locks): void
    {
        $original = new ContactCoordinationPdo('original', true);
        $replacement = new ContactCoordinationPdo('replacement');
        $db = new ContactCoordinationConnection($original); $this->bind($db);
        $reconnections = 0;
        $db->setReconnector(function ($connection) use ($replacement, &$reconnections): void {
            $reconnections++;
            $connection->setPdo($replacement);
        });
        try {
            $this->action($action);
            self::fail('A replacement session has no approval/contact coordination lock.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('coordination session changed', $error->getMessage());
        }
        self::assertSame(1, $reconnections, 'Exercise Laravel actual lost-connection retry at begin, not a replacement transaction mock.');
        self::assertSame(1, $original->beginCalls);
        self::assertSame(1, $replacement->beginCalls);
        self::assertSame(1, $replacement->rollbackCalls, 'Rollback the replacement transaction after the pin guard rejects it.');
        self::assertSame(0, $replacement->commitCalls);
        self::assertSame(0, $original->commitCalls);
        self::assertFalse($replacement->active);
        self::assertSame(0, $db->transactionLevel());
        self::assertSame(0, $db->businessActions);
        self::assertSame($action === 'record' ? [] : [['level' => 0, 'session' => 'original']], $db->externalChecks,
            'A reconnected transaction must fail its pin guard before any in-transaction external or business read.');
        self::assertCount($locks, $db->acquired);
        self::assertSame(array_reverse($db->acquired), $db->released);
    }

    public function test_pending_external_header_holds_contact_attribution_inside_its_pinned_transaction(): void
    {
        $pdo = new ContactCoordinationPdo('original');
        $db = new ContactCoordinationConnection($pdo); $db->pendingExternal = true; $this->bind($db);
        self::assertSame(['outcome' => 'held', 'reason' => 'historical_registration_in_progress', 'request_id' => null], $this->action('record'));
        self::assertSame([['level' => 1, 'session' => 'original']], $db->externalChecks);
        self::assertSame(0, $db->businessActions);
        self::assertSame(1, $pdo->beginCalls); self::assertSame(1, $pdo->commitCalls); self::assertSame(0, $pdo->rollbackCalls);
        self::assertFalse($pdo->active); self::assertSame(0, $db->transactionLevel());
        self::assertSame(['sms:contact-attribution'], $db->acquired); self::assertSame($db->acquired, $db->released);
    }

    public function test_pending_external_header_refuses_both_approval_entry_points_before_business_transactions(): void
    {
        foreach (['requestApprove', 'markApproved'] as $action) {
            $pdo = new ContactCoordinationPdo('original');
            $db = new ContactCoordinationConnection($pdo); $db->pendingExternal = true; $this->bind($db);
            try { $this->action($action); self::fail('Pending historical registration must block another approval.'); }
            catch (HttpException $error) {
                self::assertSame(409, $error->getStatusCode());
                self::assertSame('Historical SMS registration must finish before another approval.', $error->getMessage());
            }
            self::assertSame([['level' => 0, 'session' => 'original']], $db->externalChecks);
            self::assertSame(0, $db->businessActions); self::assertSame(0, $pdo->beginCalls);
            self::assertSame([], $db->acquired); self::assertSame([], $db->released);
            self::assertFalse($pdo->active); self::assertSame(0, $db->transactionLevel());
        }
    }

    #[DataProvider('approvalFilePhases')]
    public function test_new_approval_phases_pin_sessions_independently_when_contact_attribution_is_disabled(string $action, int $locks): void
    {
        config(['sms-exports.contact_attribution_enabled' => false]);
        $original = new ContactCoordinationPdo('original', true);
        $replacement = new ContactCoordinationPdo('replacement');
        $db = new ContactCoordinationConnection($original); $this->bind($db);
        $reconnections = 0;
        $db->setReconnector(function ($connection) use ($replacement, &$reconnections): void {
            $reconnections++; $connection->setPdo($replacement);
        });
        try { $this->action($action); self::fail('Replacement PDO must not mutate allocation or approval state.'); }
        catch (RuntimeException $error) { self::assertStringContainsString('coordination session changed', $error->getMessage()); }
        self::assertSame(1, $reconnections);
        self::assertSame(1, $original->beginCalls);
        self::assertSame(1, $replacement->beginCalls);
        self::assertSame(1, $replacement->rollbackCalls);
        self::assertSame(0, $replacement->commitCalls);
        self::assertSame(0, $db->businessActions);
        self::assertCount($locks, $db->acquired);
        self::assertSame(array_reverse($db->acquired), $db->released);
        self::assertSame(0, $db->transactionLevel());
    }

    #[DataProvider('approvalFilePhases')]
    public function test_new_approval_phases_with_stable_sessions_reach_the_guarded_business_callback(string $action, int $locks): void
    {
        config(['sms-exports.contact_attribution_enabled' => false]);
        $pdo = new ContactCoordinationPdo('original');
        $db = new ContactCoordinationConnection($pdo); $this->bind($db);
        try { $this->action($action); self::fail('Expected synthetic business sentinel.'); }
        catch (RuntimeException $error) { self::assertSame('Synthetic business sentinel.', $error->getMessage()); }
        self::assertSame(1, $db->businessActions);
        self::assertSame(1, $pdo->rollbackCalls);
        self::assertCount($locks, $db->acquired);
        self::assertSame(array_reverse($db->acquired), $db->released);
        $business = array_search('business', $db->trace, true);
        self::assertSame('pdo:original', $db->trace[$business - 1]);
    }
}

/** No PDO constructor/driver socket is called; only transaction state is simulated. */
final class ContactCoordinationPdo extends PDO
{
    public bool $active = false;
    public int $beginCalls = 0;
    public int $commitCalls = 0;
    public int $rollbackCalls = 0;
    public function __construct(public string $label, private bool $loseAtBegin = false) {}
    public function inTransaction(): bool { return $this->active; }
    public function beginTransaction(): bool
    {
        $this->beginCalls++;
        if ($this->loseAtBegin) throw new PDOException('server has gone away');
        $this->active = true;
        return true;
    }
    public function commit(): bool { $this->commitCalls++; $this->active = false; return true; }
    public function rollBack(): bool { $this->rollbackCalls++; $this->active = false; return true; }
}

/** SQL driver exercises the real application-lock branch; no SQL is executed. */
final class ContactCoordinationConnection extends SqlServerConnection
{
    public array $trace = [];
    public array $acquired = [];
    public array $released = [];
    public int $businessActions = 0;
    public int $lockResult = 0;
    public bool $pendingExternal = false;
    public array $externalChecks = [];
    public ?PDO $replaceDuringAcquisition = null;

    public function __construct(ContactCoordinationPdo $pdo)
    {
        parent::__construct($pdo, 'synthetic-no-database', '', ['driver' => 'sqlsrv']);
    }

    public function getPdo()
    {
        $pdo = parent::getPdo();
        $this->trace[] = 'pdo:'.$pdo->label;
        return $pdo;
    }

    public function selectOne($query, $bindings = [], $useReadPdo = true)
    {
        TestCase::assertStringContainsString('sys.sp_getapplock', $query);
        TestCase::assertCount(1, $bindings);
        $this->trace[] = 'acquire:'.$bindings[0];
        if ($this->lockResult >= 0) $this->acquired[] = $bindings[0];
        if ($this->replaceDuringAcquisition !== null) {
            $this->setPdo($this->replaceDuringAcquisition);
            $this->replaceDuringAcquisition = null;
        }
        return (object) ['Result' => $this->lockResult];
    }

    public function statement($query, $bindings = [])
    {
        TestCase::assertStringContainsString('sys.sp_releaseapplock', $query);
        TestCase::assertCount(1, $bindings);
        $this->released[] = $bindings[0];
        $this->trace[] = 'release:'.$bindings[0];
        return true;
    }

    public function getSchemaBuilder()
    {
        return new class {
            public function hasTable($table): bool { return true; }
            public function hasColumns($table, $columns): bool { return true; }
            public function hasColumn($table, $column): bool { return true; }
            public function getIndexes($table): array
            {
                return [['name' => 'IX_'.$table.'_Contact_Attribution', 'columns' => ['Prospect_PK', 'Phone', 'Request_ID']]];
            }
        };
    }

    public function table($table, $as = null)
    {
        if ($table === SmsExternalEventService::EVENTS) {
            return new class($this) {
                private bool $pendingOnly = false;
                public function __construct(private ContactCoordinationConnection $db) {}
                public function where($column, $operator, $value): self
                {
                    TestCase::assertSame(['State', '<>', 'completed'], [$column, $operator, $value]);
                    $this->pendingOnly = true;
                    return $this;
                }
                public function exists(): bool
                {
                    TestCase::assertTrue($this->pendingOnly, 'Only the explicit pending-header probe is modeled.');
                    return $this->db->externalProbe();
                }
            };
        }
        $this->businessActions++;
        $this->trace[] = 'business';
        throw new RuntimeException('Synthetic business sentinel.');
    }

    public function externalProbe(): bool
    {
        // Observe fake state without adding a getPdo trace entry that could
        // accidentally satisfy the separate first-business pin-guard assertion.
        $this->externalChecks[] = ['level' => $this->transactionLevel(), 'session' => parent::getPdo()->label];
        return $this->pendingExternal;
    }
}
