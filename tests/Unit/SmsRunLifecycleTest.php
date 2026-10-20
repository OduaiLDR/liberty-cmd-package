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

test('the completed workflow migration refuses destructive rollback', function () {
    $migration = require __DIR__.'/../../database/sms-migrations/2026_10_19_000001_complete_sms_workflow.php';
    expect(fn () => $migration->down())->toThrow(RuntimeException::class);
    expect($this->db->getSchemaBuilder()->hasTable('TblSMSAttemptAudit'))->toBeTrue();
});
