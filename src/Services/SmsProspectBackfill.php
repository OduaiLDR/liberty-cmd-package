<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

use PDO;
use RuntimeException;
use Throwable;

require_once dirname(__DIR__, 2).'/scripts/sms-prospect-backfill-plan.php';

/** Insert-only, bounded writer. Its caller supplies evidence from a COMPLETED coverage plan. */
final class SmsProspectBackfill
{
    public const POLICY = 'single-source-verified-events-v1';
    public const VERIFIED_HISTORY = 'verified-events-v1';
    public const NO_PRIOR_SENDS = 'confirmed-no-prior-sends-v1';
    public const MAX_BATCH = 1000;
    private const LOCKS = ['sms:new-run', 'sms:prospect-maintenance', 'sms:prospect-admission', 'sms:contact-sync', 'sms:phone-suppression'];
    private const CLOSED = "'sent','discarded','review_only','not_sent'";

    public static function schemaSql(): string
    {
        return <<<'SQL'
CREATE TABLE dbo.TblSMSProspectBackfillRuns (
 Run_ID uniqueidentifier NOT NULL PRIMARY KEY,
 Policy varchar(60) NOT NULL, Plan_SHA256 char(64) NOT NULL, Proof nvarchar(max) NOT NULL,
 History_Reviewed bit NOT NULL, Actor nvarchar(255) NOT NULL,
 After_ID int NOT NULL DEFAULT 0, Batches int NOT NULL DEFAULT 0,
 Checked bigint NOT NULL DEFAULT 0, Added bigint NOT NULL DEFAULT 0,
 Existing bigint NOT NULL DEFAULT 0, Deferred bigint NOT NULL DEFAULT 0,
 Status varchar(30) NOT NULL DEFAULT 'ready', Created_At datetime2 NOT NULL DEFAULT SYSUTCDATETIME(),
 Updated_At datetime2 NOT NULL DEFAULT SYSUTCDATETIME()
);
CREATE TABLE dbo.TblSMSProspectBackfillBatches (
 Run_ID uniqueidentifier NOT NULL, After_ID int NOT NULL, Through_ID int NOT NULL,
 Input_SHA256 char(64) NOT NULL, Checked int NOT NULL, Added int NOT NULL, Existing int NOT NULL,
 Deferred int NOT NULL, Committed_At datetime2 NOT NULL DEFAULT SYSUTCDATETIME(),
 CONSTRAINT PK_SMSProspectBackfillBatches PRIMARY KEY(Run_ID,After_ID),
 CONSTRAINT FK_SMSProspectBackfillBatches_Run FOREIGN KEY(Run_ID) REFERENCES dbo.TblSMSProspectBackfillRuns(Run_ID)
);
CREATE TABLE dbo.TblSMSProspectBackfillRows (
 Run_ID uniqueidentifier NOT NULL, Source_PK int NOT NULL, Unique_Source_PK int NULL,
 Prospect_PK int NULL, Decision varchar(20) NOT NULL, Reason varchar(40) NULL,
 Source_SHA256 char(64) NOT NULL, After_SHA256 char(64) NULL, History_SHA256 char(64) NULL,
 CONSTRAINT PK_SMSProspectBackfillRows PRIMARY KEY(Run_ID,Source_PK),
 CONSTRAINT FK_SMSProspectBackfillRows_Run FOREIGN KEY(Run_ID) REFERENCES dbo.TblSMSProspectBackfillRuns(Run_ID)
);
CREATE INDEX IX_SMSProspectBackfillRows_Prospect ON dbo.TblSMSProspectBackfillRows(Prospect_PK) WHERE Prospect_PK IS NOT NULL;
SQL;
    }

    /** Explicit additive installation, never called by a normal plan or writer invocation. */
    public static function install(PDO $db): void
    {
        self::dedicated($db);
        self::assertNoExternalEvents($db);
        $db->beginTransaction();
        try {
            foreach (['Runs', 'Batches', 'Rows'] as $suffix) {
                if (self::rows($db, 'SELECT OBJECT_ID(?) AS id', ['dbo.TblSMSProspectBackfill'.$suffix])[0]['id'] !== null) {
                    throw new RuntimeException('Backfill ledger already exists or is partially installed; do not replace it.');
                }
            }
            $db->exec(self::schemaSql());
            $db->commit();
        } catch (Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }
    }

    /** No application boot, cache, provider, existing-prospect update, or inferred send occurs. */
    public static function begin(PDO $db, string $runId, array $proof, string $actor, bool $historyReviewed = false): array
    {
        self::dedicated($db); self::runId($runId); self::proof($proof);
        if (self::historyPolicy($proof) === self::NO_PRIOR_SENDS && ! $historyReviewed) {
            throw new RuntimeException('No-prior-sends admission requires an explicit reviewed confirmation.');
        }
        if ($actor === '' || mb_strlen($actor) > 255 || preg_match('/[\x00-\x1f\x7f]/', $actor)) throw new RuntimeException('A valid backfill actor is required.');
        $json = \SmsProspectReconciliation::json($proof);
        $hash = hash('sha256', $json);
        return self::locked($db, function () use ($db, $runId, $proof, $actor, $historyReviewed, $hash, $json): array {
            return self::transaction($db, function () use ($db, $runId, $proof, $actor, $historyReviewed, $hash, $json): array {
                $known = self::run($db, $runId, true);
                if ($known !== null) {
                    if (! hash_equals($known['Plan_SHA256'], $hash) || (bool) $known['History_Reviewed'] !== $historyReviewed) {
                        throw new RuntimeException('This backfill run belongs to different immutable evidence or history policy.');
                    }
                    if (self::historyPolicy($proof) === self::NO_PRIOR_SENDS) self::guard($db, $proof);
                    return self::publicRun($known);
                }
                self::guard($db, $proof);
                self::execute($db, 'INSERT dbo.TblSMSProspectBackfillRuns(Run_ID,Policy,Plan_SHA256,Proof,History_Reviewed,Actor) VALUES(?,?,?,?,?,?)',
                    [$runId, self::POLICY, $hash, $json, (int) $historyReviewed, $actor]);
                return self::publicRun(self::run($db, $runId, true));
            });
        });
    }

    /** Replay returns the committed chunk. A crash cannot leave inserts without their checkpoint. */
    public static function applyBatch(PDO $db, string $runId, int $afterId, array $candidates): array
    {
        self::dedicated($db); self::runId($runId);
        if ($afterId < 0 || count($candidates) < 1 || count($candidates) > self::MAX_BATCH) throw new RuntimeException('Invalid bounded backfill batch.');
        $previous = $afterId;
        foreach ($candidates as $candidate) {
            if (array_keys($candidate) !== ['Source_PK', 'Observed_SHA256', 'Contact_Key'] || ! is_int($candidate['Source_PK'])
                || $candidate['Source_PK'] <= $previous || $candidate['Source_PK'] > 2147483647) throw new RuntimeException('Candidate IDs must be strictly increasing.');
            foreach (['Observed_SHA256', 'Contact_Key'] as $key) self::digest($candidate[$key]);
            $previous = $candidate['Source_PK'];
        }
        $inputHash = hash('sha256', \SmsProspectReconciliation::json($candidates));
        return self::locked($db, function () use ($db, $runId, $afterId, $candidates, $inputHash): array {
            return self::transaction($db, function () use ($db, $runId, $afterId, $candidates, $inputHash): array {
                $run = self::run($db, $runId, true);
                if ($run === null || $run['Policy'] !== self::POLICY) throw new RuntimeException('Create a reviewed backfill run first.');
                $replay = self::rows($db, 'SELECT * FROM dbo.TblSMSProspectBackfillBatches WHERE Run_ID=? AND After_ID=?', [$runId, $afterId])[0] ?? null;
                if ($replay !== null) {
                    if (! hash_equals($replay['Input_SHA256'], $inputHash)) throw new RuntimeException('A committed checkpoint cannot be reused with different candidates.');
                    return self::batchResult($replay, true);
                }
                if ((int) $run['After_ID'] !== $afterId || $run['Status'] !== 'ready') throw new RuntimeException('Backfill cursor changed; reload the committed checkpoint.');
                $proof = json_decode($run['Proof'], true, 32, JSON_THROW_ON_ERROR); self::proof($proof);
                if (end($candidates)['Source_PK'] > $proof['candidate_max_id']) throw new RuntimeException('Batch exceeds the completed plan.');
                self::guard($db, $proof);
                $source = self::sourceRows($db, array_column($candidates, 'Source_PK'), $proof['prospect_watermark_pk']);
                $result = ['Run_ID' => $runId, 'After_ID' => $afterId, 'Through_ID' => end($candidates)['Source_PK'],
                    'Input_SHA256' => $inputHash, 'Checked' => count($candidates), 'Added' => 0, 'Existing' => 0, 'Deferred' => 0];
                $inputs = [];
                foreach ($candidates as $candidate) {
                    $row = $source[$candidate['Source_PK']] ?? null;
                    if ($row === null) throw new RuntimeException('A planned source row is missing; no chunk was committed.');
                    $raw = $row; unset($raw['Unique_Source_PK'], $raw['Source_Tier'], $raw['Creation_Date'], $raw['City_Valid']);
                    // The coverage hash is of the RAW SQL row, not its normalized saved evidence.
                    if (! hash_equals($candidate['Observed_SHA256'], hash('sha256', \SmsProspectReconciliation::json($raw)))) {
                        throw new RuntimeException('Source evidence changed; no chunk was committed.');
                    }
                    $contact = \SmsProspectCoverage::normalize($row);
                    $contactKey = hash('sha256', \SmsProspectReconciliation::json([$contact['Phone'], $contact['Email'] === null ? null : strtolower($contact['Email'])]));
                    if (! $contact['identity_valid'] || ($contact['Phone'] === null && $contact['Email'] === null) || ! hash_equals($candidate['Contact_Key'], $contactKey)) {
                        throw new RuntimeException('Candidate contact or identity proof failed.');
                    }
                    $city = trim((string) $row['City']);
                    $state = strtoupper(trim((string) $row['State']));
                    $inputs[] = ['Source_PK' => $candidate['Source_PK'], 'Unique_Source_PK' => $row['Unique_Source_PK'],
                        'Source_SHA256' => $candidate['Observed_SHA256'],
                        'Reason' => $row['Unique_Source_PK'] === null ? 'unresolved_source_owner' : (! (bool) $row['City_Valid'] ? 'invalid_city' : null),
                        'Creation_Date' => $row['Creation_Date'], 'Client' => $row['Client'], 'Address' => $row['Address'],
                        'City' => $city === '' ? null : $city, 'State' => preg_match('/^[A-Z]{2}$/D', $state) ? $state : null,
                        'Zip' => $row['Zip'], 'Tier' => SmsProspectValues::tier($row['Source_Tier']), 'Phone' => $contact['Phone'], 'Email' => $contact['Email']];
                }
                // One set-based write round trip per chunk, not thousands of per-person
                // round trips between the runner and Azure.
                $counts = self::applyRows($db, $runId, $inputs, (bool) $run['History_Reviewed'], self::historyPolicy($proof) === self::NO_PRIOR_SENDS);
                foreach (['Added','Existing','Deferred'] as $key) $result[$key] = (int) $counts[$key];
                if ((int) $run['Checked'] + $result['Checked'] > $proof['candidate_count']) throw new RuntimeException('Candidate total exceeds the frozen plan.');
                self::execute($db, 'INSERT dbo.TblSMSProspectBackfillBatches(Run_ID,After_ID,Through_ID,Input_SHA256,Checked,Added,Existing,Deferred) VALUES(?,?,?,?,?,?,?,?)', array_values($result));
                self::execute($db, 'UPDATE dbo.TblSMSProspectBackfillRuns SET After_ID=?,Batches=Batches+1,Checked=Checked+?,Added=Added+?,Existing=Existing+?,Deferred=Deferred+?,Updated_At=SYSUTCDATETIME() WHERE Run_ID=?',
                    [$result['Through_ID'], $result['Checked'], $result['Added'], $result['Existing'], $result['Deferred'], $runId]);
                // Optional durable summary is on this same business transaction. Legacy
                // cache mode recovers through its five-minute count refresh / manual refresh.
                if ($result['Added'] > 0 && self::rows($db, "SELECT OBJECT_ID('dbo.TblSMSAvailabilitySummary') AS id")[0]['id'] !== null) {
                    self::execute($db, 'UPDATE dbo.TblSMSAvailabilitySummary SET revision=revision+1');
                }
                return self::batchResult($result, false);
            });
        });
    }

    public static function finish(PDO $db, string $runId): array
    {
        self::dedicated($db); self::runId($runId);
        return self::locked($db, fn () => self::transaction($db, function () use ($db, $runId): array {
            $run = self::run($db, $runId, true);
            if ($run === null) throw new RuntimeException('Unknown backfill run.');
            $proof = json_decode($run['Proof'], true, 32, JSON_THROW_ON_ERROR); self::proof($proof);
            if ((int) $run['Checked'] !== $proof['candidate_count'] || (int) $run['After_ID'] !== $proof['candidate_max_id']) throw new RuntimeException('Backfill has not exhausted its frozen candidate plan.');
            self::guard($db, $proof);
            self::execute($db, 'UPDATE dbo.TblSMSProspectBackfillRuns SET Status=?,Updated_At=SYSUTCDATETIME() WHERE Run_ID=?',
                [(int) $run['Deferred'] > 0 ? 'completed_with_deferrals' : 'completed', $runId]);
            return self::publicRun(self::run($db, $runId, true));
        }));
    }

    public static function status(PDO $db, string $runId): ?array
    {
        self::runId($runId);
        $run = self::run($db, $runId, false);
        return $run === null ? null : self::publicRun($run);
    }

    private static function guard(PDO $db, array $proof): void
    {
        $metadata = \SmsProspectCoverage::metadata($db);
        if (! hash_equals($proof['source_guard'], \SmsProspectCoverage::sourceGuard($metadata))) {
            throw new RuntimeException('Source/schema proof differs from the completed audit.');
        }
        $control = self::rows($db, "SELECT Status,PostPublishWrites,ShadowObjectID,OBJECT_ID('dbo.TblMailersUniqueEnriched') AS Live_Object_ID
            FROM tu_repair_20261014.Control WITH(UPDLOCK,HOLDLOCK) WHERE ID=1")[0] ?? null;
        if ($control === null || $control['Status'] !== 'PUBLISHED' || (int) $control['PostPublishWrites'] !== 0
            || (int) $control['ShadowObjectID'] !== (int) $control['Live_Object_ID']) throw new RuntimeException('The reviewed repaired source is no longer unchanged and published.');
        $trigger = self::rows($db, "SELECT t.is_disabled FROM sys.triggers t WHERE t.parent_id=OBJECT_ID('dbo.TblMailersUniqueEnriched') AND t.name='TURepair20261014PostPublish'")[0] ?? null;
        if ($trigger === null || (int) $trigger['is_disabled'] !== 0) throw new RuntimeException('The source change-detection trigger must be enabled.');
        $indexes = self::rows($db, "SELECT OBJECT_SCHEMA_NAME(i.object_id)+'.'+OBJECT_NAME(i.object_id) AS Table_Name,i.name,i.is_unique,c.name AS Column_Name,ic.key_ordinal
            FROM sys.indexes i JOIN sys.index_columns ic ON ic.object_id=i.object_id AND ic.index_id=i.index_id
            JOIN sys.columns c ON c.object_id=ic.object_id AND c.column_id=ic.column_id
            WHERE i.object_id IN(OBJECT_ID('tu_repair_20261014.Lineage'),OBJECT_ID('dbo.TblMailersUnique'),OBJECT_ID('dbo.TblSMSHistory'),OBJECT_ID('dbo.TblPhoneNumbers'))
             AND i.is_disabled=0 AND i.has_filter=0 AND ic.key_ordinal>0 ORDER BY i.object_id,i.index_id,ic.key_ordinal");
        foreach (['tu_repair_20261014.Lineage' => ['Enriched_PK'], 'dbo.TblMailersUnique' => ['External_ID'],
            'dbo.TblSMSHistory' => ['Request_ID','Phone'], 'dbo.TblPhoneNumbers' => ['Phone']] as $table => $keys) {
            $groups = [];
            foreach ($indexes as $index) if ($index['Table_Name'] === $table) {
                if ($table === 'tu_repair_20261014.Lineage' && ! (bool) $index['is_unique']) continue;
                if ($table === 'dbo.TblMailersUnique' && $index['name'] !== 'IndexExternalID') continue;
                $groups[$index['name']][] = $index['Column_Name'];
            }
            $found = array_filter($groups, static fn (array $columns): bool => array_slice($columns, 0, count($keys)) === $keys);
            if ($found === []) throw new RuntimeException('A required bounded backfill lookup index is unavailable.');
        }
        $external = self::rows($db, "SELECT TYPE_NAME(user_type_id) AS Type_Name,max_length FROM sys.columns WHERE object_id=OBJECT_ID('dbo.TblMailersUnique') AND name='External_ID'")[0] ?? null;
        if ($external === null || $external['Type_Name'] !== 'nvarchar' || (int) $external['max_length'] !== 100) {
            throw new RuntimeException('Review the authoritative source External_ID type before indexed backfill lookups.');
        }
        if (self::rows($db, "SELECT TOP(1) object_id FROM sys.triggers WHERE parent_id=OBJECT_ID('dbo.TblProspects') AND is_disabled=0") !== []) {
            throw new RuntimeException('Review prospect triggers before insert-only backfill; unexpected side effects are not allowed.');
        }
        // This small table lock also blocks a correction changing sent->reversing
        // between the history read and this chunk's commit. Nothing is updated here.
        if (self::rows($db, 'SELECT TOP(1) PK FROM dbo.TblSMSRuns WITH(TABLOCK,HOLDLOCK) WHERE Status NOT IN('.self::CLOSED.')') !== []) {
            throw new RuntimeException('Finish or discard the open SMS run before applying a backfill chunk.');
        }
        if (self::historyPolicy($proof) === self::NO_PRIOR_SENDS) {
            // Human confirmation is frozen in Proof and Actor, not inferred from an empty
            // table. Hold both tables until commit so a send cannot appear mid-chunk.
            if (self::rows($db, 'SELECT TOP(1) Phone FROM dbo.TblSMSLegacySends WITH(TABLOCK,HOLDLOCK)') !== []
                || self::rows($db, "SELECT TOP(1) PK FROM dbo.TblSMSRuns WITH(TABLOCK,HOLDLOCK) WHERE Status='sent' OR Sent_At IS NOT NULL") !== []) {
                throw new RuntimeException('Recorded send evidence contradicts the no-prior-sends confirmation; reconcile before continuing.');
            }
        }
    }

    private static function sourceRows(PDO $db, array $ids, int $prospectWatermark): array
    {
        $list = implode(',', $ids); // Bounded positive integers, checked before entering SQL.
        // Keep the original raw projection/order identical to the coverage audit hash.
        $sql = <<<SQL
WITH Scope AS (
 SELECT PK,Client AS Raw_Client,Address AS Raw_Address,City,State,Zip AS Raw_Zip,Zip5_Normalized,
  Phone,Email,Drop_Name,External_ID,Latest_Export_Date,enriched_at,last_seen_at
 FROM dbo.TblMailersUniqueEnriched WHERE PK IN ({$list})
), Texts AS (
 SELECT *,LTRIM(RTRIM(Raw_Client)) AS Client_Text,LTRIM(RTRIM(Raw_Address)) AS Address_Text,
  COALESCE(NULLIF(LTRIM(RTRIM(Zip5_Normalized)),N''),LEFT(LTRIM(RTRIM(Raw_Zip)),5)) AS Zip_Text FROM Scope
), Converted AS (
 SELECT *,CAST(Client_Text AS varchar(150)) COLLATE SQL_Latin1_General_CP1_CI_AS AS Client,
  CAST(Address_Text AS varchar(200)) COLLATE SQL_Latin1_General_CP1_CI_AS AS Address,
  CAST(Zip_Text AS varchar(10)) COLLATE SQL_Latin1_General_CP1_CI_AS AS Zip FROM Texts
), Normalized AS (
 SELECT *,CASE WHEN LEN(Client_Text) BETWEEN 1 AND 150 AND LEN(Address_Text) BETWEEN 1 AND 200
  AND Zip_Text LIKE '[0-9][0-9][0-9][0-9][0-9]'
  AND CONVERT(nvarchar(150),Client) COLLATE Latin1_General_100_BIN2=Client_Text COLLATE Latin1_General_100_BIN2
  AND CONVERT(nvarchar(200),Address) COLLATE Latin1_General_100_BIN2=Address_Text COLLATE Latin1_General_100_BIN2
  THEN 1 ELSE 0 END AS Identity_Valid FROM Converted
)
SELECT n.PK,n.Raw_Client,n.Raw_Address,n.Raw_Zip,n.Client,n.Address,n.Zip,n.City,n.State,n.Phone,n.Email,
 n.Drop_Name,n.External_ID,n.Latest_Export_Date,n.enriched_at,n.last_seen_at,n.Identity_Valid,
 CHECKSUM(n.Client,n.Address,n.Zip) AS Bucket,p.Existing_Count,
 u.PK AS Unique_Source_PK,u.Debt_Tier AS Source_Tier,
 CONVERT(varchar(10),m.Creation_Date,23) AS Creation_Date,
 CASE WHEN n.City IS NULL OR (LEN(LTRIM(RTRIM(n.City)))<=100 AND
  CONVERT(nvarchar(100),CONVERT(varchar(100),LTRIM(RTRIM(n.City)))) COLLATE Latin1_General_100_BIN2=LTRIM(RTRIM(n.City)) COLLATE Latin1_General_100_BIN2) THEN 1 ELSE 0 END AS City_Valid
FROM Normalized n
OUTER APPLY(SELECT COUNT(*) AS Existing_Count FROM(SELECT TOP(2) q.PK FROM dbo.TblProspects q
 WHERE n.Identity_Valid=1 AND q.PK<={$prospectWatermark} AND q.Client=n.Client AND q.Address=n.Address AND q.Zip=n.Zip) matches) p
LEFT JOIN tu_repair_20261014.Lineage l WITH(HOLDLOCK) ON l.Enriched_PK=n.PK
-- Production's Unique table is a heap with NO index on PK. Seek its existing
-- External_ID index first, bound the bucket, then verify the exact lineage PK.
-- TOP bounds a lookup; it never chooses a winner from duplicate source owners.
OUTER APPLY(
 SELECT CASE WHEN COUNT(*)<=1000 AND SUM(Is_Owner)=1 THEN MAX(CASE WHEN Is_Owner=1 THEN PK END) END AS PK,
  CASE WHEN COUNT(*)<=1000 AND SUM(Is_Owner)=1 THEN MAX(CASE WHEN Is_Owner=1 THEN Debt_Tier END) END AS Debt_Tier
 FROM(
  SELECT bucket.*,CASE WHEN bucket.PK=l.Unique_Source_PK AND (bucket.Drop_Name=n.Drop_Name OR (bucket.Drop_Name IS NULL AND n.Drop_Name IS NULL)) THEN 1 ELSE 0 END AS Is_Owner
  FROM(SELECT TOP(1001) original.PK,original.Drop_Name,original.Debt_Tier
   FROM dbo.TblMailersUnique original WITH(INDEX(IndexExternalID),FORCESEEK,HOLDLOCK)
   WHERE n.External_ID IS NOT NULL AND original.External_ID=n.External_ID) bucket
 ) owners
) u
OUTER APPLY(SELECT MAX(Send_Date) AS Creation_Date FROM dbo.TblMarketing WITH(HOLDLOCK) WHERE Drop_Name=n.Drop_Name) m
ORDER BY n.PK OPTION(MAXDOP 1)
SQL;
        $rows = self::rows($db, $sql); $out = [];
        foreach ($rows as $row) {
            $pk = (int) $row['PK'];
            if (isset($out[$pk])) throw new RuntimeException('Source lineage is not one-to-one.');
            $out[$pk] = $row;
        }
        return $out;
    }

    /** Bulk insert only: MERGE has no MATCHED/UPDATE/DELETE arm. */
    private static function applyRows(PDO $db, string $runId, array $inputs, bool $historyReviewed, bool $noPriorSends): array
    {
        $json = \SmsProspectReconciliation::json($inputs);
        if (strlen($json) > 2 * 1024 * 1024) throw new RuntimeException('Backfill payload exceeded the chunk limit.');
        return self::rows($db, <<<'SQL'
SET NOCOUNT ON;
DECLARE @run uniqueidentifier=?,@reviewed bit=?,@no_prior_sends bit=?;
SELECT * INTO #BFInput FROM OPENJSON(CAST(? AS nvarchar(max))) WITH (
 Source_PK int,Unique_Source_PK int,Source_SHA256 char(64),Reason varchar(40),Creation_Date date,
 Client varchar(150),Address varchar(200),City varchar(100),State char(2),Zip varchar(10),Tier varchar(2),Phone varchar(10),Email varchar(254)
);
CREATE UNIQUE CLUSTERED INDEX IX_BFInput ON #BFInput(Source_PK);
-- Tempdb can have a different default collation from the reviewed business database.
ALTER TABLE #BFInput ALTER COLUMN Client varchar(150) COLLATE SQL_Latin1_General_CP1_CI_AS NULL;
ALTER TABLE #BFInput ALTER COLUMN Address varchar(200) COLLATE SQL_Latin1_General_CP1_CI_AS NULL;
ALTER TABLE #BFInput ALTER COLUMN Zip varchar(10) COLLATE SQL_Latin1_General_CP1_CI_AS NULL;
CREATE UNIQUE INDEX IX_BFInput_Identity ON #BFInput(Client,Address,Zip);
-- The nonunique identity index needs range locks; the audit watermark is deliberately absent.
SELECT i.Source_PK,p.PK INTO #BFExisting FROM #BFInput i CROSS APPLY (
 SELECT TOP(1) q.PK FROM dbo.TblProspects q WITH(UPDLOCK,HOLDLOCK)
 WHERE q.Client=i.Client AND q.Address=i.Address AND q.Zip=i.Zip ORDER BY q.PK
) p OPTION(MAXDOP 1);
SELECT i.* INTO #BFNew FROM #BFInput i WHERE i.Reason IS NULL AND NOT EXISTS(SELECT 1 FROM #BFExisting x WHERE x.Source_PK=i.Source_PK);
IF @reviewed=0 AND EXISTS(SELECT 1 FROM #BFNew WHERE Phone IS NOT NULL)
 THROW 52301,'Phone-send history has not been reviewed. No chunk was committed.',1;
IF @no_prior_sends=0 AND EXISTS(SELECT 1 FROM #BFNew WHERE Phone IS NOT NULL)
 AND NOT EXISTS(SELECT 1 FROM dbo.TblSMSLegacySends s WITH(HOLDLOCK) LEFT JOIN dbo.TblSMSRuns r ON r.Request_ID=s.Request_ID
  WHERE s.Request_ID IS NULL OR (r.Status='sent' AND r.Sent_At IS NOT NULL))
 AND NOT EXISTS(SELECT 1 FROM dbo.TblSMSRuns r JOIN dbo.TblSMSHistory h WITH(HOLDLOCK) ON h.Request_ID=r.Request_ID WHERE r.Status='sent' AND r.Sent_At IS NOT NULL)
 THROW 52303,'Restore and review the known historical send evidence before admitting phone-bearing rows.',1;
SELECT DISTINCT Phone INTO #BFPhones FROM #BFNew WHERE Phone IS NOT NULL;
-- Anonymous legacy evidence counts once per phone/day; modern evidence counts per
-- sent Request_ID. UNION removes the mirrored history/ledger copy of a modern send.
SELECT w.Phone,CAST('legacy:'+CONVERT(varchar(10),s.Send_Date,23) AS varchar(50)) AS Event_ID,s.Send_Date
INTO #BFEvents FROM #BFPhones w JOIN dbo.TblSMSLegacySends s WITH(HOLDLOCK) ON s.Phone=w.Phone WHERE s.Request_ID IS NULL
UNION
SELECT w.Phone,'run:'+CONVERT(varchar(36),r.Request_ID),CAST(r.Sent_At AT TIME ZONE 'UTC' AT TIME ZONE 'Pacific Standard Time' AS date)
FROM #BFPhones w JOIN dbo.TblSMSLegacySends s WITH(HOLDLOCK) ON s.Phone=w.Phone JOIN dbo.TblSMSRuns r ON r.Request_ID=s.Request_ID
WHERE r.Status='sent'
UNION
SELECT w.Phone,'run:'+CONVERT(varchar(36),r.Request_ID),CAST(r.Sent_At AT TIME ZONE 'UTC' AT TIME ZONE 'Pacific Standard Time' AS date)
FROM #BFPhones w CROSS JOIN dbo.TblSMSRuns r JOIN dbo.TblSMSHistory h WITH(HOLDLOCK,FORCESEEK) ON h.Request_ID=r.Request_ID AND h.Phone=w.Phone
WHERE r.Status='sent';
IF EXISTS(SELECT 1 FROM #BFEvents WHERE Send_Date IS NULL) THROW 52302,'A sent event has no business date.',1;
SELECT n.*,
 CAST(CASE WHEN c.found=1 THEN 1 ELSE 0 END AS bit) AS Contacted,
 CAST(COALESCE(s.Opt_Out,0) AS bit) AS Opt_Out,CAST(COALESCE(s.Invalid_Phone,0) AS bit) AS Invalid_Phone,
 h.Latest_Export_Date,h.SMS_Attempts,
 CONVERT(char(64),HASHBYTES('SHA2_256',COALESCE((SELECT e.Event_ID,e.Send_Date FROM #BFEvents e WHERE e.Phone=n.Phone ORDER BY e.Send_Date,e.Event_ID FOR JSON PATH,INCLUDE_NULL_VALUES),N'[]')),2) AS History_SHA256
INTO #BFReady FROM #BFNew n
OUTER APPLY(SELECT TOP(1) 1 AS found FROM dbo.TblPhoneNumbers c WITH(HOLDLOCK) WHERE c.Phone IN(n.Phone,'1'+n.Phone)) c
LEFT JOIN dbo.TblSMSSuppressions s WITH(UPDLOCK,HOLDLOCK) ON s.Phone=n.Phone
-- Parallel workers can self-deadlock while taking RangeS-U locks on absent phone keys.
-- Keep the suppression/identity locks; serialize these bounded locking lookups instead.
OUTER APPLY(SELECT MAX(Send_Date) AS Latest_Export_Date,COUNT(*) AS SMS_Attempts FROM #BFEvents e WHERE e.Phone=n.Phone) h OPTION(MAXDOP 1);
CREATE TABLE #BFAdded(Source_PK int PRIMARY KEY,Prospect_PK int NOT NULL);
MERGE dbo.TblProspects AS target USING #BFReady AS source ON 1=0
WHEN NOT MATCHED THEN INSERT(Creation_Date,Client,Address,City,State,Zip,Phone,Email,Contacted,Response_Date,Invalid_Phone,Opt_Out,Latest_Export_Date,SMS_Attempts)
 VALUES(source.Creation_Date,source.Client,source.Address,source.City,source.State,source.Zip,source.Phone,source.Email,source.Contacted,NULL,source.Invalid_Phone,source.Opt_Out,source.Latest_Export_Date,source.SMS_Attempts)
OUTPUT source.Source_PK,inserted.PK INTO #BFAdded;
INSERT dbo.TblSMSProspectBackfillRows(Run_ID,Source_PK,Unique_Source_PK,Prospect_PK,Decision,Reason,Source_SHA256,After_SHA256,History_SHA256)
SELECT @run,i.Source_PK,i.Unique_Source_PK,COALESCE(a.Prospect_PK,x.PK),
 CASE WHEN x.PK IS NOT NULL THEN 'existing' WHEN a.Prospect_PK IS NOT NULL THEN 'inserted' ELSE 'deferred' END,
 CASE WHEN x.PK IS NOT NULL THEN NULL ELSE i.Reason END,i.Source_SHA256,
 CASE WHEN a.Prospect_PK IS NULL THEN NULL ELSE CONVERT(char(64),HASHBYTES('SHA2_256',(SELECT p.* FOR JSON PATH,WITHOUT_ARRAY_WRAPPER,INCLUDE_NULL_VALUES)),2) END,
 r.History_SHA256
FROM #BFInput i LEFT JOIN #BFExisting x ON x.Source_PK=i.Source_PK LEFT JOIN #BFAdded a ON a.Source_PK=i.Source_PK
LEFT JOIN dbo.TblProspects p ON p.PK=a.Prospect_PK LEFT JOIN #BFReady r ON r.Source_PK=i.Source_PK;
DECLARE @added int=(SELECT COUNT(*) FROM #BFAdded),@existing int=(SELECT COUNT(*) FROM #BFExisting),@total int=(SELECT COUNT(*) FROM #BFInput);
DROP TABLE #BFAdded,#BFReady,#BFEvents,#BFPhones,#BFNew,#BFExisting,#BFInput;
SELECT @added AS Added,@existing AS Existing,@total-@added-@existing AS Deferred;
SQL, [$runId, (int) $historyReviewed, (int) $noPriorSends, $json])[0];
    }

    private static function proof(array $proof): void
    {
        if (! in_array($proof['format'] ?? null, [1, 2], true)) throw new RuntimeException('A completed coverage plan is required.');
        if ($proof['format'] === 2) {
            \SmsProspectBackfillPlan::validateHeldProof($proof);
            foreach (['artifact_sha256','writer_tool_sha256'] as $key) self::digest($proof[$key] ?? null);
            if (! array_key_exists('history_policy', $proof)
                || ! is_int($proof['source_watermark_pk'] ?? null) || $proof['source_watermark_pk'] > 2147483647
                || ! is_int($proof['prospect_watermark_pk'] ?? null) || $proof['prospect_watermark_pk'] > 2147483647
                || ! is_int($proof['candidate_max_id'] ?? null) || $proof['candidate_count'] > $proof['candidate_max_id']) {
                throw new RuntimeException('Invalid explicit frozen all-repeats hold proof.');
            }
        }
        self::historyPolicy($proof);
        foreach (['coverage_manifest_sha256','coverage_state_sha256','source_guard'] as $key) self::digest($proof[$key] ?? null);
        $bounds = ['source_watermark_pk','prospect_watermark_pk','candidate_count','candidate_max_id'];
        if ($proof['format'] === 1) $bounds = [...$bounds, 'excluded_conflicts','excluded_repeated_nonconflicting'];
        foreach ($bounds as $key) {
            if (! is_int($proof[$key] ?? null) || $proof[$key] < 0) throw new RuntimeException('Invalid frozen backfill bounds.');
        }
        if ($proof['candidate_max_id'] > $proof['source_watermark_pk'] || $proof['candidate_max_id'] > 2147483647
            || (($proof['candidate_count'] === 0) !== ($proof['candidate_max_id'] === 0))) throw new RuntimeException('Inconsistent backfill bounds.');
    }

    private static function run(PDO $db, string $id, bool $lock): ?array
    {
        return self::rows($db, 'SELECT * FROM dbo.TblSMSProspectBackfillRuns'.($lock ? ' WITH(UPDLOCK,HOLDLOCK)' : '').' WHERE Run_ID=?', [$id])[0] ?? null;
    }

    private static function publicRun(array $run): array
    {
        $proof = json_decode($run['Proof'], true, 32, JSON_THROW_ON_ERROR);
        return ['run_id' => $run['Run_ID'], 'status' => $run['Status'], 'after_id' => (int) $run['After_ID'],
            'batches' => (int) $run['Batches'], 'checked' => (int) $run['Checked'], 'added' => (int) $run['Added'],
            'existing' => (int) $run['Existing'], 'deferred' => (int) $run['Deferred'], 'history_reviewed' => (bool) $run['History_Reviewed'],
            'history_policy' => self::historyPolicy($proof)];
    }

    private static function historyPolicy(array $proof): string
    {
        $policy = array_key_exists('history_policy', $proof) ? $proof['history_policy'] : self::VERIFIED_HISTORY;
        if (! in_array($policy, [self::VERIFIED_HISTORY, self::NO_PRIOR_SENDS], true)) {
            throw new RuntimeException('Unknown frozen backfill history policy.');
        }
        return $policy;
    }

    private static function batchResult(array $row, bool $replayed): array
    {
        return ['after_id' => (int) $row['After_ID'], 'through_id' => (int) $row['Through_ID'], 'checked' => (int) $row['Checked'],
            'added' => (int) $row['Added'], 'existing' => (int) $row['Existing'], 'deferred' => (int) $row['Deferred'], 'replayed' => $replayed];
    }

    private static function locked(PDO $db, callable $work): mixed
    {
        $held = [];
        try {
            foreach (self::LOCKS as $name) {
                $lock = self::rows($db, "DECLARE @r int; EXEC @r=sys.sp_getapplock @Resource=?,@LockMode='Exclusive',@LockOwner='Session',@LockTimeout=0; SELECT @r AS result", [$name])[0];
                if ((int) $lock['result'] < 0) throw new RuntimeException('Another SMS workflow is active; no backfill chunk was started.');
                $held[] = $name;
            }
            self::assertNoExternalEvents($db);
            return $work();
        } finally {
            foreach (array_reverse($held) as $name) self::execute($db, "EXEC sys.sp_releaseapplock @Resource=?,@LockOwner='Session'", [$name]);
        }
    }

    private static function assertNoExternalEvents(PDO $db): void
    {
        $object = self::rows($db, "SELECT OBJECT_ID(N'dbo.TblSMSExternalEvents',N'U') AS id")[0]['id'] ?? null;
        if ($object !== null && self::rows($db, 'SELECT TOP(1) Request_ID FROM dbo.TblSMSExternalEvents WITH(HOLDLOCK)') !== []) {
            throw new RuntimeException('This legacy phone/day writer does not support exact-PK external SMS events. Keep historical evidence unchanged and use the dedicated external-event workflow.');
        }
    }

    private static function transaction(PDO $db, callable $work): mixed
    {
        if (! $db->beginTransaction()) throw new RuntimeException('Could not start the backfill transaction.');
        try {
            $result = $work();
            if (! $db->commit()) throw new RuntimeException('Backfill commit was not acknowledged; reload its SQL checkpoint.');
            return $result;
        } catch (Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }
    }

    private static function dedicated(PDO $db): void
    {
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlsrv' || $db->inTransaction()) throw new RuntimeException('Use a dedicated SQL Server connection outside a transaction.');
        // Temp-table ALTER COLUMN is not permitted in a SNAPSHOT transaction.
        // This writer owns its session; explicit workflow, source-control, table
        // and identity-range locks provide its read/write consistency instead.
        $db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
    }

    private static function digest(mixed $value): void
    {
        if (! is_string($value) || ! preg_match('/^[a-f0-9]{64}$/D', $value)) throw new RuntimeException('Invalid evidence digest.');
    }

    private static function runId(string $value): void
    {
        if (! preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD', $value)) throw new RuntimeException('Use a UUID backfill run ID.');
    }

    private static function rows(PDO $db, string $sql, array $bindings = []): array
    {
        $query = $db->prepare($sql);
        try {
            $query->execute($bindings);
            while ($query->columnCount() === 0 && $query->nextRowset()) {}
            return $query->columnCount() === 0 ? [] : $query->fetchAll(PDO::FETCH_ASSOC);
        }
        finally { $query->closeCursor(); }
    }

    private static function execute(PDO $db, string $sql, array $bindings = []): void
    {
        $query = $db->prepare($sql);
        try { $query->execute($bindings); }
        finally { $query->closeCursor(); }
    }
}
