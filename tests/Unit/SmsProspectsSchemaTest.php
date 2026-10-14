<?php

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Str;

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
    $this->migration = require __DIR__.'/../../database/sms-migrations/2026_10_12_000001_create_sms_prospects_tables.php';
    $this->migration->up();
});

afterEach(function () {
    Facade::clearResolvedInstances();
    Container::setInstance($this->previousContainer);
    Facade::setFacadeApplication($this->previousContainer);
});

const PROSPECT_TABLES = ['TblProspectsPending', 'TblProspects', 'TblProspectEnrichmentLog', 'TblSMSDrops', 'TblSMSRuns', 'TblSMSHistory'];

test('prospect tables are created and dropped together', function () {
    $schema = $this->db->getSchemaBuilder();
    foreach (PROSPECT_TABLES as $table) expect($schema->hasTable($table))->toBeTrue();
    $this->migration->down();
    foreach (PROSPECT_TABLES as $table) expect($schema->hasTable($table))->toBeFalse();
});

test('a person is stored once and starts eligible with no SMS attempts', function () {
    $person = ['Client' => 'Jane Doe', 'Address' => '1 Main St', 'Zip' => '90210'];
    $this->db->table('TblProspects')->insert($person + ['Creation_Date' => '2026-10-12', 'Phone' => '2025550101']);
    $row = $this->db->table('TblProspects')->first();
    expect([(int) $row->SMS_Attempts, (int) $row->Contacted, (int) $row->Invalid_Phone, (int) $row->Opt_Out, $row->Latest_SMS_Date])
        ->toBe([0, 0, 0, 0, null]);
    expect(fn () => $this->db->table('TblProspects')->insert($person + ['Creation_Date' => '2026-10-13']))->toThrow(Exception::class);
    $this->db->table('TblProspectsPending')->insert($person + ['Created_At' => '2026-10-13 09:00:00']);
    expect(fn () => $this->db->table('TblProspectsPending')->insert($person + ['Created_At' => '2026-10-13 09:00:00']))
        ->toThrow(Exception::class);
});

test('an export run holds each phone and each prospect once, but a later run may repeat them', function () {
    $run = (string) Str::uuid();
    $row = fn (string $request, int $prospect, string $phone) => ['Request_ID' => $request, 'Prospect_PK' => $prospect, 'Phone' => $phone];
    $this->db->table('TblSMSHistory')->insert($row($run, 1, '2025550101'));
    expect(fn () => $this->db->table('TblSMSHistory')->insert($row($run, 2, '2025550101')))->toThrow(Exception::class);
    expect(fn () => $this->db->table('TblSMSHistory')->insert($row($run, 1, '2025550199')))->toThrow(Exception::class);
    $this->db->table('TblSMSHistory')->insert($row((string) Str::uuid(), 1, '2025550101'));
    expect($this->db->table('TblSMSHistory')->count())->toBe(2);
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
