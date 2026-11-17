<?php

declare(strict_types=1);

use Cmd\Reports\Services\SmsRunAutomaticHandoffProjection;
use Cmd\Reports\Services\SmsRunAutomaticHandoffWorkflow;
use Cmd\Reports\Services\SmsRunOptions;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;

/** Runs the projection with the three switches set and a stand-in for the saved automatic handoff state. */
function smsAutomaticProjection(array $switches, array|Throwable|null $state, array $view, ?array $handoff, ?int &$reads = null): ?array
{
    $previous = Container::getInstance();
    $container = new Container;
    $container->instance('config', new Repository(['sms-exports' => $switches + [
        'handoff_automatic' => true, 'file_publication_enabled' => true, 'email_handoff_enabled' => true]]));
    $reads = 0;
    $container->bind(SmsRunAutomaticHandoffWorkflow::class, function () use ($state, &$reads): object {
        return new class($state, $reads) {
            public function __construct(private array|Throwable|null $state, private int &$reads) {}
            public function status(string $id): ?array
            {
                $this->reads++;
                if ($id !== 'dd5bfa17-c1fa-4a9d-84f0-c308f4db615e') throw new RuntimeException('The id must be passed in lower case.');
                if ($this->state instanceof Throwable) throw $this->state;

                return $this->state;
            }
        };
    });
    Container::setInstance($container);
    try {
        return SmsRunAutomaticHandoffProjection::forRun($view, $handoff);
    } finally {
        Container::setInstance($previous);
    }
}

function smsAutomaticView(string $status = 'approved'): array
{
    return ['request_id' => 'DD5BFA17-C1FA-4A9D-84F0-C308F4DB615E', 'status' => $status,
        'options' => ['filename_version' => SmsRunOptions::APPROVAL_FILENAME_VERSION]];
}

function smsAutomaticHandoff(): array
{
    return ['file_publication' => ['status' => 'awaiting_publish', 'note' => 'Explicit publication requires the reviewed account.'],
        'email' => ['status' => 'awaiting_links', 'note' => 'Approval alone does not send an email.'],
        'debtplete_import' => ['status' => 'not_configured']];
}

test('a handoff that is running by itself is reported as in progress, with notes that say so', function (string $state): void {
    $projected = smsAutomaticProjection([], ['status' => $state], smsAutomaticView(), smsAutomaticHandoff(), $reads);
    expect($projected['automatic']['status'])->toBe('in_progress')
        ->and($projected['file_publication']['status'])->toBe('awaiting_publish')
        ->and($projected['file_publication']['note'])->toContain('published automatically')->not->toContain('Explicit publication')
        ->and($projected['email']['status'])->toBe('awaiting_links')
        ->and($projected['email']['note'])->toContain('sent automatically')->not->toContain('Approval alone')
        ->and($projected['debtplete_import'])->toBe(['status' => 'not_configured'])
        ->and($reads)->toBe(1);
})->with(['pending', 'working']);

test('progress that is already saved keeps its own status and note while the handoff goes on', function (): void {
    $handoff = ['file_publication' => ['status' => 'published', 'note' => 'Verified.', 'files_done' => 1, 'files_total' => 1, 'rows_done' => 3, 'rows_total' => 3],
        'email' => ['status' => 'ready', 'note' => 'Prepared.']];
    $projected = smsAutomaticProjection([], ['status' => 'pending'], smsAutomaticView(), $handoff);
    expect($projected['automatic']['status'])->toBe('in_progress')
        ->and($projected['file_publication'])->toBe($handoff['file_publication'])
        ->and($projected['email'])->toBe($handoff['email']);
});

test('a finished handoff is reported as completed, without a read when both parts already say so', function (): void {
    $done = ['file_publication' => ['status' => 'published'], 'email' => ['status' => 'accepted']];
    $projected = smsAutomaticProjection([], new RuntimeException('Must not be read.'), smsAutomaticView(), $done, $reads);
    expect($projected['automatic'])->toBe(['status' => 'completed'])->and($reads)->toBe(0);
    $projected = smsAutomaticProjection([], ['status' => 'completed'], smsAutomaticView(), smsAutomaticHandoff(), $reads);
    expect($projected['automatic'])->toBe(['status' => 'completed'])->and($reads)->toBe(1)
        ->and($projected['file_publication']['note'])->toContain('Explicit publication');
});

test('a stopped handoff is reported as held and the page is told nothing is retried', function (): void {
    $projected = smsAutomaticProjection([], ['status' => 'held', 'error_code' => 'private-code'], smsAutomaticView(), smsAutomaticHandoff());
    expect($projected['automatic']['status'])->toBe('held')->and($projected['automatic']['note'])->toContain('needs to be checked')
        ->and(json_encode($projected, JSON_THROW_ON_ERROR))->not->toContain('private-code')
        ->and($projected['file_publication']['note'])->toContain('Explicit publication');
});

test('nothing is added when there is no saved automatic handoff, the state is unknown or it cannot be read', function (array|Throwable|null $state): void {
    $handoff = smsAutomaticHandoff();
    expect(smsAutomaticProjection([], $state, smsAutomaticView(), $handoff))->toBe($handoff);
})->with([
    'approved before the switch was on' => [null],
    'an unknown state' => [['status' => 'delivered']],
    'no state' => [[]],
    'the saved state cannot be verified' => [new RuntimeException('The automatic SMS handoff is unconfirmed.')],
]);

test('nothing is read or added unless the batch is approved and all three switches are on', function (array $switches, array $view, ?array $handoff): void {
    $projected = smsAutomaticProjection($switches, new RuntimeException('Must not be read.'), $view, $handoff, $reads);
    expect($projected)->toBe($handoff)->and($reads)->toBe(0);
})->with([
    'automatic off' => [['handoff_automatic' => false], smsAutomaticView(), smsAutomaticHandoff()],
    'publishing off' => [['file_publication_enabled' => false], smsAutomaticView(), smsAutomaticHandoff()],
    'email off' => [['email_handoff_enabled' => false], smsAutomaticView(), smsAutomaticHandoff()],
    'an open batch' => [[], smsAutomaticView('file_ready'), smsAutomaticHandoff()],
    'a sent batch' => [[], smsAutomaticView('sent'), smsAutomaticHandoff()],
    'an older file-name version' => [[], ['request_id' => 'dd5bfa17-c1fa-4a9d-84f0-c308f4db615e', 'status' => 'approved', 'options' => ['filename_version' => 'older']], smsAutomaticHandoff()],
    'no handoff view' => [[], smsAutomaticView(), null],
]);
