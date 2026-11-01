<?php
declare(strict_types=1);
namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Console\Commands\RecoverSmsAutomaticHandoffs;
use Cmd\Reports\Services\SmsDebtPleteImportPrivacy;
use Cmd\Reports\Services\SmsRunAutomaticHandoffWorkflow;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/** Only the saved-ledger/dispatch boundary is faked; real workflow and real queue have separate tests. */
final class AutomaticRecoveryWorkflowBoundary extends SmsRunAutomaticHandoffWorkflow
{
    public array $ids=[];public array $limits=[];public bool $fail=false;
    public function __construct(){}
    public function pending(int $limit=10):array
    { TestCase::assertTrue(SmsDebtPleteImportPrivacy::active());$this->limits[]=$limit;if($this->fail)throw new RuntimeException('PRIVATE SQL bindings');return $this->ids; }
}
final class AutomaticRecoveryCommandBoundary extends RecoverSmsAutomaticHandoffs
{
    public int $factories=0;public array $attempts=[];public ?int $failAt=null;public bool $failFactory=false;
    public function __construct(public AutomaticRecoveryWorkflowBoundary $boundary){parent::__construct();}
    protected function workflow():SmsRunAutomaticHandoffWorkflow
    {TestCase::assertTrue(SmsDebtPleteImportPrivacy::active());++$this->factories;if($this->failFactory)throw new RuntimeException('PRIVATE provider secret');return $this->boundary;}
    protected function enqueue(string $id):void
    {TestCase::assertTrue(SmsDebtPleteImportPrivacy::active());$this->attempts[]=$id;if(count($this->attempts)===$this->failAt)throw new RuntimeException('PRIVATE queue credential');}
}
final class RecoverSmsAutomaticHandoffsTest extends TestCase
{
    private Container $previous;private Container $app;private AutomaticRecoveryWorkflowBoundary $workflow;private AutomaticRecoveryCommandBoundary $command;
    private const ID='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    protected function setUp():void
    {
        $this->previous=Container::getInstance();$this->app=new class extends Container{public function runningUnitTests():bool{return true;}};Container::setInstance($this->app);
        $this->app->instance('config',new Repository(['sms-exports'=>['handoff_automatic'=>true,'file_publication_enabled'=>true,'email_handoff_enabled'=>true,'queue'=>'tu-export'],
            'queue'=>['default'=>'database','connections'=>['database'=>['driver'=>'database','retry_after'=>7200]]]]));
        $this->workflow=new AutomaticRecoveryWorkflowBoundary;$this->command=new AutomaticRecoveryCommandBoundary($this->workflow);
    }
    protected function tearDown():void{self::assertFalse(SmsDebtPleteImportPrivacy::active());Container::setInstance($this->previous);}
    private function runCommand(array $options=[]):array
    {
        $this->command->setLaravel($this->app);$console=new Application;$console->setAutoExit(false);
        if(method_exists($console,'addCommand'))$console->addCommand($this->command);else$console->add($this->command);
        $input=new ArrayInput(['command'=>'sms:automatic-handoff-recover']+$options);$input->setInteractive(false);$output=new BufferedOutput;
        return[$console->run($input,$output),$output->fetch()];
    }
    public function test_default_is_bounded_saved_intent_preview_without_dispatch_or_identity_output():void
    {
        $this->workflow->ids=[self::ID];[$exit,$out]=$this->runCommand();self::assertSame(0,$exit);
        self::assertSame(['status'=>'preview','pending_handoffs'=>1,'dispatch_requests'=>0],json_decode(trim($out),true));
        self::assertSame([10],$this->workflow->limits);self::assertSame([],$this->command->attempts);self::assertStringNotContainsString(self::ID,$out);
    }
    public function test_disabled_is_honest_and_does_not_construct_workflow_even_when_queue_requested():void
    {
        config(['sms-exports.handoff_automatic'=>'true']);$this->command->failFactory=true;[$exit,$out]=$this->runCommand(['--queue'=>true,'--limit'=>'bad']);
        self::assertSame(0,$exit);self::assertSame(['status'=>'disabled','dispatch_requests'=>0],json_decode(trim($out),true));self::assertSame(0,$this->command->factories);
    }
    public function test_explicit_dispatch_counts_only_requests_returned_before_outage():void
    {
        $this->workflow->ids=[self::ID,'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb','cccccccc-cccc-4ccc-8ccc-cccccccccccc'];$this->command->failAt=3;
        [$exit,$out]=$this->runCommand(['--queue'=>true,'--limit'=>'3']);self::assertSame(1,$exit);
        self::assertSame(['status'=>'pending_recovery','dispatch_requests'=>2],json_decode(trim($out),true));self::assertSame($this->workflow->ids,$this->command->attempts);
        self::assertStringNotContainsString('PRIVATE',$out);self::assertStringNotContainsString('credential',$out);
    }
    public function test_explicit_queue_reuses_only_saved_uuid_and_does_not_claim_delivery():void
    {
        $this->workflow->ids=[self::ID];[$exit,$out]=$this->runCommand(['--queue'=>true]);self::assertSame(0,$exit);
        self::assertSame(['status'=>'requested','pending_handoffs'=>1,'dispatch_requests'=>1],json_decode(trim($out),true));self::assertSame([self::ID],$this->command->attempts);
    }
    #[DataProvider('limits')]
    public function test_invalid_limit_never_constructs_workflow(string $limit):void
    {[$exit,$out]=$this->runCommand(['--limit'=>$limit]);self::assertSame(1,$exit);self::assertSame(0,$this->command->factories);self::assertSame(['status'=>'pending_recovery','dispatch_requests'=>0],json_decode(trim($out),true));}
    public static function limits():array{return[['0'],['101'],['001'],['1.5'],['-1'],['1000'],[' 1']];}
    public function test_bad_saved_identity_and_queue_readiness_fail_closed_without_raw_errors():void
    {
        $this->workflow->ids=['../PRIVATE'];[$exit,$out]=$this->runCommand(['--queue'=>true]);self::assertSame(1,$exit);self::assertSame([],$this->command->attempts);self::assertStringNotContainsString('PRIVATE',$out);
        config(['queue.default'=>'sync']);$before=$this->command->factories;[$exit,$out]=$this->runCommand(['--queue'=>true]);self::assertSame(1,$exit);self::assertSame($before,$this->command->factories);
    }
    public function test_saved_ledger_failure_is_generic_and_privacy_scope_restores():void
    {$this->workflow->fail=true;[$exit,$out]=$this->runCommand();self::assertSame(1,$exit);self::assertStringNotContainsString('PRIVATE',$out);self::assertStringNotContainsString('SQL',$out);self::assertSame([],$this->command->attempts);}
}
