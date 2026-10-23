<?php

declare(strict_types=1);

use Cmd\Reports\Console\Commands\SmsRunPublicationCommand;
use Cmd\Reports\Services\SmsRunArtifactPartReader;
use Cmd\Reports\Services\SmsRunEmailWorkflow;
use Cmd\Reports\Services\SmsRunLinkPublisher;
use Cmd\Reports\Services\SmsRunPublicationWorkflow;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

beforeEach(function (): void {
    $this->publicationCommandPrevious = Container::getInstance();
    $container = new class extends Container { public function runningUnitTests(): bool { return true; } };
    $container->instance('config', new Repository(['sms-exports' => ['file_publication_enabled' => false]]));
    Container::setInstance($container);
    $publisher = new class implements SmsRunLinkPublisher {
        public function identity(): string { throw new RuntimeException('Unexpected provider.'); }
        public function policy(string $sendDate): array { throw new RuntimeException('Unexpected provider.'); }
        public function publish(string $id, array $part, mixed $stream, array $policy): array { throw new RuntimeException('Unexpected provider.'); }
        public function verify(string $id, array $part, array $receipt, array $policy): void { throw new RuntimeException('Unexpected provider.'); }
    };
    $container->instance(SmsRunPublicationWorkflow::class, new SmsRunPublicationWorkflow($publisher, new SmsRunArtifactPartReader));
    $this->publicationCommandContainer = $container;
});

afterEach(function (): void { Mockery::close(); Container::setInstance($this->publicationCommandPrevious); });

function smsPublicationCommandRun(Container $container, array $options): array
{
    $command = new SmsRunPublicationCommand;
    $command->setLaravel($container);
    $console = new Application;
    $console->setAutoExit(false);
    if (method_exists($console, 'addCommand')) $console->addCommand($command);
    else $console->add($command);
    $output = new BufferedOutput;
    $exit = $console->run(new ArrayInput(['command' => 'sms:run-publish', 'request-id' => '11111111-2222-4333-8444-555555555555'] + $options), $output);
    return [$exit, $output->fetch()];
}

test('publication command defaults to read-only status without provider or database access', function (): void {
    [$exit, $output] = smsPublicationCommandRun($this->publicationCommandContainer, []);
    expect($exit)->toBe(0)->and($output)->toContain('No tracked SMS publication', 'No email was sent')
        ->and($output)->not->toContain('Unexpected provider', 'https://', 'Bearer');
});

test('publication command requires explicit valid operator and publishing confirmation', function (array $options): void {
    [$exit, $output] = smsPublicationCommandRun($this->publicationCommandContainer, $options);
    expect($exit)->toBe(1)->and($output)->toContain('did not complete', 'No email send was requested')
        ->and($output)->not->toContain('Unexpected provider', 'secret@example.invalid', 'Bearer');
})->with([
    [['--action' => 'publish']], [['--action' => 'queue']], [['--action' => 'prepare-email']],
    [['--action' => 'publish', '--actor' => 'secret@example.invalid']],
    [['--action' => 'publish', '--actor' => 'invalid', '--confirm-publish' => true]],
    [['--action' => 'prepare-email', '--actor' => 'secret@example.invalid', '--confirm-publish' => true]],
]);

test('publication command rejects send and import as unsupported actions', function (): void {
    [$exit, $output] = smsPublicationCommandRun($this->publicationCommandContainer, ['--action' => 'send']);
    expect($exit)->toBe(1)->and($output)->toContain('action status, publish, queue or prepare-email');
});

test('publication command retries separate email preparation without invoking send or exposing links', function (): void {
    $id = '11111111-2222-4333-8444-555555555555';
    $actor = 'operator@example.invalid';
    $links = [['name' => 'Fixture.csv', 'url' => 'https://secret.invalid/private-capability', 'sha256' => str_repeat('a', 64), 'bytes' => 10, 'rows' => 1]];
    $publications = Mockery::mock(SmsRunPublicationWorkflow::class);
    $publications->shouldReceive('publish')->with($id, $actor)->twice()->andReturn(['status' => 'published', 'files_done' => 1, 'files_total' => 1]);
    $publications->shouldReceive('links')->with($id)->twice()->andReturn($links);
    $emails = Mockery::mock(SmsRunEmailWorkflow::class);
    $emails->shouldReceive('prepare')->with($id, $links, $actor)->once()->ordered()->andThrow(new RuntimeException('private-capability raw-provider-secret'));
    $emails->shouldReceive('prepare')->with($id, $links, $actor)->once()->ordered()->andReturn(['status' => 'ready', 'payload_sha256' => str_repeat('b', 64)]);
    $emails->shouldNotReceive('send');
    $this->publicationCommandContainer->instance(SmsRunPublicationWorkflow::class, $publications);
    $this->publicationCommandContainer->instance(SmsRunEmailWorkflow::class, $emails);
    $options = ['--action' => 'prepare-email', '--actor' => $actor, '--confirm-publish' => true];
    [$failedExit, $failedOutput] = smsPublicationCommandRun($this->publicationCommandContainer, $options);
    [$readyExit, $readyOutput] = smsPublicationCommandRun($this->publicationCommandContainer, $options);
    expect($failedExit)->toBe(1)->and($failedOutput)->not->toContain('private-capability', 'raw-provider-secret')
        ->and($readyExit)->toBe(0)->and($readyOutput)->toContain('SMS email prepared: ready', str_repeat('b', 64), 'No email was sent')
        ->and($readyOutput)->not->toContain('secret.invalid', 'private-capability');
});
