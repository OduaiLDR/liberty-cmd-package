<?php

declare(strict_types=1);

namespace Cmd\Reports\Console\Commands\GenerateMissingAssignmentsReport;

use Cmd\Reports\Services\ContactSyncIdentity;
use Cmd\Reports\Services\DBConnector;

final class ReportData
{
    /** @return list<array{llg_id:string,lending_tower_id:string,client:string,status:string,created_date:string}> */
    public function fetch(DBConnector $connector, string $start, string $endExclusive): array
    {
        $contacts = $this->queryRows($connector, <<<'SQL'
SELECT LLG_ID, Client, Status,
       CONVERT(char(10), CAST(Created_Date AS date), 23) AS Created_Date,
       Email, Phone
FROM TblContacts
WHERE Agent = ''
  AND Created_Date >= ?
  AND Created_Date < ?
  AND Stage = 'Client'
  AND Status <> 'Funded'
ORDER BY CAST(Created_Date AS date) ASC, Client ASC
SQL, [$start, $endExclusive]);

        $ids = array_values(array_unique(array_column($contacts, 'llg_id')));
        $owners = [];
        foreach (array_chunk($ids, 400) as $chunk) {
            $marks = implode(', ', array_fill(0, count($chunk), '?'));
            $sources = $this->queryRows($connector, "
SELECT LLG_ID, External_ID, Client, Email, Phone
FROM TblContactsLDR WHERE LLG_ID IN ({$marks})
UNION ALL
SELECT LLG_ID, External_ID, Client, Email, Phone
FROM TblContactsPLAW WHERE LLG_ID IN ({$marks})
", array_merge($chunk, $chunk));

            // Keep every physical owner: duplicate/cross-company IDs are ambiguous.
            foreach ($sources as $source) {
                $owners[(string) $source['llg_id']][] = $source;
            }
        }

        $rows = [];
        foreach ($contacts as $contact) {
            $llgId = (string) $contact['llg_id'];
            $candidates = $owners[$llgId] ?? [];
            $towerId = '';
            if (count($candidates) === 1) {
                $candidate = $candidates[0];
                $externalId = trim((string) ($candidate['external_id'] ?? ''));
                if (ContactSyncIdentity::validNativeId($externalId)
                    && ContactSyncIdentity::matches($contact, $candidate)) {
                    $towerId = $externalId;
                }
            }

            // An unresolved ID must never remove a client from the assignment list.
            $rows[] = [
                'llg_id' => $llgId,
                'lending_tower_id' => $towerId,
                'client' => (string) $contact['client'],
                'status' => (string) $contact['status'],
                'created_date' => (string) $contact['created_date'],
            ];
        }

        return $rows;
    }

    /** @return list<array<string,mixed>> */
    private function queryRows(DBConnector $connector, string $sql, array $params): array
    {
        $result = $connector->querySqlServer($sql, $params);
        if (($result['success'] ?? false) !== true || !is_array($result['data'] ?? null)) {
            throw new \RuntimeException('Missing Assignments query failed: '.($result['error'] ?? 'invalid SQL Server result'));
        }

        return array_map(static fn (array $row): array => array_change_key_case($row, CASE_LOWER), $result['data']);
    }
}
