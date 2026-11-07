<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Repositories\SmsRecordExportRepository;
use Cmd\Reports\Services\SmsCurrentDebtSql;
use Cmd\Reports\Services\SmsRunOptions;
use Cmd\Reports\Services\SmsStoredDebt;
use Cmd\Reports\Services\SmsStoredDebtRefresh;
use Cmd\Reports\Services\SmsStoredDebtWorker;
use Cmd\Reports\Services\SmsTierWindowSelectionSql;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Facade;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Real SQL Server. The stored value must be exactly what the live rule says, in every case the rule has. */
final class SmsStoredDebtSqlServerTest extends TestCase
{
    private static string $database = 'SmsStoredDebtFixture';

    private static ?PDO $admin = null;

    private ConnectionInterface $db;

    private Container $previous;

    private string $statePath;

    private int $clock = 1794038400; // Midday UTC, outside the nightly jobs.

    private array $slept = [];

    public static function setUpBeforeClass(): void
    {
        if (getenv('SMS_RECORD_NATIVE_ACCEPT_OWNED_FIXTURE') !== '1' || ! in_array('sqlsrv', PDO::getAvailableDrivers(), true)) return;
        self::$admin = new PDO('sqlsrv:Server=127.0.0.1,14337;Database=master;Encrypt=no;TrustServerCertificate=yes', 'sa',
            (string) getenv('SMS_RECORD_NATIVE_OWNED_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        self::dropDatabase();
        self::$admin->exec('CREATE DATABASE ['.self::$database.'] COLLATE SQL_Latin1_General_CP1_CI_AS');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$admin !== null) self::dropDatabase();
    }

    private static function dropDatabase(): void
    {
        if (self::$admin->query("SELECT DB_ID('".self::$database."')")->fetchColumn() !== null) {
            self::$admin->exec('ALTER DATABASE ['.self::$database.'] SET SINGLE_USER WITH ROLLBACK IMMEDIATE; DROP DATABASE ['.self::$database.']');
        }
    }

    protected function setUp(): void
    {
        if (self::$admin === null) $this->markTestSkipped('Needs the owned local SQL Server fixture.');
        $this->previous = Container::getInstance();
        $app = new Container;
        Container::setInstance($app);
        $app->instance('config', new Repository(['sms-exports' => []]));
        $this->statePath = sys_get_temp_dir().'/sms-stored-debt-test-'.bin2hex(random_bytes(6)).'/state.json';
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
        $capsule = new Manager($app);
        $capsule->addConnection(['driver' => 'sqlsrv', 'host' => '127.0.0.1', 'port' => '14337', 'database' => self::$database,
            'username' => 'sa', 'password' => (string) getenv('SMS_RECORD_NATIVE_OWNED_PASSWORD'), 'trust_server_certificate' => true, 'encrypt' => 'no'], 'sqlsrv');
        $this->db = $capsule->getConnection('sqlsrv');
        $this->db->unprepared(<<<'SQL'
IF OBJECT_ID('dbo.StoredDebtBreak','TR') IS NOT NULL DROP TRIGGER dbo.StoredDebtBreak;
DROP TABLE IF EXISTS dbo.TblProspects; DROP TABLE IF EXISTS dbo.TblTUData; DROP TABLE IF EXISTS dbo.TblMailers; DROP TABLE IF EXISTS dbo.TblMarketing;
DROP TABLE IF EXISTS dbo.TblSMSRuns; DROP TABLE IF EXISTS dbo.TblTUPipelineOwner; DROP TABLE IF EXISTS dbo.StoredDebtFlag;
CREATE TABLE dbo.TblSMSRuns (PK int IDENTITY(1,1) PRIMARY KEY, Status varchar(30) NOT NULL);
CREATE TABLE dbo.TblTUPipelineOwner (Stage_ID int PRIMARY KEY, Batch_ID uniqueidentifier NULL);
INSERT dbo.TblTUPipelineOwner VALUES (1, NULL);
CREATE TABLE dbo.StoredDebtFlag (N int NOT NULL);
CREATE TABLE dbo.TblProspects (PK int IDENTITY(1,1) NOT NULL PRIMARY KEY, Client varchar(150) NULL, Address varchar(200) NULL, Zip varchar(10) NULL,
    Phone varchar(10) NULL, Contacted bit NOT NULL DEFAULT 0, Opt_Out bit NOT NULL DEFAULT 0, Invalid_Phone bit NOT NULL DEFAULT 0, Last_SMS_Date date NULL,
    Current_Debt decimal(18,2) NULL, Current_Tier char(2) NULL, Current_Debt_Source varchar(10) NULL, Current_Debt_Source_PK int NULL);
CREATE TABLE dbo.TblTUData (PK int IDENTITY(1,1) NOT NULL PRIMARY KEY, Creation_Date date NULL, ncoaAddr_frstNme varchar(50) NULL, ncoaAddr_lastNme varchar(50) NULL,
    ncoaAddr_addrLine1Txt varchar(200) NULL, ncoaAddr_zipCde varchar(10) NULL, SMS_Zip5 AS LEFT(ncoaAddr_zipCde,5) PERSISTED,
    derivedFields1_unsecdebtbalance decimal(18,2) NULL, Tier_Tag varchar(30) NULL);
CREATE TABLE dbo.TblMailers (PK int IDENTITY(1,1) NOT NULL PRIMARY KEY, Client varchar(150) NULL, Address varchar(200) NULL, Zip varchar(10) NULL,
    SMS_Zip5 AS LEFT(Zip,5) PERSISTED, Drop_Name varchar(30) NULL, Debt_Amount decimal(10,2) NULL, Debt_Tier nvarchar(5) NULL);
CREATE TABLE dbo.TblMarketing (PK int IDENTITY(1,1) NOT NULL PRIMARY KEY, Drop_Name varchar(30) NULL, Send_Date date NULL);
SQL);
    }

    protected function tearDown(): void
    {
        if (isset($this->statePath) && is_file($this->statePath)) {
            unlink($this->statePath);
            rmdir(dirname($this->statePath));
        }
        if (isset($this->previous)) Container::setInstance($this->previous);
        Facade::clearResolvedInstances();
    }

    private function person(string $client = 'Ann Lee', string $address = '1 Main St', string $zip = '90210-1234'): int
    {
        return (int) $this->db->table('TblProspects')->insertGetId(['Client' => $client, 'Address' => $address, 'Zip' => $zip, 'Phone' => '5550000000'], 'PK');
    }

    private function tu(?string $debt, ?string $tier, string $date = '2026-11-01', string $first = 'Ann', string $last = 'Lee', string $address = '1 Main St', string $zip = '90210-9999'): int
    {
        return (int) $this->db->table('TblTUData')->insertGetId(['Creation_Date' => $date, 'ncoaAddr_frstNme' => $first, 'ncoaAddr_lastNme' => $last,
            'ncoaAddr_addrLine1Txt' => $address, 'ncoaAddr_zipCde' => $zip, 'derivedFields1_unsecdebtbalance' => $debt, 'Tier_Tag' => $tier], 'PK');
    }

    private function mailer(?string $debt, ?string $tier, string $drop = 'D1', ?string $send = '2026-10-01', string $client = 'Ann Lee', string $address = '1 Main St', string $zip = '90210'): int
    {
        if ($send !== null && ! $this->db->table('TblMarketing')->where('Drop_Name', $drop)->exists()) {
            $this->db->table('TblMarketing')->insert(['Drop_Name' => $drop, 'Send_Date' => $send]);
        }

        return (int) $this->db->table('TblMailers')->insertGetId(['Client' => $client, 'Address' => $address, 'Zip' => $zip, 'Drop_Name' => $drop, 'Debt_Amount' => $debt, 'Debt_Tier' => $tier], 'PK');
    }

    private function stored(int $pk): array
    {
        $row = $this->db->table('TblProspects')->where('PK', $pk)->first(SmsStoredDebt::COLUMNS);

        return [$row->Current_Debt === null ? null : (string) $row->Current_Debt, $row->Current_Tier, $row->Current_Debt_Source, $row->Current_Debt_Source_PK === null ? null : (int) $row->Current_Debt_Source_PK];
    }

    private function refresh(int ...$pks): array
    {
        return (new SmsStoredDebtRefresh($this->db))->refresh($pks);
    }

    public function test_a_tu_row_wins_and_keeps_the_exact_raw_debt_and_source_row(): void
    {
        $pk = $this->person();
        $this->mailer('40000.00', 'T6');
        $tu = $this->tu('26000.49', 'Tier5');
        $this->assertSame(['people' => 1, 'written' => 1, 'resolved' => 1, 'no_value' => 0], $this->refresh($pk));
        $this->assertSame(['26000.49', 'T5', 'TblTUData', $tu], $this->stored($pk));
    }

    public function test_with_no_tu_row_the_newest_mailer_drop_wins(): void
    {
        $pk = $this->person();
        $this->mailer('30000.00', 'T5', 'OLD', '2026-01-01');
        $new = $this->mailer('42000.00', 'T6', 'NEW', '2026-09-01');
        $this->refresh($pk);
        $this->assertSame(['42000.00', 'T6', 'TblMailers', $new], $this->stored($pk));
    }

    public function test_a_zero_mailer_falls_to_the_fixed_tier_amount_and_keeps_no_source_row(): void
    {
        $pk = $this->person();
        $this->mailer('0.00', 'T5');
        $this->refresh($pk);
        $this->assertSame([(string) SmsCurrentDebtSql::TIER_AMOUNTS['T5'].'.00', 'T5', 'Tier', null], $this->stored($pk));
    }

    public function test_every_person_the_rule_holds_gets_no_value_so_a_run_can_never_pick_them(): void
    {
        $zero = $this->person('Zero Tu', '2 Main St');
        $this->tu('0.00', 'T5', first: 'Zero', last: 'Tu', address: '2 Main St');
        $this->mailer('50000.00', 'T7', client: 'Zero Tu', address: '2 Main St');
        $conflict = $this->person('Two Rows', '3 Main St');
        $this->tu('30000.00', 'T5', '2026-11-01', 'Two', 'Rows', '3 Main St');
        $this->tu('31000.00', 'T5', '2026-11-01', 'Two', 'Rows', '3 Main St');
        $noTier = $this->person('No Tier', '4 Main St');
        $this->tu('30000.00', 'junk', first: 'No', last: 'Tier', address: '4 Main St');
        $noSource = $this->person('Nobody Here', '5 Main St');
        $undated = $this->person('Undated Drop', '6 Main St');
        $this->mailer('30000.00', 'T5', 'NODATE', null, 'Undated Drop', '6 Main St');
        $this->refresh($zero, $conflict, $noTier, $noSource, $undated);
        foreach ([$zero, $conflict, $noTier, $noSource, $undated] as $pk) {
            $this->assertSame([null, null, 'None', null], $this->stored($pk), "prospect {$pk}");
        }
    }

    public function test_a_new_tu_row_replaces_a_mailer_value_but_a_new_mailer_changes_nothing_until_refreshed(): void
    {
        $pk = $this->person();
        $old = $this->mailer('30000.00', 'T5', 'OLD', '2026-01-01');
        $this->refresh($pk);
        $this->assertSame(['30000.00', 'T5', 'TblMailers', $old], $this->stored($pk));
        $this->mailer('90000.00', 'T9', 'NEW', '2026-09-01');
        $this->assertSame(['30000.00', 'T5', 'TblMailers', $old], $this->stored($pk), 'a newer mailer alone never moves a stored value');
        $tu = $this->tu('27000.00', 'T5');
        $this->refresh($pk);
        $this->assertSame(['27000.00', 'T5', 'TblTUData', $tu], $this->stored($pk));
    }

    public function test_a_person_with_a_stored_value_goes_back_to_no_value_when_the_rule_holds_them(): void
    {
        $pk = $this->person();
        $this->tu('30000.00', 'T5', '2026-01-01');
        $this->refresh($pk);
        $this->tu('0.00', 'T5', '2026-11-01');
        $this->refresh($pk);
        $this->assertSame([null, null, 'None', null], $this->stored($pk));
    }

    public function test_the_name_match_is_the_rules_own_so_a_middle_name_still_matches(): void
    {
        $pk = $this->person('Ann Marie Lee');
        $tu = $this->tu('33000.00', 'T5');
        $this->refresh($pk);
        $this->assertSame(['33000.00', 'T5', 'TblTUData', $tu], $this->stored($pk));
        $other = $this->person('Ann Leeson', '9 Other St');
        $this->tu('44000.00', 'T6', first: 'Ann', last: 'Lee', address: '9 Other St');
        $this->refresh($other);
        $this->assertSame([null, null, 'None', null], $this->stored($other), 'a longer surname is a different person');
    }

    public function test_running_twice_writes_nothing_the_second_time(): void
    {
        $pk = $this->person();
        $this->tu('30000.00', 'T5');
        $this->assertSame(1, $this->refresh($pk)['written']);
        $this->db->unprepared("CREATE TRIGGER dbo.NoSecondWrite ON dbo.TblProspects AFTER UPDATE AS BEGIN SET NOCOUNT ON; IF EXISTS(SELECT 1 FROM inserted) THROW 52999,'Second write.',1; END");
        try {
            $this->assertSame(0, $this->refresh($pk)['written']);
        } finally {
            $this->db->unprepared('DROP TRIGGER dbo.NoSecondWrite');
        }
    }

    public function test_it_touches_only_the_stored_columns_and_only_the_people_it_was_given(): void
    {
        $pk = $this->person();
        $this->db->table('TblProspects')->where('PK', $pk)->update(['Contacted' => 1, 'Last_SMS_Date' => '2026-05-05']);
        $other = $this->person('Ann Lee', '1 Main St');
        $this->tu('30000.00', 'T5');
        $this->refresh($pk);
        $row = $this->db->table('TblProspects')->where('PK', $pk)->first();
        $this->assertSame(1, (int) $row->Contacted);
        $this->assertStringStartsWith('2026-05-05', (string) $row->Last_SMS_Date);
        $this->assertSame([null, null, null, null], $this->stored($other));
    }

    public function test_more_people_than_one_write_page_all_land_once(): void
    {
        $count = SmsStoredDebtRefresh::WRITE_PAGE + 3;
        $rows = [];
        for ($i = 1; $i <= $count; $i++) $rows[] = ['Client' => "Ann Lee{$i}", 'Address' => "{$i} Main St", 'Zip' => '90210', 'Phone' => '5550000000'];
        foreach (array_chunk($rows, 200) as $page) $this->db->table('TblProspects')->insert($page);
        $this->db->unprepared("INSERT dbo.TblTUData (Creation_Date,ncoaAddr_frstNme,ncoaAddr_lastNme,ncoaAddr_addrLine1Txt,ncoaAddr_zipCde,derivedFields1_unsecdebtbalance,Tier_Tag)
            SELECT '2026-11-01','Ann',CONCAT('Lee',p.PK),CONCAT(p.PK,' Main St'),'90210',30000,'T5' FROM dbo.TblProspects p");
        $pks = array_map('intval', $this->db->table('TblProspects')->pluck('PK')->all());
        $result = $this->refresh(...array_reverse($pks), ...array_slice($pks, 0, 5));
        $this->assertSame($count, $result['people']);
        $this->assertSame($count, $result['written']);
        $this->assertSame($count, $this->db->table('TblProspects')->where('Current_Debt_Source', 'TblTUData')->count());
    }

    public function test_the_comparison_counts_each_kind_of_difference_and_writes_nothing(): void
    {
        $same = $this->person();
        $this->tu('30000.00', 'T5');
        $newerMailerPerson = $this->person('Bob Ray', '7 Main St');
        $this->mailer('30000.00', 'T5', 'OLD', '2026-01-01', 'Bob Ray', '7 Main St');
        $this->refresh($same, $newerMailerPerson);
        $this->mailer('90000.00', 'T9', 'NEW', '2026-09-01', 'Bob Ray', '7 Main St');
        $blank = $this->person('Cal Fox', '8 Main St');
        $result = (new SmsStoredDebtRefresh($this->db))->compare([$same, $newerMailerPerson, $blank]);
        $this->assertSame(['people' => 3, 'never_filled' => 1, 'debt_differs' => 1, 'tier_differs' => 1, 'source_differs' => 1, 'same' => 1], $result);
        $this->assertSame([null, null, null, null], $this->stored($blank));
    }

    private function worker(): SmsStoredDebtWorker
    {
        return new SmsStoredDebtWorker($this->db, $this->statePath, null, function (int $seconds): void {
            $this->slept[] = $seconds;
            $this->clock += $seconds;
        }, fn (): int => $this->clock);
    }

    private function eligiblePerson(int $i, ?string $texted = null): int
    {
        $pk = $this->person("Ann Lee{$i}", "{$i} Main St");
        if ($texted !== null) $this->db->table('TblProspects')->where('PK', $pk)->update(['Last_SMS_Date' => $texted]);
        $this->tu('30000.00', 'T5', first: 'Ann', last: "Lee{$i}", address: "{$i} Main St");

        return $pk;
    }

    public function test_the_back_fill_fills_one_scope_stops_at_its_limit_and_picks_up_where_it_stopped(): void
    {
        $never = [$this->eligiblePerson(1), $this->eligiblePerson(2), $this->eligiblePerson(3)];
        $texted = $this->eligiblePerson(4, '2026-01-01');
        $contact = $this->eligiblePerson(5);
        $this->db->table('TblProspects')->where('PK', $contact)->update(['Contacted' => 1]);
        $noPhone = $this->eligiblePerson(6);
        $this->db->table('TblProspects')->where('PK', $noPhone)->update(['Phone' => null]);

        $first = $this->worker()->backfill('never', 2, 2);
        $this->assertSame(['people' => 2, 'finished' => false, 'stopped' => 'limit', 'cursor' => $never[1]], array_intersect_key($first, array_flip(['people', 'finished', 'stopped', 'cursor'])));
        $this->assertSame([null, null, null, null], $this->stored($never[2]));
        $second = $this->worker()->backfill('never', 2);
        $this->assertSame(1, $second['people']);
        $this->assertTrue($second['finished']);
        $this->assertSame('TblTUData', $this->stored($never[2])[2]);
        foreach ([$texted, $contact, $noPhone] as $pk) $this->assertSame([null, null, null, null], $this->stored($pk));
        $this->assertSame(1, $this->worker()->backfill('previous')['people']);
        $this->assertSame('TblTUData', $this->stored($texted)[2]);
    }

    public function test_a_back_fill_that_finds_nobody_left_finishes_cleanly_and_a_rerun_writes_nothing(): void
    {
        $this->eligiblePerson(1);
        $this->worker()->backfill('never');
        $again = $this->worker()->backfill('never');
        $this->assertSame([0, 0, true], [$again['people'], $again['written'], $again['finished']]);
    }

    public function test_it_waits_for_a_run_an_import_and_the_nightly_jobs_and_writes_nothing_while_it_does(): void
    {
        $pk = $this->eligiblePerson(1);
        $this->db->table('TblSMSRuns')->insert(['Status' => 'selecting']);
        $this->assertSame('an SMS run is working', $this->worker()->pauseReason());
        $result = $this->worker()->backfill('never', 2, 0, 2);
        $this->assertSame('time limit', $result['stopped']);
        $this->assertSame(0, $result['people']);
        $this->assertNotEmpty($this->slept);
        $this->assertSame([null, null, null, null], $this->stored($pk));

        $this->db->table('TblSMSRuns')->update(['Status' => 'approved']);
        $this->assertNull($this->worker()->pauseReason());
        $this->db->table('TblTUPipelineOwner')->update(['Batch_ID' => '11111111-2222-4333-8444-555555555555']);
        $this->assertSame('a TU import is working', $this->worker()->pauseReason());
        $this->db->table('TblTUPipelineOwner')->update(['Batch_ID' => null]);

        $this->clock = gmmktime(9, 30, 0, 11, 8, 2026);
        $this->assertSame('the nightly jobs are running', $this->worker()->pauseReason());
        $this->clock = gmmktime(10, 25, 0, 11, 8, 2026);
        $this->assertNull($this->worker()->pauseReason());
    }

    public function test_every_finished_run_status_lets_it_work_and_every_working_status_stops_it(): void
    {
        foreach (['approved', 'discarded', 'review', 'file_ready', 'failed', 'external_sent'] as $status) {
            $this->db->table('TblSMSRuns')->delete();
            $this->db->table('TblSMSRuns')->insert(['Status' => $status]);
            $this->assertNull($this->worker()->pauseReason(), $status);
        }
        foreach (['queued', 'selecting', 'building_file', 'approving', 'sending', 'reversing'] as $status) {
            $this->db->table('TblSMSRuns')->delete();
            $this->db->table('TblSMSRuns')->insert(['Status' => $status]);
            $this->assertNotNull($this->worker()->pauseReason(), $status);
        }
    }

    public function test_the_refresh_needs_the_back_fill_first(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('back fill');
        $this->worker()->refresh();
    }

    public function test_the_refresh_updates_only_the_people_new_tu_rows_belong_to_and_ignores_new_mailers(): void
    {
        $tuPerson = $this->person('Ann Lee', '1 Main St');
        $old = $this->mailer('30000.00', 'T5', 'OLD', '2026-01-01');
        $mailerOnly = $this->person('Bob Ray', '7 Main St');
        $mailerRow = $this->mailer('31000.00', 'T5', 'OLD', '2026-01-01', 'Bob Ray', '7 Main St');
        $this->worker()->backfill('never');
        $this->assertSame(['30000.00', 'T5', 'TblMailers', $old], $this->stored($tuPerson));

        $this->assertSame(['tu_rows' => 0, 'tu_people' => 0, 'written' => 0, 'stopped' => null], $this->worker()->refresh());
        $tu = $this->tu('27000.00', 'T5');
        $this->mailer('90000.00', 'T9', 'NEW', '2026-09-01', 'Bob Ray', '7 Main St');
        $result = $this->worker()->refresh();
        $this->assertSame(['tu_rows' => 1, 'tu_people' => 1, 'written' => 1, 'stopped' => null], $result);
        $this->assertSame(['27000.00', 'T5', 'TblTUData', $tu], $this->stored($tuPerson));
        $this->assertSame(['31000.00', 'T5', 'TblMailers', $mailerRow], $this->stored($mailerOnly), 'a newer mailer changes nothing');
        $this->assertSame(0, $this->worker()->refresh()['tu_rows']);
    }

    public function test_the_refresh_fills_a_new_person_from_tus_return(): void
    {
        $this->eligiblePerson(1);
        $this->worker()->backfill('never');
        $new = $this->eligiblePerson(2);
        $this->assertSame('TblTUData', $this->worker()->refresh()['stopped'] ?? $this->stored($new)[2]);
    }

    public function test_a_person_who_gains_a_phone_later_is_caught_by_the_nightly_sweep(): void
    {
        $late = $this->eligiblePerson(1);
        $this->db->table('TblProspects')->where('PK', $late)->update(['Phone' => null]);
        $this->eligiblePerson(2);
        $this->worker()->backfill('never');
        $this->db->table('TblProspects')->where('PK', $late)->update(['Phone' => '5551112222']);
        $this->worker()->refresh();
        $this->assertSame([null, null, null, null], $this->stored($late));
        $this->worker()->restartSweep();
        $this->worker()->refresh();
        $this->assertSame('TblTUData', $this->stored($late)[2]);
    }

    public function test_the_refresh_gives_way_and_does_nothing_while_a_run_is_working(): void
    {
        $this->worker()->backfill('never');
        $this->db->table('TblSMSRuns')->insert(['Status' => 'approving']);
        $pk = $this->eligiblePerson(1);
        $this->assertSame(['stopped' => 'an SMS run is working', 'tu_rows' => 0, 'tu_people' => 0, 'written' => 0], $this->worker()->refresh());
        $this->assertSame([null, null, null, null], $this->stored($pk));
    }

    public function test_a_stop_part_way_through_new_tu_rows_repeats_them_and_loses_nobody(): void
    {
        $this->worker()->backfill('never');
        $a = $this->eligiblePerson(1);
        $b = $this->eligiblePerson(2);
        $clock = 0;
        $worker = new SmsStoredDebtWorker($this->db, $this->statePath, null, function (int $s) use (&$clock): void { $clock += $s; }, function () use (&$clock): int {
            $clock += 3600;

            return $this->clock + $clock;
        });
        $stopped = $worker->refresh(1, 1);
        $this->assertSame('time limit', $stopped['stopped']);
        $this->assertSame('TblTUData', $this->worker()->refresh(1, 0)['stopped'] ?? $this->stored($a)[2]);
        $this->assertSame('TblTUData', $this->stored($b)[2]);
    }

    public function test_a_database_error_retries_the_same_people_and_never_moves_past_them(): void
    {
        $pk = $this->eligiblePerson(1);
        $this->db->unprepared("CREATE TRIGGER dbo.StoredDebtBreak ON dbo.TblProspects AFTER UPDATE AS BEGIN SET NOCOUNT ON;
            IF (SELECT COUNT(*) FROM dbo.StoredDebtFlag)=0 BEGIN ROLLBACK; THROW 52998,'Deadlock stand-in.',1; END END");
        $this->db->unprepared('ALTER TABLE dbo.TblProspects DISABLE TRIGGER StoredDebtBreak');
        $this->db->unprepared('ALTER TABLE dbo.TblProspects ENABLE TRIGGER StoredDebtBreak');
        $seen = 0;
        $worker = new SmsStoredDebtWorker($this->db, $this->statePath, null, function () use (&$seen): void {
            if (++$seen === 2) $this->db->table('StoredDebtFlag')->insert(['N' => 1]);
        }, fn (): int => $this->clock);
        $this->assertSame(1, $worker->backfill('never')['people']);
        $this->assertSame(2, $seen);
        $this->assertSame('TblTUData', $this->stored($pk)[2]);
    }

    public function test_repeated_database_errors_stop_it_with_nothing_half_done(): void
    {
        $pk = $this->eligiblePerson(1);
        $this->db->unprepared("CREATE TRIGGER dbo.StoredDebtBreak ON dbo.TblProspects AFTER UPDATE AS BEGIN SET NOCOUNT ON; ROLLBACK; THROW 52998,'Always fails.',1; END");
        try {
            $this->worker()->backfill('never');
            $this->fail('It must stop after repeated errors.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('repeated database errors', $error->getMessage());
        }
        $this->db->unprepared('DROP TRIGGER dbo.StoredDebtBreak');
        $this->assertSame([null, null, null, null], $this->stored($pk));
        $this->assertSame(0, json_decode((string) file_get_contents($this->statePath), true)['cursor']['never']);
    }

    public function test_a_damaged_state_file_stops_it_instead_of_starting_again(): void
    {
        $this->worker()->backfill('never');
        file_put_contents($this->statePath, 'not json');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unreadable');
        $this->worker()->backfill('never');
    }

    public function test_a_wrong_scope_or_batch_is_refused(): void
    {
        foreach ([fn () => $this->worker()->backfill('everyone'), fn () => $this->worker()->backfill('never', 0), fn () => $this->worker()->backfill('never', 50001), fn () => $this->worker()->compare('never', 0)] as $call) {
            try {
                $call();
                $this->fail('Bad input must be refused.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_the_worker_comparison_samples_the_scope_and_writes_nothing(): void
    {
        foreach (range(1, 6) as $i) $this->eligiblePerson($i);
        $this->worker()->backfill('never');
        $blank = $this->eligiblePerson(7);
        $result = $this->worker()->compare('never', 100);
        $this->assertSame(7, $result['people']);
        $this->assertSame(6, $result['same']);
        $this->assertSame(1, $result['never_filled']);
        $this->assertSame([null, null, null, null], $this->stored($blank));
    }

    public function test_the_status_reads_counts_from_the_state_and_the_sample(): void
    {
        $this->worker()->backfill('never');
        $status = $this->worker()->status();
        $this->assertSame(['people' => 0, 'blank' => 0, 'no_value' => 0], $status['scopes']['never']);
        $this->assertTrue($status['state']['finished']['never']);
        $this->assertNull($status['paused']);
    }

    public function test_bad_input_is_refused_before_any_sql(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new SmsStoredDebtRefresh($this->db))->refresh(['1); DROP TABLE dbo.TblProspects;--']);
    }

    public function test_an_empty_list_does_nothing(): void
    {
        $this->assertSame(['people' => 0, 'written' => 0, 'resolved' => 0, 'no_value' => 0], (new SmsStoredDebtRefresh($this->db))->refresh([]));
    }

    public function test_the_ready_check_refuses_missing_or_different_columns(): void
    {
        SmsStoredDebt::assertReady($this->db);
        $this->db->unprepared('ALTER TABLE dbo.TblProspects ALTER COLUMN Current_Tier varchar(5) NULL');
        try {
            SmsStoredDebt::assertReady($this->db);
            $this->fail('A different column shape must stop a stored run.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('reviewed shape', $error->getMessage());
        }
        $this->db->unprepared('ALTER TABLE dbo.TblProspects ALTER COLUMN Current_Tier char(2) NULL; ALTER TABLE dbo.TblProspects DROP COLUMN Current_Debt_Source_PK');
        $this->expectException(RuntimeException::class);
        SmsStoredDebt::assertReady($this->db);
    }

    public function test_the_stored_insert_rounds_up_like_the_live_rule_and_never_keeps_a_source_row_for_a_tier(): void
    {
        $sql = SmsTierWindowSelectionSql::insertStored();
        $this->assertStringContainsString("CASE WHEN w.Current_Debt_Source='Tier' THEN NULL ELSE w.Current_Debt_Source_PK END", $sql);
        $this->assertStringContainsString(SmsStoredDebt::ROUNDED_DEBT_SQL, $sql);
        $this->assertSame(str_replace('w.Current_Debt', 'r.Debt', SmsStoredDebt::ROUNDED_DEBT_SQL), SmsCurrentDebtSql::ROUNDED_DEBT_SQL);
        $this->assertSame(['T1','T2','T3','T4','T5','T6','T7','T8','T9'], SmsRunOptions::TIERS);
        $this->assertTrue(class_exists(SmsRecordExportRepository::class));
    }
}
