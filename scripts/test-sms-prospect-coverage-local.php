<?php

declare(strict_types=1);

// Explicit disposable local SQL Server only. No .env, application boot, provider or live connection.
if (count($argv) !== 2 || $argv[1] !== '--confirm-local-only') { fwrite(STDERR, "Pass --confirm-local-only for disposable local SQL Server checks.\n"); exit(2); }
$password = getenv('SMS_LOCAL_SQL_PASSWORD');
if (! is_string($password) || $password === '') { fwrite(STDERR, "Set SMS_LOCAL_SQL_PASSWORD for the existing local SQL Server fixture.\n"); exit(2); }
require_once __DIR__.'/sms-prospect-coverage.php';
$name = 'sms_coverage_test_'.bin2hex(random_bytes(8));
$dsn = 'sqlsrv:Server=127.0.0.1,14330;Encrypt=no;TrustServerCertificate=yes;MultipleActiveResultSets=false;Database=';
$admin = new PDO($dsn.'master', 'sa', $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$admin->exec("CREATE DATABASE [{$name}] COLLATE SQL_Latin1_General_CP1_CI_AS");
$checks = 0;
$check = function (bool $valid, string $message) use (&$checks): void {
    if (! $valid) throw new RuntimeException('FAIL: '.$message);
    $checks++; echo 'PASS: '.$message."\n";
};
$db = null; $journal = null;
try {
    $admin->exec("ALTER DATABASE [{$name}] SET ALLOW_SNAPSHOT_ISOLATION ON");
    $db = new PDO($dsn.$name, 'sa', $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::SQLSRV_ATTR_QUERY_TIMEOUT => 15]);
    unset($password);
    $db->exec(<<<'SQL'
CREATE TABLE dbo.TblProspects (PK int NOT NULL PRIMARY KEY,Client varchar(150),Address varchar(200),City varchar(100),State char(2),Zip varchar(10),
 Phone varchar(10),Email varchar(254),Creation_Date date,Contacted bit NOT NULL DEFAULT 0,Response_Date date,
 Invalid_Phone bit NOT NULL DEFAULT 0,Opt_Out bit NOT NULL DEFAULT 0,Latest_Export_Date date,SMS_Attempts int NOT NULL DEFAULT 0);
CREATE INDEX IX_Identity ON dbo.TblProspects(Client,Address,Zip);
CREATE TABLE dbo.TblMailersUniqueEnriched (PK int NOT NULL PRIMARY KEY,Client nvarchar(150),Address nvarchar(255),City nvarchar(100),State nvarchar(50),
 Zip nvarchar(20),Zip5_Normalized nvarchar(5),Phone nvarchar(50),Email nvarchar(255),Drop_Name nvarchar(50),External_ID nvarchar(50),
 Latest_Export_Date date,enriched_at date,last_seen_at date);
CREATE INDEX IX_Source_Address ON dbo.TblMailersUniqueEnriched(Address);
SQL);
    $db->exec('CREATE SCHEMA tu_repair_20261014');
    $db->exec('CREATE TABLE tu_repair_20261014.Control(ID int PRIMARY KEY,Status varchar(20),ShadowObjectID int,OriginalObjectID int,PostPublishWrites int)');
    $db->exec("INSERT INTO tu_repair_20261014.Control VALUES(1,'PUBLISHED',OBJECT_ID('dbo.TblMailersUniqueEnriched'),123,0)");
    $db->exec("INSERT INTO dbo.TblProspects(PK,Client,Address,Zip,Phone,Latest_Export_Date,SMS_Attempts,Opt_Out) VALUES(1,'ALICE','1 Test St','00123','2025550101','2026-10-07',3,1)");
    $insert = $db->prepare('INSERT INTO dbo.TblMailersUniqueEnriched(PK,Client,Address,Zip,Zip5_Normalized,Phone,Email,Drop_Name,enriched_at) VALUES(?,?,?,?,?,?,?,?,?)');
    $rows = [
        [1,'Alice','1 Test St','00123','00123','2025550101',null,'A','2026-10-01'],
        [2,'Bob','2 Test St','00123','00123','+1 (202) 555-0102',null,'A','2026-10-01'],
        [3,'Email','3 Test St','00123','00123','bad','EmailOnly@example.test','A','2026-10-01'],
        [4,'Neither','4 Test St','00123','00123',null,null,'A',null],
        [5,'Invalid contacts','5 Test St','00123','00123','bad','invalid@','A',null],
        [6,'Too long',str_repeat('x',201),'00123','00123','2025550106',null,'A',null],
        [7,'客户','7 Test St','00123','00123','2025550107',null,'A',null],
        [8,'bob','2 TEST ST','00123','00123','2025550102',null,'B','2026-10-02'],
        [9,'Bob','2 Test St','00123','00123','2025550999',null,'C','2026-09-01'],
        [10,'Other identity','10 Test St','00123','00123','2025550102',null,'A',null],
        [11,'Invalid zip','11 Test St','1234','1234','2025550111',null,'A',null],
        [12,null,'12 Test St','00123','00123',null,'valid@example.test','A',null],
    ];
    foreach ($rows as $row) $insert->execute($row);
    unset($insert);
    $snapshot = fn () => hash('sha256', SmsProspectReconciliation::json([
        $db->query('SELECT * FROM dbo.TblProspects ORDER BY PK')->fetchAll(PDO::FETCH_ASSOC),
        $db->query('SELECT * FROM dbo.TblMailersUniqueEnriched ORDER BY PK')->fetchAll(PDO::FETCH_ASSOC),
        $db->query('SELECT object_id,name FROM sys.tables ORDER BY object_id')->fetchAll(PDO::FETCH_ASSOC),
    ]));
    $before = $snapshot();
    $db->exec('SET LOCK_TIMEOUT 5000; SET DEADLOCK_PRIORITY LOW; SET TRANSACTION ISOLATION LEVEL SNAPSHOT');
    $metadata = SmsProspectCoverage::metadata($db);
    $check($metadata['source_max_pk'] === 12 && $metadata['prospect_max_pk'] === 1, 'live-like schema/index/collation and both watermarks verified');
    $manifest = ['format' => 1,'read_only' => true,'apply_supported' => false,'batch_size' => 3,
        'source_watermark_pk' => 12,'prospect_watermark_pk' => 1];
    $journal = new PDO('sqlite::memory:');
    SmsProspectCoverage::initialize($journal, $manifest);
    $first = SmsProspectCoverage::auditBatch($db, $journal, $manifest);
    $check($first['source_rows'] === 3 && $first['counts']['unique_missing_people'] === 2 && $first['counts']['unique_existing_people'] === 1, 'first batch keeps phone-only/email-only and distinguishes existing identities');
    $check($first['counts']['email_only_rows'] === 1 && $first['counts']['invalid_phone_rows'] === 1, 'one invalid field never discards the other valid contact');
    $identityBefore = $journal->query('SELECT COUNT(*) FROM identities')->fetchColumn();
    $journal->exec("CREATE TRIGGER simulate_crash BEFORE INSERT ON batches WHEN NEW.number=2 BEGIN SELECT RAISE(ABORT,'synthetic interruption'); END");
    try { SmsProspectCoverage::auditBatch($db, $journal, $manifest); throw new LogicException('Synthetic crash failed'); }
    catch (PDOException) { $check(SmsProspectCoverage::state($journal, $manifest) === $first && $journal->query('SELECT COUNT(*) FROM identities')->fetchColumn() === $identityBefore,
        'checkpoint failure rolls back all local identities and counters; source is unchanged'); }
    $journal->exec('DROP TRIGGER simulate_crash');
    $state = $first;
    while (! $state['exhausted']) $state = SmsProspectCoverage::auditBatch($db, $journal, $manifest);
    $c = $state['counts'];
    $check($state['source_rows'] === 12 && $state['after_pk'] === 12 && $c['unique_contact_people'] === 4 && $c['unique_missing_people'] === 3,
        'global case-insensitive identity dedup spans batches and drops; source rows are not unique people');
    $check($c['invalid_identity_rows'] === 4 && $c['neither_rows'] === 2 && $c['valid_contact_rows'] === 6,
        'overlength/lossy/missing identities and neither-contact rows have separate reconciled counts');
    $check($c['missing_contact_conflicts'] === 1 && (int) $journal->query('SELECT contact_conflict FROM identities WHERE id=2')->fetchColumn() === 1,
        'conflicting contacts are unresolved rather than selected by source PK recency');
    $check((int) $journal->query('SELECT source_rows FROM identities WHERE id=2')->fetchColumn() === 3,
        'repeated identity occurrences are retained in aggregate without duplicated people');
    $evidence = $journal->query('SELECT evidence FROM identities WHERE id=3')->fetchColumn();
    $evidence = json_decode(gzdecode($evidence), true, 512, JSON_THROW_ON_ERROR);
    $check((int) $evidence['PK'] === 3 && $evidence['Phone'] === null && $evidence['Email'] === 'EmailOnly@example.test', 'private representative evidence preserves source PK and normalized email-only outcome');
    $check(SmsProspectCoverage::auditBatch($db, $journal, $manifest) === $state && $before === $snapshot() && ! $db->inTransaction(),
        'finished/repeated reads change no prospect, source, flags, dates, counts or source schema');
    $a = $db->query("SELECT CHECKSUM(CAST('Case Person' AS varchar(150)),CAST('1 Test St' AS varchar(200)),CAST('00123' AS varchar(10))) AS Bucket")->fetch(PDO::FETCH_ASSOC);
    $groups = SmsProspectCoverage::identityGroups($db,
        [['PK'=>200,'Bucket'=>$a['Bucket'],'Client'=>'CASE PERSON','Address'=>'1 TEST ST','Zip'=>'00123']],
        [['id'=>100,'bucket'=>$a['Bucket'],'client'=>'Case Person','address'=>'1 Test St','zip'=>'00123']]);
    $check($groups === [200=>100], 'remote SQL equality—not PHP/SQLite case folding—maps existing canonical identities');
    $collision = $db->query("WITH Numbers AS (
        SELECT TOP(200000) ROW_NUMBER() OVER(ORDER BY a.object_id,b.object_id) AS n FROM sys.all_objects a CROSS JOIN sys.all_objects b
    ), Names AS (
        SELECT CONVERT(varchar(150),CONVERT(varchar(64),HASHBYTES('SHA2_256',CONVERT(varchar(20),n)),2)) AS Client FROM Numbers
    ), Buckets AS (
        SELECT Client,CHECKSUM(Client,CAST('Collision St' AS varchar(200)),CAST('00123' AS varchar(10))) AS Bucket FROM Names
    ) SELECT TOP(1) Bucket,MIN(Client) AS First_Name,MAX(Client) AS Second_Name FROM Buckets
      GROUP BY Bucket HAVING MIN(Client)<>MAX(Client)")->fetch(PDO::FETCH_ASSOC);
    $check((bool) $collision, 'bounded synthetic fixture found two unequal identities sharing a real checksum bucket');
    $groups = SmsProspectCoverage::identityGroups($db, [
        ['PK'=>201,'Bucket'=>$collision['Bucket'],'Client'=>$collision['First_Name'],'Address'=>'Collision St','Zip'=>'00123'],
        ['PK'=>202,'Bucket'=>$collision['Bucket'],'Client'=>$collision['Second_Name'],'Address'=>'Collision St','Zip'=>'00123'],
    ], []);
    ksort($groups);
    $check($groups === [201=>201,202=>202], 'checksum collisions never merge unequal identities');
    try {
        SmsProspectCoverage::identityGroups($db, [['PK'=>201,'Bucket'=>(int)$a['Bucket']+1,'Client'=>'Case Person','Address'=>'1 Test St','Zip'=>'00123']], []);
        throw new LogicException('Invalid bucket accepted');
    } catch (RuntimeException $e) { $check(str_contains($e->getMessage(),'collation guard'), 'mismatched checksum/type/collation evidence fails closed'); }
    $bounded = SmsProspectCoverage::sourceRows($db, 0, 8, 1, 1000);
    $check(count($bounded) === 8 && (int) max(array_column($bounded,'PK')) === 8, 'saved source watermark excludes later rows even with a larger batch size');
    $guard = SmsProspectCoverage::sourceGuard($metadata);
    $db->exec('UPDATE tu_repair_20261014.Control SET PostPublishWrites=1');
    $check($guard !== SmsProspectCoverage::sourceGuard(SmsProspectCoverage::metadata($db)), 'published-repair source changes invalidate the saved audit guard');
    echo "{$checks} disposable SQL Server coverage checks passed; no production connection or admission occurred.\n";
} finally {
    $db = null; $journal = null;
    if (! preg_match('/^sms_coverage_test_[a-f0-9]{16}$/D', $name)) throw new RuntimeException('Unsafe local cleanup target.');
    $admin->exec("ALTER DATABASE [{$name}] SET SINGLE_USER WITH ROLLBACK IMMEDIATE");
    $admin->exec("DROP DATABASE [{$name}]");
}
