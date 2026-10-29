<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Services\SmsDebtPolicy;
use Cmd\Reports\Services\SmsFrozenEligibilityHold;
use Cmd\Reports\Services\SmsRunOptions;
use Cmd\Reports\Services\SmsTierSelectionSql;
use Cmd\Reports\Services\SmsTierWindowSelectionSql;
use Illuminate\Database\SqlServerConnection;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Opt-in, owned random database on loopback14337 only. Existing14330 is never touched. */
final class SmsDebtPolicySqlServerTest extends TestCase
{
    private static ?PDO $admin = null;

    private static string $database;

    private static string $password;

    private SqlServerConnection $db;

    private const ID = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';

    public static function setUpBeforeClass(): void
    {
        if (getenv('SMS_DEBT_MINIMUM_OWNED_NATIVE') !== '1' || ! in_array('sqlsrv', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('Explicit owned debt-minimum SQL Server fixture is required.');
        }
        self::$password = (string) getenv('SMS_DEBT_MINIMUM_OWNED_PASSWORD');
        if (strlen(self::$password) < 16) {
            throw new \RuntimeException('Owned native fixture password is absent.');
        }
        self::$database = 'sms_debt_owned_'.bin2hex(random_bytes(8));
        for ($i = 0; $i < 20; $i++) {
            try {
                self::$admin = self::pdo('master');
                break;
            } catch (\PDOException) {
                usleep(250000);
            }
        }
        if (self::$admin === null) {
            throw new \RuntimeException('Owned native SQL authentication was not ready.');
        }
        self::$admin->exec('CREATE DATABASE ['.self::$database.'] COLLATE SQL_Latin1_General_CP1_CI_AS');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$admin !== null && preg_match('/\Asms_debt_owned_[a-f0-9]{16}\z/D', self::$database)) {
            self::$admin->exec('ALTER DATABASE ['.self::$database.'] SET SINGLE_USER WITH ROLLBACK IMMEDIATE');
            self::$admin->exec('DROP DATABASE ['.self::$database.']');
            self::$admin = null;
        }
    }

    private static function pdo(string $database): PDO
    {
        return new PDO('sqlsrv:Server=127.0.0.1,14337;Database='.$database.';Encrypt=no;TrustServerCertificate=yes', 'sa', self::$password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    protected function setUp(): void
    {
        $this->db = new SqlServerConnection(self::pdo(self::$database), self::$database, '', ['driver' => 'sqlsrv']);
        self::assertSame(self::$database, $this->db->selectOne('SELECT DB_NAME() AS name')->name);
        $this->db->unprepared('DROP TABLE IF EXISTS TblProspectsExport,TblProspects,TblTUData,TblMailers,TblMarketing,TblSMSRuns;
CREATE TABLE TblProspects(PK int NOT NULL PRIMARY KEY,Phone varchar(10),Client varchar(150),Address varchar(200),City varchar(100),State char(2),Zip varchar(10),Email varchar(254),Creation_Date date,Last_SMS_Date date);
CREATE TABLE TblTUData(PK int NOT NULL PRIMARY KEY,Creation_Date date,ncoaAddr_frstNme varchar(50),ncoaAddr_lastNme varchar(75),ncoaAddr_addrLine1Txt varchar(200),ncoaAddr_zipCde varchar(10),derivedFields1_unsecdebtbalance decimal(18,2),Tier_Tag varchar(10),SMS_Zip5 AS LEFT(ncoaAddr_zipCde,5) PERSISTED);
CREATE TABLE TblMailers(PK int NOT NULL PRIMARY KEY,Client varchar(150),Address varchar(200),Zip varchar(10),Drop_Name varchar(50),Debt_Amount decimal(18,2),Debt_Tier varchar(10),SMS_Zip5 AS LEFT(Zip,5) PERSISTED);
CREATE TABLE TblMarketing(Drop_Name varchar(50),Send_Date date);
CREATE TABLE TblSMSRuns(Request_ID uniqueidentifier,Target int,Run_Options_JSON nvarchar(max));
CREATE TABLE TblProspectsExport(PK int IDENTITY NOT NULL PRIMARY KEY,Request_ID uniqueidentifier,Prospect_PK int,Phone nvarchar(20),Debt_Tier varchar(10),Debt_Amount decimal(12,2),Debt_Source varchar(30),Debt_Source_PK int,Client varchar(150),Address varchar(200),City varchar(100),State char(2),Zip varchar(10),Email varchar(254),Was_Previously_Texted bit,Last_SMS_Date_At_Selection date);
CREATE INDEX IX_TU_Identity ON TblTUData(ncoaAddr_addrLine1Txt,SMS_Zip5);
CREATE INDEX IX_Mailer_Identity ON TblMailers(Client,Address,SMS_Zip5);');
        $this->db->table('TblSMSRuns')->insert(['Request_ID' => self::ID, 'Target' => 9]);
        $this->db->table('TblMarketing')->insert(['Drop_Name' => 'SYNTHETIC', 'Send_Date' => '2026-10-01']);
        foreach (range(1, 9) as $pk) {
            $this->db->table('TblProspects')->insert(['PK' => $pk, 'Phone' => in_array($pk, [2, 6], true) ? '2025550100' : '20255501'.str_pad((string) $pk, 2, '0', STR_PAD_LEFT),
                'Client' => 'Synthetic Person', 'Address' => $pk.' Fixture Lane', 'City' => 'Fixture', 'State' => 'CA', 'Zip' => '00123',
                'Creation_Date' => '2026-10-'.str_pad((string) (31 - $pk), 2, '0', STR_PAD_LEFT), 'Last_SMS_Date' => $pk === 7 ? '2025-01-01' : null]);
        }
        foreach ([1 => '24999.99', 2 => '25000.00', 3 => '25000.01', 5 => '50000', 6 => '35000', 7 => '40000'] as $pk => $debt) {
            $this->db->table('TblTUData')->insert(['PK' => $pk, 'Creation_Date' => $pk === 5 ? null : '2026-10-01', 'ncoaAddr_frstNme' => 'Synthetic',
                'ncoaAddr_lastNme' => 'Person', 'ncoaAddr_addrLine1Txt' => $pk.' Fixture Lane', 'ncoaAddr_zipCde' => '00123-9999', 'derivedFields1_unsecdebtbalance' => $debt, 'Tier_Tag' => 'T5']);
        }
        foreach ([1 => ['85000', 'T9'], 4 => ['24999.99', 'T9'], 8 => [null, 'T5'], 9 => [null, 'T4']] as $pk => [$debt,$tier]) {
            $this->db->table('TblMailers')->insert(['PK' => 100 + $pk, 'Client' => 'Synthetic Person', 'Address' => $pk.' Fixture Lane', 'Zip' => '00123-9999',
                'Drop_Name' => 'SYNTHETIC', 'Debt_Amount' => $debt, 'Debt_Tier' => $tier]);
        }
    }

    protected function tearDown(): void
    {
        while ($this->db->transactionLevel() > 0) {
            $this->db->rollBack();
        }
        $this->db->disconnect();
    }

    public static function selectors(): array
    {
        return [['v1', false], ['windowed', false], ['windowed', true]];
    }

    private function select(string $selector, bool $lean, int $minimum, int $target): array
    {
        $this->db->table('TblProspectsExport')->delete();
        if ($selector === 'v1') {
            $sql = SmsTierSelectionSql::select('SELECT * FROM TblProspects', SmsRunOptions::TIERS, 'Send_Date', $lean);
        } else {
            $this->db->unprepared('DROP TABLE IF EXISTS #SmsTierWanted;
SELECT ROW_NUMBER() OVER(ORDER BY CASE WHEN Last_SMS_Date IS NULL THEN 0 ELSE 1 END,CASE WHEN Last_SMS_Date IS NULL THEN Creation_Date END DESC,Last_SMS_Date,PK) AS Window_Position,
PK AS Export_PK,Phone,Client,Address,City,State,Zip AS Snapshot_Zip,LEFT(Zip,5) AS Zip,Email,Creation_Date,Last_SMS_Date INTO #SmsTierWanted FROM TblProspects;');
            $sql = SmsTierWindowSelectionSql::insert(SmsRunOptions::TIERS, 'Send_Date', $lean);
        }
        $this->db->statement($sql, [$target, self::ID, ...SmsRunOptions::TIERS, SmsDebtPolicy::validate($minimum)]);

        return $this->db->table('TblProspectsExport')->orderBy('PK')->get()->all();
    }

    #[DataProvider('selectors')]
    public function test_unrounded_floor_precedes_quantity_and_preserves_record_priority_without_fallback_or_phone_dedupe(string $selector, bool $lean): void
    {
        $rows = $this->select($selector, $lean, 25000, 3);
        self::assertSame([2, 3, 6], array_map(fn ($r) => (int) $r->Prospect_PK, $rows));
        self::assertSame([25000, 26000, 35000], array_map(fn ($r) => (int) $r->Debt_Amount, $rows));
        self::assertSame(2, count(array_filter($rows, fn ($r) => $r->Phone === '2025550100')));
        $all = $this->select($selector, $lean, 25000, 9);
        self::assertSame([2, 3, 6, 8, 7], array_map(fn ($r) => (int) $r->Prospect_PK, $all));
        self::assertSame(5, count($all));
        self::assertSame(4, 9 - count($all));
        self::assertSame(4, count(array_filter($all, fn ($r) => (int) $r->Was_Previously_Texted === 0)));
        self::assertSame(1, count(array_filter($all, fn ($r) => (int) $r->Was_Previously_Texted === 1)));
        self::assertSame([6, 8, 7], array_map(fn ($r) => (int) $r->Prospect_PK, $this->select($selector, $lean, 25500, 9)));
        self::assertSame([6, 7], array_map(fn ($r) => (int) $r->Prospect_PK, $this->select($selector, $lean, 31000, 9)));
    }

    public function test_pending_old_rounded_boundary_is_held_against_its_exact_source_without_repricing_or_fallback(): void
    {
        $this->select('v1', false, 25000, 9);
        SmsDebtPolicy::assertPending($this->db, self::ID);
        $before = $this->db->table('TblProspectsExport')->get()->toJson();
        $this->db->table('TblTUData')->where('PK', 2)->update(['derivedFields1_unsecdebtbalance' => '24999.99']);
        try {
            SmsDebtPolicy::assertPending($this->db, self::ID);
            self::fail('Rounded24999.99 source passed');
        } catch (SmsFrozenEligibilityHold) {
            self::assertSame($before, $this->db->table('TblProspectsExport')->get()->toJson());
        }
        $this->db->table('TblTUData')->where('PK', 2)->update(['derivedFields1_unsecdebtbalance' => '25000.00']);
        SmsDebtPolicy::assertPending($this->db, self::ID);
        $this->db->table('TblTUData')->where('PK', 2)->delete();
        $this->expectException(SmsFrozenEligibilityHold::class);
        SmsDebtPolicy::assertPending($this->db, self::ID);
    }

    public function test_final_transaction_holds_the_exact_boundary_source_key_until_commit(): void
    {
        $this->select('v1', false, 25000, 9);
        $other = self::pdo(self::$database);
        $other->exec('SET LOCK_TIMEOUT 250');
        $this->db->beginTransaction();
        try {
            SmsDebtPolicy::assertPending($this->db, self::ID);
            try {
                $other->exec('UPDATE TblTUData SET derivedFields1_unsecdebtbalance=24999.99 WHERE PK=2');
                self::fail('Concurrent source change escaped final check');
            } catch (\PDOException $error) {
                self::assertSame(1222, (int) ($error->errorInfo[1] ?? 0));
            }
        } finally {
            $this->db->rollBack();
        }
        $other->exec('UPDATE TblTUData SET derivedFields1_unsecdebtbalance=24999.99 WHERE PK=2');
        $this->expectException(SmsFrozenEligibilityHold::class);
        SmsDebtPolicy::assertPending($this->db, self::ID);
    }

    private function replaceTuWithBoundaryOnly(bool $indexed, int $copies = 1): void
    {
        $this->db->unprepared('DROP TABLE TblTUData;
CREATE TABLE TblTUData(PK int,derivedFields1_unsecdebtbalance decimal(18,2),ncoaAddr_frstNme varchar(50),ncoaAddr_lastNme varchar(75),ncoaAddr_addrLine1Txt varchar(200),ncoaAddr_zipCde varchar(10));');
        if ($indexed) {
            $this->db->unprepared('CREATE INDEX IX_TU_Source_PK ON TblTUData(PK)');
        }
        for ($i = 0; $i < $copies; $i++) {
            $this->db->table('TblTUData')->insert(['PK' => 2, 'derivedFields1_unsecdebtbalance' => '25000.00',
                'ncoaAddr_frstNme' => 'Synthetic', 'ncoaAddr_lastNme' => 'Person', 'ncoaAddr_addrLine1Txt' => '2 Fixture Lane', 'ncoaAddr_zipCde' => '00123']);
        }
    }

    public function test_unindexed_source_heap_holds_before_any_boundary_source_data_query(): void
    {
        $this->select('v1', false, 25000, 9);
        $this->replaceTuWithBoundaryOnly(false);
        $this->db->enableQueryLog();
        try {
            SmsDebtPolicy::assertPending($this->db, self::ID);
            self::fail('Unindexed source scan was permitted');
        } catch (SmsFrozenEligibilityHold $hold) {
            self::assertStringContainsString('reviewed seekable TblTUData', $hold->getMessage());
        }
        $sql = implode("\n", array_column($this->db->getQueryLog(), 'query'));
        self::assertStringNotContainsString('TblTUData s2', $sql);
        self::assertStringNotContainsString('TblTUData s ', $sql);
        $this->db->disableQueryLog();
    }

    public function test_nonunique_pk_seek_is_accepted_but_a_second_exact_pk_version_holds(): void
    {
        $this->select('v1', false, 25000, 9);
        $this->replaceTuWithBoundaryOnly(true);
        SmsDebtPolicy::assertPending($this->db, self::ID);
        $unrelated = (array) $this->db->table('TblTUData')->first();
        $unrelated['ncoaAddr_addrLine1Txt'] = 'Unrelated source identity';
        $unrelated['derivedFields1_unsecdebtbalance'] = null;
        $this->db->table('TblTUData')->insert($unrelated);
        SmsDebtPolicy::assertPending($this->db, self::ID);
        $before = $this->db->table('TblProspectsExport')->get()->toJson();
        $this->db->table('TblTUData')->insert((array) $this->db->table('TblTUData')->where('ncoaAddr_addrLine1Txt', '2 Fixture Lane')->first());
        try {
            SmsDebtPolicy::assertPending($this->db, self::ID);
            self::fail('Ambiguous exact source PK accepted');
        } catch (SmsFrozenEligibilityHold) {
            self::assertSame($before, $this->db->table('TblProspectsExport')->get()->toJson());
        }
    }

    /** Production-shaped Unicode keys and reviewed covering index, deliberately NO PK index. */
    private function compositeSource(string $source = 'TblTUData'): void
    {
        $this->select('v1', false, 25000, 9);
        $this->db->table('TblProspectsExport')->where('Prospect_PK', '<>', 2)->delete();
        $this->db->unprepared('ALTER TABLE TblProspectsExport ALTER COLUMN Client nvarchar(150);
ALTER TABLE TblProspectsExport ALTER COLUMN Address nvarchar(200);
ALTER TABLE TblProspectsExport ALTER COLUMN Zip nvarchar(10);');
        if ($source === 'TblTUData') {
            $this->db->unprepared('DROP TABLE TblTUData;
CREATE TABLE TblTUData(PK int,Creation_Date date,ncoaAddr_frstNme varchar(100),ncoaAddr_lastNme varchar(100),
ncoaAddr_addrLine1Txt nvarchar(255),ncoaAddr_zipCde nvarchar(5),derivedFields1_unsecdebtbalance decimal(18,2),Tier_Tag varchar(10),
SMS_Zip5 AS LEFT(ncoaAddr_zipCde,5) PERSISTED);
CREATE INDEX IX_TblTUData_SMS_Identity ON TblTUData(ncoaAddr_addrLine1Txt,SMS_Zip5)
INCLUDE(PK,Creation_Date,ncoaAddr_frstNme,ncoaAddr_lastNme,derivedFields1_unsecdebtbalance,Tier_Tag);');
            $this->db->table('TblTUData')->insert(['PK' => 2, 'Creation_Date' => '2026-10-01', 'ncoaAddr_frstNme' => 'Synthetic',
                'ncoaAddr_lastNme' => 'Person', 'ncoaAddr_addrLine1Txt' => '2 Fixture Lane', 'ncoaAddr_zipCde' => '00123',
                'derivedFields1_unsecdebtbalance' => '25000', 'Tier_Tag' => 'T5']);
        } else {
            $this->db->unprepared('DROP TABLE TblMailers;
CREATE TABLE TblMailers(PK int,Client nvarchar(50),Address nvarchar(50),Zip nvarchar(5),Drop_Name varchar(50),
Debt_Amount decimal(18,2),Debt_Tier varchar(10),SMS_Zip5 AS LEFT(Zip,5) PERSISTED);
CREATE INDEX IX_TblMailers_SMS_Identity ON TblMailers(Client,Address,SMS_Zip5) INCLUDE(PK,Drop_Name,Debt_Amount,Debt_Tier);');
            $this->db->table('TblProspectsExport')->update(['Debt_Source' => 'TblMailers']);
            $this->db->table('TblMailers')->insert(['PK' => 2, 'Client' => 'Synthetic Person', 'Address' => '2 Fixture Lane',
                'Zip' => '00123', 'Debt_Amount' => '25000', 'Drop_Name' => 'SYNTHETIC', 'Debt_Tier' => 'T5']);
        }
    }

    public static function sourceKinds(): array
    {
        return [['TblTUData'], ['TblMailers']];
    }

    #[DataProvider('sourceKinds')]
    public function test_reviewed_composite_seek_proves_only_the_needed_source_without_a_pk_index(string $source): void
    {
        $this->compositeSource($source);
        $otherSource = $source === 'TblTUData' ? 'TblMailers' : 'TblTUData';
        $this->db->unprepared('DROP TABLE '.$otherSource.'; DROP TABLE TblMarketing;');
        $row = (array) $this->db->table($source)->first();
        unset($row['SMS_Zip5']);
        // Many same-PK records with other identities must not trigger lean-v3
        // uniqueness or send this v1/v2 proof through the whole PK range.
        for ($i = 0; $i < 100; $i++) {
            $row[$source === 'TblTUData' ? 'ncoaAddr_addrLine1Txt' : 'Address'] = 'Unrelated '.$i;
            $this->db->table($source)->insert($row);
        }
        $this->db->enableQueryLog();
        SmsDebtPolicy::assertPending($this->db, self::ID);
        $proofs = array_values(array_filter($this->db->getQueryLog(), fn ($q) => str_contains($q['query'], 'OUTER APPLY')));
        self::assertCount(1, $proofs);
        $query = $proofs[0];
        self::assertStringContainsString('TOP(2)', $query['query']);
        self::assertStringContainsString('FORCESEEK', $query['query']);
        self::assertStringContainsString('IX_'.$source.'_SMS_Identity', $query['query']);
        $this->db->disableQueryLog();
        // Execute the SAME bound read-only proof in the owned fixture and inspect
        // its actual plan. No row values or plan XML leave this test.
        $pdo = $this->db->getPdo();
        $pdo->exec('SET STATISTICS XML ON');
        try {
            $statement = $pdo->prepare($query['query'], [PDO::SQLSRV_ATTR_DIRECT_QUERY => true]);
            $statement->execute($query['bindings']);
            $xml = null;
            do {
                if ($statement->columnCount() > 0) {
                    while (($value = $statement->fetchColumn()) !== false) {
                        if (is_string($value) && str_contains($value, '<ShowPlanXML')) $xml = $value;
                    }
                }
            } while ($statement->nextRowset());
            $statement->closeCursor();
        } finally {
            $pdo->exec('SET STATISTICS XML OFF');
        }
        self::assertIsString($xml, 'Native actual proof plan was not returned.');
        $doc = new \DOMDocument;
        self::assertTrue($doc->loadXML($xml));
        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('p', 'http://schemas.microsoft.com/sqlserver/2004/07/showplan');
        self::assertGreaterThan(0, $xpath->query('//p:RelOp[@PhysicalOp="Index Seek"]//p:Object[@Table="['.$source.']" and @Index="[IX_'.$source.'_SMS_Identity]"]')->length);
        foreach ($source === 'TblTUData' ? ['ncoaAddr_addrLine1Txt', 'SMS_Zip5'] : ['Client', 'Address', 'SMS_Zip5'] as $key) {
            self::assertGreaterThan(0, $xpath->query('//p:IndexScan[p:Object[@Table="['.$source.']"]]/p:SeekPredicates//p:RangeColumns/p:ColumnReference[@Column="'.$key.'"]')->length, 'Every identity key must be a seek predicate.');
        }
        self::assertSame(0, $xpath->query('//p:SeekPredicates//p:Convert//p:ColumnReference[@Table="['.$source.']"]')->length);
    }

    public static function nativeDuplicates(): array
    {
        $cases = [];
        foreach (['TblTUData', 'TblMailers'] as $source) {
            foreach ([null, -1, '24999.99', '25000', SmsDebtPolicy::MAX + 1] as $debt) {
                $cases[] = [$source, $debt];
            }
        }

        return $cases;
    }

    #[DataProvider('nativeDuplicates')]
    public function test_composite_duplicate_is_held_even_when_the_second_debt_is_invalid(string $source, mixed $debt): void
    {
        $this->compositeSource($source);
        $duplicate = (array) $this->db->table($source)->first();
        unset($duplicate['SMS_Zip5']);
        $duplicate[$source === 'TblTUData' ? 'derivedFields1_unsecdebtbalance' : 'Debt_Amount'] = $debt;
        $this->db->table($source)->insert($duplicate);
        $before = $this->db->table('TblProspectsExport')->get()->toJson();
        try {
            SmsDebtPolicy::assertPending($this->db, self::ID);
            self::fail('Composite duplicate was hidden by debt filtering');
        } catch (SmsFrozenEligibilityHold) {
            self::assertSame($before, $this->db->table('TblProspectsExport')->get()->toJson());
        }
    }

    public static function brokenCompositeSchemas(): array
    {
        return [['missing'], ['disabled'], ['wrong keys'], ['missing coverage'], ['wrong computed'], ['varchar identity'], ['wrong collation'], ['session settings']];
    }

    #[DataProvider('brokenCompositeSchemas')]
    public function test_unreviewed_composite_schema_fails_before_source_data_reads(string $damage): void
    {
        $this->compositeSource();
        if ($damage === 'disabled') {
            $this->db->unprepared('ALTER INDEX IX_TblTUData_SMS_Identity ON TblTUData DISABLE');
        } elseif ($damage === 'session settings') {
            $this->db->unprepared('SET NUMERIC_ROUNDABORT ON');
        } else {
            $this->db->unprepared('DROP INDEX IX_TblTUData_SMS_Identity ON TblTUData');
            if ($damage === 'wrong keys') {
                $this->db->unprepared('CREATE INDEX IX_TblTUData_SMS_Identity ON TblTUData(SMS_Zip5,ncoaAddr_addrLine1Txt)
INCLUDE(PK,Creation_Date,ncoaAddr_frstNme,ncoaAddr_lastNme,derivedFields1_unsecdebtbalance,Tier_Tag)');
            } elseif ($damage === 'missing coverage') {
                $this->db->unprepared('CREATE INDEX IX_TblTUData_SMS_Identity ON TblTUData(ncoaAddr_addrLine1Txt,SMS_Zip5)');
            } elseif ($damage !== 'missing') {
                if ($damage === 'wrong computed') {
                    $this->db->unprepared('ALTER TABLE TblTUData DROP COLUMN SMS_Zip5; ALTER TABLE TblTUData ADD SMS_Zip5 AS RIGHT(ncoaAddr_zipCde,5) PERSISTED');
                } elseif ($damage === 'varchar identity') {
                    $this->db->unprepared('ALTER TABLE TblTUData ALTER COLUMN ncoaAddr_addrLine1Txt varchar(255)');
                } elseif ($damage === 'wrong collation') {
                    $this->db->unprepared('ALTER TABLE TblTUData ALTER COLUMN ncoaAddr_addrLine1Txt nvarchar(255) COLLATE Latin1_General_100_BIN2');
                }
                $this->db->unprepared('CREATE INDEX IX_TblTUData_SMS_Identity ON TblTUData(ncoaAddr_addrLine1Txt,SMS_Zip5)
INCLUDE(PK,Creation_Date,ncoaAddr_frstNme,ncoaAddr_lastNme,derivedFields1_unsecdebtbalance,Tier_Tag)');
            }
        }
        $this->db->enableQueryLog();
        try {
            SmsDebtPolicy::assertPending($this->db, self::ID);
            self::fail('Unreviewed source access was permitted');
        } catch (SmsFrozenEligibilityHold $hold) {
            self::assertStringContainsString('reviewed seekable TblTUData', $hold->getMessage());
        } finally {
            $this->db->unprepared('SET NUMERIC_ROUNDABORT OFF');
        }
        self::assertStringNotContainsString('FROM dbo.TblTUData s', implode("\n", array_column($this->db->getQueryLog(), 'query')));
        $this->db->disableQueryLog();
    }

    public function test_composite_seek_locks_current_debt_and_prevents_a_new_exact_identity_version(): void
    {
        $this->compositeSource();
        $other = self::pdo(self::$database);
        $other->exec('SET LOCK_TIMEOUT 250');
        $this->db->beginTransaction();
        try {
            SmsDebtPolicy::assertPending($this->db, self::ID);
            foreach (['UPDATE TblTUData SET derivedFields1_unsecdebtbalance=24999.99 WHERE PK=2',
                "INSERT TblTUData(PK,ncoaAddr_frstNme,ncoaAddr_lastNme,ncoaAddr_addrLine1Txt,ncoaAddr_zipCde,derivedFields1_unsecdebtbalance) VALUES(2,'Synthetic','Person',N'2 Fixture Lane',N'00123',NULL)"] as $sql) {
                try {
                    $other->exec($sql);
                    self::fail('Concurrent composite source change escaped the final lock');
                } catch (\PDOException $error) {
                    self::assertSame(1222, (int) ($error->errorInfo[1] ?? 0));
                }
            }
        } finally {
            $this->db->rollBack();
        }
        $other->exec('UPDATE TblTUData SET derivedFields1_unsecdebtbalance=24999.99 WHERE PK=2');
        $this->expectException(SmsFrozenEligibilityHold::class);
        SmsDebtPolicy::assertPending($this->db, self::ID);
    }

    public function test_composite_proof_never_switches_to_a_high_fallback_after_exact_source_debt_falls_or_disappears(): void
    {
        $this->compositeSource();
        $this->db->table('TblMailers')->insert(['PK' => 999, 'Client' => 'Synthetic Person', 'Address' => '2 Fixture Lane',
            'Zip' => '00123', 'Debt_Amount' => 85000, 'Drop_Name' => 'SYNTHETIC', 'Debt_Tier' => 'T9']);
        $before = $this->db->table('TblProspectsExport')->get()->toJson();
        foreach (['24999.99', null, 'missing'] as $debt) {
            if ($debt === 'missing') {
                $this->db->table('TblTUData')->delete();
            } else {
                $this->db->table('TblTUData')->update(['derivedFields1_unsecdebtbalance' => $debt]);
            }
            try {
                SmsDebtPolicy::assertPending($this->db, self::ID);
                self::fail('Missing/low exact source silently gained a high fallback');
            } catch (SmsFrozenEligibilityHold) {
                self::assertSame($before, $this->db->table('TblProspectsExport')->get()->toJson());
            }
        }
    }

    public function test_narrow_mailer_keys_cannot_truncate_frozen_identity_into_a_false_match(): void
    {
        $this->compositeSource('TblMailers');
        foreach (['Client', 'Address'] as $key) {
            $before = $this->db->table('TblProspectsExport')->value($key);
            $this->db->table('TblProspectsExport')->update([$key => str_repeat('X', 50).'tail']);
            $this->db->table('TblMailers')->update([$key => str_repeat('X', 50)]);
            try {
                SmsDebtPolicy::assertPending($this->db, self::ID);
                self::fail('Overlong frozen identity was truncated into a match');
            } catch (SmsFrozenEligibilityHold) {
                self::assertTrue(true);
            }
            $this->db->table('TblProspectsExport')->update([$key => $before]);
            $this->db->table('TblMailers')->update([$key => $before]);
        }
    }

    public function test_pk_seek_varchar_source_uses_lossless_outer_casts_and_holds_unicode_loss(): void
    {
        $this->select('v1', false, 25000, 9);
        $this->db->table('TblProspectsExport')->where('Prospect_PK', '<>', 2)->delete();
        $this->db->unprepared('ALTER TABLE TblProspectsExport ALTER COLUMN Address nvarchar(200)');
        SmsDebtPolicy::assertPending($this->db, self::ID);
        $this->db->table('TblProspectsExport')->update(['Address' => '2 漢字 Lane']);
        $this->db->table('TblTUData')->where('PK', 2)->update(['ncoaAddr_addrLine1Txt' => '2 ?? Lane']);
        $this->db->enableQueryLog();
        try {
            SmsDebtPolicy::assertPending($this->db, self::ID);
            self::fail('Code-page substitution became a false source match');
        } catch (SmsFrozenEligibilityHold) {
            self::assertStringNotContainsString('FROM dbo.TblTUData s', implode("\n", array_column($this->db->getQueryLog(), 'query')));
        }
        $this->db->disableQueryLog();
    }
}
