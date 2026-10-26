<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Repositories\SmsProspectExportRepository;
use Cmd\Reports\Console\Commands\SyncPhoneNumbers;
use Cmd\Reports\Services\SmsAvailabilityLedger;
use Cmd\Reports\Services\SmsDurableAvailabilityService;
use Cmd\Reports\Services\SmsAvailabilityService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Support\Facades\Facade;
use PDO;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/** Real engine regression for the observed schema; hard-coded loopback, random owned DB only. */
final class SmsContactFlagSqlServerTest extends TestCase
{
    private static string $database;
    private static ?PDO $admin = null;
    private Container $previous;
    private Connection $db;
    private object $ledger;
    private object $requests;

    public static function setUpBeforeClass(): void
    {
        if (! in_array('sqlsrv', PDO::getAvailableDrivers(), true)) self::markTestSkipped('Loopback SQL Server PDO is required.');
        self::$database = 'sms_contact_flag_test_'.bin2hex(random_bytes(8));
        self::$admin = new PDO('sqlsrv:Server=127.0.0.1,14330;Database=master;Encrypt=no;TrustServerCertificate=yes',
            'sa', 'LocalDev!2026Pass', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        self::$admin->exec('CREATE DATABASE ['.self::$database.']');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$admin !== null && preg_match('/\Asms_contact_flag_test_[a-f0-9]{16}\z/D', self::$database)) {
            self::$admin->exec('ALTER DATABASE ['.self::$database.'] SET SINGLE_USER WITH ROLLBACK IMMEDIATE');
            self::$admin->exec('DROP DATABASE ['.self::$database.']'); self::$admin = null;
        }
    }

    protected function setUp(): void
    {
        $this->previous = Container::getInstance();
        $app = new Container; Container::setInstance($app);
        $app->instance('config', new Repository(['database'=>['default'=>'sqlsrv'], 'sms-exports'=>['durable_availability_enabled'=>true]]));
        $capsule = new Manager($app);
        $capsule->addConnection(['driver'=>'sqlsrv','host'=>'127.0.0.1,14330','database'=>self::$database,
            'username'=>'sa','password'=>'LocalDev!2026Pass','encrypt'=>'no','trust_server_certificate'=>'yes'], 'sqlsrv');
        $capsule->getDatabaseManager()->setDefaultConnection('sqlsrv');
        $app->instance('db', $capsule->getDatabaseManager());
        Facade::clearResolvedInstances(); Facade::setFacadeApplication($app);
        $this->db = $capsule->getConnection('sqlsrv');
        self::assertSame(self::$database, $this->db->selectOne('SELECT DB_NAME() AS name')->name);
        $this->db->setTransactionManager(new DatabaseTransactionsManager);
        $this->db->unprepared('SET LOCK_TIMEOUT 3000; DROP TABLE IF EXISTS dbo.TblProspects; DROP TABLE IF EXISTS dbo.TblPhoneNumbers; DROP TABLE IF EXISTS dbo.test_revisions;
            CREATE TABLE dbo.TblProspects(PK int PRIMARY KEY,Phone varchar(10) NULL,Contacted bit NOT NULL DEFAULT 0,
                Contacted_Date date NULL,Creation_Date date NULL,Last_SMS_Date date NULL,Latest_Export_Date date NULL,
                SMS_Attempts int NOT NULL DEFAULT 0,Opt_Out bit NOT NULL DEFAULT 0,Invalid_Phone bit NOT NULL DEFAULT 0);
            CREATE TABLE dbo.TblPhoneNumbers(Phone nvarchar(50) NULL,Created_Date date NULL,Source nvarchar(50),CID nvarchar(50));
            CREATE TABLE dbo.test_revisions(id int IDENTITY PRIMARY KEY);');
        $this->ledger = new class {
            public bool $fail = false;
            public int $calls = 0;
            public function dirty(Connection $db): void {
                ++$this->calls;
                if ($db->transactionLevel() !== 1 || ! $db->getPdo()->inTransaction()) throw new RuntimeException('Missing transaction.');
                $db->statement('INSERT INTO test_revisions DEFAULT VALUES');
                if ($this->fail) throw new RuntimeException('Synthetic revision failure.');
            }
        };
        $this->requests = new class($this->db) {
            public int $calls = 0;
            public function __construct(private Connection $db) {}
            public function request(): void {
                if ($this->db->transactionLevel() !== 0 || $this->db->getPdo()->inTransaction()) throw new RuntimeException('Request before commit.');
                ++$this->calls;
            }
        };
        $app->instance(SmsAvailabilityLedger::class, $this->ledger);
        $app->instance(SmsDurableAvailabilityService::class, $this->requests);
    }

    protected function tearDown(): void
    {
        $this->db->disconnect(); Facade::clearResolvedInstances();
        Container::setInstance($this->previous); Facade::setFacadeApplication($this->previous);
    }

    public function test_current_schema_flags_each_matching_prospect_without_inventing_dates_or_other_changes(): void
    {
        foreach ([1=>'2025550101',2=>'2025550101',3=>'2025550102',4=>'2025550103',5=>null] as $pk=>$phone) {
            $this->db->table('TblProspects')->insert(['PK'=>$pk,'Phone'=>$phone,'Contacted'=>$pk===4?1:0,
                'Contacted_Date'=>$pk===1?'2026-10-01':null,'Creation_Date'=>'2026-09-01','Last_SMS_Date'=>'2026-10-05',
                'Latest_Export_Date'=>'2026-10-06','SMS_Attempts'=>3,'Opt_Out'=>1,'Invalid_Phone'=>1]);
        }
        $this->db->table('TblPhoneNumbers')->insert([
            ['Phone'=>'12025550101','Created_Date'=>'2026-10-14','Source'=>'DP_LT','CID'=>'12345678901234567890123456789012345678'],
            ['Phone'=>'2025550101','Created_Date'=>'2026-10-12','Source'=>'DP_LDR','CID'=>'17'],
            ['Phone'=>'2025550103','Created_Date'=>null,'Source'=>'DP_PLAW','CID'=>'18'],
            ['Phone'=>null,'Created_Date'=>null,'Source'=>'DP','CID'=>null],
        ]);
        $before = $this->db->table('TblProspects')->orderBy('PK')->get()->map(fn($r)=>(array)$r)->all();
        $phones = $this->db->table('TblPhoneNumbers')->orderBy('CID')->get()->toJson();
        $repo = new SmsProspectExportRepository;
        self::assertSame(2, $repo->markContacted());
        $after = $this->db->table('TblProspects')->orderBy('PK')->get()->map(fn($r)=>(array)$r)->all();
        foreach ($before as $i=>$row) {
            $expected = $row; if ($row['PK'] <= 2) $expected['Contacted'] = 1;
            self::assertEquals($expected, $after[$i]);
        }
        self::assertSame($phones, $this->db->table('TblPhoneNumbers')->orderBy('CID')->get()->toJson());
        self::assertSame(1,$this->ledger->calls); self::assertSame(1,$this->requests->calls);
        self::assertSame(0,$repo->markContacted());
        self::assertSame(1,$this->ledger->calls); self::assertSame(1,$this->requests->calls);
    }

    public function test_legacy_response_date_keeps_earliest_date_and_separate_contacted_date(): void
    {
        $this->db->statement('ALTER TABLE TblProspects ADD Response_Date date NULL');
        foreach ([1=>null,2=>'2026-10-01',3=>'2026-10-20'] as $pk=>$date) {
            $this->db->table('TblProspects')->insert(['PK'=>$pk,'Phone'=>'2025550101','Response_Date'=>$date,'Contacted_Date'=>'2026-09-01']);
        }
        $this->db->table('TblPhoneNumbers')->insert([
            ['Phone'=>'12025550101','Created_Date'=>'2026-10-14'],['Phone'=>'2025550101','Created_Date'=>'2026-10-12'],
        ]);
        self::assertSame(3,(new SmsProspectExportRepository)->markContacted());
        self::assertSame(['2026-10-12','2026-10-01','2026-10-12'],$this->db->table('TblProspects')->orderBy('PK')->pluck('Response_Date')->all());
        self::assertSame(['2026-09-01','2026-09-01','2026-09-01'],$this->db->table('TblProspects')->orderBy('PK')->pluck('Contacted_Date')->all());
        self::assertSame(1,$this->requests->calls);
    }

    public function test_current_schema_rolls_back_flags_and_revision_together_on_failure(): void
    {
        $this->db->table('TblProspects')->insert(['PK'=>1,'Phone'=>'2025550101']);
        $this->db->table('TblPhoneNumbers')->insert(['Phone'=>'2025550101','Created_Date'=>'2026-10-12']);
        $this->ledger->fail = true;
        try { (new SmsProspectExportRepository)->markContacted(); self::fail('Expected revision failure.'); }
        catch (RuntimeException $e) { self::assertSame('Synthetic revision failure.',$e->getMessage()); }
        self::assertSame(0,(int)$this->db->table('TblProspects')->value('Contacted'));
        self::assertSame(0,$this->db->table('test_revisions')->count());
        self::assertSame(0,$this->requests->calls); self::assertSame(0,$this->db->transactionLevel());
    }

    public function test_original_contact_ids_round_trip_without_integer_truncation_and_bad_ids_are_rejected(): void
    {
        $command = new class extends SyncPhoneNumbers {
            public function info($string, $verbosity = null) {}
        };
        $id = '12345678901234567890123456789012345678';
        $normalize = new \ReflectionMethod($command,'normalizePhones');
        $rows = $normalize->invoke($command,[
            ['ID'=>$id,'PHONE'=>'2025550101','CREATED_DATE'=>'2026-10-12'],
            ['ID'=>null,'PHONE'=>'2025550102','CREATED_DATE'=>null],
        ]);
        self::assertSame($id,$rows[0]['cid']); self::assertNull($rows[1]['cid']);
        (new \ReflectionProperty($command,'withCreatedDate'))->setValue($command,true);
        self::assertSame(2,(new \ReflectionMethod($command,'insertPhoneBatch'))->invoke($command,$this->db->getPdo(),$rows,1));
        self::assertSame($id,$this->db->table('TblPhoneNumbers')->where('Phone','2025550101')->value('CID'));
        self::assertSame('2026-10-12',$this->db->table('TblPhoneNumbers')->where('Phone','2025550101')->value('Created_Date'));
        self::assertNull($this->db->table('TblPhoneNumbers')->where('Phone','2025550102')->value('CID'));
        foreach ([1.2e30,'1e30',str_repeat('9',39),"1'; PRIVATE"] as $badId) {
            try { $normalize->invoke($command,[['ID'=>$badId,'PHONE'=>'2025550199']]); self::fail('Expected ID rejection.'); }
            catch (RuntimeException $e) { self::assertSame('Unexpected contact ID format; the phone snapshot was not replaced.',$e->getMessage()); }
        }
        self::assertSame(2,$this->db->table('TblPhoneNumbers')->count());
    }

    public function test_bad_staged_contact_id_rolls_back_the_source_replacement_and_preserves_other_sources(): void
    {
        config(['sms-exports.durable_availability_enabled'=>false]);
        $this->db->table('TblPhoneNumbers')->insert([
            ['Phone'=>'2025550101','Created_Date'=>'2026-10-12','Source'=>'DP_LT','CID'=>'19'],
            ['Phone'=>'2025550102','Created_Date'=>null,'Source'=>'DP','CID'=>null],
        ]);
        $before = $this->db->table('TblPhoneNumbers')->orderBy('Phone')->get()->toJson();
        $command = new class extends SyncPhoneNumbers { public function info($string,$verbosity=null) {} };
        $stage = tempnam(sys_get_temp_dir(),'sms-contact-id-fixture-');
        self::assertNotFalse($stage);
        file_put_contents($stage,json_encode(['phone'=>'2025550191','cid'=>'123'])."\n".json_encode(['phone'=>'2025550192','cid'=>'1e30'])."\n");
        try {
            try { (new \ReflectionMethod($command,'replacePhonesFromFile'))->invoke($command,$this->db->getPdo(),$stage,1); self::fail('Expected ID rejection.'); }
            catch (RuntimeException $e) { self::assertSame('Unexpected contact ID format; the phone snapshot was not replaced.',$e->getMessage()); }
            self::assertSame($before,$this->db->table('TblPhoneNumbers')->orderBy('Phone')->get()->toJson());
            self::assertFalse($this->db->getPdo()->inTransaction());
            self::assertSame(0,$this->requests->calls);
        } finally { unlink($stage); }
    }

    public static function companies(): array {return [['lt','DP_LT'],['ldr','DP_LDR'],['plaw','DP_PLAW']];}

    #[DataProvider('companies')]
    public function test_explicit_company_replaces_and_cleans_only_its_own_label(string $environment,string $label): void
    {
        config(['sms-exports.durable_availability_enabled'=>false]);
        Container::getInstance()->instance(SmsAvailabilityService::class,new class {public function invalidate():void {}});
        foreach(['DP','DP_LT','DP_LDR','DP_PLAW','UNKNOWN'] as $source) {
            $this->db->table('TblPhoneNumbers')->insert(['Phone'=>'','Source'=>$source,'CID'=>null,'Created_Date'=>null]);
        }
        $others=$this->db->table('TblPhoneNumbers')->where('Source','<>',$label)->orderBy('Source')->get()->toJson();
        $command=new class($environment) extends SyncPhoneNumbers {
            public function __construct(private string $selected) {parent::__construct();}
            public function option($key=null) {return ['source'=>$this->selected,'dry-run'=>true][$key]??null;}
            protected function syncNow():int {return 0;}
            public function info($string,$verbosity=null) {}
        };
        self::assertSame(0,$command->handle());
        (new \ReflectionProperty($command,'withCreatedDate'))->setValue($command,true);
        $stage=tempnam(sys_get_temp_dir(),'sms-company-native-');self::assertNotFalse($stage);
        $cid='12345678901234567890123456789012345678';
        file_put_contents($stage,json_encode(['phone'=>'2025550191','cid'=>$cid,'created'=>'2026-10-12'])."\n".json_encode(['phone'=>'','cid'=>'17','created'=>null])."\n");
        try {
            self::assertSame([1,2,1],(new \ReflectionMethod($command,'replacePhonesFromFile'))->invoke($command,$this->db->getPdo(),$stage,1));
            self::assertSame($others,$this->db->table('TblPhoneNumbers')->where('Source','<>',$label)->orderBy('Source')->get()->toJson());
            $own=$this->db->table('TblPhoneNumbers')->where('Source',$label)->get();self::assertCount(1,$own);
            self::assertSame('2025550191',$own[0]->Phone);self::assertSame($cid,$own[0]->CID);self::assertSame('2026-10-12',$own[0]->Created_Date);
            self::assertFalse($this->db->getPdo()->inTransaction());
        } finally {unlink($stage);}
    }
}
