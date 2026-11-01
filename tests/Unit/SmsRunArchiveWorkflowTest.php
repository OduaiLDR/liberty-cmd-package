<?php

declare(strict_types=1);

use Cmd\Reports\Repositories\SmsProspectExportRepository;
use Cmd\Reports\Services\SmsRunArchiveStore;
use Cmd\Reports\Services\SmsRunArchiveWorkflow;
use Cmd\Reports\Services\SmsRunArtifactBundle;
use Cmd\Reports\Services\SmsRunOptions;
use Cmd\Reports\Services\SmsRunService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class SmsWorkflowArchiveFixtureStore implements SmsRunArchiveStore
{
    public array $objects = [];
    public int $puts = 0;
    public int $io = 0;
    public string $storeId = 'fixture-store-v1';
    public ?Closure $afterPut = null;
    public ?Closure $beforeRead = null;

    public function identity(): string { return $this->storeId; }
    private function outsideSql(): void
    {
        $this->io++;
        $connection = Illuminate\Support\Facades\DB::connection('sqlsrv');
        if ($connection->transactionLevel() !== 0 || $connection->getPdo()->inTransaction()) {
            throw new RuntimeException('Provider called inside SQL transaction.');
        }
    }
    public function putIfAbsent(string $key, string $sourcePath, array $metadata): string
    {
        $this->outsideSql();
        $bytes = file_get_contents($sourcePath);
        if (isset($this->objects[$key])) {
            if ($this->objects[$key]['bytes'] !== $bytes || $this->objects[$key]['metadata'] !== $metadata) throw new RuntimeException('Archive create conflict.');
            return $this->objects[$key]['version'];
        }
        $version = 'fixture-version-'.++$this->puts;
        $this->objects[$key] = compact('bytes', 'metadata', 'version');
        if ($this->afterPut !== null) ($this->afterPut)($this->puts);
        return $version;
    }
    public function metadata(string $key, string $version): ?array
    {
        $this->outsideSql();
        $object = $this->objects[$key] ?? null;
        return $object === null || $object['version'] !== $version ? null : ['bytes' => strlen($object['bytes']), 'metadata' => $object['metadata']];
    }
    public function read(string $key, string $version): mixed
    {
        $this->outsideSql();
        if ($this->beforeRead !== null) ($this->beforeRead)();
        $object = $this->objects[$key] ?? null;
        if ($object === null || $object['version'] !== $version) return null;
        $stream = fopen('php://temp', 'w+b'); fwrite($stream, $object['bytes']); rewind($stream);
        return $stream;
    }
}

// Fresh in-memory database plus freshly owned temporary bundle. No host .env or network.
beforeEach(function (): void {
    $this->previousArchiveContainer = Container::getInstance();
    $container = new class extends Container {
        public function environment(...$values): string|bool { return $values === [] ? 'testing' : false; }
        public function abort($code, $message = '', array $headers = []): never { throw new HttpException($code, $message, null, $headers); }
    };
    Container::setInstance($container);
    $this->archiveRoot = str_replace('\\', '/', sys_get_temp_dir()).'/sms-archive-flow-'.bin2hex(random_bytes(8));
    mkdir($this->archiveRoot, 0700);
    $container->instance('config', new Repository(['sms-exports' => [
        'local_root' => $this->archiveRoot, 'run_archive_enabled' => true,
        'run_archive_policy_sha256' => str_repeat('a', 64), 'run_archive_prefix' => 'fixture/private',
    ], 'database' => ['default' => 'sqlsrv']]));
    $capsule = new Manager($container);
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:'], 'sqlsrv');
    $capsule->getDatabaseManager()->setDefaultConnection('sqlsrv');
    $container->instance('db', $capsule->getDatabaseManager());
    Facade::clearResolvedInstances(); Facade::setFacadeApplication($container);
    $this->archiveDb = $capsule->getConnection('sqlsrv');
    $this->archiveDb->getSchemaBuilder()->create('TblPhoneNumbers', fn (Blueprint $table) => $table->string('Phone'));
    foreach (['2026_10_12_000001_create_sms_prospects_tables.php', '2026_10_19_000001_complete_sms_workflow.php',
        '2026_10_20_000001_create_sms_availability_summary.php', '2026_10_20_000003_add_sms_record_approval.php',
        '2026_10_22_000001_add_sms_run_file_options.php', '2026_10_23_000002_add_sms_run_archive_receipt.php'] as $migration) {
        (require dirname(__DIR__, 2).'/database/sms-migrations/'.$migration)->up();
    }
    $this->archiveId = '11111111-2222-4333-8444-555555555555';
    $this->archiveDirectory = $this->archiveRoot.'/runs/'.$this->archiveId;
    $this->archiveOptions = SmsRunOptions::normalize(3, 2);
    $rows = [['Synthetic One', '14000', '2025550100', '2026-10-26'],
        ['Synthetic Two', '14000', '2025550101', '2026-10-26'], ['Synthetic Three', '14000', '2025550102', '2026-10-26']];
    $this->archiveBundle = (new SmsRunArtifactBundle)->build($this->archiveDirectory, 'sms-fixture', $rows, 3, $this->archiveOptions);
    $this->archiveManifestPath = dirname($this->archiveBundle['path']).'/manifest.json';
    // Different original JSON encoding proves restore uses original bytes rather than re-encoding SQL JSON.
    $this->archiveOriginalJson = json_encode($this->archiveBundle['manifest'], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
    file_put_contents($this->archiveManifestPath, $this->archiveOriginalJson);
    $this->archiveDb->table('TblSMSRuns')->insert(['Request_ID' => $this->archiveId, 'Target' => 3, 'Selected' => 3,
        'Status' => 'approved', 'Selection_Policy' => 'prospect-record-v1', 'Created_By' => 'fixture@example.invalid',
        'Created_At' => '2026-10-21 18:00:00', 'Updated_At' => '2026-10-21 18:00:00',
        'Approved_By' => 'fixture@example.invalid', 'Approved_At' => '2026-10-21 18:11:15',
        'Sent_By' => 'fixture@example.invalid', 'Sent_At' => '2026-10-21 18:11:15',
        'Run_Options_JSON' => json_encode($this->archiveOptions), 'Artifact_Manifest_JSON' => json_encode($this->archiveBundle['manifest']),
        'File_Path' => $this->archiveBundle['path'], 'File_Bytes' => $this->archiveBundle['bytes'], 'File_Sha256' => $this->archiveBundle['sha256']]);
    $this->archiveStore = new SmsWorkflowArchiveFixtureStore;
    $this->archiveWorkflow = new SmsRunArchiveWorkflow($this->archiveStore);
});

afterEach(function (): void {
    if (isset($this->archiveDb)) while ($this->archiveDb->transactionLevel() > 0) $this->archiveDb->rollBack();
    $root = realpath($this->archiveRoot); $temp = str_replace('\\', '/', realpath(sys_get_temp_dir()));
    if ($root !== false && ! is_link($this->archiveRoot)
        && str_starts_with(str_replace('\\', '/', $root), $temp.'/sms-archive-flow-')) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) { if ($file->isDir() && ! $file->isLink()) rmdir($file->getPathname()); else unlink($file->getPathname()); }
        rmdir($root);
    }
    Facade::clearResolvedInstances(); Container::setInstance($this->previousArchiveContainer); Facade::setFacadeApplication($this->previousArchiveContainer);
});

test('approved archive stores both exact objects and a durable replayable receipt without business writes', function (): void {
    $before = (array) $this->archiveDb->table('TblSMSRuns')->first();
    $receipt = $this->archiveWorkflow->archive($this->archiveId);
    expect($this->archiveStore->puts)->toBe(2)
        ->and($this->archiveStore->objects[$receipt['manifest']['key']]['bytes'])->toBe($this->archiveOriginalJson)
        ->and($this->archiveStore->objects[$receipt['primary']['key']]['bytes'])->toBe(file_get_contents($this->archiveBundle['path']))
        ->and(json_decode($this->archiveDb->table('TblSMSRuns')->value('Archive_Receipt_JSON'), true))->toBe($receipt)
        ->and($this->archiveWorkflow->archive($this->archiveId))->toBe($receipt)
        ->and($this->archiveWorkflow->verify($this->archiveId))->toBe($receipt)
        ->and($this->archiveStore->puts)->toBe(2);
    $after = (array) $this->archiveDb->table('TblSMSRuns')->first(); unset($before['Archive_Receipt_JSON'], $after['Archive_Receipt_JSON']);
    expect($after)->toBe($before)->and($this->archiveDb->table('TblSMSHistory')->count())->toBe(0)
        ->and($this->archiveDb->table('TblSMSAttemptAudit')->count())->toBe(0);
});

test('planned-date CSV and ZIP names archive and restore unchanged with safe provider keys', function (int $cap, bool $dropRange): void {
    $root = $this->archiveRoot.'/planned';
    config(['sms-exports.local_root' => $root]);
    $directory = $root.'/runs/'.$this->archiveId;
    $options = SmsRunOptions::normalize(3, $cap, null, null, SmsRunOptions::PLANNED_DATE_VERSION, '2026-10-27', $dropRange ? SmsRunOptions::DROP_RANGE_FILENAME_VERSION : null);
    $rows = [['Synthetic One', '14000', '2025550100', '2026-10-27'],
        ['Synthetic Two', '14000', '2025550101', '2026-10-27'], ['Synthetic Three', '14000', '2025550102', '2026-10-27']];
    $bundle = (new SmsRunArtifactBundle)->build($directory, 'SMS Export - 2026-10-27'.($dropRange ? ' - SMS0001-SMS0009' : ''), $rows, 3, $options);
    $this->archiveDb->table('TblSMSRuns')->where('Request_ID', $this->archiveId)->update([
        'Run_Options_JSON' => json_encode($options), 'Artifact_Manifest_JSON' => json_encode($bundle['manifest']),
        'File_Path' => $bundle['path'], 'File_Bytes' => $bundle['bytes'], 'File_Sha256' => $bundle['sha256'],
    ]);
    $receipt = $this->archiveWorkflow->archive($this->archiveId);
    expect($receipt['primary']['key'])->toBe('fixture/private/'.$this->archiveId.'/v1/'.basename($bundle['path']));
    $original = file_get_contents($bundle['path']);
    unlink($bundle['path']); unlink(dirname($bundle['path']).'/manifest.json');
    $restored = $this->archiveWorkflow->restore($this->archiveId);
    expect($restored)->toBe($bundle['path'])->and(file_get_contents($restored))->toBe($original)
        ->and($this->archiveWorkflow->verify($this->archiveId))->toBe($receipt);
    $view = (new SmsRunService(new SmsProspectExportRepository))->show($this->archiveId);
    expect($view->file['files'])->toHaveCount($cap === 2 ? 2 : 1)
        ->and($view->handoff['email_preview']['available'])->toBeTrue()
        ->and($view->handoff['debtplete_import']['status'])->toBe('not_configured');
})->with([[2, false], [5, false], [2, true], [5, true]]);

test('interrupted archive resumes the same objects before publishing its complete receipt', function (int $interruptAfter): void {
    $this->archiveStore->afterPut = static function (int $count) use ($interruptAfter): void {
        if ($count === $interruptAfter) throw new RuntimeException('Synthetic lost upload acknowledgement.');
    };
    expect(fn () => $this->archiveWorkflow->archive($this->archiveId))->toThrow(RuntimeException::class)
        ->and($this->archiveDb->table('TblSMSRuns')->value('Archive_Receipt_JSON'))->toBeNull();
    $this->archiveStore->afterPut = null;
    $receipt = $this->archiveWorkflow->archive($this->archiveId);
    expect($this->archiveStore->puts)->toBe(2)->and($this->archiveWorkflow->verify($this->archiveId))->toBe($receipt);
})->with([1, 2]);

test('approval-time archives pin only the final v2 generation and leave pending v1 unchanged', function (int $cap): void {
    $root = $this->archiveRoot.'/approval';
    config(['sms-exports.local_root' => $root]);
    $directory = $root.'/runs/'.$this->archiveId;
    $options = SmsRunOptions::normalize(3, $cap, null, null, SmsRunOptions::PLANNED_DATE_VERSION, '2026-10-27', SmsRunOptions::APPROVAL_FILENAME_VERSION);
    $rows = [['Synthetic One', '14000', '02025550100', '2026-10-27'], ['Synthetic Two', '14000', '02025550101', '2026-10-27'], ['Synthetic Three', '14000', '02025550102', '2026-10-27']];
    $pending = (new SmsRunArtifactBundle)->build($directory, 'SMS Export - 2026-10-27 - ID Pending', $rows, 3, $options);
    $pendingBytes = file_get_contents($pending['path']);
    $final = (new SmsRunArtifactBundle('v2'))->publishApproved($directory, $pending['manifest'], $options, 3, 'SMS0001-SMS0009');
    $this->archiveDb->table('TblSMSRuns')->where('Request_ID', $this->archiveId)->update([
        'Run_Options_JSON' => json_encode($options), 'Artifact_Manifest_JSON' => json_encode($final['manifest']),
        'File_Path' => $final['path'], 'File_Bytes' => $final['bytes'], 'File_Sha256' => $final['sha256'],
    ]);
    $receipt = $this->archiveWorkflow->archive($this->archiveId);
    expect($receipt['primary']['key'])->toBe('fixture/private/'.$this->archiveId.'/v2/'.basename($final['path']))
        ->and($receipt['manifest']['key'])->toBe('fixture/private/'.$this->archiveId.'/v2/manifest.json')
        ->and($this->archiveWorkflow->archive($this->archiveId))->toBe($receipt)
        ->and($this->archiveStore->puts)->toBe(2)
        ->and(file_get_contents($pending['path']))->toBe($pendingBytes);
    $finalBytes = file_get_contents($final['path']);
    unlink($final['path']); unlink(dirname($final['path']).'/manifest.json');
    expect($this->archiveWorkflow->restore($this->archiveId))->toBe($final['path'])
        ->and(file_get_contents($final['path']))->toBe($finalBytes)
        ->and(file_get_contents($pending['path']))->toBe($pendingBytes)
        ->and($this->archiveWorkflow->verify($this->archiveId))->toBe($receipt);
})->with([2, 5]);

test('approval-time archive rejects a pending generation pointer before any provider call', function (): void {
    $options = SmsRunOptions::normalize(3, 2, null, null, SmsRunOptions::PLANNED_DATE_VERSION, '2026-10-27', SmsRunOptions::APPROVAL_FILENAME_VERSION);
    $directory = $this->archiveRoot.'/pending/runs/'.$this->archiveId;
    config(['sms-exports.local_root' => $this->archiveRoot.'/pending']);
    $rows = [['One', '14000', '2025550100', '2026-10-27'], ['Two', '14000', '2025550101', '2026-10-27'], ['Three', '14000', '2025550102', '2026-10-27']];
    $pending = (new SmsRunArtifactBundle)->build($directory, 'SMS Export - 2026-10-27 - ID Pending', $rows, 3, $options);
    $this->archiveDb->table('TblSMSRuns')->where('Request_ID', $this->archiveId)->update([
        'Run_Options_JSON' => json_encode($options), 'Artifact_Manifest_JSON' => json_encode($pending['manifest']),
        'File_Path' => $pending['path'], 'File_Bytes' => $pending['bytes'], 'File_Sha256' => $pending['sha256'],
    ]);
    expect(fn () => $this->archiveWorkflow->archive($this->archiveId))->toThrow(RuntimeException::class)
        ->and($this->archiveStore->io)->toBe(0)->and($this->archiveStore->puts)->toBe(0);
});

test('archive receipt write failure rolls back and retries the already verified object versions', function (): void {
    $interrupt = true;
    $this->archiveDb->beforeExecuting(static function (string $sql) use (&$interrupt): void {
        if ($interrupt && str_starts_with($sql, 'update') && str_contains($sql, 'Archive_Receipt_JSON')) {
            $interrupt = false; throw new RuntimeException('Synthetic SQL receipt write failure.');
        }
    });
    expect(fn () => $this->archiveWorkflow->archive($this->archiveId))->toThrow(RuntimeException::class)
        ->and($this->archiveDb->transactionLevel())->toBe(0)
        ->and($this->archiveDb->table('TblSMSRuns')->value('Archive_Receipt_JSON'))->toBeNull();
    $receipt = $this->archiveWorkflow->archive($this->archiveId);
    expect($this->archiveStore->puts)->toBe(2)->and($this->archiveWorkflow->verify($this->archiveId))->toBe($receipt);
});

test('restore retains exact manifest and primary bytes and existing download verification accepts them', function (): void {
    $receipt = $this->archiveWorkflow->archive($this->archiveId);
    unlink($this->archiveBundle['path']); unlink($this->archiveManifestPath);
    rmdir(dirname($this->archiveManifestPath)); rmdir($this->archiveDirectory.'/v1');
    $restored = $this->archiveWorkflow->restore($this->archiveId);
    expect($restored)->toBe($this->archiveBundle['path'])->and(file_get_contents($this->archiveManifestPath))->toBe($this->archiveOriginalJson)
        ->and(hash_file('sha256', $restored))->toBe($receipt['primary']['sha256'])
        ->and((new SmsRunService(new SmsProspectExportRepository))->filePath($this->archiveId))->toBe($restored)
        ->and($this->archiveWorkflow->restore($this->archiveId))->toBe($restored);
});

test('partial restore resumes missing files but refuses different local bytes', function (): void {
    $this->archiveWorkflow->archive($this->archiveId);
    unlink($this->archiveManifestPath);
    $this->archiveWorkflow->restore($this->archiveId);
    expect(file_get_contents($this->archiveManifestPath))->toBe($this->archiveOriginalJson);
    file_put_contents($this->archiveBundle['path'], 'different local bytes');
    expect(fn () => $this->archiveWorkflow->restore($this->archiveId))->toThrow(RuntimeException::class, 'different bytes')
        ->and(file_get_contents($this->archiveBundle['path']))->toBe('different local bytes');
});

test('changed provider or policy is refused before any provider read', function (): void {
    $this->archiveWorkflow->archive($this->archiveId); $io = $this->archiveStore->io;
    $this->archiveStore->storeId = 'different-store';
    expect(fn () => $this->archiveWorkflow->verify($this->archiveId))->toThrow(RuntimeException::class)
        ->and($this->archiveStore->io)->toBe($io);
    $this->archiveStore->storeId = 'fixture-store-v1';
    config(['sms-exports.run_archive_policy_sha256' => str_repeat('b', 64)]);
    expect(fn () => $this->archiveWorkflow->restore($this->archiveId))->toThrow(RuntimeException::class)
        ->and($this->archiveStore->io)->toBe($io);
});

test('outer SQL transactions disabled configuration and pending runs never reach provider', function (): void {
    $this->archiveDb->beginTransaction();
    expect(fn () => $this->archiveWorkflow->archive($this->archiveId))->toThrow(RuntimeException::class, 'outside any transaction');
    $this->archiveDb->rollBack();
    $this->archiveDb->getPdo()->beginTransaction();
    try {
        expect(fn () => $this->archiveWorkflow->archive($this->archiveId))->toThrow(RuntimeException::class, 'outside any transaction');
    } finally { $this->archiveDb->getPdo()->rollBack(); }
    config(['sms-exports.run_archive_enabled' => false]);
    expect(fn () => $this->archiveWorkflow->archive($this->archiveId))->toThrow(HttpException::class);
    config(['sms-exports.run_archive_enabled' => true]);
    $this->archiveDb->table('TblSMSRuns')->update(['Status' => 'file_ready']);
    expect(fn () => $this->archiveWorkflow->archive($this->archiveId))->toThrow(HttpException::class)
        ->and($this->archiveStore->io)->toBe(0);
});

test('interrupted private restore scratch never blocks a later restore or verified download', function (): void {
    $this->archiveWorkflow->archive($this->archiveId);
    unlink($this->archiveBundle['path']); unlink($this->archiveManifestPath);
    $scratch = (new SmsRunArtifactBundle)->archiveRestoreScratch($this->archiveDirectory);
    $interrupted = $scratch.'/manifest.json.restore-0123456789abcdef';
    file_put_contents($interrupted, 'private interrupted copy');
    $path = $this->archiveWorkflow->restore($this->archiveId);
    expect((new SmsRunService(new SmsProspectExportRepository))->filePath($this->archiveId))->toBe($path)
        ->and(file_get_contents($interrupted))->toBe('private interrupted copy')
        ->and(array_values(array_diff(scandir(dirname($path)), ['.', '..'])))->toBe(['manifest.json', 'sms-fixture.zip']);
});

test('valid abandoned generation staging survives archive restoration while foreign staging is refused', function (): void {
    $stage = $this->archiveDirectory.'/v1/staging-0123456789abcdef';
    mkdir($stage, 0700);
    file_put_contents($stage.'/.build.json', json_encode(['version' => 1, 'request_id' => $this->archiveId, 'stem' => 'sms-fixture', 'parts' => 2]));
    file_put_contents($stage.'/'.$this->archiveBundle['manifest']['parts'][0]['name'], 'private incomplete CSV scratch');
    $this->archiveWorkflow->archive($this->archiveId);
    unlink($this->archiveBundle['path']);
    $path = $this->archiveWorkflow->restore($this->archiveId);
    expect((new SmsRunService(new SmsProspectExportRepository))->filePath($this->archiveId))->toBe($path)
        ->and(is_file($stage.'/.build.json'))->toBeTrue();
    file_put_contents($stage.'/foreign.txt', 'unrecognized evidence');
    expect(fn () => $this->archiveWorkflow->restore($this->archiveId))->toThrow(RuntimeException::class)
        ->and(file_get_contents($stage.'/foreign.txt'))->toBe('unrecognized evidence');
});

test('a run change during archive prevents the durable complete receipt', function (): void {
    $db = $this->archiveDb;
    $this->archiveStore->afterPut = static function (int $count) use ($db): void {
        if ($count === 2) $db->table('TblSMSRuns')->update(['Selected' => 2]);
    };
    expect(fn () => $this->archiveWorkflow->archive($this->archiveId))->toThrow(RuntimeException::class, 'run changed')
        ->and($this->archiveDb->table('TblSMSRuns')->value('Archive_Receipt_JSON'))->toBeNull();
});

test('corrupt remote manifest or primary never completes a restore', function (string $which): void {
    $receipt = $this->archiveWorkflow->archive($this->archiveId);
    $this->archiveStore->objects[$receipt[$which]['key']]['bytes'] = 'corrupt remote bytes';
    unlink($this->archiveBundle['path']); unlink($this->archiveManifestPath);
    expect(fn () => $this->archiveWorkflow->restore($this->archiveId))->toThrow(RuntimeException::class)
        ->and(file_exists($this->archiveBundle['path']))->toBeFalse()->and(file_exists($this->archiveManifestPath))->toBeFalse();
})->with(['manifest', 'primary']);

test('actual operator CLI defaults to verify and rejects invalid action without provider calls', function (): void {
    $this->archiveWorkflow->archive($this->archiveId);
    Container::getInstance()->instance(SmsRunArchiveWorkflow::class, $this->archiveWorkflow);
    $command = new Cmd\Reports\Console\Commands\SmsRunArchiveCommand;
    $command->setLaravel(new class extends Container { public function runningUnitTests(): bool { return true; } });
    $application = new Symfony\Component\Console\Application;
    $application->setAutoExit(false);
    if (method_exists($application, 'addCommand')) $application->addCommand($command);
    else $application->add($command);
    $output = new Symfony\Component\Console\Output\BufferedOutput;
    expect($application->run(new Symfony\Component\Console\Input\ArrayInput([
        'command' => 'sms:run-archive', 'request-id' => $this->archiveId,
    ]), $output))->toBe(0)->and($output->fetch())->toContain('verify verified successfully');
    $io = $this->archiveStore->io;
    expect($application->run(new Symfony\Component\Console\Input\ArrayInput([
        'command' => 'sms:run-archive', 'request-id' => $this->archiveId, '--action' => 'delete',
    ]), $output))->toBe(1)->and($this->archiveStore->io)->toBe($io);
});

test('archive receipt migration preserves existing runs and refuses repeat or destructive rollback', function (): void {
    $row = (array) $this->archiveDb->table('TblSMSRuns')->first();
    expect($row['Archive_Receipt_JSON'])->toBeNull();
    $migration = require dirname(__DIR__, 2).'/database/sms-migrations/2026_10_23_000002_add_sms_run_archive_receipt.php';
    expect(fn () => $migration->up())->toThrow(RuntimeException::class)
        ->and(fn () => $migration->down())->toThrow(RuntimeException::class)
        ->and((array) $this->archiveDb->table('TblSMSRuns')->first())->toBe($row);
});

test('registered provider remains lazy and fails closed when its explicit configuration is disabled', function (): void {
    (new Cmd\Reports\ReportsServiceProvider(Container::getInstance()))->register();
    config(['sms-exports.run_archive_enabled' => false]);
    expect(Container::getInstance()->bound(SmsRunArchiveStore::class))->toBeTrue()
        ->and(fn () => Container::getInstance()->make(SmsRunArchiveStore::class))->toThrow(RuntimeException::class)
        ->and($this->archiveStore->io)->toBe(0);
});

test('restored original download requires valid signature and a fresh authenticated download grant', function (): void {
    $this->archiveWorkflow->archive($this->archiveId);
    unlink($this->archiveBundle['path']); unlink($this->archiveManifestPath);
    $path = $this->archiveWorkflow->restore($this->archiveId);
    $providerIo = $this->archiveStore->io;
    require_once dirname(__DIR__, 3).'/cmd-runner/app/Http/Middleware/VerifyCmdSession.php';
    $container = Container::getInstance();
    config(['services.cmd' => ['auth_enabled' => true, 'auth_validate_url' => 'https://identity.fixture.invalid', 'auth_cache_ttl' => 60],
        'cache' => ['default' => 'array', 'stores' => ['array' => ['driver' => 'array']]]]);
    $container->instance('cache', new Illuminate\Cache\CacheManager($container));
    $container->instance(Illuminate\Http\Client\Factory::class, new Illuminate\Http\Client\Factory);
    $routes = new Illuminate\Routing\RouteCollection;
    $routes->add((new Illuminate\Routing\Route(['GET'], 'api/cmd/mail-drop-export-report/runs/{requestId}/download', fn () => null))->name('cmd.sms_run.download'));
    $urls = new Illuminate\Routing\UrlGenerator($routes, Illuminate\Http\Request::create('https://cmd.fixture.invalid'));
    $urls->setKeyResolver(fn () => 'archive-test-signing-key');
    $container->instance('url', $urls); Facade::clearResolvedInstance('url');
    Illuminate\Http\Request::macro('hasValidSignatureWhileIgnoring', function ($ignore = [], $absolute = true) {
        return Illuminate\Support\Facades\URL::hasValidSignature($this, $absolute, $ignore);
    });
    $signed = 'https://cmd.fixture.invalid'.$urls->temporarySignedRoute('cmd.sms_run.download', new DateTimeImmutable('+15 minutes'), ['requestId' => $this->archiveId], false);
    $controller = new Cmd\Reports\Http\Controllers\SmsRunController(new SmsRunService(new SmsProspectExportRepository));
    $pipeline = function (string $url) use ($controller) {
        $request = Illuminate\Http\Request::create($url); $request->headers->set('Cookie', 'isolated-archive-session=unchanged');
        return (new Illuminate\Routing\Middleware\ValidateSignature)->handle($request,
            fn ($r) => (new Cmd\Reports\Http\Middleware\SmsFreshSession)->handle($r,
                fn ($r) => (new App\Http\Middleware\VerifyCmdSession)->handle($r,
                    fn ($r) => (new Cmd\Reports\Http\Middleware\SmsPermission)->handle($r,
                        fn () => $controller->download($this->archiveId), 'download'))), 'relative');
    };
    Illuminate\Support\Facades\Http::preventStrayRequests();
    Illuminate\Support\Facades\Http::fake(['identity.fixture.invalid/*' => Illuminate\Support\Facades\Http::response([
        'email' => 'agent@example.invalid', 'permissionIds' => ['cmd_sms_download'],
    ])]);
    $response = $pipeline($signed);
    expect($response->getStatusCode())->toBe(200)->and($response->getFile()->getPathname())->toBe($path)
        ->and(hash_file('sha256', $response->getFile()->getPathname()))->toBe($this->archiveBundle['sha256']);
    expect(fn () => $pipeline($signed.'&changed=1'))->toThrow(HttpException::class);
    Illuminate\Support\Facades\Http::swap(new Illuminate\Http\Client\Factory);
    Illuminate\Support\Facades\Http::preventStrayRequests();
    Illuminate\Support\Facades\Http::fake(['identity.fixture.invalid/*' => Illuminate\Support\Facades\Http::response([
        'email' => 'agent@example.invalid', 'permissionIds' => ['cmd_report_mail_drop_export'],
    ])]);
    expect(fn () => $pipeline($signed))->toThrow(HttpException::class)
        ->and($this->archiveStore->io)->toBe($providerIo);
    Illuminate\Support\Facades\Http::assertSentCount(1);
});
