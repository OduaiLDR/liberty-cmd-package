<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

use PDO;
use RuntimeException;
use Throwable;

/** Append verified phone/day evidence only. Never updates prospects, campaigns or existing sends. */
final class SmsLegacyHistoryImport
{
    public const POLICY = 'verified-phone-day-csv-v1';

    public const MAX_BATCH = 1000;

    private const LOCKS = ['sms:new-run', 'sms:prospect-maintenance', 'sms:prospect-admission', 'sms:contact-sync', 'sms:phone-suppression', 'sms:legacy-history-import'];

    public static function schemaSql(): string
    {
        return <<<'SQL'
CREATE TABLE dbo.TblSMSLegacyImportRuns (
 Run_ID uniqueidentifier NOT NULL PRIMARY KEY,Plan_SHA256 char(64) NOT NULL,Proof nvarchar(max) NOT NULL,
 Actor nvarchar(255) NOT NULL,Confirmed_Sent bit NOT NULL,
 After_ID int NOT NULL DEFAULT 0,Batches int NOT NULL DEFAULT 0,Checked bigint NOT NULL DEFAULT 0,
 Added bigint NOT NULL DEFAULT 0,Existing bigint NOT NULL DEFAULT 0,Status varchar(20) NOT NULL DEFAULT 'ready',
 Created_At datetime2 NOT NULL DEFAULT SYSUTCDATETIME(),Updated_At datetime2 NOT NULL DEFAULT SYSUTCDATETIME()
);
CREATE TABLE dbo.TblSMSLegacyImportChunks (
 Run_ID uniqueidentifier NOT NULL,After_ID int NOT NULL,Through_ID int NOT NULL,Input_SHA256 char(64) NOT NULL,
 Checked int NOT NULL,Added int NOT NULL,Existing int NOT NULL,Committed_At datetime2 NOT NULL DEFAULT SYSUTCDATETIME(),
 CONSTRAINT PK_SMSLegacyImportChunks PRIMARY KEY(Run_ID,After_ID),
 CONSTRAINT FK_SMSLegacyImportChunks_Run FOREIGN KEY(Run_ID) REFERENCES dbo.TblSMSLegacyImportRuns(Run_ID)
);
CREATE TABLE dbo.TblSMSLegacyImportRows (
 Run_ID uniqueidentifier NOT NULL,Event_ID int NOT NULL,Decision varchar(10) NOT NULL,Phone_Date_SHA256 char(64) NOT NULL,
 CONSTRAINT PK_SMSLegacyImportRows PRIMARY KEY(Run_ID,Event_ID),
 CONSTRAINT FK_SMSLegacyImportRows_Run FOREIGN KEY(Run_ID) REFERENCES dbo.TblSMSLegacyImportRuns(Run_ID)
);
SQL;
    }

    public static function install(PDO $db): void
    {
        self::dedicated($db);
        self::assertNoExternalEvents($db);
        self::transaction($db, function () use ($db): void {
            foreach (['Runs', 'Chunks', 'Rows'] as $suffix) {
                if (self::rows($db, 'SELECT OBJECT_ID(?) AS id', ['dbo.TblSMSLegacyImport'.$suffix])[0]['id'] !== null) {
                    throw new RuntimeException('History import ledger exists or is partially installed; never replace it.');
                }
            }
            $db->exec(self::schemaSql());
        });
    }

    public static function begin(PDO $db, string $runId, array $proof, string $actor, bool $confirmedSent = false): array
    {
        self::dedicated($db);
        self::uuid($runId);
        self::proof($proof);
        if (! $confirmedSent) {
            throw new RuntimeException('Actual sending must be confirmed; an export file alone is not proof of a send.');
        }
        if ($actor === '' || trim($actor) !== $actor || ! preg_match('//u', $actor) || mb_strlen($actor) > 255 || preg_match('/[\x00-\x1f\x7f]/', $actor)) {
            throw new RuntimeException('A valid history import actor is required.');
        }
        $json = self::json($proof);
        $hash = hash('sha256', $json);

        return self::locked($db, fn () => self::transaction($db, function () use ($db, $runId, $actor, $hash, $json): array {
            $known = self::run($db, $runId, true);
            if ($known !== null) {
                if (! hash_equals($known['Plan_SHA256'], $hash) || ! (bool) $known['Confirmed_Sent']) {
                    throw new RuntimeException('History import evidence or confirmation changed.');
                }

                return self::publicRun($known);
            }
            self::guard($db);
            self::execute($db, 'INSERT dbo.TblSMSLegacyImportRuns(Run_ID,Plan_SHA256,Proof,Actor,Confirmed_Sent) VALUES(?,?,?,?,1)', [$runId, $hash, $json, $actor]);

            return self::publicRun(self::run($db, $runId, true));
        }));
    }

    public static function applyBatch(PDO $db, string $runId, int $afterId, array $events): array
    {
        self::dedicated($db);
        self::uuid($runId);
        if ($afterId < 0 || count($events) < 1 || count($events) > self::MAX_BATCH) {
            throw new RuntimeException('Invalid bounded history import batch.');
        }
        $previous = $afterId;
        foreach ($events as $event) {
            if (array_keys($event) !== ['Event_ID', 'Phone', 'Send_Date'] || ! is_int($event['Event_ID']) || $event['Event_ID'] <= $previous || $event['Event_ID'] > 2147483647
                || ! is_string($event['Phone']) || ! preg_match('/^[0-9]{10}$/D', $event['Phone'])) {
                throw new RuntimeException('Invalid history import event.');
            }
            self::date($event['Send_Date']);
            $previous = $event['Event_ID'];
        }
        $hash = hash('sha256', self::json($events));

        return self::locked($db, fn () => self::transaction($db, function () use ($db, $runId, $afterId, $events, $hash): array {
            $run = self::run($db, $runId, true);
            if ($run === null || ! (bool) $run['Confirmed_Sent']) {
                throw new RuntimeException('Create a confirmed history import first.');
            }
            $committed = self::rows($db, 'SELECT * FROM dbo.TblSMSLegacyImportChunks WHERE Run_ID=? AND After_ID=?', [$runId, $afterId])[0] ?? null;
            if ($committed !== null) {
                if (! hash_equals($committed['Input_SHA256'], $hash)) {
                    throw new RuntimeException('A committed history checkpoint cannot accept different events.');
                }

                return self::chunk($committed, true);
            }
            if ($run['Status'] !== 'ready' || (int) $run['After_ID'] !== $afterId) {
                throw new RuntimeException('History cursor changed; reload the SQL checkpoint.');
            }
            $proof = json_decode($run['Proof'], true, 64, JSON_THROW_ON_ERROR);
            self::proof($proof);
            if (end($events)['Event_ID'] > $proof['event_max_id'] || (int) $run['Checked'] + count($events) > $proof['unique_events']) {
                throw new RuntimeException('History batch exceeds its frozen evidence.');
            }
            foreach ($events as $event) {
                if ($event['Send_Date'] !== $proof['sent_date']) {
                    throw new RuntimeException('History event date differs from the reviewed files.');
                }
            }
            self::guard($db);
            $counts = self::rows($db, <<<'SQL'
SET NOCOUNT ON;
DECLARE @run uniqueidentifier=?;
SELECT * INTO #LegacyInput FROM OPENJSON(CAST(? AS nvarchar(max))) WITH(Event_ID int,Phone varchar(10),Send_Date date);
CREATE UNIQUE CLUSTERED INDEX IX_LegacyInput ON #LegacyInput(Event_ID);
ALTER TABLE #LegacyInput ALTER COLUMN Phone varchar(10) COLLATE SQL_Latin1_General_CP1_CI_AS NULL;
CREATE UNIQUE INDEX IX_LegacyInputPhoneDate ON #LegacyInput(Phone,Send_Date);
IF EXISTS(SELECT 1 FROM #LegacyInput i JOIN dbo.TblSMSLegacySends s WITH(UPDLOCK,HOLDLOCK) ON s.Phone=i.Phone AND s.Send_Date=i.Send_Date WHERE s.Request_ID IS NOT NULL)
 THROW 52401,'An existing linked send needs manual reconciliation; no history chunk was committed.',1;
-- A phone/day import cannot establish whether a same-day modern send is its mirror.
IF EXISTS(SELECT 1 FROM #LegacyInput i CROSS JOIN dbo.TblSMSRuns r JOIN dbo.TblSMSHistory h WITH(HOLDLOCK,FORCESEEK)
 ON h.Request_ID=r.Request_ID AND h.Phone=i.Phone WHERE r.Status='sent'
 AND (r.Sent_At IS NULL OR CAST(r.Sent_At AT TIME ZONE 'UTC' AT TIME ZONE 'Pacific Standard Time' AS date)=i.Send_Date))
 THROW 52402,'Historical evidence overlaps a modern sent event; reconcile it before importing.',1;
SELECT i.Event_ID INTO #LegacyExisting FROM #LegacyInput i JOIN dbo.TblSMSLegacySends s WITH(UPDLOCK,HOLDLOCK) ON s.Phone=i.Phone AND s.Send_Date=i.Send_Date;
INSERT dbo.TblSMSLegacySends(Phone,Send_Date,Request_ID)
 SELECT i.Phone,i.Send_Date,NULL FROM #LegacyInput i WHERE NOT EXISTS(SELECT 1 FROM #LegacyExisting e WHERE e.Event_ID=i.Event_ID);
INSERT dbo.TblSMSLegacyImportRows(Run_ID,Event_ID,Decision,Phone_Date_SHA256)
 SELECT @run,i.Event_ID,CASE WHEN e.Event_ID IS NULL THEN 'added' ELSE 'existing' END,
 CONVERT(char(64),HASHBYTES('SHA2_256',CONCAT(i.Phone,N'|',CONVERT(char(10),i.Send_Date,23))),2)
 FROM #LegacyInput i LEFT JOIN #LegacyExisting e ON e.Event_ID=i.Event_ID;
DECLARE @total int=(SELECT COUNT(*) FROM #LegacyInput),@existing int=(SELECT COUNT(*) FROM #LegacyExisting);
DROP TABLE #LegacyInput,#LegacyExisting;
SELECT @total-@existing AS Added,@existing AS Existing;
SQL, [$runId, self::json($events)])[0];
            $result = ['Run_ID' => $runId, 'After_ID' => $afterId, 'Through_ID' => end($events)['Event_ID'], 'Input_SHA256' => $hash,
                'Checked' => count($events), 'Added' => (int) $counts['Added'], 'Existing' => (int) $counts['Existing']];
            self::execute($db, 'INSERT dbo.TblSMSLegacyImportChunks(Run_ID,After_ID,Through_ID,Input_SHA256,Checked,Added,Existing) VALUES(?,?,?,?,?,?,?)', array_values($result));
            self::execute($db, 'UPDATE dbo.TblSMSLegacyImportRuns SET After_ID=?,Batches=Batches+1,Checked=Checked+?,Added=Added+?,Existing=Existing+?,Updated_At=SYSUTCDATETIME() WHERE Run_ID=?',
                [$result['Through_ID'], $result['Checked'], $result['Added'], $result['Existing'], $runId]);
            if ($result['Added'] > 0 && self::rows($db, "SELECT OBJECT_ID('dbo.TblSMSAvailabilitySummary') AS id")[0]['id'] !== null) {
                self::execute($db, 'UPDATE dbo.TblSMSAvailabilitySummary SET revision=revision+1');
            }

            return self::chunk($result, false);
        }));
    }

    public static function finish(PDO $db, string $runId): array
    {
        self::dedicated($db);
        self::uuid($runId);

        return self::locked($db, fn () => self::transaction($db, function () use ($db, $runId): array {
            $run = self::run($db, $runId, true);
            if ($run === null) {
                throw new RuntimeException('Unknown history import.');
            }
            $proof = json_decode($run['Proof'], true, 64, JSON_THROW_ON_ERROR);
            self::proof($proof);
            if ((int) $run['Checked'] !== $proof['unique_events'] || (int) $run['After_ID'] !== $proof['event_max_id']) {
                throw new RuntimeException('History import has not exhausted the verified files.');
            }
            self::guard($db);
            self::execute($db, "UPDATE dbo.TblSMSLegacyImportRuns SET Status='completed',Updated_At=SYSUTCDATETIME() WHERE Run_ID=?", [$runId]);

            return self::publicRun(self::run($db, $runId, true));
        }));
    }

    public static function status(PDO $db, string $runId): ?array
    {
        self::uuid($runId);
        $run = self::run($db, $runId, false);

        return $run === null ? null : self::publicRun($run);
    }

    private static function guard(PDO $db): void
    {
        foreach (['TblSMSLegacySends', 'TblSMSRuns', 'TblSMSHistory'] as $table) {
            if (self::rows($db, 'SELECT OBJECT_ID(?) AS id', ['dbo.'.$table])[0]['id'] === null) {
                throw new RuntimeException('Required history schema is unavailable.');
            }
        }
        $columns = self::rows($db, "SELECT name,TYPE_NAME(user_type_id) AS type,max_length,is_nullable FROM sys.columns WHERE object_id=OBJECT_ID('dbo.TblSMSLegacySends')");
        $columns = array_column($columns, null, 'name');
        if (! isset($columns['Phone'],$columns['Send_Date'],$columns['Request_ID']) || ! in_array($columns['Phone']['type'], ['varchar', 'nvarchar'], true)
            || (int) $columns['Phone']['max_length'] !== ($columns['Phone']['type'] === 'nvarchar' ? 20 : 10)
            || $columns['Send_Date']['type'] !== 'date' || $columns['Request_ID']['type'] !== 'uniqueidentifier' || ! (bool) $columns['Request_ID']['is_nullable']) {
            throw new RuntimeException('Review the legacy phone/day schema before importing.');
        }
        $indexes = self::rows($db, "SELECT OBJECT_NAME(i.object_id) AS name,i.index_id,i.is_unique,ic.key_ordinal,c.name AS column_name
 FROM sys.indexes i JOIN sys.index_columns ic ON ic.object_id=i.object_id AND ic.index_id=i.index_id JOIN sys.columns c ON c.object_id=ic.object_id AND c.column_id=ic.column_id
 WHERE i.object_id IN(OBJECT_ID('dbo.TblSMSLegacySends'),OBJECT_ID('dbo.TblSMSHistory')) AND i.is_disabled=0 AND i.has_filter=0 AND ic.key_ordinal>0 ORDER BY i.object_id,i.index_id,ic.key_ordinal");
        foreach (['TblSMSLegacySends' => ['Phone', 'Send_Date'], 'TblSMSHistory' => ['Request_ID', 'Phone']] as $table => $keys) {
            $groups = [];
            foreach ($indexes as $i) {
                if ($i['name'] === $table && ($table !== 'TblSMSLegacySends' || (bool) $i['is_unique'])) {
                    $groups[$i['index_id']][] = $i['column_name'];
                }
            }
            if (! array_filter($groups, fn (array $c): bool => ($table === 'TblSMSLegacySends' ? $c : array_slice($c, 0, 2)) === $keys)) {
                throw new RuntimeException('A required unique/history lookup index is unavailable.');
            }
        }
        if (self::rows($db, "SELECT TOP(1) object_id FROM sys.triggers WHERE parent_id=OBJECT_ID('dbo.TblSMSLegacySends') AND is_disabled=0") !== []) {
            throw new RuntimeException('Review legacy-send triggers before an append-only import.');
        }
        if (self::rows($db, "SELECT TOP(1) PK FROM dbo.TblSMSRuns WITH(TABLOCK,HOLDLOCK) WHERE Status NOT IN('sent','discarded','review_only','not_sent')") !== []) {
            throw new RuntimeException('Finish or discard the open SMS run before importing history.');
        }
    }

    private static function proof(array $proof): void
    {
        if (($proof['format'] ?? null) !== 1 || ($proof['complete'] ?? null) !== true || ($proof['policy'] ?? null) !== self::POLICY) {
            throw new RuntimeException('A complete verified history-file plan is required.');
        }
        self::date($proof['sent_date'] ?? null);
        foreach (['rows', 'phone_occurrences', 'unique_events', 'duplicate_events', 'event_max_id'] as $key) {
            if (! is_int($proof[$key] ?? null) || $proof[$key] < 0) {
                throw new RuntimeException('Invalid history evidence bounds.');
            }
        }
        if ($proof['unique_events'] < 1 || $proof['rows'] < 1 || $proof['phone_occurrences'] !== $proof['unique_events'] + $proof['duplicate_events'] || $proof['rows'] > $proof['phone_occurrences']
            || $proof['event_max_id'] < $proof['unique_events'] || $proof['event_max_id'] > 2147483647) {
            throw new RuntimeException('Inconsistent history evidence totals.');
        }
        self::digest($proof['events_sha256'] ?? null);
        if (! is_array($proof['files'] ?? null) || ! array_is_list($proof['files']) || count($proof['files']) < 1 || count($proof['files']) > 10) {
            throw new RuntimeException('Invalid history file manifest.');
        }
        $rows = 0;
        $phones = 0;
        foreach ($proof['files'] as $i => $file) {
            self::digest($file['sha256'] ?? null);
            if (($file['part'] ?? null) !== $i + 1) {
                throw new RuntimeException('Invalid history file ordering.');
            }
            foreach (['bytes', 'rows', 'phone_occurrences'] as $key) {
                if (! is_int($file[$key] ?? null) || $file[$key] < 1) {
                    throw new RuntimeException('Invalid history file bounds.');
                }
            }
            $rows += $file['rows'];
            $phones += $file['phone_occurrences'];
        }
        if ($rows !== $proof['rows'] || $phones !== $proof['phone_occurrences']) {
            throw new RuntimeException('History file totals differ from the reviewed scope.');
        }
    }

    private static function publicRun(array $row): array
    {
        $out = ['run_id' => $row['Run_ID'], 'status' => $row['Status']];
        foreach (['After_ID' => 'after_id', 'Batches' => 'batches', 'Checked' => 'checked', 'Added' => 'added', 'Existing' => 'existing'] as $k => $v) {
            $out[$v] = (int) $row[$k];
        }

        return $out;
    }

    private static function chunk(array $row, bool $replayed): array
    {
        $out = [];
        foreach (['After_ID' => 'after_id', 'Through_ID' => 'through_id', 'Checked' => 'checked', 'Added' => 'added', 'Existing' => 'existing'] as $k => $v) {
            $out[$v] = (int) $row[$k];
        }
        $out['replayed'] = $replayed;

        return $out;
    }

    private static function run(PDO $db, string $id, bool $lock): ?array
    {
        return self::rows($db, 'SELECT * FROM dbo.TblSMSLegacyImportRuns'.($lock ? ' WITH(UPDLOCK,HOLDLOCK)' : '').' WHERE Run_ID=?', [$id])[0] ?? null;
    }

    private static function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function uuid(string $id): void
    {
        if (! preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD', $id)) {
            throw new RuntimeException('A UUID history import ID is required.');
        }
    }

    private static function digest(mixed $value): void
    {
        if (! is_string($value) || ! preg_match('/^[a-f0-9]{64}$/D', $value)) {
            throw new RuntimeException('Invalid history evidence digest.');
        }
    }

    private static function date(mixed $value): void
    {
        $d = is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC')) : false;
        if (! $d || $d->format('Y-m-d') !== $value || $value > gmdate('Y-m-d')) {
            throw new RuntimeException('A valid past send date is required.');
        }
    }

    private static function dedicated(PDO $db): void
    {
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlsrv' || $db->inTransaction()) {
            throw new RuntimeException('Use a dedicated SQL Server connection outside a transaction.');
        }
    }

    private static function locked(PDO $db, callable $work): mixed
    {
        $held = [];
        try {
            foreach (self::LOCKS as $name) {
                $r = self::rows($db, "DECLARE @r int; EXEC @r=sys.sp_getapplock @Resource=?,@LockMode='Exclusive',@LockOwner='Session',@LockTimeout=0; SELECT @r AS result", [$name])[0];
                if ((int) $r['result'] < 0) {
                    throw new RuntimeException('Another SMS workflow is active; no history import was started.');
                }
                $held[] = $name;
            }

            self::assertNoExternalEvents($db);
            return $work();
        } finally {
            foreach (array_reverse($held) as $name) {
                self::execute($db, "EXEC sys.sp_releaseapplock @Resource=?,@LockOwner='Session'", [$name]);
            }
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
        if (! $db->beginTransaction()) {
            throw new RuntimeException('Cannot begin history transaction.');
        }
        try {
            $result = $work();
            if (! $db->commit()) {
                throw new RuntimeException('History commit was not acknowledged; reload its SQL checkpoint.');
            }

            return $result;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    private static function rows(PDO $db, string $sql, array $bindings = []): array
    {
        $s = $db->prepare($sql);
        try {
            $s->execute($bindings);
            while ($s->columnCount() === 0 && $s->nextRowset()) {
            }

            return $s->columnCount() === 0 ? [] : $s->fetchAll(PDO::FETCH_ASSOC);
        } finally {
            $s->closeCursor();
        }
    }

    private static function execute(PDO $db, string $sql, array $bindings = []): void
    {
        $s = $db->prepare($sql);
        try {
            $s->execute($bindings);
        } finally {
            $s->closeCursor();
        }
    }
}
