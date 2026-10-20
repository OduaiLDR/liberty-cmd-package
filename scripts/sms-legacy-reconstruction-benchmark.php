<?php

declare(strict_types=1);

// SELECT-only 100k-row equivalence/performance probe; no application boot or persistent writes.
require_once __DIR__.'/sms-legacy-reconstruction-cli.php';
require '/var/www/cmd-runner/vendor/autoload.php';
$env = Dotenv\Dotenv::parse(file_get_contents('/var/www/cmd-runner/.env'));
if (($env['CMD_DB_HOST'] ?? '') !== 'serverlibertydebtrelief.database.windows.net' || ($env['CMD_DB_DATABASE'] ?? '') !== 'CommissionDatabase') throw new RuntimeException('Unexpected database.');
$first = (function (): Generator {
    $count = 0;
    foreach (SmsLegacyReconstructionFiles::rows('/home/ubuntu/sms-recovery-current-20261019T2050Z/prospects_tracking.jsonl.gz') as $row) {
        yield $row;
        if (++$count === 100000) break;
    }
})();
$expected = SmsLegacyReconstruction::fingerprint($first,'tracking');
$db = new PDO('sqlsrv:Server=serverlibertydebtrelief.database.windows.net;Database=CommissionDatabase;Encrypt=yes;TrustServerCertificate=no;MultipleActiveResultSets=false',
    $env['CMD_DB_USERNAME'] ?? '',$env['CMD_DB_PASSWORD'] ?? '',[PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::SQLSRV_ATTR_QUERY_TIMEOUT => 120,PDO::SQLSRV_ATTR_ENCODING => PDO::SQLSRV_ENCODING_UTF8]);
$started = microtime(true);
$query = $db->query('SELECT TOP(100000) PK,Client,Address,Zip,Phone,Creation_Date,Latest_Export_Date,SMS_Attempts,Contacted,Invalid_Phone,Opt_Out,Response_Date FROM dbo.TblProspects ORDER BY PK OPTION(MAXDOP 1)');
$actual = SmsLegacyReconstruction::fingerprint((function () use ($query): Generator {
    while (($row = $query->fetch(PDO::FETCH_ASSOC)) !== false) {
        $row['Identity_SHA256'] = SmsRecoverySnapshot::identityHash($row); yield $row;
    }
})(),'tracking');
$query->closeCursor();
echo json_encode(['read_only' => true,'rows' => $actual['rows'],'seconds' => round(microtime(true)-$started,3),'same_fingerprint' => $expected === $actual,'mars_enabled' => false],JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
