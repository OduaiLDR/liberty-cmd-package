<?php

declare(strict_types=1);

use Cmd\Reports\Services\SmsDeliveryPolicy;
use Cmd\Reports\Services\SmsReceivedDates;
use Cmd\Reports\Services\SmsRunOptions;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Facade;

beforeEach(function (): void {
    $this->datesOldContainer = Container::getInstance();
    $container = new Container; Container::setInstance($container);
    $this->datesConfig = new Repository(['database' => ['default' => 'sqlsrv'], 'sms-exports' => []]);
    $container->instance('config', $this->datesConfig);
    $capsule = new Manager($container);
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:'], 'sqlsrv');
    $capsule->getDatabaseManager()->setDefaultConnection('sqlsrv');
    $container->instance('db', $capsule->getDatabaseManager());
    Facade::clearResolvedInstances(); Facade::setFacadeApplication($container);
    $this->datesDb = $capsule->getConnection('sqlsrv');
    $schema = $this->datesDb->getSchemaBuilder();
    $schema->create('TblProspects', function (Blueprint $t): void { $t->integer('PK')->primary(); $t->date('Creation_Date')->nullable(); });
    $schema->create('TblTUData', function (Blueprint $t): void { $t->integer('PK')->primary(); $t->date('Creation_Date')->nullable(); });
    $schema->create('TblMailers', function (Blueprint $t): void { $t->integer('PK')->primary(); $t->string('Drop_Name')->nullable(); });
    $schema->create('TblMarketing', function (Blueprint $t): void { $t->string('Drop_Name'); $t->date('Send_Date')->nullable(); });
    $schema->create('TblProspectsExport', function (Blueprint $t): void {
        $t->integer('PK')->primary(); $t->string('Request_ID'); $t->integer('Prospect_PK'); $t->string('Debt_Source')->nullable(); $t->integer('Debt_Source_PK')->nullable();
    });
});

afterEach(function (): void {
    Facade::clearResolvedInstances(); Container::setInstance($this->datesOldContainer); Facade::setFacadeApplication($this->datesOldContainer);
});

test('the received-dates format has the five field-name columns and then the two dates', function (): void {
    expect(SmsRunOptions::CSV_FORMATS)->toContain(SmsRunOptions::RECEIVED_DATES_CSV_FORMAT)
        ->and(SmsRunOptions::RECEIVED_DATES_CSV_FORMAT)->toBe('sms-first-last-name-v3');
    expect(SmsDeliveryPolicy::headerForFormat(SmsRunOptions::RECEIVED_DATES_CSV_FORMAT))
        ->toBe(['first_name', 'last_name', 'debt_load', 'phone1', 'send_date', 'date_first_received', 'date_last_received']);
    $five = SmsDeliveryPolicy::rowForFormat(SmsRunOptions::FIELD_NAME_CSV_FORMAT, 'Synthetic Person', '1 Test St', '30000.00', '2025550101', '2026-11-23');
    expect(SmsDeliveryPolicy::rowForFormat(SmsRunOptions::RECEIVED_DATES_CSV_FORMAT, 'Synthetic Person', '1 Test St', '30000.00', '2025550101', '2026-11-23', '2026-10-06', '2026-11-10'))
        ->toBe([...$five, '2026-10-06', '2026-11-10']);
    // The older formats ignore the dates and keep their columns.
    expect(SmsDeliveryPolicy::rowForFormat(SmsRunOptions::FIELD_NAME_CSV_FORMAT, 'Synthetic Person', '1 Test St', '30000.00', '2025550101', '2026-11-23', '2026-10-06', '2026-11-10'))->toBe($five);
    $options = SmsRunOptions::normalize(10, 5, null, null, null, null, null, null, null, SmsRunOptions::RECEIVED_DATES_CSV_FORMAT);
    expect($options['csv_format'])->toBe(SmsRunOptions::RECEIVED_DATES_CSV_FORMAT);
});

test('the first date is never after the last, one known date fills both, and nothing is guessed', function (?string $first, ?string $last, array $expected): void {
    expect(SmsDeliveryPolicy::receivedDates($first, $last))->toBe($expected);
})->with([
    'both known' => ['2026-10-06', '2026-11-10', ['2026-10-06', '2026-11-10']],
    'same day (net new)' => ['2026-11-17', '2026-11-17', ['2026-11-17', '2026-11-17']],
    'first after last' => ['2026-11-12', '2026-11-10', ['2026-11-10', '2026-11-10']],
    'only first' => ['2026-10-06', null, ['2026-10-06', '2026-10-06']],
    'only last' => [null, '2026-11-10', ['2026-11-10', '2026-11-10']],
    'neither' => [null, null, ['', '']],
    'database datetime text' => ['2026-10-06 00:00:00.000', '2026-11-10 00:00:00', ['2026-10-06', '2026-11-10']],
    'not a date' => ['soon', '2026-13-40', ['', '']],
]);

test('new runs keep five columns unless the dates are switched on', function (): void {
    expect(SmsReceivedDates::enabled())->toBeFalse()->and(SmsReceivedDates::newRunFormat())->toBe(SmsRunOptions::FIELD_NAME_CSV_FORMAT);
    foreach (['true', 1, 'yes'] as $notExactlyTrue) {
        $this->datesConfig->set('sms-exports.received_dates_enabled', $notExactlyTrue);
        expect(SmsReceivedDates::newRunFormat())->toBe(SmsRunOptions::FIELD_NAME_CSV_FORMAT);
    }
    $this->datesConfig->set('sms-exports.received_dates_enabled', true);
    expect(SmsReceivedDates::newRunFormat())->toBe(SmsRunOptions::RECEIVED_DATES_CSV_FORMAT);
});

test('on SQL Server the dates need both covering indexes, keyed on PK and carrying the date column', function (array $rows, bool $ready): void {
    $db = Mockery::mock(Illuminate\Database\ConnectionInterface::class);
    $db->shouldReceive('getDriverName')->andReturn('sqlsrv');
    $db->shouldReceive('select')->once()->withArgs(fn (string $sql, array $bindings) => str_contains($sql, 'sys.indexes')
        && $bindings === ['IX_TblTUData_PK_Creation_Date', 'IX_TblMailers_PK_Drop_Name'])->andReturn(array_map(fn (array $r) => (object) $r, $rows));
    expect(SmsReceivedDates::ready($db))->toBe($ready);
})->with([
    'both there' => [[['Index_Name' => 'IX_TblTUData_PK_Creation_Date', 'Key_Columns' => 1, 'First_Key' => 'PK', 'Included' => 'Creation_Date'],
        ['Index_Name' => 'IX_TblMailers_PK_Drop_Name', 'Key_Columns' => '1', 'First_Key' => 'PK', 'Included' => 'Drop_Name']], true],
    'one missing' => [[['Index_Name' => 'IX_TblTUData_PK_Creation_Date', 'Key_Columns' => 1, 'First_Key' => 'PK', 'Included' => 'Creation_Date']], false],
    'none' => [[], false],
    'wrong key' => [[['Index_Name' => 'IX_TblTUData_PK_Creation_Date', 'Key_Columns' => 1, 'First_Key' => 'Creation_Date', 'Included' => null],
        ['Index_Name' => 'IX_TblMailers_PK_Drop_Name', 'Key_Columns' => 1, 'First_Key' => 'PK', 'Included' => 'Drop_Name']], false],
    'two key columns' => [[['Index_Name' => 'IX_TblTUData_PK_Creation_Date', 'Key_Columns' => 2, 'First_Key' => 'PK', 'Included' => 'Creation_Date'],
        ['Index_Name' => 'IX_TblMailers_PK_Drop_Name', 'Key_Columns' => 1, 'First_Key' => 'PK', 'Included' => 'Drop_Name']], false],
    'date column not included' => [[['Index_Name' => 'IX_TblTUData_PK_Creation_Date', 'Key_Columns' => 1, 'First_Key' => 'PK', 'Included' => null],
        ['Index_Name' => 'IX_TblMailers_PK_Drop_Name', 'Key_Columns' => 1, 'First_Key' => 'PK', 'Included' => 'Drop_Name']], false],
]);

test('a failing catalog read means not ready, so new runs keep five columns', function (): void {
    $db = Mockery::mock(Illuminate\Database\ConnectionInterface::class);
    $db->shouldReceive('getDriverName')->andReturn('sqlsrv');
    $db->shouldReceive('select')->andThrow(new RuntimeException('catalog unavailable'));
    expect(SmsReceivedDates::ready($db))->toBeFalse();
});

test('each person gets the day they were first added and the date of the record their debt came from', function (): void {
    $db = $this->datesDb;
    $db->table('TblProspects')->insert([['PK' => 1, 'Creation_Date' => '2026-10-06'], ['PK' => 2, 'Creation_Date' => '2026-09-01'], ['PK' => 3, 'Creation_Date' => '2026-08-15'],
        ['PK' => 4, 'Creation_Date' => '2026-11-10'], ['PK' => 5, 'Creation_Date' => '2026-07-01'], ['PK' => 6, 'Creation_Date' => '2026-11-12'], ['PK' => 7, 'Creation_Date' => '2026-06-01']]);
    $db->table('TblTUData')->insert([['PK' => 100, 'Creation_Date' => '2026-11-10'], ['PK' => 101, 'Creation_Date' => '2026-11-10'], ['PK' => 102, 'Creation_Date' => '2026-11-03']]);
    $db->table('TblMailers')->insert([['PK' => 100, 'Drop_Name' => 'DROP-A'], ['PK' => 200, 'Drop_Name' => 'DROP-A'], ['PK' => 201, 'Drop_Name' => 'DROP-MIXED'], ['PK' => 202, 'Drop_Name' => 'DROP-B']]);
    $db->table('TblMarketing')->insert([['Drop_Name' => 'DROP-A', 'Send_Date' => '2026-09-20'], ['Drop_Name' => 'DROP-A', 'Send_Date' => '2026-09-20'],
        ['Drop_Name' => 'DROP-B', 'Send_Date' => '2026-10-15'], ['Drop_Name' => 'DROP-MIXED', 'Send_Date' => '2026-09-01'], ['Drop_Name' => 'DROP-MIXED', 'Send_Date' => '2026-09-08']]);
    $db->table('TblProspectsExport')->insert([
        ['PK' => 1, 'Request_ID' => 'run', 'Prospect_PK' => 1, 'Debt_Source' => 'TblTUData', 'Debt_Source_PK' => 100],   // received again from TU
        ['PK' => 2, 'Request_ID' => 'run', 'Prospect_PK' => 2, 'Debt_Source' => 'TblMailers', 'Debt_Source_PK' => 200],  // mailer: the drop's date
        ['PK' => 3, 'Request_ID' => 'run', 'Prospect_PK' => 3, 'Debt_Source' => 'Tier', 'Debt_Source_PK' => 202],        // tier fallback is a mailer row too
        ['PK' => 4, 'Request_ID' => 'run', 'Prospect_PK' => 4, 'Debt_Source' => 'TblTUData', 'Debt_Source_PK' => 101],   // net new
        ['PK' => 5, 'Request_ID' => 'run', 'Prospect_PK' => 5, 'Debt_Source' => 'TblMailers', 'Debt_Source_PK' => 201],  // drop with two dates: unknown
        ['PK' => 6, 'Request_ID' => 'run', 'Prospect_PK' => 6, 'Debt_Source' => 'TblTUData', 'Debt_Source_PK' => 102],   // added after the record's date
        ['PK' => 7, 'Request_ID' => 'run', 'Prospect_PK' => 7, 'Debt_Source' => 'TblTUData', 'Debt_Source_PK' => 999],   // source row gone
        ['PK' => 8, 'Request_ID' => 'other', 'Prospect_PK' => 1, 'Debt_Source' => 'TblTUData', 'Debt_Source_PK' => 102],
    ]);
    $query = $db->table('TblProspectsExport as x')->where('x.Request_ID', 'run');
    $columns = SmsReceivedDates::join($query, 'x');
    $rows = $query->orderBy('x.Prospect_PK')->select(['x.Prospect_PK', ...$columns])->get();
    $out = [];
    foreach ($rows as $row) {
        $out[(int) $row->Prospect_PK] = SmsDeliveryPolicy::receivedDates(SmsReceivedDates::text($row->First_Received), SmsReceivedDates::text($row->Last_Received));
    }
    expect($out)->toBe([
        1 => ['2026-10-06', '2026-11-10'],
        2 => ['2026-09-01', '2026-09-20'],
        3 => ['2026-08-15', '2026-10-15'],
        4 => ['2026-11-10', '2026-11-10'],
        5 => ['2026-07-01', '2026-07-01'],
        6 => ['2026-11-03', '2026-11-03'],
        7 => ['2026-06-01', '2026-06-01'],
    ]);
    // The TU row is only used for a TU debt and the mailer row only for a mailer debt, even when the numbers are equal.
    expect($query->toSql())->toContain("x.Debt_Source = 'TblTUData'")->toContain("x.Debt_Source IN ('TblMailers','Tier')")
        ->not->toContain('WITH (INDEX(');
});

test('on SQL Server the two covering indexes are named in the query, so a missing one fails instead of reading the heaps', function (): void {
    $grammar = new Illuminate\Database\Query\Grammars\SqlServerGrammar($this->datesDb);
    $processor = new Illuminate\Database\Query\Processors\SqlServerProcessor;
    $connection = Mockery::mock(Illuminate\Database\Connection::class);
    $connection->shouldReceive('getDriverName')->andReturn('sqlsrv');
    $connection->shouldReceive('getQueryGrammar')->andReturn($grammar);
    $connection->shouldReceive('getPostProcessor')->andReturn($processor);
    $connection->shouldReceive('getTablePrefix')->andReturn('');
    $connection->shouldReceive('getDatabaseName')->andReturn('CommissionDatabase');
    $connection->shouldReceive('table')->andReturnUsing(fn (string $table) => (new Illuminate\Database\Query\Builder($connection, $grammar, $processor))->from($table));
    $query = (new Illuminate\Database\Query\Builder($connection, $grammar, $processor))->from('TblProspectsExport as x');
    SmsReceivedDates::join($query, 'x');
    expect($query->toSql())->toContain('TblTUData as rt WITH (INDEX(IX_TblTUData_PK_Creation_Date))')
        ->toContain('TblMailers as rm WITH (INDEX(IX_TblMailers_PK_Drop_Name))');
});

test('a seven-column batch builds, splits into parts and is published at approval with its two dates intact', function (): void {
    $root = str_replace(chr(92), '/', sys_get_temp_dir()).'/sms-received-dates-'.bin2hex(random_bytes(8));
    mkdir($root, 0700);
    try {
        $options = SmsRunOptions::normalize(3, 2, null, null, SmsRunOptions::PLANNED_DATE_VERSION, '2026-11-23', SmsRunOptions::APPROVAL_FILENAME_VERSION,
            null, null, SmsRunOptions::RECEIVED_DATES_CSV_FORMAT);
        $rows = array_map(fn (array $p): array => SmsDeliveryPolicy::rowForFormat(SmsRunOptions::RECEIVED_DATES_CSV_FORMAT, $p[0], '1 Test St', '30000', $p[1], '2026-11-23', $p[2], $p[3]), [
            ['Synthetic One', '2025550101', '2026-10-06', '2026-11-10'], ['Synthetic Two', '2025550102', '2026-11-17', '2026-11-17'], ['Synthetic Three', '2025550103', null, null]]);
        $pending = (new Cmd\Reports\Services\SmsRunArtifactBundle)->build($root.'/runs/11111111-2222-4333-8444-555555555555', 'SMS Export - 2026-11-23 - ID Pending', $rows, 3, $options);
        $approved = (new Cmd\Reports\Services\SmsRunArtifactBundle('v2'))->publishApproved($root.'/runs/11111111-2222-4333-8444-555555555555', $pending['manifest'], $options, 3, 'SMS0043-SMS0051');
        expect($approved['manifest']['csv_format'])->toBe(SmsRunOptions::RECEIVED_DATES_CSV_FORMAT)->and(count($approved['manifest']['parts']))->toBe(2);
        $zip = new ZipArchive; expect($zip->open($approved['path']))->toBeTrue();
        $lines = [];
        for ($i = 0; $i < $zip->numFiles; $i++) foreach (preg_split('/\r\n/', trim($zip->getFromIndex($i))) as $line) $lines[] = $line;
        $zip->close();
        expect($lines)->toBe([
            'first_name,last_name,debt_load,phone1,send_date,date_first_received,date_last_received',
            'Synthetic,One,30000,2025550101,2026-11-23,2026-10-06,2026-11-10',
            'Synthetic,Two,30000,2025550102,2026-11-23,2026-11-17,2026-11-17',
            'first_name,last_name,debt_load,phone1,send_date,date_first_received,date_last_received',
            'Synthetic,Three,30000,2025550103,2026-11-23,,',
        ]);
    } finally {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
        rmdir($root);
    }
});
