<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Console\Commands\SyncPhoneNumbers;
use Cmd\Reports\Services\DBConnector;
use Cmd\Reports\Services\SmsAvailabilityService;
use Cmd\Reports\Services\SmsPhoneSnapshotStage;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

/** Source pages and target are synthetic local SQLite; no remote connector is used. */
final class SmsPhoneSnapshotDedupTest extends TestCase
{
    private Container $previous;
    private object $db;
    private object $logger;

    protected function setUp(): void
    {
        $this->previous = Container::getInstance(); $app = new Container; Container::setInstance($app);
        $app->instance('config', new Repository([]));
        $manager = new Manager($app); $manager->addConnection(['driver'=>'sqlite','database'=>':memory:'], 'sqlsrv');
        $app->instance('db', $manager->getDatabaseManager());
        $app->instance(SmsAvailabilityService::class, new class { public function invalidate(): void {} });
        $this->logger = new class {
            public array $entries = [];
            public function info($message, array $context=[]): void { $this->entries[] = [$message,$context]; }
            public function error($message, array $context=[]): void { $this->entries[] = [$message,$context]; }
        };
        $app->instance('log', $this->logger); Facade::clearResolvedInstances(); Facade::setFacadeApplication($app);
        $this->db = $manager->getConnection('sqlsrv');
        $this->db->statement('CREATE TABLE TblPhoneNumbers(Phone TEXT,Source TEXT,CID TEXT,Created_Date TEXT,Data_Source TEXT,External_ID TEXT)');
        foreach (['DP_LT','DP_LDR','DP'] as $source) $this->db->table('TblPhoneNumbers')->insert(['Phone'=>'2025550100','Source'=>$source,'CID'=>'old']);
    }

    protected function tearDown(): void
    {
        $this->db->disconnect(); Facade::clearResolvedInstances(); Container::setInstance($this->previous); Facade::setFacadeApplication($this->previous);
    }

    private function row(array $changes=[]): array
    {
        return array_replace(['PHONE'=>'(202) 555-0199','ID'=>'000123','CREATED_DATE'=>'2026-10-12',
            'DATA_SOURCE'=>'Synthetic source','EXTERNAL_ID'=>'000987','DATA_SOURCE_VARIANTS'=>1], $changes);
    }

    private function command(array $pages, bool $phonesOnly=true): SyncPhoneNumbers
    {
        return new class($this->db->getPdo(),$pages,$phonesOnly) extends SyncPhoneNumbers {
            public int $flags=0; public int $queries=0; public array $errors=[]; public array $sourceSql=[];
            public function __construct(private \PDO $pdo,public array $pages,private bool $phonesOnly) { parent::__construct(); }
            public function option($key=null) { return ['source'=>'lt','dry-run'=>false,'batch-size'=>1000,'phones-only'=>$this->phonesOnly][$key]??null; }
            public function info($string,$verbosity=null): void {} public function warn($string,$verbosity=null): void {}
            public function error($string,$verbosity=null): void { $this->errors[]=$string; }
            protected function initializeSqlServerConnection(): \PDO { return $this->pdo; }
            protected function flagContactedProspects(): ?int { ++$this->flags; return 0; }
            protected function createSnowflakeConnector(): DBConnector {
                return new class($this) extends DBConnector {
                    public function __construct(private object $command) {}
                    public function query(string $sql,array $bindings=[],?int $timeoutSeconds=null): array {
                        $this->command->sourceSql[]=$sql;
                        return ['data'=>$this->command->pages[$this->command->queries++]??[]];
                    }
                };
            }
        };
    }

    public function test_repeated_slots_and_page_boundary_collisions_collapse_without_merging_other_contacts(): void
    {
        $command=$this->command([array_fill(0,5000,$this->row()),[
            $this->row(['PHONE'=>'12025550199']), $this->row(['PHONE'=>'202-555-0199']),
            $this->row(['ID'=>'123']), $this->row(['PHONE'=>'2025550198']),
        ]]);
        $before=glob(sys_get_temp_dir().'/sync-phone-*');
        self::assertSame(0,$command->handle()); self::assertSame(2,$command->queries); self::assertSame(0,$command->flags);
        $own=$this->db->table('TblPhoneNumbers')->where('Source','DP_LT')->get(); self::assertCount(3,$own);
        self::assertSame(['000123','000123','123'],$own->pluck('CID')->sort()->values()->all());
        self::assertSame(2,$this->db->table('TblPhoneNumbers')->whereIn('Source',['DP','DP_LDR'])->count());
        self::assertSame([],array_values(array_diff(glob(sys_get_temp_dir().'/sync-phone-*'),$before)));
        $sql=$command->sourceSql[0]; self::assertStringContainsString('c.TP_ID::VARCHAR AS EXTERNAL_ID',$sql);
        self::assertStringContainsString("COUNT(DISTINCT TO_BINARY(NAME, 'UTF-8'))",$sql);
        self::assertStringContainsString('LEFT JOIN source_names AS ds ON c.C_SOURCE = ds.ID',$sql);
        self::assertStringContainsString("c.CREATED)::DATE",$sql); self::assertStringNotContainsString('CREATED_AT',$sql);
    }

    public static function conflicts(): array
    {
        return [['CREATED_DATE','2026-10-13'],['DATA_SOURCE','Different source'],['DATA_SOURCE',null],
            ['EXTERNAL_ID','00987'],['EXTERNAL_ID',null],['DATA_SOURCE_VARIANTS',2]];
    }

    #[DataProvider('conflicts')]
    public function test_late_provenance_conflict_aborts_before_deletion_without_private_values_in_errors(string $field,mixed $value): void
    {
        $command=$this->command([array_fill(0,5000,$this->row()),[$this->row([$field=>$value])]]);
        $before=$this->db->table('TblPhoneNumbers')->get()->toJson(); $files=glob(sys_get_temp_dir().'/sync-phone-*');
        self::assertSame(1,$command->handle()); self::assertSame(0,$command->flags);
        self::assertSame($before,$this->db->table('TblPhoneNumbers')->get()->toJson());
        self::assertSame(['SyncPhoneNumbers stopped during source_staging. Phone snapshot committed: no.'],$command->errors);
        self::assertStringNotContainsString('000987',json_encode([$command->errors,$this->logger->entries]));
        self::assertSame([],array_values(array_diff(glob(sys_get_temp_dir().'/sync-phone-*'),$files)));
    }

    public function test_nullable_blank_unicode_and_text_external_ids_round_trip_and_default_flags_remain_enabled(): void
    {
        $command=$this->command([[$this->row(['DATA_SOURCE'=>"源 O'Brien 😀",'EXTERNAL_ID'=>'000123-abc']),
            $this->row(['ID'=>'124','DATA_SOURCE'=>null,'EXTERNAL_ID'=>null]),
            $this->row(['ID'=>'125','DATA_SOURCE'=>'','EXTERNAL_ID'=>''])]],false);
        self::assertSame(0,$command->handle()); self::assertSame(1,$command->flags);
        $rows=$this->db->table('TblPhoneNumbers')->where('Source','DP_LT')->orderBy('CID')->get();
        self::assertSame("源 O'Brien 😀",$rows[0]->Data_Source); self::assertSame('000123-abc',$rows[0]->External_ID);
        self::assertNull($rows[1]->Data_Source); self::assertNull($rows[1]->External_ID);
        self::assertSame('',$rows[2]->Data_Source); self::assertSame('',$rows[2]->External_ID);
    }

    public static function invalidProvenance(): array
    {
        return [['DATA_SOURCE',str_repeat('x',51)],['DATA_SOURCE',str_repeat('😀',26)],
            ['EXTERNAL_ID',str_repeat('x',257)],['EXTERNAL_ID',123],['DATA_SOURCE',"\xFF"]];
    }

    #[DataProvider('invalidProvenance')]
    public function test_overlong_or_nontext_provenance_never_truncates_or_replaces(string $field,mixed $value): void
    {
        $before=$this->db->table('TblPhoneNumbers')->get()->toJson(); $command=$this->command([[$this->row([$field=>$value])]]);
        self::assertSame(1,$command->handle()); self::assertSame($before,$this->db->table('TblPhoneNumbers')->get()->toJson());
    }

    public function test_private_stage_preserves_company_identity_and_removes_every_owned_file(): void
    {
        $stage=new SmsPhoneSnapshotStage; $row=['phone'=>'2025550199','cid'=>'123','created'=>'2026-10-12','data_source'=>null,'external_id'=>null];
        try {
            $stage->add('DP_LT',$row); $stage->add('DP_LT',$row); $stage->add('DP_LDR',$row);
            self::assertSame(2,$stage->count()); $path=$stage->export(); $directory=dirname($path);
            self::assertCount(2,file($path,FILE_IGNORE_NEW_LINES));
            if (DIRECTORY_SEPARATOR==='/') {
                self::assertSame(0700,fileperms($directory)&0777); self::assertSame(0600,fileperms($path)&0777);
                self::assertSame(0600,fileperms($directory.'/snapshot.sqlite')&0777);
            }
        } finally { $stage->close(); }
        self::assertDirectoryDoesNotExist($directory);
    }

    public function test_additive_migration_preserves_rows_and_missing_schema_stops_before_source_read(): void
    {
        $this->db->statement('ALTER TABLE TblPhoneNumbers DROP COLUMN Data_Source');
        $this->db->statement('ALTER TABLE TblPhoneNumbers DROP COLUMN External_ID');
        $command=$this->command([[$this->row()]]); self::assertSame(1,$command->handle()); self::assertSame(0,$command->queries);
        $migration=require __DIR__.'/../../database/sms-migrations/2026_10_28_000002_add_phone_source_provenance.php';
        $migration->up(); $migration->up(); $migration->down();
        self::assertSame(3,$this->db->table('TblPhoneNumbers')->count());
        self::assertTrue($this->db->getSchemaBuilder()->hasColumn('TblPhoneNumbers','Data_Source'));
        self::assertTrue($this->db->getSchemaBuilder()->hasColumn('TblPhoneNumbers','External_ID'));
    }
}
