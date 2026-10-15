<?php
namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Console\Commands\SyncNGOEPFClearRates;
use Cmd\Reports\Services\DBConnector;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class SyncNGOEPFClearRatesTest extends TestCase
{
    private function invoke(string $method, array $args = [], ?SyncNGOEPFClearRates $command = null): mixed
    {
        return (new \ReflectionMethod(SyncNGOEPFClearRates::class, $method))->invokeArgs($command ?? new SyncNGOEPFClearRates(), $args);
    }

    public function test_explicit_payroll_period_preserves_full_last_day_and_validates_before_read(): void
    {
        $command = new SyncNGOEPFClearRates();
        $command->setInput(new ArrayInput(['--period' => '2026-10'], $command->getDefinition()));
        $this->assertSame(['2026-10-01', '2026-10-31'], $this->invoke('resolveDateRange', [], $command));
        $command->setInput(new ArrayInput(['--period' => '2026-13'], $command->getDefinition()));
        $this->expectException(\InvalidArgumentException::class);
        $this->invoke('resolveDateRange', [], $command);
    }

    public function test_valid_zero_cleared_and_empty_complete_population_remain_distinct_from_failure(): void
    {
        $db = $this->getMockBuilder(DBConnector::class)->disableOriginalConstructor()->onlyMethods(['query'])->getMock();
        $db->expects($this->once())->method('query')->willReturnCallback(function (string $sql): array {
            $this->assertStringContainsString("d.SETTLEMENT_DATE < '2026-11-01'", $sql);
            $this->assertStringContainsString('c.COMPANY_ID = 85781', $sql);
            $this->assertStringContainsString('t1._FIVETRAN_DELETED', $sql);
            return ['success'=>true,'data'=>[['NEG_ID'=>'101','EPF_SCHEDULED'=>'1000','EPF_CLEARED'=>'0']], 'rowCount'=>1];
        });
        $this->assertSame([101=>['scheduled'=>1000.0,'cleared'=>0.0]], $this->invoke('fetchEpfSums', [$db,[101],'2026-10-01','2026-10-31','LDR']));
    }

    public function test_incomplete_or_failed_source_cannot_replace_stored_rows(): void
    {
        $db = $this->getMockBuilder(DBConnector::class)->disableOriginalConstructor()->onlyMethods(['query'])->getMock();
        $db->method('query')->willReturn(['success'=>false,'data'=>[], 'rowCount'=>0]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('preserved');
        $this->invoke('fetchEpfSums', [$db,[101],'2026-10-01','2026-10-31','PLAW']);
    }

    public function test_replacement_is_atomic_and_scoped_so_reruns_remove_prior_duplicates_including_zero(): void
    {
        $db = $this->getMockBuilder(DBConnector::class)->disableOriginalConstructor()->onlyMethods(['querySqlServer'])->getMock();
        $db->expects($this->once())->method('querySqlServer')->willReturnCallback(function (string $sql, array $params): array {
            $this->assertStringContainsString('BEGIN TRANSACTION', $sql);
            $this->assertStringContainsString('UPDLOCK, HOLDLOCK', $sql);
            $this->assertStringContainsString('ROLLBACK TRANSACTION', $sql);
            $this->assertStringContainsString('Start_Date = ? AND End_Date = ? AND Agent_ID = ? AND Source = ?', $sql);
            $this->assertSame(['2026-10-01','2026-10-31',7,'PLAW','2026-10-01','2026-10-31',7,1000.0,0.0,0.0,'2026-11-01 00:00:00','PLAW'], $params);
            return ['success'=>true];
        });
        $this->invoke('insertClearRate', [$db,'PLAW','2026-10-01','2026-10-31','2026-11-01 00:00:00',['agent_id'=>7,'scheduled'=>1000.0,'cleared'=>0.0,'rate'=>0.0]]);
    }

    public function test_database_replacement_failure_is_not_reported_as_success(): void
    {
        $db = $this->getMockBuilder(DBConnector::class)->disableOriginalConstructor()->onlyMethods(['querySqlServer'])->getMock();
        $db->method('querySqlServer')->willReturn(['success'=>false]);
        $this->expectException(\RuntimeException::class);
        $this->invoke('insertClearRate', [$db,'LDR','2026-10-01','2026-10-31','2026-11-01 00:00:00',['agent_id'=>7,'scheduled'=>0,'cleared'=>0,'rate'=>0]]);
    }

    public function test_command_keeps_zero_percent_and_verified_no_activity_rows_in_both_company_refreshes(): void
    {
        $writes=[];
        $sql=$this->getMockBuilder(DBConnector::class)->disableOriginalConstructor()->onlyMethods(['querySqlServer'])->getMock();
        $sql->method('querySqlServer')->willReturnCallback(function(string $query,array $params=[]) use (&$writes): array {
            if (str_contains($query,'SELECT PK')) return ['success'=>true,'data'=>[['PK'=>7,'UID'=>101]]];
            $writes[]=$params;
            return ['success'=>true];
        });
        $snowflake=$this->getMockBuilder(DBConnector::class)->disableOriginalConstructor()->onlyMethods(['query'])->getMock();
        $snowflake->expects($this->exactly(2))->method('query')->willReturnOnConsecutiveCalls(
            ['success'=>true,'data'=>[['NEG_ID'=>101,'EPF_SCHEDULED'=>1000,'EPF_CLEARED'=>0]],'rowCount'=>1],
            ['success'=>true,'data'=>[],'rowCount'=>0]);
        $command=new class($sql,$snowflake) extends SyncNGOEPFClearRates {
            public function __construct(private DBConnector $sql,private DBConnector $snowflake) { parent::__construct(); }
            protected function initializeSqlServerConnector(): DBConnector { return $this->sql; }
            protected function initializeSnowflakeConnector(string $source): DBConnector { return $this->snowflake; }
        };
        $input=new ArrayInput(['--period'=>'2026-10'],$command->getDefinition());
        $command->setInput($input);
        $command->setOutput(new \Illuminate\Console\OutputStyle($input,new BufferedOutput()));
        $this->assertSame(0,$command->handle());
        $this->assertCount(2,$writes);
        $this->assertSame(['LDR',1000.0,0.0,0.0],[$writes[0][3],$writes[0][7],$writes[0][8],$writes[0][9]]);
        $this->assertSame(['PLAW',0.0,0.0,0.0],[$writes[1][3],$writes[1][7],$writes[1][8],$writes[1][9]]);
    }
}
