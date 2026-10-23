<?php

declare(strict_types=1);

require_once __DIR__.'/sms-prospect-reconciliation.php';

/**
 * Local evidence only: no application boot, providers, environment or remote database.
 *
 * FORMAT/POLICY pin the proof keys, ordered source ordinals (one based), raw-byte SHA256,
 * and event SHA256 = canonical json([integer id, string phone, string date])+"\n", in ID order.
 * Rows count data records, occurrences count EVERY populated phone slot (including repeats),
 * duplicates = occurrences - unique phone/day events. Source paths and discarded columns
 * are never stored. Header normalization removes an initial UTF-8 BOM and ASCII whitespace,
 * and folds ASCII case. Dates are exact ISO calendar dates; future-date policy is the CLI's.
 * MAX_ROW_BYTES includes CSV delimiters, line endings and any initial BOM. Quoted multiline
 * fields and doubled quotes are supported; LF/CRLF and a final record without EOL are valid.
 *
 * build() owns an EMPTY dedicated SQLite connection. An interrupted artifact is never reused.
 * proof() checks the complete journal once before apply; page() checks completion and each
 * bounded page. The caller must hold the closed artifact immutable throughout proof/apply.
 */
final class SmsLegacyHistoryFiles
{
    public const FORMAT = 1;
    public const POLICY = 'verified-phone-day-csv-v1';
    public const MAX_ROW_BYTES = 1048576;
    public const MAX_PAGE_SIZE = 1000;
    public const MAX_EVENT_ID = 2147483647; // Matches the append-only writer's SQL int key.
    public const MAX_FILES = 10;
    public const COMMIT_EVERY = 5000; // Phone occurrences, not source rows.
    private const MAX_PROOF_BYTES = 1048576;
    private const HEADER = ['firstname', 'address', 'debtload', 'phone1', 'phone2', 'phone3', 'phone4', 'phone5', 'senddate'];
    private const EVENTS_SQL = 'CREATE TABLE events (id INTEGER PRIMARY KEY, phone TEXT NOT NULL, send_date TEXT NOT NULL, UNIQUE(phone,send_date))';
    private const STATE_SQL = 'CREATE TABLE state (id INTEGER PRIMARY KEY CHECK(id=1), complete INTEGER NOT NULL CHECK(complete IN (0,1)), payload TEXT)';
    // Bound materialization even if an artifact was corrupted to contain enormous cell values.
    private const EVENT_COLUMNS = "id,
        CASE WHEN typeof(phone)='text' AND length(CAST(phone AS BLOB))=10 THEN phone END AS phone,
        CASE WHEN typeof(send_date)='text' AND length(CAST(send_date AS BLOB))=10 THEN send_date END AS send_date";

    public static function build(PDO $sqlite, array $paths, string $expectedISODate): array
    {
        $handles = []; $part = 0; $row = 0; $transaction = false; $mode = null;
        try {
            self::connection($sqlite);
            if ($sqlite->inTransaction() || ! self::date($expectedISODate) || ! $paths || count($paths) > self::MAX_FILES || ! array_is_list($paths)) {
                throw new RuntimeException('Invalid build input.');
            }
            $mode = $sqlite->getAttribute(PDO::ATTR_ERRMODE);
            $sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            // Reject every existing schema object, including empty tables and temp artifacts.
            if ($sqlite->query('SELECT COUNT(*) FROM main.sqlite_master')->fetchColumn() !== 0
                || $sqlite->query('SELECT COUNT(*) FROM temp.sqlite_master')->fetchColumn() !== 0) {
                throw new RuntimeException('Artifact already exists.');
            }
            $sqlite->beginTransaction(); $transaction = true;
            $sqlite->exec(self::EVENTS_SQL);
            $sqlite->exec(self::STATE_SQL);
            $sqlite->exec('INSERT INTO state(id,complete,payload) VALUES(1,0,NULL)');
            $sqlite->commit(); $transaction = false;

            $insert = $sqlite->prepare('INSERT INTO events(phone,send_date) VALUES(?,?) ON CONFLICT(phone,send_date) DO NOTHING');
            $insert->bindValue(2, $expectedISODate, PDO::PARAM_STR);
            $files = []; $rows = 0; $occurrences = 0; $pending = 0;
            foreach ($paths as $path) {
                $part++; $row = 1;
                $before = self::sourceStat($path);
                $handle = @fopen($path, 'rb');
                if ($handle === false) throw new RuntimeException('Source cannot be opened.');
                $handles[] = ['handle' => $handle, 'path' => $path, 'stat' => $before];
                if (! @flock($handle, LOCK_SH | LOCK_NB) || self::handleStat($handle) !== $before) {
                    throw new RuntimeException('Source identity or lock is invalid.');
                }
                $hash = hash_init('sha256'); $bytes = 0; $fileRows = 0; $fileOccurrences = 0;
                foreach (self::records($handle, $hash, $bytes, $row) as $fields) {
                    if ($row === 1) {
                        $header = array_map(static fn (string $field): string => strtolower(preg_replace('/[ \t\r\n\v\f]+/', '', $field)), $fields);
                        if ($header !== self::HEADER) throw new RuntimeException('Invalid source header.');
                        continue;
                    }
                    if ($fields[8] !== $expectedISODate) throw new RuntimeException('Invalid source date.');
                    $phones = [];
                    for ($slot = 3; $slot <= 7; $slot++) {
                        $phone = trim($fields[$slot], " \t");
                        if ($phone === '') continue;
                        $phones[] = self::phone($phone);
                    }
                    if (! $phones) throw new RuntimeException('Missing source phone.');
                    foreach ($phones as $phone) {
                        if (! $transaction) { $sqlite->beginTransaction(); $transaction = true; }
                        $insert->bindValue(1, $phone, PDO::PARAM_STR);
                        $insert->execute(); $insert->closeCursor();
                        if (++$pending === self::COMMIT_EVERY) {
                            $sqlite->commit(); $transaction = false; $pending = 0;
                        }
                    }
                    $fileRows++; $fileOccurrences += count($phones);
                }
                if ($fileRows === 0 || self::handleStat($handle) !== $before || self::sourceStat($path) !== $before) {
                    throw new RuntimeException('Source is empty or changed.');
                }
                $files[] = ['part' => $part, 'sha256' => hash_final($hash), 'bytes' => $bytes,
                    'rows' => $fileRows, 'phone_occurrences' => $fileOccurrences];
                $rows += $fileRows; $occurrences += $fileOccurrences;
            }
            if ($transaction) { $sqlite->commit(); $transaction = false; }
            // Keep every shared lock until ALL files have been read, then reread raw bytes.
            // This catches same-size/same-timestamp edits and changes to earlier parts.
            foreach ($handles as $index => $source) {
                $part = $index + 1; $row = 0;
                [$sha, $bytes] = self::fingerprint($source['handle']);
                if (! hash_equals($files[$index]['sha256'], $sha) || $files[$index]['bytes'] !== $bytes
                    || self::handleStat($source['handle']) !== $source['stat'] || self::sourceStat($source['path']) !== $source['stat']) {
                    throw new RuntimeException('Source changed.');
                }
            }
            $summary = self::eventSummary($sqlite, $expectedISODate);
            $proof = ['format' => self::FORMAT, 'complete' => true, 'policy' => self::POLICY,
                'sent_date' => $expectedISODate, 'files' => $files, 'rows' => $rows,
                'phone_occurrences' => $occurrences, 'unique_events' => $summary['count'],
                'duplicate_events' => $occurrences - $summary['count'], 'event_max_id' => $summary['max'],
                'events_sha256' => $summary['sha256']];
            $payload = SmsProspectReconciliation::json($proof);
            if (strlen($payload) > self::MAX_PROOF_BYTES) throw new RuntimeException('Proof exceeds bound.');
            $sqlite->beginTransaction(); $transaction = true;
            $sqlite->prepare('UPDATE state SET complete=1,payload=? WHERE id=1')->execute([$payload]);
            $sqlite->commit(); $transaction = false;
            return $proof;
        } catch (Throwable) {
            if ($transaction && $sqlite->inTransaction()) @$sqlite->rollBack();
            // Never attach an underlying PDO/filesystem exception: it may contain customer data.
            throw new RuntimeException("Historical SMS evidence build refused (part {$part}, row {$row}).");
        } finally {
            foreach ($handles as $source) { @flock($source['handle'], LOCK_UN); @fclose($source['handle']); }
            if ($mode !== null) $sqlite->setAttribute(PDO::ATTR_ERRMODE, $mode);
        }
    }

    /** Read-only full reconciliation; use this before the CLI applies any event pages. */
    public static function proof(PDO $sqlite): array
    {
        return self::read($sqlite, static function () use ($sqlite): array {
            $proof = self::completed($sqlite);
            $summary = self::eventSummary($sqlite, $proof['sent_date']);
            if ($summary['count'] !== $proof['unique_events'] || $summary['max'] !== $proof['event_max_id']
                || ! hash_equals($proof['events_sha256'], $summary['sha256'])) {
                throw new RuntimeException('Event proof mismatch.');
            }
            return $proof;
        }, 'proof');
    }

    /** @return list<array{Event_ID:int,Phone:string,Send_Date:string}> */
    public static function page(PDO $sqlite, int $afterId, int $size = 250): array
    {
        return self::read($sqlite, static function () use ($sqlite, $afterId, $size): array {
            if ($afterId < 0 || $afterId > self::MAX_EVENT_ID || $size < 1 || $size > self::MAX_PAGE_SIZE) throw new RuntimeException('Invalid page range.');
            $proof = self::completed($sqlite);
            $query = $sqlite->prepare('SELECT '.self::EVENT_COLUMNS.' FROM events WHERE id>? ORDER BY id LIMIT ?');
            $query->bindValue(1, $afterId, PDO::PARAM_INT); $query->bindValue(2, $size, PDO::PARAM_INT);
            $query->execute(); $entries = []; $previous = $afterId;
            try {
                while (($event = $query->fetch(PDO::FETCH_ASSOC)) !== false) {
                    self::event($event, $previous, $proof['sent_date']);
                    if ($event['id'] > $proof['event_max_id']) throw new RuntimeException('Event outside proof.');
                    $entries[] = ['Event_ID' => $event['id'], 'Phone' => $event['phone'], 'Send_Date' => $event['send_date']];
                    $previous = $event['id'];
                }
            } finally { $query->closeCursor(); }
            return $entries;
        }, 'page');
    }

    private static function connection(PDO $sqlite): void
    {
        if ($sqlite->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') throw new RuntimeException('SQLite required.');
        if ($sqlite->query('SELECT COUNT(*) FROM temp.sqlite_master')->fetchColumn() !== 0) {
            throw new RuntimeException('Temporary schema must be empty.');
        }
        foreach ($sqlite->query('PRAGMA database_list')->fetchAll(PDO::FETCH_ASSOC) as $database) {
            if (! in_array($database['name'], ['main', 'temp'], true)) throw new RuntimeException('Dedicated SQLite required.');
        }
    }

    private static function read(PDO $sqlite, Closure $reader, string $operation): array
    {
        $mode = null; $transaction = false;
        try {
            self::connection($sqlite);
            $mode = $sqlite->getAttribute(PDO::ATTR_ERRMODE);
            $sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            if (! $sqlite->inTransaction()) { $sqlite->beginTransaction(); $transaction = true; }
            return $reader();
        } catch (Throwable) {
            throw new RuntimeException('Historical SMS evidence '.$operation.' refused.');
        } finally {
            if ($transaction && $sqlite->inTransaction()) $sqlite->rollBack();
            if ($mode !== null) $sqlite->setAttribute(PDO::ATTR_ERRMODE, $mode);
        }
    }

    private static function date(string $date): bool
    {
        return preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $date) === 1
            && checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4));
    }

    private static function phone(string $phone): string
    {
        // ASCII digits, standard 3-3-4 separators or a parenthesized area code; no extensions,
        // letters, dangling punctuation or other country codes. Leading zeroes are evidence.
        if (! preg_match('/\A(?:\+?1[ .-]?)?(?:[0-9]{10}|[0-9]{3}([ .-])[0-9]{3}\1[0-9]{4}|\([0-9]{3}\) ?[0-9]{3}[ -]?[0-9]{4})\z/', $phone)) {
            throw new RuntimeException('Invalid phone.');
        }
        $normalized = SmsProspectReconciliation::phone($phone);
        if ($normalized === null) throw new RuntimeException('Invalid phone.');
        return $normalized;
    }

    /** lstat every ancestor too: a regular final file below a linked directory is not eligible. */
    private static function sourceStat(mixed $path): array
    {
        if (! is_string($path) || str_contains($path, "\0")
            || ! preg_match('~\A(?:[A-Za-z]:[\\\\/]|/|\\\\\\\\[^\\\\/]+[\\\\/][^\\\\/]+[\\\\/])~', $path)) {
            throw new RuntimeException('Absolute local source required.');
        }
        $source = null;
        for ($current = $path; ; $current = $parent) {
            clearstatcache(true, $current);
            $stat = @lstat($current);
            if ($stat === false || is_link($current)
                || ($stat['mode'] & 0170000) !== ($source === null ? 0100000 : 0040000)
                || ($source === null && $stat['nlink'] !== 1)) {
                throw new RuntimeException('Regular unlinked source required.');
            }
            if ($source === null) $source = $stat;
            $parent = dirname($current);
            if ($parent === $current) break;
        }
        return self::stableStat($source);
    }

    private static function stableStat(array $stat): array
    {
        return array_intersect_key($stat, array_flip(['dev', 'ino', 'mode', 'nlink', 'size', 'mtime', 'ctime']));
    }

    private static function handleStat($handle): array
    {
        $stat = @fstat($handle);
        if ($stat === false) throw new RuntimeException('Source identity unavailable.');
        return self::stableStat($stat);
    }

    private static function fingerprint($handle): array
    {
        if (@fseek($handle, 0) !== 0) throw new RuntimeException('Source cannot be rewound.');
        $hash = hash_init('sha256'); $bytes = 0;
        while (! feof($handle)) {
            $chunk = @fread($handle, 65536);
            if ($chunk === false || ($chunk === '' && ! feof($handle))) throw new RuntimeException('Incomplete source read.');
            hash_update($hash, $chunk); $bytes += strlen($chunk);
        }
        return [hash_final($hash), $bytes];
    }

    /** Strict RFC-style CSV state machine. fgets and logical records are independently bounded. */
    private static function records($handle, $hash, int &$bytes, int &$row): Generator
    {
        $state = 0; $fields = []; $field = ''; $recordBytes = 0;
        while (($chunk = @fgets($handle, self::MAX_ROW_BYTES + 2)) !== false) {
            $length = strlen($chunk); hash_update($hash, $chunk); $bytes += $length; $recordBytes += $length;
            if ($recordBytes > self::MAX_ROW_BYTES || str_contains($chunk, "\0")) throw new RuntimeException('Invalid bounded CSV record.');
            if ($bytes === $length && str_starts_with($chunk, "\xEF\xBB\xBF")) $chunk = substr($chunk, 3);
            $length = strlen($chunk); $end = false;
            for ($offset = 0; $offset < $length;) {
                if ($state === 2) { // Quoted field: newlines and commas are literal.
                    $span = strcspn($chunk, '"', $offset);
                    $field .= substr($chunk, $offset, $span); $offset += $span;
                    if ($offset < $length) { $state = 3; $offset++; }
                    continue;
                }
                $char = $chunk[$offset];
                if ($char === '"') {
                    if ($state === 0) $state = 2;
                    elseif ($state === 3) { $field .= '"'; $state = 2; }
                    else throw new RuntimeException('Broken CSV quote.');
                    $offset++; continue;
                }
                if ($char === ',') {
                    $fields[] = $field; $field = ''; $state = 0; $offset++;
                    if (count($fields) >= 9) throw new RuntimeException('Invalid CSV width.');
                    continue;
                }
                if ($char === "\r" || $char === "\n") {
                    if ($char === "\r" && substr($chunk, $offset, 2) !== "\r\n") throw new RuntimeException('Broken CSV line ending.');
                    $offset += $char === "\r" ? 2 : 1;
                    if ($offset !== $length) throw new RuntimeException('Broken CSV record.');
                    $end = true; break;
                }
                if ($state === 3) throw new RuntimeException('Trailing CSV quote data.');
                $state = 1; $span = strcspn($chunk, ",\"\r\n", $offset);
                $field .= substr($chunk, $offset, $span); $offset += $span;
            }
            if ($end) {
                $fields[] = $field;
                if (count($fields) !== 9) throw new RuntimeException('Invalid CSV width.');
                yield $fields;
                $row++; $state = 0; $fields = []; $field = ''; $recordBytes = 0;
            }
        }
        if (! feof($handle) || $state === 2) throw new RuntimeException('Truncated CSV record.');
        if ($recordBytes > 0) {
            $fields[] = $field;
            if (count($fields) !== 9) throw new RuntimeException('Invalid CSV width.');
            yield $fields;
        }
    }

    private static function completed(PDO $sqlite): array
    {
        // Enforce the journal's actual INTEGER PRIMARY KEY and phone/day uniqueness, not a
        // lookalike view/table with weaker storage types or duplicate events.
        $schema = $sqlite->query("SELECT name,sql FROM main.sqlite_master WHERE name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_KEY_PAIR);
        if ($schema !== ['events' => self::EVENTS_SQL, 'state' => self::STATE_SQL]) throw new RuntimeException('Invalid journal schema.');
        $query = $sqlite->query("SELECT complete,CASE WHEN typeof(payload)='text'
            AND length(CAST(payload AS BLOB))<=".self::MAX_PROOF_BYTES." THEN payload END AS payload FROM state");
        $state = $query->fetch(PDO::FETCH_ASSOC); $extra = $query->fetch(PDO::FETCH_ASSOC); $query->closeCursor();
        if ($extra !== false || ! is_array($state) || $state['complete'] !== 1 || ! is_string($state['payload'])) {
            throw new RuntimeException('Incomplete journal.');
        }
        $proof = json_decode($state['payload'], true, 16, JSON_THROW_ON_ERROR);
        if (! is_array($proof) || array_keys($proof) !== ['format', 'complete', 'policy', 'sent_date', 'files', 'rows',
            'phone_occurrences', 'unique_events', 'duplicate_events', 'event_max_id', 'events_sha256']
            || $proof['format'] !== self::FORMAT || $proof['complete'] !== true || $proof['policy'] !== self::POLICY
            || ! is_string($proof['sent_date']) || ! self::date($proof['sent_date'])
            || ! is_array($proof['files']) || ! array_is_list($proof['files']) || ! $proof['files'] || count($proof['files']) > self::MAX_FILES
            || ! self::sha($proof['events_sha256']) || SmsProspectReconciliation::json($proof) !== $state['payload']) {
            throw new RuntimeException('Invalid complete proof.');
        }
        foreach (['rows', 'phone_occurrences', 'unique_events', 'duplicate_events', 'event_max_id'] as $key) {
            if (! is_int($proof[$key]) || $proof[$key] < 0) throw new RuntimeException('Invalid proof counter.');
        }
        $rows = 0; $occurrences = 0;
        foreach ($proof['files'] as $index => $file) {
            if (! is_array($file) || array_keys($file) !== ['part', 'sha256', 'bytes', 'rows', 'phone_occurrences']
                || $file['part'] !== $index + 1 || ! self::sha($file['sha256'])) throw new RuntimeException('Invalid source proof.');
            foreach (['bytes', 'rows', 'phone_occurrences'] as $key) {
                if (! is_int($file[$key]) || $file[$key] < 1) throw new RuntimeException('Invalid source counter.');
            }
            if ($file['phone_occurrences'] < $file['rows'] || $file['phone_occurrences'] > $file['rows'] * 5) {
                throw new RuntimeException('Invalid source occurrences.');
            }
            $rows += $file['rows']; $occurrences += $file['phone_occurrences'];
        }
        if ($rows !== $proof['rows'] || $occurrences !== $proof['phone_occurrences'] || $proof['unique_events'] < 1
            || $proof['event_max_id'] < $proof['unique_events'] || $proof['event_max_id'] > self::MAX_EVENT_ID || $proof['unique_events'] > $occurrences
            || $proof['duplicate_events'] !== $occurrences - $proof['unique_events']) throw new RuntimeException('Invalid proof totals.');
        return $proof;
    }

    private static function sha(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[0-9a-f]{64}\z/', $value) === 1;
    }

    private static function event(array $event, int $previous, string $date): void
    {
        if (! is_int($event['id']) || $event['id'] <= $previous || $event['id'] > self::MAX_EVENT_ID || ! is_string($event['phone'])
            || ! preg_match('/\A[0-9]{10}\z/', $event['phone']) || $event['send_date'] !== $date) {
            throw new RuntimeException('Invalid journal event.');
        }
    }

    private static function eventSummary(PDO $sqlite, string $date): array
    {
        $totals = $sqlite->query('SELECT COUNT(*) AS total,COALESCE(MAX(id),0) AS maximum FROM events')->fetch(PDO::FETCH_ASSOC);
        if (! is_int($totals['total']) || ! is_int($totals['maximum']) || $totals['total'] < 0 || $totals['maximum'] < 0) {
            throw new RuntimeException('Invalid event totals.');
        }
        $query = $sqlite->query('SELECT '.self::EVENT_COLUMNS.' FROM events ORDER BY id');
        $count = 0; $previous = 0; $hash = hash_init('sha256');
        try {
            while (($event = $query->fetch(PDO::FETCH_ASSOC)) !== false) {
                self::event($event, $previous, $date); $previous = $event['id']; $count++;
                hash_update($hash, SmsProspectReconciliation::json([$event['id'], $event['phone'], $event['send_date']])."\n");
            }
        } finally { $query->closeCursor(); }
        if ($count !== $totals['total'] || $previous !== $totals['maximum']) throw new RuntimeException('Event totals changed.');
        return ['count' => $count, 'max' => $totals['maximum'], 'sha256' => hash_final($hash)];
    }
}
