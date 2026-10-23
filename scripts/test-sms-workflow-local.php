<?php

// Disposable SQL Server integration checks. No .env, app bootstrap, production connection,
// purchased data or provider services. The host/port and generated DB prefix are not configurable.
// php scripts/test-sms-workflow-local.php --confirm-local-only
if (! in_array('--confirm-local-only', $argv, true)) {
    fwrite(STDERR, "Pass --confirm-local-only to create/drop a disposable database on 127.0.0.1:14330.\n");
    exit(2);
}
require dirname(__DIR__).'/tests/bootstrap-workspace.php';

use Carbon\Carbon;
use Cmd\Reports\Repositories\SmsProspectExportRepository;
use Cmd\Reports\Services\SmsFeedbackService;
use Cmd\Reports\Services\SmsRunService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Str;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;

$database = 'sms_test_'.bin2hex(random_bytes(6));
$admin = new PDO('sqlsrv:Server=127.0.0.1,14330;Database=master;Encrypt=no;TrustServerCertificate=yes', 'sa', 'LocalDev!2026Pass');
$admin->exec("CREATE DATABASE [{$database}] COLLATE SQL_Latin1_General_CP1_CI_AS");
$files = [];
$checks = 0;
$check = function (bool $condition, string $message) use (&$checks): void {
    if (! $condition) throw new RuntimeException('FAIL '.$message);
    $checks++; echo 'PASS '.$message.PHP_EOL;
};
try {
    $container = new Container;
    Container::setInstance($container);
    $container->instance('config', new Repository(['database' => ['default' => 'sqlsrv']]));
    $durable = in_array('--durable-summary', $argv, true);
    config(['sms-exports.durable_availability_enabled' => $durable, 'sms-exports.mailer_recency_date' => 'Send_Date']);
    $capsule = new Manager($container);
    $capsule->addConnection(['driver' => 'sqlsrv', 'host' => '127.0.0.1,14330', 'database' => $database,
        'username' => 'sa', 'password' => 'LocalDev!2026Pass', 'encrypt' => 'no', 'trust_server_certificate' => 'yes'], 'sqlsrv');
    $capsule->getDatabaseManager()->setDefaultConnection('sqlsrv');
    $container->instance('db', $capsule->getDatabaseManager());
    $container->instance('validator', new Factory(new Translator(new ArrayLoader, 'en'), $container));
    Facade::clearResolvedInstances(); Facade::setFacadeApplication($container);
    $db = $capsule->getConnection('sqlsrv');
    $db->setTransactionManager(new DatabaseTransactionsManager);
    if ($durable) {
        (require dirname(__DIR__).'/database/sms-migrations/2026_10_20_000001_create_sms_availability_summary.php')->up();
        // This test checks business SQL + atomic dirty markers, not queue infrastructure.
        $refresh = Mockery::mock(\Cmd\Reports\Services\SmsDurableAvailabilityService::class);
        $refresh->shouldReceive('request')->andReturn(['state' => 'pending']);
        $container->instance(\Cmd\Reports\Services\SmsDurableAvailabilityService::class, $refresh);
    }
    $db->unprepared('CREATE TABLE TblPhoneNumbers (Phone nvarchar(50), Created_Date date)');
    (require dirname(__DIR__).'/database/sms-migrations/2026_10_12_000001_create_sms_prospects_tables.php')->up();
    (require dirname(__DIR__).'/database/sms-migrations/2026_10_19_000001_complete_sms_workflow.php')->up();
    $db->unprepared(<<<'SQL'
CREATE TABLE TblTUData (PK int IDENTITY PRIMARY KEY, Creation_Date date, ncoaAddr_frstNme varchar(100), ncoaAddr_lastNme varchar(100),
 ncoaAddr_addrLine1Txt varchar(255), ncoaAddr_zipCde varchar(10), derivedFields1_unsecdebtbalance decimal(18,2), Tier_Tag varchar(30));
CREATE TABLE TblMailers (PK int PRIMARY KEY, Client nvarchar(50), Address nvarchar(50), Zip nvarchar(50), Debt_Amount decimal(12,2), Debt_Tier nvarchar(5), Drop_Name nvarchar(50));
CREATE TABLE TblMarketing (Drop_Name nvarchar(50), Debt_Tier nvarchar(5), Send_Date date, SMS_Last_Export_Date date);
INSERT INTO TblMarketing VALUES ('debt-fixture','T2','2026-10-20',NULL);
INSERT INTO TblTUData VALUES ('2026-01-01','Ann','Lee','1 Test St','90210',9000,'Tier1'),
 ('2026-09-01','Ann','Lee','1 Test St','90210-1234',11000,'Tier1'),
 ('2026-10-01','Ann','Lee','1 Test St','90210',11000.01,'Tier1'),
 ('2026-10-01','Cal','Reed','3 Test St','90210',10500,'Tier1');
INSERT INTO TblMailers VALUES (1,'Bob Ray','2 Test St','90210',15000,'T2','debt-fixture'),(2,'Bob Ray','2 Test St','90210',15500,'T2','debt-fixture');
INSERT INTO TblPhoneNumbers VALUES ('12025550110','2026-10-01');
SQL);
    Carbon::setTestNow('2026-10-19 20:00:00');
    $person = function (string $name, string $address, string $phone, string $date, array $extra = []) use ($db): int {
        return (int) $db->table('TblProspects')->insertGetId($extra + ['Client' => $name, 'Address' => $address, 'Zip' => '90210', 'Phone' => $phone, 'Creation_Date' => $date]);
    };
    $ann = $person('Ann Lee', '1 Test St', '2025550101', '2026-10-19');
    $bob = $person('Bob Ray', '2 Test St', '2025550102', '2026-10-18');
    $cal = $person('Cal Reed', '3 Test St', '2025550103', '2026-10-17');
    $household = $person('Dan Reed', '3 Test St', '2025550103', '2026-10-16');
    $person('Eve Repeat', '5 Test St', '2025550105', '2026-10-19', ['Latest_Export_Date' => '2026-10-07', 'SMS_Attempts' => 1]);
    foreach (range(6, 8) as $n) $person('Shared Person'.$n, $n.' Test St', '2025550199', '2026-10-19');
    $person('Known Contact', '10 Test St', '2025550110', '2026-10-19');
    $person('Optout Person', '11 Test St', '2025550111', '2026-10-19', ['Opt_Out' => 1]);
    $person('Invalid Person', '12 Test St', '2025550112', '2026-10-19', ['Invalid_Phone' => 1]);
    $repo = new SmsProspectExportRepository;
    $check($repo->availability() === ['eligible' => 4, 'never_texted' => 3, 'texted_before' => 1], 'rotation, household dedupe, contacts and flags');
    $run = (string) Str::uuid();
    $repo->select($run, 3);
    $check($db->table('TblProspectsExport')->orderBy('PK')->pluck('Prospect_PK')->map(fn ($n) => (int) $n)->all() === [$ann, $bob, $cal], 'never texted and newest first');
    $repo->lookupDebts($run, ['T1' => 10000, 'T2' => 13500]);
    $priced = $db->table('TblProspectsExport')->orderBy('PK')->get();
    $check(array_map(fn ($r) => $r->Debt_Source, $priced->all()) === ['TblTUData', 'TblMailers', 'TblTUData'], 'current dated source only; no fabricated tier debt');
    $check((float) $priced[0]->Debt_Amount === 12000.0 && (int) $priced[0]->Debt_Source_PK === 3, 'newest TU revision, upward rounding and exact FK');
    $drops = $repo->assignDrops($run, \Cmd\Reports\Services\SmsDeliveryPolicy::followingMonday());
    $check(count($drops) === 2 && $drops[0]['Debt_Tier'] === 'T1', 'Tier1 is canonical T1, no duplicate tier drops');
    $file = tempnam(sys_get_temp_dir(), 'sms-workflow-test-'); $files[] = $file;
    $db->table('TblProspects')->where('PK', $ann)->update(['Client' => 'Changed Name', 'Address' => 'Changed Address']);
    $check($repo->writeCsv($run, $file) === 3 && str_contains(file_get_contents($file), 'Ann,"1 Test St"'), 'CSV uses the frozen reviewed name and address');
    $repo->markSent($run, Carbon::now());
    $check($db->table('TblSMSHistory')->where('Request_ID', $run)->count() === 3 && ! $db->table('TblProspectsExport')->exists(), 'confirmed rows move to history');
    $check($db->table('TblSMSAttemptAudit')->where('Request_ID', $run)->count() === 4 && (int) $db->table('TblProspects')->where('PK', $household)->value('SMS_Attempts') === 1, 'audit includes every affected household prospect');
    $check($repo->markSent($run, Carbon::now()) === 0, 'send replay never increments twice');
    $repo->reverseSent($run);
    $check($db->table('TblProspects')->whereIn('PK', [$ann, $bob, $cal, $household])->whereNull('Latest_Export_Date')->where('SMS_Attempts', 0)->count() === 4, 'correction restores exact previous values');
    $legacy = (string) Str::uuid(); $blocked = false;
    try { $repo->reverseSent($legacy); } catch (\Illuminate\Validation\ValidationException) { $blocked = true; }
    $check($blocked, 'legacy correction without a before snapshot fails closed');

    $feedback = new SmsFeedbackService;
    $feedbackId = (string) Str::uuid();
    $input = [['phone' => '+1 (202) 555-0103', 'status' => 'opt_out'], ['phone' => '2025550188', 'status' => 'invalid_phone']];
    $first = $feedback->import($feedbackId, 0, $input, 'test@example.invalid');
    $second = $feedback->import($feedbackId, 0, $input, 'test@example.invalid');
    $check($first['updated'] === 2 && $second['replayed'] && $db->table('TblSMSFeedbackImports')->count() === 1, 'feedback is chunk-idempotent and covers household matches');
    $new = $person('Later Purchase', '18 Test St', '2025550188', '2026-10-19');
    $check($repo->availability()['eligible'] === 3, 'suppression survives a later import of the same phone');
    $check($db->getSchemaBuilder()->hasColumn('TblSMSRuns', 'Progress_Step'), 'additive migration applied without modifying purchased tables');
    (require dirname(__DIR__).'/database/sms-migrations/2026_10_19_000002_create_sms_enrichment_tables.php')->up();
    $enrichment = new \Cmd\Reports\Repositories\SmsEnrichmentRepository;
    $source = ['Client' => 'New Testperson', 'Address' => '30 Test St', 'Zip' => '90210-1234', 'State' => 'ca', 'Tier' => 'Tier2', 'Mailer_PK' => 1001, 'Creation_Date' => '2026-10-12'];
    $summary = $enrichment->stage('fixture-intake-1', [$source, array_replace($source, ['Client' => 'NEW TESTPERSON'])], 'test@example.invalid');
    $check($summary['submitted'] === 1 && $summary['duplicate'] === 1, 'SQL Server weekly intake matches case-insensitively and zip5');
    $vendor = iterator_to_array($enrichment->exportRows('fixture-intake-1'), false);
    $check(count($vendor) === 1 && str_starts_with($vendor[0]['Record_ID'], 'SMSP-') && $vendor[0]['First_Name'] === 'New', 'vendor identity is durable while display-name case is preserved');
    $returned = $enrichment->returned('fixture-intake-1', [['Record_ID' => $vendor[0]['Record_ID'], 'Phone' => '+1 (202) 555-0130', 'Email' => 'valid@example.invalid']], true);
    $check($returned['added'] === 1 && $returned['both'] === 1 && $returned['returned'] === 1, 'a valid TU result admits only source identity with a usable contact');
    $again = $enrichment->returned('fixture-intake-1', [['Record_ID' => $vendor[0]['Record_ID'], 'Phone' => '+1 (202) 555-0130', 'Email' => 'valid@example.invalid']], true);
    $check($again['added'] === 1 && $again['returned'] === 1, 'provider return replay never adds or counts twice');
    $blocked = false;
    try { $enrichment->returned('fixture-intake-1', [['Record_ID' => 'SMSP-999999', 'Phone' => '2025550131']]); }
    catch (\Symfony\Component\HttpKernel\Exception\HttpException) { $blocked = true; }
    $check($blocked, 'unknown provider ID cannot fall back to a mailer or synthetic drop');
    $none = array_replace($source, ['Client' => 'No Returnperson', 'Address' => '31 Test St', 'Mailer_PK' => 1002]);
    $enrichment->stage('fixture-intake-2', [$none], 'test@example.invalid');
    $vendor2 = iterator_to_array($enrichment->exportRows('fixture-intake-2'), false);
    $summary2 = $enrichment->returned('fixture-intake-2', [['Record_ID' => $vendor2[0]['Record_ID'], 'Phone' => '', 'Email' => '']], true);
    $check($summary2['neither'] === 1 && $summary2['added'] === 0, 'no-result people do not become prospects');
    $samePurchase = $enrichment->stage('fixture-intake-3', [$none], 'test@example.invalid');
    $check($samePurchase['submitted'] === 0, 'the same purchased mailer is not paid for again');
    $check(iterator_to_array($enrichment->exportRows('fixture-intake-3'), false) === [], 'fully excluded intake has no provider rows');
    $empty = $enrichment->summary('fixture-intake-3');
    $check($empty['status'] === 'completed' && $empty['completed_at'] !== null && $empty['pending'] === 0, 'frozen empty intake completes without waiting for a provider return');
    $repurchase = $enrichment->stage('fixture-intake-4', [array_replace($none, ['Mailer_PK' => 1003])], 'test@example.invalid');
    $check($repurchase['submitted'] === 1, 'a genuinely later purchase may retry no-result enrichment');

    $db->unprepared(<<<'SQL'
CREATE TABLE TblMailersUnique (PK int PRIMARY KEY, Drop_Name nvarchar(50), External_ID nvarchar(50), Debt_Tier nvarchar(5));
CREATE TABLE TblEnrichedDropNames (Drop_Name nvarchar(50), Imported int);
CREATE TABLE TblMailersUniqueEnriched (PK int PRIMARY KEY, Drop_Name nvarchar(50), External_ID nvarchar(50), Client nvarchar(150), Address nvarchar(255), City nvarchar(100), State nvarchar(50), Zip nvarchar(20), Zip5_Normalized nvarchar(5), Phone nvarchar(50), Email nvarchar(255));
INSERT INTO TblMailersUnique VALUES (1,'fixture-drop','u1','T1');
INSERT INTO TblMarketing VALUES ('fixture-drop','T1','2026-10-12',NULL);
INSERT INTO TblEnrichedDropNames VALUES ('fixture-drop',2);
INSERT INTO TblMailersUniqueEnriched VALUES
 (1,'fixture-drop','u1','Load Testperson','40 Test St','Test','CA','90210-1234','90210','(202) 555-0140',NULL),
 (2,'fixture-drop','u1','load testperson','40 Test St','Test','CA','90210','90210','2025550140',NULL);
INSERT INTO TblSMSLegacySends (Phone,Send_Date) VALUES ('2025550140','2026-10-08');
SQL);
    $load = new \Cmd\Reports\Repositories\SmsProspectLoadRepository;
    $loaded = $load->loadDrop('fixture-drop', '2026-10-12');
    $check($loaded['added'] === 1 && $load->loadDrop('fixture-drop', '2026-10-12')['added'] === 0, 'repaired-source load deduplicates within and across reruns');
    $check($db->table('TblProspects')->where('Phone', '2025550140')->value('Latest_Export_Date') === '2026-10-08', 'newly loaded people inherit durable historical phone send dates');
    if ($durable) {
        $ledger = new \Cmd\Reports\Services\SmsAvailabilityLedger;
        $row = $ledger->read();
        $check($row !== null && (int) $row['revision'] >= 6 && $row['counted_at'] === null,
            'business writes persisted summary revisions without inventing counts');
        $before = (int) $row['revision'];
        $feedback->import($feedbackId, 0, $input, 'test@example.invalid');
        $load->loadDrop('fixture-drop', '2026-10-12');
        $check((int) $ledger->read()['revision'] === $before, 'unchanged feedback and source-load replays do not dirty the summary');
    }
    echo "{$checks} local SQL Server checks passed.\n";
} finally {
    foreach ($files as $file) if (is_file($file)) unlink($file);
    if (isset($capsule)) { $capsule->getDatabaseManager()->purge('sqlsrv'); }
    // Only this script's freshly generated database can be dropped; the shared local clone stays intact.
    if (preg_match('/^sms_test_[0-9a-f]{12}$/D', $database)) {
        $admin->exec("ALTER DATABASE [{$database}] SET SINGLE_USER WITH ROLLBACK IMMEDIATE; DROP DATABASE [{$database}]");
    }
}
