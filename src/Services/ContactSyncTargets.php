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
        $main = [];
        $backendRows = [];
        if ($source === 'LT') {
            $current = self::lookup($pdo, $table, $ids, $exts, $lock);
        } else {
            $nativeIds = array_values(array_filter($exts, fn ($id) => ContactSyncIdentity::validNativeId((string) $id)));
            // LDR and PLAW run concurrently: acquire shared-table locks in one order.
            $main = self::lookup($pdo, 'TblContacts', array_merge($ids, array_map(fn ($id) => 'LLG-' . $id, $nativeIds)), [], $lock);
            foreach (['LDR', 'PLAW'] as $namespace) {
                $backendRows[$namespace] = self::lookup($pdo, self::table($namespace), $ids, $nativeIds, $lock);
            }
            $current = array_values(array_filter($backendRows[$source], fn ($row) => in_array((string) $row['llg_id'], $ids, true)));
        }
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
            // Reload complete physical ownership at every discovered destination, even
            // when no primary row exists or a duplicate has a different External_ID.
            $ownerIds = array_unique(array_merge($ids, array_column($current, 'llg_id'), array_column($sides, 'llg_id')));
            $sides = [];
            foreach (['LDR', 'PLAW'] as $namespace) {
                foreach (self::lookup($pdo, self::table($namespace), $ownerIds, $ltIds, $lock) as $side) {
                    $side['_source'] = $namespace;
                    $sides[] = $side;
                }
            }
        }

        $linked = $source === 'LT' ? [] : self::backendIdentityPlans($incoming, $current, $main, array_merge(...array_values($backendRows)));
        $plan = [];
        $claimed = [];
        foreach ($incoming as $id => $row) {
            // Count every physical claim, including a competing row with a different identity.
            $bridges = array_values(array_filter($sides, fn ($s) => (string) $s['external_id'] === substr($id, 4)));
            if (count($bridges) > 1) {
                throw new RuntimeException("Ambiguous source namespace for {$source}:{$id}; explicit repair required.");
            }
            $backend = $bridges[0] ?? null;
            if ($backend !== null && (!ContactSyncIdentity::validNativeId(substr($id, 4))
                || !ContactSyncIdentity::corroborates($row, $backend))) {
                throw new RuntimeException("Conflicting identity on native LT bridge for {$id}; explicit repair required.");
            }
            if ($backend !== null && count(array_filter($sides, fn ($s) => (string) $s['llg_id'] === (string) $backend['llg_id'])) !== 1) {
                throw new RuntimeException("Conflicting source ownership at native LT bridge for {$id}; explicit repair required.");
            }
            $candidates = [];
            foreach ($current as $old) {
                $direct = (string) $old['llg_id'] === $id;
                $bridge = $backend !== null && (string) $old['llg_id'] === (string) $backend['llg_id'];
                $sameIdentity = ContactSyncIdentity::matches($row, $old);
                if ($source === 'LT' && $backend !== null && ($direct || $bridge)) {
                    // Do not chain relaxed comparisons through a historically mixed main row.
                    $sameIdentity = $sameIdentity || ContactSyncIdentity::matches($backend, $old);
                } elseif ($source !== 'LT' && $direct && self::sameNativeLink($row, $old)) {
                    $sameIdentity = $sameIdentity || ContactSyncIdentity::corroborates($row, $old);
                }
                if ($direct && !$sameIdentity) {
                    throw new RuntimeException("Conflicting identity at {$table}.{$id}; refusing overwrite.");
                }
                $external = $source === 'LT' && $row['external_id'] !== '' && $row['external_id'] !== null
                    && (string) $old['external_id'] === (string) $row['external_id'];
                if ($bridge && !$sameIdentity) {
                    throw new RuntimeException("Conflicting identity at bridge target {$table}.{$old['llg_id']}; explicit repair required.");
                }
                if ($external && ContactSyncIdentity::matches($row, $old)) {
                    foreach ($sides as $side) {
                        $verifiedBackend = $bridge && $sameIdentity && $side === $backend;
                        if ((string) $side['llg_id'] === (string) $old['llg_id']
                            && !ContactSyncIdentity::matches($old, $side) && !$verifiedBackend) {
                            throw new RuntimeException("Existing identity contamination at {$table}.{$old['llg_id']}; explicit repair required.");
                        }
                    }
                }
                // Shared mailer/partner IDs do not prove a switch. A native LT ID bridge does.
                if (($direct || $bridge) && $sameIdentity) {
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
                if (count($owners) !== 1 || !ContactSyncIdentity::corroborates($row, $owners[0])
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
            if ($source === 'LT' && $backend !== null) {
                foreach (['client', 'email', 'phone'] as $field) {
                    $value = $backend[$field];
                    // Blank backend fields do not authorize erasing a known contact detail.
                    $populated = trim((string) $value) !== ''
                        && ($field !== 'email' || strpos((string) $value, '@') > 0);
                    $after[$field] = $populated ? $value : (($before[$field] ?? null) ?: $row[$field]);
                }
            }
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
            if (isset($linked[$id])) {
                $plan[array_key_last($plan)]['linked_identity'] = $linked[$id];
            }
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
            if (isset($change['linked_identity'])) {
                self::apply($pdo, [$change['linked_identity']], 'LT');
            }
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

    private static function sameNativeLink(array $left, array $right): bool
    {
        $id = (string) ($left['external_id'] ?? '');
        return ContactSyncIdentity::validNativeId($id)
            && $id === (string) ($right['external_id'] ?? '');
    }

    /** Preserve a proven pre-update identity when only the backend appears in the delta. */
    private static function backendIdentityPlans(array $incoming, array $current, array $main, array $owners): array
    {
        $changed = [];
        foreach ($current as $old) {
            $row = $incoming[$old['llg_id']] ?? null;
            if ($row !== null && self::sameNativeLink($row, $old) && ContactSyncIdentity::corroborates($row, $old)) {
                foreach (['client', 'email', 'phone'] as $field) {
                    if (!self::equal($field, $old[$field], $row[$field])) {
                        $changed[$old['llg_id']] = $old;
                    }
                }
            }
        }
        if ($changed === []) {
            return [];
        }
        $plans = [];
        foreach ($changed as $id => $old) {
            $claims = array_filter($owners, fn ($owner) => (string) $owner['llg_id'] === (string) $id
                || (string) $owner['external_id'] === (string) $old['external_id']);
            $targets = array_values(array_filter($main, fn ($row) => (string) $row['llg_id'] === (string) $id
                || (string) $row['llg_id'] === 'LLG-' . $old['external_id']));
            if (count($claims) !== 1 || count($targets) !== 1
                || !ContactSyncIdentity::matches($targets[0], $old)) {
                continue; // A historical/ambiguous main row never gains identity proof from an edit.
            }
            $before = $targets[0];
            $after = $before;
            $changes = [];
            foreach (['client', 'email', 'phone'] as $field) {
                $value = $incoming[$id][$field];
                if (trim((string) $value) !== '' && ($field !== 'email' || strpos((string) $value, '@') > 0)
                    && !self::equal($field, $before[$field], $value)) {
                    $after[$field] = $value;
                    $changes[$field] = ['before' => $before[$field], 'after' => $value];
                }
            }
            if ($changes !== []) {
                $plans[$id] = ['incoming_id' => $id, 'target_id' => $before['llg_id'], 'before' => $before, 'after' => $after, 'changes' => $changes];
            }
        }
        return $plans;
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
