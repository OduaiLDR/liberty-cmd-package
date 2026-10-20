<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2).'/scripts/sms-prospect-reconciliation.php';
require_once dirname(__DIR__, 2).'/scripts/sms-prospect-reconciliation-cli.php';

function reconciliationPerson(array $overrides = []): array
{
    return $overrides + ['PK' => 1, 'Client' => 'Ann Lee', 'Address' => '1 Test St', 'Zip' => '01234',
        'Phone' => '2025550101', 'Email' => 'ann@example.test', 'SMS_Attempts' => 3, 'Latest_Export_Date' => '2026-10-07', 'Opt_Out' => 1];
}

it('compares contact formatting without changing original identity or tracking', function (): void {
    $p = reconciliationPerson();
    $s = ['PK' => 20, 'Phone' => '+1 (202) 555-0101', 'Email' => 'ANN@example.test'];
    expect(SmsProspectReconciliation::classify($p, [$s])['category'])->toBe('matched_same_contacts')
        ->and($p['SMS_Attempts'])->toBe(3)->and($p['Opt_Out'])->toBe(1)->and($p['Client'])->toBe('Ann Lee');
    expect(SmsProspectReconciliation::phone('0202555010'))->toBe('0202555010')
        ->and(SmsProspectReconciliation::email('bad@email'))->toBeNull();
});

it('distinguishes available, changed, unsupported and unusable contacts without choosing a repair', function (): void {
    $result = SmsProspectReconciliation::classify(reconciliationPerson(), [['Phone' => '2025550102', 'Email' => null]]);
    expect($result['category'])->toBe('matched_contact_differences')
        ->and($result['differences'])->toBe(['phone_changed', 'email_unsupported']);
    $result = SmsProspectReconciliation::classify(reconciliationPerson(['Phone' => null, 'Email' => 'bad']), [['Phone' => '2025550101', 'Email' => 'valid@example.test']]);
    expect($result['differences'])->toBe(['phone_available','email_available','prospect_email_unusable']);
    $result = SmsProspectReconciliation::classify(reconciliationPerson(['Phone' => null, 'Email' => null]), [['Phone' => 'bad', 'Email' => 'bad']]);
    expect($result['category'])->toBe('matched_contact_differences')
        ->and($result['differences'])->toBe(['source_phone_unusable', 'source_email_unusable']);
});

it('does not call missing evidence an orphan or choose between multiple source rows', function (): void {
    expect(SmsProspectReconciliation::classify(reconciliationPerson(), [])['category'])->toBe('no_indexed_source_match');
    expect(SmsProspectReconciliation::classify(reconciliationPerson(), [['PK' => 1], ['PK' => 2]])['category'])->toBe('ambiguous_source_match');
    expect(SmsProspectReconciliation::classify(reconciliationPerson(['Zip' => '1234']), [])['category'])->toBe('invalid_prospect_identity');
    expect(SmsProspectReconciliation::classify(reconciliationPerson(['Client' => '']), [])['category'])->toBe('invalid_prospect_identity');
});

it('fingerprints exact before-values including nulls, identity case, flags and send dates', function (): void {
    $p = reconciliationPerson();
    $hash = SmsProspectReconciliation::classify($p, [])['prospect_sha256'];
    foreach (['Client' => 'ANN LEE', 'Phone' => null, 'SMS_Attempts' => 2, 'Latest_Export_Date' => null, 'Opt_Out' => 0] as $field => $value) {
        expect(SmsProspectReconciliation::classify(array_replace($p, [$field => $value]), [])['prospect_sha256'])->not->toBe($hash);
    }
});

it('rejects database mutations before preparing a statement', function (): void {
    $db = new PDO('sqlite::memory:');
    foreach (['DELETE FROM x', 'WITH x AS (SELECT 1 AS n) UPDATE x SET n=2', 'SELECT * INTO backup FROM x',
        'SELECT 1; DROP TABLE x', 'EXEC procedure', 'SELECT * FROM x WITH(NOLOCK)', 'CREATE TABLE x(n int)'] as $sql) {
        expect(fn () => SmsProspectReconciliation::select($db, $sql))->toThrow(RuntimeException::class, 'read-only');
    }
});

it('publishes immutable verified batches and resumes only the saved watermark', function (): void {
    $dir = sys_get_temp_dir().'/sms-reconciliation-unit-'.bin2hex(random_bytes(8)); mkdir($dir, 0700);
    $manifest = ['batch_size' => 2, 'watermark_pk' => 4];
    $batch = ['after_pk' => 0, 'last_pk' => 2, 'watermark_pk' => 4, 'people' => 2,
        'counts' => array_replace(SmsProspectReconciliation::counts(), ['matched_same_contacts' => 1, 'no_indexed_source_match' => 1]),
        'details' => [['prospect_pk' => 2]], 'observed_sha256' => str_repeat('a', 64)];
    try {
        SmsProspectReconciliation::privateJson($dir.'/manifest.json', $manifest);
        $hash = hash_file('sha256', $dir.'/manifest.json');
        SmsProspectReconciliation::saveBatch($dir, 1, $batch, $hash);
        mkdir($dir.'/batch-00000002.interrupted.partial', 0700);
        $state = SmsProspectReconciliation::journal($dir, $manifest);
        expect($state['people'])->toBe(2)->and($state['after_pk'])->toBe(2)->and($state['exhausted'])->toBeFalse();
        expect(fn () => SmsProspectReconciliation::saveBatch($dir, 1, $batch, $hash))->toThrow(RuntimeException::class);
        expect(fn () => SmsProspectReconciliation::privateJson($dir.'/manifest.json', []))->toThrow(RuntimeException::class);
        $batch2 = array_replace($batch, ['after_pk' => 2, 'last_pk' => 4]);
        SmsProspectReconciliation::saveBatch($dir, 2, $batch2, $hash);
        $state = SmsProspectReconciliation::journal($dir, $manifest);
        expect($state['people'])->toBe(4)->and($state['exhausted'])->toBeTrue()->and($state['counts']['matched_same_contacts'])->toBe(2);
        file_put_contents($dir.'/batch-00000002/evidence.json.gz', 'truncated');
        expect(fn () => SmsProspectReconciliation::journal($dir, $manifest))->toThrow(RuntimeException::class, 'fingerprint');
    } finally {
        foreach (glob($dir.'/batch-*', GLOB_ONLYDIR) as $batchDir) {
            foreach (glob($batchDir.'/*') as $file) unlink($file);
            rmdir($batchDir);
        }
        unlink($dir.'/manifest.json'); rmdir($dir);
    }
});

it('refuses journal gaps, changed manifests and invalid ranges', function (): void {
    foreach (['gap','manifest','range','totals'] as $variant) {
        $dir = sys_get_temp_dir().'/sms-reconciliation-unit-'.bin2hex(random_bytes(8)); mkdir($dir, 0700);
        $manifest = ['batch_size' => 2, 'watermark_pk' => 5];
        $batch = ['after_pk' => $variant === 'range' ? 1 : 0, 'last_pk' => 2, 'watermark_pk' => 5, 'people' => 2,
            'counts' => array_replace(SmsProspectReconciliation::counts(), ['matched_same_contacts' => $variant === 'totals' ? 1 : 2]), 'details' => []];
        try {
            SmsProspectReconciliation::privateJson($dir.'/manifest.json', $manifest);
            SmsProspectReconciliation::saveBatch($dir, $variant === 'gap' ? 2 : 1, $batch, hash_file('sha256', $dir.'/manifest.json'));
            if ($variant === 'manifest') file_put_contents($dir.'/manifest.json', 'changed');
            expect(fn () => SmsProspectReconciliation::journal($dir, $manifest))->toThrow(RuntimeException::class);
        } finally {
            foreach (glob($dir.'/batch-*', GLOB_ONLYDIR) as $batchDir) {
                foreach (glob($batchDir.'/*') as $file) unlink($file);
                rmdir($batchDir);
            }
            unlink($dir.'/manifest.json'); rmdir($dir);
        }
    }
});

it('rejects apply, unknown flags and unsafe batch parameters before reading credentials', function (): void {
    foreach (['--apply', '--delete', '--resume=true', '--max-batches=-1', '--batch-size=0', '--batch-size=1001'] as $option) {
        expect(fn () => smsReconciliationMain(['audit', $option]))->toThrow(RuntimeException::class);
    }
    expect(fn () => smsReconciliationMain(['audit','--batch-size=1','--batch-size=2']))->toThrow(RuntimeException::class);
});

it('does not expose malformed environment contents or connect to an unreviewed server', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'sms-audit-env-');
    $root = dirname(__DIR__, 3).'/local-dev/sms-test-harness';
    try {
        file_put_contents($path, "INVALID KEY=PRIVATE_TEST_MARKER\n");
        $caught = false;
        try { smsReconciliationMain(['audit','--root='.$root,'--env='.$path,'--out=/private/audit-not-created']); }
        catch (RuntimeException $error) {
            $caught = true;
            expect($error->getMessage())->toContain('could not be read safely')->not->toContain('PRIVATE_TEST_MARKER');
        }
        expect($caught)->toBeTrue();
        file_put_contents($path, "CMD_DB_HOST=unexpected.example.test\nCMD_DB_DATABASE=other\n");
        expect(fn () => smsReconciliationMain(['audit','--root='.$root,'--env='.$path,'--out=/private/audit-not-created']))
            ->toThrow(RuntimeException::class, 'pinned');
    } finally { unlink($path); }
});
