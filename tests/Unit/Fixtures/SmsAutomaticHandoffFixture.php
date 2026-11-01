<?php
declare(strict_types=1);
namespace Cmd\Reports\Tests\Fixtures;

use Carbon\Carbon;
use Cmd\Reports\Services\{SmsDebtPleteImportPrivacy,SmsRunArtifactBundle,SmsRunArtifactPartReader,SmsRunAutomaticHandoffWorkflow,SmsRunEmailGateway,SmsRunLinkPublisher,SmsRunOptions,SmsRunPublicationWorkflow};
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\Facade;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class HandoffFixturePublisher implements SmsRunLinkPublisher
{
    public string $store = 'closed-store-v1'; public array $objects = []; public int $creates = 0; public int $publishes = 0; public int $verifies = 0;
    public ?int $failPart = null; public ?\Closure $onPublish = null; public ?\Closure $onVerify = null;
    public function __construct(private Connection $db) {}
    public function identity(): string { return $this->store; }
    public function policy(string $date): array { return ['version' => 1, 'send_date' => $date, 'access' => 'viewer']; }
    public function publish(string $id, array $part, mixed $stream, array $policy): array
    {
        $this->outside(); ++$this->publishes; TestCase::assertSame(0, ftell($stream)); $bytes = stream_get_contents($stream);
        TestCase::assertSame($part['sha256'], hash('sha256', $bytes)); TestCase::assertSame($part['bytes'], strlen($bytes));
        $key = $id.'/'.$part['part'];
        if (!isset($this->objects[$key])) {
            ++$this->creates; $this->objects[$key] = ['version' => 1, 'request_id' => $id, 'part' => $part['part'], 'name' => $part['name'],
                'url' => 'https://download.example.invalid/part-'.$part['part'], 'sha256' => $part['sha256'], 'bytes' => $part['bytes'],
                'rows' => $part['rows'], 'remote' => ['provider' => 'closed-fixture', 'version' => 1], 'policy' => $policy];
        }
        if ($this->onPublish) ($this->onPublish)($part);
        if ($this->failPart === $part['part']) { $this->failPart = null; throw new RuntimeException('PRIVATE uncertain publication fixture'); }
        return $this->objects[$key];
    }
    public function verify(string $id, array $part, array $receipt, array $policy): void
    {
        $this->outside(); ++$this->verifies; TestCase::assertSame($this->objects[$id.'/'.$part['part']], $receipt);
        if ($this->onVerify) ($this->onVerify)($part);
    }
    private function outside(): void { TestCase::assertSame(0, $this->db->transactionLevel()); TestCase::assertFalse($this->db->getPdo()->inTransaction()); TestCase::assertTrue(SmsDebtPleteImportPrivacy::active()); }
}

final class HandoffFixtureEmail implements SmsRunEmailGateway
{
    public array $configuration = ['from' => 'admin@lendingtower.com', 'to' => ['first@example.invalid'], 'cc' => [], 'bcc' => [], 'report_name' => 'Synthetic SMS', 'companies' => ['FIXTURE']];
    public int $lookups = 0; public array $sent = []; public ?\Closure $onReady = null; public bool $loseAck = false;
    public function __construct(private Connection $db) {}
    public function configuration(): array { $this->outside(); ++$this->lookups; return $this->configuration; }
    public function assertReady(): void { $this->outside(); if ($this->onReady) ($this->onReady)(); }
    public function send(array $message): bool { $this->outside(); $this->sent[] = $message; if ($this->loseAck) throw new RuntimeException('PRIVATE ambiguous mail acceptance'); return true; }
    private function outside(): void { TestCase::assertSame(0, $this->db->transactionLevel()); TestCase::assertFalse($this->db->getPdo()->inTransaction()); TestCase::assertTrue(SmsDebtPleteImportPrivacy::active()); }
}

final class HandoffFixtureBeginConnection extends SQLiteConnection
{
    public bool $replace = false; public ?PDO $original = null; public ?PDO $replacement = null;
    public function beginTransaction()
    {
        parent::beginTransaction();
        if ($this->replace) { $this->replace = false; $this->original = $this->getPdo(); $this->replacement = new PDO('sqlite::memory:'); $this->setPdo($this->replacement); }
    }
}

final class HandoffFixtureReadTrap extends PDO
{
    public int $reads = 0;
    public function __construct() { parent::__construct('sqlite::memory:'); }
    public function prepare(string $query, array $options = []): \PDOStatement|false { ++$this->reads; throw new RuntimeException('Read replica must not be used.'); }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false { ++$this->reads; throw new RuntimeException('Read replica must not be used.'); }
}

/** Minimal owned source/receipt schemas; no old migrations, application boot, .env or provider network. */
abstract class SmsAutomaticHandoffFixture extends TestCase
{
    protected Connection $db; protected SmsRunAutomaticHandoffWorkflow $workflow;
    protected HandoffFixturePublisher $publisher; protected HandoffFixtureEmail $mail; protected array $bundle;
    protected string $root; protected string $id = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private Container $previous; private mixed $facade; private mixed $clock; private ?PDO $admin = null; private ?string $database = null;
    protected function native(): bool { return false; }
    protected function setUp(): void
    {
        if ($this->native() && !in_array('sqlsrv', PDO::getAvailableDrivers(), true)) self::markTestSkipped('Loopback PDO SQL Server unavailable.');
        $this->previous = Container::getInstance(); $this->facade = Facade::getFacadeApplication(); $this->clock = Carbon::getTestNow();
        Carbon::setTestNow(Carbon::parse('2026-10-26 12:00:00.123456', 'UTC'));
        $app = new Container; Container::setInstance($app); $this->root = str_replace('\\', '/', sys_get_temp_dir()).'/sms-automatic-handoff-fixture-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->root, 0700));
        $app->instance('config', new Repository(['database' => ['default' => 'sqlsrv'], 'sms-exports' => ['local_root' => $this->root,
            'handoff_automatic' => true, 'file_publication_enabled' => true, 'email_handoff_enabled' => true,
            'email_report_source' => 'plaw', 'email_report_name' => 'Synthetic SMS', 'email_report_company' => 'FIXTURE', 'email_allowed_link_hosts' => ['download.example.invalid']]]));
        $capsule = new Manager($app);
        if ($this->native()) {
            $this->admin = new PDO('sqlsrv:Server=127.0.0.1,14330;Database=master;Encrypt=no;TrustServerCertificate=yes', 'sa', 'LocalDev!2026Pass', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            self::assertSame('master', $this->admin->query('SELECT DB_NAME()')->fetchColumn());
            $this->database = 'sms_automatic_handoff_test_'.bin2hex(random_bytes(8));
            self::assertMatchesRegularExpression('/\Asms_automatic_handoff_test_[a-f0-9]{16}\z/D', $this->database);
            $this->admin->exec('CREATE DATABASE ['.$this->database.']');
            $capsule->addConnection(['driver' => 'sqlsrv', 'host' => '127.0.0.1,14330', 'database' => $this->database,
                'username' => 'sa', 'password' => 'LocalDev!2026Pass', 'encrypt' => 'no', 'trust_server_certificate' => 'yes'], 'sqlsrv');
        } else {
            $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:'], 'sqlsrv');
            $capsule->getDatabaseManager()->extend('sqlite', static fn ($config) => new HandoffFixtureBeginConnection(new PDO('sqlite::memory:'), ':memory:', '', $config));
        }
        $capsule->getDatabaseManager()->setDefaultConnection('sqlsrv'); $app->instance('db', $capsule->getDatabaseManager());
        Facade::clearResolvedInstances(); Facade::setFacadeApplication($app); $this->db = $capsule->getConnection('sqlsrv');
        $this->db->setTransactionManager(new DatabaseTransactionsManager);
        if ($this->native()) { self::assertSame($this->database, $this->db->selectOne('SELECT DB_NAME() AS db')->db); $this->db->unprepared('SET LOCK_TIMEOUT 3000'); }
        $s = $this->db->getSchemaBuilder();
        $s->create('TblSMSRuns', function (Blueprint $t): void {
            $t->uuid('Request_ID')->primary(); foreach (['Status','Selection_Policy','Approved_By','Sent_By','Run_Options_JSON','Artifact_Manifest_JSON','File_Path','File_Sha256','File_Cleanup_Path'] as $c) $t->text($c)->nullable();
            $t->dateTime('Approved_At', 7)->nullable(); $t->dateTime('Sent_At', 7)->nullable(); foreach (['Target','Selected','File_Bytes'] as $c) $t->bigInteger($c);
        });
        $s->create('TblSMSFilePublications', function (Blueprint $t): void {
            $t->string('Request_ID',36)->primary(); foreach (['Run_Sha256','Plan_JSON','Plan_Sha256','State','Created_By'] as $c) $t->text($c);
            $t->dateTime('Created_At'); $t->dateTime('Completed_At')->nullable(); $t->string('Error_Code',64)->nullable();
        });
        $s->create('TblSMSFilePublicationParts', function (Blueprint $t): void {
            $t->string('Request_ID',36); $t->integer('Part_Number'); $t->primary(['Request_ID','Part_Number']); $t->string('State',16);
            foreach (['Attempted_At','Completed_At'] as $c) $t->dateTime($c)->nullable(); foreach (['Receipt_JSON','Receipt_Sha256','Error_Code'] as $c) $t->text($c)->nullable();
        });
        $s->create('TblSMSEmailOutbox', function (Blueprint $t): void {
            $t->string('Request_ID',36)->primary(); foreach (['Run_Sha256','Payload_JSON','Payload_Sha256','State','Created_By'] as $c) $t->text($c);
            $t->dateTime('Created_At'); foreach (['Attempted_At','Accepted_At'] as $c) $t->dateTime($c)->nullable(); $t->string('Error_Code',64)->nullable();
        });
        (require dirname(__DIR__,3).'/database/sms-migrations/2026_10_26_000001_create_sms_automatic_handoffs.php')->up();
        $options = SmsRunOptions::normalize(3,2,null,null,SmsRunOptions::PLANNED_DATE_VERSION,'2026-10-27',SmsRunOptions::APPROVAL_FILENAME_VERSION);
        $directory = $this->root.'/runs/'.$this->id; $rows = [['Synthetic one','14000','0555010101','2026-10-27'],['Synthetic two','14000','0555010101','2026-10-27'],['Synthetic three','14000','0555010102','2026-10-27']];
        $pending = (new SmsRunArtifactBundle)->build($directory,'SMS Export - 2026-10-27 - ID Pending',$rows,3,$options);
        $this->bundle = (new SmsRunArtifactBundle('v2'))->publishApproved($directory,$pending['manifest'],$options,3,'SMS0001-SMS0003');
        $this->db->table('TblSMSRuns')->insert(['Request_ID'=>$this->id,'Status'=>'approved','Selection_Policy'=>'prospect-record-v1','Target'=>3,'Selected'=>3,
            'Approved_By'=>'fixture@example.invalid','Approved_At'=>'2026-10-26 11:00:00.1234567','Sent_By'=>'fixture@example.invalid','Sent_At'=>'2026-10-26 11:00:00.1234567',
            'Run_Options_JSON'=>json_encode($options),'Artifact_Manifest_JSON'=>json_encode($this->bundle['manifest']),
            'File_Path'=>$this->bundle['path'],'File_Bytes'=>$this->bundle['bytes'],'File_Sha256'=>$this->bundle['sha256']]);
        $this->publisher = new HandoffFixturePublisher($this->db); $this->mail = new HandoffFixtureEmail($this->db);
        $reader = new SmsRunArtifactPartReader; $app->instance(SmsRunPublicationWorkflow::class,new SmsRunPublicationWorkflow($this->publisher,$reader));
        $this->workflow = new SmsRunAutomaticHandoffWorkflow($this->publisher,$reader,$this->mail);
    }
    protected function capture(): void { $this->db->transaction(fn () => $this->workflow->captureApproved($this->source())); }
    protected function source(): object { return $this->db->table('TblSMSRuns')->useWritePdo()->first(); }
    protected function ledger(): array { return (array) $this->db->table(SmsRunAutomaticHandoffWorkflow::TABLE)->useWritePdo()->first(); }
    protected function tearDown(): void
    {
        if (isset($this->db)) { while ($this->db->transactionLevel()>0) $this->db->rollBack(); $this->db->disconnect(); }
        if ($this->admin && $this->database && preg_match('/\Asms_automatic_handoff_test_[a-f0-9]{16}\z/D',$this->database)) {
            self::assertSame('master',$this->admin->query('SELECT DB_NAME()')->fetchColumn());
            $this->admin->exec('ALTER DATABASE ['.$this->database.'] SET SINGLE_USER WITH ROLLBACK IMMEDIATE'); $this->admin->exec('DROP DATABASE ['.$this->database.']');
            $q=$this->admin->prepare('SELECT DB_ID(?)');$q->execute([$this->database]);self::assertNull($q->fetchColumn());
            file_put_contents($this->root.'/native-cleanup.json',json_encode(['database'=>$this->database,'database_dropped'=>true],JSON_THROW_ON_ERROR));
        }
        if (isset($this->previous)) { Carbon::setTestNow($this->clock);Facade::clearResolvedInstances();Facade::setFacadeApplication($this->facade);Container::setInstance($this->previous); }
        self::assertFalse(SmsDebtPleteImportPrivacy::active());
    }
}
