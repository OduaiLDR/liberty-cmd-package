<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Carbon\Carbon;
use Cmd\Reports\Services\SmsDebtPleteImportGateway;
use Cmd\Reports\Services\SmsDebtPleteImportPlan;
use Cmd\Reports\Services\SmsRunArtifactBundle;
use Cmd\Reports\Services\SmsRunArtifactPartReader;
use Cmd\Reports\Services\SmsRunDebtPleteImportWorkflow;
use Cmd\Reports\Services\SmsRunOptions;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Support\Facades\Facade;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Closed protocol peer: no HTTP client, providers or application boot. */
final class SqlServerImportClosedGateway implements SmsDebtPleteImportGateway
{
    public array $calls = [];
    public ?array $remote = null;
    public ?array $payload = null;
    public readonly array $destination;
    public function __construct(private readonly Connection $db)
    {
        $this->destination = ['protocol' => 'debtplete-sms-import-v1', 'origin' => 'https://tenant.example.invalid',
            'tenant_id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'client_id' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc'];
    }
    public function identity(): array { return $this->destination; }
    private function outside(): void
    {
        TestCase::assertSame(0, $this->db->transactionLevel());
        TestCase::assertFalse($this->db->getPdo()->inTransaction());
    }
    public function register(array $payload): array
    {
        $this->outside(); $this->calls[] = ['register', $payload['request_id']]; $this->payload = $payload;
        $json = json_encode(['tenant_id' => $this->destination['tenant_id'], 'client_id' => $this->destination['client_id'], 'plan' => $payload], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $this->remote = ['request_id' => $payload['request_id'], 'plan_sha256' => hash('sha256', $json),
            'state' => 'queued', 'status' => 'queued', 'processed' => 0, 'total' => $payload['manifest']['rows'], 'error_code' => null,
            'parts' => array_map(static fn (array $p): array => ['part' => $p['part'], 'state' => 'awaiting_upload',
                'rows_expected' => $p['rows'], 'rows_committed' => 0, 'accepted' => false, 'bytes' => $p['bytes'], 'sha256' => $p['sha256']], $payload['manifest']['parts'])];
        return $this->remote;
    }
    public function status(string $requestId): array
    {
        $this->outside(); $this->calls[] = ['status', $requestId];
        if ($this->remote === null) throw new RuntimeException('Missing closed fixture receipt.', 404);
        TestCase::assertSame($this->remote['request_id'], $requestId);
        return $this->remote;
    }
    public function upload(string $requestId, int $part, string $planSha256, mixed $stream): array
    {
        $this->outside(); $this->calls[] = ['upload', $requestId, $part];
        TestCase::assertSame($this->remote['request_id'], $requestId);
        TestCase::assertSame($this->remote['plan_sha256'], $planSha256);
        TestCase::assertSame(0, ftell($stream));
        $bytes = stream_get_contents($stream); $expected = $this->payload['manifest']['parts'][$part - 1];
        TestCase::assertSame($expected['bytes'], strlen($bytes)); TestCase::assertSame($expected['sha256'], hash('sha256', $bytes));
        $this->remote['parts'][$part - 1]['accepted'] = true; $this->remote['parts'][$part - 1]['state'] = 'uploaded';
        if (!array_filter($this->remote['parts'], static fn (array $p): bool => !$p['accepted'])) {
            $this->remote['state'] = $this->remote['status'] = 'completed'; $this->remote['processed'] = $this->remote['total'];
            foreach ($this->remote['parts'] as &$p) { $p['state'] = 'completed'; $p['rows_committed'] = $p['rows_expected']; } unset($p);
        }
        return $this->remote;
    }
}

/** Hardwired loopback only. Every case owns a new random DB; no ordinary migrations are loaded. */
final class SmsDebtPleteImportSqlServerTest extends TestCase
{
    private static ?PDO $admin = null;
    private Container $previous;
    private mixed $previousFacade;
    private mixed $clock;
    private string $database;
    private Connection $db;
    private string $root;
    private array $bundle;
    private SqlServerImportClosedGateway $gateway;
    private SmsRunDebtPleteImportWorkflow $workflow;
    private const ID = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    public static function setUpBeforeClass(): void
    {
        if (!in_array('sqlsrv', PDO::getAvailableDrivers(), true)) self::markTestSkipped('Loopback PDO SQL Server is unavailable.');
        self::$admin = new PDO('sqlsrv:Server=127.0.0.1,14330;Database=master;Encrypt=no;TrustServerCertificate=yes',
            'sa', 'LocalDev!2026Pass', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        self::assertSame('master', self::$admin->query('SELECT DB_NAME()')->fetchColumn());
    }

    protected function setUp(): void
    {
        $this->previous = Container::getInstance(); $this->previousFacade = Facade::getFacadeApplication();
        $this->clock = Carbon::getTestNow(); Carbon::setTestNow(Carbon::parse('2026-10-26 12:00:00.123456', 'UTC'));
        $this->database = 'sms_import_sender_test_'.bin2hex(random_bytes(8));
        self::assertMatchesRegularExpression('/\Asms_import_sender_test_[a-f0-9]{16}\z/D', $this->database);
        self::$admin->exec('CREATE DATABASE ['.$this->database.'] COLLATE SQL_Latin1_General_CP1_CI_AS');
        $this->root = str_replace('\\', '/', sys_get_temp_dir()).'/sms-import-sender-sqlserver-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->root, 0700));
        $app = new Container; Container::setInstance($app);
        $app->instance('config', new Repository(['database' => ['default' => 'sqlsrv'], 'sms-exports' => [
            'local_root' => $this->root, 'debtplete_import_enabled' => true, 'debtplete_import_automatic' => false]]));
        $capsule = new Manager($app);
        $capsule->addConnection(['driver' => 'sqlsrv', 'host' => '127.0.0.1,14330', 'database' => $this->database,
            'username' => 'sa', 'password' => 'LocalDev!2026Pass', 'encrypt' => 'no', 'trust_server_certificate' => 'yes'], 'sqlsrv');
        $capsule->getDatabaseManager()->setDefaultConnection('sqlsrv'); $app->instance('db', $capsule->getDatabaseManager());
        Facade::clearResolvedInstances(); Facade::setFacadeApplication($app);
        $this->db = $capsule->getConnection('sqlsrv'); $this->db->setTransactionManager(new DatabaseTransactionsManager);
        self::assertSame($this->database, $this->db->selectOne('SELECT DB_NAME() AS name')->name);
        $this->db->unprepared('SET LOCK_TIMEOUT 3000; SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $this->db->unprepared('CREATE TABLE dbo.TblSMSRuns (Request_ID uniqueidentifier NOT NULL PRIMARY KEY,
            Status nvarchar(32) NULL, Selection_Policy nvarchar(64) NULL, Target int NOT NULL, Selected int NOT NULL,
            Approved_By nvarchar(255) NULL, Approved_At datetime2(7) NULL, Sent_By nvarchar(255) NULL, Sent_At datetime2(7) NULL,
            Run_Options_JSON nvarchar(max) NULL, Artifact_Manifest_JSON nvarchar(max) NULL, File_Path nvarchar(max) NULL,
            File_Bytes bigint NOT NULL, File_Sha256 nvarchar(64) NULL, File_Cleanup_Path nvarchar(max) NULL)');
        $options = SmsRunOptions::normalize(3, 2, ['T1'], SmsRunOptions::WINDOWED_TIER_SELECTION_POLICY,
            SmsRunOptions::PLANNED_DATE_VERSION, '2020-01-13', SmsRunOptions::APPROVAL_FILENAME_VERSION);
        $directory = $this->root.'/runs/'.self::ID;
        $rows = [['Synthetic one', '60000', '0555010101', '2020-01-13'], ['Synthetic two', '60000', '0555010101', '2020-01-13'], ['Synthetic three', '60000', '0555010102', '2020-01-13']];
        $pending = (new SmsRunArtifactBundle)->build($directory, 'SMS Export - 2020-01-13 - ID Pending', $rows, 3, $options);
        $this->bundle = (new SmsRunArtifactBundle('v2'))->publishApproved($directory, $pending['manifest'], $options, 3, 'SMS0001-SMS0003');
        $this->db->table('TblSMSRuns')->insert(['Request_ID' => self::ID, 'Status' => 'approved', 'Selection_Policy' => 'prospect-record-v1',
            'Target' => 3, 'Selected' => 3, 'Approved_By' => 'fixture@example.invalid', 'Approved_At' => '2020-01-06 12:34:56.1234567',
            'Sent_By' => 'fixture@example.invalid', 'Sent_At' => '2020-01-06 12:34:56.1234567',
            'Run_Options_JSON' => json_encode($options, JSON_THROW_ON_ERROR), 'Artifact_Manifest_JSON' => json_encode($this->bundle['manifest'], JSON_THROW_ON_ERROR),
            'File_Path' => $this->bundle['path'], 'File_Bytes' => $this->bundle['bytes'], 'File_Sha256' => $this->bundle['sha256']]);
        $this->gateway = new SqlServerImportClosedGateway($this->db);
        $this->workflow = new SmsRunDebtPleteImportWorkflow($this->gateway, new SmsRunArtifactPartReader);
    }

    protected function tearDown(): void
    {
        // Keep small private synthetic artifact/evidence files, including failures, for review.
        $evidence = ['database' => $this->database ?? null, 'database_dropped' => false];
        if (isset($this->root, $this->db)) {
            try {
                if ($this->db->getPdo()->inTransaction()) $this->db->getPdo()->rollBack();
                $source = $this->source();
                $evidence += ['driver' => $this->db->getDriverName(), 'source_request_id' => $source->Request_ID,
                    'approval_at' => $source->Approved_At, 'closed_calls' => isset($this->gateway) ? $this->gateway->calls : []];
                $type = $this->db->selectOne("SELECT TYPE_NAME(user_type_id) AS type,scale FROM sys.columns WHERE object_id=OBJECT_ID('dbo.TblSMSDebtPleteImports') AND name='Created_At'");
                $evidence['outbox_created_at_type'] = $type === null ? null : (array) $type;
                if ($type !== null) {
                    try {
                        // Read-only provider probe: the workflow submits this exact timestamp shape.
                        $this->db->selectOne('SELECT CONVERT('.($type->type === 'datetime' ? 'datetime' : 'datetime2(6)').', ?) AS value', ['2026-10-26 12:00:00.123456']);
                        $evidence['microsecond_parameter_accepted'] = true;
                    } catch (\Illuminate\Database\QueryException $error) {
                        $evidence['microsecond_parameter_accepted'] = false;
                        $evidence['timestamp_error_sqlstate'] = $error->errorInfo[0] ?? null;
                        $evidence['timestamp_error_native'] = $error->errorInfo[1] ?? null;
                    }
                }
            } catch (\Throwable $error) { $evidence['evidence_error_class'] = $error::class; }
            $this->db->disconnect();
        }
        if (isset($this->database) && preg_match('/\Asms_import_sender_test_[a-f0-9]{16}\z/D', $this->database)) {
            self::assertSame('master', self::$admin->query('SELECT DB_NAME()')->fetchColumn());
            self::$admin->exec('ALTER DATABASE ['.$this->database.'] SET SINGLE_USER WITH ROLLBACK IMMEDIATE');
            self::$admin->exec('DROP DATABASE ['.$this->database.']');
            $check = self::$admin->prepare('SELECT DB_ID(?)'); $check->execute([$this->database]); self::assertNull($check->fetchColumn());
            $evidence['database_dropped'] = true;
        }
        if (isset($this->root)) {
            file_put_contents($this->root.'/native-evidence.json', json_encode($evidence, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
            chmod($this->root.'/native-evidence.json', 0600);
        }
        if (isset($this->previous)) {
            Carbon::setTestNow($this->clock); Facade::clearResolvedInstances(); Facade::setFacadeApplication($this->previousFacade);
            Container::setInstance($this->previous);
        }
    }

    private function source(): object { return $this->db->table('TblSMSRuns')->first(); }
    private function install(): object
    {
        $migration = require dirname(__DIR__, 2).'/database/sms-migrations/2026_10_25_000001_create_sms_debtplete_imports.php';
        $migration->up(); return $migration;
    }

    public function test_explicit_native_install_preserves_source_and_refuses_repeated_install(): void
    {
        $source = (array) $this->source(); $migration = $this->install();
        $type = $this->db->selectOne("SELECT TYPE_NAME(user_type_id) AS type, scale FROM sys.columns WHERE object_id=OBJECT_ID('dbo.TblSMSRuns') AND name='Approved_At'");
        self::assertSame('datetime2', $type->type); self::assertSame(7, (int) $type->scale);
        self::assertSame('uniqueidentifier', $this->db->selectOne("SELECT TYPE_NAME(user_type_id) AS type FROM sys.columns WHERE object_id=OBJECT_ID('dbo.TblSMSRuns') AND name='Request_ID'")->type);
        foreach (['Created_At', 'Updated_At', 'Lease_Until', 'Completed_At'] as $column) {
            $stored = $this->db->selectOne("SELECT TYPE_NAME(user_type_id) AS type,scale FROM sys.columns WHERE object_id=OBJECT_ID('dbo.TblSMSDebtPleteImports') AND name=?", [$column]);
            self::assertSame('datetime2', $stored->type, $column.' must accept the actual workflow timestamp shape.');
            self::assertSame(6, (int) $stored->scale);
        }
        self::assertSame(2, (int) $this->db->selectOne('SELECT COUNT(*) AS n FROM sys.tables')->n);
        self::assertSame(0, $this->db->table(SmsRunDebtPleteImportWorkflow::TABLE)->count());
        try { $migration->up(); self::fail('Repeated install must refuse.'); } catch (RuntimeException) {}
        self::assertSame($source, (array) $this->source()); self::assertSame(0, $this->db->transactionLevel());
    }

    public function test_native_uuid_and_seven_digit_approval_produce_canonical_transport_without_rewriting_fingerprint(): void
    {
        $source = $this->source(); self::assertSame(self::ID, strtolower($source->Request_ID));
        self::assertSame('2020-01-06 12:34:56.1234567', $source->Approved_At);
        $plan = SmsDebtPleteImportPlan::fromApproved($source, $this->gateway->destination);
        self::assertSame(self::ID, $plan->requestId()); self::assertSame('2020-01-06T12:34:56.1234567Z', $plan->payload()['approval']['at']);
        $values = [];
        foreach (['Request_ID', 'Status', 'Selection_Policy', 'Target', 'Selected', 'Approved_By', 'Approved_At', 'Sent_By', 'Sent_At',
            'Run_Options_JSON', 'Artifact_Manifest_JSON', 'File_Path', 'File_Bytes', 'File_Sha256', 'File_Cleanup_Path'] as $field) $values[$field] = $source->$field ?? null;
        self::assertSame(hash('sha256', json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), $plan->fingerprint());
        self::assertSame((array) $source, (array) $this->source());
    }

    public function test_native_approval_capture_is_atomic_with_owner_transaction_and_never_opens_artifact(): void
    {
        $this->install(); config(['sms-exports.debtplete_import_automatic' => true]); $source = (array) $this->source();
        self::assertTrue(rename($this->bundle['path'], $this->bundle['path'].'.retained'));
        try {
            $this->db->beginTransaction();
            $this->workflow->captureApproved($this->source());
            self::assertSame(1, $this->db->table(SmsRunDebtPleteImportWorkflow::TABLE)->count());
            $this->db->rollBack(); self::assertSame(0, $this->db->table(SmsRunDebtPleteImportWorkflow::TABLE)->count());
            $this->db->beginTransaction(); $this->workflow->captureApproved($this->source()); $this->db->commit();
            self::assertSame('queued', $this->workflow->status(self::ID)['status']);
            self::assertSame(1, $this->db->table(SmsRunDebtPleteImportWorkflow::TABLE)->count());
            self::assertSame($source, (array) $this->source()); self::assertSame([], $this->gateway->calls);
        } finally {
            if ($this->db->transactionLevel() > 0) $this->db->rollBack();
            rename($this->bundle['path'].'.retained', $this->bundle['path']);
        }
    }

    public function test_native_two_part_progress_and_completed_replay_preserve_all_source_and_outbox_bytes(): void
    {
        $this->install(); $source = (array) $this->source();
        self::assertSame('queued', $this->workflow->prepare(self::ID, 'fixture@example.invalid')['status']);
        self::assertSame([self::ID], $this->workflow->pending());
        $one = $this->workflow->step(self::ID);
        self::assertSame('pending', $one['status']); self::assertSame(1, $one['files_accepted']); self::assertSame(0, $one['processed']);
        $two = $this->workflow->step(self::ID);
        self::assertSame('completed', $two['status']); self::assertSame(3, $two['processed']); self::assertSame(2, $two['files_accepted']);
        self::assertSame('2020-01-06T12:34:56.1234567Z', $this->gateway->payload['approval']['at']);
        self::assertSame([1, 2], array_column(array_values(array_filter($this->gateway->calls, static fn (array $c): bool => $c[0] === 'upload')), 2));
        $saved = (array) $this->db->table(SmsRunDebtPleteImportWorkflow::TABLE)->first(); $calls = $this->gateway->calls;
        self::assertSame(2, (int) $saved['Lease_Version']); self::assertNull($saved['Lease_Until']); self::assertNotNull($saved['Completed_At']);
        self::assertSame($two, $this->workflow->step(self::ID)); self::assertSame($two, $this->workflow->prepare(self::ID, 'other@example.invalid'));
        self::assertSame($two, $this->workflow->status(self::ID)); self::assertSame([], $this->workflow->pending());
        self::assertSame($saved, (array) $this->db->table(SmsRunDebtPleteImportWorkflow::TABLE)->first());
        self::assertSame($source, (array) $this->source()); self::assertSame($calls, $this->gateway->calls);
        self::assertSame($this->bundle['sha256'], hash_file('sha256', $this->bundle['path']));
        self::assertSame(0, $this->db->transactionLevel()); self::assertFalse($this->db->getPdo()->inTransaction());
    }
}
