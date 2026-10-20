<?php

declare(strict_types=1);

// Explicit disposable local DB only. Never reads .env, boots Laravel or calls a provider.
if (count($argv) !== 2 || $argv[1] !== '--confirm-local-only') { fwrite(STDERR, "Pass --confirm-local-only for disposable SQL Server checks.\n"); exit(2); }
require_once __DIR__.'/sms-prospect-reconciliation.php';
$name = 'sms_reconcile_test_'.bin2hex(random_bytes(8));
$dsn = 'sqlsrv:Server=127.0.0.1,14330;Encrypt=no;TrustServerCertificate=yes;MultipleActiveResultSets=false;Database=';
$admin = new PDO($dsn.'master', 'sa', 'LocalDev!2026Pass', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$admin->exec("CREATE DATABASE [{$name}] COLLATE SQL_Latin1_General_CP1_CI_AS");
$checks = 0;
$check = function (bool $valid, string $message) use (&$checks): void {
    if (! $valid) throw new RuntimeException('FAIL: '.$message);
    $checks++; echo 'PASS: '.$message."\n";
};
$db = null;
try {
    $admin->exec("ALTER DATABASE [{$name}] SET ALLOW_SNAPSHOT_ISOLATION ON");
    $db = new PDO($dsn.$name, 'sa', 'LocalDev!2026Pass', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::SQLSRV_ATTR_QUERY_TIMEOUT => 15]);
    $db->exec(<<<'SQL'
CREATE TABLE dbo.TblProspects (PK int NOT NULL PRIMARY KEY,Client varchar(150),Address varchar(200),City varchar(100),State char(2),Zip varchar(10),
 Phone varchar(10),Email varchar(254),Creation_Date date,Tier varchar(2),Contacted bit NOT NULL DEFAULT 0,Response_Date date,
 Invalid_Phone bit NOT NULL DEFAULT 0,Opt_Out bit NOT NULL DEFAULT 0,Latest_Export_Date date,SMS_Attempts int NOT NULL DEFAULT 0);
CREATE TABLE dbo.TblMailersUniqueEnriched (PK int NOT NULL PRIMARY KEY,Client nvarchar(150),Address nvarchar(255),City nvarchar(100),State nvarchar(50),
 Zip nvarchar(20),Zip5_Normalized nvarchar(5),Phone nvarchar(50),Email nvarchar(255),Drop_Name nvarchar(50));
CREATE INDEX IX_Source_Address ON dbo.TblMailersUniqueEnriched(Address);
SQL);
    $db->exec('CREATE SCHEMA tu_repair_20261014');
    $db->exec('CREATE TABLE tu_repair_20261014.Control(ID int PRIMARY KEY,Status varchar(20),ShadowObjectID int,OriginalObjectID int,PostPublishWrites int)');
    $db->exec("INSERT INTO tu_repair_20261014.Control VALUES(1,'PUBLISHED',OBJECT_ID('dbo.TblMailersUniqueEnriched'),123,0)");
    $p = $db->prepare('INSERT INTO dbo.TblProspects(PK,Client,Address,Zip,Phone,Email,Latest_Export_Date,SMS_Attempts,Opt_Out) VALUES(?,?,?,?,?,?,?,?,?)');
    $s = $db->prepare('INSERT INTO dbo.TblMailersUniqueEnriched(PK,Client,Address,Zip,Zip5_Normalized,Phone,Email,Drop_Name) VALUES(?,?,?,?,?,?,?,?)');
    foreach (range(1, 9) as $n) {
        $p->execute([$n,'Test Person'.$n,$n.' Test St',$n === 8 ? '1234' : '01234','202555010'.$n,'person'.$n.'@example.test','2026-10-07',3,1]);
    }
    $s->execute([10,'TEST PERSON1   ','1 Test St','01234-1234',null,'+1 (202) 555-0101','PERSON1@example.test','fixture']);
    $s->execute([20,'Test Person2','2 Test St','01234','01234','2025550999','changed@example.test','fixture']);
    $s->execute([30,'Test Person3','3 Test St','01234',null,'2025550103','person3@example.test','fixture']);
    $s->execute([31,'Test Person3','3 Test St','01234',null,'2025550003','person3@example.test','fixture']);
    // Leading/truncated addresses are unresolved, never called orphans or guessed matches.
    $s->execute([40,'Test Person4',' 4 Test St','01234',null,'2025550104','person4@example.test','fixture']);
    $s->execute([60,'Test Person6','6 Test St','01234',null,null,null,'fixture']);
    $s->execute([70,'Test Person7','7 Test St','01234',null,'bad','bad','fixture']);
    $s->execute([90,'Test Person9','9 Test St','01234',null,'2025550109','person9@example.test','fixture']);
    unset($p, $s);
    $snapshot = fn () => hash('sha256', json_encode([
        $db->query('SELECT * FROM dbo.TblProspects ORDER BY PK')->fetchAll(PDO::FETCH_ASSOC),
        $db->query('SELECT * FROM dbo.TblMailersUniqueEnriched ORDER BY PK')->fetchAll(PDO::FETCH_ASSOC),
        $db->query('SELECT object_id,name FROM sys.tables ORDER BY object_id')->fetchAll(PDO::FETCH_ASSOC),
    ], JSON_THROW_ON_ERROR));
    $before = $snapshot();
    $db->exec('SET LOCK_TIMEOUT 5000; SET DEADLOCK_PRIORITY LOW; SET TRANSACTION ISOLATION LEVEL SNAPSHOT');
    $metadata = SmsProspectReconciliation::metadata($db);
    $check($metadata['prospect_max_pk'] === 9 && (int) $metadata['repair']['Source_Rows'] === 8, 'metadata verifies published source, indexes and watermark');
    $first = SmsProspectReconciliation::batch($db, 0, 8, 3);
    $check($first['people'] === 3 && $first['last_pk'] === 3, 'SQL TOP and keyset batch limits count people, not duplicate source rows');
    $check($first['counts']['matched_same_contacts'] === 1, 'CI names, trailing spaces, ZIP+4 and formatted phones compare correctly');
    $check($first['counts']['matched_contact_differences'] === 1 && $first['details'][0]['differences'] === ['phone_changed','email_changed'], 'phone/email differences reported');
    $check($first['counts']['ambiguous_source_match'] === 1 && count($first['details'][1]['sources']) === 2, 'multiple source rows remain ambiguous, not newest-PK guesses');
    $second = SmsProspectReconciliation::batch($db, 3, 8, 3);
    $check($second['people'] === 3 && $second['last_pk'] === 6 && $second['counts']['no_indexed_source_match'] === 2, 'leading-space address and missing evidence remain unresolved');
    $check($second['details'][2]['differences'] === ['phone_unsupported','email_unsupported'], 'missing source contact fields do not propose clearing existing fields');
    $last = SmsProspectReconciliation::batch($db, 6, 8, 3);
    $check($last['people'] === 2 && $last['last_pk'] === 8 && $last['counts']['invalid_prospect_identity'] === 1, 'saved watermark excludes newer person PK9 and identifies invalid ZIP');
    $check(in_array('source_phone_unusable', $last['details'][0]['differences'], true), 'unusable source contacts are explicitly reported');
    $check(! $db->inTransaction() && $before === $snapshot(), 'no prospect, source, send counter, flag, identity or schema changed');
    $guard = SmsProspectReconciliation::sourceGuard($metadata);
    $db->exec('UPDATE tu_repair_20261014.Control SET PostPublishWrites=1');
    $check(SmsProspectReconciliation::sourceGuard(SmsProspectReconciliation::metadata($db)) !== $guard, 'repair/source guard detects changed publish metadata');
    $db->exec('DROP INDEX IX_Source_Address ON dbo.TblMailersUniqueEnriched');
    try { SmsProspectReconciliation::metadata($db); throw new LogicException('Missing index was accepted'); }
    catch (RuntimeException $error) { $check(str_contains($error->getMessage(), 'leading-key index'), 'audit refuses to run without its indexed matching path'); }
    echo "{$checks} local SQL Server checks passed. No live connection was made.\n";
} finally {
    $db = null;
    if (! preg_match('/^sms_reconcile_test_[a-f0-9]{16}$/D', $name)) throw new RuntimeException('Unsafe test cleanup target.');
    $admin->exec("ALTER DATABASE [{$name}] SET SINGLE_USER WITH ROLLBACK IMMEDIATE");
    $admin->exec("DROP DATABASE [{$name}]");
}
