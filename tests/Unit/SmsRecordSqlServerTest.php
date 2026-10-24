<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Carbon\Carbon;
use Cmd\Reports\Repositories\SmsProspectExportRepository;
use Cmd\Reports\Repositories\SmsRecordExportRepository;
use Cmd\Reports\Services\SmsDurableAvailabilityService;
use Cmd\Reports\Services\SmsContactAttribution;
use Cmd\Reports\Services\SmsContactEvent;
use Cmd\Reports\Services\SmsRunService;
use Cmd\Reports\Services\SmsRunOptions;
use Cmd\Reports\Services\SmsTierWindowSchema;
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
    private string $directory;
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
        $container = new class extends Container {
            public function environment(...$values): string|bool { return $values === [] ? 'testing' : in_array('testing', $values, true); }
            public function abort($code, $message = '', array $headers = []): never { throw new HttpException($code, $message, null, $headers); }
        };
        Container::setInstance($container);
        $this->directory = sys_get_temp_dir().'/sms-tier-record-test-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $container->instance('config', new Repository(['app' => ['env' => 'testing'], 'sms-exports' => [
            'record_workflow_enabled' => true, 'durable_availability_enabled' => true, 'local_root' => $this->directory], 'database' => ['default' => 'sqlsrv']]));
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
            $this->db->getSchemaBuilder()->create('TblPhoneNumbers', fn (Blueprint $t) => $t->string('Phone')->nullable()->index('IX_TblPhoneNumbers_SMS_Phone'));
            foreach (['2026_10_12_000001_create_sms_prospects_tables.php', '2026_10_19_000001_complete_sms_workflow.php',
                '2026_10_20_000001_create_sms_availability_summary.php', '2026_10_20_000003_add_sms_record_approval.php'] as $file) {
                (require dirname(__DIR__, 2).'/database/sms-migrations/'.$file)->up();
            }
        }
        if (! $this->db->getSchemaBuilder()->hasColumn('TblSMSRuns', 'Run_Options_JSON')) {
            (require dirname(__DIR__, 2).'/database/sms-migrations/2026_10_22_000001_add_sms_run_file_options.php')->up();
            $this->db->unprepared('CREATE TABLE dbo.TblTUData (PK int PRIMARY KEY, Creation_Date date NULL, ncoaAddr_frstNme varchar(100), ncoaAddr_lastNme varchar(100), ncoaAddr_addrLine1Txt nvarchar(255), ncoaAddr_zipCde nvarchar(10), SMS_Zip5 AS LEFT(ncoaAddr_zipCde,5), derivedFields1_unsecdebtbalance decimal(18,2), Tier_Tag varchar(30));
                CREATE TABLE dbo.TblMailers (PK int PRIMARY KEY, Client nvarchar(50), Address nvarchar(50), Zip nvarchar(50), SMS_Zip5 AS LEFT(Zip,5), Drop_Name nvarchar(50), Debt_Amount decimal(10,2), Debt_Tier nvarchar(5));
                CREATE TABLE dbo.TblMarketing (Drop_Name nvarchar(50), Send_Date date NULL)');
            foreach ($this->windowIndexes() as $ddl) $this->db->unprepared($ddl);
        }
        if (! $this->db->getSchemaBuilder()->hasColumn('TblSMSAvailabilitySummary', 'tu_date_verified')) {
            (require dirname(__DIR__, 2).'/database/sms-migrations/2026_10_22_000002_add_sms_tu_summary_date_proof.php')->up();
        }
        if (! $this->db->getSchemaBuilder()->hasColumn('TblSMSRuns', 'Approved_At_Precision')) {
            (require dirname(__DIR__, 2).'/database/sms-migrations/2026_10_23_000001_add_sms_contact_attribution_evidence.php')->up();
        }
        if (! $this->db->getSchemaBuilder()->hasColumn('TblSMSRuns', 'Archive_Receipt_JSON')) {
            (require dirname(__DIR__, 2).'/database/sms-migrations/2026_10_23_000002_add_sms_run_archive_receipt.php')->up();
        }
        if (! $this->db->getSchemaBuilder()->hasTable('TblSMSEmailOutbox')) {
            (require dirname(__DIR__, 2).'/database/sms-migrations/2026_10_23_000003_create_sms_email_outbox.php')->up();
        }
        if (! $this->db->getSchemaBuilder()->hasTable('TblSMSFilePublications')) {
            (require dirname(__DIR__, 2).'/database/sms-migrations/2026_10_23_000004_create_sms_file_publications.php')->up();
        }
        $this->db->unprepared('DROP TRIGGER IF EXISTS dbo.SmsRecordRejectHistory');
        $this->db->unprepared('DROP TRIGGER IF EXISTS dbo.SmsRecordRejectSelection');
        $this->db->unprepared('DROP TRIGGER IF EXISTS dbo.SmsRecordRejectContact');
        foreach (['TblSMSFilePublicationParts','TblSMSFilePublications','TblSMSEmailOutbox','TblSMSResponseAttribution','TblSMSHistory','TblProspectsExport','TblSMSDrops','TblSMSRuns','TblProspects',
            'TblSMSAvailabilitySummary','TblSMSLegacySends','TblSMSSuppressions','TblPhoneNumbers','TblTUData','TblMailers','TblMarketing'] as $table) $this->db->table($table)->delete();
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
        $root = realpath($this->directory); $temp = realpath(sys_get_temp_dir());
        if ($root !== false && $temp !== false && ! is_link($this->directory)
            && str_starts_with(str_replace('\\', '/', $root), str_replace('\\', '/', $temp).'/sms-tier-record-test-')) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                if ($file->isDir() && ! $file->isLink()) rmdir($file->getPathname()); else unlink($file->getPathname());
            }
            rmdir($root);
        }
        Facade::clearResolvedInstances(); Container::setInstance($this->previous); Facade::setFacadeApplication($this->previous);
    }

    private function person(array $values = []): int
    {
        return (int) $this->db->table('TblProspects')->insertGetId($values + ['Client' => 'Synthetic Person','Address' => 'Fixture St',
            'Phone' => '0005550100','Zip' => '00123','Creation_Date' => '2026-10-01']);
    }

    private function windowIndexes(): array
    {
        return [
            'never' => 'CREATE INDEX IX_TblProspects_SMS_Never_Priority ON dbo.TblProspects(Creation_Date DESC,PK ASC) INCLUDE (Phone,Client,Address,City,State,Zip,Email,Last_SMS_Date) WHERE Contacted=0 AND Opt_Out=0 AND Invalid_Phone=0 AND Phone IS NOT NULL AND Last_SMS_Date IS NULL',
            'previous' => 'CREATE INDEX IX_TblProspects_SMS_Previous_Priority ON dbo.TblProspects(Last_SMS_Date ASC,PK ASC) INCLUDE (Phone,Client,Address,City,State,Zip,Email,Creation_Date) WHERE Contacted=0 AND Opt_Out=0 AND Invalid_Phone=0 AND Phone IS NOT NULL AND Last_SMS_Date IS NOT NULL',
            'tu' => 'CREATE INDEX IX_TblTUData_SMS_Identity ON dbo.TblTUData(ncoaAddr_addrLine1Txt,SMS_Zip5) INCLUDE (PK,Creation_Date,ncoaAddr_frstNme,ncoaAddr_lastNme,derivedFields1_unsecdebtbalance,Tier_Tag)',
            'mailers' => 'CREATE INDEX IX_TblMailers_SMS_Identity ON dbo.TblMailers(Client,Address,SMS_Zip5) INCLUDE (PK,Drop_Name,Debt_Amount,Debt_Tier)',
            'marketing' => 'CREATE INDEX IX_TblMarketing_SMS_Drop_SendDate ON dbo.TblMarketing(Drop_Name,Send_Date)',
        ];
    }

    private function selecting(int $target): SmsRecordExportRepository
    {
        $this->runs->start(self::ID, $target, 'synthetic@example.invalid');
        $this->db->table('TblSMSRuns')->where('Request_ID', self::ID)->update(['Status' => 'selecting']);
        return new SmsRecordExportRepository;
    }

    private function source(int $pk, string $address, string $tier = 'T2', string $debt = '13500'): void
    {
        $this->db->table('TblTUData')->insert(['PK' => $pk, 'Creation_Date' => '2026-10-01',
            'ncoaAddr_frstNme' => 'Synthetic', 'ncoaAddr_lastNme' => 'Person', 'ncoaAddr_addrLine1Txt' => $address,
            'ncoaAddr_zipCde' => '00123-9999', 'derivedFields1_unsecdebtbalance' => $debt, 'Tier_Tag' => $tier]);
    }

    private function tierRun(int $target, array $tiers): array
    {
        config(['sms-exports.run_file_options_enabled' => true, 'sms-exports.tier_selection_enabled' => true,
            'sms-exports.tier_selection_windowed_enabled' => true, 'sms-exports.tier_selection_window_size' => 2]);
        $options = SmsRunOptions::normalize($target, 1, $tiers, SmsRunOptions::WINDOWED_TIER_SELECTION_POLICY);
        $this->runs->start(self::ID, $target, 'synthetic@example.invalid', $options);
        return $options;
    }

    private function readyApproval(int $target): SmsRecordExportRepository
    {
        $repo = $this->selecting($target); $selected = $repo->select(self::ID, $target)['selected'];
        $drop = $this->db->table('TblSMSDrops')->insertGetId(['Request_ID' => self::ID,'Drop_Name' => 'SMS0001',
            'Debt_Tier' => 'T1','Amount_Dropped' => $selected,'Drop_Date' => '2026-10-19','Created_At' => '2026-10-20 00:00:00']);
        $this->db->table('TblProspectsExport')->update(['Debt_Amount' => 10000,'Debt_Tier' => 'T1','Debt_Source' => 'TblTUData','SMS_Drop_PK' => $drop,'Drop_Name' => 'SMS0001']);
        $this->db->table('TblSMSRuns')->update(['Status' => 'approving','Selected' => $selected,
            'Approved_At' => '2026-10-20 00:30:00','Approved_By' => 'synthetic@example.invalid']);
        return $repo;
    }

    private function enableContactAttribution(): void
    {
        config(['sms-exports.contact_attribution_enabled' => true, 'sms-exports.contact_source_system' => 'fixture.contacts',
            'sms-exports.contact_source_contract_sha256' => str_repeat('a', 64)]);
        Carbon::setTestNow(Carbon::parse('2026-10-23T12:00:00.500000Z'));
    }

    private function contactEvent(int $pk): SmsContactEvent
    {
        return SmsContactEvent::fromCanonical($pk, '0005550100', '2026-10-22T06:59:59.999999999Z',
            'fixture.contacts', 'fixture-1', str_repeat('a', 64), 1, true);
    }

    public function test_real_contact_evidence_preserves_nine_digits_and_updates_only_one_pk_atomically(): void
    {
        $pk = $this->person(['Creation_Date' => '2026-10-10', 'Response_Date' => '2026-10-01']);
        $other = $this->person(['Creation_Date' => '2025-01-01']);
        $this->readyApproval(1); $this->runs->markApproved(self::ID);
        $this->db->table('TblSMSRuns')->update(['Approved_At' => '2026-10-21 12:00:00.123456', 'Approved_At_Precision' => 6]);
        $this->enableContactAttribution();
        $this->db->enableQueryLog(); $this->db->flushQueryLog();
        try {
            $result = (new SmsContactAttribution)->record($this->contactEvent($pk));
            $queries = implode("\n", array_column($this->db->getQueryLog(), 'query'));
        } finally { $this->db->disableQueryLog(); }
        self::assertSame('recorded', $result['outcome']); self::assertSame(self::ID, strtolower($result['request_id']));
        $row = $this->db->table('TblSMSResponseAttribution')->first();
        self::assertSame('2026-10-22T06:59:59.999999999Z', $row->Contact_At_UTC_Exact);
        self::assertSame('2026-10-22 06:59:59.9999999', $row->Contact_At_UTC);
        self::assertSame('2026-10-21', $row->Contacted_Date);
        self::assertSame('fixture-1', $row->Source_Contact_ID);
        self::assertSame('2026-10-01', $this->db->table('TblProspects')->where('PK', $pk)->value('Response_Date'));
        self::assertSame('2026-10-19', $this->db->table('TblProspects')->where('PK', $pk)->value('Last_SMS_Date'));
        self::assertSame(0, (int) $this->db->table('TblProspects')->where('PK', $other)->value('Contacted'));
        self::assertSame(1, $this->db->table('TblSMSHistory')->count());
        self::assertMatchesRegularExpression('/select top 1001/i', $queries);
        self::assertSame('already_recorded', (new SmsContactAttribution)->record($this->contactEvent($pk))['outcome']);
        self::assertSame(1, $this->db->table('TblSMSResponseAttribution')->count());
    }

    public function test_real_contact_and_approval_share_one_session_lock_and_pending_receipt_holds(): void
    {
        $pk = $this->person(); $this->readyApproval(1); $this->enableContactAttribution();
        $settings = $this->db->getConfig();
        self::assertSame('127.0.0.1,14330', $settings['host']); self::assertSame(self::$database, $settings['database']);
        $other = new PDO('sqlsrv:Server='.$settings['host'].';Database='.$settings['database'].';Encrypt=no;TrustServerCertificate=yes',
            $settings['username'], $settings['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $q = $other->prepare("DECLARE @r int; EXEC @r=sys.sp_getapplock @Resource=?,@LockMode='Exclusive',@LockOwner='Session',@LockTimeout=0; SELECT @r AS result");
        $q->execute(['sms:contact-attribution']); self::assertGreaterThanOrEqual(0, (int) $q->fetchColumn()); $q->closeCursor();
        try {
            foreach ([fn () => (new SmsContactAttribution)->record($this->contactEvent($pk)),
                fn () => $this->runs->requestApprove(self::ID, 'fixture@example.invalid'), fn () => $this->runs->markApproved(self::ID)] as $action) {
                try { $action(); self::fail('Expected shared coordination lock refusal.'); }
                catch (HttpException $error) { self::assertSame(409, $error->getStatusCode()); }
            }
        } finally {
            $release = $other->prepare("EXEC sys.sp_releaseapplock @Resource=?,@LockOwner='Session'");
            $release->execute(['sms:contact-attribution']); $release->closeCursor(); $q = $other = null;
        }
        self::assertSame('approval_in_progress', (new SmsContactAttribution)->record($this->contactEvent($pk))['reason']);
        self::assertSame(0, $this->db->table('TblSMSResponseAttribution')->count());
        $this->runs->markApproved(self::ID);
        self::assertSame('recorded', (new SmsContactAttribution)->record($this->contactEvent($pk))['outcome']);
    }

    public function test_real_contact_update_failure_rolls_back_evidence_and_releases_coordination_lock(): void
    {
        $pk = $this->person(); $this->enableContactAttribution();
        $this->db->unprepared("CREATE TRIGGER dbo.SmsRecordRejectContact ON dbo.TblProspects AFTER UPDATE AS BEGIN THROW 52903,'Synthetic contact failure.',1; END");
        try { (new SmsContactAttribution)->record($this->contactEvent($pk)); self::fail('Expected real contact rollback.'); }
        catch (\Illuminate\Database\QueryException) {}
        self::assertSame(0, (int) $this->db->table('TblProspects')->value('Contacted'));
        self::assertNull($this->db->table('TblProspects')->value('Contacted_Date'));
        self::assertSame(0, $this->db->table('TblSMSResponseAttribution')->count());
        self::assertSame(0, $this->db->table('TblSMSAvailabilitySummary')->count());
        $this->db->unprepared('DROP TRIGGER dbo.SmsRecordRejectContact');
        self::assertSame('recorded', (new SmsContactAttribution)->record($this->contactEvent($pk))['outcome']);
    }

    public function test_real_new_approval_freezes_microsecond_acceptance_and_california_day_separate_from_monday(): void
    {
        $pk = $this->person(['Address' => 'J4 fixture']); $this->source(1, 'J4 fixture');
        $this->tierRun(1, ['T2']); $this->runs->prepare(self::ID);
        $this->runs->requestFile(self::ID); $this->runs->buildFile(self::ID);
        $this->enableContactAttribution(); Carbon::setTestNow(Carbon::parse('2026-10-22T06:59:59.987654Z'));
        $this->runs->requestApprove(self::ID, 'first@example.invalid');
        self::assertSame('2026-10-22 06:59:59.9876540', $this->db->table('TblSMSRuns')->value('Approved_At'));
        self::assertSame(6, (int) $this->db->table('TblSMSRuns')->value('Approved_At_Precision'));
        Carbon::setTestNow(Carbon::parse('2026-10-24T12:00:00Z'));
        $this->runs->requestApprove(self::ID, 'later@example.invalid'); $this->runs->markApproved(self::ID);
        self::assertSame('2026-10-21', $this->db->table('TblProspects')->where('PK', $pk)->value('Last_SMS_Date'));
        self::assertSame('2026-10-26', $this->db->table('TblSMSDrops')->value('Drop_Date'));
        self::assertSame('first@example.invalid', $this->db->table('TblSMSRuns')->value('Approved_By'));
        self::assertSame('2026-10-22 06:59:59.9876540', $this->db->table('TblSMSRuns')->value('Approved_At'));
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

    public function test_real_tier_selection_filters_before_quantity_and_approval_retains_same_phone_pk_scope(): void
    {
        $wrong = $this->person(['Address' => 'Wrong tier', 'Creation_Date' => '2026-10-20']);
        $a = $this->person(['Address' => 'Allowed A', 'Creation_Date' => '2026-10-10']);
        $b = $this->person(['Address' => 'Allowed B', 'Creation_Date' => '2026-10-10']);
        $previous = $this->person(['Address' => 'Previously', 'Last_SMS_Date' => '2026-01-01']);
        $invalid = $this->person(['Address' => 'Invalid latest', 'Creation_Date' => '2026-10-21']);
        $this->source(1, 'Wrong tier', 'T9'); $this->source(2, 'Allowed A'); $this->source(3, 'Allowed B');
        $this->source(4, 'Previously'); $this->source(5, 'Invalid latest', 'T2', '0');
        $this->tierRun(2, ['T2']);
        $before = $this->db->selectOne('SELECT transaction_isolation_level AS isolation_level FROM sys.dm_exec_sessions WHERE session_id=@@SPID')->isolation_level;
        $this->runs->prepare(self::ID);
        self::assertSame([$a,$b], array_map('intval', $this->db->table('TblProspectsExport')->orderBy('PK')->pluck('Prospect_PK')->all()));
        $view = $this->runs->show(self::ID);
        self::assertSame(2, $view->selected); self::assertSame(0, $view->shortfall);
        self::assertSame([14000,14000], array_map('intval', $this->db->table('TblProspectsExport')->orderBy('PK')->pluck('Debt_Amount')->all()));
        self::assertSame([2,3], array_map('intval', $this->db->table('TblProspectsExport')->orderBy('PK')->pluck('Debt_Source_PK')->all()));
        self::assertSame((int) $before, (int) $this->db->selectOne('SELECT transaction_isolation_level AS isolation_level FROM sys.dm_exec_sessions WHERE session_id=@@SPID')->isolation_level);
        config(['sms-exports.tier_selection_enabled' => false]);
        $this->runs->requestFile(self::ID); $this->runs->buildFile(self::ID);
        self::assertSame(2, $this->runs->show(self::ID)->file['parts']);
        self::assertSame(0, (int) $this->db->table('TblProspects')->sum('SMS_Attempts'));
        $this->runs->requestApprove(self::ID, 'synthetic@example.invalid');
        $this->runs->markApproved(self::ID); $this->runs->markApproved(self::ID);
        self::assertSame([$a,$b], array_map('intval', $this->db->table('TblSMSHistory')->orderBy('Prospect_PK')->pluck('Prospect_PK')->all()));
        foreach ([$wrong,$previous,$invalid] as $pk) self::assertSame(0, (int) $this->db->table('TblProspects')->where('PK',$pk)->value('SMS_Attempts'));
        self::assertSame(2, (int) $this->db->table('TblProspects')->sum('SMS_Attempts'));
    }

    public function test_real_windowed_selector_matches_v1_exactly_across_rejected_pages_and_priority_phases(): void
    {
        $wrong = $this->person(['Address' => 'Diff wrong', 'Creation_Date' => '2026-10-22']);
        $held = $this->person(['Address' => 'Diff held', 'Creation_Date' => '2026-10-21']);
        $unresolved = $this->person(['Address' => 'Diff none', 'Creation_Date' => '2026-10-20']);
        $first = $this->person(['Address' => 'Diff first', 'Creation_Date' => '2026-10-19']);
        $second = $this->person(['Address' => 'Diff second', 'Creation_Date' => null]);
        $previous = $this->person(['Address' => 'Diff previous', 'Creation_Date' => '2026-10-22', 'Last_SMS_Date' => '2026-01-01']);
        $this->source(1, 'Diff wrong', 'T9');
        $this->source(2, 'Diff held', 'T2', '0');
        $this->source(3, 'Diff first');
        $this->source(4, 'Diff second');
        $this->source(5, 'Diff previous');
        config(['sms-exports.run_file_options_enabled' => true, 'sms-exports.tier_selection_enabled' => true,
            'sms-exports.tier_selection_windowed_enabled' => false, 'sms-exports.tier_selection_window_size' => 2]);

        $v1 = SmsRunOptions::normalize(3, 3, ['T2'], SmsRunOptions::TIER_SELECTION_POLICY);
        $this->runs->start(self::ID, 3, 'synthetic@example.invalid', $v1);
        $this->db->table('TblSMSRuns')->where('Request_ID', self::ID)->update(['Status' => 'selecting']);
        $repo = new SmsRecordExportRepository;
        self::assertSame(3, $repo->select(self::ID, 3)['selected']);
        $columns = ['Prospect_PK','Phone','Debt_Tier','Debt_Amount','Debt_Source','Debt_Source_PK','Client','Address','City','State','Zip','Email','Was_Previously_Texted','Last_SMS_Date_At_Selection'];
        $expected = $this->db->table('TblProspectsExport')->where('Request_ID', self::ID)->orderBy('PK')->get($columns)->toJson();
        self::assertSame([$first,$second,$previous], array_map('intval', $this->db->table('TblProspectsExport')->orderBy('PK')->pluck('Prospect_PK')->all()));
        foreach ([$wrong,$held,$unresolved] as $pk) self::assertFalse($this->db->table('TblProspectsExport')->where('Prospect_PK', $pk)->exists());

        $v2id = 'bbbbbbbb-cccc-4ddd-8eee-ffffffffffff';
        $this->db->table('TblSMSRuns')->where('Request_ID', self::ID)->update(['Status' => 'discarded']);
        $this->db->table('TblProspectsExport')->where('Request_ID', self::ID)->delete();
        config(['sms-exports.tier_selection_windowed_enabled' => true]);
        $v2 = SmsRunOptions::normalize(3, 3, ['T2'], SmsRunOptions::WINDOWED_TIER_SELECTION_POLICY);
        $this->runs->start($v2id, 3, 'synthetic@example.invalid', $v2);
        $this->db->table('TblSMSRuns')->where('Request_ID', $v2id)->update(['Status' => 'selecting']);
        self::assertSame(3, $repo->select($v2id, 3)['selected']);
        $actual = $this->db->table('TblProspectsExport')->where('Request_ID', $v2id)->orderBy('PK')->get($columns)->toJson();
        self::assertSame($expected, $actual);
    }

    public function test_real_windowed_selector_fails_closed_when_a_seek_prerequisite_is_missing(): void
    {
        config(['sms-exports.run_file_options_enabled' => true, 'sms-exports.tier_selection_enabled' => true,
            'sms-exports.tier_selection_windowed_enabled' => true]);
        $this->db->unprepared('DROP INDEX IX_TblMarketing_SMS_Drop_SendDate ON dbo.TblMarketing');
        try {
            self::assertFalse($this->runs->overview()['capabilities']['tier_subset']);
            $options = SmsRunOptions::normalize(1, 1, ['T2'], SmsRunOptions::WINDOWED_TIER_SELECTION_POLICY);
            try { $this->runs->start(self::ID, 1, 'synthetic@example.invalid', $options); self::fail('Expected missing seek schema refusal.'); }
            catch (HttpException $error) { self::assertSame(503, $error->getStatusCode()); }
            self::assertSame(0, $this->db->table('TblSMSRuns')->count());
        } finally {
            $this->db->unprepared('CREATE INDEX IX_TblMarketing_SMS_Drop_SendDate ON dbo.TblMarketing(Drop_Name,Send_Date)');
        }
    }

    public function test_real_windowed_schema_rejects_altered_expression_filters_and_missing_coverage(): void
    {
        $indexes = $this->windowIndexes();
        self::assertTrue(SmsTierWindowSchema::ready($this->db));
        $this->db->unprepared('DROP INDEX IX_TblMailers_SMS_Identity ON dbo.TblMailers;
            ALTER TABLE dbo.TblMailers DROP COLUMN SMS_Zip5;
            ALTER TABLE dbo.TblMailers ADD SMS_Zip5 AS (LEFT(Zip,5)+N\'X\')');
        try {
            $this->db->unprepared($indexes['mailers']);
            self::assertFalse(SmsTierWindowSchema::ready($this->db), 'A LEFT substring must not admit changed ZIP semantics.');
        } finally {
            $this->db->unprepared('DROP INDEX IX_TblMailers_SMS_Identity ON dbo.TblMailers;
                ALTER TABLE dbo.TblMailers DROP COLUMN SMS_Zip5;
                ALTER TABLE dbo.TblMailers ADD SMS_Zip5 AS LEFT(Zip,5)');
            $this->db->unprepared($indexes['mailers']);
        }
        foreach ([
            ['tu','IX_TblTUData_SMS_Identity','TblTUData',$indexes['tu'].' WHERE ncoaAddr_frstNme IS NOT NULL'],
            ['never','IX_TblProspects_SMS_Never_Priority','TblProspects',$indexes['never'].' AND State=\'CA\''],
            ['tu','IX_TblTUData_SMS_Identity','TblTUData',str_replace(',Tier_Tag)', ')', $indexes['tu'])],
        ] as [$key,$index,$table,$replacement]) {
            $this->db->unprepared('DROP INDEX '.$index.' ON dbo.'.$table);
            try {
                $this->db->unprepared($replacement);
                self::assertFalse(SmsTierWindowSchema::ready($this->db), $replacement);
            } finally {
                $this->db->unprepared('DROP INDEX '.$index.' ON dbo.'.$table);
                $this->db->unprepared($indexes[$key]);
            }
        }
        $this->db->unprepared('ALTER INDEX IX_TblTUData_SMS_Identity ON dbo.TblTUData DISABLE');
        try { self::assertFalse(SmsTierWindowSchema::ready($this->db)); }
        finally { $this->db->unprepared('ALTER INDEX IX_TblTUData_SMS_Identity ON dbo.TblTUData REBUILD'); }
        self::assertTrue(SmsTierWindowSchema::ready($this->db));
    }

    public function test_real_windowed_second_page_failure_rolls_back_the_first_page_and_replays_cleanly(): void
    {
        $first = $this->person(['Address' => 'First page', 'Creation_Date' => '2026-10-22']);
        $second = $this->person(['Address' => 'Failure page2', 'Creation_Date' => '2026-10-21']);
        $this->source(1, 'First page'); $this->source(2, 'Failure page2');
        $this->tierRun(2, ['T2']); config(['sms-exports.tier_selection_window_size' => 1]);
        $this->db->table('TblSMSRuns')->update(['Status' => 'selecting']);
        $this->db->unprepared("CREATE TRIGGER dbo.SmsRecordRejectSelection ON dbo.TblProspectsExport AFTER INSERT AS BEGIN
            IF EXISTS (SELECT 1 FROM inserted WHERE Address='Failure page2')
            BEGIN
                IF (SELECT COUNT(*) FROM dbo.TblProspectsExport)<>2 THROW 52903,'First page was not present.',1;
                THROW 52902,'Synthetic second page failure.',1;
            END
        END");
        $repo = new SmsRecordExportRepository;
        $this->db->unprepared('SET NOCOUNT ON');
        try { $repo->select(self::ID, 2); self::fail('Expected second page failure.'); }
        catch (\Illuminate\Database\QueryException $error) { self::assertStringContainsString('Synthetic second page failure', $error->getMessage()); }
        self::assertSame(0, $this->db->table('TblProspectsExport')->count());
        self::assertNull($this->db->table('TblSMSRuns')->value('Selected'));
        self::assertSame(0, $this->db->transactionLevel());
        self::assertSame(512, (int) $this->db->selectOne('SELECT @@OPTIONS & 512 AS no_count')->no_count);
        $this->db->unprepared('SET NOCOUNT OFF');
        $this->db->unprepared('DROP TRIGGER dbo.SmsRecordRejectSelection');
        self::assertSame(2, $repo->select(self::ID, 2)['selected']);
        self::assertSame([$first,$second], array_map('intval', $this->db->table('TblProspectsExport')->orderBy('PK')->pluck('Prospect_PK')->all()));
    }

    public function test_real_windowed_pages_retain_one_snapshot_during_concurrent_source_and_population_changes(): void
    {
        $first = $this->person(['Address' => 'Snapshot first', 'Creation_Date' => '2026-10-22']);
        $second = $this->person(['Address' => 'Snapshot second', 'Creation_Date' => '2026-10-21']);
        $this->source(1, 'Snapshot first'); $this->source(2, 'Snapshot second');
        $this->tierRun(3, ['T2']); config(['sms-exports.tier_selection_window_size' => 1]);
        $this->db->table('TblSMSRuns')->update(['Status' => 'selecting']);
        $writer = new PDO('sqlsrv:Server=127.0.0.1,14330;Database='.self::$database.';Encrypt=no;TrustServerCertificate=yes',
            'sa', 'LocalDev!2026Pass', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $writer->exec('SET LOCK_TIMEOUT 3000');
        $injected = false;
        $this->db->setEventDispatcher(new \Illuminate\Events\Dispatcher(Container::getInstance()));
        $this->db->listen(function ($event) use ($writer, &$injected): void {
            if ($injected || ! str_starts_with(ltrim($event->sql), 'WITH Wanted AS')) return;
            $injected = true;
            $writer->exec("UPDATE dbo.TblTUData SET Tier_Tag='T9',derivedFields1_unsecdebtbalance=85000 WHERE PK=2;
                INSERT dbo.TblProspects (Client,Address,Phone,Zip,Creation_Date) VALUES ('Synthetic Person','Concurrent newcomer','0005550100','00123','2026-10-23');
                INSERT dbo.TblTUData (PK,Creation_Date,ncoaAddr_frstNme,ncoaAddr_lastNme,ncoaAddr_addrLine1Txt,ncoaAddr_zipCde,derivedFields1_unsecdebtbalance,Tier_Tag)
                VALUES (3,'2026-10-01','Synthetic','Person','Concurrent newcomer','00123',13500,'T2')");
        });
        try { self::assertSame(2, (new SmsRecordExportRepository)->select(self::ID, 3)['selected']); }
        finally { $this->db->unsetEventDispatcher(); }
        self::assertTrue($injected);
        self::assertSame([$first,$second], array_map('intval', $this->db->table('TblProspectsExport')->orderBy('PK')->pluck('Prospect_PK')->all()));
        self::assertSame(['T2','T2'], $this->db->table('TblProspectsExport')->orderBy('PK')->pluck('Debt_Tier')->all());
        self::assertSame('T9', $this->db->table('TblTUData')->where('PK', 2)->value('Tier_Tag'));
        self::assertSame(3, $this->db->table('TblProspects')->count());
    }

    public function test_real_windowed_mailer_waterfall_and_null_previous_ties_match_v1_across_pages(): void
    {
        $expected = [];
        foreach (['Null one','Null two','Null three'] as $i => $address) {
            $expected[] = $this->person(['Address' => $address, 'Creation_Date' => null]); $this->source($i + 1, $address);
        }
        foreach (['Mailer positive','Mailer fallback','Mailer agreeing'] as $i => $address) {
            $expected[] = $this->person(['Address' => $address, 'Last_SMS_Date' => '2026-01-01']);
        }
        foreach (['TU blocks mailer','Unknown date','Conflicting date'] as $address) $this->person(['Address' => $address, 'Creation_Date' => '2026-10-23']);
        $this->source(4, 'TU blocks mailer', 'T2', '0');
        $this->db->table('TblMarketing')->insert([
            ['Drop_Name' => 'Known', 'Send_Date' => '2026-10-01'],
            ['Drop_Name' => 'Newer', 'Send_Date' => '2026-10-20'],
            ['Drop_Name' => 'Unknown', 'Send_Date' => null],
            ['Drop_Name' => 'Conflict', 'Send_Date' => '2026-10-01'],
            ['Drop_Name' => 'Conflict', 'Send_Date' => '2026-10-02'],
        ]);
        foreach ([
            ['Mailer positive','Known',13500,'T2'], ['Mailer positive','Newer',null,'T9'],
            ['Mailer fallback','Known',null,'T2'], ['Mailer agreeing','Known',13500,'T2'],
            ['Mailer agreeing','Known',13500,'T2'], ['TU blocks mailer','Known',13500,'T2'],
            ['Unknown date','Unknown',13500,'T2'], ['Conflicting date','Conflict',13500,'T2'],
        ] as $i => [$address,$drop,$debt,$tier]) {
            $this->db->table('TblMailers')->insert(['PK' => $i + 1, 'Client' => 'Synthetic Person', 'Address' => $address,
                'Zip' => '00123-9999', 'Drop_Name' => $drop, 'Debt_Amount' => $debt, 'Debt_Tier' => $tier]);
        }
        config(['sms-exports.run_file_options_enabled' => true,'sms-exports.tier_selection_enabled' => true]);
        $repo = new SmsRecordExportRepository;
        $columns = ['Prospect_PK','Debt_Tier','Debt_Amount','Debt_Source','Debt_Source_PK','Was_Previously_Texted','Last_SMS_Date_At_Selection'];
        $v1 = SmsRunOptions::normalize(9, 9, ['T2'], SmsRunOptions::TIER_SELECTION_POLICY);
        $this->runs->start(self::ID, 9, 'synthetic@example.invalid', $v1);
        $this->db->table('TblSMSRuns')->update(['Status' => 'selecting']);
        self::assertSame(6, $repo->select(self::ID, 9)['selected']);
        $baseline = $this->db->table('TblProspectsExport')->orderBy('PK')->get($columns)->toJson();
        self::assertSame($expected, array_map('intval', $this->db->table('TblProspectsExport')->orderBy('PK')->pluck('Prospect_PK')->all()));
        $this->db->table('TblProspectsExport')->delete(); $this->db->table('TblSMSRuns')->delete();
        $this->tierRun(9, ['T2']); config(['sms-exports.tier_selection_window_size' => 1]);
        $this->db->table('TblSMSRuns')->update(['Status' => 'selecting']);
        self::assertSame(6, $repo->select(self::ID, 9)['selected']);
        self::assertSame($baseline, $this->db->table('TblProspectsExport')->orderBy('PK')->get($columns)->toJson());
    }

    public function test_opt_in_windowed_scale_100_1000_and_100000(): void
    {
        if (getenv('SMS_SELECTION_SCALE') !== '1') self::markTestSkipped('Set SMS_SELECTION_SCALE=1 for disposable 100K or optional 3M scale evidence.');
        $populationValue = getenv('SMS_SELECTION_SCALE_ROWS');
        if ($populationValue === false) $populationValue = '100000';
        if (! in_array($populationValue, ['100000','3000000'], true)) throw new \InvalidArgumentException('Scale population must be exactly 100000 or 3000000.');
        $population = (int) $populationValue;
        $this->db->statement("WITH n AS (SELECT TOP (?) ROW_NUMBER() OVER (ORDER BY (SELECT NULL)) AS n FROM sys.all_objects a CROSS JOIN sys.all_objects b)
            INSERT dbo.TblProspects (Client,Address,Phone,Zip,Creation_Date)
            SELECT 'Synthetic Person',CONCAT('Scale ',n),'0005550100','00123',DATEADD(DAY,-CAST(n/1000 AS int),CAST('2026-10-22' AS date)) FROM n;
            INSERT dbo.TblTUData (PK,Creation_Date,ncoaAddr_frstNme,ncoaAddr_lastNme,ncoaAddr_addrLine1Txt,ncoaAddr_zipCde,derivedFields1_unsecdebtbalance,Tier_Tag)
            SELECT PK,'2026-10-01','Synthetic','Person',Address,'00123',13500,'T2' FROM dbo.TblProspects", [$population]);
        self::assertSame($population, $this->db->table('TblProspects')->count());
        self::assertSame($population, $this->db->table('TblTUData')->count());
        config(['sms-exports.run_file_options_enabled' => true,'sms-exports.tier_selection_enabled' => true,
            'sms-exports.tier_selection_windowed_enabled' => true,'sms-exports.tier_selection_window_size' => 5000]);
        foreach (array_values(array_unique([100,1000,100000,$population])) as $target) {
            $id = sprintf('%08d-bbbb-4ccc-8ddd-eeeeeeeeeeee', $target);
            $options = SmsRunOptions::normalize($target, 100000, ['T2'], SmsRunOptions::WINDOWED_TIER_SELECTION_POLICY);
            $this->runs->start($id, $target, 'synthetic@example.invalid', $options);
            $this->db->table('TblSMSRuns')->where('Request_ID', $id)->update(['Status' => 'selecting']);
            $started = microtime(true); $result = (new SmsRecordExportRepository)->select($id, $target);
            $seconds = microtime(true) - $started;
            self::assertSame($target, $result['selected']);
            self::assertSame($target, $this->db->table('TblProspectsExport')->where('Request_ID', $id)->count());
            // Exact ordered membership is checked inside this disposable SQL
            // database, avoiding two multi-million-row PHP result arrays.
            $comparison = $this->db->selectOne('WITH expected AS (
                SELECT TOP (?) PK,ROW_NUMBER() OVER (ORDER BY Creation_Date DESC,PK ASC) AS position
                FROM dbo.TblProspects ORDER BY Creation_Date DESC,PK ASC
            ), actual AS (
                SELECT Prospect_PK,ROW_NUMBER() OVER (ORDER BY PK ASC) AS position
                FROM dbo.TblProspectsExport WHERE Request_ID=?
            ) SELECT COUNT_BIG(*) AS mismatches FROM expected e FULL OUTER JOIN actual a ON a.position=e.position
                WHERE e.PK IS NULL OR a.Prospect_PK IS NULL OR e.PK<>a.Prospect_PK', [$target,$id]);
            self::assertSame(0, (int) $comparison->mismatches);
            fwrite(STDOUT, PHP_EOL.json_encode(['environment' => 'disposable-loopback-synthetic','population' => $population,
                'target' => $target,'selected' => $result['selected'],'seconds' => round($seconds, 3),
                'php_peak_memory_bytes' => memory_get_peak_usage(true), 'membership_check' => 'exact-ordered-sql']).PHP_EOL);
            $cached = $this->db->selectOne("SELECT TOP (1) s.execution_count,s.total_logical_reads,s.last_logical_reads,s.max_logical_reads,p.query_plan AS plan_xml
                FROM sys.dm_exec_query_stats s CROSS APPLY sys.dm_exec_sql_text(s.sql_handle)t
                CROSS APPLY sys.dm_exec_query_plan(s.plan_handle)p
                WHERE EXISTS (SELECT 1 FROM sys.dm_exec_plan_attributes(s.plan_handle)a WHERE a.attribute='dbid' AND CONVERT(int,a.value)=DB_ID())
                    AND CHARINDEX('insert into [#SmsTierWanted]',t.text)>0 AND CHARINDEX('union all',t.text)>0
                    AND CHARINDEX('sys.dm_exec_query_stats',t.text)=0 ORDER BY s.total_worker_time DESC");
            if ($cached !== null) {
                $document = new \DOMDocument; $document->loadXML($cached->plan_xml);
                $xpath = new \DOMXPath($document); $xpath->registerNamespace('s', 'http://schemas.microsoft.com/sqlserver/2004/07/showplan');
                $access = [];
                foreach ($xpath->query('//s:RelOp') as $operator) {
                    $object = $xpath->query('s:IndexScan/s:Object', $operator)->item(0);
                    if ($object === null || $object->getAttribute('Table') !== '[TblProspects]') continue;
                    $access[] = ['physical' => $operator->getAttribute('PhysicalOp'), 'index' => $object->getAttribute('Index'),
                        'has_seek_predicates' => $xpath->query('s:IndexScan/s:SeekPredicates', $operator)->length > 0];
                }
                fwrite(STDOUT, PHP_EOL.json_encode(['environment' => 'disposable-loopback-synthetic','candidate_union_plan' => $access,
                    'execution_count' => (int) $cached->execution_count,'total_logical_reads' => (int) $cached->total_logical_reads,
                    'last_logical_reads' => (int) $cached->last_logical_reads,'max_logical_reads' => (int) $cached->max_logical_reads]).PHP_EOL);
            }
            $this->db->table('TblSMSRuns')->where('Request_ID', $id)->update(['Status' => 'discarded']);
            $this->db->table('TblProspectsExport')->where('Request_ID', $id)->delete();
        }
    }

    public function test_real_disjoint_cursor_ranges_match_v1_across_multiple_same_date_pages_and_null_phase(): void
    {
        $cases = [
            ...array_fill(0, 7, ['Creation_Date' => '2026-10-22']),
            ...array_fill(0, 2, ['Creation_Date' => '2026-10-21']),
            ...array_fill(0, 3, ['Creation_Date' => null]),
            ...array_fill(0, 4, ['Last_SMS_Date' => '2026-01-01']),
            ...array_fill(0, 2, ['Last_SMS_Date' => '2026-01-02']),
        ];
        $expected = [];
        foreach ($cases as $i => $values) {
            $address = 'Disjoint case '.$i;
            $pk = $this->person(['Address' => $address] + $values);
            $this->source($i + 1, $address, $i === 4 ? 'T9' : 'T2', $i === 1 ? '0' : '13500');
            if (! in_array($i, [1,4], true)) $expected[] = $pk;
        }
        config(['sms-exports.run_file_options_enabled' => true,'sms-exports.tier_selection_enabled' => true]);
        $this->runs->start(self::ID, 18, 'synthetic@example.invalid', SmsRunOptions::normalize(18, 18, ['T2'], SmsRunOptions::TIER_SELECTION_POLICY));
        $this->db->table('TblSMSRuns')->update(['Status' => 'selecting']);
        $repo = new SmsRecordExportRepository;
        self::assertSame(16, $repo->select(self::ID, 18)['selected']);
        $columns = ['Prospect_PK','Phone','Debt_Tier','Debt_Amount','Debt_Source','Debt_Source_PK','Client','Address','City','State','Zip','Email','Was_Previously_Texted','Last_SMS_Date_At_Selection'];
        $baseline = $this->db->table('TblProspectsExport')->orderBy('PK')->get($columns)->toJson();
        self::assertSame($expected, array_map('intval', $this->db->table('TblProspectsExport')->orderBy('PK')->pluck('Prospect_PK')->all()));
        $this->db->table('TblProspectsExport')->delete(); $this->db->table('TblSMSRuns')->delete();
        $this->tierRun(18, ['T2']);
        $this->db->table('TblSMSRuns')->update(['Status' => 'selecting']);
        self::assertSame(16, $repo->select(self::ID, 18)['selected']);
        self::assertSame($baseline, $this->db->table('TblProspectsExport')->orderBy('PK')->get($columns)->toJson());
    }

    public function test_real_windowed_contact_phone_seeks_match_v1_normalization_and_trailing_spaces(): void
    {
        $expected = [];
        foreach (range(0, 6) as $i) {
            $pk = $this->person(['Address' => 'Phone case '.$i, 'Phone' => '000555010'.$i]);
            $this->source($i + 1, 'Phone case '.$i);
            if ($i >= 4) $expected[] = $pk;
        }
        $this->db->table('TblPhoneNumbers')->insert([
            ['Phone' => '0005550100'], ['Phone' => '10005550101'],
            ['Phone' => '0005550102   '], ['Phone' => '10005550103   '],
            ['Phone' => '110005550104'], ['Phone' => '00005550105'], ['Phone' => null],
        ]);
        config(['sms-exports.run_file_options_enabled' => true,'sms-exports.tier_selection_enabled' => true]);
        $v1 = SmsRunOptions::normalize(7, 7, ['T2'], SmsRunOptions::TIER_SELECTION_POLICY);
        $this->runs->start(self::ID, 7, 'synthetic@example.invalid', $v1);
        $this->db->table('TblSMSRuns')->update(['Status' => 'selecting']);
        $repo = new SmsRecordExportRepository;
        self::assertSame(3, $repo->select(self::ID, 7)['selected']);
        self::assertSame($expected, array_map('intval', $this->db->table('TblProspectsExport')->orderBy('PK')->pluck('Prospect_PK')->all()));
        $this->db->table('TblProspectsExport')->delete(); $this->db->table('TblSMSRuns')->delete();
        $this->tierRun(7, ['T2']); config(['sms-exports.tier_selection_window_size' => 1]);
        $this->db->table('TblSMSRuns')->update(['Status' => 'selecting']);
        self::assertSame(3, $repo->select(self::ID, 7)['selected']);
        self::assertSame($expected, array_map('intval', $this->db->table('TblProspectsExport')->orderBy('PK')->pluck('Prospect_PK')->all()));
    }

    public function test_real_windowed_selection_counts_rows_when_a_reused_session_has_nocount_on(): void
    {
        foreach (['No count first','No count second','No count third'] as $i => $address) {
            $this->person(['Address' => $address]); $this->source($i + 1, $address);
        }
        $this->tierRun(3, ['T2']); config(['sms-exports.tier_selection_window_size' => 1]);
        $this->db->table('TblSMSRuns')->update(['Status' => 'selecting']);
        $this->db->unprepared('SET NOCOUNT ON');
        try {
            self::assertSame(3, (new SmsRecordExportRepository)->select(self::ID, 3)['selected']);
            self::assertSame(3, (int) $this->db->table('TblSMSRuns')->value('Selected'));
            self::assertSame(512, (int) $this->db->selectOne('SELECT @@OPTIONS & 512 AS no_count')->no_count);
        } finally {
            $this->db->unprepared('SET NOCOUNT OFF');
        }
    }

    public function test_real_committed_selection_retry_keeps_source_prices_after_creation_flag_and_sources_change(): void
    {
        $this->person(['Address' => 'Frozen']); $this->source(1, 'Frozen');
        $options = $this->tierRun(5, ['T2']);
        $this->db->table('TblSMSRuns')->update(['Status' => 'selecting']);
        $repo = new SmsRecordExportRepository;
        self::assertSame(1, $repo->select(self::ID, 5)['selected']);
        // Simulate selection committed, then worker failed before drops/review.
        $this->db->table('TblSMSRuns')->update(['Status' => 'queued']);
        $this->db->table('TblTUData')->update(['Tier_Tag' => 'T9', 'derivedFields1_unsecdebtbalance' => 85000]);
        config(['sms-exports.tier_selection_enabled' => false, 'sms-exports.mailer_recency_date' => 'obsolete']);
        $this->runs->prepare(self::ID);
        self::assertSame('review', $this->runs->show(self::ID)->status);
        self::assertSame(4, $this->runs->show(self::ID)->shortfall);
        self::assertSame($options, $this->runs->show(self::ID)->options);
        self::assertSame('T2', $this->db->table('TblProspectsExport')->value('Debt_Tier'));
        self::assertSame(14000, (int) $this->db->table('TblProspectsExport')->value('Debt_Amount'));
        self::assertSame('T2', $this->db->table('TblSMSDrops')->value('Debt_Tier'));
    }

    public function test_real_source_selection_insert_failure_rolls_back_and_restores_snapshot_settings(): void
    {
        $this->person(['Address' => 'Failure']); $this->source(1, 'Failure'); $this->tierRun(2, ['T2']);
        $this->db->table('TblSMSRuns')->update(['Status' => 'selecting']);
        $this->db->unprepared("CREATE TRIGGER dbo.SmsRecordRejectSelection ON dbo.TblProspectsExport AFTER INSERT AS BEGIN THROW 52902,'Synthetic selector failure.',1; END");
        $repo = new SmsRecordExportRepository;
        try { $repo->select(self::ID, 2); self::fail('Expected selection rollback.'); } catch (\Illuminate\Database\QueryException) {}
        self::assertSame(0, $this->db->table('TblProspectsExport')->count());
        self::assertNull($this->db->table('TblSMSRuns')->value('Selected'));
        self::assertSame(0, $this->db->transactionLevel());
        self::assertSame(2, (int) $this->db->selectOne('SELECT transaction_isolation_level AS isolation_level FROM sys.dm_exec_sessions WHERE session_id=@@SPID')->isolation_level);
        $this->db->unprepared('DROP TRIGGER dbo.SmsRecordRejectSelection');
        self::assertSame(1, $repo->select(self::ID, 2)['selected']);
    }

    public function test_real_empty_committed_selection_remains_empty_when_retry_sources_change(): void
    {
        $this->person(['Address' => 'Initially unresolved']);
        $this->tierRun(5, ['T2']);
        $this->db->table('TblSMSRuns')->update(['Status' => 'selecting']);
        $repo = new SmsRecordExportRepository;
        self::assertSame(0, $repo->select(self::ID, 5)['selected']);
        self::assertSame(0, (int) $this->db->table('TblSMSRuns')->value('Selected'));
        // No pending row exists, but the zero-selection receipt committed atomically.
        $this->source(1, 'Initially unresolved');
        try { $repo->select(self::ID, 5); self::fail('Completed empty selection must not rerun.'); } catch (HttpException) {}
        $this->db->table('TblSMSRuns')->update(['Status' => 'queued']);
        config(['sms-exports.tier_selection_enabled' => false]);
        $this->runs->prepare(self::ID);
        self::assertSame('review', $this->runs->show(self::ID)->status);
        self::assertSame(0, $this->runs->show(self::ID)->selected);
        self::assertSame(5, $this->runs->show(self::ID)->shortfall);
        self::assertSame(0, $this->db->table('TblProspectsExport')->count());
        self::assertSame(0, $this->db->table('TblSMSDrops')->count());
        self::assertNull($this->db->table('TblProspects')->value('Last_SMS_Date'));
    }

    public function test_real_closed_history_keysets_preserve_mixed_runs_without_writes_or_counts(): void
    {
        $ids = $keys = [];
        $options = SmsRunOptions::normalize(100, 50, ['T2', 'T9'], SmsRunOptions::TIER_SELECTION_POLICY);
        for ($i = 1; $i <= 25; $i++) {
            $id = sprintf('%08d-1111-4111-8111-%012d', $i, $i);
            $ids[] = $id;
            $keys[] = (int) $this->db->table('TblSMSRuns')->insertGetId([
                'Request_ID' => $id, 'Target' => 100, 'Selected' => 90,
                'Status' => SmsRunService::CLOSED[($i - 1) % count(SmsRunService::CLOSED)],
                'Selection_Policy' => $i % 2 ? 'legacy-phone-v1' : 'prospect-record-v1',
                'Run_Options_JSON' => $i % 2 ? null : json_encode($options, JSON_THROW_ON_ERROR),
                'Created_By' => 'synthetic@example.invalid',
                // Deliberately opposite to PK order: pagination is by the immutable run key.
                'Created_At' => sprintf('2026-10-%02d 01:00:00', 26 - $i), 'Updated_At' => '2026-10-22 01:00:00',
            ], 'PK');
        }
        $this->db->table('TblSMSRuns')->insert(['Request_ID' => self::ID, 'Target' => 100,
            'Status' => 'review', 'Created_By' => 'synthetic@example.invalid',
            'Created_At' => '2026-10-22 01:00:00', 'Updated_At' => '2026-10-22 01:00:00']);
        $before = $this->db->table('TblSMSRuns')->orderBy('PK')->get()->toJson();
        $this->db->enableQueryLog(); $this->db->flushQueryLog();
        try {
            $first = $this->runs->history();
            $second = $this->runs->history($first['next_cursor']);
            $third = $this->runs->history($second['next_cursor']);
            $queries = implode("\n", array_column($this->db->getQueryLog(), 'query'));
        } finally {
            $this->db->disableQueryLog();
        }
        self::assertCount(10, $first['items']); self::assertCount(10, $second['items']); self::assertCount(5, $third['items']);
        self::assertSame($keys[15], $first['next_cursor']); self::assertSame($keys[5], $second['next_cursor']);
        self::assertNull($third['next_cursor']);
        $items = [...$first['items'], ...$second['items'], ...$third['items']];
        self::assertSame(array_reverse($ids), array_column($items, 'request_id'));
        self::assertNull($first['items'][0]->options); self::assertSame($options, $first['items'][1]->options);
        self::assertMatchesRegularExpression('/select top 11/i', $queries);
        self::assertDoesNotMatchRegularExpression('/\b(?:INSERT|UPDATE|DELETE|OFFSET|COUNT)\b/i', $queries);
        self::assertSame($before, $this->db->table('TblSMSRuns')->orderBy('PK')->get()->toJson());
        self::assertSame(0, $this->db->table('TblSMSHistory')->count());
        self::assertSame(0, $this->db->table('TblProspectsExport')->count());
    }

    public function test_authenticated_routes_and_database_jobs_complete_versioned_generate_approve_history_and_delete(): void
    {
        // Actual package routes, host guards, SQL repositories and serialized queue
        // deliveries. Identity is fake; only this class's random loopback DB is used.
        $container = Container::getInstance();
        foreach (['app/Reports/Shared/ReportPolicy.php', 'app/Http/Middleware/VerifyCmdSession.php', 'app/Http/Middleware/EnforceReportPolicy.php'] as $file) {
            require_once dirname(__DIR__, 3).'/cmd-runner/'.$file;
        }
        config(['app.url' => 'https://cmd.fixture.invalid', 'sms-exports.queue' => 'tu-export',
            'sms-exports.run_file_options_enabled' => true, 'sms-exports.tier_selection_enabled' => true,
            'queue.default' => 'database', 'queue.connections.database.retry_after' => 15000,
            'services.cmd' => ['auth_enabled' => true, 'auth_validate_url' => 'https://identity.fixture.invalid', 'auth_cache_ttl' => 60],
            'cache' => ['default' => 'array', 'stores' => ['array' => ['driver' => 'array']]]]);
        $container->instance(\Illuminate\Contracts\Container\Container::class, $container);
        $container->instance('cache', new \Illuminate\Cache\CacheManager($container));
        $container->instance(\Illuminate\Http\Client\Factory::class, new \Illuminate\Http\Client\Factory);
        $container->instance('validator', new \Illuminate\Validation\Factory(new \Illuminate\Translation\Translator(new \Illuminate\Translation\ArrayLoader, 'en'), $container));
        $container->instance('log', new \Psr\Log\NullLogger);
        $events = new \Illuminate\Events\Dispatcher($container); $container->instance('events', $events);
        $this->db->getSchemaBuilder()->create('jobs', function (Blueprint $table): void {
            $table->bigIncrements('id'); $table->string('queue')->index(); $table->longText('payload');
            $table->unsignedTinyInteger('attempts'); $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at'); $table->unsignedInteger('created_at');
        });
        $queue = new \Illuminate\Queue\DatabaseQueue($this->db, 'jobs', 'tu-export', 15000);
        $queue->setContainer($container); $queue->setConnectionName('database');
        $bus = new \Illuminate\Bus\Dispatcher($container, static fn () => $queue);
        $container->instance(\Illuminate\Contracts\Bus\Dispatcher::class, $bus);
        $this->runs = new SmsRunService(new SmsProspectExportRepository);
        $container->instance(SmsRunService::class, $this->runs);
        $router = new \Illuminate\Routing\Router($events, $container);
        $router->middlewareGroup('api', []);
        $router->aliasMiddleware('signed', \Illuminate\Routing\Middleware\ValidateSignature::class);
        $container->instance('router', $router); Facade::clearResolvedInstance('router');
        require dirname(__DIR__, 2).'/routes/sms-api.php';
        require dirname(__DIR__, 2).'/routes/sms-download.php';
        $router->getRoutes()->refreshNameLookups();
        $urls = new \Illuminate\Routing\UrlGenerator($router->getRoutes(), \Illuminate\Http\Request::create('https://cmd.fixture.invalid'));
        $urls->setKeyResolver(static fn () => 'http-queue-fixture-signing-key');
        $container->instance('url', $urls); Facade::clearResolvedInstance('url');
        \Illuminate\Http\Request::macro('hasValidSignatureWhileIgnoring', function ($ignore = [], $absolute = true) {
            return \Illuminate\Support\Facades\URL::hasValidSignature($this, $absolute, $ignore);
        });
        $actors = ['manager' => ['email' => 'manager@example.invalid', 'permissionIds' => ['cmd_sms_manage']],
            'agent' => ['email' => 'agent@example.invalid', 'permissionIds' => ['cmd_report_mail_drop_export', 'cmd_sms_download']],
            'viewer' => ['email' => 'viewer@example.invalid', 'permissionIds' => ['cmd_report_mail_drop_export']]];
        $actor = $actors['manager'];
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        \Illuminate\Support\Facades\Http::fake(['identity.fixture.invalid/*' => static function () use (&$actor) {
            return \Illuminate\Support\Facades\Http::response($actor);
        }]);
        $http = function (string $method, string $path, array $data = [], string $role = 'manager') use ($container, $router, $actors, &$actor) {
            $actor = $actors[$role];
            $url = str_starts_with($path, 'https://') ? $path : 'https://cmd.fixture.invalid/api/cmd/mail-drop-export-report/'.$path;
            $request = \Illuminate\Http\Request::create($url, $method, $data);
            $request->headers->set('Cookie', 'isolated-session='.$role); $request->headers->set('Accept', 'application/json');
            $container->instance('request', $request); $container->instance(\Illuminate\Http\Request::class, $request);
            try { return $router->dispatch($request); }
            catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $error) { return new \Illuminate\Http\JsonResponse(['message' => $error->getMessage()], $error->getStatusCode()); }
            catch (\Illuminate\Validation\ValidationException $error) { return new \Illuminate\Http\JsonResponse(['errors' => $error->errors()], 422); }
        };
        $deliver = function (string $step) use ($queue): void {
            $delivery = $queue->pop('tu-export'); self::assertNotNull($delivery);
            $command = unserialize($delivery->payload()['data']['command']);
            self::assertInstanceOf(\Cmd\Reports\Jobs\SmsRunStepJob::class, $command);
            self::assertSame($step, $command->step); $delivery->fire();
            self::assertTrue($delivery->isDeleted());
        };
        foreach (['HTTP A', 'HTTP B', 'HTTP C', 'Wrong tier'] as $i => $address) {
            $this->person(['Address' => $address]); $this->source($i + 1, $address, $i === 3 ? 'T9' : 'T2');
        }
        $input = ['request_id' => self::ID, 'target' => 5, 'rows_per_file' => 2,
            'csv_format' => SmsRunOptions::CSV_FORMAT, 'tiers' => ['T2'], 'tier_selection_policy' => SmsRunOptions::TIER_SELECTION_POLICY,
            'options_version' => SmsRunOptions::PLANNED_DATE_VERSION, 'send_date' => '2026-10-26',
            'filename_version' => SmsRunOptions::APPROVAL_FILENAME_VERSION];
        self::assertSame(403, $http('POST', 'runs', $input, 'agent')->getStatusCode());
        self::assertSame(0, $queue->size());
        $started = $http('POST', 'runs', $input);
        self::assertSame(202, $started->getStatusCode()); self::assertSame('queued', $started->getData(true)['status']);
        self::assertSame(1, $queue->size());
        self::assertSame(202, $http('POST', 'runs', $input)->getStatusCode()); self::assertSame(1, $queue->size());
        self::assertSame(409, $http('POST', 'runs', array_replace($input, ['rows_per_file' => 3]))->getStatusCode());
        $deliver('prepare');
        $review = $http('GET', 'runs/'.self::ID, [], 'agent')->getData(true);
        self::assertSame(['review', 3, 2], [$review['status'], $review['selected'], $review['shortfall']]);
        self::assertSame(['T2'], $review['options']['tiers']); self::assertSame(2, $review['options']['rows_per_file']);
        self::assertSame('manager@example.invalid', $review['created_by']);
        self::assertSame('pending', $review['id_status']);
        self::assertSame(0, $this->db->table('TblSMSDrops')->count());
        self::assertSame(0, $this->db->table('TblProspectsExport')->whereNotNull('SMS_Drop_PK')->count());
        self::assertSame(202, $http('POST', 'runs/'.self::ID.'/file')->getStatusCode());
        self::assertSame('building_file', $http('GET', 'runs')->getData(true)['open']['status']);
        $deliver('file');
        $ready = $http('GET', 'runs/'.self::ID)->getData(true);
        self::assertSame('file_ready', $ready['status']); self::assertSame(2, $ready['file']['parts']);
        self::assertSame('SMS Export - 2026-10-26 - ID Pending.zip', $ready['file']['name']);
        self::assertSame(['pending', 'awaiting_approval', null], [$ready['id_status'], $ready['approval_phase'], $ready['file']['drop_name_range']]);
        self::assertSame(0, $this->db->table('TblSMSDrops')->count());
        self::assertSame(0, (int) $this->db->table('TblProspects')->sum('SMS_Attempts'));
        self::assertSame(403, $http('GET', 'runs/'.self::ID.'/link', [], 'viewer')->getStatusCode());
        $link = $http('GET', 'runs/'.self::ID.'/link', [], 'agent')->getData(true)['download_url'];
        $download = $http('GET', $link, [], 'agent');
        self::assertSame(200, $download->getStatusCode());
        self::assertInstanceOf(\Symfony\Component\HttpFoundation\BinaryFileResponse::class, $download);
        $fileHash = hash_file('sha256', $download->getFile()->getPathname());
        $manifest = json_decode($this->db->table('TblSMSRuns')->where('Request_ID', self::ID)->value('Artifact_Manifest_JSON'), true);
        self::assertSame([2, 1], array_column($manifest['parts'], 'rows'));
        self::assertSame(['SMS Export - 2026-10-26 - ID Pending - Part 1 of 2.csv', 'SMS Export - 2026-10-26 - ID Pending - Part 2 of 2.csv'], array_column($manifest['parts'], 'name'));
        $zip = new \ZipArchive; self::assertTrue($zip->open($download->getFile()->getPathname()));
        try {
            self::assertSame(2, $zip->numFiles); $rowsRead = 0;
            foreach ($manifest['parts'] as $part) {
                $stream = $zip->getStream($part['name']); self::assertIsResource($stream);
                try {
                    self::assertSame(['First name', 'debt load', 'phone1', 'send date'], fgetcsv($stream, null, ',', '"', ''));
                    $partRows = 0;
                    while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
                        self::assertCount(4, $row); self::assertSame(['14000', '0005550100', '2026-10-26'], array_slice($row, 1));
                        $partRows++; $rowsRead++;
                    }
                    self::assertSame($part['rows'], $partRows);
                } finally { fclose($stream); }
            }
            self::assertSame(3, $rowsRead);
        } finally { $zip->close(); }
        self::assertSame(403, $http('GET', $link.'&changed=1', [], 'agent')->getStatusCode());
        self::assertSame(403, $http('POST', 'runs/'.self::ID.'/approve', ['confirmed_approval' => true], 'agent')->getStatusCode());
        self::assertSame(422, $http('POST', 'runs/'.self::ID.'/approve', [])->getStatusCode());
        self::assertSame(202, $http('POST', 'runs/'.self::ID.'/approve', ['confirmed_approval' => true])->getStatusCode());
        self::assertSame(202, $http('POST', 'runs/'.self::ID.'/approve', ['confirmed_approval' => true])->getStatusCode());
        self::assertSame(0, $this->db->table('TblSMSDrops')->count());
        self::assertSame('assigning_ids', $http('GET', 'runs/'.self::ID)->getData(true)['approval_phase']);
        self::assertSame(409, $http('GET', 'runs/'.self::ID.'/link', [], 'agent')->getStatusCode());
        self::assertSame(1, $queue->size()); $deliver('approve');
        self::assertSame(202, $http('POST', 'runs/'.self::ID.'/approve', ['confirmed_approval' => true])->getStatusCode());
        self::assertSame(0, $queue->size());
        self::assertSame(3, (int) $this->db->table('TblProspects')->sum('SMS_Attempts'));
        self::assertSame(3, $this->db->table('TblSMSHistory')->count());
        self::assertSame(409, $http('POST', 'runs/'.self::ID.'/discard')->getStatusCode());
        $history = $http('GET', 'runs/history', [], 'agent')->getData(true);
        self::assertSame('approved', $history['items'][0]['status']);
        self::assertSame(['assigned', 'complete', 'SMS0001-SMS0001'], [$history['items'][0]['id_status'], $history['items'][0]['approval_phase'], $history['items'][0]['file']['drop_name_range']]);
        self::assertSame('SMS Export - 2026-10-26 - SMS0001-SMS0001.zip', $history['items'][0]['file']['name']);
        self::assertSame(1, $this->db->table('TblSMSDrops')->count());
        $finalManifest = json_decode($this->db->table('TblSMSRuns')->where('Request_ID', self::ID)->value('Artifact_Manifest_JSON'), true);
        self::assertSame(array_column($manifest['parts'], 'sha256'), array_column($finalManifest['parts'], 'sha256'));
        self::assertSame(array_column($manifest['parts'], 'bytes'), array_column($finalManifest['parts'], 'bytes'));
        self::assertSame(['SMS Export - 2026-10-26 - SMS0001-SMS0001 - Part 1 of 2.csv', 'SMS Export - 2026-10-26 - SMS0001-SMS0001 - Part 2 of 2.csv'], array_column($finalManifest['parts'], 'name'));
        $finalLink = $http('GET', 'runs/'.self::ID.'/link', [], 'agent')->getData(true)['download_url'];
        $finalDownload = $http('GET', $finalLink, [], 'agent');
        self::assertSame(200, $finalDownload->getStatusCode());
        self::assertStringContainsString('/v2/published/', str_replace('\\', '/', $finalDownload->getFile()->getPathname()));
        self::assertSame($fileHash, hash_file('sha256', $download->getFile()->getPathname()));
        // Publication and email use real SQL app locks, migrations and serialized
        // database jobs; both providers are closed fakes with no external I/O.
        config(['sms-exports.email_handoff_enabled' => true,
            'sms-exports.file_publication_enabled' => true,
            'sms-exports.email_allowed_link_hosts' => ['files.example.invalid']]);
        $beforeEmail = [$this->db->table('TblSMSRuns')->orderBy('PK')->get()->toJson(),
            $this->db->table('TblSMSHistory')->orderBy('PK')->get()->toJson(),
            $this->db->table('TblProspects')->orderBy('PK')->get()->toJson()];
        $publisher = new class($this->db) implements \Cmd\Reports\Services\SmsRunLinkPublisher {
            public array $objects = [];
            public int $calls = 0;
            public int $verifies = 0;
            public function __construct(private $db) {}
            public function identity(): string { return 'synthetic-sql-publication-v1'; }
            public function policy(string $sendDate): array { return ['version' => 1, 'send_date' => $sendDate, 'audience' => 'fixture-only']; }
            private function guard(): void
            {
                if ($this->db->transactionLevel() !== 0 || $this->db->getPdo()->inTransaction()) throw new \RuntimeException('Publisher inside a transaction.');
            }
            public function publish(string $id, array $part, mixed $stream, array $policy): array
            {
                $this->guard(); $this->calls++;
                $bytes = stream_get_contents($stream);
                if (strlen($bytes) !== $part['bytes'] || hash('sha256', $bytes) !== $part['sha256']) throw new \RuntimeException('Changed fixture CSV.');
                $receipt = ['version' => 1, 'request_id' => $id, 'part' => $part['part'], 'name' => $part['name'],
                    'url' => 'https://files.example.invalid/'.$id.'/part'.$part['part'], 'sha256' => $part['sha256'],
                    'bytes' => $part['bytes'], 'rows' => $part['rows'], 'remote' => ['provider' => 'closed-sql-fixture', 'revision' => 'fixed-1'], 'policy' => $policy];
                if (isset($this->objects[$part['part']]) && $this->objects[$part['part']] !== $receipt) throw new \RuntimeException('Conflicting fixture object.');
                return $this->objects[$part['part']] = $receipt;
            }
            public function verify(string $id, array $part, array $receipt, array $policy): void
            {
                $this->guard(); $this->verifies++;
                if (($this->objects[$part['part']] ?? null) !== $receipt || $receipt['request_id'] !== $id || $receipt['policy'] !== $policy) throw new \RuntimeException('Changed fixture receipt.');
            }
        };
        $publications = new \Cmd\Reports\Services\SmsRunPublicationWorkflow($publisher, new \Cmd\Reports\Services\SmsRunArtifactPartReader);
        $container->instance(\Cmd\Reports\Services\SmsRunPublicationWorkflow::class, $publications);
        $publications->assertPublishable(self::ID);
        self::assertNull($publications->status(self::ID));
        self::assertSame(0, $publisher->calls);
        foreach ([1, 2] as $_) {
            $queue->push(new \Cmd\Reports\Jobs\SmsRunPublicationJob(self::ID, 'manager@example.invalid'));
            $publicationDelivery = $queue->pop('tu-export'); self::assertNotNull($publicationDelivery);
            self::assertInstanceOf(\Cmd\Reports\Jobs\SmsRunPublicationJob::class, unserialize($publicationDelivery->payload()['data']['command']));
            $publicationDelivery->fire(); self::assertTrue($publicationDelivery->isDeleted());
        }
        self::assertSame(2, $publisher->calls);
        self::assertSame(4, $publisher->verifies);
        self::assertSame('published', $publications->status(self::ID)['status']);
        self::assertSame(2, $this->db->table('TblSMSFilePublicationParts')->where('State', 'complete')->count());
        self::assertSame(0, $this->db->table('TblSMSEmailOutbox')->count());
        $publishedView = $http('GET', 'runs/'.self::ID, [], 'agent')->getData(true)['handoff'];
        self::assertSame('published', $publishedView['file_publication']['status']);
        self::assertSame([2, 2, 3, 3], array_map(fn ($key) => $publishedView['file_publication'][$key], ['files_done', 'files_total', 'rows_done', 'rows_total']));
        self::assertStringNotContainsString('files.example.invalid', json_encode($publishedView));
        $gateway = new class($this->db) implements \Cmd\Reports\Services\SmsRunEmailGateway {
            public int $calls = 0;
            public function __construct(private $db) {}
            public function configuration(): array
            {
                return ['from' => 'admin@lendingtower.com', 'to' => ['dynamic@example.invalid'], 'cc' => [],
                    'bcc' => ['private-copy@example.invalid'], 'report_name' => 'Synthetic SMS', 'companies' => ['FIXTURE']];
            }
            public function assertReady(): void {}
            public function send(array $message): bool
            {
                if ($this->db->transactionLevel() !== 0 || $this->db->getPdo()->inTransaction()) throw new \RuntimeException('Mail inside a transaction.');
                $this->calls++;
                return true;
            }
        };
        $emails = new \Cmd\Reports\Services\SmsRunEmailWorkflow($gateway);
        $container->instance(\Cmd\Reports\Services\SmsRunEmailWorkflow::class, $emails);
        $receipts = $publications->links(self::ID);
        self::assertSame(array_column($finalManifest['parts'], 'sha256'), array_column($receipts, 'sha256'));
        $email = $emails->prepare(self::ID, $receipts, 'manager@example.invalid');
        self::assertSame('ready', $email['status']);
        self::assertSame(0, $gateway->calls);
        $preparedView = $http('GET', 'runs/'.self::ID, [], 'agent')->getData(true)['handoff'];
        self::assertSame('ready', $preparedView['email']['status']);
        self::assertSame(['dynamic@example.invalid'], $preparedView['email_preview']['to']);
        self::assertTrue($preparedView['email_preview']['bcc_configured']);
        self::assertStringNotContainsString('files.example.invalid', json_encode($preparedView));
        self::assertStringNotContainsString('private-copy@example.invalid', json_encode($preparedView));
        foreach ([1, 2] as $_) {
            $queue->push(new \Cmd\Reports\Jobs\SmsRunEmailJob(self::ID, $email['payload_sha256']));
            $delivery = $queue->pop('tu-export'); self::assertNotNull($delivery);
            self::assertInstanceOf(\Cmd\Reports\Jobs\SmsRunEmailJob::class, unserialize($delivery->payload()['data']['command']));
            $delivery->fire(); self::assertTrue($delivery->isDeleted());
        }
        self::assertSame(1, $gateway->calls);
        self::assertSame('accepted', $emails->status(self::ID)['status']);
        $emailHistory = $http('GET', 'runs/history', [], 'agent')->getData(true)['items'][0]['handoff'];
        self::assertSame('accepted', $emailHistory['email']['status']);
        self::assertSame('published', $emailHistory['file_publication']['status']);
        self::assertSame('not_configured', $emailHistory['debtplete_import']['status']);
        self::assertSame($beforeEmail, [$this->db->table('TblSMSRuns')->orderBy('PK')->get()->toJson(),
            $this->db->table('TblSMSHistory')->orderBy('PK')->get()->toJson(),
            $this->db->table('TblProspects')->orderBy('PK')->get()->toJson()]);
        $input['request_id'] = '22222222-3333-4444-8555-666666666666';
        self::assertSame(202, $http('POST', 'runs', $input)->getStatusCode()); $deliver('prepare');
        self::assertSame(200, $http('POST', 'runs/'.$input['request_id'].'/discard')->getStatusCode());
        self::assertSame(3, $this->db->table('TblSMSHistory')->count());
        self::assertSame(3, (int) $this->db->table('TblProspects')->sum('SMS_Attempts'));
        self::assertSame(0, $this->db->table('TblProspectsExport')->where('Request_ID', $input['request_id'])->count());
        self::assertSame(1, $this->db->table('TblSMSDrops')->count());
    }

    public function test_real_approved_archive_receipt_and_restore_preserve_history_and_attempts(): void
    {
        $this->person(['Address' => 'Archive A']); $this->person(['Address' => 'Archive B']);
        $this->source(1, 'Archive A'); $this->source(2, 'Archive B');
        $this->tierRun(2, ['T2']); $this->runs->prepare(self::ID);
        $this->runs->requestFile(self::ID); $this->runs->buildFile(self::ID);
        $this->runs->requestApprove(self::ID, 'synthetic@example.invalid'); $this->runs->markApproved(self::ID);
        $history = $this->db->table('TblSMSHistory')->orderBy('PK')->get()->toJson();
        $prospects = $this->db->table('TblProspects')->orderBy('PK')->get()->toJson();
        $store = new class($this->db) implements \Cmd\Reports\Services\SmsRunArchiveStore {
            public array $objects = [];
            public function __construct(private $connection) {}
            public function identity(): string { return 'local-sql-fixture-v1'; }
            private function guard(): void
            {
                if ($this->connection->transactionLevel() !== 0 || $this->connection->getPdo()->inTransaction()) throw new \RuntimeException('Provider inside SQL transaction.');
            }
            public function putIfAbsent(string $key, string $sourcePath, array $metadata): string
            {
                $this->guard(); $bytes = file_get_contents($sourcePath);
                if (isset($this->objects[$key]) && $this->objects[$key] !== compact('bytes', 'metadata')) throw new \RuntimeException('Conflicting fixture object.');
                $this->objects[$key] = compact('bytes', 'metadata'); return 'fixture-version-1';
            }
            public function metadata(string $key, string $version): ?array
            {
                $this->guard(); $object = $this->objects[$key] ?? null;
                return $object === null ? null : ['bytes' => strlen($object['bytes']), 'metadata' => $object['metadata']];
            }
            public function read(string $key, string $version): mixed
            {
                $this->guard(); $stream = fopen('php://temp', 'w+b'); fwrite($stream, $this->objects[$key]['bytes']); rewind($stream); return $stream;
            }
        };
        config(['sms-exports.run_archive_enabled' => true, 'sms-exports.run_archive_policy_sha256' => str_repeat('a', 64), 'sms-exports.run_archive_prefix' => 'local-fixture']);
        $workflow = new \Cmd\Reports\Services\SmsRunArchiveWorkflow($store);
        $receipt = $workflow->archive(self::ID);
        self::assertCount(2, $store->objects);
        self::assertSame($receipt, json_decode($this->db->table('TblSMSRuns')->value('Archive_Receipt_JSON'), true));
        self::assertSame($receipt, $workflow->archive(self::ID));
        $path = $this->runs->filePath(self::ID); $manifestPath = dirname($path).'/manifest.json';
        $originalManifest = file_get_contents($manifestPath); unlink($path); unlink($manifestPath);
        self::assertSame($path, $workflow->restore(self::ID));
        self::assertSame($path, $this->runs->filePath(self::ID));
        self::assertSame($originalManifest, file_get_contents($manifestPath));
        self::assertSame($history, $this->db->table('TblSMSHistory')->orderBy('PK')->get()->toJson());
        self::assertSame($prospects, $this->db->table('TblProspects')->orderBy('PK')->get()->toJson());
        self::assertSame('approved', $this->db->table('TblSMSRuns')->value('Status'));
    }

    public function test_real_summary_counts_and_tu_completion_date_share_one_snapshot_during_concurrent_completion(): void
    {
        // This class already verifies its random, loopback-only disposable database.
        $this->db->unprepared('CREATE TABLE dbo.TblTUPipelineBatches (Batch_ID varchar(30) PRIMARY KEY, Data_Date date NOT NULL);
            CREATE TABLE dbo.TblTUPipelinePhases (Batch_ID varchar(30), Phase varchar(50), PRIMARY KEY (Batch_ID,Phase))');
        try {
            $this->db->table('TblTUPipelineBatches')->insert([
                ['Batch_ID' => 'old-complete', 'Data_Date' => '2026-10-13'],
                ['Batch_ID' => 'new-processing', 'Data_Date' => '2026-10-20'],
            ]);
            $this->db->table('TblTUPipelinePhases')->insert(['Batch_ID' => 'old-complete', 'Phase' => 'summary-refreshed']);
            $this->person();
            config(['sms-exports.tu_completion_date_enabled' => true]);
            $settings = $this->db->getConfig();
            self::assertSame('127.0.0.1,14330', $settings['host']);
            self::assertSame(self::$database, $settings['database']);
            $writer = new PDO('sqlsrv:Server='.$settings['host'].';Database='.$settings['database'].';Encrypt=no;TrustServerCertificate=yes',
                $settings['username'], $settings['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $export = new class($writer) extends SmsProspectExportRepository {
                public function __construct(private ?PDO $writer) {}
                public function availabilityWithTimeout(): array
                {
                    $count = $this->connection()->table('TblProspects')->count();
                    if ($this->writer !== null) {
                        $this->writer->beginTransaction();
                        $this->writer->exec("INSERT dbo.TblProspects (Client,Address,Phone,Zip,Creation_Date) VALUES ('Concurrent Fixture','Fixture St','0005550101','00123','2026-10-20')");
                        $this->writer->exec("INSERT dbo.TblTUPipelinePhases (Batch_ID,Phase) VALUES ('new-processing','summary-refreshed')");
                        $this->writer->commit(); $this->writer = null;
                    }
                    return ['eligible' => $count, 'never_texted' => $count, 'texted_before' => 0];
                }
            };
            $old = $export->availabilitySnapshotWithTimeout();
            self::assertSame(1, $old['eligible']);
            self::assertSame('2026-10-13', $old['latest_data_date']);
            $new = $export->availabilitySnapshotWithTimeout();
            self::assertSame(2, $new['eligible']);
            self::assertSame('2026-10-20', $new['latest_data_date']);
            self::assertSame(0, $this->db->transactionLevel());
            self::assertSame(2, (int) $this->db->selectOne('SELECT transaction_isolation_level AS isolation_level FROM sys.dm_exec_sessions WHERE session_id=@@SPID')->isolation_level);
        } finally {
            // Only these two synthetic tables in this class's verified random database.
            $this->db->unprepared('DROP TABLE dbo.TblTUPipelinePhases; DROP TABLE dbo.TblTUPipelineBatches');
        }
    }
}
