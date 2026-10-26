<?php

declare(strict_types=1);

use Cmd\Reports\Console\Commands\RecoverSmsRunDebtPleteImports;
use Cmd\Reports\Console\Commands\SmsRunDebtPleteImportCommand;
use Cmd\Reports\Services\SmsDebtPleteImportPrivacy;
use Cmd\Reports\Services\SmsRunDebtPleteImportWorkflow;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

// Boundary fakes isolate command routing/privacy; approval, SQL receipts and
// remote protocol correctness belong to the separate workflow/native suites.
class ImportCommandWorkflowBoundaryFake extends SmsRunDebtPleteImportWorkflow
{
    public array $calls = [];
    public ?array $saved = null;
    public array $result = ['status' => 'pending', 'processed' => 0, 'total' => 3];
    public array $ids = [];
    public ?Throwable $failure = null;
    public function __construct() {}
    private function record(string $method, array $args): void
    {
        $this->calls[] = [$method, $args, SmsDebtPleteImportPrivacy::active()];
        if ($this->failure) throw $this->failure;
    }
    public function status(string $id): ?array { $this->record('status', [$id]); return $this->saved; }
    public function prepare(string $id, string $actor): array { $this->record('prepare', [$id, $actor]); return $this->result; }
    public function step(string $id): array { $this->record('step', [$id]); return $this->result; }
    public function pending(int $limit = 10): array { $this->record('pending', [$limit]); return $this->ids; }
}

trait ImportCommandBoundarySeams
{
    public ImportCommandWorkflowBoundaryFake $boundary;
    public int $factories = 0;
    public bool $factoryFails = false;
    public array $dispatchAttempts = [];
    public ?int $failDispatchAt = null;
    protected function workflow(): SmsRunDebtPleteImportWorkflow
    {
        expect(SmsDebtPleteImportPrivacy::active())->toBeTrue();
        ++$this->factories;
        if ($this->factoryFails) throw new RuntimeException('RAW SQL password SENTINEL');
        return $this->boundary;
    }
    protected function enqueue(string $id): void
    {
        expect(SmsDebtPleteImportPrivacy::active())->toBeTrue();
        $this->dispatchAttempts[] = $id;
        if ($this->failDispatchAt === count($this->dispatchAttempts)) throw new RuntimeException('RAW queue credential SENTINEL');
    }
}

class ImportCommandBoundary extends SmsRunDebtPleteImportCommand { use ImportCommandBoundarySeams; }
class ImportRecoverCommandBoundary extends RecoverSmsRunDebtPleteImports { use ImportCommandBoundarySeams; }

beforeEach(function (): void {
    $this->importCommandPrevious = Container::getInstance();
    $app = new class extends Container { public function runningUnitTests(): bool { return true; } };
    $app->instance('config', new Repository([
        'sms-exports' => ['debtplete_import_enabled' => true, 'queue' => 'tu-export'],
        'queue' => ['default' => 'database', 'connections' => ['database' => ['driver' => 'database', 'retry_after' => 7200]]],
    ]));
    Container::setInstance($app);
    $this->importCommandApp = $app;
    $this->importCommandWorkflow = new ImportCommandWorkflowBoundaryFake;
    $this->importCommand = new ImportCommandBoundary;
    $this->importRecovery = new ImportRecoverCommandBoundary;
    $this->importCommand->boundary = $this->importCommandWorkflow;
    $this->importRecovery->boundary = $this->importCommandWorkflow;
    $this->importCommandId = '11111111-2222-4333-8444-555555555555';
});

afterEach(function (): void {
    expect(SmsDebtPleteImportPrivacy::active())->toBeFalse();
    Container::setInstance($this->importCommandPrevious);
});

function runImportBoundaryCommand(Container $app, \Illuminate\Console\Command $command, array $arguments = []): array
{
    $command->setLaravel($app);
    $console = new Application; $console->setAutoExit(false);
    if (method_exists($console, 'addCommand')) $console->addCommand($command); else $console->add($command);
    $input = new ArrayInput(['command' => $command->getName()] + $arguments); $input->setInteractive(false);
    $output = new BufferedOutput;
    return [$console->run($input, $output), $output->fetch()];
}

test('import status defaults to a saved read without preparing or dispatching', function (): void {
    [$exit, $output] = runImportBoundaryCommand($this->importCommandApp, $this->importCommand, ['request-id' => $this->importCommandId]);
    expect($exit)->toBe(0)->and(json_decode(trim($output), true))->toBe(['status' => 'not_requested'])
        ->and($this->importCommandWorkflow->calls)->toBe([['status', [$this->importCommandId], true]])
        ->and($this->importCommand->dispatchAttempts)->toBe([]);
});

test('disabled status and recovery do not resolve a workflow or claim a fabricated pending count', function (): void {
    config(['sms-exports.debtplete_import_enabled' => false]);
    $this->importCommand->factoryFails = $this->importRecovery->factoryFails = true;
    [$exit, $output] = runImportBoundaryCommand($this->importCommandApp, $this->importCommand, ['request-id' => $this->importCommandId]);
    [$recoveryExit, $recoveryOutput] = runImportBoundaryCommand($this->importCommandApp, $this->importRecovery, ['--queue' => true, '--limit' => 'bad']);
    expect($exit)->toBe(0)->and(json_decode(trim($output), true))->toBe(['status' => 'disabled'])
        ->and($recoveryExit)->toBe(0)->and(json_decode(trim($recoveryOutput), true))->toBe(['status' => 'disabled', 'dispatch_requests' => 0])
        ->and($this->importCommand->factories)->toBe(0)->and($this->importRecovery->factories)->toBe(0)
        ->and($this->importCommandWorkflow->calls)->toBe([]);
});

test('mutating actions require confirmation before even resolving workflow', function (string $action): void {
    $this->importCommand->factoryFails = true;
    [$exit, $output] = runImportBoundaryCommand($this->importCommandApp, $this->importCommand,
        ['request-id' => $this->importCommandId, '--action' => $action, '--actor' => 'operator@example.invalid']);
    expect($exit)->toBe(1)->and($output)->toContain('explicit import confirmation')
        ->and($this->importCommand->factories)->toBe(0)->and($this->importCommandWorkflow->calls)->toBe([])
        ->and($this->importCommand->dispatchAttempts)->toBe([]);
})->with(['prepare', 'step', 'queue']);

test('explicit command routes only the requested action and same existing identity', function (string $action): void {
    [$exit, $output] = runImportBoundaryCommand($this->importCommandApp, $this->importCommand,
        ['request-id' => $this->importCommandId, '--action' => $action, '--actor' => 'operator@example.invalid', '--confirm-import' => true]);
    $method = $action === 'step' ? 'step' : 'prepare';
    $args = $action === 'step' ? [$this->importCommandId] : [$this->importCommandId, 'operator@example.invalid'];
    expect($exit)->toBe(0)->and(json_decode(trim($output), true))->toBe($this->importCommandWorkflow->result)
        ->and($this->importCommandWorkflow->calls)->toBe([[$method, $args, true]])
        ->and($this->importCommand->dispatchAttempts)->toBe($action === 'queue' ? [$this->importCommandId] : []);
})->with(['prepare', 'step', 'queue']);

test('queue command does not enqueue held or completed handoffs', function (string $status): void {
    $this->importCommandWorkflow->result = ['status' => $status];
    [$exit] = runImportBoundaryCommand($this->importCommandApp, $this->importCommand,
        ['request-id' => $this->importCommandId, '--action' => 'queue', '--confirm-import' => true]);
    expect($exit)->toBe(0)->and($this->importCommand->dispatchAttempts)->toBe([]);
})->with(['held', 'completed']);

test('invalid command identity or action cannot reach factory or leak supplied data', function (string $id, string $action): void {
    [$exit, $output] = runImportBoundaryCommand($this->importCommandApp, $this->importCommand,
        ['request-id' => $id, '--action' => $action, '--confirm-import' => true]);
    expect($exit)->toBe(1)->and($output)->not->toContain('RAW-SENTINEL')
        ->and($this->importCommand->factories)->toBe(0)->and($this->importCommand->dispatchAttempts)->toBe([]);
})->with([
    ['../RAW-SENTINEL', 'queue'], ['AAAAAAAA-2222-4333-8444-555555555555', 'status'],
    ['11111111-2222-4333-8444-555555555555', 'RAW-SENTINEL'],
]);

test('command failures remain generic and do not invent successful dispatch after preparation', function (string $failure): void {
    $this->importCommand->factoryFails = $failure === 'factory';
    if ($failure === 'workflow') $this->importCommandWorkflow->failure = new RuntimeException('RAW SQL password SENTINEL');
    if ($failure === 'queue') $this->importCommand->failDispatchAt = 1;
    [$exit, $output] = runImportBoundaryCommand($this->importCommandApp, $this->importCommand,
        ['request-id' => $this->importCommandId, '--action' => 'queue', '--confirm-import' => true]);
    expect($exit)->toBe(1)->and($output)->toContain('outcome is unconfirmed')
        ->and($output)->not->toContain('RAW', 'password', 'credential', 'SENTINEL', 'requested');
    if ($failure === 'queue') expect($this->importCommandWorkflow->calls[0][0])->toBe('prepare')
        ->and($this->importCommand->dispatchAttempts)->toBe([$this->importCommandId]);
})->with(['factory', 'workflow', 'queue']);

test('recovery defaults to bounded existing-ledger preview without queue dispatch', function (): void {
    $this->importCommandWorkflow->ids = [$this->importCommandId];
    [$exit, $output] = runImportBoundaryCommand($this->importCommandApp, $this->importRecovery);
    expect($exit)->toBe(0)->and(json_decode(trim($output), true))->toBe(['status' => 'preview', 'pending_handoffs' => 1, 'dispatch_requests' => 0])
        ->and($this->importCommandWorkflow->calls)->toBe([['pending', [10], true]])
        ->and($this->importRecovery->dispatchAttempts)->toBe([])->and($output)->not->toContain($this->importCommandId);
});

test('explicit recovery counts only successful dispatch requests and preserves partial outage evidence', function (): void {
    $ids = [$this->importCommandId, '22222222-2222-4333-8444-555555555555', '33333333-2222-4333-8444-555555555555'];
    $this->importCommandWorkflow->ids = $ids;
    $this->importRecovery->failDispatchAt = 3;
    [$exit, $output] = runImportBoundaryCommand($this->importCommandApp, $this->importRecovery, ['--queue' => true, '--limit' => '3']);
    expect($exit)->toBe(1)->and(json_decode(trim($output), true))->toBe(['status' => 'pending_recovery', 'dispatch_requests' => 2])
        ->and($this->importRecovery->dispatchAttempts)->toBe($ids)->and($this->importCommandWorkflow->calls)->toBe([['pending', [3], true]])
        ->and($output)->not->toContain('RAW', 'SENTINEL', 'credential', 'password');
});

test('confirmed recovery requests only returned same UUIDs and reports requests rather than delivery', function (): void {
    $this->importCommandWorkflow->ids = [$this->importCommandId];
    [$exit, $output] = runImportBoundaryCommand($this->importCommandApp, $this->importRecovery, ['--queue' => true, '--limit' => '100']);
    expect($exit)->toBe(0)->and(json_decode(trim($output), true))->toBe(['status' => 'requested', 'pending_handoffs' => 1, 'dispatch_requests' => 1])
        ->and($this->importRecovery->dispatchAttempts)->toBe([$this->importCommandId])
        ->and($this->importCommandWorkflow->calls)->toBe([['pending', [100], true]]);
});

test('recovery rejects unbounded or malformed limits before factory resolution', function (string $limit): void {
    [$exit, $output] = runImportBoundaryCommand($this->importCommandApp, $this->importRecovery, ['--limit' => $limit]);
    expect($exit)->toBe(1)->and(json_decode(trim($output), true))->toBe(['status' => 'pending_recovery', 'dispatch_requests' => 0])
        ->and($this->importRecovery->factories)->toBe(0)->and($this->importRecovery->dispatchAttempts)->toBe([]);
})->with(['0', '101', '1.0', '-1', ' 1', '001', '1000']);

test('recovery never dispatches an unverified returned UUID and keeps honest prior request count', function (): void {
    $this->importCommandWorkflow->ids = [$this->importCommandId, '../RAW-SENTINEL'];
    [$exit, $output] = runImportBoundaryCommand($this->importCommandApp, $this->importRecovery, ['--queue' => true]);
    expect($exit)->toBe(1)->and(json_decode(trim($output), true))->toBe(['status' => 'pending_recovery', 'dispatch_requests' => 1])
        ->and($this->importRecovery->dispatchAttempts)->toBe([$this->importCommandId])->and($output)->not->toContain('RAW-SENTINEL');
});

test('recovery queue readiness failure occurs before saved workflow read', function (): void {
    config(['queue.default' => 'sync']);
    [$exit, $output] = runImportBoundaryCommand($this->importCommandApp, $this->importRecovery, ['--queue' => true]);
    expect($exit)->toBe(1)->and(json_decode(trim($output), true))->toBe(['status' => 'pending_recovery', 'dispatch_requests' => 0])
        ->and($this->importRecovery->factories)->toBe(0)->and($this->importRecovery->dispatchAttempts)->toBe([]);
});

test('well formed foreign UUID is delegated to the workflow and refusal cannot request a dispatch', function (): void {
    // The fake models an ownership refusal, not an ownership implementation.
    $foreign = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';
    $this->importCommandWorkflow->failure = new RuntimeException('RAW foreign owner credential SENTINEL');
    [$exit, $output] = runImportBoundaryCommand($this->importCommandApp, $this->importCommand,
        ['request-id' => $foreign, '--action' => 'queue', '--confirm-import' => true]);
    expect($exit)->toBe(1)->and($this->importCommandWorkflow->calls)->toBe([['prepare', [$foreign, ''], true]])
        ->and($this->importCommand->dispatchAttempts)->toBe([])
        ->and($output)->not->toContain('RAW', 'foreign owner', 'credential', 'SENTINEL', $foreign);
});

test('recovery factory and saved ledger failures remain private and request no new work', function (bool $factory): void {
    $this->importRecovery->factoryFails = $factory;
    $this->importCommandWorkflow->failure = new RuntimeException('RAW SQL credential SENTINEL');
    [$exit, $output] = runImportBoundaryCommand($this->importCommandApp, $this->importRecovery, ['--queue' => true]);
    expect($exit)->toBe(1)->and(json_decode(trim($output), true))->toBe(['status' => 'pending_recovery', 'dispatch_requests' => 0])
        ->and($this->importRecovery->dispatchAttempts)->toBe([])
        ->and($output)->not->toContain('RAW', 'SQL', 'credential', 'SENTINEL');
})->with([true, false]);
