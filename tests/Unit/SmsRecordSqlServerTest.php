<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Carbon\Carbon;
use Cmd\Reports\Repositories\SmsProspectExportRepository;
use Cmd\Reports\Repositories\SmsRecordExportRepository;
use Cmd\Reports\Services\SmsDurableAvailabilityService;
use Cmd\Reports\Services\SmsRunService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Facade;
use PDO;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Hard-wired loopback only; creates/drops only its random sms_record_test_<hex> database. */
final class SmsRecordSqlServerTest extends TestCase
{
    private static string $database;
    private static ?PDO $admin = null;
    private Container $previous;
    private $db;
    private SmsRunService $runs;
    private const ID = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';

    public static function setUpBeforeClass(): void
    {
        if (! in_array('sqlsrv', PDO::getAvailableDrivers(), true)) self::markTestSkipped('Loopback SQL Server PDO is required.');
        self::$database = 'sms_record_test_'.bin2hex(random_bytes(8));
        self::$admin = new PDO('sqlsrv:Server=127.0.0.1,14330;Database=master;Encrypt=no;TrustServerCertificate=yes',
            'sa', 'LocalDev!2026Pass', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        self::$admin->exec('CREATE DATABASE ['.self::$database.'] COLLATE SQL_Latin1_General_CP1_CI_AS');
        self::$admin->exec('ALTER DATABASE ['.self::$database.'] SET ALLOW_SNAPSHOT_ISOLATION ON');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$admin !== null && preg_match('/^sms_record_test_[a-f0-9]{16}$/D', self::$database)) {
            self::$admin->exec('ALTER DATABASE ['.self::$database.'] SET SINGLE_USER WITH ROLLBACK IMMEDIATE');
            self::$admin->exec('DROP DATABASE ['.self::$database.']'); self::$admin = null;
        }
    }

    protected function setUp(): void
    {
        $this->previous = Container::getInstance();
        $container = new Container; Container::setInstance($container);
        $container->instance('config', new Repository(['app' => ['env' => 'testing'], 'sms-exports' => [
            'record_workflow_enabled' => true, 'durable_availability_enabled' => true], 'database' => ['default' => 'sqlsrv']]));
        $capsule = new Manager($container);
        $capsule->addConnection(['driver' => 'sqlsrv', 'host' => '127.0.0.1,14330', 'database' => self::$database,
            'username' => 'sa', 'password' => 'LocalDev!2026Pass', 'encrypt' => 'no', 'trust_server_certificate' => 'yes'], 'sqlsrv');
        $capsule->getDatabaseManager()->setDefaultConnection('sqlsrv');
        $container->instance('db', $capsule->getDatabaseManager());
        Facade::clearResolvedInstances(); Facade::setFacadeApplication($container);
        $this->db = $capsule->getConnection('sqlsrv');
        self::assertSame(self::$database, $this->db->selectOne('SELECT DB_NAME() AS name')->name);
        $this->db->unprepared('SET LOCK_TIMEOUT 3000; SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $this->db->setTransactionManager(new DatabaseTransactionsManager);
        if (! $this->db->getSchemaBuilder()->hasTable('TblProspects')) {
            $this->db->getSchemaBuilder()->create('TblPhoneNumbers', fn (Blueprint $t) => $t->string('Phone'));
            foreach (['2026_10_12_000001_create_sms_prospects_tables.php', '2026_10_19_000001_complete_sms_workflow.php',
                '2026_10_20_000001_create_sms_availability_summary.php', '2026_10_20_000003_add_sms_record_approval.php'] as $file) {
                (require dirname(__DIR__, 2).'/database/sms-migrations/'.$file)->up();
            }
        }
        $this->db->unprepared('DROP TRIGGER IF EXISTS dbo.SmsRecordRejectHistory');
        foreach (['TblSMSResponseAttribution','TblSMSHistory','TblProspectsExport','TblSMSDrops','TblSMSRuns','TblProspects',
            'TblSMSAvailabilitySummary','TblSMSLegacySends','TblSMSSuppressions','TblPhoneNumbers'] as $table) $this->db->table($table)->delete();
        $summary = \Mockery::mock(SmsDurableAvailabilityService::class);
        $summary->shouldReceive('request')->andReturn(['state' => 'pending']);
        $container->instance(SmsDurableAvailabilityService::class, $summary);
        $this->runs = new class(new SmsProspectExportRepository) extends SmsRunService {
            public array $queued = [];
            protected function dispatch(string $id, string $job): void { $this->queued[] = [$id,$job]; }
        };
        Carbon::setTestNow(Carbon::parse('2026-10-20T00:30:00Z'));
    }

    protected function tearDown(): void
    {
        $this->db?->disconnect(); \Mockery::close(); Carbon::setTestNow();
        Facade::clearResolvedInstances(); Container::setInstance($this->previous); Facade::setFacadeApplication($this->previous);
    }

    private function person(array $values = []): int
    {
        return (int) $this->db->table('TblProspects')->insertGetId($values + ['Client' => 'Synthetic Person','Address' => 'Fixture St',
            'Phone' => '0005550100','Zip' => '00123','Tier' => 'T1','Creation_Date' => '2026-10-01']);
    }

    private function selecting(int $target): SmsRecordExportRepository
    {
        $this->runs->start(self::ID, $target, 'synthetic@example.invalid');
        $this->db->table('TblSMSRuns')->where('Request_ID', self::ID)->update(['Status' => 'selecting']);
        return new SmsRecordExportRepository;
    }

    private function readyApproval(int $target): SmsRecordExportRepository
    {
        $repo = $this->selecting($target); $selected = $repo->select(self::ID, $target)['selected'];
        $drop = $this->db->table('TblSMSDrops')->insertGetId(['Request_ID' => self::ID,'Drop_Name' => 'SMS0001',
            'Debt_Tier' => 'T1','Amount_Dropped' => $selected,'Drop_Date' => '2026-10-19','Created_At' => '2026-10-20 00:00:00']);
        $this->db->table('TblProspectsExport')->update(['Debt_Amount' => 10000,'Debt_Source' => 'Tier','SMS_Drop_PK' => $drop,'Drop_Name' => 'SMS0001']);
        $this->db->table('TblSMSRuns')->update(['Status' => 'approving','Selected' => $selected,
            'Approved_At' => '2026-10-20 00:30:00','Approved_By' => 'synthetic@example.invalid']);
        return $repo;
    }

    public function test_real_schema_removes_only_phone_uniqueness_and_selects_exact_record_order(): void
    {
        $first = $this->person(['Creation_Date' => '2026-10-10']); $second = $this->person(['Creation_Date' => '2026-10-10']);
        $older = $this->person(['Creation_Date' => '2025-01-01']);
        $previous = $this->person(['Last_SMS_Date' => '2026-09-01']);
        $this->person(['Contacted' => 1]);
        $repo = $this->selecting(3);
        self::assertSame(['eligible' => 4,'never_texted' => 3,'texted_before' => 1], $repo->availability());
        self::assertSame(3, $repo->select(self::ID, 3)['selected']);
        self::assertSame([$first,$second,$older], array_map('intval', $this->db->table('TblProspectsExport')->orderBy('PK')->pluck('Prospect_PK')->all()));
        self::assertFalse($this->db->table('TblProspectsExport')->where('Prospect_PK', $previous)->exists());
        self::assertSame(['0005550100'], $this->db->table('TblProspectsExport')->distinct()->pluck('Phone')->all());
        self::assertSame(3, $this->db->table('TblProspectsExport')->count());
    }

    public function test_real_approval_is_pk_only_and_idempotent_for_shared_phone_records(): void
    {
        $selected = $this->person(['Creation_Date' => '2026-10-10']); $other = $this->person(['Creation_Date' => '2025-01-01']);
        $this->readyApproval(1);
        $this->runs->markApproved(self::ID); $this->runs->markApproved(self::ID);
        self::assertSame('2026-10-19', $this->db->table('TblProspects')->where('PK', $selected)->value('Last_SMS_Date'));
        self::assertNull($this->db->table('TblProspects')->where('PK', $other)->value('Last_SMS_Date'));
        self::assertSame(1, $this->db->table('TblSMSHistory')->count());
        self::assertSame(1, (int) $this->db->table('TblSMSAvailabilitySummary')->value('revision'));
        self::assertSame('approved', $this->db->table('TblSMSRuns')->value('Status'));
        self::assertSame(0, $this->db->table('TblSMSLegacySends')->count());
    }

    public function test_real_history_failure_rolls_back_approval_and_is_manually_retryable(): void
    {
        $this->person(); $this->readyApproval(1);
        $this->db->unprepared("CREATE TRIGGER dbo.SmsRecordRejectHistory ON dbo.TblSMSHistory AFTER INSERT AS BEGIN THROW 52901,'Synthetic history failure.',1; END");
        try { $this->runs->markApproved(self::ID); self::fail('Expected real SQL rollback.'); } catch (\Illuminate\Database\QueryException) {}
        self::assertNull($this->db->table('TblProspects')->value('Last_SMS_Date'));
        self::assertSame(0, (int) $this->db->table('TblProspects')->value('SMS_Attempts'));
        self::assertSame(1, $this->db->table('TblProspectsExport')->count()); self::assertSame(0, $this->db->table('TblSMSHistory')->count());
        self::assertSame('approving', $this->db->table('TblSMSRuns')->value('Status'));
        $this->db->unprepared('DROP TRIGGER dbo.SmsRecordRejectHistory');
        $this->runs->markApproved(self::ID);
        self::assertSame('approved', $this->db->table('TblSMSRuns')->value('Status'));
    }

    public function test_real_session_lock_rejects_competing_approval_and_delete(): void
    {
        $this->person(); $this->readyApproval(1);
        $other = new PDO('sqlsrv:Server=127.0.0.1,14330;Database='.self::$database.';Encrypt=no;TrustServerCertificate=yes',
            'sa','LocalDev!2026Pass',[PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $q = $other->prepare("DECLARE @r int; EXEC @r=sys.sp_getapplock @Resource=?,@LockMode='Exclusive',@LockOwner='Session',@LockTimeout=0; SELECT @r AS result");
        $q->execute(['sms:run:'.self::ID]); self::assertGreaterThanOrEqual(0, (int) $q->fetchColumn()); $q->closeCursor();
        try {
            foreach ([fn () => $this->runs->markApproved(self::ID),fn () => $this->runs->discard(self::ID)] as $action) {
                try { $action(); self::fail('Expected competing-session lock refusal.'); } catch (HttpException $error) { self::assertSame(409, $error->getStatusCode()); }
            }
            self::assertSame('approving', $this->db->table('TblSMSRuns')->value('Status'));
            self::assertNull($this->db->table('TblProspects')->value('Last_SMS_Date'));
        } finally {
            $release = $other->prepare("EXEC sys.sp_releaseapplock @Resource=?,@LockOwner='Session'");
            $release->execute(['sms:run:'.self::ID]); $release->closeCursor();
            $q = null; $other = null;
        }
        $this->runs->markApproved(self::ID);
        self::assertSame('approved', $this->db->table('TblSMSRuns')->value('Status'));
    }
}
