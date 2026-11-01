<?php

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Str;

// SQLite stands in for SQL Server here; the SQL Server DDL itself is checked against a local SQL Server.
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
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($container);
    // TblPhoneNumbers already exists in production; the migration only adds Created_Date to it.
    $this->db->getSchemaBuilder()->create('TblPhoneNumbers', function (Blueprint $table) {
        $table->increments('PK');
        $table->string('Phone')->nullable();
    });
    $this->migration = require __DIR__.'/../../database/sms-migrations/2026_10_12_000001_create_sms_prospects_tables.php';
    $this->migration->up();
});

afterEach(function () {
    Facade::clearResolvedInstances();
    Container::setInstance($this->previousContainer);
    Facade::setFacadeApplication($this->previousContainer);
});

const PROSPECT_TABLES = ['TblProspects', 'TblProspectsExport', 'TblSMSDrops', 'TblSMSRuns', 'TblSMSHistory'];

test('live prospects contain neither debt nor tier while snapshots retain them', function () {
    $schema = $this->db->getSchemaBuilder();
    expect($schema->hasColumn('TblProspects', 'Debt_Tier'))->toBeFalse();
    expect($schema->hasColumn('TblProspects', 'Tier'))->toBeFalse();
    expect($schema->hasColumn('TblProspects', 'Debt_Amount'))->toBeFalse();
    expect($schema->hasColumn('TblProspectsExport', 'Debt_Tier'))->toBeTrue();
});

test('prospect tables are created and dropped together; TblPhoneNumbers keeps its rows', function () {
    $schema = $this->db->getSchemaBuilder();
    foreach (PROSPECT_TABLES as $table) expect($schema->hasTable($table))->toBeTrue();
    expect($schema->hasColumn('TblPhoneNumbers', 'Created_Date'))->toBeTrue();
    $this->db->table('TblPhoneNumbers')->insert(['Phone' => '2025550101', 'Created_Date' => '2026-10-14']);
    $this->migration->down();
    foreach (PROSPECT_TABLES as $table) expect($schema->hasTable($table))->toBeFalse();
    expect($schema->hasColumn('TblPhoneNumbers', 'Created_Date'))->toBeFalse();
    expect($this->db->table('TblPhoneNumbers')->pluck('Phone')->all())->toBe(['2025550101']);
});

test('a new prospect starts eligible: flags 0, no attempts, never exported', function () {
    $this->db->table('TblProspects')->insert(['Client' => 'Jane Doe', 'Address' => '1 Main St', 'Zip' => '90210',
        'Creation_Date' => '2026-10-12', 'Phone' => '2025550101']);
    $row = $this->db->table('TblProspects')->first();
    expect([(int) $row->SMS_Attempts, (int) $row->Contacted, (int) $row->Invalid_Phone, (int) $row->Opt_Out, $row->Latest_Export_Date, $row->Response_Date])
        ->toBe([0, 0, 0, 0, null, null]);
});

test('an export run holds each phone and each prospect once, but a later run may repeat them', function () {
    foreach (['TblProspectsExport', 'TblSMSHistory'] as $table) {
        $run = (string) Str::uuid();
        $extra = $table === 'TblSMSHistory' ? ['Export_Date' => '2026-10-16 10:00:00'] : [];
        $row = fn (string $request, int $prospect, string $phone) => ['Request_ID' => $request, 'Prospect_PK' => $prospect, 'Phone' => $phone] + $extra;
        $this->db->table($table)->insert($row($run, 1, '2025550101'));
        expect(fn () => $this->db->table($table)->insert($row($run, 2, '2025550101')))->toThrow(Exception::class);
        expect(fn () => $this->db->table($table)->insert($row($run, 1, '2025550199')))->toThrow(Exception::class);
        $this->db->table($table)->insert($row((string) Str::uuid(), 1, '2025550101'));
        expect($this->db->table($table)->count())->toBe(2);
    }
});

test('an SMS drop name is used once', function () {
    $drop = ['Drop_Name' => 'SMS0001', 'Debt_Tier' => 'T1', 'Drop_Date' => '2026-10-16', 'Created_At' => '2026-10-16 09:00:00'];
    $this->db->table('TblSMSDrops')->insert($drop);
    expect((int) $this->db->table('TblSMSDrops')->value('Amount_Dropped'))->toBe(0);
    expect(fn () => $this->db->table('TblSMSDrops')->insert($drop))->toThrow(Exception::class);
});

test('a run is tracked once per request and starts with nothing selected', function () {
    $run = ['Request_ID' => (string) Str::uuid(), 'Target' => 500, 'Status' => 'queued', 'Created_By' => 'a@example.com',
        'Created_At' => '2026-10-13 09:00:00', 'Updated_At' => '2026-10-13 09:00:00'];
    $this->db->table('TblSMSRuns')->insert($run);
    $row = $this->db->table('TblSMSRuns')->first();
    expect([$row->Selected, $row->File_Path, $row->Sent_At, $row->Failed_Step])->toBe([null, null, null, null]);
    expect(fn () => $this->db->table('TblSMSRuns')->insert($run))->toThrow(Exception::class);
});
