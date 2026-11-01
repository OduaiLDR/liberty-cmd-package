<?php

declare(strict_types=1);

use Cmd\Reports\Services\SmsDebtPleteImportPlan;
use Cmd\Reports\Services\SmsRunArtifactBundle;
use Cmd\Reports\Services\SmsRunOptions;

function smsSenderPlanIdentity(): array
{
    return ['protocol' => 'debtplete-sms-import-v1', 'origin' => 'https://tenant.example.invalid',
        'tenant_id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'client_id' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc'];
}

beforeEach(function (): void {
    $this->planRoot = str_replace('\\', '/', sys_get_temp_dir()).'/sms-sender-plan-'.bin2hex(random_bytes(8));
    mkdir($this->planRoot, 0700);
    $this->planId = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    $directory = $this->planRoot.'/runs/'.$this->planId;
    $options = SmsRunOptions::normalize(3, 2, ['T1'], SmsRunOptions::WINDOWED_TIER_SELECTION_POLICY,
        SmsRunOptions::PLANNED_DATE_VERSION, '2020-01-13', SmsRunOptions::APPROVAL_FILENAME_VERSION);
    $rows = [['Élodie', '60000', '0555010101', '2020-01-13'], ['Second', '60000', '0555010101', '2020-01-13'], ['Third', '60000', '0555010102', '2020-01-13']];
    $pending = (new SmsRunArtifactBundle())->build($directory, 'SMS Export - 2020-01-13 - ID Pending', $rows, 3, $options);
    $bundle = (new SmsRunArtifactBundle('v2'))->publishApproved($directory, $pending['manifest'], $options, 3, 'SMS0001-SMS0003');
    $this->approvedRun = (object) ['Request_ID' => $this->planId, 'Status' => 'approved', 'Selection_Policy' => 'prospect-record-v1',
        'Target' => 3, 'Selected' => 3, 'Approved_By' => 'fixture@example.invalid', 'Approved_At' => '2020-01-06 12:34:56.1234567',
        'Sent_By' => 'fixture@example.invalid', 'Sent_At' => '2020-01-06 12:34:56.1234567',
        'Run_Options_JSON' => json_encode($options, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        'Artifact_Manifest_JSON' => json_encode($bundle['manifest'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        'File_Path' => $bundle['path'], 'File_Bytes' => $bundle['bytes'], 'File_Sha256' => $bundle['sha256'], 'File_Cleanup_Path' => null];
});

afterEach(function (): void {
    $root = realpath($this->planRoot);
    $temporary = str_replace('\\', '/', realpath(sys_get_temp_dir()));
    if ($root === false || is_link($this->planRoot)
        || !str_starts_with(str_replace('\\', '/', $root), $temporary.'/sms-sender-plan-')) {
        throw new RuntimeException('Refusing to clean an unowned sender fixture.');
    }
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) {
        $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($root);
});

test('seals real approved exporter metadata without changing source bytes or historical dates', function (): void {
    $before = serialize($this->approvedRun); $hash = hash_file('sha256', $this->approvedRun->File_Path);
    $plan = SmsDebtPleteImportPlan::fromApproved($this->approvedRun, smsSenderPlanIdentity());
    $payload = $plan->payload();
    expect($payload['approval'])->toBe(['state' => 'approved', 'at' => '2020-01-06T12:34:56.1234567Z'])
        ->and($plan->options()['send_date'])->toBe('2020-01-13')->and($plan->rows())->toBe(3)->and($plan->parts())->toHaveCount(2)
        ->and($plan->requestId())->toBe($this->planId)->and(serialize($this->approvedRun))->toBe($before)
        ->and(hash_file('sha256', $this->approvedRun->File_Path))->toBe($hash)
        ->and($plan->json())->toBe(json_encode(['tenant_id' => smsSenderPlanIdentity()['tenant_id'], 'client_id' => smsSenderPlanIdentity()['client_id'],
            'plan' => ['version' => 1, 'request_id' => $this->planId, 'approval' => $payload['approval'], 'options' => $payload['options'], 'manifest' => $payload['manifest']]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))
        ->and($plan->sha256())->toBe(hash('sha256', $plan->json()))
        ->and($plan->json())->not->toContain('Élodie', '0555010101', 'fixture@example.invalid', $this->planRoot);
    $values = [];
    foreach (['Request_ID', 'Status', 'Selection_Policy', 'Target', 'Selected', 'Approved_By', 'Approved_At', 'Sent_By', 'Sent_At',
        'Run_Options_JSON', 'Artifact_Manifest_JSON', 'File_Path', 'File_Bytes', 'File_Sha256', 'File_Cleanup_Path'] as $field) { $values[$field] = $this->approvedRun->$field ?? null; }
    expect($plan->fingerprint())->toBe(hash('sha256', json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));
});

test('preserves every supported SQL UTC fractional precision', function (string $fraction): void {
    $this->approvedRun->Approved_At = '2020-01-06 12:34:56'.$fraction;
    expect(SmsDebtPleteImportPlan::fromApproved($this->approvedRun, smsSenderPlanIdentity())->payload()['approval']['at'])
        ->toBe('2020-01-06T12:34:56'.$fraction.'Z');
})->with(['', '.0', '.12', '.123456', '.1234567']);

test('accepts exact SQL integer strings without changing their source fingerprint', function (): void {
    $this->approvedRun->Target = '3'; $this->approvedRun->Selected = '3';
    $this->approvedRun->File_Bytes = (string) $this->approvedRun->File_Bytes;
    $plan = SmsDebtPleteImportPlan::fromApproved($this->approvedRun, smsSenderPlanIdentity());
    expect($plan->rows())->toBe(3)->and($plan->options()['target'])->toBe(3)
        ->and($this->approvedRun->Target)->toBe('3')->and($this->approvedRun->Selected)->toBe('3');
});

test('refuses unsupported or changed source state with a static error and no previous payload', function (string $field, mixed $value): void {
    $this->approvedRun->$field = $value;
    try { SmsDebtPleteImportPlan::fromApproved($this->approvedRun, smsSenderPlanIdentity()); test()->fail('Expected rejection.'); }
    catch (InvalidArgumentException $error) {
        expect($error->getMessage())->toBe('The approved SMS import plan could not be verified.')->and($error->getPrevious())->toBeNull();
    }
})->with([
    ['Status', 'review'], ['Status', 'external_sent'], ['Selection_Policy', 'prospect-external-sent-v1'], ['Selection_Policy', 'legacy-phone-v1'],
    ['Approved_By', ''], ['Approved_By', ' '], ['Approved_At', '2020-02-30 12:00:00'], ['Approved_At', '2020-01-01 24:00:00'], ['Approved_At', '2020-01-01 00:00:00.12345678'],
    ['Approved_At', '2020-01-01T00:00:00Z'], ['Selected', 0], ['Selected', 3.0], ['Target', '03'], ['Selected', 4],
    ['File_Cleanup_Path', 'PRIVATE'], ['File_Bytes', 1], ['File_Sha256', 'PRIVATE'], ['Run_Options_JSON', '{}'], ['Artifact_Manifest_JSON', 'PRIVATE'],
    ['Request_ID', 'AAAAAAAA-AAAA-4AAA-8AAA-AAAAAAAAAAAG'],
]);

test('refuses noncanonical or mismatched trusted identities', function (string $field, mixed $value): void {
    $identity = smsSenderPlanIdentity(); $identity[$field] = $value;
    expect(fn () => SmsDebtPleteImportPlan::fromApproved($this->approvedRun, $identity))->toThrow(InvalidArgumentException::class);
})->with([
    ['protocol', 'other'], ['tenant_id', 'BBBBBBBB-BBBB-4BBB-8BBB-BBBBBBBBBBBB'], ['client_id', 'bad'],
    ['origin', 'http://tenant.example.invalid'], ['origin', 'https://Tenant.example.invalid'], ['origin', 'https://tenant.example.invalid/'],
    ['origin', 'https://user:password@tenant.example.invalid'], ['origin', 'https://tenant.example.invalid:443'], ['origin', 'https://tenant.example.invalid?q=private'],
    ['origin', 'https://tenant.example.invalid:8443'], ['origin', 'https://127.0.0.1'], ['origin', 'https://[::1]'], ['origin', 'https://localhost'],
    ['origin', 'https://tenant.123'], ['origin', 'https://tenant.example.invalid.'], ['origin', 'https://tenant..invalid'],
    ['origin', 'https://-tenant.example.invalid'], ['origin', 'https://tenant.example.invalid#private'],
    ['unexpected', 'PRIVATE'],
]);

test('enforces receiver byte limits before handoff even with otherwise valid exporter metadata', function (): void {
    $manifest = json_decode($this->approvedRun->Artifact_Manifest_JSON, true, flags: JSON_THROW_ON_ERROR);
    $manifest['parts'][0]['bytes'] = 1073741825;
    $manifest['download']['bytes'] = 1073742825;
    $this->approvedRun->Artifact_Manifest_JSON = json_encode($manifest, JSON_THROW_ON_ERROR);
    $this->approvedRun->File_Bytes = $manifest['download']['bytes'];
    expect(fn () => SmsDebtPleteImportPlan::fromApproved($this->approvedRun, smsSenderPlanIdentity()))->toThrow(InvalidArgumentException::class);
});

test('accepts the same canonical maximum DNS host length as the real client', function (): void {
    $identity = smsSenderPlanIdentity();
    $identity['origin'] = 'https://'.str_repeat('a', 63).'.'.str_repeat('b', 63).'.'.str_repeat('c', 63).'.'.str_repeat('d', 61);
    expect(strlen($identity['origin']))->toBe(261)
        ->and(SmsDebtPleteImportPlan::fromApproved($this->approvedRun, $identity)->identity())->toBe($identity);
});

test('fingerprints every frozen publication field including fields not sent to the receiver', function (): void {
    $before = SmsDebtPleteImportPlan::fromApproved($this->approvedRun, smsSenderPlanIdentity());
    $this->approvedRun->Sent_By = 'other@example.invalid';
    $after = SmsDebtPleteImportPlan::fromApproved($this->approvedRun, smsSenderPlanIdentity());
    expect($after->fingerprint())->not->toBe($before->fingerprint())->and($after->sha256())->toBe($before->sha256());
});

test('canonicalizes only a trusted SQL Server uppercase source UUID and retains its raw fingerprint', function (): void {
    $lower = SmsDebtPleteImportPlan::fromApproved($this->approvedRun, smsSenderPlanIdentity());
    $this->approvedRun->Request_ID = strtoupper($this->approvedRun->Request_ID);
    $before = serialize($this->approvedRun);
    $provider = SmsDebtPleteImportPlan::fromApproved($this->approvedRun, smsSenderPlanIdentity());
    expect($provider->requestId())->toBe($this->planId)->and($provider->payload())->toBe($lower->payload())
        ->and($provider->json())->toBe($lower->json())->and($provider->sha256())->toBe($lower->sha256())
        ->and($provider->fingerprint())->not->toBe($lower->fingerprint())
        ->and(serialize($this->approvedRun))->toBe($before)
        ->and($this->approvedRun->Request_ID)->toBe(strtoupper($this->planId));
    $manifest = json_decode($this->approvedRun->Artifact_Manifest_JSON, true, flags: JSON_THROW_ON_ERROR);
    $manifest['request_id'] = strtoupper($manifest['request_id']);
    $this->approvedRun->Artifact_Manifest_JSON = json_encode($manifest, JSON_THROW_ON_ERROR);
    expect(fn () => SmsDebtPleteImportPlan::fromApproved($this->approvedRun, smsSenderPlanIdentity()))->toThrow(InvalidArgumentException::class);
});

test('rejects a subset selector without an explicit policy rather than inventing one', function (): void {
    $options = json_decode($this->approvedRun->Run_Options_JSON, true, flags: JSON_THROW_ON_ERROR);
    unset($options['tier_selection_policy']);
    $this->approvedRun->Run_Options_JSON = json_encode($options, JSON_THROW_ON_ERROR);
    expect(fn () => SmsDebtPleteImportPlan::fromApproved($this->approvedRun, smsSenderPlanIdentity()))->toThrow(InvalidArgumentException::class);
});

test('accepts the receiver total-byte boundary and rejects aggregate growth without reading artifacts', function (): void {
    $options = SmsRunOptions::normalize(10, 1, ['T1'], SmsRunOptions::WINDOWED_TIER_SELECTION_POLICY,
        SmsRunOptions::PLANNED_DATE_VERSION, '2020-01-13', SmsRunOptions::APPROVAL_FILENAME_VERSION);
    $stem = 'SMS Export - 2020-01-13 - SMS0001-SMS0010'; $parts = [];
    for ($part = 1; $part <= 10; ++$part) {
        $parts[] = ['part' => $part, 'name' => $stem.' - Part '.$part.' of 10.csv', 'rows' => 1, 'bytes' => 1073741824, 'sha256' => str_repeat('a', 64)];
    }
    $manifest = ['version' => 1, 'request_id' => $this->planId,
        'options_sha256' => hash('sha256', json_encode($options, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
        'rows' => 10, 'rows_per_file' => 1, 'csv_format' => 'sms-four-column-v1',
        'download' => ['name' => $stem.'.zip', 'format' => 'zip', 'bytes' => 10737418340, 'sha256' => str_repeat('b', 64)], 'parts' => $parts];
    $this->approvedRun->Target = $this->approvedRun->Selected = 10;
    $this->approvedRun->Run_Options_JSON = json_encode($options, JSON_THROW_ON_ERROR);
    $this->approvedRun->Artifact_Manifest_JSON = json_encode($manifest, JSON_THROW_ON_ERROR);
    $this->approvedRun->File_Bytes = $manifest['download']['bytes']; $this->approvedRun->File_Sha256 = $manifest['download']['sha256'];
    expect(SmsDebtPleteImportPlan::fromApproved($this->approvedRun, smsSenderPlanIdentity())->parts())->toHaveCount(10);
    $options['target'] = 11; $stem = 'SMS Export - 2020-01-13 - SMS0001-SMS0011';
    $manifest['rows'] = 11; $manifest['parts'][] = $parts[0];
    foreach ($manifest['parts'] as $index => &$part) { $part['part'] = $index + 1; $part['name'] = $stem.' - Part '.($index + 1).' of 11.csv'; }
    unset($part);
    $manifest['options_sha256'] = hash('sha256', json_encode($options, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    $manifest['download']['name'] = $stem.'.zip'; $manifest['download']['bytes'] += 1073741824;
    $this->approvedRun->Target = $this->approvedRun->Selected = 11;
    $this->approvedRun->Run_Options_JSON = json_encode($options, JSON_THROW_ON_ERROR);
    $this->approvedRun->Artifact_Manifest_JSON = json_encode($manifest, JSON_THROW_ON_ERROR);
    $this->approvedRun->File_Bytes = $manifest['download']['bytes'];
    expect(fn () => SmsDebtPleteImportPlan::fromApproved($this->approvedRun, smsSenderPlanIdentity()))->toThrow(InvalidArgumentException::class);
});
