<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Console\Commands\SyncPhoneNumbers;
use Cmd\Reports\Services\DBConnector;
use Cmd\Reports\Services\SmsAvailabilityService;
use Cmd\Reports\Services\SmsPhoneSnapshotStage;
use Cmd\Reports\Services\SmsPhoneNumbersSql;
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
        $app->instance('config', new Repository(['sms-exports'=>['wide_phone_contacts_enabled'=>true]]));
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
        $this->db->statement('CREATE TABLE TblPhoneNumbers(PK INTEGER PRIMARY KEY AUTOINCREMENT,Phone TEXT,Source TEXT,CID TEXT,Created_Date TEXT,Data_Source TEXT,External_ID TEXT,Data_Source_ID TEXT,Data_Source_Origin TEXT,Data_Source_Map_SHA256 TEXT,Status TEXT,Status_ID TEXT,Status_Stamp TEXT,Phone1 TEXT,Phone2 TEXT,Phone3 TEXT,Phone4 TEXT)');
        foreach (['DP_LT','DP_LDR','DP'] as $source) $this->db->table('TblPhoneNumbers')->insert(['Phone'=>'2025550100','Source'=>$source,'CID'=>'old']);
    }

    protected function tearDown(): void
    {
        $this->db->disconnect(); Facade::clearResolvedInstances(); Container::setInstance($this->previous); Facade::setFacadeApplication($this->previous);
    }

    private function row(array $changes=[]): array
    {
        return array_replace(['PHONE'=>'(202) 555-0199','ID'=>'000123','CREATED_DATE'=>'2026-10-12',
            'DATA_SOURCE'=>'Synthetic source','DATA_SOURCE_ID'=>'79071','EXTERNAL_ID'=>'000987','DATA_SOURCE_VARIANTS'=>1,
            'STATUS'=>'Active','STATUS_ID'=>'1234','STATUS_STAMP'=>'2026-10-28 12:34:56.000 -0700','STATUS_VARIANTS'=>1,
            'PHONE_SLOT'=>0], $changes);
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
            $this->row(['ID'=>'123']), $this->row(['PHONE'=>'2025550198','PHONE_SLOT'=>1]),
        ]]);
        $before=glob(sys_get_temp_dir().'/sync-phone-*');
        self::assertSame(0,$command->handle()); self::assertSame(2,$command->queries); self::assertSame(0,$command->flags);
        $own=$this->db->table('TblPhoneNumbers')->where('Source','DP_LT')->orderBy('CID')->get(); self::assertCount(2,$own);
        self::assertSame(['000123','123'],$own->pluck('CID')->all());
        self::assertSame('2025550199',$own[0]->Phone1); self::assertSame('2025550198',$own[0]->Phone2);
        self::assertSame($own[0]->Phone1,$own[0]->Phone); self::assertNull($own[0]->Phone3);
        self::assertSame(2,$this->db->table('TblPhoneNumbers')->whereIn('Source',['DP','DP_LDR'])->count());
        self::assertSame([],array_values(array_diff(glob(sys_get_temp_dir().'/sync-phone-*'),$before)));
        $sql=$command->sourceSql[0]; self::assertStringContainsString('c.TP_ID::VARCHAR AS EXTERNAL_ID',$sql);
        self::assertStringContainsString("COUNT(DISTINCT TO_BINARY(NAME, 'UTF-8'))",$sql);
        self::assertStringContainsString('LEFT JOIN source_names AS ds ON c.C_SOURCE = ds.ID',$sql);
        self::assertStringContainsString('PARTITION BY CONTACT_ID ORDER BY STAMP DESC, ID DESC',$sql);
        self::assertStringContainsString('COALESCE(_FIVETRAN_DELETED, FALSE) = FALSE',$sql);
        self::assertStringContainsString('latest_status.STATUS_RANK = 1',$sql);
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

    public function test_nullable_blank_unicode_and_text_external_ids_round_trip_without_prospect_changes(): void
    {
        $command=$this->command([[$this->row(['DATA_SOURCE'=>"源 O'Brien 😀",'EXTERNAL_ID'=>'000123-abc']),
            $this->row(['ID'=>'124','DATA_SOURCE'=>null,'DATA_SOURCE_ID'=>null,'DATA_SOURCE_VARIANTS'=>0,'EXTERNAL_ID'=>null,
                'STATUS'=>null,'STATUS_ID'=>null,'STATUS_STAMP'=>null,'STATUS_VARIANTS'=>0]),
            $this->row(['ID'=>'125','DATA_SOURCE'=>'','EXTERNAL_ID'=>''])]]);
        self::assertSame(0,$command->handle()); self::assertSame(0,$command->flags);
        $rows=$this->db->table('TblPhoneNumbers')->where('Source','DP_LT')->orderBy('CID')->get();
        self::assertSame("源 O'Brien 😀",$rows[0]->Data_Source); self::assertSame('000123-abc',$rows[0]->External_ID);
        self::assertNull($rows[1]->Data_Source); self::assertNull($rows[1]->External_ID);
        self::assertSame('',$rows[2]->Data_Source); self::assertSame('',$rows[2]->External_ID);
        self::assertSame('79071',$rows[0]->Data_Source_ID); self::assertSame('current',$rows[0]->Data_Source_Origin);
        self::assertSame('Active',$rows[0]->Status); self::assertSame('1234',$rows[0]->Status_ID);
        self::assertSame('2026-10-28 12:34:56.000 -0700',$rows[0]->Status_Stamp);
    }

    public function test_default_schedule_cannot_run_the_superseded_prospect_date_writer(): void
    {
        $before=$this->db->table('TblPhoneNumbers')->get()->toJson();
        $command=$this->command([[$this->row()]],false);
        self::assertSame(1,$command->handle());
        self::assertSame(0,$command->queries); self::assertSame(0,$command->flags);
        self::assertSame($before,$this->db->table('TblPhoneNumbers')->get()->toJson());
        self::assertSame(['Use --phones-only until the reviewed response and enrollment refresh is installed. No synchronization was started.'],$command->errors);
    }

    public static function invalidProvenance(): array
    {
        return [['DATA_SOURCE',str_repeat('x',51)],['DATA_SOURCE',str_repeat('😀',26)],
            ['EXTERNAL_ID',str_repeat('x',257)],['EXTERNAL_ID',123],['DATA_SOURCE',"\xFF"],['EXTERNAL_ID',"bad\0id"]];
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
        foreach (['Data_Source_ID','Data_Source_Origin','Data_Source_Map_SHA256','Status','Status_ID','Status_Stamp'] as $column) {
            $this->db->statement("ALTER TABLE TblPhoneNumbers DROP COLUMN {$column}");
        }
        $command=$this->command([[$this->row()]]); self::assertSame(1,$command->handle()); self::assertSame(0,$command->queries);
        $migration=require __DIR__.'/../../database/sms-migrations/2026_10_28_000002_add_phone_source_provenance.php';
        $migration->up(); $migration->up(); $migration->down();
        $metadataMigration=require __DIR__.'/../../database/sms-migrations/2026_10_29_000001_add_phone_contact_metadata.php';
        $metadataMigration->up(); $metadataMigration->up(); $metadataMigration->down();
        self::assertSame(3,$this->db->table('TblPhoneNumbers')->count());
        self::assertTrue($this->db->getSchemaBuilder()->hasColumn('TblPhoneNumbers','Data_Source'));
        self::assertTrue($this->db->getSchemaBuilder()->hasColumn('TblPhoneNumbers','External_ID'));
        foreach (['Data_Source_ID','Data_Source_Origin','Data_Source_Map_SHA256','Status','Status_ID','Status_Stamp'] as $column) {
            self::assertTrue($this->db->getSchemaBuilder()->hasColumn('TblPhoneNumbers',$column));
        }
    }

    public function test_lt_legacy_source_fallback_is_auditable_and_status_title_can_be_unknown(): void
    {
        $command=$this->command([[$this->row(['DATA_SOURCE'=>null,'DATA_SOURCE_ID'=>'79071','DATA_SOURCE_VARIANTS'=>0,
            'STATUS'=>null,'STATUS_ID'=>'999999','STATUS_STAMP'=>'2026-10-28 09:00:00.000 -0700','STATUS_VARIANTS'=>0])]]);
        self::assertSame(0,$command->handle());
        $row=$this->db->table('TblPhoneNumbers')->where('Source','DP_LT')->sole();
        self::assertSame('DPP - Mass Post Source',$row->Data_Source);
        self::assertSame('79071',$row->Data_Source_ID);
        self::assertSame('legacy',$row->Data_Source_Origin);
        self::assertSame(\Cmd\Reports\Services\SmsLegacyDataSourceMap::SOURCE_SHA256,$row->Data_Source_Map_SHA256);
        self::assertNull($row->Status); self::assertSame('999999',$row->Status_ID);
        self::assertSame('2026-10-28 09:00:00.000 -0700',$row->Status_Stamp);
    }

    public function test_four_unique_phones_keep_source_slot_order_and_fifth_aborts_without_target_changes(): void
    {
        $phones=[]; foreach([3,1,2,0] as $slot) $phones[]=$this->row(['PHONE'=>'202555010'.$slot,'PHONE_SLOT'=>$slot]);
        $command=$this->command([$phones]); self::assertSame(0,$command->handle());
        $row=$this->db->table('TblPhoneNumbers')->where('Source','DP_LT')->sole();
        self::assertSame(['2025550100','2025550101','2025550102','2025550103'],[$row->Phone1,$row->Phone2,$row->Phone3,$row->Phone4]);
        self::assertSame($row->Phone1,$row->Phone);
        $before=$this->db->table('TblPhoneNumbers')->get()->toJson();
        $phones[]=$this->row(['PHONE'=>'2025550104','PHONE_SLOT'=>3]);
        $command=$this->command([$phones]); self::assertSame(1,$command->handle());
        self::assertSame($before,$this->db->table('TblPhoneNumbers')->get()->toJson());
        self::assertSame(0,$command->flags);
    }

    public function test_conflicting_metadata_across_different_phones_on_same_contact_is_not_a_second_row(): void
    {
        $before=$this->db->table('TblPhoneNumbers')->get()->toJson();
        $command=$this->command([[$this->row(),$this->row(['PHONE'=>'2025550198','PHONE_SLOT'=>1,'EXTERNAL_ID'=>'different'])]]);
        self::assertSame(1,$command->handle()); self::assertSame($before,$this->db->table('TblPhoneNumbers')->get()->toJson());
    }

    public function test_gate_off_refuses_source_read_and_publication_and_missing_slot_is_not_invented(): void
    {
        config(['sms-exports.wide_phone_contacts_enabled'=>false]); $command=$this->command([[$this->row()]]);
        self::assertSame(1,$command->handle()); self::assertSame(0,$command->queries); self::assertSame(0,$command->flags);
        config(['sms-exports.wide_phone_contacts_enabled'=>true]);
        foreach([null,-1,4,'1.0'] as $slot) { $command=$this->command([[$this->row(['PHONE_SLOT'=>$slot])]]); self::assertSame(1,$command->handle()); }
    }

    public function test_wide_projection_keeps_all_phones_and_metadata_without_repeating_compatibility_phone(): void
    {
        self::assertSame('TblPhoneNumbers AS c',SmsPhoneNumbersSql::from('c'));
        $this->db->table('TblPhoneNumbers')->delete();
        $this->db->table('TblPhoneNumbers')->insert(['Phone'=>'2025550101','Phone1'=>'2025550101','Phone2'=>'2025550102',
            'Phone3'=>'2025550103','Phone4'=>'2025550104','Source'=>'DP_LT','CID'=>'000123','Data_Source'=>'Synthetic','External_ID'=>'0009']);
        $this->db->table('TblPhoneNumbers')->insert(['Phone'=>'2025550101','Source'=>'DP_LDR','CID'=>'000123']);
        $rows=$this->db->select('SELECT * FROM '.SmsPhoneNumbersSql::from('p','',true));
        self::assertCount(5,$rows); self::assertSame(4,count(array_filter($rows,fn($r)=>$r->Source==='DP_LT')));
        self::assertSame(1,count(array_unique(array_map(fn($r)=>$r->PK,array_filter($rows,fn($r)=>$r->Source==='DP_LT')))));
        $hinted=SmsPhoneNumbersSql::from('p',' WITH(UPDLOCK,HOLDLOCK)',true);
        self::assertSame(5,substr_count($hinted,'TblPhoneNumbers WITH (UPDLOCK, HOLDLOCK)'));
        self::assertStringEndsWith(') AS p',$hinted);
        foreach([['p;DROP',''],['p',' WITH(NOLOCK)']] as [$alias,$hint]) {
            try { SmsPhoneNumbersSql::from($alias,$hint,true); self::fail('Expected unsafe SQL rejection.'); }
            catch(\InvalidArgumentException) {}
        }
    }
}
