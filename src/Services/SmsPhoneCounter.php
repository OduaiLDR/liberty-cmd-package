<?php

namespace Cmd\Reports\Services;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Validation\ValidationException;

/**
 * Counts eligible SMS phones without building CSV rows or looking up debt per lead.
 * SMS sends to one number per lead (its TU cell append in TblMailersUniqueEnriched),
 * so a lead counts once when it has a valid phone and none of its numbers is a contact.
 * TblMailersUniqueEnriched2 is an older five-slot source, not all cell numbers; SMS ignores it.
 */
class SmsPhoneCounter
{
    public function count(ConnectionInterface $connection, string $dropName): int
    {
        return $this->countMany($connection, [$dropName])[$dropName];
    }

    /** @param array<int, string> $dropNames @return array<string, int> */
    public function countMany(ConnectionInterface $connection, array $dropNames): array
    {
        $result = [];
        foreach ($this->details($connection, $dropNames) as $name => $details) {
            $result[$name] = $this->validatedCount($name, $details);
        }

        return $result;
    }

    public function validatedCount(string $dropName, array $details): int
    {
        if ($details['missing_identity']) {
            throw ValidationException::withMessages(['target' => "{$dropName} contains a lead without an External_ID. Correct the source before exporting."]);
        }

        return $details['count'];
    }

    /** Counts ahead without rejecting an invalid drop that the target will not use. */
    public function details(ConnectionInterface $connection, array $dropNames): array
    {
        $result = array_fill_keys($dropNames, ['count' => 0, 'missing_identity' => false]);
        foreach (array_chunk(array_values(array_unique($dropNames)), 1) as $names) {
            // Group/sort by a compact ordinal, not a 4,000-character drop key.
            // Keep the original name intact only for the indexed source joins.
            $marks = implode(',', array_map(static fn (int $index): string =>
                '('.$index.', CONVERT(nvarchar(4000), ?))', array_keys($names)));
            $filters = implode(',', array_fill(0, count($names), '?'));
            $sources = 'SELECT d.Drop_Key AS Drop_Key, e.External_ID, e.Phone FROM TblMailersUniqueEnriched e WITH (FORCESEEK) '
                .'JOIN RequestedDrops d ON e.Drop_Name = d.Drop_Name WHERE e.Drop_Name IN ('.$filters.')';
            $pdo = $connection->getPdo();
            $attribute = defined('PDO::SQLSRV_ATTR_QUERY_TIMEOUT') ? constant('PDO::SQLSRV_ATTR_QUERY_TIMEOUT') : null;
            $previousTimeout = $attribute === null ? null : $pdo->getAttribute($attribute);
            // An individual historical drop can be large. Keep its query limit
            // below the 7,000-second queue job and the queue retry reservation.
            if ($attribute !== null) $pdo->setAttribute($attribute, 1800);
            try {
                $rows = $connection->select($this->aggregateSql($sources, null, "RequestedDrops AS (SELECT Drop_Key, Drop_Name FROM (VALUES {$marks}) requested(Drop_Key, Drop_Name)),", '50')."\nOPTION (RECOMPILE)", array_merge($names, $names));
            } finally {
                if ($attribute !== null) $pdo->setAttribute($attribute, $previousTimeout);
            }
            foreach ($rows as $row) {
                $name = $names[(int) $row->Drop_Key] ?? null;
                if ($name === null || ! array_key_exists($name, $result)) throw new \RuntimeException('The counted source drop could not be matched to its selection.');
                $result[$name] = ['count' => (int) $row->Eligible_Phones, 'missing_identity' => (int) $row->Missing_Identity > 0];
            }
        }

        return $result;
    }

    /** Trusted SQL fragments only; overrides allow SELECT-only SQL Server fixtures. */
    public function sql(?string $sources = null, ?string $contacts = null): string
    {
        if ($sources !== null) {
            $sources = "SELECT N'fixture' AS Drop_Key, fixture.* FROM ({$sources}) fixture";
        } else $sources = <<<'SQL'
SELECT Drop_Name AS Drop_Key, External_ID, Phone FROM TblMailersUniqueEnriched WHERE Drop_Name = ?
SQL;

        return $this->aggregateSql($sources, $contacts);
    }

    protected function aggregateSql(string $sources, ?string $contacts = null, string $prefix = '', string $phoneWidth = 'max'): string
    {
        $contacts ??= 'SELECT Phone FROM TblPhoneNumbers';
        $identity = self::identitySql('External_ID');
        // Verified source width: E phone is nvarchar(50).
        // Fixture SQL keeps arbitrary-length phone values for normalization tests.

        return <<<SQL
WITH {$prefix} RawPhones AS ({$sources}),
CleanPhones AS (
    SELECT Drop_Key, {$identity} AS Lead_ID,
        REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(CONVERT(nvarchar({$phoneWidth}), Phone),
        N' ', N''), N'-', N''), N'(', N''), N')', N''), N'+', N''), N'.', N'') AS Digits
    FROM RawPhones
),
CountryPhones AS (
    SELECT Drop_Key, Lead_ID, CASE WHEN LEN(Digits) = 11 AND LEFT(Digits, 1) = N'1'
        THEN SUBSTRING(Digits, 2, 10) ELSE Digits END AS Digits FROM CleanPhones
),
DistinctPhones AS (
    SELECT DISTINCT Drop_Key, Lead_ID, CASE WHEN LEN(Digits) = 10
        AND Digits COLLATE Latin1_General_100_BIN2 NOT LIKE N'%[^0-9]%'
        THEN CONVERT(nvarchar(10), Digits) ELSE NULL END AS Phone
    FROM CountryPhones
),
ContactPhones AS ({$contacts}),
LeadCounts AS (
    SELECT d.Drop_Key, d.Lead_ID, COUNT_BIG(d.Phone) AS Phone_Count,
        MAX(COALESCE(c.Matched, 0)) AS Contacted
    FROM DistinctPhones d
    OUTER APPLY (SELECT TOP (1) 1 AS Matched FROM ContactPhones c
        WHERE c.Phone = d.Phone OR c.Phone = N'1' + d.Phone) c
    GROUP BY d.Drop_Key, d.Lead_ID
)
SELECT Drop_Key, COALESCE(SUM(CASE WHEN Contacted = 0 AND Phone_Count > 0 THEN 1 ELSE 0 END), 0) AS Eligible_Phones,
    COALESCE(MAX(CASE WHEN Lead_ID IS NULL OR Lead_ID = N'' THEN 1 ELSE 0 END), 0) AS Missing_Identity
FROM LeadCounts
GROUP BY Drop_Key
SQL;
    }

    /** Match PHP strtolower(trim()) exactly; column names are trusted code only. */
    public static function identitySql(string $column): string
    {
        // Both source External_ID columns are nvarchar(50); trim/ASCII case
        // conversion cannot expand them. A bounded key avoids multi-GB sort grants.
        return "CONVERT(nvarchar(50), TRANSLATE(TRIM(NCHAR(0) + NCHAR(9) + NCHAR(10) + NCHAR(11) + NCHAR(13) + N' ' FROM {$column} COLLATE Latin1_General_100_BIN2), "
            ."N'ABCDEFGHIJKLMNOPQRSTUVWXYZ', N'abcdefghijklmnopqrstuvwxyz')) COLLATE Latin1_General_100_BIN2";
    }
}
