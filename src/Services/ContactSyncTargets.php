<?php

namespace Cmd\Reports\Services;

use PDO;
use RuntimeException;

/** SELECT-only planning shared by previews and writes; no temporary lookup tables. */
final class ContactSyncTargets
{
    public const FIELDS = ['created_date', 'assigned_date', 'llg_id', 'external_id', 'campaign', 'data_source',
        'created_by', 'agent', 'client', 'phone', 'email', 'address_1', 'address_2', 'city', 'state', 'zip',
        'stage', 'status', 'debt_amount', 'debt_enrolled', 'credit_score', 'credit_utilization', 'category', 'affiliate_agent'];

    public static function table(string $source): string
    {
        return match ($source) {
            'LT' => 'TblContacts', 'LDR' => 'TblContactsLDR', 'PLAW' => 'TblContactsPLAW',
            default => throw new RuntimeException('Invalid contact source.'),
        };
    }

    public static function plan(PDO $pdo, array $data, string $source, bool $lock = false): array
    {
        if ($data === []) {
            return [];
        }
        if ($lock && !$pdo->inTransaction()) {
            throw new RuntimeException('Contact write planning requires a transaction.');
        }
        $incoming = [];
        foreach ($data as $row) {
            $row = array_change_key_case($row, CASE_LOWER);
            $id = (string) ($row['llg_id'] ?? '');
            if (!preg_match('/^LLG-\d+$/', $id) || isset($incoming[$id])) {
                throw new RuntimeException('Missing or duplicate incoming contact ID: ' . $id);
            }
            $incoming[$id] = self::values($row);
        }
        $table = self::table($source);
        $ids = array_keys($incoming);
        $exts = array_values(array_filter(array_column($incoming, 'external_id'), fn ($v) => $v !== null && $v !== ''));
        $current = self::lookup($pdo, $table, $ids, $source === 'LT' ? $exts : [], $lock);
        $sides = [];
        if ($source === 'LT') {
            $ltIds = array_map(fn ($v) => substr($v, 4), $ids);
            foreach (['LDR', 'PLAW'] as $namespace) {
                foreach (self::lookup($pdo, self::table($namespace), array_merge($ids, array_column($current, 'llg_id')), $ltIds, $lock) as $side) {
                    $side['_source'] = $namespace;
                    $sides[] = $side;
                }
            }
            // Fetch a bridge target once, retaining physical duplicates for ambiguity detection.
            $missingIds = array_diff(array_unique(array_column($sides, 'llg_id')), array_column($current, 'llg_id'));
            $current = array_merge($current, self::lookup($pdo, $table, $missingIds, [], $lock));
            // Inspect both namespaces at every discovered bridge destination too.
            foreach (['LDR', 'PLAW'] as $namespace) {
                $loaded = array_column(array_filter($sides, fn ($s) => $s['_source'] === $namespace), 'llg_id');
                $unseen = array_diff(array_unique(array_column($current, 'llg_id')), $loaded);
                foreach (self::lookup($pdo, self::table($namespace), $unseen, [], $lock) as $side) {
                    $side['_source'] = $namespace;
                    $sides[] = $side;
                }
            }
        }

        $plan = [];
        $claimed = [];
        foreach ($incoming as $id => $row) {
            $bridges = array_values(array_filter($sides, fn ($s) => (string) $s['external_id'] === substr($id, 4)
                && ContactSyncIdentity::matches($row, $s)));
            if (count($bridges) > 1) {
                throw new RuntimeException("Ambiguous source namespace for {$source}:{$id}; explicit repair required.");
            }
            $candidates = [];
            foreach ($current as $old) {
                $direct = (string) $old['llg_id'] === $id;
                if ($direct && !ContactSyncIdentity::matches($row, $old)) {
                    throw new RuntimeException("Conflicting identity at {$table}.{$id}; refusing overwrite.");
                }
                $external = $source === 'LT' && $row['external_id'] !== '' && $row['external_id'] !== null
                    && (string) $old['external_id'] === (string) $row['external_id'];
                $bridge = in_array((string) $old['llg_id'], array_column($bridges, 'llg_id'), true);
                if ($bridge && !ContactSyncIdentity::matches($row, $old)) {
                    throw new RuntimeException("Conflicting identity at bridge target {$table}.{$old['llg_id']}; explicit repair required.");
                }
                if ($external && ContactSyncIdentity::matches($row, $old)) {
                    foreach ($sides as $side) {
                        if ((string) $side['llg_id'] === (string) $old['llg_id'] && !ContactSyncIdentity::matches($old, $side)) {
                            throw new RuntimeException("Existing identity contamination at {$table}.{$old['llg_id']}; explicit repair required.");
                        }
                    }
                }
                // Shared mailer/partner IDs do not prove a switch. A native LT ID bridge does.
                if (($direct || $bridge) && ContactSyncIdentity::matches($row, $old)) {
                    $candidates[] = $old;
                }
            }
            if (count($candidates) > 1) {
                throw new RuntimeException("Ambiguous contact target for {$source}:{$id}; explicit repair required.");
            }
            $before = $candidates[0] ?? null;
            $target = (string) ($before['llg_id'] ?? $id);
            $owners = array_values(array_filter($sides, fn ($s) => (string) $s['llg_id'] === $target));
            if ($source === 'LT' && $owners !== []) {
                if (count($owners) !== 1 || !ContactSyncIdentity::matches($row, $owners[0])
                    || (string) $owners[0]['external_id'] !== substr($id, 4)) {
                    throw new RuntimeException("Conflicting source ownership for {$table}.{$target}; explicit repair required.");
                }
            }
            if (isset($claimed[$target])) {
                throw new RuntimeException("Two incoming contacts claim {$table}.{$target}.");
            }
            $claimed[$target] = true;
            $after = $row;
            $after['llg_id'] = $target;
            if ($source === 'LT' && $before !== null && $target !== $id) {
                $after['external_id'] = $before['external_id'] ?: ($row['external_id'] ?: substr($id, 4));
                // These fields belong to the verified destination company after a switch.
                if ($owners !== []) {
                    foreach (['category', 'affiliate_agent', 'campaign'] as $field) {
                        $after[$field] = $before[$field];
                    }
                }
            }
            $changes = [];
            foreach ($after as $field => $value) {
                $old = $before[$field] ?? null;
                if ($before === null || !self::equal($field, $old, $value)) {
                    $changes[$field] = ['before' => $old, 'after' => $value];
                }
            }
            $plan[] = ['incoming_id' => $id, 'target_id' => $target, 'before' => $before, 'after' => $after, 'changes' => $changes];
        }
        return $plan;
    }

    /** Requires the transaction/locks acquired by plan(..., true). */
    public static function apply(PDO $pdo, array $plan, string $source): int
    {
        if (!$pdo->inTransaction()) {
            throw new RuntimeException('Contact writes require a transaction.');
        }
        $table = self::table($source);
        foreach ($plan as $change) {
            if ($change['changes'] === []) {
                continue;
            }
            if ($change['before'] === null) {
                $fields = array_keys($change['after']);
                $marks = implode(', ', array_fill(0, count($fields), '?'));
                $sql = "INSERT INTO {$table} (" . implode(', ', $fields) . ") SELECT {$marks}
                    WHERE NOT EXISTS (SELECT 1 FROM {$table} WITH (UPDLOCK, HOLDLOCK) WHERE LLG_ID = ?)";
                $values = array_values($change['after']);
                $values[] = $change['target_id'];
            } else {
                $fields = array_keys($change['changes']);
                $sql = "UPDATE {$table} SET " . implode(', ', array_map(fn ($f) => "{$f} = ?", $fields));
                $values = array_map(fn ($f) => $change['after'][$f], $fields);
                // Recheck the complete before-image even if called with a previously saved plan.
                $where = [];
                foreach ($change['before'] as $field => $old) {
                    $where[] = $old === null ? "{$field} IS NULL" : "{$field} = ?";
                    if ($old !== null) {
                        $values[] = $old;
                    }
                }
                $sql .= ' WHERE ' . implode(' AND ', $where);
            }
            $stmt = $pdo->prepare($sql);
            if ($stmt === false || !$stmt->execute($values) || $stmt->rowCount() !== 1) {
                throw new RuntimeException("Contact target changed or write count was not one: {$table}.{$change['target_id']}");
            }
        }
        return count($plan);
    }

    private static function lookup(PDO $pdo, string $table, array $ids, array $exts, bool $lock): array
    {
        $ids = array_values(array_unique($ids));
        $exts = array_values(array_unique($exts));
        if ($ids === [] && $exts === []) {
            return [];
        }
        // One query avoids de-duplicating genuine duplicate physical rows returned by separate lookups.
        $quote = fn ($v) => "'" . str_replace("'", "''", (string) $v) . "'";
        $conditions = [];
        if ($ids !== []) {
            $conditions[] = 'LLG_ID IN (' . implode(', ', array_map($quote, $ids)) . ')';
        }
        if ($exts !== []) {
            $conditions[] = 'External_ID IN (' . implode(', ', array_map($quote, $exts)) . ')';
        }
        $hint = $lock ? ' WITH (UPDLOCK, HOLDLOCK)' : '';
        $stmt = $pdo->query('SELECT ' . implode(', ', self::FIELDS) . " FROM {$table}{$hint} WHERE " . implode(' OR ', $conditions));
        if ($stmt === false) {
            throw new RuntimeException('Contact target lookup failed.');
        }
        return array_map(fn ($r) => array_change_key_case($r, CASE_LOWER), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private static function values(array $row): array
    {
        $values = [];
        foreach (self::FIELDS as $field) {
            $value = $row[$field] ?? null;
            if (in_array($field, ['created_date', 'assigned_date'], true)) {
                $value = $value ?: null;
            } elseif ($field === 'email') {
                $value = str_contains((string) $value, '@') ? $value : null;
            } elseif (in_array($field, ['debt_amount', 'credit_score', 'credit_utilization'], true)) {
                $value = (int) $value;
            } elseif ($field === 'debt_enrolled') {
                $value = (float) $value;
            } else {
                $value = (string) $value;
            }
            $values[$field] = $value;
        }
        return $values;
    }

    private static function equal(string $field, $left, $right): bool
    {
        if ($left === null || $right === null) {
            return $left === $right;
        }
        if (in_array($field, ['created_date', 'assigned_date'], true)) {
            return (new \DateTimeImmutable((string) $left))->format('Y-m-d H:i:s.u')
                === (new \DateTimeImmutable((string) $right))->format('Y-m-d H:i:s.u');
        }
        if (in_array($field, ['debt_amount', 'debt_enrolled', 'credit_score', 'credit_utilization'], true)) {
            return (float) $left === (float) $right;
        }
        return (string) $left === (string) $right;
    }
}
