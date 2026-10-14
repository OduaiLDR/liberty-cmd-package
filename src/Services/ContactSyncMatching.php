<?php

namespace Cmd\Reports\Services;

/** Each preview and UPDATE is rendered from the same joins, guards and field changes. */
final class ContactSyncMatching
{
    public static function sourceSteps(string $source, array $excludedIds = [], array $verifiedCampaigns = []): array
    {
        $table = ContactSyncTargets::table($source);
        if (!in_array($source, ['LDR', 'PLAW'], true)) {
            throw new \RuntimeException('Only destination companies participate in contact matching.');
        }
        $identity = ContactSyncIdentity::sql('c', 's');
        $continuity = ContactSyncIdentity::corroboratesSql('c', 's');
        $uniqueLink = 'SELECT External_ID FROM (
            SELECT External_ID FROM TblContactsLDR
            UNION ALL SELECT External_ID FROM TblContactsPLAW
        ) links GROUP BY External_ID HAVING COUNT(*) = 1';
        // The primary table has no company namespace. A destination ID must have
        // one physical company owner, including rows with a conflicting identity.
        $uniqueOwner = 'SELECT LLG_ID FROM (
            SELECT LLG_ID FROM TblContactsLDR
            UNION ALL SELECT LLG_ID FROM TblContactsPLAW
        ) owners GROUP BY LLG_ID HAVING COUNT(*) = 1';
        // UNION ALL deliberately retains duplicate physical rows and competing company identities.
        $edges = [];
        foreach (['LDR', 'PLAW'] as $namespace) {
            $side = ContactSyncTargets::table($namespace);
            // Separate equality joins avoid an OR join's enormous candidate cross product.
            // The bridge omits only an already accepted direct edge, not physical owners.
            foreach (['direct', 'bridge'] as $route) {
                $join = $route === 'direct' ? 'c.LLG_ID = s.LLG_ID'
                    : "c.LLG_ID = 'LLG-' + CAST(s.External_ID AS VARCHAR(50))";
                $guard = $route === 'direct' ? $identity : "{$continuity}
                    AND LEFT(s.External_ID, 1) BETWEEN '1' AND '9'
                    AND s.External_ID COLLATE Latin1_General_100_BIN2 NOT LIKE '%[^0-9]%'
                    AND s.External_ID <> '1234567840' AND s.External_ID IN ({$uniqueLink})
                    AND NOT (c.LLG_ID = s.LLG_ID AND {$identity})";
                $edges[] = "SELECT '{$namespace}' AS Namespace, c.LLG_ID AS ContactId, s.LLG_ID AS SourceId
                    FROM TblContacts c JOIN {$side} s ON {$join}
                    JOIN (SELECT LLG_ID FROM TblContacts GROUP BY LLG_ID HAVING COUNT(*) = 1) unique_contact
                      ON unique_contact.LLG_ID = c.LLG_ID
                    JOIN ({$uniqueOwner}) unique_source ON unique_source.LLG_ID = s.LLG_ID
                    WHERE {$guard}";
            }
        }
        $eligible = 'SELECT * FROM (
            SELECT *, COUNT(*) OVER (PARTITION BY ContactId) AS ContactCount,
                COUNT(*) OVER (PARTITION BY Namespace, SourceId) AS SourceCount FROM ('
                . implode(' UNION ALL ', $edges) . ') edges
        ) counted WHERE ContactCount = 1 AND SourceCount = 1
              AND (ContactId = SourceId OR NOT EXISTS (SELECT 1 FROM TblContacts taken WHERE taken.LLG_ID = counted.SourceId))';
        $cte = '';
        $from = "FROM TblContacts c JOIN ({$eligible}) e ON e.ContactId = c.LLG_ID AND e.Namespace = '{$source}'
            JOIN {$table} s ON s.LLG_ID = e.SourceId";
        // Exclude only after counting every physical owner and edge. Removing an
        // excluded competitor earlier could incorrectly make an ambiguous mapping unique.
        $scope = ContactSyncExclusions::predicate('c', $excludedIds)
            . ' AND ' . ContactSyncExclusions::predicate('s', $excludedIds);
        $fields = [];
        foreach (['Affiliate_Agent', 'Category'] as $field) {
            $fields[$field] = "CASE WHEN COALESCE(s.{$field}, '') <> '' THEN s.{$field} ELSE c.{$field} END";
        }
        $campaignProof = self::campaignProofSql('s', 'Campaign', $verifiedCampaigns);
        $fields['Campaign'] = "CASE WHEN NULLIF(LTRIM(RTRIM(c.Campaign)), '') IS NULL AND ({$campaignProof})
            THEN s.Campaign ELSE c.Campaign END";
        foreach (['Client', 'Email', 'Phone'] as $field) {
            $valid = $field === 'Email' ? " AND CHARINDEX('@', s.Email) > 1" : '';
            $fields[$field] = "CASE WHEN NULLIF(LTRIM(RTRIM(s.{$field})), '') IS NOT NULL{$valid}
                THEN s.{$field} ELSE c.{$field} END";
        }
        return [
            'linked_fields' => self::step($cte, $from, 'c', $fields, $scope),
            // Historical name-only remaps and all orphan deletion belong to the explicit repair tool.
            'external_id_remap' => self::step($cte, $from, 'c', ['LLG_ID' => 's.LLG_ID'],
                "{$scope} AND c.LLG_ID = 'LLG-' + CAST(s.External_ID AS VARCHAR(50)) AND c.LLG_ID <> s.LLG_ID"),
            // Only verified LT intake supplies the main mailer key. A backend
            // External_ID is an LT reference and must never backfill that field.
            'lt_agent_sync' => self::step($cte, $from, 's', ['Agent' => 'c.Agent'],
                "{$scope} AND c.LLG_ID = s.LLG_ID AND NULLIF(LTRIM(RTRIM(c.Agent)), '') IS NOT NULL AND c.Agent NOT LIKE '% User'"),
        ];
    }

    public static function enrollmentSteps(bool $reconcileAgents, array $excludedIds = [], array $verifiedCampaigns = []): array
    {
        $name = ContactSyncIdentity::nameSql('e');
        $contactName = ContactSyncIdentity::nameSql('c');
        $from = "FROM TblEnrollment e JOIN TblContacts c ON e.LLG_ID = c.LLG_ID
            AND {$name} <> '' AND {$name} = {$contactName}";
        // COUNT all physical contacts, including blanks: MIN must never manufacture a composite person.
        $unique = '(SELECT COUNT(*) FROM TblContacts d WHERE d.LLG_ID = c.LLG_ID) = 1 AND ' . self::enrollmentOwnerSql('c')
            . ' AND ' . ContactSyncExclusions::predicate('c', $excludedIds)
            . ' AND ' . ContactSyncExclusions::predicate('e', $excludedIds);
        $agentScope = $reconcileAgents ? '1 = 1' : "(NULLIF(LTRIM(RTRIM(e.Agent)), '') IS NULL OR e.Agent LIKE '% User')";
        $campaignProof = self::campaignProofSql('c', 'Campaign', $verifiedCampaigns);
        return [
            'agent_contacts' => self::step('', $from, 'e', ['Agent' => 'c.Agent'],
                "{$unique} AND {$agentScope} AND NULLIF(LTRIM(RTRIM(c.Agent)), '') IS NOT NULL AND c.Agent NOT LIKE '% User'"),
            'drop_name' => self::step('', $from, 'e', ['Drop_Name' => 'c.Campaign'],
                "{$unique} AND NULLIF(LTRIM(RTRIM(e.Drop_Name)), '') IS NULL AND ({$campaignProof})"),
        ];
    }

    /** SELECT-only: detect historical conflicts before any matching UPDATE, and bound SQL proof literals. */
    public static function campaignPreflight(\PDO $pdo, array $verifiedCampaigns): array
    {
        self::validateCampaigns($verifiedCampaigns);
        $rows = [];
        $mainIds = [];
        $backendProofs = [];
        foreach (array_chunk(array_keys($verifiedCampaigns), 400) as $ids) {
            $marks = implode(', ', array_fill(0, count($ids), '?'));
            $sql = "SELECT 'main' AS Kind, LLG_ID, Campaign, NULL AS External_ID FROM TblContacts WHERE LLG_ID IN ({$marks})
                UNION ALL SELECT 'enrollment', LLG_ID, Drop_Name, NULL FROM TblEnrollment WHERE LLG_ID IN ({$marks})
                UNION ALL SELECT 'backend', LLG_ID, Campaign, External_ID FROM TblContactsLDR WHERE LLG_ID IN ({$marks})
                UNION ALL SELECT 'backend', LLG_ID, Campaign, External_ID FROM TblContactsPLAW WHERE LLG_ID IN ({$marks})";
            $statement = $pdo->prepare($sql);
            if ($statement === false || !$statement->execute(array_merge($ids, $ids, $ids, $ids))) {
                throw new \RuntimeException('Unable to inspect contact campaign attribution.');
            }
            foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $row = array_change_key_case($row, CASE_LOWER);
                $rows[] = $row;
                if ($row['kind'] === 'main') {
                    $mainIds[(string) $row['llg_id']] = true;
                } elseif ($row['kind'] === 'backend' && ContactSyncIdentity::validNativeId((string) $row['external_id'])) {
                    $backendProofs['LLG-' . $row['external_id']][] = (string) $row['llg_id'];
                }
            }
        }
        // Before the ID remap, a backend proof may refer to a still-native LT main row.
        foreach (array_chunk(array_diff(array_keys($backendProofs), array_keys($mainIds)), 400) as $ids) {
            $marks = implode(', ', array_fill(0, count($ids), '?'));
            $statement = $pdo->prepare("SELECT 'main' AS Kind, LLG_ID, Campaign FROM TblContacts WHERE LLG_ID IN ({$marks})");
            if ($statement === false || !$statement->execute(array_values($ids))) {
                throw new \RuntimeException('Unable to inspect linked contact campaign attribution.');
            }
            foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $rows[] = array_change_key_case($row, CASE_LOWER);
            }
        }
        $conflicts = [];
        $fillable = [];
        foreach ($rows as $row) {
            $id = (string) $row['llg_id'];
            $proofIds = isset($verifiedCampaigns[$id]) ? [$id] : [];
            if ($row['kind'] === 'main') {
                $proofIds = array_unique(array_merge($proofIds, $backendProofs[$id] ?? []));
            }
            foreach ($proofIds as $proofId) {
                $campaign = (string) $verifiedCampaigns[$proofId];
                if (trim((string) $row['campaign']) === '') {
                    if ($row['kind'] !== 'backend') {
                        $fillable[$proofId] = $campaign;
                    }
                } elseif ((string) $row['campaign'] !== $campaign) {
                    $conflicts[$row['kind'] . ':' . $id . ':' . $proofId] = [
                        'id' => $id, 'proof_id' => $proofId, 'code' => 'campaign_attribution_review',
                        'message' => 'Existing ' . $row['kind'] . ' campaign differs from verified attribution; history review required.',
                    ];
                }
            }
        }
        return ['conflicts' => array_values($conflicts), 'verified_campaigns' => $fillable];
    }

    private static function campaignProofSql(string $alias, string $field, array $campaigns): string
    {
        self::validateCampaigns($campaigns);
        $proofs = [];
        foreach ($campaigns as $id => $campaign) {
            $quoted = "'" . str_replace("'", "''", $campaign) . "'";
            $proofs[] = "('{$id}', {$quoted})";
        }
        return $proofs === [] ? '1 = 0' : 'EXISTS (SELECT 1 FROM (VALUES ' . implode(', ', $proofs)
            . ") proof(Id, Campaign) WHERE proof.Id = {$alias}.LLG_ID AND proof.Campaign = {$alias}.{$field})";
    }

    private static function validateCampaigns(array $campaigns): void
    {
        foreach ($campaigns as $id => $campaign) {
            if (!preg_match('/^LLG-[0-9]{1,50}$/D', (string) $id) || !is_string($campaign)
                || trim($campaign) === '' || strlen($campaign) > 255) {
                throw new \RuntimeException('Invalid verified contact campaign.');
            }
        }
    }

    /** Enrollment has no email/phone: require its contact's independent company identity proof. */
    public static function enrollmentOwnerSql(string $contact): string
    {
        $owners = '(SELECT LLG_ID, Client, Email, Phone FROM TblContactsLDR
            UNION ALL SELECT LLG_ID, Client, Email, Phone FROM TblContactsPLAW)';
        $identity = ContactSyncIdentity::sql($contact, 'owner');
        return "EXISTS (SELECT 1 FROM (
            SELECT *, COUNT(*) OVER (PARTITION BY LLG_ID) AS OwnerCount FROM {$owners} company_rows
        ) owner WHERE owner.LLG_ID = {$contact}.LLG_ID AND owner.OwnerCount = 1 AND {$identity})";
    }

    private static function step(string $cte, string $from, string $target, array $fields, string $where): array
    {
        $sets = [];
        $select = ["{$target}.LLG_ID AS TargetId"];
        $different = [];
        foreach ($fields as $field => $value) {
            $sets[] = "{$target}.{$field} = {$value}";
            $select[] = "{$target}.{$field} AS Before_{$field}";
            $select[] = "{$value} AS After_{$field}";
            $different[] = "({$target}.{$field} <> {$value} OR ({$target}.{$field} IS NULL AND {$value} IS NOT NULL)
                OR ({$target}.{$field} IS NOT NULL AND {$value} IS NULL))";
        }
        $predicate = "WHERE ({$where}) AND (" . implode(' OR ', $different) . ')';
        return [
            'update' => $cte . "UPDATE {$target} SET " . implode(', ', $sets) . " {$from} {$predicate}",
            'preview' => $cte . 'SELECT ' . implode(', ', $select) . " {$from} {$predicate}",
        ];
    }
}
