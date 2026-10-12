<?php

use Cmd\Reports\Http\Controllers\SmsWorkflowApiController;
use Cmd\Reports\Repositories\MailDropExportRepository;
use Cmd\Reports\Repositories\MarketingReportRepository;
use Cmd\Reports\Services\SmsExportArtifacts;
use Cmd\Reports\Jobs\BuildSmsExportJob;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Str;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;

beforeEach(function () {
    $this->previousContainer = Container::getInstance();
    $container = new Container;
    Container::setInstance($container);
    $container->instance('config', new Repository(['queue' => ['default' => 'database',
        'connections' => ['database' => ['retry_after' => 15000]]]]));
    $capsule = new Manager($container);
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'sqlsrv');
    $container->instance('db', $capsule->getDatabaseManager());
    $container->instance('validator', new Factory(new Translator(new ArrayLoader, 'en'), $container));
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($container);
    $this->db = $capsule->getConnection('sqlsrv');
    $schema = $this->db->getSchemaBuilder();
    $schema->create('TblMarketing', function (Blueprint $table) {
        $table->integer('PK')->primary();
        $table->string('Drop_Name');
        $table->integer('SMS_Drops')->default(0);
        $table->dateTime('SMS_Last_Export_Date')->nullable();
    });
    $schema->create('TblSmsExports', function (Blueprint $table) {
        $table->integer('PK')->primary();
    });
    $schema->create('TblSmsExportSources', function (Blueprint $table) {
        $table->integer('SMS_Export_PK');
        $table->integer('Marketing_PK');
        $table->integer('SMS_Count');
    });
    $schema->create('TblSmsExportRequests', function (Blueprint $table) {
        $table->string('Request_ID')->primary();
        $table->string('Actor_Email');
        $table->string('Status');
        $table->integer('Target');
        $table->text('Drop_PKs')->nullable();
        $table->string('Artifact_Key')->nullable();
        $table->integer('Artifact_Bytes')->nullable();
        $table->integer('SMS_Count')->nullable();
        $table->integer('Part_Count')->nullable();
        $table->string('Artifact_Format')->nullable();
        $table->string('Error')->nullable();
        $table->dateTime('Created_At');
        $table->dateTime('Updated_At');
    });
    $schema->create('TblSmsSelectionRequests', function (Blueprint $table) {
        $table->string('Request_ID')->primary();
        $table->string('Actor_Email');
        $table->string('Status');
        $table->string('Selection_Mode');
        $table->integer('Target')->nullable();
        $table->text('Drop_PKs')->nullable();
        $table->text('Result')->nullable();
        $table->string('Error')->nullable();
        $table->dateTime('Created_At');
        $table->dateTime('Updated_At');
    });
    $this->drops = Mockery::mock(MailDropExportRepository::class);
    $this->marketing = Mockery::mock(MarketingReportRepository::class);
    $this->controller = new SmsWorkflowApiController($this->drops, $this->marketing);
});

afterEach(function () {
    Mockery::close();
    Facade::clearResolvedInstances();
    Container::setInstance($this->previousContainer);
    Facade::setFacadeApplication($this->previousContainer);
});

test('API preview returns whole-drop counts and an actionable shortfall without exporting', function () {
    $this->drops->shouldReceive('selectDrops')->once()->with(10000)->andReturn(collect([
        (object) ['PK' => 1, 'Drop_Name' => 'DROP1', 'Debt_Tier' => 'T1', 'Amount_Dropped' => 6000, 'SMS_Drops' => 0],
    ]));
    $response = $this->controller->preview(Request::create('/', 'GET', ['target' => 10000]));
    $data = $response->getData(true);
    expect($response->getStatusCode())->toBe(200);
    expect($data['total'])->toBe(6000)->and($data['shortfall'])->toBe(4000);
    expect($data['drops'][0]['Drop_Name'])->toBe('DROP1');
    expect($data['has_more'])->toBeFalse()->and(Str::isUuid($data['request_id']))->toBeTrue();
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

test('API browse reports whether another page actually exists', function () {
    $this->drops->shouldReceive('allDrops')->once()->with(2)->andReturn(collect([(object) ['Amount_Dropped' => 5]]));
    $this->drops->shouldReceive('allDrops')->once()->with(3)->andReturn(collect());
    $data = $this->controller->preview(Request::create('/', 'GET', ['page' => 2]))->getData(true);
    expect($data['target'])->toBe(0)->and($data['page'])->toBe(2)->and($data['has_more'])->toBeFalse();
});

test('API previews exactly the manually chosen drops without an amount target', function () {
    $this->drops->shouldReceive('selectDropsByIds')->once()->with([7, 3])->andReturn(collect([
        (object) ['PK' => 7, 'Drop_Name' => 'DROP7', 'Amount_Dropped' => 2],
        (object) ['PK' => 3, 'Drop_Name' => 'DROP3', 'Amount_Dropped' => 3],
    ]));
    $data = $this->controller->preview(Request::create('/', 'GET', ['drop_pks' => ['7', '3']]))->getData(true);
    expect($data['selection_mode'])->toBe('manual')->and($data['target'])->toBe(5)
        ->and($data['total'])->toBe(5)->and($data['has_more'])->toBeFalse();
    expect(array_column($data['drops'], 'PK'))->toBe([7, 3]);
});

test('API input rejects invalid targets identifiers dates and invoice costs before repository calls', function (string $method, array $data) {
    expect(fn () => $this->controller->{$method}(Request::create('/', in_array($method, ['preview', 'history']) ? 'GET' : 'POST', $data)))
        ->toThrow(ValidationException::class);
})->with([
    ['preview', ['target' => 0]],
    ['preview', ['target' => 10000001]],
    ['preview', ['page' => -1]],
    ['preview', ['drop_pks' => []]],
    ['preview', ['drop_pks' => [1, 1]]],
    ['preview', ['drop_pks' => '1']],
    ['export', ['target' => 1, 'request_id' => 'invalid']],
    ['export', ['target' => 1, 'request_id' => '00000000-0000-4000-8000-000000000001', 'drop_pks' => [1, 1]]],
    ['export', ['request_id' => '00000000-0000-4000-8000-000000000001']],
    ['history', ['week' => '2026-02-30']],
    ['invoice', ['kind' => 'sms', 'week' => '2026-10-05', 'invoice_number' => 'INV', 'cost' => '1.001']],
    ['invoice', ['kind' => 'mail', 'week' => '2026-10-05', 'invoice_number' => 'INV', 'cost' => '1.00']],
]);

test('all API operations explain unavailable SMS schema before calling repositories', function (string $method, array $data) {
    $this->db->getSchemaBuilder()->drop('TblSmsExports');
    try {
        $this->controller->{$method}(Request::create('/', in_array($method, ['preview', 'history']) ? 'GET' : 'POST', $data));
        $this->fail('Expected missing-schema rejection.');
    } catch (HttpException $error) {
        expect($error->getStatusCode())->toBe(503);
        expect($error->getMessage())->toContain('reports-sms-migrations');
    }
})->with([
    ['preview', ['target' => 1]],
    ['export', ['target' => 1, 'request_id' => '00000000-0000-4000-8000-000000000001']],
    ['history', ['week' => '2026-10-05']],
    ['invoice', ['kind' => 'sms', 'week' => '2026-10-05', 'invoice_number' => 'INV', 'cost' => '1.00']],
]);

test('API history normalizes Monday and includes only matching export sources', function () {
    $this->db->table('TblMarketing')->insert(['PK' => 7, 'Drop_Name' => 'DROP7']);
    $this->db->table('TblSmsExportSources')->insert([
        ['SMS_Export_PK' => 9, 'Marketing_PK' => 7, 'SMS_Count' => 120],
        ['SMS_Export_PK' => 99, 'Marketing_PK' => 7, 'SMS_Count' => 800],
    ]);
    $this->marketing->shouldReceive('smsExports')->once()->with('2026-10-05')->andReturn(collect([
        (object) ['PK' => 9, 'SMS_Drop_Name' => 'SMS0009', 'SMS_Count' => 120, 'SMS_Invoice_Number' => 'INV', 'SMS_Cost' => '1.00'],
    ]));
    $data = $this->controller->history(Request::create('/', 'GET', ['week' => '2026-10-11']))->getData(true);
    expect($data['week'])->toBe('2026-10-05');
    expect($data['exports'][0]['sources'])->toBe([['Marketing_PK' => 7, 'Drop_Name' => 'DROP7', 'SMS_Count' => 120]]);
    expect($data['exports'][0]['SMS_Cost'])->toBe('1.00');
});

test('API invoice preserves exact cost string and delegates only validated fields', function () {
    $data = ['kind' => 'sms', 'week' => '2026-10-05', 'invoice_number' => 'INV', 'cost' => '10.01'];
    $this->marketing->shouldReceive('allocateInvoice')->once()->with($data);
    $response = $this->controller->invoice(Request::create('/', 'POST', $data + ['unexpected' => 'ignored']));
    expect($response->getData(true)['ok'])->toBeTrue();
    expect($response->getData(true)['message'])->toContain('selected drop');
});

test('API export queues once and scopes the request to its actor', function () {
    $id = '00000000-0000-4000-8000-000000000001';
    $artifacts = Mockery::mock(SmsExportArtifacts::class);
    $artifacts->shouldReceive('assertConfigured')->twice();
    $dispatcher = Mockery::mock(BusDispatcher::class);
    $dispatcher->shouldReceive('dispatch')->once()->with(Mockery::type(BuildSmsExportJob::class));
    Container::getInstance()->instance(BusDispatcher::class, $dispatcher);
    $request = Request::create('/', 'POST', ['target' => 1, 'request_id' => $id, 'drop_pks' => ['7']]);
    $request->attributes->set('cmd_user', ['email' => 'one@example.com']);
    $response = $this->controller->export($request, $artifacts);
    expect($response->getStatusCode())->toBe(202);
    expect($response->getData(true)['status'])->toBe('queued');
    expect($this->db->table('TblSmsExportRequests')->where('Request_ID', $id)->value('Actor_Email'))->toBe('one@example.com');
    $this->controller->export($request, $artifacts);
});

test('API status never gives another actor a download URL', function () {
    $id = '00000000-0000-4000-8000-000000000009';
    $this->db->table('TblSmsExportRequests')->insert([
        'Request_ID' => $id, 'Actor_Email' => 'one@example.com', 'Status' => 'ready', 'Target' => 1,
        'Artifact_Key' => 'sms-exports/file.csv', 'Artifact_Bytes' => 100, 'SMS_Count' => 1,
        'Part_Count' => 1, 'Artifact_Format' => 'csv', 'Created_At' => now(), 'Updated_At' => now(),
    ]);
    $artifacts = Mockery::mock(SmsExportArtifacts::class);
    $artifacts->shouldReceive('temporaryUrl')->once()->with('sms-exports/file.csv', 100)->andReturn('https://example.com/signed');
    $other = Request::create('/', 'GET', ['request_id' => $id]);
    $other->attributes->set('cmd_user', ['email' => 'other@example.com']);
    expect(fn () => $this->controller->exportStatus($other, $artifacts))->toThrow(HttpException::class);
    $owner = Request::create('/', 'GET', ['request_id' => $id]);
    $owner->attributes->set('cmd_user', ['email' => 'one@example.com']);
    expect($this->controller->exportStatus($owner, $artifacts)->getData(true)['download_url'])->toBe('https://example.com/signed');
});
class SmsApiTestSessionGuard {}
class SmsApiTestPolicyGuard {}

test('API routes are host gated and bind correct methods and CMD report permissions', function () {
    $container = Container::getInstance();
    $router = new Router(new Dispatcher($container), $container);
    $container->instance('router', $router);
    Facade::clearResolvedInstance('router');
    $session = 'App\\Http\\Middleware\\VerifyCmdSession';
    $policy = 'App\\Http\\Middleware\\EnforceReportPolicy';
    if (! class_exists($session) || ! class_exists($policy)) {
        require __DIR__.'/../../routes/sms-api.php';
        expect($router->getRoutes()->count())->toBe(0);
    }
    if (! class_exists($session)) class_alias(SmsApiTestSessionGuard::class, $session);
    if (! class_exists($policy)) class_alias(SmsApiTestPolicyGuard::class, $policy);
    require __DIR__.'/../../routes/sms-api.php';
    expect($router->getRoutes()->count())->toBe(7);
    foreach ([
        ['GET', 'mail-drop-export-report/preview', 'cmd.mail_drop_export.preview'],
        ['POST', 'mail-drop-export-report/export', 'cmd.mail_drop_export.export'],
        ['GET', 'mail-drop-export-report/export-status', 'cmd.mail_drop_export.export_status'],
        ['POST', 'mail-drop-export-report/selection', 'cmd.mail_drop_export.selection'],
        ['GET', 'mail-drop-export-report/selection-status', 'cmd.mail_drop_export.selection_status'],
        ['GET', 'marketing-report/sms-history', 'cmd.marketing.sms_history'],
        ['POST', 'marketing-report/invoice', 'cmd.marketing.invoice'],
    ] as [$method, $path, $name]) {
        $route = $router->getRoutes()->match(Request::create('/api/cmd/'.$path, $method));
        expect($route->getName())->toBe($name);
        expect($route->gatherMiddleware())->toBe(['api', $session, $policy]);
        expect(fn () => $router->getRoutes()->match(Request::create('/api/cmd/'.$path, $method === 'GET' ? 'POST' : 'GET')))
            ->toThrow(MethodNotAllowedHttpException::class);
    }
});
