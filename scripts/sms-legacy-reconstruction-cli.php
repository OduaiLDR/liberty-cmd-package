<?php

declare(strict_types=1);

require_once __DIR__.'/sms-legacy-reconstruction.php';

/** A pinned one-off operator tool, not an application endpoint or an exact historical undo. */
final class SmsLegacyReconstructionFiles
{
    public static function rows(string $path): Generator
    {
        $input = gzopen($path, 'rb');
        if ($input === false) throw new RuntimeException('Cannot open verified archive.');
        try {
            while (($line = gzgets($input, 1048577)) !== false) {
                if (! str_ends_with($line, "\n")) throw new RuntimeException('Incomplete archive row.');
                yield json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            }
            if (! gzeof($input)) throw new RuntimeException('Archive read failed.');
        } finally { gzclose($input); }
    }

    public static function reserve(string $path)
    {
        if (file_exists($path) || is_link($path)) throw new RuntimeException('Refusing to overwrite an evidence report.');
        $out = fopen($path.'.partial', 'xb');
        if ($out === false) throw new RuntimeException('Cannot create evidence report.');
        if (! chmod($path.'.partial', 0600)) { fclose($out); throw new RuntimeException('Cannot protect evidence report.'); }
        return $out;
    }

    public static function json(string $path, array $data, $reserved = null): void
    {
        $out = $reserved ?? self::reserve($path);
        try {
            $data = json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
            if (fwrite($out, $data) !== strlen($data) || ! fflush($out)) throw new RuntimeException('Incomplete evidence report.');
        } finally { fclose($out); }
        if (! link($path.'.partial', $path) || ! unlink($path.'.partial')) throw new RuntimeException('Cannot publish evidence report.');
    }

    public static function backup(string $path): array
    {
        echo 'Strictly verifying the complete safety export'.PHP_EOL;
        SmsRecoverySnapshot::verify($path);
        $manifest = json_decode(file_get_contents($path.'/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        if ($manifest['role'] !== 'current'
            || $manifest['files']['prospects_tracking']['sha256'] !== '73ddd91a13d7bac7eee2507a160edea71a6a09c42201d9fbfd9722af4bb794c9'
            || $manifest['files']['target_history']['sha256'] !== 'b13af14cdc1591c9d94f36f84d36a67488244ce5394030c324036fafa837517d'
            || $manifest['files']['prospects_tracking']['rows'] !== 8576308) throw new RuntimeException('This is not the reviewed, verified safety export.');
        return $manifest;
    }

    public static function plan(string $backup, string $directory): array
    {
        $manifest = self::backup($backup);
        if (file_exists($directory) || is_link($directory) || ! is_dir(dirname($directory))) throw new RuntimeException('Choose a new private plan directory.');
        $mask = umask(0077);
        try { if (! mkdir($directory, 0700)) throw new RuntimeException('Cannot create private plan directory.'); }
        finally { umask($mask); }
        $expected = SmsLegacyReconstruction::production();
        $phones = []; $historyHash = hash_init('sha256'); $count = 0; $previous = 0;
        echo 'Pinning all 3M history memberships from the safety export'.PHP_EOL;
        foreach (self::rows($backup.'/target_history.jsonl.gz') as $row) {
            if (strtolower($row['Request_ID']) !== SmsLegacyReconstruction::RUN || (int) $row['PK'] <= $previous
                || ! preg_match('/\A[0-9]{10}\z/D', $row['Phone']) || isset($phones[$row['Phone']])
                || ! str_starts_with($row['Export_Date'], '2026-10-15')) throw new RuntimeException('Unexpected historical membership.');
            $previous = (int) $row['PK']; $phones[$row['Phone']] = false; $count++;
            hash_update($historyHash, json_encode(SmsLegacyReconstruction::canonical($row, 'history'), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n");
        }
        if ($count !== 3000000 || count($phones) !== 3000000) throw new RuntimeException('Wrong history population.');
        $expected['history_fingerprint'] = ['rows' => $count, 'sha256' => hash_final($historyHash)];
        $trackingHash = hash_init('sha256'); $afterHash = hash_init('sha256'); $total = 0; $groups = [1 => 0,2 => 0];
        $entry = SmsRecoverySnapshot::writeRows($directory.'/cohort.jsonl.gz', (function () use ($backup,&$phones,&$trackingHash,&$afterHash,&$total,&$groups): Generator {
            $previous = 0;
            foreach (self::rows($backup.'/prospects_tracking.jsonl.gz') as $row) {
                if ((int) $row['PK'] <= $previous) throw new RuntimeException('Unsorted tracking backup.');
                $previous = (int) $row['PK']; $total++;
                hash_update($trackingHash, json_encode(SmsLegacyReconstruction::canonical($row,'tracking'), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n");
                $after = $row;
                if ($row['Phone'] !== null && array_key_exists($row['Phone'], $phones)) {
                    $attempts = (int) $row['SMS_Attempts'];
                    if ($row['Latest_Export_Date'] !== '2026-10-15' || ! in_array($attempts,[1,2],true)) throw new RuntimeException('Unexpected cohort baseline.');
                    $phones[$row['Phone']] = true; $groups[$attempts]++;
                    $proposed = SmsLegacyReconstruction::proposed($attempts);
                    $after['SMS_Attempts'] = $proposed['attempts']; $after['Latest_Export_Date'] = $proposed['date'];
                    yield ['PK' => (int) $row['PK'], 'Phone' => $row['Phone'], 'Expected_Date' => '2026-10-15','Expected_Attempts' => $attempts];
                }
                hash_update($afterHash, json_encode(SmsLegacyReconstruction::canonical($after,'tracking'), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n");
            }
        })());
        if ($groups !== [1 => 3217615, 2 => 4657] || $total !== 8576308 || in_array(false, $phones, true)) throw new RuntimeException('The complete cohort does not match the approved populations.');
        unset($phones);
        $expected['tracking_fingerprint'] = ['rows' => $total, 'sha256' => hash_final($trackingHash)];
        $expected['after_tracking_fingerprint'] = ['rows' => $total, 'sha256' => hash_final($afterHash)];
        SmsRecoverySnapshot::verifyArchive($directory.'/cohort.jsonl.gz',$entry);
        $plan = ['type' => 'assumption_based_reconstruction', 'policy' => SmsLegacyReconstruction::POLICY,
            'created_utc' => gmdate('c'), 'authorization' => 'Monther: bro imagine he didnt change and go a head (19 Oct 2026). Not proof of historical before-values.',
            'assumptions' => 'No intervening phone/tracking changes since the mistaken send; only Oct 7/8 earlier inputs. Reviewed original Oct 8 file phone set is disjoint from target. Attempts 1 -> NULL/0; attempts 2 -> Oct 7/1.',
            'backup_directory' => realpath($backup), 'backup_sha256' => hash_file('sha256',$backup.'/manifest.json'),
            'expected' => $expected, 'cohort' => $entry];
        self::json($directory.'/plan.json',$plan);
        return ['plan' => $directory.'/plan.json','type' => $plan['type'],'cohort_rows' => $entry['rows'], 'groups' => $groups];
    }

    public static function load(string $path): array
    {
        if (is_link($path)) throw new RuntimeException('Plan must not be a symlink.');
        $plan = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (($plan['type'] ?? '') !== 'assumption_based_reconstruction' || ($plan['policy'] ?? '') !== SmsLegacyReconstruction::POLICY
            || array_intersect_key($plan['expected'] ?? [],SmsLegacyReconstruction::production()) !== SmsLegacyReconstruction::production()) throw new RuntimeException('Plan identity differs from the authorized operation.');
        if (hash_file('sha256',$plan['backup_directory'].'/manifest.json') !== $plan['backup_sha256']) throw new RuntimeException('Backup manifest changed.');
        if (($plan['cohort']['file'] ?? '') !== 'cohort.jsonl.gz' || $plan['cohort']['rows'] !== 3222272) throw new RuntimeException('Unexpected cohort archive.');
        foreach (['tracking_fingerprint','history_fingerprint','after_tracking_fingerprint'] as $key) {
            if (! is_int($plan['expected'][$key]['rows'] ?? null) || ! preg_match('/\A[0-9a-f]{64}\z/D',$plan['expected'][$key]['sha256'] ?? '')) throw new RuntimeException('Missing complete evidence fingerprint.');
        }
        SmsRecoverySnapshot::verifyArchive(dirname($path).'/cohort.jsonl.gz',$plan['cohort']);
        return $plan;
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) return;
$options = getopt('', ['make-plan:', 'output:', 'plan:', 'preview', 'apply', 'accept-assumption-based-reconstruction', 'preview-report:']);
$receipt = null; $committed = false;
try {
    if (isset($options['make-plan'],$options['output']) && ! isset($options['plan'],$options['apply'])) {
        echo json_encode(SmsLegacyReconstructionFiles::plan($options['make-plan'],$options['output']), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
        exit(0);
    }
    if (! isset($options['plan'],$options['output']) || isset($options['preview']) === isset($options['apply'])) throw new RuntimeException('Choose --make-plan=BACKUP --output=NEW_DIRECTORY, or --plan=PLAN --preview|--apply --output=NEW_REPORT.');
    if (isset($options['apply']) && ! isset($options['accept-assumption-based-reconstruction'],$options['preview-report'])) throw new RuntimeException('Apply needs explicit acceptance and a successful preview report.');
    if (file_exists($options['output']) || is_link($options['output']) || ! is_dir(dirname($options['output']))) throw new RuntimeException('Output report must be a new private file.');
    $plan = SmsLegacyReconstructionFiles::load($options['plan']);
    $planSha = hash_file('sha256',$options['plan']);
    $toolSha = hash('sha256',file_get_contents(__FILE__).file_get_contents(__DIR__.'/sms-legacy-reconstruction.php').file_get_contents(__DIR__.'/sms-recovery-snapshot.php'));
    if (isset($options['apply'])) {
        $preview = json_decode(file_get_contents($options['preview-report']),true,512,JSON_THROW_ON_ERROR);
        if (($preview['ready'] ?? false) !== true || $preview['plan_sha256'] !== $planSha || $preview['tool_sha256'] !== $toolSha) throw new RuntimeException('No successful preview of this exact plan and tool exists.');
    }
    // Reserve a private receipt before any apply; publication failure after commit is reported distinctly.
    $receipt = SmsLegacyReconstructionFiles::reserve($options['output']);
    $root = '/var/www/cmd-runner';
    require $root.'/vendor/autoload.php';
    $env = Dotenv\Dotenv::parse(file_get_contents($root.'/.env'));
    if (($env['CMD_DB_HOST'] ?? '') !== 'serverlibertydebtrelief.database.windows.net' || ($env['CMD_DB_DATABASE'] ?? '') !== 'CommissionDatabase') throw new RuntimeException('Unexpected configured database; no connection attempted.');
    $db = new PDO('sqlsrv:Server=serverlibertydebtrelief.database.windows.net;Database=CommissionDatabase;Encrypt=yes;TrustServerCertificate=no;MultipleActiveResultSets=false',
        $env['CMD_DB_USERNAME'] ?? '',$env['CMD_DB_PASSWORD'] ?? '',[PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::SQLSRV_ATTR_QUERY_TIMEOUT => 3600,PDO::SQLSRV_ATTR_ENCODING => PDO::SQLSRV_ENCODING_UTF8]);
    $identity = $db->query('SELECT @@SERVERNAME AS Server_Name,DB_NAME() AS Database_Name,(SELECT compatibility_level FROM sys.databases WHERE name=DB_NAME()) AS Compatibility')->fetch(PDO::FETCH_ASSOC);
    if ($identity['Server_Name'] !== 'serverlibertydebtrelief' || $identity['Database_Name'] !== 'CommissionDatabase' || (int) $identity['Compatibility'] < 130) throw new RuntimeException('Database identity/compatibility check failed.');
    $capacity = $db->query("SELECT CONVERT(bigint,DATABASEPROPERTYEX(DB_NAME(),'MaxSizeInBytes')) AS Max_Bytes,(SELECT SUM(CONVERT(bigint,size))*8192 FROM sys.database_files WHERE type=0) AS Allocated_Bytes,(SELECT used_log_space_in_percent FROM sys.dm_db_log_space_usage) AS Log_Used_Percent")->fetch(PDO::FETCH_ASSOC);
    if ((int) $capacity['Max_Bytes']-(int) $capacity['Allocated_Bytes'] < 2147483648 || (float) $capacity['Log_Used_Percent'] > 70) throw new RuntimeException('Insufficient safe data/log headroom; no correction attempted.');
    $artifact = $root.'/storage/app/private/sms-exports/runs/'.SmsLegacyReconstruction::RUN.'/sms-2026-10-15.csv';
    if (is_link($artifact) || filesize($artifact) !== $plan['expected']['file_bytes'] || hash_file('sha256',$artifact) !== $plan['expected']['file_sha']) throw new RuntimeException('The physical review file is missing or changed.');
    $tool = new SmsLegacyReconstruction($db,$plan['expected']);
    echo 'Staging the 3,222,272-row cohort in connection-local temp storage; complete before fingerprints remain mandatory'.PHP_EOL;
    $prior = $db->query("SELECT OBJECT_ID('dbo.TblSMSLegacyCorrectionRuns')")->fetchColumn();
    $applied = false;
    if ($prior) {
        $q = $db->prepare("SELECT COUNT(*) FROM dbo.TblSMSLegacyCorrectionRuns WHERE Request_ID=? AND Status='applied'");
        $q->execute([SmsLegacyReconstruction::RUN]); $applied = (int) $q->fetchColumn() === 1; $q->closeCursor();
    }
    if (! ($applied && isset($options['apply']))) $tool->stageFromLive();
    if (isset($options['preview'])) {
        $db->exec('SET TRANSACTION ISOLATION LEVEL SNAPSHOT'); $db->beginTransaction();
        try { $result = $tool->check(); $db->commit(); }
        catch (Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
        unset($result['run_before']);
    } else {
        echo 'Applying only the guarded correction; SQL table locks temporarily fence SMS activity'.PHP_EOL;
        $result = $tool->apply(['accepted_assumptions' => true,'backup_sha256' => $plan['backup_sha256'],'plan_sha256' => $planSha]);
        $committed = true;
    }
    $result += ['plan_sha256' => $planSha,'tool_sha256' => $toolSha,'completed_utc' => gmdate('c'),'type' => 'assumption_based_reconstruction'];
    SmsLegacyReconstructionFiles::json($options['output'],$result,$receipt);
    echo json_encode($result,JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    if (is_resource($receipt)) fclose($receipt);
    if ($committed) {
        fwrite(STDERR,'COMMITTED: correction '.SmsLegacyReconstruction::RUN.' succeeded, but the receipt could not be published. Verify dbo.TblSMSLegacyCorrectionRuns; do not reapply manually.'.PHP_EOL);
        exit(3);
    }
    // Do not print SQL bindings, credentials or arbitrary row contents.
    fwrite(STDERR,'Stopped: '.($error instanceof PDOException ? 'Database safety/SQL failure ('.$error->getCode().'). Verify the correction audit/run status before retrying; do not assume a commit outcome after a lost connection.' : $error->getMessage()).PHP_EOL);
    exit(1);
}
