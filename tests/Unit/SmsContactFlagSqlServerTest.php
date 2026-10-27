<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Repositories\SmsProspectExportRepository;
use Cmd\Reports\Console\Commands\SyncPhoneNumbers;
use Cmd\Reports\Services\SmsAvailabilityLedger;
use Cmd\Reports\Services\SmsDurableAvailabilityService;
use Cmd\Reports\Services\SmsAvailabilityService;
use Cmd\Reports\Services\SmsContactFlagSeek;
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

    private function enableContactSeek(): void
    {
        $this->db->statement('ALTER TABLE dbo.TblProspects ADD SMS_ContactPhone AS CONVERT(nvarchar(10),Phone)');
        $this->db->statement('CREATE INDEX IX_TblProspects_SMS_ContactPhone ON dbo.TblProspects(Contacted,SMS_ContactPhone) INCLUDE(Phone)');
        config(['sms-exports.contact_flag_seek_enabled'=>true]);
    }

    public static function phoneCollations(): array
    {
        return [['SQL_Latin1_General_CP1_CI_AS'],['Latin1_General_100_CI_AI'],['Latin1_General_100_BIN2']];
    }

    #[DataProvider('phoneCollations')]
    public function test_unicode_seek_matches_the_original_sql_for_edge_values(string $collation): void
    {
        $this->db->statement("ALTER TABLE dbo.TblProspects ALTER COLUMN Phone varchar(10) COLLATE {$collation} NULL");
        $this->db->statement("ALTER TABLE dbo.TblPhoneNumbers ALTER COLUMN Phone nvarchar(100) COLLATE {$collation} NULL");
        $prospects=['2025550101','2025550102','2025550103','2025550104','2025550105','2025550106',
            'abcdefghij','ABCDEFGHIJ','cafe','café','?', '', '123',null];
        foreach($prospects as $i=>$phone) $this->db->table('TblProspects')->insert(['PK'=>$i+1,'Phone'=>$phone]);
        $contacts=['12025550101','2025550101','2025550102  ','2025550103X',"2025550104\u{4e2d}",
            '12025550105  ',"\u{ff12}\u{ff10}\u{ff12}\u{ff15}\u{ff15}\u{ff15}\u{ff10}\u{ff11}\u{ff10}\u{ff16}",
            'abcdefghij','CAFÉ',"\u{4e2d}",'','123',null];
        foreach($contacts as $phone) {
            $this->db->table('TblPhoneNumbers')->insert(['Phone'=>$phone]);
        }
        (new SmsProspectExportRepository)->markContacted();
        $expected=$this->db->table('TblProspects')->orderBy('PK')->pluck('Contacted','PK')->all();
        $this->db->table('TblProspects')->update(['Contacted'=>0]);
        $this->enableContactSeek();
        (new SmsProspectExportRepository)->markContacted();
        self::assertSame($expected,$this->db->table('TblProspects')->orderBy('PK')->pluck('Contacted','PK')->all());
        self::assertSame(0,(int)$expected[4], 'Do not truncate a long contact to its ten-character prefix.');
        self::assertSame(0,(int)$expected[11], 'Do not convert an unrepresentable Unicode contact to question mark.');
    }

    public static function invalidSeekSchemas(): array
    {
        return [['wrong_expression'],['wrong_keys'],['disabled_index'],['missing_coverage'],['different_collation'],['numeric_roundabort']];
    }

    #[DataProvider('invalidSeekSchemas')]
    public function test_opt_in_rejects_incompatible_schema_or_settings_before_writing(string $case): void
    {
        $this->db->table('TblProspects')->insert(['PK'=>1,'Phone'=>'2025550101']);
        $this->db->table('TblPhoneNumbers')->insert(['Phone'=>'2025550101']);
        $this->enableContactSeek();
        if($case==='wrong_expression') {
            $this->db->statement('DROP INDEX IX_TblProspects_SMS_ContactPhone ON dbo.TblProspects');
            $this->db->statement('ALTER TABLE dbo.TblProspects DROP COLUMN SMS_ContactPhone');
            $this->db->statement('ALTER TABLE dbo.TblProspects ADD SMS_ContactPhone AS CONVERT(nvarchar(10),LEFT(Phone,9))');
        } elseif($case==='wrong_keys' || $case==='missing_coverage') {
            $this->db->statement('DROP INDEX IX_TblProspects_SMS_ContactPhone ON dbo.TblProspects');
            $keys=$case==='wrong_keys'?'SMS_ContactPhone,Contacted':'Contacted,SMS_ContactPhone';
            $include=$case==='wrong_keys'?' INCLUDE(Phone)':'';
            $this->db->statement("CREATE INDEX IX_TblProspects_SMS_ContactPhone ON dbo.TblProspects({$keys}){$include}");
        } elseif($case==='disabled_index') {
            $this->db->statement('ALTER INDEX IX_TblProspects_SMS_ContactPhone ON dbo.TblProspects DISABLE');
        } elseif($case==='different_collation') {
            $this->db->statement('ALTER TABLE dbo.TblPhoneNumbers ALTER COLUMN Phone nvarchar(50) COLLATE Latin1_General_100_BIN2 NULL');
        } else $this->db->unprepared('SET NUMERIC_ROUNDABORT ON');
        try {
            $error=null;
            try {(new SmsProspectExportRepository)->markContacted();}
            catch(RuntimeException $caught) {$error=$caught;}
            self::assertInstanceOf(RuntimeException::class,$error);
            self::assertStringStartsWith('Contact flag seeks require',$error->getMessage());
            self::assertSame(0,(int)$this->db->table('TblProspects')->value('Contacted'));
            self::assertSame(0,$this->ledger->calls);self::assertSame(0,$this->requests->calls);
        } finally {$this->db->unprepared('SET NUMERIC_ROUNDABORT OFF');}
    }

    public function test_opt_in_unicode_seek_flags_matches_and_is_idempotent(): void
    {
        $this->enableContactSeek();
        $this->test_current_schema_flags_each_matching_prospect_without_inventing_dates_or_other_changes();
    }

    public function test_opt_in_unicode_seek_rolls_back_flags_and_revision(): void
    {
        $this->enableContactSeek();
        $this->test_current_schema_rolls_back_flags_and_revision_together_on_failure();
    }

    public function test_opt_in_does_not_replace_legacy_earliest_response_date_logic(): void
    {
        config(['sms-exports.contact_flag_seek_enabled'=>true]);
        $this->test_legacy_response_date_keeps_earliest_date_and_separate_contacted_date();
    }

    public function test_opt_in_without_reviewed_schema_fails_without_modifying_flags(): void
    {
        config(['sms-exports.contact_flag_seek_enabled'=>true]);
        $this->db->table('TblProspects')->insert(['PK'=>1,'Phone'=>'2025550101']);
        $this->db->table('TblPhoneNumbers')->insert(['Phone'=>'2025550101']);
        try { (new SmsProspectExportRepository)->markContacted(); self::fail('Expected missing schema rejection.'); }
        catch(RuntimeException $error) {self::assertStringContainsString('reviewed lossless Unicode',$error->getMessage());}
        self::assertSame(0,(int)$this->db->table('TblProspects')->value('Contacted'));
        self::assertSame(0,$this->ledger->calls);self::assertSame(0,$this->requests->calls);
    }

    public function test_schema_proposal_is_non_installing_even_if_executed_in_the_local_fixture(): void
    {
        $this->db->unprepared(file_get_contents(__DIR__.'/../../database/sms-performance/contact-flag-seek-proposal.sql'));
        self::assertFalse($this->db->getSchemaBuilder()->hasColumn('TblProspects','SMS_ContactPhone'));
        self::assertSame(0,(int)$this->db->selectOne("SELECT COUNT(*) AS n FROM sys.indexes
            WHERE object_id=OBJECT_ID('dbo.TblProspects') AND name='IX_TblProspects_SMS_ContactPhone'")->n);
        self::assertSame(0,$this->db->table('TblProspects')->count());
    }

    public static function localScaleCases(): array
    {
        return [[200000,1000],[1000000,11000],[200000,90000],[1000000,11000,3300]];
    }

    #[DataProvider('localScaleCases')]
    public function test_local_scale_actual_plan_uses_prospect_phone_seeks_not_a_population_scan(int $population,int $contacts,?int $matched=null): void
    {
        $matched??=$contacts;
        $this->db->unprepared("WITH D(n) AS (SELECT n FROM (VALUES(0),(1),(2),(3),(4),(5),(6),(7),(8),(9))d(n)),
            N(n) AS (SELECT TOP({$population}) ROW_NUMBER() OVER(ORDER BY (SELECT NULL)) FROM D a CROSS JOIN D b
                CROSS JOIN D c CROSS JOIN D d CROSS JOIN D e CROSS JOIN D f)
            INSERT dbo.TblProspects(PK,Phone) SELECT n,CONVERT(varchar(10),CONVERT(bigint,8000000000)+n) FROM N;
            INSERT dbo.TblPhoneNumbers(Phone) SELECT CASE WHEN PK%2=0 THEN '1'+Phone ELSE Phone END
                FROM dbo.TblProspects WHERE PK<={$matched};
            INSERT dbo.TblPhoneNumbers(Phone) SELECT CONVERT(varchar(10),CONVERT(bigint,9000000000)+PK)
                FROM dbo.TblProspects WHERE PK<=".($contacts-$matched).";
            INSERT dbo.TblPhoneNumbers(Phone) SELECT Phone FROM dbo.TblPhoneNumbers;");
        $this->enableContactSeek();
        SmsContactFlagSeek::assertReady($this->db);
        $baseline=<<<'SQL'
WITH Contacts AS (
    SELECT DISTINCT CASE WHEN LEN(Phone) = 11 AND LEFT(Phone, 1) = '1' THEN SUBSTRING(Phone, 2, 10) ELSE Phone END AS Phone
    FROM TblPhoneNumbers WHERE Phone IS NOT NULL
)
UPDATE p SET Contacted = 1 FROM TblProspects p JOIN Contacts c ON c.Phone = p.Phone
WHERE p.Contacted = 0 AND p.Phone IS NOT NULL OPTION (HASH JOIN)
SQL;
        $results=[];
        foreach(['original'=>$baseline,'seek'=>SmsContactFlagSeek::SQL] as $name=>$sql) {
            $this->db->beginTransaction();
            $pdo=$this->db->getPdo();$statement=null;$plans=[];
            try {
                $pdo->exec('SET STATISTICS XML ON');$started=microtime(true);
                $statement=$pdo->query($sql);
                do {
                    if($statement->columnCount()>0) foreach($statement->fetchAll(PDO::FETCH_NUM) as $row) {
                        if(is_string($row[0]??null) && str_contains($row[0],'<ShowPlanXML')) $plans[]=$row[0];
                    }
                } while($statement->nextRowset());
                $seconds=microtime(true)-$started;
                $statement->closeCursor();$pdo->exec('SET STATISTICS XML OFF');
                self::assertSame($matched,$this->db->table('TblProspects')->where('Contacted',1)->count());
                self::assertSame(0,$this->db->table('TblProspects')->where('Contacted',1)->where('PK','>',$matched)->count());
                self::assertCount(1,$plans);
                $xml=simplexml_load_string($plans[0]);self::assertNotFalse($xml);
                $xml->registerXPathNamespace('s','http://schemas.microsoft.com/sqlserver/2004/07/showplan');
                $operators=[];
                foreach($xml->xpath('//s:RelOp[s:IndexScan/s:Object[@Table="[TblProspects]"]]') as $op) {
                    $op->registerXPathNamespace('s','http://schemas.microsoft.com/sqlserver/2004/07/showplan');
                    $objects=$op->xpath('./s:IndexScan/s:Object');
                    $runtime=$op->xpath('./s:RunTimeInformation/s:RunTimeCountersPerThread');
                    $reads=0;$rows=0;
                    foreach($runtime as $counter) {$reads+=(int)$counter['ActualLogicalReads'];$rows+=(int)$counter['ActualRowsRead'];}
                    $operators[]=['operator'=>(string)$op['PhysicalOp'],'index'=>(string)$objects[0]['Index'],
                        'actual_rows_read'=>$rows,'actual_logical_reads'=>$reads];
                }
                $results[$name]=['seconds_including_actual_plan'=>$seconds,'prospect_read_operators'=>$operators,
                    'plan_sha256'=>hash('sha256',$plans[0])];
                self::assertNotEmpty($operators);
                if($name!=='original') foreach($operators as $op) {
                    self::assertContains($op['operator'],['Index Seek','Clustered Index Seek']);
                    self::assertLessThanOrEqual($matched,$op['actual_rows_read']);
                }
                if($name!=='original') {
                    self::assertContains('[IX_TblProspects_SMS_ContactPhone]',array_column($operators,'index'));
                    self::assertCount(1,$operators,'The direct update must avoid the extra PK read alias.');
                }
            } finally {
                if($statement!==null)$statement->closeCursor();
                $pdo->exec('SET STATISTICS XML OFF');$this->db->rollBack();
            }
        }
        $path=getenv('SMS_CONTACT_SEEK_BENCH_REPORT');
        if(is_string($path) && $path!=='') {
            $report=['scope'=>'loopback synthetic fixture only','candidate'=>'direct update, no self join','observed_at_utc'=>gmdate('c'),
                'prospects'=>$population,'contact_rows'=>$contacts*2,'distinct_normalized_contacts'=>$contacts,
                'expected_matched_prospects'=>$matched,'results'=>$results,
                'production_runtime_acceptance'=>false,'warm_cache_control'=>false];
            self::assertNotFalse(file_put_contents($path.'-'.$population.'-'.$contacts.'-'.$matched.'.json',json_encode($report,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)));
        }
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
            ['ID'=>'000123','PHONE'=>'2025550102','CREATED_DATE'=>'2024-02-29'],
        ]);
        self::assertSame($id,$rows[0]['cid']); self::assertSame('000123',$rows[1]['cid']);
        (new \ReflectionProperty($command,'withCreatedDate'))->setValue($command,true);
        self::assertSame(2,(new \ReflectionMethod($command,'insertPhoneBatch'))->invoke($command,$this->db->getPdo(),$rows,1));
        self::assertSame($id,$this->db->table('TblPhoneNumbers')->where('Phone','2025550101')->value('CID'));
        self::assertSame('2026-10-12',$this->db->table('TblPhoneNumbers')->where('Phone','2025550101')->value('Created_Date'));
        self::assertSame('000123',$this->db->table('TblPhoneNumbers')->where('Phone','2025550102')->value('CID'));
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
        (new \ReflectionProperty($command,'withCreatedDate'))->setValue($command,true);
        $stage = tempnam(sys_get_temp_dir(),'sms-contact-id-fixture-');
        self::assertNotFalse($stage);
        file_put_contents($stage,json_encode(['phone'=>'2025550191','cid'=>'123','created'=>'2026-10-12'])."\n".json_encode(['phone'=>'2025550192','cid'=>'1e30','created'=>'2026-10-12'])."\n");
        try {
            try { (new \ReflectionMethod($command,'replacePhonesFromFile'))->invoke($command,$this->db->getPdo(),$stage,1); self::fail('Expected ID rejection.'); }
            catch (RuntimeException $e) { self::assertSame('Unexpected contact ID format; the phone snapshot was not replaced.',$e->getMessage()); }
            self::assertSame($before,$this->db->table('TblPhoneNumbers')->orderBy('Phone')->get()->toJson());
            self::assertFalse($this->db->getPdo()->inTransaction());
            self::assertSame(0,$this->requests->calls);
        } finally { unlink($stage); }
    }

    public static function companies(): array {return [['lt','DP_LT'],['ldr','DP_LDR'],['plaw','DP_PLAW']];}

    public static function invalidStagedMetadata(): array
    {
        return [[null,'2026-10-12'],['123',null],['123','2026-02-30']];
    }

    #[DataProvider('invalidStagedMetadata')]
    public function test_invalid_late_stage_rolls_back_native_phone_snapshot_without_dirty_revision(mixed $id,mixed $date): void
    {
        $this->db->table('TblPhoneNumbers')->insert(['Phone'=>'2025550101','Source'=>'DP_LT','CID'=>'19']);
        $before=$this->db->table('TblPhoneNumbers')->get()->toJson();
        $command=new class extends SyncPhoneNumbers {
            public function __construct() {parent::__construct();$this->withCreatedDate=true;}
            public function info($string,$verbosity=null) {}
        };
        $stage=tempnam(sys_get_temp_dir(),'sms-validity-native-');self::assertNotFalse($stage);
        file_put_contents($stage,json_encode(['phone'=>'2025550199','cid'=>'123','created'=>'2026-10-12'])."\n"
            .json_encode(['phone'=>'2025550198','cid'=>$id,'created'=>$date])."\n");
        try {
            $error=null;
            try {(new \ReflectionMethod($command,'replacePhonesFromFile'))->invoke($command,$this->db->getPdo(),$stage,1);}
            catch(RuntimeException $caught) {$error=$caught;}
            self::assertInstanceOf(RuntimeException::class,$error);
            self::assertSame($before,$this->db->table('TblPhoneNumbers')->get()->toJson());
            self::assertFalse($this->db->getPdo()->inTransaction());
            self::assertFalse((new \ReflectionProperty($command,'phoneSnapshotCommitted'))->getValue($command));
            self::assertSame(0,$this->db->table('test_revisions')->count());
            self::assertSame(0,$this->ledger->calls);self::assertSame(0,$this->requests->calls);
        } finally {unlink($stage);}
    }

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
        file_put_contents($stage,json_encode(['phone'=>'2025550191','cid'=>$cid,'created'=>'2026-10-12'])."\n".json_encode(['phone'=>'','cid'=>'17','created'=>'2026-10-12'])."\n");
        try {
            self::assertSame([1,2,1],(new \ReflectionMethod($command,'replacePhonesFromFile'))->invoke($command,$this->db->getPdo(),$stage,1));
            self::assertSame($others,$this->db->table('TblPhoneNumbers')->where('Source','<>',$label)->orderBy('Source')->get()->toJson());
            $own=$this->db->table('TblPhoneNumbers')->where('Source',$label)->get();self::assertCount(1,$own);
            self::assertSame('2025550191',$own[0]->Phone);self::assertSame($cid,$own[0]->CID);self::assertSame('2026-10-12',$own[0]->Created_Date);
            self::assertFalse($this->db->getPdo()->inTransaction());
        } finally {unlink($stage);}
    }
}
