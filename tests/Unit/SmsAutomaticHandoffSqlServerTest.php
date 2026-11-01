<?php
declare(strict_types=1);
namespace Cmd\Reports\Tests\Unit;
require_once __DIR__.'/Fixtures/SmsAutomaticHandoffFixture.php';

use Carbon\Carbon;
use Cmd\Reports\Services\{SmsAutomaticHandoffPlan,SmsRunAutomaticHandoffWorkflow,SmsRunEmailWorkflow};
use Cmd\Reports\Tests\Fixtures\SmsAutomaticHandoffFixture;

/** Actual loopback SQL Server uniqueidentifier/datetime/provider behavior; every case drops only its owned DB. */
final class SmsAutomaticHandoffSqlServerTest extends SmsAutomaticHandoffFixture
{
    protected function native(): bool { return true; }
    public function test_native_capture_owner_transaction_and_raw_uuid_fingerprint_are_preserved(): void
    {
        $source=$this->source();self::assertSame(strtoupper($this->id),$source->Request_ID);self::assertSame('2026-10-26 11:00:00.1234567',$source->Approved_At);
        $this->db->beginTransaction();$this->workflow->captureApproved($source);self::assertSame(1,$this->db->table(SmsRunAutomaticHandoffWorkflow::TABLE)->count());
        $this->db->rollBack();self::assertSame(0,$this->db->table(SmsRunAutomaticHandoffWorkflow::TABLE)->count());$this->capture();
        $row=$this->ledger();$plan=json_decode($row['Plan_JSON'],true,flags:JSON_THROW_ON_ERROR);
        self::assertSame($this->id,$row['Request_ID']);self::assertSame(SmsAutomaticHandoffPlan::fingerprint($source),$plan['run_sha256']);
        self::assertSame((array)$source,(array)$this->source());self::assertSame(0,$this->publisher->publishes);self::assertSame(0,$this->mail->lookups);
        foreach(['Created_At','Updated_At','Lease_Until','Completed_At']as$name){$type=$this->db->selectOne("SELECT TYPE_NAME(user_type_id) AS type,scale FROM sys.columns WHERE object_id=OBJECT_ID('dbo.TblSMSAutomaticHandoffs') AND name=?",[$name]);self::assertSame('datetime2',$type->type);self::assertSame(6,(int)$type->scale);}
    }
    public function test_native_real_publication_and_email_complete_once_and_replay_changes_nothing(): void
    {
        $source=(array)$this->source();$read=new \Cmd\Reports\Tests\Fixtures\HandoffFixtureReadTrap;$this->db->setReadPdo($read);
        $this->capture();self::assertSame([$this->id],$this->workflow->pending());self::assertSame('prepare',$this->workflow->step($this->id)['phase']);
        self::assertSame('send',$this->workflow->step($this->id)['phase']);$done=$this->workflow->step($this->id);
        self::assertSame('completed',$done['status']);self::assertSame(2,$this->publisher->creates);self::assertSame(1,$this->mail->lookups);self::assertCount(1,$this->mail->sent);
        $row=$this->ledger();self::assertSame(3,(int)$row['Lease_Version']);self::assertNull($row['Lease_Token']);self::assertNotNull($row['Completed_At']);
        self::assertSame($done,$this->workflow->step($this->id));$this->capture();self::assertSame($row,$this->ledger());self::assertCount(1,$this->mail->sent);
        self::assertSame($source,(array)$this->source());self::assertSame('accepted',$this->db->table(SmsRunEmailWorkflow::TABLE)->useWritePdo()->value('State'));
        self::assertSame([],$this->workflow->pending());self::assertSame(0,$read->reads);self::assertSame($read,$this->db->getRawReadPdo());
    }
    public function test_native_partial_receipts_are_reconciled_after_lease_without_duplicate_remote_creates(): void
    {
        $this->capture();$this->publisher->failPart=2;self::assertSame('pending_recovery',$this->workflow->step($this->id)['error_code']);
        $calls=$this->publisher->publishes;self::assertSame('working',$this->workflow->step($this->id)['status']);self::assertSame($calls,$this->publisher->publishes);
        Carbon::setTestNow(Carbon::now('UTC')->addSeconds(7801));self::assertSame([$this->id],$this->workflow->pending());
        self::assertSame('prepare',$this->workflow->step($this->id)['phase']);self::assertSame(2,$this->publisher->creates);self::assertSame(0,$this->mail->lookups);
    }
    public function test_native_ambiguous_email_acceptance_holds_forever_without_another_send(): void
    {
        $this->capture();$this->workflow->step($this->id);$this->workflow->step($this->id);$this->mail->loseAck=true;
        $held=$this->workflow->step($this->id);self::assertSame('held',$held['status']);self::assertSame('email_uncertain',$held['error_code']);
        $saved=$this->ledger();Carbon::setTestNow(Carbon::now('UTC')->addDays(3));self::assertSame($held,$this->workflow->step($this->id));
        self::assertSame($saved,$this->ledger());self::assertCount(1,$this->mail->sent);self::assertSame([],$this->workflow->pending());
    }
}
