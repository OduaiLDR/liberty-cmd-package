<?php

declare(strict_types=1);

use Carbon\Carbon;
use Cmd\Reports\Http\Controllers\SmsRunController;
use Cmd\Reports\Repositories\SmsProspectExportRepository;
use Cmd\Reports\Repositories\SmsRecordExportRepository;
use Cmd\Reports\Services\SmsDurableAvailabilityService;
use Cmd\Reports\Services\SmsRunOptions;
use Cmd\Reports\Services\SmsRunService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

// Synthetic in-memory SQL and uniquely owned temporary artifacts only. Source
// pricing is stubbed here; the separate real-SQL fixture tests the waterfall.
beforeEach(function (): void {
    $this->previousContainer = Container::getInstance();
    $container = new Container;
    Container::setInstance($container);
    $this->versionedDirectory = sys_get_temp_dir().'/sms-versioned-test-'.bin2hex(random_bytes(8));
    mkdir($this->versionedDirectory, 0700);
    $container->instance('config', new Repository(['app' => ['env' => 'testing', 'url' => 'https://local.invalid'],
        'sms-exports' => ['record_workflow_enabled' => true, 'durable_availability_enabled' => true,
            'run_file_options_enabled' => true, 'local_root' => $this->versionedDirectory],
        'database' => ['default' => 'sqlsrv']]));
    $capsule = new Manager($container);
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:'], 'sqlsrv');
    $capsule->getDatabaseManager()->setDefaultConnection('sqlsrv');
    $container->instance('db', $capsule->getDatabaseManager());
    $container->instance('validator', new Factory(new Translator(new ArrayLoader, 'en'), $container));
    Facade::clearResolvedInstances(); Facade::setFacadeApplication($container);
    $this->db = $capsule->getConnection('sqlsrv');
    $this->db->setTransactionManager(new DatabaseTransactionsManager);
    $this->db->getSchemaBuilder()->create('TblPhoneNumbers', fn (Blueprint $table) => $table->string('Phone'));
    foreach (['2026_10_12_000001_create_sms_prospects_tables.php', '2026_10_19_000001_complete_sms_workflow.php',
        '2026_10_20_000001_create_sms_availability_summary.php', '2026_10_20_000003_add_sms_record_approval.php',
        '2026_10_22_000001_add_sms_run_file_options.php'] as $file) {
        (require dirname(__DIR__, 2).'/database/sms-migrations/'.$file)->up();
    }
    $summary = Mockery::mock(SmsDurableAvailabilityService::class);
    $summary->shouldReceive('request')->andReturn(['state' => 'pending']);
    $container->instance(SmsDurableAvailabilityService::class, $summary);
    $container->instance(SmsRecordExportRepository::class, new SmsRecordExportRepository);
    $export = new class extends SmsProspectExportRepository {
        public function lookupDebts(string $id, array $debts = [], ?callable $progress = null): array
        {
            $this->table('TblProspectsExport')->where('Request_ID', $id)->update([
                'Debt_Amount' => 30000, 'Debt_Tier' => 'T5', 'Debt_Source' => 'TblMailers', 'Debt_Source_PK' => 123,
            ]);
            return [];
        }
    };
    $this->service = new class($export) extends SmsRunService {
        public array $queued = [];
        public bool $interruptPublication = false;
        public bool $interruptCleanup = false;
        public bool $interruptBeforeApprovalFiles = false;
        public bool $interruptAfterApprovalFiles = false;
        public bool $interruptAllocationCommit = false;
        public ?string $clockAfterFileVerification = null;
        public array $importQueueRequests = [];
        public array $automaticQueueRequests = [];
        protected function requestAutomaticHandoff(string $id): void
        {
            if (config('sms-exports.handoff_automatic') !== true || config('sms-exports.file_publication_enabled') !== true
                || config('sms-exports.email_handoff_enabled') !== true) return;
            expect(DB::connection('sqlsrv')->transactionLevel())->toBe(0);
            $this->automaticQueueRequests[] = $id;
        }
        public function requestRealAutomaticQueueForTest(string $id): void { parent::requestAutomaticHandoff($id); }
        protected function requestDebtPleteImport(string $id): void
        {
            if (config('sms-exports.debtplete_import_enabled') !== true || config('sms-exports.debtplete_import_automatic') !== true) return;
            expect(DB::connection('sqlsrv')->transactionLevel())->toBe(0);
            $this->importQueueRequests[] = $id;
        }
        public function requestRealImportQueueForTest(string $id): void { parent::requestDebtPleteImport($id); }
        protected function dispatch(string $id, string $job): void { $this->queued[] = [$id, $job]; }
        protected function move(string $id, array $from, string $to, array $values = []): bool
        {
            if (($values['Progress_Step'] ?? null) === 'finalizing_files' && $this->interruptAllocationCommit) {
                $this->interruptAllocationCommit = false;
                throw new RuntimeException('Synthetic interruption before SMS allocation commit.');
            }
            if ($to === 'file_ready' && $this->interruptPublication) {
                $this->interruptPublication = false;
                throw new RuntimeException('Synthetic interruption after artifact publication.');
            }
            return parent::move($id, $from, $to, $values);
        }
        public function cleanupDeletedFile(string $id): void
        {
            if ($this->interruptCleanup) throw new RuntimeException('Synthetic cleanup interruption.');
            parent::cleanupDeletedFile($id);
        }
        protected function publishApprovedFiles(string $requestId, array $manifest, array $options, int $expected, string $range): array
        {
            if ($this->interruptBeforeApprovalFiles) {
                $this->interruptBeforeApprovalFiles = false;
                throw new RuntimeException('Synthetic interruption after SMS ID allocation.');
            }
            $bundle = parent::publishApprovedFiles($requestId, $manifest, $options, $expected, $range);
            if ($this->interruptAfterApprovalFiles) {
                $this->interruptAfterApprovalFiles = false;
                throw new RuntimeException('Synthetic interruption after final SMS files.');
            }
            return $bundle;
        }
        public function filePath(string $requestId): string
        {
            $path = parent::filePath($requestId);
            if ($this->clockAfterFileVerification !== null) {
                Carbon::setTestNow(Carbon::parse($this->clockAfterFileVerification));
                $this->clockAfterFileVerification = null;
            }
            return $path;
        }
    };
    $this->controller = new SmsRunController($this->service);
    $this->id = '11111111-2222-4333-8444-555555555555';
    $this->otherId = '22222222-3333-4444-8555-666666666666';
    $this->fileOptions = SmsRunOptions::normalize(7, 3);
    $this->seedMembers = function (int $count = 5): void {
        for ($i = 0; $i < $count; $i++) {
            $this->db->table('TblProspects')->insert(['Client' => $i === 0 ? '=Synthetic Person' : 'Synthetic Person'.$i,
                'Address' => '1, Fixture St', 'City' => 'Test', 'State' => 'CA', 'Zip' => '00123',
                'Phone' => '0012345600', 'Creation_Date' => '2026-10-01']);
        }
    };
    $this->prepareVersioned = function (): void {
        ($this->seedMembers)();
        $this->service->start($this->id, 7, 'editor@example.invalid', $this->fileOptions);
        $this->service->prepare($this->id);
    };
    $this->prepareApprovalVersion = function (): void {
        config(['sms-exports.approval_only_creation_enabled' => true]);
        $this->fileOptions = SmsRunOptions::normalize(7, 3, null, null, SmsRunOptions::PLANNED_DATE_VERSION, '2026-10-26', SmsRunOptions::APPROVAL_FILENAME_VERSION);
        ($this->prepareVersioned)();
        $first = $this->db->table('TblProspectsExport')->where('Request_ID', $this->id)->orderBy('Prospect_PK')->limit(2)->pluck('Prospect_PK')->all();
        $this->db->table('TblProspectsExport')->where('Request_ID', $this->id)->whereIn('Prospect_PK', $first)->update(['Debt_Tier' => 'T6', 'Debt_Amount' => 42000]);
        $this->service->requestFile($this->id); $this->service->buildFile($this->id);
    };
    $this->makeFile = function (): string {
        ($this->prepareVersioned)();
        $this->service->requestFile($this->id);
        $this->service->buildFile($this->id);
        return $this->service->filePath($this->id);
    };
    Carbon::setTestNow(Carbon::parse('2026-10-20T00:30:00Z'));
});

afterEach(function (): void {
    Mockery::close(); Carbon::setTestNow();
    // Resolve and validate this exact newly created temp root before recursive cleanup.
    $root = realpath($this->versionedDirectory);
    $temp = realpath(sys_get_temp_dir());
    if ($root !== false && $temp !== false && ! is_link($this->versionedDirectory)
        && str_starts_with(str_replace('\\', '/', $root), str_replace('\\', '/', $temp).'/sms-versioned-test-')) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            if ($file->isDir() && ! $file->isLink()) rmdir($file->getPathname()); else unlink($file->getPathname());
        }
        rmdir($root);
    }
    Facade::clearResolvedInstances(); Container::setInstance($this->previousContainer); Facade::setFacadeApplication($this->previousContainer);
});

test('versioned starts persist all options and compare the complete request on replay', function (): void {
    $first = $this->service->start($this->id, 7, 'editor@example.invalid', $this->fileOptions);
    expect($first->options)->toBe($this->fileOptions)->and($first->status)->toBe('queued');
    expect(json_decode($this->db->table('TblSMSRuns')->value('Run_Options_JSON'), true))->toBe($this->fileOptions);
    $this->service->start($this->id, 7, 'editor@example.invalid', $this->fileOptions);
    expect($this->service->queued)->toHaveCount(1);
    expect(fn () => $this->service->start($this->id, 7, 'editor@example.invalid', SmsRunOptions::normalize(7, 4)))->toThrow(HttpException::class);
    expect(fn () => $this->service->start($this->id, 7, 'editor@example.invalid', SmsRunOptions::normalize(7, 3, ['T2'])))->toThrow(HttpException::class);
    expect(fn () => $this->service->start($this->id, 7, 'editor@example.invalid'))->toThrow(HttpException::class);
    expect((int) $this->db->table('TblSMSRuns')->count())->toBe(1);
});

test('creation flag changes do not reinterpret a versioned retry or old optionless run', function (): void {
    $this->service->start($this->id, 7, 'editor@example.invalid', $this->fileOptions);
    config(['sms-exports.run_file_options_enabled' => false]);
    expect($this->service->start($this->id, 7, 'editor@example.invalid', $this->fileOptions)->options)->toBe($this->fileOptions);
    expect($this->service->overview()['capabilities'])->toBeNull();
    expect(fn () => $this->service->start($this->otherId, 7, 'editor@example.invalid', $this->fileOptions))->toThrow(HttpException::class);
    $this->db->table('TblSMSRuns')->where('Request_ID', $this->id)->update(['Status' => 'discarded']);
    config(['sms-exports.run_file_options_enabled' => true]);
    $legacy = $this->service->start($this->otherId, 7, 'editor@example.invalid');
    expect($legacy->options)->toBeNull()->and($this->service->overview()['recent'][0]->options)->toBe($this->fileOptions);
});

test('capability requires the complete additive schema and explicit new requests fail before writes', function (): void {
    expect($this->service->overview()['capabilities'])->toBe([
        'csv_format' => SmsRunOptions::CSV_FORMAT, 'rows_per_file' => true, 'tier_subset' => false, 'max_parts' => 1000,
        'options_version' => SmsRunOptions::PLANNED_DATE_VERSION, 'send_date' => true, 'default_send_date' => '2026-10-26',
        'min_send_date' => '2026-10-19', 'send_date_timezone' => 'America/Los_Angeles',
        'filename_version' => SmsRunOptions::APPROVAL_FILENAME_VERSION,
    ]);
    $this->db->getSchemaBuilder()->table('TblSMSRuns', fn (Blueprint $table) => $table->dropColumn('Artifact_Manifest_JSON'));
    expect($this->service->overview()['capabilities'])->toBeNull();
    expect(fn () => $this->service->start($this->id, 7, 'editor@example.invalid', $this->fileOptions))->toThrow(HttpException::class);
    expect($this->db->table('TblSMSRuns')->count())->toBe(0)->and($this->service->queued)->toBe([]);
    expect($this->service->start($this->id, 7, 'editor@example.invalid')->options)->toBeNull();
});

test('planned-date controller freezes the Monday default once and unchanged retries survive calendar rollover', function (): void {
    $request = Request::create('/', 'POST', ['request_id' => $this->id, 'target' => 7,
        'csv_format' => SmsRunOptions::CSV_FORMAT, 'rows_per_file' => 3, 'options_version' => SmsRunOptions::PLANNED_DATE_VERSION]);
    $request->attributes->set('cmd_user', ['email' => 'editor@example.invalid']);
    $first = $this->controller->store($request)->getData(true);
    expect($first['options']['send_date'])->toBe('2026-10-26')->and($first['options']['options_version'])->toBe(SmsRunOptions::PLANNED_DATE_VERSION);
    Carbon::setTestNow(Carbon::parse('2026-11-05T18:00:00Z'));
    expect($this->controller->store($request)->getData(true)['options'])->toBe($first['options'])
        ->and($this->service->queued)->toHaveCount(1);
    foreach (['2026-10-27', '2026-11-09'] as $changed) {
        $options = array_replace($first['options'], ['send_date' => $changed]);
        expect(fn () => $this->service->start($this->id, 7, 'editor@example.invalid', $options))->toThrow(HttpException::class, 'different');
    }
});

test('explicit California today is accepted while new past malformed and unversioned dates fail before writes', function (): void {
    foreach (['2026-10-18', '2026-02-29', '2026-10-19T00:00:00Z', null] as $date) {
        $request = Request::create('/', 'POST', ['request_id' => $this->id, 'target' => 7,
            'csv_format' => SmsRunOptions::CSV_FORMAT, 'send_date' => $date]);
        $request->attributes->set('cmd_user', ['email' => 'editor@example.invalid']);
        expect(fn () => $this->controller->store($request))->toThrow(ValidationException::class);
        expect($this->db->table('TblSMSRuns')->count())->toBe(0);
    }
    $request = Request::create('/', 'POST', ['request_id' => $this->id, 'target' => 7, 'send_date' => '2026-10-19']);
    expect(fn () => $this->controller->store($request))->toThrow(ValidationException::class);
    $request = Request::create('/', 'POST', ['request_id' => $this->id, 'target' => 7,
        'csv_format' => SmsRunOptions::CSV_FORMAT, 'send_date' => '2026-10-19']);
    $request->attributes->set('cmd_user', ['email' => 'editor@example.invalid']);
    expect($this->controller->store($request)->getData(true)['options']['send_date'])->toBe('2026-10-19');
});

test('future non-Monday date remains frozen through later-week selection generation and approval', function (): void {
    $this->fileOptions = SmsRunOptions::normalize(7, 3, null, null, SmsRunOptions::PLANNED_DATE_VERSION, '2026-11-10');
    ($this->seedMembers)();
    $this->service->start($this->id, 7, 'editor@example.invalid', $this->fileOptions);
    Carbon::setTestNow(Carbon::parse('2026-11-03T18:00:00Z'));
    $this->service->prepare($this->id);
    expect($this->db->table('TblSMSDrops')->value('Drop_Date'))->toBe('2026-11-10');
    $this->service->requestFile($this->id); $this->service->buildFile($this->id);
    $view = $this->service->show($this->id);
    expect($view->file['name'])->toBe('SMS Export - 2026-11-10.zip')->and($view->file['files'])->toBe([
        ['name' => 'SMS Export - 2026-11-10 - Part 1 of 2.csv', 'rows' => 3],
        ['name' => 'SMS Export - 2026-11-10 - Part 2 of 2.csv', 'rows' => 2],
    ]);
    $path = $this->service->filePath($this->id); $before = hash_file('sha256', $path);
    $zip = new ZipArchive; expect($zip->open($path))->toBeTrue();
    try {
        foreach ($view->file['files'] as $part) {
            $stream = $zip->getStream($part['name']); fgetcsv($stream, escape: '');
            while (($row = fgetcsv($stream, escape: '')) !== false) expect($row[3])->toBe('2026-11-10');
            fclose($stream);
        }
    } finally { $zip->close(); }
    $this->service->requestApprove($this->id, 'editor@example.invalid'); $this->service->markApproved($this->id);
    expect($this->db->table('TblProspects')->value('Last_SMS_Date'))->toBe('2026-11-03')
        ->and($this->db->table('TblSMSDrops')->value('Drop_Date'))->toBe('2026-11-10')
        ->and(hash_file('sha256', $this->service->filePath($this->id)))->toBe($before);
});

test('unpublished planned date expires safely without silently changing membership dates', function (string $stage): void {
    $this->fileOptions = SmsRunOptions::normalize(7, 3, null, null, SmsRunOptions::PLANNED_DATE_VERSION, '2026-10-19');
    ($this->seedMembers)();
    $this->service->start($this->id, 7, 'editor@example.invalid', $this->fileOptions);
    if ($stage !== 'prepare') $this->service->prepare($this->id);
    if ($stage === 'build') $this->service->requestFile($this->id);
    Carbon::setTestNow(Carbon::parse('2026-10-20T18:00:00Z'));
    if ($stage === 'prepare') expect(fn () => $this->service->prepare($this->id))->toThrow(InvalidArgumentException::class, 'California');
    elseif ($stage === 'request') expect(fn () => $this->service->requestFile($this->id))->toThrow(HttpException::class, 'California');
    else expect(fn () => $this->service->buildFile($this->id))->toThrow(InvalidArgumentException::class, 'California');
    expect($this->db->table('TblSMSRuns')->value('File_Path'))->toBeNull()
        ->and($this->db->table('TblSMSDrops')->where('Drop_Date', '<>', '2026-10-19')->count())->toBe(0)
        ->and((int) $this->db->table('TblProspects')->sum('SMS_Attempts'))->toBe(0);
})->with(['prepare', 'request', 'build']);

test('published planned-date recovery adopts its exact name and bytes even after planned date expires', function (): void {
    $this->fileOptions = SmsRunOptions::normalize(7, 10, null, null, SmsRunOptions::PLANNED_DATE_VERSION, '2026-10-19');
    ($this->prepareVersioned)(); $this->service->requestFile($this->id); $this->service->interruptPublication = true;
    expect(fn () => $this->service->buildFile($this->id))->toThrow(RuntimeException::class, 'Synthetic interruption');
    $path = str_replace('\\', '/', $this->versionedDirectory).'/runs/'.$this->id.'/v1/published/SMS Export - 2026-10-19 - Part 1 of 1.csv';
    $before = hash_file('sha256', $path);
    Carbon::setTestNow(Carbon::parse('2026-11-10T18:00:00Z'));
    $this->service->buildFile($this->id);
    expect($this->service->filePath($this->id))->toBe($path)->and(hash_file('sha256', $path))->toBe($before)
        ->and($this->service->show($this->id)->file['files'])->toBe([['name' => basename($path), 'rows' => 5]]);
    // Old duplicate queue deliveries are no-ops, not new work subject to expiry.
    $this->service->prepare($this->id);
    expect($this->service->show($this->id)->status)->toBe('file_ready');
});

test('planned-date status and history expose only pure reconciled file metadata and honest pending handoff', function (): void {
    $this->fileOptions = SmsRunOptions::normalize(7, 3, null, null, SmsRunOptions::PLANNED_DATE_VERSION, '2026-11-10');
    $path = ($this->makeFile)();
    $view = $this->service->show($this->id);
    expect($view->handoff['email_preview']['available'])->toBeTrue()
        ->and($view->handoff['email']['status'])->toBe('not_configured')
        ->and($view->handoff['debtplete_import']['processed'])->toBeNull();
    $this->service->requestApprove($this->id, 'editor@example.invalid'); $this->service->markApproved($this->id);
    // Status/history are metadata-only; archive eviction does not make them read bytes.
    unlink($path);
    $recent = $this->service->history()['items'][0];
    expect($recent->file['files'])->toBe($view->file['files'])->and($recent->handoff)->toBe($view->handoff);
    $this->db->table('TblSMSRuns')->update(['File_Bytes' => 1]);
    expect(fn () => $this->service->history())->toThrow(HttpException::class, 'metadata');
});

test('opted-in drop range names use saved run drops not the next global counter and retain publication on recovery', function (): void {
    $this->fileOptions = SmsRunOptions::normalize(7, 3, null, null, SmsRunOptions::PLANNED_DATE_VERSION, '2026-10-26', SmsRunOptions::DROP_RANGE_FILENAME_VERSION);
    ($this->prepareVersioned)();
    $firstDrop = $this->db->table('TblSMSDrops')->where('Request_ID', $this->id)->value('PK');
    $this->db->table('TblSMSDrops')->where('PK', $firstDrop)->update(['Drop_Name' => 'SMS9999', 'Amount_Dropped' => 3]);
    $this->db->table('TblProspectsExport')->where('Request_ID', $this->id)->update(['Drop_Name' => 'SMS9999']);
    $secondDrop = $this->db->table('TblSMSDrops')->insertGetId(['Drop_Name' => 'SMS10000', 'Debt_Tier' => 'T2',
        'Amount_Dropped' => 2, 'Drop_Date' => '2026-10-26', 'Request_ID' => $this->id, 'Created_At' => '2026-10-20 00:30:00']);
    $members = $this->db->table('TblProspectsExport')->where('Request_ID', $this->id)->orderBy('Prospect_PK')->limit(2)->pluck('Prospect_PK')->all();
    $this->db->table('TblProspectsExport')->where('Request_ID', $this->id)->whereIn('Prospect_PK', $members)
        ->update(['Drop_Name' => 'SMS10000', 'SMS_Drop_PK' => $secondDrop]);
    // Unrelated next counters must not contaminate this run's assigned range.
    $this->db->table('TblSMSDrops')->insert(['Drop_Name' => 'SMS99999', 'Debt_Tier' => 'T2', 'Amount_Dropped' => 1, 'Drop_Date' => '2026-10-26', 'Request_ID' => $this->otherId, 'Created_At' => '2026-10-20 00:30:00']);
    expect($this->service->start($this->id, 7, 'editor@example.invalid', $this->fileOptions)->options)->toBe($this->fileOptions);
    $withoutMarker = $this->fileOptions; unset($withoutMarker['filename_version']);
    expect(fn () => $this->service->start($this->id, 7, 'editor@example.invalid', $withoutMarker))->toThrow(HttpException::class, 'different');
    $this->service->requestFile($this->id); $this->service->interruptPublication = true;
    expect(fn () => $this->service->buildFile($this->id))->toThrow(RuntimeException::class, 'Synthetic interruption');
    Carbon::setTestNow(Carbon::parse('2026-11-05T18:00:00Z'));
    $this->service->buildFile($this->id);
    $view = $this->service->show($this->id);
    expect($view->file['name'])->toBe('SMS Export - 2026-10-26 - SMS9999-SMS10000.zip')
        ->and($view->file['drop_name_range'])->toBe('SMS9999-SMS10000')
        ->and($view->file['files'])->toBe([
            ['name' => 'SMS Export - 2026-10-26 - SMS9999-SMS10000 - Part 1 of 2.csv', 'rows' => 3],
            ['name' => 'SMS Export - 2026-10-26 - SMS9999-SMS10000 - Part 2 of 2.csv', 'rows' => 2],
        ]);
});

test('drop-range filename markers fail closed without the planned contract or for changed malformed versions', function (): void {
    foreach ([['filename_version' => SmsRunOptions::DROP_RANGE_FILENAME_VERSION], ['options_version' => SmsRunOptions::PLANNED_DATE_VERSION, 'filename_version' => 'unknown'],
        ['options_version' => SmsRunOptions::PLANNED_DATE_VERSION, 'filename_version' => null]] as $extra) {
        $request = Request::create('/', 'POST', ['request_id' => $this->id, 'target' => 7, 'csv_format' => SmsRunOptions::CSV_FORMAT] + $extra);
        $request->attributes->set('cmd_user', ['email' => 'editor@example.invalid']);
        expect(fn () => $this->controller->store($request))->toThrow(ValidationException::class);
        expect($this->db->table('TblSMSRuns')->count())->toBe(0);
    }
});

test('approval-time files remain ID Pending until approval and preserve every CSV part byte after final naming', function (): void {
    ($this->prepareApprovalVersion)();
    $pending = $this->service->show($this->id); $pendingPath = $this->service->filePath($this->id);
    expect($pending->id_status)->toBe('pending')->and($pending->approval_phase)->toBe('awaiting_approval')
        ->and($pending->drops)->toBe([])->and($pending->file['drop_name_range'])->toBeNull()
        ->and($pending->file['name'])->toBe('SMS Export - 2026-10-26 - ID Pending.zip')
        ->and($this->db->table('TblSMSDrops')->count())->toBe(0)
        ->and($this->db->table('TblProspectsExport')->whereNotNull('SMS_Drop_PK')->count())->toBe(0)
        ->and($this->db->table('TblProspectsExport')->whereNotNull('Drop_Name')->count())->toBe(0);
    $pendingManifest = json_decode($this->db->table('TblSMSRuns')->value('Artifact_Manifest_JSON'), true);
    $this->service->requestApprove($this->id, 'editor@example.invalid');
    expect($this->service->show($this->id)->approval_phase)->toBe('assigning_ids')
        ->and($this->db->table('TblSMSDrops')->count())->toBe(0);
    expect(fn () => $this->service->filePath($this->id))->toThrow(HttpException::class, 'finalized');
    Carbon::setTestNow(Carbon::parse('2026-11-05T18:00:00Z'));
    $this->service->markApproved($this->id);
    $final = $this->service->show($this->id); $path = $this->service->filePath($this->id);
    $manifest = json_decode($this->db->table('TblSMSRuns')->value('Artifact_Manifest_JSON'), true);
    expect($final->id_status)->toBe('assigned')->and($final->approval_phase)->toBe('complete')
        ->and($final->file['drop_name_range'])->toBe('SMS0001-SMS0002')
        ->and($final->file['name'])->toBe('SMS Export - 2026-10-26 - SMS0001-SMS0002.zip')
        ->and($final->file['files'])->toBe([
            ['name' => 'SMS Export - 2026-10-26 - SMS0001-SMS0002 - Part 1 of 2.csv', 'rows' => 3],
            ['name' => 'SMS Export - 2026-10-26 - SMS0001-SMS0002 - Part 2 of 2.csv', 'rows' => 2],
        ])
        ->and(array_column($manifest['parts'], 'sha256'))->toBe(array_column($pendingManifest['parts'], 'sha256'))
        ->and($this->db->table('TblSMSDrops')->count())->toBe(2)
        ->and($this->db->table('TblSMSHistory')->count())->toBe(5)
        ->and((int) $this->db->table('TblProspects')->sum('SMS_Attempts'))->toBe(5)
        ->and($this->db->table('TblProspects')->value('Last_SMS_Date'))->toBe('2026-10-19')
        ->and(is_file($pendingPath))->toBeTrue()->and(str_contains($path, '/v2/published/'))->toBeTrue();
    $this->service->markApproved($this->id);
    expect((int) $this->db->table('TblProspects')->sum('SMS_Attempts'))->toBe(5)->and($this->db->table('TblSMSDrops')->count())->toBe(2);
});

test('deleting a pending approval-time export consumes no SMS IDs and no attempts', function (): void {
    ($this->prepareApprovalVersion)();
    $this->service->discard($this->id);
    expect($this->db->table('TblSMSDrops')->count())->toBe(0)->and($this->db->table('TblSMSHistory')->count())->toBe(0)
        ->and($this->db->table('TblProspectsExport')->count())->toBe(0)->and((int) $this->db->table('TblProspects')->sum('SMS_Attempts'))->toBe(0);
});

test('new approval requests reject expired planned dates before accepting approval or IDs', function (): void {
    ($this->prepareApprovalVersion)();
    Carbon::setTestNow(Carbon::parse('2026-10-27T18:00:00Z'));
    expect(fn () => $this->service->requestApprove($this->id, 'editor@example.invalid'))->toThrow(HttpException::class, 'California');
    expect($this->db->table('TblSMSRuns')->value('Approved_At'))->toBeNull()->and($this->db->table('TblSMSDrops')->count())->toBe(0);
});

test('approval expiry is rechecked against captured California acceptance time after a slow preflight crosses midnight', function (): void {
    ($this->prepareApprovalVersion)();
    Carbon::setTestNow(Carbon::parse('2026-10-27T06:59:59Z'));
    $this->service->clockAfterFileVerification = '2026-10-27T07:00:01Z';
    expect(fn () => $this->service->requestApprove($this->id, 'editor@example.invalid'))->toThrow(HttpException::class, 'California');
    expect($this->db->table('TblSMSRuns')->value('Approved_At'))->toBeNull()
        ->and($this->db->table('TblSMSRuns')->value('Status'))->toBe('file_ready')
        ->and($this->db->table('TblSMSDrops')->count())->toBe(0)
        ->and((int) $this->db->table('TblProspects')->sum('SMS_Attempts'))->toBe(0);
});

test('direct drop allocation cannot consume IDs for an unapproved new pending export', function (): void {
    ($this->prepareApprovalVersion)();
    expect(fn () => (new SmsProspectExportRepository)->assignDrops($this->id, Carbon::parse('2026-10-26')))->toThrow(ValidationException::class);
    expect($this->db->table('TblSMSDrops')->count())->toBe(0)
        ->and($this->db->table('TblProspectsExport')->whereNotNull('SMS_Drop_PK')->count())->toBe(0);
});

test('approval allocation rollback leaves no IDs and replay uses the first available IDs once', function (): void {
    ($this->prepareApprovalVersion)(); $this->service->requestApprove($this->id, 'editor@example.invalid');
    $this->service->interruptAllocationCommit = true;
    expect(fn () => $this->service->markApproved($this->id))->toThrow(RuntimeException::class, 'allocation commit');
    expect($this->db->table('TblSMSDrops')->count())->toBe(0)
        ->and($this->db->table('TblProspectsExport')->whereNotNull('SMS_Drop_PK')->count())->toBe(0)
        ->and($this->db->table('TblSMSHistory')->count())->toBe(0)->and((int) $this->db->table('TblProspects')->sum('SMS_Attempts'))->toBe(0);
    $this->service->markApproved($this->id);
    expect($this->service->show($this->id)->file['drop_name_range'])->toBe('SMS0001-SMS0002');
});

test('approval crashes after allocation or final files resume same IDs and perform the business commit once', function (string $boundary): void {
    ($this->prepareApprovalVersion)(); $this->service->requestApprove($this->id, 'editor@example.invalid');
    if ($boundary === 'allocated') $this->service->interruptBeforeApprovalFiles = true;
    else $this->service->interruptAfterApprovalFiles = true;
    expect(fn () => $this->service->markApproved($this->id))->toThrow(RuntimeException::class, 'Synthetic interruption');
    $ids = $this->db->table('TblSMSDrops')->orderBy('PK')->pluck('Drop_Name', 'PK')->all();
    $accepted = $this->db->table('TblSMSRuns')->value('Approved_At');
    $view = $this->service->show($this->id);
    expect($view->approval_phase)->toBe('finalizing_files')->and($view->id_status)->toBe('assigned')
        ->and($view->file['drop_name_range'])->toBeNull()->and($view->file['name'])->toContain('ID Pending')
        ->and($this->db->table('TblSMSDrops')->count())->toBe(2)->and($this->db->table('TblSMSHistory')->count())->toBe(0)
        ->and($this->db->table('TblProspectsExport')->count())->toBe(5)->and((int) $this->db->table('TblProspects')->sum('SMS_Attempts'))->toBe(0);
    $this->service->fail($this->id, 'approve', 'Synthetic worker failure');
    expect(fn () => $this->service->discard($this->id))->toThrow(HttpException::class);
    $this->service->retry($this->id); $this->service->markApproved($this->id); $this->service->markApproved($this->id);
    expect($this->db->table('TblSMSDrops')->orderBy('PK')->pluck('Drop_Name', 'PK')->all())->toBe($ids)
        ->and($this->db->table('TblSMSRuns')->value('Approved_At'))->toBe($accepted)
        ->and($this->db->table('TblSMSHistory')->count())->toBe(5)->and((int) $this->db->table('TblProspects')->sum('SMS_Attempts'))->toBe(5);
})->with(['allocated', 'published']);

test('final approval transaction rollback keeps allocated IDs and published files but rolls back every attempt and history row', function (): void {
    ($this->prepareApprovalVersion)(); $this->service->requestApprove($this->id, 'editor@example.invalid');
    $this->db->statement("CREATE TRIGGER block_final_approval BEFORE UPDATE OF Status ON TblSMSRuns WHEN NEW.Status='approved' BEGIN SELECT RAISE(ABORT, 'Synthetic final approval failure'); END");
    expect(fn () => $this->service->markApproved($this->id))->toThrow(\Illuminate\Database\QueryException::class);
    expect($this->db->table('TblSMSDrops')->count())->toBe(2)->and($this->db->table('TblSMSHistory')->count())->toBe(0)
        ->and($this->db->table('TblProspectsExport')->count())->toBe(5)->and((int) $this->db->table('TblProspects')->sum('SMS_Attempts'))->toBe(0)
        ->and($this->service->show($this->id)->file['name'])->toContain('ID Pending');
    $this->db->statement('DROP TRIGGER block_final_approval');
    $this->service->markApproved($this->id);
    expect($this->db->table('TblSMSDrops')->count())->toBe(2)->and($this->db->table('TblSMSHistory')->count())->toBe(5)
        ->and((int) $this->db->table('TblProspects')->sum('SMS_Attempts'))->toBe(5);
});

test('partial saved allocation is rejected without issuing more IDs or business updates', function (): void {
    ($this->prepareApprovalVersion)(); $this->service->requestApprove($this->id, 'editor@example.invalid');
    $this->service->interruptBeforeApprovalFiles = true;
    expect(fn () => $this->service->markApproved($this->id))->toThrow(RuntimeException::class);
    $member = $this->db->table('TblProspectsExport')->value('PK');
    $this->db->table('TblProspectsExport')->where('PK', $member)->update(['SMS_Drop_PK' => null]);
    expect(fn () => $this->service->markApproved($this->id))->toThrow(RuntimeException::class, 'does not reconcile');
    expect($this->db->table('TblSMSDrops')->count())->toBe(2)->and($this->db->table('TblSMSHistory')->count())->toBe(0)
        ->and((int) $this->db->table('TblProspects')->sum('SMS_Attempts'))->toBe(0);
});

test('post-commit callback failure cannot duplicate final approval business updates', function (): void {
    ($this->prepareApprovalVersion)(); $this->service->requestApprove($this->id, 'editor@example.invalid');
    $interrupt = true;
    $this->db->beforeExecuting(function (string $sql, array $bindings) use (&$interrupt): void {
        if ($interrupt && str_starts_with($sql, 'update') && str_contains($sql, 'TblSMSRuns') && in_array('approved', $bindings, true)) {
            $interrupt = false;
            $this->db->afterCommit(static fn () => throw new RuntimeException('Synthetic callback failure after committed approval.'));
        }
    });
    expect(fn () => $this->service->markApproved($this->id))->toThrow(RuntimeException::class, 'callback failure');
    expect($this->service->show($this->id)->status)->toBe('approved');
    $this->service->markApproved($this->id);
    expect($this->db->table('TblSMSHistory')->count())->toBe(5)->and((int) $this->db->table('TblProspects')->sum('SMS_Attempts'))->toBe(5)
        ->and($this->db->table('TblSMSDrops')->count())->toBe(2);
});

test('unsupported subsets excessive parts and malformed options create no run', function (): void {
    foreach ([SmsRunOptions::normalize(7, 3, ['T2']), SmsRunOptions::normalize(1001, 1),
        ['csv_format' => 'unknown'], ['csv_format' => SmsRunOptions::CSV_FORMAT, 'rows_per_file' => 0]] as $options) {
        expect(fn () => $this->service->start($this->id, $options['target'] ?? 7, 'editor@example.invalid', $options))->toThrow(ValidationException::class);
    }
    expect($this->db->table('TblSMSRuns')->count())->toBe(0)->and($this->service->queued)->toBe([]);
});

test('controller validates explicit format cap and tiers instead of ignoring new fields', function (): void {
    foreach ([['rows_per_file' => 3], ['csv_format' => 'unknown'], ['csv_format' => SmsRunOptions::CSV_FORMAT, 'rows_per_file' => 0],
        ['csv_format' => SmsRunOptions::CSV_FORMAT, 'tiers' => []], ['csv_format' => SmsRunOptions::CSV_FORMAT, 'tiers' => ['T1', 'T1']]] as $extra) {
        $request = Request::create('/', 'POST', $extra + ['request_id' => $this->id, 'target' => 7]);
        $request->attributes->set('cmd_user', ['email' => 'editor@example.invalid']);
        expect(fn () => $this->controller->store($request))->toThrow(ValidationException::class);
    }
    expect($this->db->table('TblSMSRuns')->count())->toBe(0);
    $request = Request::create('/', 'POST', ['request_id' => $this->id, 'target' => 7,
        'csv_format' => SmsRunOptions::CSV_FORMAT, 'rows_per_file' => 3]);
    $request->attributes->set('cmd_user', ['email' => 'editor@example.invalid']);
    $response = $this->controller->store($request);
    expect($response->getStatusCode())->toBe(202)->and($response->getData(true)['options'])->toBe($this->fileOptions);
});

test('selected count drives ZIP parts while generation preserves prospects and frozen four-column data', function (): void {
    $path = ($this->makeFile)();
    $view = $this->service->show($this->id);
    expect($view->selected)->toBe(5)->and($view->shortfall)->toBe(2)
        ->and($view->file['format'])->toBe('zip')->and($view->file['parts'])->toBe(2);
    $zip = new ZipArchive; expect($zip->open($path))->toBeTrue();
    $phones = []; $counts = [];
    try {
        expect($zip->numFiles)->toBe(2);
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stream = $zip->getStream($zip->getNameIndex($index));
            expect(fgetcsv($stream, escape: ''))->toBe(['First name', 'debt load', 'phone1', 'send date']);
            $count = 0;
            while (($row = fgetcsv($stream, escape: '')) !== false) {
                expect($row)->toHaveCount(4)->and($row[1])->toBe('30000')->and($row[2])->toBe('0012345600')->and($row[3])->toBe('2026-10-26');
                if ($index === 0 && $count === 0) expect($row[0])->toBe("'=Synthetic");
                $phones[] = $row[2]; $count++;
            }
            fclose($stream); $counts[] = $count;
        }
    } finally { $zip->close(); }
    expect($counts)->toBe([3, 2])->and($phones)->toHaveCount(5);
    expect((int) $this->db->table('TblProspects')->sum('SMS_Attempts'))->toBe(0)
        ->and($this->db->table('TblProspects')->whereNotNull('Last_SMS_Date')->count())->toBe(0)
        ->and($this->db->table('TblSMSHistory')->count())->toBe(0);
    expect($this->controller->download($this->id)->headers->get('Content-Type'))->toBe('application/zip');
});

test('versioned single CSV ignores mutable legacy format settings and keeps original file bytes', function (): void {
    $this->fileOptions = SmsRunOptions::normalize(7, 10);
    ($this->prepareVersioned)();
    config(['sms-exports.single_phone_csv' => true, 'sms-exports.run_file_options_enabled' => false]);
    $this->service->requestFile($this->id); $this->service->buildFile($this->id);
    $path = $this->service->filePath($this->id);
    expect(pathinfo($path, PATHINFO_EXTENSION))->toBe('csv');
    $before = hash_file('sha256', $path);
    config(['sms-exports.single_phone_csv' => false]);
    expect(hash_file('sha256', $this->service->filePath($this->id)))->toBe($before);
    $stream = fopen($path, 'rb');
    try { expect(fgetcsv($stream, escape: ''))->toBe(['First name', 'debt load', 'phone1', 'send date']); }
    finally { fclose($stream); }
});

test('target-only callers retain the original record CSV layout even with new capability enabled', function (): void {
    ($this->seedMembers)();
    $this->service->start($this->id, 7, 'editor@example.invalid');
    $this->service->prepare($this->id); $this->service->requestFile($this->id); $this->service->buildFile($this->id);
    $stream = fopen($this->service->filePath($this->id), 'rb');
    try {
        expect(fgetcsv($stream, escape: ''))->toBe(['First name', 'address', 'debt load', 'phone1', 'phone2', 'phone3', 'phone4', 'phone5', 'send date']);
    } finally { fclose($stream); }
    expect($this->service->show($this->id)->options)->toBeNull()
        ->and($this->db->table('TblSMSRuns')->value('Artifact_Manifest_JSON'))->toBeNull();
});

test('versioned approval keeps exact files and increments only frozen selected records once', function (): void {
    $path = ($this->makeFile)(); $hash = hash_file('sha256', $path);
    $unselected = $this->db->table('TblProspects')->insertGetId(['Client' => 'Unselected Same Phone',
        'Address' => '2 Fixture St', 'Zip' => '00123', 'Phone' => '0012345600']);
    $this->service->requestApprove($this->id, 'editor@example.invalid');
    $this->service->markApproved($this->id);
    $this->service->requestApprove($this->id, 'editor@example.invalid');
    $this->service->markApproved($this->id);
    expect($this->service->show($this->id)->status)->toBe('approved')
        ->and($this->db->table('TblSMSHistory')->count())->toBe(5)
        ->and((int) $this->db->table('TblProspects')->sum('SMS_Attempts'))->toBe(5)
        ->and((int) $this->db->table('TblProspects')->where('PK', $unselected)->value('SMS_Attempts'))->toBe(0)
        ->and($this->db->table('TblProspects')->where('PK', $unselected)->value('Last_SMS_Date'))->toBeNull()
        ->and(hash_file('sha256', $this->service->filePath($this->id)))->toBe($hash)
        ->and($this->db->table('TblSMSDrops')->value('Drop_Date'))->toBe('2026-10-26');
    expect($this->service->overview()['recent'][0]->options)->toBe($this->fileOptions);
});

test('a worker crash after publication adopts the exact bundle even after the week changes', function (): void {
    ($this->prepareVersioned)(); $this->service->requestFile($this->id);
    $this->service->interruptPublication = true;
    expect(fn () => $this->service->buildFile($this->id))->toThrow(RuntimeException::class, 'Synthetic interruption');
    expect($this->db->table('TblSMSRuns')->value('File_Path'))->toBeNull();
    $published = str_replace('\\', '/', $this->versionedDirectory).'/runs/'.$this->id.'/v1/published';
    $manifest = json_decode(file_get_contents($published.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
    $before = hash_file('sha256', $published.'/'.$manifest['download']['name']);
    Carbon::setTestNow(Carbon::parse('2026-11-03T00:30:00Z'));
    $this->service->buildFile($this->id);
    expect(hash_file('sha256', $this->service->filePath($this->id)))->toBe($before)
        ->and($this->service->show($this->id)->status)->toBe('file_ready');
});

test('artifact corruption blocks download and approval without mutating sending state', function (): void {
    $path = ($this->makeFile)();
    file_put_contents($path, 'corrupt', FILE_APPEND);
    expect(fn () => $this->service->filePath($this->id))->toThrow(HttpException::class);
    expect(fn () => $this->service->requestApprove($this->id, 'editor@example.invalid'))->toThrow(HttpException::class);
    expect($this->service->show($this->id)->status)->toBe('file_ready')
        ->and($this->db->table('TblSMSRuns')->value('Approved_At'))->toBeNull()
        ->and((int) $this->db->table('TblProspects')->sum('SMS_Attempts'))->toBe(0);
});

test('committed deletion retains one quarantine receipt and cleanup retry removes the complete bundle', function (): void {
    ($this->makeFile)();
    $this->service->interruptCleanup = true;
    $view = $this->service->discard($this->id);
    $receipt = $this->db->table('TblSMSRuns')->value('File_Cleanup_Path');
    expect($view->status)->toBe('discarded')->and($view->file_cleanup_pending)->toBeTrue()->and(is_dir($receipt))->toBeTrue();
    expect($this->db->table('TblProspectsExport')->count())->toBe(0)->and($this->db->table('TblSMSHistory')->count())->toBe(0);
    $this->service->interruptCleanup = false; $this->service->cleanupDeletedFile($this->id);
    expect(is_dir($receipt))->toBeFalse()->and($this->service->show($this->id)->file_cleanup_pending)->toBeFalse();
});

test('database deletion failure restores the entire bundle and pending membership', function (): void {
    $path = ($this->makeFile)(); $hash = hash_file('sha256', $path);
    $this->db->statement("CREATE TRIGGER block_versioned_delete BEFORE DELETE ON TblProspectsExport BEGIN SELECT RAISE(ABORT, 'Synthetic delete failure'); END");
    expect(fn () => $this->service->discard($this->id))->toThrow(\Illuminate\Database\QueryException::class);
    expect($this->service->show($this->id)->status)->toBe('file_ready')
        ->and(hash_file('sha256', $this->service->filePath($this->id)))->toBe($hash)
        ->and($this->db->table('TblProspectsExport')->count())->toBe(5);
});

test('malformed stored options never become legacy and migration rollback never drops receipts', function (): void {
    ($this->seedMembers)();
    $this->service->start($this->id, 7, 'editor@example.invalid', $this->fileOptions);
    foreach (['', '{', '{}', json_encode(array_replace($this->fileOptions, ['csv_format' => 'unknown']))] as $json) {
        $this->db->table('TblSMSRuns')->update(['Run_Options_JSON' => $json]);
        expect(fn () => $this->service->show($this->id))->toThrow(HttpException::class);
        expect(fn () => $this->service->prepare($this->id))->toThrow(HttpException::class);
        expect($this->db->table('TblSMSRuns')->value('Status'))->toBe('queued')
            ->and($this->db->table('TblProspectsExport')->count())->toBe(0)
            ->and($this->db->table('TblSMSDrops')->count())->toBe(0);
    }
    $migration = require dirname(__DIR__, 2).'/database/sms-migrations/2026_10_22_000001_add_sms_run_file_options.php';
    expect(fn () => $migration->down())->toThrow(RuntimeException::class);
    expect(fn () => $migration->up())->toThrow(RuntimeException::class);
    expect($this->db->getSchemaBuilder()->hasColumns('TblSMSRuns', ['Run_Options_JSON', 'Artifact_Manifest_JSON']))->toBeTrue();
});

test('approval-only creation rejects older new requests and preserves an existing frozen request', function (): void {
    $old = $this->service->start($this->id, 7, 'editor@example.invalid', $this->fileOptions);
    $stored = $this->db->table('TblSMSRuns')->where('Request_ID', $this->id)->first();
    config(['sms-exports.approval_only_creation_enabled' => true]);
    expect($this->service->start($this->id, 7, 'editor@example.invalid', $this->fileOptions)->options)->toBe($old->options);
    expect($this->db->table('TblSMSRuns')->where('Request_ID', $this->id)->first())->toEqual($stored);
    $this->db->table('TblSMSRuns')->where('Request_ID', $this->id)->update(['Status' => 'discarded']);

    $earlyNames = SmsRunOptions::normalize(7, 3, null, null, SmsRunOptions::PLANNED_DATE_VERSION,
        '2026-10-26', SmsRunOptions::DROP_RANGE_FILENAME_VERSION);
    foreach ([null, $this->fileOptions, $earlyNames] as $options) {
        expect(fn () => $this->service->start($this->otherId, 7, 'editor@example.invalid', $options))->toThrow(HttpException::class);
        expect($this->db->table('TblSMSRuns')->where('Request_ID', $this->otherId)->exists())->toBeFalse();
    }
    expect($this->db->table('TblSMSDrops')->count())->toBe(0)
        ->and($this->db->table('TblSMSHistory')->count())->toBe(0)->and($this->service->queued)->toHaveCount(1);
});

test('approval-only new batch previews without registration and approves once with creation disabled', function (): void {
    config(['sms-exports.approval_only_creation_enabled' => true]);
    ($this->prepareApprovalVersion)();
    $this->service->filePath($this->id);
    $parts = array_column(json_decode($this->db->table('TblSMSRuns')->value('Artifact_Manifest_JSON'), true)['parts'], 'sha256');
    expect($this->db->table('TblSMSDrops')->count())->toBe(0)
        ->and($this->db->table('TblSMSHistory')->count())->toBe(0)
        ->and((int) $this->db->table('TblProspects')->sum('SMS_Attempts'))->toBe(0);
    config(['sms-exports.run_file_options_enabled' => false]);
    $this->service->requestApprove($this->id, 'approver@example.invalid');
    $this->service->markApproved($this->id); $this->service->markApproved($this->id);
    expect($this->db->table('TblSMSRuns')->value('Status'))->toBe('approved')
        ->and($this->db->table('TblSMSHistory')->count())->toBe(5)
        ->and((int) $this->db->table('TblProspects')->sum('SMS_Attempts'))->toBe(5);
    expect(is_file($this->service->filePath($this->id)))->toBeTrue()
        ->and(array_column(json_decode($this->db->table('TblSMSRuns')->value('Artifact_Manifest_JSON'), true)['parts'], 'sha256'))->toBe($parts);
});

test('approval-only creation fails closed when coordinated prerequisites are unavailable', function (string $missing): void {
    config(['sms-exports.approval_only_creation_enabled' => true, 'sms-exports.'.$missing => false]);
    $options = SmsRunOptions::normalize(7, 3, null, null, SmsRunOptions::PLANNED_DATE_VERSION,
        '2026-10-26', SmsRunOptions::APPROVAL_FILENAME_VERSION);
    expect(fn () => $this->service->start($this->id, 7, 'editor@example.invalid', $options))->toThrow(HttpException::class)
        ->and($this->db->table('TblSMSRuns')->count())->toBe(0)->and($this->service->queued)->toBe([]);
})->with(['record_workflow_enabled', 'durable_availability_enabled', 'run_file_options_enabled']);

test('tier selector capability is opt-in and canonical choices replay with creation disabled', function (): void {
    $marked = SmsRunOptions::normalize(7, 3, ['T9','T2'], SmsRunOptions::TIER_SELECTION_POLICY);
    expect($this->service->overview()['capabilities']['tier_subset'])->toBeFalse();
    expect(fn () => $this->service->start($this->id, 7, 'editor@example.invalid', $marked))->toThrow(HttpException::class);
    expect($this->db->table('TblSMSRuns')->count())->toBe(0);
    config(['sms-exports.tier_selection_enabled' => true]);
    expect($this->service->overview()['capabilities']['tier_subset'])->toBeTrue()
        ->and($this->service->overview()['capabilities']['tier_selection_policy'])->toBe(SmsRunOptions::TIER_SELECTION_POLICY);
    $request = Request::create('/', 'POST', ['request_id' => $this->id, 'target' => 7,
        'csv_format' => SmsRunOptions::CSV_FORMAT, 'rows_per_file' => 3, 'tiers' => ['T9','T2'],
        'tier_selection_policy' => SmsRunOptions::TIER_SELECTION_POLICY]);
    $request->attributes->set('cmd_user', ['email' => 'editor@example.invalid']);
    expect($this->controller->store($request)->getData(true)['options'])->toBe($marked);
    config(['sms-exports.tier_selection_enabled' => false, 'sms-exports.run_file_options_enabled' => false]);
    expect($this->service->start($this->id, 7, 'editor@example.invalid', $marked)->options)->toBe($marked)
        ->and($this->service->queued)->toHaveCount(1);
    expect(fn () => $this->service->start($this->id, 7, 'editor@example.invalid', $this->fileOptions))->toThrow(HttpException::class);
    expect(fn () => $this->service->start($this->id, 7, 'editor@example.invalid',
        SmsRunOptions::normalize(7, 3, ['T2'], SmsRunOptions::TIER_SELECTION_POLICY)))->toThrow(HttpException::class);
});

test('old all-tier options retain their selector while new all-tier options freeze the explicit version', function (): void {
    config(['sms-exports.tier_selection_enabled' => true]);
    $old = $this->service->start($this->id, 7, 'editor@example.invalid', $this->fileOptions);
    expect($old->options)->toBe($this->fileOptions)->and(array_keys($old->options))->toHaveCount(4);
    $newAll = SmsRunOptions::normalize(7, 3, null, SmsRunOptions::TIER_SELECTION_POLICY);
    expect(fn () => $this->service->start($this->id, 7, 'editor@example.invalid', $newAll))->toThrow(HttpException::class);
    $this->db->table('TblSMSRuns')->where('Request_ID', $this->id)->update(['Status' => 'discarded']);
    expect($this->service->start($this->otherId, 7, 'editor@example.invalid', $newAll)->options)->toBe($newAll);
});

test('minimum debt capabilities and create response freeze exact integer options for idempotent retries', function (): void {
    config(['sms-exports.tier_selection_enabled' => true]);
    $caps = $this->service->overview()['capabilities'];
    expect($caps['minimum_debt_amount'])->toBeTrue()->and($caps['minimum_debt_floor'])->toBe(25000)
        ->and($caps['minimum_debt_default'])->toBe(25000)->and($caps['minimum_debt_max'])->toBe(9999999000);
    $options = SmsRunOptions::normalize(7, 3, ['T5','T9'], SmsRunOptions::TIER_SELECTION_POLICY,
        null, null, null, 31000);
    $request = Request::create('/', 'POST', ['request_id' => $this->id, 'target' => 7,
        'csv_format' => SmsRunOptions::CSV_FORMAT, 'rows_per_file' => 3, 'tiers' => ['T9','T5'],
        'tier_selection_policy' => SmsRunOptions::TIER_SELECTION_POLICY, 'minimum_debt_amount' => '31000']);
    $request->attributes->set('cmd_user', ['email' => 'editor@example.invalid']);
    expect($this->controller->store($request)->getData(true)['options'])->toBe($options);
    config(['sms-exports.tier_selection_enabled' => false, 'sms-exports.run_file_options_enabled' => false]);
    expect($this->service->start($this->id, 7, 'editor@example.invalid', $options)->options)->toBe($options)
        ->and($this->service->queued)->toHaveCount(1);
    expect(fn () => $this->service->start($this->id, 7, 'editor@example.invalid',
        array_replace($options, ['minimum_debt_amount' => 32000])))->toThrow(HttpException::class);
    expect($this->db->table('TblSMSRuns')->count())->toBe(1)->and($this->db->table('TblProspectsExport')->count())->toBe(0);
});

test('invalid or formatless minimum debt input never creates a run', function (): void {
    config(['sms-exports.tier_selection_enabled' => true]);
    foreach ([24999, -1, 25000.5, null, '25000bad', 10000000000] as $minimum) {
        $request = Request::create('/', 'POST', ['request_id' => $this->id, 'target' => 7,
            'csv_format' => SmsRunOptions::CSV_FORMAT, 'tier_selection_policy' => SmsRunOptions::TIER_SELECTION_POLICY,
            'minimum_debt_amount' => $minimum]);
        expect(fn () => $this->controller->store($request))->toThrow(ValidationException::class);
    }
    $request = Request::create('/', 'POST', ['request_id' => $this->id, 'target' => 7, 'minimum_debt_amount' => 25000]);
    expect(fn () => $this->controller->store($request))->toThrow(ValidationException::class);
    expect($this->db->table('TblSMSRuns')->count())->toBe(0)->and($this->service->queued)->toBe([]);
});

test('malformed or formatless selector requests fail before run insertion', function (): void {
    config(['sms-exports.tier_selection_enabled' => true]);
    foreach ([['tier_selection_policy' => SmsRunOptions::TIER_SELECTION_POLICY],
        ['csv_format' => SmsRunOptions::CSV_FORMAT, 'tier_selection_policy' => 'unknown'],
        ['csv_format' => SmsRunOptions::CSV_FORMAT, 'tier_selection_policy' => null],
        ['csv_format' => SmsRunOptions::CSV_FORMAT, 'tier_selection_policy' => SmsRunOptions::TIER_SELECTION_POLICY, 'tiers' => []]] as $extra) {
        $request = Request::create('/', 'POST', $extra + ['request_id' => $this->id, 'target' => 7]);
        expect(fn () => $this->controller->store($request))->toThrow(ValidationException::class);
    }
    expect($this->db->table('TblSMSRuns')->count())->toBe(0)->and($this->service->queued)->toBe([]);
});

test('closed run history traverses every page without pending rows duplicates or source writes', function (): void {
    $ids = [];
    for ($i = 1; $i <= 25; $i++) {
        $id = sprintf('%08d-1111-4111-8111-%012d', $i, $i); $ids[] = $id;
        $this->db->table('TblSMSRuns')->insert(['Request_ID' => $id, 'Target' => 7, 'Selected' => 5,
            'Status' => SmsRunService::CLOSED[($i - 1) % count(SmsRunService::CLOSED)],
            'Selection_Policy' => $i % 2 ? 'legacy-phone-v1' : 'prospect-record-v1',
            'Created_By' => 'editor@example.invalid', 'Created_At' => '2026-10-01 01:00:00', 'Updated_At' => '2026-10-01 01:00:00']);
    }
    $this->service->start($this->id, 7, 'editor@example.invalid');
    $before = $this->db->table('TblSMSRuns')->orderBy('PK')->get()->toJson();
    $this->db->enableQueryLog(); $this->db->flushQueryLog();
    $overview = $this->service->overview();
    $first = $overview['recent']; $cursor = $overview['history_next_cursor'];
    expect($first)->toHaveCount(10)->and($cursor)->toBe(16);
    $second = $this->service->history($cursor);
    $third = $this->service->history($second['next_cursor']);
    expect($second['items'])->toHaveCount(10)->and($second['next_cursor'])->toBe(6)
        ->and($third['items'])->toHaveCount(5)->and($third['next_cursor'])->toBeNull();
    $actual = array_map(fn ($run) => $run->request_id, [...$first, ...$second['items'], ...$third['items']]);
    expect($actual)->toBe(array_reverse($ids))->and(array_unique($actual))->toHaveCount(25);
    $queries = array_column($this->db->getQueryLog(), 'query');
    expect(implode("\n", $queries))->not->toMatch('/\b(?:INSERT|UPDATE|DELETE|OFFSET)\b/i');
    $this->db->disableQueryLog();
    expect($this->db->table('TblSMSRuns')->orderBy('PK')->get()->toJson())->toBe($before)
        ->and($this->db->table('TblSMSHistory')->count())->toBe(0)
        ->and($this->db->table('TblProspectsExport')->count())->toBe(0);
});

test('history cursor remains stable when a newer run closes and permits bounded empty pages', function (): void {
    for ($i = 1; $i <= 12; $i++) $this->db->table('TblSMSRuns')->insert([
        'Request_ID' => sprintf('%08d-1111-4111-8111-%012d', $i, $i), 'Target' => 7, 'Selected' => 5,
        'Status' => 'discarded', 'Created_By' => 'editor@example.invalid', 'Created_At' => '2026-10-01', 'Updated_At' => '2026-10-01']);
    $first = $this->service->history();
    $this->db->table('TblSMSRuns')->insert(['Request_ID' => $this->id, 'Target' => 7, 'Selected' => 5,
        'Status' => 'discarded', 'Created_By' => 'editor@example.invalid', 'Created_At' => '2026-10-02', 'Updated_At' => '2026-10-02']);
    $older = $this->service->history($first['next_cursor']);
    expect(array_map(fn ($run) => $run->request_id, $older['items']))->toBe([
        '00000002-1111-4111-8111-000000000002', '00000001-1111-4111-8111-000000000001',
    ])->and($older['next_cursor'])->toBeNull()
        ->and($this->service->history(1)['items'])->toBe([])
        ->and($this->service->history(1)['next_cursor'])->toBeNull();
    expect($this->service->history(null, 50)['items'])->toHaveCount(13);
});

test('history controller rejects malformed cursors and limits and retains private response caching', function (): void {
    foreach ([['before_id' => 0], ['before_id' => -1], ['before_id' => 2147483648], ['before_id' => '1 OR 1=1'],
        ['before_id' => null], ['before_id' => []], ['limit' => 0], ['limit' => 51], ['limit' => 1.5]] as $query) {
        expect(fn () => $this->controller->history(Request::create('/', 'GET', $query)))->toThrow(ValidationException::class);
    }
    foreach ([fn () => $this->service->history(0), fn () => $this->service->history(2147483648),
        fn () => $this->service->history(null, 51)] as $call) expect($call)->toThrow(ValidationException::class);
    $response = $this->controller->history(Request::create('/', 'GET', ['limit' => 1]));
    expect($response->getData(true))->toBe(['items' => [], 'next_cursor' => null, 'limit' => 1])
        ->and($response->headers->get('Cache-Control'))->toContain('private', 'no-store')
        ->and($this->db->table('TblSMSRuns')->count())->toBe(0)->and($this->service->queued)->toBe([]);
});

test('older historical runs retain frozen file metadata and download exact bytes without rebuilding', function (): void {
    $path = ($this->makeFile)(); $hash = hash_file('sha256', $path);
    $this->service->requestApprove($this->id, 'editor@example.invalid'); $this->service->markApproved($this->id);
    for ($i = 1; $i <= 12; $i++) $this->db->table('TblSMSRuns')->insert([
        'Request_ID' => sprintf('%08d-1111-4111-8111-%012d', $i, $i), 'Target' => 7, 'Selected' => 0,
        'Status' => 'discarded', 'Created_By' => 'editor@example.invalid', 'Created_At' => '2026-10-21', 'Updated_At' => '2026-10-21']);
    $first = $this->service->history(); $older = $this->service->history($first['next_cursor']);
    $row = array_values(array_filter($older['items'], fn ($run) => $run->request_id === $this->id))[0];
    expect($row->options)->toBe($this->fileOptions)->and($row->file['format'])->toBe('zip')->and($row->file['parts'])->toBe(2);
    expect(hash_file('sha256', $this->service->filePath($row->request_id)))->toBe($hash);
    $attempts = (int) $this->db->table('TblProspects')->sum('SMS_Attempts');
    file_put_contents($path, str_repeat('x', filesize($path)));
    // Listing reports saved metadata, not a costly file/hash readiness claim.
    expect($this->service->history($first['next_cursor'])['items'])->toHaveCount(3);
    expect(fn () => $this->service->filePath($row->request_id))->toThrow(HttpException::class);
    expect((int) $this->db->table('TblProspects')->sum('SMS_Attempts'))->toBe($attempts)
        ->and($this->service->show($this->id)->status)->toBe('approved');
});


/** Closed import gateway: approval capture may inspect identity but must never transfer a file. */
function smsVersionedBindImportWorkflow(): \Cmd\Reports\Services\SmsRunDebtPleteImportWorkflow
{
    $gateway = new class implements \Cmd\Reports\Services\SmsDebtPleteImportGateway {
        public function identity(): array
        {
            return ['protocol' => 'debtplete-sms-import-v1', 'origin' => 'https://tenant.example.invalid',
                'tenant_id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'client_id' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc'];
        }
        public function register(array $payload): array { throw new RuntimeException('Approval must not call the receiver.'); }
        public function upload(string $id, int $part, string $sha, mixed $stream): array { throw new RuntimeException('Approval must not upload bytes.'); }
        public function status(string $id): array { throw new RuntimeException('A saved read must not poll the receiver.'); }
    };
    $workflow = new \Cmd\Reports\Services\SmsRunDebtPleteImportWorkflow($gateway, new \Cmd\Reports\Services\SmsRunArtifactPartReader);
    app()->instance(\Cmd\Reports\Services\SmsRunDebtPleteImportWorkflow::class, $workflow);
    return $workflow;
}

test('automatic import outbox commits with approval once and queue intent follows the commit', function (): void {
    ($this->prepareApprovalVersion)();
    (require dirname(__DIR__, 2).'/database/sms-migrations/2026_10_25_000001_create_sms_debtplete_imports.php')->up();
    config(['sms-exports.debtplete_import_enabled' => true, 'sms-exports.debtplete_import_automatic' => true]);
    smsVersionedBindImportWorkflow();
    $this->service->requestApprove($this->id, 'editor@example.invalid');
    $this->service->markApproved($this->id);
    $receipt = $this->db->table('TblSMSDebtPleteImports')->first();
    expect($receipt->Request_ID)->toBe($this->id)->and($receipt->State)->toBe('queued')
        ->and($receipt->Remote_Receipt_JSON)->toBeNull()->and($this->service->importQueueRequests)->toBe([$this->id])
        ->and($this->db->table('TblSMSRuns')->value('Status'))->toBe('approved')
        ->and((int) $this->db->table('TblProspects')->sum('SMS_Attempts'))->toBe(5);
    $this->service->markApproved($this->id);
    expect($this->db->table('TblSMSDebtPleteImports')->count())->toBe(1)
        ->and($this->service->importQueueRequests)->toBe([$this->id])
        ->and($this->db->table('TblSMSHistory')->count())->toBe(5);
    $view = $this->service->show($this->id);
    expect($view->handoff['debtplete_import']['status'])->toBe('queued')
        ->and($view->handoff['debtplete_import']['processed'])->toBeNull();
});

test('failed automatic outbox insert rolls back approval attempts and history while retaining finalized files for the same retry', function (): void {
    ($this->prepareApprovalVersion)();
    (require dirname(__DIR__, 2).'/database/sms-migrations/2026_10_25_000001_create_sms_debtplete_imports.php')->up();
    config(['sms-exports.debtplete_import_enabled' => true, 'sms-exports.debtplete_import_automatic' => true]);
    smsVersionedBindImportWorkflow();
    $this->db->statement("CREATE TRIGGER block_import_capture BEFORE INSERT ON TblSMSDebtPleteImports BEGIN SELECT RAISE(ABORT, 'Synthetic outbox failure'); END");
    $this->service->requestApprove($this->id, 'editor@example.invalid');
    expect(fn () => $this->service->markApproved($this->id))->toThrow(RuntimeException::class);
    expect($this->db->table('TblSMSDebtPleteImports')->count())->toBe(0)
        ->and($this->db->table('TblSMSRuns')->value('Status'))->toBe('approving')
        ->and($this->db->table('TblSMSHistory')->count())->toBe(0)
        ->and((int) $this->db->table('TblProspects')->sum('SMS_Attempts'))->toBe(0)
        ->and($this->service->importQueueRequests)->toBe([]);
    $manifest = str_replace('\\', '/', $this->versionedDirectory).'/runs/'.$this->id.'/v2/published/manifest.json';
    expect(is_file($manifest))->toBeTrue();
    $before = hash_file('sha256', $manifest);
    $this->db->statement('DROP TRIGGER block_import_capture');
    $this->service->markApproved($this->id);
    expect($this->db->table('TblSMSDebtPleteImports')->count())->toBe(1)
        ->and(hash_file('sha256', $manifest))->toBe($before)
        ->and($this->db->table('TblSMSHistory')->count())->toBe(5)
        ->and((int) $this->db->table('TblProspects')->sum('SMS_Attempts'))->toBe(5);
});

test('enabling import does not retroactively enqueue an earlier approved run', function (): void {
    ($this->prepareApprovalVersion)();
    $this->service->requestApprove($this->id, 'editor@example.invalid'); $this->service->markApproved($this->id);
    (require dirname(__DIR__, 2).'/database/sms-migrations/2026_10_25_000001_create_sms_debtplete_imports.php')->up();
    config(['sms-exports.debtplete_import_enabled' => true, 'sms-exports.debtplete_import_automatic' => true]);
    smsVersionedBindImportWorkflow();
    $this->service->markApproved($this->id);
    expect($this->db->table('TblSMSDebtPleteImports')->count())->toBe(0)
        ->and($this->service->importQueueRequests)->toBe([])
        ->and($this->service->show($this->id)->handoff['debtplete_import']['status'])->toBe('not_requested');
});

test('queue or logging failure after approval leaves the committed outbox and business state intact', function (): void {
    ($this->prepareApprovalVersion)();
    (require dirname(__DIR__, 2).'/database/sms-migrations/2026_10_25_000001_create_sms_debtplete_imports.php')->up();
    config(['sms-exports.debtplete_import_enabled' => true, 'sms-exports.debtplete_import_automatic' => true]);
    smsVersionedBindImportWorkflow();
    $this->service->requestApprove($this->id, 'editor@example.invalid'); $this->service->markApproved($this->id);
    $before = $this->db->table('TblSMSDebtPleteImports')->first();
    $logger = Mockery::mock(\Psr\Log\LoggerInterface::class);
    $logger->shouldReceive('warning')->once()->withArgs(function (string $message, array $context): bool {
        expect(\Cmd\Reports\Services\SmsDebtPleteImportPrivacy::active())->toBeTrue();
        return $context === ['request_id' => '11111111-2222-4333-8444-555555555555'];
    })->andThrow(new RuntimeException('Synthetic unavailable logger.'));
    app()->instance('log', $logger);
    // This fixture has no dispatcher/cache bindings: acknowledgement fails before any queue write.
    $this->service->requestRealImportQueueForTest($this->id);
    expect($this->db->table('TblSMSDebtPleteImports')->first())->toEqual($before)
        ->and($this->db->table('TblSMSRuns')->value('Status'))->toBe('approved')
        ->and((int) $this->db->table('TblProspects')->sum('SMS_Attempts'))->toBe(5);
});

/** Uses the real metadata capture; every publication/email operation is a closed tripwire. */
function smsVersionedBindAutomaticHandoff(): void
{
    foreach (['2026_10_23_000003_create_sms_email_outbox.php', '2026_10_23_000004_create_sms_file_publications.php',
        '2026_10_26_000001_create_sms_automatic_handoffs.php'] as $file) {
        (require dirname(__DIR__, 2).'/database/sms-migrations/'.$file)->up();
    }
    config(['sms-exports.handoff_automatic' => true, 'sms-exports.file_publication_enabled' => true,
        'sms-exports.email_handoff_enabled' => true, 'sms-exports.email_report_source' => 'plaw',
        'sms-exports.email_report_name' => 'Synthetic SMS', 'sms-exports.email_report_company' => 'FIXTURE',
        'sms-exports.email_allowed_link_hosts' => ['download.example.invalid']]);
    $publisher = new class implements \Cmd\Reports\Services\SmsRunLinkPublisher {
        public function identity(): string { return 'closed-fixture'; }
        public function policy(string $date): array { return ['version' => 1, 'send_date' => $date]; }
        public function publish(string $id, array $part, mixed $stream, array $policy): array { throw new RuntimeException('Approval must not publish.'); }
        public function verify(string $id, array $part, array $receipt, array $policy): void { throw new RuntimeException('Approval must not contact a provider.'); }
    };
    $mail = new class implements \Cmd\Reports\Services\SmsRunEmailGateway {
        public function configuration(): array { throw new RuntimeException('Approval must not look up recipients.'); }
        public function assertReady(): void { throw new RuntimeException('Approval must not contact mail.'); }
        public function send(array $message): bool { throw new RuntimeException('Approval must not send.'); }
    };
    app()->instance(\Cmd\Reports\Services\SmsRunAutomaticHandoffWorkflow::class,
        new \Cmd\Reports\Services\SmsRunAutomaticHandoffWorkflow($publisher, new \Cmd\Reports\Services\SmsRunArtifactPartReader, $mail));
}

test('automatic publication and email intent commits only with the first approval and dispatches afterward', function (): void {
    ($this->prepareApprovalVersion)(); smsVersionedBindAutomaticHandoff();
    $this->service->requestApprove($this->id, 'editor@example.invalid'); $this->service->markApproved($this->id);
    $row = $this->db->table('TblSMSAutomaticHandoffs')->first();
    expect($row->Request_ID)->toBe($this->id)->and($row->State)->toBe('queued')->and($row->Phase)->toBe('publish')
        ->and($this->db->table('TblSMSEmailOutbox')->count())->toBe(0)
        ->and($this->db->table('TblSMSFilePublications')->count())->toBe(0)
        ->and($this->service->automaticQueueRequests)->toBe([$this->id])
        ->and($this->service->importQueueRequests)->toBe([]);
    $this->service->markApproved($this->id);
    expect($this->db->table('TblSMSAutomaticHandoffs')->first())->toEqual($row)
        ->and($this->service->automaticQueueRequests)->toBe([$this->id])
        ->and($this->db->table('TblSMSHistory')->count())->toBe(5);
});

test('automatic handoff intent failure rolls back both intents attempts and history without republishing files', function (): void {
    ($this->prepareApprovalVersion)(); smsVersionedBindAutomaticHandoff();
    (require dirname(__DIR__, 2).'/database/sms-migrations/2026_10_25_000001_create_sms_debtplete_imports.php')->up();
    config(['sms-exports.debtplete_import_enabled' => true, 'sms-exports.debtplete_import_automatic' => true]);
    smsVersionedBindImportWorkflow();
    $this->db->statement("CREATE TRIGGER block_auto_capture BEFORE INSERT ON TblSMSAutomaticHandoffs BEGIN SELECT RAISE(ABORT, 'Synthetic intent failure'); END");
    $this->service->requestApprove($this->id, 'editor@example.invalid');
    expect(fn () => $this->service->markApproved($this->id))->toThrow(RuntimeException::class);
    expect($this->db->table('TblSMSAutomaticHandoffs')->count())->toBe(0)
        ->and($this->db->table('TblSMSDebtPleteImports')->count())->toBe(0)
        ->and($this->db->table('TblSMSHistory')->count())->toBe(0)
        ->and((int) $this->db->table('TblProspects')->sum('SMS_Attempts'))->toBe(0)
        ->and($this->service->automaticQueueRequests)->toBe([])->and($this->service->importQueueRequests)->toBe([]);
    $manifest = str_replace('\\', '/', $this->versionedDirectory).'/runs/'.$this->id.'/v2/published/manifest.json';
    $hash = hash_file('sha256', $manifest);
    $this->db->statement('DROP TRIGGER block_auto_capture'); $this->service->markApproved($this->id);
    expect($this->db->table('TblSMSAutomaticHandoffs')->count())->toBe(1)
        ->and($this->db->table('TblSMSDebtPleteImports')->count())->toBe(1)
        ->and(hash_file('sha256', $manifest))->toBe($hash)
        ->and($this->service->automaticQueueRequests)->toBe([$this->id])->and($this->service->importQueueRequests)->toBe([$this->id]);
});

test('enabling automatic handoff never adopts earlier approved history', function (): void {
    ($this->prepareApprovalVersion)();
    $this->service->requestApprove($this->id, 'editor@example.invalid'); $this->service->markApproved($this->id);
    smsVersionedBindAutomaticHandoff(); $this->service->markApproved($this->id);
    expect($this->db->table('TblSMSAutomaticHandoffs')->count())->toBe(0)->and($this->service->automaticQueueRequests)->toBe([]);
});

test('automatic queue and logging failures preserve committed approval and its recoverable intent', function (): void {
    ($this->prepareApprovalVersion)(); smsVersionedBindAutomaticHandoff();
    $this->service->requestApprove($this->id, 'editor@example.invalid'); $this->service->markApproved($this->id);
    $before = $this->db->table('TblSMSAutomaticHandoffs')->first();
    $logger = Mockery::mock(); $logger->shouldReceive('warning')->andThrow(new RuntimeException('Synthetic logger outage'));
    app()->instance('log', $logger); Facade::clearResolvedInstance('log'); config(['queue.connections' => []]);
    $this->service->requestRealAutomaticQueueForTest($this->id);
    expect($this->db->table('TblSMSAutomaticHandoffs')->first())->toEqual($before)
        ->and($this->db->table('TblSMSRuns')->value('Status'))->toBe('approved')
        ->and($this->db->table('TblSMSHistory')->count())->toBe(5);
});
