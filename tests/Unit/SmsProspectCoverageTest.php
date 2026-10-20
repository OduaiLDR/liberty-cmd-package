<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2).'/scripts/sms-prospect-coverage.php';

function coverageManifest(array $changes = []): array
{
    return $changes + ['format' => 1, 'read_only' => true, 'apply_supported' => false, 'batch_size' => 3,
        'source_watermark_pk' => 50, 'prospect_watermark_pk' => 20, 'source_guard' => str_repeat('a', 64)];
}

function coverageRow(array $changes = []): array
{
    return $changes + ['Identity_Valid' => 1, 'Client' => 'Ann Lee', 'Address' => '1 Test St', 'Zip' => '00123',
        'Phone' => '+1 (202) 555-0101', 'Email' => ' person@example.test '];
}

it('keeps either usable contact independently and preserves leading-zero identities', function (): void {
    $both = SmsProspectCoverage::normalize(coverageRow());
    expect($both['identity_valid'])->toBeTrue()->and($both['Phone'])->toBe('2025550101')->and($both['Email'])->toBe('person@example.test');
    $phone = SmsProspectCoverage::normalize(coverageRow(['Email' => 'invalid@']));
    expect($phone['Phone'])->toBe('2025550101')->and($phone['Email'])->toBeNull()->and($phone['invalid_email'])->toBeTrue();
    $email = SmsProspectCoverage::normalize(coverageRow(['Phone' => '123', 'Email' => 'OriginalCase@example.test']));
    expect($email['Phone'])->toBeNull()->and($email['Email'])->toBe('OriginalCase@example.test')->and($email['invalid_phone'])->toBeTrue();
    $neither = SmsProspectCoverage::normalize(coverageRow(['Phone' => null, 'Email' => null]));
    expect($neither['Phone'])->toBeNull()->and($neither['Email'])->toBeNull()->and($neither['invalid_phone'])->toBeFalse()->and($neither['invalid_email'])->toBeFalse();
});

it('does not treat malformed or lossy identities as valid new people', function (): void {
    foreach ([['Identity_Valid' => 0], ['Client' => ''], ['Address' => ' '], ['Client' => "Ann\tLee"],
        ['Address' => ' 1 Test St'], ['Zip' => '1234'], ['Zip' => '1234x'], ['Zip' => '00123 ']] as $changes) {
        expect(SmsProspectCoverage::normalize(coverageRow($changes))['identity_valid'])->toBeFalse();
    }
    expect(SmsProspectCoverage::normalize(coverageRow(['Email' => str_repeat('a', 245).'@example.test']))['Email'])->toBeNull();
});

it('starts an empty private journal and refuses replacement or changed manifests', function (): void {
    $journal = new PDO('sqlite::memory:'); $manifest = coverageManifest();
    SmsProspectCoverage::initialize($journal, $manifest);
    $state = SmsProspectCoverage::state($journal, $manifest);
    expect($state['source_rows'])->toBe(0)->and($state['after_pk'])->toBe(0)->and($state['exhausted'])->toBeFalse()
        ->and(array_keys($state['counts']))->toBe(SmsProspectCoverage::COUNTERS)->and(array_sum($state['counts']))->toBe(0);
    expect(fn () => SmsProspectCoverage::initialize($journal, $manifest))->toThrow(RuntimeException::class, 'replace');
    expect(fn () => SmsProspectCoverage::state($journal, coverageManifest(['source_watermark_pk' => 51])))->toThrow(RuntimeException::class, 'manifest');
    expect(fn () => SmsProspectCoverage::initialize(new PDO('sqlite::memory:'), coverageManifest(['apply_supported' => true])))
        ->toThrow(RuntimeException::class, 'read-only');
});

it('rejects inconsistent counters and checkpoints rather than trusting edited progress', function (): void {
    foreach (['totals', 'negative', 'bounds', 'checkpoint'] as $fault) {
        $journal = new PDO('sqlite::memory:'); $manifest = coverageManifest();
        SmsProspectCoverage::initialize($journal, $manifest);
        $state = SmsProspectCoverage::state($journal, $manifest);
        if ($fault === 'totals') $state['source_rows'] = 1;
        if ($fault === 'negative') $state['counts']['invalid_phone_rows'] = -1;
        if ($fault === 'bounds') $state['after_pk'] = 51;
        if ($fault === 'checkpoint') $state['batches'] = 1;
        $journal->prepare('UPDATE state SET payload=? WHERE id=1')->execute([SmsProspectReconciliation::json($state)]);
        expect(fn () => SmsProspectCoverage::state($journal, $manifest))->toThrow(RuntimeException::class);
    }
});

it('fails closed on unavailable stale or busy database resource statistics', function (): void {
    $fresh = ['age_seconds' => 4, 'avg_cpu_percent' => 1, 'avg_data_io_percent' => 2, 'avg_log_write_percent' => 3];
    SmsProspectCoverage::assertResources(['resources' => $fresh]);
    foreach ([['unavailable' => true], ['age_seconds' => 121] + $fresh, ['age_seconds' => -1] + $fresh,
        ['avg_cpu_percent' => 80] + $fresh, ['avg_data_io_percent' => 80] + $fresh, ['avg_log_write_percent' => 80] + $fresh] as $resources) {
        expect(fn () => SmsProspectCoverage::assertResources(['resources' => $resources]))->toThrow(RuntimeException::class, 'resume later');
    }
});

it('refuses unbounded ranges and potentially writable source SQL before database execution', function (): void {
    $db = new PDO('sqlite::memory:');
    foreach ([[0, 50, 20, 0], [0, 50, 20, 1001], [-1, 50, 20, 2], [51, 50, 20, 2], [0, 50, -1, 2]] as $range) {
        expect(fn () => SmsProspectCoverage::sourceRows($db, ...$range))->toThrow(RuntimeException::class, 'bounded');
    }
    expect(SmsProspectCoverage::identityGroups($db, [], []))->toBe([]);
    $source = file_get_contents(dirname(__DIR__, 2).'/scripts/sms-prospect-coverage.php');
    expect($source)->not->toContain('INSERT INTO dbo.', 'UPDATE dbo.', 'DELETE FROM dbo.', 'sp_getapplock', 'SmsAvailabilityService', 'markTexted(');
});
