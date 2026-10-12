<?php

use Cmd\Reports\Http\Controllers\SmsExportDownloadController;
use Cmd\Reports\Services\SmsExportArtifacts;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class SmsExportArtifactsTest extends TestCase
{
    private const ID = 'd983d923-1d9d-4099-a80c-1d9d7e5fdb7d';
    private Container $previousContainer;
    private Container $container;
    private string $root;
    private string $source;
    private SmsExportArtifacts $artifacts;
    private UrlGenerator $urls;
    private Manager $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $this->container = new Container;
        Container::setInstance($this->container);
        $this->root = sys_get_temp_dir().'/sms-artifact-test-'.bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
        $this->source = $this->root.'/source';
        file_put_contents($this->source, "PK\x03\x04\0binary SMS ZIP\xff\n");
        $this->container->instance('config', new Repository([
            'sms-exports' => ['local_root' => $this->root.'/retained'],
            'app' => ['url' => 'https://cmd.example.test'],
        ]));
        $routes = new RouteCollection;
        $routes->add((new Route(['GET'], 'api/cmd/mail-drop-export-report/download', SmsExportDownloadController::class))->name('cmd.sms_export.download'));
        $this->urls = new UrlGenerator($routes, Request::create('https://gateway.example.test/api/cmd'));
        $this->urls->setKeyResolver(fn () => 'test-signing-key');
        $this->container->instance('url', $this->urls);
        $this->db = new Manager($this->container);
        $this->db->addConnection(['driver' => 'sqlite', 'database' => ':memory:'], 'sqlsrv');
        $this->container->instance('db', $this->db->getDatabaseManager());
        $this->container->instance('validator', new Factory(new Translator(new ArrayLoader, 'en'), $this->container));
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->container);
        $schema = $this->db->getConnection('sqlsrv')->getSchemaBuilder();
        $schema->create('TblSmsExportRequests', function (Blueprint $table): void {
            $table->string('Request_ID')->primary(); $table->string('Status');
            $table->string('Artifact_Key'); $table->string('Artifact_Format');
            $table->integer('Artifact_Bytes'); $table->integer('SMS_Count'); $table->integer('Part_Count');
        });
        $schema->create('TblSmsExports', function (Blueprint $table): void {
            $table->string('Request_ID'); $table->integer('SMS_Count');
        });
        $this->artifacts = new SmsExportArtifacts;
    }

    protected function tearDown(): void
    {
        // Remove only the random directory this test created, never host storage.
        foreach (glob($this->root.'/retained/*', GLOB_ONLYDIR) ?: [] as $dir) {
            foreach (glob($dir.'/*') ?: [] as $file) unlink($file);
            foreach (glob($dir.'/.artifact-*') ?: [] as $file) unlink($file);
            foreach (glob($dir.'/.manifest-*') ?: [] as $file) unlink($file);
            rmdir($dir);
        }
        if (is_dir($this->root.'/retained')) rmdir($this->root.'/retained');
        if (is_file($this->source)) unlink($this->source);
        rmdir($this->root);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousContainer);
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    private function publish(): array
    {
        return $this->artifacts->publish(self::ID, ['path' => $this->source, 'format' => 'zip', 'count' => 2000000, 'part_count' => 2]);
    }

    public function test_private_local_storage_needs_no_bucket_and_survives_scratch_cleanup(): void
    {
        self::assertTrue($this->artifacts->configured());
        $published = $this->publish();
        self::assertSame('local:'.self::ID.'.zip', $published['key']);
        $original = file_get_contents($this->source);
        unlink($this->source);
        self::assertSame($original, file_get_contents($this->artifacts->localPath($published['key'])));
        self::assertSame(2000000, $this->artifacts->verifyLocal($published['key'], $published['bytes'])['count']);
        self::assertSame(2, $this->artifacts->recover(self::ID, 2000000)['part_count']);
    }

    public function test_signed_url_uses_cmd_origin_and_rejects_tampering_and_expiry(): void
    {
        $published = $this->publish();
        $url = $this->artifacts->temporaryUrl($published['key'], $published['bytes']);
        self::assertStringStartsWith('https://cmd.example.test/api/cmd/mail-drop-export-report/download?', $url);
        self::assertTrue($this->urls->hasValidSignature(Request::create($url), false));
        self::assertFalse($this->urls->hasValidSignature(Request::create(str_replace(self::ID, 'a983d923-1d9d-4099-a80c-1d9d7e5fdb7d', $url)), false));
        $expired = $this->urls->temporarySignedRoute('cmd.sms_export.download', now()->subMinute(), ['request_id' => self::ID], false);
        self::assertFalse($this->urls->hasValidSignature(Request::create('https://cmd.example.test'.$expired), false));
    }

    public function test_same_size_corruption_is_detected(): void
    {
        $published = $this->publish();
        $path = $this->artifacts->localPath($published['key']);
        file_put_contents($path, str_repeat('x', $published['bytes']));
        // Status checks must not hash a potentially large ZIP on every poll.
        // Full verification still refuses the corrupt artifact before download.
        self::assertStringStartsWith('https://cmd.example.test/', $this->artifacts->temporaryUrl($published['key'], $published['bytes']));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('differs from its retained manifest');
        $this->artifacts->verifyLocal($published['key'], $published['bytes']);
    }

    public function test_package_config_can_load_before_a_host_storage_path_is_available(): void
    {
        $withoutDisk = require __DIR__.'/../../config/sms-exports.php';
        self::assertNull($withoutDisk['local_root']);
        $this->container->make('config')->set('filesystems.disks.local.root', $this->root.'/private');
        $withDisk = require __DIR__.'/../../config/sms-exports.php';
        self::assertSame($this->root.'/private/sms-exports', $withDisk['local_root']);
    }

    public function test_private_files_preserve_existing_storage_group_for_web_downloads(): void
    {
        if (PHP_OS_FAMILY === 'Windows') self::markTestSkipped('Windows ACLs do not expose POSIX storage groups.');
        $expectedGroup = filegroup($this->root);
        $published = $this->publish();
        $path = $this->artifacts->localPath($published['key']);
        self::assertSame($expectedGroup, filegroup($path));
        self::assertSame($expectedGroup, filegroup(dirname($path)));
        self::assertSame(0640, fileperms($path) & 0777);
        self::assertSame(0750, fileperms(dirname($path)) & 0777);
    }

    public function test_conflicting_retry_preserves_original_retained_file(): void
    {
        $published = $this->publish();
        $original = file_get_contents($this->artifacts->localPath($published['key']));
        file_put_contents($this->source, str_repeat('x', $published['bytes']));
        try {
            $this->publish();
            self::fail('A conflicting artifact must not overwrite the recorded file.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('different retained SMS file', $error->getMessage());
        }
        self::assertSame($original, file_get_contents($this->artifacts->localPath($published['key'])));
    }

    public function test_request_keys_cannot_escape_private_storage(): void
    {
        $this->expectException(RuntimeException::class);
        $this->artifacts->localPath('local:../../public/leads.csv');
    }

    public function test_recovery_rejects_a_count_that_differs_from_tracking(): void
    {
        $this->publish();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('differs from recorded tracking');
        $this->artifacts->recover(self::ID, 1999999);
    }

    public function test_download_returns_unmodified_zip_only_for_recorded_ready_export(): void
    {
        $published = $this->publish();
        $connection = $this->db->getConnection('sqlsrv');
        $connection->table('TblSmsExportRequests')->insert(['Request_ID' => self::ID, 'Status' => 'ready',
            'Artifact_Key' => $published['key'], 'Artifact_Format' => 'zip', 'Artifact_Bytes' => $published['bytes'],
            'SMS_Count' => 2000000, 'Part_Count' => 2]);
        $connection->table('TblSmsExports')->insert(['Request_ID' => self::ID, 'SMS_Count' => 2000000]);
        $response = (new SmsExportDownloadController)(Request::create('/?request_id='.self::ID), $this->artifacts);
        self::assertSame('application/zip', $response->headers->get('Content-Type'));
        self::assertSame(file_get_contents($this->source), file_get_contents($response->getFile()->getPathname()));
        $connection->table('TblSmsExports')->delete();
        try {
            (new SmsExportDownloadController)(Request::create('/?request_id='.self::ID), $this->artifacts);
            self::fail('Missing tracking must prevent download.');
        } catch (HttpException $error) {
            self::assertSame(409, $error->getStatusCode());
        }
    }

    public function test_legacy_s3_download_and_recovery_remain_supported_without_network(): void
    {
        $this->container->make('config')->set('filesystems.disks.s3', ['bucket' => 'legacy-private-bucket', 'region' => 'us-east-2']);
        $handler = new \Aws\MockHandler([
            new \Aws\Result(['ContentLength' => 42]),
            new \Aws\Result(['ContentLength' => 42, 'Metadata' => ['request-id' => self::ID, 'sms-count' => '25', 'part-count' => '1']]),
        ]);
        $client = new \Aws\S3\S3Client(['version' => 'latest', 'region' => 'us-east-2',
            'credentials' => ['key' => 'test-key', 'secret' => 'test-secret'], 'handler' => $handler]);
        $legacy = new class($client) extends SmsExportArtifacts {
            public function __construct(private \Aws\S3\S3Client $fakeClient) {}
            protected function client(): \Aws\S3\S3Client { return $this->fakeClient; }
        };
        $url = $legacy->temporaryUrl('sms-exports/'.self::ID.'.csv', 42);
        self::assertStringContainsString('legacy-private-bucket.s3.us-east-2.amazonaws.com', $url);
        self::assertStringContainsString('X-Amz-Expires=900', $url);
        self::assertSame('sms-exports/'.self::ID.'.csv', $legacy->recover(self::ID, 25)['key']);
    }
}
