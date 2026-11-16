<?php

use Carbon\Carbon;
use Cmd\Reports\Repositories\SmsProspectExportRepository;
use Cmd\Reports\Services\SmsFeedbackService;
use Cmd\Reports\Services\SmsAvailabilityService;
use Cmd\Reports\Services\SmsRunService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Str;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->previousContainer = Container::getInstance();
    $container = new Container;
    Container::setInstance($container);
    $container->instance('config', new Repository(['database' => ['default' => 'sqlsrv']]));
    $capsule = new Manager($container);
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:'], 'sqlsrv');
    $capsule->getDatabaseManager()->setDefaultConnection('sqlsrv');
    $container->instance('db', $capsule->getDatabaseManager());
    $container->instance('validator', new Factory(new Translator(new ArrayLoader, 'en'), $container));
    Facade::clearResolvedInstances(); Facade::setFacadeApplication($container);
    $this->db = $capsule->getConnection('sqlsrv');
    $this->db->setTransactionManager(new DatabaseTransactionsManager);
    $this->summaryTransactionLevels = [];
    $this->summary = Mockery::mock(SmsAvailabilityService::class);
    $this->summary->shouldReceive('invalidate')->andReturnUsing(function (): array {
        $this->summaryTransactionLevels[] = $this->db->transactionLevel();
        return ['state' => 'pending'];
    });
    $container->instance(SmsAvailabilityService::class, $this->summary);
    $this->db->getSchemaBuilder()->create('TblPhoneNumbers', fn (Blueprint $t) => $t->string('Phone'));
    (require __DIR__.'/../../database/sms-migrations/2026_10_12_000001_create_sms_prospects_tables.php')->up();
    (require __DIR__.'/../../database/sms-migrations/2026_10_19_000001_complete_sms_workflow.php')->up();
    $this->repo = new SmsProspectExportRepository;
    $this->service = new class($this->repo) extends SmsRunService {
        public array $queued = [];
        protected function dispatch(string $id, string $job): void { $this->queued[] = [$id, $job]; }
    };
    $this->id = (string) Str::uuid();
    $this->makeRun = function (string $status, array $extra = []): void {
        $this->db->table('TblSMSRuns')->insert($extra + ['Request_ID' => $this->id, 'Status' => $status, 'Target' => 1, 'Selected' => 1,
            'Debt_Missing' => 0, 'Created_By' => 'test@example.invalid', 'Created_At' => '2026-10-19 20:00:00', 'Updated_At' => '2026-10-19 20:00:00']);
    };
    Carbon::setTestNow('2026-10-19 20:00:00');
});

afterEach(function () {
    foreach ($this->summaryTransactionLevels as $level) expect($level)->toBe(0);
    Mockery::close();
    Carbon::setTestNow(); Facade::clearResolvedInstances();
    Container::setInstance($this->previousContainer); Facade::setFacadeApplication($this->previousContainer);
});

test('start is idempotent but a different open run or reused target is rejected', function () {
    $first = $this->service->start($this->id, 1, 'test@example.invalid');
    expect($first->status)->toBe('queued');
    $this->service->start($this->id, 1, 'test@example.invalid');
    expect($this->service->queued)->toHaveCount(1);
    expect(fn () => $this->service->start($this->id, 2, 'test@example.invalid'))->toThrow(HttpException::class);
    expect(fn () => $this->service->start((string) Str::uuid(), 1, 'test@example.invalid'))->toThrow(HttpException::class);
});

test('missing debt blocks a file instead of exporting blanks', function () {
    ($this->makeRun)('review', ['Debt_Missing' => 1]);
    expect(fn () => $this->service->requestFile($this->id))->toThrow(HttpException::class);
    expect($this->service->show($this->id)->status)->toBe('review');
    expect($this->service->queued)->toBe([]);
});

test('saving a review copy retains file metadata and releases people without a send', function () {
    ($this->makeRun)('file_ready', ['File_Path' => '/review-copy.csv', 'File_Bytes' => 100]);
    $pk = $this->db->table('TblProspects')->insertGetId(['Client' => 'Test Person', 'Phone' => '2025550101']);
    $drop = $this->db->table('TblSMSDrops')->insertGetId(['Request_ID' => $this->id, 'Drop_Name' => 'SMS0001', 'Debt_Tier' => 'T1', 'Drop_Date' => '2026-10-19', 'Created_At' => now()]);
    $this->db->table('TblProspectsExport')->insert(['Request_ID' => $this->id, 'Prospect_PK' => $pk, 'Phone' => '2025550101', 'SMS_Drop_PK' => $drop]);
    $view = $this->service->reviewOnly($this->id, 'test@example.invalid');
    expect($view->status)->toBe('review_only')->and($view->sent_at)->toBeNull()->and($view->file['name'])->toBe('review-copy.csv');
    expect($this->db->table('TblProspectsExport')->count())->toBe(0);
    expect($this->db->table('TblSMSHistory')->count())->toBe(0);
    expect((int) $this->db->table('TblProspects')->value('SMS_Attempts'))->toBe(0);
    expect($this->service->overview()['open'])->toBeNull();
    expect($this->service->overview()['recent'][0]->drops)->toHaveCount(1);
    $this->summary->shouldHaveReceived('invalidate')->once();
});

test('legacy sent runs have no guessed undo and remain unchanged', function () {
    ($this->makeRun)('sent', ['Sent_At' => now()]);
    expect(fn () => $this->service->requestNotSent($this->id, 'test@example.invalid', 'The provider never sent this file.'))->toThrow(HttpException::class);
    expect($this->service->show($this->id)->status)->toBe('sent');
    expect($this->service->show($this->id)->can_correct_send)->toBeFalse();
});

test('invoiced sends cannot be silently reversed', function () {
    ($this->makeRun)('sent');
    $this->db->table('TblSMSDrops')->insert(['Request_ID' => $this->id, 'Drop_Name' => 'SMS0001', 'Drop_Date' => '2026-10-19', 'Created_At' => now(), 'Invoice_Number' => 'PAID-1']);
    expect(fn () => $this->service->requestNotSent($this->id, 'test@example.invalid', 'The provider never sent this file.'))->toThrow(HttpException::class);
    expect($this->service->show($this->id)->status)->toBe('sent');
});

test('a stale failure callback cannot fail a different step', function () {
    ($this->makeRun)('building_file');
    $this->service->fail($this->id, 'prepare', 'old worker');
    expect($this->service->show($this->id)->status)->toBe('building_file');
    $this->service->fail($this->id, 'file', 'file worker');
    expect($this->service->show($this->id)->status)->toBe('failed')->and($this->service->show($this->id)->failed_step)->toBe('file');
});

test('feedback rejects changed chunk replays and retains both suppression flags', function () {
    $feedback = new SmsFeedbackService;
    $feedback->import($this->id, 0, [['phone' => '2025550101', 'status' => 'opt_out']], 'test@example.invalid');
    expect(fn () => $feedback->import($this->id, 0, [['phone' => '2025550101', 'status' => 'invalid_phone']], 'test@example.invalid'))->toThrow(HttpException::class);
    $feedback->import($this->id, 1, [['phone' => '2025550101', 'status' => 'invalid_phone']], 'test@example.invalid');
    $row = $this->db->table('TblSMSSuppressions')->first();
    expect([(int) $row->Opt_Out, (int) $row->Invalid_Phone])->toBe([1, 1]);
    $this->summary->shouldHaveReceived('invalidate')->twice();
});

test('feedback replay does not refresh unchanged eligibility and rollback does not notify', function () {
    $feedback = new SmsFeedbackService;
    $rows = [['phone' => '2025550101', 'status' => 'opt_out']];
    $feedback->import($this->id, 0, $rows, 'test@example.invalid');
    $replay = $feedback->import($this->id, 0, $rows, 'test@example.invalid');
    expect($replay['replayed'])->toBeTrue();
    $this->summary->shouldHaveReceived('invalidate')->once();
    expect(fn () => $this->db->transaction(function () use ($feedback): void {
        $feedback->import($this->id, 1, [['phone' => '2025550102', 'status' => 'invalid_phone']], 'test@example.invalid');
        throw new RuntimeException('Synthetic rollback');
    }))->toThrow(RuntimeException::class, 'Synthetic rollback');
    $this->summary->shouldHaveReceived('invalidate')->once();
    expect($this->db->table('TblSMSSuppressions')->where('Phone', '2025550102')->exists())->toBeFalse();
});

test('feedback finds prospects by one keyed phone lookup, flags them by PK and leaves contacted ones', function () {
    $person = fn (array $row): int => $this->db->table('TblProspects')->insertGetId($row + ['Client' => 'Test Person']);
    $first = $person(['Phone' => '2025550101']); $second = $person(['Phone' => '2025550101']);
    $contacted = $person(['Phone' => '2025550101', 'Contacted' => 1]);
    $invalid = $person(['Phone' => '2025550102']); $already = $person(['Phone' => '2025550103', 'Opt_Out' => 1]);
    $other = $person(['Phone' => '2025550199']);
    $flags = fn (int $pk): array => array_map('intval', array_values((array) $this->db->table('TblProspects')->where('PK', $pk)->first(['Opt_Out', 'Invalid_Phone'])));
    $rows = [['phone' => '2025550101', 'status' => 'opt_out'], ['phone' => '+1 (202) 555-0101', 'status' => 'opt_out'],
        ['phone' => '2025550102', 'status' => 'invalid_phone'], ['phone' => '2025550103', 'status' => 'opt_out'], ['phone' => '2025550104', 'status' => 'opt_out']];

    $this->db->enableQueryLog();
    $result = (new SmsFeedbackService)->import($this->id, 0, $rows, 'test@example.invalid');
    $sql = array_column($this->db->getQueryLog(), 'query');
    $this->db->disableQueryLog();

    expect($result)->toBe(['records' => 5, 'matched' => 3, 'updated' => 3, 'replayed' => false]);
    expect([$flags($first), $flags($second), $flags($invalid), $flags($already)])->toBe([[1, 0], [1, 0], [0, 1], [1, 0]]);
    expect([$flags($contacted), $flags($other)])->toBe([[0, 0], [0, 0]], 'a contacted prospect and another phone keep their flags');
    expect($this->db->table('TblSMSSuppressions')->orderBy('Phone')->pluck('Opt_Out', 'Phone')->map(fn ($v) => (int) $v)->all())
        ->toBe(['2025550101' => 1, '2025550102' => 0, '2025550103' => 1, '2025550104' => 1], 'the block list gets every phone, matched or not');

    $onProspects = array_values(array_filter($sql, fn (string $q) => str_contains($q, 'TblProspects')));
    $lookups = array_values(array_filter($onProspects, fn (string $q) => stripos($q, 'select') === 0));
    expect($lookups)->toHaveCount(1);
    expect($lookups[0])->toContain('WHERE Contacted = 0 AND Phone IN (CAST(? AS varchar(10)),CAST(? AS varchar(10))');
    expect(substr_count($lookups[0], 'CAST(? AS varchar(10))'))->toBe(4, 'one cast per different phone');
    foreach (array_diff($onProspects, $lookups) as $write) {
        expect($write)->toContain('"PK" in (')->not->toContain('"Phone"');
    }

    $again = (new SmsFeedbackService)->import($this->id, 1, $rows, 'test@example.invalid');
    expect([$again['matched'], $again['updated'], $again['replayed']])->toBe([3, 0, false]);
});

test('feedback forces the phone index seek on SQL Server only', function () {
    $lookup = new ReflectionMethod(SmsFeedbackService::class, 'prospects');
    foreach (['sqlsrv' => 'FROM TblProspects WITH (FORCESEEK(IX_TblProspects_Phone(Phone))) WHERE Contacted = 0 AND Phone IN (CAST(? AS varchar(10)),CAST(? AS varchar(10)))',
        'sqlite' => 'FROM TblProspects WHERE Contacted = 0 AND Phone IN (CAST(? AS varchar(10)),CAST(? AS varchar(10)))'] as $driver => $expected) {
        $db = Mockery::mock(Illuminate\Database\ConnectionInterface::class);
        $db->shouldReceive('getDriverName')->andReturn($driver);
        $db->shouldReceive('select')->once()->withArgs(fn (string $sql, array $bindings) => str_ends_with($sql, $expected)
            && str_starts_with($sql, 'SELECT PK, Phone, Opt_Out, Invalid_Phone ') && $bindings === ['2025550101', '2025550102'])->andReturn([]);
        expect($lookup->invoke(new SmsFeedbackService, $db, ['2025550101', '2025550102']))->toBe([]);
    }
});

test('the completed workflow migration refuses destructive rollback', function () {
    $migration = require __DIR__.'/../../database/sms-migrations/2026_10_19_000001_complete_sms_workflow.php';
    expect(fn () => $migration->down())->toThrow(RuntimeException::class);
    expect($this->db->getSchemaBuilder()->hasTable('TblSMSAttemptAudit'))->toBeTrue();
});
