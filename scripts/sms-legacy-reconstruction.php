<?php

declare(strict_types=1);

require_once __DIR__.'/sms-recovery-snapshot.php';

/**
 * One-off, explicitly assumption-based reconstruction of the unused Oct 15 campaign.
 * This is NOT a recovered historical before-image and must not populate TblSMSAttemptAudit.
 * Normal mode is a preview. The CLI pins the host, database, request and approved populations.
 */
final class SmsLegacyReconstruction
{
    public const RUN = 'e20ca1c2-a0e6-4e6a-a034-ccb312257d44';
    public const POLICY = 'unused-oct15-assumed-no-intervening-changes-v1';
    private const AFTER_SQL = "CASE WHEN b.Expected_Attempts=2 THEN CONVERT(date,'2026-10-07') ELSE NULL END";

    public function __construct(private PDO $db, private array $expected) {}

    public static function production(): array
    {
        return ['run' => self::RUN, 'selected' => 3000000, 'one' => 3217615, 'two' => 4657,
            'file_sha' => '8b926c95924c6e7b351022801ea903e8901e02e23d0eb3ce3a662641b5fb263e',
            'file_bytes' => 181868557];
    }

    public static function proposed(int $attempts): array
    {
        return match ($attempts) {
            1 => ['date' => null, 'attempts' => 0],
            2 => ['date' => '2026-10-07', 'attempts' => 1],
            default => throw new RuntimeException('Unexpected attempts; reconstruction refused.'),
        };
    }

    /** The same canonical encoding is used for the backup and live, never SQL JSON/hash approximations. */
    public static function canonical(array $row, string $kind): array
    {
        if ($kind === 'tracking') return [
            (int) $row['PK'], $row['Phone'], $row['Creation_Date'], $row['Latest_Export_Date'],
            (int) $row['SMS_Attempts'], $row['Identity_SHA256'],
            (int) $row['Contacted'], (int) $row['Invalid_Phone'], (int) $row['Opt_Out'], $row['Response_Date'],
        ];
        if ($kind !== 'history') throw new RuntimeException('Unsupported fingerprint.');
        return [(int) $row['PK'], strtolower($row['Request_ID']),
            $row['SMS_Drop_PK'] === null ? null : (int) $row['SMS_Drop_PK'], $row['Drop_Name'],
            (int) $row['Prospect_PK'], $row['Phone'], $row['Export_Date'],
            $row['Debt_Amount'] === null ? null : (string) $row['Debt_Amount'],
            $row['Debt_Tier'], $row['Debt_Source'],
            $row['Debt_Source_PK'] === null ? null : (int) $row['Debt_Source_PK']];
    }

    public static function fingerprint(iterable $rows, string $kind): array
    {
        $hash = hash_init('sha256'); $count = 0;
        foreach ($rows as $row) {
            hash_update($hash, json_encode(self::canonical($row, $kind), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n");
            $count++;
            if ($count % 1000000 === 0) echo '  '.$kind.': '.number_format($count).' rows checked'.PHP_EOL;
        }
        return ['rows' => $count, 'sha256' => hash_final($hash)];
    }

    private function liveFingerprint(string $kind): array
    {
        $query = $this->db->prepare($kind === 'tracking'
            ? 'SELECT PK,Client,Address,Zip,Phone,Creation_Date,Latest_Export_Date,SMS_Attempts,Contacted,Invalid_Phone,Opt_Out,Response_Date FROM dbo.TblProspects ORDER BY PK OPTION(MAXDOP 1)'
            : 'SELECT PK,Request_ID,SMS_Drop_PK,Drop_Name,Prospect_PK,Phone,Export_Date,Debt_Amount,Debt_Tier,Debt_Source,Debt_Source_PK FROM dbo.TblSMSHistory WHERE Request_ID=? ORDER BY PK OPTION(MAXDOP 1)');
        $query->execute($kind === 'tracking' ? [] : [$this->expected['run']]);
        try {
            return self::fingerprint((function () use ($query,$kind): Generator {
                while (($row = $query->fetch(PDO::FETCH_ASSOC)) !== false) {
                    if ($kind === 'tracking') $row['Identity_SHA256'] = SmsRecoverySnapshot::identityHash($row);
                    yield $row;
                }
            })(), $kind);
        } finally { $query->closeCursor(); }
    }

    /** Only connection-local temporary staging is written. No permanent table changes. */
    public function stage(iterable $rows): void
    {
        $this->db->exec('CREATE TABLE #SMSLegacyBaseline (PK int NOT NULL PRIMARY KEY, Phone varchar(10) NOT NULL, Expected_Date date NOT NULL, Expected_Attempts int NOT NULL)');
        $insert = $this->db->prepare(<<<'SQL'
INSERT INTO #SMSLegacyBaseline (PK,Phone,Expected_Date,Expected_Attempts)
SELECT PK,Phone,Expected_Date,Expected_Attempts FROM OPENJSON(?)
WITH (PK int '$.PK', Phone varchar(10) '$.Phone', Expected_Date date '$.Expected_Date', Expected_Attempts int '$.Expected_Attempts')
SQL);
        $batch = []; $counts = [1 => 0, 2 => 0]; $previous = 0;
        foreach ($rows as $row) {
            if (! isset($row['PK'], $row['Phone'], $row['Expected_Date'], $row['Expected_Attempts'])
                || ! is_int($row['PK']) || $row['PK'] <= $previous
                || ! is_string($row['Phone']) || ! preg_match('/\A[0-9]{10}\z/D', $row['Phone'])
                || $row['Expected_Date'] !== '2026-10-15' || ! in_array($row['Expected_Attempts'], [1, 2], true)) {
                throw new RuntimeException('Invalid or unsorted baseline row; no persistent changes made.');
            }
            $previous = $row['PK'];
            $counts[$row['Expected_Attempts']]++;
            $batch[] = $row;
            if (count($batch) === 10000) { $insert->execute([json_encode($batch, JSON_THROW_ON_ERROR)]); $insert->closeCursor(); $batch = []; }
            if (array_sum($counts) % 500000 === 0) echo '  '.number_format(array_sum($counts)).' baseline rows staged'.PHP_EOL;
        }
        if ($batch) { $insert->execute([json_encode($batch, JSON_THROW_ON_ERROR)]); $insert->closeCursor(); }
        if ($counts !== [1 => $this->expected['one'], 2 => $this->expected['two']]) throw new RuntimeException('Baseline population differs from the authorized reconstruction.');
    }

    /**
     * Avoid re-uploading millions of already-backed-up rows. This is permitted ONLY with
     * complete immutable before fingerprints: check() must prove every prospect/history
     * row matches that backup, in addition to proving this staged cohort is bijective.
     * Writes only a connection-local temp table. apply() repeats all checks under real locks.
     */
    public function stageFromLive(): void
    {
        $this->guard(isset($this->expected['tracking_fingerprint'],$this->expected['history_fingerprint']), 'Full safety-export fingerprints are required for server-side temporary staging.');
        $this->db->exec('CREATE TABLE #SMSLegacyBaseline (PK int NOT NULL PRIMARY KEY, Phone varchar(10) NOT NULL, Expected_Date date NOT NULL, Expected_Attempts int NOT NULL)');
        $q = $this->db->prepare(<<<'SQL'
INSERT INTO #SMSLegacyBaseline (PK,Phone,Expected_Date,Expected_Attempts)
SELECT p.PK,p.Phone,p.Latest_Export_Date,p.SMS_Attempts
FROM dbo.TblProspects p JOIN (SELECT DISTINCT Phone FROM dbo.TblSMSHistory WHERE Request_ID=?) h ON h.Phone=p.Phone
OPTION(HASH JOIN,MAXDOP 1)
SQL);
        $q->execute([$this->expected['run']]); $q->closeCursor();
        $counts = $this->rows('SELECT Expected_Attempts,COUNT_BIG(*) AS People FROM #SMSLegacyBaseline GROUP BY Expected_Attempts ORDER BY Expected_Attempts');
        $this->guard(count($counts) === 2 && (int) $counts[0]['Expected_Attempts'] === 1 && (int) $counts[0]['People'] === $this->expected['one']
            && (int) $counts[1]['Expected_Attempts'] === 2 && (int) $counts[1]['People'] === $this->expected['two'], 'Staged population differs from the safety export.');
        $this->guard((int) $this->scalar("SELECT COUNT_BIG(*) FROM #SMSLegacyBaseline WHERE Expected_Date<>'2026-10-15' OR LEN(Phone)<>10 OR Phone LIKE '%[^0-9]%'") === 0, 'Unexpected staged date/phone.');
        echo '  '.number_format($this->expected['one']+$this->expected['two']).' baseline rows staged on the database server'.PHP_EOL;
    }

    private function scalar(string $sql, array $bindings = []): mixed
    {
        $q = $this->db->prepare($sql); $q->execute($bindings);
        $value = $q->fetchColumn(); $q->closeCursor(); return $value;
    }

    private function rows(string $sql, array $bindings = []): array
    {
        $q = $this->db->prepare($sql); $q->execute($bindings);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC); $q->closeCursor(); return $rows;
    }

    private function guard(bool $condition, string $message): void
    {
        if (! $condition) throw new RuntimeException($message.' Nothing was committed.');
    }

    public function check(): array
    {
        $id = $this->expected['run'];
        $runs = $this->rows('SELECT * FROM dbo.TblSMSRuns WHERE Request_ID=?', [$id]);
        $this->guard(count($runs) === 1, 'Expected export run is missing/ambiguous.');
        $run = $runs[0];
        $this->guard($run['Status'] === 'sent' && (int) $run['Selected'] === $this->expected['selected'], 'The run is no longer the reviewed sent batch.');
        $this->guard(strtolower((string) $run['File_Sha256']) === $this->expected['file_sha'] && (int) $run['File_Bytes'] === $this->expected['file_bytes'], 'The artifact identity changed.');
        $this->guard((int) $this->scalar("SELECT COUNT_BIG(*) FROM dbo.TblSMSRuns WHERE Request_ID<>? AND Status NOT IN ('sent','discarded')", [$id]) === 0, 'Another SMS run is open or uses an unexpected status.');
        $this->guard((int) $this->scalar('SELECT COUNT_BIG(*) FROM dbo.TblProspectsExport') === 0, 'A working export is present.');
        $this->guard((int) $this->scalar("SELECT COUNT(*) FROM sys.triggers WHERE parent_id IN (OBJECT_ID('dbo.TblProspects'),OBJECT_ID('dbo.TblSMSRuns')) AND is_disabled=0") === 0, 'Unexpected tracking/run trigger; update scope is not safe.');
        $history = $this->rows('SELECT COUNT_BIG(*) AS Rows,COUNT(DISTINCT Phone) AS Phones,COUNT(DISTINCT Prospect_PK) AS People FROM dbo.TblSMSHistory WHERE Request_ID=?', [$id])[0];
        $this->guard(array_map('intval', array_values($history)) === array_fill(0, 3, $this->expected['selected']), 'Target history membership counts changed.');
        $this->guard((int) $this->scalar('SELECT COUNT_BIG(*) FROM dbo.TblSMSHistory h LEFT JOIN dbo.TblProspects p ON p.PK=h.Prospect_PK AND p.Phone=h.Phone WHERE h.Request_ID=? AND p.PK IS NULL OPTION(MAXDOP 1)', [$id]) === 0, 'A selected prospect or phone changed.');
        $this->guard((int) $this->scalar("SELECT COUNT_BIG(*) FROM dbo.TblSMSDrops d WHERE EXISTS (SELECT 1 FROM dbo.TblSMSHistory h WHERE h.Request_ID=? AND (h.SMS_Drop_PK=d.PK OR h.Drop_Name=d.Drop_Name)) AND (NULLIF(LTRIM(RTRIM(d.Invoice_Number)),'') IS NOT NULL OR COALESCE(d.Cost,0)<>0)", [$id]) === 0, 'A linked SMS drop has an invoice/cost.');
        $this->guard((int) $this->scalar('SELECT COUNT_BIG(*) FROM dbo.TblSMSHistory h LEFT JOIN dbo.TblSMSDrops d ON d.PK=h.SMS_Drop_PK AND d.Drop_Name=h.Drop_Name WHERE h.Request_ID=? AND d.PK IS NULL', [$id]) === 0, 'SMS drop ownership is ambiguous.');
        $this->guard((int) $this->scalar('SELECT COUNT_BIG(*) FROM dbo.TblSMSHistory h WHERE h.Request_ID<>? AND EXISTS (SELECT 1 FROM dbo.TblSMSHistory t WHERE t.Request_ID=? AND t.SMS_Drop_PK=h.SMS_Drop_PK)', [$id,$id]) === 0, 'An SMS drop is shared with another history run.');
        if ($this->scalar("SELECT OBJECT_ID('dbo.TblSMSExports')")) {
            $this->guard((int) $this->scalar('SELECT COUNT_BIG(*) FROM dbo.TblSMSExports e WHERE EXISTS (SELECT 1 FROM dbo.TblSMSHistory h WHERE h.Request_ID=? AND h.Drop_Name=e.SMS_Drop_Name)', [$id]) === 0, 'A legacy export also owns these drop names.');
        }
        foreach (['TblSMSAttemptAudit', 'TblSMSLegacySends'] as $table) {
            if ($this->scalar('SELECT OBJECT_ID(?)', ['dbo.'.$table])) {
                $this->guard((int) $this->scalar('SELECT COUNT_BIG(*) FROM dbo.['.$table.'] WHERE Request_ID=?', [$id]) === 0, 'Unexpected target-linked send audit/ledger; use a different recovery plan.');
            }
        }
        $this->guard((int) $this->scalar(<<<'SQL'
SELECT COUNT_BIG(*) FROM #SMSLegacyBaseline b LEFT JOIN dbo.TblProspects p ON p.PK=b.PK
WHERE p.PK IS NULL OR p.Phone IS NULL OR p.Phone<>b.Phone OR p.Latest_Export_Date IS NULL
 OR p.Latest_Export_Date<>b.Expected_Date OR p.SMS_Attempts<>b.Expected_Attempts OPTION(MAXDOP 1)
SQL) === 0, 'Current tracking differs from the verified safety backup.');
        $this->guard((int) $this->scalar(<<<'SQL'
SELECT COUNT_BIG(*) FROM dbo.TblProspects p
WHERE EXISTS(SELECT 1 FROM dbo.TblSMSHistory h WHERE h.Request_ID=? AND h.Phone=p.Phone)
 AND NOT EXISTS(SELECT 1 FROM #SMSLegacyBaseline b WHERE b.PK=p.PK) OPTION(HASH JOIN,MAXDOP 1)
SQL, [$id]) === 0, 'There are new/changed household matches outside the baseline.');
        $this->guard((int) $this->scalar("SELECT COUNT_BIG(*) FROM dbo.TblProspects p WHERE Latest_Export_Date='2026-10-15' AND NOT EXISTS(SELECT 1 FROM #SMSLegacyBaseline b WHERE b.PK=p.PK) OPTION(MAXDOP 1)") === 0, 'Oct 15 tracking exists outside the approved cohort.');
        foreach (['tracking','history'] as $kind) {
            if (isset($this->expected[$kind.'_fingerprint'])) {
                echo 'Checking complete live '.$kind.' fingerprint against the safety backup'.PHP_EOL;
                $this->guard($this->liveFingerprint($kind) === $this->expected[$kind.'_fingerprint'], 'Live '.$kind.' differs from the verified backup.');
            }
        }
        return ['ready' => true, 'type' => 'assumption_based_reconstruction', 'policy' => self::POLICY,
            'run_id' => $id, 'to_never_texted' => $this->expected['one'], 'to_oct7_once' => $this->expected['two'],
            'total' => $this->expected['one']+$this->expected['two'], 'history_retained' => $this->expected['selected'],
            'run_status_after' => 'discarded', 'run_before' => $run];
    }

    public function apply(array $evidence, ?Closure $testBeforeCommit = null): array
    {
        $this->guard(($evidence['accepted_assumptions'] ?? false) === true, 'Assumption-based history was not accepted.');
        foreach (['backup_sha256','plan_sha256'] as $field) $this->guard((bool) preg_match('/\A[0-9a-f]{64}\z/D', $evidence[$field] ?? ''), 'Missing evidence hash.');
        $id = $this->expected['run'];
        $this->db->exec('SET XACT_ABORT ON; SET LOCK_TIMEOUT 10000; SET DEADLOCK_PRIORITY LOW');
        $this->db->beginTransaction();
        try {
            // Old application versions do not participate in the new application-lock protocol.
            // Real SQL table locks fence run creation, selection, sends, admissions and invoices.
            foreach (['TblSMSRuns','TblProspectsExport','TblSMSHistory','TblSMSDrops','TblProspects'] as $table) {
                $this->scalar('SELECT TOP(1) 1 FROM dbo.['.$table.'] WITH(TABLOCKX,HOLDLOCK)');
            }
            if ($this->scalar("SELECT OBJECT_ID('dbo.TblSMSLegacyCorrectionRuns')")) {
                $old = $this->rows('SELECT * FROM dbo.TblSMSLegacyCorrectionRuns WHERE Request_ID=?', [$id]);
                if ($old) {
                    $this->guard($old[0]['Policy'] === self::POLICY && $old[0]['Status'] === 'applied' && $old[0]['Plan_SHA256'] === $evidence['plan_sha256']
                        && $old[0]['Backup_SHA256'] === $evidence['backup_sha256']
                        && (int) $old[0]['Changed_Rows'] === $this->expected['one']+$this->expected['two'], 'A different or incomplete correction already exists.');
                    $this->guard((string) $this->scalar('SELECT Status FROM dbo.TblSMSRuns WHERE Request_ID=?',[$id]) === 'discarded', 'Corrected run was reopened; refuse a second correction.');
                    $this->db->commit();
                    return ['already_applied' => true, 'changed' => 0, 'run_id' => $id];
                }
            }
            $preview = $this->check();
            $this->db->exec(<<<'SQL'
IF OBJECT_ID('dbo.TblSMSLegacyCorrectionRuns') IS NULL
CREATE TABLE dbo.TblSMSLegacyCorrectionRuns (
 Request_ID uniqueidentifier NOT NULL PRIMARY KEY, Policy varchar(100) NOT NULL,
 Status varchar(20) NOT NULL, Assumptions nvarchar(1500) NOT NULL, Actor varchar(150) NOT NULL,
 Backup_SHA256 char(64) NOT NULL, Plan_SHA256 char(64) NOT NULL,
 Original_Run_JSON nvarchar(max) NOT NULL, Changed_Rows int NOT NULL, Applied_At datetime2 NOT NULL
);
IF OBJECT_ID('dbo.TblSMSLegacyCorrectionRows') IS NULL
CREATE TABLE dbo.TblSMSLegacyCorrectionRows (
 Request_ID uniqueidentifier NOT NULL, Prospect_PK int NOT NULL, Phone varchar(10) NOT NULL,
 Observed_Date date NULL, Observed_Attempts int NOT NULL,
 Reconstructed_Date date NULL, Reconstructed_Attempts int NOT NULL,
 PRIMARY KEY(Request_ID,Prospect_PK)
);
SQL);
            $q = $this->db->prepare(<<<'SQL'
INSERT INTO dbo.TblSMSLegacyCorrectionRuns (Request_ID,Policy,Status,Assumptions,Actor,Backup_SHA256,Plan_SHA256,Original_Run_JSON,Changed_Rows,Applied_At)
VALUES (?,?,'applying',?,?,?, ?,?,0,SYSUTCDATETIME())
SQL);
            $q->execute([$id,self::POLICY,
                'User-authorized reconstruction, not recovered historical values. Assume no intervening tracking/phone changes; prior dates Oct 7/8 only; Oct 8 phones disjoint. Observed attempt 1 -> 0/NULL; 2 -> 1/Oct 7. Original history/file/drops retained; flags/identity untouched.',
                'monther-authorized-reconstruction',$evidence['backup_sha256'],$evidence['plan_sha256'],json_encode($preview['run_before'], JSON_THROW_ON_ERROR)]);
            $q->closeCursor();
            $after = self::AFTER_SQL;
            $q = $this->db->prepare(<<<SQL
UPDATE p SET SMS_Attempts=b.Expected_Attempts-1, Latest_Export_Date={$after}
OUTPUT ?,deleted.PK,deleted.Phone,deleted.Latest_Export_Date,deleted.SMS_Attempts,inserted.Latest_Export_Date,inserted.SMS_Attempts
INTO dbo.TblSMSLegacyCorrectionRows (Request_ID,Prospect_PK,Phone,Observed_Date,Observed_Attempts,Reconstructed_Date,Reconstructed_Attempts)
FROM dbo.TblProspects p JOIN #SMSLegacyBaseline b ON b.PK=p.PK
WHERE p.Phone=b.Phone AND p.Latest_Export_Date=b.Expected_Date AND p.SMS_Attempts=b.Expected_Attempts
OPTION(HASH JOIN,MAXDOP 1)
SQL);
            $q->execute([$id]); $q->closeCursor();
            $changed = (int) $this->scalar('SELECT COUNT_BIG(*) FROM dbo.TblSMSLegacyCorrectionRows WHERE Request_ID=?', [$id]);
            $this->guard($changed === $preview['total'], 'Audited update count mismatch.');
            $this->guard((int) $this->scalar(<<<'SQL'
SELECT COUNT_BIG(*) FROM dbo.TblProspects p JOIN #SMSLegacyBaseline b ON b.PK=p.PK
WHERE p.SMS_Attempts<>b.Expected_Attempts-1
 OR (b.Expected_Attempts=1 AND p.Latest_Export_Date IS NOT NULL)
 OR (b.Expected_Attempts=2 AND (p.Latest_Export_Date IS NULL OR p.Latest_Export_Date<>CONVERT(date,'2026-10-07'))) OPTION(MAXDOP 1)
SQL) === 0, 'Post-update tracking verification failed.');
            $q = $this->db->prepare("UPDATE dbo.TblSMSRuns SET Status='discarded',Sent_By=NULL,Sent_At=NULL,Updated_At=SYSUTCDATETIME() WHERE Request_ID=? AND Status='sent'");
            $q->execute([$id]); $updated = $q->rowCount(); $q->closeCursor();
            $this->guard($updated === 1, 'The run status changed concurrently.');
            $this->guard((int) $this->scalar('SELECT COUNT_BIG(*) FROM dbo.TblSMSHistory WHERE Request_ID=?', [$id]) === $this->expected['selected'], 'History retention verification failed.');
            $this->guard((int) $this->scalar('SELECT COUNT_BIG(*) FROM dbo.TblProspectsExport') === 0, 'A working export appeared.');
            $this->guard((int) $this->scalar("SELECT COUNT_BIG(*) FROM dbo.TblProspects WHERE Latest_Export_Date='2026-10-15'") === 0, 'Unresolved Oct 15 tracking remains.');
            if (isset($this->expected['after_tracking_fingerprint'])) {
                echo 'Verifying every prospect against the proposed final state, including unchanged flags'.PHP_EOL;
                $this->guard($this->liveFingerprint('tracking') === $this->expected['after_tracking_fingerprint'], 'Final full-table tracking/flag verification failed.');
            }
            if ($testBeforeCommit) $testBeforeCommit();
            $q = $this->db->prepare("UPDATE dbo.TblSMSLegacyCorrectionRuns SET Status='applied',Changed_Rows=? WHERE Request_ID=? AND Status='applying'");
            $q->execute([$changed,$id]); $q->closeCursor();
            $this->db->commit();
            unset($preview['run_before']);
            return $preview + ['applied' => true,'changed' => $changed];
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
    }
}
