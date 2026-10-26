<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SmsDebtPleteImportMigrationTest extends TestCase
{
    private Container $previous;
    private mixed $db;

    protected function setUp(): void
    {
        $this->previous = Container::getInstance();
        $container = new Container;
        Container::setInstance($container);
        $container->instance('config', new Repository(['database' => ['default' => 'sqlsrv']]));
        $capsule = new Manager($container);
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:'], 'sqlsrv');
        $capsule->getDatabaseManager()->setDefaultConnection('sqlsrv');
        $container->instance('db', $capsule->getDatabaseManager());
        Facade::clearResolvedInstances(); Facade::setFacadeApplication($container);
        $this->db = $capsule->getConnection('sqlsrv');
    }

    protected function tearDown(): void
    {
        $this->db->disconnect();
        Facade::clearResolvedInstances(); Facade::setFacadeApplication($this->previous);
        Container::setInstance($this->previous);
    }

    private function migration(): object
    {
        return require dirname(__DIR__, 2).'/database/sms-migrations/2026_10_25_000001_create_sms_debtplete_imports.php';
    }

    private function approvalTable(): void
    {
        $this->db->getSchemaBuilder()->create('TblSMSRuns', function (Blueprint $table): void {
            $table->string('Request_ID', 36)->primary();
            foreach (['Status', 'Selection_Policy', 'Approved_By', 'Approved_At', 'Run_Options_JSON', 'Artifact_Manifest_JSON'] as $column) {
                $table->text($column)->nullable();
            }
        });
        $this->db->table('TblSMSRuns')->insert(['Request_ID' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'Status' => 'approved']);
    }

    public function test_missing_approval_prerequisite_creates_nothing(): void
    {
        try { $this->migration()->up(); self::fail('Missing approval schema must refuse.'); }
        catch (RuntimeException) {}
        self::assertFalse($this->db->getSchemaBuilder()->hasTable('TblSMSDebtPleteImports'));
    }

    public function test_creates_only_explicit_outbox_and_preserves_existing_approval_rows(): void
    {
        $this->approvalTable();
        $before = $this->db->table('TblSMSRuns')->get()->toJson();
        $this->migration()->up();
        $schema = $this->db->getSchemaBuilder();
        self::assertTrue($schema->hasColumns('TblSMSDebtPleteImports', [
            'Request_ID', 'Run_Sha256', 'Destination_JSON', 'Destination_Sha256', 'Plan_JSON', 'Plan_Sha256',
            'State', 'Remote_Receipt_JSON', 'Remote_Receipt_Sha256', 'Lease_Token', 'Lease_Version', 'Lease_Until',
            'Created_By', 'Created_At', 'Updated_At', 'Completed_At', 'Error_Code',
        ]));
        $indexes = array_column($schema->getIndexes('TblSMSDebtPleteImports'), null, 'name');
        self::assertSame(['State', 'Lease_Until', 'Request_ID'], $indexes['sms_debtplete_import_recovery']['columns']);
        self::assertSame($before, $this->db->table('TblSMSRuns')->get()->toJson());
        self::assertSame(0, $this->db->table('TblSMSDebtPleteImports')->count());
        self::assertSame(0, $this->db->transactionLevel());
    }

    public function test_existing_outbox_is_not_adopted_repaired_or_dropped(): void
    {
        $this->approvalTable();
        $this->db->getSchemaBuilder()->create('TblSMSDebtPleteImports', fn (Blueprint $table) => $table->string('retained'));
        $this->db->table('TblSMSDebtPleteImports')->insert(['retained' => 'audit-evidence']);
        try { $this->migration()->up(); self::fail('An existing object requires review.'); }
        catch (RuntimeException) {}
        self::assertSame(['retained'], $this->db->getSchemaBuilder()->getColumnListing('TblSMSDebtPleteImports'));
        self::assertSame('audit-evidence', $this->db->table('TblSMSDebtPleteImports')->value('retained'));
    }

    public function test_repeated_install_and_down_preserve_created_evidence(): void
    {
        $this->approvalTable(); $this->migration()->up();
        $row = [
            'Request_ID' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'Run_Sha256' => str_repeat('a', 64),
            'Destination_JSON' => '{}', 'Destination_Sha256' => str_repeat('b', 64), 'Plan_JSON' => '{}',
            'Plan_Sha256' => str_repeat('c', 64), 'State' => 'queued', 'Created_By' => 'fixture@example.invalid',
            'Created_At' => '2020-01-01 00:00:00', 'Updated_At' => '2020-01-01 00:00:00',
        ];
        $this->db->table('TblSMSDebtPleteImports')->insert($row);
        $before = $this->db->table('TblSMSDebtPleteImports')->first();
        self::assertSame(0, $before->Lease_Version); self::assertNull($before->Remote_Receipt_JSON);
        foreach (['up', 'down'] as $action) {
            try { $this->migration()->{$action}(); self::fail('Evidence must be preserved.'); }
            catch (RuntimeException) {}
            self::assertEquals($before, $this->db->table('TblSMSDebtPleteImports')->first());
        }
    }

    public function test_ambient_transaction_is_refused_without_rolling_back_the_callers_work(): void
    {
        $this->approvalTable(); $this->db->beginTransaction();
        try {
            try { $this->migration()->up(); self::fail('Ambient transaction must refuse.'); }
            catch (RuntimeException) {}
            self::assertSame(1, $this->db->transactionLevel());
            self::assertFalse($this->db->getSchemaBuilder()->hasTable('TblSMSDebtPleteImports'));
        } finally { $this->db->rollBack(); }
    }
}

