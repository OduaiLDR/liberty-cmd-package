<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2).'/scripts/sms-recovery-snapshot.php';

it('refuses legacy baseline guesses and accepts only the known current send', function (): void {
    $run = ['Request_ID' => strtoupper(SmsRecoverySnapshot::RUN), 'Selected' => '3000000', 'Status' => 'sent'];
    SmsRecoverySnapshot::assertBaseline('current', $run, 3000000);
    expect(fn () => SmsRecoverySnapshot::assertBaseline('current', $run, 2999999))->toThrow(RuntimeException::class);
    expect(fn () => SmsRecoverySnapshot::assertBaseline('before', $run, 0))->toThrow(RuntimeException::class);
    expect(fn () => SmsRecoverySnapshot::assertBaseline('before', array_replace($run, ['Status' => 'sending']), 3000000))->toThrow(RuntimeException::class);
    expect(fn () => SmsRecoverySnapshot::assertBaseline('current', array_replace($run, ['Request_ID' => 'other']), 3000000))->toThrow(RuntimeException::class);
});

it('allows an in-progress run before the transactional send but not a completed timestamp', function (): void {
    $run = ['Request_ID' => SmsRecoverySnapshot::RUN, 'Selected' => 3000000, 'Status' => 'sending', 'Sent_At' => null];
    SmsRecoverySnapshot::assertBaseline('before', $run, 0);
    expect(fn () => SmsRecoverySnapshot::assertBaseline('before', $run, 1))->toThrow(RuntimeException::class);
    expect(fn () => SmsRecoverySnapshot::assertBaseline('before', array_replace($run, ['Sent_At' => '2026-10-15 19:10:45']), 0))->toThrow(RuntimeException::class);
});

it('pins original identity exactly without treating case, NULL or separators as interchangeable', function (): void {
    $person = ['Client' => 'Ann Lee', 'Address' => '1 Test St', 'Zip' => '90210'];
    expect(SmsRecoverySnapshot::identityHash($person))->toHaveLength(64)
        ->not->toBe(SmsRecoverySnapshot::identityHash(array_replace($person, ['Client' => 'ANN LEE'])))
        ->not->toBe(SmsRecoverySnapshot::identityHash(array_replace($person, ['Client' => null])));
    expect(SmsRecoverySnapshot::identityHash(['Client' => 'A|B', 'Address' => 'C']))
        ->not->toBe(SmsRecoverySnapshot::identityHash(['Client' => 'A', 'Address' => 'B|C']));
});

it('round trips private compressed rows and refuses tampered or overwritten evidence', function (): void {
    $directory = sys_get_temp_dir().'/sms-recovery-test-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $rows = [['PK' => '1', 'Phone' => '0202555010', 'Latest_Export_Date' => null, 'SMS_Attempts' => '0'],
        ['PK' => '2', 'Phone' => '2025550102', 'Latest_Export_Date' => '2026-10-07', 'SMS_Attempts' => '3']];
    $path = $directory.'/tracking.jsonl.gz';
    try {
        $file = SmsRecoverySnapshot::writeRows($path, $rows);
        expect($file['rows'])->toBe(2);
        $in = gzopen($path, 'rb');
        try { expect(json_decode(gzgets($in), true, 512, JSON_THROW_ON_ERROR))->toBe($rows[0]); }
        finally { gzclose($in); }
        file_put_contents($directory.'/manifest.json', json_encode(['format' => 1, 'complete' => true, 'role' => 'current', 'files' => ['tracking' => $file]], JSON_THROW_ON_ERROR));
        expect(SmsRecoverySnapshot::verifyArchive($path, $file))->toBe(2);
        expect(fn () => SmsRecoverySnapshot::verify($directory))->toThrow(RuntimeException::class);
        expect(fn () => SmsRecoverySnapshot::writeRows($path, []))->toThrow(RuntimeException::class);
        file_put_contents($path, 'tampered');
        expect(fn () => SmsRecoverySnapshot::verify($directory))->toThrow(RuntimeException::class);
    } finally {
        foreach ([$path, $directory.'/manifest.json'] as $file) if (is_file($file)) unlink($file);
        rmdir($directory);
    }
});

it('rejects malformed gzip even with matching compressed hashes and row counts', function (): void {
    $directory = sys_get_temp_dir().'/sms-recovery-test-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $path = $directory.'/fixture.jsonl.gz';
    $good = gzencode('{"PK":"1"}'."\n");
    $badCrc = $good;
    $badCrc[strlen($badCrc) - 8] = chr(ord($badCrc[strlen($badCrc) - 8]) ^ 1);
    try {
        foreach ([substr($good, 0, -8), $badCrc, '{"PK":"1"}'."\n", $good.'trailing', $good.$good] as $bytes) {
            file_put_contents($path, $bytes);
            $entry = ['bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'rows' => 1];
            expect(fn () => SmsRecoverySnapshot::verifyArchive($path, $entry))->toThrow(RuntimeException::class);
        }
    } finally { unlink($path); rmdir($directory); }
});

it('requires all recovery evidence entries and the exact reviewed manifest identity', function (): void {
    $manifest = ['format' => 1, 'complete' => true, 'read_only' => true, 'run_id' => SmsRecoverySnapshot::RUN,
        'isolation' => 'SNAPSHOT', 'role' => 'current', 'target_history_rows' => 3000000, 'files' => [],
        'identity' => ['Server_Name' => 'serverlibertydebtrelief', 'Database_Name' => 'CommissionDatabase', 'Database_UTC' => '2026-10-19 20:00:00'],
        'requested_before_utc' => SmsRecoverySnapshot::BEFORE_UTC, 'tool_sha256' => str_repeat('b', 64),
        'capture_started_utc' => '2026-10-19T20:00:00Z', 'capture_finished_utc' => '2026-10-19T20:01:00Z'];
    foreach (['prospects_tracking' => 8, 'target_history' => 3000000, 'sms_runs' => 1, 'sms_drops' => 18, 'working_export' => 0] as $name => $rows) {
        $manifest['files'][$name] = ['file' => $name.'.jsonl.gz', 'rows' => $rows, 'bytes' => 20, 'sha256' => str_repeat('a', 64)];
    }
    SmsRecoverySnapshot::assertManifest($manifest);
    foreach (array_keys($manifest['files']) as $name) {
        $missing = $manifest;
        unset($missing['files'][$name]);
        expect(fn () => SmsRecoverySnapshot::assertManifest($missing))->toThrow(RuntimeException::class);
    }
    expect(fn () => SmsRecoverySnapshot::assertManifest(array_replace($manifest, ['run_id' => 'another'])))->toThrow(RuntimeException::class);
    expect(fn () => SmsRecoverySnapshot::assertManifest(array_replace($manifest, ['isolation' => 'NOLOCK'])))->toThrow(RuntimeException::class);
    foreach (['identity', 'capture_started_utc', 'capture_finished_utc', 'tool_sha256'] as $field) {
        $missing = $manifest;
        unset($missing[$field]);
        expect(fn () => SmsRecoverySnapshot::assertManifest($missing))->toThrow(RuntimeException::class);
    }
    $wrongDatabase = $manifest;
    $wrongDatabase['identity']['Database_Name'] = 'sms_recovery_20261015_185027';
    expect(fn () => SmsRecoverySnapshot::assertManifest($wrongDatabase))->toThrow(RuntimeException::class);
});

it('never advertises an interrupted stream as a completed evidence file', function (): void {
    $directory = sys_get_temp_dir().'/sms-recovery-test-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $path = $directory.'/interrupted.jsonl.gz';
    $rows = (function (): Generator { yield ['PK' => '1']; throw new RuntimeException('fixture interruption'); })();
    try {
        expect(fn () => SmsRecoverySnapshot::writeRows($path, $rows))->toThrow(RuntimeException::class);
        expect(file_exists($path))->toBeFalse()->and(file_exists($path.'.partial'))->toBeTrue();
        expect(fn () => SmsRecoverySnapshot::writeRows($path, []))->toThrow(RuntimeException::class);
    } finally {
        if (is_file($path.'.partial')) unlink($path.'.partial');
        rmdir($directory);
    }
});
