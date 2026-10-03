<?php

namespace Cmd\Reports\Services;

use RuntimeException;

/** Ephemeral corroboration: source identifiers and their digests never enter a contact plan. */
final class ContactSyncSourceEvidence
{
    private array $proofs = [];
    private array $selections = [];

    private function __construct() {}

    /** $query(source, SELECT SQL) returns associative rows; it must not log response bodies. */
    public static function collect(array $requests, callable $query): self
    {
        $evidence = new self();
        $salt = bin2hex(random_bytes(32));
        $selections = array_values(array_filter($requests, fn ($request) => ($request['kind'] ?? '') === 'backend_selection'));
        $requests = array_values(array_filter($requests, fn ($request) => ($request['kind'] ?? '') !== 'backend_selection'));
        $evidence->collectSelections($selections, $query, $salt);
        foreach (array_chunk($requests, 500) as $batch) {
            $byNative = [];
            $ids = ['LT' => [], 'LDR' => [], 'PLAW' => []];
            foreach ($batch as $request) {
                $kind = $request['kind'] ?? 'lt_bridge';
                $incoming = array_change_key_case($request['incoming'], CASE_LOWER);
                $backend = array_change_key_case($request['backend'], CASE_LOWER);
                $native = $kind === 'backend_update' ? (string) ($incoming['external_id'] ?? '')
                    : substr((string) ($incoming['llg_id'] ?? ''), 4);
                $backendId = substr((string) ($backend['llg_id'] ?? ''), 4);
                $source = $backend['_source'] ?? '';
                if (!in_array($kind, ['lt_bridge', 'backend_update'], true) || !in_array($source, ['LDR', 'PLAW'], true)
                    || !ContactSyncIdentity::validNativeId($native) || !ContactSyncIdentity::validNativeId($backendId)
                    || ($incoming['llg_id'] ?? '') !== 'LLG-' . ($kind === 'backend_update' ? $backendId : $native)
                    || ($backend['llg_id'] ?? '') !== 'LLG-' . $backendId
                    || (string) ($backend['external_id'] ?? '') !== $native || isset($byNative[$native])) {
                    throw new RuntimeException('Invalid or duplicate contact source evidence request.');
                }
                $byNative[$native] = ['kind' => $kind, 'incoming' => $incoming, 'backend' => $backend];
                $ids['LT'][] = $native;
                $ids[$source][] = $backendId;
            }
            if ($byNative === []) {
                continue;
            }
            $rows = [];
            $claims = [];
            foreach (['LT', 'LDR', 'PLAW'] as $source) {
                try {
                    $response = $query($source, self::querySql($source, $ids[$source], $ids['LT'], $salt));
                } catch (\Throwable $error) {
                    // Connector exception bodies can contain returned data. Do not propagate them.
                    throw new RuntimeException('Contact source evidence read failed for ' . $source . '.');
                }
                foreach ($response as $raw) {
                    $row = array_change_key_case($raw, CASE_UPPER);
                    if (!self::activePrimary($row)) {
                        continue;
                    }
                    $id = (string) ($row['ID'] ?? '');
                    if (!ContactSyncIdentity::validNativeId($id)) {
                        continue;
                    }
                    $rows[$source][$id][] = $row;
                    $native = trim((string) ($row['TP_ID'] ?? ''));
                    if ($source !== 'LT' && isset($byNative[$native])) {
                        $claims[$native][] = [$source, $id];
                    }
                }
                unset($response, $row, $raw);
            }
            foreach ($byNative as $native => $request) {
                $backend = $request['backend'];
                $source = $backend['_source'];
                $backendId = substr($backend['llg_id'], 4);
                $left = $rows['LT'][$native] ?? [];
                $right = $rows[$source][$backendId] ?? [];
                if (count($left) !== 1 || count($right) !== 1 || ($claims[$native] ?? []) !== [[$source, $backendId]]) {
                    continue;
                }
                $lt = $left[0];
                $side = $right[0];
                $token = (string) ($lt['FULL_TOKEN'] ?? '');
                if (!preg_match('/^[a-f0-9]{64}$/iD', $token)
                    || !hash_equals(strtolower($token), strtolower((string) ($side['FULL_TOKEN'] ?? '')))) {
                    continue;
                }
                $freshLt = self::sourceSnapshot($lt);
                $freshBackend = self::sourceSnapshot($side);
                $supported = $request['kind'] === 'backend_update'
                    ? self::fingerprint($freshBackend) === self::fingerprint($request['incoming'])
                        && (ContactSyncIdentity::matches($backend, $freshLt) || ContactSyncIdentity::matches($backend, $freshBackend))
                    : self::fingerprint($freshLt) === self::fingerprint($request['incoming'])
                        && self::matchesStoredSnapshot($freshBackend, $backend);
                if ($supported) {
                    $evidence->proofs[self::key($request['incoming'], $backend, $request['kind'])] = true;
                }
            }
            unset($rows, $left, $right, $lt, $side, $token);
        }
        return $evidence;
    }

    public function supports(array $incoming, array $backend): bool
    {
        return isset($this->proofs[self::key($incoming, $backend)]);
    }

    public function supportsBackendUpdate(array $incoming, array $old, string $source): bool
    {
        $old = array_change_key_case($old, CASE_LOWER);
        $old['_source'] = $source;
        return isset($this->proofs[self::key($incoming, $old, 'backend_update')]);
    }

    /** Null means no decision for this exact SQL snapshot; a null winner is an explicit conflict. */
    public function selection(array $incoming, array $candidates): ?array
    {
        return $this->selections[self::selectionKey($incoming, $candidates)] ?? null;
    }

    private function collectSelections(array $requests, callable $query, string $salt): void
    {
        $batches = [];
        $batch = [];
        $counts = ['LT' => 0, 'LDR' => 0, 'PLAW' => 0];
        foreach ($requests as $request) {
            $incoming = array_change_key_case($request['incoming'], CASE_LOWER);
            $native = substr((string) ($incoming['llg_id'] ?? ''), 4);
            $candidates = array_map(fn ($row) => array_change_key_case($row, CASE_LOWER), $request['candidates'] ?? []);
            if (!ContactSyncIdentity::validNativeId($native) || ($incoming['llg_id'] ?? '') !== 'LLG-' . $native
                || count($candidates) < 2) {
                throw new RuntimeException('Invalid backend selection request.');
            }
            $needed = ['LT' => 1, 'LDR' => 0, 'PLAW' => 0];
            foreach ($candidates as $candidate) {
                $source = $candidate['_source'] ?? '';
                $id = substr((string) ($candidate['llg_id'] ?? ''), 4);
                if (!in_array($source, ['LDR', 'PLAW'], true) || !ContactSyncIdentity::validNativeId($id)
                    || ($candidate['llg_id'] ?? '') !== 'LLG-' . $id || (string) ($candidate['external_id'] ?? '') !== $native) {
                    throw new RuntimeException('Invalid backend selection candidate.');
                }
                $needed[$source]++;
            }
            $prepared = ['incoming' => $incoming, 'candidates' => $candidates, 'native' => $native];
            if (max($needed) > 500) {
                $this->selections[self::selectionKey($incoming, $candidates)] = self::selectionResult($candidates, 'too_many_candidates');
                continue;
            }
            if ($batch !== [] && max(array_map(fn ($source) => $counts[$source] + $needed[$source], array_keys($counts))) > 500) {
                $batches[] = $batch;
                $batch = [];
                $counts = ['LT' => 0, 'LDR' => 0, 'PLAW' => 0];
            }
            $batch[] = $prepared;
            foreach ($counts as $source => $_) { $counts[$source] += $needed[$source]; }
        }
        if ($batch !== []) { $batches[] = $batch; }
        foreach ($batches as $batch) {
            $ids = ['LT' => [], 'LDR' => [], 'PLAW' => []];
            foreach ($batch as $request) {
                $ids['LT'][] = $request['native'];
                foreach ($request['candidates'] as $candidate) {
                    $ids[$candidate['_source']][] = substr($candidate['llg_id'], 4);
                }
            }
            $rows = [];
            $claims = [];
            foreach (array_keys($ids) as $source) {
                try { $response = $query($source, self::querySql($source, $ids[$source], $ids['LT'], $salt, true)); }
                catch (\Throwable $error) { throw new RuntimeException('Contact source evidence read failed for ' . $source . '.'); }
                foreach ($response as $raw) {
                    $row = array_change_key_case($raw, CASE_UPPER);
                    if (!self::activePrimary($row)) { continue; }
                    $id = (string) ($row['ID'] ?? '');
                    if (!ContactSyncIdentity::validNativeId($id)) { continue; }
                    $rows[$source][$id][] = $row;
                    $native = trim((string) ($row['TP_ID'] ?? ''));
                    if ($source !== 'LT' && in_array($native, $ids['LT'], true)) {
                        $claims[$native][] = ['source' => $source, 'id' => 'LLG-' . $id];
                    }
                }
                unset($response, $row, $raw);
            }
            foreach ($batch as $request) {
                $decision = self::selectBackend($request, $rows, $claims[$request['native']] ?? []);
                $this->selections[self::selectionKey($request['incoming'], $request['candidates'])] = $decision;
            }
            unset($rows, $claims);
        }
    }

    private static function selectBackend(array $request, array $rows, array $claims): array
    {
        $candidates = $request['candidates'];
        $result = self::selectionResult($candidates, 'source_candidates_changed');
        $identifiers = $result['candidates'];
        if (count(array_unique(array_map(fn ($id) => $id['source'] . ':' . $id['id'], $identifiers))) !== count($identifiers)) {
            $result['reason'] = 'duplicate_sql_candidate';
            return $result;
        }
        $claimKeys = array_map(fn ($id) => $id['source'] . ':' . $id['id'], $claims);
        if (count(array_unique($claimKeys)) !== count($claimKeys)
            || array_filter($claims, fn ($claim) => !in_array($claim, $identifiers, true)) !== []) { return $result; }
        $lt = $rows['LT'][$request['native']] ?? [];
        if (count($lt) !== 1 || self::fingerprint(self::sourceSnapshot($lt[0])) !== self::fingerprint($request['incoming'])) {
            $result['reason'] = 'lt_snapshot_changed';
            return $result;
        }
        $enrolled = [];
        foreach ($candidates as $candidate) {
            $sourceRows = $rows[$candidate['_source']][substr($candidate['llg_id'], 4)] ?? [];
            if (count($sourceRows) > 1) { return $result; }
            if ($sourceRows === [] || trim((string) ($sourceRows[0]['TP_ID'] ?? '')) !== $request['native']) {
                $result['stale_candidates'][] = ['source' => $candidate['_source'], 'id' => $candidate['llg_id']];
                continue; // Absence/moved references only flag the stored loser; they never authorize its mutation.
            }
            $rawFlag = $sourceRows[0]['ENROLLED'] ?? null;
            $flag = is_bool($rawFlag) ? (string) (int) $rawFlag : strtolower(trim((string) $rawFlag));
            if (!in_array($flag, ['0', '1', 'false', 'true'], true)) {
                $result['reason'] = 'unknown_enrollment';
                return $result;
            }
            if (in_array($flag, ['1', 'true'], true)) { $enrolled[] = [$candidate, $sourceRows[0]]; }
        }
        if (count($enrolled) !== 1) {
            $result['reason'] = $enrolled === [] ? 'neither_enrolled' : 'multiple_enrolled';
            return $result;
        }
        [$candidate, $fresh] = $enrolled[0];
        if (!self::matchesStoredSnapshot(self::sourceSnapshot($fresh), $candidate)) {
            $result['reason'] = 'backend_snapshot_changed';
            return $result;
        }
        $leftToken = (string) ($lt[0]['FULL_TOKEN'] ?? '');
        $equalToken = preg_match('/^[a-f0-9]{64}$/iD', $leftToken)
            && hash_equals(strtolower($leftToken), strtolower((string) ($fresh['FULL_TOKEN'] ?? '')));
        if (!ContactSyncIdentity::corroborates(self::sourceSnapshot($lt[0]), self::sourceSnapshot($fresh)) && !$equalToken) {
            $result['reason'] = 'identity_unproven';
            return $result;
        }
        $result['winner'] = ['source' => $candidate['_source'], 'id' => $candidate['llg_id']];
        $result['reason'] = 'single_enrolled';
        $result['identity_proven'] = true;
        $result['losers'] = array_values(array_filter($identifiers, fn ($id) => $id !== $result['winner']));
        return $result;
    }

    private static function selectionResult(array $candidates, string $reason): array
    {
        $identifiers = array_map(fn ($row) => ['source' => $row['_source'], 'id' => $row['llg_id']], $candidates);
        usort($identifiers, fn ($left, $right) => [$left['source'], $left['id']] <=> [$right['source'], $right['id']]);
        return ['winner' => null, 'reason' => $reason, 'candidates' => $identifiers, 'losers' => [],
            'stale_candidates' => [], 'identity_proven' => false];
    }

    /** Bind all stored fields, retaining physical duplicates; only row ordering is immaterial. */
    private static function selectionKey(array $incoming, array $candidates): string
    {
        $snapshot = static function (array $row): string {
            $row = array_change_key_case($row, CASE_LOWER);
            $values = [];
            foreach (array_merge(ContactSyncTargets::FIELDS, ['_source']) as $field) {
                $values[$field] = isset($row[$field]) ? (string) $row[$field] : null;
            }
            return json_encode($values, JSON_THROW_ON_ERROR);
        };
        $stored = array_map($snapshot, $candidates);
        sort($stored, SORT_STRING);
        return hash('sha256', json_encode([$snapshot($incoming), $stored], JSON_THROW_ON_ERROR));
    }

    /** Only missing stored contact details may differ; populated disagreement still fails. */
    private static function matchesStoredSnapshot(array $fresh, array $stored): bool
    {
        if (self::fingerprint($fresh) === self::fingerprint($stored)) {
            return true;
        }
        if (!ContactSyncIdentity::matches($fresh, $stored)) {
            return false;
        }
        foreach (['email', 'phone'] as $field) {
            $value = (string) ($fresh[$field] ?? '');
            $valid = $field === 'email' ? strpos($value, '@') > 0 : strlen($value) >= 7 && ctype_digit($value);
            if (trim((string) ($stored[$field] ?? '')) === '' && $valid) {
                $stored[$field] = $fresh[$field];
            }
        }
        return self::fingerprint($fresh) === self::fingerprint($stored);
    }

    private static function key(array $incoming, array $backend, string $kind = 'lt_bridge'): string
    {
        $backend = array_change_key_case($backend, CASE_LOWER);
        return $kind . ':' . ($backend['_source'] ?? '') . ':' . self::fingerprint($incoming) . ':' . self::fingerprint($backend);
    }

    private static function fingerprint(array $row): string
    {
        $row = array_change_key_case($row, CASE_LOWER);
        $name = mb_strtoupper(str_replace([' ', '.', ',', '-', '/', "'", "\t"], '', trim((string) ($row['client'] ?? ''), ' ')), 'UTF-8');
        $email = mb_strtolower(trim((string) ($row['email'] ?? ''), ' '), 'UTF-8');
        $email = strpos($email, '@') > 0 ? $email : '';
        $phone = str_replace([' ', '-', '(', ')', '+', '.', '/', "\t", "\n", "\r"], '', trim((string) ($row['phone'] ?? '')));
        return hash('sha256', json_encode([(string) ($row['llg_id'] ?? ''), (string) ($row['external_id'] ?? ''), $name, $email, $phone], JSON_THROW_ON_ERROR));
    }

    private static function sourceSnapshot(array $row): array
    {
        $external = trim((string) ($row['TP_ID'] ?? ''));
        return ['llg_id' => 'LLG-' . $row['ID'],
            'external_id' => in_array($external, ['0', '1234567840', 'UNKNOWN'], true) ? '' : substr($external, 0, 50),
            'client' => substr((string) ($row['FULLNAME'] ?? ''), 0, 255), 'email' => $row['EMAIL'] ?? '',
            'phone' => substr(preg_replace('/[^0-9]/', '', (string) ($row['PHONE3'] ?? '')), 0, 50)];
    }

    private static function activePrimary(array $row): bool
    {
        $false = static fn ($value) => in_array(strtolower((string) $value), ['0', 'false'], true) || $value === false;
        return array_key_exists('DEL', $row) && array_key_exists('ISCOAPP', $row)
            && $false($row['DEL']) && $false($row['ISCOAPP']);
    }

    private static function querySql(string $source, array $ids, array $nativeIds, string $salt, bool $enrollment = false): string
    {
        $targets = $ids === [] ? '0' : implode(',', array_unique($ids));
        $references = implode(',', array_map(fn ($id) => "'{$id}'", array_unique($nativeIds)));
        // Match PHP trim() used by processChunk: a padded competing reference still owns the link.
        $nativeReference = "TRIM(COALESCE(c.TP_ID, ''), CONCAT(' ', CHR(9), CHR(10), CHR(13), CHR(0), CHR(11)))";
        $selection = $source === 'LT' ? "c.ID IN ({$targets})"
            : "(c.ID IN ({$targets}) OR {$nativeReference} IN ({$references}))";
        $candidateEnrollment = $enrollment ? 'c.ENROLLED,' : '';
        $selectedEnrollment = $enrollment ? 'ENROLLED,' : '';
        return "WITH candidate AS (
            SELECT c.ID, c.TP_ID, c.DEL, c.ISCOAPP, {$candidateEnrollment} CONCAT(c.FIRSTNAME, ' ', c.LASTNAME) AS FULLNAME, c.PHONE3, c.EMAIL,
                CASE WHEN c.ID IN ({$targets}) THEN REGEXP_REPLACE(COALESCE(c.SSN, ''), '[^0-9]', '') ELSE '' END AS DIGITS
            FROM CONTACTS c WHERE c._FIVETRAN_DELETED = FALSE AND c.DEL = 'FALSE' AND c.ISCOAPP = 0
                AND c.ID > 0 AND c.FIRSTNAME IS NOT NULL AND c.FIRSTNAME <> '' AND {$selection}
        ) SELECT ID, TP_ID, DEL, ISCOAPP, {$selectedEnrollment} FULLNAME, PHONE3, EMAIL,
            CASE WHEN LENGTH(DIGITS) = 9 AND DIGITS <> REPEAT(LEFT(DIGITS, 1), 9)
                AND DIGITS NOT IN ('123456789', '987654321')
                THEN SHA2(CONCAT('{$salt}', ':full:', DIGITS), 256) ELSE NULL END AS FULL_TOKEN
            FROM candidate";
    }
}
