<?php

declare(strict_types=1);

use Cmd\Reports\Services\SmsDebtPleteImportPrivacy;
use Cmd\Reports\Services\SmsRunDebtPleteImportProjection;
use Cmd\Reports\Services\SmsRunDebtPleteImportWorkflow;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;

class SmsImportProjectionFixture extends SmsRunDebtPleteImportWorkflow
{
    public int $calls = 0;
    public bool $throw = false;
    public ?array $result = null;
    public function __construct() {}
    public function status(string $id): ?array
    {
        ++$this->calls;
        expect(SmsDebtPleteImportPrivacy::active())->toBeTrue();
        if ($this->throw) throw new RuntimeException('PRIVATE-DATA-/path/0555010101');
        return $this->result;
    }
    public function step(string $id): array { throw new RuntimeException('A read must never start work.'); }
}

function smsImportProjectionView(): array
{
    return ['request_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'status' => 'approved', 'selected' => 3,
        'options' => ['filename_version' => 'sms-approval-drop-range-v1'], 'file' => ['parts' => 2]];
}
function smsImportProjectionStatus(): array
{
    return ['request_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'status' => 'pending', 'processed' => 1, 'total' => 3,
        'files_accepted' => 2, 'files_total' => 2, 'remote_status' => 'importing', 'error_code' => null, 'retry_pending' => true];
}

beforeEach(function (): void {
    $this->priorImportProjectionContainer = Container::getInstance();
    $app = new Container; Container::setInstance($app);
    $app->instance('config', new Repository(['sms-exports' => ['debtplete_import_enabled' => true]]));
    $this->fixture = new SmsImportProjectionFixture;
    $app->instance(SmsRunDebtPleteImportWorkflow::class, $this->fixture);
    $this->preview = ['email' => ['status' => 'not_configured'], 'debtplete_import' => ['status' => 'not_configured', 'processed' => null, 'total' => 3]];
});
afterEach(function (): void { Container::setInstance($this->priorImportProjectionContainer); });

test('default off and unapproved historical views never resolve an import reader', function () {
    config(['sms-exports.debtplete_import_enabled' => false]);
    expect(SmsRunDebtPleteImportProjection::forRun(smsImportProjectionView(), $this->preview))->toBe($this->preview);
    config(['sms-exports.debtplete_import_enabled' => true]);
    $view = smsImportProjectionView(); $view['status'] = 'external_sent';
    expect(SmsRunDebtPleteImportProjection::forRun($view, $this->preview))->toBe($this->preview)
        ->and(SmsRunDebtPleteImportProjection::forRun(smsImportProjectionView(), null))->toBeNull()
        ->and($this->fixture->calls)->toBe(0);
});

test('missing saved receipt does not invent manual history or zero imported counts', function () {
    $result = SmsRunDebtPleteImportProjection::forRun(smsImportProjectionView(), $this->preview);
    expect($result['debtplete_import']['status'])->toBe('not_requested')
        ->and($result['debtplete_import']['processed'])->toBeNull()
        ->and($result['debtplete_import']['note'])->toContain('Past manual imports are not inferred.')
        ->and($this->fixture->calls)->toBe(1)->and(SmsDebtPleteImportPrivacy::active())->toBeFalse();
});

test('saved progress is scoped and projected without private metadata or initiating work', function () {
    $this->fixture->result = smsImportProjectionStatus() + ['private' => '/PRIVATE/0555010101'];
    $result = SmsRunDebtPleteImportProjection::forRun(smsImportProjectionView(), $this->preview);
    expect($result['debtplete_import']['status'])->toBe('importing')
        ->and($result['debtplete_import']['processed'])->toBe(1)
        ->and($result['debtplete_import']['files_accepted'])->toBe(2)
        ->and(json_encode($result))->not->toContain('PRIVATE', '0555010101')
        ->and($result['email'])->toBe($this->preview['email']);
});

test('unconfirmed receiver counts remain unknown and verified completion has a bounded claim', function () {
    $status = smsImportProjectionStatus();
    $status['status'] = 'queued'; $status['remote_status'] = null;
    $status['processed'] = null; $status['files_accepted'] = null;
    expect(SmsRunDebtPleteImportProjection::apply(smsImportProjectionView(), $this->preview, $status)['debtplete_import']['processed'])->toBeNull();
    $status = smsImportProjectionStatus(); $status['status'] = 'completed'; $status['remote_status'] = 'completed';
    $status['processed'] = 3; $status['retry_pending'] = false;
    $result = SmsRunDebtPleteImportProjection::apply(smsImportProjectionView(), $this->preview, $status)['debtplete_import'];
    expect($result['status'])->toBe('completed')->and($result['note'])->toContain('does not confirm SMS delivery');
});

test('corrupt or inconsistent saved progress is never presented as completion', function (string $field, mixed $value) {
    $status = smsImportProjectionStatus(); $status[$field] = $value;
    expect(fn () => SmsRunDebtPleteImportProjection::apply(smsImportProjectionView(), $this->preview, $status))->toThrow(RuntimeException::class);
})->with([
    ['request_id', 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'], ['total', 4], ['total', '3'],
    ['processed', -1], ['processed', 4], ['processed', 1.5], ['processed', null],
    ['files_accepted', 3], ['files_accepted', null], ['files_total', 3],
    ['status', 'provider_delivered'], ['status', 'completed'], ['remote_status', 'delivered'],
    ['remote_status', null], ['retry_pending', 'yes'],
]);

test('failed saved reads expose only static status and unwind privacy', function () {
    $this->fixture->throw = true;
    $result = SmsRunDebtPleteImportProjection::forRun(smsImportProjectionView(), $this->preview);
    expect($result['debtplete_import']['status'])->toBe('unavailable')
        ->and($result['debtplete_import']['processed'])->toBeNull()
        ->and(json_encode($result))->not->toContain('PRIVATE', '0555010101')
        ->and(SmsDebtPleteImportPrivacy::active())->toBeFalse();
});

