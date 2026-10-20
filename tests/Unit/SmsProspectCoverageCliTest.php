<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2).'/scripts/sms-prospect-coverage-cli.php';

it('uses conservative coverage CLI defaults without loading configuration or a database', function (): void {
    $options = smsCoverageOptions(['coverage']);
    expect($options['batch-size'])->toBe(500)->and($options['max-batches'])->toBe(1)
        ->and($options['out'])->toBe('/home/ubuntu/new-private-output')
        ->and($options['env'])->toBe($options['root'].'/.env')
        ->and($options['resume'])->toBeFalse()->and($options['metadata-only'])->toBeFalse()
        ->and($options['explicit-batch-size'])->toBeFalse();
});

it('accepts bounded batch controls and bare switches without doing IO', function (): void {
    foreach ([1, 500, 1000] as $size) {
        $options = smsCoverageOptions(['coverage', '--batch-size='.$size, '--max-batches=0', '--resume', '--metadata-only',
            '--root=/var/www/cmd-runner', '--env=/private/credentials.env', '--out=/home/ubuntu/private-audit']);
        expect($options['batch-size'])->toBe($size)->and($options['max-batches'])->toBe(0)
            ->and($options['explicit-batch-size'])->toBeTrue()->and($options['resume'])->toBeTrue()
            ->and($options['metadata-only'])->toBeTrue()->and($options['root'])->toBe('/var/www/cmd-runner')
            ->and($options['env'])->toBe('/private/credentials.env');
    }
    expect(smsCoverageOptions(['coverage', '--max-batches='.(string) PHP_INT_MAX])['max-batches'])->toBe(PHP_INT_MAX);
});

it('rejects apply, external actions, positional arguments, and malformed switches before configuration', function (): void {
    foreach (['--apply', '--apply=true', '--apply=false', '--execute', '--delete', '--unknown', '--', '-h', 'recipient@example.test',
        '--batch-size', '--out=', '--env=', '--root=', '--resume=true', '--metadata-only=false', '--help=',
        '--max-batches=1'."\n".'PRIVATE_OPTION_MARKER', '--ROOT=/private'] as $arg) {
        try {
            smsCoverageMain(['coverage', '--root=/root-that-does-not-exist', $arg]);
            throw new LogicException('Invalid option was accepted.');
        } catch (SmsProspectCoverageCliException $error) {
            expect($error->getMessage())->not->toContain('configuration could not')->not->toContain('PRIVATE_OPTION_MARKER')
                ->not->toContain('recipient@example.test');
        }
    }
});

it('rejects duplicate options including flags before configuration even when help is present', function (): void {
    foreach (['--root=/private', '--env=/private/env', '--out=/private/out', '--batch-size=5', '--max-batches=0',
        '--resume', '--metadata-only', '--help'] as $arg) {
        expect(fn () => smsCoverageMain(['coverage', $arg, $arg]))->toThrow(SmsProspectCoverageCliException::class, 'duplicate');
    }
    expect(fn () => smsCoverageMain(['coverage', '--help', '--apply']))->toThrow(SmsProspectCoverageCliException::class)
        ->and(fn () => smsCoverageMain(['coverage', '--help', '--batch-size=1001']))->toThrow(SmsProspectCoverageCliException::class);
});

it('rejects unbounded and noninteger batch options before configuration', function (): void {
    foreach (['0', '1001', '-1', '+1', '1.5', '1e2', ' 1', '1 ', '999999999999999999999999', 'NaN'] as $value) {
        expect(fn () => smsCoverageMain(['coverage', '--batch-size='.$value]))->toThrow(SmsProspectCoverageCliException::class, 'batch');
    }
    foreach (['-1', '+1', '1.5', '1e2', ' 0', '1 ', '18446744073709551616', '999999999999999999999999', 'all'] as $value) {
        expect(fn () => smsCoverageMain(['coverage', '--max-batches='.$value]))->toThrow(SmsProspectCoverageCliException::class, 'batch');
    }
});

it('rejects traversal, streams, network shares, control characters, and nondedicated output paths', function (): void {
    foreach (['relative/report', '/private/../report', '/private/./report', '/private/report/..', 'file:///private/report',
        'php://filter', '//server/share/report', '\\\\server\\share\\report', '/private//report', '/private/report:stream',
        '/private/report'."\0", '/private/report'."\n"] as $path) {
        foreach (['root', 'env', 'out'] as $key) {
            expect(fn () => smsCoverageOptions(['coverage', '--'.$key.'='.$path]))->toThrow(SmsProspectCoverageCliException::class);
        }
    }
    foreach (['/', 'C:/'] as $path) {
        expect(fn () => smsCoverageOptions(['coverage', '--out='.$path]))->toThrow(SmsProspectCoverageCliException::class, 'dedicated');
    }
    $options = smsCoverageOptions(['coverage', '--root=C:\\local\\runner', '--out=C:\\private\\audit']);
    expect($options['root'])->toBe('C:/local/runner')->and($options['out'])->toBe('C:/private/audit');
});

it('prints help without requiring the core, environment, application, or any database driver', function (): void {
    ob_start();
    try {
        $status = smsCoverageMain(['coverage', '--help', '--root=/missing-runner']);
        $output = ob_get_contents();
    } finally { ob_end_clean(); }
    expect($status)->toBe(0)->and($output)->toContain('NO apply', '--batch-size=500', '--max-batches=1', '--metadata-only', '8 GiB', '0700/0600');
});

it('refuses existing fresh outputs and missing resumes before loading configuration', function (): void {
    $directory = sys_get_temp_dir().'/sms-coverage-cli-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    try {
        expect(fn () => smsCoverageMain(['coverage', '--root=/missing-runner', '--out='.$directory]))
            ->toThrow(SmsProspectCoverageCliException::class, 'new output directory');
        expect(fn () => smsCoverageMain(['coverage', '--root=/missing-runner', '--out='.$directory.'/missing', '--resume']))
            ->toThrow(SmsProspectCoverageCliException::class, 'existing audit');
        expect(scandir($directory))->toBe(['.', '..']);
    } finally { rmdir($directory); }
});

it('rejects symlinked output ancestors and dangling output links before configuration', function (): void {
    if (PHP_OS_FAMILY === 'Windows') test()->markTestSkipped('This Windows account lacks symlink creation privileges; run this check on the Linux target.');
    $directory = sys_get_temp_dir().'/sms-coverage-links-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    try {
        if (! @symlink($directory, $directory.'/alias')) test()->markTestSkipped('Symlink creation is unavailable for this local account.');
        expect(fn () => smsCoverageMain(['coverage', '--out='.$directory.'/alias/new-report']))
            ->toThrow(SmsProspectCoverageCliException::class, 'Symlink');
        expect(fn () => smsCoverageMain(['coverage', '--out='.$directory.'/alias', '--resume']))
            ->toThrow(SmsProspectCoverageCliException::class, 'Symlink');
        unlink($directory.'/alias');
        if (! @symlink($directory.'/missing', $directory.'/dangling')) test()->markTestSkipped('Dangling symlink creation is unavailable.');
        expect(fn () => smsCoverageMain(['coverage', '--out='.$directory.'/dangling']))
            ->toThrow(SmsProspectCoverageCliException::class, 'Symlink');
    } finally {
        foreach (['alias', 'dangling'] as $name) if (is_link($directory.'/'.$name)) unlink($directory.'/'.$name);
        rmdir($directory);
    }
});

it('redacts malformed environment contents using only the local harness vendor without connecting', function (): void {
    $root = dirname(__DIR__, 3).'/local-dev/sms-test-harness';
    $path = tempnam(sys_get_temp_dir(), 'sms-coverage-env-');
    $output = sys_get_temp_dir().'/sms-coverage-not-created-'.bin2hex(random_bytes(8));
    try {
        file_put_contents($path, "INVALID KEY=PRIVATE_COVERAGE_ENV_MARKER\nCMD_DB_PASSWORD=PRIVATE_PASSWORD_MARKER\n");
        ob_start();
        try {
            smsCoverageMain(['coverage', '--root='.$root, '--env='.$path, '--out='.$output]);
            throw new LogicException('Malformed configuration was accepted.');
        } catch (SmsProspectCoverageCliException $error) {
            expect($error->getMessage())->toContain('could not be read safely')->not->toContain('PRIVATE_COVERAGE_ENV_MARKER')
                ->not->toContain('PRIVATE_PASSWORD_MARKER')->not->toContain('INVALID KEY');
            expect(ob_get_contents())->toBe('');
        } finally { ob_end_clean(); }
        expect(file_exists($output))->toBeFalse();
        // Host mismatch must be rejected before the PDO constructor, even with otherwise plausible credentials.
        file_put_contents($path, "CMD_DB_HOST=unexpected.example.test\nCMD_DB_DATABASE=other\nCMD_DB_USERNAME=PRIVATE_USER_MARKER\nCMD_DB_PASSWORD=PRIVATE_PASSWORD_MARKER\n");
        expect(fn () => smsCoverageMain(['coverage', '--root='.$root, '--env='.$path, '--out='.$output]))
            ->toThrow(SmsProspectCoverageCliException::class, 'pinned');
        expect(file_exists($output))->toBeFalse();
    } finally { unlink($path); }
});

it('redacts PDO, dependency and runtime diagnostic strings without echoing SQL, bindings or recipient fields', function (): void {
    foreach ([new PDOException('PRIVATE_SQL_MARKER SELECT Phone,Email FROM source WHERE Client=PRIVATE_BINDING_MARKER'),
        new RuntimeException('PRIVATE_ENV_MARKER person@example.test 2025550101'),
        new JsonException('PRIVATE_RECIPIENT_MARKER'), new Error('PRIVATE_DEPENDENCY_MARKER')] as $error) {
        $message = smsCoverageErrorMessage($error);
        expect($message)->not->toContain('PRIVATE_')->not->toContain('SELECT')->not->toContain('person@example.test')
            ->not->toContain('2025550101')->toContain('manually');
    }
});

it('restores the caller error handler and private creation mask after rejected options', function (): void {
    $mask = umask();
    $handler = static fn (): bool => true;
    set_error_handler($handler);
    try {
        expect(fn () => smsCoverageMain(['coverage', '--apply']))->toThrow(SmsProspectCoverageCliException::class);
        $actual = set_error_handler($handler);
        restore_error_handler();
        expect($actual)->toBe($handler)->and(umask())->toBe($mask);
    } finally { restore_error_handler(); }
});

it('allows missing optional lock and WAL files under the real main error handler', function (): void {
    $directory = str_replace('\\', '/', sys_get_temp_dir()).'/sms-coverage-optional-'.bin2hex(random_bytes(8));
    $checked = false;
    // A synchronous help-output callback runs while smsCoverageMain has its actual handler installed.
    ob_start(static function (string $chunk) use ($directory, &$checked): string {
        if (! $checked) {
            smsCoveragePrivateFile($directory.'/.audit.lock');
            smsCoveragePrivateFile($directory.'/coverage.sqlite-wal');
            smsCoveragePrivateFile($directory.'/coverage.sqlite-shm');
            $checked = true;
        }
        return '';
    }, 1);
    try { $status = smsCoverageMain(['coverage', '--help']); }
    finally { ob_end_clean(); }
    expect($status)->toBe(0)->and($checked)->toBeTrue()->and(file_exists($directory))->toBeFalse();
});

it('projects counters with saved scope bounds and never emits unreviewed state fields', function (): void {
    require_once dirname(__DIR__, 2).'/scripts/sms-prospect-coverage.php';
    $state = ['batches' => 2, 'after_pk' => 100, 'source_rows' => 10, 'exhausted' => false,
        'counts' => array_fill_keys(SmsProspectCoverage::COUNTERS, 0),
        'Email' => 'PRIVATE_RECIPIENT_MARKER', 'details' => ['Phone' => 'PRIVATE_PHONE_MARKER']];
    $manifest = ['source_watermark_pk' => 250, 'prospect_watermark_pk' => 75, 'metadata' => ['PRIVATE_METADATA_MARKER']];
    $output = smsCoverageCounters($state, $manifest);
    expect($output['source_watermark_pk'])->toBe(250)->and($output['prospect_watermark_pk'])->toBe(75)
        ->and($output['after_pk'])->toBe(100)->and($output['scope_complete'])->toBeFalse()
        ->and(json_encode($output))->not->toContain('PRIVATE_');
    $state['exhausted'] = true;
    expect(smsCoverageCounters($state, $manifest)['scope_complete'])->toBeTrue();
});
