<?php

namespace Cmd\Reports\Repositories;

use Cmd\Reports\Services\SmsWorkflowLock;
use Cmd\Reports\Services\SmsAvailabilityRefresh;
use InvalidArgumentException;

/**
 * Fills TblProspects from TblMailersUniqueEnriched (Jacob, 14 Oct 2026), one drop at a time, newest
 * drop first, so a person's newest data wins. A person is Client + Address + 5-digit Zip; only people
 * with a phone or an email are added, and someone already in TblProspects is not added again (an older
 * row can still fill a missing phone or email). Safe to stop and run again. SQL Server only.
 *
 * Earlier SMS sends count as texted, so the next file starts with people who have not had one: the
 * verified phone send ledger while loading, and markTexted() for the phones in a sent file.
 */
class SmsProspectLoadRepository extends SqlSrvRepository
{
    /** Seconds one drop's query may run (cmd-runner's default limit is 300). */
    public const DROP_QUERY_TIMEOUT = 1800;

    /**
     * The drop's people, cleaned: phone as 10 digits, 5-digit zip, sizes of TblProspects.
     * No index hints: forcing seeks made production read a whole drop once per person (15 Oct).
     */
    private const SOURCE_SQL = <<<'SQL'
WITH Source AS (
    SELECT e.PK,
        CAST(LEFT(LTRIM(RTRIM(e.Client)), 150) AS varchar(150)) AS Client,
        CAST(LEFT(LTRIM(RTRIM(e.Address)), 200) AS varchar(200)) AS Address,
        CAST(LEFT(NULLIF(LTRIM(RTRIM(e.City)), ''), 100) AS varchar(100)) AS City,
        CASE WHEN LEN(LTRIM(RTRIM(e.State))) = 2 THEN CAST(UPPER(LTRIM(RTRIM(e.State))) AS char(2)) END AS State,
        CAST(COALESCE(NULLIF(LTRIM(RTRIM(e.Zip5_Normalized)), ''), LEFT(LTRIM(e.Zip), 5)) AS varchar(10)) AS Zip,
        ph.Phone,
        CASE WHEN LTRIM(RTRIM(e.Email)) LIKE '_%@_%._%' THEN CAST(LEFT(LTRIM(RTRIM(e.Email)), 254) AS varchar(254)) END AS Email
    FROM TblMailersUniqueEnriched e
    CROSS APPLY (SELECT REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(LTRIM(RTRIM(e.Phone)),
        '(', ''), ')', ''), '-', ''), ' ', ''), '.', ''), '+', '') AS Digits) d
    CROSS APPLY (SELECT CASE WHEN LEN(d.Digits) = 11 AND LEFT(d.Digits, 1) = '1' THEN SUBSTRING(d.Digits, 2, 10) ELSE d.Digits END AS Digits) d10
    CROSS APPLY (SELECT CASE WHEN LEN(d10.Digits) = 10 AND d10.Digits NOT LIKE '%[^0-9]%' THEN CAST(d10.Digits AS varchar(10)) END AS Phone) ph
    WHERE e.Drop_Name = ?
),
People AS (
    SELECT s.*, ROW_NUMBER() OVER (PARTITION BY s.Client, s.Address, s.Zip
        ORDER BY CASE WHEN s.Phone IS NULL THEN 1 ELSE 0 END, s.PK DESC) AS Person_Rank
    FROM Source s
    WHERE s.Client <> '' AND s.Address <> '' AND s.Zip LIKE '[0-9][0-9][0-9][0-9][0-9]'
      AND (s.Phone IS NOT NULL OR s.Email IS NOT NULL)
)
SQL;

    /**
     * Enriched drops, newest send date first (TblMarketing.Send_Date); drops without one come last.
     *
     * @param  array<int, string>  $only
     * @return array<int, object{Drop_Name:string, Send_Date:?string, Imported:?int}>
     */
    public function drops(array $only = []): array
    {
        $query = $this->table('TblEnrichedDropNames', 'd')
            ->leftJoin('TblMarketing as m', 'm.Drop_Name', '=', 'd.Drop_Name')
            ->groupBy('d.Drop_Name')
            ->selectRaw('d.Drop_Name, CONVERT(varchar(10), MAX(m.Send_Date), 23) AS Send_Date, MAX(d.Imported) AS Imported')
            ->orderByRaw('CASE WHEN MAX(m.Send_Date) IS NULL THEN 1 ELSE 0 END, MAX(m.Send_Date) DESC, d.Drop_Name');
        if ($only !== []) $query->whereIn('d.Drop_Name', $only);

        return $query->get()->all();
    }

    /**
     * Adds the drop's new people and fills a missing phone or email on people already loaded.
     * With $dryRun nothing is written; it counts the drop's people and those not in TblProspects yet.
     * The real run skips the people count to save a pass over the drop.
     *
     * @return array{people:?int, added:int, filled:int}
     */
    public function loadDrop(string $dropName, ?string $sendDate, bool $dryRun = false, bool $fill = true): array
    {
        // A large or busy drop can take longer than the default 5-minute query limit.
        return SmsWorkflowLock::run('prospect-admission', fn () => $this->withQueryTimeout(self::DROP_QUERY_TIMEOUT, fn (): array => $this->loadDropNow($dropName, $sendDate, $dryRun, $fill)));
    }

    /** @return array{people:?int, added:int, filled:int} */
    private function loadDropNow(string $dropName, ?string $sendDate, bool $dryRun, bool $fill): array
    {
        $db = $this->connection();
        if ($dryRun) {
            $people = (int) $db->selectOne(self::SOURCE_SQL.' SELECT COUNT(*) AS n FROM People WHERE Person_Rank = 1', [$dropName])->n;
            $added = (int) $db->selectOne(self::SOURCE_SQL.' SELECT COUNT(*) AS n FROM People p WHERE p.Person_Rank = 1
                AND NOT EXISTS (SELECT 1 FROM TblProspects q WHERE q.Client = p.Client AND q.Address = p.Address AND q.Zip = p.Zip)', [$dropName])->n;

            return ['people' => $people, 'added' => $added, 'filled' => 0];
        }

        return $db->transaction(function () use ($db, $dropName, $sendDate, $fill): array {
            // Old deployments remain usable until the additive workflow migration is applied.
            $legacy = $db->getSchemaBuilder()->hasTable('TblSMSLegacySends')
                ? "SELECT Send_Date AS Sent FROM TblSMSLegacySends WHERE Phone = p.Phone" : 'SELECT CAST(NULL AS date) AS Sent';
            $added = $db->affectingStatement(self::SOURCE_SQL.<<<SQL

INSERT INTO TblProspects (Creation_Date, Client, Address, City, State, Zip, Phone, Email, Latest_Export_Date, SMS_Attempts)
SELECT ?, p.Client, p.Address, p.City, p.State, p.Zip, p.Phone, p.Email, x.Sent, CASE WHEN x.Sent IS NULL THEN 0 ELSE 1 END
FROM People p
OUTER APPLY (SELECT MAX(d.Sent) AS Sent FROM (
    {$legacy}
) d) x
WHERE p.Person_Rank = 1
  AND NOT EXISTS (SELECT 1 FROM TblProspects q WHERE q.Client = p.Client AND q.Address = p.Address AND q.Zip = p.Zip)
SQL, [$dropName, $sendDate]);
            // The fill reads the drop a second time; --no-fill leaves it for a later run.
            $filled = ! $fill ? 0 : $db->affectingStatement(self::SOURCE_SQL.<<<'SQL'

UPDATE q SET Phone = COALESCE(q.Phone, p.Phone), Email = COALESCE(q.Email, p.Email)
FROM TblProspects q
JOIN People p ON p.Person_Rank = 1 AND q.Client = p.Client AND q.Address = p.Address AND q.Zip = p.Zip
WHERE (q.Phone IS NULL AND p.Phone IS NOT NULL) OR (q.Email IS NULL AND p.Email IS NOT NULL)
SQL, [$dropName]);

            if ($added + $filled > 0) SmsAvailabilityRefresh::afterCommit($db);
            return ['people' => null, 'added' => $added, 'filled' => $filled];
        });
    }

    /** Prospects an SMS run could text: a phone and not a contact (counted on the filtered Phone index). */
    public function prospectsWithPhone(): int
    {
        return (int) $this->connection()->selectOne('SELECT COUNT(*) AS n FROM TblProspects WHERE Contacted = 0 AND Phone IS NOT NULL')->n;
    }

    /**
     * Phones from a sent SMS file count as texted on $sentDate: one more attempt and that
     * Latest_Export_Date, unless the prospect already has that date or a later one (so running it
     * again changes nothing). Contacted prospects are left alone. With $dryRun, counts only.
     *
     * @param  array<int, string>  $phones  10-digit phones
     */
    public function markTexted(array $phones, string $sentDate, bool $dryRun = false): int
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $sentDate)) {
            throw new InvalidArgumentException("Send date must be YYYY-MM-DD, got '{$sentDate}'.");
        }
        $marked = 0;
        foreach (array_chunk(array_values(array_unique($phones)), 1000) as $chunk) {
            foreach ($chunk as $phone) {
                if (! preg_match('/^\d{10}$/D', $phone)) throw new InvalidArgumentException("Not a 10-digit phone: '{$phone}'.");
            }
            // Literal varchar values (checked above) keep the filtered Phone index usable.
            $list = "'".implode("','", $chunk)."'";
            if (! $dryRun && $this->connection()->getSchemaBuilder()->hasTable('TblSMSLegacySends')) {
                // Retain the actual historical phone/date even when its prospect has not been
                // loaded yet. Replaying the original file is idempotent and never records Oct 15.
                $known = $this->table('TblSMSLegacySends')->where('Send_Date', $sentDate)->whereIn('Phone', $chunk)->pluck('Phone')->all();
                $new = array_values(array_diff($chunk, $known));
                foreach (array_chunk($new, 500) as $insert) {
                    if ($insert) {
                        $db = $this->connection();
                        // Each chunk keeps its own commit even if a later insert/update fails.
                        SmsAvailabilityRefresh::transaction($db, function () use ($db, $insert, $sentDate): void {
                            $db->table('TblSMSLegacySends')->insert(array_map(fn ($phone) => ['Phone' => $phone, 'Send_Date' => $sentDate], $insert));
                            SmsAvailabilityRefresh::afterCommit($db);
                        });
                    }
                }
            }
            $where = "WHERE Contacted = 0 AND Phone IN ({$list}) AND (Latest_Export_Date IS NULL OR Latest_Export_Date < ?)";
            if ($dryRun) {
                $marked += (int) $this->connection()->selectOne("SELECT COUNT(*) AS n FROM TblProspects {$where}", [$sentDate])->n;
            } else {
                $db = $this->connection();
                $changed = SmsAvailabilityRefresh::transaction($db, function () use ($db, $where, $sentDate): int {
                    $changed = $db->update("UPDATE TblProspects SET SMS_Attempts = SMS_Attempts + 1, Latest_Export_Date = ? {$where}", [$sentDate, $sentDate]);
                    if ($changed > 0) SmsAvailabilityRefresh::afterCommit($db);
                    return $changed;
                });
                $marked += $changed;
            }
        }

        return $marked;
    }
}
