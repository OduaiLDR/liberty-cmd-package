<?php

declare(strict_types=1);

use Cmd\Reports\Console\Commands\SmsRunEmailCommand;
use Cmd\Reports\Services\SmsRunEmailGateway;
use Cmd\Reports\Services\SmsRunEmailWorkflow;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

beforeEach(function (): void {
    $this->emailCommandPreviousContainer = Container::getInstance();
    $this->emailCommandContainer = new class extends Container {
        public function runningUnitTests(): bool { return true; }
    };
    $this->emailCommandContainer->instance('config', new Repository(['sms-exports' => ['email_handoff_enabled' => false]]));
    Container::setInstance($this->emailCommandContainer);
    $this->emailCommandGateway = new class implements SmsRunEmailGateway {
        public function configuration(): array { throw new RuntimeException('Unexpected configuration I/O.'); }
        public function assertReady(): void { throw new RuntimeException('Unexpected provider readiness.'); }
        public function send(array $message): bool { throw new RuntimeException('Unexpected provider call.'); }
    };
    $this->emailCommandContainer->instance(SmsRunEmailWorkflow::class, new SmsRunEmailWorkflow($this->emailCommandGateway));
});

afterEach(function (): void {
    Container::setInstance($this->emailCommandPreviousContainer);
});

function smsEmailCommandTestRun(Container $container, array $options): array
{
    $command = new SmsRunEmailCommand;
    $command->setLaravel($container);
    $console = new Application;
    $console->setAutoExit(false);
    if (method_exists($console, 'addCommand')) $console->addCommand($command);
    else $console->add($command);
    $output = new BufferedOutput;
    $exit = $console->run(new ArrayInput(['command' => 'sms:run-email', 'request-id' => '11111111-2222-4333-8444-555555555555'] + $options), $output);
    return [$exit, $output->fetch()];
}

test('SMS email command defaults to read-only status with no provider or database dependency', function (): void {
    [$exit, $output] = smsEmailCommandTestRun($this->emailCommandContainer, []);
    expect($exit)->toBe(0)->and($output)->toContain('No prepared SMS email')
        ->and($output)->not->toContain('queued', 'Bearer', '@lendingtower.com');
});

test('SMS email command rejects unconfirmed sends and invalid preparation without raw errors', function (array $options): void {
    [$exit, $output] = smsEmailCommandTestRun($this->emailCommandContainer, $options);
    expect($exit)->toBe(1)->and($output)->toContain('did not complete', 'Do not blindly resend')
        ->and($output)->not->toContain('private-key', 'Unexpected provider', 'secret@example.com');
})->with([
    [['--action' => 'queue']],
    [['--action' => 'queue', '--payload-sha256' => str_repeat('a', 64)]],
    [['--action' => 'queue', '--payload-sha256' => str_repeat('a', 64), '--confirm-send' => true]],
    [['--action' => 'prepare', '--actor' => 'invalid']],
    [['--action' => 'prepare', '--actor' => 'secret@example.com', '--links-file' => 'https://files.example.com/private-key']],
]);

test('SMS email command rejects unknown actions without mutating outbox state', function (): void {
    [$exit, $output] = smsEmailCommandTestRun($this->emailCommandContainer, ['--action' => 'force-resend']);
    expect($exit)->toBe(1)->and($output)->toContain('action status, prepare or queue');
});

test('SMS email command reads a private bounded local link list without network or output', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'sms-email-links-');
    expect($path)->toBeString();
    try {
        chmod($path, 0600);
        $receipt = [['name' => 'Synthetic part.csv', 'url' => 'https://files.example.com/private-capability',
            'sha256' => str_repeat('a', 64), 'bytes' => 10, 'rows' => 1]];
        file_put_contents($path, json_encode($receipt, JSON_THROW_ON_ERROR));
        $reader = new ReflectionMethod(SmsRunEmailCommand::class, 'links');
        expect($reader->invoke(new SmsRunEmailCommand, $path))->toBe($receipt);
        file_put_contents($path, '{"not":"an ordered list"}');
        expect(fn () => $reader->invoke(new SmsRunEmailCommand, $path))->toThrow(RuntimeException::class);
        expect(fn () => $reader->invoke(new SmsRunEmailCommand, dirname($path)))->toThrow(RuntimeException::class);
        if (PHP_OS_FAMILY !== 'Windows') {
            file_put_contents($path, '[]'); chmod($path, 0644); clearstatcache(true, $path);
            expect(fn () => $reader->invoke(new SmsRunEmailCommand, $path))->toThrow(RuntimeException::class);
        }
    } finally {
        if (is_file($path) && str_starts_with(basename($path), 'sms-email-links-')) unlink($path);
    }
});
