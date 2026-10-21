<?php

// Explicitly isolated SQL Server integration test. Never loads .env or an application.
// php scripts/test-sms-availability-local.php --confirm-local-only
if (! in_array('--confirm-local-only', $argv, true)) {
    fwrite(STDERR, "Pass --confirm-local-only to create/drop a disposable database on 127.0.0.1:14330.\n");
    exit(2);
}
require dirname(__DIR__).'/tests/bootstrap-workspace.php';

use Carbon\Carbon;
use Cmd\Reports\Repositories\SmsProspectExportRepository;
use Cmd\Reports\Services\SmsAvailabilityLedger;
use Cmd\Reports\Services\SmsAvailabilityRefresh;
use Cmd\Reports\Services\SmsDurableAvailabilityService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Support\Facades\Facade;

$database = 'sms_summary_test_'.bin2hex(random_bytes(6));
$admin = new PDO('sqlsrv:Server=127.0.0.1,14330;Database=master;Encrypt=no;TrustServerCertificate=yes', 'sa', 'LocalDev!2026Pass');
$admin->exec("CREATE DATABASE [{$database}]");
$admin->exec("ALTER DATABASE [{$database}] SET ALLOW_SNAPSHOT_ISOLATION ON");
$checks = 0;
$check = function (bool $ok, string $message) use (&$checks): void {
    if (! $ok) throw new RuntimeException('FAIL '.$message);
    $checks++;
    echo 'PASS '.$message.PHP_EOL;
};

try {
    $app = new Container;
    Container::setInstance($app);
    $app->instance('config', new Repository([
        'app' => ['env' => 'testing'],
        'database' => ['connections' => ['sqlsrv' => ['host' => '127.0.0.1,14330', 'database' => $database]]],
        'sms-exports' => ['durable_availability_enabled' => true, 'queue' => 'synthetic-summary'],
        'queue' => ['default' => 'database', 'connections' => ['database' => ['retry_after' => 15000]]],
    ]));
    $capsule = new Manager($app);
    $connection = ['driver' => 'sqlsrv', 'host' => '127.0.0.1,14330', 'database' => $database,
        'username' => 'sa', 'password' => 'LocalDev!2026Pass', 'encrypt' => 'no', 'trust_server_certificate' => 'yes'];
    $capsule->addConnection($connection, 'sqlsrv');
    $capsule->addConnection($connection, 'writer');
    $app->instance('db', $capsule->getDatabaseManager());
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($app);
    $db = $capsule->getConnection('sqlsrv');
    $writer = $capsule->getConnection('writer');
    $db->setTransactionManager(new DatabaseTransactionsManager);
    $migration = require dirname(__DIR__).'/database/sms-migrations/2026_10_20_000001_create_sms_availability_summary.php';
    $migration->up();
    $db->statement('CREATE TABLE summary_probe (id int PRIMARY KEY)');
    $ledger = new SmsAvailabilityLedger;
    $app->instance(SmsAvailabilityLedger::class, $ledger);
    $service = new SmsDurableAvailabilityService($ledger);
    $app->instance(SmsDurableAvailabilityService::class, $service);
    $jobs = [];
    $bus = Mockery::mock(Dispatcher::class);
    $bus->shouldReceive('dispatch')->andReturnUsing(function ($job) use (&$jobs): int { $jobs[] = $job; return 1; });
    $app->instance(Dispatcher::class, $bus);
    Carbon::setTestNow('2026-10-20 12:00:00 UTC');

    $check($ledger->read() === null, 'additive migration does not invent a count');
    try {
        $db->transaction(function () use ($db, $ledger): void {
            $db->insert('INSERT INTO summary_probe VALUES (1)');
            $ledger->dirty($db);
            throw new RuntimeException('synthetic rollback');
        });
    } catch (RuntimeException $error) {
        if ($error->getMessage() !== 'synthetic rollback') throw $error;
    }
    $check($ledger->read() === null && $db->table('summary_probe')->count() === 0, 'first-row initialization and business change roll back together');
    $db->transaction(function () use ($db): void {
        $db->insert('INSERT INTO summary_probe VALUES (1)');
        SmsAvailabilityRefresh::afterCommit($db);
    });
    $check(count($jobs) === 1 && $ledger->read()['status'] === 'queued', 'committed durable dirty marker dispatches exactly one generation');
    $check(empty($service->request(true)['queued_now']), 'concurrent request coalesces with queued generation');

    $repo = new class($db, $writer, $check) extends SmsProspectExportRepository {
        public bool $writeDuringRead = true;
        public function __construct(private $db, private $writer, private $check) {}
        protected function connection(): \Illuminate\Database\ConnectionInterface { return $this->db; }
        public function availability(): array
        {
            $before = $this->db->table('summary_probe')->count();
            if ($this->writeDuringRead) {
                $this->writer->insert('INSERT INTO summary_probe VALUES (2)');
                ($this->check)($before === $this->db->table('summary_probe')->count(), 'SNAPSHOT read excludes a concurrent committed insert');
            }
            return ['eligible' => $before, 'never_texted' => $before, 'texted_before' => 0];
        }
    };
    $db->unprepared('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
    $jobs[0]->handle($service, $repo);
    $ready = $service->request();
    $check($ready['state'] === 'ready' && $ready['eligible'] === 1, 'database ledger publishes counts and timestamp atomically');
    $isolation = $db->selectOne('SELECT transaction_isolation_level AS n FROM sys.dm_exec_sessions WHERE session_id = @@SPID');
    $check((int) $isolation->n === 2 && ! $db->getPdo()->inTransaction(), 'worker restores READ COMMITTED and closes snapshot transaction');
    $check($ready['latest_data_date'] === null, 'counting never invents the latest TU data date');

    $db->getPdo()->beginTransaction();
    $db->getPdo()->exec('INSERT INTO summary_probe VALUES (3)');
    SmsAvailabilityRefresh::beforeNativeCommit($db, $db->getPdo());
    $db->getPdo()->commit();
    SmsAvailabilityRefresh::afterNativeCommit();
    $check(count($jobs) === 2 && (int) $ledger->read()['revision'] > (int) $ledger->read()['published_revision'], 'native PDO write persists dirty revision before dispatch');
    $repo->writeDuringRead = false;
    $jobs[1]->handle($service, $repo);
    $check($service->request()['eligible'] === 3, 'follow-up snapshot includes all committed writes');

    $failing = new class($db) extends SmsProspectExportRepository {
        public function __construct(private $db) {}
        protected function connection(): \Illuminate\Database\ConnectionInterface { return $this->db; }
        public function availability(): array { throw new RuntimeException('synthetic snapshot failure'); }
    };
    $db->unprepared('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
    try {
        $failing->availabilitySnapshotWithTimeout();
        throw new RuntimeException('Expected the synthetic read failure.');
    } catch (RuntimeException $error) {
        if ($error->getMessage() !== 'synthetic snapshot failure') throw $error;
    }
    $isolation = $db->selectOne('SELECT transaction_isolation_level AS n FROM sys.dm_exec_sessions WHERE session_id = @@SPID');
    $check((int) $isolation->n === 4 && ! $db->getPdo()->inTransaction(), 'failed count restores original nondefault isolation and rolls back');
    $db->unprepared('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');

    $migration->down();
    $check(! $db->getSchemaBuilder()->hasTable('TblSMSAvailabilitySummary') && $db->table('summary_probe')->count() === 3, 'rollback removes only the new summary table');
    echo "{$checks} checks passed in disposable {$database}.".PHP_EOL;
} finally {
    Carbon::setTestNow();
    if (isset($capsule)) {
        $capsule->getDatabaseManager()->purge('writer');
        $capsule->getDatabaseManager()->purge('sqlsrv');
    }
    // Only this script's random, locally created database can be dropped.
    if (preg_match('/^sms_summary_test_[0-9a-f]{12}$/D', $database)) {
        $admin->exec("ALTER DATABASE [{$database}] SET SINGLE_USER WITH ROLLBACK IMMEDIATE; DROP DATABASE [{$database}]");
    }
    Mockery::close();
}
