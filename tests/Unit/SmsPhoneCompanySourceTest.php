<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Console\Commands\SyncPhoneNumbers;
use Cmd\Reports\Services\DBConnector;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
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
        $app->instance('config',new Repository([]));
        $this->logger=new class {public array $contexts=[];public function info($message,array $context=[]):void {$this->contexts[]=$context;}};
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
            public function __construct(public mixed $selected) {parent::__construct();}
            public function option($key=null) {return ['source'=>$this->selected,'dry-run'=>true,'batch-size'=>1000][$key]??null;}
            public function info($string,$verbosity=null) {}
            public function warn($string,$verbosity=null) {}
            public function error($string,$verbosity=null) {$this->errors[]=$string;}
            protected function initializeSqlServerConnection(): \PDO {++$this->sqlInitializations;throw new \RuntimeException('Unexpected SQL initialization.');}
            protected function createSnowflakeConnector(): DBConnector {
                $this->environments[]=$this->sourceEnvironment;
                return new class extends DBConnector {
                    public function __construct() {}
                    public function query(string $sql,array $bindings=[],?int $timeoutSeconds=null):array {
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
}
