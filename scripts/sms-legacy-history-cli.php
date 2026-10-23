<?php

declare(strict_types=1);

use Cmd\Reports\Services\SmsLegacyHistoryImport;

/** Only fixed messages may leave this entry point. */
final class SmsLegacyHistoryCliException extends RuntimeException
{
    public function __construct(string $kind = 'runtime')
    {
        parent::__construct(match ($kind) {
            'options' => 'Invalid history options; use --help. No configuration was loaded.',
            'artifact' => 'A complete, closed, private history artifact and an available audit lock are required.',
            'approval' => 'Writes require Linux, the exact reviewed plan hash, and a recent UTC backup check.',
            'build' => 'History evidence build refused; use verified CSV files and a new private output directory.',
            'storage' => 'Verified local disk, current resources and Azure storage headroom are required before writes.',
            'checkpoint' => 'The SQL history checkpoint does not reconcile with the verified events; investigate before retrying manually.',
            default => 'History operation failed safely; inspect evidence, resources and the committed SQL checkpoint before retrying manually.',
        });
    }
}

set_error_handler(static function (): never { throw new SmsLegacyHistoryCliException(); });
try {
    // Definitions only: neither helper entry point runs, and the unrelated backfill writer is not loaded.
    require_once __DIR__.'/sms-prospect-backfill-cli.php';
    require_once __DIR__.'/sms-legacy-history-files.php';
} catch (Throwable) {
    if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
        fwrite(STDERR, 'History stopped: '.(new SmsLegacyHistoryCliException())->getMessage()."\n");
        exit(1);
    }
    throw new SmsLegacyHistoryCliException();
} finally { restore_error_handler(); }

/** Lexical validation only; even malformed help never inspects runtime configuration. */
function smsHistoryOptions(array $argv): array
{
    $values = ['root', 'env', 'source', 'out', 'sent-date', 'plan-dir', 'run-id', 'actor',
        'expect-plan-sha256', 'backup-checked-at', 'batch-size', 'max-batches'];
    $flags = ['help', 'build', 'install-ledger', 'apply', 'confirmed-sent'];
    $options = []; $sources = [];
    try {
        foreach (array_slice($argv, 1) as $arg) {
            if (! is_string($arg) || preg_match('/[\x00-\x1f\x7f]/', $arg)
                || ! preg_match('/^--([a-z0-9-]+)(?:=(.*))?$/D', $arg, $match)
                || ! in_array($match[1], [...$values, ...$flags], true)
                || ($match[1] !== 'source' && array_key_exists($match[1], $options))) throw new RuntimeException();
            $flag = in_array($match[1], $flags, true);
            if (($flag && array_key_exists(2, $match)) || (! $flag && ($match[2] ?? '') === '')) throw new RuntimeException();
            if ($match[1] === 'source') $sources[] = $match[2];
            else $options[$match[1]] = $flag ? true : $match[2];
        }
        $options += array_fill_keys($flags, false);
        if ((int) $options['build'] + (int) $options['apply'] + (int) $options['install-ledger'] > 1
            || ($options['confirmed-sent'] && ! $options['apply']) || count($sources) > 10) throw new RuntimeException();
        if ($options['build']) {
            if (isset($options['plan-dir'])) throw new RuntimeException();
            foreach (['run-id', 'actor', 'expect-plan-sha256', 'backup-checked-at', 'batch-size', 'max-batches'] as $key) {
                if (isset($options[$key])) throw new RuntimeException();
            }
            if (! $options['help'] && (! $sources || ! isset($options['out'], $options['sent-date']))) throw new RuntimeException();
        } elseif ($sources || isset($options['out']) || isset($options['sent-date'])
            || (! $options['help'] && ! isset($options['plan-dir']))) throw new RuntimeException();
        $args = ['coverage', '--out='.($options['out'] ?? $options['plan-dir'] ?? '/sms-history-help'),
            '--batch-size='.($options['batch-size'] ?? '250'), '--max-batches='.($options['max-batches'] ?? '1')];
        foreach (['root', 'env'] as $key) if (isset($options[$key])) $args[] = '--'.$key.'='.$options[$key];
        $validated = smsCoverageOptions($args);
        foreach (['root', 'env', 'batch-size', 'max-batches'] as $key) $options[$key] = $validated[$key];
        foreach (['out', 'plan-dir'] as $key) if (isset($options[$key])) $options[$key] = $validated['out'];
        foreach ($sources as &$path) $path = smsCoverageOptions(['coverage', '--out='.$path])['out'];
        unset($path);
        $unique = PHP_OS_FAMILY === 'Windows' ? array_map('strtolower', $sources) : $sources;
        if (count(array_unique($unique)) !== count($sources)) throw new RuntimeException();
        $options['source'] = $sources;
        if (isset($options['sent-date'])) {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $options['sent-date'], new DateTimeZone('UTC'));
            if (! $date || $date->format('Y-m-d') !== $options['sent-date'] || $options['sent-date'] > gmdate('Y-m-d')) throw new RuntimeException();
        }
        if (isset($options['run-id'])) {
            if (! preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD', $options['run-id'])) throw new RuntimeException();
            $options['run-id'] = strtolower($options['run-id']);
        }
        if (isset($options['actor']) && (trim($options['actor']) !== $options['actor'] || $options['actor'] === ''
            || ! preg_match('//u', $options['actor']) || mb_strlen($options['actor']) > 255)) throw new RuntimeException();
        if (isset($options['expect-plan-sha256']) && ! preg_match('/^[a-f0-9]{64}$/D', $options['expect-plan-sha256'])) throw new RuntimeException();
        if (isset($options['backup-checked-at'])) smsBackfillBackup($options['backup-checked-at']);
        if (! $options['help'] && ($options['apply'] || $options['install-ledger'])
            && (! isset($options['expect-plan-sha256'], $options['backup-checked-at'])
                || ($options['apply'] && (! $options['confirmed-sent'] || ! isset($options['run-id'], $options['actor']))))) throw new RuntimeException();
    } catch (Throwable) { throw new SmsLegacyHistoryCliException('options'); }
    return $options;
}

/** Fingerprint the local executable dependency closure, independent of runtime options/autoload order. */
function smsHistoryToolHash(): string
{
    $hashes = smsCoverageToolHash();
    foreach ([__DIR__.'/sms-prospect-backfill-cli.php', __DIR__.'/sms-prospect-backfill-plan.php', __FILE__,
        __DIR__.'/sms-legacy-history-files.php', dirname(__DIR__).'/src/Services/SmsLegacyHistoryImport.php',
        dirname(__DIR__).'/src/Services/SmsProspectValues.php'] as $path) {
        $hash = @hash_file('sha256', $path);
        if ($hash === false) throw new SmsLegacyHistoryCliException('artifact');
        $hashes .= $hash;
    }
    return hash('sha256', $hashes);
}

/** No chmod or SQLite recovery on an existing artifact. No recursive traversal. */
function smsHistoryClosedFiles(string $directory): array
{
    $stats = ['.' => smsBackfillPrivate($directory, true)];
    $required = ['.audit.lock', 'manifest.json', 'events.sqlite'];
    $sidecars = ['events.sqlite-wal', 'events.sqlite-journal', 'events.sqlite-shm'];
    foreach (scandir($directory) as $name) {
        if (! in_array($name, ['.', '..', ...$required, ...$sidecars], true)) throw new SmsLegacyHistoryCliException('artifact');
    }
    foreach ($required as $name) $stats[$name] = smsBackfillPrivate($directory.'/'.$name);
    if ($stats['.audit.lock']['size'] !== 0) throw new SmsLegacyHistoryCliException('artifact');
    foreach ($sidecars as $name) {
        $stats[$name] = smsBackfillPrivate($directory.'/'.$name, false, false);
        if ($stats[$name] !== null && $stats[$name]['size'] !== 0) throw new SmsLegacyHistoryCliException('artifact');
    }
    return $stats;
}

function smsHistorySameStats(array $before, array $after): void
{
    foreach ($before as $name => $stat) {
        if (($stat === null) !== ($after[$name] === null)) throw new SmsLegacyHistoryCliException('artifact');
        if ($stat === null) continue;
        foreach (['dev', 'ino', 'mode', 'uid', 'gid', 'nlink', 'size', 'mtime', 'ctime'] as $key) {
            if ($stat[$key] !== $after[$name][$key]) throw new SmsLegacyHistoryCliException('artifact');
        }
    }
}

/** Bounded read of the original manifest bytes, with no permission repairs. */
function smsHistoryManifest(string $path, ?string &$hash = null): array
{
    $stat = smsBackfillPrivate($path); $handle = fopen($path, 'rb');
    if ($handle === false) throw new SmsLegacyHistoryCliException('artifact');
    try {
        $opened = fstat($handle);
        if ($opened === false || $opened['dev'] !== $stat['dev'] || $opened['ino'] !== $stat['ino']
            || $opened['size'] > 2 * 1024 * 1024) throw new SmsLegacyHistoryCliException('artifact');
        $text = stream_get_contents($handle, 2 * 1024 * 1024 + 1);
        $after = fstat($handle);
        if ($text === false || $after === false || strlen($text) > 2 * 1024 * 1024
            || $after['size'] !== strlen($text) || $after['mtime'] !== $opened['mtime'] || $after['ctime'] !== $opened['ctime']) throw new SmsLegacyHistoryCliException('artifact');
    } finally { fclose($handle); }
    $manifest = json_decode($text, true, 64, JSON_THROW_ON_ERROR);
    if (! is_array($manifest) || array_keys($manifest) !== ['format', 'complete', 'tool_sha256', 'proof']
        || $manifest['format'] !== 1 || $manifest['complete'] !== true || ! is_array($manifest['proof'])
        || ! is_string($manifest['tool_sha256']) || ! hash_equals(smsHistoryToolHash(), $manifest['tool_sha256'])) throw new SmsLegacyHistoryCliException('artifact');
    $hash = hash('sha256', $text);
    return $manifest;
}

/** The shared lock stays held until the immutable SQLite connection and SQL connection are released. */
function smsHistoryOpenArtifact(array $options): array
{
    $directory = $options['plan-dir']; $stats = smsHistoryClosedFiles($directory);
    $lock = fopen($directory.'/.audit.lock', 'rb');
    if ($lock === false) throw new SmsLegacyHistoryCliException('artifact');
    try {
        $opened = fstat($lock);
        if ($opened === false || $opened['dev'] !== $stats['.audit.lock']['dev'] || $opened['ino'] !== $stats['.audit.lock']['ino']
            || ! flock($lock, LOCK_SH | LOCK_NB)) throw new SmsLegacyHistoryCliException('artifact');
        smsHistorySameStats($stats, smsHistoryClosedFiles($directory));
        $manifestHash = null; $manifest = smsHistoryManifest($directory.'/manifest.json', $manifestHash);
        $uri = str_replace(['%2F', '%3A'], ['/', ':'], rawurlencode($directory.'/events.sqlite'));
        $journal = new PDO('sqlite:file:'.$uri.'?mode=ro&immutable=1', null, null,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]);
        $journal->exec('PRAGMA query_only=ON');
        $proof = SmsLegacyHistoryFiles::proof($journal);
        if (SmsProspectReconciliation::json($proof) !== SmsProspectReconciliation::json($manifest['proof'])
            || $proof['sent_date'] > gmdate('Y-m-d')) throw new SmsLegacyHistoryCliException('artifact');
        $proof['artifact_sha256'] = hash_file('sha256', $directory.'/events.sqlite');
        $proof['manifest_file_sha256'] = $manifestHash;
        $proof['lock_file_sha256'] = hash_file('sha256', $directory.'/.audit.lock');
        $proof['writer_tool_sha256'] = smsHistoryToolHash();
        $audit = ['directory' => $directory, 'lock' => $lock, 'journal' => $journal, 'stats' => $stats,
            'proof' => $proof, 'plan_sha256' => hash('sha256', SmsProspectReconciliation::json($proof))];
        smsHistoryArtifactUnchanged($audit, false);
        if (isset($options['expect-plan-sha256']) && ! hash_equals($options['expect-plan-sha256'], $audit['plan_sha256'])) throw new SmsLegacyHistoryCliException('approval');
        return $audit;
    } catch (Throwable $error) {
        unset($journal);
        flock($lock, LOCK_UN); fclose($lock);
        throw $error;
    }
}

/** Small stat checks per chunk; full byte/code hashes only at initial verification and final release. */
function smsHistoryArtifactUnchanged(array $audit, bool $hash = true): void
{
    smsHistorySameStats($audit['stats'], smsHistoryClosedFiles($audit['directory']));
    $opened = fstat($audit['lock']); $locked = $audit['stats']['.audit.lock'];
    if ($opened === false || $opened['dev'] !== $locked['dev'] || $opened['ino'] !== $locked['ino']) throw new SmsLegacyHistoryCliException('artifact');
    if ($hash && (! hash_equals($audit['proof']['artifact_sha256'], hash_file('sha256', $audit['directory'].'/events.sqlite'))
        || ! hash_equals($audit['proof']['manifest_file_sha256'], hash_file('sha256', $audit['directory'].'/manifest.json'))
        || ! hash_equals($audit['proof']['lock_file_sha256'], hash_file('sha256', $audit['directory'].'/.audit.lock'))
        || ! hash_equals($audit['proof']['writer_tool_sha256'], smsHistoryToolHash()))) throw new SmsLegacyHistoryCliException('artifact');
}

function smsHistoryCloseArtifact(array &$audit): void
{
    $audit['journal'] = null;
    flock($audit['lock'], LOCK_UN); fclose($audit['lock']);
}

/** Creates only a NEW directory; a failed build stays incomplete and cannot be consumed. */
function smsHistoryBuild(array $options): int
{
    $directory = $options['out']; $journal = null; $lock = null;
    try {
        smsCoverageNoLinks($directory);
        if (file_exists($directory) || is_link($directory) || ! is_dir(dirname($directory))) throw new SmsLegacyHistoryCliException('build');
        foreach ($options['source'] as $path) smsBackfillPrivate($path);
        if (! mkdir($directory, 0700)) throw new SmsLegacyHistoryCliException('build');
        smsCoveragePrivateMode($directory, 0700);
        $lock = fopen($directory.'/.audit.lock', 'x+b');
        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) throw new SmsLegacyHistoryCliException('build');
        smsCoveragePrivateMode($directory.'/.audit.lock', 0600);
        $path = $directory.'/events.sqlite'; $file = fopen($path, 'x+b');
        if ($file === false) throw new SmsLegacyHistoryCliException('build');
        fclose($file); smsCoveragePrivateMode($path, 0600);
        $toolHash = smsHistoryToolHash();
        $journal = new PDO('sqlite:'.$path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $journal->exec('PRAGMA journal_mode=DELETE');
        $proof = SmsLegacyHistoryFiles::build($journal, $options['source'], $options['sent-date']);
        if (SmsLegacyHistoryFiles::proof($journal) !== $proof || ! hash_equals($toolHash, smsHistoryToolHash())) throw new SmsLegacyHistoryCliException('build');
        $journal = null;
        smsBackfillPrivate($path);
        $manifest = ['format' => 1, 'complete' => true, 'tool_sha256' => $toolHash, 'proof' => $proof];
        $file = fopen($directory.'/manifest.json', 'x+b');
        if ($file === false) throw new SmsLegacyHistoryCliException('build');
        try {
            $bytes = SmsProspectReconciliation::json($manifest)."\n";
            if (fwrite($file, $bytes) !== strlen($bytes) || ! fflush($file)) throw new SmsLegacyHistoryCliException('build');
        } finally { fclose($file); }
        smsCoveragePrivateMode($directory.'/manifest.json', 0600);
        smsHistoryClosedFiles($directory);
    } catch (Throwable) { throw new SmsLegacyHistoryCliException('build'); }
    finally {
        $journal = null;
        if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
    }
    // Reopen exactly as a later offline plan/apply invocation will, after the build lock closes.
    $audit = smsHistoryOpenArtifact(['plan-dir' => $directory]);
    try {
        smsHistoryArtifactUnchanged($audit);
        echo SmsProspectReconciliation::json(smsHistoryReport($audit, $options))."\n";
    } finally { smsHistoryCloseArtifact($audit); }
    return 0;
}

/** Require the reviewed local writer AFTER the runtime autoloader; never accept a vendored class. */
function smsHistoryLoadWriter(): void
{
    $class = SmsLegacyHistoryImport::class; $path = dirname(__DIR__).'/src/Services/SmsLegacyHistoryImport.php';
    if (! class_exists($class, false)) require_once $path;
    if (realpath((new ReflectionClass($class))->getFileName()) !== realpath($path)) throw new SmsLegacyHistoryCliException();
}

/** Dotenv parses in memory through the existing pinned configuration helper, with diagnostics suppressed. */
function smsHistoryConfiguration(array $options): array
{
    $level = ob_get_level(); ob_start();
    try { $credentials = smsCoverageConfiguration($options); smsHistoryLoadWriter(); return $credentials; }
    finally { while (ob_get_level() > $level) ob_end_clean(); }
}

/** History mode needs resource statistics only; do not query unrelated prospect/source guards. */
function smsHistoryResources(PDO $source): void
{
    try {
        $resources = SmsProspectReconciliation::select($source, 'SELECT TOP(1) end_time,avg_cpu_percent,avg_data_io_percent,avg_log_write_percent,
            DATEDIFF(second,end_time,SYSUTCDATETIME()) AS age_seconds FROM sys.dm_db_resource_stats ORDER BY end_time DESC')[0] ?? [];
        foreach (['age_seconds', 'avg_cpu_percent', 'avg_data_io_percent', 'avg_log_write_percent'] as $key) {
            if (! isset($resources[$key]) || ! is_numeric($resources[$key]) || ! is_finite((float) $resources[$key]) || (float) $resources[$key] < 0) throw new RuntimeException();
        }
        SmsProspectCoverage::assertResources(['resources' => $resources]);
    } catch (Throwable) { throw new SmsLegacyHistoryCliException('storage'); }
}

function smsHistoryWriteGuard(PDO $source, array $audit, array $options, int $remaining): array
{
    if (PHP_OS_FAMILY !== 'Linux' || ! isset($options['expect-plan-sha256'])
        || ! hash_equals($options['expect-plan-sha256'], $audit['plan_sha256'])) throw new SmsLegacyHistoryCliException('approval');
    smsHistoryArtifactUnchanged($audit, false);
    smsCoverageLocalStorage($audit['directory']); smsCoverageDiskHeadroom($audit['directory']);
    smsHistoryResources($source);
    $storage = smsBackfillStorage($source, $remaining);
    if (! $storage['azure_headroom_known'] || $storage['azure_available_bytes'] < $storage['estimated_bytes']) throw new SmsLegacyHistoryCliException('storage');
    return $storage;
}

function smsHistoryCheckpoint(?array $state, array $proof, string $runId): array
{
    if ($state === null || ! is_string($state['run_id'] ?? null) || strtolower($state['run_id']) !== strtolower($runId)
        || ! in_array($state['status'] ?? null, ['ready', 'completed'], true)) throw new SmsLegacyHistoryCliException('checkpoint');
    foreach (['after_id', 'batches', 'checked', 'added', 'existing'] as $key) {
        if (! is_int($state[$key] ?? null) || $state[$key] < 0) throw new SmsLegacyHistoryCliException('checkpoint');
    }
    if ($state['checked'] > $proof['unique_events'] || $state['after_id'] > $proof['event_max_id']
        || $state['added'] > $state['checked'] || $state['existing'] !== $state['checked'] - $state['added']
        || $state['batches'] > $state['checked'] || $state['checked'] > $state['after_id']
        || (($state['checked'] === 0) !== ($state['after_id'] === 0))
        || (($state['checked'] === 0) !== ($state['batches'] === 0))
        || (($state['checked'] === $proof['unique_events']) !== ($state['after_id'] === $proof['event_max_id']))
        || ($state['status'] === 'completed' && $state['checked'] !== $proof['unique_events'])) throw new SmsLegacyHistoryCliException('checkpoint');
    return $state;
}

/** Reconcile a resumed SQL cursor with the actual ordered event prefix, once per invocation. */
function smsHistoryResumeCheckpoint(PDO $journal, ?array $state, array $proof, string $runId): int
{
    if ($state === null) return $proof['unique_events'];
    $state = smsHistoryCheckpoint($state, $proof, $runId);
    $after = 0; $count = 0;
    while ($after < $state['after_id']) {
        $page = SmsLegacyHistoryFiles::page($journal, $after, 1000);
        if ($page === []) throw new SmsLegacyHistoryCliException('checkpoint');
        foreach ($page as $event) {
            if ($event['Event_ID'] > $state['after_id']) break 2;
            $after = $event['Event_ID']; $count++;
            if ($after === $state['after_id']) break 2;
        }
    }
    if ($after !== $state['after_id'] || $count !== $state['checked']) throw new SmsLegacyHistoryCliException('checkpoint');
    return $proof['unique_events'] - $count;
}

/** Validate the committed SQL state, including a writer's immutable replay result. No local cursor authority. */
function smsHistoryCommitted(array $previous, array $next, array $chunk, array $events, array $proof, string $runId): array
{
    $next = smsHistoryCheckpoint($next, $proof, $runId);
    foreach (['after_id', 'through_id', 'checked', 'added', 'existing'] as $key) {
        if (! is_int($chunk[$key] ?? null) || $chunk[$key] < 0) throw new SmsLegacyHistoryCliException('checkpoint');
    }
    if (! is_bool($chunk['replayed'] ?? null) || $events === [] || $chunk['after_id'] !== $previous['after_id']
        || $chunk['through_id'] !== end($events)['Event_ID'] || $chunk['checked'] !== count($events)
        || $chunk['added'] > $chunk['checked'] || $chunk['existing'] !== $chunk['checked'] - $chunk['added']
        || $next['after_id'] !== $chunk['through_id'] || $next['checked'] !== $previous['checked'] + $chunk['checked']
        || $next['added'] !== $previous['added'] + $chunk['added'] || $next['existing'] !== $previous['existing'] + $chunk['existing']
        || $next['batches'] !== $previous['batches'] + 1) throw new SmsLegacyHistoryCliException('checkpoint');
    return $next;
}

function smsHistoryApply(PDO $source, array $audit, array $options): array
{
    $proof = $audit['proof']; $runId = $options['run-id'];
    $known = SmsLegacyHistoryImport::status($source, $runId);
    $remaining = smsHistoryResumeCheckpoint($audit['journal'], $known, $proof, $runId);
    $storage = smsHistoryWriteGuard($source, $audit, $options, $remaining);
    smsBackfillBackup($options['backup-checked-at']); // Once, immediately before this invocation's first write.
    $state = smsHistoryCheckpoint(SmsLegacyHistoryImport::begin($source, $runId, $proof, $options['actor'], $options['confirmed-sent']), $proof, $runId);
    if ($known !== null && $state !== $known) throw new SmsLegacyHistoryCliException('checkpoint');
    if ($known === null && $state['checked'] !== 0) throw new SmsLegacyHistoryCliException('checkpoint');
    $batches = 0;
    while (true) {
        if ($state['checked'] === $proof['unique_events']) {
            if (SmsLegacyHistoryFiles::page($audit['journal'], $state['after_id'], 1) !== []) throw new SmsLegacyHistoryCliException('checkpoint');
            if ($state['status'] !== 'completed') {
                $storage = smsHistoryWriteGuard($source, $audit, $options, 0);
                $finished = smsHistoryCheckpoint(SmsLegacyHistoryImport::finish($source, $runId), $proof, $runId);
                if ($finished !== array_replace($state, ['status' => 'completed'])) throw new SmsLegacyHistoryCliException('checkpoint');
                $state = $finished;
            }
            return [$state, $storage];
        }
        if ($options['max-batches'] !== 0 && $batches >= $options['max-batches']) return [$state, $storage];
        $page = SmsLegacyHistoryFiles::page($audit['journal'], $state['after_id'], $options['batch-size']);
        if ($page === [] || count($page) > $proof['unique_events'] - $state['checked']) throw new SmsLegacyHistoryCliException('checkpoint');
        $storage = smsHistoryWriteGuard($source, $audit, $options, $proof['unique_events'] - $state['checked']);
        $chunk = SmsLegacyHistoryImport::applyBatch($source, $runId, $state['after_id'], $page);
        $state = smsHistoryCommitted($state, SmsLegacyHistoryImport::status($source, $runId) ?? [], $chunk, $page, $proof, $runId);
        $batches++;
    }
}

/** Explicit aggregate projection: never serialize raw proof, manifest, actor, UUID, paths or SQL. */
function smsHistoryReport(array $audit, array $options, array $state = [], ?array $storage = null): array
{
    $proof = $audit['proof'];
    $report = ['format' => 1, 'read_only' => ! $options['apply'] && ! $options['install-ledger'],
        'complete' => true, 'structural_proof_only' => true, 'policy' => 'verified-phone-day-csv-v1', 'sent_date' => $proof['sent_date'],
        'plan_sha256' => $audit['plan_sha256'], 'events_sha256' => $proof['events_sha256'],
        'artifact_sha256' => $proof['artifact_sha256'], 'tool_sha256' => $proof['writer_tool_sha256'], 'files' => count($proof['files']),
        'confirmed_sent' => $options['confirmed-sent'], 'scope_complete' => ($state['status'] ?? null) === 'completed'];
    foreach (['rows', 'phone_occurrences', 'unique_events', 'duplicate_events', 'event_max_id'] as $key) $report[$key] = smsBackfillInteger($proof[$key]);
    foreach (['after_id', 'batches', 'checked', 'added', 'existing'] as $key) if (isset($state[$key])) $report[$key] = smsBackfillInteger($state[$key]);
    $report['azure_headroom_known'] = ($storage['azure_headroom_known'] ?? false) === true;
    $report['estimated_bytes'] = smsBackfillInteger($storage['estimated_bytes'] ?? $proof['unique_events'] * 2048);
    $report['azure_available_bytes'] = $report['azure_headroom_known'] ? smsBackfillInteger($storage['azure_available_bytes']) : null;
    return $report;
}

function smsHistoryRun(array $options): int
{
    if ($options['help']) {
        echo "Standalone historical SMS structural CSV proof: default offline plan; no application boot.\n"
            ."--build --source=ABS.csv (repeat, 1..10) --out=NEW_ABS_DIRECTORY --sent-date=YYYY-MM-DD\n"
            ."Source CSVs must already be owned, unlinked private 0600 files; prepare verified private copies without changing originals.\n"
            ."Plan/install/apply: --plan-dir=ABS_DIRECTORY; --root=ABS_RUNTIME --env=ABS_CONFIG\n"
            ."--install-ledger OR --apply --run-id=UUID --actor=NAME --confirmed-sent\n"
            ."--confirmed-sent attests actual sending on the verified date; CSV existence alone does not establish a send.\n"
            ."CSV dates do not establish actual sending. Exported-only files must never be imported as sends.\n"
            ."Writes: Linux only, --expect-plan-sha256=SHA256 --backup-checked-at=YYYY-MM-DDTHH:MM:SSZ\n"
            ."Backup checked within 60 minutes, revalidated once before the first write.\n"
            ."--batch-size=250 (1..1000) --max-batches=1 (0=all); SQL checkpoints govern resume; no automatic retries.\n"
            ."Closed private 0700/0600 evidence, verified local mount, 8 GiB local disk and Azure headroom required.\n";
        return 0;
    }
    if ($options['build']) return smsHistoryBuild($options);
    $audit = smsHistoryOpenArtifact($options); $source = null;
    try {
        $state = []; $storage = null;
        try {
            if ($options['apply'] || $options['install-ledger']) {
                if (PHP_OS_FAMILY !== 'Linux' || ! hash_equals($options['expect-plan-sha256'], $audit['plan_sha256'])) throw new SmsLegacyHistoryCliException('approval');
                smsBackfillBackup($options['backup-checked-at']);
                smsCoverageLocalStorage($audit['directory']); smsCoverageDiskHeadroom($audit['directory']);
                $credentials = smsHistoryConfiguration($options);
                try {
                    $source = new PDO('sqlsrv:Server=serverlibertydebtrelief.database.windows.net;Database=CommissionDatabase;Encrypt=yes;TrustServerCertificate=no;MultipleActiveResultSets=false',
                        $credentials[0], $credentials[1], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::SQLSRV_ATTR_QUERY_TIMEOUT => 60]);
                } finally { unset($credentials); }
                $source->exec('SET LOCK_TIMEOUT 5000; SET DEADLOCK_PRIORITY LOW; SET TRANSACTION ISOLATION LEVEL SNAPSHOT');
                if ($options['install-ledger']) {
                    $storage = smsHistoryWriteGuard($source, $audit, $options, $audit['proof']['unique_events']);
                    smsBackfillBackup($options['backup-checked-at']);
                    SmsLegacyHistoryImport::install($source);
                } else [$state, $storage] = smsHistoryApply($source, $audit, $options);
            }
        } finally { smsHistoryArtifactUnchanged($audit); }
        echo SmsProspectReconciliation::json(smsHistoryReport($audit, $options, $state, $storage))."\n";
    } finally { $source = null; smsHistoryCloseArtifact($audit); }
    return 0;
}

function smsHistoryErrorMessage(Throwable $error): string
{
    return $error instanceof SmsLegacyHistoryCliException ? $error->getMessage() : (new SmsLegacyHistoryCliException())->getMessage();
}

function smsHistoryMain(array $argv): int
{
    $mask = umask(0077);
    set_error_handler(static function (int $severity): bool {
        if (! (error_reporting() & $severity)) return false;
        throw new SmsLegacyHistoryCliException();
    });
    try { return smsHistoryRun(smsHistoryOptions($argv)); }
    catch (Throwable $error) {
        if ($error instanceof SmsLegacyHistoryCliException) throw $error;
        throw new SmsLegacyHistoryCliException();
    } finally { restore_error_handler(); umask($mask); }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try { exit(smsHistoryMain($argv)); }
    catch (Throwable $error) { fwrite(STDERR, 'History stopped: '.smsHistoryErrorMessage($error)."\n"); exit(1); }
}
