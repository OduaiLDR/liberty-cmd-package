<?php

declare(strict_types=1);

use Cmd\Reports\Services\SmsTestEnvironmentProbe;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Facade;

beforeEach(function (): void {
    $this->previousProbeContainer = Container::getInstance();
    $container = new class extends Container {
        public function environment(...$values): string|bool
        {
            return $values === [] ? $this['env'] : in_array($this['env'], $values, true);
        }
    };
    $container->instance('env', 'testing'); Container::setInstance($container);
    $this->probeRoot = str_replace('\\', '/', sys_get_temp_dir()).'/sms-readiness-probe-'.bin2hex(random_bytes(8));
    mkdir($this->probeRoot, 0700);
    $container->instance('config', new Repository([
        'app' => ['env' => 'testing', 'url' => 'https://cmd.fixture.invalid'],
        'database' => ['default' => 'application'],
        'queue' => ['default' => 'database', 'connections' => ['database' => ['driver' => 'database', 'connection' => 'queue-db', 'table' => 'custom_jobs', 'retry_after' => 15000]],
            'failed' => ['driver' => 'database-uuids', 'database' => 'queue-db', 'table' => 'custom_failures']],
        'services' => ['cmd' => ['auth_enabled' => true, 'auth_validate_url' => 'https://identity.fixture.invalid']],
        'sms-exports' => ['queue' => 'tu-export', 'local_root' => $this->probeRoot],
    ]));
    $capsule = new Manager($container);
    foreach (['application', 'queue-db'] as $name) $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:'], $name);
    $capsule->getDatabaseManager()->setDefaultConnection('application');
    $container->instance('db', $capsule->getDatabaseManager());
    Facade::clearResolvedInstances(); Facade::setFacadeApplication($container);
    $this->probeDb = $capsule->getConnection('queue-db');
    $this->probeDb->getSchemaBuilder()->create('custom_jobs', function (Blueprint $table): void {
        $table->increments('id'); $table->string('queue'); $table->text('payload'); $table->integer('attempts');
        $table->integer('reserved_at')->nullable(); $table->integer('available_at'); $table->integer('created_at');
    });
    $this->probeDb->getSchemaBuilder()->create('custom_failures', function (Blueprint $table): void {
        $table->increments('id'); foreach (['uuid', 'connection', 'queue', 'payload', 'exception', 'failed_at'] as $name) $table->text($name);
    });
});

afterEach(function (): void {
    if (is_dir($this->probeRoot) && ! is_link($this->probeRoot)) rmdir($this->probeRoot);
    Facade::clearResolvedInstances(); Container::setInstance($this->previousProbeContainer); Facade::setFacadeApplication($this->previousProbeContainer);
});

test('probe reads configured queue schemas and private storage without issuing mutations', function (): void {
    $queries = [];
    $this->probeDb->beforeExecuting(static function (string $sql) use (&$queries): void { $queries[] = $sql; });
    $report = (new SmsTestEnvironmentProbe)->report();
    expect($report['test_configuration_ready'])->toBeTrue()->and($report['production_ready'])->toBeFalse()
        ->and($report['execution_context']['php_sapi'])->toBe(PHP_SAPI)->and($queries)->not->toBeEmpty();
    foreach ($queries as $query) expect(strtolower(ltrim($query)))->toMatch('/^(select|pragma)\b/');
    expect(json_encode($report))->not->toContain($this->probeRoot, 'custom_jobs', 'identity.fixture.invalid');
});

test('probe reports missing storage and wrong queue connection without creating a directory', function (): void {
    config(['sms-exports.local_root' => $this->probeRoot.'/missing', 'queue.connections.database.connection' => 'application']);
    $report = (new SmsTestEnvironmentProbe)->report();
    expect(array_column($report['blockers'], 'code'))->toContain('jobs_schema_ready', 'private_root_exists', 'private_root_writable')
        ->and(is_dir($this->probeRoot.'/missing'))->toBeFalse();
});

test('probe hides connection errors and refuses relative traversal paths and wrong failure logging drivers', function (): void {
    config(['queue.connections.database.connection' => 'do-not-print-connection', 'queue.failed.driver' => 'null', 'sms-exports.local_root' => '../unsafe']);
    $report = (new SmsTestEnvironmentProbe)->report();
    expect(array_column($report['blockers'], 'code'))->toContain('jobs_schema_ready', 'failed_jobs_schema_ready', 'private_root_symlink_free')
        ->and(json_encode($report))->not->toContain('do-not-print-connection', '../unsafe');
});

test('actual local middleware environment overrides cached configuration when reporting authentication', function (): void {
    config(['app.env' => 'production']);
    Container::getInstance()->instance('env', 'local');
    $report = (new SmsTestEnvironmentProbe)->report();
    expect($report['test_configuration_ready'])->toBeFalse()
        ->and($report['execution_context']['app_environment'])->toBe('local')
        ->and($report['checks']['authenticated_sessions_enforced'])->toBeFalse()
        ->and(array_column($report['blockers'], 'code'))->toContain('authenticated_sessions_enforced');
});
