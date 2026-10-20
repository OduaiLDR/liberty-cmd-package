<?php

use Cmd\Reports\Services\SmsDemoGuard;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;

beforeEach(function () {
    $this->beforeApp = Container::getInstance();
    $app = new class extends Container {
        public string $testEnvironment = 'local';
        public function environment(string ...$names): bool { return in_array($this->testEnvironment, $names, true); }
    };
    $app->instance('config', new Repository([
        'sms-exports' => ['demo' => true, 'queue' => 'sms-demo'],
        'database' => ['default' => 'mysql', 'connections' => [
            'sqlsrv' => ['host' => '127.0.0.1,14330', 'database' => 'sms_demo_test_123'],
            'mysql' => ['host' => '127.0.0.1'],
        ]],
    ]));
    Container::setInstance($app);
});

afterEach(function () { Container::setInstance($this->beforeApp); });

test('simulation accepts only its isolated local connections and queue', function () {
    expect(SmsDemoGuard::enabled())->toBeTrue();
    config(['sms-exports.demo' => false, 'database.connections.sqlsrv.host' => 'remote.example.invalid']);
    expect(SmsDemoGuard::enabled())->toBeFalse();
});

test('simulation refuses production before any database connection', function () {
    app()->testEnvironment = 'production';
    expect(fn () => SmsDemoGuard::enabled())->toThrow(RuntimeException::class);
});

test('simulation refuses real business databases remote job hosts and shared queues', function () {
    foreach (['database.connections.sqlsrv.database' => 'CommissionDatabase',
        'database.connections.sqlsrv.host' => 'remote.example.invalid',
        'database.connections.mysql.host' => 'remote.example.invalid',
        'sms-exports.queue' => 'tu-export'] as $key => $bad) {
        $old = config($key);
        config([$key => $bad]);
        expect(fn () => SmsDemoGuard::enabled())->toThrow(RuntimeException::class);
        config([$key => $old]);
    }
});

test('the package validates demo isolation before loading any routes', function () {
    $source = file_get_contents(__DIR__.'/../../src/ReportsServiceProvider.php');
    expect(strpos($source, 'SmsDemoGuard::enabled();'))->toBeLessThan(strpos($source, '$this->loadRoutesFrom'));
});
