<?php

namespace Cmd\Reports\Services;

/** Each preview and UPDATE is rendered from the same joins, guards and field changes. */
final class ContactSyncMatching
{
    public static function sourceSteps(string $source): array
    {
        $table = ContactSyncTargets::table($source);
        if (!in_array($source, ['LDR', 'PLAW'], true)) {
            throw new \RuntimeException('Only destination companies participate in contact matching.');
        }
        $identity = ContactSyncIdentity::sql('c', 's');
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
            $edges[] = "SELECT '{$namespace}' AS Namespace, c.LLG_ID AS ContactId, s.LLG_ID AS SourceId
                FROM TblContacts c JOIN {$side} s
                  ON (c.LLG_ID = s.LLG_ID OR c.LLG_ID = 'LLG-' + CAST(s.External_ID AS VARCHAR(50)))
                 AND {$identity}
                JOIN (SELECT LLG_ID FROM TblContacts GROUP BY LLG_ID HAVING COUNT(*) = 1) unique_contact
                  ON unique_contact.LLG_ID = c.LLG_ID
                JOIN ({$uniqueOwner}) unique_source
                  ON unique_source.LLG_ID = s.LLG_ID";
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
        $fields = [];
        foreach (['Affiliate_Agent', 'Campaign', 'Category'] as $field) {
            $fields[$field] = "CASE WHEN COALESCE(s.{$field}, '') <> '' THEN s.{$field} ELSE c.{$field} END";
        }
        return [
            'linked_fields' => self::step($cte, $from, 'c', $fields, '1 = 1'),
            // Historical name-only remaps and all orphan deletion belong to the explicit repair tool.
            'external_id_remap' => self::step($cte, $from, 'c', ['LLG_ID' => 's.LLG_ID'],
                "c.LLG_ID = 'LLG-' + CAST(s.External_ID AS VARCHAR(50)) AND c.LLG_ID <> s.LLG_ID"),
            'backfill_external_id' => self::step($cte, $from, 'c', ['External_ID' => 'LEFT(CAST(s.External_ID AS VARCHAR(50)), 50)'],
                "c.LLG_ID = s.LLG_ID AND COALESCE(c.External_ID, '') = ''
                 AND COALESCE(CAST(s.External_ID AS VARCHAR(50)), '') NOT IN ('', '0', '1234567840', 'UNKNOWN')"),
            'lt_agent_sync' => self::step($cte, $from, 's', ['Agent' => 'c.Agent'],
                "c.LLG_ID = s.LLG_ID AND NULLIF(LTRIM(RTRIM(c.Agent)), '') IS NOT NULL AND c.Agent NOT LIKE '% User'"),
        ];
    }

    public static function enrollmentSteps(bool $reconcileAgents): array
    {
        $name = ContactSyncIdentity::nameSql('e');
        $contactName = ContactSyncIdentity::nameSql('c');
        $from = "FROM TblEnrollment e JOIN TblContacts c ON e.LLG_ID = c.LLG_ID
            AND {$name} <> '' AND {$name} = {$contactName}";
        // COUNT all physical contacts, including blanks: MIN must never manufacture a composite person.
        $unique = '(SELECT COUNT(*) FROM TblContacts d WHERE d.LLG_ID = c.LLG_ID) = 1 AND ' . self::enrollmentOwnerSql('c');
        $agentScope = $reconcileAgents ? '1 = 1' : "(NULLIF(LTRIM(RTRIM(e.Agent)), '') IS NULL OR e.Agent LIKE '% User')";
        return [
            'agent_contacts' => self::step('', $from, 'e', ['Agent' => 'c.Agent'],
                "{$unique} AND {$agentScope} AND NULLIF(LTRIM(RTRIM(c.Agent)), '') IS NOT NULL AND c.Agent NOT LIKE '% User'"),
            'drop_name' => self::step('', $from, 'e', ['Drop_Name' => 'c.Campaign'],
                "{$unique} AND COALESCE(c.Campaign, '') <> ''"),
        ];
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
