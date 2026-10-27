<?php

declare(strict_types=1);

use Carbon\Carbon;
use Cmd\Reports\Services\SmsRunArtifactBundle;
use Cmd\Reports\Services\SmsRunEmailGateway;
use Cmd\Reports\Services\SmsRunEmailWorkflow;
use Cmd\Reports\Services\SmsRunOptions;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class SmsWorkflowEmailFixtureGateway implements SmsRunEmailGateway
{
    public array $config = ['from' => 'admin@lendingtower.com', 'to' => ['fixture@example.invalid'], 'cc' => [], 'bcc' => [],
        'report_name' => 'Synthetic SMS handoff', 'companies' => ['FIXTURE']];
    public int $lookups = 0;
    public int $checks = 0;
    public array $messages = [];
    public ?Closure $afterLookup = null;
    public ?Closure $onReady = null;
    public ?Closure $onSend = null;

    public function configuration(): array
    {
        $this->outside();
        $this->lookups++;
        if ($this->afterLookup !== null) ($this->afterLookup)();
        return $this->config;
    }

    public function assertReady(): void
    {
        $this->outside();
        $this->checks++;
        if ($this->onReady !== null) ($this->onReady)();
    }

    public function send(array $message): bool
    {
        $this->outside();
        $this->messages[] = $message;
        return $this->onSend === null ? true : ($this->onSend)($message);
    }

    private function outside(): void
    {
        $db = Illuminate\Support\Facades\DB::connection('sqlsrv');
        if ($db->transactionLevel() !== 0 || $db->getPdo()->inTransaction()) throw new RuntimeException('Fixture provider invoked inside SQL transaction.');
    }
}

/** Exercise Laravel's actual begin/reconnect path without any database driver/socket. */
final class SmsEmailBeginFixturePdo extends PDO
{
    public bool $active = false;
    public int $rollbacks = 0;
    public int $begins = 0;
    public function __construct(private readonly bool $loseAtBegin = false) {}
    public function inTransaction(): bool { return $this->active; }
    public function beginTransaction(): bool
    {
        $this->begins++;
        if ($this->loseAtBegin) throw new PDOException('server has gone away');
        $this->active = true;
        return true;
    }
    public function commit(): bool { $this->active = false; return true; }
    public function rollBack(): bool { $this->rollbacks++; $this->active = false; return true; }
}

// Disposable in-memory SQL and owned synthetic artifacts only; no host .env, provider or recipient rows.
beforeEach(function (): void {
    $this->emailPreviousContainer = Container::getInstance();
    $this->emailPreviousClock = Carbon::getTestNow();
    Carbon::setTestNow(Carbon::parse('2026-10-23 18:00:00', 'UTC'));
    $container = new class extends Container { public function runningUnitTests(): bool { return true; } };
    Container::setInstance($container);
    $this->emailRoot = str_replace('\\', '/', sys_get_temp_dir()).'/sms-email-flow-'.bin2hex(random_bytes(8));
    mkdir($this->emailRoot, 0700);
    $container->instance('config', new Repository(['sms-exports' => [
        'local_root' => $this->emailRoot, 'email_handoff_enabled' => true, 'email_allowed_link_hosts' => ['download.example.invalid'],
    ], 'database' => ['default' => 'sqlsrv']]));
    $capsule = new Manager($container);
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:'], 'sqlsrv');
    $capsule->getDatabaseManager()->setDefaultConnection('sqlsrv');
    $container->instance('db', $capsule->getDatabaseManager());
    Facade::clearResolvedInstances(); Facade::setFacadeApplication($container);
    $this->emailDb = $capsule->getConnection('sqlsrv');
    $this->emailDb->getSchemaBuilder()->create('TblPhoneNumbers', fn (Blueprint $table) => $table->string('Phone'));
    foreach (['2026_10_12_000001_create_sms_prospects_tables.php', '2026_10_19_000001_complete_sms_workflow.php',
        '2026_10_20_000001_create_sms_availability_summary.php', '2026_10_20_000003_add_sms_record_approval.php',
        '2026_10_22_000001_add_sms_run_file_options.php', '2026_10_23_000003_create_sms_email_outbox.php'] as $migration) {
        (require dirname(__DIR__, 2).'/database/sms-migrations/'.$migration)->up();
    }
    $this->emailId = '11111111-2222-4333-8444-555555555555';
    $directory = $this->emailRoot.'/runs/'.$this->emailId;
    $this->emailOptions = SmsRunOptions::normalize(3, 2, null, null, SmsRunOptions::PLANNED_DATE_VERSION, '2026-10-26', SmsRunOptions::APPROVAL_FILENAME_VERSION);
    $rows = [['Synthetic One', '14000', '02025550100', '2026-10-26'], ['Synthetic Two', '14000', '02025550100', '2026-10-26'], ['Synthetic Three', '14000', '02025550102', '2026-10-26']];
    $this->emailPending = (new SmsRunArtifactBundle)->build($directory, 'SMS Export - 2026-10-26 - ID Pending', $rows, 3, $this->emailOptions);
    $this->emailBundle = (new SmsRunArtifactBundle('v2'))->publishApproved($directory, $this->emailPending['manifest'], $this->emailOptions, 3, 'SMS0001-SMS0009');
    $this->emailDb->table('TblSMSRuns')->insert(['Request_ID' => $this->emailId, 'Target' => 3, 'Selected' => 3,
        'Status' => 'approved', 'Selection_Policy' => 'prospect-record-v1', 'Created_By' => 'fixture@example.invalid',
        'Created_At' => '2026-10-23 17:00:00', 'Updated_At' => '2026-10-23 17:00:00',
        'Approved_By' => 'fixture@example.invalid', 'Approved_At' => '2026-10-23 17:01:00',
        'Sent_By' => 'fixture@example.invalid', 'Sent_At' => '2026-10-23 17:01:00',
        'Run_Options_JSON' => json_encode($this->emailOptions), 'Artifact_Manifest_JSON' => json_encode($this->emailBundle['manifest']),
        'File_Path' => $this->emailBundle['path'], 'File_Bytes' => $this->emailBundle['bytes'], 'File_Sha256' => $this->emailBundle['sha256']]);
    $this->emailLinks = array_map(static fn (array $part): array => [
        'name' => $part['name'], 'url' => 'https://download.example.invalid/private-part-'.$part['part'].'?token=synthetic-only',
        'sha256' => $part['sha256'], 'bytes' => $part['bytes'], 'rows' => $part['rows'],
    ], $this->emailBundle['manifest']['parts']);
    $this->emailGateway = new SmsWorkflowEmailFixtureGateway;
    $this->emailWorkflow = new SmsRunEmailWorkflow($this->emailGateway);
    $this->emailPrepare = fn (): array => $this->emailWorkflow->prepare($this->emailId, $this->emailLinks, 'fixture@example.invalid');
});

afterEach(function (): void {
    if (isset($this->emailDb)) while ($this->emailDb->transactionLevel() > 0) $this->emailDb->rollBack();
    $root = realpath($this->emailRoot); $temp = str_replace('\\', '/', realpath(sys_get_temp_dir()));
    if ($root !== false && ! is_link($this->emailRoot) && str_starts_with(str_replace('\\', '/', $root), $temp.'/sms-email-flow-')) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) { if ($file->isDir() && ! $file->isLink()) rmdir($file->getPathname()); else unlink($file->getPathname()); }
        rmdir($root);
    }
    Carbon::setTestNow($this->emailPreviousClock);
    Facade::clearResolvedInstances(); Container::setInstance($this->emailPreviousContainer); Facade::setFacadeApplication($this->emailPreviousContainer);
});

test('late phone suppression blocks email before the attempt marker and leaves its reviewed payload intact', function (): void {
    $ids = \Cmd\Reports\Tests\Support\SmsFrozenEligibilityFixture::seed($this->emailDb, $this->emailId);
    config(['sms-exports.approved_handoff_suppression_enabled' => true]);
    $prepared = ($this->emailPrepare)();
    $stored = (array) $this->emailDb->table(SmsRunEmailWorkflow::TABLE)->first();
    $this->emailGateway->onReady = function (): void {
        $this->emailDb->table('TblPhoneNumbers')->insert(['Phone' => '12025550100']);
    };
    expect(fn () => $this->emailWorkflow->send($this->emailId, $prepared['payload_sha256']))
        ->toThrow(RuntimeException::class, 'held without changing membership')
        ->and($this->emailGateway->messages)->toBe([])
        ->and((array) $this->emailDb->table(SmsRunEmailWorkflow::TABLE)->first())->toBe($stored)
        ->and($this->emailWorkflow->status($this->emailId)['status'])->toBe('ready');
    $this->emailGateway->onReady = null; $this->emailDb->table('TblPhoneNumbers')->delete();
    expect($this->emailWorkflow->send($this->emailId, $prepared['payload_sha256'])['status'])->toBe('accepted')
        ->and($this->emailGateway->messages)->toHaveCount(1);
});

test('SMS email preparation freezes dynamic configuration and exact approved file receipts without sending or business writes', function (): void {
    $run = (array) $this->emailDb->table('TblSMSRuns')->first();
    $pendingBytes = file_get_contents($this->emailPending['path']);
    $result = ($this->emailPrepare)();
    $stored = (array) $this->emailDb->table(SmsRunEmailWorkflow::TABLE)->first();
    expect($result['status'])->toBe('ready')->and($this->emailGateway->lookups)->toBe(1)
        ->and($this->emailGateway->checks)->toBe(0)->and($this->emailGateway->messages)->toBe([])
        ->and(hash('sha256', $stored['Payload_JSON']))->toBe($result['payload_sha256'])
        ->and($stored['Attempted_At'])->toBeNull()->and($stored['Accepted_At'])->toBeNull();
    $message = $this->emailWorkflow->preparedMessage($this->emailId);
    expect($message['files'])->toBe($this->emailLinks)->and($message['total'])->toBe(3)->and($message['parts'])->toBe(2)
        ->and($message['to'])->toBe(['fixture@example.invalid']);
    $this->emailGateway->config['to'] = ['changed@example.invalid'];
    $this->emailGateway->afterLookup = static fn () => throw new RuntimeException('Duplicate prepare must not reread the dynamic table.');
    expect(($this->emailPrepare)())->toBe($result)->and($this->emailWorkflow->preparedMessage($this->emailId))->toBe($message)
        ->and((array) $this->emailDb->table(SmsRunEmailWorkflow::TABLE)->first())->toBe($stored)
        ->and($this->emailDb->table(SmsRunEmailWorkflow::TABLE)->count())->toBe(1)
        ->and((array) $this->emailDb->table('TblSMSRuns')->first())->toBe($run)
        ->and(file_get_contents($this->emailPending['path']))->toBe($pendingBytes)
        ->and($this->emailDb->table('TblSMSHistory')->count())->toBe(0)->and($this->emailDb->table('TblSMSAttemptAudit')->count())->toBe(0);
});

test('SMS email send commits attempting before the provider and treats acceptance as non-delivery evidence', function (): void {
    $prepared = ($this->emailPrepare)();
    $message = $this->emailWorkflow->preparedMessage($this->emailId);
    $this->emailGateway->config['to'] = ['changed@example.invalid'];
    $this->emailGateway->onSend = function (array $actual) use ($message): bool {
        expect($actual)->toBe($message)
            ->and($this->emailDb->table(SmsRunEmailWorkflow::TABLE)->value('State'))->toBe('attempting')
            ->and($this->emailDb->table(SmsRunEmailWorkflow::TABLE)->value('Attempted_At'))->not->toBeNull()
            ->and($this->emailWorkflow->status($this->emailId)['status'])->toBe('sending');
        return true;
    };
    $result = $this->emailWorkflow->send($this->emailId, $prepared['payload_sha256']);
    expect($result['status'])->toBe('accepted')->and($result['note'])->toContain('not delivery confirmation')
        ->and($this->emailGateway->messages)->toHaveCount(1)->and($this->emailGateway->lookups)->toBe(1)
        ->and($this->emailDb->table(SmsRunEmailWorkflow::TABLE)->value('Accepted_At'))->not->toBeNull()
        ->and($this->emailWorkflow->send($this->emailId, $prepared['payload_sha256']))->toBe($result)
        ->and($this->emailGateway->messages)->toHaveCount(1);
});

test('SMS email false and exception outcomes are held with no automatic resend or private provider errors', function (bool $throws): void {
    $prepared = ($this->emailPrepare)();
    $this->emailGateway->onSend = static function () use ($throws): bool {
        if ($throws) throw new RuntimeException('Secret synthetic transport response with fixture@example.invalid');
        return false;
    };
    $result = $this->emailWorkflow->send($this->emailId, $prepared['payload_sha256']);
    expect($result['status'])->toBe('uncertain')->and($this->emailDb->table(SmsRunEmailWorkflow::TABLE)->value('State'))->toBe('needs_review')
        ->and($this->emailDb->table(SmsRunEmailWorkflow::TABLE)->value('Error_Code'))->toBe($throws ? 'provider_exception' : 'provider_not_accepted')
        ->and(json_encode($result))->not->toContain('Secret', 'fixture@example.invalid', 'https:')
        ->and($this->emailWorkflow->send($this->emailId, $prepared['payload_sha256']))->toBe($result)
        ->and($this->emailGateway->messages)->toHaveCount(1);
})->with([false, true]);

test('SMS email provider acceptance followed by a failed receipt commit is never retried', function (): void {
    $prepared = ($this->emailPrepare)();
    $crashing = new class($this->emailGateway) extends SmsRunEmailWorkflow {
        protected function finish($db, PDO $pdo, object $row, string $state, ?string $code): void { throw new RuntimeException('Synthetic receipt-save crash.'); }
    };
    expect(fn () => $crashing->send($this->emailId, $prepared['payload_sha256']))->toThrow(RuntimeException::class, 'Synthetic receipt-save crash.')
        ->and($this->emailDb->table(SmsRunEmailWorkflow::TABLE)->value('State'))->toBe('attempting')
        ->and($this->emailGateway->messages)->toHaveCount(1);
    $checks = $this->emailGateway->checks;
    expect($this->emailWorkflow->send($this->emailId, $prepared['payload_sha256'])['status'])->toBe('uncertain')
        ->and($this->emailDb->table(SmsRunEmailWorkflow::TABLE)->value('Error_Code'))->toBe('interrupted_attempt')
        ->and($this->emailGateway->checks)->toBe($checks)->and($this->emailGateway->messages)->toHaveCount(1);
});

test('SMS email interrupted attempt is held even when the original file is unavailable or planned date has passed', function (): void {
    $prepared = ($this->emailPrepare)();
    $this->emailDb->table(SmsRunEmailWorkflow::TABLE)->update(['State' => 'attempting', 'Attempted_At' => '2026-10-23 18:01:00']);
    unlink($this->emailBundle['path']);
    Carbon::setTestNow(Carbon::parse('2026-11-01', 'UTC'));
    expect($this->emailWorkflow->send($this->emailId, $prepared['payload_sha256'])['status'])->toBe('uncertain')
        ->and($this->emailGateway->messages)->toBe([])->and($this->emailGateway->checks)->toBe(0);
});

test('SMS email local readiness failure remains ready without crossing the attempt boundary', function (): void {
    $prepared = ($this->emailPrepare)();
    $this->emailGateway->onReady = static fn () => throw new RuntimeException('Synthetic local configuration missing.');
    expect(fn () => $this->emailWorkflow->send($this->emailId, $prepared['payload_sha256']))->toThrow(RuntimeException::class)
        ->and($this->emailDb->table(SmsRunEmailWorkflow::TABLE)->value('State'))->toBe('ready')
        ->and($this->emailDb->table(SmsRunEmailWorkflow::TABLE)->value('Attempted_At'))->toBeNull()
        ->and($this->emailGateway->messages)->toBe([]);
});

test('SMS email explicit hash and exact link receipts prevent stale confirmation and mutable duplicate prepare', function (): void {
    $prepared = ($this->emailPrepare)();
    $before = (array) $this->emailDb->table(SmsRunEmailWorkflow::TABLE)->first();
    expect(fn () => $this->emailWorkflow->send($this->emailId, str_repeat('0', 64)))->toThrow(HttpException::class);
    $this->emailLinks[0]['url'] .= '&changed=1';
    expect(fn () => ($this->emailPrepare)())->toThrow(HttpException::class)
        ->and((array) $this->emailDb->table(SmsRunEmailWorkflow::TABLE)->first())->toBe($before)
        ->and($this->emailGateway->messages)->toBe([]);
});

test('SMS email no provider lookup occurs for pending legacy or unapproved runs', function (array $changes): void {
    $this->emailDb->table('TblSMSRuns')->update($changes);
    expect(fn () => ($this->emailPrepare)())->toThrow(Exception::class)
        ->and($this->emailGateway->lookups)->toBe(0)->and($this->emailGateway->messages)->toBe([])
        ->and($this->emailDb->table(SmsRunEmailWorkflow::TABLE)->count())->toBe(0);
})->with([
    'pending' => [['Status' => 'file_ready']], 'approving' => [['Status' => 'approving']],
    'legacy' => [['Selection_Policy' => 'legacy-phone-v1']], 'no options' => [['Run_Options_JSON' => null]],
    'no approval actor' => [['Approved_By' => null]], 'no approval time' => [['Approved_At' => null]],
    'pending pointer' => [['File_Path' => '/synthetic/pending.csv']], 'cleanup' => [['File_Cleanup_Path' => '/synthetic/quarantine']],
]);

test('SMS email corrupt artifact or approved receipt rejects preparation before dynamic lookup', function (string $kind): void {
    if ($kind === 'bytes') file_put_contents($this->emailBundle['path'], 'Synthetic corrupt bytes');
    else $this->emailDb->table('TblSMSRuns')->update(['File_Sha256' => str_repeat('0', 64)]);
    expect(fn () => ($this->emailPrepare)())->toThrow(RuntimeException::class)
        ->and($this->emailGateway->lookups)->toBe(0)->and($this->emailDb->table(SmsRunEmailWorkflow::TABLE)->count())->toBe(0);
})->with(['bytes', 'receipt']);

test('SMS email changed approved context or payload blocks ready sending without a provider attempt', function (string $kind): void {
    $prepared = ($this->emailPrepare)();
    if ($kind === 'run') $this->emailDb->table('TblSMSRuns')->update(['Approved_By' => 'changed@example.invalid']);
    else $this->emailDb->table(SmsRunEmailWorkflow::TABLE)->update(['Payload_JSON' => '{}']);
    expect(fn () => $this->emailWorkflow->send($this->emailId, $prepared['payload_sha256']))->toThrow(Exception::class)
        ->and($this->emailDb->table(SmsRunEmailWorkflow::TABLE)->value('State'))->toBe('ready')
        ->and($this->emailGateway->checks)->toBe(0)->and($this->emailGateway->messages)->toBe([]);
})->with(['run', 'payload']);

test('SMS email removing the current link allowlist blocks ready sending while preserving frozen payload', function (): void {
    $prepared = ($this->emailPrepare)();
    $before = (array) $this->emailDb->table(SmsRunEmailWorkflow::TABLE)->first();
    config(['sms-exports.email_allowed_link_hosts' => ['different.example.invalid']]);
    expect(fn () => $this->emailWorkflow->send($this->emailId, $prepared['payload_sha256']))->toThrow(Exception::class)
        ->and((array) $this->emailDb->table(SmsRunEmailWorkflow::TABLE)->first())->toBe($before)
        ->and($this->emailGateway->messages)->toBe([]);
});

test('SMS email cannot start after its California send date even if readiness crosses midnight', function (bool $crossesMidnight): void {
    $prepared = ($this->emailPrepare)();
    Carbon::setTestNow(Carbon::parse($crossesMidnight ? '2026-10-27 06:59:59' : '2026-10-27 07:00:00', 'UTC'));
    if ($crossesMidnight) $this->emailGateway->onReady = static fn () => Carbon::setTestNow(Carbon::parse('2026-10-27 07:00:00', 'UTC'));
    expect(fn () => $this->emailWorkflow->send($this->emailId, $prepared['payload_sha256']))->toThrow(InvalidArgumentException::class)
        ->and($this->emailDb->table(SmsRunEmailWorkflow::TABLE)->value('State'))->toBe('ready')
        ->and($this->emailGateway->messages)->toBe([]);
})->with([false, true]);

test('SMS email read projections are bounded private-link-free and never consult the dynamic table or mutate state', function (): void {
    $prepared = ($this->emailPrepare)();
    $stored = (array) $this->emailDb->table(SmsRunEmailWorkflow::TABLE)->first();
    $this->emailGateway->afterLookup = static fn () => throw new RuntimeException('Read consulted dynamic email table.');
    $this->emailGateway->onReady = static fn () => throw new RuntimeException('Read consulted transport readiness.');
    unlink($this->emailBundle['path']);
    expect($this->emailWorkflow->status($this->emailId))->toBe($prepared)
        ->and(json_encode($prepared))->not->toContain('https:', 'fixture@example.invalid', 'synthetic-only')
        ->and($this->emailWorkflow->preparedMessage($this->emailId)['to'])->toBe(['fixture@example.invalid'])
        ->and((array) $this->emailDb->table(SmsRunEmailWorkflow::TABLE)->first())->toBe($stored)
        ->and($this->emailGateway->checks)->toBe(0)->and($this->emailGateway->messages)->toBe([]);
});

test('SMS email disabled reads do not open a database connection and writes fail closed', function (): void {
    config(['sms-exports.email_handoff_enabled' => false]);
    Container::getInstance()->instance('db', new class { public function connection(): never { throw new RuntimeException('Disabled read opened DB.'); } });
    Facade::clearResolvedInstance('db');
    expect($this->emailWorkflow->status($this->emailId))->toBeNull()
        ->and($this->emailWorkflow->preparedMessage($this->emailId))->toBeNull()
        ->and(fn () => ($this->emailPrepare)())->toThrow(HttpException::class, 'not enabled');
});

test('SMS email missing schema has null read status and refuses preparation', function (): void {
    $this->emailDb->getSchemaBuilder()->drop(SmsRunEmailWorkflow::TABLE);
    expect($this->emailWorkflow->status($this->emailId))->toBeNull()
        ->and($this->emailWorkflow->preparedMessage($this->emailId))->toBeNull()
        ->and(fn () => ($this->emailPrepare)())->toThrow(HttpException::class, 'migration');
});

test('SMS email provider and preparation cannot run under Laravel or direct PDO transactions', function (bool $direct): void {
    if ($direct) $this->emailDb->getPdo()->beginTransaction(); else $this->emailDb->beginTransaction();
    try {
        expect(fn () => ($this->emailPrepare)())->toThrow(RuntimeException::class, 'outside every transaction')
            ->and($this->emailGateway->lookups)->toBe(0)->and($this->emailGateway->messages)->toBe([]);
    } finally {
        if ($direct) $this->emailDb->getPdo()->rollBack(); else $this->emailDb->rollBack();
    }
})->with([false, true]);

test('SMS email configuration lookup cannot replace the pinned SQL session before receipt insertion', function (): void {
    $original = $this->emailDb->getPdo();
    $this->emailGateway->afterLookup = function (): void { $this->emailDb->setPdo(new PDO('sqlite::memory:')); };
    try { expect(fn () => ($this->emailPrepare)())->toThrow(RuntimeException::class, 'pinned SQL session'); }
    finally { $this->emailDb->setPdo($original); }
    expect($this->emailDb->table(SmsRunEmailWorkflow::TABLE)->count())->toBe(0)->and($this->emailGateway->messages)->toBe([]);
});

test('SMS email changed run during configuration lookup cannot commit a stale email preparation', function (): void {
    $this->emailGateway->afterLookup = function (): void { $this->emailDb->table('TblSMSRuns')->update(['Approved_By' => 'changed@example.invalid']); };
    expect(fn () => ($this->emailPrepare)())->toThrow(HttpException::class, 'receipt changed')
        ->and($this->emailDb->table(SmsRunEmailWorkflow::TABLE)->count())->toBe(0);
});

test('SMS email Laravel begin reconnect loses the applock session and rolls back before any business callback', function (): void {
    $original = new SmsEmailBeginFixturePdo(true);
    $replacement = new SmsEmailBeginFixturePdo;
    $connection = new Illuminate\Database\SqlServerConnection($original, 'synthetic-no-database', '', ['driver' => 'sqlsrv']);
    $reconnections = 0;
    $connection->setReconnector(static function ($db) use ($replacement, &$reconnections): void {
        $reconnections++;
        $db->setPdo($replacement);
    });
    $business = 0;
    $method = new ReflectionMethod(SmsRunEmailWorkflow::class, 'transaction');
    expect(fn () => $method->invoke($this->emailWorkflow, $connection, $original, static function () use (&$business): void { $business++; }))
        ->toThrow(RuntimeException::class, 'session changed before commit')
        ->and($reconnections)->toBe(1)->and($original->begins)->toBe(1)->and($replacement->begins)->toBe(1)
        ->and($replacement->rollbacks)->toBe(1)->and($replacement->active)->toBeFalse()->and($business)->toBe(0);
});

test('SMS email command explicit queue and serialized independent job send once without changing approved business state', function (): void {
    $prepared = ($this->emailPrepare)();
    $container = Container::getInstance();
    $container->instance(SmsRunEmailWorkflow::class, $this->emailWorkflow);
    $captured = [];
    $dispatcher = $this->createMock(Illuminate\Contracts\Bus\Dispatcher::class);
    $dispatcher->expects($this->once())->method('dispatch')->willReturnCallback(static function ($job) use (&$captured) {
        $captured[] = serialize($job);
        return null;
    });
    $container->instance(Illuminate\Contracts\Bus\Dispatcher::class, $dispatcher);
    $command = new Cmd\Reports\Console\Commands\SmsRunEmailCommand;
    $command->setLaravel($container);
    $console = new Symfony\Component\Console\Application;
    $console->setAutoExit(false);
    if (method_exists($console, 'addCommand')) $console->addCommand($command); else $console->add($command);
    $output = new Symfony\Component\Console\Output\BufferedOutput;
    $runBefore = (array) $this->emailDb->table('TblSMSRuns')->first();
    $exit = $console->run(new Symfony\Component\Console\Input\ArrayInput([
        'command' => 'sms:run-email', 'request-id' => $this->emailId, '--action' => 'queue',
        '--payload-sha256' => $prepared['payload_sha256'], '--confirm-send' => true,
    ]), $output);
    expect($exit)->toBe(0)->and($output->fetch())->toContain('job queued')->and($this->emailGateway->messages)->toBe([])
        ->and($this->emailDb->table(SmsRunEmailWorkflow::TABLE)->value('State'))->toBe('ready');
    $job = unserialize($captured[0]);
    expect($job)->toBeInstanceOf(Cmd\Reports\Jobs\SmsRunEmailJob::class)->and($job->requestId)->toBe($this->emailId)
        ->and($job->payloadSha256)->toBe($prepared['payload_sha256']);
    $job->handle($this->emailWorkflow);
    // A duplicate delivery of the serialized queue payload cannot repeat the provider call.
    unserialize($captured[0])->handle($this->emailWorkflow);
    expect($this->emailGateway->messages)->toHaveCount(1)->and($this->emailWorkflow->status($this->emailId)['status'])->toBe('accepted')
        ->and((array) $this->emailDb->table('TblSMSRuns')->first())->toBe($runBefore)
        ->and($this->emailDb->table('TblSMSHistory')->count())->toBe(0)->and($this->emailDb->table('TblSMSAttemptAudit')->count())->toBe(0);
});

test('SMS email opt-in migration does not permit repeat install rollback or ambiguous partial schemas', function (): void {
    $migration = require dirname(__DIR__, 2).'/database/sms-migrations/2026_10_23_000003_create_sms_email_outbox.php';
    expect(fn () => $migration->up())->toThrow(RuntimeException::class)
        ->and(fn () => $migration->down())->toThrow(RuntimeException::class, 'Retain SMS email');
    $this->emailDb->getSchemaBuilder()->drop(SmsRunEmailWorkflow::TABLE);
    $this->emailDb->getSchemaBuilder()->dropColumns('TblSMSRuns', ['Approved_By']);
    expect(fn () => $migration->up())->toThrow(RuntimeException::class)
        ->and($this->emailDb->getSchemaBuilder()->hasTable(SmsRunEmailWorkflow::TABLE))->toBeFalse();
});

test('SMS email service rejects malformed operator actors before configuration or persistence', function (string $actor): void {
    expect(fn () => $this->emailWorkflow->prepare($this->emailId, $this->emailLinks, $actor))->toThrow(HttpException::class)
        ->and($this->emailGateway->lookups)->toBe(0)->and($this->emailDb->table(SmsRunEmailWorkflow::TABLE)->count())->toBe(0);
})->with(['', 'Synthetic Operator', ' fixture@example.invalid', "fixture@example.invalid\n", 'fixture@example.invalid extra']);

test('SMS email rejects inconsistent retained state evidence instead of resending or claiming acceptance', function (array $changes): void {
    $prepared = ($this->emailPrepare)();
    $this->emailDb->table(SmsRunEmailWorkflow::TABLE)->update($changes);
    expect(fn () => $this->emailWorkflow->send($this->emailId, $prepared['payload_sha256']))->toThrow(RuntimeException::class, 'inconsistent')
        ->and(fn () => $this->emailWorkflow->status($this->emailId))->toThrow(RuntimeException::class, 'inconsistent')
        ->and(fn () => $this->emailWorkflow->preparedMessage($this->emailId))->toThrow(RuntimeException::class, 'inconsistent')
        ->and($this->emailGateway->messages)->toBe([])->and($this->emailGateway->checks)->toBe(0);
})->with([
    'ready after attempt' => [['State' => 'ready', 'Attempted_At' => '2026-10-23 18:00:00']],
    'ready after acceptance' => [['State' => 'ready', 'Accepted_At' => '2026-10-23 18:00:00']],
    'ready with error' => [['State' => 'ready', 'Error_Code' => 'provider_exception']],
    'accepted missing attempts' => [['State' => 'accepted']],
    'accepted missing acceptance time' => [['State' => 'accepted', 'Attempted_At' => '2026-10-23 18:00:00']],
    'accepted before attempt' => [['State' => 'accepted', 'Attempted_At' => '2026-10-23 18:00:01', 'Accepted_At' => '2026-10-23 18:00:00']],
    'accepted with error' => [['State' => 'accepted', 'Attempted_At' => '2026-10-23 18:00:00', 'Accepted_At' => '2026-10-23 18:00:00', 'Error_Code' => 'provider_exception']],
    'attempting missing attempt time' => [['State' => 'attempting']],
    'attempting with acceptance time' => [['State' => 'attempting', 'Attempted_At' => '2026-10-23 18:00:00', 'Accepted_At' => '2026-10-23 18:00:00']],
    'attempting with error' => [['State' => 'attempting', 'Attempted_At' => '2026-10-23 18:00:00', 'Error_Code' => 'provider_exception']],
    'review missing attempt' => [['State' => 'needs_review', 'Error_Code' => 'provider_exception']],
    'review missing reason' => [['State' => 'needs_review', 'Attempted_At' => '2026-10-23 18:00:00']],
    'review invalid reason' => [['State' => 'needs_review', 'Attempted_At' => '2026-10-23 18:00:00', 'Error_Code' => 'private provider exception']],
    'review accepted time' => [['State' => 'needs_review', 'Attempted_At' => '2026-10-23 18:00:00', 'Accepted_At' => '2026-10-23 18:00:00', 'Error_Code' => 'provider_exception']],
    'invalid calendar' => [['State' => 'attempting', 'Attempted_At' => '2026-02-30 18:00:00']],
    'invalid clock' => [['State' => 'attempting', 'Attempted_At' => '2026-10-23 24:00:00']],
]);

test('SMS email state evidence accepts SQL Server fractional UTC timestamps without reattempting accepted messages', function (): void {
    $prepared = ($this->emailPrepare)();
    $this->emailDb->table(SmsRunEmailWorkflow::TABLE)->update(['State' => 'accepted',
        'Attempted_At' => '2026-10-23 18:00:00.000', 'Accepted_At' => '2026-10-23 18:00:00.0000001']);
    expect($this->emailWorkflow->status($this->emailId)['status'])->toBe('accepted')
        ->and($this->emailWorkflow->send($this->emailId, $prepared['payload_sha256'])['status'])->toBe('accepted')
        ->and($this->emailGateway->messages)->toBe([]);
});
