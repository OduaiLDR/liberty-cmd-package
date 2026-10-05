<?php

namespace Cmd\Reports\Services;

use PDO;
use RuntimeException;

/** Expand review flags without writing staging tables or treating mailer IDs as CRM links. */
final class ContactSyncExclusions
{
    public static function resolve(PDO $pdo, array $ids): array
    {
        $known = array_fill_keys(self::normalize($ids), true);
        if (count($known) > 10000) {
            throw new RuntimeException('Contact review scope exceeds 10,000 IDs; matching requires review.');
        }
        $pending = array_keys($known);
        while ($pending !== []) {
            $next = [];
            foreach (array_chunk($pending, 400) as $batch) {
                $native = array_values(array_filter(array_map(fn ($id) => substr($id, 4), $batch),
                    fn ($id) => ContactSyncIdentity::validNativeId($id)));
                $where = 'LLG_ID IN (' . implode(', ', array_fill(0, count($batch), '?')) . ')';
                if ($native !== []) {
                    $where .= ' OR External_ID IN (' . implode(', ', array_fill(0, count($native), '?')) . ')';
                }
                // Keep both namespaces, conflicting identities and physical duplicates in scope.
                $query = "SELECT LLG_ID, External_ID FROM TblContactsLDR WHERE {$where}
                    UNION ALL SELECT LLG_ID, External_ID FROM TblContactsPLAW WHERE {$where}";
                $parameters = array_merge($batch, $native);
                $statement = $pdo->prepare($query);
                if ($statement === false || !$statement->execute(array_merge($parameters, $parameters))) {
                    throw new RuntimeException('Unable to resolve contact reconciliation exclusions.');
                }
                foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $row = array_change_key_case($row, CASE_LOWER);
                    $related = self::normalize([(string) $row['llg_id']]);
                    $external = (string) ($row['external_id'] ?? '');
                    if (ContactSyncIdentity::validNativeId($external)) {
                        $related[] = 'LLG-' . $external;
                    }
                    foreach ($related as $id) {
                        if (!isset($known[$id])) {
                            $known[$id] = true;
                            $next[] = $id;
                        }
                    }
                    // An unexpectedly connected component must stop safely, never truncate exclusions.
                    if (count($known) > 10000) {
                        throw new RuntimeException('Contact review scope exceeds 10,000 IDs; matching requires review.');
                    }
                }
            }
            $pending = $next;
        }
        $ids = array_keys($known);
        sort($ids, SORT_STRING);
        return $ids;
    }

    public static function predicate(string $alias, array $ids): string
    {
        if (!in_array($alias, ['c', 's', 'e'], true)) {
            throw new RuntimeException('Invalid contact exclusion alias.');
        }
        $ids = self::normalize($ids);
        return $ids === [] ? '1 = 1' : "{$alias}.LLG_ID NOT IN ("
            . implode(', ', array_map(fn ($id) => "'{$id}'", $ids)) . ')';
    }

    private static function normalize(array $ids): array
    {
        foreach ($ids as $id) {
            if (!is_string($id) || !preg_match('/^LLG-[0-9]{1,50}$/D', $id)) {
                throw new RuntimeException('Invalid contact reconciliation exclusion ID.');
            }
        }
        return array_values(array_unique($ids));
    }
}
