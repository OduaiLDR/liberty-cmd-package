<?php

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Http\Controllers\SmsOperationsController;
use Cmd\Reports\Jobs\SmsProspectMaintenanceJob;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Facade;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Controller-only safeguards: in-memory SQLite and fake dispatch; no host/provider bootstrap. */
class SmsOperationsRetrySafetyTest extends TestCase
{
    private Container $previous;
    private Container $app;
    private Connection $db;
    private array $dispatched;
    private const ID = '10000000-0000-4000-8000-000000000001';

    protected function setUp(): void
    {
        $this->previous = Container::getInstance();
        $this->app = new Container;
        Container::setInstance($this->app);
        $this->app->instance('config', new Repository([
            'database' => ['default' => 'sqlsrv'],
            'sms-exports' => ['queue' => 'sms-demo', 'prospect_enrichment_enabled' => false],
            'queue' => ['default' => 'database', 'connections' => ['database' => ['retry_after' => 15000]]],
        ]));
        $capsule = new Manager($this->app);
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:'], 'sqlsrv');
        $this->app->instance('db', $capsule->getDatabaseManager());
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);
        $this->db = $capsule->getConnection('sqlsrv');
        $this->db->getSchemaBuilder()->create('TblSMSMaintenanceJobs', function (Blueprint $t): void {
            $t->string('Request_ID')->primary(); $t->string('Status');
            $t->string('Since_Date'); $t->string('Error')->nullable(); $t->string('Updated_At');
        });
        $this->db->table('TblSMSMaintenanceJobs')->insert(['Request_ID' => self::ID,
            'Status' => 'failed', 'Since_Date' => '2026-10-12', 'Error' => 'Original failure', 'Updated_At' => '2026-10-20 01:00:00']);
        $this->dispatched = [];
        $bus = Mockery::mock(Dispatcher::class);
        $bus->shouldReceive('dispatch')->andReturnUsing(function ($job): void { $this->dispatched[] = $job; });
        $this->app->instance(Dispatcher::class, $bus);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        $this->db->disconnect();
        Facade::clearResolvedInstances();
        Container::setInstance($this->previous);
        Facade::setFacadeApplication($this->previous);
    }

    private function rejected(int $status, string $message, callable $work): void
    {
        try { $work(); self::fail('Expected safeguard rejection.'); }
        catch (HttpException $error) {
            self::assertSame($status, $error->getStatusCode());
            self::assertStringContainsString($message, $error->getMessage());
        }
    }

    public function test_disabled_enrichment_retry_is_rejected_before_resolving_transport(): void
    {
        $this->app->bind('App\\Services\\SmsProspectIntakeService', function () {
            self::fail('A disabled enrichment retry must not resolve the host transport.');
        });
        $this->rejected(503, 'Prospect enrichment is not enabled', fn () => (new SmsOperationsController)->retryEnrichment(self::ID));
        self::assertSame([], $this->dispatched);
    }

    public static function unsafeQueues(): array
    {
        return [['sync', 15000], ['redis', 15000], ['database', 90], ['database', 7000]];
    }

    #[DataProvider('unsafeQueues')]
    public function test_load_retry_checks_queue_before_changing_failed_status(string $connection, int $retryAfter): void
    {
        config(['queue.default' => $connection, 'queue.connections.'.$connection.'.retry_after' => $retryAfter]);
        $before = (array) $this->db->table('TblSMSMaintenanceJobs')->first();
        $this->rejected(503, 'asynchronous database queue', fn () => (new SmsOperationsController)->retryLoad(self::ID));
        self::assertSame($before, (array) $this->db->table('TblSMSMaintenanceJobs')->first());
        self::assertSame([], $this->dispatched);
    }

    public function test_safe_load_retry_dispatches_once_and_a_repeated_retry_cannot_redispatch(): void
    {
        $controller = new SmsOperationsController;
        $response = $controller->retryLoad(self::ID);
        self::assertSame(202, $response->getStatusCode());
        self::assertSame(self::ID, $response->getData()->Request_ID);
        self::assertSame('queued', $response->getData()->Status);
        self::assertNull($response->getData()->Error);
        self::assertCount(1, $this->dispatched);
        self::assertInstanceOf(SmsProspectMaintenanceJob::class, $this->dispatched[0]);
        self::assertSame(self::ID, $this->dispatched[0]->requestId);
        self::assertSame('sms-demo', $this->dispatched[0]->queue);
        $this->rejected(409, 'already running', fn () => $controller->retryLoad(self::ID));
        self::assertCount(1, $this->dispatched);
    }

    public function test_completed_load_is_not_made_retryable(): void
    {
        $this->db->table('TblSMSMaintenanceJobs')->update(['Status' => 'completed']);
        $before = (array) $this->db->table('TblSMSMaintenanceJobs')->first();
        $this->rejected(409, 'Only a failed load', fn () => (new SmsOperationsController)->retryLoad(self::ID));
        self::assertSame($before, (array) $this->db->table('TblSMSMaintenanceJobs')->first());
        self::assertSame([], $this->dispatched);
    }
}
