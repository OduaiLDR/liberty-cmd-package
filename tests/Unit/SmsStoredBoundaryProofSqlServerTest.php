<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Services\SmsDebtPolicy;
use Cmd\Reports\Services\SmsFrozenEligibility;
use Cmd\Reports\Services\SmsFrozenEligibilityHold;
use Cmd\Reports\Services\SmsRunOptions;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Support\Facades\Facade;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * The debt-on-the-minimum proof from the stored debt on the prospect row (Monther, Nov 19, 2026). On Nov 18 the proof
 * re-read the mailer row of each of 380,254 people, one disk read a person, up to 850 seconds. The same number is
 * stored on the prospect row the member check reads anyway.
 * Explicitly owned, loopback-only fixture; never connects to the existing port 14330 instance.
 */
final class SmsStoredBoundaryProofSqlServerTest extends TestCase
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
        $container->instance('config', new Repository(['database' => ['default' => 'sqlsrv'], 'sms-exports' => ['stored_debt_selection' => true]]));
        $capsule = new Manager($container);
        $capsule->addConnection(['driver' => 'sqlsrv', 'host' => '127.0.0.1,14337', 'database' => self::$database,
            'username' => 'sa', 'password' => self::$password, 'encrypt' => 'no', 'trust_server_certificate' => 'yes'], 'sqlsrv');
        $container->instance('db', $capsule->getDatabaseManager());
        Facade::clearResolvedInstances(); Facade::setFacadeApplication($container); $this->db = $capsule->getConnection('sqlsrv');
        self::assertSame(self::$database, $this->db->selectOne('SELECT DB_NAME() AS name')->name);
        $this->db->unprepared('DROP TABLE IF EXISTS TblProspectsExport,TblProspects,TblPhoneNumbers,TblSMSSuppressions,TblSMSRuns,TblMailers,TblTUData;
CREATE TABLE TblProspects(PK int NOT NULL PRIMARY KEY,State char(2),Phone varchar(20),Contacted bit NOT NULL,Opt_Out bit NOT NULL,Invalid_Phone bit NOT NULL,
    Current_Debt decimal(18,2) NULL,Current_Tier char(2) NULL,Current_Debt_Source varchar(10) NULL,Current_Debt_Source_PK int NULL);
CREATE TABLE TblProspectsExport(Request_ID uniqueidentifier NOT NULL,Prospect_PK int NOT NULL,State char(2),Phone nvarchar(20),Debt_Amount decimal(12,2),Debt_Tier varchar(10) DEFAULT \'T5\',
    Debt_Source varchar(30),Debt_Source_PK int,Client varchar(150),Address varchar(200),Zip varchar(10));
CREATE TABLE TblPhoneNumbers(Phone varchar(20) NOT NULL PRIMARY KEY);
CREATE TABLE TblSMSSuppressions(Phone varchar(20) NOT NULL PRIMARY KEY,Opt_Out bit NOT NULL,Invalid_Phone bit NOT NULL);
CREATE TABLE TblSMSRuns(Request_ID uniqueidentifier NOT NULL PRIMARY KEY,Target int,Run_Options_JSON nvarchar(max));
CREATE TABLE TblMailers(PK int NOT NULL PRIMARY KEY,Client varchar(150),Address varchar(200),Zip varchar(10),Drop_Name varchar(50),Debt_Amount decimal(18,2),Debt_Tier varchar(10),SMS_Zip5 AS LEFT(Zip,5) PERSISTED);
CREATE TABLE TblTUData(PK int NOT NULL PRIMARY KEY,ncoaAddr_frstNme varchar(50),ncoaAddr_lastNme varchar(75),ncoaAddr_addrLine1Txt varchar(200),ncoaAddr_zipCde varchar(10),derivedFields1_unsecdebtbalance decimal(18,2),SMS_Zip5 AS LEFT(ncoaAddr_zipCde,5) PERSISTED);');
        // A debt-range run with a 25,000 minimum: person 1 sits exactly on it, person 2 is above it.
        $this->db->table('TblSMSRuns')->insert(['Request_ID' => self::ID, 'Target' => 2, 'Run_Options_JSON' => json_encode(
            SmsRunOptions::normalize(2, 2, SmsRunOptions::TIERS, SmsRunOptions::TIER_SELECTION_POLICY, null, null, null, 25000), JSON_THROW_ON_ERROR)]);
        foreach ([1 => ['25000.00', '25000.00'], 2 => ['25400.00', '26000.00']] as $pk => [$debt, $frozen]) {
            $this->db->table('TblMailers')->insert(['PK' => 100 + $pk, 'Client' => 'Synthetic Person', 'Address' => $pk.' Fixture Lane', 'Zip' => '00123-9999',
                'Drop_Name' => 'SYNTHETIC', 'Debt_Amount' => $debt, 'Debt_Tier' => 'T5']);
            $this->db->table('TblProspects')->insert(['PK' => $pk, 'State' => 'CA', 'Phone' => '202555010'.$pk, 'Contacted' => 0, 'Opt_Out' => 0, 'Invalid_Phone' => 0,
                'Current_Debt' => $debt, 'Current_Tier' => 'T5', 'Current_Debt_Source' => 'TblMailers', 'Current_Debt_Source_PK' => 100 + $pk]);
            $this->db->table('TblProspectsExport')->insert(['Request_ID' => self::ID, 'Prospect_PK' => $pk, 'State' => 'CA', 'Phone' => '202555010'.$pk,
                'Debt_Amount' => $frozen, 'Debt_Source' => 'TblMailers', 'Debt_Source_PK' => 100 + $pk, 'Client' => 'Synthetic Person', 'Address' => $pk.' Fixture Lane', 'Zip' => '00123']);
        }
    }

    protected function tearDown(): void
    {
        while ($this->db->transactionLevel() > 0) $this->db->rollBack();
        $this->db->disconnect(); Facade::clearResolvedInstances(); Container::setInstance($this->previous); Facade::setFacadeApplication($this->previous);
    }

    /** @return list<string> the statements that read a source row for the proof */
    private function sourceReads(callable $work): array
    {
        $this->db->flushQueryLog(); $this->db->enableQueryLog();
        try { $work(); } finally { $this->db->disableQueryLog(); }

        return array_values(array_filter(array_column($this->db->getQueryLog(), 'query'), static fn (string $sql): bool => str_contains($sql, 'OUTER APPLY')));
    }

    private function check(): void
    {
        SmsFrozenEligibility::assertPending($this->db, self::ID);
    }

    public function test_everyone_on_the_minimum_is_proven_by_the_stored_debt_and_no_source_row_is_read(): void
    {
        $proof = SmsDebtPolicy::storedBoundary($this->db, self::ID);
        self::assertSame([25000, 25000, SmsDebtPolicy::MAX], $proof['bindings']);
        self::assertSame([], $this->sourceReads(fn () => $this->check()));
        // The proof rides on the member pass: one statement over the batch and its prospect rows carries it.
        $this->db->flushQueryLog(); $this->db->enableQueryLog();
        $this->check();
        $this->db->disableQueryLog();
        $passes = array_values(array_filter(array_column($this->db->getQueryLog(), 'query'), static fn (string $sql): bool => str_contains($sql, '[TblProspects] as [p]')));
        self::assertCount(1, $passes, 'State, flags and the debt proof are read in one pass over the prospect rows');
        self::assertStringContainsString('p.Current_Debt_Source_PK=x.Debt_Source_PK', $passes[0]);
        // The same inside the approval transaction, where the prospect rows are locked first.
        self::assertSame([], $this->sourceReads(fn () => $this->db->transaction(fn () => $this->check())));
        // And in the rebuild after held people were taken out, which has no member pass of its own.
        self::assertSame([], $this->sourceReads(fn () => SmsDebtPolicy::assertPendingFromStored($this->db, self::ID)));
    }

    public function test_a_stored_value_that_moved_on_sends_only_that_person_to_the_source_row(): void
    {
        // A newer TU row became person 1's debt after the batch was frozen: the stored value no longer names the frozen source row.
        $this->db->table('TblProspects')->where('PK', 1)->update(['Current_Debt' => '61000.00', 'Current_Debt_Source' => 'TblTUData', 'Current_Debt_Source_PK' => 7]);
        $reads = $this->sourceReads(fn () => $this->check());
        self::assertCount(1, $reads, 'the mailer rows are read once, for the people the stored value does not prove');
        self::assertStringContainsString('LEFT JOIN dbo.TblProspects p ON p.PK=x.Prospect_PK', $reads[0]);
        self::assertStringContainsString('dbo.TblMailers', $reads[0]);
        // The frozen source row itself still proves 25,000, so the batch passes. Lower it and the batch is held.
        $this->db->table('TblMailers')->where('PK', 101)->update(['Debt_Amount' => '24999.99']);
        try {
            $this->check();
            self::fail('A source row below the minimum must hold the batch.');
        } catch (SmsFrozenEligibilityHold $held) {
            self::assertStringContainsString('cannot prove the required unrounded source-debt minimum', $held->getMessage());
        }
        // Person 2 is not on the minimum: a moved stored value there asks for no proof at all.
        $this->db->table('TblMailers')->where('PK', 101)->update(['Debt_Amount' => '25000.00']);
        $this->db->table('TblProspects')->where('PK', 1)->update(['Current_Debt' => '25000.00', 'Current_Debt_Source' => 'TblMailers', 'Current_Debt_Source_PK' => 101]);
        $this->db->table('TblProspects')->where('PK', 2)->update(['Current_Debt' => null, 'Current_Debt_Source' => null, 'Current_Debt_Source_PK' => null]);
        self::assertSame([], $this->sourceReads(fn () => $this->check()));
    }

    public function test_a_stored_debt_that_does_not_itself_prove_the_minimum_is_never_taken_on_trust(): void
    {
        foreach ([['Current_Debt' => '24999.99'], ['Current_Debt' => null], ['Current_Debt' => '25000.01'], ['Current_Debt_Source_PK' => 102],
            ['Current_Debt_Source' => 'Tier'], ['Current_Debt_Source' => 'None', 'Current_Debt' => null, 'Current_Debt_Source_PK' => null]] as $change) {
            $this->db->table('TblProspects')->where('PK', 1)->update(['Current_Debt' => '25000.00', 'Current_Debt_Source' => 'TblMailers', 'Current_Debt_Source_PK' => 101]);
            self::assertSame([], $this->sourceReads(fn () => $this->check()));
            $this->db->table('TblProspects')->where('PK', 1)->update($change);
            self::assertCount(1, $this->sourceReads(fn () => $this->check()), json_encode($change).': the source row decides, and here it still proves 25,000');
        }
        // A prospect row that is gone is a member problem, found before any debt question.
        $this->db->table('TblProspects')->where('PK', 1)->delete();
        $this->expectException(SmsFrozenEligibilityHold::class);
        $this->check();
    }

    public function test_switched_off_or_without_stored_selection_every_source_row_is_read_as_before(): void
    {
        foreach ([['sms-exports.stored_debt_boundary_proof' => false], ['sms-exports.stored_debt_selection' => false]] as $setting) {
            config(['sms-exports.stored_debt_boundary_proof' => true, 'sms-exports.stored_debt_selection' => true]);
            config($setting);
            self::assertNull(SmsDebtPolicy::storedBoundary($this->db, self::ID));
            $reads = $this->sourceReads(fn () => $this->check());
            self::assertCount(1, $reads);
            self::assertStringNotContainsString('TblProspects p', $reads[0], 'the old statement, for everyone on the minimum');
            $this->db->table('TblMailers')->where('PK', 101)->update(['Debt_Amount' => '24999.99']);
            try {
                $this->check();
                self::fail('The source re-read must hold a source below the minimum.');
            } catch (SmsFrozenEligibilityHold) {
            }
            $this->db->table('TblMailers')->where('PK', 101)->update(['Debt_Amount' => '25000.00']);
        }
    }

    public function test_without_the_stored_columns_the_source_rows_are_read_as_before(): void
    {
        $this->db->unprepared('ALTER TABLE TblProspects DROP COLUMN Current_Debt, Current_Tier, Current_Debt_Source, Current_Debt_Source_PK');
        self::assertNull(SmsDebtPolicy::storedBoundary($this->db, self::ID));
        self::assertCount(1, $this->sourceReads(fn () => $this->check()));
    }

    public function test_the_rebuild_after_a_removal_holds_on_a_person_neither_the_stored_debt_nor_the_source_proves(): void
    {
        $this->db->table('TblProspects')->where('PK', 1)->update(['Current_Debt' => '24999.99']);
        $this->db->table('TblMailers')->where('PK', 101)->update(['Debt_Amount' => '24999.99']);
        $this->expectException(SmsFrozenEligibilityHold::class);
        SmsDebtPolicy::assertPendingFromStored($this->db, self::ID);
    }
}
