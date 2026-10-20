<?php

declare(strict_types=1);

require_once __DIR__.'/sms-prospect-reconciliation.php';

// Standalone audit only. Never bootstrap Laravel or print PDO errors, SQL, bindings or .env values.
function smsReconciliationMain(array $args): int
{
    $allowed = ['root','env','out','batch-size','max-batches','resume','metadata-only','help']; $options = [];
    foreach (array_slice($args, 1) as $arg) {
        if (! preg_match('/^--([a-z-]+)(?:=(.*))?$/D', $arg, $m) || ! in_array($m[1], $allowed, true) || isset($options[$m[1]])) throw new RuntimeException('Unknown or duplicate option. Use --help.');
        $flag = in_array($m[1], ['resume','metadata-only','help'], true);
        if (($flag && isset($m[2])) || (! $flag && (! isset($m[2]) || $m[2] === ''))) throw new RuntimeException('Use --name=value for value options.');
        $options[$m[1]] = $flag ? true : $m[2];
    }
    if (isset($options['help'])) {
        echo "Read-only prospect/source audit; NO apply mode.\n"
            ."--root=/var/www/cmd-runner --out=/home/ubuntu/sms-reconcile-YYYYMMDD\n"
            ."--metadata-only  Inspect schema/indexes only; no prospect comparison\n"
            ."--batch-size=250 (1..1000) --max-batches=1 (0=all remaining) --resume\n"
            ."--env=/explicit/file optional; defaults to ROOT/.env.\n";
        return 0;
    }
    $size = $options['batch-size'] ?? '250'; $limit = $options['max-batches'] ?? '1';
    if (! preg_match('/^[0-9]+$/D', $size) || (int) $size < 1 || (int) $size > 1000
        || ! preg_match('/^[0-9]{1,8}$/D', $limit)) throw new RuntimeException('Invalid bounded batch options.');
    $root = $options['root'] ?? getcwd(); $directory = $options['out'] ?? '';
    if ($directory === '' || ! str_starts_with($directory, '/') || str_contains($directory, '..') || is_link($directory)) throw new RuntimeException('Choose an absolute private server output directory without parent traversal.');
    require rtrim($root, '/\\').'/vendor/autoload.php';
    try {
        $envText = @file_get_contents($options['env'] ?? rtrim($root, '/\\').'/.env');
        if ($envText === false) throw new RuntimeException('Cannot read configuration.');
        $env = Dotenv\Dotenv::parse($envText);
        unset($envText);
    } catch (Throwable) { throw new RuntimeException('Database configuration could not be read safely; no contents were printed.'); }
    if (($env['CMD_DB_HOST'] ?? '') !== 'serverlibertydebtrelief.database.windows.net' || ($env['CMD_DB_DATABASE'] ?? '') !== 'CommissionDatabase') throw new RuntimeException('This CLI is pinned to the reviewed Azure server/database. Local tests use an explicit disposable connection.');
    $db = new PDO('sqlsrv:Server=serverlibertydebtrelief.database.windows.net;Database=CommissionDatabase;Encrypt=yes;TrustServerCertificate=no;MultipleActiveResultSets=false',
        $env['CMD_DB_USERNAME'] ?? '', $env['CMD_DB_PASSWORD'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::SQLSRV_ATTR_QUERY_TIMEOUT => 60]);
    $db->exec('SET LOCK_TIMEOUT 5000; SET DEADLOCK_PRIORITY LOW; SET TRANSACTION ISOLATION LEVEL SNAPSHOT');
    $metadata = SmsProspectReconciliation::metadata($db); $oldMask = umask(0077);
    try {
        if (! isset($options['resume'])) {
            if (file_exists($directory) || ! is_dir(dirname($directory)) || ! mkdir($directory, 0700)) throw new RuntimeException('Choose a new output directory; use --resume only for an existing audit.');
        } elseif (! is_dir($directory)) throw new RuntimeException('There is no audit to resume.');
        $lockPath = $directory.'/.audit.lock';
        if (is_link($lockPath)) throw new RuntimeException('Audit lock must not be symlinked.');
        $lock = fopen($lockPath, 'c');
        if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('Another audit is using this directory.');
        chmod($lockPath, 0600);
        try {
            $manifestPath = $directory.'/manifest.json';
            $toolHash = hash('sha256', hash_file('sha256', __FILE__).hash_file('sha256', __DIR__.'/sms-prospect-reconciliation.php'));
            if (! isset($options['resume'])) {
                SmsProspectReconciliation::privateJson($manifestPath, ['format' => SmsProspectReconciliation::FORMAT, 'read_only' => true,
                    'apply_supported' => false, 'created_utc' => gmdate('c'), 'batch_size' => (int) $size,
                    'watermark_pk' => $metadata['prospect_max_pk'], 'metadata_only' => isset($options['metadata-only']),
                    'source_guard' => SmsProspectReconciliation::sourceGuard($metadata), 'tool_sha256' => $toolHash, 'metadata' => $metadata,
                    'scope' => 'Existing prospects through the saved PK watermark. This does not count new source-only people.',
                    'matching' => 'Indexed Address equality; trimmed case-insensitive Client and zip5. At most two candidates; multiple rows are ambiguous. Source PK order is NOT enrichment recency.',
                    'limitations' => ['No indexed match is NOT proof of an orphan: leading address whitespace, truncation, identity changes and repair lineage require investigation.',
                        'Email validity is conservative FILTER_VALIDATE_EMAIL. Differences are evidence, not automatic overwrite instructions.',
                        'Per-batch SNAPSHOT only; resumptions can observe later data. Metadata guards cannot detect all in-place changes. Revalidate before any later repair.',
                        'No repair, suppression, history/date/count changes, source writes, missing-prospect load or provider calls.']]);
            }
            if (is_link($manifestPath)) throw new RuntimeException('Manifest must not be symlinked.');
            $manifest = json_decode(file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
            if ($manifest['format'] !== SmsProspectReconciliation::FORMAT || $manifest['read_only'] !== true || $manifest['apply_supported'] !== false
                || ! hash_equals($manifest['tool_sha256'], $toolHash) || ! hash_equals($manifest['source_guard'], SmsProspectReconciliation::sourceGuard($metadata))
                || (isset($options['batch-size']) && $manifest['batch_size'] !== (int) $size)) throw new RuntimeException('Audit tool/source/schema changed; start a new report rather than mixing evidence.');
            if ($manifest['metadata_only'] || isset($options['metadata-only'])) {
                echo SmsProspectReconciliation::json(['read_only' => true, 'metadata_only' => true, 'output' => $directory,
                    'source_rows' => $metadata['repair']['Source_Rows'], 'prospect_max_pk' => $metadata['prospect_max_pk'],
                    'source_guard' => $manifest['source_guard'], 'schema_and_index_checks' => 'passed', 'resources' => $metadata['resources']])."\n";
                return 0;
            }
            $state = SmsProspectReconciliation::journal($directory, $manifest);
            for ($i = 0; ! $state['exhausted'] && ((int) $limit === 0 || $i < (int) $limit); $i++) {
                $before = SmsProspectReconciliation::metadata($db);
                if (! hash_equals($manifest['source_guard'], SmsProspectReconciliation::sourceGuard($before))) throw new RuntimeException('Source/schema changed during audit; stopped without changing data.');
                $resources = $before['resources'];
                if (isset($resources['unavailable']) || (int) $resources['age_seconds'] > 120
                    || (float) $resources['avg_cpu_percent'] >= 80 || (float) $resources['avg_data_io_percent'] >= 80) {
                    throw new RuntimeException('Recent resource statistics are unavailable or CPU/data IO is at least 80%; resume later rather than adding load.');
                }
                $batch = SmsProspectReconciliation::batch($db, $state['after_pk'], $manifest['watermark_pk'], $manifest['batch_size']);
                $after = SmsProspectReconciliation::metadata($db);
                if (! hash_equals($manifest['source_guard'], SmsProspectReconciliation::sourceGuard($after))) throw new RuntimeException('Source/schema changed during batch; the batch was not published.');
                SmsProspectReconciliation::saveBatch($directory, $state['batches'] + 1, $batch, hash_file('sha256', $manifestPath));
                $state['batches']++; $state['after_pk'] = $batch['last_pk']; $state['people'] += $batch['people'];
                foreach ($batch['counts'] as $key => $value) $state['counts'][$key] += $value;
                $state['exhausted'] = $batch['people'] < $manifest['batch_size'] || $batch['last_pk'] === $manifest['watermark_pk'];
                echo SmsProspectReconciliation::json(['read_only' => true, 'batch' => $state['batches'], 'batch_seconds' => $batch['seconds'],
                    'people_checked' => $state['people'], 'through_pk' => $state['after_pk'], 'counts' => $state['counts'], 'scope_complete' => $state['exhausted']])."\n";
            }
            echo SmsProspectReconciliation::json(['read_only' => true, 'output' => $directory, 'state' => $state,
                'warning' => 'Audit evidence only; no prospects changed. No-match/ambiguous records are not classified as deletions.'])."\n";
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    } finally { umask($oldMask); }
    return 0;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try { exit(smsReconciliationMain($argv)); }
    catch (PDOException $error) { fwrite(STDERR, 'Audit stopped: database request failed (SQLSTATE '.$error->getCode().'). No data changes were attempted; completed batches can be resumed.'."\n"); exit(1); }
    catch (Throwable $error) {
        // Suppress dependency/filesystem/parser errors that might contain secret file contents.
        $message = $error instanceof RuntimeException && ! $error instanceof JsonException ? $error->getMessage() : 'Configuration or audit evidence could not be read safely.';
        fwrite(STDERR, 'Audit stopped: '.$message."\n"); exit(1);
    }
}
