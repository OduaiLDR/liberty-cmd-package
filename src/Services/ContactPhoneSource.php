<?php

namespace Cmd\Reports\Services;

use DateTimeImmutable;
use RuntimeException;

/** Reads a contact-owned snapshot; never writes to the destination database. */
class ContactPhoneSource
{
    public const SOURCES = ['ldr' => 'DP_LDR', 'plaw' => 'DP_PLAW', 'lt' => 'DP_LT'];

    public static function sources(string $selection): array
    {
        $selection = strtolower(trim($selection));
        if ($selection === 'all') {
            return self::SOURCES;
        }
        if (! isset(self::SOURCES[$selection])) {
            throw new RuntimeException('--source must be all, LDR, PLAW or LT.');
        }

        return [$selection => self::SOURCES[$selection]];
    }

    public function summary(DBConnector $source, ?string $watermark = null): array
    {
        $where = 'WHERE (c._FIVETRAN_DELETED = FALSE OR c._FIVETRAN_DELETED IS NULL)';
        if ($watermark !== null) {
            $where .= ' AND c.ID <= '.$this->contactId($watermark);
        }
        $sql = <<<SQL
WITH source_phones AS (
    SELECT c.ID AS CID, c.CREATED AS CONTACT_CREATED,
           REGEXP_REPLACE(phone_values.VALUE::STRING, '[^0-9]', '') AS DIGITS
    FROM CONTACTS c,
         LATERAL FLATTEN(INPUT => ARRAY_CONSTRUCT_COMPACT(c.PHONE,c.PHONE2,c.PHONE3,c.PHONE4)) phone_values
    {$where}
)
SELECT COUNT(*) AS SOURCE_ROWS,
       COALESCE(SUM(IFF(DIGITS <> '',1,0)),0) AS NORMALIZED_ROWS,
       COUNT(DISTINCT IFF(DIGITS <> '',CID,NULL)) AS CONTACTS,
       COALESCE(SUM(IFF(CID IS NULL OR CID <= 0 OR CID <> TRUNC(CID),1,0)),0) AS INVALID_IDS,
       COALESCE(SUM(IFF(DIGITS <> '' AND CONTACT_CREATED IS NULL,1,0)),0) AS MISSING_CREATED,
       MAX(CID) AS MAX_CID
FROM source_phones
SQL;
        $rows = $source->queryReadOnly($sql, [], 180)['data'] ?? null;
        if (! is_array($rows) || count($rows) !== 1) {
            throw new RuntimeException('Phone source summary is incomplete.');
        }
        $row = $rows[0];
        foreach (['SOURCE_ROWS', 'NORMALIZED_ROWS', 'CONTACTS', 'INVALID_IDS', 'MISSING_CREATED'] as $column) {
            if (! isset($row[$column]) || ! ctype_digit((string) $row[$column])) {
                throw new RuntimeException('Phone source summary has an invalid '.$column.'.');
            }
            $row[$column] = (int) $row[$column];
        }
        if ($row['INVALID_IDS'] || $row['MISSING_CREATED']) {
            throw new RuntimeException('Phone source has '.$row['INVALID_IDS'].' invalid contact IDs and '.$row['MISSING_CREATED'].' missing contact creation dates; replacement refused.');
        }
        if ($row['NORMALIZED_ROWS'] < 1 || $row['CONTACTS'] < 1) {
            throw new RuntimeException('Phone source is empty; existing rows will be preserved.');
        }
        $row['MAX_CID'] = $this->contactId($row['MAX_CID'] ?? null);

        return $row;
    }

    public function normalize(array $row): ?array
    {
        $digits = preg_replace('/\D/', '', (string) ($row['PHONE'] ?? ''));
        if ($digits === '') {
            return null;
        }
        $id = $this->contactId($row['ID'] ?? null);
        $created = (string) ($row['CREATED_DATE'] ?? '');
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $created);
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $created) || ! $parsed || $parsed->format('Y-m-d') !== $created) {
            throw new RuntimeException('Phone source has an invalid contact creation date; replacement refused.');
        }

        return ['phone' => str_pad($digits, 10, '0', STR_PAD_LEFT), 'cid' => $id, 'created' => $created];
    }

    public function contactId(mixed $id): string
    {
        // Do not cast IDs to PHP integers/floats; the destination is nvarchar(50).
        if ((! is_int($id) && ! is_string($id)) || ! preg_match('/^[1-9][0-9]{0,49}$/D', (string) $id)) {
            throw new RuntimeException('Phone source has an invalid contact ID; replacement refused.');
        }

        return (string) $id;
    }

    /** A fixed high-water mark and keyset avoid OFFSET skips as new contacts arrive. */
    public function stage(DBConnector $source): array
    {
        $expected = $this->summary($source);
        $path = tempnam(sys_get_temp_dir(), 'contact-phones-');
        if ($path === false) {
            throw new RuntimeException('Unable to allocate phone staging file.');
        }
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            unlink($path);
            throw new RuntimeException('Unable to open phone staging file.');
        }
        $lastId = null;
        $lastSlot = -1;
        $raw = 0;
        $normalized = 0;
        $contacts = 0;
        $lastContact = null;
        try {
            do {
                $cursor = $lastId === null ? '' : "AND (c.ID > {$lastId} OR (c.ID = {$lastId} AND phone_values.INDEX > {$lastSlot}))";
                $sql = <<<SQL
SELECT c.ID, phone_values.INDEX AS PHONE_SLOT, phone_values.VALUE::STRING AS PHONE,
       TO_CHAR(CONVERT_TIMEZONE('America/Los_Angeles',c.CREATED)::DATE,'YYYY-MM-DD') AS CREATED_DATE
FROM CONTACTS c,
     LATERAL FLATTEN(INPUT => ARRAY_CONSTRUCT_COMPACT(c.PHONE,c.PHONE2,c.PHONE3,c.PHONE4)) phone_values
WHERE (c._FIVETRAN_DELETED = FALSE OR c._FIVETRAN_DELETED IS NULL)
  AND c.ID <= {$expected['MAX_CID']} {$cursor}
ORDER BY c.ID,phone_values.INDEX
LIMIT 5000
SQL;
                $rows = $source->queryReadOnly($sql, [], 180)['data'] ?? null;
                if (! is_array($rows)) {
                    throw new RuntimeException('Phone source page is incomplete.');
                }
                foreach ($rows as $row) {
                    $id = $this->contactId($row['ID'] ?? null);
                    $slot = $row['PHONE_SLOT'] ?? null;
                    if (! is_numeric($slot) || (string) (int) $slot !== (string) $slot || (int) $slot < 0 || (int) $slot > 3) {
                        throw new RuntimeException('Invalid phone source cursor.');
                    }
                    $comparison = $lastId === null ? 1 : (strlen($id) <=> strlen($lastId) ?: strcmp($id, $lastId));
                    if ($lastId !== null && ($comparison < 0 || ($comparison === 0 && (int) $slot <= $lastSlot))) {
                        throw new RuntimeException('Repeated or unordered contact phone cursor; replacement refused.');
                    }
                    $lastId = $id;
                    $lastSlot = (int) $slot;
                    $raw++;
                    $phone = $this->normalize($row);
                    if ($phone === null) {
                        continue;
                    }
                    $line = json_encode($phone, JSON_THROW_ON_ERROR).PHP_EOL;
                    if (fwrite($handle, $line) !== strlen($line)) {
                        throw new RuntimeException('Phone staging file is incomplete.');
                    }
                    $normalized++;
                    if ($id !== $lastContact) {
                        $contacts++;
                        $lastContact = $id;
                    }
                }
            } while (count($rows) === 5000);
            if (! fflush($handle)) {
                throw new RuntimeException('Unable to flush phone staging file.');
            }
            $after = $this->summary($source, $expected['MAX_CID']);
            foreach (['SOURCE_ROWS' => $raw, 'NORMALIZED_ROWS' => $normalized, 'CONTACTS' => $contacts] as $key => $count) {
                if ($count !== $expected[$key] || $after[$key] !== $expected[$key]) {
                    throw new RuntimeException('Phone source changed or pagination is incomplete; existing destination rows will be preserved.');
                }
            }
            fclose($handle);
            $handle = null;

            return ['path' => $path, 'sha256' => hash_file('sha256', $path), 'rows' => $normalized, 'source_rows' => $raw, 'contacts' => $contacts];
        } catch (\Throwable $error) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            if (is_file($path)) {
                unlink($path);
            }
            throw $error;
        }
    }
}
