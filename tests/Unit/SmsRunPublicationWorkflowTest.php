<?php

declare(strict_types=1);

use Carbon\Carbon;
use Cmd\Reports\Services\SmsRunArtifactBundle;
use Cmd\Reports\Services\SmsRunArtifactPartReader;
use Cmd\Reports\Services\SmsRunLinkPublisher;
use Cmd\Reports\Services\SmsRunPublicationWorkflow;
use Cmd\Reports\Services\SmsRunOptions;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Closed create-only remote fixture. No HTTP, host credentials or actual shared links. */
final class SmsPublicationFixturePublisher implements SmsRunLinkPublisher
{
    public string $storeId = 'synthetic-publication-store-v1';
    public array $settings = ['version' => 1, 'folder' => '/synthetic-approved', 'access' => 'configured-fixture-only'];
    public array $objects = [];
    public int $creates = 0;
    public int $calls = 0;
    public int $verifies = 0;
    public ?Closure $duringPublish = null;
    public ?Closure $duringVerify = null;
    public ?Closure $duringPolicy = null;
    public function identity(): string { return $this->storeId; }
    public function policy(string $sendDate): array
    {
        if ($this->duringPolicy !== null) ($this->duringPolicy)();
        return $this->settings + ['send_date' => $sendDate];
    }
    public function publish(string $id, array $part, mixed $stream, array $policy): array
    {
        $this->outside(); $this->calls++;
        expect(ftell($stream))->toBe(0)->and(stream_get_meta_data($stream)['seekable'])->toBeTrue();
        $bytes = stream_get_contents($stream);
        expect(strlen($bytes))->toBe($part['bytes'])->and(hash('sha256', $bytes))->toBe($part['sha256']);
        $key = $id.'/v2/'.$part['name'];
        if (! isset($this->objects[$key])) {
            $this->creates++;
            $receipt = ['version' => 1, 'request_id' => $id, 'part' => $part['part'], 'name' => $part['name'],
                'url' => 'https://download.example.invalid/part-'.$part['part'].'?synthetic-only=1', 'sha256' => $part['sha256'],
                'bytes' => $part['bytes'], 'rows' => $part['rows'], 'remote' => ['provider' => 'synthetic-fixture', 'object_version' => 'version-'.$part['part']], 'policy' => $policy];
            $this->objects[$key] = compact('receipt', 'bytes', 'policy');
        } else {
            expect($this->objects[$key]['bytes'])->toBe($bytes)->and($this->objects[$key]['policy'])->toBe($policy);
        }
        if ($this->duringPublish !== null) ($this->duringPublish)($part);
        return $this->objects[$key]['receipt'];
    }
    public function verify(string $id, array $part, array $receipt, array $policy): void
    {
        $this->outside(); $this->verifies++;
        $key = $id.'/v2/'.$part['name'];
        if (! isset($this->objects[$key]) || $this->objects[$key]['receipt'] !== $receipt || $this->objects[$key]['policy'] !== $policy
            || hash('sha256', $this->objects[$key]['bytes']) !== $part['sha256']) throw new RuntimeException('Synthetic remote receipt verification failed.');
        if ($this->duringVerify !== null) ($this->duringVerify)($part);
    }
    private function outside(): void
    {
        $db = Illuminate\Support\Facades\DB::connection('sqlsrv');
        if ($db->transactionLevel() !== 0 || $db->getPdo()->inTransaction()) throw new RuntimeException('Publication provider called inside SQL transaction.');
    }
}

/** No PDO driver/socket: exercises Laravel's real reconnect-at-begin control flow. */
final class SmsPublicationBeginFixturePdo extends PDO
{
    public bool $active = false;
    public int $begins = 0;
    public int $rollbacks = 0;
    public function __construct(private readonly bool $loseAtBegin = false) {}
    public function inTransaction(): bool { return $this->active; }
    public function beginTransaction(): bool
    {
        $this->begins++;
        if ($this->loseAtBegin) throw new PDOException('server has gone away');
        $this->active = true; return true;
    }
    public function commit(): bool { $this->active = false; return true; }
    public function rollBack(): bool { $this->rollbacks++; $this->active = false; return true; }
}

final class SmsPublicationFixtureEmailGateway implements Cmd\Reports\Services\SmsRunEmailGateway
{
    public int $sends = 0;
    public ?Closure $duringReady = null;
    public function configuration(): array { return ['from' => 'admin@lendingtower.com', 'to' => ['fixture@example.invalid'],
        'cc' => [], 'bcc' => [], 'report_name' => 'Synthetic SMS', 'companies' => ['FIXTURE']]; }
    public function assertReady(): void { if ($this->duringReady !== null) ($this->duringReady)(); }
    public function send(array $message): bool { $this->sends++; return true; }
}

beforeEach(function (): void {
    $this->publicationOldContainer = Container::getInstance();
    $this->publicationOldClock = Carbon::getTestNow();
    Carbon::setTestNow(Carbon::parse('2026-10-23 18:00:00', 'UTC'));
    $container = new class extends Container { public function runningUnitTests(): bool { return true; } };
    Container::setInstance($container);
    $this->publicationRoot = str_replace('\\', '/', sys_get_temp_dir()).'/sms-publication-flow-'.bin2hex(random_bytes(8));
    mkdir($this->publicationRoot, 0700);
    $container->instance('config', new Repository(['sms-exports' => [
        'local_root' => $this->publicationRoot, 'file_publication_enabled' => true,
        'email_handoff_enabled' => true, 'email_allowed_link_hosts' => ['download.example.invalid'],
    ], 'database' => ['default' => 'sqlsrv']]));
    $capsule = new Manager($container);
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:'], 'sqlsrv');
    $capsule->getDatabaseManager()->setDefaultConnection('sqlsrv');
    $container->instance('db', $capsule->getDatabaseManager());
    Facade::clearResolvedInstances(); Facade::setFacadeApplication($container);
    $this->publicationDb = $capsule->getConnection('sqlsrv');
    $this->publicationDb->getSchemaBuilder()->create('TblPhoneNumbers', fn (Blueprint $table) => $table->string('Phone'));
    foreach (['2026_10_12_000001_create_sms_prospects_tables.php', '2026_10_19_000001_complete_sms_workflow.php',
        '2026_10_20_000001_create_sms_availability_summary.php', '2026_10_20_000003_add_sms_record_approval.php',
        '2026_10_22_000001_add_sms_run_file_options.php', '2026_10_23_000003_create_sms_email_outbox.php',
        '2026_10_23_000004_create_sms_file_publications.php'] as $migration) {
        (require dirname(__DIR__, 2).'/database/sms-migrations/'.$migration)->up();
    }
    $this->publicationId = '11111111-2222-4333-8444-555555555555';
    $this->publicationDirectory = $this->publicationRoot.'/runs/'.$this->publicationId;
    $this->publicationOptions = SmsRunOptions::normalize(3, 2, null, null, SmsRunOptions::PLANNED_DATE_VERSION, '2026-10-26', SmsRunOptions::APPROVAL_FILENAME_VERSION);
    $rows = [['Synthetic One', '14000', '02025550100', '2026-10-26'], ['Synthetic Two', '14000', '02025550100', '2026-10-26'], ['Synthetic Three', '14000', '02025550102', '2026-10-26']];
    $this->publicationPending = (new SmsRunArtifactBundle)->build($this->publicationDirectory, 'SMS Export - 2026-10-26 - ID Pending', $rows, 3, $this->publicationOptions);
    $this->publicationBundle = (new SmsRunArtifactBundle('v2'))->publishApproved($this->publicationDirectory, $this->publicationPending['manifest'], $this->publicationOptions, 3, 'SMS0001-SMS0009');
    $this->publicationDb->table('TblSMSRuns')->insert(['Request_ID' => $this->publicationId, 'Target' => 3, 'Selected' => 3,
        'Status' => 'approved', 'Selection_Policy' => 'prospect-record-v1', 'Created_By' => 'fixture@example.invalid',
        'Created_At' => '2026-10-23 17:00:00', 'Updated_At' => '2026-10-23 17:00:00',
        'Approved_By' => 'fixture@example.invalid', 'Approved_At' => '2026-10-23 17:01:00',
        'Sent_By' => 'fixture@example.invalid', 'Sent_At' => '2026-10-23 17:01:00',
        'Run_Options_JSON' => json_encode($this->publicationOptions), 'Artifact_Manifest_JSON' => json_encode($this->publicationBundle['manifest']),
        'File_Path' => $this->publicationBundle['path'], 'File_Bytes' => $this->publicationBundle['bytes'], 'File_Sha256' => $this->publicationBundle['sha256']]);
    $this->publicationProvider = new SmsPublicationFixturePublisher;
    $this->publicationWorkflow = new SmsRunPublicationWorkflow($this->publicationProvider, new SmsRunArtifactPartReader);
    $container->instance(SmsRunPublicationWorkflow::class, $this->publicationWorkflow);
    $this->publicationPublish = fn (): array => $this->publicationWorkflow->publish($this->publicationId, 'fixture@example.invalid');
});

afterEach(function (): void {
    if (isset($this->publicationDb)) while ($this->publicationDb->transactionLevel() > 0) $this->publicationDb->rollBack();
    $root = realpath($this->publicationRoot); $temp = str_replace('\\', '/', realpath(sys_get_temp_dir()));
    if ($root !== false && ! is_link($this->publicationRoot) && str_starts_with(str_replace('\\', '/', $root), $temp.'/sms-publication-flow-')) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) { if ($file->isDir() && ! $file->isLink()) rmdir($file->getPathname()); else unlink($file->getPathname()); }
        rmdir($root);
    }
    Carbon::setTestNow($this->publicationOldClock);
    Facade::clearResolvedInstances(); Container::setInstance($this->publicationOldContainer); Facade::setFacadeApplication($this->publicationOldContainer);
});

test('final suppression gate holds approved publication and links while retaining original history and artifacts', function (): void {
    $ids = \Cmd\Reports\Tests\Support\SmsFrozenEligibilityFixture::seed($this->publicationDb, $this->publicationId);
    config(['sms-exports.approved_handoff_suppression_enabled' => true]);
    $history = $this->publicationDb->table('TblSMSHistory')->get()->toJson();
    $this->publicationDb->table('TblProspects')->where('PK', $ids[0])->update(['Contacted' => 1]);
    expect(fn () => ($this->publicationPublish)())->toThrow(RuntimeException::class, 'held without changing membership')
        ->and($this->publicationProvider->calls)->toBe(0)
        ->and($this->publicationDb->table(SmsRunPublicationWorkflow::TABLE)->count())->toBe(0);
    $this->publicationDb->table('TblProspects')->where('PK', $ids[0])->update(['Contacted' => 0]);
    expect(($this->publicationPublish)()['status'])->toBe('published');
    $this->publicationDb->table('TblProspects')->where('PK', $ids[0])->update(['State' => 'WV']);
    expect(fn () => $this->publicationWorkflow->links($this->publicationId))->toThrow(RuntimeException::class, 'State')
        ->and($this->publicationWorkflow->status($this->publicationId)['status'])->toBe('published')
        ->and($this->publicationDb->table('TblSMSHistory')->get()->toJson())->toBe($history)
        ->and(hash_file('sha256', $this->publicationBundle['path']))->toBe($this->publicationBundle['sha256']);
});

test('SMS publication stores exact ordered approved CSV parts and replay verifies without remote duplicates or business changes', function (): void {
    $run = (array) $this->publicationDb->table('TblSMSRuns')->first();
    $pendingBytes = file_get_contents($this->publicationPending['path']);
    expect($this->publicationWorkflow->status($this->publicationId))->toBeNull();
    $status = ($this->publicationPublish)();
    expect($status['status'])->toBe('published')->and($status['files_done'])->toBe(2)->and($status['rows_done'])->toBe(3)
        ->and($status['files_total'])->toBe(2)->and($status['rows_total'])->toBe(3)->and($this->publicationProvider->creates)->toBe(2);
    $links = $this->publicationWorkflow->links($this->publicationId);
    expect(array_column($links, 'name'))->toBe(array_column($this->publicationBundle['manifest']['parts'], 'name'))
        ->and(array_column($links, 'sha256'))->toBe(array_column($this->publicationBundle['manifest']['parts'], 'sha256'))
        ->and(array_column($links, 'rows'))->toBe([2, 1]);
    $calls = $this->publicationProvider->calls; $verifies = $this->publicationProvider->verifies;
    expect(($this->publicationPublish)())->toBe($status)->and($this->publicationProvider->calls)->toBe($calls)
        ->and($this->publicationProvider->verifies)->toBe($verifies + 2)->and($this->publicationProvider->creates)->toBe(2)
        ->and((array) $this->publicationDb->table('TblSMSRuns')->first())->toBe($run)
        ->and(file_get_contents($this->publicationPending['path']))->toBe($pendingBytes)
        ->and($this->publicationDb->table('TblSMSHistory')->count())->toBe(0)->and($this->publicationDb->table('TblSMSAttemptAudit')->count())->toBe(0)
        ->and($this->publicationDb->table('TblSMSEmailOutbox')->count())->toBe(0);
});

test('SMS publication partial upload ambiguity preserves completed parts and safely reconciles the same remote path', function (): void {
    $this->publicationProvider->duringPublish = static function (array $part): void { if ($part['part'] === 2) throw new RuntimeException('Synthetic lost remote acknowledgement.'); };
    expect(fn () => ($this->publicationPublish)())->toThrow(RuntimeException::class, 'did not complete');
    $status = $this->publicationWorkflow->status($this->publicationId);
    expect($status['status'])->toBe('uncertain')->and($status['files_done'])->toBe(1)->and($status['rows_done'])->toBe(2)
        ->and($this->publicationProvider->creates)->toBe(2)
        ->and(fn () => $this->publicationWorkflow->links($this->publicationId))->toThrow(HttpException::class);
    $this->publicationProvider->duringPublish = null;
    expect(($this->publicationPublish)()['status'])->toBe('published')->and($this->publicationProvider->creates)->toBe(2)
        ->and($this->publicationProvider->calls)->toBe(3)->and($this->publicationDb->table(SmsRunPublicationWorkflow::PARTS)->where('State', 'complete')->count())->toBe(2);
});

test('SMS publication failed local receipt commit adopts the same uploaded object on explicit retry', function (): void {
    $crashing = new class($this->publicationProvider, new SmsRunArtifactPartReader) extends SmsRunPublicationWorkflow {
        protected function commitPart($db, PDO $pdo, object $row, object $run, int $number, array $receipt): void { throw new RuntimeException('Synthetic local receipt commit failure.'); }
    };
    expect(fn () => $crashing->publish($this->publicationId, 'fixture@example.invalid'))->toThrow(RuntimeException::class)
        ->and($this->publicationProvider->creates)->toBe(1)->and($this->publicationWorkflow->status($this->publicationId)['status'])->toBe('uncertain');
    expect(($this->publicationPublish)()['status'])->toBe('published')->and($this->publicationProvider->creates)->toBe(2);
});

test('SMS publication completed remote receipt mismatch fails explicit revalidation without replacing local receipt', function (): void {
    ($this->publicationPublish)();
    $before = $this->publicationDb->table(SmsRunPublicationWorkflow::PARTS)->get()->map(fn ($row) => (array) $row)->all();
    $key = array_key_first($this->publicationProvider->objects);
    $this->publicationProvider->objects[$key]['bytes'] = 'Synthetic changed remote bytes';
    expect(fn () => ($this->publicationPublish)())->toThrow(RuntimeException::class, 'could not be reverified')
        ->and($this->publicationDb->table(SmsRunPublicationWorkflow::PARTS)->get()->map(fn ($row) => (array) $row)->all())->toBe($before)
        ->and($this->publicationProvider->creates)->toBe(2)
        ->and($this->publicationWorkflow->status($this->publicationId)['status'])->toBe('uncertain')
        ->and(fn () => $this->publicationWorkflow->links($this->publicationId))->toThrow(HttpException::class);
});

test('SMS publication provider identity policy or approved context drift never republishes frozen parts', function (string $kind): void {
    ($this->publicationPublish)();
    if ($kind === 'identity') $this->publicationProvider->storeId = 'changed-scope';
    elseif ($kind === 'policy') $this->publicationProvider->settings['folder'] = '/other-folder';
    else $this->publicationDb->table('TblSMSRuns')->update(['Approved_By' => 'changed@example.invalid']);
    $calls = $this->publicationProvider->calls; $verifies = $this->publicationProvider->verifies;
    expect(fn () => ($this->publicationPublish)())->toThrow(RuntimeException::class)
        ->and($this->publicationProvider->calls)->toBe($calls)->and($this->publicationProvider->verifies)->toBe($verifies);
})->with(['identity', 'policy', 'run']);

test('SMS publication status and private links use ledger only with no artifacts provider calls or mutation', function (): void {
    $status = ($this->publicationPublish)(); $links = $this->publicationWorkflow->links($this->publicationId);
    $before = (array) $this->publicationDb->table(SmsRunPublicationWorkflow::TABLE)->first();
    unlink($this->publicationBundle['path']);
    $this->publicationProvider->duringPolicy = static fn () => throw new RuntimeException('GET consulted provider settings.');
    $this->publicationProvider->duringVerify = static fn () => throw new RuntimeException('GET called provider.');
    $calls = $this->publicationProvider->calls; $verifies = $this->publicationProvider->verifies;
    expect($this->publicationWorkflow->status($this->publicationId))->toBe($status)
        ->and($this->publicationWorkflow->links($this->publicationId))->toBe($links)
        ->and(json_encode($status))->not->toContain('https:', 'synthetic-only', 'fixture@example.invalid', 'folder')
        ->and($this->publicationProvider->calls)->toBe($calls)->and($this->publicationProvider->verifies)->toBe($verifies)
        ->and((array) $this->publicationDb->table(SmsRunPublicationWorkflow::TABLE)->first())->toBe($before);
});

test('SMS publication corrupt plan receipt counts or completion evidence fail read validation', function (string $kind): void {
    ($this->publicationPublish)();
    match ($kind) {
        'plan' => $this->publicationDb->table(SmsRunPublicationWorkflow::TABLE)->update(['Plan_JSON' => '{}']),
        'receipt' => $this->publicationDb->table(SmsRunPublicationWorkflow::PARTS)->where('Part_Number', 1)->update(['Receipt_JSON' => '{}']),
        'missing part' => $this->publicationDb->table(SmsRunPublicationWorkflow::PARTS)->where('Part_Number', 1)->delete(),
        'part order' => $this->publicationDb->table(SmsRunPublicationWorkflow::PARTS)->where('Part_Number', 1)->update(['Part_Number' => 3]),
        'missing time' => $this->publicationDb->table(SmsRunPublicationWorkflow::PARTS)->where('Part_Number', 1)->update(['Attempted_At' => null]),
        'parent time' => $this->publicationDb->table(SmsRunPublicationWorkflow::TABLE)->update(['Completed_At' => null]),
        'ready after attempt' => $this->publicationDb->table(SmsRunPublicationWorkflow::PARTS)->where('Part_Number', 1)->update(['State' => 'ready']),
    };
    expect(fn () => $this->publicationWorkflow->links($this->publicationId))->toThrow(RuntimeException::class)
        ->and(fn () => $this->publicationWorkflow->status($this->publicationId))->toThrow(RuntimeException::class);
})->with(['plan', 'receipt', 'missing part', 'part order', 'missing time', 'parent time', 'ready after attempt']);

test('SMS publication pending legacy malformed or unapproved batches never call provider or create publication rows', function (array $changes): void {
    $this->publicationDb->table('TblSMSRuns')->update($changes);
    expect(fn () => ($this->publicationPublish)())->toThrow(Exception::class)
        ->and($this->publicationProvider->calls)->toBe(0)->and($this->publicationProvider->verifies)->toBe(0)
        ->and($this->publicationDb->table(SmsRunPublicationWorkflow::TABLE)->count())->toBe(0);
})->with([
    [['Status' => 'file_ready']], [['Status' => 'approving']], [['Selection_Policy' => 'legacy-phone-v1']],
    [['Approved_By' => null]], [['Approved_At' => null]], [['Run_Options_JSON' => null]],
    [['File_Path' => '/synthetic/pending.csv']], [['File_Sha256' => str_repeat('0', 64)]],
]);

test('SMS publication fresh expired campaign is rejected before provider activity', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-27 07:00:00', 'UTC'));
    expect(fn () => ($this->publicationPublish)())->toThrow(InvalidArgumentException::class)
        ->and($this->publicationProvider->calls)->toBe(0)->and($this->publicationDb->table(SmsRunPublicationWorkflow::TABLE)->count())->toBe(0);
});

test('SMS publication queue preflight uses metadata only and never creates intent or touches files', function (): void {
    unlink($this->publicationBundle['path']);
    $this->publicationWorkflow->assertPublishable($this->publicationId);
    expect($this->publicationProvider->calls)->toBe(0)->and($this->publicationProvider->verifies)->toBe(0)
        ->and($this->publicationDb->table(SmsRunPublicationWorkflow::TABLE)->count())->toBe(0);
    $this->publicationDb->table('TblSMSRuns')->update(['Status' => 'approving']);
    expect(fn () => $this->publicationWorkflow->assertPublishable($this->publicationId))->toThrow(HttpException::class);
});

test('SMS publication direct or Laravel transactions cannot enclose provider I/O', function (bool $direct): void {
    if ($direct) $this->publicationDb->getPdo()->beginTransaction(); else $this->publicationDb->beginTransaction();
    try { expect(fn () => ($this->publicationPublish)())->toThrow(RuntimeException::class, 'outside every transaction')
        ->and($this->publicationProvider->calls)->toBe(0); }
    finally { if ($direct) $this->publicationDb->getPdo()->rollBack(); else $this->publicationDb->rollBack(); }
})->with([false, true]);

test('SMS publication disabled status avoids opening a database and mutation methods fail closed', function (): void {
    config(['sms-exports.file_publication_enabled' => false]);
    Container::getInstance()->instance('db', new class { public function connection(): never { throw new RuntimeException('Disabled publication opened DB.'); } });
    Facade::clearResolvedInstance('db');
    expect($this->publicationWorkflow->status($this->publicationId))->toBeNull()
        ->and(fn () => ($this->publicationPublish)())->toThrow(HttpException::class, 'not enabled')
        ->and(fn () => $this->publicationWorkflow->assertPublishable($this->publicationId))->toThrow(HttpException::class, 'not enabled');
});

test('SMS publication missing schema has null status and refuses mutation without provider calls', function (): void {
    $this->publicationDb->getSchemaBuilder()->drop(SmsRunPublicationWorkflow::PARTS);
    expect($this->publicationWorkflow->status($this->publicationId))->toBeNull()
        ->and(fn () => ($this->publicationPublish)())->toThrow(HttpException::class, 'migration')
        ->and($this->publicationProvider->calls)->toBe(0);
});

test('SMS publication preparation rejects a replacement SQL session before inserting ledger rows', function (): void {
    $original = $this->publicationDb->getPdo();
    $this->publicationProvider->duringPolicy = function (): void { $this->publicationDb->setPdo(new PDO('sqlite::memory:')); };
    try { expect(fn () => ($this->publicationPublish)())->toThrow(RuntimeException::class, 'pinned SQL session'); }
    finally { $this->publicationDb->setPdo($original); }
    expect($this->publicationDb->table(SmsRunPublicationWorkflow::TABLE)->count())->toBe(0)->and($this->publicationProvider->calls)->toBe(0);
});

test('SMS publication migration preserves existing evidence and refuses repeat install or destructive rollback', function (): void {
    $migration = require dirname(__DIR__, 2).'/database/sms-migrations/2026_10_23_000004_create_sms_file_publications.php';
    expect(fn () => $migration->up())->toThrow(RuntimeException::class)
        ->and(fn () => $migration->down())->toThrow(RuntimeException::class, 'Retain SMS publication')
        ->and($this->publicationDb->getSchemaBuilder()->hasTable(SmsRunPublicationWorkflow::TABLE))->toBeTrue()
        ->and($this->publicationDb->getSchemaBuilder()->hasTable(SmsRunPublicationWorkflow::PARTS))->toBeTrue();
});

test('SMS publication self-consistent receipt from another run part or policy cannot become an email link', function (string $kind): void {
    ($this->publicationPublish)();
    $row = $this->publicationDb->table(SmsRunPublicationWorkflow::PARTS)->where('Part_Number', 1)->first();
    $receipt = json_decode($row->Receipt_JSON, true);
    match ($kind) {
        'run' => $receipt['request_id'] = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
        'part' => $receipt['part'] = 2,
        'name' => $receipt['name'] = str_replace('Part 1', 'Part 2', $receipt['name']),
        'sha' => $receipt['sha256'] = str_repeat('0', 64),
        'bytes' => $receipt['bytes']++,
        'rows' => $receipt['rows']++,
        'policy' => $receipt['policy']['folder'] = '/other-scope',
        'remote' => $receipt['remote'] = [],
        'version' => $receipt['version'] = 2,
        'extra' => $receipt['extra'] = 'not approved',
        'cross-part' => $receipt = json_decode($this->publicationDb->table(SmsRunPublicationWorkflow::PARTS)->where('Part_Number', 2)->value('Receipt_JSON'), true),
    };
    $json = json_encode($receipt, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $this->publicationDb->table(SmsRunPublicationWorkflow::PARTS)->where('Part_Number', 1)->update(['Receipt_JSON' => $json, 'Receipt_Sha256' => hash('sha256', $json)]);
    $calls = $this->publicationProvider->calls; $verifies = $this->publicationProvider->verifies;
    expect(fn () => $this->publicationWorkflow->links($this->publicationId))->toThrow(RuntimeException::class)
        ->and(fn () => $this->publicationWorkflow->status($this->publicationId))->toThrow(RuntimeException::class)
        ->and(fn () => ($this->publicationPublish)())->toThrow(RuntimeException::class)
        ->and($this->publicationProvider->calls)->toBe($calls)->and($this->publicationProvider->verifies)->toBe($verifies);
})->with(['run', 'part', 'name', 'sha', 'bytes', 'rows', 'policy', 'remote', 'version', 'extra', 'cross-part']);

test('SMS publication complete links feed frozen dynamic email preparation without sending or business changes', function (): void {
    $gateway = new class implements Cmd\Reports\Services\SmsRunEmailGateway {
        public int $sends = 0;
        public function configuration(): array { return ['from' => 'admin@lendingtower.com', 'to' => ['fixture@example.invalid'],
            'cc' => [], 'bcc' => [], 'report_name' => 'Synthetic SMS', 'companies' => ['FIXTURE']]; }
        public function assertReady(): void { throw new RuntimeException('Preparation attempted transport readiness.'); }
        public function send(array $message): bool { $this->sends++; throw new RuntimeException('Publication must not send mail.'); }
    };
    $emails = new Cmd\Reports\Services\SmsRunEmailWorkflow($gateway);
    ($this->publicationPublish)();
    $run = (array) $this->publicationDb->table('TblSMSRuns')->first();
    $email = $emails->prepare($this->publicationId, $this->publicationWorkflow->links($this->publicationId), 'fixture@example.invalid');
    $message = $emails->preparedMessage($this->publicationId);
    expect($email['status'])->toBe('ready')->and($message['files'])->toBe($this->publicationWorkflow->links($this->publicationId))
        ->and($message['total'])->toBe(3)->and($message['parts'])->toBe(2)->and($gateway->sends)->toBe(0)
        ->and((array) $this->publicationDb->table('TblSMSRuns')->first())->toBe($run);
});

test('SMS publication serialized job recovers safely and never sends email', function (): void {
    $job = new Cmd\Reports\Jobs\SmsRunPublicationJob($this->publicationId, 'fixture@example.invalid');
    $serialized = serialize($job);
    unserialize($serialized)->handle($this->publicationWorkflow);
    unserialize($serialized)->handle($this->publicationWorkflow);
    expect($this->publicationWorkflow->status($this->publicationId)['status'])->toBe('published')
        ->and($this->publicationProvider->creates)->toBe(2)->and($this->publicationProvider->calls)->toBe(2)
        ->and($this->publicationDb->table('TblSMSEmailOutbox')->count())->toBe(0);
});

test('SMS publication rejects invalid operator email before any provider call', function (string $actor): void {
    expect(fn () => $this->publicationWorkflow->publish($this->publicationId, $actor))->toThrow(HttpException::class)
        ->and($this->publicationProvider->calls)->toBe(0)->and($this->publicationDb->table(SmsRunPublicationWorkflow::TABLE)->count())->toBe(0);
})->with(['', 'Synthetic Operator', ' fixture@example.invalid', "fixture@example.invalid\n"]);

test('SMS publication replaced SQL session after upload cannot commit receipt and retry reconciles original object', function (): void {
    $original = $this->publicationDb->getPdo();
    $this->publicationProvider->duringPublish = function (): void { $this->publicationDb->setPdo(new PDO('sqlite::memory:')); };
    try { expect(fn () => ($this->publicationPublish)())->toThrow(RuntimeException::class, 'did not complete'); }
    finally { $this->publicationDb->setPdo($original); }
    expect($this->publicationDb->table(SmsRunPublicationWorkflow::TABLE)->value('State'))->toBe('publishing')
        ->and($this->publicationDb->table(SmsRunPublicationWorkflow::PARTS)->where('Part_Number', 1)->value('State'))->toBe('attempting')
        ->and($this->publicationDb->table(SmsRunPublicationWorkflow::PARTS)->where('Part_Number', 1)->value('Receipt_JSON'))->toBeNull()
        ->and($this->publicationProvider->creates)->toBe(1);
    $this->publicationProvider->duringPublish = null;
    expect(($this->publicationPublish)()['status'])->toBe('published')->and($this->publicationProvider->creates)->toBe(2);
});

test('SMS publication begin reconnect rolls back the replacement session before any receipt callback', function (): void {
    $original = new SmsPublicationBeginFixturePdo(true); $replacement = new SmsPublicationBeginFixturePdo;
    $connection = new Illuminate\Database\SqlServerConnection($original, 'synthetic-no-database', '', ['driver' => 'sqlsrv']);
    $reconnects = 0;
    $connection->setReconnector(static function ($db) use ($replacement, &$reconnects): void { $reconnects++; $db->setPdo($replacement); });
    $writes = 0; $method = new ReflectionMethod(SmsRunPublicationWorkflow::class, 'transaction');
    expect(fn () => $method->invoke($this->publicationWorkflow, $connection, $original, static function () use (&$writes): void { $writes++; }))
        ->toThrow(RuntimeException::class, 'session changed before commit')
        ->and($reconnects)->toBe(1)->and($original->begins)->toBe(1)->and($replacement->begins)->toBe(1)
        ->and($replacement->rollbacks)->toBe(1)->and($replacement->active)->toBeFalse()->and($writes)->toBe(0);
});

test('SMS publication known bad shared links block prepared email until same-plan revalidation recovers', function (): void {
    ($this->publicationPublish)();
    $gateway = new SmsPublicationFixtureEmailGateway;
    $emails = new Cmd\Reports\Services\SmsRunEmailWorkflow($gateway);
    $prepared = $emails->prepare($this->publicationId, $this->publicationWorkflow->links($this->publicationId), 'fixture@example.invalid');
    $key = array_key_first($this->publicationProvider->objects);
    $original = $this->publicationProvider->objects[$key]['bytes'];
    $this->publicationProvider->objects[$key]['bytes'] = 'Synthetic changed remote object';
    expect(fn () => ($this->publicationPublish)())->toThrow(RuntimeException::class, 'could not be reverified')
        ->and($this->publicationWorkflow->status($this->publicationId)['status'])->toBe('uncertain')
        ->and(fn () => $emails->send($this->publicationId, $prepared['payload_sha256']))->toThrow(Exception::class)
        ->and($gateway->sends)->toBe(0)->and($emails->status($this->publicationId)['status'])->toBe('ready')
        ->and($this->publicationDb->table('TblSMSEmailOutbox')->value('Attempted_At'))->toBeNull();
    $this->publicationProvider->objects[$key]['bytes'] = $original;
    expect(($this->publicationPublish)()['status'])->toBe('published')
        ->and($emails->send($this->publicationId, $prepared['payload_sha256'])['status'])->toBe('accepted')
        ->and($emails->send($this->publicationId, $prepared['payload_sha256'])['status'])->toBe('accepted')
        ->and($gateway->sends)->toBe(1);
});

test('SMS publication disable flag cannot bypass a tracked publication guard on prepared email', function (): void {
    ($this->publicationPublish)();
    $gateway = new SmsPublicationFixtureEmailGateway;
    $emails = new Cmd\Reports\Services\SmsRunEmailWorkflow($gateway);
    $prepared = $emails->prepare($this->publicationId, $this->publicationWorkflow->links($this->publicationId), 'fixture@example.invalid');
    config(['sms-exports.file_publication_enabled' => false]);
    expect(fn () => $emails->send($this->publicationId, $prepared['payload_sha256']))->toThrow(Exception::class)
        ->and($gateway->sends)->toBe(0)->and($emails->status($this->publicationId)['status'])->toBe('ready')
        ->and($this->publicationDb->table('TblSMSEmailOutbox')->value('Attempted_At'))->toBeNull();
});

test('SMS publication email preparation rejects manual links differing from its tracked verified links', function (): void {
    ($this->publicationPublish)();
    $gateway = new SmsPublicationFixtureEmailGateway;
    $emails = new Cmd\Reports\Services\SmsRunEmailWorkflow($gateway);
    $links = $this->publicationWorkflow->links($this->publicationId);
    $links[0]['url'] .= '&changed=1';
    expect(fn () => $emails->prepare($this->publicationId, $links, 'fixture@example.invalid'))->toThrow(Exception::class)
        ->and($gateway->sends)->toBe(0)->and($this->publicationDb->table('TblSMSEmailOutbox')->count())->toBe(0);
});

test('SMS publication tracking introduced after manual preparation blocks stale manual email links', function (): void {
    $links = array_map(static fn (array $part): array => ['name' => $part['name'], 'url' => 'https://download.example.invalid/manual-'.$part['part'],
        'sha256' => $part['sha256'], 'bytes' => $part['bytes'], 'rows' => $part['rows']], $this->publicationBundle['manifest']['parts']);
    $gateway = new SmsPublicationFixtureEmailGateway;
    $emails = new Cmd\Reports\Services\SmsRunEmailWorkflow($gateway);
    $prepared = $emails->prepare($this->publicationId, $links, 'fixture@example.invalid');
    ($this->publicationPublish)();
    expect(fn () => $emails->send($this->publicationId, $prepared['payload_sha256']))->toThrow(Exception::class)
        ->and($gateway->sends)->toBe(0)->and($emails->status($this->publicationId)['status'])->toBe('ready');
});

test('SMS publication becoming held during email readiness is caught before committing an email attempt', function (): void {
    ($this->publicationPublish)();
    $gateway = new SmsPublicationFixtureEmailGateway;
    $emails = new Cmd\Reports\Services\SmsRunEmailWorkflow($gateway);
    $prepared = $emails->prepare($this->publicationId, $this->publicationWorkflow->links($this->publicationId), 'fixture@example.invalid');
    $gateway->duringReady = function (): void {
        $this->publicationDb->table(SmsRunPublicationWorkflow::TABLE)->update(['State' => 'uncertain', 'Completed_At' => null, 'Error_Code' => 'publication_exception']);
    };
    expect(fn () => $emails->send($this->publicationId, $prepared['payload_sha256']))->toThrow(Exception::class)
        ->and($gateway->sends)->toBe(0)->and($emails->status($this->publicationId)['status'])->toBe('ready')
        ->and($this->publicationDb->table('TblSMSEmailOutbox')->value('Attempted_At'))->toBeNull();
});
