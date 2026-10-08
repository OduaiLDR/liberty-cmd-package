<?php

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Jobs\PlanSmsSelectionJob;
use Cmd\Reports\Repositories\MailDropExportRepository;
use Cmd\Reports\Repositories\MarketingReportRepository;
use Cmd\Reports\Http\Controllers\SmsWorkflowApiController;
use Cmd\Reports\Services\SmsExportQueue;
use Cmd\Reports\Services\SmsSelectionProgress;
use Illuminate\Cache\CacheManager;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Mockery;
use PHPUnit\Framework\TestCase;

class SmsSelectionJobTest extends TestCase
{
    private Container $previous;
    private $db;
    private string $id = 'd983d923-1d9d-4099-a80c-1d9d7e5fdb7d';

    protected function setUp(): void
    {
        $this->previous = Container::getInstance();
        $app = new Container;
        Container::setInstance($app);
        $app->instance('config', new Repository(['database' => ['default' => 'sqlsrv'],
            'cache' => ['default' => 'file', 'stores' => ['file' => ['driver' => 'array']], 'prefix' => 'sms-test']]));
        $capsule = new Manager($app);
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:'], 'sqlsrv');
        $capsule->getDatabaseManager()->setDefaultConnection('sqlsrv');
        $app->instance('db', $capsule->getDatabaseManager());
        $app->instance('cache', new CacheManager($app));
        $app->instance('validator', new Factory(new Translator(new ArrayLoader, 'en'), $app));
        $logger = Mockery::mock(\Psr\Log\LoggerInterface::class);
        $logger->shouldReceive('error')->byDefault();
        $app->instance('log', $logger);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
        $this->db = $capsule->getConnection('sqlsrv');
        $this->db->getSchemaBuilder()->create('TblSmsSelectionRequests', function (Blueprint $t): void {
            $t->string('Request_ID')->primary(); $t->string('Actor_Email'); $t->string('Status');
            $t->string('Selection_Mode'); $t->integer('Target')->nullable(); $t->text('Drop_PKs')->nullable();
            $t->text('Result')->nullable(); $t->text('Error')->nullable(); $t->dateTime('Created_At'); $t->dateTime('Updated_At');
        });
        $this->db->table('TblSmsSelectionRequests')->insert(['Request_ID' => $this->id,
            'Actor_Email' => 'admin@example.test', 'Status' => 'queued', 'Selection_Mode' => 'automatic',
            'Target' => 1000, 'Created_At' => now(), 'Updated_At' => now()]);
    }

    protected function tearDown(): void
    {
        Mockery::close(); Facade::clearResolvedInstances();
        Container::setInstance($this->previous); Facade::setFacadeApplication($this->previous);
    }

    public function test_completion_and_progress_persist_after_claiming_the_job(): void
    {
        $state = $this->checkpoint();
        $repo = Mockery::mock(MailDropExportRepository::class);
        $repo->shouldReceive('startSelectionCheckpoint')->once()->with(1000, null)->andReturn($state);
        $repo->shouldReceive('advanceSelectionCheckpoint')->once()->with($state)->andReturnUsing(function ($state) {
            $row = $this->db->table('TblSmsSelectionRequests')->first();
            self::assertSame('running', $row->Status);
            self::assertSame(0, json_decode($row->Result, true)['progress']['processed_drops']);
            $state['cursor'] = 1;
            $state['subtotal'] = 1500;
            $state['complete'] = true;
            return $state;
        });
        $repo->shouldReceive('selectionCheckpointResult')->once()->andReturnUsing(function ($state) {
            $saved = json_decode($this->db->table('TblSmsSelectionRequests')->first()->Result, true);
            self::assertSame(1500, $saved['progress']['eligible_phones']);
            self::assertSame(1, $saved['checkpoint']['cursor']);
            return ['drops' => [['PK' => 1, 'Amount_Dropped' => 1500]], 'target' => 1000,
                'total' => 1500, 'shortfall' => false, 'selection_mode' => 'automatic'];
        });
        $queue = Mockery::mock(SmsExportQueue::class);
        $queue->shouldReceive('assertReady')->once();
        (new PlanSmsSelectionJob($this->id))->handle($repo, $queue);
        $row = $this->db->table('TblSmsSelectionRequests')->first();
        self::assertSame('ready', $row->Status);
        self::assertSame(1500, json_decode($row->Result, true)['total']);
        $snapshot = (new SmsSelectionProgress)->read(strtoupper($this->id), 'ADMIN@example.test');
        self::assertSame('ready', $snapshot['status']);
        self::assertArrayNotHasKey('checkpoint', $snapshot);
        self::assertArrayNotHasKey('checkpoint', json_decode($row->Result, true));
        self::assertNull((new SmsSelectionProgress)->read($this->id, 'someone-else@example.test'));
    }

    public function test_failure_is_recorded_instead_of_leaving_the_request_running(): void
    {
        $repo = Mockery::mock(MailDropExportRepository::class);
        $repo->shouldReceive('startSelectionCheckpoint')->once()->with(1000, null)->andReturn($this->checkpoint());
        $repo->shouldReceive('advanceSelectionCheckpoint')->once()->andThrow(new \RuntimeException('count failed'));
        $queue = Mockery::mock(SmsExportQueue::class);
        $queue->shouldReceive('assertReady')->once();
        try {
            (new PlanSmsSelectionJob($this->id))->handle($repo, $queue);
            self::fail('The count error must propagate.');
        } catch (\RuntimeException $error) { self::assertSame('count failed', $error->getMessage()); }
        $row = $this->db->table('TblSmsSelectionRequests')->first();
        self::assertSame('failed', $row->Status);
        self::assertSame('count failed', $row->Error);
        self::assertSame(0, json_decode($row->Result, true)['checkpoint']['cursor']);
        $snapshot = (new SmsSelectionProgress)->read($this->id, 'admin@example.test');
        self::assertTrue($snapshot['can_resume']);
        self::assertArrayNotHasKey('checkpoint', $snapshot);
    }

    public function test_cached_status_is_actor_scoped_and_needs_no_sql_query(): void
    {
        (new SmsSelectionProgress)->write($this->id, 'admin@example.test', ['request_id' => $this->id,
            'status' => 'running', 'progress' => ['heartbeat_at' => now()->utc()->toIso8601String(),
                'eligible_phones' => 500, 'processed_drops' => 1, 'target' => 1000, 'phase' => 'counting', 'elapsed_seconds' => 2]]);
        $this->db->enableQueryLog();
        $controller = new SmsWorkflowApiController(new MailDropExportRepository, new MarketingReportRepository);
        $request = Request::create('/', 'GET', ['request_id' => $this->id]);
        $request->attributes->set('cmd_user', ['email' => 'admin@example.test']);
        $response = $controller->selectionStatus($request);
        self::assertSame(500, $response->getData(true)['progress']['eligible_phones']);
        self::assertSame([], $this->db->getQueryLog());
        $request->attributes->set('cmd_user', ['email' => 'other@example.test']);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $controller->selectionStatus($request);
    }

    public function test_export_validation_allows_large_targets_and_more_than_500_drops_without_losing_precision(): void
    {
        $rules = (new \Cmd\Reports\Http\Requests\MailDropExportRequest)->rules();
        $data = ['request_id' => $this->id, 'target' => 30000001, 'drop_pks' => range(1, 501)];
        self::assertTrue(app('validator')->make($data, $rules)->passes());
        $data['target'] = '9007199254740992';
        self::assertFalse(app('validator')->make($data, $rules)->passes());
        $data['target'] = 0;
        self::assertFalse(app('validator')->make($data, $rules)->passes());
        $data['target'] = 1.5;
        self::assertFalse(app('validator')->make($data, $rules)->passes());
    }

    public function test_queued_and_failed_status_return_saved_progress_without_private_checkpoint(): void
    {
        $progress = ['processed_drops' => 1, 'total_drops' => 3, 'eligible_phones' => 600,
            'target' => 1000, 'phase' => 'counting'];
        $saved = ['checkpoint' => $this->checkpoint(), 'worker_token' => 'private-worker', 'progress' => $progress];
        $controller = new SmsWorkflowApiController(new MailDropExportRepository, new MarketingReportRepository);
        $request = Request::create('/', 'GET', ['request_id' => $this->id]);
        $request->attributes->set('cmd_user', ['email' => 'admin@example.test']);
        foreach (['queued', 'failed'] as $status) {
            $this->db->table('TblSmsSelectionRequests')->update(['Status' => $status,
                'Result' => json_encode($saved), 'Error' => $status === 'failed' ? 'worker stopped' : null]);
            $data = $controller->selectionStatus($request)->getData(true);
            self::assertSame($status, $data['status']);
            self::assertSame($progress, $data['progress']);
            self::assertTrue($data['can_resume']);
            self::assertArrayNotHasKey('checkpoint', $data);
            self::assertArrayNotHasKey('worker_token', $data);
        }
    }

    public function test_one_drop_counts_keep_original_names_and_restore_the_query_timeout(): void
    {
        $names = ['First Drop', 'SECOND drop'];
        $connection = Mockery::mock(\Illuminate\Database\ConnectionInterface::class);
        $pdo = Mockery::mock(\PDO::class);
        $connection->shouldReceive('getPdo')->twice()->andReturn($pdo);
        if (defined('PDO::SQLSRV_ATTR_QUERY_TIMEOUT')) {
            $attribute = constant('PDO::SQLSRV_ATTR_QUERY_TIMEOUT');
            $pdo->shouldReceive('getAttribute')->twice()->with($attribute)->andReturn(300);
            $pdo->shouldReceive('setAttribute')->twice()->with($attribute, 1800)->andReturn(true);
            $pdo->shouldReceive('setAttribute')->twice()->with($attribute, 300)->andReturn(true);
        }
        foreach ([11, 7] as $index => $count) {
            $name = $names[$index];
            $connection->shouldReceive('select')->once()->withArgs(function ($sql, $bindings) use ($name): bool {
                return $bindings === [$name, $name, $name] && str_contains($sql, 'requested(Drop_Key, Drop_Name)')
                    && str_contains($sql, 'OPTION (RECOMPILE)');
            })->andReturn([(object) ['Drop_Key' => 0, 'Eligible_Phones' => $count, 'Missing_Identity' => 0]]);
        }
        $counts = (new \Cmd\Reports\Services\SmsPhoneCounter)->countMany($connection, $names);
        self::assertSame(['First Drop' => 11, 'SECOND drop' => 7], $counts);
    }

    private function checkpoint(): array
    {
        return ['version' => 1, 'manual' => false, 'target' => 1000,
            'candidates' => [['PK' => 1, 'Drop_Name' => 'First Drop', 'SMS_Drops' => 0]],
            'cursor' => 0, 'counts' => [], 'selected' => [], 'subtotal' => 0,
            'crossing' => null, 'crossing_details' => null, 'replacement' => null,
            'replacement_details' => null, 'looked_ahead' => 0, 'elapsed_seconds' => 0, 'complete' => false];
    }
    public function test_a_stale_retry_cannot_claim_a_newer_failed_checkpoint(): void
    {
        $initial = $this->checkpoint();
        $this->db->table('TblSmsSelectionRequests')->update(['Status' => 'failed',
            'Result' => json_encode(['checkpoint' => $initial]), 'Error' => 'first failure']);
        $queue = Mockery::mock(SmsExportQueue::class);
        $queue->shouldReceive('assertReady')->once();
        Container::getInstance()->instance(SmsExportQueue::class, $queue);
        $bus = Mockery::mock(\Illuminate\Contracts\Bus\Dispatcher::class);
        $bus->shouldNotReceive('dispatch');
        Container::getInstance()->instance(\Illuminate\Contracts\Bus\Dispatcher::class, $bus);
        $controller = Mockery::mock(SmsWorkflowApiController::class,
            [new MailDropExportRepository, new MarketingReportRepository])->makePartial()->shouldAllowMockingProtectedMethods();
        $controller->shouldReceive('ensureSchemaReady')->once();
        $controller->shouldReceive('ensureSelectionSchemaReady')->once();
        $this->db->setEventDispatcher(new \Illuminate\Events\Dispatcher(Container::getInstance()));
        $advanced = false;
        $this->db->listen(function ($query) use (&$advanced, $initial): void {
            if ($advanced || ! str_starts_with(strtolower($query->sql), 'select') || ! str_contains($query->sql, 'TblSmsSelectionRequests')) return;
            // Another client completes a batch and fails again after this
            // caller reads the old row, but before it claims the retry.
            $advanced = true;
            $newer = $initial; $newer['cursor'] = 1;
            $this->db->table('TblSmsSelectionRequests')->update(['Status' => 'failed',
                'Result' => json_encode(['checkpoint' => $newer]), 'Error' => 'new failure',
                'Updated_At' => now()->addSecond()->toDateTimeString()]);
        });
        $request = Request::create('/', 'POST', ['request_id' => $this->id, 'target' => 1000]);
        $request->attributes->set('cmd_user', ['email' => 'admin@example.test']);
        self::assertSame('failed', $controller->selection($request)->getData(true)['status']);
        $row = $this->db->table('TblSmsSelectionRequests')->first();
        self::assertSame(1, json_decode($row->Result, true)['checkpoint']['cursor']);
        self::assertSame('new failure', $row->Error);
    }
    public function test_export_failure_is_recorded_after_the_queued_claim(): void
    {
        $this->db->getSchemaBuilder()->create('TblSmsExportRequests', function (Blueprint $t): void {
            $t->string('Request_ID')->primary(); $t->string('Status'); $t->integer('Target');
            $t->text('Drop_PKs')->nullable(); $t->text('Error')->nullable(); $t->dateTime('Updated_At');
        });
        $this->db->table('TblSmsExportRequests')->insert(['Request_ID' => $this->id,
            'Status' => 'queued', 'Target' => 1000, 'Updated_At' => now()]);
        $repo = Mockery::mock(MailDropExportRepository::class);
        $storage = Mockery::mock(\Cmd\Reports\Services\SmsExportArtifacts::class);
        $storage->shouldReceive('assertConfigured')->once()->andThrow(new \RuntimeException('storage failed'));
        $queue = Mockery::mock(SmsExportQueue::class);
        $queue->shouldReceive('assertReady')->once();
        try {
            (new \Cmd\Reports\Jobs\BuildSmsExportJob($this->id))->handle($repo, $storage, $queue);
            self::fail('Storage errors must propagate.');
        } catch (\RuntimeException $error) { self::assertSame('storage failed', $error->getMessage()); }
        $row = $this->db->table('TblSmsExportRequests')->first();
        self::assertSame('failed', $row->Status);
        self::assertSame('storage failed', $row->Error);
    }
}
