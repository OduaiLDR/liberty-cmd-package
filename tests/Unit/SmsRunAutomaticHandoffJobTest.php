<?php

declare(strict_types=1);

use Cmd\Reports\Jobs\SmsRunAutomaticHandoffJob;
use Cmd\Reports\Services\SmsDebtPleteImportPrivacy;
use Cmd\Reports\Services\SmsRunAutomaticHandoffWorkflow;
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
class AutomaticJobWorkflowBoundaryFake extends SmsRunAutomaticHandoffWorkflow
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

class AutomaticJobMemoryLogger extends AbstractLogger
{
    public array $entries = [];
    public function log($level, string|Stringable $message, array $context = []): void
    { $this->entries[] = [$level, (string) $message, $context, SmsDebtPleteImportPrivacy::active()]; }
}

beforeEach(function (): void {
    $this->automaticJobPrevious = Container::getInstance();
    $this->automaticJobFacadePrevious = Facade::getFacadeApplication();
    $app = new Container;
    Container::setInstance($app);
    $app->instance('config', new Repository([
        'database' => ['default' => 'queue-fixture'],
        'queue' => ['default' => 'database', 'connections' => ['database' => ['driver' => 'database', 'retry_after' => 7200]]],
        'cache' => ['default' => 'array', 'stores' => ['array' => ['driver' => 'array']], 'prefix' => 'closed-import-job:'],
        'sms-exports' => ['queue' => 'tu-export', 'handoff_automatic' => true, 'file_publication_enabled' => true, 'email_handoff_enabled' => true,
            'handoff_fixture_secret' => 'SYNTHETIC-NEVER-SERIALIZE-KEY'],
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
    $logger = new AutomaticJobMemoryLogger;
    $app->instance('log', $logger);
    $workflow = new AutomaticJobWorkflowBoundaryFake;
    $this->automaticJobFactories = 0;
    $this->automaticJobFactoryFailure = false;
    $app->bind(SmsRunAutomaticHandoffWorkflow::class, function () use ($workflow) {
        expect(SmsDebtPleteImportPrivacy::active())->toBeTrue();
        ++$this->automaticJobFactories;
        if ($this->automaticJobFactoryFailure) throw new RuntimeException('RAW credential SENTINEL');
        return $workflow;
    });
    Facade::clearResolvedInstances(); Facade::setFacadeApplication($app);
    $this->automaticJobApp = $app; $this->automaticJobDb = $db; $this->automaticJobQueue = $queue;
    $this->automaticJobWorkflow = $workflow; $this->automaticJobLogger = $logger;
    $this->automaticJobId = '11111111-2222-4333-8444-555555555555';
});

afterEach(function (): void {
    expect(SmsDebtPleteImportPrivacy::active())->toBeFalse();
    Facade::clearResolvedInstances(); Facade::setFacadeApplication($this->automaticJobFacadePrevious);
    Container::setInstance($this->automaticJobPrevious);
});

test('real database queue deduplicates pending jobs and permits exactly one successor after reservation', function (): void {
    $this->automaticJobWorkflow->results = [
        ['status' => 'pending', 'error_code' => null, 'phase' => 'prepare', 'again' => true],
        ['status' => 'pending', 'error_code' => null, 'phase' => 'send', 'again' => true],
        ['status' => 'completed', 'phase' => 'send', 'again' => false],
    ];
    SmsRunAutomaticHandoffJob::dispatch($this->automaticJobId)->afterCommit();
    SmsRunAutomaticHandoffJob::dispatch($this->automaticJobId)->afterCommit();
    expect($this->automaticJobDb->table('jobs')->count())->toBe(1);
    $payload = $this->automaticJobDb->table('jobs')->value('payload');
    expect($payload)->toContain($this->automaticJobId)->not->toContain('SYNTHETIC-NEVER-SERIALIZE-KEY', 'Bearer', 'csv', 'plan_sha256');
    $metadata = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
    expect($metadata['maxTries'])->toBe(1)->and($metadata['timeout'])->toBe(7000)->and($metadata['failOnTimeout'])->toBeTrue();
    for ($step = 1; $step <= 3; ++$step) {
        $reserved = $this->automaticJobQueue->pop('tu-export');
        expect($reserved)->not->toBeNull()->and($reserved->attempts())->toBe(1)
            ->and($this->automaticJobDb->table('jobs')->whereNotNull('reserved_at')->count())->toBe(1);
        $reserved->fire();
        expect($this->automaticJobDb->table('jobs')->count())->toBe($step === 3 ? 0 : 1);
        if ($step < 3) {
            SmsRunAutomaticHandoffJob::dispatch($this->automaticJobId)->afterCommit();
            expect($this->automaticJobDb->table('jobs')->count())->toBe(1);
        }
    }
    expect($this->automaticJobFactories)->toBe(3)->and($this->automaticJobWorkflow->calls)
        ->toBe(array_fill(0, 3, [$this->automaticJobId, true]))->and($this->automaticJobLogger->entries)->toBe([]);
});

test('real queue defers dispatch until commit and releases unique lock on rollback', function (): void {
    $this->automaticJobDb->beginTransaction();
    SmsRunAutomaticHandoffJob::dispatch($this->automaticJobId)->afterCommit();
    expect($this->automaticJobDb->table('jobs')->count())->toBe(0);
    $this->automaticJobDb->rollBack();
    expect($this->automaticJobDb->table('jobs')->count())->toBe(0);
    $this->automaticJobDb->beginTransaction();
    SmsRunAutomaticHandoffJob::dispatch($this->automaticJobId)->afterCommit();
    expect($this->automaticJobDb->table('jobs')->count())->toBe(0);
    $this->automaticJobDb->commit();
    expect($this->automaticJobDb->table('jobs')->count())->toBe(1)->and($this->automaticJobFactories)->toBe(0);
});

test('actual reserved automatic delivery chains only explicit safe next phase and never uncertain outcomes', function (array $result): void {
    $this->automaticJobWorkflow->results = [$result];
    SmsRunAutomaticHandoffJob::dispatch($this->automaticJobId);
    $this->automaticJobQueue->pop('tu-export')->fire();
    expect($this->automaticJobFactories)->toBe(1)->and($this->automaticJobDb->table('jobs')->count())->toBe(0);
})->with([
    [['status' => 'pending', 'error_code' => null, 'again' => false]],
    [['status' => 'pending', 'error_code' => null, 'again' => 1]],
    [['status' => 'pending', 'error_code' => 'pending_recovery', 'again' => true]],
    [['status' => 'held', 'again' => true]], [['status' => 'completed', 'again' => true]], [['status' => 'working', 'again' => true]],
    [['status' => 'pending', 'error_code' => null]],
    [['status' => 'pending', 'again' => 'true']],
]);

test('unreserved execution cannot resolve a workflow', function (): void {
    (new SmsRunAutomaticHandoffJob($this->automaticJobId))->handle();
    expect($this->automaticJobFactories)->toBe(0)->and($this->automaticJobLogger->entries[0][2])
        ->toBe(['request_id' => $this->automaticJobId, 'code' => 'pending_recovery']);
});

test('reserved job refuses changed queue visibility or actual reservation routing before workflow resolution', function (string $change): void {
    $job = new SmsRunAutomaticHandoffJob($this->automaticJobId);
    $route = $change === 'queue' ? 'wrong-queue' : 'tu-export';
    $this->automaticJobQueue->push($job, '', $route);
    if ($change === 'connection') $this->automaticJobQueue->setConnectionName('other');
    if ($change === 'visibility') config(['queue.connections.database.retry_after' => 7000]);
    if ($change === 'string') config(['queue.connections.database.retry_after' => '7200']);
    if ($change === 'driver') config(['queue.connections.database.driver' => 'redis']);
    $this->automaticJobQueue->pop($route)->fire();
    expect($this->automaticJobFactories)->toBe(0)->and($this->automaticJobLogger->entries[0][2]['code'])->toBe('pending_recovery')
        ->and($this->automaticJobLogger->entries[0][3])->toBeTrue()->and($this->automaticJobDb->table('jobs')->count())->toBe(0);
})->with(['queue', 'connection', 'visibility', 'string', 'driver']);

test('gate off after enqueue does not construct a workflow or request successors', function (): void {
    SmsRunAutomaticHandoffJob::dispatch($this->automaticJobId);
    config(['sms-exports.handoff_automatic' => false]);
    $this->automaticJobQueue->pop('tu-export')->fire();
    expect($this->automaticJobFactories)->toBe(0)->and($this->automaticJobDb->table('jobs')->count())->toBe(0)
        ->and($this->automaticJobLogger->entries)->toBe([]);
});

test('factory and workflow failures retain only static private recovery diagnostics', function (bool $factory): void {
    $this->automaticJobFactoryFailure = $factory;
    $this->automaticJobWorkflow->failure = new RuntimeException('RAW credential SENTINEL https://private.invalid');
    SmsRunAutomaticHandoffJob::dispatch($this->automaticJobId);
    $this->automaticJobQueue->pop('tu-export')->fire();
    $entries = $this->automaticJobLogger->entries;
    expect(count($entries))->toBe(1)->and($entries[0][2])->toBe(['request_id' => $this->automaticJobId, 'code' => 'pending_recovery'])
        ->and($entries[0][3])->toBeTrue()->and(json_encode($entries))->not->toContain('RAW', 'credential', 'SENTINEL', 'private.invalid')
        ->and($this->automaticJobDb->table('jobs')->count())->toBe(0);
})->with([true, false]);

test('failed callback sanitizes the exception and remains inside privacy context', function (): void {
    (new SmsRunAutomaticHandoffJob($this->automaticJobId))->failed(new RuntimeException('RAW credential SENTINEL'));
    expect($this->automaticJobFactories)->toBe(0)->and($this->automaticJobLogger->entries[0][2]['code'])->toBe('worker_stopped')
        ->and($this->automaticJobLogger->entries[0][3])->toBeTrue()->and(json_encode($this->automaticJobLogger->entries))->not->toContain('RAW', 'SENTINEL');
});

test('job construction rejects unsafe synchronous visibility and request identities', function (string $kind): void {
    if ($kind === 'sync') config(['queue.default' => 'sync']);
    if ($kind === 'short') config(['queue.connections.database.retry_after' => 7000]);
    if ($kind === 'queue') config(['sms-exports.queue' => '../unsafe']);
    $id = $kind === 'id' ? '../RAW-secret' : $this->automaticJobId;
    expect(fn () => new SmsRunAutomaticHandoffJob($id))->toThrow(
        in_array($kind, ['sync', 'short'], true) ? \Symfony\Component\HttpKernel\Exception\HttpException::class : InvalidArgumentException::class
    );
    expect($this->automaticJobDb->table('jobs')->count())->toBe(0)->and($this->automaticJobFactories)->toBe(0);
})->with(['sync', 'short', 'queue', 'id']);
