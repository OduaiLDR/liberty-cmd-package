<?php

namespace Cmd\Reports\Services;

/** Bounded SELECTs: a backend LT reference is never treated as a mailer suffix. */
final class ContactSyncCampaignLookup
{
    public static function collect(DBConnector $sql, array $rows, string $source, ?DBConnector $lt = null, ?callable $identityQuery = null): array
    {
        $contacts = [];
        if ($source !== 'LT') {
            $ids = array_values(array_unique(array_filter(array_map(
                fn ($row) => trim((string) ($row['EXTERNAL_ID'] ?? '')), $rows
            ), [ContactSyncIdentity::class, 'validNativeId'])));
            foreach (array_chunk($ids, 500) as $batch) {
                if ($lt === null) throw new \RuntimeException('LT campaign evidence connector is required.');
                $result = $lt->query("SELECT ID, TP_ID AS EXTERNAL_ID,
                    TRIM(CONCAT(COALESCE(FIRSTNAME, ''), ' ', COALESCE(LASTNAME, ''))) AS FULLNAME,
                    EMAIL, PHONE3 AS CELL_PHONE, ADDRESS AS ADDRESS1, CITY, STATE, ZIP
                    FROM CONTACTS WHERE ID IN (" . implode(',', $batch) . ")
                    AND (_FIVETRAN_DELETED = FALSE OR _FIVETRAN_DELETED IS NULL) AND DEL=FALSE AND COALESCE(ISCOAPP,0)=0");
                if (($result['success'] ?? true) !== true || !is_array($result['data'] ?? null)
                    || !empty($result['error']) || !empty($result['truncated'])
                    || (isset($result['rowCount']) && (int) $result['rowCount'] !== count($result['data']))) {
                    throw new \RuntimeException('Incomplete LT campaign evidence query.');
                }
                foreach ($result['data'] ?? [] as $contact) $contacts[(string) $contact['ID']][] = $contact;
            }
        }

        $identityRequests = [];
        if ($source !== 'LT' && $identityQuery !== null) {
            $referenceCounts = array_count_values(array_map(fn ($row) => trim((string) ($row['EXTERNAL_ID'] ?? '')), $rows));
            foreach ($rows as $row) {
                $reference = trim((string) ($row['EXTERNAL_ID'] ?? ''));
                $id = (string) ($row['LLG_ID'] ?? '');
                $candidates = $contacts[$reference] ?? [];
                if (!ContactSyncIdentity::validNativeId($id) || count($candidates) !== 1
                    || ($referenceCounts[$reference] ?? 0) !== 1
                    || ContactSyncIdentity::corroborates(self::identity($row), self::identity($candidates[0]))) {
                    continue;
                }
                // Bind both fresh source snapshots and check all active backend
                // claims. Equality of a reference alone never proves the person.
                $identityRequests[$id] = ['incoming' => self::sourceSnapshot($candidates[0]),
                    'backend' => self::sourceSnapshot($row) + ['_source' => $source]];
            }
        }
        $identityEvidence = $identityRequests === [] ? null
            : ContactSyncSourceEvidence::collect(array_values($identityRequests), $identityQuery);

        $recipients = $proofs = $keys = [];
        foreach ($rows as $row) {
            $id = (string) ($row['LLG_ID'] ?? '');
            $reference = trim((string) ($row['EXTERNAL_ID'] ?? ''));
            $recipient = $row;
            if ($source !== 'LT' && isset($contacts[$reference])) {
                $candidates = $contacts[$reference];
                $request = $identityRequests[$id] ?? null;
                $sourceVerified = $request !== null && $identityEvidence->supports($request['incoming'], $request['backend']);
                if (count($candidates) !== 1 || (!ContactSyncIdentity::corroborates(
                    self::identity($row), self::identity($candidates[0])
                ) && !$sourceVerified)) {
                    $proofs[$id] = ['status' => 'unresolved', 'campaign' => '', 'external_id' => '',
                        'reason' => 'LT recipient identity did not verify for this backend reference.'];
                    continue;
                }
                $recipient = $candidates[0];
            }
            $key = trim((string) ($recipient['EXTERNAL_ID'] ?? ''));
            $recipients[$id] = [$recipient, $reference];
            if ($key !== '' && !in_array($key, ['0', '1234567840', 'UNKNOWN'], true)) $keys[$key] = true;
        }

        $mailers = [];
        foreach (array_chunk(array_keys($keys), 500) as $batch) {
            $result = $sql->querySqlServer('SELECT External_ID, Drop_Name, Client, Address, City, State, Zip
                FROM TblMailers WHERE External_ID IN (' . implode(',', array_fill(0, count($batch), '?')) . ')', $batch);
            if (!($result['success'] ?? false)) throw new \RuntimeException('Mailer evidence query failed.');
            foreach ($result['data'] ?? [] as $mailer) $mailers[(string) $mailer['External_ID']][] = $mailer;
        }
        foreach ($recipients as $id => [$recipient, $reference]) {
            $key = trim((string) ($recipient['EXTERNAL_ID'] ?? ''));
            $proofs[$id] = ContactSyncCampaign::resolve($recipient, $mailers[$key] ?? []);
            if ($source !== 'LT') $proofs[$id]['source_reference'] = $reference;
        }
        return $proofs;
    }

    private static function identity(array $row): array
    {
        return ['client' => $row['FULLNAME'] ?? '', 'email' => $row['EMAIL'] ?? '',
            'phone' => $row['CELL_PHONE'] ?? ''];
    }

    private static function sourceSnapshot(array $row): array
    {
        $external = trim((string) ($row['EXTERNAL_ID'] ?? ''));
        return ['llg_id' => 'LLG-' . (string) ($row['LLG_ID'] ?? $row['ID'] ?? ''),
            'external_id' => in_array($external, ['0', '1234567840', 'UNKNOWN'], true) ? '' : substr($external, 0, 50),
            'client' => substr((string) ($row['FULLNAME'] ?? ''), 0, 255), 'email' => $row['EMAIL'] ?? '',
            'phone' => substr(preg_replace('/[^0-9]/', '', (string) ($row['CELL_PHONE'] ?? '')), 0, 50)];
    }
}
