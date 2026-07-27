<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

use UnexpectedValueException;

/**
 * Reads and classifies contacts for the dropped-status sync.
 * This service never writes to Snowflake or SQL Server.
 */
final class DroppedStatusSyncService
{
    public const TARGET_STATUS = 'Dropped / Cancelled';

    /** @var list<array{env:string, source:string, company:string}> */
    private const PORTALS = [
        ['env' => 'ldr', 'source' => 'LDR', 'company' => 'LDR'],
        ['env' => 'plaw', 'source' => 'PLAW', 'company' => 'PLAW'],
    ];

    /**
     * @return list<array{env:string, source:string, company:string}>
     */
    public function portals(string $source = 'ALL'): array
    {
        $source = strtoupper(trim($source));

        if ($source === '' || $source === 'ALL') {
            return self::PORTALS;
        }

        foreach (self::PORTALS as $portal) {
            if ($portal['source'] === $source) {
                return [$portal];
            }
        }

        throw new \InvalidArgumentException("Unknown source '{$source}'. Use ALL, LDR, or PLAW.");
    }

    /**
     * Fetch every dropped contact and classify it for a preview or live run.
     *
     * @return array{
     *   scanned_count:int,
     *   candidate_count:int,
     *   selected_count:int,
     *   skipped_dropped_count:int,
     *   skipped_system_cancel_count:int,
     *   skipped_missing_status_count:int,
     *   skipped_invalid_id_count:int,
     *   duplicate_count:int,
     *   limit:?int,
     *   candidates:list<array{contact_id:string,client:string,enrolled_date:string,dropped_date:string,current_status:string,target_status:string,action:string}>,
     *   skipped:list<array{contact_id:string,client:string,enrolled_date:string,dropped_date:string,current_status:string,skip_reason:string}>
     * }
     */
    public function buildReport(DBConnector $snowflake, ?int $limit = null): array
    {
        if ($limit !== null && $limit < 1) {
            throw new \InvalidArgumentException('The limit must be a positive integer.');
        }

        $rows = $this->fetchDroppedContacts($snowflake);
        $seen = [];
        $candidates = [];
        $skipped = [];
        $duplicates = 0;
        $skippedDropped = 0;
        $skippedSystemCancel = 0;
        $skippedMissingStatus = 0;
        $skippedInvalidId = 0;

        foreach ($rows as $row) {
            $contactId = trim($this->value($row, 'ID'));
            if ($contactId === '') {
                $skippedInvalidId++;
                $skipped[] = [
                    'contact_id' => '',
                    'client' => $this->value($row, 'CLIENT'),
                    'enrolled_date' => $this->value($row, 'ENROLLED_DATE'),
                    'dropped_date' => $this->value($row, 'DROPPED_DATE'),
                    'current_status' => $this->value($row, 'STATUS'),
                    'skip_reason' => 'missing_contact_id',
                ];
                continue;
            }

            if (isset($seen[$contactId])) {
                $duplicates++;
                continue;
            }
            $seen[$contactId] = true;

            $client = [
                'contact_id' => $contactId,
                'client' => $this->value($row, 'CLIENT'),
                'enrolled_date' => $this->value($row, 'ENROLLED_DATE'),
                'dropped_date' => $this->value($row, 'DROPPED_DATE'),
                'current_status' => $this->value($row, 'STATUS'),
            ];
            $status = $client['current_status'];

            if ($status === '') {
                $skippedMissingStatus++;
                $skipped[] = $client + ['skip_reason' => 'missing_current_status'];
                continue;
            }

            if (stripos($status, 'dropped') !== false) {
                $skippedDropped++;
                $skipped[] = $client + ['skip_reason' => 'already_dropped_status'];
                continue;
            }

            if (stripos($status, 'system cancel') === 0) {
                $skippedSystemCancel++;
                $skipped[] = $client + ['skip_reason' => 'already_system_cancel_status'];
                continue;
            }

            $candidates[] = $client + [
                'target_status' => self::TARGET_STATUS,
                'action' => 'update_client_status',
            ];
        }

        $selected = $limit === null ? $candidates : array_slice($candidates, 0, $limit);

        return [
            'scanned_count' => count($rows),
            'candidate_count' => count($candidates),
            'selected_count' => count($selected),
            'skipped_dropped_count' => $skippedDropped,
            'skipped_system_cancel_count' => $skippedSystemCancel,
            'skipped_missing_status_count' => $skippedMissingStatus,
            'skipped_invalid_id_count' => $skippedInvalidId,
            'duplicate_count' => $duplicates,
            'limit' => $limit,
            'candidates' => array_values($selected),
            'skipped' => array_values($skipped),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function fetchDroppedContacts(DBConnector $snowflake): array
    {
        $sql = <<<'SQL'
SELECT
    c.ID,
    CONCAT(c.FIRSTNAME, ' ', c.LASTNAME) AS CLIENT,
    TO_VARCHAR(c.ENROLLED_DATE::date, 'YYYY-MM-DD') AS ENROLLED_DATE,
    TO_VARCHAR(c.DROPPED_DATE::date, 'YYYY-MM-DD') AS DROPPED_DATE,
    cls.TITLE AS STATUS
FROM CONTACTS AS c
LEFT JOIN CONTACTS_LEAD_STATUS AS cls ON c.LEADSTATUS = cls.ID
WHERE c.DROPPED_DATE IS NOT NULL
ORDER BY c.DROPPED_DATE ASC, c.ID ASC
SQL;

        $result = $snowflake->query($sql);
        if (($result['success'] ?? true) === false) {
            throw new \RuntimeException(
                'Snowflake dropped-status query failed: '.((string) ($result['error'] ?? 'unknown error'))
            );
        }

        $data = $result['data'] ?? null;
        if (! is_array($data)) {
            throw new UnexpectedValueException('Snowflake returned an invalid dropped-status result.');
        }

        return $data;
    }

    private function value(array $row, string $key): string
    {
        foreach ($row as $rowKey => $value) {
            if (strcasecmp((string) $rowKey, $key) === 0) {
                return trim((string) ($value ?? ''));
            }
        }

        return '';
    }
}
