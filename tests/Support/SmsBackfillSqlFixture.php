<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Support;

use PDO;
use RuntimeException;
use SmsProspectCoverage;
use SmsProspectReconciliation;
use Throwable;

/** Real SQL Server only; never reads environment, application config, or a shared database. */
final class SmsBackfillSqlFixture
{
    private const DSN = 'sqlsrv:Server=127.0.0.1,14330;Encrypt=no;TrustServerCertificate=yes;MultipleActiveResultSets=false;Database=';
    private const PASSWORD = 'LocalDev!2026Pass';
    private const TABLES = ['dbo.TblProspects' => 'PK', 'dbo.TblMailersUniqueEnriched' => 'PK',
        'dbo.TblMailersUnique' => 'PK', 'dbo.TblMarketing' => 'Drop_Name,Send_Date',
        'tu_repair_20261014.Control' => 'ID', 'tu_repair_20261014.Lineage' => 'Enriched_PK',
        'dbo.TblSMSRuns' => 'PK', 'dbo.TblSMSHistory' => 'PK', 'dbo.TblSMSLegacySends' => 'Phone,Send_Date',
        'dbo.TblPhoneNumbers' => 'Phone', 'dbo.TblSMSSuppressions' => 'Phone'];

    private string $database;
    private PDO $admin;
    private bool $created = false;
    public ?PDO $db = null;

    public function __construct()
    {
        $this->database = 'sms_backfill_test_'.bin2hex(random_bytes(8));
        $this->admin = self::connect('master');
        $this->checkName();
        $this->admin->exec("CREATE DATABASE [{$this->database}] COLLATE SQL_Latin1_General_CP1_CI_AS");
        $this->created = true;
        try {
            $this->admin->exec("ALTER DATABASE [{$this->database}] SET ALLOW_SNAPSHOT_ISOLATION ON");
            $this->db = $this->connection();
            $this->db->exec(<<<'SQL'
CREATE TABLE dbo.TblProspects (
 PK int IDENTITY(1,1) NOT NULL CONSTRAINT PK_TblProspects PRIMARY KEY,
 Creation_Date date,Client varchar(150),Address varchar(200),City varchar(100),State char(2),Zip varchar(10),
 Phone varchar(10),Email varchar(254),Contacted bit NOT NULL DEFAULT 0,Response_Date date,
 Invalid_Phone bit NOT NULL DEFAULT 0,Opt_Out bit NOT NULL DEFAULT 0,Latest_Export_Date date,SMS_Attempts int NOT NULL DEFAULT 0);
CREATE INDEX IX_TblProspects_Match ON dbo.TblProspects(Client,Address,Zip);
CREATE TABLE dbo.TblMailersUniqueEnriched (
 PK int NOT NULL CONSTRAINT PK_Source PRIMARY KEY,Client nvarchar(150),Address nvarchar(255),City nvarchar(100),State nvarchar(50),
 Zip nvarchar(20),Zip5_Normalized nvarchar(5),Phone nvarchar(50),Email nvarchar(255),Drop_Name nvarchar(50),External_ID nvarchar(50),
 Latest_Export_Date date,enriched_at date,last_seen_at date);
CREATE INDEX IX_Source_Address ON dbo.TblMailersUniqueEnriched(Address);
CREATE TABLE dbo.TblMailersUnique(PK int NOT NULL CONSTRAINT PK_Unique PRIMARY KEY,Drop_Name nvarchar(50),External_ID nvarchar(50),Debt_Tier nvarchar(5));
CREATE INDEX IndexExternalID ON dbo.TblMailersUnique(External_ID);
CREATE TABLE dbo.TblMarketing(Drop_Name nvarchar(50),Send_Date date,SMS_Last_Export_Date date);
CREATE TABLE dbo.TblSMSRuns(PK int IDENTITY PRIMARY KEY,Request_ID uniqueidentifier NOT NULL UNIQUE,Status varchar(30) NOT NULL,Sent_At datetime2);
CREATE TABLE dbo.TblSMSHistory(PK bigint IDENTITY PRIMARY KEY,Request_ID uniqueidentifier NOT NULL,Phone varchar(10) NOT NULL,Prospect_PK int,Export_Date datetime2);
CREATE UNIQUE INDEX IX_History_Request_Phone ON dbo.TblSMSHistory(Request_ID,Phone);
CREATE TABLE dbo.TblSMSLegacySends(Phone varchar(10) NOT NULL,Send_Date date NOT NULL,Request_ID uniqueidentifier,
 CONSTRAINT UQ_Legacy_Phone_Date UNIQUE(Phone,Send_Date));
CREATE TABLE dbo.TblPhoneNumbers(Phone nvarchar(50) NOT NULL,Created_Date date);
CREATE INDEX IX_PhoneNumbers_Phone ON dbo.TblPhoneNumbers(Phone);
CREATE TABLE dbo.TblSMSSuppressions(Phone varchar(10) PRIMARY KEY,Opt_Out bit NOT NULL,Invalid_Phone bit NOT NULL,Updated_At datetime2);
CREATE TABLE dbo.TblSMSAvailabilitySummary(id int PRIMARY KEY,revision bigint NOT NULL);
SQL);
            $this->db->exec('CREATE SCHEMA tu_repair_20261014');
            $this->db->exec(<<<'SQL'
CREATE TABLE tu_repair_20261014.Control(ID int PRIMARY KEY,Status varchar(20),ShadowObjectID int,OriginalObjectID int,PostPublishWrites int);
CREATE TABLE tu_repair_20261014.Lineage(Enriched_PK int NOT NULL CONSTRAINT PK_Lineage PRIMARY KEY,Unique_Source_PK int NOT NULL UNIQUE);
SQL);
        } catch (Throwable $error) {
            $this->close();
            throw $error;
        }
    }

    private static function connect(string $database): PDO
    {
        if ($database !== 'master' && ! preg_match('/^sms_backfill_test_[a-f0-9]{16}$/D', $database)) {
            throw new RuntimeException('Unsafe local fixture connection target.');
        }
        $db = new PDO(self::DSN.$database, 'sa', self::PASSWORD, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::SQLSRV_ATTR_QUERY_TIMEOUT => 10]);
        $db->exec('SET NOCOUNT ON; SET LOCK_TIMEOUT 3000; SET DATEFORMAT ymd');
        return $db;
    }

    public function connection(): PDO
    {
        $this->checkName();
        return self::connect($this->database);
    }

    public function reset(): void
    {
        $this->assertOwnedDatabase();
        if ($this->db->inTransaction()) $this->db->rollBack();
        $this->db->exec(<<<'SQL'
DROP TRIGGER IF EXISTS dbo.SmsBackfillTestFailBatch;
DROP TRIGGER IF EXISTS dbo.SmsBackfillTestProspectTrigger;
DROP TRIGGER IF EXISTS dbo.TURepair20261014PostPublish;
DELETE dbo.TblSMSProspectBackfillRows; DELETE dbo.TblSMSProspectBackfillBatches; DELETE dbo.TblSMSProspectBackfillRuns;
DELETE tu_repair_20261014.Lineage; DELETE dbo.TblMailersUniqueEnriched; DELETE dbo.TblMailersUnique;
DELETE dbo.TblMarketing; DELETE dbo.TblSMSHistory; DELETE dbo.TblSMSLegacySends; DELETE dbo.TblSMSRuns;
DELETE dbo.TblPhoneNumbers; DELETE dbo.TblSMSSuppressions; DELETE dbo.TblSMSAvailabilitySummary;
DELETE tu_repair_20261014.Control; DELETE dbo.TblProspects;
SET IDENTITY_INSERT dbo.TblProspects ON;
INSERT dbo.TblProspects(PK,Client,Address,Zip,Phone,Contacted,Opt_Out,Invalid_Phone,SMS_Attempts,Latest_Export_Date,Response_Date)
 VALUES(100,'Existing sentinel','100 Unchanged St','00123','2025550199',1,1,1,9,'2026-09-30','2026-10-01');
SET IDENTITY_INSERT dbo.TblProspects OFF;
DBCC CHECKIDENT ('dbo.TblProspects',RESEED,100) WITH NO_INFOMSGS;
INSERT tu_repair_20261014.Control VALUES(1,'PUBLISHED',OBJECT_ID('dbo.TblMailersUniqueEnriched'),123,0);
INSERT dbo.TblMarketing VALUES('fixture-drop','2026-08-14','2026-10-19'),('fixture-drop','2026-09-12','2026-10-20');
INSERT dbo.TblPhoneNumbers VALUES('12025550101','2026-10-01');
INSERT dbo.TblSMSSuppressions VALUES('2025550101',1,1,'2026-10-01');
INSERT dbo.TblSMSAvailabilitySummary VALUES(1,10);
INSERT dbo.TblSMSRuns(Request_ID,Status,Sent_At) VALUES
 ('00000000-0000-0000-0000-000000000001','sent','2026-10-05T06:30:00'),
 ('00000000-0000-0000-0000-000000000002','sent','2026-10-06T07:01:00'),
 ('00000000-0000-0000-0000-000000000003','sent','2026-10-03T08:00:00'),
 ('00000000-0000-0000-0000-000000000004','discarded','2026-10-20T08:00:00');
INSERT dbo.TblSMSLegacySends VALUES
 ('2025550101','2026-09-01',NULL),
 ('2025550101','2026-10-02','00000000-0000-0000-0000-000000000001'),
 ('2025550101','2026-10-03','00000000-0000-0000-0000-000000000003'),
 ('2025550101','2026-10-20','00000000-0000-0000-0000-000000000004'),
 ('2025550101','2026-10-21','00000000-0000-0000-0000-000000000099');
INSERT dbo.TblSMSHistory(Request_ID,Phone,Prospect_PK,Export_Date) VALUES
 ('00000000-0000-0000-0000-000000000001','2025550101',201,'2026-10-01'),
 ('00000000-0000-0000-0000-000000000002','2025550101',202,'2026-10-02'),
 ('00000000-0000-0000-0000-000000000004','2025550101',204,'2026-10-20');
SQL);
        foreach (range(1, 9) as $id) {
            $source = [$id, $id === 3 ? '  Case Person  ' : 'Person '.$id, $id === 3 ? '  3 Test St  ' : $id.' Test St',
                $id === 6 ? '城市' : '  Test City  ', ' ca ', '00123-4567', $id === 3 ? null : '00123',
                match ($id) { 1 => '+1 (202) 555-0101', 2 => 'bad', 3, 4 => '202555010'.$id, 7, 8 => '2025550107', default => null },
                match ($id) { 2 => '  Mail.Only@example.test  ', 5, 6, 9 => 'person'.$id.'@example.test', default => null },
                'fixture-drop', $id === 9 ? null : 'external-'.$id, '2026-10-19', '2026-10-01', '2026-10-02'];
            if (in_array($id, [7, 8], true)) {
                $source[1] = $id === 7 ? 'Duplicate Person' : 'DUPLICATE PERSON';
                $source[2] = $id === 7 ? '7 Test St' : '7 TEST ST';
            }
            $this->rows('INSERT dbo.TblMailersUniqueEnriched VALUES('.implode(',', array_fill(0, count($source), '?')).')', $source);
            $this->rows('INSERT dbo.TblMailersUnique VALUES(?,?,?,?)', [1000 + $id, 'fixture-drop', $source[10], 'Tier2']);
            if ($id !== 5) $this->rows('INSERT tu_repair_20261014.Lineage VALUES(?,?)', [$id, 1000 + $id]);
        }
        // Install only AFTER seeding; ordinary source writes must invalidate a frozen plan.
        $this->db->exec(<<<'SQL'
CREATE TRIGGER dbo.TURepair20261014PostPublish ON dbo.TblMailersUniqueEnriched AFTER INSERT,UPDATE,DELETE AS
BEGIN SET NOCOUNT ON; UPDATE tu_repair_20261014.Control SET PostPublishWrites=1 WHERE ID=1; END
SQL);
    }

    /** Candidate hashes are the original ordered RAW SQL projection, before normalization. */
    public function plan(array $ids): array
    {
        $metadata = SmsProspectCoverage::metadata($this->db);
        $source = SmsProspectCoverage::sourceRows($this->db, 0, $metadata['source_max_pk'], $metadata['prospect_max_pk'], 1000);
        $candidates = [];
        foreach ($source as $raw) {
            if (! in_array((int) $raw['PK'], $ids, true)) continue;
            $contact = SmsProspectCoverage::normalize($raw);
            $candidates[] = ['Source_PK' => (int) $raw['PK'],
                'Observed_SHA256' => hash('sha256', SmsProspectReconciliation::json($raw)),
                'Contact_Key' => hash('sha256', SmsProspectReconciliation::json([$contact['Phone'], $contact['Email'] === null ? null : strtolower($contact['Email'])]))];
        }
        if (count($candidates) !== count($ids)) throw new RuntimeException('Fixture candidate IDs are missing.');
        return [['format' => 1, 'coverage_manifest_sha256' => hash('sha256', 'local fixture coverage manifest'),
            'coverage_state_sha256' => hash('sha256', 'local fixture completed state'),
            'source_guard' => SmsProspectCoverage::sourceGuard($metadata), 'source_watermark_pk' => $metadata['source_max_pk'],
            'prospect_watermark_pk' => $metadata['prospect_max_pk'], 'candidate_count' => count($candidates),
            'candidate_max_id' => $ids === [] ? 0 : max($ids), 'excluded_conflicts' => 0, 'excluded_repeated_nonconflicting' => 0], $candidates];
    }

    public function rows(string $sql, array $bindings = [], ?PDO $connection = null): array
    {
        $query = ($connection ?? $this->db)->prepare($sql);
        try {
            $query->execute($bindings);
            while ($query->columnCount() === 0 && $query->nextRowset()) {}
            return $query->columnCount() === 0 ? [] : $query->fetchAll(PDO::FETCH_ASSOC);
        } finally { $query->closeCursor(); }
    }

    public function snapshot(bool $includeProspects = true): array
    {
        $out = [];
        foreach (self::TABLES as $table => $order) {
            if (! $includeProspects && $table === 'dbo.TblProspects') continue;
            $out[$table] = $this->rows("SELECT * FROM {$table} ORDER BY {$order}");
        }
        return $out;
    }

    public function ledger(): array
    {
        return [$this->rows('SELECT * FROM dbo.TblSMSProspectBackfillRuns ORDER BY Run_ID'),
            $this->rows('SELECT * FROM dbo.TblSMSProspectBackfillBatches ORDER BY Run_ID,After_ID'),
            $this->rows('SELECT * FROM dbo.TblSMSProspectBackfillRows ORDER BY Run_ID,Source_PK'),
            $this->rows('SELECT * FROM dbo.TblSMSAvailabilitySummary ORDER BY id')];
    }

    private function checkName(): void
    {
        if (! preg_match('/^sms_backfill_test_[a-f0-9]{16}$/D', $this->database)) throw new RuntimeException('Unsafe fixture database name.');
    }

    public function assertOwnedDatabase(): void
    {
        $this->checkName();
        if ($this->rows('SELECT DB_NAME() AS name')[0]['name'] !== $this->database) throw new RuntimeException('Not the owned disposable database.');
    }

    public function close(): void
    {
        if (! $this->created) return;
        $this->checkName();
        if ($this->db !== null) {
            $this->assertOwnedDatabase();
            if ($this->db->inTransaction()) $this->db->rollBack();
        }
        $this->db = null;
        $this->admin->exec("ALTER DATABASE [{$this->database}] SET SINGLE_USER WITH ROLLBACK IMMEDIATE");
        $this->admin->exec("DROP DATABASE [{$this->database}]");
        $this->created = false;
    }
}
