<?php
declare(strict_types=1);
namespace Cmd\Reports\Tests\Unit;
require_once __DIR__.'/Fixtures/SmsAutomaticHandoffFixture.php';

use Cmd\Reports\Services\SmsRunAutomaticHandoffWorkflow;
use Cmd\Reports\Tests\Fixtures\SmsAutomaticHandoffFixture;
use RuntimeException;

final class SmsAutomaticHandoffMigrationTest extends SmsAutomaticHandoffFixture
{
    private function migration():object{return require dirname(__DIR__,2).'/database/sms-migrations/2026_10_26_000001_create_sms_automatic_handoffs.php';}
    public function test_repeated_install_and_rollback_refuse_without_changing_any_retained_intent_or_source():void
    {
        $source=(array)$this->source();$this->capture();$row=$this->ledger();
        foreach(['up','down']as$action){try{$this->migration()->$action();self::fail('Retained intent must not be adopted or dropped.');}catch(RuntimeException){}self::assertSame($row,$this->ledger());}
        self::assertSame($source,(array)$this->source());self::assertSame(0,$this->publisher->publishes);self::assertSame(0,$this->mail->lookups);
    }
    public function test_ambient_transaction_is_refused_without_rolling_back_its_owner():void
    {
        $this->db->beginTransaction();try{try{$this->migration()->up();self::fail('Ambient transaction must refuse.');}catch(RuntimeException){}
            self::assertSame(1,$this->db->transactionLevel());self::assertTrue($this->db->getPdo()->inTransaction());}finally{$this->db->rollBack();}
    }
    public function test_incomplete_prerequisite_holds_before_new_ddl_without_repair():void
    {
        $schema=$this->db->getSchemaBuilder();$schema->drop(SmsRunAutomaticHandoffWorkflow::TABLE);$schema->table('TblSMSRuns',fn($t)=>$t->dropColumn('Approved_At'));
        $before=(array)$this->source();try{$this->migration()->up();self::fail('Incomplete prerequisite must hold.');}catch(RuntimeException){}
        self::assertFalse($schema->hasTable(SmsRunAutomaticHandoffWorkflow::TABLE));self::assertSame($before,(array)$this->source());
    }
    public function test_reconnect_at_ddl_begin_cannot_create_on_replacement_or_leave_original_transaction_active():void
    {
        $this->db->getSchemaBuilder()->drop(SmsRunAutomaticHandoffWorkflow::TABLE);$old=$this->db->getPdo();$this->db->replace=true;
        try{$this->migration()->up();self::fail('Changed session must refuse before DDL.');}catch(RuntimeException){}
        self::assertFalse($old->inTransaction());self::assertFalse($this->db->replacement->inTransaction());
        self::assertSame(0,(int)$old->query("SELECT COUNT(*) FROM sqlite_master WHERE name='TblSMSAutomaticHandoffs'")->fetchColumn());
        self::assertSame(0,(int)$this->db->replacement->query("SELECT COUNT(*) FROM sqlite_master WHERE name='TblSMSAutomaticHandoffs'")->fetchColumn());
    }
}
