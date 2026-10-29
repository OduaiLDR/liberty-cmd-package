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
use PHPUnit\Framework\Attributes\DataProvider;
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
CREATE TABLE TblProspectsExport(Request_ID uniqueidentifier NOT NULL,Prospect_PK int NOT NULL,State char(2),Phone nvarchar(20),Debt_Amount decimal(12,2) DEFAULT 30000,Debt_Tier varchar(10) DEFAULT \'T5\',Debt_Source varchar(30) DEFAULT \'TblTUData\',Debt_Source_PK int DEFAULT 1,Client varchar(150),Address varchar(200),Zip varchar(10),PRIMARY KEY(Request_ID,Prospect_PK));
CREATE TABLE TblSMSHistory(Request_ID uniqueidentifier NOT NULL,Prospect_PK int NOT NULL,State char(2),Phone nvarchar(20),Debt_Amount decimal(12,2) DEFAULT 30000,Debt_Tier varchar(10) DEFAULT \'T5\',Debt_Source varchar(30) DEFAULT \'TblTUData\',Debt_Source_PK int DEFAULT 1,Client varchar(150),Address varchar(200),Zip varchar(10),PRIMARY KEY(Request_ID,Prospect_PK));
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
        foreach ([['State' => 'WV'], ['Phone' => '2025550199'], ['Phone' => '2025550100 '], ['Contacted' => 1], ['Opt_Out' => 1], ['Invalid_Phone' => 1]] as $change) {
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

    public static function phoneStorageCases(): array
    {
        return [
            'legacy varchar' => ['varchar(20)', 'varchar(20)'],
            'production mixed storage' => ['varchar(20)', 'nvarchar(20)'],
            'inverse mixed storage' => ['nvarchar(20)', 'varchar(20)'],
            'unicode storage' => ['nvarchar(20)', 'nvarchar(20)'],
            'utf8 varchar storage' => ['varchar(20) COLLATE Latin1_General_100_BIN2_UTF8', 'varchar(20) COLLATE Latin1_General_100_BIN2_UTF8'],
        ];
    }

    #[DataProvider('phoneStorageCases')]
    public function test_same_ascii_phone_passes_pending_and_approved_checks_across_storage_types(string $currentType, string $frozenType): void
    {
        // Types are fixed fixture literals, never operator/customer input.
        $this->db->unprepared("ALTER TABLE TblProspects ALTER COLUMN Phone {$currentType} NULL;
ALTER TABLE TblProspectsExport ALTER COLUMN Phone {$frozenType} NULL;
ALTER TABLE TblSMSHistory ALTER COLUMN Phone {$frozenType} NULL;");
        if (str_contains($frozenType, 'UTF8')) $this->useUtf8ExclusionTables();
        $bytes = $this->db->selectOne('SELECT DATALENGTH(p.Phone) AS current_bytes,DATALENGTH(x.Phone) AS frozen_bytes
FROM TblProspectsExport x JOIN TblProspects p ON p.PK=x.Prospect_PK');
        self::assertSame(str_starts_with($currentType, 'n') ? 20 : 10, (int) $bytes->current_bytes);
        self::assertSame(str_starts_with($frozenType, 'n') ? 20 : 10, (int) $bytes->frozen_bytes);
        $pendingBefore = $this->db->table('TblProspectsExport')->first();
        SmsFrozenEligibility::assertPending($this->db, self::ID);
        self::assertEquals($pendingBefore, $this->db->table('TblProspectsExport')->first());

        $this->db->table('TblSMSRuns')->insert(['Request_ID' => self::ID, 'Status' => 'approved', 'Selected' => 1,
            'Selection_Policy' => 'prospect-record-v1', 'Approved_At' => '2026-10-27 12:00:00', 'Approved_By' => 'fixture@example.invalid']);
        $this->db->table('TblSMSHistory')->insert((array) $pendingBefore);
        $approvedBefore = $this->db->table('TblSMSHistory')->first();
        SmsFrozenEligibility::assertApproved(self::ID);
        self::assertEquals($approvedBefore, $this->db->table('TblSMSHistory')->first());
    }

    public function test_unicode_storage_still_rejects_malformed_frozen_phones_without_mutation(): void
    {
        $this->db->unprepared('ALTER TABLE TblProspects ALTER COLUMN Phone nvarchar(20) NULL');
        foreach (['202555010', '20255501000', '2025550100 ', ' 2025550100', '202555010 ',
            '202555010x', '２０２５５５０１００', '٢٠٢٥٥٥٠١٠٠', "202555010\0", "202555010\t"] as $phone) {
            $this->db->beginTransaction();
            try {
                $this->db->table('TblProspects')->where('PK', 1)->update(['Phone' => $phone]);
                $this->db->table('TblProspectsExport')->update(['Phone' => $phone]);
                $before = $this->db->table('TblProspectsExport')->first();
                try { SmsFrozenEligibility::assertPending($this->db, self::ID); self::fail('Malformed frozen phone was released.'); }
                catch (SmsFrozenEligibilityHold $error) { self::assertStringContainsString('held without changing membership', $error->getMessage()); }
                self::assertEquals($before, $this->db->table('TblProspectsExport')->first());
            } finally { $this->db->rollBack(); }
        }
    }

    public function test_utf8_phones_are_not_folded_to_ascii_before_validation_or_identity_comparison(): void
    {
        $this->db->unprepared('ALTER TABLE TblProspects ALTER COLUMN Phone varchar(40) COLLATE Latin1_General_100_BIN2_UTF8 NULL;
ALTER TABLE TblProspectsExport ALTER COLUMN Phone varchar(40) COLLATE Latin1_General_100_BIN2_UTF8 NULL;
ALTER TABLE TblSMSHistory ALTER COLUMN Phone varchar(40) COLLATE Latin1_General_100_BIN2_UTF8 NULL;');
        $this->useUtf8ExclusionTables();
        $this->db->table('TblSMSRuns')->insert(['Request_ID' => self::ID, 'Status' => 'approved', 'Selected' => 1,
            'Selection_Policy' => 'prospect-record-v1', 'Approved_At' => '2026-10-27 12:00:00', 'Approved_By' => 'fixture@example.invalid']);
        $this->db->table('TblSMSHistory')->insert((array) $this->db->table('TblProspectsExport')->first());
        SmsFrozenEligibility::assertPending($this->db, self::ID);
        SmsFrozenEligibility::assertApproved(self::ID);

        foreach (['202555010０' => 12, '２０２５５５０１００' => 30] as $phone => $utf8Bytes) {
            $this->db->beginTransaction();
            try {
                foreach (['TblProspects', 'TblProspectsExport', 'TblSMSHistory'] as $table) $this->db->table($table)->update(['Phone' => $phone]);
                self::assertSame($utf8Bytes, (int) $this->db->selectOne('SELECT DATALENGTH(Phone) AS bytes FROM TblProspectsExport')->bytes);
                $before = $this->db->table('TblProspectsExport')->first();
                foreach ([fn () => SmsFrozenEligibility::assertPending($this->db, self::ID), fn () => SmsFrozenEligibility::assertApproved(self::ID)] as $check) {
                    try { $check(); self::fail('Non-ASCII UTF8 phone was released.'); }
                    catch (SmsFrozenEligibilityHold) { self::assertEquals($before, $this->db->table('TblProspectsExport')->first()); }
                }
            } finally { $this->db->rollBack(); }
        }

        // A changed UTF8 current phone must not best-fit to the frozen ASCII phone.
        $this->db->unprepared('ALTER TABLE TblProspectsExport ALTER COLUMN Phone nvarchar(40) COLLATE Latin1_General_100_BIN2_UTF8 NULL;
ALTER TABLE TblSMSHistory ALTER COLUMN Phone nvarchar(40) COLLATE Latin1_General_100_BIN2_UTF8 NULL;');
        $this->db->table('TblProspects')->update(['Phone' => '202555010０']);
        self::assertSame(12, (int) $this->db->selectOne('SELECT DATALENGTH(Phone) AS bytes FROM TblProspects')->bytes);
        $before = $this->db->table('TblSMSHistory')->first();
        foreach ([fn () => SmsFrozenEligibility::assertPending($this->db, self::ID), fn () => SmsFrozenEligibility::assertApproved(self::ID)] as $check) {
            try { $check(); self::fail('Changed UTF8 current phone was treated as unchanged.'); }
            catch (SmsFrozenEligibilityHold) { self::assertEquals($before, $this->db->table('TblSMSHistory')->first()); }
        }
    }

    public function test_wide_set_probe_covers_every_slot_legacy_and_country_prefix_without_changing_membership(): void
    {
        config(['sms-exports.wide_phone_contacts_enabled' => true]);
        $this->db->unprepared('DROP TABLE TblPhoneNumbers;
CREATE TABLE TblPhoneNumbers(PK int IDENTITY PRIMARY KEY,Source varchar(20),CID varchar(50),Created_Date datetime2,
Data_Source nvarchar(150),External_ID nvarchar(50),Phone varchar(20),Phone1 varchar(20),Phone2 varchar(20),Phone3 varchar(20),Phone4 varchar(20));');
        $before = $this->db->table('TblProspectsExport')->first();
        foreach (['Phone','Phone1','Phone2','Phone3','Phone4'] as $slot) {
            foreach (['2025550100', '12025550100'] as $number) {
                $this->db->table('TblPhoneNumbers')->delete();
                $row = [$slot => $number];
                if (in_array($slot, ['Phone2','Phone3','Phone4'], true)) $row['Phone1'] = '9995550100';
                $this->db->table('TblPhoneNumbers')->insert($row);
                try { SmsFrozenEligibility::assertPending($this->db, self::ID); self::fail('A wide/legacy/prefixed phone escaped suppression.'); }
                catch (SmsFrozenEligibilityHold) { self::assertEquals($before, $this->db->table('TblProspectsExport')->first()); }
            }
        }
        $this->db->table('TblPhoneNumbers')->delete();
        // Legacy Phone is deliberately ignored when Phone1 is populated.
        $this->db->table('TblPhoneNumbers')->insert(['Phone' => '2025550100', 'Phone1' => '9995550100']);
        SmsFrozenEligibility::assertPending($this->db, self::ID);
        self::assertEquals($before, $this->db->table('TblProspectsExport')->first());
    }

    public function test_native_suppression_uses_hash_joins_and_checks_a_late_member_without_mutation(): void
    {
        $this->db->unprepared(";WITH n AS (SELECT TOP(6000) ROW_NUMBER() OVER(ORDER BY (SELECT NULL))+1 AS n FROM sys.all_objects a CROSS JOIN sys.all_objects b)
INSERT TblProspects SELECT n,'CA',CONVERT(varchar(20),2025550000+n),0,0,0 FROM n;");
        $this->db->insert('INSERT TblProspectsExport(Request_ID,Prospect_PK,State,Phone) SELECT ?,PK,State,Phone FROM TblProspects WHERE PK>1', [self::ID]);
        $queries = $this->db->pretend(fn () => SmsFrozenEligibility::assertPending($this->db, self::ID));
        $query = array_values(array_filter($queries, fn ($q) => str_contains($q['query'], 'OPTION (HASH JOIN)')))[0];
        $pdo = $this->db->getPdo();
        $pdo->exec('SET STATISTICS XML ON');
        $xml = '';
        try {
            $statement = $pdo->prepare($query['query']);
            $statement->execute($query['bindings']);
            do {
                if ($statement->columnCount() === 0) continue;
                foreach ($statement->fetchAll(PDO::FETCH_NUM) as $row) {
                    if (is_string($row[0] ?? null) && str_contains($row[0], 'ShowPlanXML')) $xml = $row[0];
                }
            } while ($statement->nextRowset());
            $statement->closeCursor();
        } finally { $pdo->exec('SET STATISTICS XML OFF'); }
        self::assertStringContainsString('PhysicalOp="Hash Match"', $xml);
        self::assertStringNotContainsString('PhysicalOp="Nested Loops"', $xml);
        $before = $this->db->selectOne('SELECT COUNT(*) AS n,CHECKSUM_AGG(BINARY_CHECKSUM(*)) AS digest FROM TblProspectsExport');
        $this->db->table('TblPhoneNumbers')->insert(['Phone' => '12025556001']);
        try { SmsFrozenEligibility::assertPending($this->db, self::ID); self::fail('Late member was skipped.'); }
        catch (SmsFrozenEligibilityHold) { self::assertEquals($before, $this->db->selectOne('SELECT COUNT(*) AS n,CHECKSUM_AGG(BINARY_CHECKSUM(*)) AS digest FROM TblProspectsExport')); }
    }

    private function useUtf8ExclusionTables(): void
    {
        // These owned tables are empty at this point. Keep indexed exclusion
        // columns in the frozen phone collation so the fixture isolates phone
        // validation, not an unrelated incompatible-collation schema error.
        $this->db->unprepared('DROP TABLE TblPhoneNumbers,TblSMSSuppressions;
CREATE TABLE TblPhoneNumbers(Phone varchar(40) COLLATE Latin1_General_100_BIN2_UTF8 NOT NULL PRIMARY KEY);
CREATE TABLE TblSMSSuppressions(Phone varchar(40) COLLATE Latin1_General_100_BIN2_UTF8 NOT NULL PRIMARY KEY,Opt_Out bit NOT NULL,Invalid_Phone bit NOT NULL);');
    }
}
