<?php

declare(strict_types=1);

namespace Cmd\Reports\Repositories;

use Carbon\CarbonImmutable;
use Cmd\Reports\Services\SmsProspectValues;
use Cmd\Reports\Services\SmsWorkflowLock;
use Illuminate\Database\Query\Builder;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Person-level weekly intake, independent of provider transport and legacy mailer import.
 *
 * stage(): canonical mailer records in numbered, immutable chunks (maximum MAX_RECORDS).
 * exportRows(): freezes membership and yields the legacy ten-column vendor contract, with
 *   Record_ID = SMSP-<ledger PK>. Repeated exports include the same admitted rows, even after
 *   returns; this does not claim that a provider has received or uploaded anything.
 * returned(): Record_ID + Phone/Email (or Phone Append 1/Email Append 1). Other fields are
 *   ignored. Changed replays/unknown IDs reject the whole request. complete=true closes
 *   nonreturned items; otherwise they retain their active identity reservation.
 * A nonnull Mailer_PK admitted to any vendor set is permanently deduplicated across batches,
 *   including no-match/completed batches; a different purchase PK may retry the same person.
 * summary()/recent(): counts and batch metadata only. "submitted" means admitted to the
 *   immutable vendor set, not a network acknowledgement. "rejected" counts invalid inputs;
 *   invalid returned contact fields have separate counters and count as no match.
 * withSummary=false avoids cumulative aggregation in stage/return loops. Stage returns
 *   immutable chunk counts (also on replay); returned returns per-call newly committed
 *   counts plus replayed/closed_unreturned. Defaults retain the full batch summary.
 */
final class SmsEnrichmentRepository extends SqlSrvRepository
{
    public const MAX_RECORDS = 1000;

    private const BATCHES = 'TblSMSEnrichmentBatches';
    private const CHUNKS = 'TblSMSEnrichmentChunks';
    private const PEOPLE = 'TblSMSEnrichmentPeople';

    public function stage(string $batchId, array $records, string $actor, int $chunk = 0, bool $withSummary = true): array
    {
        $this->assertBatchId($batchId);
        $this->assertBoundedList($records);
        $actor = trim($actor);
        if ($chunk < 0 || $chunk > 2147483647 || $actor === '' || mb_strlen($actor) > 255) {
            throw new HttpException(422, 'A nonnegative chunk index and an actor of at most 255 characters are required.');
        }
        $hash = $this->payloadHash($records);

        return SmsWorkflowLock::run('prospect-admission', fn (): array => $this->connection()->transaction(function () use ($batchId, $records, $actor, $chunk, $hash, $withSummary): array {
            $batch = $this->table(self::BATCHES)->where('Batch_ID', $batchId)->first();
            $previous = $this->table(self::CHUNKS)->where('Batch_ID', $batchId)->where('Chunk', $chunk)->first();
            if ($previous !== null) {
                if (! hash_equals($previous->Payload_Hash, $hash)) {
                    throw new HttpException(409, 'This intake chunk is already bound to a different payload.');
                }
                return $withSummary ? $this->summary($batchId) : $this->stagedChunkArray($batchId, $previous);
            }
            if ($batch !== null && $batch->Status !== 'staging') {
                throw new HttpException(409, 'This batch is frozen; new intake chunks need a new batch ID.');
            }
            $now = $this->now();
            if ($batch === null) {
                $this->table(self::BATCHES)->insert(['Batch_ID' => $batchId, 'Created_By' => $actor,
                    'Status' => 'staging', 'Created_At' => $now, 'Updated_At' => $now]);
            }
            $rejected = $duplicate = $existing = 0;
            $normalized = [];
            foreach ($records as $record) {
                $person = $this->normalizeRecord($record);
                if ($person === null) {
                    $rejected++;
                } else {
                    $normalized[] = $person;
                }
            }
            $purchased = [];
            $sourcePks = array_values(array_unique(array_filter(array_column($normalized, 'Mailer_PK'), static fn ($pk): bool => $pk !== null)));
            foreach (array_chunk($sourcePks, 500) as $keys) {
                foreach ($this->table(self::PEOPLE)->where('Submitted', true)->whereIn('Mailer_PK', $keys)->pluck('Mailer_PK') as $pk) {
                    $purchased[(string) $pk] = true;
                }
            }
            $people = [];
            foreach ($normalized as $person) {
                $sourcePk = $person['Mailer_PK'];
                if (($sourcePk !== null && isset($purchased[(string) $sourcePk])) || isset($people[$person['Person_Key']])) {
                    $duplicate++;
                    continue;
                }
                if ($sourcePk !== null) $purchased[(string) $sourcePk] = true;
                $people[$person['Person_Key']] = $person;
            }
            // Also reject values that SQL Server's existing VARCHAR prospect columns cannot
            // represent losslessly. Merely checking PHP character counts would allow '?' or
            // byte-length conversion collisions under a non-Unicode database code page.
            foreach ($this->unrepresentablePeople($people) as $key) {
                unset($people[$key]);
                $rejected++;
            }
            // Insert each bounded part before checking the next: a database-equivalent
            // identity with a different hash in a later part must see the earlier row.
            foreach (array_chunk($people, 200, true) as $part) {
                foreach ($this->equivalentIncomingKeys($part) as $key) {
                    unset($part[$key]);
                    $duplicate++;
                }
                $pending = $this->pendingPeople($part, $batchId);
                $prospects = $this->existingPeople($part);
                $inserts = [];
                foreach ($part as $key => $person) {
                    if (isset($pending[$key]) && (int) $pending[$key]->Same_Batch === 1) {
                        $duplicate++;
                        continue;
                    }
                    $status = isset($prospects[$key]) ? 'existing' : (isset($pending[$key]) ? 'duplicate' : 'pending');
                    $existing += $status === 'existing' ? 1 : 0;
                    $duplicate += $status === 'duplicate' ? 1 : 0;
                    $inserts[] = $person + ['Batch_ID' => $batchId, 'Status' => $status,
                        'Submitted' => $status === 'pending', 'Active_Key' => $status === 'pending' ? $key : null,
                        'Prospect_PK' => $prospects[$key]->PK ?? null, 'Created_At' => $now, 'Updated_At' => $now];
                }
                $this->insertBounded(self::PEOPLE, $inserts);
            }
            $staged = ['Batch_ID' => $batchId, 'Chunk' => $chunk, 'Payload_Hash' => $hash,
                'Records' => count($records), 'Rejected' => $rejected, 'Existing' => $existing,
                'Duplicate' => $duplicate, 'Created_By' => $actor, 'Created_At' => $now];
            $this->table(self::CHUNKS)->insert($staged);
            $this->table(self::BATCHES)->where('Batch_ID', $batchId)->update(['Updated_At' => $now]);

            return $withSummary ? $this->summary($batchId) : $this->stagedChunkArray($batchId, (object) $staged);
        }));
    }

    public function exportRows(string $batchId): iterable
    {
        $this->assertBatchId($batchId);
        SmsWorkflowLock::run('prospect-admission', function () use ($batchId): void {
            $batch = $this->requireBatch($batchId);
            if ($batch->Status !== 'staging') return;

            $hasSubmittedPeople = $this->table(self::PEOPLE)->where('Batch_ID', $batchId)->where('Submitted', true)->exists();
            $now = $this->now();
            $changes = ['Status' => $hasSubmittedPeople ? 'awaiting_return' : 'completed', 'Updated_At' => $now];
            // Close only when membership is frozen, not while later chunks can still arrive.
            if (! $hasSubmittedPeople) $changes['Completed_At'] = $now;
            $this->table(self::BATCHES)->where('Batch_ID', $batchId)->where('Status', 'staging')
                ->update($changes);
        });

        return $this->table(self::PEOPLE)->where('Batch_ID', $batchId)->where('Submitted', true)
            ->lazyById(500, 'PK')->map(static function (object $person): array {
                $parts = preg_split('/\s+/u', $person->Client) ?: [];
                $first = array_shift($parts) ?? '';
                $last = $parts === [] ? '' : array_pop($parts);
                return ['Record_ID' => 'SMSP-'.$person->PK, 'First_Name' => $first,
                    'Middle_Name' => implode(' ', $parts), 'Last_Name' => $last,
                    'Address' => $person->Address, 'City' => $person->City ?? '',
                    'State' => $person->State ?? '', 'Zip_Code' => $person->Zip,
                    'Phone_1' => '', 'Email_1' => ''];
            });
    }

    public function returned(string $batchId, array $results, bool $complete = false, bool $withSummary = true): array
    {
        $this->assertBatchId($batchId);
        $this->assertBoundedList($results);
        $normalized = [];
        foreach ($results as $result) {
            if (! is_array($result) || ! is_string($result['Record_ID'] ?? null)
                || ! preg_match('/^SMSP-([1-9][0-9]*)$/D', $result['Record_ID'], $match)
                || (string) (int) $match[1] !== $match[1]) {
                throw new HttpException(422, 'Every return needs a valid SMSP Record_ID.');
            }
            $id = (int) $match[1];
            $contact = $this->normalizeContact($result);
            if (isset($normalized[$id]) && $normalized[$id]['Return_Hash'] !== $contact['Return_Hash']) {
                throw new HttpException(409, 'This return contains conflicting values for the same Record_ID.');
            }
            $normalized[$id] = $contact;
        }

        return SmsWorkflowLock::run('prospect-admission', fn (): array => $this->connection()->transaction(function () use ($batchId, $normalized, $complete, $withSummary): array {
            $batch = $this->requireBatch($batchId);
            $items = [];
            foreach (array_chunk(array_keys($normalized), 500) as $ids) {
                foreach ($this->table(self::PEOPLE)->where('Batch_ID', $batchId)->where('Submitted', true)->whereIn('PK', $ids)->get() as $item) {
                    $items[(int) $item->PK] = $item;
                }
            }
            if (count($items) !== count($normalized)) {
                throw new HttpException(422, 'One or more return IDs do not belong to this batch\'s submitted items.');
            }
            $new = [];
            // Validate the entire request before any admission, including replay conflicts.
            foreach ($normalized as $id => $contact) {
                $item = $items[$id];
                if ($item->Returned_At !== null) {
                    if (! hash_equals($item->Return_Hash, $contact['Return_Hash'])) {
                        throw new HttpException(409, 'A returned item is already bound to different contact results.');
                    }
                } elseif ($batch->Status === 'completed' || $item->Status !== 'pending') {
                    throw new HttpException(409, 'A closed, nonreturned item cannot accept a late result.');
                } else {
                    $new[$id] = $contact;
                }
            }
            $sourcePeople = [];
            foreach ($new as $id => $contact) {
                if ($contact['Phone'] !== null || $contact['Email'] !== null) {
                    $item = $items[$id];
                    $sourcePeople[$item->Person_Key] = (array) $item;
                }
            }
            $prospects = $this->existingPeople($sourcePeople);
            $now = $this->now();
            $counts = ['batch_id' => $batchId, 'returned' => count($new), 'replayed' => count($normalized) - count($new),
                'phone' => 0, 'email' => 0, 'both' => 0, 'neither' => 0, 'added' => 0, 'filled' => 0,
                'invalid_phone' => 0, 'invalid_email' => 0, 'closed_unreturned' => 0];
            foreach ($new as $id => $contact) {
                $item = $items[$id];
                $pk = null;
                $added = $filled = false;
                if ($contact['Phone'] !== null || $contact['Email'] !== null) {
                    $existing = $prospects[$item->Person_Key] ?? null;
                    if ($existing === null) {
                        $source = [];
                        foreach (['Client', 'Address', 'City', 'State', 'Zip', 'Tier', 'Creation_Date'] as $field) {
                            $source[$field] = $item->{$field};
                        }
                        $pk = $this->table('TblProspects')->insertGetId($source + [
                            'Phone' => $contact['Phone'], 'Email' => $contact['Email'],
                        ], 'PK');
                        $added = true;
                    } else {
                        $pk = $existing->PK;
                        foreach (['Phone', 'Email'] as $field) {
                            if ($contact[$field] !== null) {
                                // Never overwrite a populated contact, suppression flags, or send history.
                                $changed = $this->table('TblProspects')->where('PK', $pk)
                                    ->where(fn (Builder $q) => $q->whereNull($field)->orWhereRaw("LTRIM(RTRIM({$field})) = ''"))
                                    ->update([$field => $contact[$field]]);
                                $filled = $filled || $changed > 0;
                            }
                        }
                    }
                }
                $this->table(self::PEOPLE)->where('PK', $id)->update($contact + [
                    'Status' => 'returned', 'Active_Key' => null, 'Prospect_PK' => $pk,
                    'Added' => $added, 'Filled' => $filled, 'Returned_At' => $now, 'Updated_At' => $now,
                ]);
                $hasPhone = $contact['Phone'] !== null;
                $hasEmail = $contact['Email'] !== null;
                $counts['phone'] += (int) $hasPhone;
                $counts['email'] += (int) $hasEmail;
                $counts['both'] += (int) ($hasPhone && $hasEmail);
                $counts['neither'] += (int) (! $hasPhone && ! $hasEmail);
                $counts['added'] += (int) $added;
                $counts['filled'] += (int) $filled;
                $counts['invalid_phone'] += (int) $contact['Invalid_Phone_Result'];
                $counts['invalid_email'] += (int) $contact['Invalid_Email_Result'];
            }
            if ($complete) {
                $counts['closed_unreturned'] = $this->table(self::PEOPLE)->where('Batch_ID', $batchId)->where('Status', 'pending')
                    ->update(['Status' => 'closed_unreturned', 'Active_Key' => null, 'Updated_At' => $now]);
                if ($batch->Status !== 'completed') {
                    $this->table(self::BATCHES)->where('Batch_ID', $batchId)->update([
                        'Status' => 'completed', 'Completed_At' => $now, 'Updated_At' => $now,
                    ]);
                }
            } elseif ($new !== []) {
                $this->table(self::BATCHES)->where('Batch_ID', $batchId)->update(['Status' => 'returning', 'Updated_At' => $now]);
            }

            return $withSummary ? $this->summary($batchId) : $counts;
        }));
    }

    public function summary(string $batchId): array
    {
        $this->assertBatchId($batchId);
        $row = $this->summaryQuery()->where('b.Batch_ID', $batchId)->first();
        if ($row === null) throw new HttpException(404, 'SMS enrichment batch not found.');
        return $this->summaryArray($row);
    }

    /** Most recent fifty batches; no names, addresses, phone numbers, emails or actor values. */
    public function recent(): array
    {
        return $this->summaryQuery()->orderByDesc('b.Created_At')->orderByDesc('b.Batch_ID')->limit(50)
            ->get()->map(fn (object $row): array => $this->summaryArray($row))->all();
    }

    private function summaryQuery(): Builder
    {
        $chunks = $this->table(self::CHUNKS)->groupBy('Batch_ID')->selectRaw('Batch_ID,
            SUM(Records) AS records, SUM(Rejected) AS rejected, SUM(Existing) AS existing, SUM(Duplicate) AS duplicate');
        $people = $this->table(self::PEOPLE)->groupBy('Batch_ID')->selectRaw("Batch_ID,
            SUM(CASE WHEN Submitted = 1 THEN 1 ELSE 0 END) AS submitted,
            SUM(CASE WHEN Status = 'pending' THEN 1 ELSE 0 END) AS pending,
            SUM(CASE WHEN Status = 'closed_unreturned' THEN 1 ELSE 0 END) AS closed_unreturned,
            SUM(CASE WHEN Returned_At IS NOT NULL THEN 1 ELSE 0 END) AS returned,
            SUM(CASE WHEN Phone IS NOT NULL THEN 1 ELSE 0 END) AS phone,
            SUM(CASE WHEN Email IS NOT NULL THEN 1 ELSE 0 END) AS email,
            SUM(CASE WHEN Phone IS NOT NULL AND Email IS NOT NULL THEN 1 ELSE 0 END) AS both,
            SUM(CASE WHEN Returned_At IS NOT NULL AND Phone IS NULL AND Email IS NULL THEN 1 ELSE 0 END) AS neither,
            SUM(CASE WHEN Added = 1 THEN 1 ELSE 0 END) AS added,
            SUM(CASE WHEN Filled = 1 THEN 1 ELSE 0 END) AS filled,
            SUM(CASE WHEN Invalid_Phone_Result = 1 THEN 1 ELSE 0 END) AS invalid_phone,
            SUM(CASE WHEN Invalid_Email_Result = 1 THEN 1 ELSE 0 END) AS invalid_email");
        return $this->table(self::BATCHES, 'b')->leftJoinSub($chunks, 'c', 'c.Batch_ID', '=', 'b.Batch_ID')
            ->leftJoinSub($people, 'p', 'p.Batch_ID', '=', 'b.Batch_ID')
            ->select('b.Batch_ID', 'b.Status', 'b.Created_At', 'b.Updated_At', 'b.Completed_At')
            ->selectRaw('c.records, c.rejected, c.existing, c.duplicate,
                p.submitted, p.pending, p.closed_unreturned, p.returned, p.phone, p.email,
                p.both, p.neither, p.added, p.filled, p.invalid_phone, p.invalid_email');
    }

    private function summaryArray(object $row): array
    {
        $result = ['batch_id' => $row->Batch_ID, 'status' => $row->Status, 'created_at' => $row->Created_At,
            'updated_at' => $row->Updated_At, 'completed_at' => $row->Completed_At];
        foreach (['records', 'rejected', 'existing', 'duplicate', 'submitted', 'pending', 'closed_unreturned',
            'returned', 'phone', 'email', 'both', 'neither', 'added', 'filled', 'invalid_phone', 'invalid_email'] as $field) {
            $result[$field] = (int) ($row->{$field} ?? 0);
        }
        // Older workers froze empty batches as awaiting_return. Report the terminal
        // outcome without GET writes or inventing a historical completion timestamp.
        if ($result['status'] === 'awaiting_return' && $result['submitted'] === 0) {
            $result['status'] = 'completed';
        }
        return $result;
    }

    private function stagedChunkArray(string $batchId, object $chunk): array
    {
        return ['batch_id' => $batchId, 'chunk' => (int) $chunk->Chunk, 'records' => (int) $chunk->Records,
            'rejected' => (int) $chunk->Rejected, 'existing' => (int) $chunk->Existing, 'duplicate' => (int) $chunk->Duplicate,
            'submitted' => (int) $chunk->Records - (int) $chunk->Rejected - (int) $chunk->Existing - (int) $chunk->Duplicate];
    }

    private function normalizeRecord(mixed $record): ?array
    {
        if (! is_array($record)) return null;
        $values = [];
        foreach (['Client' => 150, 'Address' => 200, 'City' => 100, 'State' => 2, 'Drop_Name' => 255, 'External_ID' => 255] as $field => $max) {
            $value = $record[$field] ?? '';
            if (! is_string($value) && ! is_int($value)) return null;
            $value = trim((string) $value);
            if (! mb_check_encoding($value, 'UTF-8') || mb_strlen($value) > $max || preg_match('/[\x00-\x1F\x7F]/', $value)) return null;
            $values[$field] = $value === '' ? null : $value;
        }
        if ($values['Client'] === null || $values['Address'] === null) return null;
        $zip = $record['Zip'] ?? '';
        if ((! is_string($zip) && ! is_int($zip)) || ! preg_match('/^[0-9]{5}(?:-?[0-9]{4})?$/D', trim((string) $zip))) return null;
        $values['Zip'] = substr(trim((string) $zip), 0, 5);
        if ($values['State'] !== null && ! preg_match('/^[A-Za-z]{2}$/D', $values['State'])) return null;
        $values['State'] = $values['State'] === null ? null : strtoupper($values['State']);
        $tier = $record['Tier'] ?? null;
        if ($tier !== null && ! is_string($tier) && ! is_int($tier)) return null;
        $values['Tier'] = SmsProspectValues::tier($tier);
        $date = $record['Creation_Date'] ?? null;
        if ($date === null || $date === '') $date = CarbonImmutable::now('America/Los_Angeles')->toDateString();
        if (! is_string($date) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $m)
            || (int) $m[1] < 1 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) return null;
        $values['Creation_Date'] = $date;
        $pk = $record['Mailer_PK'] ?? null;
        if ($pk !== null && $pk !== '' && ((! is_int($pk) && ! is_string($pk))
            || ! preg_match('/^[1-9][0-9]*$/D', (string) $pk) || (string) (int) $pk !== (string) $pk)) return null;
        $values['Mailer_PK'] = $pk === null || $pk === '' ? null : (int) $pk;
        $values['Person_Key'] = $this->payloadHash([mb_strtolower($values['Client'], 'UTF-8'), mb_strtolower($values['Address'], 'UTF-8'), $values['Zip']]);
        return $values;
    }

    private function normalizeContact(array $result): array
    {
        $rawPhone = $result['Phone'] ?? $result['Phone Append 1'] ?? '';
        $rawEmail = $result['Email'] ?? $result['Email Append 1'] ?? '';
        $phone = (is_string($rawPhone) || is_int($rawPhone)) ? SmsProspectValues::phone($rawPhone) : null;
        $email = is_string($rawEmail) ? trim($rawEmail) : '';
        $email = strlen($email) <= 254 && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
        $contact = ['Phone' => $phone, 'Email' => $email,
            'Invalid_Phone_Result' => $phone === null && $rawPhone !== null && $rawPhone !== '',
            'Invalid_Email_Result' => $email === null && $rawEmail !== null && $rawEmail !== ''];
        return $contact + ['Return_Hash' => $this->payloadHash($contact)];
    }

    /** Existing prospects are already normalized by the loader. Keep every indexed p column bare. */
    private function existingPeople(array $people): array
    {
        $matches = [];
        foreach (array_chunk($people, 200, true) as $chunk) {
            [$source, $bindings] = $this->identitySource($chunk);
            $predicate = $this->identityPredicate();
            foreach ($this->connection()->select("SELECT v.Person_Key, p.PK, p.Phone, p.Email FROM TblProspects p
                INNER JOIN ({$source}) v ON {$predicate}
                ORDER BY p.PK", $bindings) as $row) {
                $matches[$row->Person_Key] ??= $row;
            }
        }
        return $matches;
    }

    /** Logical matching follows database CI equality, including equivalents with different hashes. */
    private function pendingPeople(array $people, string $batchId): array
    {
        if ($people === []) return [];
        [$source, $bindings] = $this->identitySource($people);
        $predicate = $this->identityPredicate(true);
        $matches = [];
        foreach ($this->connection()->select("SELECT v.Person_Key,
            CASE WHEN p.Batch_ID = ? THEN 1 ELSE 0 END AS Same_Batch
            FROM ".self::PEOPLE." p INNER JOIN ({$source}) v ON {$predicate}
            WHERE p.Batch_ID = ? OR p.Active_Key IS NOT NULL", [$batchId, ...$bindings, $batchId]) as $row) {
            if (! isset($matches[$row->Person_Key]) || (int) $row->Same_Batch === 1) $matches[$row->Person_Key] = $row;
        }
        return $matches;
    }

    /** Retain the first incoming record for each identity under the database's collation. */
    private function equivalentIncomingKeys(array $people): array
    {
        if (count($people) < 2) return [];
        [$source, $bindings] = $this->identitySource($people);
        $partition = $this->connection()->getDriverName() === 'sqlsrv'
            ? 'v.Client, v.Address, v.Zip'
            : 'v.Client COLLATE NOCASE, v.Address COLLATE NOCASE, v.Zip';
        return array_map(static fn (object $row): string => $row->Person_Key,
            $this->connection()->select("SELECT Person_Key FROM (
                SELECT v.Person_Key, ROW_NUMBER() OVER (PARTITION BY {$partition} ORDER BY v.Ordinal) AS Identity_Rank
                FROM ({$source}) v) ranked WHERE Identity_Rank > 1", $bindings));
    }

    /** At most 200 rows/four bindings each; the ordinal comes only from our own array order. */
    private function identitySource(array $people): array
    {
        $selects = $bindings = [];
        foreach (array_values($people) as $ordinal => $person) {
            $selects[] = "SELECT CAST(? AS varchar(64)) AS Person_Key, CAST(? AS varchar(150)) AS Client,
                CAST(? AS varchar(200)) AS Address, CAST(? AS varchar(10)) AS Zip, {$ordinal} AS Ordinal";
            array_push($bindings, $person['Person_Key'], $person['Client'], $person['Address'], $person['Zip']);
        }
        return [implode(' UNION ALL ', $selects), $bindings];
    }

    private function identityPredicate(bool $pending = false): string
    {
        if ($this->connection()->getDriverName() !== 'sqlsrv') {
            return 'p.Client COLLATE NOCASE = v.Client AND p.Address COLLATE NOCASE = v.Address AND p.Zip = v.Zip';
        }
        // Pending columns are NVARCHAR; prospect columns are Jacob's VARCHAR columns.
        // Match the value type, not the indexed column, and inherit the configured CI collation.
        $type = $pending ? 'nvarchar' : 'varchar';
        return "p.Client = CAST(v.Client AS {$type}(150)) AND p.Address = CAST(v.Address AS {$type}(200))
            AND p.Zip = CAST(v.Zip AS {$type}(10))";
    }

    private function unrepresentablePeople(array $people): array
    {
        if ($this->connection()->getDriverName() !== 'sqlsrv') return [];
        $invalid = [];
        foreach (array_chunk($people, 200, true) as $chunk) {
            $selects = $bindings = [];
            foreach ($chunk as $key => $person) {
                $selects[] = 'SELECT ? AS Person_Key, ? AS Client, ? AS Address, ? AS City';
                array_push($bindings, $key, $person['Client'], $person['Address'], $person['City'] ?? '');
            }
            $conditions = [];
            foreach (['Client' => 150, 'Address' => 200, 'City' => 100] as $field => $max) {
                $conditions[] = "DATALENGTH(CONVERT(varchar(max), v.{$field})) > {$max}
                    OR CONVERT(nvarchar(max), CONVERT(varchar(max), v.{$field})) COLLATE Latin1_General_100_BIN2 <> v.{$field} COLLATE Latin1_General_100_BIN2";
            }
            foreach ($this->connection()->select('SELECT v.Person_Key FROM ('.implode(' UNION ALL ', $selects).') v WHERE '.implode(' OR ', $conditions), $bindings) as $row) {
                $invalid[] = $row->Person_Key;
            }
        }
        return $invalid;
    }

    private function insertBounded(string $table, array $rows): void
    {
        if ($rows === []) return;
        foreach (array_chunk($rows, max(1, intdiv(2000, count($rows[0])))) as $chunk) {
            $this->table($table)->insert($chunk);
        }
    }

    private function requireBatch(string $batchId): object
    {
        $row = $this->table(self::BATCHES)->where('Batch_ID', $batchId)->first();
        if ($row === null) throw new HttpException(404, 'SMS enrichment batch not found.');
        return $row;
    }

    private function assertBatchId(string $batchId): void
    {
        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,99}$/D', $batchId)) {
            throw new HttpException(422, 'A batch ID of 1 to 100 safe identifier characters is required.');
        }
    }

    private function assertBoundedList(array $records): void
    {
        if (! array_is_list($records) || count($records) > self::MAX_RECORDS) {
            throw new HttpException(422, 'Supply a list of at most '.self::MAX_RECORDS.' records per request.');
        }
    }

    private function payloadHash(array $payload): string
    {
        $sort = static function (mixed $value) use (&$sort): mixed {
            if (! is_array($value)) return $value;
            if (! array_is_list($value)) ksort($value, SORT_STRING);
            return array_map($sort, $value);
        };
        try {
            return hash('sha256', json_encode($sort($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        } catch (\JsonException) {
            throw new HttpException(422, 'The payload must contain valid JSON data.');
        }
    }

    private function now(): string
    {
        return CarbonImmutable::now('UTC')->toDateTimeString();
    }
}
