<?php

declare(strict_types=1);

use Cmd\Reports\Services\SmsDebtPleteImportPlan;
use Cmd\Reports\Services\SmsDebtPleteImportReceipt;
use Cmd\Reports\Services\SmsRunOptions;

function smsSenderReceiptPlan(): SmsDebtPleteImportPlan
{
    $id = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    $options = SmsRunOptions::normalize(3, 2, ['T1'], SmsRunOptions::WINDOWED_TIER_SELECTION_POLICY,
        SmsRunOptions::PLANNED_DATE_VERSION, '2020-01-13', SmsRunOptions::APPROVAL_FILENAME_VERSION);
    $stem = 'SMS Export - 2020-01-13 - SMS0001-SMS0003';
    $manifest = ['version' => 1, 'request_id' => $id, 'options_sha256' => hash('sha256', json_encode($options, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
        'rows' => 3, 'rows_per_file' => 2, 'csv_format' => 'sms-four-column-v1',
        'download' => ['name' => $stem.'.zip', 'format' => 'zip', 'bytes' => 300, 'sha256' => str_repeat('a', 64)],
        'parts' => [
            ['part' => 1, 'name' => $stem.' - Part 1 of 2.csv', 'rows' => 2, 'bytes' => 100, 'sha256' => str_repeat('b', 64)],
            ['part' => 2, 'name' => $stem.' - Part 2 of 2.csv', 'rows' => 1, 'bytes' => 100, 'sha256' => str_repeat('c', 64)],
        ]];
    return SmsDebtPleteImportPlan::fromApproved((object) ['Request_ID' => $id, 'Status' => 'approved', 'Selection_Policy' => 'prospect-record-v1',
        'Target' => 3, 'Selected' => 3, 'Approved_By' => 'fixture@example.invalid', 'Approved_At' => '2020-01-06 00:00:00',
        'Run_Options_JSON' => json_encode($options, JSON_THROW_ON_ERROR), 'Artifact_Manifest_JSON' => json_encode($manifest, JSON_THROW_ON_ERROR),
        'File_Path' => '/synthetic/final.zip', 'File_Bytes' => 300, 'File_Sha256' => str_repeat('a', 64)],
        ['protocol' => 'debtplete-sms-import-v1', 'origin' => 'https://tenant.example.invalid',
            'tenant_id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'client_id' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc']);
}

function smsSenderReceipt(SmsDebtPleteImportPlan $plan, string $state = 'queued'): array
{
    $parts = [];
    foreach ($plan->parts() as $part) {
        $parts[] = ['part' => $part['part'], 'state' => $state === 'completed' ? 'completed' : 'uploaded',
            'rows_expected' => $part['rows'], 'rows_committed' => $state === 'completed' ? $part['rows'] : 0,
            'accepted' => true, 'bytes' => $part['bytes'], 'sha256' => $part['sha256']];
    }
    return ['request_id' => $plan->requestId(), 'plan_sha256' => $plan->sha256(), 'state' => $state,
        'status' => $state, 'processed' => $state === 'completed' ? $plan->rows() : 0, 'total' => $plan->rows(), 'parts' => $parts, 'error_code' => null];
}

test('matches the fixed receiver canonical JSON seal golden vector', function (): void {
    // Independently sealed from the receiver's tenant/client/plan insertion order.
    $plan = smsSenderReceiptPlan();
    expect($plan->sha256())->toBe('799b0404081b16ff699a0a94935a9191cec98a9cd811d4b2a0d084755a636857')
        ->and($plan->payload()['manifest']['options_sha256'])->toBe('dde2aba3f7d89c2963dd11fe4b84e54d0293e9e393a2e0641a2df37712eb504f');
});

test('accepts exact safe aggregates and completed same-plan replay metadata', function (): void {
    $plan = smsSenderReceiptPlan(); $receipt = smsSenderReceipt($plan);
    expect(SmsDebtPleteImportReceipt::validate($receipt, $plan))->toBe($receipt);
    $complete = smsSenderReceipt($plan, 'completed') + ['updated' => 0, 'already_completed' => true, 'retry_pending' => false, 'processing_queue' => 'pending_recovery'];
    expect(SmsDebtPleteImportReceipt::validate($complete, $plan))->toBe($complete);
    SmsDebtPleteImportReceipt::assertNotRegressed($receipt, $complete);
    SmsDebtPleteImportReceipt::assertNotRegressed($complete, [...$complete, 'processing_queue' => 'pending_recovery']);
});

test('rejects unexpected data identities counts and flags without leaking remote values', function (string $path, mixed $value): void {
    $plan = smsSenderReceiptPlan(); $receipt = smsSenderReceipt($plan);
    $segments = explode('.', $path); $target = &$receipt;
    foreach (array_slice($segments, 0, -1) as $segment) { $target = &$target[$segment]; }
    $target[end($segments)] = $value;
    try { SmsDebtPleteImportReceipt::validate($receipt, $plan); test()->fail('Expected rejection.'); }
    catch (InvalidArgumentException $error) {
        expect($error->getMessage())->toBe('The SMS import receipt could not be verified.')->and($error->getPrevious())->toBeNull();
    }
})->with([
    ['request_id', 'PRIVATE'], ['plan_sha256', str_repeat('0', 64)], ['total', 4], ['total', 3.0], ['processed', -1], ['processed', 1], ['processed', 0.0],
    ['state', 'other'], ['status', 'completed'], ['error_code', 'PRIVATE'], ['response', ['phone' => '0555010101']],
    ['parts.0.part', 2], ['parts.0.bytes', 101], ['parts.0.sha256', str_repeat('d', 64)], ['parts.0.rows_expected', 3], ['parts.0.rows_committed', 3],
    ['parts.0.accepted', 'true'], ['parts.0.accepted', false], ['parts.0.state', 'importing'], ['parts.0.file_path', '/PRIVATE'],
    ['updated', -1], ['updated', 1], ['already_completed', true], ['retry_pending', 1], ['processing_queue', 'PRIVATE'],
]);

test('requires all completed parts and reconciles partial importing commitments', function (): void {
    $plan = smsSenderReceiptPlan(); $receipt = smsSenderReceipt($plan, 'completed');
    $receipt['parts'][0]['rows_committed'] = 1; $receipt['processed'] = 2;
    expect(fn () => SmsDebtPleteImportReceipt::validate($receipt, $plan))->toThrow(InvalidArgumentException::class);
    $receipt['state'] = $receipt['status'] = 'importing'; $receipt['parts'][0]['state'] = 'importing';
    expect(SmsDebtPleteImportReceipt::validate($receipt, $plan)['processed'])->toBe(2);
});

test('accepts an explicit held aggregate but never arbitrary error text', function (): void {
    $plan = smsSenderReceiptPlan(); $receipt = smsSenderReceipt($plan);
    $receipt['state'] = 'held'; $receipt['status'] = 'failed'; $receipt['error_code'] = 'source_unconfirmed';
    $receipt['parts'][0]['state'] = 'held'; $receipt['parts'][0]['accepted'] = false;
    expect(SmsDebtPleteImportReceipt::validate($receipt, $plan))->toBe($receipt);
    $receipt['error_code'] = 'PRIVATE-PHONE-0555010101';
    expect(fn () => SmsDebtPleteImportReceipt::validate($receipt, $plan))->toThrow(InvalidArgumentException::class);
});

test('accepts missing uploads but refuses a validated-file regression even without committed rows', function (): void {
    $plan = smsSenderReceiptPlan(); $before = smsSenderReceipt($plan);
    $before['parts'][0]['state'] = 'awaiting_upload'; $before['parts'][0]['accepted'] = false;
    expect(SmsDebtPleteImportReceipt::validate($before, $plan))->toBe($before);
    $validated = smsSenderReceipt($plan); $validated['parts'][0]['state'] = 'validated';
    SmsDebtPleteImportReceipt::assertNotRegressed($before, $validated);
    expect(fn () => SmsDebtPleteImportReceipt::assertNotRegressed($validated, smsSenderReceipt($plan)))->toThrow(InvalidArgumentException::class);
});

test('normalizes safe key order so a completed replay stays immutable across JSON object ordering', function (): void {
    $plan = smsSenderReceiptPlan(); $receipt = smsSenderReceipt($plan, 'completed');
    $reordered = array_reverse($receipt, true);
    foreach ($reordered['parts'] as &$part) { $part = array_reverse($part, true); }
    unset($part);
    $before = SmsDebtPleteImportReceipt::validate($receipt, $plan);
    $after = SmsDebtPleteImportReceipt::validate($reordered, $plan);
    expect($after)->toBe($before);
    SmsDebtPleteImportReceipt::assertNotRegressed($before, $after);
});

test('rejects regressing commitments accepted files state and completed receipts', function (string $change): void {
    $plan = smsSenderReceiptPlan(); $previous = smsSenderReceipt($plan, 'completed'); $current = $previous;
    match ($change) {
        'processed' => $current['processed'] = 2,
        'part' => $current['parts'][0]['rows_committed'] = 1,
        'accepted' => $current['parts'][0]['accepted'] = false,
        'state' => $current['state'] = 'importing',
        'identity' => $current['plan_sha256'] = str_repeat('0', 64),
        'error' => $current['error_code'] = 'receipt_invalid',
    };
    expect(fn () => SmsDebtPleteImportReceipt::assertNotRegressed($previous, $current))->toThrow(InvalidArgumentException::class);
})->with(['processed', 'part', 'accepted', 'state', 'identity', 'error']);
