<?php

declare(strict_types=1);

/** Evidence-only audit. No apply mode, Laravel boot, DDL, DML, queues or provider calls. */
final class SmsProspectReconciliation
{
    public const FORMAT = 1;
    public const CATEGORIES = ['matched_same_contacts', 'matched_contact_differences', 'ambiguous_source_match',
        'no_indexed_source_match', 'invalid_prospect_identity'];

    public static function select(PDO $db, string $sql, array $bindings = []): array
    {
        // All queries are internal constants. Never accept SQL/identifiers from CLI input.
        if (! preg_match('/^\s*(?:SELECT|WITH)\b/i', $sql)
            || preg_match('/\b(?:INSERT|UPDATE|DELETE|MERGE|DROP|ALTER|CREATE|TRUNCATE|EXEC(?:UTE)?|INTO|NOLOCK)\b/i', $sql)) {
            throw new RuntimeException('Only the reviewed read-only queries are allowed.');
        }
        $query = $db->prepare($sql);
        try {
            foreach ($bindings as $i => $value) $query->bindValue($i + 1, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
            $query->execute();
            return $query->fetchAll(PDO::FETCH_ASSOC);
        } finally { $query->closeCursor(); }
    }

    public static function metadata(PDO $db): array
    {
        $identity = self::select($db, "SELECT @@SERVERNAME AS Server_Name, DB_NAME() AS Database_Name,
            CONVERT(varchar(128),DATABASEPROPERTYEX(DB_NAME(),'Collation')) AS Collation,
            snapshot_isolation_state_desc AS Snapshot_Isolation FROM sys.databases WHERE name=DB_NAME()")[0];
        if ($identity['Collation'] !== 'SQL_Latin1_General_CP1_CI_AS') throw new RuntimeException('The reviewed case-insensitive matching collation is not active.');
        $columns = self::select($db, "SELECT OBJECT_SCHEMA_NAME(c.object_id) AS Schema_Name,
            OBJECT_NAME(c.object_id) AS Table_Name,c.name AS Column_Name,TYPE_NAME(c.user_type_id) AS Data_Type,c.max_length
            FROM sys.columns c WHERE c.object_id IN (OBJECT_ID('dbo.TblProspects'),OBJECT_ID('dbo.TblMailersUniqueEnriched'),
                OBJECT_ID('tu_repair_20261014.Lineage')) ORDER BY c.object_id,c.column_id");
        foreach (['TblProspects' => ['PK','Client','Address','City','State','Zip','Phone','Email','Creation_Date','Tier',
            'Contacted','Response_Date','Invalid_Phone','Opt_Out','Latest_Export_Date','SMS_Attempts'],
            'TblMailersUniqueEnriched' => ['PK','Client','Address','City','State','Zip','Zip5_Normalized','Phone','Email','Drop_Name']] as $table => $required) {
            $present = array_column(array_values(array_filter($columns, fn ($c) => $c['Schema_Name'] === 'dbo' && $c['Table_Name'] === $table)), 'Column_Name');
            if (array_diff($required, $present)) throw new RuntimeException('Required prospect/source schema is missing.');
        }
        $indexes = self::select($db, "SELECT OBJECT_NAME(i.object_id) AS Table_Name,i.name AS Index_Name,
            i.is_unique,i.is_disabled,i.has_filter,ic.key_ordinal,c.name AS Column_Name
            FROM sys.indexes i JOIN sys.index_columns ic ON ic.object_id=i.object_id AND ic.index_id=i.index_id
            JOIN sys.columns c ON c.object_id=ic.object_id AND c.column_id=ic.column_id
            WHERE i.object_id IN (OBJECT_ID('dbo.TblProspects'),OBJECT_ID('dbo.TblMailersUniqueEnriched'))
                AND i.index_id>0 AND ic.key_ordinal>0 ORDER BY i.object_id,i.index_id,ic.key_ordinal");
        foreach (['TblProspects' => 'PK', 'TblMailersUniqueEnriched' => 'Address'] as $table => $key) {
            $usable = array_filter($indexes, fn ($i) => $i['Table_Name'] === $table && $i['Column_Name'] === $key
                && (int) $i['key_ordinal'] === 1 && ! $i['is_disabled'] && ! $i['has_filter']
                && ($table !== 'TblProspects' || ($i['is_unique'] && ! array_filter($indexes,
                    fn ($other) => $other['Table_Name'] === $table && $other['Index_Name'] === $i['Index_Name'] && (int) $other['key_ordinal'] > 1))));
            if (! $usable) throw new RuntimeException('A required leading-key index is unavailable; no source scan will be started.');
        }
        $repair = self::select($db, "SELECT c.Status, c.ShadowObjectID, c.OriginalObjectID, c.PostPublishWrites,
            OBJECT_ID('dbo.TblMailersUniqueEnriched') AS Live_Object_ID,
            (SELECT SUM(row_count) FROM sys.dm_db_partition_stats WHERE object_id=c.ShadowObjectID AND index_id IN(0,1)) AS Source_Rows
            FROM tu_repair_20261014.Control c WHERE c.ID=1")[0] ?? [];
        if (($repair['Status'] ?? '') !== 'PUBLISHED' || (int) ($repair['ShadowObjectID'] ?? 0) <= 0
            || (int) $repair['ShadowObjectID'] !== (int) ($repair['Live_Object_ID'] ?? 0)) {
            throw new RuntimeException('The repaired source is not confirmed as the published live table.');
        }
        if ($identity['Snapshot_Isolation'] !== 'ON') throw new RuntimeException('SNAPSHOT isolation must already be enabled; database options will not be changed.');
        $bounds = self::select($db, 'SELECT COALESCE(MAX(PK),0) AS Max_PK FROM dbo.TblProspects')[0];
        try {
            $resources = self::select($db, 'SELECT TOP(1) end_time,avg_cpu_percent,avg_data_io_percent,avg_log_write_percent,
                DATEDIFF(second,end_time,SYSUTCDATETIME()) AS age_seconds FROM sys.dm_db_resource_stats ORDER BY end_time DESC')[0] ?? ['unavailable' => true];
        } catch (PDOException) { $resources = ['unavailable' => true]; }
        return ['identity' => $identity, 'repair' => $repair, 'columns' => $columns, 'indexes' => $indexes,
            'prospect_max_pk' => (int) $bounds['Max_PK'], 'resources' => $resources];
    }

    /** Source/DDL guard, not proof that no in-place data changes occurred between batches. */
    public static function sourceGuard(array $metadata): string
    {
        return hash('sha256', self::json([$metadata['identity'], $metadata['repair'], $metadata['columns'], $metadata['indexes']]));
    }

    public static function counts(): array { return array_fill_keys(self::CATEGORIES, 0); }

    /** A short SNAPSHOT transaction covers one keyset batch, not the whole multi-hour audit. */
    public static function batch(PDO $db, int $afterPk, int $maxPk, int $size): array
    {
        if ($afterPk < 0 || $maxPk < $afterPk || $size < 1 || $size > 1000) throw new RuntimeException('Invalid bounded audit range.');
        if ($db->inTransaction()) throw new RuntimeException('Use a dedicated audit connection.');
        $started = microtime(true);
        $db->beginTransaction();
        try {
            $rows = self::select($db, "WITH Scope AS (
                SELECT TOP ({$size}) PK,Client,Address,City,State,Zip,Phone,Email,Creation_Date,Tier,
                    Contacted,Response_Date,Invalid_Phone,Opt_Out,Latest_Export_Date,SMS_Attempts
                FROM dbo.TblProspects WHERE PK>? AND PK<=? ORDER BY PK
            )
            SELECT p.*, e.PK AS Source_PK,e.Drop_Name AS Source_Drop,e.Phone AS Source_Phone,e.Email AS Source_Email,
                e.City AS Source_City,e.State AS Source_State
            FROM Scope p OUTER APPLY (
                SELECT TOP (2) e.PK,e.Drop_Name,e.Phone,e.Email,e.City,e.State
                FROM dbo.TblMailersUniqueEnriched e
                WHERE e.Address=CONVERT(nvarchar(255),p.Address)
                    AND LTRIM(RTRIM(e.Client))=CONVERT(nvarchar(150),LTRIM(RTRIM(p.Client)))
                    AND LEFT(COALESCE(NULLIF(LTRIM(RTRIM(e.Zip5_Normalized)),N''),LTRIM(RTRIM(e.Zip))),5)
                        =CONVERT(nvarchar(10),LTRIM(RTRIM(p.Zip)))
                ORDER BY e.PK DESC
            ) e ORDER BY p.PK,e.PK DESC OPTION(MAXDOP 1)", [$afterPk, $maxPk]);
            // No mutation exists in this transaction; rollback just releases the consistent read.
            $db->rollBack();
        } catch (Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }
        $grouped = [];
        foreach ($rows as $row) {
            $pk = (int) $row['PK'];
            if (! isset($grouped[$pk])) {
                $prospect = array_filter($row, fn ($key) => ! str_starts_with($key, 'Source_'), ARRAY_FILTER_USE_KEY);
                $grouped[$pk] = ['prospect' => $prospect, 'sources' => []];
            }
            if ($row['Source_PK'] !== null) {
                $grouped[$pk]['sources'][] = ['PK' => (int) $row['Source_PK'], 'Drop_Name' => $row['Source_Drop'],
                    'Phone' => $row['Source_Phone'], 'Email' => $row['Source_Email'], 'City' => $row['Source_City'], 'State' => $row['Source_State']];
            }
        }
        $counts = self::counts(); $details = []; $fingerprint = hash_init('sha256'); $lastPk = $afterPk;
        foreach ($grouped as $pk => $group) {
            $result = self::classify($group['prospect'], $group['sources']);
            $counts[$result['category']]++;
            hash_update($fingerprint, self::json($group)."\n");
            if ($result['category'] !== 'matched_same_contacts') $details[] = $result + $group;
            $lastPk = $pk;
        }
        return ['after_pk' => $afterPk, 'last_pk' => $lastPk, 'watermark_pk' => $maxPk, 'people' => count($grouped),
            'counts' => $counts, 'seconds' => round(microtime(true) - $started, 3),
            'observed_utc' => gmdate('c'), 'observed_sha256' => hash_final($fingerprint), 'details' => $details];
    }

    public static function phone(mixed $raw): ?string
    {
        $digits = preg_replace('/[^0-9]/', '', (string) $raw);
        if (strlen($digits) === 11 && str_starts_with($digits, '1')) $digits = substr($digits, 1);
        return preg_match('/^[0-9]{10}$/D', $digits) ? $digits : null;
    }

    public static function email(mixed $raw): ?string
    {
        $email = trim((string) $raw);
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? strtolower($email) : null;
    }

    /** Conservative evidence categories, never a proposed automatic UPDATE/DELETE. */
    public static function classify(array $prospect, array $sources): array
    {
        $identityValid = trim((string) ($prospect['Client'] ?? '')) !== '' && trim((string) ($prospect['Address'] ?? '')) !== ''
            && preg_match('/^[0-9]{5}$/D', trim((string) ($prospect['Zip'] ?? '')));
        $base = ['prospect_pk' => (int) $prospect['PK'], 'prospect_sha256' => hash('sha256', self::json($prospect)), 'differences' => []];
        if (! $identityValid) return ['category' => 'invalid_prospect_identity'] + $base;
        if (! $sources) return ['category' => 'no_indexed_source_match'] + $base;
        if (count($sources) > 1) return ['category' => 'ambiguous_source_match'] + $base;
        $differences = [];
        foreach (['Phone' => 'phone', 'Email' => 'email'] as $field => $method) {
            $old = self::$method($prospect[$field] ?? null); $new = self::$method($sources[0][$field] ?? null);
            if ($old !== $new) $differences[] = strtolower($field).($old === null ? '_available' : ($new === null ? '_unsupported' : '_changed'));
            foreach (['prospect' => $prospect, 'source' => $sources[0]] as $side => $row) {
                if (trim((string) ($row[$field] ?? '')) !== '' && self::$method($row[$field]) === null) $differences[] = $side.'_'.strtolower($field).'_unusable';
            }
        }
        return ['category' => $differences ? 'matched_contact_differences' : 'matched_same_contacts', 'differences' => $differences]
            + array_diff_key($base, ['differences' => true]);
    }

    public static function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public static function privateJson(string $path, mixed $value): void
    {
        if (file_exists($path) || is_link($path)) throw new RuntimeException('Refusing to overwrite audit evidence.');
        $handle = fopen($path, 'xb');
        if (! $handle) throw new RuntimeException('Cannot create private audit evidence.');
        try {
            chmod($path, 0600);
            $data = self::json($value)."\n";
            if (fwrite($handle, $data) !== strlen($data) || ! fflush($handle)) throw new RuntimeException('Incomplete audit evidence write.');
        } finally { fclose($handle); }
    }

    /** Publish a complete immutable directory; interrupted .partial directories are never resumed. */
    public static function saveBatch(string $directory, int $number, array $batch, string $manifestHash): void
    {
        $name = sprintf('batch-%08d', $number); $final = $directory.'/'.$name;
        if (file_exists($final) || is_link($final)) throw new RuntimeException('This completed batch already exists.');
        $partial = $directory.'/'.$name.'.'.bin2hex(random_bytes(8)).'.partial';
        if (! mkdir($partial, 0700)) throw new RuntimeException('Cannot create private batch directory.');
        $data = gzencode(self::json($batch), 6);
        if ($data === false) throw new RuntimeException('Cannot compress audit batch.');
        $handle = fopen($partial.'/evidence.json.gz', 'xb');
        if (! $handle) throw new RuntimeException('Cannot create batch evidence.');
        try {
            chmod($partial.'/evidence.json.gz', 0600);
            if (fwrite($handle, $data) !== strlen($data) || ! fflush($handle)) throw new RuntimeException('Incomplete compressed audit write.');
        } finally { fclose($handle); }
        self::privateJson($partial.'/summary.json', array_diff_key($batch, ['details' => true])
            + ['manifest_sha256' => $manifestHash, 'evidence_sha256' => hash('sha256', $data), 'evidence_bytes' => strlen($data)]);
        if (! rename($partial, $final)) throw new RuntimeException('Cannot publish completed batch.');
    }

    /** Verify journal before resuming; no trusting an editable last-PK checkpoint. */
    public static function journal(string $directory, array $manifest): array
    {
        $state = ['batches' => 0, 'after_pk' => 0, 'people' => 0, 'counts' => self::counts(), 'exhausted' => false];
        $manifestHash = hash_file('sha256', $directory.'/manifest.json');
        $paths = glob($directory.'/batch-*', GLOB_ONLYDIR) ?: []; sort($paths, SORT_STRING);
        foreach ($paths as $path) {
            if (str_ends_with($path, '.partial')) continue;
            if (is_link($path) || basename($path) !== sprintf('batch-%08d', $state['batches'] + 1)) throw new RuntimeException('Audit batch sequence is invalid.');
            foreach (['summary.json', 'evidence.json.gz'] as $file) if (is_link($path.'/'.$file)) throw new RuntimeException('Audit evidence must not be symlinked.');
            $summary = json_decode(file_get_contents($path.'/summary.json'), true, 512, JSON_THROW_ON_ERROR);
            $compressed = file_get_contents($path.'/evidence.json.gz');
            if (! hash_equals($summary['manifest_sha256'] ?? '', $manifestHash)
                || ! hash_equals($summary['evidence_sha256'] ?? '', hash('sha256', $compressed))
                || ($summary['evidence_bytes'] ?? null) !== strlen($compressed)) throw new RuntimeException('Audit evidence fingerprint mismatch.');
            $decoded = gzdecode($compressed);
            if ($decoded === false) throw new RuntimeException('Audit evidence is not a complete gzip archive.');
            $batch = json_decode($decoded, true, 512, JSON_THROW_ON_ERROR);
            if (array_diff_key($batch, ['details' => true]) !== array_diff_key($summary, array_flip(['manifest_sha256','evidence_sha256','evidence_bytes']))
                || $batch['after_pk'] !== $state['after_pk'] || $batch['watermark_pk'] !== $manifest['watermark_pk']
                || ! is_int($batch['people']) || $batch['people'] < 0 || $batch['people'] > $manifest['batch_size']
                || $batch['last_pk'] < $batch['after_pk'] || $batch['last_pk'] > $manifest['watermark_pk']
                || ($batch['people'] > 0 && $batch['last_pk'] === $batch['after_pk'])
                || ($batch['people'] === 0 && $batch['last_pk'] !== $batch['after_pk'])
                || array_keys($batch['counts']) !== self::CATEGORIES || array_sum($batch['counts']) !== $batch['people']
                || count($batch['details']) !== $batch['people'] - $batch['counts']['matched_same_contacts'] || $state['exhausted']) {
                throw new RuntimeException('Audit journal ranges or totals are invalid.');
            }
            foreach ($batch['counts'] as $key => $count) {
                if (! is_int($count) || $count < 0) throw new RuntimeException('Invalid audit category count.');
                $state['counts'][$key] += $count;
            }
            $state['batches']++; $state['after_pk'] = $batch['last_pk']; $state['people'] += $batch['people'];
            $state['exhausted'] = $batch['people'] < $manifest['batch_size'] || $batch['last_pk'] === $manifest['watermark_pk'];
        }
        return $state;
    }
}
