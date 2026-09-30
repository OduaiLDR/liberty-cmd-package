<?php

namespace Cmd\Reports\Console\Commands\GenerateLendingTowerInvoices;

use DateTimeImmutable;
use InvalidArgumentException;

final class ProgressLawLeadQuery
{
    /** These status IDs belong to Lending Tower, the lead source billed to Progress Law. */
    public const SOURCE = 'lt';

    public const STATUSES = [
        293539 => 'Contract Sent',
        293533 => 'Rejected (Not Interested DS)',
        293527 => 'Rejected (Partial DS)',
        293531 => 'Rejected (Pitched DS)',
        293285 => 'Submitted',
    ];

    /**
     * Rank the entire qualifying history BEFORE filtering the billing period. STAMP is
     * TIMESTAMP_TZ in LT Snowflake; compare LA wall-clock timestamps with NTZ bounds.
     * CONTACTS.LEADSTATUS is the current status at query time, independent of billing history.
     */
    public static function sql(string $start, string $end, array $states): string
    {
        foreach ([$start, $end] as $date) {
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if (! $parsed || $parsed->format('Y-m-d') !== $date) {
                throw new InvalidArgumentException('Invoice dates must be valid YYYY-MM-DD dates.');
            }
        }
        if ($start >= $end || $states === [] || array_diff($states, array_keys(UsStateClassification::STATES)) !== []) {
            throw new InvalidArgumentException('A valid billing period and US state list are required.');
        }
        $stateSql = "'" . implode("', '", array_unique($states)) . "'";
        $statusSql = implode(', ', array_keys(self::STATUSES));
        $statusLabels = 'CASE s.STATUS_ID';
        foreach (self::STATUSES as $id => $title) {
            $statusLabels .= " WHEN {$id} THEN '{$title}'";
        }
        $statusLabels .= ' END';

        return "
            WITH eligible_contacts AS (
                SELECT c.ID, c.FIRSTNAME, c.LASTNAME, c.STATE, c.LEADSTATUS
                FROM CONTACTS AS c
                WHERE c.STATE IN ({$stateSql})
                  AND c.DEL = FALSE
                  AND c.ISCOAPP = 0
            ),
            qualifying_history AS (
                SELECT s.CONTACT_ID,
                       COALESCE(cls.TITLE, {$statusLabels}) AS BILLABLE_STATUS,
                       CONVERT_TIMEZONE('America/Los_Angeles', s.STAMP)::TIMESTAMP_NTZ AS STATUS_LOCAL,
                       ROW_NUMBER() OVER (PARTITION BY s.CONTACT_ID ORDER BY s.STAMP ASC, s.ID ASC) AS STATUS_RANK
                FROM CONTACTS_STATUS AS s
                JOIN eligible_contacts AS c ON c.ID = s.CONTACT_ID
                LEFT JOIN CONTACTS_LEAD_STATUS AS cls ON cls.ID = s.STATUS_ID
                WHERE s.STATUS_ID IN ({$statusSql})
                  AND s.STAMP IS NOT NULL
            )
            SELECT c.ID AS CONTACT_ID,
                   TRIM(CONCAT(COALESCE(c.FIRSTNAME, ''), ' ', COALESCE(c.LASTNAME, ''))) AS CLIENT,
                   c.STATE,
                   TO_CHAR(h.STATUS_LOCAL, 'YYYY-MM-DD HH24:MI:SS') AS STATUS_LOCAL,
                   h.BILLABLE_STATUS,
                   current_status.TITLE AS CURRENT_STATUS
            FROM eligible_contacts AS c
            JOIN qualifying_history AS h ON h.CONTACT_ID = c.ID AND h.STATUS_RANK = 1
            LEFT JOIN CONTACTS_LEAD_STATUS AS current_status ON current_status.ID = c.LEADSTATUS
            WHERE h.STATUS_LOCAL >= '{$start}'::TIMESTAMP_NTZ
              AND h.STATUS_LOCAL < '{$end}'::TIMESTAMP_NTZ
            ORDER BY h.STATUS_LOCAL, c.ID
        ";
    }
}
