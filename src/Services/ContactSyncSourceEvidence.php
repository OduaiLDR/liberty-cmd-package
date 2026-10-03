<?php

namespace Cmd\Reports\Services;

use RuntimeException;

/** Ephemeral corroboration: source identifiers and their digests never enter a contact plan. */
final class ContactSyncSourceEvidence
{
    private array $proofs = [];

    private function __construct() {}

    /** $query(source, SELECT SQL) returns associative rows; it must not log response bodies. */
    public static function collect(array $requests, callable $query): self
    {
        $evidence = new self();
        $salt = bin2hex(random_bytes(32));
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

    private static function querySql(string $source, array $ids, array $nativeIds, string $salt): string
    {
        $targets = $ids === [] ? '0' : implode(',', array_unique($ids));
        $references = implode(',', array_map(fn ($id) => "'{$id}'", array_unique($nativeIds)));
        // Match PHP trim() used by processChunk: a padded competing reference still owns the link.
        $nativeReference = "TRIM(COALESCE(c.TP_ID, ''), CONCAT(' ', CHR(9), CHR(10), CHR(13), CHR(0), CHR(11)))";
        $selection = $source === 'LT' ? "c.ID IN ({$targets})"
            : "(c.ID IN ({$targets}) OR {$nativeReference} IN ({$references}))";
        return "WITH candidate AS (
            SELECT c.ID, c.TP_ID, c.DEL, c.ISCOAPP, CONCAT(c.FIRSTNAME, ' ', c.LASTNAME) AS FULLNAME, c.PHONE3, c.EMAIL,
                CASE WHEN c.ID IN ({$targets}) THEN REGEXP_REPLACE(COALESCE(c.SSN, ''), '[^0-9]', '') ELSE '' END AS DIGITS
            FROM CONTACTS c WHERE c._FIVETRAN_DELETED = FALSE AND c.DEL = 'FALSE' AND c.ISCOAPP = 0
                AND c.ID > 0 AND c.FIRSTNAME IS NOT NULL AND c.FIRSTNAME <> '' AND {$selection}
        ) SELECT ID, TP_ID, DEL, ISCOAPP, FULLNAME, PHONE3, EMAIL,
            CASE WHEN LENGTH(DIGITS) = 9 AND DIGITS <> REPEAT(LEFT(DIGITS, 1), 9)
                AND DIGITS NOT IN ('123456789', '987654321')
                THEN SHA2(CONCAT('{$salt}', ':full:', DIGITS), 256) ELSE NULL END AS FULL_TOKEN
            FROM candidate";
    }
}
