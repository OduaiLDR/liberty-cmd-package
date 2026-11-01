<?php

declare(strict_types=1);

use Cmd\Reports\Jobs\SmsRunDebtPleteImportJob;
use Cmd\Reports\Services\SmsDebtPleteImportPrivacy;
use Cmd\Reports\Services\SmsRunDebtPleteImportWorkflow;
use Illuminate\Bus\Dispatcher;
use Illuminate\Cache\CacheManager;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher as Events;
use Illuminate\Queue\CallQueuedHandler;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Support\Facades\Facade;
use Psr\Log\AbstractLogger;

// Only the workflow is a boundary fake. Dispatch, uniqueness, reservation,
// CallQueuedHandler, after-commit delivery and the job under test are Laravel's real implementations.
class ImportJobWorkflowBoundaryFake extends SmsRunDebtPleteImportWorkflow
{
    public array $calls = [];
    public array $results = [];
    public ?Throwable $failure = null;
    public function __construct() {}
    public function step(string $id): array
    {
        $this->calls[] = [$id, SmsDebtPleteImportPrivacy::active()];
        if ($this->failure) throw $this->failure;
        return array_shift($this->results) ?? ['status' => 'completed'];
    }
}

class ImportJobMemoryLogger extends AbstractLogger
{
    public array $entries = [];
    public function log($level, string|Stringable $message, array $context = []): void
    { $this->entries[] = [$level, (string) $message, $context, SmsDebtPleteImportPrivacy::active()]; }
}

beforeEach(function (): void {
    $this->importJobPrevious = Container::getInstance();
    $this->importJobFacadePrevious = Facade::getFacadeApplication();
    $app = new Container;
    Container::setInstance($app);
    $app->instance('config', new Repository([
        'database' => ['default' => 'queue-fixture'],
        'queue' => ['default' => 'database', 'connections' => ['database' => ['driver' => 'database', 'retry_after' => 7200]]],
        'cache' => ['default' => 'array', 'stores' => ['array' => ['driver' => 'array']], 'prefix' => 'closed-import-job:'],
        'sms-exports' => ['queue' => 'tu-export', 'debtplete_import_enabled' => true,
            'debtplete_import_api_key' => 'SYNTHETIC-NEVER-SERIALIZE-KEY'],
    ]));
    $capsule = new Manager($app);
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:'], 'queue-fixture');
    $capsule->getDatabaseManager()->setDefaultConnection('queue-fixture');
    $app->instance('db', $capsule->getDatabaseManager());
    $db = $capsule->getConnection('queue-fixture');
    $db->getSchemaBuilder()->create('jobs', function (Blueprint $t): void {
        $t->bigIncrements('id'); $t->string('queue')->index(); $t->text('payload');
        $t->unsignedTinyInteger('attempts'); $t->unsignedInteger('reserved_at')->nullable();
        $t->unsignedInteger('available_at'); $t->unsignedInteger('created_at');
    });
    $transactions = new DatabaseTransactionsManager;
    $db->setTransactionManager($transactions);
    $app->instance('db.transactions', $transactions);
    $events = new Events($app);
    $app->instance('events', $events);
    $app->instance(\Illuminate\Contracts\Events\Dispatcher::class, $events);
    $app->instance(\Illuminate\Log\Context\Repository::class, new \Illuminate\Log\Context\Repository($events));
    $cache = new CacheManager($app);
    $app->instance('cache', $cache);
    $app->instance('cache.store', $cache->store());
    $app->instance(\Illuminate\Contracts\Cache\Factory::class, $cache);
    $app->instance(\Illuminate\Contracts\Cache\Repository::class, $cache->store());
    $queue = new DatabaseQueue($db, 'jobs', 'tu-export', 7200, false);
    $queue->setContainer($app); $queue->setConnectionName('database');
    $bus = new Dispatcher($app, fn () => $queue);
    $app->instance(\Illuminate\Contracts\Bus\Dispatcher::class, $bus);
    $app->instance(\Illuminate\Contracts\Bus\QueueingDispatcher::class, $bus);
    $app->instance(CallQueuedHandler::class, new CallQueuedHandler($bus, $app));
    $logger = new ImportJobMemoryLogger;
    $app->instance('log', $logger);
    $workflow = new ImportJobWorkflowBoundaryFake;
    $this->importJobFactories = 0;
    $this->importJobFactoryFailure = false;
    $app->bind(SmsRunDebtPleteImportWorkflow::class, function () use ($workflow) {
        expect(SmsDebtPleteImportPrivacy::active())->toBeTrue();
        ++$this->importJobFactories;
        if ($this->importJobFactoryFailure) throw new RuntimeException('RAW credential SENTINEL');
        return $workflow;
    });
    Facade::clearResolvedInstances(); Facade::setFacadeApplication($app);
    $this->importJobApp = $app; $this->importJobDb = $db; $this->importJobQueue = $queue;
    $this->importJobWorkflow = $workflow; $this->importJobLogger = $logger;
    $this->importJobId = '11111111-2222-4333-8444-555555555555';
});

afterEach(function (): void {
    expect(SmsDebtPleteImportPrivacy::active())->toBeFalse();
    Facade::clearResolvedInstances(); Facade::setFacadeApplication($this->importJobFacadePrevious);
    Container::setInstance($this->importJobPrevious);
});

test('real database queue deduplicates pending jobs and permits exactly one successor after reservation', function (): void {
    $this->importJobWorkflow->results = [
        ['status' => 'pending', 'error_code' => null, 'remote_status' => 'queued', 'files_accepted' => 1, 'files_total' => 3],
        ['status' => 'pending', 'error_code' => null, 'remote_status' => 'queued', 'files_accepted' => 2, 'files_total' => 3],
        ['status' => 'completed', 'files_accepted' => 3, 'files_total' => 3],
    ];
    SmsRunDebtPleteImportJob::dispatch($this->importJobId)->afterCommit();
    SmsRunDebtPleteImportJob::dispatch($this->importJobId)->afterCommit();
    expect($this->importJobDb->table('jobs')->count())->toBe(1);
    $payload = $this->importJobDb->table('jobs')->value('payload');
    expect($payload)->toContain($this->importJobId)->not->toContain('SYNTHETIC-NEVER-SERIALIZE-KEY', 'Bearer', 'csv', 'plan_sha256');
    $metadata = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
    expect($metadata['maxTries'])->toBe(1)->and($metadata['timeout'])->toBe(1200)->and($metadata['failOnTimeout'])->toBeTrue();
    for ($step = 1; $step <= 3; ++$step) {
        $reserved = $this->importJobQueue->pop('tu-export');
        expect($reserved)->not->toBeNull()->and($reserved->attempts())->toBe(1)
            ->and($this->importJobDb->table('jobs')->whereNotNull('reserved_at')->count())->toBe(1);
        $reserved->fire();
        expect($this->importJobDb->table('jobs')->count())->toBe($step === 3 ? 0 : 1);
        if ($step < 3) {
            SmsRunDebtPleteImportJob::dispatch($this->importJobId)->afterCommit();
            expect($this->importJobDb->table('jobs')->count())->toBe(1);
        }
    }
    expect($this->importJobFactories)->toBe(3)->and($this->importJobWorkflow->calls)
        ->toBe(array_fill(0, 3, [$this->importJobId, true]))->and($this->importJobLogger->entries)->toBe([]);
});

test('real queue defers dispatch until commit and releases unique lock on rollback', function (): void {
    $this->importJobDb->beginTransaction();
    SmsRunDebtPleteImportJob::dispatch($this->importJobId)->afterCommit();
    expect($this->importJobDb->table('jobs')->count())->toBe(0);
    $this->importJobDb->rollBack();
    expect($this->importJobDb->table('jobs')->count())->toBe(0);
    $this->importJobDb->beginTransaction();
    SmsRunDebtPleteImportJob::dispatch($this->importJobId)->afterCommit();
    expect($this->importJobDb->table('jobs')->count())->toBe(0);
    $this->importJobDb->commit();
    expect($this->importJobDb->table('jobs')->count())->toBe(1)->and($this->importJobFactories)->toBe(0);
});

test('actual reserved delivery does not spin on uncertain terminal or fully accepted results', function (array $result): void {
    $this->importJobWorkflow->results = [$result];
    SmsRunDebtPleteImportJob::dispatch($this->importJobId);
    $this->importJobQueue->pop('tu-export')->fire();
    expect($this->importJobFactories)->toBe(1)->and($this->importJobDb->table('jobs')->count())->toBe(0);
})->with([
    [['status' => 'pending', 'error_code' => null, 'remote_status' => 'queued', 'files_accepted' => 3, 'files_total' => 3]],
    [['status' => 'pending', 'error_code' => null, 'remote_status' => 'importing', 'files_accepted' => 1, 'files_total' => 3]],
    [['status' => 'pending', 'error_code' => 'pending_recovery', 'remote_status' => 'queued', 'files_accepted' => 1, 'files_total' => 3]],
    [['status' => 'held']], [['status' => 'completed']], [['status' => 'working']],
    [['status' => 'pending', 'remote_status' => 'queued', 'files_accepted' => 0, 'files_total' => 3]],
    [['status' => 'pending', 'remote_status' => 'queued', 'files_accepted' => '1', 'files_total' => 3]],
]);

test('unreserved execution cannot resolve a workflow', function (): void {
    (new SmsRunDebtPleteImportJob($this->importJobId))->handle();
    expect($this->importJobFactories)->toBe(0)->and($this->importJobLogger->entries[0][2])
        ->toBe(['request_id' => $this->importJobId, 'code' => 'pending_recovery']);
});

test('reserved job refuses changed queue visibility or actual reservation routing before workflow resolution', function (string $change): void {
    $job = new SmsRunDebtPleteImportJob($this->importJobId);
    $route = $change === 'queue' ? 'wrong-queue' : 'tu-export';
    $this->importJobQueue->push($job, '', $route);
    if ($change === 'connection') $this->importJobQueue->setConnectionName('other');
    if ($change === 'visibility') config(['queue.connections.database.retry_after' => 1200]);
    if ($change === 'string') config(['queue.connections.database.retry_after' => '7200']);
    if ($change === 'driver') config(['queue.connections.database.driver' => 'redis']);
    $this->importJobQueue->pop($route)->fire();
    expect($this->importJobFactories)->toBe(0)->and($this->importJobLogger->entries[0][2]['code'])->toBe('pending_recovery')
        ->and($this->importJobLogger->entries[0][3])->toBeTrue()->and($this->importJobDb->table('jobs')->count())->toBe(0);
})->with(['queue', 'connection', 'visibility', 'string', 'driver']);

test('gate off after enqueue does not construct a workflow or request successors', function (): void {
    SmsRunDebtPleteImportJob::dispatch($this->importJobId);
    config(['sms-exports.debtplete_import_enabled' => false]);
    $this->importJobQueue->pop('tu-export')->fire();
    expect($this->importJobFactories)->toBe(0)->and($this->importJobDb->table('jobs')->count())->toBe(0)
        ->and($this->importJobLogger->entries)->toBe([]);
});

test('factory and workflow failures retain only static private recovery diagnostics', function (bool $factory): void {
    $this->importJobFactoryFailure = $factory;
    $this->importJobWorkflow->failure = new RuntimeException('RAW credential SENTINEL https://private.invalid');
    SmsRunDebtPleteImportJob::dispatch($this->importJobId);
    $this->importJobQueue->pop('tu-export')->fire();
    $entries = $this->importJobLogger->entries;
    expect(count($entries))->toBe(1)->and($entries[0][2])->toBe(['request_id' => $this->importJobId, 'code' => 'pending_recovery'])
        ->and($entries[0][3])->toBeTrue()->and(json_encode($entries))->not->toContain('RAW', 'credential', 'SENTINEL', 'private.invalid')
        ->and($this->importJobDb->table('jobs')->count())->toBe(0);
})->with([true, false]);

test('failed callback sanitizes the exception and remains inside privacy context', function (): void {
    (new SmsRunDebtPleteImportJob($this->importJobId))->failed(new RuntimeException('RAW credential SENTINEL'));
    expect($this->importJobFactories)->toBe(0)->and($this->importJobLogger->entries[0][2]['code'])->toBe('worker_stopped')
        ->and($this->importJobLogger->entries[0][3])->toBeTrue()->and(json_encode($this->importJobLogger->entries))->not->toContain('RAW', 'SENTINEL');
});

test('job construction rejects unsafe synchronous visibility and request identities', function (string $kind): void {
    if ($kind === 'sync') config(['queue.default' => 'sync']);
    if ($kind === 'short') config(['queue.connections.database.retry_after' => 7000]);
    if ($kind === 'queue') config(['sms-exports.queue' => '../unsafe']);
    $id = $kind === 'id' ? '../RAW-secret' : $this->importJobId;
    expect(fn () => new SmsRunDebtPleteImportJob($id))->toThrow(
        in_array($kind, ['sync', 'short'], true) ? \Symfony\Component\HttpKernel\Exception\HttpException::class : InvalidArgumentException::class
    );
    expect($this->importJobDb->table('jobs')->count())->toBe(0)->and($this->importJobFactories)->toBe(0);
})->with(['sync', 'short', 'queue', 'id']);
