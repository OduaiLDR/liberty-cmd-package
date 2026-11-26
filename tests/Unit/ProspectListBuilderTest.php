<?php

declare(strict_types=1);

use Cmd\Reports\Repositories\ProspectListRepository;
use Cmd\Reports\Repositories\ProspectListExportFiles;
use Cmd\Reports\Repositories\ProspectMailDateIndex;
use Cmd\Reports\Http\Controllers\ProspectListController;
use Cmd\Reports\Http\Middleware\ProspectListServiceAuth;
use Cmd\Reports\Http\Requests\ProspectListRequest;
use Cmd\Reports\Jobs\BuildProspectList;
use Cmd\Reports\Services\ProspectExportInspector;
use Cmd\Reports\Services\ProspectCampaignCsv;
use Cmd\Reports\Services\ProspectListCsvFiles;
use Cmd\Reports\Services\ProspectListFields;
use Cmd\Reports\Services\ProspectListRuns;
use Cmd\Reports\Services\TcpaLitigatorScrubber;
use Cmd\Reports\Services\TcpaScrubUnavailable;
use Illuminate\Config\Repository;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\SqlServerConnection;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    $container = new Application();
    $container->instance('config', new Repository(['prospect-list-builder' => ['dob_column' => 'DOB']]));
    Container::setInstance($container);
    $this->connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $container->instance('db', $this->connection);
    $this->previousFacadeApplication = Facade::getFacadeApplication();
    Facade::setFacadeApplication($container);
    Facade::clearResolvedInstance('db');

    $migration = require __DIR__.'/../../database/prospect-list-migrations/2026_11_25_104441_create_prospect_list_export_files_table.php';
    \Illuminate\Support\Facades\Schema::swap($this->connection->getSchemaBuilder());
    $migration->up();

    $this->connection->statement('CREATE TABLE TblProspects (
        PK INTEGER PRIMARY KEY, Client TEXT, Address TEXT, Zip TEXT, Phone TEXT, Email TEXT, State TEXT, Creation_Date TEXT,
        Contacted INTEGER, Opt_Out INTEGER, Invalid_Phone INTEGER, Latest_Export_Date TEXT, Last_SMS_Date TEXT,
        Current_Debt INTEGER, Current_Debt_Source TEXT, Current_Debt_Source_PK INTEGER
    )');
    $this->connection->statement('CREATE TABLE TblTUData (
        PK INTEGER PRIMARY KEY, ncoaAddr_frstNme TEXT, ncoaAddr_lastNme TEXT, evtg04_finscore INTEGER, DOB TEXT,
        creditAsOfDate_creditAsOfDate TEXT, blto02_finscore INTEGER, blto02_v001name TEXT, derivedFields1_unsecdebtbalance NUMERIC
    )');
    $this->connection->table('TblTUData')->insert([
        'PK' => 90, 'ncoaAddr_frstNme' => 'Taylor', 'ncoaAddr_lastNme' => 'Example',
        'evtg04_finscore' => 650, 'DOB' => '1985-01-01', 'creditAsOfDate_creditAsOfDate' => '2026-11-12',
        'blto02_finscore' => 7300, 'blto02_v001name' => 'BC102S', 'derivedFields1_unsecdebtbalance' => 16000.25,
    ]);

    $eligible = [
        'Client' => 'Taylor Example', 'Phone' => '5551234567', 'Email' => 'taylor@example.test',
        'State' => 'CA', 'Creation_Date' => '2026-11-20', 'Contacted' => 0, 'Opt_Out' => 0, 'Invalid_Phone' => 0,
        'Latest_Export_Date' => null, 'Last_SMS_Date' => '2026-07-15', 'Current_Debt' => 20000,
        'Current_Debt_Source' => 'TblTUData', 'Current_Debt_Source_PK' => 90,
    ];
    $this->connection->table('TblProspects')->insert([
        ['PK' => 1, ...$eligible],
        [...$eligible, 'PK' => 2, 'Opt_Out' => 1],
        [...$eligible, 'PK' => 3, 'Contacted' => 1],
        [...$eligible, 'PK' => 4, 'Invalid_Phone' => 1],
        [...$eligible, 'PK' => 5, 'Latest_Export_Date' => '2026-11-15'],
        [...$eligible, 'PK' => 6, 'Last_SMS_Date' => '2026-11-15'],
        [...$eligible, 'PK' => 7, 'Current_Debt_Source' => 'TblMailers'],
        [...$eligible, 'PK' => 8, 'Email' => ''],
    ]);

    $this->prospects = new class($this->connection) extends ProspectListRepository {
        public function __construct(private SQLiteConnection $database) {}

        protected function connection(): ConnectionInterface
        {
            return $this->database;
        }
    };
});

afterEach(function (): void {
    Facade::clearResolvedInstance('db');
    Facade::clearResolvedInstance('db.schema');
    Facade::setFacadeApplication($this->previousFacadeApplication);
});

test('saved CSV inspection preserves values and applies typed filters', function (): void {
    $stream = fopen('php://temp', 'w+');
    $columns = ['state', 'full_name', 'debt', 'date_added'];
    $records = [
        ['State', 'Full Name', 'Debt', 'Date added'],
        ['CA', "First, Middle\nLast", '9007199254740992.0001', '2026-11-22 11:30:00'],
        ['ca', 'Second', '9007199254740992.0002', '2026-11-23 09:00:00'],
        ['', 'Third', '0', ''],
    ];
    foreach ($records as $row) {
        fputcsv($stream, $row, escape: '');
    }

    $inspector = new ProspectExportInspector();
    try {
        rewind($stream);
        $states = $inspector->inspect($stream, $columns, 0, 'text', ['operator' => 'equals', 'value' => 'ca'], 3);
        expect($states['scanned_rows'])->toBe(3)
            ->and($states['matching_rows'])->toBe(2)
            ->and($states['top_values'])->toBe([['value' => 'CA', 'count' => 2]])
            ->and($states['columns'])->toBe(array_map(static fn ($id, $label) => compact('id', 'label'), $columns, $records[0]))
            ->and($states['examples'][0])->toBe(['number' => 1, 'values' => $records[1]]);

        rewind($stream);
        $debt = $inspector->inspect($stream, $columns, 2, 'number', ['operator' => 'range', 'minimum' => '9007199254740992.0002'], 3);
        expect($debt['examples'])->toBe([['number' => 2, 'values' => $records[2]]]);

        rewind($stream);
        $date = $inspector->inspect($stream, $columns, 3, 'date', ['operator' => 'range', 'minimum' => '2026-11-22', 'maximum' => '2026-11-22'], 3);
        expect($date['examples'])->toBe([['number' => 1, 'values' => $records[1]]]);
    } finally {
        fclose($stream);
    }
});

test('saved CSV inspection reports a bounded preview and handles blank values', function (): void {
    $stream = fopen('php://temp', 'w+');
    fputcsv($stream, ['State'], escape: '');
    $fileRows = ProspectExportInspector::ROW_LIMIT + 2;
    for ($row = 0; $row < $fileRows; $row++) {
        fputcsv($stream, [$row < 6 ? '' : 'CA'], escape: '');
    }
    rewind($stream);

    try {
        $inspection = (new ProspectExportInspector())->inspect($stream, ['state'], 0, 'text', ['operator' => 'missing'], $fileRows);
        expect($inspection['file_rows'])->toBe($fileRows)
            ->and($inspection['scanned_rows'])->toBe(ProspectExportInspector::ROW_LIMIT)
            ->and($inspection['blank_rows'])->toBe(6)
            ->and($inspection['matching_rows'])->toBe(6)
            ->and($inspection['top_values'])->toBeEmpty()
            ->and($inspection['examples'])->toHaveCount(5)
            ->and($inspection['examples'][0])->toBe(['number' => 1, 'values' => ['']]);
    } finally {
        fclose($stream);
    }
});

test('count preview uses the export filters, row order, selected columns and formatted values', function (): void {
    $filters = ['states' => ['CA'], 'debt_min' => 10000, 'contact' => 'phone_email'];
    $date = Carbon::parse('2026-11-24');
    $columns = ['debt', 'state', 'last_name', 'phone'];
    $plannedRows = $this->prospects->count($filters, $date);
    $previewRows = $this->prospects->preview($filters, $columns, $date, 2);
    $exportedRows = [];
    $this->prospects->eachBatch($filters, $columns, $date, 2, function ($rows) use (&$exportedRows): void {
        $exportedRows = $rows->all();
    });

    $inspection = (new ProspectExportInspector())->preview($previewRows, $columns, 1, ['operator' => 'equals', 'value' => 'CA'], $plannedRows);
    expect($previewRows->all())->toEqual($exportedRows)
        ->and($inspection['file_rows'])->toBe(4)
        ->and($inspection['scanned_rows'])->toBe(2)
        ->and($inspection['matching_rows'])->toBe(2)
        ->and($inspection['examples'][0]['values'])->toBe(['20000', 'CA', 'Example', '5551234567'])
        ->and($this->connection->table('TblProspects')->where('PK', 1)->value('Latest_Export_Date'))->toBeNull();
});

test('count and export use the same eligibility, credit, age and recency filters', function (): void {
    $date = Carbon::parse('2026-11-24');
    $filters = [
        'states' => ['CA'], 'debt_min' => 10000, 'score_min' => 600, 'age_min' => 35, 'age_max' => 45,
        'date_from' => '2026-11-01', 'date_to' => '2026-11-20', 'contact' => 'phone_email', 'months_since_pull' => 3,
    ];

    $exported = [];
    $this->prospects->eachBatch($filters, ['phone', 'score', 'last_name'], $date, 1,
        function ($rows) use (&$exported): void {
            $exported = $rows->all();
        });

    expect($this->prospects->count($filters, $date))->toBe(1)
        ->and($exported)->toEqual([
            (object) ['prospect_id' => 1, 'name_fallback' => 'Taylor Example', 'phone' => '5551234567', 'score' => 650, 'last_name' => 'Example'],
        ]);
});

test('export honors a requested size above ten thousand across ordered batches', function (): void {
    $this->connection->statement('WITH RECURSIVE ids (id) AS (
        SELECT 100 UNION ALL SELECT id + 1 FROM ids WHERE id < 11000
    ) INSERT INTO TblProspects (PK, Client, Contacted, Opt_Out, Invalid_Phone)
        SELECT id, \'Preview\', 0, 0, 0 FROM ids');

    $ids = [];
    $this->prospects->eachBatch([], ['full_name'], Carbon::parse('2026-11-24'), 10_503,
        function ($rows) use (&$ids): void {
            array_push($ids, ...$rows->pluck('prospect_id')->all());
        });

    expect($ids)->toBe([1, 5, 6, 7, 8, ...range(100, 10597)]);
});

test('newest mailed pulls keep filters, cross page and date boundaries, and skip marked prospects', function (bool $indexed): void {
    $db = $this->connection;
    $db->statement('CREATE TABLE TblMarketing (Drop_Name TEXT, Send_Date TEXT)');
    $db->statement('CREATE TABLE TblMailers (PK INTEGER PRIMARY KEY, Client TEXT, Address TEXT, SMS_Zip5 TEXT, Drop_Name TEXT)');
    $db->table('TblMarketing')->insert([
        ['Drop_Name' => 'recent', 'Send_Date' => '2026-11-10'],
        ['Drop_Name' => 'recent', 'Send_Date' => '2026-11-10'],
        ['Drop_Name' => 'earlier', 'Send_Date' => '2026-09-15'],
        ['Drop_Name' => 'future', 'Send_Date' => '2026-12-01'],
    ]);
    $db->statement("WITH RECURSIVE ids (id) AS (
        SELECT 10 UNION ALL SELECT id + 1 FROM ids WHERE id < 2012
    ) INSERT INTO TblProspects (PK, Client, Address, Zip, State, Contacted, Opt_Out, Invalid_Phone, Current_Debt)
        SELECT id, 'Example ' || id, '1 Sample Way', '90210-1234', 'CA', 0, 0, 0, 20000 FROM ids");
    $db->statement("INSERT INTO TblMailers (Client, Address, SMS_Zip5, Drop_Name)
        SELECT Client, Address, '90210', 'recent' FROM TblProspects WHERE PK >= 10");
    $db->table('TblProspects')->whereIn('PK', [1, 5, 6, 7, 8])->update(['Address' => '1 Sample Way', 'Zip' => '90210']);
    $db->table('TblProspects')->where('PK', 7)->update(['Client' => 'Older match', 'Last_SMS_Date' => null]);
    $db->table('TblProspects')->where('PK', 8)->update(['Client' => 'Future match', 'Last_SMS_Date' => null]);
    $db->table('TblMailers')->insert([
        ['Client' => 'Taylor Example', 'Address' => '1 Sample Way', 'SMS_Zip5' => '90210', 'Drop_Name' => 'recent'],
        ['Client' => 'Example 10', 'Address' => '1 Sample Way', 'SMS_Zip5' => '90210', 'Drop_Name' => 'earlier'],
        ['Client' => 'Older match', 'Address' => '1 Sample Way', 'SMS_Zip5' => '90210', 'Drop_Name' => 'earlier'],
        ['Client' => 'Future match', 'Address' => '1 Sample Way', 'SMS_Zip5' => '90210', 'Drop_Name' => 'future'],
        ['Client' => 'Example 2010', 'Address' => '1 Sample Way', 'SMS_Zip5' => '90210', 'Drop_Name' => 'future'],
    ]);
    $db->table('TblProspects')->where('PK', 2012)->update(['State' => 'NV']);
    $db->table('TblProspects')->where('PK', 2011)->update(['Current_Debt' => 1000]);

    $date = Carbon::parse('2026-11-24');
    $filters = ['fresh_mailed' => true, 'states' => ['CA'], 'debt_min' => 10_000, 'months_since_pull' => 1];
    $columns = ['full_name'];
    $expected = [...range(10, 2010), 7];
    $exportIds = [];
    if ($indexed) {
        config(['prospect-list-builder.indexed_mail_dates' => true]);
        expect($this->prospects->count($filters, $date))->toBe(count($expected));

        $db->statement('CREATE TABLE TblProspectMailDateBuilds (
            id INTEGER PRIMARY KEY, Source_Version TEXT, Source_Snapshot TEXT, Previous_Build_ID INTEGER, Reusable BOOLEAN DEFAULT 1,
            Prospect_Max_PK INTEGER, Cursor_PK INTEGER DEFAULT 0, Published_At TEXT, Retired_At TEXT
        )');
        $db->statement('CREATE TABLE TblProspectMailDates (
            Build_ID INTEGER, Prospect_PK INTEGER, Mailed_On TEXT, PRIMARY KEY (Build_ID, Prospect_PK)
        )');
        $index = new ProspectMailDateIndex($db);
        $first = $index->refresh($date, 500, 1);
        expect($first['published'])->toBeFalse()
            ->and($index->publishedBuild($date))->toBeNull()
            ->and($this->prospects->count($filters, $date))->toBe(count($expected));

        $complete = $index->refresh($date, 500, 10);
        expect($complete)->toBe(['build' => $first['build'], 'cursor' => 2012, 'published' => true])
            ->and($index->publishedBuild($date))->toBe($first['build']);

        $db->enableQueryLog();
        expect($this->prospects->preview($filters, $columns, $date, 2)->pluck('prospect_id')->all())->toBe([10, 11]);
        $previewQuery = $db->getQueryLog()[array_key_last($db->getQueryLog())]['query'];
        $db->disableQueryLog();
        expect($previewQuery)->toContain('TblProspectMailDates')->not->toContain('SMS_Zip5');

        $nextDrop = Carbon::parse('2026-12-01');
        expect($index->publishedBuild($nextDrop))->toBeNull()
            ->and($this->prospects->preview($filters, $columns, $nextDrop, 2)->pluck('prospect_id')->all())->toBe([8, 2010]);
    }
    expect($this->prospects->count($filters, $date))->toBe(count($expected))
        ->and($this->prospects->preview($filters, $columns, $date, 2)->pluck('prospect_id')->all())->toBe([10, 11])
        ->and($this->prospects->diagnostics($filters, $date)['states'])->toBe(['CA' => count($expected)]);

    $this->prospects->eachBatch($filters, $columns, $date, count($expected), function ($rows) use (&$exportIds): void {
        array_push($exportIds, ...$rows->pluck('prospect_id')->all());
    });

    expect($exportIds)->toBe($expected);
    $this->prospects->stamp([range(10, 2009)], $date);
    expect($this->prospects->count($filters, $date))->toBe(2)
        ->and($this->prospects->preview($filters, $columns, $date, 2)->pluck('prospect_id')->all())->toBe([2010, 7]);

    $this->prospects->stamp([[2010]], $date);
    expect($this->prospects->count($filters, $date))->toBe(1)
        ->and($this->prospects->preview($filters, $columns, $date, 2)->pluck('prospect_id')->all())->toBe([7]);

    if ($indexed) {
        $db->table('TblMailers')->insert(['Client' => 'Older match', 'Address' => '1 Sample Way', 'SMS_Zip5' => '90210', 'Drop_Name' => 'recent']);
        expect($index->publishedBuild($date))->toBeNull()
            ->and($this->prospects->preview($filters, $columns, $date, 1)->first()->mailed_on)->toBe('2026-11-10');

        $db->table('TblProspectMailDateBuilds')->where('id', $first['build'])->update(['Retired_At' => now('UTC')->subHours(4)]);
        $pending = $index->refresh($date, 1000, 1);
        expect($pending['published'])->toBeFalse()
            ->and($index->publishedBuild($date))->toBeNull()
            ->and($db->table('TblProspectMailDates')->where('Build_ID', $first['build'])->where('Prospect_PK', 7)->exists())->toBeTrue();

        $refreshed = $index->refresh($date, 1000, 4);
        expect($refreshed['published'])->toBeTrue()
            ->and($refreshed['build'])->toBe($pending['build'])
            ->and($index->publishedBuild($date))->toBe($refreshed['build'])
            ->and($db->table('TblProspectMailDateBuilds')->where('id', $refreshed['build'])->value('Previous_Build_ID'))->toBe($first['build'])
            ->and($this->prospects->preview($filters, $columns, $date, 1)->first()->mailed_on)->toBe('2026-11-10');

        $db->table('TblMarketing')->where('Drop_Name', 'recent')->update(['Send_Date' => '2026-11-01']);
        $corrected = $index->refresh($date, 1000, 4);
        expect($corrected['published'])->toBeTrue()
            ->and($db->table('TblProspectMailDateBuilds')->where('id', $corrected['build'])->value('Previous_Build_ID'))->toBeNull()
            ->and($this->prospects->preview($filters, $columns, $date, 1)->first()->mailed_on)->toBe('2026-11-01');

        $db->table('TblMailers')->where('Client', 'Older match')->where('Drop_Name', 'recent')->update(['Address' => 'Corrected away']);
        $rebuilt = $index->refresh($date, 1000, 1, rebuild: true);
        expect($rebuilt['published'])->toBeFalse()->and($index->publishedBuild($date))->toBeNull();
        expect($index->refresh($date, 1000, 3)['build'])->toBe($rebuilt['build'])
            ->and($this->prospects->preview($filters, $columns, $date, 1)->first()->mailed_on)->toBe('2026-09-15');
    }
})->with(['direct mailing history' => false, 'indexed mail dates' => true]);

test('export results reconcile with the headers and populated values actually written to each CSV', function (?int $rowsPerFile, array $partSizes): void {
    config(['prospect-list-builder.disk' => 'fixture', 'prospect-list-builder.stamp_exports' => false]);
    $this->connection->table('TblProspects')->where('PK', 1)->update(['Address' => '10 Example Lane', 'Zip' => '90210-1234']);
    $this->connection->table('TblProspects')->where('PK', 7)->update(['State' => null]);
    $this->connection->table('TblProspects')->where('PK', 8)->update(['Current_Debt' => 0]);
    $this->connection->table('TblTUData')->where('PK', 90)->update(['blto02_v001name' => '=1+1']);
    $this->connection->statement('CREATE TABLE TblMailers (PK INTEGER PRIMARY KEY, Client TEXT, Address TEXT, SMS_Zip5 TEXT, External_ID TEXT)');
    $this->connection->table('TblMailers')->insert([
        ['PK' => 90, 'Client' => 'Taylor Example', 'Address' => '1 Former Lane', 'SMS_Zip5' => '90210', 'External_ID' => '1234567890'],
        ['PK' => 91, 'Client' => 'Taylor Example', 'Address' => '10 Example Lane', 'SMS_Zip5' => '90210', 'External_ID' => '001234567890'],
    ]);

    $previousApplication = Facade::getFacadeApplication();
    Facade::setFacadeApplication(Container::getInstance());
    try {
        $parts = [];
        $cache = new CacheRepository(new ArrayStore());
        $disk = Mockery::mock(\Illuminate\Filesystem\FilesystemAdapter::class);
        $disk->shouldReceive('put')->times(count($partSizes))->andReturnUsing(function (string $path, $stream) use (&$parts): bool {
            while (($record = fgetcsv($stream, escape: '')) !== false) {
                $parts[$path][] = $record;
            }

            return true;
        });
        $storage = Mockery::mock(\Illuminate\Filesystem\FilesystemManager::class);
        $storage->shouldReceive('disk')->with('fixture')->andReturn($disk);
        Storage::swap($storage);
        $cacheManager = Mockery::mock(\Illuminate\Cache\CacheManager::class);
        $cacheManager->shouldReceive('store')->with('database')->andReturn($cache);
        Cache::swap($cacheManager);
        $logger = Mockery::mock(\Psr\Log\LoggerInterface::class);
        $logger->shouldReceive('info')->once()->with('Prospect list exported.', Mockery::on(
            static fn (array $context): bool => $context['filter_keys'] === ['fields']
                && $context['field_filters'] === ['full_name']
                && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), 'Taylor Example'),
        ));
        Log::swap($logger);

        $key = ProspectListRuns::key('lendingtower', 'actor', 'export');
        $cache->put($key, ['status' => 'pending']);
        $filters = ['fields' => ['full_name' => ['operator' => 'equals', 'value' => 'Taylor Example']]];
        $columns = ['state', 'source', 'email', 'campaign_id', 'tu_blto02_v001name', 'debt', 'external_system_id', 'personal_loan_amount'];
        $date = Carbon::parse('2026-11-24');
        $plannedRows = $this->prospects->count($filters, $date);
        $preview = (new ProspectExportInspector())->preview($this->prospects->preview($filters, $columns, $date, 2), $columns, 7, ['operator' => 'all'], $plannedRows);
        expect($plannedRows)->toBe(5)
            ->and($preview['examples'][0]['values'])->toBe(['CA', 'mailers', 'taylor@example.test', '', "'=1+1", '20000', '001234567890', '17000'])
            ->and($this->connection->table('prospect_list_export_files')->count())->toBe(0);

        $job = new BuildProspectList($key, 'lendingtower', 'actor', 'export', $filters, $columns, '2026-11-24', 5, rowsPerFile: $rowsPerFile);
        $job->handle($this->prospects);

        $run = $cache->get($key);
        $headers = ['State', 'source', 'Email', 'campaign_id', 'BLTO02 V001 Name (BC102S)', 'Debt', 'external_system_id', 'personal_loan_amount'];
        $rows = [];
        $campaignIds = [];
        foreach ($partSizes as $index => $size) {
            $path = ProspectListRuns::artifact($key, $rowsPerFile === null ? null : $index + 1);
            expect($parts[$path][0])->toBe($headers)->and(count($parts[$path]) - 1)->toBe($size);
            $fileRows = array_slice($parts[$path], 1);
            $id = ProspectListExportFiles::find($key, $index + 1);
            expect($id)->toMatch('/\A[0-9]+\z/')->and(array_unique(array_column($fileRows, 3)))->toBe([$id]);
            $campaignIds[] = $id;
            array_push($rows, ...$fileRows);
        }
        expect(array_unique($campaignIds))->toBe($campaignIds)
            ->and($rows[0][4])->toBe("'=1+1")
            ->and(array_column($rows, 1))->toBe(array_fill(0, 5, 'mailers'))
            ->and(array_column($rows, 6))->toBe(['001234567890', '', '', '1234567890', ''])
            ->and(array_column($rows, 7))->toBe(['17000', '17000', '17000', '', '17000'])
            ->and($this->connection->table('TblProspects')->where('PK', 1)->value('Latest_Export_Date'))->toBeNull()
            ->and($run['count'])->toBe(count($rows))
            ->and($run['file_count'])->toBe(count($partSizes))
            ->and($run['rows_per_file'])->toBe($rowsPerFile)
            ->and($run['export_summary'])->toBe([
                'fields' => [
                    ['id' => 'state', 'label' => 'State', 'present' => 4],
                    ['id' => 'source', 'label' => 'source', 'present' => 5],
                    ['id' => 'email', 'label' => 'Email', 'present' => 4],
                    ['id' => 'campaign_id', 'label' => 'campaign_id', 'present' => 5],
                    ['id' => 'tu_blto02_v001name', 'label' => 'BLTO02 V001 Name (BC102S)', 'present' => 4],
                    ['id' => 'debt', 'label' => 'Debt', 'present' => 5],
                    ['id' => 'external_system_id', 'label' => 'external_system_id', 'present' => 2],
                    ['id' => 'personal_loan_amount', 'label' => 'personal_loan_amount', 'present' => 4],
                ],
                'states' => ['CA' => 4, 'Blank' => 1],
                'missing_mailer_id' => 3,
            ]);
    } finally {
        foreach (['cache', 'filesystem', 'log'] as $accessor) {
            Facade::clearResolvedInstance($accessor);
        }
        Facade::setFacadeApplication($previousApplication);
        Mockery::close();
    }
})->with([
    'single file' => [null, [5]],
    'last split file smaller' => [2, [2, 2, 1]],
    'exact split boundary' => [5, [5]],
]);

test('CSV parts stream three million rows with one million rows in each file', function (): void {
    $key = ProspectListRuns::key('lendingtower', 'actor', 'large-export');
    $disk = Mockery::mock(\Illuminate\Filesystem\FilesystemAdapter::class);
    $published = [];
    $disk->shouldReceive('put')->times(3)->andReturnUsing(function (string $path, $stream) use (&$published): bool {
        expect(fgetcsv($stream, escape: ''))->toBe(['State']);
        $rows = 0;
        while (! feof($stream)) {
            $rows += substr_count(fread($stream, 65_536), "\n");
        }
        $published[$path] = $rows;

        return true;
    });
    $files = new ProspectListCsvFiles($disk, $key, ['State'], 1_000_000);
    try {
        for ($row = 0; $row < 3_000_000; $row++) {
            $files->write(['CA']);
        }
        expect($files->finish(false))->toBe(3)->and($published)->toBe([
            ProspectListRuns::artifact($key, 1) => 1_000_000,
            ProspectListRuns::artifact($key, 2) => 1_000_000,
            ProspectListRuns::artifact($key, 3) => 1_000_000,
        ]);
    } finally {
        $files->close();
        Mockery::close();
    }
});

test('personal loan amount rounds positive raw TU debt up by exactly one thousand-dollar increment', function (mixed $debt, ?string $amount): void {
    expect(ProspectListFields::csvCell((object) ['personal_loan_amount' => $debt], 'personal_loan_amount', 'number'))->toBe($amount);
})->with([
    'below the boundary' => ['999.99', '1000'],
    'exact boundary' => ['1000.00', '1000'],
    'one cent above boundary' => ['1000.01', '2000'],
    'large exact decimal' => ['9007199254740000.01', '9007199254741000'],
    'missing' => [null, null],
    'zero debt' => ['0.00', null],
    'negative debt' => ['-0.01', null],
]);

test('a failed part upload discards only files belonging to that export', function (): void {
    $key = ProspectListRuns::key('lendingtower', 'actor', 'failed-export');
    $disk = Mockery::mock(\Illuminate\Filesystem\FilesystemAdapter::class);
    $disk->shouldReceive('put')->with(ProspectListRuns::artifact($key, 1), Mockery::any(), Mockery::any())->once()->andReturn(true);
    $disk->shouldReceive('put')->with(ProspectListRuns::artifact($key, 2), Mockery::any(), Mockery::any())->once()->andReturn(false);
    $disk->shouldReceive('deleteDirectory')->with(ProspectListRuns::artifactDirectory($key))->once()->andReturn(true);
    $files = new ProspectListCsvFiles($disk, $key, ['State'], 1);
    try {
        $files->write(['CA']);
        expect(fn () => $files->write(['NY']))->toThrow(RuntimeException::class, 'Unable to store the prospect list');
        $files->discard();
    } finally {
        $files->close();
        Mockery::close();
    }
});

test('campaign exports match the vendor layout and keep one campaign ID per downloaded file', function (?string $assignedMailerId): void {
    config(['prospect-list-builder.disk' => 'fixture', 'prospect-list-builder.stamp_exports' => true]);
    $this->connection->table('TblProspects')->where('PK', 1)->update([
        'Phone' => '+1 (202) 555-0101', 'Address' => '10 Example Lane', 'Zip' => '90210-1234',
    ]);
    $this->connection->table('TblProspects')->where('PK', 5)->update(['Phone' => 'not a phone', 'Latest_Export_Date' => null]);
    $this->connection->table('TblProspects')->where('PK', 7)->update([
        'Client' => 'Morgan Example', 'Phone' => '202.555.0107', 'Current_Debt_Source_PK' => 702,
    ]);
    $this->connection->table('TblProspects')->where('PK', 8)->update(['Address' => '20 Example Lane', 'Zip' => '90210']);
    $this->connection->statement('CREATE TABLE TblMailers (PK INTEGER PRIMARY KEY, Client TEXT, Address TEXT, SMS_Zip5 TEXT, External_ID TEXT)');
    $this->connection->table('TblMailers')->insert([
        ['PK' => 701, 'Client' => 'Taylor Example', 'Address' => '10 Example Lane', 'SMS_Zip5' => '90210', 'External_ID' => '000042'],
        ['PK' => 702, 'Client' => 'Morgan Example', 'Address' => '1 Former Lane', 'SMS_Zip5' => '90210', 'External_ID' => 'Linked702'],
        ['PK' => 703, 'Client' => 'Taylor Example', 'Address' => '20 Example Lane', 'SMS_Zip5' => '90210', 'External_ID' => 'MailerA'],
        ['PK' => 704, 'Client' => 'Taylor Example', 'Address' => '20 Example Lane', 'SMS_Zip5' => '90210', 'External_ID' => 'MailerB'],
    ]);

    $id = '11111111-1111-4111-8111-111111111111';
    $key = ProspectListRuns::key('lendingtower', 'actor', $id);
    $filters = ['months_since_pull' => 1];
    $headers = [
        'campaign_id', 'first_name', 'last_name', 'country_code', 'state_code',
        'mobile_number', 'external_system_id', 'source', 'personal_loan_amount',
    ];
    $store = new CacheRepository(new ArrayStore());
    $campaign = ['source' => 'Direct Mail'];
    if ($assignedMailerId !== null) {
        $campaign['mailer_id'] = $assignedMailerId;
    }
    $store->put($key, [
        'id' => $id, 'kind' => 'export', 'status' => 'pending', 'columns' => $headers,
        'campaign' => $campaign,
    ]);
    $cache = Mockery::mock(\Illuminate\Cache\CacheManager::class);
    $cache->shouldReceive('store')->with('database')->andReturn($store);
    Cache::swap($cache);
    $parts = [];
    $disk = Mockery::mock(\Illuminate\Filesystem\FilesystemAdapter::class);
    $disk->shouldReceive('put')->times(2)->andReturnUsing(function (string $path, $stream) use (&$parts): bool {
        while (($row = fgetcsv($stream, escape: '')) !== false) {
            $parts[$path][] = $row;
        }

        return true;
    });
    $disk->shouldReceive('exists')->andReturnUsing(static function (string $path) use (&$parts): bool {
        return isset($parts[$path]);
    });
    $disk->shouldReceive('temporaryUrl')->andReturnUsing(static fn ($path, $expires, $options) => $options['ResponseContentDisposition']);
    $storage = Mockery::mock(\Illuminate\Filesystem\FilesystemManager::class);
    $storage->shouldReceive('disk')->with('fixture')->andReturn($disk);
    Storage::swap($storage);
    $logger = Mockery::mock(\Psr\Log\LoggerInterface::class);
    $logger->shouldReceive('info')->once();
    Log::swap($logger);
    $responses = Mockery::mock(ResponseFactory::class);
    $responses->shouldReceive('json')->andReturnUsing(static fn (array $response) => new JsonResponse($response));
    Container::getInstance()->instance(ResponseFactory::class, $responses);

    try {
        $job = new BuildProspectList($key, 'lendingtower', 'actor', $id, $filters, array_keys(ProspectCampaignCsv::COLUMNS), '2026-11-24', 4, rowsPerFile: 2);
        $job->handle($this->prospects);

        $firstId = ProspectListExportFiles::find($key, 1);
        $secondId = ProspectListExportFiles::find($key, 2);
        expect($firstId)->toMatch('/\A[0-9]+\z/')
            ->and($secondId)->toMatch('/\A[0-9]+\z/')->not->toBe($firstId)
            ->and(ProspectListExportFiles::reserve($key, 1, $assignedMailerId))->toBe($firstId)
            ->and($parts[ProspectListRuns::artifact($key, 1)])->toBe([
                $headers,
                [$firstId, 'Taylor', 'Example', 'US', 'CA', '(202) 555-0101', $assignedMailerId ?? '000042', 'Direct Mail', ''],
                [$firstId, 'Morgan', 'Example', 'US', 'CA', '(202) 555-0107', $assignedMailerId ?? 'Linked702', 'Direct Mail', ''],
            ])
            ->and($parts[ProspectListRuns::artifact($key, 2)])->toBe([
                $headers,
                [$secondId, 'Taylor', 'Example', 'US', 'CA', '(555) 123-4567', $assignedMailerId ?? '', 'Direct Mail', ''],
            ])
            ->and($store->get($key)['export_summary']['invalid_mobile'])->toBe(1)
            ->and($store->get($key)['export_summary']['missing_mailer_id'])->toBe($assignedMailerId === null ? 1 : 0)
            ->and($this->connection->table('TblProspects')->where('PK', 5)->value('Latest_Export_Date'))->toBeNull()
            ->and($this->connection->table('TblProspects')->where('PK', 1)->value('Latest_Export_Date'))->not->toBeNull();

        $request = Request::create('/');
        $request->headers->set('X-Prospect-Tenant', 'lendingtower');
        $request->headers->set('X-Prospect-Actor', 'actor');
        $controller = new ProspectListController();
        foreach ([1 => $firstId, 2 => $secondId] as $part => $campaignId) {
            $response = ['url' => 'attachment; filename="'.$campaignId.'.csv"'];
            expect($controller->download($request, $id, (string) $part)->getData(true))->toBe($response)
                ->and($controller->download($request, $id, (string) $part)->getData(true))->toBe($response);
        }
        expect($this->connection->table('prospect_list_export_files')->count())->toBe(2);
    } finally {
        foreach (['cache', 'filesystem', 'log'] as $accessor) {
            Facade::clearResolvedInstance($accessor);
        }
        Mockery::close();
    }
})->with(['previously queued assigned ID' => ['Mailer007'], 'existing IDs' => [null]]);

test('TCPA screening runs before publishing the selected CSV columns and stamps only cleared rows', function (string $scenario): void {
    config([
        'prospect-list-builder.disk' => 'fixture',
        'prospect-list-builder.stamp_exports' => true,
        'prospect-list-builder.tcpa_scrub' => ['enabled' => false, 'username' => 'fixture-user', 'password' => 'fixture-pass'],
    ]);
    $this->connection->table('TblProspects')->where('PK', 1)->update(['Phone' => '+1 (202) 555-0101']);
    $this->connection->table('TblProspects')->where('PK', 5)->update(['Phone' => '2025550102', 'Latest_Export_Date' => null]);
    $this->connection->table('TblProspects')->where('PK', 6)->update(['Phone' => 'not a phone']);
    $this->connection->table('TblProspects')->where('PK', 7)->update(['Phone' => '2025550101', 'Client' => 'Taylor Duplicate Phone']);
    $this->connection->table('TblProspects')->where('PK', 8)->update(['Phone' => '2025550103']);

    $results = [['phone_number' => '2025550102', 'clean' => 0, 'is_bad_number' => true]];
    if ($scenario !== 'incomplete') {
        $results[] = ['phone_number' => 2025550101, 'clean' => $scenario === 'no clears' ? '0' : '1'];
    }
    $endpoint = 'https://api.tcpalitigatorlist.com/scrub/phones/';
    $http = new HttpFactory();
    $http->preventStrayRequests()->fake([$endpoint => HttpFactory::response(['results' => $results])]);
    Container::getInstance()->instance(TcpaLitigatorScrubber::class, new TcpaLitigatorScrubber($http));

    $previousApplication = Facade::getFacadeApplication();
    Facade::setFacadeApplication(Container::getInstance());
    Carbon::setTestNow(Carbon::parse('2026-11-24 12:00:00', 'America/Los_Angeles'));
    try {
        $csvRows = [];
        $cache = new CacheRepository(new ArrayStore());
        $disk = Mockery::mock(\Illuminate\Filesystem\FilesystemAdapter::class);
        $put = $disk->shouldReceive('put');
        if ($scenario === 'successful') {
            $put->once()->andReturnUsing(function (string $path, $stream) use (&$csvRows): bool {
                while (($row = fgetcsv($stream, escape: '')) !== false) {
                    $csvRows[] = $row;
                }

                return true;
            });
        } else {
            $put->never();
        }
        $storage = Mockery::mock(\Illuminate\Filesystem\FilesystemManager::class);
        $storage->shouldReceive('disk')->with('fixture')->andReturn($disk);
        Storage::swap($storage);
        $cacheManager = Mockery::mock(\Illuminate\Cache\CacheManager::class);
        $cacheManager->shouldReceive('store')->with('database')->andReturn($cache);
        Cache::swap($cacheManager);
        $logger = Mockery::mock(\Psr\Log\LoggerInterface::class);
        $logger->shouldReceive('info')->times($scenario === 'incomplete' ? 0 : 1);
        Log::swap($logger);

        $key = ProspectListRuns::key('lendingtower', 'actor', 'export');
        $cache->put($key, ['status' => 'pending']);
        $job = new BuildProspectList($key, 'lendingtower', 'actor', 'export', [], ['full_name'], '2026-11-24', 4, requireTcpaScrub: true);
        if ($scenario === 'incomplete') {
            expect(fn () => $job->handle($this->prospects))->toThrow(TcpaScrubUnavailable::class, 'did not verify every number');
            $job->failed(new TcpaScrubUnavailable());
            expect($cache->get($key))->toMatchArray(['status' => 'failed', 'failure_reason' => 'tcpa_scrub']);
        } else {
            $job->handle($this->prospects);
            $run = $cache->get($key);
            expect($run['status'])->toBe('completed')
                ->and($run['count'])->toBe($scenario === 'successful' ? 2 : 0)
                ->and($run['export_summary']['tcpa_scrub'])->toBe([
                    'matched' => $scenario === 'successful' ? 1 : 3,
                    'invalid_phone' => 1,
                ]);
        }

        $http->assertSentCount(1);
        $http->assertSent(static fn ($request): bool => $request->url() === $endpoint
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('fixture-user:fixture-pass'))
            && $request->data() === [
                'phones' => '["2025550101","2025550102"]', 'type' => 'tcpa', 'small_list' => 'true', 'sub_user' => 'list-builder',
            ]);
        expect($csvRows)->toBe($scenario === 'successful' ? [['Full Name'], ['Taylor Example'], ['Taylor Duplicate Phone']] : [])
            ->and($this->connection->table('TblProspects')->whereNotNull('Latest_Export_Date')->orderBy('PK')->pluck('PK')->all())
            ->toBe($scenario === 'successful' ? [1, 7] : []);
    } finally {
        Carbon::setTestNow();
        foreach (['cache', 'filesystem', 'log'] as $accessor) {
            Facade::clearResolvedInstance($accessor);
        }
        Facade::setFacadeApplication($previousApplication);
        Mockery::close();
    }
})->with(['successful', 'incomplete', 'no clears']);

test('the TCPA scrub rejects ambiguous or unsuccessful vendor responses', function (array $body, int $status): void {
    config(['prospect-list-builder.tcpa_scrub' => ['username' => 'fixture-user', 'password' => 'fixture-pass']]);
    $http = new HttpFactory();
    $http->preventStrayRequests()->fake(['https://api.tcpalitigatorlist.com/scrub/phones/' => HttpFactory::response($body, $status)]);
    $scrubber = new TcpaLitigatorScrubber($http);

    expect(fn () => $scrubber->filter(collect([(object) ['phone' => '2025550101']])))->toThrow(TcpaScrubUnavailable::class);
})->with([
    'bad credentials' => [['error' => 'Rejected'], 401],
    'rate limited' => [[], 429],
    'insufficient credits' => [['error' => 'Insufficient credits'], 200],
    'queued' => [['status' => 'in_queue', 'job_key' => 'fixture-job'], 200],
    'unexpected phone' => [['results' => [['phone_number' => '2025550102', 'clean' => 1]]], 200],
    'unknown decision' => [['results' => [['phone_number' => '2025550101', 'clean' => 'unknown']]], 200],
    'conflicting decision' => [['results' => [['phone_number' => '2025550101', 'clean' => 1, 'is_bad_number' => true]]], 200],
    'duplicate decision' => [['results' => [
        ['phone_number' => '2025550101', 'clean' => 1], ['phone_number' => '2025550101', 'clean' => 0],
    ]], 200],
]);

test('the count breakdown distinguishes restrictive criteria from missing source data', function (): void {
    $date = Carbon::parse('2026-11-24');
    $filters = ['score_min' => 700, 'contact' => 'phone_email', 'age_min' => 35];

    expect($this->prospects->count($filters, $date))->toBe(0)
        ->and($this->prospects->diagnostics($filters, $date))->toBe([
            'sample_size' => 5,
            'filters' => [
                ['key' => 'score', 'label' => null, 'matching' => 0, 'missing' => 1],
                ['key' => 'age', 'label' => null, 'matching' => 4, 'missing' => 1],
                ['key' => 'contact', 'label' => null, 'matching' => 4, 'missing' => 1],
            ],
            'states' => [],
        ]);
});

test('the breakdown uses one query and reconciles matching and missing states', function (): void {
    $this->connection->table('TblProspects')->where('PK', 5)->update(['State' => 'TX']);
    $this->connection->table('TblProspects')->where('PK', 6)->update(['State' => 'ca']);
    $this->connection->table('TblProspects')->where('PK', 8)->update(['State' => null]);
    $this->connection->enableQueryLog();

    $breakdown = $this->prospects->diagnostics(['score_min' => 650], Carbon::parse('2026-11-24'));

    expect($this->connection->getQueryLog())->toHaveCount(1)
        ->and($breakdown['sample_size'])->toBe(5)
        ->and($breakdown['states'])->toBe(['CA' => 2, 'TX' => 1])
        ->and($breakdown['filters'])->toBe([
            ['key' => 'score', 'label' => null, 'matching' => 4, 'missing' => 1],
        ]);
});

test('count progress advances only after the corresponding work and keeps failed counts incomplete', function (bool $fails): void {
    $previousApplication = Facade::getFacadeApplication();
    Facade::setFacadeApplication(Container::getInstance());
    try {
        $cache = new CacheRepository(new ArrayStore());
        $cacheManager = Mockery::mock(\Illuminate\Cache\CacheManager::class);
        $cacheManager->shouldReceive('store')->with('database')->andReturn($cache);
        Cache::swap($cacheManager);

        $key = ProspectListRuns::key('lendingtower', 'actor', 'count');
        $cache->put($key, ['status' => 'pending', 'count_stage' => 'queued']);
        $repository = Mockery::mock(ProspectListRepository::class);
        $repository->shouldReceive('count')->once()->andReturnUsing(function () use ($cache, $key, $fails): int {
            expect($cache->get($key))->toBe(['status' => 'pending', 'count_stage' => 'counting']);
            if ($fails) {
                throw new RuntimeException('Database unavailable');
            }

            return 0;
        });
        $summary = ['sample_size' => 5, 'filters' => [], 'states' => []];
        if ($fails) {
            $repository->shouldNotReceive('diagnostics');
        } else {
            $repository->shouldReceive('diagnostics')->once()->andReturnUsing(function () use ($cache, $key, $summary): array {
                expect($cache->get($key))->toBe(['status' => 'pending', 'count_stage' => 'summarizing', 'count' => 0]);

                return $summary;
            });
        }

        $job = new BuildProspectList($key, 'lendingtower', 'actor', 'count', [], null, '2026-11-24');
        if ($fails) {
            expect(fn () => $job->handle($repository))->toThrow(RuntimeException::class, 'Database unavailable');
            $job->failed(new RuntimeException('Database unavailable'));
            expect($cache->get($key))->toBe(['status' => 'failed', 'count_stage' => 'counting']);
        } else {
            $job->handle($repository);
            expect($cache->get($key))->toBe(['status' => 'completed', 'count_stage' => 'complete', 'count' => 0, 'diagnostics' => $summary]);
        }
    } finally {
        Facade::clearResolvedInstance('cache');
        Facade::setFacadeApplication($previousApplication);
        Mockery::close();
    }
})->with([false, true]);

test('workbook fields share the exported column definitions and their raw identifiers stay allowlisted', function (): void {
    $catalog = array_column(ProspectListFields::catalog()['columns'], null, 'id');

    expect($catalog)->toHaveCount(215)
        ->and($catalog['source']['csv_header'])->toBe('source')
        ->and($catalog['personal_loan_amount']['type'])->toBe('number')
        ->and($catalog['campaign_id']['export_only'])->toBeTrue()
        ->and($catalog['score']['description'])->toBe('VantageScore 4.0')
        ->and($catalog['tu_blto02_v001val']['column'])->toBe('t.blto02_v001val')
        ->and($catalog['tu_blto02_v001val']['description'])->toBe('Value of BC102S')
        ->and(array_column($catalog, 'column'))->not->toContain('t.dob', 't.person_socialSecurityNumber')
        ->and(array_unique(array_column($catalog, 'label')))->toHaveCount(count($catalog));
});

test('typed field slices compose with standard filters and limit the data written in requested order', function (): void {
    $date = Carbon::parse('2026-11-24');
    $filters = ['states' => ['CA'], 'fields' => [
        'tu_blto02_finscore' => ['operator' => 'range', 'minimum' => '7300', 'maximum' => '8000'],
        'tu_blto02_v001name' => ['operator' => 'equals', 'value' => 'BC102S'],
        'credit_as_of' => ['operator' => 'range', 'minimum' => '2026-11-01'],
        'tu_derivedfields1_unsecdebtbalance' => ['operator' => 'present'],
    ]];
    $captured = [];
    $this->prospects->eachBatch($filters, ['tu_blto02_finscore', 'tu_derivedfields1_unsecdebtbalance'], $date, 2,
        function ($rows) use (&$captured): void { $captured = $rows->all(); });

    expect($this->prospects->count($filters, $date))->toBe(4)
        ->and($captured[0])->toEqual((object) ['prospect_id' => 1, 'tu_blto02_finscore' => 7300, 'tu_derivedfields1_unsecdebtbalance' => 16000.25])
        ->and($this->prospects->diagnostics($filters, $date)['states'])->toBe(['CA' => 4])
        ->and($this->prospects->count(['fields' => ['tu_blto02_finscore' => ['operator' => 'missing']]], $date))->toBe(1);
});

test('field slicing rejects unknown fields, mismatched operators and invalid ranges', function (): void {
    $validator = new Factory(new Translator(new ArrayLoader(), 'en'));
    $errors = function (array $fields) use ($validator): array {
        $request = ProspectListRequest::create('/', 'POST', ['id' => '219ac986-0235-704b-9343-1c8c2850a3a0', 'filters' => ['fields' => $fields]]);

        return $validator->make($request->all(), $request->rules())->errors()->keys();
    };

    expect($errors(['tu_blto02_finscore' => ['operator' => 'range', 'minimum' => '5000', 'maximum' => '6000']]))->toBe([])
        ->and($errors(['tu_dob' => ['operator' => 'present']]))->toContain('filters.fields')
        ->and($errors(['tu_blto02_finscore' => ['operator' => 'equals', 'value' => '7500']]))->toContain('filters.fields.tu_blto02_finscore.operator')
        ->and($errors(['tu_blto02_finscore' => ['operator' => 'range', 'minimum' => '6000', 'maximum' => '5000']]))->toContain('filters.fields.tu_blto02_finscore.maximum')
        ->and($errors(['credit_as_of' => ['operator' => 'range']]))->toContain('filters.fields.credit_as_of.minimum');
});

test('a count stays valid when the same field slices are sent in a different order', function (): void {
    expect(ProspectListRuns::fingerprint(['fields' => [
        'score' => ['operator' => 'range', 'minimum' => '650'], 'email' => ['operator' => 'present'],
    ]]))->toBe(ProspectListRuns::fingerprint(['fields' => [
        'email' => ['operator' => 'present'], 'score' => ['minimum' => '650', 'operator' => 'range'],
    ]]));
});

test('export part size is optional and must fit inside the row amount', function (): void {
    $validator = new Factory(new Translator(new ArrayLoader(), 'en'));
    $errors = static function (mixed $size) use ($validator): array {
        $request = ProspectListRequest::create('/', 'POST', [
            'id' => '219ac986-0235-704b-9343-1c8c2850a3a0', 'count_id' => '219ac986-0235-704b-9343-1c8c2850a3a1',
            'filters' => [], 'columns' => ['phone'], 'row_limit' => 3_000_000, 'rows_per_file' => $size,
        ]);
        $request->setRouteResolver(static fn () => (new \Illuminate\Routing\Route(['POST'], 'exports', []))->name('cmd.prospect-list.exports'));

        return $validator->make($request->all(), $request->rules())->errors()->keys();
    };

    expect($errors(null))->toBe([])
        ->and($errors(1_000_000))->toBe([])
        ->and($errors(3_000_001))->toBe(['rows_per_file'])
        ->and($errors(0))->toBe(['rows_per_file'])
        ->and($errors(1.5))->toBe(['rows_per_file']);
});

test('new campaign requests resolve external IDs without accepting an override', function (): void {
    $validator = new Factory(new Translator(new ArrayLoader(), 'en'));
    $errors = static function (array $campaign) use ($validator): array {
        $request = ProspectListRequest::create('/', 'POST', [
            'id' => '219ac986-0235-704b-9343-1c8c2850a3a0', 'count_id' => '219ac986-0235-704b-9343-1c8c2850a3a1',
            'filters' => [], 'campaign' => $campaign, 'row_limit' => 1,
        ]);
        $request->setRouteResolver(static fn () => (new \Illuminate\Routing\Route(['POST'], 'exports', []))->name('cmd.prospect-list.exports'));

        return $validator->make($request->all(), $request->rules())->errors()->keys();
    };

    expect($errors(['source' => 'Direct Mail']))->toBe([])
        ->and($errors(['source' => 'Direct Mail', 'mailer_id' => 'Mailer007']))->toBe(['campaign']);
});

test('stamping advances two history dates only for selected people and preserves newer dates', function (): void {
    $this->prospects->stamp([[1]], Carbon::parse('2026-11-10'));
    $this->prospects->stamp([[1]], Carbon::parse('2026-10-10'));

    $people = $this->connection->table('TblProspects')->whereIn('PK', [1, 2])->orderBy('PK')->get();
    expect($people[0]->Latest_Export_Date)->toBe('2026-11-10')
        ->and($people[0]->Last_SMS_Date)->toBe('2026-11-10')
        ->and($people[1]->Latest_Export_Date)->toBeNull()
        ->and($people[1]->Last_SMS_Date)->toBe('2026-07-15');
});

test('the artifact keys are scoped by actor and the export catalog excludes SSN and birth date', function (): void {
    expect(ProspectListRuns::key('lendingtower', '1', 'export'))->not->toBe(ProspectListRuns::key('lendingtower', '2', 'export'))
        ->and(array_keys(ProspectListFields::columns()))->not->toContain('ssn', 'dob')
        ->and(ProspectListRuns::fingerprint(['states' => ['CA', 'TX'], 'debt_min' => 10]))
        ->toBe(ProspectListRuns::fingerprint(['debt_min' => 10, 'states' => ['TX', 'CA']]));
});

test('the internal route accepts an authenticated user for a configured tenant', function (string $tenantId): void {
    config([
        'prospect-list-builder.token' => 'fixture-secret',
        'prospect-list-builder.allowed_tenant_ids' => ['lendingtower', '90148bb7-0a61-4599-a2bd-be10a4c1583f'],
    ]);
    $request = Request::create('/api/internal/prospect-lists/catalog', server: [
        'HTTP_AUTHORIZATION' => 'Bearer fixture-secret',
        'HTTP_X_PROSPECT_TENANT' => $tenantId,
        'HTTP_X_PROSPECT_ACTOR' => '019ac986-0235-704b-9343-1c8c2850a3a0',
    ]);

    $response = (new ProspectListServiceAuth())->handle($request, fn (): Response => new Response('ok'));

    expect($response->getStatusCode())->toBe(200);
})->with([
    'legacy tenant slug' => ['lendingtower'],
    'tenant UUID' => ['90148bb7-0a61-4599-a2bd-be10a4c1583f'],
]);

test('the internal route rejects unauthorized tenant requests', function (array $headers, int $status): void {
    config([
        'prospect-list-builder.token' => 'fixture-secret',
        'prospect-list-builder.allowed_tenant_ids' => ['90148bb7-0a61-4599-a2bd-be10a4c1583f'],
    ]);
    $request = Request::create('/api/internal/prospect-lists/catalog', server: [
        'HTTP_AUTHORIZATION' => 'Bearer fixture-secret',
        'HTTP_X_PROSPECT_TENANT' => '90148bb7-0a61-4599-a2bd-be10a4c1583f',
        'HTTP_X_PROSPECT_ACTOR' => '019ac986-0235-704b-9343-1c8c2850a3a0',
        ...$headers,
    ]);

    expect(fn () => (new ProspectListServiceAuth())->handle($request, fn (): Response => new Response('ok')))
        ->toThrow(fn (HttpException $exception) => expect($exception->getStatusCode())->toBe($status));
})->with([
    'tenant outside the allowlist' => [['HTTP_X_PROSPECT_TENANT' => 'lendingtower'], 403],
    'missing tenant' => [['HTTP_X_PROSPECT_TENANT' => ''], 403],
    'invalid actor' => [['HTTP_X_PROSPECT_ACTOR' => 'invalid'], 403],
    'invalid token' => [['HTTP_AUTHORIZATION' => 'Bearer invalid'], 401],
]);

test('Azure age filters bind date bounds and parse the TU text field', function (): void {
    $connection = new SqlServerConnection(new PDO('sqlite::memory:'), config: ['driver' => 'sqlsrv']);
    $prospects = new class($connection) extends ProspectListRepository {
        public function __construct(private SqlServerConnection $database) {}

        protected function connection(): ConnectionInterface
        {
            return $this->database;
        }

        protected function withQueryTimeout(int $seconds, callable $callback): mixed
        {
            return $callback();
        }
    };

    $queries = $connection->pretend(fn () => $prospects->count(['age_min' => 35, 'age_max' => 45], Carbon::parse('2026-11-24')));

    expect($queries[0]['query'])->toContain("TRY_CONVERT(date, NULLIF(LTRIM(RTRIM([t].[DOB])), ''), 23) BETWEEN '1980-11-25' AND '1991-11-24'")
        ->and(array_slice($queries[0]['bindings'], -2))->toBe(['1980-11-25', '1991-11-24']);
});
