<?php

use Carbon\Carbon;
use Cmd\Reports\Repositories\MailDropExportRepository;
use Cmd\Reports\Repositories\MarketingReportRepository;
use Cmd\Reports\Services\SmsDropPlanner;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->previousContainer = Container::getInstance();
    $container = new Container;
    Container::setInstance($container);
    $container->instance('config', new Repository);
    $capsule = new Manager($container);
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'sqlsrv');
    $container->instance('db', $capsule->getDatabaseManager());
    $this->db = $capsule->getConnection('sqlsrv');
    $container->instance('db.schema', $this->db->getSchemaBuilder());
    $container->instance('validator', new Factory(new Translator(new ArrayLoader, 'en'), $container));
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($container);
    $schema = $this->db->getSchemaBuilder();
    $schema->create('TblMarketing', function (Blueprint $table) {
        $table->integer('PK')->primary();
        $table->string('Drop_Name');
        $table->string('Debt_Tier')->nullable();
        $table->date('Send_Date');
        $table->string('Vendor')->default('Vendor A');
        $table->integer('Amount_Dropped')->default(0);
        $table->string('Mail_Invoice_Number')->nullable();
        $table->string('Data_Invoice_Number')->nullable();
        $table->decimal('Mail_Drop_Cost', 12, 2)->default(0);
        $table->decimal('Data_Drop_Cost', 12, 2)->default(0);
    });
    $schema->create('TblMailersUniqueEnriched', function (Blueprint $table) {
        $table->integer('PK')->primary();
        $table->string('Drop_Name');
        $table->string('External_ID');
        $table->string('Phone')->nullable();
        $table->string('Client')->default('Sample Person');
        $table->string('Address')->default('Sample address');
    });
    $schema->create('TblPhoneNumbers', function (Blueprint $table) {
        $table->string('Phone');
    });
    $schema->create('TblMailersUnique', function (Blueprint $table) {
        $table->integer('PK')->primary();
        $table->string('Drop_Name');
        $table->string('External_ID');
        $table->integer('Debt_Amount');
    });
    $migration = require __DIR__.'/../../database/sms-migrations/2026_10_06_092340_add_sms_export_tracking.php';
    $migration->up();
    Carbon::setTestNow('2026-10-06 12:00:00');
    $this->repo = new MailDropExportRepository;
});

afterEach(function () {
    Carbon::setTestNow();
    Facade::clearResolvedInstances();
    Container::setInstance($this->previousContainer);
    Facade::setFacadeApplication($this->previousContainer);
});

function smsFixture($db, int $pk, string $tier, string $date, array $phones): void
{
    $db->table('TblMarketing')->insert([
        'PK' => $pk, 'Drop_Name' => 'DROP'.$pk, 'Debt_Tier' => $tier, 'Send_Date' => $date,
        'Amount_Dropped' => count($phones),
    ]);
    foreach ($phones as $index => $phone) {
        $id = $pk * 100 + $index;
        $db->table('TblMailersUniqueEnriched')->insert([
            'PK' => $id, 'Drop_Name' => 'DROP'.$pk, 'External_ID' => 'EXT'.$id, 'Phone' => $phone,
        ]);
        $db->table('TblMailersUnique')->insert([
            'PK' => $id, 'Drop_Name' => 'DROP'.$pk, 'External_ID' => 'EXT'.$id, 'Debt_Amount' => 10000,
        ]);
    }
}

test('counts and CSV exclude synced phones including country-code and punctuation variants', function () {
    smsFixture($this->db, 1, 'T1', '2026-10-05', ['(202) 555-0101', '+1 202-555-0102', '2025550103', '', 'invalid']);
    $this->db->table('TblPhoneNumbers')->insert([['Phone' => '2025550101'], ['Phone' => '12025550102']]);
    expect((int) $this->repo->allDrops()->first()->Amount_Dropped)->toBe(1);
    $export = $this->repo->prepareExport(1, '00000000-0000-4000-8000-000000000001');
    try {
        $csv = file_get_contents($export['path']);
        expect($export['count'])->toBe(1);
        expect($csv)->toContain('2025550103')->not->toContain('2025550101')->not->toContain('2025550102');
        expect($this->db->table('TblSmsExportSources')->sum('SMS_Count'))->toBe(1);
    } finally {
        unlink($export['path']);
    }
});

test('selects whole drops newest first and overshoots the target', function () {
    smsFixture($this->db, 1, 'T1', '2026-09-28', ['2025550101', '2025550102']);
    smsFixture($this->db, 2, 'T2', '2026-10-05', ['2025550103', '2025550104', '2025550105']);
    $selected = $this->repo->selectDrops(4);
    expect($selected->pluck('PK')->all())->toBe([2, 1]);
    expect((int) $selected->sum('Amount_Dropped'))->toBe(5);
});

test('exhausts lower export counts and chooses the oldest last use before repeating', function () {
    foreach ([1, 2, 3] as $id) {
        smsFixture($this->db, $id, 'T'.$id, '2026-10-05', ['202555010'.$id]);
    }
    $this->db->table('TblMarketing')->where('PK', 1)->update(['SMS_Drops' => 2, 'SMS_Last_Export_Date' => '2026-09-01']);
    $this->db->table('TblMarketing')->where('PK', 2)->update(['SMS_Drops' => 1, 'SMS_Last_Export_Date' => '2026-10-01']);
    $this->db->table('TblMarketing')->where('PK', 3)->update(['SMS_Drops' => 1, 'SMS_Last_Export_Date' => '2026-09-20']);
    expect($this->repo->selectDrops(2)->pluck('PK')->all())->toBe([3, 2]);
    smsFixture($this->db, 4, 'T4', '2026-10-06', ['2025550104']);
    expect($this->repo->selectDrops(1)->pluck('PK')->all())->toBe([4]);
});

test('creates eight tier IDs and continues at nine in the following week', function () {
    for ($id = 1; $id <= 8; $id++) {
        smsFixture($this->db, $id, 'T'.$id, '2026-10-05', ['202555010'.$id]);
    }
    $first = $this->repo->prepareExport(8, '00000000-0000-4000-8000-000000000002');
    unlink($first['path']);
    expect($first['names'])->toBe(['SMS0001','SMS0002','SMS0003','SMS0004','SMS0005','SMS0006','SMS0007','SMS0008']);
    Carbon::setTestNow('2026-10-13 12:00:00');
    $next = $this->repo->prepareExport(1, '00000000-0000-4000-8000-000000000003');
    unlink($next['path']);
    expect($next['names'])->toBe(['SMS0009']);
    expect($this->db->table('TblSmsExports')->where('PK', 9)->value('Week_Start'))->toBe('2026-10-12');
});

test('combines multiple source drops in the same tier into one SMS ID', function () {
    smsFixture($this->db, 1, 'T1', '2026-10-05', ['2025550101']);
    smsFixture($this->db, 2, 'T1', '2026-09-28', ['2025550102']);
    $export = $this->repo->prepareExport(2, '00000000-0000-4000-8000-000000000004');
    unlink($export['path']);
    expect($export['names'])->toBe(['SMS0001']);
    expect($this->db->table('TblSmsExportSources')->count())->toBe(2);
    expect((int) $this->db->table('TblSmsExports')->value('SMS_Count'))->toBe(2);
});

test('replaying an export request cannot increment counters twice', function () {
    smsFixture($this->db, 1, 'T1', '2026-10-05', ['2025550101']);
    $request = '00000000-0000-4000-8000-000000000005';
    $export = $this->repo->prepareExport(1, $request);
    unlink($export['path']);
    expect(fn () => $this->repo->prepareExport(1, $request))->toThrow(ValidationException::class);
    expect((int) $this->db->table('TblMarketing')->value('SMS_Drops'))->toBe(1);
    expect($this->db->table('TblSmsExports')->count())->toBe(1);
});

test('a file failure rolls back earlier tier logs and all counters', function () {
    smsFixture($this->db, 1, 'T1', '2026-10-05', ['2025550101']);
    smsFixture($this->db, 2, 'T2', '2026-10-05', ['2025550102']);
    $repo = new class extends MailDropExportRepository {
        protected function writeCsv(mixed $out, array $values): void
        {
            if (($values[0] ?? '') === 'SMS0002') {
                throw new RuntimeException('Disk failure');
            }
            parent::writeCsv($out, $values);
        }
    };
    expect(fn () => $repo->prepareExport(2, '00000000-0000-4000-8000-000000000006'))->toThrow(RuntimeException::class, 'Disk failure');
    expect($this->db->table('TblSmsExports')->count())->toBe(0);
    expect((int) $this->db->table('TblMarketing')->sum('SMS_Drops'))->toBe(0);
    expect($this->db->table('TblSmsExportSources')->count())->toBe(0);
});

test('unreachable targets do not create exports', function () {
    smsFixture($this->db, 1, 'T1', '2026-10-05', ['2025550101']);
    expect(fn () => $this->repo->prepareExport(2, '00000000-0000-4000-8000-000000000007'))->toThrow(ValidationException::class);
    expect($this->db->table('TblSmsExports')->count())->toBe(0);
});

test('allocates exact cents proportionally including small totals', function () {
    $planner = new SmsDropPlanner;
    expect($planner->allocate('100.00', [1 => 1, 2 => 2]))->toBe([1 => '33.33', 2 => '66.67']);
    expect($planner->allocate('0.01', [1 => 1, 2 => 1, 3 => 1]))->toBe([1 => '0.01', 2 => '0.00', 3 => '0.00']);
    expect($planner->allocate('0', [1 => 0, 2 => 1]))->toBe([1 => '0.00', 2 => '0.00']);
    expect(fn () => $planner->allocate('1', [1 => 0]))->toThrow(ValidationException::class);
});

test('weekly SMS billing uses exported counts and does not affect another week', function () {
    smsFixture($this->db, 1, 'T1', '2026-10-05', ['2025550101']);
    smsFixture($this->db, 2, 'T2', '2026-10-05', ['2025550102', '2025550103']);
    $export = $this->repo->prepareExport(3, '00000000-0000-4000-8000-000000000008');
    unlink($export['path']);
    Carbon::setTestNow('2026-10-13 12:00:00');
    $next = $this->repo->prepareExport(1, '00000000-0000-4000-8000-000000000009');
    unlink($next['path']);
    (new MarketingReportRepository)->allocateInvoice(['kind' => 'sms', 'invoice_number' => 'SMS-INV', 'cost' => '100.00', 'week' => '2026-10-07']);
    expect((float) $this->db->table('TblSmsExports')->where('PK', 1)->value('SMS_Cost'))->toBe(33.33);
    expect((float) $this->db->table('TblSmsExports')->where('PK', 2)->value('SMS_Cost'))->toBe(66.67);
    expect($this->db->table('TblSmsExports')->where('PK', 3)->value('SMS_Invoice_Number'))->toBeNull();
});

test('mail invoice distributes across the vendor week and keeps other vendors unchanged', function () {
    smsFixture($this->db, 1, 'T1', '2026-10-05', ['2025550101']);
    smsFixture($this->db, 2, 'T2', '2026-10-05', ['2025550102', '2025550103']);
    smsFixture($this->db, 3, 'T3', '2026-10-05', ['2025550104']);
    $this->db->table('TblMarketing')->where('PK', 3)->update(['Vendor' => 'Vendor B']);
    (new MarketingReportRepository)->allocateInvoice(['kind' => 'mail', 'invoice_number' => 'MAIL-INV', 'cost' => '10.00', 'week' => '2026-10-05', 'vendor' => 'Vendor A']);
    expect((float) $this->db->table('TblMarketing')->where('PK', 1)->value('Mail_Drop_Cost'))->toBe(3.33);
    expect((float) $this->db->table('TblMarketing')->where('PK', 2)->value('Mail_Drop_Cost'))->toBe(6.67);
    expect($this->db->table('TblMarketing')->where('PK', 3)->value('Mail_Invoice_Number'))->toBeNull();
});


test('form requests reject invalid targets and invoices and enforce permissions', function () {
    $export = new \Cmd\Reports\Http\Requests\MailDropExportRequest;
    $invoice = new \Cmd\Reports\Http\Requests\MarketingInvoiceRequest;
    expect($export->authorize())->toBeFalse();
    expect($invoice->authorize())->toBeFalse();
    $validator = Container::getInstance()->make('validator');
    expect($validator->make(['target' => -1, 'request_id' => 'invalid'], $export->rules())->fails())->toBeTrue();
    expect($validator->make(['kind' => 'mail', 'invoice_number' => 'INV', 'cost' => '1.001', 'week' => '2026-10-05'], $invoice->rules())->fails())->toBeTrue();
    expect($validator->make(['kind' => 'sms', 'invoice_number' => 'INV', 'cost' => '1.01', 'week' => '2026-10-05'], $invoice->rules())->passes())->toBeTrue();
});

test('migration rolls back SMS structures without deleting source drops', function () {
    smsFixture($this->db, 1, 'T1', '2026-10-05', ['2025550101']);
    $migration = require __DIR__.'/../../database/sms-migrations/2026_10_06_092340_add_sms_export_tracking.php';
    $migration->down();
    expect($this->db->getSchemaBuilder()->hasTable('TblSmsExports'))->toBeFalse();
    expect($this->db->getSchemaBuilder()->hasColumn('TblMarketing', 'SMS_Drops'))->toBeFalse();
    expect($this->db->table('TblMarketing')->count())->toBe(1);
});

function smsRenderView(string $name, array $data): string
{
    $container = Container::getInstance();
    $files = new \Illuminate\Filesystem\Filesystem;
    $directory = sys_get_temp_dir().'/sms-blade-'.bin2hex(random_bytes(8));
    $files->makeDirectory($directory.'/layouts', 0700, true);
    $files->put($directory.'/layouts/app.blade.php', "@yield('content') @stack('scripts')");
    $session = new \Illuminate\Session\Store('sms-test', new \Illuminate\Session\ArraySessionHandler(120));
    $session->start();
    $request = \Illuminate\Http\Request::create('http://localhost/');
    $request->setLaravelSession($session);
    $container->instance('request', $request);
    $container->instance('session', $session);
    $routes = new \Illuminate\Routing\RouteCollection;
    foreach (['cmd.reports.mail_drop_export', 'cmd.reports.mail_drop_export.export', 'cmd.reports.marketing_report', 'cmd.reports.marketing_report.invoice'] as $routeName) {
        $route = new \Illuminate\Routing\Route('GET', '/'.$routeName, fn () => null);
        $routes->add($route->name($routeName));
    }
    $container->instance('url', new \Illuminate\Routing\UrlGenerator($routes, $request));
    $engines = new \Illuminate\View\Engines\EngineResolver;
    $compiler = new \Illuminate\View\Compilers\BladeCompiler($files, $directory);
    $engines->register('blade', fn () => new \Illuminate\View\Engines\CompilerEngine($compiler, $files));
    $finder = new \Illuminate\View\FileViewFinder($files, [$directory]);
    $finder->addNamespace('reports', __DIR__.'/../../resources/views');
    $factory = new \Illuminate\View\Factory($engines, $finder, new \Illuminate\Events\Dispatcher($container));
    $factory->setContainer($container);
    $factory->share('errors', new \Illuminate\Support\ViewErrorBag);
    $container->instance('view', $factory);
    try {
        return $factory->make($name, $data)->render();
    } finally {
        $files->deleteDirectory($directory);
    }
}

test('export view renders target preview and disables unreachable exports', function () {
    $data = ['drops' => collect(), 'target' => 10, 'total' => 0, 'requestId' => 'test', 'page' => 1];
    $html = smsRenderView('reports::reports.mail_drop_export', $data);
    expect($html)->toContain('SMS phone target')->toContain('not enough eligible phones');
    expect($html)->not->toContain('id="sms-export-form"');
});

test('marketing view renders SMS invoice fields and weekly totals', function () {
    $html = smsRenderView('reports::reports.marketing', [
        'reports' => collect(), 'options' => [], 'smsWeek' => '2026-10-05',
        'smsExports' => collect([(object) [
            'SMS_Drop_Name' => 'SMS0009', 'Debt_Tier' => 'T1', 'Exported_At' => '2026-10-06',
            'SMS_Count' => 12, 'SMS_Invoice_Number' => 'INV-9', 'SMS_Cost' => '1.23',
        ]]),
    ]);
    expect($html)->toContain('SMS0009')->toContain('INV-9')->toContain('$1.23')->toContain('Distribute invoice');
});


test('failed phone sync preserves the previous suppression snapshot', function () {
    $this->db->getSchemaBuilder()->table('TblPhoneNumbers', function (Blueprint $table) {
        $table->string('Source')->nullable();
        $table->integer('CID')->nullable();
    });
    $this->db->table('TblPhoneNumbers')->insert(['Phone' => '2025550101', 'Source' => 'DP_LT']);
    $connector = \Mockery::mock(\Cmd\Reports\Services\DBConnector::class);
    $pdo = $this->db->getPdo();
    $connector->shouldReceive('getSqlServerConnection')->andReturn($pdo);
    $connector->shouldReceive('querySqlServer')->andReturnUsing(function (string $sql) use ($pdo): array {
        return ['success' => true, 'row_count' => $pdo->exec($sql)];
    });
    $command = \Mockery::mock(\Cmd\Reports\Console\Commands\SyncPhoneNumbers::class)->makePartial();
    $command->shouldReceive('info')->andReturnNull();
    $path = tempnam(sys_get_temp_dir(), 'sms-sync-test-');
    file_put_contents($path, json_encode(['phone' => '2025550199', 'cid' => 1])."\ninvalid json\n");
    try {
        $method = new ReflectionMethod($command, 'replacePhonesFromFile');
        expect(fn () => $method->invoke($command, $connector, $path, 1))->toThrow(JsonException::class);
        expect($this->db->table('TblPhoneNumbers')->pluck('Phone')->all())->toBe(['2025550101']);
        expect($pdo->inTransaction())->toBeFalse();
    } finally {
        unlink($path);
        \Mockery::close();
    }
});

test('successful phone sync atomically replaces only its own source', function () {
    $this->db->getSchemaBuilder()->table('TblPhoneNumbers', function (Blueprint $table) {
        $table->string('Source')->nullable();
        $table->integer('CID')->nullable();
    });
    $this->db->table('TblPhoneNumbers')->insert([
        ['Phone' => '2025550101', 'Source' => 'DP_LT'],
        ['Phone' => '2025550102', 'Source' => 'DP_LDR'],
    ]);
    $pdo = $this->db->getPdo();
    $connector = \Mockery::mock(\Cmd\Reports\Services\DBConnector::class);
    $connector->shouldReceive('getSqlServerConnection')->andReturn($pdo);
    $connector->shouldReceive('querySqlServer')->andReturnUsing(function (string $sql) use ($pdo): array {
        return ['success' => true, 'row_count' => $pdo->exec($sql)];
    });
    $command = \Mockery::mock(\Cmd\Reports\Console\Commands\SyncPhoneNumbers::class)->makePartial();
    $command->shouldReceive('info')->andReturnNull();
    $path = tempnam(sys_get_temp_dir(), 'sms-sync-test-');
    file_put_contents($path, json_encode(['phone' => '2025550199', 'cid' => 1])."\n");
    try {
        $result = (new ReflectionMethod($command, 'replacePhonesFromFile'))->invoke($command, $connector, $path, 1);
        expect($result)->toBe([1, 1, 0]);
        expect($this->db->table('TblPhoneNumbers')->orderBy('Phone')->pluck('Phone')->all())->toBe(['2025550102', '2025550199']);
    } finally {
        unlink($path);
        \Mockery::close();
    }
});
