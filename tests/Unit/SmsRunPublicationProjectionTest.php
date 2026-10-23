<?php

declare(strict_types=1);

use Cmd\Reports\Services\SmsRunOptions;
use Cmd\Reports\Services\SmsRunPublicationProjection;
use Cmd\Reports\Services\SmsRunPublicationWorkflow;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;

test('disabled or legacy publication views never resolve a publisher or database', function (): void {
    $previous = Container::getInstance();
    $container = new Container;
    $container->instance('config', new Repository(['sms-exports' => ['file_publication_enabled' => false]]));
    $container->bind(SmsRunPublicationWorkflow::class, fn () => throw new RuntimeException('Unexpected publication resolution.'));
    Container::setInstance($container);
    try {
        expect(SmsRunPublicationProjection::forRun([], null))->toBeNull();
        $view = ['status' => 'approved', 'options' => ['filename_version' => SmsRunOptions::APPROVAL_FILENAME_VERSION]];
        expect(SmsRunPublicationProjection::forRun($view, [])['file_publication']['status'])->toBe('not_configured');
        config(['sms-exports.file_publication_enabled' => true]);
        expect(SmsRunPublicationProjection::forRun([], [])['file_publication']['status'])->toBe('not_configured');
        expect(SmsRunPublicationProjection::forRun($view, [])['file_publication']['status'])->toBe('unavailable');
    } finally {
        Container::setInstance($previous);
    }
});

test('publication projection exposes only verified counts independently of email and import', function (): void {
    $view = ['selected' => 3, 'file' => ['parts' => 2]];
    $handoff = ['email' => ['status' => 'ready'], 'debtplete_import' => ['status' => 'not_configured']];
    expect(SmsRunPublicationProjection::apply($view, $handoff, null)['file_publication']['status'])->toBe('awaiting_publish');
    $state = ['status' => 'publishing', 'note' => 'One CSV published.', 'files_done' => 1, 'files_total' => 2,
        'rows_done' => 2, 'rows_total' => 3, 'remote' => ['folder_path' => '/private'], 'url' => 'https://secret.invalid/capability', 'token' => 'secret-token'];
    $projected = SmsRunPublicationProjection::apply($view, $handoff, $state);
    expect($projected['file_publication'])->toBe(array_intersect_key($state, array_flip(['status', 'note', 'files_done', 'files_total', 'rows_done', 'rows_total'])))
        ->and($projected['email'])->toBe($handoff['email'])
        ->and($projected['debtplete_import'])->toBe($handoff['debtplete_import'])
        ->and(json_encode($projected))->not->toContain('secret', 'private', 'capability');
});

test('publication projection rejects false completion and malformed or mismatched counts', function (array $change): void {
    $state = $change + ['status' => 'published', 'files_done' => 2, 'files_total' => 2, 'rows_done' => 3, 'rows_total' => 3];
    SmsRunPublicationProjection::apply(['selected' => 3, 'file' => ['parts' => 2]], [], $state);
})->with([
    [['status' => 'delivered']], [['files_done' => 1]], [['rows_done' => 2]], [['files_total' => 3]],
    [['rows_total' => 4]], [['files_done' => '2']], [['rows_done' => -1]], [['files_done' => 3]],
])->throws(RuntimeException::class);
