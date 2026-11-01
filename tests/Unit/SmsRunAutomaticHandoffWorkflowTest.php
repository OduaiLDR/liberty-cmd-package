<?php
declare(strict_types=1);
namespace Cmd\Reports\Tests\Unit;
require_once __DIR__.'/Fixtures/SmsAutomaticHandoffFixture.php';

use Carbon\Carbon;
use Cmd\Reports\Services\{SmsRunAutomaticHandoffWorkflow,SmsRunEmailWorkflow,SmsRunPublicationWorkflow};
use Cmd\Reports\Tests\Fixtures\{SmsAutomaticHandoffFixture,HandoffFixtureBeginConnection};
use RuntimeException;

final class SmsRunAutomaticHandoffWorkflowTest extends SmsAutomaticHandoffFixture
{
    public function test_approval_capture_is_atomic_metadata_only_idempotent_and_never_discovers_history(): void
    {
        self::assertSame([], $this->workflow->pending()); self::assertNull($this->workflow->status($this->id));
        try { $this->workflow->captureApproved($this->source()); self::fail('Capture requires approval owner transaction.'); } catch(RuntimeException) {}
        rename($this->bundle['path'],$this->bundle['path'].'.retained');
        try {
            $this->db->beginTransaction(); $this->workflow->captureApproved($this->source()); self::assertCount(1,$this->db->table(SmsRunAutomaticHandoffWorkflow::TABLE)->get());
            $this->db->rollBack(); self::assertSame(0,$this->db->table(SmsRunAutomaticHandoffWorkflow::TABLE)->count()); $this->capture();
        } finally { rename($this->bundle['path'].'.retained',$this->bundle['path']); }
        $before=$this->ledger(); $this->capture(); self::assertSame($before,$this->ledger());
        self::assertSame([$this->id],$this->workflow->pending()); self::assertSame(0,$this->publisher->publishes);self::assertSame(0,$this->mail->lookups);self::assertSame([],$this->mail->sent);
    }
    public function test_three_real_phases_freeze_dynamic_recipients_and_complete_without_h4_or_replay_writes(): void
    {
        $source=(array)$this->source();$this->capture();
        self::assertSame('prepare',$this->workflow->step($this->id)['phase']);self::assertSame(2,$this->publisher->creates);self::assertSame(0,$this->mail->lookups);
        self::assertSame('send',$this->workflow->step($this->id)['phase']);self::assertSame(1,$this->mail->lookups);self::assertSame([],$this->mail->sent);
        $this->mail->configuration['to']=['later@example.invalid'];$done=$this->workflow->step($this->id);
        self::assertSame('completed',$done['status']);self::assertSame(['first@example.invalid'],$this->mail->sent[0]['to']);self::assertCount(1,$this->mail->sent);
        $saved=$this->ledger();$pubs=$this->publisher->publishes;$verifies=$this->publisher->verifies;
        self::assertSame($done,$this->workflow->step($this->id));$this->capture();self::assertSame($saved,$this->ledger());
        self::assertSame($pubs,$this->publisher->publishes);self::assertSame($verifies,$this->publisher->verifies);self::assertCount(1,$this->mail->sent);
        self::assertSame($source,(array)$this->source());self::assertSame([],$this->workflow->pending());
        self::assertStringNotContainsString('first@example.invalid',json_encode($done));
    }
    public function test_partial_publication_retains_receipts_and_waits_before_reconciling_same_objects(): void
    {
        $this->capture();$this->publisher->failPart=2;$waiting=$this->workflow->step($this->id);
        self::assertSame('working',$waiting['status']);self::assertSame('pending_recovery',$waiting['error_code']);self::assertSame(2,$this->publisher->creates);
        $calls=$this->publisher->publishes;self::assertSame($waiting,$this->workflow->step($this->id));self::assertSame($calls,$this->publisher->publishes);self::assertSame([],$this->workflow->pending());
        Carbon::setTestNow(Carbon::now('UTC')->addSeconds(7801));self::assertSame([$this->id],$this->workflow->pending());
        self::assertSame('prepare',$this->workflow->step($this->id)['phase']);self::assertSame(2,$this->publisher->creates);self::assertSame(0,$this->mail->lookups);
    }
    public function test_policy_or_source_drift_holds_before_email_lookup(): void
    {
        $this->capture();$this->workflow->step($this->id);$this->publisher->store='changed-store';
        $held=$this->workflow->step($this->id);self::assertSame('held',$held['status']);self::assertSame('context_changed',$held['error_code']);
        self::assertSame(0,$this->mail->lookups);self::assertSame([],$this->mail->sent);self::assertSame($held,$this->workflow->step($this->id));
    }
    public function test_changed_publication_plan_is_held_before_any_reverification_or_email_lookup(): void
    {
        $this->capture();$this->workflow->step($this->id);$calls=$this->publisher->verifies;
        $row=$this->db->table(SmsRunPublicationWorkflow::TABLE)->first();$plan=json_decode($row->Plan_JSON,true,flags:JSON_THROW_ON_ERROR);$plan['provider_identity']='changed';
        $json=\Cmd\Reports\Services\SmsAutomaticHandoffPlan::json($plan);$this->db->table(SmsRunPublicationWorkflow::TABLE)->update(['Plan_JSON'=>$json,'Plan_Sha256'=>hash('sha256',$json)]);
        self::assertSame('held',$this->workflow->step($this->id)['status']);self::assertSame($calls,$this->publisher->verifies);self::assertSame(0,$this->mail->lookups);self::assertSame([],$this->mail->sent);
    }
    public function test_unknown_mail_acceptance_is_terminal_and_never_resent(): void
    {
        $this->capture();$this->workflow->step($this->id);$this->workflow->step($this->id);$this->mail->loseAck=true;
        $held=$this->workflow->step($this->id);self::assertSame('held',$held['status']);self::assertSame('email_uncertain',$held['error_code']);self::assertCount(1,$this->mail->sent);
        self::assertSame('needs_review',$this->db->table(SmsRunEmailWorkflow::TABLE)->value('State'));
        Carbon::setTestNow(Carbon::now('UTC')->addDays(3));self::assertSame($held,$this->workflow->step($this->id));self::assertCount(1,$this->mail->sent);
    }
    public function test_revoke_during_verified_part_commits_that_receipt_but_blocks_next_provider_call(): void
    {
        $this->capture();$this->publisher->onVerify=function():void{config(['sms-exports.handoff_automatic'=>false]);};
        try{$this->workflow->step($this->id);self::fail('Revoked phase must stop.');}catch(RuntimeException){}
        self::assertSame(1,$this->publisher->creates);self::assertSame(1,$this->publisher->publishes);self::assertSame(1,$this->publisher->verifies);
        self::assertSame('complete',$this->db->table(SmsRunPublicationWorkflow::PARTS)->where('Part_Number',1)->value('State'));self::assertSame([],$this->mail->sent);
    }
    public function test_revoke_during_mail_readiness_prevents_send_and_preserves_nonretryable_attempt(): void
    {
        $this->capture();$this->workflow->step($this->id);$this->workflow->step($this->id);
        $this->mail->onReady=function():void{config(['sms-exports.handoff_automatic'=>false]);};
        try{$this->workflow->step($this->id);self::fail('Revoked phase must stop.');}catch(RuntimeException){}
        self::assertSame([],$this->mail->sent);self::assertSame('needs_review',$this->db->table(SmsRunEmailWorkflow::TABLE)->value('State'));
        config(['sms-exports.handoff_automatic'=>true]);Carbon::setTestNow(Carbon::now('UTC')->addSeconds(7801));
        self::assertSame('held',$this->workflow->step($this->id)['status']);self::assertSame([],$this->mail->sent);
    }
    public function test_active_lease_blocks_second_worker_without_provider_or_counter_changes(): void
    {
        $this->capture();$observed=[];$this->publisher->onPublish=function()use(&$observed):void{$observed[]=$this->workflow->step($this->id)['status'];};
        self::assertSame('prepare',$this->workflow->step($this->id)['phase']);self::assertSame(['working','working'],$observed);self::assertSame(2,$this->publisher->creates);
    }
    public function test_pdo_swap_after_begin_rolls_back_both_sessions_before_any_callback_write(): void
    {
        $this->capture();self::assertInstanceOf(HandoffFixtureBeginConnection::class,$this->db);$original=$this->db->getPdo();$this->db->replace=true;
        try{$this->workflow->step($this->id);self::fail('Changed session must refuse.');}catch(RuntimeException){}
        self::assertFalse($original->inTransaction());self::assertFalse($this->db->replacement->inTransaction());
        self::assertSame('queued',$original->query('SELECT State FROM TblSMSAutomaticHandoffs')->fetchColumn());self::assertSame(0,$this->publisher->publishes);
    }
    public function test_all_gates_are_strict_and_manual_existing_receipts_are_not_adopted(): void
    {
        config(['sms-exports.handoff_automatic'=>'true']);$this->capture();self::assertSame(0,$this->db->table(SmsRunAutomaticHandoffWorkflow::TABLE)->count());
        config(['sms-exports.handoff_automatic'=>true]);
        $this->db->table(SmsRunEmailWorkflow::TABLE)->insert(['Request_ID'=>$this->id,'Run_Sha256'=>str_repeat('a',64),'Payload_JSON'=>'{}','Payload_Sha256'=>str_repeat('b',64),'State'=>'ready','Created_By'=>'fixture@example.invalid','Created_At'=>'2026-10-26 11:00:00']);
        try{$this->capture();self::fail('Manual outbox must not be adopted.');}catch(RuntimeException){}
        self::assertSame(0,$this->db->table(SmsRunAutomaticHandoffWorkflow::TABLE)->count());self::assertSame(1,$this->db->table(SmsRunEmailWorkflow::TABLE)->count());
    }
    public function test_pending_status_and_all_phases_never_touch_a_configured_read_replica(): void
    {
        $read=new \Cmd\Reports\Tests\Fixtures\HandoffFixtureReadTrap;$this->db->setReadPdo($read);
        $this->capture();self::assertSame([$this->id],$this->workflow->pending());self::assertSame('queued',$this->workflow->status($this->id)['status']);
        self::assertSame('prepare',$this->workflow->step($this->id)['phase']);self::assertSame('send',$this->workflow->step($this->id)['phase']);
        self::assertSame('completed',$this->workflow->step($this->id)['status']);self::assertSame([],$this->workflow->pending());self::assertSame(0,$read->reads);
        self::assertSame($read,$this->db->getRawReadPdo());self::assertSame('completed',$this->workflow->step($this->id)['status']);self::assertSame($read,$this->db->getRawReadPdo());
    }
    public function test_original_lazy_read_handle_is_restored_after_guard_exception_without_ever_resolving_it(): void
    {
        $read=fn()=>throw new RuntimeException('PRIVATE replica must never open');$this->db->setReadPdo($read);
        try{$this->workflow->step($this->id);self::fail('There is no captured intent.');}catch(RuntimeException $e){self::assertStringNotContainsString('PRIVATE',$e->getMessage());}
        self::assertSame($read,$this->db->getRawReadPdo());$this->capture();self::assertSame($read,$this->db->getRawReadPdo());
        $this->publisher->onVerify=function():void{config(['sms-exports.handoff_automatic'=>false]);};
        try{$this->workflow->step($this->id);self::fail('Gate revocation must refuse next provider.');}catch(RuntimeException){}
        self::assertSame($read,$this->db->getRawReadPdo());
    }
    public function test_nested_real_publication_begin_session_swap_is_discarded_without_leaking_original_transaction(): void
    {
        $this->capture();$old=$this->db->getPdo();$count=0;
        $this->db->beforeStartingTransaction(function()use(&$count):void{if(++$count===2)$this->db->replace=true;});
        try{$this->workflow->step($this->id);self::fail('Changed nested publication session must refuse.');}catch(RuntimeException){}
        self::assertFalse($old->inTransaction());self::assertFalse($this->db->replacement->inTransaction());
        self::assertSame(0,(int)$old->query('SELECT COUNT(*) FROM TblSMSFilePublications')->fetchColumn());
        self::assertSame('working',$old->query('SELECT State FROM TblSMSAutomaticHandoffs')->fetchColumn());self::assertSame(0,$this->publisher->publishes);
    }
    public function test_ready_email_created_between_phases_is_retained_and_never_adopted_or_relooked_up(): void
    {
        $this->capture();$this->workflow->step($this->id);
        $publications=app(SmsRunPublicationWorkflow::class);
        \Cmd\Reports\Services\SmsDebtPleteImportPrivacy::run(fn()=>(new SmsRunEmailWorkflow($this->mail))->prepare($this->id,$publications->links($this->id),'manual@example.invalid'));
        $before=(array)$this->db->table(SmsRunEmailWorkflow::TABLE)->first();$lookups=$this->mail->lookups;$verifies=$this->publisher->verifies;
        $held=$this->workflow->step($this->id);self::assertSame('held',$held['status']);self::assertSame('email_origin_unconfirmed',$held['error_code']);
        self::assertSame($lookups,$this->mail->lookups);self::assertSame($verifies,$this->publisher->verifies);self::assertSame([],$this->mail->sent);
        self::assertSame($before,(array)$this->db->table(SmsRunEmailWorkflow::TABLE)->first());self::assertSame($held,$this->workflow->step($this->id));
    }
    public function test_committed_email_prepare_with_lost_intent_sha_ack_holds_ready_message_without_relookup_or_send(): void
    {
        $this->capture();$this->workflow->step($this->id);
        $this->db->unprepared("CREATE TRIGGER handoff_lose_prepared_ack BEFORE UPDATE OF Email_Sha256 ON TblSMSAutomaticHandoffs WHEN NEW.Email_Sha256 IS NOT NULL BEGIN SELECT RAISE(ABORT,'synthetic lost prepared acknowledgement'); END");
        $waiting=$this->workflow->step($this->id);self::assertSame('working',$waiting['status']);self::assertSame('prepare',$waiting['phase']);self::assertSame('pending_recovery',$waiting['error_code']);
        $before=(array)$this->db->table(SmsRunEmailWorkflow::TABLE)->first();self::assertSame('ready',$before['State']);self::assertNull($this->ledger()['Email_Sha256']);self::assertSame(1,$this->mail->lookups);
        Carbon::setTestNow(Carbon::now('UTC')->addSeconds(7801));$held=$this->workflow->step($this->id);
        self::assertSame('held',$held['status']);self::assertSame('email_origin_unconfirmed',$held['error_code']);self::assertSame(1,$this->mail->lookups);self::assertSame([],$this->mail->sent);
        self::assertSame($before,(array)$this->db->table(SmsRunEmailWorkflow::TABLE)->first());self::assertSame($held,$this->workflow->step($this->id));
    }
    public function test_ready_email_created_during_publication_reverification_is_caught_by_final_absence_check(): void
    {
        $this->capture();$this->workflow->step($this->id);$created=false;
        $this->publisher->onVerify=function()use(&$created):void{if(!$created){$created=true;$publications=app(SmsRunPublicationWorkflow::class);(new SmsRunEmailWorkflow($this->mail))->prepare($this->id,$publications->links($this->id),'manual@example.invalid');}};
        $held=$this->workflow->step($this->id);self::assertSame('held',$held['status']);self::assertSame('email_origin_unconfirmed',$held['error_code']);
        self::assertSame(1,$this->mail->lookups);self::assertSame([],$this->mail->sent);self::assertSame('ready',$this->db->table(SmsRunEmailWorkflow::TABLE)->value('State'));
    }
}
