<?php

declare(strict_types=1);

/**
 * Read-only, standalone evidence capture for the unused 15 October SMS run.
 * No Laravel boot, DML, DDL, migrations, queue dispatch, restore or apply mode.
 * A current capture is NOT a replacement for a pre-send restore point.
 */
final class SmsRecoverySnapshot
{
    public const RUN = 'e20ca1c2-a0e6-4e6a-a034-ccb312257d44';
    public const BEFORE_UTC = '2026-10-15T18:50:27Z';

    public static function capture(PDO $db, string $directory, string $role): array
    {
        if (! in_array($role, ['current', 'before'], true)) throw new RuntimeException('Invalid snapshot role.');
        if (file_exists($directory) || is_link($directory)) throw new RuntimeException('The output directory must not already exist.');
        if (! is_dir(dirname($directory))) throw new RuntimeException('The private parent directory must already exist.');
        $options = $db->query('SELECT snapshot_isolation_state_desc FROM sys.databases WHERE name=DB_NAME()')->fetch(PDO::FETCH_ASSOC);
        if (($options['snapshot_isolation_state_desc'] ?? '') !== 'ON') throw new RuntimeException('SNAPSHOT isolation must already be ON; this tool will not change database options.');

        $oldMask = umask(0077);
        try {
            if (! mkdir($directory, 0700)) throw new RuntimeException('Cannot create a new private output directory.');
        } finally {
            umask($oldMask);
        }
        $started = gmdate('c');
        $db->exec('SET TRANSACTION ISOLATION LEVEL SNAPSHOT');
        $db->beginTransaction();
        try {
            // The first user-table read establishes a consistent view for every capture below.
            $query = $db->prepare('SELECT * FROM dbo.TblSMSRuns WHERE Request_ID=?');
            $query->execute([self::RUN]);
            $run = $query->fetch(PDO::FETCH_ASSOC);
            $query->closeCursor();
            if (! $run) throw new RuntimeException('The expected export run is missing; investigate before capturing.');
            $query = $db->prepare('SELECT COUNT_BIG(*) FROM dbo.TblSMSHistory WHERE Request_ID=?');
            $query->execute([self::RUN]);
            $historyRows = (int) $query->fetchColumn();
            $query->closeCursor();
            self::assertBaseline($role, $run, $historyRows);
            if ($role === 'before') {
                $query = $db->prepare('SELECT COUNT_BIG(*) AS Rows, COUNT(DISTINCT Phone) AS Phones, COUNT(DISTINCT Prospect_PK) AS People FROM dbo.TblProspectsExport WHERE Request_ID=?');
                $query->execute([self::RUN]);
                $working = $query->fetch(PDO::FETCH_ASSOC);
                $query->closeCursor();
                if (! $working || array_map('intval', array_values($working)) !== [3000000, 3000000, 3000000]) {
                    throw new RuntimeException('The restored working export is incomplete or not unique; investigate restore provenance first.');
                }
            }
            $identity = $db->query('SELECT @@SERVERNAME AS Server_Name, DB_NAME() AS Database_Name, SYSUTCDATETIME() AS Database_UTC')->fetch(PDO::FETCH_ASSOC);
            $manifest = ['format' => 1, 'read_only' => true, 'complete' => false, 'role' => $role,
                'run_id' => self::RUN, 'requested_before_utc' => self::BEFORE_UTC,
                'capture_started_utc' => $started, 'identity' => $identity, 'isolation' => 'SNAPSHOT',
                'tool_sha256' => hash_file('sha256', __FILE__),
                'cohort' => 'All prospect tracking fields for a safety copy; export/history are evidence, not an automatically approved correction cohort.',
                'restore_provenance_verified' => false,
                'target_status' => $run['Status'], 'target_history_rows' => $historyRows,
                'warning' => 'Evidence only. A current snapshot cannot recover earlier SMS values. No apply mode exists.', 'files' => []];

            $sources = [
                'prospects_tracking' => [<<<'SQL'
SELECT PK, Client, Address, Zip, Phone, Creation_Date, Latest_Export_Date, SMS_Attempts,
       Contacted, Invalid_Phone, Opt_Out, Response_Date
FROM dbo.TblProspects ORDER BY PK OPTION (MAXDOP 1)
SQL, [], true],
                'target_history' => ['SELECT PK, Request_ID, SMS_Drop_PK, Drop_Name, Prospect_PK, Phone, Export_Date, Debt_Amount, Debt_Tier, Debt_Source, Debt_Source_PK FROM dbo.TblSMSHistory WHERE Request_ID=? ORDER BY PK OPTION (MAXDOP 1)', [self::RUN], false],
                'sms_runs' => ['SELECT * FROM dbo.TblSMSRuns ORDER BY PK OPTION (MAXDOP 1)', [], false],
                'sms_drops' => ['SELECT * FROM dbo.TblSMSDrops ORDER BY PK OPTION (MAXDOP 1)', [], false],
                'working_export' => ['SELECT PK, Request_ID, SMS_Drop_PK, Drop_Name, Prospect_PK, Phone, Debt_Amount, Debt_Tier, Debt_Source, Debt_Source_PK FROM dbo.TblProspectsExport ORDER BY PK OPTION (MAXDOP 1)', [], false],
            ];
            foreach (['TblSMSDropNames', 'TblSMSExports', 'TblSMSAttemptAudit', 'TblSMSLegacySends', 'TblSMSSuppressions'] as $table) {
                $query = $db->prepare('SELECT OBJECT_ID(?)');
                $query->execute(['dbo.'.$table]);
                $exists = $query->fetchColumn();
                $query->closeCursor();
                if ($exists) $sources[$table] = ['SELECT * FROM dbo.['.$table.'] OPTION (MAXDOP 1)', [], false];
            }
            foreach ($sources as $label => [$sql, $bindings, $tracking]) {
                echo 'Capturing '.$label.' (read-only)'.PHP_EOL;
                $query = $db->prepare($sql);
                $query->execute($bindings);
                $rows = (function () use ($query, $tracking): Generator {
                    while (($row = $query->fetch(PDO::FETCH_ASSOC)) !== false) {
                        if ($tracking) {
                            // Pin identity without duplicating names/addresses in the tracking archive.
                            $row['Identity_SHA256'] = self::identityHash($row);
                            unset($row['Client'], $row['Address'], $row['Zip']);
                        }
                        yield $row;
                    }
                })();
                try {
                    $manifest['files'][$label] = self::writeRows($directory.'/'.$label.'.jsonl.gz', $rows);
                } finally {
                    unset($rows);
                    $query->closeCursor();
                }
                echo 'Saved '.$label.': '.number_format($manifest['files'][$label]['rows']).' rows'.PHP_EOL;
            }
            if ($manifest['files']['target_history']['rows'] !== $historyRows) throw new RuntimeException('History row count changed within the capture.');
            $db->commit();
            foreach ($manifest['files'] as $file) self::verifyArchive($directory.'/'.$file['file'], $file);
            $manifest['capture_finished_utc'] = gmdate('c');
            $manifest['complete'] = true;
            self::assertManifest($manifest);
            self::writePrivateJson($directory.'/manifest.json', $manifest);
            return $manifest;
        } catch (Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            // Partial files intentionally remain private evidence, never advertised as complete.
            throw $error;
        }
    }

    public static function assertBaseline(string $role, array $run, int $historyRows): void
    {
        if (strtolower((string) ($run['Request_ID'] ?? '')) !== self::RUN || (int) ($run['Selected'] ?? 0) !== 3000000) {
            throw new RuntimeException('The export identity or expected selection count does not match.');
        }
        if ($role === 'current' && (($run['Status'] ?? '') !== 'sent' || $historyRows !== 3000000)) {
            throw new RuntimeException('Current run differs from the reviewed legacy send; investigate first.');
        }
        if ($role === 'before' && ($historyRows !== 0 || in_array($run['Status'] ?? '', ['sent', 'not_sent'], true) || ! empty($run['Sent_At']))) {
            throw new RuntimeException('Restore already contains target send evidence; it is not a valid pre-send baseline.');
        }
    }

    public static function identityHash(array $row): string
    {
        return hash('sha256', json_encode([$row['Client'] ?? null, $row['Address'] ?? null, $row['Zip'] ?? null], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    public static function writeRows(string $path, iterable $rows): array
    {
        if (file_exists($path) || is_link($path) || file_exists($path.'.partial') || is_link($path.'.partial')) throw new RuntimeException('Refusing to overwrite evidence.');
        $out = fopen($path.'.partial', 'xb');
        if ($out === false) throw new RuntimeException('Cannot create an exclusive evidence file.');
        try {
            if (! chmod($path.'.partial', 0600)) throw new RuntimeException('Cannot restrict evidence permissions.');
            if (stream_filter_append($out, 'zlib.deflate', STREAM_FILTER_WRITE, ['window' => 31, 'level' => 3]) === false) throw new RuntimeException('Cannot initialize gzip compression.');
            $count = 0;
            $buffer = '';
            foreach ($rows as $row) {
                $buffer .= json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n";
                $count++;
                if (strlen($buffer) >= 65536) { self::writeAll($out, $buffer); $buffer = ''; }
                if ($count % 1000000 === 0) echo '  '.number_format($count).' rows captured'.PHP_EOL;
            }
            if ($buffer !== '') self::writeAll($out, $buffer);
            if (! fflush($out)) throw new RuntimeException('Unable to flush evidence.');
        } finally {
            if (! fclose($out)) throw new RuntimeException('Unable to finish compressed evidence.');
        }
        self::publish($path.'.partial', $path);
        $sha256 = hash_file('sha256', $path);
        $bytes = filesize($path);
        if ($sha256 === false || $bytes === false) throw new RuntimeException('Unable to hash evidence.');
        return ['file' => basename($path), 'rows' => $count, 'bytes' => $bytes, 'sha256' => $sha256];
    }

    public static function verify(string $directory): array
    {
        $manifest = json_decode(file_get_contents($directory.'/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertManifest($manifest);
        foreach ($manifest['files'] as $file) {
            $name = $file['file'] ?? '';
            if (! preg_match('/\A[A-Za-z0-9_]+\.jsonl\.gz\z/D', $name) || is_link($directory.'/'.$name)) throw new RuntimeException('Unsafe archive filename.');
            self::verifyArchive($directory.'/'.$name, $file);
        }
        return ['verified' => true, 'read_only' => true, 'role' => $manifest['role'], 'files' => $manifest['files']];
    }

    public static function assertManifest(array $manifest): void
    {
        if (($manifest['complete'] ?? false) !== true || ($manifest['format'] ?? null) !== 1
            || ($manifest['read_only'] ?? false) !== true || ($manifest['run_id'] ?? '') !== self::RUN
            || ($manifest['isolation'] ?? '') !== 'SNAPSHOT' || ! in_array($manifest['role'] ?? '', ['current', 'before'], true)) {
            throw new RuntimeException('No complete supported manifest exists.');
        }
        $database = $manifest['identity']['Database_Name'] ?? '';
        if (($manifest['identity']['Server_Name'] ?? '') !== 'serverlibertydebtrelief'
            || empty($manifest['identity']['Database_UTC']) || ($manifest['requested_before_utc'] ?? '') !== self::BEFORE_UTC
            || ! preg_match('/\A[0-9a-f]{64}\z/D', $manifest['tool_sha256'] ?? '')
            || empty($manifest['capture_started_utc']) || empty($manifest['capture_finished_utc'])
            || ($manifest['role'] === 'current' && $database !== 'CommissionDatabase')
            || ($manifest['role'] === 'before' && ! preg_match('/\Asms_recovery_20261015_[A-Za-z0-9_]+\z/D', $database))) {
            throw new RuntimeException('Recovery provenance is missing or inconsistent.');
        }
        $started = new DateTimeImmutable($manifest['capture_started_utc']);
        $finished = new DateTimeImmutable($manifest['capture_finished_utc']);
        if ($finished < $started) throw new RuntimeException('Invalid capture timestamp order.');
        foreach (['prospects_tracking', 'target_history', 'sms_runs', 'sms_drops', 'working_export'] as $required) {
            if (! isset($manifest['files'][$required])) throw new RuntimeException('Required evidence is missing: '.$required);
        }
        foreach ($manifest['files'] as $label => $file) {
            if (($file['file'] ?? '') !== $label.'.jsonl.gz' || ! is_int($file['rows'] ?? null) || $file['rows'] < 0
                || ! is_int($file['bytes'] ?? null) || $file['bytes'] < 20 || ! preg_match('/\A[0-9a-f]{64}\z/D', $file['sha256'] ?? '')) {
                throw new RuntimeException('Malformed evidence entry.');
            }
        }
        $expectedHistory = $manifest['role'] === 'current' ? 3000000 : 0;
        if (($manifest['target_history_rows'] ?? null) !== $expectedHistory || $manifest['files']['target_history']['rows'] !== $expectedHistory
            || $manifest['files']['prospects_tracking']['rows'] < 1 || $manifest['files']['sms_runs']['rows'] < 1
            || ($manifest['role'] === 'before' && $manifest['files']['working_export']['rows'] !== 3000000)) {
            throw new RuntimeException('Evidence counts do not match the reviewed run.');
        }
    }

    public static function verifyArchive(string $path, array $file): int
    {
        if (is_link($path) || ! is_file($path) || filesize($path) !== $file['bytes'] || ! hash_equals($file['sha256'], hash_file('sha256', $path))) throw new RuntimeException('Archive integrity mismatch.');
        $input = fopen($path, 'rb');
        if ($input === false) throw new RuntimeException('Cannot open compressed archive.');
        $inflate = inflate_init(ZLIB_ENCODING_GZIP);
        if ($inflate === false) throw new RuntimeException('Cannot initialize strict gzip verification.');
        $count = 0;
        $buffer = '';
        try {
            while (! feof($input)) {
                $compressed = fread($input, 65536);
                if ($compressed === false) throw new RuntimeException('Cannot read compressed evidence.');
                if ($compressed === '') break;
                $warning = false;
                set_error_handler(static function () use (&$warning): bool { $warning = true; return true; }, E_WARNING);
                try { $decoded = inflate_add($inflate, $compressed, ZLIB_SYNC_FLUSH); }
                finally { restore_error_handler(); }
                if ($warning || $decoded === false) throw new RuntimeException('Invalid gzip stream or CRC.');
                $buffer .= $decoded;
                $offset = 0;
                while (($end = strpos($buffer, "\n", $offset)) !== false) {
                    if ($end - $offset > 1048576 || ! is_array(json_decode(substr($buffer, $offset, $end - $offset), true, 512, JSON_THROW_ON_ERROR))) throw new RuntimeException('Invalid archive row.');
                    $count++;
                    $offset = $end + 1;
                }
                $buffer = substr($buffer, $offset);
                if (strlen($buffer) > 1048576) throw new RuntimeException('Archive row is unreasonably large.');
                if (inflate_get_status($inflate) === ZLIB_STREAM_END) break;
            }
            if (inflate_get_status($inflate) !== ZLIB_STREAM_END || inflate_get_read_len($inflate) !== $file['bytes'] || $buffer !== '') throw new RuntimeException('Incomplete gzip archive or trailing data.');
        } finally { fclose($input); }
        if ($count !== $file['rows']) throw new RuntimeException('Archive row count mismatch.');
        return $count;
    }

    private static function publish(string $partial, string $path): void
    {
        // Same-directory hard-link publication cannot replace an existing final file.
        if (! @link($partial, $path)) throw new RuntimeException('Unable to publish evidence without replacement.');
        if (! unlink($partial)) throw new RuntimeException('Unable to remove the completed partial link.');
    }

    private static function writeAll($out, string $buffer): void
    {
        $offset = 0;
        while ($offset < strlen($buffer)) {
            $written = fwrite($out, substr($buffer, $offset));
            if ($written === false || $written === 0) throw new RuntimeException('Incomplete evidence write.');
            $offset += $written;
        }
    }

    private static function writePrivateJson(string $path, array $value): void
    {
        $out = fopen($path.'.partial', 'xb');
        if ($out === false) throw new RuntimeException('Refusing to overwrite a manifest.');
        try {
            if (! chmod($path.'.partial', 0600)) throw new RuntimeException('Cannot restrict manifest permissions.');
            self::writeAll($out, json_encode($value, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
            if (! fflush($out)) throw new RuntimeException('Unable to flush manifest.');
        } finally { if (! fclose($out)) throw new RuntimeException('Unable to close manifest.'); }
        self::publish($path.'.partial', $path);
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) return;
$options = getopt('', ['root:', 'database:', 'output:', 'role:', 'confirm-read-only', 'verify:']);
if (isset($options['verify'])) {
    try { echo json_encode(SmsRecoverySnapshot::verify((string) $options['verify']), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL; }
    catch (Throwable $error) { fwrite(STDERR, $error->getMessage().PHP_EOL); exit(1); }
    exit(0);
}
if (! isset($options['confirm-read-only'], $options['output'], $options['role'])) {
    fwrite(STDERR, "Usage: php sms-recovery-snapshot.php --root=/var/www/cmd-runner --role=current|before --output=/home/ubuntu/NEW-private-directory --confirm-read-only [--database=sms_recovery_20261015_NAME]\nNo restore or apply mode exists.\n");
    exit(2);
}
try {
    $root = rtrim((string) ($options['root'] ?? getcwd()), '/\\');
    require $root.'/vendor/autoload.php';
    $env = Dotenv\Dotenv::parse(file_get_contents($root.'/.env'));
    $host = $env['CMD_DB_HOST'] ?? '';
    $database = (string) ($options['database'] ?? ($env['CMD_DB_DATABASE'] ?? ''));
    $role = (string) $options['role'];
    if ($host !== 'serverlibertydebtrelief.database.windows.net' || ! preg_match('/\A[A-Za-z0-9_]+\z/D', $database)) throw new RuntimeException('Unexpected server/database; no connection attempted.');
    if (($role === 'current' && $database !== 'CommissionDatabase') || ($role === 'before' && ! str_starts_with($database, 'sms_recovery_20261015_'))) throw new RuntimeException('Current/live and before/restored database names must not be confused.');
    $db = new PDO('sqlsrv:Server='.$host.';Database='.$database.';Encrypt=yes;TrustServerCertificate=no',
        $env['CMD_DB_USERNAME'] ?? '', $env['CMD_DB_PASSWORD'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::SQLSRV_ATTR_QUERY_TIMEOUT => 1800, PDO::SQLSRV_ATTR_ENCODING => PDO::SQLSRV_ENCODING_UTF8]);
    $manifest = SmsRecoverySnapshot::capture($db, (string) $options['output'], $role);
    echo json_encode(['complete' => true, 'read_only' => true, 'directory' => $options['output'],
        'role' => $role, 'files' => $manifest['files']], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    if ($error instanceof PDOException) fwrite(STDERR, 'SQL operation failed (SQLSTATE '.$error->getCode().'). No credentials were printed; no database rows were written.'.PHP_EOL);
    else fwrite(STDERR, $error->getMessage().PHP_EOL);
    exit(1);
}
