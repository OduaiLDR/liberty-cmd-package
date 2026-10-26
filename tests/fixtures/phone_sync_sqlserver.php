<?php

declare(strict_types=1);
use Cmd\Reports\Console\Commands\SyncPhoneNumbers;
use Cmd\Reports\Services\SmsAvailabilityService;
use Illuminate\Config\Repository;
use Illuminate\Console\OutputStyle;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

// Disposable localhost SQL Server only. Credentials are read in-process, never printed.
require dirname(__DIR__).'/bootstrap-workspace.php';
$config = json_decode(file_get_contents($argv[1] ?? ''), true, 512, JSON_THROW_ON_ERROR);
if ($config['host'] !== '127.0.0.1,64604') {
    throw new RuntimeException('Local test SQL Server required.');
}
$database = 'phone_sync_test_'.bin2hex(random_bytes(5));
$connect = static fn (string $db): PDO => new PDO('sqlsrv:Server='.$config['host'].';Database='.$db.';Encrypt=yes;TrustServerCertificate=yes', $config['user'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$master = $connect('master');
if (! str_contains((string) $master->query("SELECT SERVERPROPERTY('Edition')")->fetchColumn(), 'Developer')) {
    throw new RuntimeException('Developer edition required.');
}
$master->exec('CREATE DATABASE ['.$database.']');
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    if (! $condition) {
        throw new RuntimeException($message);
    } $checks++;
};
try {
    $pdo = $connect($database);
    $pdo->exec('CREATE TABLE dbo.TblPhoneNumbers (PK int IDENTITY PRIMARY KEY, Phone nvarchar(50), Source nvarchar(50), CID nvarchar(50), Created_Date date)');
    $pdo->exec("INSERT INTO dbo.TblPhoneNumbers (Phone,Source,CID,Created_Date) VALUES ('2025550199','DP','123',NULL),('2025550188','DP','888',NULL),('2025550199','DP_LT','123','2020-01-02'),('2025550100','DP_LDR',NULL,NULL),('2025550101','DP_PLAW',NULL,NULL)");
    $app = new Container;
    Container::setInstance($app);
    $app->instance('config', new Repository(['database' => ['default' => 'sqlsrv']]));
    $capsule = new Manager($app);
    $capsule->addConnection(['driver' => 'sqlsrv', 'database' => $database, 'host' => $config['host'], 'username' => $config['user'], 'password' => $config['password']], 'sqlsrv');
    $app->instance('db', $capsule->getDatabaseManager());
    Facade::setFacadeApplication($app);
    DB::connection('sqlsrv')->setPdo($pdo);
    $availability = Mockery::mock(SmsAvailabilityService::class);
    $availability->shouldReceive('invalidate')->andReturnNull();
    $app->instance(SmsAvailabilityService::class, $availability);
    $command = new class extends SyncPhoneNumbers
    {
        public function replace(PDO $pdo, string $label, array $rows): array
        {
            $this->activeSource = $label;
            $this->withCreatedDate = true;
            $path = tempnam(sys_get_temp_dir(), 'phone-sql-test-');
            try {
                file_put_contents($path, implode(PHP_EOL, array_map(fn ($row) => json_encode($row, JSON_THROW_ON_ERROR), $rows)).PHP_EOL);

                return $this->replacePhonesFromFile($pdo, $path, 1000, count($rows));
            } finally {
                unlink($path);
            }
        }
    };
    $command->setLaravel($app);
    // Direct helper needs only an OutputStyle.
    $command->setOutput(new OutputStyle(new ArrayInput([]), new NullOutput));
    $script = file_get_contents(dirname(__DIR__, 2).'/database/phone-sync-repair.sql');
    $productionGuard = "IF DB_NAME() <> N'CommissionDatabase' OR CONVERT(nvarchar(128),SERVERPROPERTY('ServerName')) <> N'serverlibertydebtrelief'";
    if (substr_count($script, $productionGuard) !== 1) {
        throw new RuntimeException('Test target guard substitution failed.');
    }
    $script = str_replace($productionGuard, "IF DB_NAME() <> N'{$database}' OR CONVERT(nvarchar(128),SERVERPROPERTY('Edition')) NOT LIKE N'%Developer%'", $script);
    $runSql = static function (string $sql) use ($pdo): array {
        $statement = $pdo->query($sql);
        $results = [];
        do {
            if ($statement->columnCount() > 0) {
                $results[] = $statement->fetchAll(PDO::FETCH_ASSOC);
            }
        } while ($statement->nextRowset());

        return $results;
    };
    $runSql($script);
    $assert((int) $pdo->query('SELECT COUNT(*) FROM dbo.TblPhoneNumbers')->fetchColumn() === 5, 'Preview mutated rows.');
    $backup = str_replace("DECLARE @Mode varchar(10) = 'preview';", "DECLARE @Mode varchar(10) = 'backup';", $script);
    $runSql($backup);
    $assert((int) $pdo->query('SELECT COUNT(*) FROM dbo.TblPhoneNumbers_Backup_20261026')->fetchColumn() === 5, 'Backup mismatch.');
    try {
        $runSql($backup);
        throw new RuntimeException('Backup unexpectedly overwritten.');
    } catch (PDOException $error) {
        $assert(str_contains($error->getMessage(), 'will not be overwritten'), 'Unexpected backup error.');
    }
    $cleanup = str_replace("DECLARE @Mode varchar(10) = 'preview';", "DECLARE @Mode varchar(10) = 'cleanup';", $script);
    try {
        $runSql($cleanup);
        throw new RuntimeException('Unreviewed cleanup allowed.');
    } catch (PDOException $error) {
        $assert(str_contains($error->getMessage(), 'Complete and review'), 'Unexpected cleanup guard error.');
    }
    foreach (['DP_LDR', 'DP_PLAW', 'DP_LT'] as $label) {
        $command->replace($pdo, $label, [['phone' => '2025550199', 'cid' => '123', 'created' => '2020-01-02'], ['phone' => '12025550199', 'cid' => '123456789012345678901234', 'created' => '2026-10-26']]);
    }
    $assert((int) $pdo->query("SELECT COUNT(*) FROM dbo.TblPhoneNumbers WHERE Source IN ('DP_LDR','DP_PLAW','DP_LT') AND CID='123456789012345678901234' AND Created_Date='2026-10-26'")->fetchColumn() === 3, 'CID/date/source round trip failed.');
    $assert((int) $pdo->query("SELECT COUNT(*) FROM dbo.TblPhoneNumbers WHERE Source='DP'")->fetchColumn() === 2, 'Sync touched legacy.');
    $pdo->exec("ALTER TABLE dbo.TblPhoneNumbers ADD CONSTRAINT RejectSyntheticCID CHECK (CID<>'999')");
    try {
        $command->replace($pdo, 'DP_PLAW', [['phone' => '2025550199', 'cid' => '999', 'created' => '2020-01-02']]);
        throw new RuntimeException('Failed INSERT did not fail.');
    } catch (PDOException $error) {
        $assert(str_contains($error->getMessage(), 'RejectSyntheticCID'), 'Unexpected rollback error.');
    }
    $assert((int) $pdo->query("SELECT COUNT(*) FROM dbo.TblPhoneNumbers WHERE Source='DP_PLAW'")->fetchColumn() === 2, 'Failed replacement did not roll back.');
    $cleanup = str_replace('DECLARE @ReviewedSyncedSources bit = 0;', 'DECLARE @ReviewedSyncedSources bit = 1;', $cleanup);
    $results = $runSql($cleanup);
    $assert((int) $results[0][0]['Proven_Legacy_Overlap_Removed'] === 1, 'Wrong cleanup count.');
    $assert((int) $results[0][0]['Unresolved_DP_Rows_Preserved'] === 1, 'Unmatched legacy lost.');
    $assert((int) $pdo->query('SELECT COUNT(*) FROM dbo.TblPhoneNumbers_Backup_20261026')->fetchColumn() === 5, 'Cleanup changed backup.');
    $results = $runSql($cleanup);
    $assert((int) $results[0][0]['Proven_Legacy_Overlap_Removed'] === 0, 'Cleanup not idempotent.');
    echo json_encode(['local_sqlserver_checks_passed' => $checks, 'production_writes' => false]),PHP_EOL;
} finally {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $master->exec('ALTER DATABASE ['.$database.'] SET SINGLE_USER WITH ROLLBACK IMMEDIATE');
    $master->exec('DROP DATABASE ['.$database.']');
    Mockery::close();
}
