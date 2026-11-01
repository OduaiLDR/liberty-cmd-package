<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Carbon\Carbon;
use Cmd\Reports\Jobs\CancelSmsSelectionJob;
use Cmd\Reports\Repositories\SmsProspectExportRepository;
use Cmd\Reports\Services\SmsRecordPolicy;
use Cmd\Reports\Services\SmsRunOptions;
use Cmd\Reports\Services\SmsRunService;
use Cmd\Reports\Services\SmsSelectionCancellation;
use Cmd\Reports\Services\SmsSelectionCancelledException;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Both sources are new disposable files; no application .env is loaded. */
class SmsSelectionCancellationTest extends TestCase
{
    protected const ID = '12345678-1234-4234-8234-123456789abc';
    protected const OTHER_ID = '87654321-4321-4321-8321-cba987654321';
    protected Container $previous;
    protected $source;
    protected $control;
    protected Container $container;
    protected string $directory;
    protected SmsSelectionCancellation $cancellation;
    protected SmsRunService $runs;
    protected $export;

    protected function sourceConfig(): array { return ['driver'=>'sqlite','database'=>$this->directory.'/source.sqlite']; }
    protected function controlConfig(): array { return ['driver'=>'sqlite','database'=>$this->directory.'/control.sqlite']; }

    protected function setUp(): void
    {
        $this->previous=Container::getInstance();
        $this->container=new class extends Container {
            public function environment(...$values): string|bool { return $values===[]?'testing':in_array('testing',$values,true); }
        };
        Container::setInstance($this->container);
        $this->directory=str_replace('\\','/',sys_get_temp_dir()).'/sms-selection-cancellation-test-'.bin2hex(random_bytes(8));
        mkdir($this->directory,0700); touch($this->directory.'/source.sqlite'); touch($this->directory.'/control.sqlite');
        $this->container->instance('config',new Repository([
            'app'=>['env'=>'testing'],
            'database'=>['default'=>'control','connections'=>['sqlsrv'=>$this->sourceConfig(),'control'=>$this->controlConfig()]],
            'queue'=>['default'=>'database','connections'=>['database'=>[
                'driver'=>'database','connection'=>'control','table'=>'jobs','queue'=>'tu-export','retry_after'=>7201,'after_commit'=>true,
            ]]],
            'sms-exports'=>['record_workflow_enabled'=>true,'durable_availability_enabled'=>true,
                'selection_cancellation_enabled'=>true,'selection_cancellation_connection'=>'control','queue'=>'tu-export',
                'local_root'=>$this->directory],
        ]));
        $capsule=new Manager($this->container);
        $capsule->addConnection($this->sourceConfig(),'sqlsrv'); $capsule->addConnection($this->controlConfig(),'control');
        $capsule->getDatabaseManager()->setDefaultConnection('control');
        $this->container->instance('db',$capsule->getDatabaseManager());
        Facade::clearResolvedInstances(); Facade::setFacadeApplication($this->container);
        $this->source=$capsule->getConnection('sqlsrv'); $this->control=$capsule->getConnection('control');
        $this->source->setTransactionManager(new DatabaseTransactionsManager); $this->control->setTransactionManager(new DatabaseTransactionsManager);
        $this->createSchema();
        $queue=new DatabaseQueue($this->control,'jobs','tu-export',7201,true); $queue->setContainer($this->container);
        $this->container->instance('queue',new class($queue) {
            public function __construct(private DatabaseQueue $queue) {}
            public function connection($name): DatabaseQueue { if($name!=='database')throw new RuntimeException('Wrong fixture queue'); return $this->queue; }
        });
        $this->cancellation=new SmsSelectionCancellation;
        $this->container->instance(SmsSelectionCancellation::class,$this->cancellation);
        $this->export=new class extends SmsProspectExportRepository {
            public $duringSelection=null;
            public function select(string $requestId,int $target):array {
                return DB::connection('sqlsrv')->transaction(function()use($requestId,$target):array {
                    for($i=1;$i<=$target;$i++)DB::connection('sqlsrv')->table('TblProspectsExport')->insert([
                        'Request_ID'=>$requestId,'Prospect_PK'=>$i,'Phone'=>'202555010'.$i,'Client'=>'Synthetic',
                        'Debt_Tier'=>'T1','Debt_Amount'=>13000,'Debt_Source'=>'TblTUData','Debt_Source_PK'=>$i,
                        'Was_Previously_Texted'=>0,
                    ]);
                    DB::connection('sqlsrv')->table('TblSMSRuns')->where('Request_ID',$requestId)->update(['Selected'=>$target,'Never_Texted'=>$target,'Previously_Texted'=>0]);
                    if($this->duringSelection!==null)($this->duringSelection)();
                    return ['selected'=>$target,'target'=>$target,'shortfall'=>0,'contacted_flagged'=>0];
                });
            }
        };
        $this->runs=new class($this->export) extends SmsRunService {
            protected function dispatch(string $id,string $step):void {}
        };
        $this->container->instance(SmsRunService::class,$this->runs);
        Carbon::setTestNow('2026-10-25T18:00:00Z');
    }

    protected function createSchema(): void
    {
        if(!$this->source->getSchemaBuilder()->hasTable('TblSMSRuns')) {
            $this->source->getSchemaBuilder()->create('TblPhoneNumbers',fn(Blueprint $t)=>$t->string('Phone')->nullable()->index());
            foreach(['2026_10_12_000001_create_sms_prospects_tables.php','2026_10_19_000001_complete_sms_workflow.php',
                '2026_10_20_000001_create_sms_availability_summary.php','2026_10_20_000003_add_sms_record_approval.php',
                '2026_10_22_000001_add_sms_run_file_options.php'] as $file) {
                (require dirname(__DIR__,2).'/database/sms-migrations/'.$file)->up();
            }
        }
        if(!$this->control->getSchemaBuilder()->hasTable('jobs'))$this->control->getSchemaBuilder()->create('jobs',function(Blueprint $t):void {
            $t->bigIncrements('id');$t->string('queue')->index();$t->longText('payload');$t->unsignedTinyInteger('attempts');
            $t->unsignedInteger('reserved_at')->nullable();$t->unsignedInteger('available_at');$t->unsignedInteger('created_at');
        });
        if(!$this->control->getSchemaBuilder()->hasTable(SmsSelectionCancellation::TABLE)) {
            (require dirname(__DIR__,2).'/database/selection-cancellation-migrations/2026_10_25_000001_create_sms_selection_cancellations.php')->up();
        }
        foreach(['TblSMSResponseAttribution','TblSMSHistory','TblProspectsExport','TblSMSDrops','TblSMSRuns','TblProspects','TblSMSAvailabilitySummary'] as $table) {
            $this->source->table($table)->delete();
        }
        $this->control->table('jobs')->delete();$this->control->table(SmsSelectionCancellation::TABLE)->delete();
    }

    protected function tearDown():void
    {
        foreach(['sqlsrv_selection_cancellation_read','sqlsrv','control'] as $name)DB::purge($name);
        Carbon::setTestNow(); Facade::clearResolvedInstances();
        Container::setInstance($this->previous);Facade::setFacadeApplication($this->previous);
        $root=realpath($this->directory);$temp=realpath(sys_get_temp_dir());
        if($root!==false && $temp!==false && !is_link($this->directory)
            && str_starts_with(str_replace('\\','/',$root),str_replace('\\','/',$temp).'/sms-selection-cancellation-test-')) {
            foreach(new \FilesystemIterator($root) as $file)if($file->isFile()&&!$file->isLink())unlink($file->getPathname());
            rmdir($root);
        }
    }

    protected function makeRun(string $status='queued',array $extra=[],string $id=self::ID,
        string $policy=SmsRunOptions::WINDOWED_TIER_SELECTION_POLICY):object
    {
        $options=SmsRunOptions::normalize(2,2,['T1'],$policy,
            SmsRunOptions::PLANNED_DATE_VERSION,'2026-10-26',SmsRunOptions::APPROVAL_FILENAME_VERSION);
        $this->source->table('TblSMSRuns')->insert($extra+[
            'Request_ID'=>$id,'Status'=>$status,'Target'=>2,'Selected'=>null,'Selection_Policy'=>SmsRecordPolicy::RECORDS,
            'Run_Options_JSON'=>json_encode($options,JSON_THROW_ON_ERROR),'Created_By'=>'owner@example.invalid',
            'Created_At'=>now(),'Updated_At'=>now(),
        ]);
        return $this->source->table('TblSMSRuns')->where('Request_ID',$id)->first();
    }

    private function assertRejected(int $expected,callable $action):void
    {
        try{$action();self::fail('Expected rejection');}
        catch(HttpException $error){self::assertSame($expected,$error->getStatusCode());}
    }

    public function testAcceptedRequestAndRecoveryJobAreAtomicAndIdempotent():void
    {
        $this->makeRun();
        $first=$this->cancellation->requestCancel(self::ID,'owner@example.invalid');
        $second=$this->cancellation->requestCancel(self::ID,'OWNER@example.invalid');
        self::assertSame('requested',$first->cancellation['state']);self::assertEquals($first,$second);
        self::assertSame(1,$this->control->table('jobs')->count());
        self::assertSame('requested',$this->control->table(SmsSelectionCancellation::TABLE)->value('state'));
        self::assertSame('queued',$this->source->table('TblSMSRuns')->value('Status'));
        $this->expectException(SmsSelectionCancelledException::class);$this->cancellation->checkpoint(self::ID);
    }

    public function testLeanV3UsesTheSameExplicitWindowedCancellationContract():void
    {
        $run=$this->makeRun('queued',[],self::ID,SmsRunOptions::LEAN_WINDOWED_TIER_SELECTION_POLICY);
        self::assertTrue($this->cancellation->begin($run));
        $view=$this->cancellation->requestCancel(self::ID,'owner@example.invalid');
        self::assertSame('requested',$view->cancellation['state']);
        self::assertSame(1,$this->control->table('jobs')->count());
        $this->expectException(SmsSelectionCancelledException::class);$this->cancellation->checkpoint(self::ID);
    }

    public function testQueueInsertFailureRollsBackReceiptAndJobTogether():void
    {
        $this->makeRun();
        $bad=new class extends SmsSelectionCancellation {
            protected function enqueue(string $id):void { parent::enqueue($id);throw new RuntimeException('Synthetic post-enqueue failure'); }
        };
        try{$bad->requestCancel(self::ID,'owner@example.invalid');self::fail('Expected queue failure');}catch(RuntimeException){}
        self::assertSame(0,$this->control->table('jobs')->count());
        self::assertSame(0,$this->control->table(SmsSelectionCancellation::TABLE)->count());
        self::assertSame('queued',$this->source->table('TblSMSRuns')->value('Status'));
    }

    public function testDisabledCreationGateAdvertisesNothingAndDoesNotAcceptANewRequest():void
    {
        $run=$this->makeRun();config(['sms-exports.selection_cancellation_enabled'=>false]);
        self::assertFalse($this->cancellation->availableForNewRequests());
        self::assertTrue($this->cancellation->begin($run));
        $this->assertRejected(503,fn()=>$this->cancellation->requestCancel(self::ID,'owner@example.invalid'));
        self::assertSame(0,$this->control->table('jobs')->count());self::assertSame('active',$this->control->table(SmsSelectionCancellation::TABLE)->value('state'));
        $this->cancellation->checkpoint(self::ID);
        $published=false;$this->cancellation->finishSelection($run,function()use(&$published):void {$published=true;});
        self::assertTrue($published);self::assertSame('finished',$this->control->table(SmsSelectionCancellation::TABLE)->value('state'));
    }

    public function testGateOffWithoutInstalledControlSchemaRetainsTheLegacyWorkerFallback():void
    {
        $run=$this->makeRun();config(['sms-exports.selection_cancellation_enabled'=>false]);
        $this->control->getSchemaBuilder()->drop(SmsSelectionCancellation::TABLE);
        self::assertFalse($this->cancellation->begin($run));
        self::assertSame(0,$this->control->table('jobs')->count());
        $this->source->table('TblSMSRuns')->where('Request_ID',self::ID)->update(['Status'=>'failed','Failed_Step'=>'select']);
        self::assertSame('discarded',$this->runs->discard(self::ID)->status);
    }

    public function testUnknownControlCommitOutcomeRetriesTheSameAcceptedReceiptWithoutAnotherJob():void
    {
        $this->makeRun();
        $events=new \Illuminate\Events\Dispatcher($this->container);$once=true;
        $events->listen(\Illuminate\Database\Events\TransactionCommitted::class,function($event)use(&$once):void {
            if($event->connection===$this->control && $once) {$once=false;throw new RuntimeException('Synthetic lost post-commit acknowledgement');}
        });
        $this->control->setEventDispatcher($events);
        try{$this->cancellation->requestCancel(self::ID,'owner@example.invalid');self::fail('Expected lost acknowledgement');}
        catch(RuntimeException){}
        $this->control->unsetEventDispatcher();
        self::assertSame('requested',$this->control->table(SmsSelectionCancellation::TABLE)->value('state'));
        self::assertSame(1,$this->control->table('jobs')->count());
        self::assertSame('requested',$this->cancellation->requestCancel(strtoupper(self::ID),'owner@example.invalid')->cancellation['state']);
        self::assertSame(1,$this->control->table('jobs')->count());
    }

    public function testOwnerPolicyAndTerminalBoundariesRejectWithoutControlWrites():void
    {
        $this->makeRun();
        $this->assertRejected(403,fn()=>$this->cancellation->requestCancel(self::ID,'other@example.invalid'));
        foreach([
            ['Status'=>'review'],['Status'=>'approved','Approved_By'=>'owner@example.invalid'],
            ['Status'=>'sent','Sent_By'=>'owner@example.invalid'],
            ['Status'=>'external_sent','Selection_Policy'=>SmsRecordPolicy::EXTERNAL],
            ['Status'=>'queued','Selection_Policy'=>SmsRecordPolicy::LEGACY],
        ] as $change) {
            $this->source->table('TblSMSRuns')->where('Request_ID',self::ID)->update($change);
            $this->assertRejected(409,fn()=>$this->cancellation->requestCancel(self::ID,'owner@example.invalid'));
        }
        self::assertSame(0,$this->control->table('jobs')->count());self::assertSame(0,$this->control->table(SmsSelectionCancellation::TABLE)->count());
    }

    public function testQueuedCancellationCompletesWithoutSelectionAndSurvivesGateOff():void
    {
        $this->makeRun();$this->cancellation->requestCancel(self::ID,'owner@example.invalid');
        config(['sms-exports.selection_cancellation_enabled'=>false]);
        $this->export->duringSelection=fn()=>self::fail('Cancelled queued work must not select');
        $this->runs->prepare(self::ID);
        $run=$this->source->table('TblSMSRuns')->first();
        self::assertSame('discarded',$run->Status);self::assertSame(SmsSelectionCancellation::CANCELLED_STEP,$run->Progress_Step);
        self::assertSame('owner@example.invalid',$run->Closed_By);
        self::assertSame(0,$this->source->table('TblProspectsExport')->count());
        self::assertSame(0,$this->source->table('TblSMSHistory')->count());self::assertSame(0,$this->source->table('TblSMSDrops')->count());
        self::assertSame('cancelled',$this->control->table(SmsSelectionCancellation::TABLE)->value('state'));
        self::assertSame('cancelled',$this->cancellation->requestCancel(self::ID,'owner@example.invalid')->cancellation['state']);
        self::assertTrue($this->runs->history()['items'][0]->selection_cancelled);
    }

    public function testInFlightCancellationUnwindsSelectionBeforeCleanupAndPreservesOtherRun():void
    {
        $this->makeRun();$this->makeRun('review',[],self::OTHER_ID);
        $this->source->table('TblProspectsExport')->insert(['Request_ID'=>self::OTHER_ID,'Prospect_PK'=>77,'Phone'=>'2025550177']);
        $this->source->table('TblProspects')->insert(['Client'=>'Synthetic','Phone'=>'2025550101','SMS_Attempts'=>4]);
        $this->export->duringSelection=function():void {
            self::assertSame(3,$this->source->table('TblProspectsExport')->count());
            $this->cancellation->requestCancel(self::ID,'owner@example.invalid');
            $this->cancellation->checkpoint(self::ID);
        };
        $this->runs->prepare(self::ID);
        self::assertSame(1,$this->source->table('TblProspectsExport')->count());
        self::assertSame(self::OTHER_ID,strtolower($this->source->table('TblProspectsExport')->value('Request_ID')));
        self::assertSame(4,(int)$this->source->table('TblProspects')->value('SMS_Attempts'));
        self::assertSame('review',$this->source->table('TblSMSRuns')->where('Request_ID',self::OTHER_ID)->value('Status'));
        self::assertSame(0,$this->source->transactionLevel());
        self::assertFalse($this->source->getPdo()->inTransaction());
    }

    public function testReviewPublicationIsFencedAndLateCancelDoesNotDeleteMembership():void
    {
        $this->makeRun();$this->runs->prepare(self::ID);
        self::assertSame('review',$this->source->table('TblSMSRuns')->value('Status'));
        self::assertSame('finished',$this->control->table(SmsSelectionCancellation::TABLE)->value('state'));
        $this->assertRejected(409,fn()=>$this->cancellation->requestCancel(self::ID,'owner@example.invalid'));
        self::assertSame(2,$this->source->table('TblProspectsExport')->count());
        self::assertSame(0,$this->control->table('jobs')->count());
        self::assertSame(0,$this->source->table('TblSMSHistory')->count());self::assertSame(0,$this->source->table('TblSMSDrops')->count());
    }

    public function testFreshUnderFenceReadRejectsAStalePreFenceSelection():void
    {
        $this->makeRun();
        $service=new class extends SmsSelectionCancellation {
            private int $reads=0;
            protected function readRunSnapshot(string $id):object {
                $run=parent::readRunSnapshot($id);
                if(++$this->reads===1)DB::connection('sqlsrv')->table('TblSMSRuns')->where('Request_ID',$id)->update(['Status'=>'review']);
                return $run;
            }
        };
        $this->assertRejected(409,fn()=>$service->requestCancel(self::ID,'owner@example.invalid'));
        self::assertSame(0,$this->control->table('jobs')->count());
        self::assertSame('review',$this->source->table('TblSMSRuns')->value('Status'));
    }

    public function testCommittedReviewWithLostControlCommitStillRejectsCancellation():void
    {
        $run=$this->makeRun('selecting');$this->cancellation->begin($run);
        try {
            $this->cancellation->finishSelection($run,function():void {
                $this->source->table('TblSMSRuns')->where('Request_ID',self::ID)->update(['Status'=>'review']);
                throw new RuntimeException('Synthetic process loss after SQL review commit');
            });
            self::fail('Expected synthetic process loss');
        } catch(RuntimeException){}
        self::assertSame('active',$this->control->table(SmsSelectionCancellation::TABLE)->value('state'));
        $this->assertRejected(409,fn()=>$this->cancellation->requestCancel(self::ID,'owner@example.invalid'));
        self::assertSame('review',$this->source->table('TblSMSRuns')->value('Status'));self::assertSame(0,$this->control->table('jobs')->count());
    }

    public function testRequestedCancellationWinsTheFinalReviewFence():void
    {
        $run=$this->makeRun('selecting');$this->cancellation->requestCancel(self::ID,'owner@example.invalid');
        try{$this->cancellation->finishSelection($run,fn()=>self::fail('Cancellation must prevent review'));self::fail('Expected cooperative stop');}
        catch(SmsSelectionCancelledException){}
        self::assertSame('selecting',$this->source->table('TblSMSRuns')->value('Status'));
        $this->runs->completeSelectionCancellation(self::ID);
        self::assertSame('discarded',$this->source->table('TblSMSRuns')->value('Status'));
    }

    public function testTerminalSqlReceiptRecoversAfterLostMysqlAcknowledgement():void
    {
        $this->makeRun();$this->cancellation->requestCancel(self::ID,'owner@example.invalid');
        $bad=new class extends SmsSelectionCancellation { protected function acknowledge(string $id):void {throw new RuntimeException('Synthetic acknowledgement loss');} };
        try{$bad->complete(self::ID);self::fail('Expected acknowledgement loss');}catch(RuntimeException){}
        self::assertSame('discarded',$this->source->table('TblSMSRuns')->value('Status'));
        self::assertSame('requested',$this->control->table(SmsSelectionCancellation::TABLE)->value('state'));
        $this->cancellation->complete(self::ID);$this->cancellation->complete(self::ID);
        self::assertSame('cancelled',$this->control->table(SmsSelectionCancellation::TABLE)->value('state'));
        self::assertSame(0,$this->source->table('TblSMSHistory')->count());self::assertSame(0,$this->source->table('TblSMSDrops')->count());
    }

    public function testCleanupRefusesUnexpectedNumberedDataAndRetainsAcceptedReceipt():void
    {
        $this->makeRun();$this->cancellation->requestCancel(self::ID,'owner@example.invalid');
        $this->source->table('TblSMSDrops')->insert(['Request_ID'=>self::ID,'Drop_Name'=>'SMS0999','Drop_Date'=>'2026-10-26','Created_At'=>now()]);
        try{$this->cancellation->complete(self::ID);self::fail('Numbered data must block cleanup');}catch(RuntimeException){}
        self::assertSame('requested',$this->control->table(SmsSelectionCancellation::TABLE)->value('state'));
        self::assertSame(1,$this->control->table('jobs')->count());self::assertSame(1,$this->source->table('TblSMSDrops')->count());
        self::assertSame('queued',$this->source->table('TblSMSRuns')->value('Status'));
    }

    public function testRecoveryJobReleasesInsteadOfAcknowledgingAFailedCleanup():void
    {
        $runs=\Mockery::mock(SmsRunService::class);$runs->shouldReceive('completeSelectionCancellation')->once()->andThrow(new RuntimeException('Private synthetic failure'));
        $queueJob=\Mockery::mock(\Illuminate\Contracts\Queue\Job::class);$queueJob->shouldReceive('release')->once()->with(5);
        $job=new CancelSmsSelectionJob(self::ID);$job->setJob($queueJob);$job->handle($runs);
        \Mockery::close();self::assertFalse($job->afterCommit);self::assertSame('database',$job->connection);
    }

    public function testActualDurableQueueRetainsFailedCleanupAndRecoversAfterRestart():void
    {
        $this->makeRun();$this->cancellation->requestCancel(self::ID,'owner@example.invalid');
        $queue=$this->container->make('queue')->connection('database');$reserved=$queue->pop('tu-export');
        self::assertNotNull($reserved);
        $bad=\Mockery::mock(SmsRunService::class);$bad->shouldReceive('completeSelectionCancellation')->once()->andThrow(new RuntimeException('Synthetic worker restart'));
        $job=new CancelSmsSelectionJob(self::ID);$job->setJob($reserved);$job->handle($bad);
        self::assertSame(1,$this->control->table('jobs')->count());
        self::assertNull($this->control->table('jobs')->value('reserved_at'));
        self::assertSame('requested',$this->control->table(SmsSelectionCancellation::TABLE)->value('state'));
        self::assertSame('queued',$this->source->table('TblSMSRuns')->value('Status'));
        Carbon::setTestNow(Carbon::now()->addSeconds(6));
        $retry=$queue->pop('tu-export');self::assertNotNull($retry);
        $restarted=new CancelSmsSelectionJob(self::ID);$restarted->setJob($retry);$restarted->handle($this->runs);
        $retry->delete(); // The queue worker acknowledges only after successful handle().
        self::assertSame(0,$this->control->table('jobs')->count());
        self::assertSame('cancelled',$this->control->table(SmsSelectionCancellation::TABLE)->value('state'));
        self::assertSame('discarded',$this->source->table('TblSMSRuns')->value('Status'));
        \Mockery::close();
    }

    public function testADeadSelectionWorkersFailedStateStillHonorsAcceptedCancellation():void
    {
        $this->makeRun('selecting');$this->cancellation->requestCancel(self::ID,'owner@example.invalid');
        $this->source->table('TblSMSRuns')->where('Request_ID',self::ID)->update([
            'Status'=>'failed','Failed_Step'=>'select','Error'=>'Synthetic process death',
        ]);
        $this->runs->completeSelectionCancellation(self::ID);
        self::assertSame('discarded',$this->source->table('TblSMSRuns')->value('Status'));
        self::assertNull($this->source->table('TblSMSRuns')->value('Error'));
        self::assertSame('cancelled',$this->control->table(SmsSelectionCancellation::TABLE)->value('state'));
    }

    public function testAcceptedCancelCleanupFailureCannotBeReplacedByOrdinaryDiscard():void
    {
        $this->makeRun();$this->cancellation->requestCancel(self::ID,'owner@example.invalid');
        $this->source->table('TblProspectsExport')->insert(['Request_ID'=>self::ID,'Prospect_PK'=>1,'Phone'=>'2025550101']);
        $bad=new class extends SmsSelectionCancellation {
            public function complete(string $id):void {throw new RuntimeException('Synthetic transient cleanup failure');}
        };
        $this->container->instance(SmsSelectionCancellation::class,$bad);
        try{$this->runs->prepare(self::ID);self::fail('Expected transient cancellation cleanup failure');}
        catch(RuntimeException){}
        $this->runs->fail(self::ID,'prepare','Synthetic queue failure callback');
        self::assertSame('failed',$this->source->table('TblSMSRuns')->value('Status'));
        self::assertSame('select',$this->source->table('TblSMSRuns')->value('Failed_Step'));
        config(['sms-exports.selection_cancellation_enabled'=>false]); // Accepted work survives rollback of the API gate.
        $this->assertRejected(409,fn()=>$this->runs->discard(self::ID));
        self::assertSame('failed',$this->source->table('TblSMSRuns')->value('Status'));
        self::assertSame('requested',$this->control->table(SmsSelectionCancellation::TABLE)->value('state'));
        self::assertSame(1,$this->source->table('TblProspectsExport')->count());
        self::assertSame(1,$this->control->table('jobs')->count());
        $this->container->instance(SmsSelectionCancellation::class,$this->cancellation);
        $this->runs->completeSelectionCancellation(self::ID);
        self::assertSame('cancelled',$this->control->table(SmsSelectionCancellation::TABLE)->value('state'));
        self::assertSame(SmsSelectionCancellation::CANCELLED_STEP,$this->source->table('TblSMSRuns')->value('Progress_Step'));
        self::assertSame(0,$this->source->table('TblProspectsExport')->count());
        self::assertSame(0,$this->source->table('TblSMSHistory')->count());self::assertSame(0,$this->source->table('TblSMSDrops')->count());
    }

    public function testOrdinaryDiscardWinsBeforeAStaleApiAttemptAndLeavesNoAcceptedCancellation():void
    {
        $this->makeRun('selecting');
        $service=new class extends SmsSelectionCancellation {
            public $afterFirstRead;
            private int $reads=0;
            protected function readRunSnapshot(string $id):object {
                $run=parent::readRunSnapshot($id);
                if(++$this->reads===1)($this->afterFirstRead)();
                return $run;
            }
        };
        $this->container->instance(SmsSelectionCancellation::class,$service);
        $service->afterFirstRead=function():void {
            $this->runs->fail(self::ID,'prepare','Synthetic selection failure');
            self::assertSame('discarded',$this->runs->discard(self::ID)->status);
        };
        $this->assertRejected(409,fn()=>$service->requestCancel(self::ID,'owner@example.invalid'));
        self::assertSame('discarded',$this->source->table('TblSMSRuns')->value('Status'));
        self::assertNull($this->source->table('TblSMSRuns')->value('Progress_Step'));
        self::assertSame('active',$this->control->table(SmsSelectionCancellation::TABLE)->value('state'));
        self::assertSame(0,$this->control->table('jobs')->count());
    }

    public function testFailedOrdinaryDiscardRollsBackWithoutATombstoneAndSelectionCanRetry():void
    {
        $run=$this->makeRun('failed',['Failed_Step'=>'select']);$this->cancellation->begin((object)array_replace((array)$run,['Status'=>'selecting']));
        $this->source->table('TblProspectsExport')->insert(['Request_ID'=>self::ID,'Prospect_PK'=>1,'Phone'=>'2025550101']);
        $events=new \Illuminate\Events\Dispatcher($this->container);$failed=false;
        $events->listen(\Illuminate\Database\Events\QueryExecuted::class,function($event)use(&$failed):void {
            if(str_starts_with(strtolower($event->sql),'delete from')&&str_contains(strtolower($event->sql),'tblprospectsexport')) {
                self::assertSame(0,$this->control->transactionLevel(),'Do not hold the cancellation fence across bulk discard');
                $failed=true;throw new RuntimeException('Synthetic discard failure');
            }
        });
        $this->source->setEventDispatcher($events);
        try{$this->runs->discard(self::ID);self::fail('Expected discard rollback');}catch(RuntimeException){}
        $this->source->unsetEventDispatcher();self::assertTrue($failed);
        self::assertSame('failed',$this->source->table('TblSMSRuns')->value('Status'));
        self::assertSame(1,$this->source->table('TblProspectsExport')->count());
        self::assertSame('active',$this->control->table(SmsSelectionCancellation::TABLE)->value('state'));
        self::assertSame('queued',$this->runs->retry(self::ID)->status);
        $this->source->table('TblProspectsExport')->delete(); // Fixture export stub inserts a fresh whole selection.
        $this->runs->prepare(self::ID);
        self::assertSame('review',$this->source->table('TblSMSRuns')->value('Status'));
        self::assertSame('finished',$this->control->table(SmsSelectionCancellation::TABLE)->value('state'));
        self::assertSame(0,$this->control->table('jobs')->count());
    }
}
