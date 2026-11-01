<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/** Hard-wired loopback disposable database; direct batches model SSMS SET scope. */
final class SmsEmailRecipientSetupSqlServerTest extends TestCase
{
    private static ?PDO $admin = null;
    private static string $database = '';
    private ?PDO $db = null;

    public static function setUpBeforeClass(): void
    {
        if (!in_array('sqlsrv', PDO::getAvailableDrivers(), true)) self::markTestSkipped('Loopback SQL Server PDO is required.');
        self::$database = 'sms_email_setup_test_'.bin2hex(random_bytes(8));
        self::$admin = self::connect('master');
        self::$admin->exec('CREATE DATABASE ['.self::$database.']');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$admin !== null && preg_match('/^sms_email_setup_test_[a-f0-9]{16}$/D', self::$database)) {
            self::$admin->exec('ALTER DATABASE ['.self::$database.'] SET SINGLE_USER WITH ROLLBACK IMMEDIATE');
            self::$admin->exec('DROP DATABASE ['.self::$database.']');
            self::$admin = null;
        }
    }

    private static function connect(string $database): PDO
    {
        if ($database !== 'master' && !preg_match('/^sms_email_setup_test_[a-f0-9]{16}$/D', $database)) {
            throw new \RuntimeException('Refusing a database outside the disposable SMS email fixture.');
        }
        return new PDO('sqlsrv:Server=127.0.0.1,14330;Database='.$database.';Encrypt=no;TrustServerCertificate=yes;MultipleActiveResultSets=false',
            'sa', 'LocalDev!2026Pass', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::SQLSRV_ATTR_QUERY_TIMEOUT => 10, PDO::SQLSRV_ATTR_DIRECT_QUERY => true]);
    }

    protected function setUp(): void
    {
        $this->db = self::connect(self::$database);
        self::assertSame(self::$database, $this->db->query('SELECT DB_NAME()')->fetchColumn());
        $this->db->exec('DROP TABLE IF EXISTS dbo.TblReports; DROP TABLE IF EXISTS dbo.AmbientEvidence;
            CREATE TABLE dbo.TblReports (PK int IDENTITY PRIMARY KEY, Report_Name nvarchar(50), Company nvarchar(50),
                Send_To nvarchar(1000), Send_CC nvarchar(1000), Send_BCC nvarchar(1000), Schedule nvarchar(250) NULL);
            CREATE TABLE dbo.AmbientEvidence (PK int PRIMARY KEY);
            SET LOCK_TIMEOUT 1739; SET XACT_ABORT OFF;');
        self::assertSame(1739, (int)$this->settings()['Lock_Timeout'], 'Direct session setup must persist outside its batch.');
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            $this->db->exec('IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION');
            $this->db = null;
        }
    }

    private function setupScript(bool $apply = false, ?string $company = null): string
    {
        $sql = file_get_contents(dirname(__DIR__, 2).'/database/sms-email-setup/configure-recipients.sql');
        self::assertIsString($sql);
        if ($apply) $sql = str_replace('DECLARE @Apply bit = 0;', 'DECLARE @Apply bit = 1;', $sql);
        if ($company !== null) $sql = str_replace("DECLARE @Company nvarchar(128) = N'LT';",
            "DECLARE @Company nvarchar(128) = N'".str_replace("'", "''", $company)."';", $sql);
        return $sql;
    }

    private function execute(string $sql): array
    {
        $statement = $this->db->query($sql);
        $results = [];
        do {
            if ($statement->columnCount() > 0) $results[] = $statement->fetchAll(PDO::FETCH_ASSOC);
        } while ($statement->nextRowset());
        $statement->closeCursor();
        return $results;
    }

    private function settings(): array
    {
        return $this->db->query('SELECT @@TRANCOUNT AS Transaction_Count, XACT_STATE() AS Transaction_State,
            @@LOCK_TIMEOUT AS Lock_Timeout, @@OPTIONS & 16384 AS Xact_Abort')->fetch(PDO::FETCH_ASSOC);
    }

    public function test_default_preview_does_not_insert_or_change_transaction_settings(): void
    {
        $before = $this->settings();
        $rows = $this->execute($this->setupScript());
        self::assertSame('proposed_insert', $rows[1][0]['Row_Source']);
        self::assertSame('LT', $rows[1][0]['Company']);
        self::assertSame(0, (int)$this->db->query('SELECT COUNT(*) FROM dbo.TblReports')->fetchColumn());
        self::assertSame($before, $this->settings());
    }

    public function test_explicit_apply_inserts_one_exact_recipient_row_and_restores_settings(): void
    {
        // Characterize the old false-green blind spot using direct batches: a
        // nested SET alone cannot restore a value changed in its parent scope.
        $this->db->exec("SET LOCK_TIMEOUT 5000; EXEC(N'SET LOCK_TIMEOUT 1739')");
        self::assertSame(5000, (int)$this->settings()['Lock_Timeout']);
        $this->db->exec('SET LOCK_TIMEOUT 1739');
        $this->db->exec("CREATE TRIGGER dbo.RequireBoundedSetupLock ON dbo.TblReports AFTER INSERT AS
            BEGIN IF @@LOCK_TIMEOUT <> 5000 THROW 52904, 'Setup did not apply its bounded child-scope lock timeout.', 1; END");
        $this->db->exec('SET XACT_ABORT ON');
        $before = $this->settings();
        $this->execute($this->setupScript(true, 'fixture-company'));
        $rows = $this->db->query('SELECT Report_Name, Company, Send_To, Send_CC, Send_BCC, Schedule FROM dbo.TblReports')->fetchAll(PDO::FETCH_ASSOC);
        self::assertSame([['Report_Name' => 'SMS Export', 'Company' => 'fixture-company',
            'Send_To' => 'Jude@lendingtower.com;Jeff@gozuvo.com;Audrey@gozuvo.com', 'Send_CC' => '', 'Send_BCC' => '', 'Schedule' => null]], $rows);
        self::assertSame($before, $this->settings());
    }

    public function test_confirmed_lt_default_inserts_only_after_explicit_apply(): void
    {
        $this->execute($this->setupScript(true));
        self::assertSame('LT', $this->db->query('SELECT Company FROM dbo.TblReports')->fetchColumn());
        self::assertSame(1, (int)$this->db->query('SELECT COUNT(*) FROM dbo.TblReports')->fetchColumn());
    }

    public function test_existing_report_under_any_company_refuses_without_overwriting_and_restores_lock_timeout(): void
    {
        $this->db->exec("INSERT dbo.TblReports (Report_Name,Company,Send_To) VALUES (N' SMS Export ',N'another-company',N'keep@example.invalid')");
        $before = $this->settings();
        try { $this->execute($this->setupScript(true, 'fixture-company')); self::fail('Existing SMS report must refuse.'); }
        catch (PDOException $error) { self::assertStringContainsString('already exist', $error->getMessage()); }
        self::assertSame($before, $this->settings());
        self::assertSame(['another-company', 'keep@example.invalid'], array_values($this->db->query('SELECT Company,Send_To FROM dbo.TblReports')->fetch(PDO::FETCH_ASSOC)));
        self::assertSame(1, (int)$this->db->query('SELECT COUNT(*) FROM dbo.TblReports')->fetchColumn());
    }

    public function test_ambient_transaction_and_its_writes_survive_with_xact_abort_off_and_on(): void
    {
        foreach (['OFF', 'ON'] as $setting) {
            $this->db->exec('SET XACT_ABORT '.$setting.'; BEGIN TRANSACTION; INSERT dbo.AmbientEvidence VALUES (1)');
            $before = $this->settings();
            try { $this->execute($this->setupScript(true)); self::fail('Ambient transaction must refuse before company validation.'); }
            catch (PDOException $error) { self::assertStringContainsString('outside any existing transaction', $error->getMessage()); }
            self::assertSame($before, $this->settings());
            self::assertSame(1, (int)$this->db->query('SELECT COUNT(*) FROM dbo.AmbientEvidence')->fetchColumn());
            self::assertSame(1, (int)$this->settings()['Transaction_State']);
            self::assertSame(0, (int)$this->db->query('SELECT COUNT(*) FROM dbo.TblReports')->fetchColumn());
            $this->db->exec('COMMIT TRANSACTION; DELETE dbo.AmbientEvidence');
        }
    }

    public function test_discovery_is_read_only_and_reports_schema_identity_sample_and_existing_sms_rows(): void
    {
        $this->db->exec("INSERT dbo.TblReports (Report_Name,Company,Send_To) VALUES
            (N'Other Report',N'fixture-company',N'other@example.invalid'),(N'SMS Export',N'operator-choice',N'keep@example.invalid')");
        $sql = file_get_contents(dirname(__DIR__, 2).'/database/sms-email-setup/discover-configuration.sql');
        self::assertIsString($sql);
        $before = $this->settings();
        $results = $this->execute($sql);
        self::assertSame(self::$database, $results[0][0]['Selected_Database']);
        self::assertSame('dbo', $results[0][0]['Table_Schema']);
        self::assertSame('PK', $results[1][0]['Column_Name']);
        self::assertSame(1, (int)$results[1][0]['is_identity']);
        self::assertSame(1, (int)$results[2][0]['is_primary_key']);
        self::assertCount(2, $results[3]);
        self::assertSame('SMS Export', $results[4][0]['Report_Name']);
        self::assertSame(2, (int)$this->db->query('SELECT COUNT(*) FROM dbo.TblReports')->fetchColumn());
        self::assertSame($before, $this->settings());
    }

    public function test_missing_company_choice_refuses_without_transaction_or_setting_changes(): void
    {
        $this->db->exec('SET XACT_ABORT ON; SET LOCK_TIMEOUT -1');
        $before = $this->settings();
        $sql = str_replace("DECLARE @Company nvarchar(128) = N'LT';", 'DECLARE @Company nvarchar(128) = NULL;', $this->setupScript(true));
        try { $this->execute($sql); self::fail('Company must be explicit.'); }
        catch (PDOException $error) { self::assertStringContainsString('Choose the actual', $error->getMessage()); }
        self::assertSame($before, $this->settings());
        self::assertSame(0, (int)$this->db->query('SELECT COUNT(*) FROM dbo.TblReports')->fetchColumn());
    }

    public function test_discovery_returns_diagnostics_for_missing_table_or_required_column(): void
    {
        $sql = file_get_contents(dirname(__DIR__, 2).'/database/sms-email-setup/discover-configuration.sql');
        self::assertIsString($sql);
        $this->db->exec('ALTER TABLE dbo.TblReports DROP COLUMN Schedule');
        $results = $this->execute($sql);
        self::assertStringContainsString('lacks required setup columns', $results[3][0]['Discovery_Status']);
        $this->db->exec('DROP TABLE dbo.TblReports');
        $results = $this->execute($sql);
        self::assertStringContainsString('no dbo.TblReports user table', $results[3][0]['Discovery_Status']);
    }
}
