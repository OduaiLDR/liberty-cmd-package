<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SmsProspectBackfillPlan;
use SmsProspectCoverage;
use SmsProspectReconciliation;
use TypeError;

require_once dirname(__DIR__, 2).'/scripts/sms-prospect-backfill-plan.php';

final class SmsProspectBackfillPlanTest extends TestCase
{
    /** Synthetic canonical identities; repeated observations have no metadata agreement proof. */
    private function fixture(array $manifestChanges = []): array
    {
        $specs = [2 => [1, 0, 0, '2025550101', null], 5 => [1, 0, 0, null, 'MixedCase@example.test'],
            9 => [1, 0, 0, '2025550102', 'both@example.test'], 11 => [2, 0, 1, '2025550103', 'conflict@example.test'],
            16 => [2, 0, 0, '2025550104', 'repeated@example.test'], 20 => [1, 1, 0, '2025550105', 'existing@example.test'],
            23 => [2, 2, 1, '2025550106', 'existing-conflict@example.test']];
        $manifest = $manifestChanges + ['format' => 1, 'read_only' => true, 'apply_supported' => false,
            'batch_size' => 250, 'source_watermark_pk' => 50, 'prospect_watermark_pk' => 20,
            'source_guard' => str_repeat('a', 64), 'source_rows' => 12];
        $journal = new SmsProspectPlanTestPdo();
        SmsProspectCoverage::initialize($journal, $manifest);
        $state = SmsProspectCoverage::state($journal, $manifest);
        $state['batches'] = 1; $state['after_pk'] = 50; $state['source_rows'] = 12; $state['exhausted'] = true;
        $state['counts']['invalid_identity_rows'] = 1; $state['counts']['neither_rows'] = 1;
        $state['counts']['invalid_phone_rows'] = 1; $state['counts']['invalid_email_rows'] = 1;
        $evidence = [];
        foreach ($specs as $id => [$sourceRows, $existing, $conflict, $phone, $email]) {
            $row = ['PK' => $id, 'Bucket' => $id === 2 ? -2147483648 : 100 + $id,
                'Identity_Valid' => 1, 'Existing_Count' => $existing,
                'Client' => 'Person '.$id, 'Address' => $id.' Test St', 'Zip' => '00123', 'Phone' => $phone, 'Email' => $email,
                'Drop_Name' => 'Canonical drop is not a recency policy', 'Latest_Export_Date' => '2020-01-01'];
            $row['observed_row_sha256'] = hash('sha256', SmsProspectReconciliation::json($row));
            $evidence[$id] = $row;
            $key = hash('sha256', SmsProspectReconciliation::json([$phone, $email === null ? null : strtolower($email)]));
            $insert = $journal->prepare('INSERT INTO identities
                (id,bucket,client,address,zip,source_rows,existing_count,contact_conflict,contact_key,evidence)
                VALUES(?,?,?,?,?,?,?,?,?,?)');
            foreach ([$id, $row['Bucket'], $row['Client'], $row['Address'], $row['Zip'], $sourceRows, $existing, $conflict, $key] as $i => $value) {
                $insert->bindValue($i + 1, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
            }
            $insert->bindValue(10, $existing > 0 ? null : gzencode(SmsProspectReconciliation::json($row), 1), $existing > 0 ? PDO::PARAM_NULL : PDO::PARAM_LOB);
            $insert->execute();
            $counts = &$state['counts'];
            $counts[$phone === null ? 'email_only_rows' : ($email === null ? 'phone_only_rows' : 'both_rows')] += $sourceRows;
            $counts['valid_contact_rows'] += $sourceRows;
            $counts[$existing > 0 ? 'existing_contact_rows' : 'missing_contact_rows'] += $sourceRows;
            $counts['unique_contact_people']++;
            $counts[$existing > 0 ? 'unique_existing_people' : 'unique_missing_people']++;
            $counts['unique_contact_conflicts'] += $conflict;
            if ($existing === 0) $counts['missing_contact_conflicts'] += $conflict;
            unset($counts);
        }
        $this->checkpoint($journal, $state);
        self::assertSame($state, SmsProspectCoverage::state($journal, $manifest));
        $journal->reads = [];
        return [$journal, $manifest, $state, $evidence];
    }

    private function checkpoint(PDO $journal, array $state): void
    {
        $payload = SmsProspectReconciliation::json($state);
        $journal->prepare('UPDATE state SET payload=? WHERE id=1')->execute([$payload]);
        $journal->prepare('INSERT OR REPLACE INTO batches VALUES(?,?,?,?,?,?,?,?)')->execute([
            $state['batches'], 0, $state['after_pk'], $state['source_rows'], hash('sha256', 'synthetic source observations'),
            '2026-10-20T00:00:00+00:00', hash('sha256', $payload), $payload]);
    }

    private function replaceEvidence(PDO $journal, mixed $evidence, int $id = 2): void
    {
        $query = $journal->prepare('UPDATE identities SET evidence=? WHERE id=?');
        $query->bindValue(1, is_array($evidence) ? gzencode(SmsProspectReconciliation::json($evidence), 1) : $evidence, PDO::PARAM_LOB);
        $query->bindValue(2, $id, PDO::PARAM_INT);
        $query->execute();
    }

    public function test_proof_and_keyset_pages_are_read_only_and_exclude_repeated_identities(): void
    {
        [$journal, $manifest, $state, $evidence] = $this->fixture();
        $before = $journal->query('SELECT total_changes()')->fetchColumn();
        $journal->exec('PRAGMA query_only=ON');
        $proof = SmsProspectBackfillPlan::proof($journal, $manifest);
        self::assertSame(['format' => 1,
            'coverage_manifest_sha256' => hash('sha256', SmsProspectReconciliation::json($manifest)),
            'coverage_state_sha256' => hash('sha256', SmsProspectReconciliation::json($state)),
            'source_guard' => $manifest['source_guard'], 'source_watermark_pk' => 50, 'prospect_watermark_pk' => 20,
            'candidate_count' => 3, 'candidate_max_id' => 9, 'excluded_conflicts' => 1, 'excluded_repeated_nonconflicting' => 1], $proof);
        self::assertSame($state['counts']['unique_missing_people'], $proof['candidate_count'] + $proof['excluded_conflicts'] + $proof['excluded_repeated_nonconflicting']);
        $entries = SmsProspectBackfillPlan::candidates($journal, $manifest, 0);
        self::assertSame([2, 5, 9], array_column($entries, 'Source_PK'));
        foreach ($entries as $entry) {
            self::assertSame(['Source_PK', 'Observed_SHA256', 'Contact_Key'], array_keys($entry));
            self::assertIsInt($entry['Source_PK']);
            self::assertSame($evidence[$entry['Source_PK']]['observed_row_sha256'], $entry['Observed_SHA256']);
        }
        self::assertSame(hash('sha256', SmsProspectReconciliation::json([null, 'mixedcase@example.test'])), $entries[1]['Contact_Key']);
        self::assertSame(array_slice($entries, 0, 2), SmsProspectBackfillPlan::candidates($journal, $manifest, 0, 2));
        self::assertSame([$entries[2]], SmsProspectBackfillPlan::candidates($journal, $manifest, 5, 1));
        self::assertSame([$entries[1]], SmsProspectBackfillPlan::candidates($journal, $manifest, 3, 1));
        self::assertSame([], SmsProspectBackfillPlan::candidates($journal, $manifest, 9));
        self::assertSame([], SmsProspectBackfillPlan::candidates($journal, $manifest, 2147483647, 1000));
        self::assertSame($before, $journal->query('SELECT total_changes()')->fetchColumn());
        self::assertSame($state, SmsProspectCoverage::state($journal, $manifest));
    }

    public function test_pages_recheck_completion_without_aggregating_identities_or_reading_excluded_evidence(): void
    {
        [$journal, $manifest, $state] = $this->fixture();
        $journal->exec("UPDATE identities SET evidence=X'00' WHERE id IN (11,16,20,23)");
        $journal->reads = [];
        self::assertCount(3, SmsProspectBackfillPlan::candidates($journal, $manifest, 0));
        $identityQueries = array_values(array_filter($journal->reads, fn ($sql) => str_contains($sql, 'FROM identities')));
        self::assertCount(1, $identityQueries);
        self::assertStringContainsString('source_rows=1', $identityQueries[0]);
        self::assertStringContainsString('ORDER BY id LIMIT ?', $identityQueries[0]);
        self::assertStringNotContainsString('SUM(', $identityQueries[0]);
        self::assertStringNotContainsString('COUNT(', $identityQueries[0]);
        $state['exhausted'] = false;
        $this->checkpoint($journal, $state);
        $this->expectException(RuntimeException::class);
        SmsProspectBackfillPlan::candidates($journal, $manifest, 0);
    }

    public function test_empty_completed_audit_has_a_zero_maximum_and_no_candidates(): void
    {
        [, $manifest] = $this->fixture();
        $manifest['source_rows'] = 0; $manifest['source_watermark_pk'] = 0;
        $journal = new PDO('sqlite::memory:');
        SmsProspectCoverage::initialize($journal, $manifest);
        $state = SmsProspectCoverage::state($journal, $manifest);
        $state['batches'] = 1; $state['exhausted'] = true;
        $this->checkpoint($journal, $state);
        $proof = SmsProspectBackfillPlan::proof($journal, $manifest);
        self::assertSame(0, $proof['candidate_count']);
        self::assertSame(0, $proof['candidate_max_id']);
        self::assertSame(0, $proof['excluded_conflicts']);
        self::assertSame(0, $proof['excluded_repeated_nonconflicting']);
        self::assertSame([], SmsProspectBackfillPlan::candidates($journal, $manifest, 0));
    }

    public function test_known_legacy_counter_drift_requires_explicit_mode_and_holds_every_repeat(): void
    {
        [$journal, $manifest] = $this->fixture(['tool_sha256' => SmsProspectBackfillPlan::LEGACY_CONFLICT_TOOL_SHA256]);
        // Legacy A/B/A: counter remembers the event while the text-bound MAX loses its flag.
        $journal->exec('UPDATE identities SET contact_conflict=0 WHERE id=11');
        $before = $journal->query('SELECT total_changes()')->fetchColumn();
        $journal->exec('PRAGMA query_only=ON');
        try {
            SmsProspectBackfillPlan::proof($journal, $manifest);
            self::fail('The strict reader must still reject inconsistent conflict counters.');
        } catch (RuntimeException $error) {
            self::assertSame('Coverage identity summary does not match stored counters.', $error->getMessage());
        }
        $proof = SmsProspectBackfillPlan::proof($journal, $manifest, true);
        self::assertSame(2, $proof['format']);
        self::assertSame(3, $proof['candidate_count']);
        self::assertSame(5, $proof['missing_people']);
        self::assertSame(2, $proof['excluded_repeated_people']);
        self::assertFalse($proof['conflict_counts_verified']);
        self::assertArrayNotHasKey('excluded_conflicts', $proof);
        self::assertArrayNotHasKey('excluded_repeated_nonconflicting', $proof);
        self::assertSame(['recorded_total' => 2,'recorded_missing' => 1,'stored_total' => 1,'stored_missing' => 0,
            'repeated_observations' => 3,'missing_repeated_observations' => 2], $proof['conflict_counter_evidence']);
        self::assertSame([2,5,9], array_column(SmsProspectBackfillPlan::candidates($journal, $manifest, 0), 'Source_PK'));
        self::assertSame($before, $journal->query('SELECT total_changes()')->fetchColumn());
    }

    public function test_hold_mode_accepts_only_the_exact_legacy_provenance(): void
    {
        foreach ([null, str_repeat('a',64), strtoupper(SmsProspectBackfillPlan::LEGACY_CONFLICT_TOOL_SHA256)] as $hash) {
            [$journal, $manifest] = $this->fixture(['tool_sha256' => $hash]);
            try { SmsProspectBackfillPlan::proof($journal, $manifest, true); self::fail('Unreviewed tool accepted.'); }
            catch (RuntimeException $error) { self::assertStringContainsString('exact reviewed legacy audit', $error->getMessage()); }
        }
    }

    #[DataProvider('corruptSummaries')]
    public function test_explicit_legacy_mode_still_rejects_other_data_or_counter_corruption(string $sql): void
    {
        [$journal, $manifest] = $this->fixture(['tool_sha256' => SmsProspectBackfillPlan::LEGACY_CONFLICT_TOOL_SHA256]);
        $journal->exec($sql);
        $this->expectException(RuntimeException::class);
        SmsProspectBackfillPlan::proof($journal, $manifest, true);
    }

    public function test_legacy_mode_cannot_explain_increases_of_flags_or_counter_transfers_between_groups(): void
    {
        foreach (['UPDATE identities SET contact_conflict=1 WHERE id=16',
            'UPDATE identities SET contact_conflict=CASE id WHEN 11 THEN 0 WHEN 16 THEN 1 ELSE contact_conflict END WHERE id IN(11,16)',
            'UPDATE identities SET contact_conflict=0 WHERE id=23'] as $sql) {
            [$journal, $manifest, $state] = $this->fixture(['tool_sha256' => SmsProspectBackfillPlan::LEGACY_CONFLICT_TOOL_SHA256]);
            $journal->exec($sql);
            // Setting extra stored flags, or impossible per-side recorded counters, is not the known failure.
            if (str_contains($sql, 'CASE')) {
                $state['counts']['missing_contact_conflicts'] = 0;
                $this->checkpoint($journal, $state);
            } elseif (str_contains($sql, 'id=23')) {
                $state['counts']['unique_contact_conflicts'] = 0;
                $state['counts']['missing_contact_conflicts'] = 0;
                $this->checkpoint($journal, $state);
            }
            try { SmsProspectBackfillPlan::proof($journal, $manifest, true); self::fail('Unexplained mismatch accepted.'); }
            catch (RuntimeException) { self::assertTrue(true); }
        }
    }

    public static function invalidRanges(): array
    {
        return [[-1, 1], [2147483648, 1], [0, 0], [0, -1], [0, 1001]];
    }

    #[DataProvider('invalidRanges')]
    public function test_refuses_invalid_cursor_and_page_sizes(int $cursor, int $size): void
    {
        [$journal, $manifest] = $this->fixture();
        $this->expectException(RuntimeException::class);
        SmsProspectBackfillPlan::candidates($journal, $manifest, $cursor, $size);
    }

    public function test_refuses_coerced_cursor_types(): void
    {
        [$journal, $manifest] = $this->fixture();
        $this->expectException(TypeError::class);
        SmsProspectBackfillPlan::candidates($journal, $manifest, '0');
    }

    public static function invalidManifests(): array
    {
        return [['source_rows', '12'], ['source_rows', 12.0], ['source_rows', null], ['source_rows', -1], ['source_rows', 11],
            ['source_guard', null], ['source_guard', 123], ['source_guard', str_repeat('g', 64)], ['source_guard', str_repeat('a', 64)."\n"]];
    }

    #[DataProvider('invalidManifests')]
    public function test_both_methods_refuse_invalid_manifest_scope(string $field, mixed $value): void
    {
        [$journal, $manifest] = $this->fixture([$field => $value]);
        foreach (['proof', 'candidates'] as $method) {
            try {
                SmsProspectBackfillPlan::$method($journal, $manifest, ...($method === 'candidates' ? [0] : []));
                self::fail('Invalid manifest scope accepted.');
            } catch (RuntimeException) { self::assertTrue(true); }
        }
    }

    public static function changedManifests(): array
    {
        return [['source_watermark_pk', 51], ['prospect_watermark_pk', 21], ['source_guard', str_repeat('b', 64)],
            ['source_watermark_pk', '50'], ['prospect_watermark_pk', -1], ['apply_supported', true]];
    }

    #[DataProvider('changedManifests')]
    public function test_delegates_manifest_binding_and_watermark_validation_to_the_core(string $field, mixed $value): void
    {
        [$journal, $manifest] = $this->fixture();
        $manifest[$field] = $value;
        $this->expectException(RuntimeException::class);
        SmsProspectBackfillPlan::proof($journal, $manifest);
    }

    public static function invalidStates(): array
    {
        return [['exhausted', false], ['exhausted', 1], ['source_rows', '12'], ['batches', '1'], ['after_pk', '50'],
            ['counts', []], ['counts.missing_contact_conflicts', '1'], ['counts.missing_contact_conflicts', -1],
            ['counts.valid_contact_rows', 9], ['counts.invalid_phone_rows', 13], ['counts.unique_contact_conflicts', 4]];
    }

    #[DataProvider('invalidStates')]
    public function test_both_methods_fail_closed_on_incomplete_or_inconsistent_state(string $field, mixed $value): void
    {
        [$journal, $manifest, $state] = $this->fixture();
        $path = explode('.', $field);
        if (count($path) === 2) $state[$path[0]][$path[1]] = $value;
        else $state[$field] = $value;
        $this->checkpoint($journal, $state);
        foreach (['proof', 'candidates'] as $method) {
            try {
                SmsProspectBackfillPlan::$method($journal, $manifest, ...($method === 'candidates' ? [0] : []));
                self::fail('Incomplete or inconsistent state accepted.');
            } catch (RuntimeException) { self::assertTrue(true); }
        }
    }

    public function test_missing_checkpoint_is_rejected(): void
    {
        [$journal, $manifest] = $this->fixture();
        $journal->exec('DELETE FROM batches');
        $this->expectException(RuntimeException::class);
        SmsProspectBackfillPlan::proof($journal, $manifest);
    }

    public static function corruptSummaries(): array
    {
        return [['DELETE FROM identities WHERE id=2'], ['UPDATE identities SET existing_count=0 WHERE id=20'],
            ['UPDATE identities SET contact_conflict=1 WHERE id=16'], ['UPDATE identities SET source_rows=3 WHERE id=16'],
            ['UPDATE identities SET source_rows=CASE id WHEN 16 THEN 1 WHEN 23 THEN 3 ELSE source_rows END'],
            ['UPDATE identities SET source_rows=0 WHERE id=16'], ['UPDATE identities SET source_rows=1.5 WHERE id=16'],
            ['UPDATE identities SET existing_count=3 WHERE id=20'], ["UPDATE identities SET existing_count='bad' WHERE id=20"],
            ['UPDATE identities SET contact_conflict=2 WHERE id=16'], ['UPDATE identities SET contact_conflict=0.5 WHERE id=16'],
            ["UPDATE identities SET bucket='bad' WHERE id=2"], ['UPDATE identities SET bucket=2147483648 WHERE id=2'],
            ['UPDATE identities SET id=51 WHERE id=2'], ['UPDATE identities SET evidence=NULL WHERE id=2'],
            ["UPDATE identities SET evidence='not a blob' WHERE id=2"], ["UPDATE identities SET contact_key=X'00' WHERE id=2"]];
    }

    #[DataProvider('corruptSummaries')]
    public function test_proof_rejects_missing_or_corrupt_identity_summaries(string $sql): void
    {
        [$journal, $manifest] = $this->fixture();
        $journal->exec($sql);
        $this->expectException(RuntimeException::class);
        SmsProspectBackfillPlan::proof($journal, $manifest);
    }

    public static function mismatchedEvidence(): array
    {
        return [['PK', '02'], ['PK', 3], ['PK', null], ['Bucket', -1], ['Bucket', '-02147483648'],
            ['Identity_Valid', '01'], ['Identity_Valid', 0], ['Existing_Count', '-0'], ['Existing_Count', 1],
            ['PK', '0'], ['PK', '-1'], ['PK', '2147483648'], ['PK', '2e0'], ['PK', '2.0'], ['PK', '+2'],
            ['PK', ' 2'], ['PK', '2 '], ['PK', str_repeat('9', 30)], ['Bucket', '-2147483649'],
            ['Bucket', '2147483648'], ['Identity_Valid', true], ['Existing_Count', false],
            ['Client', 'PERSON 2'], ['Address', '2 test St'], ['Zip', 123], ['Zip', '00124'],
            ['observed_row_sha256', str_repeat('a', 63)], ['observed_row_sha256', str_repeat('a', 65)],
            ['observed_row_sha256', str_repeat('g', 64)], ['observed_row_sha256', str_repeat('a', 64)."\n"],
            ['observed_row_sha256', 123], ['Phone', 2025550101], ['Phone', []], ['Email', []],
            ['Phone', '+1 (202) 555-0101'], ['Phone', 'bad'], ['Phone', null], ['Email', 'bad@']];
    }

    #[DataProvider('mismatchedEvidence')]
    public function test_pages_reject_mismatched_or_wrongly_typed_evidence(string $field, mixed $value): void
    {
        [$journal, $manifest, , $evidence] = $this->fixture();
        $row = $evidence[2]; $row[$field] = $value;
        $this->replaceEvidence($journal, $row);
        $this->expectException(RuntimeException::class);
        SmsProspectBackfillPlan::candidates($journal, $manifest, 0);
    }

    public static function invalidCompressedEvidence(): array
    {
        return [[''], ['not gzip'], [gzencode('{')], [gzencode('null')], [gzencode('[]')],
            [substr(gzencode('{"PK":2}'), 0, -5)], [gzencode(str_repeat('x', 1048577))], [str_repeat('x', 1048577)]];
    }

    #[DataProvider('invalidCompressedEvidence')]
    public function test_pages_reject_corrupt_truncated_and_oversized_compressed_evidence(string $compressed): void
    {
        [$journal, $manifest] = $this->fixture();
        $this->replaceEvidence($journal, $compressed);
        $this->expectException(RuntimeException::class);
        SmsProspectBackfillPlan::candidates($journal, $manifest, 0);
    }

    public function test_contact_key_is_verified_and_uppercase_observed_hex_is_preserved(): void
    {
        [$journal, $manifest, , $evidence] = $this->fixture();
        $row = $evidence[2]; $row['observed_row_sha256'] = strtoupper($row['observed_row_sha256']);
        $this->replaceEvidence($journal, $row);
        self::assertSame($row['observed_row_sha256'], SmsProspectBackfillPlan::candidates($journal, $manifest, 0, 1)[0]['Observed_SHA256']);
        $journal->prepare('UPDATE identities SET contact_key=? WHERE id=2')->execute([str_repeat('0', 64)]);
        $this->expectException(RuntimeException::class);
        SmsProspectBackfillPlan::candidates($journal, $manifest, 0);
    }

    public static function invalidIdentities(): array
    {
        return [['Client', ''], ['Client', ' Person 2'], ['Client', "Person\t2"],
            ['Address', ''], ['Zip', '0123x'], ['Zip', '01234 ']];
    }

    #[DataProvider('invalidIdentities')]
    public function test_even_matching_identity_bytes_must_be_valid_and_lossless(string $field, string $value): void
    {
        [$journal, $manifest, , $evidence] = $this->fixture();
        $row = $evidence[2]; $row[$field] = $value;
        $this->replaceEvidence($journal, $row);
        $column = strtolower($field);
        $journal->prepare('UPDATE identities SET '.$column.'=? WHERE id=2')->execute([$value]);
        $this->expectException(RuntimeException::class);
        SmsProspectBackfillPlan::candidates($journal, $manifest, 0);
    }

    public function test_identity_bytes_preserve_valid_accented_text_and_leading_zero_zip(): void
    {
        [$journal, $manifest, , $evidence] = $this->fixture();
        $row = $evidence[2]; $row['Client'] = str_repeat('é', 100);
        $this->replaceEvidence($journal, $row);
        $journal->prepare('UPDATE identities SET client=? WHERE id=2')->execute([$row['Client']]);
        self::assertSame(2, SmsProspectBackfillPlan::candidates($journal, $manifest, 0, 1)[0]['Source_PK']);
    }

    public static function incompleteGzipStreams(): array
    {
        return [['trailer'], ['trailing'], ['concatenated'], ['crc']];
    }

    #[DataProvider('incompleteGzipStreams')]
    public function test_valid_evidence_requires_exactly_one_complete_gzip_stream(string $variant): void
    {
        [$journal, $manifest, , $evidence] = $this->fixture();
        $compressed = gzencode(SmsProspectReconciliation::json($evidence[2]), 1);
        if ($variant === 'trailer') $compressed = substr($compressed, 0, -1);
        if ($variant === 'trailing') $compressed .= 'junk';
        if ($variant === 'concatenated') $compressed .= gzencode('{}');
        if ($variant === 'crc') $compressed[strlen($compressed) - 8] = $compressed[strlen($compressed) - 8] ^ "\x01";
        $this->replaceEvidence($journal, $compressed);
        $this->expectException(RuntimeException::class);
        SmsProspectBackfillPlan::candidates($journal, $manifest, 0);
    }

    public static function missingEvidenceFields(): array
    {
        return array_map(fn ($field) => [$field], ['PK', 'Bucket', 'Identity_Valid', 'Existing_Count', 'Client', 'Address', 'Zip',
            'Phone', 'Email', 'observed_row_sha256']);
    }

    #[DataProvider('missingEvidenceFields')]
    public function test_candidate_evidence_requires_all_validated_fields(string $field): void
    {
        [$journal, $manifest, , $evidence] = $this->fixture();
        $row = $evidence[2]; unset($row[$field]);
        $this->replaceEvidence($journal, $row);
        $this->expectException(RuntimeException::class);
        SmsProspectBackfillPlan::candidates($journal, $manifest, 0);
    }

    public function test_floating_point_pk_is_rejected_without_json_integer_coercion(): void
    {
        [$journal, $manifest, , $evidence] = $this->fixture();
        $json = str_replace('"PK":2,', '"PK":2.0,', SmsProspectReconciliation::json($evidence[2]));
        $this->replaceEvidence($journal, gzencode($json, 1));
        $this->expectException(RuntimeException::class);
        SmsProspectBackfillPlan::candidates($journal, $manifest, 0);
    }

    public function test_production_sql_pdo_decimal_strings_preserve_original_raw_observed_hashes(): void
    {
        [$journal, $manifest, , $evidence] = $this->fixture();
        $expected = [];
        foreach ([2, 5, 9] as $id) {
            $raw = $evidence[$id]; unset($raw['observed_row_sha256']);
            foreach (['PK', 'Bucket', 'Identity_Valid', 'Existing_Count'] as $field) $raw[$field] = (string) $raw[$field];
            $hash = hash('sha256', SmsProspectReconciliation::json($raw));
            $recast = $raw;
            foreach (['PK', 'Bucket', 'Identity_Valid', 'Existing_Count'] as $field) $recast[$field] = (int) $recast[$field];
            self::assertNotSame(hash('sha256', SmsProspectReconciliation::json($recast)), $hash);
            $saved = $raw; $saved['observed_row_sha256'] = $hash;
            $this->replaceEvidence($journal, $saved, $id);
            $expected[] = ['Source_PK' => $id, 'Observed_SHA256' => $hash,
                'Contact_Key' => hash('sha256', SmsProspectReconciliation::json([$raw['Phone'], $raw['Email'] === null ? null : strtolower($raw['Email'])]))];
        }
        $journal->exec('PRAGMA query_only=ON');
        self::assertSame(3, SmsProspectBackfillPlan::proof($journal, $manifest)['candidate_count']);
        self::assertSame($expected, SmsProspectBackfillPlan::candidates($journal, $manifest, 0));
        self::assertSame([$expected[1]], SmsProspectBackfillPlan::candidates($journal, $manifest, 2, 1));
    }

    public function test_invalid_unique_people_counts_are_rejected_on_pages_without_scanning_identities(): void
    {
        [$journal, $manifest, $state] = $this->fixture();
        $state['counts']['unique_contact_people'] = 10;
        $state['counts']['unique_missing_people'] = 8;
        $this->checkpoint($journal, $state);
        $journal->reads = [];
        try {
            SmsProspectBackfillPlan::candidates($journal, $manifest, 0);
            self::fail('Impossible per-side unique counts accepted.');
        } catch (RuntimeException $error) {
            self::assertSame('Incomplete coverage counters.', $error->getMessage());
        }
        self::assertSame([], array_values(array_filter($journal->reads, fn ($sql) => str_contains($sql, 'FROM identities'))));
    }

    public static function wrongStorageTypes(): array
    {
        return [['client', 'BLOB'], ['address', 'BLOB'], ['zip', 'BLOB'], ['contact_key', 'BLOB'], ['evidence', 'TEXT']];
    }

    #[DataProvider('wrongStorageTypes')]
    public function test_pages_reject_wrong_sqlite_types_even_when_bytes_match(string $column, string $type): void
    {
        [$journal, $manifest] = $this->fixture();
        $journal->exec('UPDATE identities SET '.$column.'=CAST('.$column.' AS '.$type.') WHERE id=2');
        $this->expectException(RuntimeException::class);
        SmsProspectBackfillPlan::candidates($journal, $manifest, 0);
    }
}

/** Real in-memory SQLite, with statement recording to guard bounded page reads. */
final class SmsProspectPlanTestPdo extends PDO
{
    public array $reads = [];

    public function __construct() { parent::__construct('sqlite::memory:'); }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->reads[] = $query;
        return parent::prepare($query, $options);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $this->reads[] = $query;
        return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
    }
}
