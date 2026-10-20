<?php

namespace Cmd\Reports\Tests\Unit;

use Carbon\Carbon;
use Cmd\Reports\Jobs\SmsProspectMaintenanceJob;
use Cmd\Reports\Jobs\SmsRunStepJob;
use Cmd\Reports\Repositories\SmsProspectExportRepository;
use Cmd\Reports\Repositories\SmsProspectLoadRepository;
use Cmd\Reports\Services\SmsRunService;
use Cmd\Reports\Services\SmsWorkflowBusyException;
use Cmd\Reports\Services\SmsWorkflowLock;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Queue\Jobs\FakeJob;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\Worker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SmsWorkflowQueueContentionTest extends TestCase
{
    private Container $previous;
    private Container $app;
    private Manager $capsule;
    private string $id = '10000000-0000-4000-8000-000000000001';

    protected function setUp(): void
    {
        $this->previous = Container::getInstance();
        $this->app = new Container;
        Container::setInstance($this->app);
        $this->app->instance('config', new Repository(['database' => ['default' => 'sqlsrv'], 'sms-exports' => ['queue' => 'sms-demo']]));
        $this->capsule = new Manager($this->app);
        $this->capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:'], 'sqlsrv');
        $this->capsule->getDatabaseManager()->setDefaultConnection('sqlsrv');
        $this->app->instance('db', $this->capsule->getDatabaseManager());
        $logger = Mockery::mock(\Psr\Log\LoggerInterface::class);
        $logger->shouldReceive('error')->byDefault();
        $this->app->instance('log', $logger);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);
        Carbon::setTestNow('2026-10-19 20:00:00');
        $db = $this->capsule->getConnection('sqlsrv');
        $db->getSchemaBuilder()->create('TblSMSMaintenanceJobs', function (Blueprint $t): void {
            $t->string('Request_ID')->primary(); $t->string('Status'); $t->string('Since_Date');
            $t->text('Drop_List')->nullable(); $t->integer('Drops_Total')->default(0); $t->integer('Drops_Done')->default(0);
            $t->integer('Added')->default(0); $t->integer('Filled')->default(0); $t->string('Current_Drop')->nullable();
            $t->text('Error')->nullable(); $t->dateTime('Updated_At')->nullable();
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();
        Facade::clearResolvedInstances();
        Container::setInstance($this->previous);
        Facade::setFacadeApplication($this->previous);
    }

    private function mockLock(int $result): void
    {
        $connection = Mockery::mock();
        $connection->shouldReceive('getDriverName')->once()->andReturn('sqlsrv');
        $connection->shouldReceive('selectOne')->once()->withArgs(fn ($sql, $bindings) => str_contains($sql, 'sp_getapplock') && $bindings === ['sms:test'])
            ->andReturn((object) ['Result' => $result]);
        if ($result >= 0) $connection->shouldReceive('statement')->once()->withArgs(fn ($sql, $bindings) => str_contains($sql, 'sp_releaseapplock') && $bindings === ['sms:test'])->andReturn(true);
        $manager = Mockery::mock();
        $manager->shouldReceive('connection')->with('sqlsrv')->andReturn($connection);
        DB::swap($manager);
    }

    public function test_lock_contention_has_a_specific_http_409_type_without_running_or_releasing_the_work(): void
    {
        $this->mockLock(-1);
        try {
            SmsWorkflowLock::run('test', fn () => self::fail('Busy work must not run.'));
            self::fail('Expected lock contention.');
        } catch (SmsWorkflowBusyException $error) {
            self::assertSame(409, $error->getStatusCode());
        }
    }

    public function test_business_409_is_not_reclassified_and_the_acquired_lock_is_released(): void
    {
        $this->mockLock(0);
        $conflict = new HttpException(409, 'An invoice already exists.');
        try { SmsWorkflowLock::run('test', fn () => throw $conflict); self::fail('Expected business conflict.'); }
        catch (HttpException $error) { self::assertSame($conflict, $error); self::assertNotInstanceOf(SmsWorkflowBusyException::class, $error); }
    }

    public static function runSteps(): array
    {
        return [['prepare', 'prepare'], ['file', 'buildFile'], ['send', 'markSent'], ['reverse', 'reverseSent']];
    }

    #[DataProvider('runSteps')]
    public function test_run_lock_contention_is_released_without_failing_or_deleting(string $step, string $method): void
    {
        $runs = Mockery::mock(SmsRunService::class);
        $runs->shouldReceive($method)->once()->with($this->id)->andThrow(new SmsWorkflowBusyException);
        $runs->shouldNotReceive('fail');
        $job = (new SmsRunStepJob($this->id, $step))->withFakeQueueInteractions();
        $job->handle($runs);
        $job->assertReleased(5)->assertNotFailed()->assertNotDeleted();
    }

    #[DataProvider('runSteps')]
    public function test_run_business_409_propagates_and_records_failure(string $step, string $method): void
    {
        $conflict = new HttpException(409, 'Business conflict, not lock contention.');
        $runs = Mockery::mock(SmsRunService::class);
        $runs->shouldReceive($method)->once()->with($this->id)->andThrow($conflict);
        $runs->shouldReceive('fail')->once()->with($this->id, $step, $conflict->getMessage());
        $job = (new SmsRunStepJob($this->id, $step))->withFakeQueueInteractions();
        try { $job->handle($runs); self::fail('Business conflicts must reach the worker.'); }
        catch (HttpException $error) { self::assertSame($conflict, $error); }
        $job->assertNotReleased();
    }

    public function test_contending_maintenance_delivery_does_not_change_the_active_ledger(): void
    {
        $db = $this->capsule->getConnection('sqlsrv');
        $db->table('TblSMSMaintenanceJobs')->insert(['Request_ID' => $this->id, 'Status' => 'running', 'Since_Date' => '2026-10-01', 'Drops_Done' => 2]);
        $connection = Mockery::mock();
        $connection->shouldReceive('getDriverName')->once()->andReturn('sqlsrv');
        $connection->shouldReceive('selectOne')->once()->andReturn((object) ['Result' => -1]);
        $manager = Mockery::mock();
        $manager->shouldReceive('connection')->with('sqlsrv')->andReturn($connection);
        DB::swap($manager);
        $load = Mockery::mock(SmsProspectLoadRepository::class);
        $export = Mockery::mock(SmsProspectExportRepository::class);
        $job = (new SmsProspectMaintenanceJob($this->id))->withFakeQueueInteractions();
        $job->handle($load, $export);
        $job->assertReleased(5)->assertNotFailed()->assertNotDeleted();
        self::assertSame('running', $db->table('TblSMSMaintenanceJobs')->value('Status'));
        self::assertSame(2, $db->table('TblSMSMaintenanceJobs')->value('Drops_Done'));
    }

    public function test_nested_maintenance_contention_resumes_the_committed_checkpoint(): void
    {
        $db = $this->capsule->getConnection('sqlsrv');
        $db->table('TblSMSMaintenanceJobs')->insert(['Request_ID' => $this->id, 'Status' => 'queued', 'Since_Date' => '2026-10-01']);
        $load = Mockery::mock(SmsProspectLoadRepository::class);
        $load->shouldReceive('drops')->once()->andReturn([(object) ['Drop_Name' => 'FIRST', 'Send_Date' => '2026-10-18'], (object) ['Drop_Name' => 'SECOND', 'Send_Date' => '2026-10-19']]);
        $load->shouldReceive('loadDrop')->once()->with('FIRST', '2026-10-18')->andReturn(['added' => 2, 'filled' => 1]);
        $calls = 0;
        $load->shouldReceive('loadDrop')->twice()->with('SECOND', '2026-10-19')->andReturnUsing(function () use (&$calls): array {
            if (++$calls === 1) throw new SmsWorkflowBusyException;
            return ['added' => 3, 'filled' => 0];
        });
        $export = Mockery::mock(SmsProspectExportRepository::class);
        $export->shouldReceive('markContacted')->once()->andReturn(0);
        $job = (new SmsProspectMaintenanceJob($this->id))->withFakeQueueInteractions();
        $job->handle($load, $export);
        $job->assertReleased(5);
        $row = $db->table('TblSMSMaintenanceJobs')->first();
        self::assertSame(['queued', 1, 2, 1, null], [$row->Status, $row->Drops_Done, $row->Added, $row->Filled, $row->Error]);
        $job->withFakeQueueInteractions()->handle($load, $export);
        $job->assertNotReleased();
        $row = $db->table('TblSMSMaintenanceJobs')->first();
        self::assertSame(['completed', 2, 5, 1], [$row->Status, $row->Drops_Done, $row->Added, $row->Filled]);
    }

    public function test_maintenance_business_409_is_failed_and_rethrown(): void
    {
        $db = $this->capsule->getConnection('sqlsrv');
        $db->table('TblSMSMaintenanceJobs')->insert(['Request_ID' => $this->id, 'Status' => 'queued', 'Since_Date' => '2026-10-01']);
        $load = Mockery::mock(SmsProspectLoadRepository::class);
        $load->shouldReceive('drops')->once()->andReturn([(object) ['Drop_Name' => 'FIRST', 'Send_Date' => '2026-10-18']]);
        $conflict = new HttpException(409, 'A changed admission chunk cannot be replayed.');
        $load->shouldReceive('loadDrop')->once()->andThrow($conflict);
        $job = (new SmsProspectMaintenanceJob($this->id))->withFakeQueueInteractions();
        try { $job->handle($load, Mockery::mock(SmsProspectExportRepository::class)); self::fail('Expected business conflict.'); }
        catch (HttpException $error) { self::assertSame($conflict, $error); }
        $job->assertNotReleased();
        self::assertSame('failed', $db->table('TblSMSMaintenanceJobs')->value('Status'));
        self::assertSame($conflict->getMessage(), $db->table('TblSMSMaintenanceJobs')->value('Error'));
    }

    public static function jobKinds(): array { return [['run'], ['maintenance']]; }

    #[DataProvider('jobKinds')]
    public function test_retry_deadline_is_fixed_in_the_payload_and_allows_two_hours_of_contention(string $kind): void
    {
        $job = $kind === 'run' ? new SmsRunStepJob($this->id, 'prepare') : new SmsProspectMaintenanceJob($this->id);
        $deadline = now()->addHours(3)->getTimestamp();
        self::assertSame(0, $job->tries);
        self::assertSame(1, $job->maxExceptions);
        self::assertSame('sms-demo', $job->queue);
        $queue = new DatabaseQueue($this->capsule->getConnection('sqlsrv'), 'unused_jobs');
        $queue->setContainer($this->app);
        $body = (new ReflectionMethod(DatabaseQueue::class, 'createPayload'))->invoke($queue, $job, 'sms-demo');
        $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($deadline, $payload['retryUntil']);
        self::assertSame(1, $payload['maxExceptions']);
        Carbon::setTestNow(now()->addHours(2));
        $restored = unserialize($payload['data']['command']);
        self::assertSame($deadline, $restored->retryUntil()->getTimestamp());
        $delivery = new class($body) extends FakeJob {
            public function __construct(private string $body) {}
            public function getRawBody() { return $this->body; }
        };
        $delivery->attempts = 1500;
        $worker = (new ReflectionClass(Worker::class))->newInstanceWithoutConstructor();
        $check = new ReflectionMethod(Worker::class, 'markJobAsFailedIfAlreadyExceedsMaxAttempts');
        $check->invoke($worker, 'database', $delivery, 1);
        self::assertFalse($delivery->hasFailed(), 'The worker --tries=1 must not override the fixed retry deadline.');
        Carbon::setTestNow(Carbon::createFromTimestampUTC($deadline + 1));
        try { $check->invoke($worker, 'database', $delivery, 1); self::fail('The three-hour deadline must stop retries.'); }
        catch (MaxAttemptsExceededException $error) { self::assertTrue($delivery->hasFailed()); }
    }
}
