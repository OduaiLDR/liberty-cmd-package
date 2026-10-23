<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Services\SmsProspectBackfill;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/** Capture the actual emitted batch without a database or customer fixtures. */
final class SmsBackfillSerialLockingTest extends TestCase
{
    public function test_bounded_range_update_locks_remain_in_place_but_cannot_use_parallel_workers(): void
    {
        $db=new SmsBackfillSerialCapturePdo();
        $result=(new ReflectionMethod(SmsProspectBackfill::class,'applyRows'))->invoke(null,$db,
            'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',[],true,true);
        self::assertSame(['Added'=>0,'Existing'=>0,'Deferred'=>0],$result);
        self::assertMatchesRegularExpression('/SELECT i\.Source_PK,p\.PK INTO #BFExisting.*?q WITH\(UPDLOCK,HOLDLOCK\).*?\) p OPTION\(MAXDOP 1\);/s',$db->sql);
        self::assertMatchesRegularExpression('/SELECT n\.\*.*?INTO #BFReady.*?TblSMSSuppressions s WITH\(UPDLOCK,HOLDLOCK\).*? h OPTION\(MAXDOP 1\);/s',$db->sql);
        self::assertStringContainsString('CREATE UNIQUE INDEX IX_BFInput_Identity ON #BFInput(Client,Address,Zip);',$db->sql);
        self::assertStringContainsString('MERGE dbo.TblProspects AS target USING #BFReady AS source ON 1=0',$db->sql);
        self::assertStringNotContainsString('WHEN MATCHED',$db->sql);
        self::assertSame(['aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',1,1,'[]'],$db->statement->bindings);
    }
}

final class SmsBackfillSerialCapturePdo extends PDO
{
    public string $sql='';
    public SmsBackfillSerialCaptureStatement $statement;
    public function __construct(){}
    public function prepare(string $query,array $options=[]): PDOStatement|false
    {
        $this->sql=$query;return $this->statement=new SmsBackfillSerialCaptureStatement();
    }
}

final class SmsBackfillSerialCaptureStatement extends PDOStatement
{
    public array $bindings=[];
    public function __construct(){}
    public function execute(?array $params=null): bool{$this->bindings=$params??[];return true;}
    public function columnCount(): int{return 1;}
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args): array{return [['Added'=>0,'Existing'=>0,'Deferred'=>0]];}
    public function closeCursor(): bool{return true;}
}
