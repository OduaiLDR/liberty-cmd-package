<?php

declare(strict_types=1);

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
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;

final class SmsImportWorkflowPdo extends PDO
{
    public bool $loseCommitAck = false;
    public function __construct() { parent::__construct('sqlite::memory:'); }
    public function commit(): bool
    {
        $result = parent::commit();
        if ($this->loseCommitAck) { $this->loseCommitAck = false; throw new RuntimeException('PRIVATE-LOST-COMMIT-ACK'); }
        return $result;
    }
}

final class SmsImportWorkflowBeginConnection extends SQLiteConnection
{
    public bool $replaceAfterBegin = false;
    public ?PDO $displacedPdo = null;
    public ?PDO $replacementPdo = null;

    public function beginTransaction()
    {
        parent::beginTransaction();
        if ($this->replaceAfterBegin) {
            $this->replaceAfterBegin = false;
            $this->displacedPdo = $this->getPdo();
            $this->replacementPdo = new PDO('sqlite::memory:');
            $this->setPdo($this->replacementPdo);
        }
    }

}

/** Closed receiver; network methods assert they are outside all SQL transactions. */
final class SmsImportWorkflowGateway implements SmsDebtPleteImportGateway
{
    public array $destination = ['protocol' => 'debtplete-sms-import-v1', 'origin' => 'https://tenant.example.invalid',
        'tenant_id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'client_id' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc'];
    public ?SmsDebtPleteImportPlan $plan = null;
    public ?array $remote = null;
    public array $calls = [];
    public int $identities = 0;
    public bool $complete = true;
    public bool $statusUnknown = false;
    public bool $loseRegisterAck = false;
    public bool $loseUploadAck = false;
    public ?Closure $duringStatus = null;
    public ?Closure $duringUpload = null;
    public ?Closure $receiptMutation = null;

    public function identity(): array { ++$this->identities; return $this->destination; }
    public function register(array $payload): array
    {
        $this->outside(); $this->calls[] = ['register', $payload['request_id']];
        expect($payload)->toBe($this->plan->payload());
        $parts = [];
        foreach ($this->plan->parts() as $part) {
            $parts[] = ['part' => $part['part'], 'state' => 'awaiting_upload', 'rows_expected' => $part['rows'], 'rows_committed' => 0,
                'accepted' => false, 'bytes' => $part['bytes'], 'sha256' => $part['sha256']];
        }
        $this->remote = ['request_id' => $this->plan->requestId(), 'plan_sha256' => $this->plan->sha256(), 'state' => 'queued', 'status' => 'queued',
            'processed' => 0, 'total' => $this->plan->rows(), 'parts' => $parts, 'error_code' => null];
        if ($this->loseRegisterAck) { $this->loseRegisterAck = false; throw new RuntimeException('PRIVATE-REGISTER-ACK'); }
        return $this->response();
    }
    public function upload(string $requestId, int $part, string $planSha256, mixed $stream): array
    {
        $this->outside(); $this->calls[] = ['upload', $requestId, $part];
        expect($requestId)->toBe($this->plan->requestId())->and($planSha256)->toBe($this->plan->sha256())->and(ftell($stream))->toBe(0);
        $bytes = stream_get_contents($stream); $expected = $this->plan->parts()[$part - 1];
        expect(strlen($bytes))->toBe($expected['bytes'])->and(hash('sha256', $bytes))->toBe($expected['sha256']);
        $this->remote['parts'][$part - 1]['accepted'] = true;
        $this->remote['parts'][$part - 1]['state'] = 'uploaded';
        if ($this->complete && !array_filter($this->remote['parts'], static fn (array $receipt): bool => !$receipt['accepted'])) {
            $this->remote['state'] = $this->remote['status'] = 'completed';
            $this->remote['processed'] = $this->plan->rows();
            foreach ($this->remote['parts'] as &$receipt) { $receipt['state'] = 'completed'; $receipt['rows_committed'] = $receipt['rows_expected']; }
            unset($receipt);
        }
        if ($this->duringUpload !== null) { ($this->duringUpload)($part); }
        if ($this->loseUploadAck) { $this->loseUploadAck = false; throw new RuntimeException('PRIVATE-UPLOAD-ACK'); }
        return $this->response();
    }
    public function status(string $requestId): array
    {
        $this->outside(); $this->calls[] = ['status', $requestId];
        if ($this->duringStatus !== null) { ($this->duringStatus)(); }
        if ($this->statusUnknown) { throw new RuntimeException('PRIVATE-UNKNOWN-STATUS', 503); }
        if ($this->remote === null) { throw new RuntimeException('SMS import not found.', 404); }
        return $this->response();
    }
    private function response(): array { return $this->receiptMutation === null ? $this->remote : ($this->receiptMutation)($this->remote); }
    private function outside(): void
    {
        $db = DB::connection('sqlsrv');
        expect($db->transactionLevel())->toBe(0)->and($db->getPdo()->inTransaction())->toBeFalse();
    }
}

beforeEach(function (): void {
    $this->previousContainer = Container::getInstance(); $this->previousFacade = Facade::getFacadeApplication();
    $this->previousClock = Carbon::getTestNow(); Carbon::setTestNow(Carbon::parse('2026-10-26 12:00:00', 'UTC'));
    $container = new class extends Container { public function runningUnitTests(): bool { return true; } };
    Container::setInstance($container);
    $this->importRoot = str_replace('\\', '/', sys_get_temp_dir()).'/sms-import-sender-flow-'.bin2hex(random_bytes(8)); mkdir($this->importRoot, 0700);
    $container->instance('config', new Repository(['sms-exports' => ['local_root' => $this->importRoot,
        'debtplete_import_enabled' => true, 'debtplete_import_automatic' => false], 'database' => ['default' => 'sqlsrv']]));
    $capsule = new Manager($container); $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:'], 'sqlsrv');
    $capsule->getDatabaseManager()->extend('sqlite', static fn (array $config): SQLiteConnection => new SmsImportWorkflowBeginConnection(new SmsImportWorkflowPdo(), ':memory:', '', $config));
    $capsule->getDatabaseManager()->setDefaultConnection('sqlsrv'); $container->instance('db', $capsule->getDatabaseManager());
    Facade::clearResolvedInstances(); Facade::setFacadeApplication($container); $this->db = $capsule->getConnection('sqlsrv');
    $schema = $this->db->getSchemaBuilder();
    $schema->create('TblSMSRuns', function (Blueprint $table): void {
        $table->string('Request_ID', 36)->primary();
        foreach (['Status', 'Selection_Policy', 'Approved_By', 'Approved_At', 'Sent_By', 'Sent_At', 'Run_Options_JSON', 'Artifact_Manifest_JSON', 'File_Path', 'File_Sha256', 'File_Cleanup_Path'] as $field) { $table->text($field)->nullable(); }
        foreach (['Target', 'Selected', 'File_Bytes'] as $field) { $table->bigInteger($field); }
    });
    $schema->create(SmsRunDebtPleteImportWorkflow::TABLE, function (Blueprint $table): void {
        $table->string('Request_ID', 36)->primary();
        foreach (['Run_Sha256', 'Destination_JSON', 'Destination_Sha256', 'Plan_JSON', 'Plan_Sha256', 'State', 'Created_By', 'Created_At', 'Updated_At'] as $field) { $table->text($field); }
        foreach (['Remote_Receipt_JSON', 'Remote_Receipt_Sha256', 'Lease_Token', 'Lease_Until', 'Completed_At', 'Error_Code'] as $field) { $table->text($field)->nullable(); }
        $table->bigInteger('Lease_Version')->default(0); $table->index(['State', 'Lease_Until', 'Request_ID']);
    });
    $this->id = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'; $this->directory = $this->importRoot.'/runs/'.$this->id;
    $options = SmsRunOptions::normalize(3, 2, ['T1'], SmsRunOptions::WINDOWED_TIER_SELECTION_POLICY,
        SmsRunOptions::PLANNED_DATE_VERSION, '2020-01-13', SmsRunOptions::APPROVAL_FILENAME_VERSION);
    $rows = [['Synthetic one', '60000', '0555010101', '2020-01-13'], ['Synthetic two', '60000', '0555010101', '2020-01-13'], ['Synthetic three', '60000', '0555010102', '2020-01-13']];
    $pending = (new SmsRunArtifactBundle())->build($this->directory, 'SMS Export - 2020-01-13 - ID Pending', $rows, 3, $options);
    $this->bundle = (new SmsRunArtifactBundle('v2'))->publishApproved($this->directory, $pending['manifest'], $options, 3, 'SMS0001-SMS0003');
    $this->db->table('TblSMSRuns')->insert(['Request_ID' => $this->id, 'Status' => 'approved', 'Selection_Policy' => 'prospect-record-v1',
        'Target' => 3, 'Selected' => 3, 'Approved_By' => 'fixture@example.invalid', 'Approved_At' => '2020-01-06 12:34:56.1234567',
        'Sent_By' => 'fixture@example.invalid', 'Sent_At' => '2020-01-06 12:34:56.1234567', 'Run_Options_JSON' => json_encode($options),
        'Artifact_Manifest_JSON' => json_encode($this->bundle['manifest']), 'File_Path' => $this->bundle['path'],
        'File_Bytes' => $this->bundle['bytes'], 'File_Sha256' => $this->bundle['sha256']]);
    $this->gateway = new SmsImportWorkflowGateway();
    $this->gateway->plan = SmsDebtPleteImportPlan::fromApproved($this->db->table('TblSMSRuns')->first(), $this->gateway->destination);
    $this->workflow = new SmsRunDebtPleteImportWorkflow($this->gateway, new SmsRunArtifactPartReader());
    $this->ledger = fn (): array => $this->db->table(SmsRunDebtPleteImportWorkflow::TABLE)->get()->map(static fn (object $row): array => (array) $row)->all();
});

afterEach(function (): void {
    $pdo = $this->db->getPdo();
    if ($pdo instanceof PDO && $pdo->inTransaction()) { $pdo->rollBack(); }
    $root = realpath($this->importRoot); $temporary = str_replace('\\', '/', realpath(sys_get_temp_dir()));
    if ($root === false || is_link($this->importRoot) || !str_starts_with(str_replace('\\', '/', $root), $temporary.'/sms-import-sender-flow-')) { throw new RuntimeException('Refusing to clean an unowned workflow fixture.'); }
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) { $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname()); }
    rmdir($root); Carbon::setTestNow($this->previousClock); Facade::clearResolvedInstances(); Facade::setFacadeApplication($this->previousFacade); Container::setInstance($this->previousContainer);
});

it('holds a late suppressed approved handoff before new registration while allowing status reconciliation', function (): void {
    $ids = \Cmd\Reports\Tests\Support\SmsFrozenEligibilityFixture::seed($this->db, $this->id);
    $this->workflow->prepare($this->id, 'fixture@example.invalid');
    config(['sms-exports.approved_handoff_suppression_enabled' => true]);
    $this->db->table('TblProspects')->where('PK', $ids[0])->update(['Opt_Out' => 1]);
    expect($this->workflow->step($this->id)['status'])->toBe('held')
        ->and(array_column($this->gateway->calls, 0))->toBe(['status'])
        ->and($this->db->table('TblSMSHistory')->count())->toBe(3)
        ->and(hash_file('sha256', $this->bundle['path']))->toBe($this->bundle['sha256']);
});

it('reconciles accepted remote data but stops the next part when suppression changes', function (): void {
    $ids = \Cmd\Reports\Tests\Support\SmsFrozenEligibilityFixture::seed($this->db, $this->id);
    config(['sms-exports.approved_handoff_suppression_enabled' => true]);
    $this->workflow->prepare($this->id, 'fixture@example.invalid');
    expect($this->workflow->step($this->id)['files_accepted'])->toBe(1);
    $calls = $this->gateway->calls;
    $this->db->table('TblProspects')->where('PK', $ids[1])->update(['State' => 'CO']);
    $result = $this->workflow->step($this->id);
    expect($result['status'])->toBe('held')->and($result['files_accepted'])->toBe(1)
        ->and(array_slice($this->gateway->calls, count($calls)))->toBe([['status', $this->id]])
        ->and($this->db->table('TblSMSHistory')->count())->toBe(3)
        ->and(hash_file('sha256', $this->bundle['path']))->toBe($this->bundle['sha256']);
});

it('adopts an already completed remote receipt after late suppression without registering or uploading again', function (): void {
    $ids = \Cmd\Reports\Tests\Support\SmsFrozenEligibilityFixture::seed($this->db, $this->id);
    config(['sms-exports.approved_handoff_suppression_enabled' => true]);
    $this->workflow->prepare($this->id, 'fixture@example.invalid');
    $this->workflow->step($this->id);
    // Model a receiver completion whose last acknowledgement was lost locally.
    $this->gateway->remote['state'] = $this->gateway->remote['status'] = 'completed';
    $this->gateway->remote['processed'] = 3;
    foreach ($this->gateway->remote['parts'] as &$part) {
        $part['accepted'] = true; $part['state'] = 'completed'; $part['rows_committed'] = $part['rows_expected'];
    }
    unset($part);
    $calls = $this->gateway->calls;
    $this->db->table('TblProspects')->where('PK', $ids[0])->update(['Contacted' => 1]);
    $result = $this->workflow->step($this->id);
    expect($result['status'])->toBe('completed')->and($result['processed'])->toBe(3)
        ->and(array_slice($this->gateway->calls, count($calls)))->toBe([['status', $this->id]]);
});

it('captures approval in its existing commit and rolls back the outbox without reading files or contacting the receiver', function (): void {
    config(['sms-exports.debtplete_import_automatic' => true]);
    rename($this->bundle['path'], $this->bundle['path'].'.retained');
    try {
        $this->db->beginTransaction();
        $this->workflow->captureApproved($this->db->table('TblSMSRuns')->first());
        expect($this->db->table(SmsRunDebtPleteImportWorkflow::TABLE)->count())->toBe(1)->and($this->gateway->calls)->toBe([]);
        $this->db->rollBack();
        expect(($this->ledger)())->toBe([]);
    } finally { rename($this->bundle['path'].'.retained', $this->bundle['path']); }
    expect(fn () => $this->workflow->captureApproved($this->db->table('TblSMSRuns')->first()))->toThrow(RuntimeException::class);
});

it('does no work when disabled and never retroactively discovers existing approved rows', function (): void {
    $this->workflow->captureApproved($this->db->table('TblSMSRuns')->first());
    expect(($this->ledger)())->toBe([])->and($this->workflow->pending())->toBe([]);
    config(['sms-exports.debtplete_import_enabled' => false, 'sms-exports.debtplete_import_automatic' => true]);
    $identities = $this->gateway->identities;
    $this->workflow->captureApproved(new stdClass());
    expect($this->workflow->status($this->id))->toBeNull()->and($this->workflow->pending())->toBe([])
        ->and($this->gateway->identities)->toBe($identities)->and($this->gateway->calls)->toBe([]);
    expect(fn () => $this->workflow->prepare($this->id, 'fixture@example.invalid'))->toThrow(RuntimeException::class);
});

it('uploads at most one part per step and completes only after the remote aggregate and final original bundle proof', function (): void {
    $source = (array) $this->db->table('TblSMSRuns')->first();
    $prepared = $this->workflow->prepare($this->id, 'fixture@example.invalid');
    expect($prepared['status'])->toBe('queued')->and($prepared['processed'])->toBeNull()->and($prepared['files_accepted'])->toBeNull()
        ->and($prepared['remote_status'])->toBeNull()->and($this->gateway->calls)->toBe([])->and($this->workflow->pending())->toBe([$this->id]);
    $one = $this->workflow->step($this->id);
    expect($one['status'])->toBe('pending')->and($one['files_accepted'])->toBe(1)->and($one['processed'])->toBe(0)
        ->and(array_column($this->gateway->calls, 0))->toBe(['status', 'register', 'upload']);
    $two = $this->workflow->step($this->id);
    expect($two['status'])->toBe('completed')->and($two['processed'])->toBe(3)->and($two['files_accepted'])->toBe(2)
        ->and(array_column($this->gateway->calls, 0))->toBe(['status', 'register', 'upload', 'status', 'upload'])
        ->and(array_column(array_filter($this->gateway->calls, static fn (array $call): bool => $call[0] === 'upload'), 2))->toBe([1, 2])
        ->and((array) $this->db->table('TblSMSRuns')->first())->toBe($source)->and(hash_file('sha256', $this->bundle['path']))->toBe($this->bundle['sha256']);
    $before = ($this->ledger)(); $calls = $this->gateway->calls;
    rename($this->bundle['path'], $this->bundle['path'].'.retained');
    try {
        expect($this->workflow->step($this->id))->toBe($two)->and($this->workflow->prepare($this->id, 'other@example.invalid'))->toBe($two)
            ->and($this->workflow->status($this->id))->toBe($two)->and(($this->ledger)())->toBe($before)
            ->and($this->gateway->calls)->toBe($calls)->and($this->workflow->pending())->toBe([]);
    } finally { rename($this->bundle['path'].'.retained', $this->bundle['path']); }
});

it('reconciles lost register and upload acknowledgements using the same GET-first identity without duplicate uploads', function (string $lost): void {
    $this->workflow->prepare($this->id, 'fixture@example.invalid');
    if ($lost === 'register') { $this->gateway->loseRegisterAck = true; } else { $this->gateway->loseUploadAck = true; }
    $uncertain = $this->workflow->step($this->id);
    expect($uncertain['status'])->toBe($lost === 'upload' ? 'working' : 'pending')->and($uncertain['error_code'])->toBe('pending_recovery');
    if ($lost === 'register') { expect($uncertain['processed'])->toBeNull()->and($uncertain['files_accepted'])->toBeNull(); }
    else {
        $calls = $this->gateway->calls;
        expect($this->workflow->step($this->id)['status'])->toBe('working')->and($this->gateway->calls)->toBe($calls);
        Carbon::setTestNow(Carbon::parse('2026-10-26 12:25:01', 'UTC'));
    }
    for ($step = 0; $step < 3 && $this->workflow->status($this->id)['status'] !== 'completed'; ++$step) { $this->workflow->step($this->id); }
    expect($this->workflow->status($this->id)['status'])->toBe('completed')
        ->and(count(array_filter($this->gateway->calls, static fn (array $call): bool => $call[0] === 'register')))->toBe(1)
        ->and(array_column(array_filter($this->gateway->calls, static fn (array $call): bool => $call[0] === 'upload'), 2))->toBe([1, 2]);
})->with(['register', 'upload']);

it('never registers on an uncertain non-404 status response', function (): void {
    $this->workflow->prepare($this->id, 'fixture@example.invalid'); $this->gateway->statusUnknown = true;
    $result = $this->workflow->step($this->id);
    expect($result['status'])->toBe('pending')->and($result['processed'])->toBeNull()->and($result['files_accepted'])->toBeNull()
        ->and(array_column($this->gateway->calls, 0))->toBe(['status']);
});

it('holds malformed or regressing remote receipts while retaining the last validated counters and receipt', function (string $kind): void {
    $this->workflow->prepare($this->id, 'fixture@example.invalid'); $this->gateway->complete = false;
    $this->workflow->step($this->id); $before = ($this->ledger)()[0];
    $this->gateway->receiptMutation = static function (array $receipt) use ($kind): array {
        if ($kind === 'leak') { $receipt['private'] = 'PRIVATE-PHONE-0555010101'; }
        elseif ($kind === 'regress') { $receipt['parts'][0]['accepted'] = false; $receipt['parts'][0]['state'] = 'awaiting_upload'; }
        else { $receipt['plan_sha256'] = str_repeat('0', 64); }
        return $receipt;
    };
    $result = $this->workflow->step($this->id); $after = ($this->ledger)()[0];
    expect($result['status'])->toBe('held')->and($result['error_code'])->toBe('receipt_invalid')->and($result['files_accepted'])->toBe(1)
        ->and($after['Remote_Receipt_JSON'])->toBe($before['Remote_Receipt_JSON'])->and($after['Remote_Receipt_Sha256'])->toBe($before['Remote_Receipt_Sha256'])
        ->and(json_encode($after))->not->toContain('PRIVATE-PHONE');
})->with(['leak', 'regress', 'identity']);

it('returns metadata-only status after the original files are unavailable', function (): void {
    $this->workflow->prepare($this->id, 'fixture@example.invalid'); $before = $this->workflow->status($this->id);
    rename($this->bundle['path'], $this->bundle['path'].'.retained');
    try { expect($this->workflow->status($this->id))->toBe($before)->and($this->gateway->calls)->toBe([]); }
    finally { rename($this->bundle['path'].'.retained', $this->bundle['path']); }
});

it('retains the lease without further remote work after a gate source destination or lease fence changes', function (string $change): void {
    $this->workflow->prepare($this->id, 'fixture@example.invalid');
    $this->gateway->duringStatus = function () use ($change): void {
        match ($change) {
            'gate' => config(['sms-exports.debtplete_import_enabled' => false]),
            'source' => $this->db->table('TblSMSRuns')->where('Request_ID', $this->id)->update(['Sent_By' => 'changed@example.invalid']),
            'destination' => $this->gateway->destination['origin'] = 'https://other.example.invalid',
            'lease' => $this->db->table(SmsRunDebtPleteImportWorkflow::TABLE)->where('Request_ID', $this->id)->update(['Lease_Token' => str_repeat('f', 64)]),
        };
    };
    if ($change === 'lease') { expect($this->workflow->step($this->id)['status'])->toBe('working'); }
    else { expect(fn () => $this->workflow->step($this->id))->toThrow(RuntimeException::class); }
    expect(array_column($this->gateway->calls, 0))->toBe(['status'])
        ->and(($this->ledger)()[0]['State'])->toBe('working')->and(($this->ledger)()[0]['Remote_Receipt_JSON'])->toBeNull();
})->with(['gate', 'source', 'destination', 'lease']);

it('preserves a committed receipt after a lost SQL acknowledgement and resumes the exact plan after lease expiry', function (): void {
    $this->workflow->prepare($this->id, 'fixture@example.invalid');
    $this->gateway->duringStatus = function (): void { $this->gateway->duringStatus = null; $this->db->getPdo()->loseCommitAck = true; };
    expect(fn () => $this->workflow->step($this->id))->toThrow(RuntimeException::class);
    $row = ($this->ledger)()[0];
    expect($row['State'])->toBe('working')->and($row['Remote_Receipt_JSON'])->not->toBeNull()
        ->and(array_column($this->gateway->calls, 0))->toBe(['status', 'register']);
    Carbon::setTestNow(Carbon::parse('2026-10-26 12:25:01', 'UTC'));
    expect($this->workflow->step($this->id)['files_accepted'])->toBe(1)
        ->and(array_column($this->gateway->calls, 0))->toBe(['status', 'register', 'status', 'upload']);
});

it('never marks completion when the original bundle ownership receipt changes after a remote completed response', function (): void {
    $this->workflow->prepare($this->id, 'fixture@example.invalid'); $this->workflow->step($this->id);
    $before = ($this->ledger)()[0];
    $this->gateway->duringUpload = function (int $part): void {
        if ($part === 2) { expect(file_put_contents($this->directory.'/v2/published/manifest.json', '{}'))->toBe(2); }
    };
    $result = $this->workflow->step($this->id); $after = ($this->ledger)()[0];
    expect($result['status'])->toBe('held')->and($result['error_code'])->toBe('source_unconfirmed')
        ->and($after['Completed_At'])->toBeNull()->and($after['Remote_Receipt_JSON'])->toBe($before['Remote_Receipt_JSON'])
        ->and($result['processed'])->toBe(0)->and($result['files_accepted'])->toBe(1);
});

it('does not turn an uncertain local receipt read into an invented malformed-remote hold', function (): void {
    $this->workflow->prepare($this->id, 'fixture@example.invalid');
    $this->gateway->receiptMutation = function (array $receipt): array {
        $this->gateway->receiptMutation = null;
        $reads = 0;
        $this->db->beforeExecuting(static function (string $sql) use (&$reads): void {
            if (str_starts_with(strtolower($sql), 'select') && str_contains($sql, 'TblSMSDebtPleteImports') && ++$reads === 2) {
                throw new RuntimeException('PRIVATE-SYNTHETIC-LOCAL-READ-OUTAGE');
            }
        });
        return $receipt;
    };
    expect(fn () => $this->workflow->step($this->id))->toThrow(RuntimeException::class);
    $row = ($this->ledger)()[0];
    expect($row['State'])->toBe('working')->and($row['Error_Code'])->toBeNull()->and($row['Remote_Receipt_JSON'])->toBeNull()
        ->and(array_column($this->gateway->calls, 0))->toBe(['status', 'register']);
});

it('does not overlap an active lease and resumes only after its expiry', function (): void {
    $this->workflow->prepare($this->id, 'fixture@example.invalid');
    $this->db->table(SmsRunDebtPleteImportWorkflow::TABLE)->update(['State' => 'working', 'Lease_Token' => str_repeat('d', 64), 'Lease_Version' => 1, 'Lease_Until' => '2026-10-26 12:25:00']);
    expect($this->workflow->step($this->id)['status'])->toBe('working')->and($this->gateway->calls)->toBe([])->and($this->workflow->pending())->toBe([]);
    Carbon::setTestNow(Carbon::parse('2026-10-26 12:25:01', 'UTC'));
    expect($this->workflow->pending())->toBe([$this->id])->and($this->workflow->step($this->id)['files_accepted'])->toBe(1)
        ->and(($this->ledger)()[0]['Lease_Version'])->toBe(2);
});

it('persists the first uploading observation and permits one same-part retry only after its durable wait and fresh GET', function (): void {
    $this->workflow->prepare($this->id, 'fixture@example.invalid');
    $this->gateway->register($this->gateway->plan->payload());
    $this->gateway->remote['parts'][0]['state'] = 'uploading'; $this->gateway->calls = [];
    $first = $this->workflow->step($this->id);
    expect($first['status'])->toBe('working')->and($first['processed'])->toBe(0)->and($first['files_accepted'])->toBe(0)
        ->and(array_column($this->gateway->calls, 0))->toBe(['status'])
        ->and(($this->ledger)()[0]['Lease_Until'])->toBe('2026-10-26 12:25:00.000000')
        ->and(($this->ledger)()[0]['Error_Code'])->toBe('pending_recovery')->and($this->workflow->pending())->toBe([]);
    $resumed = new SmsRunDebtPleteImportWorkflow($this->gateway, new SmsRunArtifactPartReader());
    Carbon::setTestNow(Carbon::parse('2026-10-26 12:24:59', 'UTC'));
    expect($resumed->step($this->id)['status'])->toBe('working')->and(array_column($this->gateway->calls, 0))->toBe(['status']);
    Carbon::setTestNow(Carbon::parse('2026-10-26 12:25:01', 'UTC'));
    expect($resumed->pending())->toBe([$this->id])->and($resumed->step($this->id)['files_accepted'])->toBe(1)
        ->and(array_column($this->gateway->calls, 0))->toBe(['status', 'status', 'upload'])
        ->and($this->gateway->calls[2][2])->toBe(1);
    expect($resumed->step($this->id)['status'])->toBe('completed')
        ->and(array_column(array_filter($this->gateway->calls, static fn (array $call): bool => $call[0] === 'upload'), 2))->toBe([1, 2]);
});

it('backs off from the unknown PUT time while the receiver still reports uploading instead of replaying a large body each minute', function (): void {
    $this->workflow->prepare($this->id, 'fixture@example.invalid'); $this->gateway->loseUploadAck = true;
    $this->gateway->duringUpload = function (int $part): void {
        $this->gateway->duringUpload = null;
        $this->gateway->remote['parts'][$part - 1]['accepted'] = false;
        $this->gateway->remote['parts'][$part - 1]['state'] = 'uploading';
        Carbon::setTestNow(Carbon::parse('2026-10-26 12:10:00', 'UTC'));
    };
    $uncertain = $this->workflow->step($this->id);
    expect($uncertain['status'])->toBe('working')->and($uncertain['files_accepted'])->toBe(0)
        ->and(($this->ledger)()[0]['Lease_Until'])->toBe('2026-10-26 12:35:00.000000');
    $calls = $this->gateway->calls;
    $resumed = new SmsRunDebtPleteImportWorkflow($this->gateway, new SmsRunArtifactPartReader());
    foreach (['12:11:00', '12:20:00', '12:34:59'] as $clock) {
        Carbon::setTestNow(Carbon::parse('2026-10-26 '.$clock, 'UTC'));
        expect($resumed->step($this->id)['status'])->toBe('working')->and($this->gateway->calls)->toBe($calls)
            ->and($resumed->pending())->toBe([]);
    }
    Carbon::setTestNow(Carbon::parse('2026-10-26 12:35:01', 'UTC'));
    expect($resumed->step($this->id)['files_accepted'])->toBe(1)
        ->and(array_column($this->gateway->calls, 0))->toBe(['status', 'register', 'upload', 'status', 'upload'])
        ->and(array_column(array_filter($this->gateway->calls, static fn (array $call): bool => $call[0] === 'upload'), 2))->toBe([1, 1]);
    expect($resumed->step($this->id)['status'])->toBe('completed');
});

it('does not mistake an old uploading snapshot from a crashed active attempt for an explicit completed wait', function (): void {
    $this->workflow->prepare($this->id, 'fixture@example.invalid');
    $this->gateway->register($this->gateway->plan->payload()); $this->gateway->remote['parts'][0]['state'] = 'uploading';
    $this->workflow->step($this->id);
    // A worker consumed its wait, began a body retry and died before acknowledging it.
    $this->db->table(SmsRunDebtPleteImportWorkflow::TABLE)->update(['Error_Code' => null]);
    $this->gateway->calls = []; Carbon::setTestNow(Carbon::parse('2026-10-26 12:25:01', 'UTC'));
    $result = (new SmsRunDebtPleteImportWorkflow($this->gateway, new SmsRunArtifactPartReader()))->step($this->id);
    expect($result['status'])->toBe('working')->and(array_column($this->gateway->calls, 0))->toBe(['status'])
        ->and(($this->ledger)()[0]['Lease_Until'])->toBe('2026-10-26 12:50:01.000000')
        ->and(($this->ledger)()[0]['Error_Code'])->toBe('pending_recovery');
});

it('retains the uploading wait after its SQL commit acknowledgement is lost', function (): void {
    $this->workflow->prepare($this->id, 'fixture@example.invalid');
    $this->gateway->register($this->gateway->plan->payload()); $this->gateway->remote['parts'][0]['state'] = 'uploading';
    $this->gateway->calls = [];
    $this->gateway->receiptMutation = function (array $receipt): array {
        $this->gateway->receiptMutation = null; $this->db->getPdo()->loseCommitAck = true;
        return $receipt;
    };
    expect(fn () => $this->workflow->step($this->id))->toThrow(RuntimeException::class);
    $row = ($this->ledger)()[0];
    expect($row['State'])->toBe('working')->and($row['Error_Code'])->toBe('pending_recovery')
        ->and($row['Lease_Until'])->toBe('2026-10-26 12:25:00.000000');
    $resumed = new SmsRunDebtPleteImportWorkflow($this->gateway, new SmsRunArtifactPartReader());
    expect($resumed->step($this->id)['status'])->toBe('working')->and(array_column($this->gateway->calls, 0))->toBe(['status']);
    Carbon::setTestNow(Carbon::parse('2026-10-26 12:25:01', 'UTC'));
    expect($resumed->step($this->id)['files_accepted'])->toBe(1)
        ->and(array_column($this->gateway->calls, 0))->toBe(['status', 'status', 'upload']);
});

it('refuses ambient transactions and checks the pinned PDO again immediately after BEGIN', function (): void {
    $this->db->beginTransaction();
    expect(fn () => $this->workflow->prepare($this->id, 'fixture@example.invalid'))->toThrow(RuntimeException::class);
    $this->db->rollBack(); $this->workflow->prepare($this->id, 'fixture@example.invalid');
    $this->db->replaceAfterBegin = true;
    expect(fn () => $this->workflow->step($this->id))->toThrow(RuntimeException::class);
    expect($this->gateway->calls)->toBe([])->and($this->db->displacedPdo->inTransaction())->toBeFalse()
        ->and($this->db->replacementPdo->inTransaction())->toBeFalse()
        ->and($this->db->displacedPdo->query('SELECT State FROM TblSMSDebtPleteImports')->fetchColumn())->toBe('queued');
});
