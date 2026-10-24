<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use PDO;
use RuntimeException;

/** Explicit opt-in only; random disposable hardwired loopback database, never application .env. */
final class SmsExternalEventSqlServerTest extends SmsExternalEventServiceTest
{
    private ?PDO $admin=null;
    private ?string $fixtureName=null;
    private bool $created=false;

    protected function setUp(): void
    {
        if (getenv('SMS_EXTERNAL_SQLSERVER_FIXTURE')!=='1') self::markTestSkipped('Set SMS_EXTERNAL_SQLSERVER_FIXTURE=1 for the guarded loopback fixture.');
        try { parent::setUp(); }
        catch (\Throwable $error) { $this->cleanupDatabase(); throw $error; }
    }

    protected function connectionConfig(): array
    {
        $this->fixtureName='sms_external_event_test_'.bin2hex(random_bytes(8));
        if (! preg_match('/\Asms_external_event_test_[a-f0-9]{16}\z/',$this->fixtureName)) throw new RuntimeException('Unsafe fixture name.');
        $this->admin=new PDO('sqlsrv:Server=127.0.0.1,14330;Database=master;Encrypt=no;TrustServerCertificate=yes','sa','LocalDev!2026Pass',
            [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::SQLSRV_ATTR_QUERY_TIMEOUT=>20]);
        $this->admin->exec('CREATE DATABASE ['.$this->fixtureName.'] COLLATE SQL_Latin1_General_CP1_CI_AS');
        $this->created=true;
        return ['driver'=>'sqlsrv','host'=>'127.0.0.1','port'=>14330,'database'=>$this->fixtureName,'username'=>'sa','password'=>'LocalDev!2026Pass',
            'encrypt'=>'no','trust_server_certificate'=>true,'prefix'=>'','options'=>[PDO::SQLSRV_ATTR_QUERY_TIMEOUT=>20]];
    }

    protected function tearDown(): void
    {
        try { parent::tearDown(); } finally { $this->cleanupDatabase(); }
    }

    private function cleanupDatabase(): void
    {
        if ($this->created && $this->admin && $this->fixtureName && preg_match('/\Asms_external_event_test_[a-f0-9]{16}\z/',$this->fixtureName)) {
            $this->admin->exec('ALTER DATABASE ['.$this->fixtureName.'] SET SINGLE_USER WITH ROLLBACK IMMEDIATE; DROP DATABASE ['.$this->fixtureName.']');
        }
        $this->admin=null; $this->fixtureName=null; $this->created=false;
    }
}
