<?php

namespace Cmd\Reports\Services;

use RuntimeException;

/** Validate every deferred enrollment proposal before any file's reconciliation writes. */
final class ContactSyncEnrollmentChanges
{
    public static function preflight(DBConnector $connector, array $bySourceChanges): array
    {
        $result = ['categories' => [], 'affiliates' => [], 'flags' => []];
        $flags = [];
        $related = [];
        $name = ContactSyncIdentity::nameSql('e') . " <> '' AND "
            . ContactSyncIdentity::nameSql('e') . ' = ' . ContactSyncIdentity::nameSql('u');
        $identity = ContactSyncIdentity::sql('c', 'u');
        $owner = ContactSyncMatching::enrollmentOwnerSql('c');
        foreach (['categories' => ['Category', 'category'], 'affiliates' => ['Affiliate_Agent', 'agent']] as $kind => [$field, $key]) {
            $groups = [];
            foreach ($bySourceChanges as $source => $changes) {
                if (!in_array($source, ['LT', 'LDR', 'PLAW'], true) || !is_array($changes[$kind] ?? [])) {
                    throw new RuntimeException('Invalid deferred enrollment source.');
                }
                foreach ($changes[$kind] ?? [] as $change) {
                    self::validate($change, $key);
                    $related[$change['llg_id']] = array_values(array_unique(array_merge(
                        $related[$change['llg_id']] ?? [], $change['related_ids'] ?? [$change['llg_id']]
                    )));
                    $groups[$change['llg_id']][] = $change;
                }
            }
            $proposals = [];
            foreach ($groups as $id => $changes) {
                $values = [];
                $distinct = [];
                foreach ($changes as $change) {
                    $values[json_encode([$change['before'], $change[$key]], JSON_THROW_ON_ERROR)] = true;
                    // Different identity claims remain separate until each one passes the SQL guard.
                    $fingerprint = json_encode(array_map(fn ($field) => $change[$field],
                        ['llg_id', 'client', 'email', 'phone', 'before', $key]), JSON_THROW_ON_ERROR);
                    $distinct[$fingerprint] = $change;
                }
                if (count($values) !== 1) {
                    $flags[$id . ':' . $field] = self::flag($id, $field,
                        'Sources propose conflicting enrollment values or before-images; file requires review.');
                    continue;
                }
                array_push($proposals, ...array_values($distinct));
            }
            $accepted = [];
            foreach (array_chunk($proposals, 100) as $batch) {
                $values = [];
                $params = [];
                foreach ($batch as $index => $change) {
                    $values[] = '(?, ?, ?, ?, ?, ?, ?)';
                    array_push($params, $index, $change['llg_id'], $change['client'], $change['email'],
                        $change['phone'], $change['before'], $change[$key]);
                }
                $sql = 'SELECT u.ProposalId FROM TblEnrollment e JOIN (VALUES ' . implode(', ', $values)
                    . ") u(ProposalId, LLG_ID, Client, Email, Phone, BeforeValue, AfterValue)
                    ON e.LLG_ID = u.LLG_ID AND {$name}
                    WHERE COALESCE(e.{$field}, '') = u.BeforeValue
                      AND (SELECT COUNT(*) FROM TblEnrollment d WHERE d.LLG_ID = e.LLG_ID) = 1
                      AND (SELECT COUNT(*) FROM TblContacts c WHERE c.LLG_ID = e.LLG_ID) = 1
                      AND EXISTS (SELECT 1 FROM TblContacts c WHERE c.LLG_ID = e.LLG_ID AND {$identity} AND {$owner})";
                $query = $connector->querySqlServer($sql, $params);
                if (!($query['success'] ?? false) || !is_array($query['data'] ?? null)) {
                    throw new RuntimeException("Enrollment {$field} preflight failed.");
                }
                $verified = [];
                foreach ($query['data'] as $row) {
                    $row = array_change_key_case($row, CASE_LOWER);
                    $verified[(string) $row['proposalid']] = true;
                }
                foreach ($batch as $index => $change) {
                    $id = $change['llg_id'];
                    if (!isset($verified[$index])) {
                        $flags[$id . ':' . $field] = self::flag($id, $field,
                            'Enrollment identity or expected value did not verify; proposed update was skipped.');
                    } else {
                        $accepted[$id] = $change;
                    }
                }
            }
            $result[$kind] = array_values($accepted);
        }
        // A failure in either field excludes both proposals for the entire file.
        $blocked = array_fill_keys(array_column($flags, 'id'), true);
        foreach (['categories', 'affiliates'] as $kind) {
            $result[$kind] = array_values(array_filter($result[$kind], fn ($change) => !isset($blocked[$change['llg_id']])));
        }
        foreach ($flags as &$flag) $flag['related_ids'] = $related[$flag['id']] ?? [$flag['id']];
        unset($flag);
        $result['flags'] = array_values($flags);
        return $result;
    }

    private static function flag(string $id, string $field, string $message): array
    {
        return ['id' => $id, 'related_ids' => [$id], 'code' => 'enrollment_' . strtolower($field) . '_conflict',
            'message' => $message];
    }

    private static function validate(mixed $change, string $key): void
    {
        if (!is_array($change) || !is_string($change['llg_id'] ?? null)
            || !preg_match('/^LLG-[1-9][0-9]{0,49}$/D', $change['llg_id'])) {
            throw new RuntimeException('Invalid deferred enrollment contact ID.');
        }
        foreach (['client', 'email', 'phone', 'before', $key] as $field) {
            if (!array_key_exists($field, $change) || (!is_string($change[$field]) && $change[$field] !== null)) {
                throw new RuntimeException('Invalid deferred enrollment value.');
            }
        }
    }
}
