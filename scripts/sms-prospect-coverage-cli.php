<?php

declare(strict_types=1);

/** Standalone Azure reads and a private local journal only; never bootstrap an application. */
final class SmsProspectCoverageCliException extends RuntimeException {}

/** Pure option validation deliberately precedes autoloading, configuration and either database. */
function smsCoverageOptions(array $argv): array
{
    $values = ['root', 'env', 'out', 'batch-size', 'max-batches'];
    $flags = ['resume', 'metadata-only', 'help'];
    $options = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (! is_string($arg) || ! preg_match('/^--([a-z-]+)(?:=(.*))?$/D', $arg, $match)
            || ! in_array($match[1], [...$values, ...$flags], true) || array_key_exists($match[1], $options)) {
            throw new SmsProspectCoverageCliException('Unknown or duplicate option; use --help. No apply mode is supported.');
        }
        $flag = in_array($match[1], $flags, true);
        if (($flag && array_key_exists(2, $match)) || (! $flag && (! isset($match[2]) || $match[2] === ''))) {
            throw new SmsProspectCoverageCliException('Use --name=value for value options and bare flags for switches.');
        }
        $options[$match[1]] = $flag ? true : $match[2];
    }
    $options['explicit-batch-size'] = array_key_exists('batch-size', $options);
    foreach (['batch-size' => 500, 'max-batches' => 1] as $key => $default) {
        $raw = $options[$key] ?? (string) $default;
        $digits = ltrim($raw, '0');
        $maximum = $key === 'batch-size' ? '1000' : (string) PHP_INT_MAX;
        if (! preg_match('/^[0-9]+$/D', $raw) || strlen($raw) > 20
            || strlen($digits) > strlen($maximum) || (strlen($digits) === strlen($maximum) && strcmp($digits, $maximum) > 0)
            || ($key === 'batch-size' && $digits === '')) {
            throw new SmsProspectCoverageCliException('Invalid batch options: batch-size must be 1..1000; max-batches must be a bounded nonnegative integer.');
        }
        $options[$key] = (int) $raw;
    }
    $options['root'] ??= getcwd() ?: '';
    $options['env'] ??= rtrim($options['root'], '/\\').'/.env';
    $options['out'] ??= '/home/ubuntu/new-private-output';
    foreach (['root', 'env', 'out'] as $key) {
        $path = str_replace('\\', '/', $options[$key]);
        if (preg_match('/[\x00-\x1f\x7f]/', $path) || str_contains($path, '//')
            || ! preg_match('~^(?:/|[A-Za-z]:/)~D', $path)
            || preg_match('~(?:^|/)\.{1,2}(?:/|$)~D', $path)
            || str_contains(substr($path, preg_match('~^[A-Za-z]:/~', $path) ? 2 : 0), ':')) {
            throw new SmsProspectCoverageCliException('Paths must be absolute local paths without traversal, streams or network shares.');
        }
        $options[$key] = strlen($path) > 1 && ! preg_match('~^[A-Za-z]:/$~D', $path) ? rtrim($path, '/') : $path;
    }
    if ($options['out'] === '/' || preg_match('~^[A-Za-z]:/$~D', $options['out'])) {
        throw new SmsProspectCoverageCliException('Choose a dedicated new private output directory.');
    }
    return $options + ['resume' => false, 'metadata-only' => false, 'help' => false];
}

/** Check each ancestor iteratively, including dangling links and Windows directory junctions. */
function smsCoverageNoLinks(string $path): void
{
    for ($part = $path; ; $part = $parent) {
        clearstatcache(true, $part);
        if (is_link($part)) throw new SmsProspectCoverageCliException('Symlinked audit/configuration paths are forbidden.');
        if (file_exists($part)) {
            $real = realpath($part);
            $expected = str_replace('\\', '/', $part);
            $actual = $real === false ? '' : str_replace('\\', '/', $real);
            $same = PHP_OS_FAMILY === 'Windows' ? strcasecmp($expected, $actual) === 0 : $expected === $actual;
            if (! $same) throw new SmsProspectCoverageCliException('Audit/configuration paths must resolve directly without links or aliases.');
        }
        $parent = str_replace('\\', '/', dirname($part));
        if ($parent === $part) break;
    }
}

function smsCoveragePrivateMode(string $path, int $mode): void
{
    if (! chmod($path, $mode)) throw new SmsProspectCoverageCliException('Cannot enforce private audit permissions.');
    clearstatcache(true, $path);
    if (PHP_OS_FAMILY !== 'Windows' && (fileperms($path) & 0777) !== $mode) {
        throw new SmsProspectCoverageCliException('Audit permissions must be 0700 for directories and 0600 for files.');
    }
}

function smsCoveragePrivateFile(string $path, bool $required = false): void
{
    smsCoverageNoLinks($path);
    $stat = @lstat($path);
    if ($stat === false) {
        if ($required) throw new SmsProspectCoverageCliException('Required audit evidence is missing; use a new directory for a fresh audit.');
        return;
    }
    if (! is_file($path) || (int) $stat['nlink'] !== 1
        || (function_exists('posix_geteuid') && $stat['uid'] !== posix_geteuid())) {
        throw new SmsProspectCoverageCliException('Audit evidence must be an owned regular file with no additional links.');
    }
    smsCoveragePrivateMode($path, 0600);
}

function smsCoverageJournalFiles(string $directory): void
{
    foreach (['coverage.sqlite', 'coverage.sqlite-wal', 'coverage.sqlite-shm', 'coverage.sqlite-journal'] as $name) {
        smsCoveragePrivateFile($directory.'/'.$name);
    }
}

/** WAL needs a local filesystem. Unknown Linux mounts fail closed instead of assuming NFS is safe. */
function smsCoverageLocalStorage(string $directory): void
{
    if (PHP_OS_FAMILY !== 'Linux') {
        throw new SmsProspectCoverageCliException('Scanning requires verified local Linux storage for the private SQLite WAL journal.');
    }
    $mounts = @file('/proc/self/mountinfo', FILE_IGNORE_NEW_LINES);
    if ($mounts === false) throw new SmsProspectCoverageCliException('Cannot verify local journal storage; retry manually after checking the output mount.');
    $longest = -1; $filesystem = '';
    foreach ($mounts as $line) {
        $halves = explode(' - ', $line, 2);
        $fields = explode(' ', $halves[0]);
        if (count($halves) !== 2 || ! isset($fields[4])) continue;
        $mount = preg_replace_callback('/\\\\([0-7]{3})/', static fn (array $m): string => chr(octdec($m[1])), $fields[4]);
        if (($mount === '/' || $directory === $mount || str_starts_with($directory, $mount.'/')) && strlen($mount) > $longest) {
            $longest = strlen($mount); $filesystem = explode(' ', $halves[1], 2)[0];
        }
    }
    if (! in_array($filesystem, ['ext2', 'ext3', 'ext4', 'xfs', 'btrfs', 'zfs', 'overlay', 'f2fs', 'bcachefs', 'jfs', 'reiserfs', 'ufs'], true)) {
        throw new SmsProspectCoverageCliException('The journal must use verified local persistent storage; network or unknown filesystems are forbidden.');
    }
}

function smsCoverageDiskHeadroom(string $directory): void
{
    clearstatcache(true, $directory);
    $free = @disk_free_space($directory);
    if ($free === false || ! is_finite($free) || $free < 8 * 1024 * 1024 * 1024) {
        throw new SmsProspectCoverageCliException('At least 8 GiB of free local disk is required before each batch; free space and retry manually with --resume.');
    }
}

function smsCoverageConfiguration(array $options): array
{
    try {
        $autoload = $options['root'].'/vendor/autoload.php';
        smsCoverageNoLinks($autoload);
        smsCoverageNoLinks($options['env']);
        if (! is_file($autoload) || ! is_file($options['env'])) throw new RuntimeException();
        require_once $autoload;
        $text = @file_get_contents($options['env']);
        if ($text === false) throw new RuntimeException();
        $env = Dotenv\Dotenv::parse($text);
        unset($text);
    } catch (Throwable) {
        throw new SmsProspectCoverageCliException('Database configuration could not be read safely; no contents were printed.');
    }
    if (($env['CMD_DB_HOST'] ?? '') !== 'serverlibertydebtrelief.database.windows.net'
        || ($env['CMD_DB_DATABASE'] ?? '') !== 'CommissionDatabase') {
        throw new SmsProspectCoverageCliException('This CLI is pinned to the reviewed Azure server/database.');
    }
    if (! is_string($env['CMD_DB_USERNAME'] ?? null) || $env['CMD_DB_USERNAME'] === ''
        || ! is_string($env['CMD_DB_PASSWORD'] ?? null) || $env['CMD_DB_PASSWORD'] === '') {
        throw new SmsProspectCoverageCliException('Required database credentials are unavailable; no configuration values were printed.');
    }
    return [$env['CMD_DB_USERNAME'], $env['CMD_DB_PASSWORD']];
}

function smsCoverageToolHash(): string
{
    $hashes = '';
    foreach ([__FILE__, __DIR__.'/sms-prospect-coverage.php', __DIR__.'/sms-prospect-reconciliation.php'] as $path) {
        $hash = @hash_file('sha256', $path);
        if ($hash === false) throw new SmsProspectCoverageCliException('A required audit tool file is unavailable.');
        $hashes .= $hash;
    }
    return hash('sha256', $hashes);
}

function smsCoverageReadManifest(string $path, string $toolHash, array $options): array
{
    smsCoveragePrivateFile($path, true);
    if (filesize($path) > 2 * 1024 * 1024) throw new SmsProspectCoverageCliException('Invalid bounded audit manifest.');
    $manifest = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (! is_array($manifest) || ($manifest['format'] ?? null) !== SmsProspectCoverage::FORMAT
        || ($manifest['read_only'] ?? null) !== true || ($manifest['apply_supported'] ?? null) !== false
        || ! is_string($manifest['tool_sha256'] ?? null) || ! hash_equals($toolHash, $manifest['tool_sha256'])
        || ! is_string($manifest['source_guard'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $manifest['source_guard'])
        || ! is_int($manifest['batch_size'] ?? null) || $manifest['batch_size'] < 1 || $manifest['batch_size'] > 1000
        || ($options['explicit-batch-size'] && $manifest['batch_size'] !== $options['batch-size'])
        || ! is_array($manifest['metadata'] ?? null) || ! is_array($manifest['limitations'] ?? null)
        || ! is_string($manifest['created_utc'] ?? null) || ! is_bool($manifest['metadata_only'] ?? null)) {
        throw new SmsProspectCoverageCliException('Audit tool/manifest/batch-size changed; start a new output directory instead of mixing evidence.');
    }
    foreach (['source_watermark_pk', 'prospect_watermark_pk', 'source_rows'] as $key) {
        if (! is_int($manifest[$key] ?? null) || $manifest[$key] < 0
            || ($key !== 'source_rows' && $manifest[$key] > 2147483647)) {
            throw new SmsProspectCoverageCliException('Invalid audit manifest bounds; start a new output directory.');
        }
    }
    if (! $options['metadata-only'] && $manifest['metadata_only']) {
        throw new SmsProspectCoverageCliException('A metadata-only report has no scan journal; choose a new output directory to scan.');
    }
    return $manifest;
}

/** The existing helper writes a private exclusive temporary file; rename publishes it atomically. */
function smsCoveragePublishManifest(string $directory, array $manifest): void
{
    $final = $directory.'/manifest.json';
    $temporary = $directory.'/.manifest-'.bin2hex(random_bytes(12)).'.partial';
    try {
        SmsProspectReconciliation::privateJson($temporary, $manifest);
        smsCoveragePrivateFile($temporary, true);
        $handle = fopen($temporary, 'r+b');
        if (! $handle) throw new SmsProspectCoverageCliException('Cannot synchronize private audit evidence.');
        try { if (! fsync($handle)) throw new SmsProspectCoverageCliException('Cannot synchronize private audit evidence.'); }
        finally { fclose($handle); }
        if (file_exists($final) || is_link($final) || ! rename($temporary, $final)) {
            throw new SmsProspectCoverageCliException('Cannot publish a new immutable audit manifest.');
        }
    } finally { if (is_file($temporary) && ! is_link($temporary)) unlink($temporary); }
}

function smsCoverageHealth(PDO $source, ?string $guard = null): array
{
    try { $metadata = SmsProspectCoverage::metadata($source); }
    catch (Throwable) { throw new SmsProspectCoverageCliException('Source metadata/schema checks failed safely; investigate before retrying manually.'); }
    if ($guard !== null && ! hash_equals($guard, SmsProspectCoverage::sourceGuard($metadata))) {
        throw new SmsProspectCoverageCliException('Source/schema guard changed; stop and start a new audit directory after investigation.');
    }
    try { SmsProspectCoverage::assertResources($metadata); }
    catch (Throwable) {
        throw new SmsProspectCoverageCliException('Recent resource statistics are unavailable, stale, or CPU/data/log IO reached 80%; retry manually with --resume after pressure clears.');
    }
    return $metadata;
}

/** Explicit numeric projection: never print a manifest, raw state, database row or exception. */
function smsCoverageCounters(array $state, array $manifest): array
{
    foreach (['batches', 'after_pk', 'source_rows'] as $key) {
        if (! is_int($state[$key] ?? null) || $state[$key] < 0) throw new SmsProspectCoverageCliException('Invalid audit counter state.');
    }
    if (! is_bool($state['exhausted'] ?? null) || ! is_array($state['counts'] ?? null)
        || array_keys($state['counts']) !== SmsProspectCoverage::COUNTERS) throw new SmsProspectCoverageCliException('Invalid audit counter state.');
    $counts = [];
    foreach (SmsProspectCoverage::COUNTERS as $key) {
        if (! is_int($state['counts'][$key]) || $state['counts'][$key] < 0) throw new SmsProspectCoverageCliException('Invalid audit counter state.');
        $counts[$key] = $state['counts'][$key];
    }
    foreach (['source_watermark_pk', 'prospect_watermark_pk'] as $key) {
        if (! is_int($manifest[$key] ?? null) || $manifest[$key] < 0) throw new SmsProspectCoverageCliException('Invalid saved audit scope.');
    }
    return ['read_only' => true, 'apply_supported' => false, 'batches' => $state['batches'], 'after_pk' => $state['after_pk'],
        'source_rows' => $state['source_rows'], 'source_watermark_pk' => $manifest['source_watermark_pk'],
        'prospect_watermark_pk' => $manifest['prospect_watermark_pk'], 'counts' => $counts, 'scope_complete' => $state['exhausted']];
}

function smsCoverageMetadataCounters(array $metadata, ?array $manifest = null): array
{
    $resources = [];
    foreach (['age_seconds', 'avg_cpu_percent', 'avg_data_io_percent', 'avg_log_write_percent'] as $key) {
        $value = $metadata['resources'][$key] ?? null;
        if (! is_numeric($value) || ! is_finite((float) $value) || (float) $value < 0) {
            throw new SmsProspectCoverageCliException('Invalid bounded metadata resource counters.');
        }
        $resources[$key] = $key === 'age_seconds' ? (int) $value : (float) $value;
    }
    $bounds = ['source_rows' => $metadata['repair']['Source_Rows'] ?? null,
        'source_max_pk' => $metadata['source_max_pk'] ?? null, 'prospect_max_pk' => $metadata['prospect_max_pk'] ?? null];
    foreach ($bounds as $key => $value) {
        if (! (is_int($value) || (is_string($value) && preg_match('/^[0-9]{1,18}$/D', $value))) || (int) $value < 0) {
            throw new SmsProspectCoverageCliException('Invalid bounded metadata counts.');
        }
        $bounds[$key] = (int) $value;
    }
    return ['read_only' => true, 'apply_supported' => false, 'metadata_only' => true, 'scope_complete' => false]
        + $bounds + ['source_watermark_pk' => $manifest['source_watermark_pk'] ?? $bounds['source_max_pk'],
            'prospect_watermark_pk' => $manifest['prospect_watermark_pk'] ?? $bounds['prospect_max_pk'], 'resources' => $resources];
}

function smsCoverageRun(array $options): int
{
    if ($options['help']) {
        echo "Read-only enriched-source coverage audit; NO apply mode. No application boot or provider calls.\n"
            ."--root=/var/www/cmd-runner --env=/explicit/file (default ROOT/.env)\n"
            ."--out=/home/ubuntu/new-private-output (default; new directory, or existing with --resume)\n"
            ."--batch-size=500 (1..1000) --max-batches=1 (0=all remaining) --resume\n"
            ."--metadata-only (bounded metadata only; no source scan or SQLite schema) --help\n"
            ."Private SQLite/WAL evidence contains sensitive identities and can grow to multiple GiB.\n"
            ."Directory/file permissions: 0700/0600. Require at least 8 GiB free before every batch.\n"
            ."Health checks run initially, at batch boundaries every 30 seconds, and finally.\n"
            ."No automatic retries; retry timeouts/resource pressure manually with --resume.\n"
            ."Stdout is counters only; the caller may redirect it to a private .log file.\n";
        return 0;
    }
    $directory = $options['out'];
    smsCoverageNoLinks($directory);
    if (! is_dir(dirname($directory)) || ($options['resume'] ? ! is_dir($directory) : file_exists($directory))) {
        throw new SmsProspectCoverageCliException('Choose a new output directory with an existing parent; --resume requires an existing audit.');
    }
    $credentials = smsCoverageConfiguration($options);
    $toolHash = smsCoverageToolHash();
    require_once __DIR__.'/sms-prospect-coverage.php';
    if (! $options['resume'] && ! mkdir($directory, 0700)) throw new SmsProspectCoverageCliException('Cannot create private output directory.');
    if (function_exists('posix_geteuid') && fileowner($directory) !== posix_geteuid()) {
        throw new SmsProspectCoverageCliException('The private audit directory must be owned by the current user.');
    }
    smsCoveragePrivateMode($directory, 0700);
    $lockPath = $directory.'/.audit.lock';
    smsCoveragePrivateFile($lockPath, $options['resume']);
    $lock = fopen($lockPath, $options['resume'] ? 'r+b' : 'x+b');
    if (! $lock) throw new SmsProspectCoverageCliException('Cannot open the private audit lock.');
    $journal = null; $locked = false;
    try {
        smsCoveragePrivateMode($lockPath, 0600);
        $stat = lstat($lockPath); $opened = fstat($lock);
        if ($stat === false || $opened === false || $stat['ino'] !== $opened['ino'] || $stat['dev'] !== $opened['dev']
            || ! flock($lock, LOCK_EX | LOCK_NB)) throw new SmsProspectCoverageCliException('Another audit holds the directory lock, or the lock changed.');
        $locked = true;
        smsCoverageJournalFiles($directory);
        $manifest = $options['resume'] ? smsCoverageReadManifest($directory.'/manifest.json', $toolHash, $options) : null;
        if (! $options['metadata-only']) {
            smsCoverageLocalStorage($directory);
            if ($options['resume']) smsCoveragePrivateFile($directory.'/coverage.sqlite', true);
        }
        // The DSN is a constant, not an environment-provided host or a Laravel connection.
        $source = new PDO('sqlsrv:Server=serverlibertydebtrelief.database.windows.net;Database=CommissionDatabase;Encrypt=yes;TrustServerCertificate=no;MultipleActiveResultSets=false',
            $credentials[0], $credentials[1], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::SQLSRV_ATTR_QUERY_TIMEOUT => 60]);
        unset($credentials);
        $source->exec('SET LOCK_TIMEOUT 5000; SET DEADLOCK_PRIORITY LOW; SET TRANSACTION ISOLATION LEVEL SNAPSHOT');
        $metadata = smsCoverageHealth($source, $manifest['source_guard'] ?? null); $checkedAt = hrtime(true);
        $metadataCounts = smsCoverageMetadataCounters($metadata);
        if ($manifest === null) {
            $manifest = ['format' => SmsProspectCoverage::FORMAT, 'read_only' => true, 'apply_supported' => false,
                'batch_size' => $options['batch-size'], 'source_watermark_pk' => $metadataCounts['source_max_pk'],
                'prospect_watermark_pk' => $metadataCounts['prospect_max_pk'], 'source_guard' => SmsProspectCoverage::sourceGuard($metadata),
                'source_rows' => $metadataCounts['source_rows'], 'tool_sha256' => $toolHash, 'metadata' => $metadata,
                'created_utc' => gmdate('c'), 'metadata_only' => $options['metadata-only'], 'limitations' => [
                    'Counts cover enriched-source rows through saved source/prospect PK watermarks; scope is complete only when the cursor is exhausted.',
                    'Eligible normalized identities are globally deduplicated using SQL Server equality. No-contact returns do not enter the identity journal.',
                    'Contact variants are conservative conflict evidence, not a newest-PK selection or automatic repair instruction.',
                    'Each batch uses a short SNAPSHOT; metadata guards cannot detect every in-place change between batches or resumptions.',
                    'Private local SQLite/WAL evidence contains sensitive identities and may require multiple GiB of disk.',
                    'Azure is read-only. No apply, source/prospect writes, application boot, provider calls, queues, cache or availability endpoints.'
                ]];
            smsCoveragePublishManifest($directory, $manifest);
        }
        if ($options['metadata-only']) {
            $final = smsCoverageHealth($source, $manifest['source_guard']);
            echo SmsProspectReconciliation::json(smsCoverageMetadataCounters($final, $manifest))."\n";
            return 0;
        }
        fwrite(STDERR, "Private audit: SQLite/WAL contains sensitive identities and can grow to multiple GiB; keep 0700/0600 permissions and at least 8 GiB free.\n");
        $journalPath = $directory.'/coverage.sqlite';
        if ($options['resume']) smsCoveragePrivateFile($journalPath, true);
        else {
            $file = fopen($journalPath, 'x+b');
            if (! $file) throw new SmsProspectCoverageCliException('Cannot create the private local journal.');
            fclose($file); smsCoveragePrivateFile($journalPath, true);
        }
        $journal = new PDO('sqlite:'.$journalPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        if (strtolower((string) $journal->query('PRAGMA journal_mode=WAL')->fetchColumn()) !== 'wal') {
            throw new SmsProspectCoverageCliException('The local journal requires SQLite WAL mode.');
        }
        $journal->exec('PRAGMA synchronous=FULL; PRAGMA busy_timeout=5000; PRAGMA foreign_keys=ON; PRAGMA trusted_schema=OFF');
        if (! $options['resume']) SmsProspectCoverage::initialize($journal, $manifest);
        $state = SmsProspectCoverage::state($journal, $manifest);
        smsCoverageJournalFiles($directory);
        $completed = 0; $finalChecked = false;
        while (! $state['exhausted'] && ($options['max-batches'] === 0 || $completed < $options['max-batches'])) {
            if (hrtime(true) - $checkedAt >= 30_000_000_000) {
                smsCoverageHealth($source, $manifest['source_guard']); $checkedAt = hrtime(true);
            }
            smsCoverageDiskHeadroom($directory);
            try { $state = SmsProspectCoverage::auditBatch($source, $journal, $manifest); }
            finally { smsCoverageJournalFiles($directory); }
            $completed++;
            $counters = smsCoverageCounters($state, $manifest);
            $finalChecked = $state['exhausted'] || ($options['max-batches'] !== 0 && $completed >= $options['max-batches']);
            if ($finalChecked || hrtime(true) - $checkedAt >= 30_000_000_000) {
                smsCoverageHealth($source, $manifest['source_guard']); $checkedAt = hrtime(true);
            }
            echo SmsProspectReconciliation::json($counters)."\n";
        }
        if (! $finalChecked) smsCoverageHealth($source, $manifest['source_guard']);
        if ($completed === 0) echo SmsProspectReconciliation::json(smsCoverageCounters($state, $manifest))."\n";
    } finally {
        $journal = null;
        try { if ($locked) smsCoverageJournalFiles($directory); }
        finally { if ($locked) flock($lock, LOCK_UN); fclose($lock); }
    }
    return 0;
}

/** Only exceptions constructed here may supply user-facing text. PDO/dependency text is never used. */
function smsCoverageErrorMessage(Throwable $error): string
{
    if ($error instanceof SmsProspectCoverageCliException) return $error->getMessage();
    if ($error instanceof PDOException) return 'Database/local journal request failed or timed out; completed batches remain resumable. Retry manually with --resume after checking resources.';
    return 'Configuration or audit evidence failed a safety/integrity check; no details were printed. Investigate before retrying manually with --resume.';
}

function smsCoverageMain(array $argv): int
{
    $mask = umask(0077);
    set_error_handler(static function (int $severity): bool {
        if (! (error_reporting() & $severity)) return false;
        throw new SmsProspectCoverageCliException('A local configuration/evidence operation failed safely; no diagnostic contents were printed.');
    });
    try { return smsCoverageRun(smsCoverageOptions($argv)); }
    catch (Throwable $error) { throw new SmsProspectCoverageCliException(smsCoverageErrorMessage($error)); }
    finally { restore_error_handler(); umask($mask); }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try { exit(smsCoverageMain($argv)); }
    catch (Throwable $error) { fwrite(STDERR, 'Audit stopped: '.smsCoverageErrorMessage($error)."\n"); exit(1); }
}
