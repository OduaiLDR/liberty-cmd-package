<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SmsLegacyHistoryCliException;
use SmsLegacyHistoryFiles;
use SmsProspectReconciliation;
use Throwable;

require_once dirname(__DIR__, 2).'/scripts/sms-legacy-history-cli.php';

/** Synthetic CSV/SQLite artifacts and inert PDO doubles only. No source connection or application boot. */
final class SmsLegacyHistoryCliTest extends TestCase
{
    private const RUN = '00000000-0000-4000-8000-000000000001';
    private const DATE = '2026-10-08';
    private const HEADER = "FirstName,Address,DebtLoad,Phone1,Phone2,Phone3,Phone4,Phone5,SendDate\n";
    private array $directories = [];
    private array $links = [];

    protected function tearDown(): void
    {
        foreach ($this->links as $link) if (is_link($link) || is_file($link)) unlink($link);
        foreach (array_reverse($this->directories) as $directory) {
            if (! is_dir($directory)) continue;
            foreach (scandir($directory) as $name) {
                if ($name !== '.' && $name !== '..' && (is_file($directory.'/'.$name) || is_link($directory.'/'.$name))) unlink($directory.'/'.$name);
            }
            rmdir($directory);
        }
        unset($GLOBALS['sms_history_cli_runtime_marker']);
    }

    private function directory(?string $path = null, bool $create = true): string
    {
        $path ??= str_replace('\\', '/', realpath(sys_get_temp_dir())).'/sms-history-cli-'.bin2hex(random_bytes(8));
        if ($create) mkdir($path, 0700);
        $this->directories[] = $path;
        return $path;
    }

    private function file(string $path, string $contents): void
    {
        file_put_contents($path, $contents); chmod($path, 0600);
    }

    private function csv(string $directory, string $name = 'part.csv', ?string $rows = null): string
    {
        $path = $directory.'/'.$name;
        $this->file($path, self::HEADER.($rows ?? "PRIVATE_CUSTOMER,PRIVATE_ADDRESS,12,2025550100,2025550101,,,,".self::DATE."\n"
            ."PRIVATE_OTHER,PRIVATE_ADDRESS,9,2025550101,2025550102,,,,".self::DATE."\n"));
        return $path;
    }

    private function capture(array $args): array
    {
        ob_start();
        try { $code = \smsHistoryMain(['history', ...$args]); return [$code, ob_get_contents()]; }
        finally { ob_end_clean(); }
    }

    private function artifact(): array
    {
        $parent = $this->directory(); $source = $this->csv($parent);
        $directory = $this->directory($parent.'/artifact', false);
        [$code, $output] = $this->capture(['--build', '--source='.$source, '--out='.$directory, '--sent-date='.self::DATE]);
        self::assertSame(0, $code);
        return [$directory, json_decode($output, true, 64, JSON_THROW_ON_ERROR), $source];
    }

    private function runtime(): string
    {
        $root = $this->directory(); $this->directory($root.'/vendor');
        $this->file($root.'/vendor/autoload.php', '<?php $GLOBALS["sms_history_cli_runtime_marker"] = true; echo "PRIVATE_AUTOLOAD_DIAGNOSTIC";');
        $this->file($root.'/.env', "CMD_DB_HOST=invalid.example.test\nCMD_DB_DATABASE=other\nCMD_DB_USERNAME=PRIVATE_USER\nCMD_DB_PASSWORD=PRIVATE_PASSWORD\n");
        return $root;
    }

    private function applyArgs(string $hash = ''): array
    {
        return ['--apply', '--run-id='.self::RUN, '--actor=PRIVATE_ACTOR', '--confirmed-sent',
            '--expect-plan-sha256='.($hash ?: str_repeat('a', 64)), '--backup-checked-at='.gmdate('Y-m-d\TH:i:s\Z')];
    }

    private function state(array $changes = []): array
    {
        return $changes + ['run_id' => self::RUN, 'status' => 'ready', 'after_id' => 2,
            'batches' => 1, 'checked' => 2, 'added' => 1, 'existing' => 1];
    }

    private function snapshot(string $directory): array
    {
        $snapshot = [];
        foreach (['.', '.audit.lock', 'manifest.json', 'events.sqlite'] as $name) {
            $path = $directory.'/'.$name; clearstatcache(true, $path); $stat = lstat($path);
            $snapshot[$name] = [is_file($path) ? hash_file('sha256', $path) : null,
                array_intersect_key($stat, array_flip(['dev', 'ino', 'mode', 'uid', 'gid', 'nlink', 'size', 'mtime', 'ctime']))];
        }
        return $snapshot;
    }

    public function test_default_plan_is_offline_and_batch_controls_are_bounded(): void
    {
        $options = \smsHistoryOptions(['history', '--plan-dir=/private/history']);
        foreach (['apply', 'build', 'install-ledger', 'confirmed-sent', 'help'] as $key) self::assertFalse($options[$key]);
        self::assertSame(250, $options['batch-size']); self::assertSame(1, $options['max-batches']);
        self::assertSame([], $options['source']); self::assertSame($options['root'].'/.env', $options['env']);
        $options = \smsHistoryOptions(['history', '--plan-dir=C:\\private\\history', '--root=C:\\runtime', '--batch-size=1000', '--max-batches=0']);
        self::assertSame('C:/private/history', $options['plan-dir']); self::assertSame(1000, $options['batch-size']);
        self::assertSame(0, $options['max-batches']);
    }

    public function test_build_accepts_up_to_ten_explicit_sources_without_configuration_io(): void
    {
        $args = ['history', '--build', '--out=/private/new', '--sent-date='.self::DATE];
        for ($i = 0; $i < 10; $i++) $args[] = '--source=/private/part'.$i.'.csv';
        self::assertCount(10, \smsHistoryOptions($args)['source']);
        $this->expectException(SmsLegacyHistoryCliException::class);
        \smsHistoryOptions([...$args, '--source=/private/part10.csv']);
    }

    public static function malformedOptions(): array
    {
        $args = ['--', '-h', 'PRIVATE_ARGUMENT', '--unknown', '--resume', '--delete', '--help=', '--apply=true',
            '--confirmed-sent=true', '--build=1', '--source', '--source=', '--plan-dir=', '--root=', '--env=',
            '--batch-size=0', '--batch-size=1001', '--batch-size=-1', '--batch-size=1.5', '--batch-size=1e2',
            '--batch-size=+1', '--batch-size= 1', '--max-batches=-1', '--max-batches=1.0', '--max-batches='.str_repeat('9', 30),
            '--actor= ', '--actor='.str_repeat('a', 256), '--actor=PRIVATE_ACTOR'."\n", '--actor='."\xff",
            '--run-id=PRIVATE_UUID', '--expect-plan-sha256='.str_repeat('A', 64), '--expect-plan-sha256='.str_repeat('g', 64),
            '--backup-checked-at=2026-02-30T00:00:00Z', '--backup-checked-at=not-a-date', '--backup-checked-at=2026-10-20T00:00:00+00:00'];
        return array_map(static fn ($arg) => [$arg], $args);
    }

    #[DataProvider('malformedOptions')]
    public function test_bad_options_fail_even_with_help_before_runtime_loading(string $arg): void
    {
        $root = $this->runtime();
        try {
            \smsHistoryMain(['history', '--help', '--root='.$root, $arg]); self::fail('Invalid argument accepted.');
        } catch (SmsLegacyHistoryCliException $error) {
            self::assertStringContainsString('Invalid history options', $error->getMessage());
            self::assertFalse(isset($GLOBALS['sms_history_cli_runtime_marker']));
        }
    }

    public static function badPaths(): array
    {
        return array_map(static fn ($path) => [$path], ['relative', '../history', '/private/../history', '/private/./history',
            '//network/history', '\\\\server\\share', 'C:relative', 'C:/private:stream', '/private//history', '/', 'C:/', "/private/\0history"]);
    }

    #[DataProvider('badPaths')]
    public function test_unsafe_paths_fail_pure_validation(string $path): void
    {
        foreach (in_array($path, ['/', 'C:/'], true) ? ['plan-dir'] : ['plan-dir', 'root', 'env'] as $key) {
            try { \smsHistoryOptions(['history', '--help', '--'.$key.'='.$path]); self::fail('Unsafe path accepted.'); }
            catch (SmsLegacyHistoryCliException) { self::assertTrue(true); }
        }
    }

    public function test_modes_missing_values_duplicates_and_send_attestation_fail_before_config(): void
    {
        $cases = [[], ['--apply'], ['--build'], ['--install-ledger'], ['--confirmed-sent'],
            ['--plan-dir=/private/plan', '--apply', '--install-ledger'],
            ['--build', '--apply'], ['--build', '--plan-dir=/private/plan', '--out=/private/new', '--source=/private/a.csv', '--sent-date='.self::DATE],
            ['--plan-dir=/private/a', '--plan-dir=/private/b'], ['--help', '--help'],
            ['--source=/private/a.csv', '--plan-dir=/private/plan'], ['--out=/private/new'],
            ['--build', '--out=/private/new', '--source=/private/a.csv', '--source=/private/a.csv', '--sent-date='.self::DATE],
            ['--build', '--out=/private/new', '--source=/private/a.csv', '--sent-date=2026-02-30'],
            ['--build', '--out=/private/new', '--source=/private/a.csv', '--sent-date=9999-01-01'],
            ['--plan-dir=/private/plan', ...array_filter($this->applyArgs(), static fn ($arg) => $arg !== '--confirmed-sent')]];
        foreach ($cases as $args) {
            try { \smsHistoryOptions(['history', ...$args]); self::fail('Invalid mode accepted.'); }
            catch (SmsLegacyHistoryCliException) { self::assertTrue(true); }
        }
        $install = \smsHistoryOptions(['history', '--plan-dir=/private/plan', '--install-ledger',
            '--expect-plan-sha256='.str_repeat('b', 64), '--backup-checked-at='.gmdate('Y-m-d\TH:i:s\Z')]);
        self::assertTrue($install['install-ledger']); self::assertFalse($install['confirmed-sent']);
        self::assertTrue(\smsHistoryOptions(['history', '--plan-dir=/private/plan', ...$this->applyArgs()])['confirmed-sent']);
    }

    public function test_help_explains_actual_send_attestation_and_uses_no_configuration(): void
    {
        $root = $this->runtime(); [$code, $output] = $this->capture(['--help', '--root='.$root]);
        self::assertSame(0, $code); self::assertStringContainsString('attests actual sending', $output);
        self::assertStringContainsString('CSV existence alone', $output);
        self::assertStringContainsString('CSV dates do not establish actual sending', $output);
        self::assertStringContainsString('Exported-only files must never be imported as sends', $output);
        self::assertFalse(isset($GLOBALS['sms_history_cli_runtime_marker']));
    }

    public function test_build_creates_only_private_artifacts_and_preserves_source_bytes(): void
    {
        [$directory, $report, $source] = $this->artifact();
        self::assertSame(['.audit.lock', 'events.sqlite', 'manifest.json'], array_values(array_diff(scandir($directory), ['.', '..'])));
        self::assertSame(2, $report['rows']); self::assertSame(4, $report['phone_occurrences']);
        self::assertSame(3, $report['unique_events']); self::assertSame(1, $report['duplicate_events']);
        self::assertSame(self::DATE, $report['sent_date']); self::assertFalse($report['confirmed_sent']);
        self::assertTrue($report['structural_proof_only']);
        $manifest = json_decode(file_get_contents($directory.'/manifest.json'), true);
        self::assertSame(hash_file('sha256', $source), $manifest['proof']['files'][0]['sha256']);
        foreach (['PRIVATE_', '2025550100', '2025550101', $source, self::RUN] as $private) {
            self::assertStringNotContainsString($private, json_encode($report));
            self::assertStringNotContainsString($private, file_get_contents($directory.'/manifest.json'));
        }
        if (PHP_OS_FAMILY !== 'Windows') {
            self::assertSame(0700, fileperms($directory) & 0777);
            foreach (['.audit.lock', 'events.sqlite', 'manifest.json'] as $name) self::assertSame(0600, fileperms($directory.'/'.$name) & 0777);
        }
    }

    public function test_offline_plan_is_stable_preserves_every_artifact_and_never_loads_runtime(): void
    {
        [$directory, $built] = $this->artifact(); $root = $this->runtime(); $before = $this->snapshot($directory);
        [$code, $output] = $this->capture(['--plan-dir='.$directory, '--root='.$root, '--env='.$root.'/.env',
            '--run-id='.self::RUN, '--actor=PRIVATE_ACTOR', '--batch-size=1', '--max-batches=0']);
        $report = json_decode($output, true);
        self::assertSame(0, $code); self::assertSame($built['plan_sha256'], $report['plan_sha256']);
        self::assertTrue($report['read_only']); self::assertFalse($report['azure_headroom_known']); self::assertNull($report['azure_available_bytes']);
        self::assertSame($before, $this->snapshot($directory)); self::assertFalse(isset($GLOBALS['sms_history_cli_runtime_marker']));
        self::assertStringNotContainsString('PRIVATE_', $output); self::assertStringNotContainsString(self::RUN, $output);
    }

    public function test_build_can_merge_repeated_source_arguments_and_deduplicates_phone_day_events(): void
    {
        $parent = $this->directory(); $first = $this->csv($parent); $second = $this->csv($parent, 'second.csv');
        $directory = $this->directory($parent.'/artifact', false);
        [, $text] = $this->capture(['--build', '--source='.$first, '--source='.$second, '--out='.$directory, '--sent-date='.self::DATE]);
        $report = json_decode($text, true); self::assertSame(2, $report['files']); self::assertSame(4, $report['rows']);
        self::assertSame(8, $report['phone_occurrences']); self::assertSame(3, $report['unique_events']); self::assertSame(5, $report['duplicate_events']);
    }

    public function test_failed_build_is_incomplete_and_rejected_before_runtime_configuration(): void
    {
        $parent = $this->directory(); $source = $this->csv($parent, 'bad.csv', "PRIVATE_NAME,PRIVATE_ADDRESS,1,PRIVATE_PHONE,,,,,2026-10-07\n");
        $directory = $this->directory($parent.'/failed', false); $root = $this->runtime();
        try { $this->capture(['--build', '--source='.$source, '--out='.$directory, '--sent-date='.self::DATE]); self::fail('Bad source accepted.'); }
        catch (SmsLegacyHistoryCliException $error) { self::assertStringNotContainsString('PRIVATE_', $error->getMessage()); }
        self::assertFileDoesNotExist($directory.'/manifest.json');
        try { $this->capture(['--plan-dir='.$directory, '--root='.$root]); self::fail('Incomplete build accepted.'); }
        catch (SmsLegacyHistoryCliException) { self::assertFalse(isset($GLOBALS['sms_history_cli_runtime_marker'])); }
    }

    public function test_build_refuses_existing_output_missing_parent_and_missing_source_without_overwrite(): void
    {
        $parent = $this->directory(); $source = $this->csv($parent); $this->file($parent.'/sentinel', 'PRIVATE_KEEP');
        foreach ([$parent, $parent.'/missing/new'] as $directory) {
            try { $this->capture(['--build', '--source='.$source, '--out='.$directory, '--sent-date='.self::DATE]); self::fail('Unsafe output accepted.'); }
            catch (SmsLegacyHistoryCliException) { self::assertSame('PRIVATE_KEEP', file_get_contents($parent.'/sentinel')); }
        }
        $directory = $parent.'/new';
        try { $this->capture(['--build', '--source='.$parent.'/missing.csv', '--out='.$directory, '--sent-date='.self::DATE]); self::fail('Missing source accepted.'); }
        catch (SmsLegacyHistoryCliException) { self::assertDirectoryDoesNotExist($directory); }
    }

    public static function artifactFaults(): array
    {
        return array_map(static fn ($fault) => [$fault], ['wal', 'journal', 'shm', 'manifest-missing', 'lock-missing', 'sqlite-missing',
            'active-lock', 'lock-content', 'tool-hash', 'complete', 'proof-count', 'proof-date', 'corrupt-sqlite', 'incomplete-journal', 'event-edit', 'extra-file']);
    }

    #[DataProvider('artifactFaults')]
    public function test_closed_complete_artifact_is_required_before_configuration(string $fault): void
    {
        [$directory] = $this->artifact(); $root = $this->runtime(); $lock = null;
        if (in_array($fault, ['wal', 'journal', 'shm'], true)) $this->file($directory.'/events.sqlite-'.$fault, 'PRIVATE_UNCHECKPOINTED');
        if (str_ends_with($fault, '-missing')) unlink($directory.'/'.match ($fault) { 'manifest-missing' => 'manifest.json', 'lock-missing' => '.audit.lock', default => 'events.sqlite' });
        if ($fault === 'active-lock') { $lock = fopen($directory.'/.audit.lock', 'r+b'); self::assertTrue(flock($lock, LOCK_EX | LOCK_NB)); }
        if ($fault === 'lock-content') $this->file($directory.'/.audit.lock', 'PRIVATE_LOCK_CONTENT');
        if (in_array($fault, ['tool-hash', 'complete', 'proof-count', 'proof-date'], true)) {
            $path = $directory.'/manifest.json'; $manifest = json_decode(file_get_contents($path), true);
            if ($fault === 'tool-hash') $manifest['tool_sha256'] = str_repeat('0', 64);
            if ($fault === 'complete') $manifest['complete'] = false;
            if ($fault === 'proof-count') $manifest['proof']['unique_events']++;
            if ($fault === 'proof-date') $manifest['proof']['sent_date'] = '2026-10-07';
            $this->file($path, SmsProspectReconciliation::json($manifest));
        }
        if ($fault === 'corrupt-sqlite') $this->file($directory.'/events.sqlite', 'PRIVATE_INVALID_SQLITE');
        if ($fault === 'incomplete-journal' || $fault === 'event-edit') {
            $journal = new PDO('sqlite:'.$directory.'/events.sqlite');
            $journal->exec($fault === 'incomplete-journal' ? 'UPDATE state SET complete=0' : "UPDATE events SET phone='2025550199' WHERE id=1");
            $journal = null;
        }
        if ($fault === 'extra-file') $this->file($directory.'/PRIVATE_EXTRA', 'PRIVATE_DATA');
        try {
            try { $this->capture(['--plan-dir='.$directory, '--root='.$root]); self::fail('Invalid artifact accepted.'); }
            catch (SmsLegacyHistoryCliException $error) {
                self::assertFalse(isset($GLOBALS['sms_history_cli_runtime_marker'])); self::assertStringNotContainsString('PRIVATE_', $error->getMessage());
            }
        } finally { if ($lock !== null) { flock($lock, LOCK_UN); fclose($lock); } }
    }

    public function test_review_hash_binds_sqlite_and_exact_manifest_bytes_and_releases_lock_on_failure(): void
    {
        [$directory, $report] = $this->artifact(); $root = $this->runtime();
        file_put_contents($directory.'/manifest.json', "\n", FILE_APPEND);
        try { $this->capture(['--plan-dir='.$directory, '--root='.$root, '--expect-plan-sha256='.$report['plan_sha256']]); self::fail('Changed artifact accepted.'); }
        catch (SmsLegacyHistoryCliException) { self::assertFalse(isset($GLOBALS['sms_history_cli_runtime_marker'])); }
        $lock = fopen($directory.'/.audit.lock', 'rb');
        try { self::assertTrue(flock($lock, LOCK_EX | LOCK_NB)); }
        finally { flock($lock, LOCK_UN); fclose($lock); }
        [, $text] = $this->capture(['--plan-dir='.$directory]);
        self::assertNotSame($report['plan_sha256'], json_decode($text, true)['plan_sha256']);
        $journal = new PDO('sqlite:'.$directory.'/events.sqlite'); $journal->exec('PRAGMA user_version=123'); $journal = null;
        [, $next] = $this->capture(['--plan-dir='.$directory]);
        self::assertNotSame(json_decode($text, true)['plan_sha256'], json_decode($next, true)['plan_sha256']);
    }

    public function test_full_final_hash_recheck_catches_bytes_changed_after_open(): void
    {
        [$directory] = $this->artifact(); $audit = \smsHistoryOpenArtifact(['plan-dir' => $directory]);
        try {
            file_put_contents($directory.'/events.sqlite', 'PRIVATE_APPEND', FILE_APPEND);
            $this->expectException(Throwable::class); \smsHistoryArtifactUnchanged($audit);
        } finally { \smsHistoryCloseArtifact($audit); }
    }

    public function test_small_chunk_checks_do_not_hash_the_whole_artifact(): void
    {
        [$directory] = $this->artifact(); $audit = \smsHistoryOpenArtifact(['plan-dir' => $directory]);
        try {
            $audit['proof']['artifact_sha256'] = str_repeat('0', 64);
            \smsHistoryArtifactUnchanged($audit, false);
            $this->expectException(SmsLegacyHistoryCliException::class); \smsHistoryArtifactUnchanged($audit);
        } finally { \smsHistoryCloseArtifact($audit); }
    }

    public function test_manifest_read_is_bounded(): void
    {
        [$directory] = $this->artifact(); $this->file($directory.'/manifest.json', str_repeat(' ', 2 * 1024 * 1024 + 1));
        $this->expectException(SmsLegacyHistoryCliException::class); \smsHistoryManifest($directory.'/manifest.json');
    }

    public function test_tool_hash_covers_helpers_reader_cli_writer_and_values_and_loads_the_local_writer(): void
    {
        \smsHistoryLoadWriter(); $root = dirname(__DIR__, 2); $hashes = \smsCoverageToolHash();
        foreach (['scripts/sms-prospect-backfill-cli.php', 'scripts/sms-prospect-backfill-plan.php', 'scripts/sms-legacy-history-cli.php',
            'scripts/sms-legacy-history-files.php', 'src/Services/SmsLegacyHistoryImport.php', 'src/Services/SmsProspectValues.php'] as $path) $hashes .= hash_file('sha256', $root.'/'.$path);
        self::assertSame(hash('sha256', $hashes), \smsHistoryToolHash());
        self::assertSame(realpath($root.'/src/Services/SmsLegacyHistoryImport.php'), realpath((new \ReflectionClass(\Cmd\Reports\Services\SmsLegacyHistoryImport::class))->getFileName()));
    }

    public function test_configuration_failure_is_redacted_and_only_a_deliberate_helper_call_loads_runtime(): void
    {
        $root = $this->runtime(); $options = \smsHistoryOptions(['history', '--plan-dir=/private/unused', '--root='.$root]);
        ob_start();
        try {
            try { \smsHistoryConfiguration($options); self::fail('Wrong source configuration accepted.'); }
            catch (Throwable $error) {
                self::assertTrue($GLOBALS['sms_history_cli_runtime_marker'] ?? false);
                $message = \smsHistoryErrorMessage($error);
                self::assertStringNotContainsString('PRIVATE_', $message); self::assertStringNotContainsString($root, $message);
            }
            self::assertSame('', ob_get_contents());
        } finally { ob_end_clean(); }
    }

    public function test_missing_approval_mismatched_hash_and_nonlinux_writes_do_not_load_configuration(): void
    {
        [$directory, $report] = $this->artifact(); $root = $this->runtime();
        $cases = [['--apply'], $this->applyArgs(str_repeat('0', 64))];
        if (PHP_OS_FAMILY !== 'Linux') $cases[] = $this->applyArgs($report['plan_sha256']);
        foreach ($cases as $args) {
            try { $this->capture(['--plan-dir='.$directory, '--root='.$root, ...$args]); self::fail('Unapproved write accepted.'); }
            catch (SmsLegacyHistoryCliException) { self::assertFalse(isset($GLOBALS['sms_history_cli_runtime_marker'])); }
        }
    }

    public function test_error_projection_and_aggregate_report_drop_all_untrusted_identity_fields(): void
    {
        foreach ([new PDOException('PRIVATE_SQL SELECT Phone'), new RuntimeException('/PRIVATE_PATH customer@example.test'),
            new \Error('PRIVATE_DEPENDENCY'), new \SmsProspectBackfillCliException('PRIVATE_CONFIGURATION'),
            new SmsLegacyHistoryCliException('PRIVATE_CUSTOM_MESSAGE')] as $error) {
            $message = \smsHistoryErrorMessage($error);
            self::assertStringNotContainsString('PRIVATE_', $message); self::assertStringNotContainsString('SELECT', $message);
            self::assertStringNotContainsString('customer@example.test', $message);
        }
        [$directory] = $this->artifact(); $audit = \smsHistoryOpenArtifact(['plan-dir' => $directory]);
        try {
            $audit['proof']['Phone'] = 'PRIVATE_PHONE'; $audit['proof']['actor'] = 'PRIVATE_ACTOR';
            $report = \smsHistoryReport($audit, \smsHistoryOptions(['history', '--plan-dir='.$directory]),
                ['actor' => 'PRIVATE_ACTOR', 'run_id' => self::RUN, 'checked' => 2]);
            self::assertStringNotContainsString('PRIVATE_', json_encode($report)); self::assertStringNotContainsString(self::RUN, json_encode($report));
            self::assertSame(2, $report['checked']); self::assertFalse($report['scope_complete']);
        } finally { \smsHistoryCloseArtifact($audit); }
    }

    public function test_resources_query_only_stats_and_rejects_missing_stale_or_pressure_metrics(): void
    {
        $safe = ['age_seconds' => '5', 'avg_cpu_percent' => '10.2', 'avg_data_io_percent' => '20', 'avg_log_write_percent' => '30'];
        $source = new SmsHistoryCliResourcePdo($safe); \smsHistoryResources($source);
        self::assertStringContainsString('sys.dm_db_resource_stats', $source->sql);
        self::assertStringNotContainsString('Tbl', $source->sql); self::assertStringNotContainsString('source_guard', $source->sql);
        foreach ([null, [], ['age_seconds' => 121], ['age_seconds' => -1], ['avg_cpu_percent' => 80],
            ['avg_data_io_percent' => 80], ['avg_log_write_percent' => 80], ['avg_cpu_percent' => 'NaN'], ['avg_cpu_percent' => null]] as $fault) {
            $row = $fault === null || $fault === [] ? $fault : $fault + $safe;
            try { \smsHistoryResources(new SmsHistoryCliResourcePdo($row)); self::fail('Unsafe resource evidence accepted.'); }
            catch (SmsLegacyHistoryCliException $error) { self::assertStringNotContainsString('PRIVATE_', $error->getMessage()); }
        }
    }

    public function test_checkpoint_requires_matching_run_and_consistent_authoritative_counts(): void
    {
        $proof = ['unique_events' => 3, 'event_max_id' => 3]; $state = $this->state();
        self::assertSame($state, \smsHistoryCheckpoint($state, $proof, self::RUN));
        foreach ([['run_id' => 'PRIVATE_RUN'], ['status' => 'pending'], ['checked' => '2'], ['checked' => 4], ['checked' => 3],
            ['after_id' => 4], ['after_id' => 3], ['added' => 2], ['existing' => 2], ['batches' => 0], ['batches' => 3],
            ['checked' => -1], ['status' => 'completed']] as $change) {
            try { \smsHistoryCheckpoint($change + $state, $proof, self::RUN); self::fail('Invalid checkpoint accepted.'); }
            catch (SmsLegacyHistoryCliException) { self::assertTrue(true); }
        }
        self::assertSame(\smsHistoryCheckpoint($this->state(['after_id' => 0, 'batches' => 0, 'checked' => 0, 'added' => 0, 'existing' => 0]), $proof, self::RUN)['checked'], 0);
    }

    public function test_resume_validates_real_event_prefix_and_reserves_only_unchecked_events(): void
    {
        [$directory] = $this->artifact(); $audit = \smsHistoryOpenArtifact(['plan-dir' => $directory]);
        try {
            $proof = $audit['proof']; $journal = $audit['journal'];
            self::assertSame(3, \smsHistoryResumeCheckpoint($journal, null, $proof, self::RUN));
            self::assertSame(1, \smsHistoryResumeCheckpoint($journal, $this->state(), $proof, self::RUN));
            self::assertSame(0, \smsHistoryResumeCheckpoint($journal, $this->state(['after_id' => 3, 'checked' => 3, 'added' => 2, 'status' => 'completed']), $proof, self::RUN));
            $this->expectException(SmsLegacyHistoryCliException::class);
            \smsHistoryResumeCheckpoint($journal, $this->state(['checked' => 1, 'added' => 0]), $proof, self::RUN);
        } finally { \smsHistoryCloseArtifact($audit); }
    }

    public function test_committed_or_replayed_chunk_must_match_exact_sql_cursor_and_counter_deltas(): void
    {
        $proof = ['unique_events' => 3, 'event_max_id' => 3]; $previous = $this->state();
        $next = $this->state(['after_id' => 3, 'checked' => 3, 'batches' => 2, 'added' => 2]);
        $events = [['Event_ID' => 3, 'Phone' => '2025550102', 'Send_Date' => self::DATE]];
        $chunk = ['after_id' => 2, 'through_id' => 3, 'checked' => 1, 'added' => 1, 'existing' => 0, 'replayed' => false];
        self::assertSame($next, \smsHistoryCommitted($previous, $next, $chunk, $events, $proof, self::RUN));
        self::assertSame($next, \smsHistoryCommitted($previous, $next, ['replayed' => true] + $chunk, $events, $proof, self::RUN));
        foreach ([['through_id' => 2], ['after_id' => 1], ['checked' => 2], ['added' => 0], ['replayed' => 1]] as $change) {
            try { \smsHistoryCommitted($previous, $next, $change + $chunk, $events, $proof, self::RUN); self::fail('Invalid replay accepted.'); }
            catch (SmsLegacyHistoryCliException) { self::assertTrue(true); }
        }
        $this->expectException(SmsLegacyHistoryCliException::class);
        \smsHistoryCommitted($previous, ['batches' => 1] + $next, $chunk, $events, $proof, self::RUN);
    }

    public function test_hard_linked_artifact_and_source_files_are_rejected_without_repairs(): void
    {
        [$directory, , $source] = $this->artifact();
        foreach (['.audit.lock', 'manifest.json', 'events.sqlite'] as $name) {
            $other = dirname($directory).'/extra-link';
            if (! @link($directory.'/'.$name, $other)) self::markTestSkipped('Hard links unavailable.');
            $this->links[] = $other;
            try { $this->capture(['--plan-dir='.$directory]); self::fail('Hard-linked artifact accepted.'); }
            catch (SmsLegacyHistoryCliException) { self::assertSame(2, lstat($directory.'/'.$name)['nlink']); }
            unlink($other);
        }
        $other = dirname($directory).'/source-link'; self::assertTrue(link($source, $other));
        $new = dirname($directory).'/new';
        try { $this->capture(['--build', '--source='.$other, '--out='.$new, '--sent-date='.self::DATE]); self::fail('Hard-linked CSV accepted.'); }
        catch (SmsLegacyHistoryCliException) { self::assertDirectoryDoesNotExist($new); }
    }

    public function test_symlinked_ancestors_and_public_permissions_are_rejected_without_repairs(): void
    {
        if (PHP_OS_FAMILY === 'Windows') self::markTestSkipped('POSIX permissions and symlinks require Linux.');
        // These checks precede opening SQLite, so even an invalid synthetic payload must be blocked.
        $directory = $this->directory();
        foreach (['.audit.lock', 'events.sqlite', 'manifest.json'] as $name) $this->file($directory.'/'.$name, '');
        $parent = $this->directory(); $alias = $parent.'/alias';
        if (! @symlink($directory, $alias)) self::markTestSkipped('Symlinks unavailable.');
        $this->links[] = $alias;
        try { $this->capture(['--plan-dir='.$alias]); self::fail('Linked path accepted.'); }
        catch (SmsLegacyHistoryCliException) { self::assertTrue(is_link($alias)); }
        foreach (['.' => 0755, 'manifest.json' => 0644, 'events.sqlite' => 0644, '.audit.lock' => 0644] as $name => $mode) {
            $path = $directory.'/'.$name; chmod($path, $mode);
            try { $this->capture(['--plan-dir='.$directory]); self::fail('Public artifact accepted.'); }
            catch (SmsLegacyHistoryCliException) { clearstatcache(true, $path); self::assertSame($mode, fileperms($path) & 0777); }
            chmod($path, $name === '.' ? 0700 : 0600);
        }
    }

    public function test_build_rejects_nonprivate_source_permissions_without_changing_originals(): void
    {
        if (PHP_OS_FAMILY === 'Windows') self::markTestSkipped('POSIX source modes require Linux.');
        $parent = $this->directory(); $source = $this->csv($parent); chmod($source, 0775);
        $hash = hash_file('sha256', $source); $directory = $parent.'/new-artifact';
        try {
            $this->capture(['--build', '--source='.$source, '--out='.$directory, '--sent-date='.self::DATE]);
            self::fail('Public CSV accepted.');
        } catch (SmsLegacyHistoryCliException) {
            clearstatcache(true, $source); self::assertSame(0775, fileperms($source) & 0777);
            self::assertSame($hash, hash_file('sha256', $source)); self::assertDirectoryDoesNotExist($directory);
        }
    }

    public function test_backup_validation_accepts_only_the_fresh_utc_window(): void
    {
        $now = 1803000000;
        foreach ([-3600, 0, 60] as $offset) { \smsBackfillBackup(gmdate('Y-m-d\TH:i:s\Z', $now + $offset), $now); self::assertTrue(true); }
        foreach ([-3601, 61] as $offset) {
            try { \smsBackfillBackup(gmdate('Y-m-d\TH:i:s\Z', $now + $offset), $now); self::fail('Stale/future backup accepted.'); }
            catch (\SmsProspectBackfillCliException) { self::assertTrue(true); }
        }
    }

    public function test_main_restores_umask_and_error_handler_after_failures(): void
    {
        $mask = umask(); $handler = static fn (): bool => true; set_error_handler($handler);
        try {
            try { \smsHistoryMain(['history', '--plan-dir=/missing/PRIVATE_ARTIFACT']); self::fail('Missing artifact accepted.'); }
            catch (SmsLegacyHistoryCliException $error) { self::assertStringNotContainsString('PRIVATE_', $error->getMessage()); }
            self::assertSame($mask, umask());
            $actual = set_error_handler($handler); restore_error_handler(); self::assertSame($handler, $actual);
        } finally { restore_error_handler(); }
    }
}

final class SmsHistoryCliResourcePdo extends PDO
{
    public string $sql = '';
    public function __construct(private ?array $row) {}
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->sql = $query;
        if ($this->row === null) throw new PDOException('PRIVATE_RESOURCE_ERROR');
        return new SmsHistoryCliResourceStatement($this->row);
    }
}

final class SmsHistoryCliResourceStatement extends PDOStatement
{
    public function __construct(private array $row) {}
    public function execute(?array $params = null): bool { return true; }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return $this->row === [] ? [] : [$this->row]; }
    public function closeCursor(): bool { return true; }
}
