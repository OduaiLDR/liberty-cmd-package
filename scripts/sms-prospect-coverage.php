<?php

declare(strict_types=1);

require_once __DIR__.'/sms-prospect-reconciliation.php';

/** Azure SELECTs only. The separate SQLite journal is private audit evidence, never an apply queue. */
final class SmsProspectCoverage
{
    public const FORMAT = 1;
    public const COUNTERS = ['invalid_identity_rows', 'neither_rows', 'phone_only_rows', 'email_only_rows', 'both_rows',
        'invalid_phone_rows', 'invalid_email_rows', 'valid_contact_rows', 'existing_contact_rows', 'missing_contact_rows',
        'unique_contact_people', 'unique_existing_people', 'unique_missing_people', 'unique_contact_conflicts', 'missing_contact_conflicts'];

    public static function metadata(PDO $db): array
    {
        $metadata = SmsProspectReconciliation::metadata($db);
        $columns = array_column(array_filter($metadata['columns'], fn ($c) => $c['Schema_Name'] === 'dbo' && $c['Table_Name'] === 'TblMailersUniqueEnriched'), 'Column_Name');
        if (array_diff(['External_ID', 'Latest_Export_Date', 'enriched_at', 'last_seen_at'], $columns)) throw new RuntimeException('Required source evidence columns are unavailable.');
        foreach (['TblProspects' => ['Client', 'Address', 'Zip'], 'TblMailersUniqueEnriched' => ['PK']] as $table => $keys) {
            $groups = [];
            foreach ($metadata['indexes'] as $index) {
                if ($index['Table_Name'] === $table && ! $index['is_disabled'] && ! $index['has_filter']
                    && ($table !== 'TblMailersUniqueEnriched' || $index['is_unique'])) $groups[$index['Index_Name']][] = $index['Column_Name'];
            }
            if (! in_array($keys, $groups, true)) throw new RuntimeException('Required bounded source/identity index is unavailable.');
        }
        $bounds = SmsProspectReconciliation::select($db, 'SELECT COALESCE(MAX(PK),0) AS source_max_pk FROM dbo.TblMailersUniqueEnriched')[0];
        $compatibility = SmsProspectReconciliation::select($db, 'SELECT compatibility_level FROM sys.databases WHERE name=DB_NAME()')[0];
        if ((int) $compatibility['compatibility_level'] < 130) throw new RuntimeException('JSON row comparison needs database compatibility level 130 or later.');
        $metadata['source_max_pk'] = (int) $bounds['source_max_pk'];
        $metadata['compatibility_level'] = (int) $compatibility['compatibility_level'];
        return $metadata;
    }

    public static function sourceGuard(array $metadata): string
    {
        return hash('sha256', SmsProspectReconciliation::sourceGuard($metadata).':'.$metadata['compatibility_level']);
    }

    public static function assertResources(array $metadata): void
    {
        $r = $metadata['resources'];
        if (isset($r['unavailable']) || ! isset($r['age_seconds'], $r['avg_cpu_percent'], $r['avg_data_io_percent'], $r['avg_log_write_percent'])
            || (int) $r['age_seconds'] < 0 || (int) $r['age_seconds'] > 120
            || max((float) $r['avg_cpu_percent'], (float) $r['avg_data_io_percent'], (float) $r['avg_log_write_percent']) >= 80) {
            throw new RuntimeException('Recent resource statistics are unavailable or CPU/data/log IO is at least 80%; resume later.');
        }
    }

    /** No lossy truncation or replacement characters are accepted as a person's identity. */
    public static function normalize(array $row): array
    {
        $rawPhone = $row['Phone'] ?? null; $rawEmail = $row['Email'] ?? null;
        $phone = SmsProspectReconciliation::phone($rawPhone);
        $email = trim((string) $rawEmail);
        $email = strlen($email) <= 254 && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
        $identity = (int) ($row['Identity_Valid'] ?? 0) === 1;
        foreach (['Client', 'Address', 'Zip'] as $field) {
            $value = (string) ($row[$field] ?? '');
            if ($value === '' || trim($value) !== $value || preg_match('/[\x00-\x1f\x7f]/', $value)) $identity = false;
        }
        if (! preg_match('/^[0-9]{5}$/D', (string) ($row['Zip'] ?? ''))) $identity = false;
        return ['identity_valid' => $identity, 'Phone' => $phone, 'Email' => $email,
            'invalid_phone' => trim((string) $rawPhone) !== '' && $phone === null,
            'invalid_email' => trim((string) $rawEmail) !== '' && $email === null];
    }

    /** Short snapshot and indexed PK/identity reads; source PK is a cursor, not a recency rule. */
    public static function sourceRows(PDO $db, int $afterPk, int $sourceMaxPk, int $prospectMaxPk, int $size): array
    {
        if ($afterPk < 0 || $sourceMaxPk < $afterPk || $sourceMaxPk > 2147483647 || $prospectMaxPk < 0 || $prospectMaxPk > 2147483647 || $size < 1 || $size > 1000) throw new RuntimeException('Invalid bounded source range.');
        return SmsProspectReconciliation::select($db, "WITH Scope AS (
            SELECT TOP ({$size}) PK,Client AS Raw_Client,Address AS Raw_Address,City,State,Zip AS Raw_Zip,Zip5_Normalized,
                Phone,Email,Drop_Name,External_ID,Latest_Export_Date,enriched_at,last_seen_at
            FROM dbo.TblMailersUniqueEnriched WHERE PK>? AND PK<=? ORDER BY PK
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
            CHECKSUM(n.Client,n.Address,n.Zip) AS Bucket,p.Existing_Count
        FROM Normalized n OUTER APPLY (
            SELECT COUNT(*) AS Existing_Count FROM (
                SELECT TOP (2) q.PK FROM dbo.TblProspects q
                WHERE n.Identity_Valid=1 AND q.PK<={$prospectMaxPk} AND q.Client=n.Client AND q.Address=n.Address AND q.Zip=n.Zip
            ) matches
        ) p ORDER BY n.PK OPTION(MAXDOP 1)", [$afterPk, $sourceMaxPk]);
    }

    /**
     * CHECKSUM is a lookup bucket only, never identity proof. Exact grouping uses SQL Server's
     * VARCHAR collation on every collision, including case-equivalent and cross-batch identities.
     * Both SELECTs use the same types/collation. No staging table or temporary Azure write exists.
     * https://learn.microsoft.com/en-us/sql/t-sql/functions/checksum-transact-sql
     */
    public static function identityGroups(PDO $db, array $current, array $prior): array
    {
        $rows = [];
        foreach (['n' => $current, 'p' => $prior] as $kind => $items) {
            foreach ($items as $item) $rows[] = ['Kind' => $kind, 'Token' => (int) ($item['PK'] ?? $item['id']),
                'Bucket' => (int) ($item['Bucket'] ?? $item['bucket']), 'Client' => $item['Client'] ?? $item['client'],
                'Address' => $item['Address'] ?? $item['address'], 'Zip' => $item['Zip'] ?? $item['zip']];
        }
        if (count($current) > 1000 || count($rows) > 20000) throw new RuntimeException('Audit comparison bucket exceeded its bounded size; no identities were merged.');
        if ($current === []) return [];
        $json = SmsProspectReconciliation::json($rows);
        if (strlen($json) > 8 * 1024 * 1024) throw new RuntimeException('Audit comparison payload exceeded its bound.');
        $result = SmsProspectReconciliation::select($db, "WITH Inputs AS (
            SELECT Kind,Token,Bucket,
                CAST(Client AS varchar(150)) COLLATE SQL_Latin1_General_CP1_CI_AS AS Client,
                CAST(Address AS varchar(200)) COLLATE SQL_Latin1_General_CP1_CI_AS AS Address,
                CAST(Zip AS varchar(10)) COLLATE SQL_Latin1_General_CP1_CI_AS AS Zip
            FROM OPENJSON(CAST(? AS nvarchar(max))) WITH (Kind char(1),Token int,Bucket int,Client nvarchar(150),Address nvarchar(200),Zip nvarchar(10))
        ), Groups AS (
            SELECT *,COALESCE(MIN(CASE WHEN Kind='p' THEN Token END) OVER(PARTITION BY Bucket,Client,Address,Zip),
                MIN(CASE WHEN Kind='n' THEN Token END) OVER(PARTITION BY Bucket,Client,Address,Zip)) AS Canonical_ID
            FROM Inputs
        ) SELECT Kind,Token,Canonical_ID,CASE WHEN Bucket=CHECKSUM(Client,Address,Zip) THEN 1 ELSE 0 END AS Bucket_Valid FROM Groups OPTION(MAXDOP 1)", [$json]);
        $groups = [];
        foreach ($result as $row) {
            if ((int) $row['Bucket_Valid'] !== 1) throw new RuntimeException('Identity comparison type/collation guard failed.');
            if ($row['Kind'] === 'n') $groups[(int) $row['Token']] = (int) $row['Canonical_ID'];
        }
        if (count($groups) !== count($current)) throw new RuntimeException('Incomplete bounded identity comparison.');
        return $groups;
    }

    public static function initialize(PDO $journal, array $manifest): void
    {
        self::manifest($manifest); self::sqlite($journal);
        if ((int) $journal->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table'")->fetchColumn() !== 0) throw new RuntimeException('Refusing to replace existing audit evidence.');
        $journal->exec('PRAGMA journal_mode=WAL; PRAGMA synchronous=FULL; PRAGMA foreign_keys=ON');
        $journal->beginTransaction();
        try {
            $journal->exec('CREATE TABLE state (id INTEGER PRIMARY KEY CHECK(id=1), manifest_sha256 TEXT NOT NULL, payload TEXT NOT NULL);
                CREATE TABLE identities (id INTEGER PRIMARY KEY,bucket INTEGER NOT NULL,client TEXT NOT NULL,address TEXT NOT NULL,zip TEXT NOT NULL,
                    source_rows INTEGER NOT NULL,existing_count INTEGER NOT NULL,contact_conflict INTEGER NOT NULL,contact_key TEXT NOT NULL,evidence BLOB);
                CREATE INDEX ix_identities_bucket ON identities(bucket);
                CREATE TABLE batches (number INTEGER PRIMARY KEY,after_pk INTEGER NOT NULL,last_pk INTEGER NOT NULL,source_rows INTEGER NOT NULL,
                    observed_sha256 TEXT NOT NULL,observed_utc TEXT NOT NULL,state_sha256 TEXT NOT NULL,payload TEXT NOT NULL)');
            $state = ['batches' => 0, 'after_pk' => 0, 'source_rows' => 0, 'counts' => array_fill_keys(self::COUNTERS, 0), 'exhausted' => false];
            $journal->prepare('INSERT INTO state VALUES(1,?,?)')->execute([self::manifestHash($manifest), SmsProspectReconciliation::json($state)]);
            $journal->commit();
        } catch (Throwable $error) { if ($journal->inTransaction()) $journal->rollBack(); throw $error; }
    }

    public static function state(PDO $journal, array $manifest): array
    {
        self::manifest($manifest); self::sqlite($journal);
        $row = $journal->query('SELECT manifest_sha256,payload FROM state WHERE id=1')->fetch(PDO::FETCH_ASSOC);
        if (! $row || ! hash_equals(self::manifestHash($manifest), $row['manifest_sha256'])) throw new RuntimeException('Coverage journal manifest mismatch.');
        $state = json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR);
        if (! is_int($state['batches'] ?? null) || $state['batches'] < 0 || ! is_int($state['after_pk'] ?? null) || $state['after_pk'] < 0
            || $state['after_pk'] > $manifest['source_watermark_pk'] || ! is_int($state['source_rows'] ?? null) || $state['source_rows'] < 0
            || ! is_bool($state['exhausted'] ?? null) || array_keys($state['counts'] ?? []) !== self::COUNTERS) throw new RuntimeException('Invalid coverage journal state.');
        foreach ($state['counts'] as $value) if (! is_int($value) || $value < 0) throw new RuntimeException('Invalid coverage counter.');
        $c = $state['counts'];
        if ($state['source_rows'] !== $c['invalid_identity_rows'] + $c['neither_rows'] + $c['phone_only_rows'] + $c['email_only_rows'] + $c['both_rows']
            || $c['valid_contact_rows'] !== $c['phone_only_rows'] + $c['email_only_rows'] + $c['both_rows']
            || $c['valid_contact_rows'] !== $c['existing_contact_rows'] + $c['missing_contact_rows']
            || $c['unique_contact_people'] !== $c['unique_existing_people'] + $c['unique_missing_people']
            || $c['unique_contact_people'] > $c['valid_contact_rows'] || $c['unique_contact_conflicts'] > $c['unique_contact_people']
            || $c['missing_contact_conflicts'] > $c['unique_missing_people'] || $c['missing_contact_conflicts'] > $c['unique_contact_conflicts']) throw new RuntimeException('Coverage counter reconciliation failed.');
        $last = $journal->query('SELECT * FROM batches ORDER BY number DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        if (($state['batches'] === 0 && ($last || $state['source_rows'] !== 0 || $state['after_pk'] !== 0))
            || ($state['batches'] > 0 && (! $last || (int) $last['number'] !== $state['batches'] || (int) $last['last_pk'] !== $state['after_pk']
                || $last['payload'] !== $row['payload'] || ! hash_equals($last['state_sha256'], hash('sha256', $row['payload']))))) throw new RuntimeException('Coverage checkpoint integrity check failed.');
        return $state;
    }

    /** One source batch and all its identity/counter/checkpoint changes publish atomically in SQLite. */
    public static function auditBatch(PDO $source, PDO $journal, array $manifest): array
    {
        $state = self::state($journal, $manifest);
        if ($state['exhausted']) return $state;
        if ($source->inTransaction() || $journal->inTransaction()) throw new RuntimeException('Use dedicated audit connections.');
        $source->beginTransaction();
        try {
            $rows = self::sourceRows($source, $state['after_pk'], $manifest['source_watermark_pk'], $manifest['prospect_watermark_pk'], $manifest['batch_size']);
            $current = []; $normalized = [];
            $previousPk = $state['after_pk'];
            foreach ($rows as $row) {
                $pk = (int) $row['PK'];
                if ($pk <= $previousPk || $pk > $manifest['source_watermark_pk']) throw new RuntimeException('Nonmonotonic source audit cursor.');
                $previousPk = $pk; $contact = self::normalize($row); $normalized[$pk] = $contact;
                if ($contact['identity_valid'] && ($contact['Phone'] !== null || $contact['Email'] !== null)) $current[] = $row;
            }
            $prior = [];
            $buckets = array_values(array_unique(array_column($current, 'Bucket')));
            foreach (array_chunk($buckets, 500) as $chunk) {
                $query = $journal->prepare('SELECT id,bucket,client,address,zip FROM identities WHERE bucket IN('.implode(',', array_fill(0, count($chunk), '?')).') LIMIT 20001');
                $query->execute($chunk); $prior = array_merge($prior, $query->fetchAll(PDO::FETCH_ASSOC)); $query->closeCursor();
                if (count($prior) + count($current) > 20000) throw new RuntimeException('Identity collision bucket exceeded its bound; no identities were merged.');
            }
            $groups = self::identityGroups($source, $current, $prior);
            $source->rollBack();
        } catch (Throwable $error) { if ($source->inTransaction()) $source->rollBack(); throw $error; }

        $observedHash = hash('sha256', SmsProspectReconciliation::json($rows));
        $journal->beginTransaction();
        try {
            if (self::state($journal, $manifest) !== $state) throw new RuntimeException('Audit checkpoint changed during its source read.');
            $after = $state;
            foreach ($rows as $row) {
                $pk = (int) $row['PK']; $contact = $normalized[$pk]; $c = &$after['counts'];
                $after['source_rows']++; $after['after_pk'] = $pk;
                $c['invalid_phone_rows'] += (int) $contact['invalid_phone']; $c['invalid_email_rows'] += (int) $contact['invalid_email'];
                if (! $contact['identity_valid']) { $c['invalid_identity_rows']++; continue; }
                if ($contact['Phone'] === null && $contact['Email'] === null) { $c['neither_rows']++; continue; }
                $c[$contact['Phone'] === null ? 'email_only_rows' : ($contact['Email'] === null ? 'phone_only_rows' : 'both_rows')]++;
                $c['valid_contact_rows']++;
                $exists = (int) $row['Existing_Count'];
                if ($exists < 0 || $exists > 2) throw new RuntimeException('Invalid bounded prospect identity match count.');
                $c[$exists > 0 ? 'existing_contact_rows' : 'missing_contact_rows']++;
                $id = $groups[$pk];
                $query = $journal->prepare('SELECT existing_count,contact_conflict,contact_key FROM identities WHERE id=?');
                $query->execute([$id]); $known = $query->fetch(PDO::FETCH_ASSOC); $query->closeCursor();
                $key = hash('sha256', SmsProspectReconciliation::json([$contact['Phone'], $contact['Email'] === null ? null : strtolower($contact['Email'])]));
                if (! $known) {
                    if ($id !== $pk) throw new RuntimeException('Missing canonical identity checkpoint.');
                    $evidence = $row; $evidence['Phone'] = $contact['Phone']; $evidence['Email'] = $contact['Email'];
                    $evidence['observed_row_sha256'] = hash('sha256', SmsProspectReconciliation::json($row));
                    $compressed = $exists > 0 ? null : gzencode(SmsProspectReconciliation::json($evidence), 1);
                    if ($compressed === false) throw new RuntimeException('Cannot preserve source evidence.');
                    $insert = $journal->prepare('INSERT INTO identities VALUES(?,?,?,?,?,1,?,0,?,?)');
                    $insert->bindValue(1, $id, PDO::PARAM_INT); $insert->bindValue(2, (int) $row['Bucket'], PDO::PARAM_INT);
                    foreach ([3 => 'Client', 4 => 'Address', 5 => 'Zip'] as $position => $field) $insert->bindValue($position, $row[$field]);
                    $insert->bindValue(6, $exists, PDO::PARAM_INT); $insert->bindValue(7, $key);
                    $insert->bindValue(8, $compressed, $compressed === null ? PDO::PARAM_NULL : PDO::PARAM_LOB); $insert->execute();
                    $c['unique_contact_people']++; $c[$exists > 0 ? 'unique_existing_people' : 'unique_missing_people']++;
                } else {
                    if (((int) $known['existing_count'] > 0) !== ($exists > 0)) throw new RuntimeException('Prospect identity coverage changed during the audit; start a new report.');
                    $conflict = ! hash_equals($known['contact_key'], $key);
                    if ($conflict && ! $known['contact_conflict']) {
                        $c['unique_contact_conflicts']++; if ($exists === 0) $c['missing_contact_conflicts']++;
                    }
                    $update = $journal->prepare('UPDATE identities SET source_rows=source_rows+1,contact_conflict=MAX(contact_conflict,?) WHERE id=?');
                    // SQLite MAX orders TEXT above INTEGER; bind integers so an agreement cannot clear a conflict.
                    $update->bindValue(1, (int) $conflict, PDO::PARAM_INT); $update->bindValue(2, $id, PDO::PARAM_INT); $update->execute();
                }
            }
            unset($c);
            $after['batches']++;
            $after['exhausted'] = count($rows) < $manifest['batch_size'] || $after['after_pk'] === $manifest['source_watermark_pk'];
            $payload = SmsProspectReconciliation::json($after);
            $journal->prepare('INSERT INTO batches VALUES(?,?,?,?,?,?,?,?)')->execute([$after['batches'], $state['after_pk'], $after['after_pk'], count($rows), $observedHash, gmdate('c'), hash('sha256', $payload), $payload]);
            $journal->prepare('UPDATE state SET payload=? WHERE id=1')->execute([$payload]);
            self::state($journal, $manifest);
            $journal->commit();
            return $after;
        } catch (Throwable $error) { if ($journal->inTransaction()) $journal->rollBack(); throw $error; }
    }

    private static function sqlite(PDO $journal): void
    {
        if ($journal->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') throw new RuntimeException('The audit journal must be a separate local SQLite database.');
        $journal->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    private static function manifest(array $manifest): void
    {
        if (($manifest['format'] ?? null) !== self::FORMAT || ($manifest['read_only'] ?? null) !== true || ($manifest['apply_supported'] ?? null) !== false) throw new RuntimeException('This is a read-only coverage audit, not an apply tool.');
        foreach (['source_watermark_pk', 'prospect_watermark_pk'] as $key) if (! is_int($manifest[$key] ?? null) || $manifest[$key] < 0 || $manifest[$key] > 2147483647) throw new RuntimeException('Invalid source/prospect watermark.');
        if (! is_int($manifest['batch_size'] ?? null) || $manifest['batch_size'] < 1 || $manifest['batch_size'] > 1000) throw new RuntimeException('Invalid bounded audit size.');
    }

    private static function manifestHash(array $manifest): string { return hash('sha256', SmsProspectReconciliation::json($manifest)); }
}
