<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Services\SmsFrozenEligibility;
use Cmd\Reports\Services\SmsFrozenEligibilityHold;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Support\Facades\Facade;
use PDO;
use PHPUnit\Framework\TestCase;

/** Explicitly owned, loopback-only fixture; never connects to the existing port 14330 instance. */
final class SmsFrozenEligibilitySqlServerTest extends TestCase
{
    private static ?PDO $admin = null;
    private static string $database;
    private static string $password;
    private Container $previous;
    private $db;
    private const ID = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';

    public static function setUpBeforeClass(): void
    {
        if (getenv('SMS_M09_OWNED_NATIVE') !== '1' || ! in_array('sqlsrv', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('Explicit owned SMS M09 native fixture is required.');
        }
        self::$password = (string) getenv('SMS_M09_OWNED_PASSWORD');
        if (strlen(self::$password) < 16) throw new \RuntimeException('The owned native fixture password is absent.');
        self::$database = 'sms_m09_owned_'.bin2hex(random_bytes(8));
        // The image can emit its ready log before first-start SA initialization finishes.
        for ($attempt = 0; $attempt < 20; $attempt++) {
            try { self::$admin = self::pdo('master'); break; }
            catch (\PDOException) { usleep(250000); }
        }
        if (self::$admin === null) throw new \RuntimeException('Owned native SQL authentication did not become ready. No fixture database was created.');
        self::$admin->exec('CREATE DATABASE ['.self::$database.']');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$admin !== null && preg_match('/^sms_m09_owned_[a-f0-9]{16}$/D', self::$database)) {
            self::$admin->exec('ALTER DATABASE ['.self::$database.'] SET SINGLE_USER WITH ROLLBACK IMMEDIATE');
            self::$admin->exec('DROP DATABASE ['.self::$database.']'); self::$admin = null;
        }
    }

    private static function pdo(string $database): PDO
    {
        return new PDO('sqlsrv:Server=127.0.0.1,14337;Database='.$database.';Encrypt=no;TrustServerCertificate=yes',
            'sa', self::$password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    protected function setUp(): void
    {
        $this->previous = Container::getInstance(); $container = new Container; Container::setInstance($container);
        $container->instance('config', new Repository(['database' => ['default' => 'sqlsrv']]));
        $capsule = new Manager($container);
        $capsule->addConnection(['driver' => 'sqlsrv', 'host' => '127.0.0.1,14337', 'database' => self::$database,
            'username' => 'sa', 'password' => self::$password, 'encrypt' => 'no', 'trust_server_certificate' => 'yes'], 'sqlsrv');
        $container->instance('db', $capsule->getDatabaseManager());
        Facade::clearResolvedInstances(); Facade::setFacadeApplication($container); $this->db = $capsule->getConnection('sqlsrv');
        self::assertSame(self::$database, $this->db->selectOne('SELECT DB_NAME() AS name')->name);
        $this->db->unprepared('DROP TABLE IF EXISTS TblProspectsExport,TblSMSHistory,TblProspects,TblPhoneNumbers,TblSMSSuppressions,TblSMSRuns;
CREATE TABLE TblProspects(PK int NOT NULL PRIMARY KEY,State char(2),Phone varchar(20),Contacted bit NOT NULL,Opt_Out bit NOT NULL,Invalid_Phone bit NOT NULL);
CREATE TABLE TblProspectsExport(Request_ID uniqueidentifier NOT NULL,Prospect_PK int NOT NULL,State char(2),Phone varchar(20),PRIMARY KEY(Request_ID,Prospect_PK));
CREATE TABLE TblSMSHistory(Request_ID uniqueidentifier NOT NULL,Prospect_PK int NOT NULL,State char(2),Phone varchar(20),PRIMARY KEY(Request_ID,Prospect_PK));
CREATE TABLE TblPhoneNumbers(Phone varchar(20) NOT NULL PRIMARY KEY);
CREATE TABLE TblSMSSuppressions(Phone varchar(20) NOT NULL PRIMARY KEY,Opt_Out bit NOT NULL,Invalid_Phone bit NOT NULL);
CREATE TABLE TblSMSRuns(Request_ID uniqueidentifier NOT NULL PRIMARY KEY,Selection_Policy varchar(40),Status varchar(20),Selected int,Approved_At datetime2,Approved_By varchar(100));
INSERT TblProspects VALUES(1,\'CA\',\'2025550100\',0,0,0);');
        $this->db->table('TblProspectsExport')->insert(['Request_ID' => self::ID, 'Prospect_PK' => 1, 'State' => 'CA', 'Phone' => '2025550100']);
    }

    protected function tearDown(): void
    {
        while ($this->db->transactionLevel() > 0) $this->db->rollBack();
        $this->db->disconnect(); Facade::clearResolvedInstances(); Container::setInstance($this->previous); Facade::setFacadeApplication($this->previous);
    }

    public function test_final_transaction_locks_current_flags_and_absent_contact_and_suppression_keys(): void
    {
        $other = self::pdo(self::$database); $other->exec('SET LOCK_TIMEOUT 250');
        $this->db->beginTransaction();
        try {
            SmsFrozenEligibility::assertPending($this->db, self::ID);
            foreach (["UPDATE TblProspects SET Contacted=1 WHERE PK=1", "UPDATE TblProspects SET Phone='2025550199' WHERE PK=1",
                "INSERT TblPhoneNumbers VALUES('12025550100')", "INSERT TblSMSSuppressions VALUES('2025550100',1,0)"] as $sql) {
                try { $other->exec($sql); self::fail('A concurrent suppression writer escaped the final transaction locks.'); }
                catch (\PDOException $error) { self::assertSame(1222, (int) ($error->errorInfo[1] ?? 0)); }
            }
        } finally { $this->db->rollBack(); }
        $other->exec("INSERT TblPhoneNumbers VALUES('12025550100')");
        $this->expectException(SmsFrozenEligibilityHold::class);
        SmsFrozenEligibility::assertPending($this->db, self::ID);
    }

    public function test_native_guard_holds_blocked_state_phone_drift_and_flags_without_changing_membership(): void
    {
        $before = $this->db->table('TblProspectsExport')->first();
        foreach ([['State' => 'WV'], ['Phone' => '2025550199'], ['Contacted' => 1], ['Opt_Out' => 1], ['Invalid_Phone' => 1]] as $change) {
            $this->db->beginTransaction();
            try {
                $this->db->table('TblProspects')->where('PK', 1)->update($change);
                try { SmsFrozenEligibility::assertPending($this->db, self::ID); self::fail('Unsafe native frozen member was released.'); }
                catch (SmsFrozenEligibilityHold $error) { self::assertStringContainsString('held without changing membership', $error->getMessage()); }
                self::assertEquals($before, $this->db->table('TblProspectsExport')->first());
            } finally { $this->db->rollBack(); }
        }
    }

    public function test_approved_history_is_rechecked_without_rewriting_approved_evidence(): void
    {
        $this->db->table('TblSMSRuns')->insert(['Request_ID' => self::ID, 'Status' => 'approved', 'Selected' => 1,
            'Selection_Policy' => 'prospect-record-v1', 'Approved_At' => '2026-10-27 12:00:00', 'Approved_By' => 'fixture@example.invalid']);
        $this->db->table('TblSMSHistory')->insert((array) $this->db->table('TblProspectsExport')->first());
        SmsFrozenEligibility::assertApproved(self::ID);
        $before = $this->db->table('TblSMSHistory')->first();
        $this->db->table('TblSMSSuppressions')->insert(['Phone' => '2025550100', 'Opt_Out' => 0, 'Invalid_Phone' => 1]);
        try { SmsFrozenEligibility::assertApproved(self::ID); self::fail('Suppressed approved member was handed off.'); }
        catch (SmsFrozenEligibilityHold) { self::assertEquals($before, $this->db->table('TblSMSHistory')->first()); }
    }
}
