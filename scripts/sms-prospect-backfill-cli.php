<?php

declare(strict_types=1);

use Cmd\Reports\Services\SmsProspectBackfill;

/** Only fixed, reviewed messages may escape the standalone entry point. */
final class SmsProspectBackfillCliException extends RuntimeException
{
    public function __construct(string $kind = 'runtime')
    {
        parent::__construct(match ($kind) {
            'options' => 'Invalid backfill options; use --help. No configuration was loaded.',
            'audit' => 'A closed, completed, private coverage artifact and an available shared audit lock are required.',
            'approval' => 'Writes require Linux, the exact reviewed plan hash, and a recent UTC backup check.',
            'storage' => 'Verified local disk and Azure storage headroom are required before writes.',
            'checkpoint' => 'The SQL checkpoint does not reconcile with the frozen candidate scope; investigate before retrying manually.',
            default => 'Backfill failed safely; inspect evidence, resources and the committed SQL checkpoint before retrying manually.',
        });
    }
}

// Required definitions must not leak missing-file warnings or dependency diagnostics either.
set_error_handler(static function (): never { throw new SmsProspectBackfillCliException(); });
try {
    require_once __DIR__.'/sms-prospect-coverage-cli.php';
    require_once __DIR__.'/sms-prospect-backfill-plan.php';
} catch (Throwable) {
    if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
        fwrite(STDERR, 'Backfill stopped: '.(new SmsProspectBackfillCliException())->getMessage()."\n");
        exit(1);
    }
    throw new SmsProspectBackfillCliException();
} finally { restore_error_handler(); }

/** Strict UTC seconds; rechecked immediately before the invocation's first SQL write. */
function smsBackfillBackup(string $value, ?int $now = null): void
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new DateTimeZone('UTC'));
    $now ??= time();
    if (! $date || $date->format('Y-m-d\TH:i:s\Z') !== $value
        || $date->getTimestamp() < $now - 3600 || $date->getTimestamp() > $now + 60) {
        throw new SmsProspectBackfillCliException('approval');
    }
}

/** No filesystem inspection, configuration, autoloader or database access. */
function smsBackfillOptions(array $argv): array
{
    $values = ['root', 'env', 'audit-dir', 'run-id', 'actor', 'batch-size', 'max-batches', 'expect-plan-sha256', 'backup-checked-at'];
    $flags = ['help', 'apply', 'install-ledger', 'history-reviewed', 'confirmed-no-prior-sends', 'hold-all-repeated'];
    $options = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (! is_string($arg) || preg_match('/[\x00-\x1f\x7f]/', $arg)
            || ! preg_match('/^--([a-z0-9-]+)(?:=(.*))?$/D', $arg, $match)
            || ! in_array($match[1], [...$values, ...$flags], true) || array_key_exists($match[1], $options)) {
            throw new SmsProspectBackfillCliException('options');
        }
        $flag = in_array($match[1], $flags, true);
        if (($flag && array_key_exists(2, $match)) || (! $flag && ($match[2] ?? '') === '')) throw new SmsProspectBackfillCliException('options');
        $options[$match[1]] = $flag ? true : $match[2];
    }
    $options += array_fill_keys($flags, false);
    if (($options['apply'] && $options['install-ledger']) || ($options['history-reviewed'] && ! $options['apply'])
        || ($options['history-reviewed'] && $options['confirmed-no-prior-sends'])
        || (! $options['help'] && ! isset($options['audit-dir']))) throw new SmsProspectBackfillCliException('options');
    // Reuse the audit CLI's pure absolute-path and bounded-integer validation.
    $paths = ['--out='.($options['audit-dir'] ?? '/sms-backfill-help'), '--batch-size='.($options['batch-size'] ?? '250'),
        '--max-batches='.($options['max-batches'] ?? '1')];
    foreach (['root', 'env'] as $key) if (isset($options[$key])) $paths[] = '--'.$key.'='.$options[$key];
    try { $validated = smsCoverageOptions(['coverage', ...$paths]); }
    catch (Throwable) { throw new SmsProspectBackfillCliException('options'); }
    foreach (['root', 'env', 'batch-size', 'max-batches'] as $key) $options[$key] = $validated[$key];
    if (isset($options['audit-dir'])) $options['audit-dir'] = $validated['out'];
    if (isset($options['run-id']) && ! preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD', $options['run-id'])) throw new SmsProspectBackfillCliException('options');
    if (isset($options['actor']) && (trim($options['actor']) !== $options['actor'] || ! preg_match('//u', $options['actor']) || mb_strlen($options['actor']) > 255)) throw new SmsProspectBackfillCliException('options');
    if (isset($options['expect-plan-sha256']) && ! preg_match('/^[a-f0-9]{64}$/D', $options['expect-plan-sha256'])) throw new SmsProspectBackfillCliException('options');
    if (isset($options['backup-checked-at'])) smsBackfillBackup($options['backup-checked-at']);
    if (! $options['help'] && ($options['apply'] || $options['install-ledger'])
        && (! isset($options['expect-plan-sha256'], $options['backup-checked-at'])
            || ($options['apply'] && (! isset($options['run-id'], $options['actor']) || ! smsBackfillHistoryReviewed($options))))) throw new SmsProspectBackfillCliException('options');
    return $options;
}

/** The writer's existing boolean represents either explicit history attestation. */
function smsBackfillHistoryReviewed(array $options): bool
{
    return ($options['history-reviewed'] ?? false) === true || ($options['confirmed-no-prior-sends'] ?? false) === true;
}

/** SQL Server temp-table ALTER COLUMN cannot run inside a SNAPSHOT writer transaction (3964). */
function smsBackfillConfigureSource(PDO $source, array $options): void
{
    $source->exec('SET LOCK_TIMEOUT 5000; SET DEADLOCK_PRIORITY LOW; SET TRANSACTION ISOLATION LEVEL '
        .(($options['apply'] || $options['install-ledger']) ? 'READ COMMITTED' : 'SNAPSHOT'));
}

/** Validate, never repair, existing audit permissions or links. */
function smsBackfillPrivate(string $path, bool $directory = false, bool $required = true): ?array
{
    smsCoverageNoLinks($path);
    clearstatcache(true, $path);
    $stat = @lstat($path);
    if ($stat === false && ! $required) return null;
    if ($stat === false || ($stat['mode'] & 0170000) !== ($directory ? 0040000 : 0100000)
        || (! $directory && $stat['nlink'] !== 1)
        || (PHP_OS_FAMILY !== 'Windows' && ! function_exists('posix_geteuid'))
        || (function_exists('posix_geteuid') && $stat['uid'] !== posix_geteuid())
        || (PHP_OS_FAMILY !== 'Windows' && ($stat['mode'] & 07777) !== ($directory ? 0700 : 0600))) {
        throw new SmsProspectBackfillCliException('audit');
    }
    return $stat;
}

function smsBackfillClosedFiles(string $directory): void
{
    smsBackfillPrivate($directory, true);
    foreach (['.audit.lock', 'manifest.json', 'coverage.sqlite'] as $name) smsBackfillPrivate($directory.'/'.$name);
    foreach (['coverage.sqlite-wal', 'coverage.sqlite-journal', 'coverage.sqlite-shm'] as $name) {
        $stat = smsBackfillPrivate($directory.'/'.$name, false, false);
        if ($stat !== null && $name !== 'coverage.sqlite-shm' && $stat['size'] !== 0) throw new SmsProspectBackfillCliException('audit');
    }
}

function smsBackfillWriterToolHash(): string
{
    $hashes = smsCoverageToolHash();
    foreach ([__FILE__, __DIR__.'/sms-prospect-backfill-plan.php', dirname(__DIR__).'/src/Services/SmsProspectBackfill.php',
        dirname(__DIR__).'/src/Services/SmsProspectValues.php'] as $path) {
        $hash = @hash_file('sha256', $path);
        if ($hash === false) throw new SmsProspectBackfillCliException();
        $hashes .= $hash;
    }
    return hash('sha256', $hashes);
}

/** Same manifest contract as smsCoverageReadManifest, without its chmod side effect. */
function smsBackfillReadManifest(string $path, ?string &$fileHash = null, bool $holdAllRepeated = false): array
{
    $stat = smsBackfillPrivate($path);
    $handle = fopen($path, 'rb');
    if ($handle === false) throw new SmsProspectBackfillCliException('audit');
    try {
        $opened = fstat($handle);
        if ($opened === false || $opened['dev'] !== $stat['dev'] || $opened['ino'] !== $stat['ino']
            || $opened['size'] > 2 * 1024 * 1024) throw new SmsProspectBackfillCliException('audit');
        $text = stream_get_contents($handle, 2 * 1024 * 1024 + 1);
        $after = fstat($handle);
        if ($text === false || strlen($text) > 2 * 1024 * 1024 || $after === false
            || $after['size'] !== strlen($text) || $after['mtime'] !== $opened['mtime'] || $after['ctime'] !== $opened['ctime']) {
            throw new SmsProspectBackfillCliException('audit');
        }
    } finally { fclose($handle); }
    $manifest = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
    if (! is_array($manifest) || ($manifest['format'] ?? null) !== SmsProspectCoverage::FORMAT
        || ($manifest['read_only'] ?? null) !== true || ($manifest['apply_supported'] ?? null) !== false
        || ! is_string($manifest['tool_sha256'] ?? null)
        || ! hash_equals($holdAllRepeated ? SmsProspectBackfillPlan::LEGACY_CONFLICT_TOOL_SHA256 : smsCoverageToolHash(), $manifest['tool_sha256'])
        || ! is_string($manifest['source_guard'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $manifest['source_guard'])
        || ! is_int($manifest['batch_size'] ?? null) || $manifest['batch_size'] < 1 || $manifest['batch_size'] > 1000
        || ! is_array($manifest['metadata'] ?? null) || ! is_array($manifest['limitations'] ?? null)
        || ! is_string($manifest['created_utc'] ?? null) || ($manifest['metadata_only'] ?? null) !== false) {
        throw new SmsProspectBackfillCliException('audit');
    }
    foreach (['source_watermark_pk', 'prospect_watermark_pk', 'source_rows'] as $key) {
        if (! is_int($manifest[$key] ?? null) || $manifest[$key] < 0 || ($key !== 'source_rows' && $manifest[$key] > 2147483647)) {
            throw new SmsProspectBackfillCliException('audit');
        }
    }
    $fileHash = hash('sha256', $text);
    return $manifest;
}

/** The shared directory lock is held until the source connection and journal are released. */
function smsBackfillOpenAudit(array $options): array
{
    $directory = $options['audit-dir'];
    smsBackfillClosedFiles($directory);
    $lock = fopen($directory.'/.audit.lock', 'rb');
    if ($lock === false) throw new SmsProspectBackfillCliException('audit');
    try {
        $stat = smsBackfillPrivate($directory.'/.audit.lock'); $opened = fstat($lock);
        if ($opened === false || $stat['dev'] !== $opened['dev'] || $stat['ino'] !== $opened['ino']
            || ! flock($lock, LOCK_SH | LOCK_NB)) throw new SmsProspectBackfillCliException('audit');
        smsBackfillClosedFiles($directory);
        $manifestHash = null;
        $holdAllRepeated = ($options['hold-all-repeated'] ?? false) === true;
        $manifest = smsBackfillReadManifest($directory.'/manifest.json', $manifestHash, $holdAllRepeated);
        $path = $directory.'/coverage.sqlite';
        $uri = str_replace(['%2F', '%3A'], ['/', ':'], rawurlencode($path));
        $journal = new PDO('sqlite:file:'.$uri.'?mode=ro&immutable=1', null, null,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]);
        $journal->exec('PRAGMA query_only=ON');
        $proof = SmsProspectBackfillPlan::proof($journal, $manifest, $holdAllRepeated);
        $proof['artifact_sha256'] = hash_file('sha256', $path);
        $proof['writer_tool_sha256'] = smsBackfillWriterToolHash();
        $proof['history_policy'] = ($options['confirmed-no-prior-sends'] ?? false) === true
            ? 'confirmed-no-prior-sends-v1' : 'verified-events-v1';
        $audit = ['directory' => $directory, 'lock' => $lock, 'journal' => $journal, 'manifest' => $manifest,
            'manifest_file_sha256' => $manifestHash, 'proof' => $proof,
            'plan_sha256' => hash('sha256', SmsProspectReconciliation::json($proof))];
        if (isset($options['expect-plan-sha256']) && ! hash_equals($options['expect-plan-sha256'], $audit['plan_sha256'])) throw new SmsProspectBackfillCliException('approval');
        return $audit;
    } catch (Throwable $error) {
        unset($journal);
        flock($lock, LOCK_UN); fclose($lock);
        throw $error;
    }
}

function smsBackfillArtifactUnchanged(array $audit): void
{
    smsBackfillClosedFiles($audit['directory']);
    $stat = smsBackfillPrivate($audit['directory'].'/.audit.lock'); $opened = fstat($audit['lock']);
    if ($opened === false || $stat['dev'] !== $opened['dev'] || $stat['ino'] !== $opened['ino']
        || ! hash_equals($audit['plan_sha256'], hash('sha256', SmsProspectReconciliation::json($audit['proof'])))
        || ! hash_equals($audit['proof']['artifact_sha256'], hash_file('sha256', $audit['directory'].'/coverage.sqlite'))
        || ! hash_equals($audit['manifest_file_sha256'], hash_file('sha256', $audit['directory'].'/manifest.json'))
        || ! hash_equals($audit['proof']['writer_tool_sha256'], smsBackfillWriterToolHash())) throw new SmsProspectBackfillCliException('audit');
}

/** No autoload lookup may silently select a vendored writer. Called AFTER the runtime loader. */
function smsBackfillLoadWriter(): void
{
    foreach (['SmsProspectValues', 'SmsProspectBackfill'] as $name) {
        $class = 'Cmd\\Reports\\Services\\'.$name;
        $path = dirname(__DIR__).'/src/Services/'.$name.'.php';
        if (! class_exists($class, false)) require_once $path;
        if (realpath((new ReflectionClass($class))->getFileName()) !== realpath($path)) throw new SmsProspectBackfillCliException();
    }
}

function smsBackfillInteger(mixed $value): int
{
    if (is_int($value) && $value >= 0) return $value;
    if (is_string($value) && preg_match('/^(?:0|[1-9][0-9]{0,18})$/D', $value)
        && (string) (int) $value === $value && (int) $value >= 0) return (int) $value;
    throw new SmsProspectBackfillCliException('storage');
}

/** Unknown permissions/metadata are reportable in plan mode, but never permit writes. */
function smsBackfillStorage(PDO $source, int $candidates): array
{
    if ($candidates < 0 || $candidates > intdiv(PHP_INT_MAX, 2048)) throw new SmsProspectBackfillCliException('storage');
    $result = ['azure_headroom_known' => false, 'estimated_bytes' => $candidates * 2048, 'azure_available_bytes' => null];
    try {
        $row = SmsProspectReconciliation::select($source, "SELECT
            SUM(CASE WHEN type=0 THEN CONVERT(bigint,FILEPROPERTY(name,'SpaceUsed')) ELSE 0 END)*8192 AS used_bytes,
            MAX(TRY_CONVERT(bigint,DATABASEPROPERTYEX(DB_NAME(),'MaxSizeInBytes'))) AS max_bytes,
            SUM(CASE WHEN type=0 THEN 1 ELSE 0 END) AS data_files,
            SUM(CASE WHEN type=0 AND FILEPROPERTY(name,'SpaceUsed') IS NULL THEN 1 ELSE 0 END) AS unknown_files
            FROM sys.database_files")[0] ?? [];
        $used = smsBackfillInteger($row['used_bytes'] ?? null); $max = smsBackfillInteger($row['max_bytes'] ?? null);
        if (smsBackfillInteger($row['data_files'] ?? null) < 1 || smsBackfillInteger($row['unknown_files'] ?? null) !== 0 || $max === 0 || $used > $max) return $result;
        $result['azure_headroom_known'] = true; $result['azure_available_bytes'] = $max - $used;
    } catch (Throwable) { /* Never print provider diagnostics or assume unknown storage is safe. */ }
    return $result;
}

function smsBackfillWriteGuard(PDO $source, array $audit, array $options, int $remaining): void
{
    if (PHP_OS_FAMILY !== 'Linux') throw new SmsProspectBackfillCliException('approval');
    if (! hash_equals($options['expect-plan-sha256'], $audit['plan_sha256'])) throw new SmsProspectBackfillCliException('approval');
    smsCoverageLocalStorage($audit['directory']);
    smsCoverageDiskHeadroom($audit['directory']);
    smsCoverageHealth($source, $audit['proof']['source_guard']);
    $storage = smsBackfillStorage($source, $remaining);
    if (! $storage['azure_headroom_known'] || $storage['azure_available_bytes'] < $storage['estimated_bytes']) throw new SmsProspectBackfillCliException('storage');
}

function smsBackfillCheckpoint(?array $state, array $proof, bool $historyReviewed): array
{
    if ($state === null) throw new SmsProspectBackfillCliException('checkpoint');
    $historyPolicy = $proof['history_policy'] ?? 'verified-events-v1';
    if (! in_array($historyPolicy, ['verified-events-v1', 'confirmed-no-prior-sends-v1'], true)
        || ($state['history_policy'] ?? null) !== $historyPolicy
        || ($historyPolicy === 'confirmed-no-prior-sends-v1' && ! $historyReviewed)) throw new SmsProspectBackfillCliException('checkpoint');
    foreach (['after_id', 'batches', 'checked', 'added', 'existing', 'deferred'] as $key) {
        if (! is_int($state[$key] ?? null) || $state[$key] < 0) throw new SmsProspectBackfillCliException('checkpoint');
    }
    if (($state['history_reviewed'] ?? null) !== $historyReviewed || ! in_array($state['status'] ?? null, ['ready', 'completed', 'completed_with_deferrals'], true)
        || $state['checked'] !== $state['added'] + $state['existing'] + $state['deferred']
        || $state['checked'] > $proof['candidate_count'] || $state['after_id'] > $proof['candidate_max_id']
        || (($state['checked'] === 0) !== ($state['after_id'] === 0))) throw new SmsProspectBackfillCliException('checkpoint');
    return $state;
}

function smsBackfillRemaining(?array $state, array $proof, bool $historyReviewed): int
{
    return $state === null ? $proof['candidate_count']
        : $proof['candidate_count'] - smsBackfillCheckpoint($state, $proof, $historyReviewed)['checked'];
}

/** Whitelist aggregates; no run ID, actor, path, proof, manifest, customer or raw SQL fields. */
function smsBackfillReport(array $audit, array $options, array $storage, array $state = [], bool $done = false): array
{
    $proof = $audit['proof'];
    $historyReviewed = smsBackfillHistoryReviewed($options);
    $result = ['read_only' => ! $options['apply'] && ! $options['install-ledger'], 'policy' => SmsProspectBackfill::POLICY,
        'plan_sha256' => $audit['plan_sha256'], 'history_policy' => $proof['history_policy'] ?? 'verified-events-v1',
        'confirmed_no_prior_sends' => ($options['confirmed-no-prior-sends'] ?? false) === true,
        'history_reviewed' => $historyReviewed, 'history_needs_review' => ! $historyReviewed,
        'candidate_scope_complete' => $done, 'scope_complete' => ($proof['format'] ?? 1) === 1 && $done && $proof['excluded_conflicts'] === 0
            && $proof['excluded_repeated_nonconflicting'] === 0 && ($state['deferred'] ?? 0) === 0];
    foreach (['source_watermark_pk', 'prospect_watermark_pk', 'candidate_count', 'candidate_max_id'] as $key) $result[$key] = smsBackfillInteger($proof[$key]);
    if (($proof['format'] ?? 1) === 2) {
        SmsProspectBackfillPlan::validateHeldProof($proof);
        $result['proof_format'] = 2;
        $result['eligibility_policy'] = $proof['eligibility_policy'];
        $result['conflict_counts_verified'] = false;
        $result['excluded_repeated_people'] = $proof['excluded_repeated_people'];
        $result['conflict_counter_evidence'] = $proof['conflict_counter_evidence'];
    } else {
        foreach (['excluded_conflicts', 'excluded_repeated_nonconflicting'] as $key) $result[$key] = smsBackfillInteger($proof[$key]);
    }
    foreach (['after_id', 'batches', 'checked', 'added', 'existing', 'deferred'] as $key) if (isset($state[$key])) $result[$key] = smsBackfillInteger($state[$key]);
    $result['azure_headroom_known'] = $storage['azure_headroom_known'] === true;
    $result['estimated_bytes'] = smsBackfillInteger($storage['estimated_bytes']);
    $result['azure_available_bytes'] = $result['azure_headroom_known'] ? smsBackfillInteger($storage['azure_available_bytes']) : null;
    return $result;
}

function smsBackfillApply(PDO $source, array $audit, array $options, array $storage): array
{
    $proof = $audit['proof']; $runId = $options['run-id'];
    $historyReviewed = smsBackfillHistoryReviewed($options);
    if (! $historyReviewed || (($options['history-reviewed'] ?? false) && ($options['confirmed-no-prior-sends'] ?? false))) {
        throw new SmsProspectBackfillCliException('options');
    }
    $remaining = smsBackfillRemaining(SmsProspectBackfill::status($source, $runId), $proof, $historyReviewed);
    smsBackfillWriteGuard($source, $audit, $options, $remaining);
    smsBackfillArtifactUnchanged($audit);
    smsBackfillBackup($options['backup-checked-at']);
    SmsProspectBackfill::begin($source, $runId, $proof, $options['actor'], $historyReviewed);
    $state = smsBackfillCheckpoint(SmsProspectBackfill::status($source, $runId), $proof, $historyReviewed);
    $batches = 0;
    while (true) {
        $done = $state['checked'] === $proof['candidate_count'] && $state['after_id'] === $proof['candidate_max_id'];
        if ($done) {
            if (SmsProspectBackfillPlan::candidates($audit['journal'], $audit['manifest'], $state['after_id'], 1) !== []) throw new SmsProspectBackfillCliException('checkpoint');
            if ($state['status'] === 'ready') {
                smsBackfillWriteGuard($source, $audit, $options, 0);
                $state = smsBackfillCheckpoint(SmsProspectBackfill::finish($source, $runId), $proof, $historyReviewed);
            }
            return [$state, true];
        }
        if ($state['status'] !== 'ready') throw new SmsProspectBackfillCliException('checkpoint');
        if ($options['max-batches'] !== 0 && $batches >= $options['max-batches']) return [$state, false];
        $page = SmsProspectBackfillPlan::candidates($audit['journal'], $audit['manifest'], $state['after_id'], $options['batch-size']);
        if ($page === [] || $state['checked'] + count($page) > $proof['candidate_count']) throw new SmsProspectBackfillCliException('checkpoint');
        smsBackfillWriteGuard($source, $audit, $options, $proof['candidate_count'] - $state['checked']);
        SmsProspectBackfill::applyBatch($source, $runId, $state['after_id'], $page);
        $next = smsBackfillCheckpoint(SmsProspectBackfill::status($source, $runId), $proof, $historyReviewed);
        if ($next['checked'] < $state['checked'] + count($page) || $next['after_id'] < end($page)['Source_PK']) throw new SmsProspectBackfillCliException('checkpoint');
        $state = $next; $batches++;
        echo SmsProspectReconciliation::json(smsBackfillReport($audit, $options, $storage, $state))."\n";
    }
}

function smsBackfillRun(array $options): int
{
    if ($options['help']) {
        echo "Standalone prospect backfill: default read-only plan; no Laravel boot.\n"
            ."--audit-dir=/private/completed-audit (required except help); --root=/runtime/root --env=/private/config\n"
            ."--batch-size=250 (1..1000); --max-batches=1 (0=all); --help\n"
            ."--apply --run-id=UUID --actor=NAME (--history-reviewed OR --confirmed-no-prior-sends); OR --install-ledger\n"
            ."--confirmed-no-prior-sends attests no prior actual SMS sends ever; an export is not a sent campaign.\n"
            ."Use this flag for plan/install/apply; it is mutually exclusive with --history-reviewed.\n"
            ."The reviewed plan hash binds the history choice; repeat the same choice for install and apply.\n"
            ."Confirmed mode requires an empty actual-send ledger throughout the run; existing prospect rows are preserved.\n"
            ."Writes: Linux only, --expect-plan-sha256=LOWERCASE_SHA256 --backup-checked-at=YYYY-MM-DDTHH:MM:SSZ\n"
            ."Backup check: previous 60 minutes, at most 60 seconds future, checked again before the first write only.\n"
            ."Require 8 GiB local disk and Azure headroom; resource checks continue before every batch.\n"
            ."Closed 0700/0600 artifact, empty/absent WAL, shared nonblocking audit lock; no automatic retries.\n"
            ."Both history modes require the same closed, completed audit, resource checks and backup approval.\n"
            ."Only single-source candidates; exclusions remain outside the candidate scope. SQL checkpoints resume the same run ID.\n";
        echo "--hold-all-repeated: explicit compatibility with the exact reviewed old audit fingerprint.\n"
            ."All repeated identities are held; conflict totals remain unverified. Repeat this choice for plan/install/apply.\n";
        return 0;
    }
    // Complete local evidence is mandatory BEFORE runtime configuration, source PDO or SQL writes.
    $audit = smsBackfillOpenAudit($options);
    $source = null;
    try {
        try {
            if (($options['apply'] || $options['install-ledger']) && PHP_OS_FAMILY !== 'Linux') throw new SmsProspectBackfillCliException('approval');
            $credentials = smsCoverageConfiguration($options);
            smsBackfillLoadWriter();
            $source = new PDO('sqlsrv:Server=serverlibertydebtrelief.database.windows.net;Database=CommissionDatabase;Encrypt=yes;TrustServerCertificate=no;MultipleActiveResultSets=false',
                $credentials[0], $credentials[1], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::SQLSRV_ATTR_QUERY_TIMEOUT => 60]);
            unset($credentials);
            smsBackfillConfigureSource($source, $options);
            smsCoverageHealth($source, $audit['proof']['source_guard']);
            $storage = smsBackfillStorage($source, $audit['proof']['candidate_count']);
            $state = []; $done = false;
            if ($options['install-ledger']) {
                smsBackfillWriteGuard($source, $audit, $options, $audit['proof']['candidate_count']);
                smsBackfillArtifactUnchanged($audit);
                smsBackfillBackup($options['backup-checked-at']);
                SmsProspectBackfill::install($source);
            } elseif ($options['apply']) {
                [$state, $done] = smsBackfillApply($source, $audit, $options, $storage);
            }
        } finally { smsBackfillArtifactUnchanged($audit); }
        echo SmsProspectReconciliation::json(smsBackfillReport($audit, $options, $storage, $state, $done))."\n";
    } finally {
        $source = null; $audit['journal'] = null;
        flock($audit['lock'], LOCK_UN); fclose($audit['lock']);
    }
    return 0;
}

function smsBackfillErrorMessage(Throwable $error): string
{
    return $error instanceof SmsProspectBackfillCliException ? $error->getMessage() : (new SmsProspectBackfillCliException())->getMessage();
}

function smsBackfillMain(array $argv): int
{
    $mask = umask(0077);
    set_error_handler(static function (int $severity): bool {
        if (! (error_reporting() & $severity)) return false;
        throw new SmsProspectBackfillCliException();
    });
    try { return smsBackfillRun(smsBackfillOptions($argv)); }
    catch (Throwable $error) {
        if ($error instanceof SmsProspectBackfillCliException) throw $error;
        throw new SmsProspectBackfillCliException();
    } finally { restore_error_handler(); umask($mask); }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try { exit(smsBackfillMain($argv)); }
    catch (Throwable $error) { fwrite(STDERR, 'Backfill stopped: '.smsBackfillErrorMessage($error)."\n"); exit(1); }
}
