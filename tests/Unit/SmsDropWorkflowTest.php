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
    $container->instance('config', new Repository(['database' => ['default' => 'sqlsrv']]));
    $capsule = new Manager($container);
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'sqlsrv');
    $capsule->getDatabaseManager()->setDefaultConnection('sqlsrv');
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
        $table->string('Drop_Type')->nullable();
        $table->date('Send_Date');
        $table->string('Vendor')->default('Vendor A');
        $table->string('Data_Type')->nullable();
        $table->string('Mail_Style')->nullable();
        $table->integer('Amount_Dropped')->default(0);
        $table->integer('Calls')->nullable();
        $table->string('Language')->nullable();
        $table->string('Drop_Name_Sequential')->nullable();
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
    $schema->create('TblMailersUniqueEnriched2', function (Blueprint $table) {
        $table->integer('PK')->primary();
        $table->string('Drop_Name');
        $table->string('External_ID');
        $table->string('Client')->default('Sample Person');
        $table->string('Address')->default('Sample address');
        $table->integer('Debt_Amount')->nullable();
        foreach (range(1, 5) as $slot) $table->string('phone'.$slot)->nullable();
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
    expect($this->repo->allDrops()->first()->Amount_Dropped)->toBeNull();
    expect((int) $this->repo->selectDrops(1)->first()->Amount_Dropped)->toBe(1);
    $export = $this->repo->prepareExport(1, '00000000-0000-4000-8000-000000000001');
    try {
        $csv = file_get_contents($export['path']);
        expect($export['count'])->toBe(1);
        expect($csv)->toStartWith("First name,address,debt load,phone1,phone2,phone3,phone4,phone5,send date\r\n")
            ->toContain('2025550103')->not->toContain('2025550101')->not->toContain('2025550102');
        expect($this->db->table('TblSmsExportSources')->sum('SMS_Count'))->toBe(1);
    } finally {
        unlink($export['path']);
    }
});

test('contacted phone lookups bind every number as text for SQL Server', function () {
    smsFixture($this->db, 1, 'T1', '2026-10-05', ['3147577081']);
    $this->db->table('TblPhoneNumbers')->insert(['Phone' => '13147577081']);
    $this->db->enableQueryLog();

    expect($this->repo->selectDrops(1))->toHaveCount(0);
    $lookup = collect($this->db->getQueryLog())->first(
        fn ($query) => str_contains($query['query'], 'TblPhoneNumbers')
    );
    expect($lookup)->not->toBeNull();
    expect($lookup['bindings'])->toBe(['3147577081', '13147577081']);
});

test('whitespace identity variants remain adjacent and suppress the complete merged lead', function () {
    smsFixture($this->db, 1, 'T1', '2026-10-05', ['2025550101', '2025550102']);
    $this->db->table('TblMailersUniqueEnriched')->insert([
        ['PK' => 102, 'Drop_Name' => 'DROP1', 'External_ID' => "\tEXT100", 'Phone' => '2025550103'],
        ['PK' => 103, 'Drop_Name' => 'DROP1', 'External_ID' => "\tEXT101", 'Phone' => '2025550104'],
    ]);
    $this->db->table('TblPhoneNumbers')->insert(['Phone' => '2025550103']);
    $export = $this->repo->prepareExport(1, '00000000-0000-4000-8000-000000000099');
    try {
        expect($export['count'])->toBe(2);
        expect(file_get_contents($export['path']))->not->toContain('2025550101')->not->toContain('2025550103');
    } finally { unlink($export['path']); }
});

test('selects whole drops newest first and overshoots the target', function () {
    smsFixture($this->db, 1, 'T1', '2026-09-28', ['2025550101', '2025550102']);
    smsFixture($this->db, 2, 'T2', '2026-10-05', ['2025550103', '2025550104', '2025550105']);
    $selected = $this->repo->selectDrops(4);
    expect($selected->pluck('PK')->all())->toBe([2, 1]);
    expect((int) $selected->sum('Amount_Dropped'))->toBe(5);
});

/** Small metadata fixtures exercise large phone targets without creating fake millions of leads. */
function smsSelectionCountFixture($db, array $counts): MailDropExportRepository
{
    $details = [];
    foreach ($counts as $index => $count) {
        $id = $index + 1;
        smsFixture($db, $id, 'T1', Carbon::parse('2026-10-06')->subDays($index)->toDateString(), []);
        $details['DROP'.$id] = is_array($count) ? $count : ['count' => $count, 'missing_identity' => false];
    }
    $repo = new class extends MailDropExportRepository {
        public array $details;
        public array $countedIds = [];
        protected function phoneCounts(\Illuminate\Support\Collection $drops): array
        {
            $result = [];
            foreach ($drops as $drop) {
                $this->countedIds[] = (int) $drop->PK;
                $result[$drop->Drop_Name] = $this->details[$drop->Drop_Name];
            }
            return $result;
        }
    };
    $repo->details = $details;
    return $repo;
}

test('automatic selection replaces the crossing drop with the smallest qualifying next drop', function () {
    $repo = smsSelectionCountFixture($this->db, [600000, 800000, 500000, 400000, 400000, 390000, 0]);
    $progress = [];
    $selected = $repo->selectDrops(1000000, function ($processed, $phones) use (&$progress) { $progress[] = [$processed, $phones]; });
    expect($selected->pluck('PK')->all())->toBe([1, 4]);
    expect((int) $selected->sum('Amount_Dropped'))->toBe(1000000);
    expect(end($progress))->toBe([7, 1000000]);
    foreach (array_slice($progress, 1) as $index => $current) {
        expect($current[0])->toBeGreaterThanOrEqual($progress[$index][0]);
        expect($current[1])->toBeGreaterThanOrEqual($progress[$index][1]);
    }
});

test('automatic selection retains an exact hit and does not inspect later drops', function () {
    $repo = smsSelectionCountFixture($this->db, [6, 4, ['count' => 1, 'missing_identity' => true]]);
    expect($repo->selectDrops(10)->pluck('PK')->all())->toBe([1, 2]);
    expect($repo->countedIds)->toBe([1, 2]);
});

test('automatic selection looks at exactly the next thirty candidates and otherwise retains the crossing drop', function () {
    $repo = smsSelectionCountFixture($this->db, [6, 10, ...array_fill(0, 29, 3), 0, 4]);
    $selected = $repo->selectDrops(10);
    expect($selected->pluck('PK')->all())->toBe([1, 2]);
    expect((int) $selected->sum('Amount_Dropped'))->toBe(16);
    expect($repo->countedIds)->toBe(range(1, 32));
});

test('automatic selection allows a qualifying replacement at the thirtieth lookahead position', function () {
    $repo = smsSelectionCountFixture($this->db, [6, 10, ...array_fill(0, 29, 3), 5, 4]);
    $selected = $repo->selectDrops(10);
    expect($selected->pluck('PK')->all())->toBe([1, 32]);
    expect((int) $selected->sum('Amount_Dropped'))->toBe(11);
    expect($repo->countedIds)->toBe(range(1, 32));
});

test('automatic selection validates only the selected lookahead replacement', function () {
    $repo = smsSelectionCountFixture($this->db, [6, 10, ['count' => 5, 'missing_identity' => true], 4]);
    expect($repo->selectDrops(10)->pluck('PK')->all())->toBe([1, 4]);
    $repo->details['DROP4'] = ['count' => 4, 'missing_identity' => true];
    expect(fn () => $repo->selectDrops(10))->toThrow(ValidationException::class);
});

test('automatic selection retains a shortfall when no crossing drop exists', function () {
    $repo = smsSelectionCountFixture($this->db, [6, 0, 3]);
    expect((int) $repo->selectDrops(10)->sum('Amount_Dropped'))->toBe(9);
});

test('automatic selection compares suppressed phone counts when choosing its replacement', function () {
    smsFixture($this->db, 1, 'T1', '2026-10-06', ['2025550101']);
    smsFixture($this->db, 2, 'T1', '2026-10-05', ['2025550102', '2025550103', '2025550104', '2025550105']);
    smsFixture($this->db, 3, 'T1', '2026-10-04', ['2025550106', '2025550107']);
    smsFixture($this->db, 4, 'T1', '2026-10-03', ['2025550108', '2025550109']);
    $this->db->table('TblPhoneNumbers')->insert(['Phone' => '12025550106']);
    $selected = $this->repo->selectDrops(3);
    expect($selected->pluck('PK')->all())->toBe([1, 4]);
    expect((int) $selected->sum('Amount_Dropped'))->toBe(3);
});

test('automatic selection never replaces the crossing drop with a worse overshoot', function () {
    $repo = smsSelectionCountFixture($this->db, [6, 5, 8, 5]);
    $selected = $repo->selectDrops(10);
    expect($selected->pluck('PK')->all())->toBe([1, 2]);
    expect((int) $selected->sum('Amount_Dropped'))->toBe(11);
});

test('automatic selection defers validation of a crossing drop that is replaced', function () {
    $repo = smsSelectionCountFixture($this->db, [6, ['count' => 10, 'missing_identity' => true], 4]);
    expect($repo->selectDrops(10)->pluck('PK')->all())->toBe([1, 3]);
    $repo->details['DROP3'] = ['count' => 3, 'missing_identity' => false];
    expect(fn () => $repo->selectDrops(10))->toThrow(ValidationException::class);
});

test('automatic selection does not replace an unused crossing drop with a previously exported drop', function () {
    $repo = smsSelectionCountFixture($this->db, [6, 10, 4]);
    $this->db->table('TblMarketing')->where('PK', 3)->update(['SMS_Drops' => 1, 'SMS_Last_Export_Date' => '2026-10-01']);
    $selected = $repo->selectDrops(10);
    expect($selected->pluck('PK')->all())->toBe([1, 2]);
    expect((int) $selected->sum('Amount_Dropped'))->toBe(16);
});

test('manual selections above the old cap keep SQL binding batches below the server limit', function () {
    $repo = smsSelectionCountFixture($this->db, array_fill(0, 2101, 1));
    $this->db->enableQueryLog();
    $selected = $repo->selectDropsByIds(range(1, 2101));
    expect($selected)->toHaveCount(2101);
    expect((int) $selected->sum('Amount_Dropped'))->toBe(2101);
    foreach ($this->db->getQueryLog() as $query) {
        expect(count($query['bindings']))->toBeLessThanOrEqual(900);
    }
});

function smsBatchedSelectionJob(string $id, int $cursor = 0): \Cmd\Reports\Jobs\PlanSmsSelectionJob
{
    return new class($id, $cursor) extends \Cmd\Reports\Jobs\PlanSmsSelectionJob {
        public array $continuations = [];
        public bool $failDispatch = false;
        protected function dispatchNext(int $cursor): void
        {
            if ($this->failDispatch) throw new RuntimeException('queue unavailable');
            $this->continuations[] = $cursor;
        }
    };
}

function smsBatchedSelectionSetup($db, int $target): array
{
    (require __DIR__.'/../../database/sms-migrations/2026_10_07_000002_add_sms_selection_requests.php')->up();
    $id = '00000000-0000-4000-8000-000000000071';
    $db->table('TblSmsSelectionRequests')->insert(['Request_ID' => $id, 'Actor_Email' => 'one@example.com',
        'Status' => 'queued', 'Selection_Mode' => 'automatic', 'Target' => $target,
        'Created_At' => now()->toDateTimeString(), 'Updated_At' => now()->toDateTimeString()]);
    $snapshots = new class extends \Cmd\Reports\Services\SmsSelectionProgress {
        public array $results = [];
        public function write(string $id, string $actor, array $result): void { $this->results[] = $result; }
        public function forget(string $id): void {}
    };
    Container::getInstance()->instance(\Cmd\Reports\Services\SmsSelectionProgress::class, $snapshots);
    Container::getInstance()->instance('log', new \Psr\Log\NullLogger);
    $queue = new class extends \Cmd\Reports\Services\SmsExportQueue { public function assertReady(): void {} };
    return [$id, $queue, $snapshots];
}

test('selection jobs checkpoint one drop and resume without recounting finished candidates', function () {
    $repo = smsSelectionCountFixture($this->db, [6, 10, 5, 4]);
    [$id, $queue, $snapshots] = smsBatchedSelectionSetup($this->db, 10);
    $first = smsBatchedSelectionJob($id);
    $first->handle($repo, $queue);
    $row = $this->db->table('TblSmsSelectionRequests')->first();
    $saved = json_decode($row->Result, true);
    expect($row->Status)->toBe('queued');
    expect($saved['checkpoint']['cursor'])->toBe(1);
    expect($saved['checkpoint']['counts']['1']['count'])->toBe(6);
    expect($first->continuations)->toBe([1]);
    $first->handle($repo, $queue);
    $first->failed(new RuntimeException('a stale callback'));
    expect($repo->countedIds)->toBe([1]);
    expect($this->db->table('TblSmsSelectionRequests')->value('Status'))->toBe('queued');
    foreach ([1, 2, 3] as $cursor) smsBatchedSelectionJob($id, $cursor)->handle($repo, $queue);
    $row = $this->db->table('TblSmsSelectionRequests')->first();
    $ready = json_decode($row->Result, true);
    expect($row->Status)->toBe('ready');
    expect(array_column($ready['drops'], 'PK'))->toBe([1, 4]);
    expect($ready['total'])->toBe(10);
    expect($repo->countedIds)->toBe([1, 2, 3, 4]);
    expect($ready)->not->toHaveKey('checkpoint');
    foreach ($snapshots->results as $snapshot) expect($snapshot)->not->toHaveKey('checkpoint');
});

test('selection continuation dispatch failure preserves completed count for same-request resume', function () {
    $repo = smsSelectionCountFixture($this->db, [6, 4]);
    [$id, $queue, $snapshots] = smsBatchedSelectionSetup($this->db, 10);
    $first = smsBatchedSelectionJob($id);
    $first->failDispatch = true;
    expect(fn () => $first->handle($repo, $queue))->toThrow(RuntimeException::class, 'queue unavailable');
    $row = $this->db->table('TblSmsSelectionRequests')->first();
    expect($row->Status)->toBe('failed');
    expect(json_decode($row->Result, true)['checkpoint']['cursor'])->toBe(1);
    expect(end($snapshots->results)['can_resume'])->toBeTrue();
    $this->db->table('TblSmsSelectionRequests')->update(['Status' => 'queued', 'Error' => null]);
    smsBatchedSelectionJob($id, 1)->handle($repo, $queue);
    expect($repo->countedIds)->toBe([1, 2]);
    expect($this->db->table('TblSmsSelectionRequests')->value('Status'))->toBe('ready');
});

test('selection job rejects a duplicate claim already owned by a running worker', function () {
    $repo = smsSelectionCountFixture($this->db, [1]);
    [$id, $queue] = smsBatchedSelectionSetup($this->db, 1);
    $this->db->table('TblSmsSelectionRequests')->update(['Status' => 'running']);
    smsBatchedSelectionJob($id)->handle($repo, $queue);
    expect($repo->countedIds)->toBe([]);
});

test('selection checkpoint retains its frozen order across new mailers and marketing updates', function () {
    $repo = smsSelectionCountFixture($this->db, [6, 10, 4]);
    $state = $repo->advanceSelectionCheckpoint($repo->startSelectionCheckpoint(10));
    $this->db->table('TblMarketing')->where('PK', 2)->update(['Send_Date' => '2026-10-12']);
    smsFixture($this->db, 99, 'T1', '2026-10-06', ['2025550199']);
    while (! $state['complete']) $state = $repo->advanceSelectionCheckpoint(json_decode(json_encode($state), true));
    $result = $repo->selectionCheckpointResult($state);
    expect(array_column($result['drops'], 'PK'))->toBe([1, 3]);
    expect($repo->countedIds)->toBe([1, 2, 3]);
});

test('selection refinement stops before counting an ineligible higher usage band', function () {
    $repo = smsSelectionCountFixture($this->db, [6, 5, 4]);
    $this->db->table('TblMarketing')->where('PK', 3)->update(['SMS_Drops' => 1]);
    $state = $repo->startSelectionCheckpoint(10);
    while (! $state['complete']) $state = $repo->advanceSelectionCheckpoint($state);
    $result = $repo->selectionCheckpointResult($state);
    expect(array_column($result['drops'], 'PK'))->toBe([1, 2]);
    expect($result['total'])->toBe(11);
    expect($repo->countedIds)->toBe([1, 2]);
    expect($state['cursor'])->toBe(2);
});

test('an older selection failure callback cannot fail a newly claimed continuation', function () {
    $repo = smsSelectionCountFixture($this->db, [6, 4]);
    [$id, $queue] = smsBatchedSelectionSetup($this->db, 10);
    $old = smsBatchedSelectionJob($id);
    $old->handle($repo, $queue);
    $row = $this->db->table('TblSmsSelectionRequests')->first();
    $saved = json_decode($row->Result, true);
    $saved['worker_token'] = 'another-worker';
    $this->db->table('TblSmsSelectionRequests')->update(['Status' => 'running', 'Result' => json_encode($saved)]);
    $old->failed(new RuntimeException('old job failed'));
    expect($this->db->table('TblSmsSelectionRequests')->value('Status'))->toBe('running');
});

test('legacy serialized selection jobs initialize missing batch properties safely', function () {
    $repo = smsSelectionCountFixture($this->db, [1]);
    [$id, $queue] = smsBatchedSelectionSetup($this->db, 1);
    $legacy = smsBatchedSelectionJob($id);
    unset($legacy->workerToken, $legacy->expectedCursor);
    $legacy->handle($repo, $queue);
    expect($this->db->table('TblSmsSelectionRequests')->value('Status'))->toBe('ready');
    $callback = smsBatchedSelectionJob($id);
    unset($callback->workerToken, $callback->expectedCursor);
    $callback->failed(new RuntimeException('old callback'));
    expect($this->db->table('TblSmsSelectionRequests')->value('Status'))->toBe('ready');
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
    smsFixture($this->db, 5, 'T5', '2026-10-12', ['2025550105']);
    expect($this->repo->selectDrops(1)->pluck('PK')->all())->toBe([4]);
    expect((int) $this->repo->allDrops()->firstWhere('PK', 5)->SMS_Selectable)->toBe(0);
    expect(fn () => $this->repo->selectDropsByIds([5]))->toThrow(ValidationException::class);
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
    expect($this->db->table('TblMarketing')->value('SMS_Last_Export_Date'))->toBe('2026-10-06 12:00:00');
    expect((int) $this->db->table('TblSmsExportSources')->where('Marketing_PK', 1)->value('SMS_Count'))->toBe(1);
});

test('a file failure rolls back earlier tier logs and all counters', function () {
    smsFixture($this->db, 1, 'T1', '2026-10-05', ['2025550101']);
    smsFixture($this->db, 2, 'T2', '2026-10-05', ['2025550102']);
    $repo = new class extends MailDropExportRepository {
        protected function writeCsv(mixed $out, array $values): void
        {
            if (($values[3] ?? '') === '2025550102') {
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
    expect(fn () => (new MarketingReportRepository)->allocateInvoice(['kind' => 'sms', 'invoice_number' => 'SECOND', 'cost' => '2.00', 'week' => '2026-10-07']))
        ->toThrow(ValidationException::class);
    expect($this->db->table('TblSmsExports')->where('PK', 1)->value('SMS_Invoice_Number'))->toBe('SMS-INV');
});

test('mail invoice distributes across all tiers of one drop and leaves other drops unchanged', function () {
    smsFixture($this->db, 1, 'T1', '2026-10-05', ['2025550101']);
    smsFixture($this->db, 2, 'T2', '2026-10-05', ['2025550102', '2025550103']);
    smsFixture($this->db, 3, 'T3', '2026-10-05', ['2025550104']);
    $this->db->table('TblMarketing')->where('PK', 2)->update(['Drop_Name' => 'DROP1']);
    (new MarketingReportRepository)->allocateInvoice(['kind' => 'mail', 'invoice_number' => 'MAIL-INV', 'cost' => '10.00', 'drop_name' => 'DROP1']);
    expect((float) $this->db->table('TblMarketing')->where('PK', 1)->value('Mail_Drop_Cost'))->toBe(3.33);
    expect((float) $this->db->table('TblMarketing')->where('PK', 2)->value('Mail_Drop_Cost'))->toBe(6.67);
    expect($this->db->table('TblMarketing')->where('PK', 3)->value('Mail_Invoice_Number'))->toBeNull();
    expect((float) $this->db->table('TblMarketing')->where('PK', 3)->value('Mail_Drop_Cost'))->toBe(0.0);
    expect(fn () => (new MarketingReportRepository)->allocateInvoice(['kind' => 'mail', 'invoice_number' => 'SECOND', 'cost' => '9.00', 'drop_name' => 'DROP1']))
        ->toThrow(ValidationException::class);
    expect($this->db->table('TblMarketing')->where('PK', 1)->value('Mail_Invoice_Number'))->toBe('MAIL-INV');
    (new MarketingReportRepository)->allocateInvoice(['kind' => 'data', 'invoice_number' => 'DATA-INV', 'cost' => '0.01', 'drop_name' => 'DROP1']);
    expect((float) $this->db->table('TblMarketing')->where('PK', 1)->value('Data_Drop_Cost'))->toBe(0.0);
    expect((float) $this->db->table('TblMarketing')->where('PK', 2)->value('Data_Drop_Cost'))->toBe(0.01);
});


test('form requests reject invalid targets and invoices and enforce permissions', function () {
    $export = new \Cmd\Reports\Http\Requests\MailDropExportRequest;
    $invoice = new \Cmd\Reports\Http\Requests\MarketingInvoiceRequest;
    expect($export->authorize())->toBeFalse();
    expect($invoice->authorize())->toBeFalse();
    $validator = Container::getInstance()->make('validator');
    expect($validator->make(['target' => -1, 'request_id' => 'invalid'], $export->rules())->fails())->toBeTrue();
    expect($validator->make(['kind' => 'mail', 'invoice_number' => 'INV', 'cost' => '1.001', 'week' => '2026-10-05'], $invoice->rules())->fails())->toBeTrue();
    expect($validator->make(['kind' => 'mail', 'invoice_number' => 'INV', 'cost' => '1.00', 'drop_name' => 'DROP1'], $invoice->rules())->passes())->toBeTrue();
    expect($validator->make(['kind' => 'mail', 'invoice_number' => 'INV', 'cost' => '1.00', 'week' => '2026-10-05'], $invoice->rules())->fails())->toBeTrue();
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
    foreach (['cmd.reports.mail_drop_export', 'cmd.reports.mail_drop_export.export', 'cmd.reports.marketing_report', 'cmd.reports.marketing_report.invoice', 'cmd.reports.marketing_report.mail.update', 'cmd.reports.marketing_report.data.update'] as $routeName) {
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
    expect($html)->toContain('SMS0009')->toContain('INV-9')->toContain('$1.23')->not->toContain('Distribute SMS invoice');
});

test('failed durable publishing rolls back SMS tracking and cleans the CSV', function () {
    smsFixture($this->db, 1, 'T1', '2026-10-05', ['2025550101']);
    $file = null;
    expect(function () use (&$file) { $this->repo->prepareExport(1, '00000000-0000-4000-8000-000000000116', null,
        function (array $export) use (&$file): void {
            $file = $export['path'];
            expect(is_file($file))->toBeTrue();
            throw new RuntimeException('S3 upload failed');
        }); })->toThrow(RuntimeException::class, 'S3 upload failed');
    expect(is_file($file))->toBeFalse();
    expect($this->db->table('TblSmsExports')->count())->toBe(0);
    expect($this->db->table('TblSmsExportSources')->count())->toBe(0);
    expect((int) $this->db->table('TblMarketing')->value('SMS_Drops'))->toBe(0);
});

test('durable publishing sees completed CSV and ZIP before tracking commits', function () {
    smsFixture($this->db, 1, 'T1', '2026-10-05', ['2025550101', '2025550102', '2025550103']);
    $seen = [];
    $csv = $this->repo->prepareExport(3, '00000000-0000-4000-8000-000000000117', null,
        function (array $export) use (&$seen): void {
            $seen[] = [$export['format'], is_file($export['path'])];
        });
    unlink($csv['path']);
    config()->set('sms-exports.split_csv', true);
    $repo = new class extends MailDropExportRepository {
        protected function csvRecordLimit(): int { return 2; }
    };
    $zip = $repo->prepareExport(3, '00000000-0000-4000-8000-000000000118', null,
        function (array $export) use (&$seen): void {
            $seen[] = [$export['format'], is_file($export['path'])];
        });
    unlink($zip['path']);
    expect($seen)->toBe([['csv', true], ['zip', true]]);
    expect($this->db->table('TblSmsExports')->count())->toBe(2);
});

test('counts frozen priority candidates in small ordered batches including zero-eligible drops', function () {
    for ($id = 1; $id <= 12; $id++) {
        smsFixture($this->db, $id, 'T1', Carbon::parse('2026-10-06')->subDays($id)->toDateString(), ['202555'.str_pad((string) $id, 4, '0', STR_PAD_LEFT)]);
    }
    $this->db->table('TblPhoneNumbers')->insert(['Phone' => '2025550012']);
    $ids = $this->repo->orderedSelectableDropIds()->all();
    expect($ids)->toBe(range(1, 12));
    $first = $this->repo->countedDropsByIds(array_slice($ids, 0, 10));
    $second = $this->repo->countedDropsByIds(array_slice($ids, 10, 10));
    expect($first->pluck('PK')->all())->toBe(range(1, 10));
    expect($second->pluck('PK')->all())->toBe([11, 12]);
    expect((int) $second->last()->Amount_Dropped)->toBe(0);
});

test('defaults to one CSV even beyond the optional part limit without losing records or tracking', function () {
    smsFixture($this->db, 1, 'T1', '2026-10-05', ['2025550101', '2025550102', '2025550103']);
    $repo = new class extends MailDropExportRepository {
        protected function csvRecordLimit(): int { return 2; }
    };
    $export = $repo->prepareExport(3, '00000000-0000-4000-8000-000000000120');
    try {
        expect($export['format'])->toBe('csv');
        expect($export['part_count'])->toBe(1);
        expect($export['count'])->toBe(3);
        $csv = file_get_contents($export['path']);
        expect(substr_count($csv, "\n"))->toBe(4);
        expect($csv)->toContain('2025550101')->toContain('2025550102')->toContain('2025550103');
        expect($this->db->table('TblSmsExports')->count())->toBe(1);
        expect((int) $this->db->table('TblSmsExports')->value('SMS_Count'))->toBe(3);
        expect((int) $this->db->table('TblMarketing')->value('SMS_Drops'))->toBe(1);
    } finally {
        unlink($export['path']);
    }
});

test('splits CSV records into numbered archive parts without splitting export tracking', function () {
    config()->set('sms-exports.split_csv', true);
    smsFixture($this->db, 1, 'T1', '2026-10-05', ['2025550101', '2025550102', '2025550103']);
    $repo = new class extends MailDropExportRepository {
        protected function csvRecordLimit(): int { return 2; }
    };
    $export = $repo->prepareExport(3, '00000000-0000-4000-8000-000000000101');
    try {
        expect($export['format'])->toBe('zip');
        expect($export['part_count'])->toBe(2);
        expect($export['count'])->toBe(3);
        $zip = new ZipArchive;
        expect($zip->open($export['path']))->toBeTrue();
        try {
            expect($zip->numFiles)->toBe(2);
            $first = $zip->getFromIndex(0);
            $second = $zip->getFromIndex(1);
            expect(substr_count($first, "\n"))->toBe(3);
            expect(substr_count($second, "\n"))->toBe(2);
            expect($first)->toContain('2025550101')->toContain('2025550102')->not->toContain('2025550103');
            expect($second)->toContain('2025550103');
            expect(strtok($first, "\r\n"))->toBe(strtok($second, "\r\n"));
        } finally {
            $zip->close();
        }
        expect($this->db->table('TblSmsExports')->count())->toBe(1);
        expect((int) $this->db->table('TblSmsExports')->value('SMS_Count'))->toBe(3);
        expect((int) $this->db->table('TblMarketing')->value('SMS_Drops'))->toBe(1);
    } finally {
        unlink($export['path']);
    }
});

test('keeps an export at the optional CSV row limit as one CSV', function () {
    config()->set('sms-exports.split_csv', true);
    smsFixture($this->db, 1, 'T1', '2026-10-05', ['2025550101', '2025550102']);
    $repo = new class extends MailDropExportRepository {
        protected function csvRecordLimit(): int { return 2; }
    };
    $export = $repo->prepareExport(2, '00000000-0000-4000-8000-000000000102');
    try {
        expect($export['format'])->toBe('csv');
        expect($export['part_count'])->toBe(1);
        expect(substr_count(file_get_contents($export['path']), "\n"))->toBe(3);
    } finally {
        unlink($export['path']);
    }
});

test('rolls back tracking when a later CSV part cannot be written', function () {
    config()->set('sms-exports.split_csv', true);
    smsFixture($this->db, 1, 'T1', '2026-10-05', ['2025550101', '2025550102', '2025550103']);
    $repo = new class extends MailDropExportRepository {
        private int $writes = 0;
        protected function csvRecordLimit(): int { return 2; }
        protected function writeCsv(mixed $out, array $values): void {
            if (++$this->writes === 4) throw new RuntimeException('Second part failed');
            parent::writeCsv($out, $values);
        }
    };
    expect(fn () => $repo->prepareExport(3, '00000000-0000-4000-8000-000000000103'))
        ->toThrow(RuntimeException::class, 'Second part failed');
    expect($this->db->table('TblSmsExports')->count())->toBe(0);
    expect($this->db->table('TblSmsExportSources')->count())->toBe(0);
    expect((int) $this->db->table('TblMarketing')->value('SMS_Drops'))->toBe(0);
});

test('removes CSV parts and rolls back tracking when archive creation fails', function () {
    config()->set('sms-exports.split_csv', true);
    smsFixture($this->db, 1, 'T1', '2026-10-05', ['2025550101', '2025550102', '2025550103']);
    $repo = new class extends MailDropExportRepository {
        public array $createdPaths = [];
        protected function csvRecordLimit(): int { return 2; }
        protected function archiveCsvParts(array $parts, string $archivePath, Carbon $exportedAt): void {
            $this->createdPaths = [...$parts, $archivePath];
            throw new RuntimeException('Archive failed');
        }
    };
    expect(fn () => $repo->prepareExport(3, '00000000-0000-4000-8000-000000000104'))
        ->toThrow(RuntimeException::class, 'Archive failed');
    expect($repo->createdPaths)->toHaveCount(3);
    foreach ($repo->createdPaths as $path) expect(is_file($path))->toBeFalse();
    expect($this->db->table('TblSmsExports')->count())->toBe(0);
    expect((int) $this->db->table('TblMarketing')->value('SMS_Drops'))->toBe(0);
});

test('marketing rows flag an invoice recorded on another tier of the same drop', function () {
    smsFixture($this->db, 1, 'T1', '2026-10-05', ['2025550101']);
    smsFixture($this->db, 2, 'T2', '2026-10-05', ['2025550102']);
    smsFixture($this->db, 3, 'T3', '2026-10-05', ['2025550103']);
    $this->db->table('TblMarketing')->where('PK', 2)->update(['Drop_Name' => 'DROP1', 'Mail_Invoice_Number' => 'OLDER']);
    $rows = (new MarketingReportRepository)->all()->keyBy('PK');
    expect((int) $rows[1]->Mail_Invoice_Recorded)->toBe(1);
    expect((int) $rows[2]->Mail_Invoice_Recorded)->toBe(1);
    expect((int) $rows[3]->Mail_Invoice_Recorded)->toBe(0);
    expect((int) $rows[1]->Data_Invoice_Recorded)->toBe(0);
});

test('direct cost edits cannot change a drop after any tier is invoiced', function () {
    smsFixture($this->db, 1, 'T1', '2026-10-05', ['2025550101']);
    smsFixture($this->db, 2, 'T2', '2026-10-05', ['2025550102']);
    $this->db->table('TblMarketing')->where('PK', 2)->update(['Drop_Name' => 'DROP1', 'Mail_Invoice_Number' => 'OLDER']);
    $repository = new MarketingReportRepository;
    expect(fn () => $repository->updateMailDropCost(1, 99.00))->toThrow(ValidationException::class);
    expect((float) $this->db->table('TblMarketing')->where('PK', 1)->value('Mail_Drop_Cost'))->toBe(0.0);
    $repository->updateDataDropCost(1, 1.25);
    expect((float) $this->db->table('TblMarketing')->where('PK', 1)->value('Data_Drop_Cost'))->toBe(1.25);
    $this->db->table('TblMarketing')->where('PK', 2)->update(['Data_Invoice_Number' => 'DATA-OLD']);
    expect(fn () => $repository->updateDataDropCost(1, 99.00))->toThrow(ValidationException::class);
    expect((float) $this->db->table('TblMarketing')->where('PK', 1)->value('Data_Drop_Cost'))->toBe(1.25);
});

test('marketing view offers only missing invoices for each source drop', function () {
    $base = [
        'PK' => 1, 'Drop_Name' => 'DROP1', 'Debt_Tier' => 'T1', 'Drop_Type' => '', 'Vendor' => 'Vendor A',
        'Data_Type' => '', 'Mail_Style' => '', 'Send_Date' => '2026-10-05', 'Amount_Dropped' => 10,
        'Mail_Invoice_Number' => null, 'Mail_Drop_Cost' => 0, 'Per_Piece_Mail_Cost' => 0,
        'Data_Invoice_Number' => 'DATA-1', 'Data_Drop_Cost' => 2, 'Per_Piece_Data_Cost' => 0.2,
        'Total_Drop_Cost' => 2, 'Per_Piece_Total_Cost' => 0.2, 'Calls' => 0,
        'Language' => '', 'Drop_Name_Sequential' => '',
    ];
    $html = smsRenderView('reports::reports.marketing', [
        'reports' => collect([(object) $base]), 'options' => [], 'smsWeek' => '2026-10-05', 'smsExports' => collect(),
    ]);
    expect($html)->toContain('Add Mail invoice')->not->toContain('Add Data invoice');
    expect($html)->toContain('name="drop_name" value="DROP1"');
    $base['Mail_Invoice_Number'] = 'MAIL-1';
    $html = smsRenderView('reports::reports.marketing', [
        'reports' => collect([(object) $base]), 'options' => [], 'smsWeek' => '2026-10-05', 'smsExports' => collect(),
    ]);
    expect($html)->not->toContain('Add Mail invoice')->not->toContain('Add Data invoice');
});


test('failed phone sync preserves the previous suppression snapshot', function () {
    $this->db->getSchemaBuilder()->table('TblPhoneNumbers', function (Blueprint $table) {
        $table->string('Source')->nullable();
        $table->integer('CID')->nullable();
    });
    $this->db->table('TblPhoneNumbers')->insert(['Phone' => '2025550101', 'Source' => 'DP_LT']);
    $pdo = $this->db->getPdo();
    $command = \Mockery::mock(\Cmd\Reports\Console\Commands\SyncPhoneNumbers::class)->makePartial();
    $command->shouldReceive('info')->andReturnNull();
    $path = tempnam(sys_get_temp_dir(), 'sms-sync-test-');
    file_put_contents($path, json_encode(['phone' => '2025550199', 'cid' => 1])."\ninvalid json\n");
    try {
        $method = new ReflectionMethod($command, 'replacePhonesFromFile');
        expect(fn () => $method->invoke($command, $pdo, $path, 1))->toThrow(JsonException::class);
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
    $command = \Mockery::mock(\Cmd\Reports\Console\Commands\SyncPhoneNumbers::class)->makePartial();
    $command->shouldReceive('info')->andReturnNull();
    $path = tempnam(sys_get_temp_dir(), 'sms-sync-test-');
    file_put_contents($path, json_encode(['phone' => '2025550199', 'cid' => 1])."\n");
    try {
        $result = (new ReflectionMethod($command, 'replacePhonesFromFile'))->invoke($command, $pdo, $path, 1);
        expect($result)->toBe([1, 1, 0]);
        expect($this->db->table('TblPhoneNumbers')->orderBy('Phone')->pluck('Phone')->all())->toBe(['2025550102', '2025550199']);
    } finally {
        unlink($path);
        \Mockery::close();
    }
});

test('manual export uses only the chosen whole drops', function () {
    smsFixture($this->db, 1, 'T1', '2026-09-28', ['2025550101', '2025550102']);
    smsFixture($this->db, 2, 'T2', '2026-10-05', ['2025550103']);
    expect($this->repo->selectDropsByIds([1])->pluck('PK')->all())->toBe([1]);
    $export = $this->repo->prepareExport(2, '00000000-0000-4000-8000-000000000009', [1]);
    try {
        expect($export['count'])->toBe(2);
        expect(file_get_contents($export['path']))->toContain('2025550101')->toContain('2025550102')->not->toContain('2025550103');
        expect($this->db->table('TblMarketing')->where('PK', 1)->value('SMS_Drops'))->toBe(1);
        expect($this->db->table('TblMarketing')->where('PK', 2)->value('SMS_Drops'))->toBe(0);
    } finally {
        unlink($export['path']);
    }
    expect(fn () => $this->repo->selectDropsByIds([99]))->toThrow(ValidationException::class);
});

test('duplicate marketing source names cannot export the same phones twice', function () {
    smsFixture($this->db, 1, 'T1', '2026-10-05', ['2025550101']);
    $this->db->table('TblMarketing')->insert([
        'PK' => 2, 'Drop_Name' => 'DROP1', 'Debt_Tier' => 'T2', 'Send_Date' => '2026-10-06',
    ]);
    expect(fn () => $this->repo->selectDropsByIds([1]))->toThrow(ValidationException::class);
    expect(fn () => $this->repo->selectDropsByIds([1, 2]))->toThrow(ValidationException::class);
    expect(fn () => $this->repo->selectDrops(1))->toThrow(ValidationException::class);
    expect(fn () => $this->repo->prepareExport(1, '00000000-0000-4000-8000-000000000010'))
        ->toThrow(ValidationException::class);
    expect($this->db->table('TblSmsExports')->count())->toBe(0);
    expect((int) $this->db->table('TblMarketing')->sum('SMS_Drops'))->toBe(0);
});

test('phone sync refuses a non-sqlsrv target before any replacement', function () {
    $command = new \Cmd\Reports\Console\Commands\SyncPhoneNumbers;
    expect(fn () => (new ReflectionMethod($command, 'initializeSqlServerConnection'))->invoke($command))
        ->toThrow(RuntimeException::class, 'sqlsrv connection');
});

test('merges one-phone and five-phone sources without losing E2-only leads or duplicate E phones', function () {
    smsFixture($this->db, 1, 'T1', '2026-10-05', ['2025550101']);
    $this->db->table('TblMailersUniqueEnriched')->insert([
        'PK' => 999, 'Drop_Name' => 'DROP1', 'External_ID' => 'EXT100',
        'Client' => 'Sample Person', 'Address' => 'Sample address', 'Phone' => '2025550102',
    ]);
    $this->db->table('TblMailersUniqueEnriched2')->insert([
        'PK' => 1, 'Drop_Name' => 'DROP1', 'External_ID' => 'EXT100',
        'Client' => 'Jane Other', 'Address' => 'Sample address', 'Debt_Amount' => 10000,
        'phone1' => '2025550103', 'phone2' => '2025550101',
    ]);
    $this->db->table('TblMailersUniqueEnriched2')->insert([
        'PK' => 2, 'Drop_Name' => 'DROP1', 'External_ID' => 'E2-ONLY',
        'Client' => 'Only Here', 'Address' => 'Another address', 'Debt_Amount' => 20000,
        'phone1' => '2025550104',
    ]);
    expect((int) $this->repo->selectDrops(2)->first()->Amount_Dropped)->toBe(4);
    $export = $this->repo->prepareExport(2, '00000000-0000-4000-8000-000000000201');
    try {
        $records = array_map('str_getcsv', file($export['path'], FILE_IGNORE_NEW_LINES));
        expect($export['count'])->toBe(4);
        expect($records[0])->toBe(['First name','address','debt load','phone1','phone2','phone3','phone4','phone5','send date']);
        expect($records[1][0])->toBe('ONLY');
        expect($records[2])->toBe(['JANE','SAMPLE ADDRESS','10000','2025550101','2025550102','2025550103','','','2026-10-06']);
    } finally { unlink($export['path']); }
});

test('a contacted number excludes its entire merged lead', function () {
    smsFixture($this->db, 1, 'T1', '2026-10-05', ['2025550101', '2025550199']);
    $this->db->table('TblMailersUniqueEnriched2')->insert([
        'PK' => 1, 'Drop_Name' => 'DROP1', 'External_ID' => 'EXT100',
        'Client' => 'Sample Person', 'Address' => 'Sample address', 'Debt_Amount' => 10000,
        'phone1' => '2025550102',
    ]);
    $this->db->table('TblPhoneNumbers')->insert(['Phone' => '12025550102']);
    expect((int) $this->repo->selectDrops(1)->first()->Amount_Dropped)->toBe(1);
    $export = $this->repo->prepareExport(1, '00000000-0000-4000-8000-000000000202');
    try { expect(file_get_contents($export['path']))->toContain('2025550199')->not->toContain('2025550101')->not->toContain('2025550102'); }
    finally { unlink($export['path']); }
});

test('six distinct phones use continuation rows so no eligible phone is lost', function () {
    smsFixture($this->db, 1, 'T1', '2026-10-05', ['2025550101']);
    $this->db->table('TblMailersUniqueEnriched2')->insert([
        'PK' => 1, 'Drop_Name' => 'DROP1', 'External_ID' => 'EXT100',
        'Client' => 'Sample Person', 'Address' => 'Sample address', 'Debt_Amount' => 10000,
        'phone1' => '2025550102', 'phone2' => '2025550103', 'phone3' => '2025550104',
        'phone4' => '2025550105', 'phone5' => '2025550106',
    ]);
    expect((int) $this->repo->selectDrops(2)->first()->Amount_Dropped)->toBe(6);
    $export = $this->repo->prepareExport(2, '00000000-0000-4000-8000-000000000203');
    try {
        $records = array_map('str_getcsv', file($export['path'], FILE_IGNORE_NEW_LINES));
        expect($records)->toHaveCount(3);
        expect($export['count'])->toBe(6);
        expect((int) $this->db->table('TblSmsExports')->value('SMS_Count'))->toBe(6);
        expect(array_merge(array_slice($records[1], 3, 5), array_slice($records[2], 3, 5)))
            ->toContain('2025550101','2025550102','2025550103','2025550104','2025550105','2025550106');
    } finally { unlink($export['path']); }
});

test('conflicting identity fails before export tracking changes', function () {
    smsFixture($this->db, 1, 'T1', '2026-10-05', ['2025550101']);
    $this->db->table('TblMailersUniqueEnriched2')->insert([
        'PK' => 1, 'Drop_Name' => 'DROP1', 'External_ID' => 'EXT100',
        'Client' => 'Other Person', 'Address' => 'Different address', 'Debt_Amount' => 10000,
        'phone1' => '2025550102',
    ]);
    expect(fn () => $this->repo->prepareExport(1, '00000000-0000-4000-8000-000000000204'))
        ->toThrow(ValidationException::class);
    expect($this->db->table('TblSmsExports')->count())->toBe(0);
});

test('duplicate one-phone identities with different names cannot be conflated', function () {
    smsFixture($this->db, 1, 'T1', '2026-10-05', ['2025550101']);
    $this->db->table('TblMailersUniqueEnriched')->insert([
        'PK' => 999, 'Drop_Name' => 'DROP1', 'External_ID' => 'EXT100',
        'Client' => 'Different Person', 'Address' => 'Sample address', 'Phone' => '2025550102',
    ]);
    expect(fn () => $this->repo->prepareExport(1, '00000000-0000-4000-8000-000000000205'))
        ->toThrow(ValidationException::class);
    expect($this->db->table('TblSmsExports')->count())->toBe(0);
});

test('missing E2 debt retains the amount from the one-phone source', function () {
    smsFixture($this->db, 1, 'T1', '2026-10-05', ['2025550101']);
    $this->db->table('TblMailersUniqueEnriched2')->insert([
        'PK' => 1, 'Drop_Name' => 'DROP1', 'External_ID' => 'EXT100',
        'Client' => 'Sample Person', 'Address' => 'Sample address', 'Debt_Amount' => null,
        'phone1' => '2025550102',
    ]);
    $export = $this->repo->prepareExport(1, '00000000-0000-4000-8000-000000000206');
    try {
        $records = array_map('str_getcsv', file($export['path'], FILE_IGNORE_NEW_LINES));
        expect($records[1][2])->toBe('10000');
    } finally { unlink($export['path']); }
});

test('SMS storage reuses the existing CMD S3 disk when no dedicated bucket is set', function () {
    $previous = getenv('CMD_SMS_EXPORT_BUCKET');
    $previousRegion = getenv('CMD_SMS_EXPORT_REGION');
    $previousDisk = config('filesystems.disks.s3');
    $previousSms = config('sms-exports');
    putenv('CMD_SMS_EXPORT_BUCKET');
    putenv('CMD_SMS_EXPORT_REGION');
    config()->set('filesystems.disks.s3.bucket', 'existing-cmd-bucket');
    config()->set('filesystems.disks.s3.region', 'us-east-2');
    try {
        $settings = require __DIR__.'/../../config/sms-exports.php';
        expect($settings['bucket'])->toBe('existing-cmd-bucket');
        expect($settings['region'])->toBe('us-east-2');
        expect($settings['prefix'])->toBe('sms-exports');
        // mergeConfigFrom is skipped with Laravel config:cache. The artifact
        // service must still use the host's cached S3 disk settings.
        config()->set('sms-exports', ['driver' => 's3']);
        expect((new \Cmd\Reports\Services\SmsExportArtifacts)->configured())->toBeTrue();
        // Older cached package config can retain a different default region.
        config()->set('sms-exports', ['driver' => 's3', 'bucket' => null, 'region' => 'us-west-1']);
        $artifacts = new \Cmd\Reports\Services\SmsExportArtifacts;
        $region = new ReflectionMethod($artifacts, 'region');
        expect($region->invoke($artifacts))->toBe('us-east-2');
        putenv('CMD_SMS_EXPORT_BUCKET=dedicated-bucket');
        $dedicated = require __DIR__.'/../../config/sms-exports.php';
        expect($dedicated['region'])->toBeNull();
        $dedicated['driver'] = 's3';
        config()->set('sms-exports', $dedicated);
        expect((new \Cmd\Reports\Services\SmsExportArtifacts)->configured())->toBeFalse();
    } finally {
        config()->set('filesystems.disks.s3', $previousDisk);
        config()->set('sms-exports', $previousSms);
        if ($previous === false) putenv('CMD_SMS_EXPORT_BUCKET');
        else putenv('CMD_SMS_EXPORT_BUCKET='.$previous);
        if ($previousRegion === false) putenv('CMD_SMS_EXPORT_REGION');
        else putenv('CMD_SMS_EXPORT_REGION='.$previousRegion);
    }
});
