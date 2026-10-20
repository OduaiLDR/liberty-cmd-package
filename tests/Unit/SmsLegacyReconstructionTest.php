<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2).'/scripts/sms-recovery-snapshot.php';
require_once dirname(__DIR__, 2).'/scripts/sms-legacy-reconstruction.php';

/** No application bootstrap, environment configuration, production DSN or provider access. */
final class SmsLegacyReconstructionSqlServerFixture
{
    private const SERVER = 'localhost,14330';

    private PDO $admin;
    private ?PDO $db = null;
    private string $database;
    private bool $created = false;

    public function __construct()
    {
        if (! extension_loaded('pdo_sqlsrv')) {
            throw new RuntimeException('These integration tests require the local pdo_sqlsrv extension.');
        }
        $this->database = 'sms_test_'.bin2hex(random_bytes(8));
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::SQLSRV_ATTR_QUERY_TIMEOUT => 60, PDO::SQLSRV_ATTR_ENCODING => PDO::SQLSRV_ENCODING_UTF8];
        $this->admin = new PDO('sqlsrv:Server='.self::SERVER.';Database=master;Encrypt=no;TrustServerCertificate=yes;MultipleActiveResultSets=false',
            'sa', 'LocalDev!2026Pass', $options);
        $this->assertAdmin();
        $this->assertName();
        try {
            $this->admin->exec('CREATE DATABASE ['.$this->database.'] COLLATE SQL_Latin1_General_CP1_CI_AS');
            $this->created = true;
            $this->db = new PDO('sqlsrv:Server='.self::SERVER.';Database='.$this->database.';Encrypt=no;TrustServerCertificate=yes;MultipleActiveResultSets=false',
                'sa', 'LocalDev!2026Pass', $options);
            $this->connection();
            $this->exec(<<<'SQL'
CREATE TABLE dbo.TblProspects (
 PK int NOT NULL PRIMARY KEY, Creation_Date date NULL, Client varchar(150), Address varchar(200),
 City varchar(100), State char(2), Zip varchar(10), Tier varchar(2), Phone varchar(10), Email varchar(254),
 Contacted bit NOT NULL DEFAULT 0, Response_Date date NULL, Invalid_Phone bit NOT NULL DEFAULT 0,
 Opt_Out bit NOT NULL DEFAULT 0, Latest_Export_Date date NULL, SMS_Attempts int NOT NULL DEFAULT 0
);
CREATE INDEX IX_TblProspects_Phone ON dbo.TblProspects(Phone) WHERE Contacted=0 AND Phone IS NOT NULL;
CREATE TABLE dbo.TblSMSRuns (
 PK int NOT NULL PRIMARY KEY, Request_ID uniqueidentifier NOT NULL UNIQUE, Status varchar(20) NOT NULL,
 Selected int NOT NULL, File_Sha256 varchar(64) NOT NULL, File_Bytes bigint NOT NULL,
 File_Path varchar(255) NOT NULL, Created_By varchar(150), Sent_By varchar(150),
 Created_At datetime2 NOT NULL, Updated_At datetime2 NOT NULL, Sent_At datetime2 NULL
);
CREATE TABLE dbo.TblSMSDrops (
 PK int NOT NULL PRIMARY KEY, Drop_Name varchar(32) NOT NULL UNIQUE, Debt_Tier varchar(30),
 Amount_Dropped int NOT NULL, Invoice_Number varchar(100) NULL, Cost decimal(12,2) NULL
);
CREATE TABLE dbo.TblSMSHistory (
 PK bigint NOT NULL PRIMARY KEY, Request_ID uniqueidentifier NOT NULL, SMS_Drop_PK int,
 Drop_Name varchar(32), Prospect_PK int NOT NULL, Phone varchar(10) NOT NULL,
 Export_Date datetime2 NOT NULL, Debt_Amount decimal(12,2), Debt_Tier varchar(30),
 Debt_Source varchar(20), Debt_Source_PK bigint NULL,
 UNIQUE(Request_ID,Prospect_PK), UNIQUE(Request_ID,Phone)
);
CREATE TABLE dbo.TblProspectsExport (
 PK bigint NOT NULL PRIMARY KEY, Request_ID uniqueidentifier NOT NULL, SMS_Drop_PK int,
 Drop_Name varchar(32), Prospect_PK int NOT NULL, Phone varchar(10) NOT NULL
);
INSERT dbo.TblProspects
 (PK,Creation_Date,Client,Address,City,State,Zip,Tier,Phone,Email,Contacted,Response_Date,Invalid_Phone,Opt_Out,Latest_Export_Date,SMS_Attempts)
VALUES
 (1,'2026-10-01','Ann Test','1 Test St','Testville','CA','90210','T1','0202555010','ann@example.test',0,NULL,0,0,'2026-10-15',1),
 (2,'2026-10-02','Ben Test','2 Test St','Testville','CA','90210','T2','2025550102','ben@example.test',0,NULL,0,0,'2026-10-15',2),
 (3,'2026-10-03','Case Kept','1 Test St','Testville','CA','90210','T1','0202555010','case@example.test',1,'2026-10-12',1,1,'2026-10-15',1),
 (4,'2026-10-04','Unrelated Person','4 Test St','Testville','CA','90210','T3','2025550104','other@example.test',1,'2026-10-08',0,1,'2026-10-08',2),
 (5,'2026-10-05','Email Only','5 Test St','Testville','CA','90210','T4',NULL,'email@example.test',0,NULL,0,0,NULL,0);
INSERT dbo.TblSMSDrops VALUES (10,'SMS-TEST-10','T1',1,NULL,NULL),(11,'SMS-TEST-11','T2',1,NULL,0);
SQL);
            $this->exec(<<<'SQL'
INSERT dbo.TblSMSRuns
 (PK,Request_ID,Status,Selected,File_Sha256,File_Bytes,File_Path,Created_By,Sent_By,Created_At,Updated_At,Sent_At)
VALUES (1,?,'sent',2,?,12345,'private/retained-synthetic.csv','synthetic creator','synthetic sender',
 '2026-10-15T18:00:00','2026-10-15T19:10:45','2026-10-15T19:10:45');
SQL, [SmsLegacyReconstruction::RUN, str_repeat('a', 64)]);
            $this->exec(<<<'SQL'
INSERT dbo.TblSMSHistory
 (PK,Request_ID,SMS_Drop_PK,Drop_Name,Prospect_PK,Phone,Export_Date,Debt_Amount,Debt_Tier,Debt_Source,Debt_Source_PK)
VALUES (1,?,10,'SMS-TEST-10',1,'0202555010','2026-10-15T18:50:28',10000,'T1','Tier',NULL),
       (2,?,11,'SMS-TEST-11',2,'2025550102','2026-10-15T18:50:28',13500,'T2','TblMailers',42);
SQL, [SmsLegacyReconstruction::RUN, SmsLegacyReconstruction::RUN]);
        } catch (Throwable $error) {
            $this->close();
            throw $error;
        }
    }

    private function assertName(): void
    {
        if (! preg_match('/\Asms_test_[0-9a-f]{16}\z/D', $this->database)) {
            throw new RuntimeException('Refusing a non-disposable SQL Server target.');
        }
    }

    private function assertAdmin(): void
    {
        if ($this->admin->query('SELECT DB_NAME()')->fetchColumn() !== 'master') {
            throw new RuntimeException('The pinned localhost administration connection changed databases.');
        }
    }

    public function connection(): PDO
    {
        $this->assertName();
        if (! $this->db || $this->db->query('SELECT DB_NAME()')->fetchColumn() !== $this->database) {
            throw new RuntimeException('The connection is not the exact generated disposable database.');
        }
        return $this->db;
    }

    public function freshConnection(): PDO
    {
        $this->connection(); // Independently pin the same generated target, never an environment DSN.
        $fresh = new PDO('sqlsrv:Server='.self::SERVER.';Database='.$this->database.';Encrypt=no;TrustServerCertificate=yes;MultipleActiveResultSets=false',
            'sa', 'LocalDev!2026Pass', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::SQLSRV_ATTR_QUERY_TIMEOUT => 60, PDO::SQLSRV_ATTR_ENCODING => PDO::SQLSRV_ENCODING_UTF8]);
        if ($fresh->query('SELECT DB_NAME()')->fetchColumn() !== $this->database) {
            throw new RuntimeException('Fresh connection did not resolve the exact disposable database.');
        }
        return $fresh;
    }

    public function exec(string $sql, array $bindings = []): void
    {
        $query = $this->connection()->prepare($sql);
        try { $query->execute($bindings); }
        finally { $query->closeCursor(); }
    }

    public function rows(string $sql, array $bindings = []): array
    {
        $query = $this->connection()->prepare($sql);
        try {
            $query->execute($bindings);
            return $query->fetchAll(PDO::FETCH_ASSOC);
        } finally { $query->closeCursor(); }
    }

    public function scalar(string $sql, array $bindings = []): mixed
    {
        $row = $this->rows($sql, $bindings);
        return $row ? reset($row[0]) : null;
    }

    public function expected(array $overrides = []): array
    {
        return array_replace(['run' => SmsLegacyReconstruction::RUN, 'selected' => 2, 'one' => 2, 'two' => 1,
            'file_sha' => str_repeat('a', 64), 'file_bytes' => 12345], $overrides);
    }

    public function baseline(): array
    {
        return [['PK' => 1, 'Phone' => '0202555010', 'Expected_Date' => '2026-10-15', 'Expected_Attempts' => 1],
            ['PK' => 2, 'Phone' => '2025550102', 'Expected_Date' => '2026-10-15', 'Expected_Attempts' => 2],
            ['PK' => 3, 'Phone' => '0202555010', 'Expected_Date' => '2026-10-15', 'Expected_Attempts' => 1]];
    }

    public function core(array $overrides = []): SmsLegacyReconstruction
    {
        return new SmsLegacyReconstruction($this->connection(), $this->expected($overrides));
    }

    public function fingerprints(): array
    {
        $tracking = $this->rows('SELECT PK,Client,Address,Zip,Phone,Creation_Date,Latest_Export_Date,SMS_Attempts,Contacted,Invalid_Phone,Opt_Out,Response_Date FROM dbo.TblProspects ORDER BY PK');
        foreach ($tracking as &$row) $row['Identity_SHA256'] = SmsRecoverySnapshot::identityHash($row);
        unset($row);
        $after = $tracking;
        foreach ($after as &$row) {
            if (in_array((int) $row['PK'], [1, 2, 3], true)) {
                $row['SMS_Attempts'] = (int) $row['SMS_Attempts'] - 1;
                $row['Latest_Export_Date'] = (int) $row['PK'] === 2 ? '2026-10-07' : null;
            }
        }
        unset($row);
        return ['tracking_fingerprint' => SmsLegacyReconstruction::fingerprint($tracking, 'tracking'),
            'after_tracking_fingerprint' => SmsLegacyReconstruction::fingerprint($after, 'tracking'),
            'history_fingerprint' => SmsLegacyReconstruction::fingerprint($this->rows('SELECT * FROM dbo.TblSMSHistory WHERE Request_ID=? ORDER BY PK', [SmsLegacyReconstruction::RUN]), 'history')];
    }

    public function evidence(): array
    {
        return ['accepted_assumptions' => true, 'backup_sha256' => str_repeat('b', 64), 'plan_sha256' => str_repeat('c', 64)];
    }

    public function state(): array
    {
        $state = [];
        foreach (['TblProspects', 'TblSMSRuns', 'TblSMSDrops', 'TblSMSHistory', 'TblProspectsExport'] as $table) {
            $state[$table] = $this->rows('SELECT * FROM dbo.['.$table.'] ORDER BY PK');
        }
        return $state;
    }

    public function assertNoCorrection(): void
    {
        expect($this->scalar("SELECT OBJECT_ID('dbo.TblSMSLegacyCorrectionRuns')"))->toBeNull()
            ->and($this->scalar("SELECT OBJECT_ID('dbo.TblSMSLegacyCorrectionRows')"))->toBeNull()
            ->and($this->connection()->inTransaction())->toBeFalse();
    }

    public function close(): void
    {
        if (! $this->created) return;
        $this->assertName();
        $this->assertAdmin();
        if ($this->db) {
            $this->connection(); // Verify the exact active target before disconnecting/dropping it.
            if ($this->db->inTransaction()) $this->db->rollBack();
            $this->db = null;
        }
        $query = $this->admin->prepare('SELECT name FROM sys.databases WHERE name=?');
        $query->execute([$this->database]);
        $resolved = $query->fetchColumn();
        $query->closeCursor();
        if ($resolved !== $this->database) throw new RuntimeException('Disposable database identity changed; refusing cleanup.');
        $this->admin->exec('ALTER DATABASE ['.$this->database.'] SET SINGLE_USER WITH ROLLBACK IMMEDIATE');
        $this->admin->exec('DROP DATABASE ['.$this->database.']');
        $this->created = false;
    }
}

beforeEach(function (): void {
    $this->legacySql = new SmsLegacyReconstructionSqlServerFixture;
});

afterEach(function (): void {
    if (isset($this->legacySql)) $this->legacySql->close();
});

it('stages a full 10000-row OPENJSON binding larger than 800000 bytes plus a remainder on SQL Server', function (): void {
    $fixture = $this->legacySql;
    $rows = [];
    foreach (range(1, 10001) as $pk) {
        $rows[] = ['PK' => $pk, 'Phone' => str_pad((string) $pk, 10, '0', STR_PAD_LEFT),
            'Expected_Date' => '2026-10-15', 'Expected_Attempts' => $pk === 10001 ? 2 : 1];
    }
    expect(strlen(json_encode(array_slice($rows, 0, 10000), JSON_THROW_ON_ERROR)))->toBeGreaterThan(800000);
    $before = $fixture->state();
    $fixture->core(['one' => 10000, 'two' => 1])->stage($rows);
    expect((int) $fixture->scalar('SELECT COUNT(*) FROM #SMSLegacyBaseline'))->toBe(10001)
        ->and($fixture->scalar('SELECT Phone FROM #SMSLegacyBaseline WHERE PK=1'))->toBe('0000000001')
        ->and((int) $fixture->scalar('SELECT Expected_Attempts FROM #SMSLegacyBaseline WHERE PK=10001'))->toBe(2)
        ->and($fixture->state())->toBe($before);
    $fixture->assertNoCorrection();
});

it('previews without persistent changes and applies household corrections while retaining protected data and evidence', function (): void {
    $fixture = $this->legacySql;
    $before = $fixture->state();
    $core = $fixture->core($fixture->fingerprints());
    $core->stage($fixture->baseline());
    $preview = $core->check();
    expect($preview['ready'])->toBeTrue()->and($preview['total'])->toBe(3)
        ->and($preview['to_never_texted'])->toBe(2)->and($preview['to_oct7_once'])->toBe(1)
        ->and($fixture->state())->toBe($before);
    $fixture->assertNoCorrection();

    $result = $core->apply($fixture->evidence());
    expect($result['applied'])->toBeTrue()->and($result['changed'])->toBe(3);
    $after = $fixture->state();
    foreach ($before['TblProspects'] as $index => $person) {
        $expected = $person;
        if (in_array((int) $person['PK'], [1, 2, 3], true)) {
            $expected['SMS_Attempts'] = (string) ((int) $person['SMS_Attempts'] - 1);
            $expected['Latest_Export_Date'] = (int) $person['PK'] === 2 ? '2026-10-07' : null;
            // Compare actual driver scalar types without assuming PDO returns ints or strings.
            $actual = $after['TblProspects'][$index];
            $actual['SMS_Attempts'] = (string) $actual['SMS_Attempts'];
            expect($actual)->toBe($expected);
        } else {
            expect($after['TblProspects'][$index])->toBe($person);
        }
    }
    foreach (['TblSMSHistory', 'TblSMSDrops', 'TblProspectsExport'] as $table) expect($after[$table])->toBe($before[$table]);
    $run = $after['TblSMSRuns'][0];
    expect($run['Status'])->toBe('discarded');
    foreach ($before['TblSMSRuns'][0] as $key => $value) {
        if (! in_array($key, ['Status', 'Updated_At', 'Sent_By', 'Sent_At'], true)) expect($run[$key])->toBe($value);
    }
    $header = $fixture->rows('SELECT * FROM dbo.TblSMSLegacyCorrectionRuns')[0];
    expect($header['Status'])->toBe('applied')->and((int) $header['Changed_Rows'])->toBe(3)
        ->and($header['Policy'])->toBe(SmsLegacyReconstruction::POLICY)
        ->and($header['Backup_SHA256'])->toBe($fixture->evidence()['backup_sha256'])
        ->and(json_decode($header['Original_Run_JSON'], true, 512, JSON_THROW_ON_ERROR))->toBe($before['TblSMSRuns'][0]);
    $audit = $fixture->rows('SELECT * FROM dbo.TblSMSLegacyCorrectionRows ORDER BY Prospect_PK');
    expect(array_map(fn ($row) => (int) $row['Prospect_PK'], $audit))->toBe([1, 2, 3]);
    foreach ($audit as $row) {
        $person = $before['TblProspects'][(int) $row['Prospect_PK'] - 1];
        expect($row['Observed_Date'])->toBe('2026-10-15')->and((int) $row['Observed_Attempts'])->toBe((int) $person['SMS_Attempts'])
            ->and($row['Phone'])->toBe($person['Phone'])
            ->and((int) $row['Reconstructed_Attempts'])->toBe((int) $person['SMS_Attempts'] - 1)
            ->and($row['Reconstructed_Date'])->toBe((int) $row['Prospect_PK'] === 2 ? '2026-10-07' : null);
    }
    expect($fixture->connection()->inTransaction())->toBeFalse();
});

it('refuses guarded mismatches without changing any data or creating correction tables', function (string $mutation, string $message): void {
    $fixture = $this->legacySql;
    $core = $fixture->core();
    $core->stage($fixture->baseline());
    $fixture->exec($mutation);
    $before = $fixture->state();
    expect(fn () => $core->apply($fixture->evidence()))->toThrow(RuntimeException::class, $message);
    expect($fixture->state())->toBe($before);
    $fixture->assertNoCorrection();
})->with([
    'counter drift' => ["UPDATE dbo.TblProspects SET SMS_Attempts=9 WHERE PK=3", 'Current tracking differs'],
    'date drift' => ["UPDATE dbo.TblProspects SET Latest_Export_Date=NULL WHERE PK=3", 'Current tracking differs'],
    'phone drift' => ["UPDATE dbo.TblProspects SET Phone='2025550199' WHERE PK=3", 'Current tracking differs'],
    'new household' => ["UPDATE dbo.TblProspects SET Phone='0202555010' WHERE PK=4", 'household matches outside'],
    'outside Oct 15 tracking' => ["UPDATE dbo.TblProspects SET Latest_Export_Date='2026-10-15' WHERE PK=4", 'Oct 15 tracking exists outside'],
    'artifact drift' => ["UPDATE dbo.TblSMSRuns SET File_Bytes=12346", 'artifact identity changed'],
    'invoice' => ["UPDATE dbo.TblSMSDrops SET Invoice_Number='SYNTHETIC-INVOICE' WHERE PK=10", 'invoice/cost'],
    'unallocated cost' => ["UPDATE dbo.TblSMSDrops SET Cost=1 WHERE PK=10", 'invoice/cost'],
    'working export' => ["INSERT dbo.TblProspectsExport VALUES (1,'e20ca1c2-a0e6-4e6a-a034-ccb312257d44',10,'SMS-TEST-10',1,'0202555010')", 'working export'],
    'another open run' => ["INSERT dbo.TblSMSRuns SELECT 2,'11111111-1111-4111-8111-111111111111','queued',Selected,File_Sha256,File_Bytes,File_Path,Created_By,Sent_By,Created_At,Updated_At,Sent_At FROM dbo.TblSMSRuns WHERE PK=1", 'Another SMS run is open'],
    'missing history' => ["DELETE dbo.TblSMSHistory WHERE PK=2", 'history membership counts'],
]);

it('rolls back newly created audit DDL and every data change on a forced precommit exception', function (): void {
    $fixture = $this->legacySql;
    $core = $fixture->core($fixture->fingerprints());
    $core->stage($fixture->baseline());
    $before = $fixture->state();
    $probe = (object) ['called' => false];
    expect(fn () => $core->apply($fixture->evidence(), function () use ($fixture, $probe): void {
        $probe->called = true;
        expect((int) $fixture->scalar('SELECT COUNT(*) FROM dbo.TblSMSLegacyCorrectionRows'))->toBe(3)
            ->and($fixture->scalar('SELECT Status FROM dbo.TblSMSRuns'))->toBe('discarded')
            ->and($fixture->connection()->inTransaction())->toBeTrue();
        throw new RuntimeException('forced precommit fixture exception');
    }))->toThrow(RuntimeException::class, 'forced precommit fixture exception');
    expect($probe->called)->toBeTrue()->and($fixture->state())->toBe($before);
    $fixture->assertNoCorrection();
    expect($core->apply($fixture->evidence())['changed'])->toBe(3);
});

it('replays the same applied correction without changing data or duplicating audit rows', function (): void {
    $fixture = $this->legacySql;
    $core = $fixture->core();
    $core->stage($fixture->baseline());
    $core->apply($fixture->evidence());
    $before = $fixture->state();
    $header = $fixture->rows('SELECT * FROM dbo.TblSMSLegacyCorrectionRuns');
    $audit = $fixture->rows('SELECT * FROM dbo.TblSMSLegacyCorrectionRows ORDER BY Prospect_PK');
    expect($core->apply($fixture->evidence()))->toBe(['already_applied' => true, 'changed' => 0, 'run_id' => SmsLegacyReconstruction::RUN])
        ->and($fixture->state())->toBe($before)
        ->and($fixture->rows('SELECT * FROM dbo.TblSMSLegacyCorrectionRuns'))->toBe($header)
        ->and($fixture->rows('SELECT * FROM dbo.TblSMSLegacyCorrectionRows ORDER BY Prospect_PK'))->toBe($audit)
        ->and($fixture->connection()->inTransaction())->toBeFalse();
});

it('refuses duplicate corrections with different evidence or a reopened run', function (string $change): void {
    $fixture = $this->legacySql;
    $core = $fixture->core();
    $core->stage($fixture->baseline());
    $evidence = $fixture->evidence();
    $core->apply($evidence);
    if ($change === 'reopened') $fixture->exec("UPDATE dbo.TblSMSRuns SET Status='sent'");
    else $evidence[$change] = str_repeat('d', 64);
    $before = $fixture->state();
    expect(fn () => $core->apply($evidence))->toThrow(RuntimeException::class);
    expect($fixture->state())->toBe($before)
        ->and((int) $fixture->scalar('SELECT COUNT(*) FROM dbo.TblSMSLegacyCorrectionRows'))->toBe(3)
        ->and($fixture->connection()->inTransaction())->toBeFalse();
})->with(['backup_sha256', 'plan_sha256', 'reopened']);

it('rejects same-count canonical tracking or history drift against complete backup fingerprints', function (string $mutation, string $kind): void {
    $fixture = $this->legacySql;
    $core = $fixture->core($fixture->fingerprints());
    $core->stage($fixture->baseline());
    expect($core->check()['ready'])->toBeTrue();
    $fixture->exec($mutation);
    $before = $fixture->state();
    expect(fn () => $core->apply($fixture->evidence()))->toThrow(RuntimeException::class, 'Live '.$kind.' differs');
    expect($fixture->state())->toBe($before);
    $fixture->assertNoCorrection();
})->with([
    'case-only unrelated identity' => ["UPDATE dbo.TblProspects SET Client='UNRELATED PERSON' WHERE PK=4", 'tracking'],
    'unrelated prior attempts' => ["UPDATE dbo.TblProspects SET SMS_Attempts=3 WHERE PK=4", 'tracking'],
    'contacted flag' => ["UPDATE dbo.TblProspects SET Contacted=1 WHERE PK=1", 'tracking'],
    'invalid flag' => ["UPDATE dbo.TblProspects SET Invalid_Phone=1 WHERE PK=2", 'tracking'],
    'opt-out flag' => ["UPDATE dbo.TblProspects SET Opt_Out=1 WHERE PK=1", 'tracking'],
    'response date' => ["UPDATE dbo.TblProspects SET Response_Date='2026-10-09' WHERE PK=4", 'tracking'],
    'history debt without count changes' => ["UPDATE dbo.TblSMSHistory SET Debt_Amount=10001 WHERE PK=1", 'history'],
    'history source FK without count changes' => ["UPDATE dbo.TblSMSHistory SET Debt_Source_PK=43 WHERE PK=2", 'history'],
]);

it('rolls back audit DDL and tracking when the proposed final full-table fingerprint is incorrect', function (): void {
    $fixture = $this->legacySql;
    $fingerprints = $fixture->fingerprints();
    $fingerprints['after_tracking_fingerprint']['sha256'] = str_repeat('0', 64);
    $core = $fixture->core($fingerprints);
    $core->stage($fixture->baseline());
    expect($core->check()['ready'])->toBeTrue();
    $before = $fixture->state();
    $probe = (object) ['called' => false];
    expect(fn () => $core->apply($fixture->evidence(), function () use ($probe): void {
        $probe->called = true;
    }))->toThrow(RuntimeException::class, 'Final full-table tracking/flag verification failed');
    expect($probe->called)->toBeFalse()->and($fixture->state())->toBe($before);
    $fixture->assertNoCorrection();
});

it('refuses prospect triggers before changing any tracking or creating correction tables', function (): void {
    $fixture = $this->legacySql;
    $core = $fixture->core();
    $core->stage($fixture->baseline());
    $fixture->exec('CREATE TRIGGER dbo.SyntheticProspectTrigger ON dbo.TblProspects AFTER UPDATE AS BEGIN SET NOCOUNT ON; END');
    $before = $fixture->state();
    expect(fn () => $core->apply($fixture->evidence()))->toThrow(RuntimeException::class, 'update scope is not safe');
    expect($fixture->state())->toBe($before);
    $fixture->assertNoCorrection();
});

it('checks legacy drop ownership using the real SMS_Drop_Name schema', function (): void {
    $fixture = $this->legacySql;
    $fixture->exec('CREATE TABLE dbo.TblSmsExports (PK bigint NOT NULL PRIMARY KEY, SMS_Drop_Name varchar(32) NOT NULL, Request_ID uniqueidentifier NOT NULL)');
    $fixture->exec('INSERT dbo.TblSmsExports VALUES (1,?,?)', ['SMS-OLDER-SYNTHETIC', '11111111-1111-4111-8111-111111111111']);
    $core = $fixture->core();
    $core->stage($fixture->baseline());
    expect($core->check()['ready'])->toBeTrue();
    $fixture->exec("UPDATE dbo.TblSmsExports SET SMS_Drop_Name='SMS-TEST-10'");
    $before = $fixture->state();
    expect(fn () => $core->apply($fixture->evidence()))->toThrow(RuntimeException::class, 'legacy export also owns');
    expect($fixture->state())->toBe($before);
    $fixture->assertNoCorrection();
});

it('stageFromLive requires both backup fingerprints and stages every household before rechecking drift on apply', function (): void {
    $fixture = $this->legacySql;
    $fingerprints = $fixture->fingerprints();
    $before = $fixture->state();
    foreach ([[], ['tracking_fingerprint' => $fingerprints['tracking_fingerprint']],
        ['history_fingerprint' => $fingerprints['history_fingerprint']]] as $incomplete) {
        expect(fn () => $fixture->core($incomplete)->stageFromLive())
            ->toThrow(RuntimeException::class, 'Full safety-export fingerprints are required');
        expect($fixture->scalar("SELECT OBJECT_ID('tempdb..#SMSLegacyBaseline')"))->toBeNull();
    }

    $core = $fixture->core($fingerprints);
    $core->stageFromLive();
    $staged = $fixture->rows('SELECT PK,Phone,Expected_Date,Expected_Attempts FROM #SMSLegacyBaseline ORDER BY PK');
    foreach ($staged as &$row) {
        $row['PK'] = (int) $row['PK'];
        $row['Expected_Attempts'] = (int) $row['Expected_Attempts'];
    }
    unset($row);
    expect($staged)->toBe($fixture->baseline()) // Includes PK 3 despite all three exclusion flags.
        ->and($fixture->state())->toBe($before)
        ->and($core->check()['total'])->toBe(3);
    $fixture->assertNoCorrection();

    // Staging/preview cannot authorize a later drift: apply must recheck both full sets.
    foreach ([['UPDATE dbo.TblProspects SET Opt_Out=0 WHERE PK=4',
        'UPDATE dbo.TblProspects SET Opt_Out=1 WHERE PK=4', 'tracking'],
        ['UPDATE dbo.TblSMSHistory SET Debt_Amount=10001 WHERE PK=1',
            'UPDATE dbo.TblSMSHistory SET Debt_Amount=10000 WHERE PK=1', 'history']] as [$mutate, $restore, $kind]) {
        $fixture->exec($mutate);
        $drifted = $fixture->state();
        expect(fn () => $core->apply($fixture->evidence()))->toThrow(RuntimeException::class, 'Live '.$kind.' differs');
        expect($fixture->state())->toBe($drifted);
        $fixture->assertNoCorrection();
        $fixture->exec($restore);
    }
    expect($fixture->state())->toBe($before);

    $result = $core->apply($fixture->evidence());
    $after = $fixture->state();
    expect($result['applied'])->toBeTrue()->and($result['changed'])->toBe(3)
        ->and($after['TblSMSRuns'][0]['Status'])->toBe('discarded')
        ->and($after['TblSMSHistory'])->toBe($before['TblSMSHistory'])
        ->and($after['TblSMSDrops'])->toBe($before['TblSMSDrops'])
        ->and($after['TblProspectsExport'])->toBe([])
        ->and($after['TblProspects'][3])->toBe($before['TblProspects'][3])
        ->and($after['TblProspects'][4])->toBe($before['TblProspects'][4])
        ->and((int) $fixture->scalar('SELECT COUNT(*) FROM dbo.TblSMSLegacyCorrectionRows'))->toBe(3)
        ->and($fixture->connection()->inTransaction())->toBeFalse();
    foreach (['Contacted', 'Invalid_Phone', 'Opt_Out', 'Response_Date', 'Client', 'Address', 'Phone'] as $field) {
        expect($after['TblProspects'][2][$field])->toBe($before['TblProspects'][2][$field]);
    }

    $headers = $fixture->rows('SELECT * FROM dbo.TblSMSLegacyCorrectionRuns');
    $audit = $fixture->rows('SELECT * FROM dbo.TblSMSLegacyCorrectionRows ORDER BY Prospect_PK');
    $fresh = $fixture->freshConnection();
    $query = $fresh->query("SELECT @@SPID AS Session_ID, OBJECT_ID('tempdb..#SMSLegacyBaseline') AS Temp_Table");
    $freshState = $query->fetch(PDO::FETCH_ASSOC);
    $query->closeCursor();
    expect((int) $freshState['Session_ID'])->not->toBe((int) $fixture->scalar('SELECT @@SPID'))
        ->and($freshState['Temp_Table'])->toBeNull();
    // No stage()/stageFromLive() call: the durable idempotence fast path must stand alone.
    expect((new SmsLegacyReconstruction($fresh, $fixture->expected($fingerprints)))->apply($fixture->evidence()))
        ->toBe(['already_applied' => true, 'changed' => 0, 'run_id' => SmsLegacyReconstruction::RUN])
        ->and($fresh->inTransaction())->toBeFalse()
        ->and($fixture->state())->toBe($after)
        ->and($fixture->rows('SELECT * FROM dbo.TblSMSLegacyCorrectionRuns'))->toBe($headers)
        ->and($fixture->rows('SELECT * FROM dbo.TblSMSLegacyCorrectionRows ORDER BY Prospect_PK'))->toBe($audit);
    $fresh = null;
});
