<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Repositories\SmsRecordExportRepository;
use Cmd\Reports\Services\SmsSelectionCancellation;
use Cmd\Reports\Services\SmsSelectionCancelledException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PDO;

require_once __DIR__.'/SmsSelectionCancellationTest.php';

/** Two actual local engines. Always new random databases, never the shared local clone or production. */
final class SmsSelectionCancellationSqlServerTest extends SmsSelectionCancellationTest
{
    private static string $sourceDatabase;
    private static string $controlDatabase;
    private static ?PDO $sourceAdmin=null;
    private static ?PDO $controlAdmin=null;

    public static function setUpBeforeClass():void
    {
        self::$sourceDatabase='sms_cancel_source_'.bin2hex(random_bytes(8));
        self::$controlDatabase='sms_cancel_control_'.bin2hex(random_bytes(8));
        self::$sourceAdmin=new PDO('sqlsrv:Server=127.0.0.1,14330;Database=master;TrustServerCertificate=1',
            'sa','LocalDev!2026Pass',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        self::$controlAdmin=new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4','root','root',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        self::$sourceAdmin->exec('CREATE DATABASE ['.self::$sourceDatabase.'] COLLATE SQL_Latin1_General_CP1_CI_AS');
        self::$sourceAdmin->exec('ALTER DATABASE ['.self::$sourceDatabase.'] SET ALLOW_SNAPSHOT_ISOLATION ON');
        self::$controlAdmin->exec('CREATE DATABASE `'.self::$controlDatabase.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    }

    public static function tearDownAfterClass():void
    {
        if(self::$sourceAdmin!==null && preg_match('/\Asms_cancel_source_[a-f0-9]{16}\z/D',self::$sourceDatabase)) {
            self::$sourceAdmin->exec('ALTER DATABASE ['.self::$sourceDatabase.'] SET SINGLE_USER WITH ROLLBACK IMMEDIATE');
            self::$sourceAdmin->exec('DROP DATABASE ['.self::$sourceDatabase.']');self::$sourceAdmin=null;
        }
        if(self::$controlAdmin!==null && preg_match('/\Asms_cancel_control_[a-f0-9]{16}\z/D',self::$controlDatabase)) {
            self::$controlAdmin->exec('DROP DATABASE `'.self::$controlDatabase.'`');self::$controlAdmin=null;
        }
    }

    protected function sourceConfig():array
    {
        return ['driver'=>'sqlsrv','host'=>'127.0.0.1,14330','database'=>self::$sourceDatabase,
            'username'=>'sa','password'=>'LocalDev!2026Pass','encrypt'=>'no','trust_server_certificate'=>'yes'];
    }

    protected function controlConfig():array
    {
        return ['driver'=>'mysql','host'=>'127.0.0.1','port'=>3306,'database'=>self::$controlDatabase,
            'username'=>'root','password'=>'root','charset'=>'utf8mb4','collation'=>'utf8mb4_unicode_ci'];
    }

    public function testNativeMysqlFenceContentionCannotAcceptOrLoseARequest():void
    {
        $run=$this->makeRun();$this->cancellation->begin($run);
        $other=new PDO('mysql:host=127.0.0.1;port=3306;dbname='.self::$controlDatabase.';charset=utf8mb4',
            'root','root',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $other->beginTransaction();$statement=$other->prepare('SELECT request_id FROM sms_selection_cancellations WHERE request_id=? FOR UPDATE');
        $statement->execute([self::ID]);$statement->fetchAll();$statement->closeCursor();
        $this->control->statement('SET SESSION innodb_lock_wait_timeout=1');
        try {
            try{$this->cancellation->requestCancel(self::ID,'owner@example.invalid');self::fail('Locked fence must not accept');}
            catch(\Symfony\Component\HttpKernel\Exception\HttpException $error){
                self::assertSame(503,$error->getStatusCode());self::assertInstanceOf(QueryException::class,$error->getPrevious());
                self::assertSame(1205,(int)($error->getPrevious()->errorInfo[1]??0));
            }
            self::assertSame(0,$this->control->transactionLevel());self::assertSame(0,$this->control->table('jobs')->count());
        } finally {$other->rollBack();$this->control->statement('SET SESSION innodb_lock_wait_timeout=50');}
        self::assertSame('active',$this->control->table(SmsSelectionCancellation::TABLE)->value('state'));
        $this->cancellation->requestCancel(self::ID,'owner@example.invalid');
        self::assertSame(1,$this->control->table('jobs')->count());
    }

    public function testNativeWindowCheckpointSeesCancelDespiteTheOpenSourceSnapshot():void
    {
        $this->windowSchema();$this->makeRun('selecting');
        for($i=1;$i<=2;$i++) {
            $this->source->table('TblProspects')->insert(['Client'=>'Synthetic Person','Address'=>'Window '.$i,'Zip'=>'90210',
                'Phone'=>'202555010'.$i,'Creation_Date'=>'2026-10-20','Contacted'=>0,'Opt_Out'=>0,'Invalid_Phone'=>0]);
            $this->source->table('TblTUData')->insert(['PK'=>$i,'Creation_Date'=>'2026-10-21','ncoaAddr_frstNme'=>'Synthetic','ncoaAddr_lastNme'=>'Person',
                'ncoaAddr_addrLine1Txt'=>'Window '.$i,'ncoaAddr_zipCde'=>'90210','derivedFields1_unsecdebtbalance'=>13679,'Tier_Tag'=>'T1']);
        }
        config(['sms-exports.tier_selection_window_size'=>1]);
        $cancellation=new class extends SmsSelectionCancellation {
            public int $checkpoints=0;
            public function checkpoint(string $id):void {
                if(++$this->checkpoints===3) {
                    self::assertSourceSnapshotForFixture();
                    $this->requestCancel($id,'owner@example.invalid');
                }
                parent::checkpoint($id);
            }
            private static function assertSourceSnapshotForFixture():void {
                if(!DB::connection('sqlsrv')->getPdo()->inTransaction())throw new \RuntimeException('Native fixture did not reach selection snapshot');
            }
        };
        $this->container->instance(SmsSelectionCancellation::class,$cancellation);
        try{(new SmsRecordExportRepository)->select(self::ID,2);self::fail('Expected the first-window cooperative stop');}
        catch(SmsSelectionCancelledException){}
        self::assertSame(3,$cancellation->checkpoints);
        self::assertSame(0,$this->source->table('TblProspectsExport')->count());
        self::assertNull($this->source->table('TblSMSRuns')->value('Selected'));
        self::assertFalse($this->source->getPdo()->inTransaction());self::assertSame(0,$this->source->transactionLevel());
        self::assertSame('requested',$this->control->table(SmsSelectionCancellation::TABLE)->value('state'));
        self::assertSame(1,$this->control->table('jobs')->count());
        $this->runs->completeSelectionCancellation(self::ID);
        self::assertSame('discarded',$this->source->table('TblSMSRuns')->value('Status'));
        self::assertSame('cancelled',$this->control->table(SmsSelectionCancellation::TABLE)->value('state'));
        self::assertSame(0,$this->source->table('TblSMSHistory')->count());self::assertSame(0,$this->source->table('TblSMSDrops')->count());
        self::assertSame(0,(int)$this->source->table('TblProspects')->sum('SMS_Attempts'));
    }

    public function testReadCommittedPollReadsUpdlockButWaitsOnUncommittedCompletionWhenRcsiIsOff():void
    {
        $this->makeRun('selecting');
        self::assertSame(0,(int)$this->source->selectOne('SELECT is_read_committed_snapshot_on AS value FROM sys.databases WHERE database_id=DB_ID()')->value);
        $worker=$this->source->getPdo();$worker->exec('SET TRANSACTION ISOLATION LEVEL SNAPSHOT');$worker->beginTransaction();
        $statement=$worker->prepare('SELECT Request_ID FROM dbo.TblSMSRuns WITH (UPDLOCK,ROWLOCK) WHERE Request_ID=?');
        $statement->execute([self::ID]);$statement->fetchAll();$statement->closeCursor();
        $poll=new PDO('sqlsrv:Server=127.0.0.1,14330;Database='.self::$sourceDatabase.';TrustServerCertificate=1',
            'sa','LocalDev!2026Pass',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $poll->exec('SET LOCK_TIMEOUT 200;SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        try {
            $before=$poll->prepare('SELECT Status FROM dbo.TblSMSRuns WHERE Request_ID=?');$before->execute([self::ID]);
            self::assertSame('selecting',$before->fetchColumn());$before->closeCursor();
            $completion=$worker->prepare('UPDATE dbo.TblSMSRuns SET Selected=1 WHERE Request_ID=?');
            $completion->execute([self::ID]);$completion->closeCursor();
            try {
                $query=$poll->prepare('SELECT Status FROM dbo.TblSMSRuns WHERE Request_ID=?');$query->execute([self::ID]);$query->fetchAll();
                self::fail('Plain polling should not be mistaken for the dedicated MVCC cancellation read');
            } catch(\PDOException $error) {self::assertSame(1222,(int)($error->errorInfo[1]??0));}
        } finally {$worker->rollBack();$worker->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');}
    }

    private function windowSchema():void
    {
        if($this->source->getSchemaBuilder()->hasTable('TblTUData')) {
            foreach(['TblTUData','TblMailers','TblMarketing'] as $table)$this->source->table($table)->delete();
            return;
        }
        $this->source->unprepared('CREATE TABLE dbo.TblTUData (PK int PRIMARY KEY, Creation_Date date NULL, ncoaAddr_frstNme varchar(100), ncoaAddr_lastNme varchar(100), ncoaAddr_addrLine1Txt nvarchar(255), ncoaAddr_zipCde nvarchar(10), SMS_Zip5 AS LEFT(ncoaAddr_zipCde,5), derivedFields1_unsecdebtbalance decimal(18,2), Tier_Tag varchar(30));
            CREATE TABLE dbo.TblMailers (PK int PRIMARY KEY, Client nvarchar(50), Address nvarchar(50), Zip nvarchar(50), SMS_Zip5 AS LEFT(Zip,5), Drop_Name nvarchar(50), Debt_Amount decimal(10,2), Debt_Tier nvarchar(5));
            CREATE TABLE dbo.TblMarketing (Drop_Name nvarchar(50), Send_Date date NULL)');
        foreach([
            'CREATE INDEX IX_TblProspects_SMS_Never_Priority ON dbo.TblProspects(Creation_Date DESC,PK ASC) INCLUDE (Phone,Client,Address,City,State,Zip,Email,Last_SMS_Date) WHERE Contacted=0 AND Opt_Out=0 AND Invalid_Phone=0 AND Phone IS NOT NULL AND Last_SMS_Date IS NULL',
            'CREATE INDEX IX_TblProspects_SMS_Previous_Priority ON dbo.TblProspects(Last_SMS_Date ASC,PK ASC) INCLUDE (Phone,Client,Address,City,State,Zip,Email,Creation_Date) WHERE Contacted=0 AND Opt_Out=0 AND Invalid_Phone=0 AND Phone IS NOT NULL AND Last_SMS_Date IS NOT NULL',
            'CREATE INDEX IX_TblTUData_SMS_Identity ON dbo.TblTUData(ncoaAddr_addrLine1Txt,SMS_Zip5) INCLUDE (PK,Creation_Date,ncoaAddr_frstNme,ncoaAddr_lastNme,derivedFields1_unsecdebtbalance,Tier_Tag)',
            'CREATE INDEX IX_TblMailers_SMS_Identity ON dbo.TblMailers(Client,Address,SMS_Zip5) INCLUDE (PK,Drop_Name,Debt_Amount,Debt_Tier)',
            'CREATE INDEX IX_TblMarketing_SMS_Drop_SendDate ON dbo.TblMarketing(Drop_Name,Send_Date)',
        ] as $ddl)$this->source->unprepared($ddl);
    }
}
