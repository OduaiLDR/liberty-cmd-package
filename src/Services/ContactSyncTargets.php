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

    /** Gather only unresolved, unique source links before acquiring write locks. */
    public static function evidenceRequests(PDO $pdo, array $data, string $source): array
    {
        if ($data === []) {
            return [];
        }
        $incoming = [];
        foreach ($data as $row) {
            $row = self::values(array_change_key_case($row, CASE_LOWER));
            $id = (string) $row['llg_id'];
            if (!preg_match('/^LLG-[1-9][0-9]*$/D', $id) || isset($incoming[$id])) {
                throw new RuntimeException('Invalid or duplicate incoming evidence contact ID.');
            }
            $incoming[$id] = $row;
        }
        if ($source !== 'LT') {
            return self::backendEvidenceRequests($pdo, $incoming, $source);
        }
        $nativeIds = array_map(fn ($id) => substr($id, 4), array_keys($incoming));
        $sides = [];
        foreach (['LDR', 'PLAW'] as $namespace) {
            $sides = array_merge($sides, self::lookup($pdo, self::table($namespace), [], $nativeIds, false));
        }
        $ownerIds = array_unique(array_column($sides, 'llg_id'));
        $sides = [];
        foreach (['LDR', 'PLAW'] as $namespace) {
            foreach (self::lookup($pdo, self::table($namespace), $ownerIds, $nativeIds, false) as $side) {
                $side['_source'] = $namespace;
                $sides[] = $side;
            }
        }
        $requests = [];
        foreach ($incoming as $id => $row) {
            $bridges = array_values(array_filter($sides, fn ($side) => (string) $side['external_id'] === substr($id, 4)));
            if (count($bridges) > 1 && ContactSyncIdentity::validNativeId(substr($id, 4))) {
                if (array_filter($bridges, fn ($side) => !preg_match('/^LLG-[1-9][0-9]*$/D', (string) $side['llg_id'])
                    || !ContactSyncIdentity::validNativeId(substr((string) $side['llg_id'], 4))) === []) {
                    $requests[] = ['kind' => 'backend_selection', 'incoming' => $row, 'candidates' => $bridges];
                }
                continue;
            }
            if (count($bridges) !== 1 || !ContactSyncIdentity::validNativeId(substr($id, 4))) {
                continue;
            }
            $backend = $bridges[0];
            $owners = array_filter($sides, fn ($side) => (string) $side['llg_id'] === (string) $backend['llg_id']);
            if (count($owners) === 1 && preg_match('/^LLG-[1-9][0-9]*$/D', (string) $backend['llg_id'])
                && ContactSyncIdentity::validNativeId(substr((string) $backend['llg_id'], 4))
                && !ContactSyncIdentity::corroborates($row, $backend)) {
                $requests[] = ['incoming' => $row, 'backend' => $backend];
            }
        }
        return $requests;
    }

    private static function backendEvidenceRequests(PDO $pdo, array $incoming, string $source): array
    {
        self::table($source);
        $nativeIds = array_values(array_filter(array_column($incoming, 'external_id'),
            fn ($id) => ContactSyncIdentity::validNativeId((string) $id)));
        $owners = [];
        $current = [];
        foreach (['LDR', 'PLAW'] as $namespace) {
            $rows = self::lookup($pdo, self::table($namespace), array_keys($incoming), $nativeIds, false);
            array_push($owners, ...$rows);
            if ($namespace === $source) {
                $current = $rows;
            }
        }
        $requests = [];
        foreach ($incoming as $id => $row) {
            $existing = array_values(array_filter($current, fn ($old) => (string) $old['llg_id'] === (string) $id));
            // Legacy backend-only records need no LT proof for a verified same-CRM refresh.
            if (count($existing) !== 1 || self::sameNativeOwner($row, $existing[0]) || ContactSyncIdentity::matches($row, $existing[0])
                || ContactSyncIdentity::corroborates($row, $existing[0]) || !self::sameNativeLink($row, $existing[0])) {
                continue;
            }
            if (count(self::backendOwners($owners, $row)) === 1) {
                $requests[] = ['kind' => 'backend_update', 'incoming' => $row,
                    'backend' => $existing[0] + ['_source' => $source]];
            }
        }
        return $requests;
    }

    private static function backendOwners(array $owners, array $row): array
    {
        return array_filter($owners, fn ($owner) => (string) $owner['llg_id'] === (string) $row['llg_id']
            || (string) $owner['external_id'] === (string) $row['external_id']);
    }

    private static function provenBackendUpdate(array $row, array $old, array $owners, string $source, ?ContactSyncSourceEvidence $evidence): bool
    {
        return $evidence !== null && (string) $row['llg_id'] === (string) $old['llg_id']
            && self::sameNativeLink($row, $old) && count(self::backendOwners($owners, $row)) === 1
            && $evidence->supportsBackendUpdate($row, $old, $source);
    }

    public static function plan(PDO $pdo, array $data, string $source, bool $lock = false, ?ContactSyncSourceEvidence $evidence = null, bool $reportConflicts = false, array $campaignHolds = []): array
    {
        // Resolve the entire page before applying any row, independent of incoming order.
        // A later contact may establish an attribution hold for an earlier linked target.
        do {
            $plan = self::planPage($pdo, $data, $source, $lock, $evidence, $reportConflicts, $campaignHolds);
            $held = [];
            foreach ($plan as $change) {
                foreach ($change['warnings'] ?? [] as $warning) {
                    if ($warning['code'] === 'campaign_attribution_preserved') {
                        array_push($held, ...($warning['related_ids'] ?? [$change['incoming_id']]));
                    }
                }
            }
            $added = array_values(array_diff(array_unique($held), $campaignHolds));
            if ($added === []) {
                // Carry the complete closure to the caller so the next page cannot
                // escape a known hold through an indirect native-ID link.
                if ($plan !== [] && $campaignHolds !== []) $plan[0]['campaign_hold_ids'] = $campaignHolds;
                return $plan;
            }
            $campaignHolds = array_values(array_unique(array_merge($campaignHolds,
                ContactSyncExclusions::resolve($pdo, $added, null))));
        } while (true);
    }

    private static function planPage(PDO $pdo, array $data, string $source, bool $lock, ?ContactSyncSourceEvidence $evidence, bool $reportConflicts, array $campaignHolds): array
    {
        if ($data === []) {
            return [];
        }
        if ($lock && !$pdo->inTransaction()) {
            throw new RuntimeException('Contact write planning requires a transaction.');
        }
        $incoming = [];
        $campaignProofs = [];
        foreach ($data as $row) {
            $row = array_change_key_case($row, CASE_LOWER);
            $id = (string) ($row['llg_id'] ?? '');
            if (!preg_match('/^LLG-\d+$/', $id) || isset($incoming[$id])) {
                throw new RuntimeException('Missing or duplicate incoming contact ID: ' . $id);
            }
            $incoming[$id] = self::values($row);
            $campaignProofs[$id] = is_array($row['_campaign_proof'] ?? null) ? $row['_campaign_proof'] : null;
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

        $backendOwners = $source === 'LT' ? [] : array_merge(...array_values($backendRows));
        $linked = $source === 'LT' ? [] : self::backendIdentityPlans($incoming, $current, $main, $backendOwners, $source, $evidence);
        $plan = [];
        $claimed = [];
        foreach ($incoming as $id => $row) {
            $warnings = [];
            try {
                // Count every physical claim, including a competing row with a different identity.
                $bridges = array_values(array_filter($sides, fn ($s) => (string) $s['external_id'] === substr($id, 4)));
                $selection = null;
                if (count($bridges) > 1) {
                    $selection = $evidence?->selection($row, $bridges);
                    if (empty($selection['winner']) || empty($selection['identity_proven'])) {
                        $warnings[] = ['code' => 'backend_selection_review', 'source' => $source, 'id' => $id,
                            'message' => $selection['reason'] ?? 'Current enrolled winner is unverified.',
                            'candidates' => array_map(fn ($side) => ['source' => $side['_source'], 'id' => $side['llg_id']], $bridges)];
                        throw new ContactSyncConflict("Ambiguous source namespace for {$source}:{$id}; "
                            . ($selection['reason'] ?? 'current enrolled winner is unverified') . '; skipped for review.');
                    }
                    $selected = array_values(array_filter($bridges, fn ($side) => $side['_source'] === $selection['winner']['source']
                        && (string) $side['llg_id'] === $selection['winner']['id']));
                    if (count($selected) !== 1) {
                        throw new ContactSyncConflict("Ambiguous physical enrolled winner for {$source}:{$id}; skipped for review.");
                    }
                    $warnings[] = ['code' => 'duplicate_backend', 'source' => $source, 'id' => $id,
                        'message' => $selection['reason'] === 'lt_plan_company'
                            ? 'Selected the backend named by the LT enrollment plan; competing files are duplicates for review.'
                            : 'Selected the enrolled backend; competing files are duplicates for review.',
                        'winner' => $selection['winner'], 'duplicates' => $selection['losers'],
                        'stale_candidates' => $selection['stale_candidates'] ?? []];
                    $bridges = $selected;
                }
                $backend = $bridges[0] ?? null;
                $corroborated = $backend !== null && (ContactSyncIdentity::corroborates($row, $backend)
                    || ($evidence !== null && $evidence->supports($row, $backend)) || !empty($selection['identity_proven']));
                if ($backend !== null && (!ContactSyncIdentity::validNativeId(substr($id, 4))
                    || !$corroborated)) {
                    throw new ContactSyncConflict("Conflicting identity on native LT bridge for {$id}; explicit repair required.");
                }
                if ($backend !== null && count(array_filter($sides, fn ($s) => (string) $s['llg_id'] === (string) $backend['llg_id'])) !== 1) {
                    throw new ContactSyncConflict("Conflicting source ownership at native LT bridge for {$id}; explicit repair required.");
                }
                $candidates = [];
                foreach ($current as $old) {
                    $direct = (string) $old['llg_id'] === $id;
                    $bridge = $backend !== null && (string) $old['llg_id'] === (string) $backend['llg_id'];
                    $sameIdentity = ContactSyncIdentity::matches($row, $old);
                    // The owning CRM may edit its own record. This never proves a CRM transfer.
                    if (!$sameIdentity && $source === 'LT' && $direct && $backend === null
                        && self::sameNativeOwner($row, $old)
                        && count(array_filter($sides, fn ($side) => (string) $side['llg_id'] === $id)) === 0) {
                        $sameIdentity = true;
                    }
                    if ($source === 'LT' && $backend !== null && ($direct || $bridge)) {
                        // Do not chain relaxed comparisons through a historically mixed main row.
                        $sameIdentity = $sameIdentity || ContactSyncIdentity::matches($backend, $old);
                    } elseif ($source !== 'LT' && $direct) {
                        $sameIdentity = $sameIdentity || self::sameNativeOwner($row, $old)
                            || (self::sameNativeLink($row, $old) && (ContactSyncIdentity::corroborates($row, $old)
                                || self::provenBackendUpdate($row, $old, $backendOwners, $source, $evidence)));
                    }
                    if ($direct && !$sameIdentity) {
                        throw new ContactSyncConflict("Conflicting identity at {$table}.{$id}; refusing overwrite.");
                    }
                    $external = $source === 'LT' && $row['external_id'] !== '' && $row['external_id'] !== null
                        && (string) $old['external_id'] === (string) $row['external_id'];
                    if ($bridge && !$sameIdentity) {
                        throw new ContactSyncConflict("Conflicting identity at bridge target {$table}.{$old['llg_id']}; explicit repair required.");
                    }
                    if ($external && ContactSyncIdentity::matches($row, $old)) {
                        foreach ($sides as $side) {
                            $verifiedBackend = $bridge && $sameIdentity && $side === $backend;
                            if ((string) $side['llg_id'] === (string) $old['llg_id']
                                && !ContactSyncIdentity::matches($old, $side) && !$verifiedBackend) {
                                throw new ContactSyncConflict("Existing identity contamination at {$table}.{$old['llg_id']}; explicit repair required.");
                            }
                        }
                    }
                    // Shared mailer/partner IDs do not prove a switch. A native LT ID bridge does.
                    if (($direct || $bridge) && $sameIdentity) {
                        $candidates[] = $old;
                    }
                }
                if (count($candidates) > 1) {
                    throw new ContactSyncConflict("Ambiguous contact target for {$source}:{$id}; explicit repair required.");
                }
                $before = $candidates[0] ?? null;
                $target = (string) ($before['llg_id'] ?? $id);
                $owners = array_values(array_filter($sides, fn ($s) => (string) $s['llg_id'] === $target));
                if ($source === 'LT' && $owners !== []) {
                    if (count($owners) !== 1 || !$corroborated || $owners[0] !== $backend
                        || (string) $owners[0]['external_id'] !== substr($id, 4)) {
                        throw new ContactSyncConflict("Conflicting source ownership for {$table}.{$target}; explicit repair required.");
                    }
                }
                $campaignLinks = $source === 'LT' ? ($backend === null ? [] : [$backend])
                    : array_values(array_filter($main, fn ($other) => (string) $other['llg_id'] === $id
                        || (ContactSyncIdentity::validNativeId((string) $row['external_id'])
                            && (string) $other['llg_id'] === 'LLG-' . $row['external_id'])));
                $proof = $campaignProofs[$id];
                $proofKey = trim((string) ($proof['external_id'] ?? ''));
                $preserveUnverified = $before !== null && $proofKey !== '' && ($source === 'LT'
                    ? (string) $before['external_id'] === $proofKey && (string) $row['external_id'] === $proofKey
                    : (count($campaignLinks) === 1 && (string) ($proof['source_reference'] ?? '') === (string) $row['external_id']
                        && (string) $campaignLinks[0]['external_id'] === $proofKey
                        && (string) $campaignLinks[0]['campaign'] === (string) $before['campaign']));
                $campaign = ContactSyncCampaign::plan($row, $before, $proof, $campaignLinks, $preserveUnverified);
                $preserveAttribution = $campaign['preserve_attribution'] ?? false;
                $attributionReview = $campaign['review_reason'] ?? null;
                $attributionIds = array_merge([$id, $target], array_column($campaignLinks, 'llg_id'));
                if ($source !== 'LT' && ContactSyncIdentity::validNativeId((string) $row['external_id'])) {
                    $attributionIds[] = 'LLG-' . $row['external_id'];
                }
                if (array_intersect($attributionIds, $campaignHolds) !== []) {
                    $attributionReview ??= 'Linked campaign attribution remains under review.';
                }
                if (($proof['status'] ?? '') === 'verified' && $source === 'LT'
                    && (string) ($proof['external_id'] ?? '') !== (string) $row['external_id']) {
                    $campaign['reason'] = 'Campaign proof does not belong to the incoming mailer key.';
                }
                if (($proof['status'] ?? '') === 'verified' && $source !== 'LT'
                    && (string) ($proof['external_id'] ?? '') !== (string) $row['external_id']
                    && (string) ($proof['source_reference'] ?? '') !== (string) $row['external_id']) {
                    $campaign['reason'] = 'Campaign proof does not belong to the verified LT source reference.';
                }
                if (($proof['status'] ?? '') === 'verified'
                    && (string) ($proof['campaign'] ?? '') !== trim((string) $row['campaign'])) {
                    $campaign['reason'] = 'Campaign proof does not belong to the incoming campaign.';
                }
                if (($proof['status'] ?? '') === 'verified' && $source === 'LT' && $before !== null && $target !== $id
                    && trim((string) $before['external_id']) !== ''
                    && (string) $before['external_id'] !== (string) $proof['external_id']) {
                    $attributionReview = 'Linked main contact has a different mailer key; a reviewed attribution repair is required.';
                }
                if (($proof['status'] ?? '') === 'verified' && $source !== 'LT') {
                    foreach ($campaignLinks as $other) {
                        if (trim((string) $other['external_id']) !== ''
                            && (string) $other['external_id'] !== (string) $proof['external_id']) {
                            $attributionReview = 'Linked main contact has a different mailer key; a reviewed attribution repair is required.';
                        }
                    }
                }
                $preserveMailerKey = $source === 'LT' && $before !== null && $target === $id
                    && trim((string) $before['external_id']) !== '';
                if ($preserveMailerKey && !$preserveAttribution && trim((string) $row['external_id']) !== ''
                    && (string) $row['external_id'] !== (string) $before['external_id']) {
                    $attributionReview = 'Existing campaign mailer key differs; a reviewed attribution repair is required.';
                }
                if ($attributionReview !== null && $campaign['reason'] === null) {
                    if ($before === null) {
                        $campaign['reason'] = $attributionReview;
                    } else {
                        $preserveAttribution = true;
                        $campaign['value'] = $before['campaign'];
                    }
                }
                if ($preserveAttribution && $source !== 'LT'
                    && (string) $before['external_id'] !== (string) $row['external_id']) {
                    // Backend External_ID is a native LT link, not a mailing key.
                    $campaign['reason'] = 'Backend LT reference changed; attribution preservation requires a stable link.';
                }
                if ($campaign['reason'] !== null) {
                    $warnings[] = ['code' => 'campaign_attribution_review', 'source' => $source, 'id' => $id,
                        'message' => $campaign['reason'], 'related_ids' => array_values(array_unique(array_merge(
                            [$id, $target], array_column($campaignLinks, 'llg_id'))))];
                    throw new ContactSyncConflict($campaign['reason']);
                }
                if (isset($claimed[$target])) {
                    $message = "Two incoming contacts claim {$table}.{$target}.";
                    if ($reportConflicts) {
                        foreach ($plan as &$prior) {
                            if ($prior['target_id'] === $target) {
                                $prior = self::skipped($prior['incoming_id'], $source, $message);
                            }
                        }
                        unset($prior);
                    }
                    throw new ContactSyncConflict($message);
                }
                $claimed[$target] = true;
                $after = $row;
                $after['llg_id'] = $target;
                $after['campaign'] = $campaign['value'];
                if ($preserveMailerKey) {
                    $after['external_id'] = $before['external_id'];
                }
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
                    $after['external_id'] = $before['external_id'] ?: $row['external_id'];
                    // These fields belong to the verified destination company after a switch.
                    if ($owners !== []) {
                        foreach (['category', 'affiliate_agent'] as $field) {
                            $after[$field] = $before[$field];
                        }
                    }
                }
                if ($preserveAttribution) {
                    // Keep the exact stored value, including NULL/blank, after all remap rules.
                    $after['external_id'] = $before['external_id'];
                    $warnings[] = ['code' => 'campaign_attribution_preserved', 'source' => $source, 'id' => $id,
                        'message' => 'Existing campaign and external ID preserved; ordinary updates allowed. ' . $attributionReview,
                        'related_ids' => array_values(array_unique($attributionIds))];
                }
                $changes = [];
                foreach ($after as $field => $value) {
                    $old = $before[$field] ?? null;
                    if ($before === null || !self::equal($field, $old, $value)) {
                        $changes[$field] = ['before' => $old, 'after' => $value];
                    }
                }
                $plan[] = ['incoming_id' => $id, 'target_id' => $target, 'before' => $before, 'after' => $after, 'changes' => $changes];
                if (($proof['status'] ?? '') === 'verified' && !$preserveAttribution) {
                    $plan[array_key_last($plan)]['campaign_proof'] = $proof;
                    // The LT main row can still use its native ID until final matching remaps it.
                    $plan[array_key_last($plan)]['campaign_proof_ids'] = array_values(array_unique(array_merge(
                        [$target], $source === 'LT' && $backend !== null ? [(string) $backend['llg_id']] : []
                    )));
                }
                if (isset($linked[$id])) {
                    $plan[array_key_last($plan)]['linked_identity'] = $linked[$id];
                }
                if ($warnings !== []) {
                    $plan[array_key_last($plan)]['warnings'] = $warnings;
                }
            } catch (ContactSyncConflict $error) {
                if (!$reportConflicts) {
                    throw $error;
                }
                $plan[] = self::skipped($id, $source, $error->getMessage(), $warnings);
            }
        }
        return $plan;
    }

    private static function skipped(string $id, string $source, string $message, array $warnings = []): array
    {
        $warnings[] = ['code' => 'contact_review', 'source' => $source, 'id' => $id, 'message' => $message];
        return ['incoming_id' => $id, 'target_id' => $id, 'before' => null, 'after' => null,
            'changes' => [], 'skip' => true, 'warnings' => $warnings];
    }

    /** Requires the transaction/locks acquired by plan(..., true). */
    public static function apply(PDO $pdo, array $plan, string $source): int
    {
        if (!$pdo->inTransaction()) {
            throw new RuntimeException('Contact writes require a transaction.');
        }
        $table = self::table($source);
        $accepted = 0;
        foreach ($plan as $change) {
            if (!empty($change['skip'])) {
                continue;
            }
            $accepted++;
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
        return $accepted;
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

    private static function sameNativeOwner(array $row, array $old): bool
    {
        if (trim((string) $row['client']) === '' || !ContactSyncIdentity::validNativeId(substr((string) $row['llg_id'], 4))) {
            return false;
        }
        foreach (['llg_id', 'external_id'] as $field) {
            if ((string) $row[$field] !== (string) $old[$field]) {
                return false;
            }
        }
        return true;
    }

    private static function sameNativeLink(array $left, array $right): bool
    {
        $id = (string) ($left['external_id'] ?? '');
        return ContactSyncIdentity::validNativeId($id)
            && $id === (string) ($right['external_id'] ?? '');
    }

    /** Preserve a proven pre-update identity when only the backend appears in the delta. */
    private static function backendIdentityPlans(array $incoming, array $current, array $main, array $owners, string $source, ?ContactSyncSourceEvidence $evidence): array
    {
        $changed = [];
        foreach ($current as $old) {
            $row = $incoming[$old['llg_id']] ?? null;
            if ($row !== null && self::sameNativeLink($row, $old) && (self::sameNativeOwner($row, $old)
                || (self::sameNativeLink($row, $old)
                    && (ContactSyncIdentity::corroborates($row, $old) || self::provenBackendUpdate($row, $old, $owners, $source, $evidence))))) {
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
