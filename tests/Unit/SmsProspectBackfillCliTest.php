<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SmsProspectBackfillCliException;
use SmsProspectCoverage;
use SmsProspectReconciliation;
use Throwable;

require_once dirname(__DIR__, 2).'/scripts/sms-prospect-backfill-cli.php';

/** Local temporary artifacts and synthetic PDO replies only; never construct a source connection. */
final class SmsProspectBackfillCliTest extends TestCase
{
    private array $files = [];
    private array $directories = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->files) as $file) if (is_file($file) || is_link($file)) unlink($file);
        foreach (array_reverse($this->directories) as $directory) rmdir($directory);
        unset($GLOBALS['sms_backfill_cli_autoload_marker']);
    }

    private function directory(?string $path = null): string
    {
        $path ??= str_replace('\\', '/', realpath(sys_get_temp_dir())).'/sms-backfill-cli-'.bin2hex(random_bytes(8));
        mkdir($path, 0700); $this->directories[] = $path;
        return $path;
    }

    private function file(string $path, string $contents): void
    {
        file_put_contents($path, $contents); chmod($path, 0600); $this->files[] = $path;
    }

    private function runtime(string $directory): string
    {
        $root = $this->directory($directory.'/runtime');
        $this->directory($root.'/vendor');
        $this->file($root.'/vendor/autoload.php', '<?php $GLOBALS["sms_backfill_cli_autoload_marker"] = true;');
        $this->file($root.'/.env', "CMD_DB_HOST=invalid.example.test\nCMD_DB_DATABASE=other\nCMD_DB_USERNAME=PRIVATE_USER\nCMD_DB_PASSWORD=PRIVATE_PASSWORD\n");
        return $root;
    }

    private function artifact(bool $complete = true, bool $legacy = false): array
    {
        $directory = $this->directory();
        $manifest = ['format' => 1, 'read_only' => true, 'apply_supported' => false, 'batch_size' => 250,
            'source_watermark_pk' => 0, 'prospect_watermark_pk' => 20, 'source_guard' => str_repeat('a', 64),
            'source_rows' => 0, 'tool_sha256' => $legacy ? \SmsProspectBackfillPlan::LEGACY_CONFLICT_TOOL_SHA256 : \smsCoverageToolHash(), 'metadata' => [], 'limitations' => [],
            'created_utc' => '2026-10-20T00:00:00+00:00', 'metadata_only' => false];
        $this->file($directory.'/.audit.lock', '');
        $this->file($directory.'/manifest.json', SmsProspectReconciliation::json($manifest)."\n");
        $path = $directory.'/coverage.sqlite';
        foreach (['', '-wal', '-shm', '-journal'] as $suffix) $this->files[] = $path.$suffix;
        $journal = new PDO('sqlite:'.$path);
        SmsProspectCoverage::initialize($journal, $manifest);
        if ($complete) {
            $state = SmsProspectCoverage::state($journal, $manifest);
            $state['batches'] = 1; $state['exhausted'] = true;
            $payload = SmsProspectReconciliation::json($state);
            $journal->prepare('UPDATE state SET payload=? WHERE id=1')->execute([$payload]);
            $journal->prepare('INSERT INTO batches VALUES(1,0,0,0,?,?,?,?)')->execute([
                hash('sha256', '[]'), '2026-10-20T00:00:00+00:00', hash('sha256', $payload), $payload]);
        }
        $journal = null;
        chmod($path, 0600);
        return [$directory, $manifest];
    }

    private function closeAudit(array &$audit): void
    {
        $audit['journal'] = null;
        flock($audit['lock'], LOCK_UN); fclose($audit['lock']);
    }

    private function applyArgs(string $historyFlag = '--history-reviewed'): array
    {
        return ['--apply', '--run-id=00000000-0000-4000-8000-000000000001', '--actor=Local reviewer', $historyFlag,
            '--expect-plan-sha256='.str_repeat('a', 64), '--backup-checked-at='.gmdate('Y-m-d\TH:i:s\Z')];
    }

    public function test_default_is_read_only_with_bounded_controls_and_no_configuration_access(): void
    {
        $options = \smsBackfillOptions(['backfill', '--audit-dir=/private/audit']);
        self::assertFalse($options['apply']); self::assertFalse($options['install-ledger']);
        self::assertFalse($options['history-reviewed']); self::assertFalse($options['help']);
        self::assertFalse($options['confirmed-no-prior-sends']); self::assertFalse(\smsBackfillHistoryReviewed($options));
        self::assertFalse($options['hold-all-repeated']);
        self::assertSame(250, $options['batch-size']); self::assertSame(1, $options['max-batches']);
        self::assertSame($options['root'].'/.env', $options['env']);
        self::assertArrayNotHasKey('run-id', $options);
        self::assertFalse(isset($GLOBALS['sms_backfill_cli_autoload_marker']));
    }

    public function test_accepts_reviewed_write_options_without_io_and_keeps_install_separate(): void
    {
        $options = \smsBackfillOptions(['backfill', '--audit-dir=C:\\private\\audit', '--root=C:\\runtime',
            '--batch-size=1000', '--max-batches=0', ...$this->applyArgs()]);
        self::assertTrue($options['apply']); self::assertTrue($options['history-reviewed']);
        self::assertSame('C:/private/audit', $options['audit-dir']); self::assertSame('C:/runtime', $options['root']);
        self::assertSame(1000, $options['batch-size']); self::assertSame(0, $options['max-batches']);
        $install = \smsBackfillOptions(['backfill', '--audit-dir=/private/audit', '--install-ledger',
            '--expect-plan-sha256='.str_repeat('b', 64), '--backup-checked-at='.gmdate('Y-m-d\TH:i:s\Z')]);
        self::assertFalse($install['apply']); self::assertTrue($install['install-ledger']); self::assertFalse($install['history-reviewed']);
    }

    public function test_legacy_hold_choice_is_required_and_bound_to_the_frozen_proof_and_report(): void
    {
        [$directory] = $this->artifact(true, true);
        $options = \smsBackfillOptions(['backfill','--audit-dir='.$directory,'--hold-all-repeated','--confirmed-no-prior-sends']);
        self::assertTrue($options['hold-all-repeated']);
        $audit = \smsBackfillOpenAudit($options);
        try {
            self::assertSame(2, $audit['proof']['format']);
            self::assertFalse($audit['proof']['conflict_counts_verified']);
            \smsBackfillLoadWriter();
            $report = \smsBackfillReport($audit, $options, ['azure_headroom_known' => false, 'estimated_bytes' => 0], [], true);
            self::assertFalse($report['scope_complete']);
            self::assertTrue($report['candidate_scope_complete']);
            self::assertFalse($report['conflict_counts_verified']);
            self::assertSame(0, $report['excluded_repeated_people']);
            self::assertArrayNotHasKey('excluded_conflicts', $report);
            self::assertArrayNotHasKey('excluded_repeated_nonconflicting', $report);
            self::assertSame(hash('sha256', \SmsProspectReconciliation::json($audit['proof'])), $audit['plan_sha256']);
        } finally { $this->closeAudit($audit); }
        if (\smsCoverageToolHash() !== \SmsProspectBackfillPlan::LEGACY_CONFLICT_TOOL_SHA256) {
            $this->expectException(Throwable::class);
            \smsBackfillOpenAudit(\smsBackfillOptions(['backfill','--audit-dir='.$directory,'--confirmed-no-prior-sends']));
        }
    }

    public static function invocationModes(): array
    {
        return [['plan', false], ['plan', true], ['install-ledger', false], ['install-ledger', true], ['apply', false], ['apply', true]];
    }

    #[DataProvider('invocationModes')]
    public function test_exact_legacy_mode_must_be_explicit_for_every_invocation_and_plan_hash(string $mode, bool $confirmed): void
    {
        [$directory] = $this->artifact(true, true);
        $args = $mode === 'apply' ? $this->applyArgs($confirmed ? '--confirmed-no-prior-sends' : '--history-reviewed')
            : ($mode === 'install-ledger' ? ['--install-ledger','--expect-plan-sha256='.str_repeat('a',64),
                '--backup-checked-at='.gmdate('Y-m-d\TH:i:s\Z')] : []);
        if ($confirmed && $mode !== 'apply') $args[] = '--confirmed-no-prior-sends';
        $args = array_values(array_filter($args, fn ($arg) => ! str_starts_with($arg, '--expect-plan-sha256=')));
        $options = \smsBackfillOptions(['backfill','--audit-dir='.$directory,'--hold-all-repeated',
            ...($confirmed ? ['--confirmed-no-prior-sends'] : [])]);
        $audit = \smsBackfillOpenAudit($options);
        $hash = $audit['plan_sha256']; $this->closeAudit($audit);
        $options = \smsBackfillOptions(['backfill','--audit-dir='.$directory,'--hold-all-repeated',...$args,'--expect-plan-sha256='.$hash]);
        $audit = \smsBackfillOpenAudit($options);
        self::assertSame(2, $audit['proof']['format']);
        self::assertSame($hash, $audit['plan_sha256']); $this->closeAudit($audit);
        $options['expect-plan-sha256'] = str_repeat('0',64);
        try { \smsBackfillOpenAudit($options); self::fail('Wrong reviewed plan hash accepted.'); }
        catch (SmsProspectBackfillCliException) { self::assertTrue(true); }
        unset($options['expect-plan-sha256']); $options['hold-all-repeated'] = false;
        $this->expectException(SmsProspectBackfillCliException::class);
        \smsBackfillOpenAudit($options);
    }

    public function test_explicit_hold_is_not_a_bypass_for_an_unreviewed_tool_hash(): void
    {
        [$directory, $manifest] = $this->artifact();
        $manifest['tool_sha256'] = str_repeat('f',64);
        file_put_contents($directory.'/manifest.json', SmsProspectReconciliation::json($manifest));
        $this->expectException(SmsProspectBackfillCliException::class);
        \smsBackfillOpenAudit(\smsBackfillOptions(['backfill','--audit-dir='.$directory,'--hold-all-repeated']));
    }

    #[DataProvider('invocationModes')]
    public function test_history_mode_is_explicit_hash_bound_and_reported_for_plan_install_and_apply(string $mode, bool $confirmed): void
    {
        [$directory] = $this->artifact();
        $args = $mode === 'apply' ? $this->applyArgs($confirmed ? '--confirmed-no-prior-sends' : '--history-reviewed')
            : ($mode === 'install-ledger' ? ['--install-ledger', '--expect-plan-sha256='.str_repeat('a', 64),
                '--backup-checked-at='.gmdate('Y-m-d\TH:i:s\Z')] : []);
        if ($confirmed && $mode !== 'apply') $args[] = '--confirmed-no-prior-sends';
        $args = array_values(array_filter($args, fn ($arg) => ! str_starts_with($arg, '--expect-plan-sha256=')));
        $planOptions = \smsBackfillOptions(['backfill', '--audit-dir='.$directory, ...($confirmed ? ['--confirmed-no-prior-sends'] : [])]);
        $audit = \smsBackfillOpenAudit($planOptions);
        try {
            $options = \smsBackfillOptions(['backfill', '--audit-dir='.$directory, ...$args,
                '--expect-plan-sha256='.$audit['plan_sha256']]);
            $reviewed = $confirmed || $mode === 'apply';
            self::assertSame($confirmed, $options['confirmed-no-prior-sends']);
            self::assertSame($mode === 'apply' && ! $confirmed, $options['history-reviewed']);
            self::assertSame($reviewed, \smsBackfillHistoryReviewed($options));
            $same = \smsBackfillOpenAudit($options);
            try { self::assertSame($audit['proof'], $same['proof']); self::assertSame($audit['plan_sha256'], $same['plan_sha256']); }
            finally { $this->closeAudit($same); }
            \smsBackfillLoadWriter();
            $report = \smsBackfillReport($audit, $options, ['azure_headroom_known' => false, 'estimated_bytes' => 0]);
            self::assertSame($confirmed ? 'confirmed-no-prior-sends-v1' : 'verified-events-v1', $report['history_policy']);
            self::assertSame($audit['proof']['history_policy'], $report['history_policy']);
            self::assertSame($confirmed, $report['confirmed_no_prior_sends']);
            self::assertSame($reviewed, $report['history_reviewed']); self::assertSame(! $reviewed, $report['history_needs_review']);
            self::assertSame($mode === 'plan', $report['read_only']);
            self::assertFalse($report['candidate_scope_complete']); self::assertFalse($report['scope_complete']);
            self::assertFalse(isset($GLOBALS['sms_backfill_cli_autoload_marker']));
        } finally { $this->closeAudit($audit); }
    }

    public function test_history_attestation_requires_boolean_true_and_never_infers_no_prior_sends(): void
    {
        self::assertFalse(\smsBackfillHistoryReviewed([]));
        foreach ([false, null, 0, 1, 'true', 'false'] as $value) {
            self::assertFalse(\smsBackfillHistoryReviewed(['history-reviewed' => $value, 'confirmed-no-prior-sends' => $value]));
        }
        self::assertTrue(\smsBackfillHistoryReviewed(['history-reviewed' => true]));
        self::assertTrue(\smsBackfillHistoryReviewed(['confirmed-no-prior-sends' => true]));
    }

    #[DataProvider('invocationModes')]
    public function test_source_session_uses_read_committed_for_writes_and_snapshot_for_read_only_plan(string $mode, bool $confirmed): void
    {
        $args = $mode === 'apply' ? $this->applyArgs($confirmed ? '--confirmed-no-prior-sends' : '--history-reviewed')
            : ($mode === 'install-ledger' ? ['--install-ledger', '--expect-plan-sha256='.str_repeat('a', 64),
                '--backup-checked-at='.gmdate('Y-m-d\TH:i:s\Z')] : []);
        if ($confirmed && $mode !== 'apply') $args[] = '--confirmed-no-prior-sends';
        $options = \smsBackfillOptions(['backfill', '--audit-dir=/private/audit', ...$args]);
        $source = new SmsBackfillCliStoragePdo(null);
        \smsBackfillConfigureSource($source, $options);
        self::assertSame('SET LOCK_TIMEOUT 5000; SET DEADLOCK_PRIORITY LOW; SET TRANSACTION ISOLATION LEVEL '
            .($mode === 'plan' ? 'SNAPSHOT' : 'READ COMMITTED'), $source->sql);
        self::assertStringNotContainsString('READ UNCOMMITTED', $source->sql);
        self::assertStringNotContainsString('NOLOCK', $source->sql);
    }

    public function test_apply_requires_either_explicit_history_attestation_before_audit_or_runtime_loading(): void
    {
        $args = array_values(array_filter($this->applyArgs(), fn ($arg) => $arg !== '--history-reviewed'));
        $this->expectException(SmsProspectBackfillCliException::class);
        $this->expectExceptionMessage('Invalid backfill options');
        \smsBackfillMain(['backfill', '--audit-dir=/missing-audit', '--root=/missing-runtime', ...$args]);
    }

    public static function malformedOptions(): array
    {
        $args = ['--', '-h', 'customer@example.test', '--unknown', '--delete', '--rollback', '--resume', '--out=/private/audit',
            '--help=', '--apply=true', '--install-ledger=false', '--history-reviewed=true', '--hold-all-repeated=true',
            '--hold-all-repeated=false', '--hold-all-repeated=', '--confirmed-no-prior-sends=true',
            '--confirmed-no-prior-sends=false', '--confirmed-no-prior-sends=', '--root=', '--env=', '--audit-dir=',
            '--batch-size', '--batch-size=0', '--batch-size=1001', '--batch-size=-1', '--batch-size=1.5', '--batch-size=1e2',
            '--batch-size=+1', '--batch-size= 1', '--max-batches=-1', '--max-batches=1.0', '--max-batches=18446744073709551616',
            '--max-batches='.str_repeat('9', 30), '--run-id=invalid', '--actor= ', '--actor='.str_repeat('a', 256),
            '--actor=PRIVATE_MARKER'."\n", '--expect-plan-sha256='.str_repeat('A', 64), '--expect-plan-sha256='.str_repeat('a', 63),
            '--expect-plan-sha256='.str_repeat('g', 64), '--backup-checked-at=2026-02-30T00:00:00Z',
            '--backup-checked-at=2026-10-20T00:00:00+00:00', '--backup-checked-at=not-a-date'];
        return array_map(fn ($arg) => [$arg], $args);
    }

    #[DataProvider('malformedOptions')]
    public function test_malformed_arguments_are_rejected_even_with_help_before_any_configuration(string $arg): void
    {
        ob_start();
        try {
            \smsBackfillMain(['backfill', '--help', '--audit-dir=/private/audit', '--root=/missing-runtime', $arg]);
            self::fail('Malformed argument accepted.');
        } catch (SmsProspectBackfillCliException $error) {
            self::assertStringNotContainsString('PRIVATE_', $error->getMessage());
            self::assertStringNotContainsString('customer@example.test', $error->getMessage());
            self::assertStringNotContainsString('/missing-runtime', $error->getMessage());
            self::assertSame('', ob_get_contents());
        } finally { ob_end_clean(); }
    }

    public static function unsafePaths(): array
    {
        $cases = [];
        foreach (['root', 'env', 'audit-dir'] as $option) {
            foreach (['relative/file', '/private/../audit', '/private/./audit', '/private/audit/..', 'file:///private/audit',
                'php://filter', '//server/share', '\\\\server\\share', '/private//audit', '/private/audit:stream', '/private/audit'."\0"] as $path) $cases[] = [$option, $path];
        }
        return $cases;
    }

    #[DataProvider('unsafePaths')]
    public function test_rejects_unsafe_paths_purely(string $option, string $path): void
    {
        $this->expectException(SmsProspectBackfillCliException::class);
        \smsBackfillOptions(['backfill', '--help', '--'.$option.'='.$path]);
    }

    public static function duplicateOptions(): array
    {
        return array_map(fn ($arg) => [$arg], ['--help', '--apply', '--install-ledger', '--history-reviewed', '--confirmed-no-prior-sends', '--root=/private',
            '--env=/private/env', '--audit-dir=/private/audit', '--run-id=00000000-0000-4000-8000-000000000001',
            '--actor=Reviewer', '--batch-size=250', '--max-batches=1', '--expect-plan-sha256='.str_repeat('a', 64),
            '--backup-checked-at=2026-10-20T00:00:00Z']);
    }

    #[DataProvider('duplicateOptions')]
    public function test_rejects_duplicate_values_and_flags(string $arg): void
    {
        $this->expectException(SmsProspectBackfillCliException::class);
        \smsBackfillOptions(['backfill', '--help', $arg, $arg]);
    }

    public function test_requires_explicit_audit_and_write_approvals_and_exclusive_modes(): void
    {
        foreach ([[], ['--apply'], ['--install-ledger'], ['--history-reviewed'], ['--apply', '--install-ledger']] as $args) {
            try {
                \smsBackfillOptions(['backfill', ...$args]);
                self::fail('Missing approval or mode conflict accepted.');
            } catch (SmsProspectBackfillCliException) { self::assertTrue(true); }
        }
    }

    public function test_history_attestations_are_mutually_exclusive_even_with_help_and_write_approvals(): void
    {
        foreach ([[], ['--help'], ['--install-ledger'], $this->applyArgs('--confirmed-no-prior-sends')] as $args) {
            if (! in_array('--confirmed-no-prior-sends', $args, true)) $args[] = '--confirmed-no-prior-sends';
            $args[] = '--history-reviewed';
            ob_start();
            try {
                \smsBackfillMain(['backfill', '--audit-dir=/missing-audit', '--root=/missing-runtime', ...$args]);
                self::fail('Conflicting history attestations accepted.');
            } catch (SmsProspectBackfillCliException $error) {
                self::assertStringContainsString('Invalid backfill options', $error->getMessage());
                self::assertFalse(isset($GLOBALS['sms_backfill_cli_autoload_marker'])); self::assertSame('', ob_get_contents());
            } finally { ob_end_clean(); }
        }
    }

    public function test_no_prior_sends_confirmation_does_not_replace_write_approvals(): void
    {
        foreach (['apply', 'install-ledger'] as $mode) {
            $args = $this->applyArgs('--confirmed-no-prior-sends');
            if ($mode === 'install-ledger') $args = array_map(fn ($arg) => $arg === '--apply' ? '--install-ledger' : $arg, $args);
            $required = ['expect-plan-sha256', 'backup-checked-at', ...($mode === 'apply' ? ['run-id', 'actor'] : [])];
            foreach ($required as $key) {
                $missing = array_values(array_filter($args, fn ($arg) => ! str_starts_with($arg, '--'.$key.'=')));
                try {
                    \smsBackfillOptions(['backfill', '--audit-dir=/missing-audit', ...$missing]);
                    self::fail('Confirmation replaced required write approval.');
                } catch (SmsProspectBackfillCliException $error) { self::assertStringContainsString('Invalid backfill options', $error->getMessage()); }
            }
        }
    }

    public function test_help_only_loads_definitions_and_restores_error_handler_and_mask(): void
    {
        $included = get_included_files(); $mask = umask();
        $handler = static fn (): bool => true;
        set_error_handler($handler); ob_start();
        try {
            self::assertSame(0, \smsBackfillMain(['backfill', '--help', '--root=/missing-runtime']));
            $output = ob_get_contents();
            self::assertStringContainsString('--history-reviewed', $output);
            self::assertStringContainsString('--confirmed-no-prior-sends', $output);
            self::assertStringContainsString('no prior actual SMS sends ever', $output);
            self::assertStringContainsString('an export is not a sent campaign', $output);
            self::assertStringContainsString('plan/install/apply', $output);
            self::assertStringContainsString('mutually exclusive', $output);
            self::assertStringContainsString('hash binds the history choice', $output);
            self::assertStringContainsString('empty actual-send ledger throughout the run', $output);
            self::assertStringContainsString('closed, completed audit, resource checks and backup approval', $output);
            self::assertStringContainsString('before the first write only', $output);
            self::assertStringContainsString('8 GiB', $output);
            self::assertSame($included, get_included_files()); self::assertSame($mask, umask());
            $actual = set_error_handler($handler); restore_error_handler();
            self::assertSame($handler, $actual);
        } finally { ob_end_clean(); restore_error_handler(); }
    }

    public static function invalidBackupChecks(): array
    {
        return [['2026-10-20T10:59:59Z'], ['2026-10-20T12:01:01Z'], ['2026-10-20T12:00:00+00:00'],
            ['2026-10-20T12:00:00.000Z'], ['2026-10-20t12:00:00z'], ['2026-02-30T12:00:00Z'], ['2026-10-20T12:00:60Z']];
    }

    #[DataProvider('invalidBackupChecks')]
    public function test_backup_validation_has_strict_utc_calendar_and_freshness_bounds(string $value): void
    {
        $this->expectException(SmsProspectBackfillCliException::class);
        \smsBackfillBackup($value, strtotime('2026-10-20T12:00:00Z'));
    }

    public function test_backup_accepts_exact_one_hour_old_and_sixty_second_future_boundaries(): void
    {
        $now = strtotime('2026-10-20T12:00:00Z');
        foreach (['2026-10-20T11:00:00Z', '2026-10-20T12:00:00Z', '2026-10-20T12:01:00Z'] as $value) \smsBackfillBackup($value, $now);
        $this->addToAssertionCount(3);
    }

    public static function confirmationModes(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('confirmationModes')]
    public function test_missing_and_incomplete_audits_fail_before_runtime_autoload_without_outputs(bool $confirmed): void
    {
        [$directory] = $this->artifact(false); $root = $this->runtime($directory);
        foreach ([$directory.'/missing', $directory] as $path) {
            ob_start();
            try {
                \smsBackfillMain(['backfill', '--audit-dir='.$path, '--root='.$root, ...($confirmed ? ['--confirmed-no-prior-sends'] : [])]);
                self::fail('Missing or incomplete audit accepted.');
            } catch (SmsProspectBackfillCliException) {
                self::assertFalse(isset($GLOBALS['sms_backfill_cli_autoload_marker']));
                self::assertSame('', ob_get_contents());
            } finally { ob_end_clean(); }
        }
    }

    #[DataProvider('confirmationModes')]
    public function test_closed_artifact_is_read_only_hash_bound_and_has_no_new_sidecars(bool $confirmed): void
    {
        [$directory] = $this->artifact();
        $before = scandir($directory); $hash = hash_file('sha256', $directory.'/coverage.sqlite');
        $metadata = [];
        foreach (['', '/.audit.lock', '/manifest.json', '/coverage.sqlite'] as $suffix) {
            clearstatcache(true, $directory.$suffix);
            $metadata[$suffix] = array_intersect_key(lstat($directory.$suffix), array_flip(['dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'size', 'mtime', 'ctime']));
        }
        // Cross the stat() timestamp boundary so a redundant chmod would be observable on Linux.
        if (PHP_OS_FAMILY === 'Linux') usleep(1100000);
        $audit = \smsBackfillOpenAudit(\smsBackfillOptions(['backfill', '--audit-dir='.$directory, ...($confirmed ? ['--confirmed-no-prior-sends'] : [])]));
        try {
            self::assertSame($hash, $audit['proof']['artifact_sha256']);
            self::assertSame(1, $audit['journal']->query('PRAGMA query_only')->fetchColumn());
            self::assertSame(hash('sha256', SmsProspectReconciliation::json($audit['proof'])), $audit['plan_sha256']);
            self::assertSame(\smsBackfillWriterToolHash(), $audit['proof']['writer_tool_sha256']);
            self::assertSame($confirmed ? 'confirmed-no-prior-sends-v1' : 'verified-events-v1', $audit['proof']['history_policy']);
            \smsBackfillArtifactUnchanged($audit);
            $audit['journal']->exec('PRAGMA query_only=OFF');
            try { $audit['journal']->exec('DELETE FROM identities'); self::fail('Read-only artifact accepted a write.'); }
            catch (PDOException) { self::assertTrue(true); }
        } finally { $this->closeAudit($audit); }
        self::assertSame($before, scandir($directory)); self::assertSame($hash, hash_file('sha256', $directory.'/coverage.sqlite'));
        foreach ($metadata as $suffix => $expected) {
            clearstatcache(true, $directory.$suffix);
            // Reading may update atime according to mount policy; no permission/ctime/mtime changes are allowed.
            self::assertSame($expected, array_intersect_key(lstat($directory.$suffix), $expected));
        }
    }

    public static function invalidManifestFields(): array
    {
        return [['format', '1'], ['read_only', false], ['apply_supported', true], ['tool_sha256', str_repeat('0', 64)],
            ['source_guard', str_repeat('g', 64)], ['batch_size', '250'], ['batch_size', 0], ['batch_size', 1001],
            ['metadata', 'PRIVATE_METADATA'], ['limitations', 'PRIVATE_LIMITATIONS'], ['created_utc', 123],
            ['metadata_only', true], ['metadata_only', 0], ['source_watermark_pk', '0'], ['source_watermark_pk', 2147483648],
            ['prospect_watermark_pk', -1], ['prospect_watermark_pk', '20'], ['source_rows', '0'], ['source_rows', -1]];
    }

    #[DataProvider('invalidManifestFields')]
    public function test_local_manifest_reader_preserves_existing_helper_validation_without_printing_data(string $field, mixed $value): void
    {
        [$directory, $manifest] = $this->artifact(); $root = $this->runtime($directory);
        $manifest[$field] = $value;
        file_put_contents($directory.'/manifest.json', SmsProspectReconciliation::json($manifest));
        ob_start();
        try {
            \smsBackfillMain(['backfill', '--audit-dir='.$directory, '--root='.$root]);
            self::fail('Malformed manifest accepted.');
        } catch (SmsProspectBackfillCliException $error) {
            self::assertStringNotContainsString('PRIVATE_', $error->getMessage());
            self::assertFalse(isset($GLOBALS['sms_backfill_cli_autoload_marker'])); self::assertSame('', ob_get_contents());
        } finally { ob_end_clean(); }
    }

    public function test_manifest_read_is_bounded_and_hashes_the_exact_original_bytes(): void
    {
        [$directory, $manifest] = $this->artifact();
        $hash = null;
        self::assertSame($manifest, \smsBackfillReadManifest($directory.'/manifest.json', $hash));
        self::assertSame(hash_file('sha256', $directory.'/manifest.json'), $hash);
        file_put_contents($directory.'/manifest.json', str_repeat(' ', 2 * 1024 * 1024 + 1));
        $this->expectException(SmsProspectBackfillCliException::class);
        \smsBackfillReadManifest($directory.'/manifest.json');
    }

    public function test_tool_fingerprint_and_execution_bind_the_same_local_values_and_writer_dependencies(): void
    {
        \smsBackfillLoadWriter();
        $root = dirname(__DIR__, 2); $hashes = \smsCoverageToolHash();
        foreach (['scripts/sms-prospect-backfill-cli.php', 'scripts/sms-prospect-backfill-plan.php',
            'src/Services/SmsProspectBackfill.php', 'src/Services/SmsProspectValues.php'] as $path) $hashes .= hash_file('sha256', $root.'/'.$path);
        self::assertSame(hash('sha256', $hashes), \smsBackfillWriterToolHash());
        foreach (['SmsProspectValues', 'SmsProspectBackfill'] as $name) {
            $class = 'Cmd\\Reports\\Services\\'.$name;
            self::assertSame(realpath($root.'/src/Services/'.$name.'.php'), realpath((new \ReflectionClass($class))->getFileName()));
        }
    }

    public static function blockedArtifacts(): array
    {
        $cases = [];
        foreach (['wal', 'journal', 'manifest', 'lock', 'active', 'tool-hash', 'metadata-only'] as $fault) {
            foreach ([false, true] as $confirmed) $cases[] = [$fault, $confirmed];
        }
        return $cases;
    }

    #[DataProvider('blockedArtifacts')]
    public function test_opening_rejects_active_unclosed_missing_or_wrong_tool_artifacts(string $fault, bool $confirmed): void
    {
        [$directory, $manifest] = $this->artifact();
        $lock = null;
        if ($fault === 'wal' || $fault === 'journal') $this->file($directory.'/coverage.sqlite-'.$fault, 'uncheckpointed');
        if ($fault === 'manifest') unlink($directory.'/manifest.json');
        if ($fault === 'lock') unlink($directory.'/.audit.lock');
        if ($fault === 'active') { $lock = fopen($directory.'/.audit.lock', 'r+b'); self::assertTrue(flock($lock, LOCK_EX | LOCK_NB)); }
        if ($fault === 'tool-hash' || $fault === 'metadata-only') {
            if ($fault === 'tool-hash') $manifest['tool_sha256'] = str_repeat('0', 64);
            else $manifest['metadata_only'] = true;
            file_put_contents($directory.'/manifest.json', SmsProspectReconciliation::json($manifest));
        }
        try {
            $this->expectException(Throwable::class);
            \smsBackfillOpenAudit(\smsBackfillOptions(['backfill', '--audit-dir='.$directory, ...($confirmed ? ['--confirmed-no-prior-sends'] : [])]));
        } finally { if ($lock !== null) { flock($lock, LOCK_UN); fclose($lock); } }
    }

    public function test_mismatched_review_hash_fails_before_runtime_and_releases_the_lock(): void
    {
        [$directory] = $this->artifact(); $root = $this->runtime($directory);
        try {
            \smsBackfillMain(['backfill', '--audit-dir='.$directory, '--root='.$root, '--expect-plan-sha256='.str_repeat('0', 64)]);
            self::fail('Changed plan accepted.');
        } catch (SmsProspectBackfillCliException) { self::assertFalse(isset($GLOBALS['sms_backfill_cli_autoload_marker'])); }
        $lock = fopen($directory.'/.audit.lock', 'rb');
        try { self::assertTrue(flock($lock, LOCK_EX | LOCK_NB)); }
        finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    public function test_artifact_changes_after_open_are_rejected(): void
    {
        [$directory] = $this->artifact();
        $audit = \smsBackfillOpenAudit(\smsBackfillOptions(['backfill', '--audit-dir='.$directory]));
        try {
            file_put_contents($directory.'/coverage.sqlite', 'changed', FILE_APPEND);
            $this->expectException(SmsProspectBackfillCliException::class);
            \smsBackfillArtifactUnchanged($audit);
        } finally { $this->closeAudit($audit); }
    }

    public function test_history_choice_is_the_only_proof_difference_and_cannot_change_after_hashing(): void
    {
        [$directory] = $this->artifact();
        $verified = \smsBackfillOpenAudit(\smsBackfillOptions(['backfill', '--audit-dir='.$directory]));
        try { $proof = $verified['proof']; $hash = $verified['plan_sha256']; }
        finally { $this->closeAudit($verified); }
        $confirmed = \smsBackfillOpenAudit(\smsBackfillOptions(['backfill', '--audit-dir='.$directory, '--confirmed-no-prior-sends']));
        try {
            self::assertSame('verified-events-v1', $proof['history_policy']);
            $proof['history_policy'] = 'confirmed-no-prior-sends-v1';
            self::assertSame($proof, $confirmed['proof']);
            self::assertNotSame($hash, $confirmed['plan_sha256']);
            self::assertSame(hash('sha256', SmsProspectReconciliation::json($confirmed['proof'])), $confirmed['plan_sha256']);
            $confirmed['proof']['history_policy'] = 'verified-events-v1';
            $this->expectException(SmsProspectBackfillCliException::class);
            \smsBackfillArtifactUnchanged($confirmed);
        } finally { $this->closeAudit($confirmed); }
    }

    #[DataProvider('invocationModes')]
    public function test_switching_history_mode_invalidates_review_before_runtime_and_releases_lock(string $mode, bool $confirmed): void
    {
        [$directory] = $this->artifact(); $root = $this->runtime($directory);
        $audit = \smsBackfillOpenAudit(\smsBackfillOptions(['backfill', '--audit-dir='.$directory, ...($confirmed ? [] : ['--confirmed-no-prior-sends'])]));
        $hash = $audit['plan_sha256']; $this->closeAudit($audit);
        $args = $mode === 'apply' ? $this->applyArgs($confirmed ? '--confirmed-no-prior-sends' : '--history-reviewed')
            : ($mode === 'install-ledger' ? ['--install-ledger', '--backup-checked-at='.gmdate('Y-m-d\TH:i:s\Z')] : []);
        if ($confirmed && $mode !== 'apply') $args[] = '--confirmed-no-prior-sends';
        $args = array_values(array_filter($args, fn ($arg) => ! str_starts_with($arg, '--expect-plan-sha256=')));
        ob_start();
        try {
            \smsBackfillMain(['backfill', '--audit-dir='.$directory, '--root='.$root, ...$args, '--expect-plan-sha256='.$hash]);
            self::fail('A plan reviewed under a different history policy was accepted.');
        } catch (SmsProspectBackfillCliException $error) {
            self::assertStringContainsString('exact reviewed plan hash', $error->getMessage());
            self::assertFalse(isset($GLOBALS['sms_backfill_cli_autoload_marker'])); self::assertSame('', ob_get_contents());
        } finally { ob_end_clean(); }
        $lock = fopen($directory.'/.audit.lock', 'rb');
        try { self::assertTrue(flock($lock, LOCK_EX | LOCK_NB)); }
        finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    public function test_plan_hash_does_not_depend_on_runtime_actor_run_or_batch_options(): void
    {
        [$directory] = $this->artifact();
        $audit = \smsBackfillOpenAudit(\smsBackfillOptions(['backfill', '--audit-dir='.$directory]));
        $hash = $audit['plan_sha256']; $proof = $audit['proof']; $this->closeAudit($audit);
        $args = array_values(array_filter($this->applyArgs(), fn ($arg) => ! str_starts_with($arg, '--expect-plan-sha256=')));
        $audit = \smsBackfillOpenAudit(\smsBackfillOptions(['backfill', '--audit-dir='.$directory, '--root=/different/runtime',
            '--batch-size=1', '--max-batches=0', '--expect-plan-sha256='.$hash, ...$args]));
        try {
            self::assertSame($hash, $audit['plan_sha256']); self::assertSame($proof, $audit['proof']);
            self::assertStringNotContainsString('Local reviewer', json_encode($proof));
        } finally { $this->closeAudit($audit); }
    }

    public function test_completed_audit_reaches_pinned_configuration_check_without_any_source_connection(): void
    {
        [$directory] = $this->artifact(); $root = $this->runtime($directory);
        ob_start();
        try {
            \smsBackfillMain(['backfill', '--audit-dir='.$directory, '--root='.$root]);
            self::fail('Invalid host configuration accepted.');
        } catch (SmsProspectBackfillCliException $error) {
            self::assertTrue($GLOBALS['sms_backfill_cli_autoload_marker'] ?? false);
            self::assertStringNotContainsString('PRIVATE_', $error->getMessage());
            self::assertStringNotContainsString($root, $error->getMessage());
            self::assertSame('', ob_get_contents());
        } finally { ob_end_clean(); }
    }

    public function test_error_projection_redacts_all_untrusted_exception_messages(): void
    {
        foreach ([new PDOException('PRIVATE_SQL SELECT Phone,Email'), new RuntimeException('/private/path customer@example.test'),
            new \Error('PRIVATE_DEPENDENCY'), new \SmsProspectCoverageCliException('PRIVATE_CONFIGURATION'),
            new SmsProspectBackfillCliException('PRIVATE_CUSTOM_MESSAGE')] as $error) {
            $message = \smsBackfillErrorMessage($error);
            self::assertStringNotContainsString('PRIVATE_', $message); self::assertStringNotContainsString('SELECT', $message);
            self::assertStringNotContainsString('customer@example.test', $message); self::assertStringContainsString('manually', $message);
        }
    }

    public function test_numeric_report_excludes_identity_and_never_claims_population_completion_with_exclusions(): void
    {
        \smsBackfillLoadWriter();
        $audit = ['plan_sha256' => str_repeat('a', 64), 'proof' => ['candidate_count' => 3, 'candidate_max_id' => 9,
            'source_watermark_pk' => 50, 'prospect_watermark_pk' => 20, 'excluded_conflicts' => 1,
            'excluded_repeated_nonconflicting' => 2, 'Client' => 'PRIVATE_CUSTOMER']];
        $options = \smsBackfillOptions(['backfill', '--audit-dir=/private/audit']);
        $output = \smsBackfillReport($audit, $options, ['azure_headroom_known' => false, 'estimated_bytes' => 6144],
            ['checked' => 3, 'actor' => 'PRIVATE_ACTOR', 'run_id' => 'PRIVATE_RUN', 'Email' => 'PRIVATE_EMAIL'], true);
        self::assertTrue($output['candidate_scope_complete']); self::assertFalse($output['scope_complete']);
        self::assertTrue($output['history_needs_review']); self::assertNull($output['azure_available_bytes']);
        self::assertSame('verified-events-v1', $output['history_policy']);
        self::assertFalse($output['confirmed_no_prior_sends']); self::assertFalse($output['history_reviewed']);
        self::assertStringNotContainsString('PRIVATE_', json_encode($output));
        self::assertSame(3, $output['checked']);
    }

    public function test_storage_uses_data_file_used_pages_and_unknown_metadata_stays_unknown(): void
    {
        $source = new SmsBackfillCliStoragePdo(['used_bytes' => '549278056448', 'max_bytes' => '805306368000', 'data_files' => 1, 'unknown_files' => 0]);
        self::assertSame(['azure_headroom_known' => true, 'estimated_bytes' => 20480, 'azure_available_bytes' => 256028311552], \smsBackfillStorage($source, 10));
        self::assertStringContainsString('sys.database_files', $source->sql);
        self::assertStringContainsString("FILEPROPERTY(name,'SpaceUsed')", $source->sql);
        foreach ([[], ['used_bytes' => null], ['max_bytes' => 0], ['unknown_files' => 1], ['data_files' => 0], ['used_bytes' => 'bad']] as $changes) {
            $row = $changes + ['used_bytes' => 10, 'max_bytes' => 100, 'data_files' => 1, 'unknown_files' => 0];
            if ($changes === []) $row = [];
            self::assertFalse(\smsBackfillStorage(new SmsBackfillCliStoragePdo($row), 1)['azure_headroom_known']);
        }
        self::assertFalse(\smsBackfillStorage(new SmsBackfillCliStoragePdo(null), 1)['azure_headroom_known']);
    }

    public function test_checkpoint_requires_matching_history_and_reconciled_committed_counts(): void
    {
        $proof = ['candidate_count' => 3, 'candidate_max_id' => 9];
        $state = ['after_id' => 5, 'batches' => 1, 'checked' => 2, 'added' => 1, 'existing' => 0, 'deferred' => 1,
            'history_reviewed' => true, 'history_policy' => 'verified-events-v1', 'status' => 'ready'];
        self::assertSame($state, \smsBackfillCheckpoint($state, $proof, true));
        foreach ([['checked' => 3], ['after_id' => 10], ['history_reviewed' => false], ['status' => 'unreviewed'], ['added' => '1']] as $changes) {
            try { \smsBackfillCheckpoint($changes + $state, $proof, true); self::fail('Invalid checkpoint accepted.'); }
            catch (SmsProspectBackfillCliException) { self::assertTrue(true); }
        }
    }

    public function test_checkpoint_accepts_each_attested_policy_and_defaults_only_legacy_proof_to_verified_events(): void
    {
        $proof = ['candidate_count' => 3, 'candidate_max_id' => 9];
        $state = ['after_id' => 5, 'batches' => 1, 'checked' => 2, 'added' => 1, 'existing' => 0, 'deferred' => 1,
            'history_reviewed' => true, 'status' => 'ready'];
        foreach (['verified-events-v1', 'confirmed-no-prior-sends-v1'] as $policy) {
            $checkpoint = ['history_policy' => $policy] + $state;
            self::assertSame($checkpoint, \smsBackfillCheckpoint($checkpoint, ['history_policy' => $policy] + $proof, true));
            self::assertSame(1, \smsBackfillRemaining($checkpoint, ['history_policy' => $policy] + $proof, true));
        }
        $checkpoint = ['history_policy' => 'verified-events-v1', 'history_reviewed' => false] + $state;
        self::assertSame($checkpoint, \smsBackfillCheckpoint($checkpoint, $proof, false));
    }

    public static function incompatibleCheckpointPolicies(): array
    {
        return [
            ['verified-events-v1', 'confirmed-no-prior-sends-v1', true],
            ['confirmed-no-prior-sends-v1', 'verified-events-v1', true],
            [null, 'confirmed-no-prior-sends-v1', true],
            ['verified-events-v1', null, true], ['confirmed-no-prior-sends-v1', null, true],
            ['unknown', 'unknown', true], ['unknown', 'verified-events-v1', true],
            ['confirmed-no-prior-sends-v1', 'confirmed-no-prior-sends-v1', false],
        ];
    }

    #[DataProvider('incompatibleCheckpointPolicies')]
    public function test_resume_rejects_missing_unknown_changed_or_unattested_checkpoint_policy(?string $proofPolicy, ?string $statePolicy, bool $reviewed): void
    {
        $proof = ['candidate_count' => 3, 'candidate_max_id' => 9];
        $state = ['after_id' => 5, 'batches' => 1, 'checked' => 2, 'added' => 1, 'existing' => 0, 'deferred' => 1,
            'history_reviewed' => $reviewed, 'status' => 'ready'];
        if ($proofPolicy !== null) $proof['history_policy'] = $proofPolicy;
        if ($statePolicy !== null) $state['history_policy'] = $statePolicy;
        $this->expectException(SmsProspectBackfillCliException::class);
        $this->expectExceptionMessage('SQL checkpoint does not reconcile');
        \smsBackfillRemaining($state, $proof, $reviewed);
    }

    public function test_apply_rejects_unattested_or_conflicting_history_before_reading_sql(): void
    {
        foreach ([false, true] as $conflict) {
            $options = \smsBackfillOptions(['backfill', '--audit-dir=/private/audit', '--run-id=00000000-0000-4000-8000-000000000001']);
            $options['apply'] = true; $options['history-reviewed'] = $conflict; $options['confirmed-no-prior-sends'] = $conflict;
            $source = new SmsBackfillCliStoragePdo(null);
            try {
                \smsBackfillApply($source, ['proof' => []], $options, []);
                self::fail('Unattested or conflicting history reached the writer.');
            } catch (SmsProspectBackfillCliException $error) {
                self::assertStringContainsString('Invalid backfill options', $error->getMessage());
                self::assertSame('', $source->sql);
            }
        }
    }

    public function test_initial_storage_reservation_covers_full_new_plan_but_only_remaining_resumed_candidates(): void
    {
        $proof = ['candidate_count' => 1000000, 'candidate_max_id' => 2000000];
        $state = ['after_id' => 1999999, 'batches' => 1000, 'checked' => 999999, 'added' => 999999,
            'existing' => 0, 'deferred' => 0, 'history_reviewed' => true, 'history_policy' => 'verified-events-v1', 'status' => 'ready'];
        self::assertSame(1000000, \smsBackfillRemaining(null, $proof, true));
        self::assertSame(1, \smsBackfillRemaining($state, $proof, true));
        self::assertSame(0, \smsBackfillRemaining(['checked' => 1000000, 'added' => 1000000, 'after_id' => 2000000] + $state, $proof, true));
        $this->expectException(SmsProspectBackfillCliException::class);
        \smsBackfillRemaining(['checked' => 1000001, 'added' => 1000001] + $state, $proof, true);
    }

    public static function linkedFiles(): array
    {
        return [['.audit.lock'], ['manifest.json'], ['coverage.sqlite']];
    }

    #[DataProvider('linkedFiles')]
    public function test_rejects_hard_linked_audit_files_without_repairing_them(string $name): void
    {
        [$directory] = $this->artifact();
        $other = $directory.'/extra-link';
        if (! @link($directory.'/'.$name, $other)) self::markTestSkipped('Hard links are unavailable on this local filesystem.');
        $this->files[] = $other;
        $this->expectException(SmsProspectBackfillCliException::class);
        \smsBackfillOpenAudit(\smsBackfillOptions(['backfill', '--audit-dir='.$directory]));
    }

    public function test_rejects_symlinked_ancestors(): void
    {
        if (PHP_OS_FAMILY === 'Windows') self::markTestSkipped('Windows symlink privilege is unavailable; run on Linux.');
        [$directory] = $this->artifact();
        $container = $this->directory(); $alias = $container.'/alias';
        if (! @symlink($directory, $alias)) self::markTestSkipped('Symlink creation is unavailable.');
        $this->files[] = $alias;
        $this->expectException(Throwable::class);
        \smsBackfillOpenAudit(\smsBackfillOptions(['backfill', '--audit-dir='.$alias]));
    }

    public function test_nonprivate_permissions_are_rejected_without_chmod_repairs(): void
    {
        if (PHP_OS_FAMILY === 'Windows') self::markTestSkipped('POSIX ownership/mode checks require Linux.');
        [$directory] = $this->artifact(); $path = $directory.'/manifest.json';
        chmod($path, 0644);
        try {
            \smsBackfillOpenAudit(\smsBackfillOptions(['backfill', '--audit-dir='.$directory]));
            self::fail('Nonprivate manifest accepted.');
        } catch (SmsProspectBackfillCliException) {
            clearstatcache(true, $path);
            self::assertSame(0644, fileperms($path) & 0777);
        }
    }

    public function test_warning_contents_are_redacted_by_the_installed_main_error_handler(): void
    {
        [$directory] = $this->artifact(); $root = $this->runtime($directory);
        file_put_contents($root.'/vendor/autoload.php', '<?php trigger_error("PRIVATE_WARNING customer@example.test", E_USER_WARNING);');
        $mask = umask(); $handler = static fn (): bool => true;
        set_error_handler($handler); ob_start();
        try {
            try { \smsBackfillMain(['backfill', '--audit-dir='.$directory, '--root='.$root]); self::fail('Warning accepted.'); }
            catch (SmsProspectBackfillCliException $error) {
                self::assertStringNotContainsString('PRIVATE_', $error->getMessage());
                self::assertStringNotContainsString('customer@example.test', $error->getMessage());
            }
            self::assertSame('', ob_get_contents()); self::assertSame($mask, umask());
            $actual = set_error_handler($handler); restore_error_handler();
            self::assertSame($handler, $actual);
        } finally { ob_end_clean(); restore_error_handler(); }
    }
}

final class SmsBackfillCliStoragePdo extends PDO
{
    public string $sql = '';
    public function __construct(private ?array $row) {}
    public function exec(string $statement): int|false { $this->sql = $statement; return 0; }
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->sql = $query;
        if ($this->row === null) throw new PDOException('PRIVATE_STORAGE_DIAGNOSTIC');
        return new SmsBackfillCliStorageStatement($this->row);
    }
}

final class SmsBackfillCliStorageStatement extends PDOStatement
{
    public function __construct(private array $row) {}
    public function execute(?array $params = null): bool { return true; }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return [$this->row]; }
    public function closeCursor(): bool { return true; }
}
