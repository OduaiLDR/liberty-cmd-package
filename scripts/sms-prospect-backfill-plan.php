<?php

declare(strict_types=1);

require_once __DIR__.'/sms-prospect-coverage.php';

/** Read-only, PII-free references to evidence from a completed, immutable coverage journal. */
final class SmsProspectBackfillPlan
{
    // Exact three-file fingerprint of the completed October 20 audit. Never a wildcard.
    public const LEGACY_CONFLICT_TOOL_SHA256 = '17c9c3a5c5b35fad653378f9b1a73e4781ef9badc0295c8a267789d12ca67b2e';
    public const HOLD_REPEATS_POLICY = 'single-source-hold-all-repeated-v1';
    private const ELIGIBLE = 'existing_count=0 AND contact_conflict=0 AND source_rows=1 AND evidence IS NOT NULL';
    private const MAX_EVIDENCE_BYTES = 1048576;

    /** Reconcile the whole identity journal once, before consuming bounded candidate pages. */
    public static function proof(PDO $journal, array $manifest, bool $holdAllRepeated = false): array
    {
        if ($holdAllRepeated && ($manifest['tool_sha256'] ?? null) !== self::LEGACY_CONFLICT_TOOL_SHA256) {
            throw new RuntimeException('Only the exact reviewed legacy audit can use the all-repeats hold policy.');
        }
        $state = self::completedState($journal, $manifest);
        $summary = $journal->query('SELECT COUNT(*) AS identity_total,
            COALESCE(SUM(existing_count>0),0) AS existing_total,
            COALESCE(SUM(existing_count=0),0) AS missing_total,
            COALESCE(SUM(contact_conflict),0) AS conflicts,
            COALESCE(SUM(CASE WHEN existing_count=0 THEN contact_conflict ELSE 0 END),0) AS missing_conflicts,
            COALESCE(SUM(source_rows),0) AS source_total,
            COALESCE(SUM(CASE WHEN existing_count>0 THEN source_rows ELSE 0 END),0) AS existing_source_total,
            COALESCE(SUM(CASE WHEN existing_count=0 THEN source_rows ELSE 0 END),0) AS missing_source_total,
            COALESCE(SUM(CASE WHEN '.self::ELIGIBLE.' THEN 1 ELSE 0 END),0) AS candidate_count,
            COALESCE(MAX(CASE WHEN '.self::ELIGIBLE.' THEN id ELSE 0 END),0) AS candidate_max_id,
            COALESCE(SUM(existing_count=0 AND contact_conflict=0 AND source_rows>1),0) AS repeated_total,
            COALESCE(SUM(existing_count=0 AND source_rows>1),0) AS all_missing_repeated,
            COALESCE(SUM(CASE WHEN typeof(id)!=\'integer\' OR id<1
                OR typeof(bucket)!=\'integer\' OR bucket NOT BETWEEN -2147483648 AND 2147483647
                OR typeof(source_rows)!=\'integer\' OR source_rows<1
                OR typeof(existing_count)!=\'integer\' OR existing_count NOT BETWEEN 0 AND 2
                OR typeof(contact_conflict)!=\'integer\' OR contact_conflict NOT IN (0,1)
                OR (contact_conflict=1 AND source_rows<2)
                OR typeof(client)!=\'text\' OR typeof(address)!=\'text\' OR typeof(zip)!=\'text\'
                OR typeof(contact_key)!=\'text\'
                OR (evidence IS NOT NULL AND typeof(evidence)!=\'blob\')
                OR (existing_count=0 AND contact_conflict=0 AND source_rows=1 AND evidence IS NULL)
                THEN 1 ELSE 0 END),0) AS invalid_rows,
            COALESCE(MAX(id),0) AS max_id FROM identities')->fetch(PDO::FETCH_ASSOC);
        foreach ($summary as $value) {
            if (! is_int($value) || $value < 0) throw new RuntimeException('Invalid coverage identity summary.');
        }
        $counts = $state['counts'];
        foreach (['identity_total' => 'unique_contact_people', 'existing_total' => 'unique_existing_people',
            'missing_total' => 'unique_missing_people', 'conflicts' => 'unique_contact_conflicts',
            'missing_conflicts' => 'missing_contact_conflicts', 'source_total' => 'valid_contact_rows',
            'existing_source_total' => 'existing_contact_rows', 'missing_source_total' => 'missing_contact_rows'] as $actual => $expected) {
            if ($holdAllRepeated && in_array($actual, ['conflicts', 'missing_conflicts'], true)) continue;
            if ($summary[$actual] !== $counts[$expected]) throw new RuntimeException('Coverage identity summary does not match stored counters.');
        }
        if ($summary['invalid_rows'] !== 0 || $summary['max_id'] > $state['after_pk']
            || $summary['candidate_count'] + $summary['missing_conflicts'] + $summary['repeated_total'] !== $counts['unique_missing_people']) {
            throw new RuntimeException('Invalid or incomplete coverage candidate summary.');
        }

        // The immutable coverage core's manifestHash() is private; use its exact serialization.
        $common = [
            'coverage_manifest_sha256' => hash('sha256', SmsProspectReconciliation::json($manifest)),
            'coverage_state_sha256' => hash('sha256', SmsProspectReconciliation::json($state)),
            'source_guard' => $manifest['source_guard'],
            'source_watermark_pk' => $manifest['source_watermark_pk'],
            'prospect_watermark_pk' => $manifest['prospect_watermark_pk'],
            'candidate_count' => $summary['candidate_count'], 'candidate_max_id' => $summary['candidate_max_id']];
        if ($holdAllRepeated) {
            $proof = ['format' => 2] + $common + [
                'eligibility_policy' => self::HOLD_REPEATS_POLICY,
                'legacy_tool_sha256' => $manifest['tool_sha256'],
                'conflict_counts_verified' => false,
                'audited_source_rows' => $state['source_rows'],
                'missing_people' => $counts['unique_missing_people'],
                'excluded_repeated_people' => $summary['all_missing_repeated'],
                'conflict_counter_evidence' => [
                    'recorded_total' => $counts['unique_contact_conflicts'],
                    'recorded_missing' => $counts['missing_contact_conflicts'],
                    'stored_total' => $summary['conflicts'], 'stored_missing' => $summary['missing_conflicts'],
                    'repeated_observations' => $summary['source_total'] - $summary['identity_total'],
                    'missing_repeated_observations' => $summary['missing_source_total'] - $summary['missing_total']]];
            self::validateHeldProof($proof);
            return $proof;
        }
        return ['format' => 1] + $common + [
            'excluded_conflicts' => $counts['missing_contact_conflicts'],
            'excluded_repeated_nonconflicting' => $summary['repeated_total']];
    }

    /** Pure shared reader/writer validation. No counter is repaired or relabelled as verified. */
    public static function validateHeldProof(array $proof): void
    {
        if (($proof['format'] ?? null) !== 2 || ($proof['eligibility_policy'] ?? null) !== self::HOLD_REPEATS_POLICY
            || ($proof['legacy_tool_sha256'] ?? null) !== self::LEGACY_CONFLICT_TOOL_SHA256
            || ($proof['conflict_counts_verified'] ?? null) !== false
            || array_key_exists('excluded_conflicts', $proof) || array_key_exists('excluded_repeated_nonconflicting', $proof)) {
            throw new RuntimeException('Invalid explicit all-repeats hold proof.');
        }
        foreach (['audited_source_rows','missing_people','candidate_count','excluded_repeated_people'] as $key) {
            if (! is_int($proof[$key] ?? null) || $proof[$key] < 0 || $proof[$key] > $proof['audited_source_rows']) {
                throw new RuntimeException('Invalid all-repeats hold bounds.');
            }
        }
        if ($proof['candidate_count'] !== $proof['missing_people'] - $proof['excluded_repeated_people']) {
            throw new RuntimeException('All-repeats hold scope does not reconcile.');
        }
        $e = $proof['conflict_counter_evidence'] ?? null;
        $keys = ['recorded_total','recorded_missing','stored_total','stored_missing','repeated_observations','missing_repeated_observations'];
        if (! is_array($e) || array_keys($e) !== $keys) throw new RuntimeException('Invalid conflict-counter evidence.');
        foreach ($e as $value) {
            if (! is_int($value) || $value < 0 || $value > $proof['audited_source_rows']) throw new RuntimeException('Invalid conflict-counter bounds.');
        }
        // The legacy text-bound MAX can only lose flags or recount repeated observations.
        // It cannot explain any other counter/shape mismatch. Every repeat stays excluded.
        if ($e['recorded_total'] < $e['recorded_missing'] || $e['stored_total'] < $e['stored_missing']
            || $e['recorded_missing'] < $e['stored_missing']
            || $e['recorded_total'] - $e['recorded_missing'] < $e['stored_total'] - $e['stored_missing']
            || $e['repeated_observations'] < $e['missing_repeated_observations']
            || 2 * $e['recorded_missing'] - $e['stored_missing'] > $e['missing_repeated_observations']
            || 2 * ($e['recorded_total'] - $e['recorded_missing']) - ($e['stored_total'] - $e['stored_missing'])
                > $e['repeated_observations'] - $e['missing_repeated_observations']
            || $e['stored_missing'] > $proof['excluded_repeated_people']
            || $proof['excluded_repeated_people'] > $e['missing_repeated_observations']) {
            throw new RuntimeException('The mismatch is outside the reviewed legacy conflict-counter failure.');
        }
    }

    /** No whole-journal aggregation here. Call proof() once for the completed immutable input. */
    public static function candidates(PDO $journal, array $manifest, int $afterId, int $size = 250): array
    {
        if ($afterId < 0 || $afterId > 2147483647 || $size < 1 || $size > 1000) throw new RuntimeException('Invalid bounded candidate range.');
        $state = self::completedState($journal, $manifest);
        $query = $journal->prepare('SELECT id,bucket,client,address,zip,source_rows,existing_count,contact_conflict,contact_key,evidence,
            (typeof(client)=\'text\' AND typeof(address)=\'text\' AND typeof(zip)=\'text\'
                AND typeof(contact_key)=\'text\' AND typeof(evidence)=\'blob\') AS evidence_types_valid
            FROM identities WHERE id>? AND '.self::ELIGIBLE.' ORDER BY id LIMIT ?');
        $query->bindValue(1, $afterId, PDO::PARAM_INT);
        $query->bindValue(2, $size, PDO::PARAM_INT);
        $query->execute();
        $entries = [];
        try {
            while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
                $entries[] = self::candidate($row, $afterId, $state['after_pk']);
                $afterId = $row['id'];
            }
        } finally { $query->closeCursor(); }
        return $entries;
    }

    private static function completedState(PDO $journal, array $manifest): array
    {
        if (! is_int($manifest['source_rows'] ?? null) || $manifest['source_rows'] < 0
            || ! is_string($manifest['source_guard'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $manifest['source_guard'])) {
            throw new RuntimeException('Invalid coverage source count or guard.');
        }
        // Existing core validates both watermarks, the bound manifest and the last checkpoint.
        $state = SmsProspectCoverage::state($journal, $manifest);
        if ($state['exhausted'] !== true || $state['source_rows'] !== $manifest['source_rows']) {
            throw new RuntimeException('A completed coverage audit matching the manifest source count is required.');
        }
        $counts = $state['counts'];
        if ($counts['unique_existing_people'] > $counts['existing_contact_rows']
            || $counts['unique_missing_people'] > $counts['missing_contact_rows']
            || $counts['unique_contact_conflicts'] - $counts['missing_contact_conflicts'] > $counts['unique_existing_people']
            || $counts['invalid_phone_rows'] > $state['source_rows'] || $counts['invalid_email_rows'] > $state['source_rows']) {
            throw new RuntimeException('Incomplete coverage counters.');
        }
        return $state;
    }

    private static function candidate(array $row, int $afterId, int $lastPk): array
    {
        if ($row['evidence_types_valid'] !== 1 || ! is_int($row['id']) || $row['id'] <= $afterId || $row['id'] > $lastPk
            || ! is_int($row['bucket']) || $row['bucket'] < -2147483648 || $row['bucket'] > 2147483647
            || $row['source_rows'] !== 1 || $row['existing_count'] !== 0 || $row['contact_conflict'] !== 0
            || ! is_string($row['contact_key']) || ! preg_match('/^[a-f0-9]{64}$/D', $row['contact_key'])
            || ! is_string($row['evidence']) || strlen($row['evidence']) > self::MAX_EVIDENCE_BYTES) {
            throw new RuntimeException('Invalid coverage candidate identity or evidence.');
        }
        $decoded = @gzdecode($row['evidence'], self::MAX_EVIDENCE_BYTES + 1);
        if ($decoded === false || strlen($decoded) > self::MAX_EVIDENCE_BYTES) throw new RuntimeException('Invalid or oversized compressed coverage evidence.');
        // gzdecode alone accepts some truncated trailers and silently ignores trailing members.
        // Its output bound above also bounds this second, complete-stream validation.
        $gzip = inflate_init(ZLIB_ENCODING_GZIP);
        if ($gzip === false || @inflate_add($gzip, $row['evidence'], ZLIB_FINISH) !== $decoded
            || inflate_get_status($gzip) !== ZLIB_STREAM_END || inflate_get_read_len($gzip) !== strlen($row['evidence'])) {
            throw new RuntimeException('Incomplete or trailing compressed coverage evidence.');
        }
        try { $evidence = json_decode($decoded, true, 512, JSON_THROW_ON_ERROR); }
        catch (JsonException) { throw new RuntimeException('Invalid coverage evidence JSON.'); }
        if (! is_array($evidence) || self::evidenceInteger($evidence['PK'] ?? null, 1, 2147483647) !== $row['id']
            || self::evidenceInteger($evidence['Bucket'] ?? null, -2147483648, 2147483647) !== $row['bucket']
            || self::evidenceInteger($evidence['Identity_Valid'] ?? null, 1, 1) !== 1
            || self::evidenceInteger($evidence['Existing_Count'] ?? null, 0, 0) !== 0
            || ! is_string($evidence['observed_row_sha256'] ?? null)
            || ! preg_match('/^[a-fA-F0-9]{64}$/D', $evidence['observed_row_sha256'])) {
            throw new RuntimeException('Coverage evidence identity or fingerprint mismatch.');
        }
        foreach (['Client' => 'client', 'Address' => 'address', 'Zip' => 'zip'] as $field => $column) {
            if (! is_string($evidence[$field] ?? null) || $evidence[$field] !== $row[$column]) {
                throw new RuntimeException('Coverage evidence identity bytes mismatch.');
            }
        }
        foreach (['Phone', 'Email'] as $field) {
            if (! array_key_exists($field, $evidence) || ($evidence[$field] !== null && ! is_string($evidence[$field]))) {
                throw new RuntimeException('Invalid coverage evidence contact type.');
            }
        }
        $contact = SmsProspectCoverage::normalize($evidence);
        if (! $contact['identity_valid'] || ($contact['Phone'] === null && $contact['Email'] === null)
            || $contact['Phone'] !== $evidence['Phone'] || $contact['Email'] !== $evidence['Email']) {
            throw new RuntimeException('Coverage evidence is not a normalized contact-bearing identity.');
        }
        $key = hash('sha256', SmsProspectReconciliation::json([$contact['Phone'], $contact['Email'] === null ? null : strtolower($contact['Email'])]));
        if (! hash_equals($row['contact_key'], $key)) throw new RuntimeException('Coverage evidence contact key mismatch.');
        return ['Source_PK' => $row['id'], 'Observed_SHA256' => $evidence['observed_row_sha256'], 'Contact_Key' => $key];
    }

    /** SQL PDO may preserve these INT values as decimal strings in the ORIGINAL evidence. */
    private static function evidenceInteger(mixed $value, int $minimum, int $maximum): int
    {
        if (is_string($value)) {
            if (! preg_match('/^(?:0|-?[1-9][0-9]{0,9})$/D', $value)) throw new RuntimeException('Invalid coverage evidence numeric field.');
            $number = (int) $value;
            if ((string) $number !== $value) throw new RuntimeException('Invalid coverage evidence numeric field.');
        } elseif (is_int($value)) {
            $number = $value;
        } else {
            throw new RuntimeException('Invalid coverage evidence numeric field.');
        }
        if ($number < $minimum || $number > $maximum) throw new RuntimeException('Invalid coverage evidence numeric field.');
        return $number;
    }
}
