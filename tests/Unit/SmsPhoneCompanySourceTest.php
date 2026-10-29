<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Console\Commands\SyncPhoneNumbers;
use Cmd\Reports\Services\DBConnector;
use Cmd\Reports\Services\SmsDebtPleteImportPrivacy;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Fake source connector only: no credentials, database, provider, scheduler or network. */
final class SmsPhoneCompanySourceTest extends TestCase
{
    private Container $previous;
    private object $logger;

    protected function setUp(): void
    {
        $this->previous=Container::getInstance();$app=new Container;Container::setInstance($app);
        $app->instance('config',new Repository(['sms-exports'=>['wide_phone_contacts_enabled'=>true]]));
        $this->logger=new class {
            public array $contexts=[];public array $failures=[];
            public function info($message,array $context=[]):void {$this->contexts[]=$context;}
            public function error($message,array $context=[]):void {$this->failures[]=[$message,$context];}
        };
        $app->instance('log',$this->logger);Facade::clearResolvedInstances();Facade::setFacadeApplication($app);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();Container::setInstance($this->previous);Facade::setFacadeApplication($this->previous);
    }

    private function command(mixed $source='lt'): SyncPhoneNumbers
    {
        return new class($source) extends SyncPhoneNumbers {
            public array $environments=[];
            public array $errors=[];
            public int $sqlInitializations=0;
            public bool $failSource=false;
            public array $privacyStates=[];
            public function __construct(public mixed $selected) {parent::__construct();}
            public function option($key=null) {return ['source'=>$this->selected,'dry-run'=>true,'batch-size'=>1000][$key]??null;}
            public function info($string,$verbosity=null) {}
            public function warn($string,$verbosity=null) {}
            public function error($string,$verbosity=null) {$this->errors[]=$string;}
            protected function initializeSqlServerConnection(): \PDO {++$this->sqlInitializations;throw new \RuntimeException('Unexpected SQL initialization.');}
            protected function createSnowflakeConnector(): DBConnector {
                $this->environments[]=$this->sourceEnvironment;
                $this->privacyStates[]=[SmsDebtPleteImportPrivacy::active(),env('SNOWFLAKE_DEBUG',false)];
                return new class($this->failSource) extends DBConnector {
                    public function __construct(private bool $fail) {}
                    public function query(string $sql,array $bindings=[],?int $timeoutSeconds=null):array {
                        if ($this->fail) throw new \RuntimeException('PRIVATE_PHONE_TOKEN_SQL_BINDINGS');
                        return ['data'=>[['SOURCE_ROWS'=>1,'NORMALIZED_ROWS'=>1,'CONTACTS'=>1]]];
                    }
                };
            }
        };
    }

    public static function companies(): array {return [['lt','DP_LT'],['ldr','DP_LDR'],['plaw','DP_PLAW']];}

    #[DataProvider('companies')]
    public function test_company_selects_its_own_connector_and_label_without_target_writes(string $source,string $label): void
    {
        $command=$this->command($source);
        self::assertSame(0,$command->handle());self::assertSame([$source],$command->environments);
        self::assertSame(0,$command->sqlInitializations);self::assertSame([],$command->errors);
        self::assertSame([$label,$label],array_column($this->logger->contexts,'source'));
        self::assertSame([[true,false]],$command->privacyStates);
        self::assertFalse(SmsDebtPleteImportPrivacy::active());
    }

    public function test_existing_unqualified_schedule_keeps_lt_and_reused_command_does_not_keep_previous_company(): void
    {
        $command=$this->command();self::assertSame('lt',$command->getDefinition()->getOption('source')->getDefault());
        self::assertSame(0,$command->handle());$command->selected='plaw';self::assertSame(0,$command->handle());
        $command->selected='lt';self::assertSame(0,$command->handle());
        self::assertSame(['lt','plaw','lt'],$command->environments);self::assertSame(0,$command->sqlInitializations);
    }

    public static function invalidCompanies(): array {return [['DP'],['DP_LT'],['LT'],['all'],['lt,ldr'],["lt';DELETE"],[null],[['lt','ldr']]];}

    #[DataProvider('invalidCompanies')]
    public function test_unknown_or_ambiguous_company_is_rejected_before_connectors_or_sql(mixed $source): void
    {
        $command=$this->command($source);self::assertSame(1,$command->handle());
        self::assertSame([],$command->environments);self::assertSame(0,$command->sqlInitializations);
        self::assertSame([],$this->logger->contexts);
        self::assertSame(['Choose one company source: lt, ldr, or plaw. No synchronization was started.'],$command->errors);
    }

    public static function priorDebugValues():array {return [['true'],['false'],[null]];}

    #[DataProvider('priorDebugValues')]
    public function test_private_scope_and_debug_are_restored_after_source_failure_without_exposing_values(?string $value):void
    {
        $repo=Env::getRepository();$had=$repo->has('SNOWFLAKE_DEBUG');$old=$repo->get('SNOWFLAKE_DEBUG');
        try {
            if($value===null)$repo->clear('SNOWFLAKE_DEBUG');else $repo->set('SNOWFLAKE_DEBUG',$value);
            $command=$this->command('plaw');$command->failSource=true;
            self::assertSame(1,$command->handle());self::assertSame([[true,false]],$command->privacyStates);
            self::assertSame($value,$repo->get('SNOWFLAKE_DEBUG'));self::assertSame($value!==null,$repo->has('SNOWFLAKE_DEBUG'));
            self::assertFalse(SmsDebtPleteImportPrivacy::active());
            self::assertSame(['SyncPhoneNumbers stopped during source_summary. Phone snapshot committed: no.'],$command->errors);
            self::assertStringNotContainsString('PRIVATE_PHONE_TOKEN_SQL_BINDINGS',json_encode([$command->errors,$this->logger->contexts,$this->logger->failures]));
            self::assertSame('source_summary',$this->logger->failures[0][1]['phase']);
            self::assertArrayNotHasKey('exception',$this->logger->failures[0][1]);
        } finally {if($had)$repo->set('SNOWFLAKE_DEBUG',$old);else $repo->clear('SNOWFLAKE_DEBUG');}
    }

    public function test_debug_is_restored_after_success_for_the_next_job_in_the_same_worker():void
    {
        $repo=Env::getRepository();$had=$repo->has('SNOWFLAKE_DEBUG');$old=$repo->get('SNOWFLAKE_DEBUG');
        try {
            $repo->set('SNOWFLAKE_DEBUG','true');$command=$this->command('ldr');
            self::assertSame(0,$command->handle());self::assertSame([[true,false]],$command->privacyStates);
            self::assertSame('true',$repo->get('SNOWFLAKE_DEBUG'));self::assertFalse(SmsDebtPleteImportPrivacy::active());
        } finally {if($had)$repo->set('SNOWFLAKE_DEBUG',$old);else $repo->clear('SNOWFLAKE_DEBUG');}
    }

    public function test_postcommit_connection_failure_keeps_true_commit_receipt_and_next_failed_replacement_resets_it():void
    {
        $pdo=new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE TblPhoneNumbers (Phone TEXT, Source TEXT, CID TEXT, Created_Date TEXT, Data_Source TEXT, External_ID TEXT, Data_Source_ID TEXT, Data_Source_Origin TEXT, Data_Source_Map_SHA256 TEXT, Status TEXT, Status_ID TEXT, Status_Stamp TEXT, Phone1 TEXT, Phone2 TEXT, Phone3 TEXT, Phone4 TEXT)');
        $path=tempnam(sys_get_temp_dir(),'sms-phone-commit-fixture-');
        self::assertNotFalse($path);
        $command=$this->command();
        (new \ReflectionProperty($command,'withCreatedDate'))->setValue($command,true);
        $manager=\Mockery::mock();
        $manager->shouldReceive('connection')->once()->with('sqlsrv')->andThrow(new \RuntimeException('PRIVATE_POSTCOMMIT_CONNECTION'));
        DB::swap($manager);
        try {
            file_put_contents($path,json_encode(['phone'=>'2025550199','cid'=>'123','created'=>'2026-10-12'])."\n");
            try {(new \ReflectionMethod($command,'replacePhonesFromFile'))->invoke($command,$pdo,$path,1);self::fail('Expected postcommit failure.');}
            catch(\RuntimeException $error){self::assertSame('PRIVATE_POSTCOMMIT_CONNECTION',$error->getMessage());}
            self::assertSame(1,(int)$pdo->query('SELECT COUNT(*) FROM TblPhoneNumbers')->fetchColumn());
            self::assertFalse($pdo->inTransaction());
            $receipt=new \ReflectionProperty($command,'phoneSnapshotCommitted');
            self::assertTrue($receipt->getValue($command));
            (new \ReflectionMethod($command,'reportFailure'))->invoke($command,new \RuntimeException('PRIVATE_POSTCOMMIT_CONNECTION'),'phone_replacement',$receipt->getValue($command));
            self::assertSame(['SyncPhoneNumbers stopped during phone_replacement. Phone snapshot committed: yes.'],$command->errors);
            self::assertStringNotContainsString('PRIVATE_POSTCOMMIT_CONNECTION',json_encode([$command->errors,$this->logger->failures]));
            file_put_contents($path,"invalid json\n");
            try {(new \ReflectionMethod($command,'replacePhonesFromFile'))->invoke($command,$pdo,$path,1);self::fail('Expected rollback.');}
            catch(\JsonException){}
            self::assertFalse($receipt->getValue($command));
            self::assertSame(1,(int)$pdo->query('SELECT COUNT(*) FROM TblPhoneNumbers')->fetchColumn());
            self::assertFalse($pdo->inTransaction());
        } finally {unlink($path);\Mockery::close();}
    }

    public static function commitStates():array {return [[false,'no'],[true,'yes']];}

    public static function invalidSourceSnapshots(): array
    {
        return [[null, '2026-10-12',true], ['123', null,true], ['123', '2026-02-30',true],['123','2026-10-12',false]];
    }

    #[DataProvider('invalidSourceSnapshots')]
    public function test_late_invalid_source_page_fails_before_replacement_and_removes_staging(mixed $id, mixed $date,bool $schemaReady): void
    {
        $app=Container::getInstance();
        $capsule=new \Illuminate\Database\Capsule\Manager($app);
        $capsule->addConnection(['driver'=>'sqlite','database'=>':memory:'] ,'sqlsrv');
        $app->instance('db',$capsule->getDatabaseManager());
        $db=$capsule->getConnection('sqlsrv');
        $db->statement('CREATE TABLE TblPhoneNumbers (Phone TEXT,Source TEXT,CID TEXT,Data_Source TEXT,External_ID TEXT,Data_Source_ID TEXT,Data_Source_Origin TEXT,Data_Source_Map_SHA256 TEXT,Status TEXT,Status_ID TEXT,Status_Stamp TEXT,Phone1 TEXT,Phone2 TEXT,Phone3 TEXT,Phone4 TEXT'.($schemaReady?',Created_Date TEXT':'').')');
        $db->table('TblPhoneNumbers')->insert(['Phone'=>'2025550101','Source'=>'DP_LT','CID'=>'old']);
        $before=$db->table('TblPhoneNumbers')->get()->toJson();
        $files=glob(sys_get_temp_dir().'/sync-phone-*');
        $command=new class($db->getPdo(),$id,$date) extends SyncPhoneNumbers {
            public int $queries=0;
            public array $errors=[];
            public function __construct(private \PDO $pdo,public mixed $id,public mixed $date) {parent::__construct();}
            public function option($key=null) {return ['source'=>'lt','dry-run'=>false,'batch-size'=>1000,'phones-only'=>true][$key]??null;}
            public function info($string,$verbosity=null) {}
            public function warn($string,$verbosity=null) {}
            public function error($string,$verbosity=null) {$this->errors[]=$string;}
            protected function initializeSqlServerConnection(): \PDO {return $this->pdo;}
            protected function flagContactedProspects(): ?int {throw new \LogicException('Flagging must not start.');}
            protected function createSnowflakeConnector(): DBConnector {
                return new class($this) extends DBConnector {
                    public function __construct(private object $command) {}
                    public function query(string $sql,array $bindings=[],?int $timeoutSeconds=null):array {
                        ++$this->command->queries;
                        return ['data'=>$this->command->queries===1
                            ? array_fill(0,5000,['PHONE'=>'2025550199','ID'=>'123','CREATED_DATE'=>'2026-10-12','PHONE_SLOT'=>0])
                            : [['PHONE'=>'2025550198','ID'=>$this->command->id,'CREATED_DATE'=>$this->command->date,'PHONE_SLOT'=>1]]];
                    }
                };
            }
        };
        try {
            self::assertSame(1,$command->handle());
            self::assertSame($schemaReady?2:0,$command->queries);
            self::assertSame($before,$db->table('TblPhoneNumbers')->get()->toJson());
            self::assertFalse($db->getPdo()->inTransaction());
            $phase=$schemaReady?'source_staging':'target_schema';
            self::assertSame(['SyncPhoneNumbers stopped during '.$phase.'. Phone snapshot committed: no.'],$command->errors);
            self::assertSame([],array_values(array_diff(glob(sys_get_temp_dir().'/sync-phone-*'),$files)));
            self::assertFalse(SmsDebtPleteImportPrivacy::active());
            self::assertStringNotContainsString('2025550199',json_encode($this->logger->failures));
        } finally {$db->disconnect();}
    }

    #[DataProvider('commitStates')]
    public function test_failure_reports_commit_state_without_claiming_rollback_after_a_commit(bool $committed,string $word):void
    {
        $command=$this->command();
        (new \ReflectionMethod($command,'reportFailure'))->invoke($command,new \RuntimeException('PRIVATE_PHONE_TOKEN_SQL_BINDINGS'),'contact_flags',$committed);
        self::assertSame(['SyncPhoneNumbers stopped during contact_flags. Phone snapshot committed: '.$word.'.'],$command->errors);
        self::assertSame($committed,$this->logger->failures[0][1]['phone_snapshot_committed']);
        self::assertStringNotContainsString('PRIVATE_PHONE_TOKEN_SQL_BINDINGS',json_encode([$command->errors,$this->logger->failures]));
        self::assertArrayNotHasKey('exception',$this->logger->failures[0][1]);
    }
}
