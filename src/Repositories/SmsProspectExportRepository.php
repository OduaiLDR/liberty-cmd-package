<?php

namespace Cmd\Reports\Repositories;

use Carbon\Carbon;
use Cmd\Reports\Services\SmsProspectValues;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Person-level SMS export (Jacob, Oct 2026). One export run (Request_ID) moves through separate
 * steps, so a failure in one step does not undo the others:
 *
 *   select → lookupDebts → assignDrops → writeCsv → markSent   (discard drops an unsent run)
 *
 * The open run's rows live in TblProspectsExport. Marking the run as sent counts the attempt on
 * TblProspects and moves the rows to TblSMSHistory. Order (Jacob, 14 Oct): never-exported prospects
 * first, newest Creation_Date first; then the oldest Latest_Export_Date. Each phone goes out once
 * per run. SQL Server only.
 */
class SmsProspectExportRepository extends SqlSrvRepository
{
    /** Suppress phones shared by this many addresses (Jacob's shared-number rule). */
    public const SHARED_PHONE_ADDRESSES = 3;

    public const CSV_HEADER = ['First name', 'address', 'debt load', 'phone1', 'phone2', 'phone3', 'phone4', 'phone5', 'send date'];

    /** Business dates (send date, Latest_Export_Date) are California dates. */
    public const TIMEZONE = 'America/Los_Angeles';

    // ASC puts the never-exported (NULL) first.
    private const PRIORITY = 'Latest_Export_Date ASC, CASE WHEN Latest_Export_Date IS NULL THEN Creation_Date END DESC, Creation_Date DESC, PK ASC';

    /**
     * For every query that checks TblPhoneNumbers. Without it SQL Server may pick nested loops that
     * scan all of TblPhoneNumbers once per prospect: 48 s for 240 prospects against 1M contacts on
     * the local clone, 0.5 s with hash joins.
     */
    private const HASH_JOINS = 'OPTION (HASH JOIN)';

    /**
     * Seconds one step's query may run: selecting and pricing ~3M people reads all of TblProspects
     * and TblTUData (52 GB). Below the queue job's 7,200 s; cmd-runner's default limit is 300.
     */
    public const STEP_QUERY_TIMEOUT = 3600;

    /** A contact phone as 10 digits: '12025551234' and '2025551234' are the same number. */
    private const CONTACT_PHONE = "CASE WHEN LEN(Phone) = 11 AND LEFT(Phone, 1) = '1' THEN SUBSTRING(Phone, 2, 10) ELSE Phone END";

    /**
     * Prospects whose phone is now a synced contact (TblPhoneNumbers) get Contacted = 1 and the
     * contact's creation date as Response_Date. Run after each phone sync and before each selection.
     */
    public function markContacted(): int
    {
        $phone = self::CONTACT_PHONE;
        $hashJoins = self::HASH_JOINS;

        return $this->connection()->update(<<<SQL
WITH Contacts AS (
    SELECT {$phone} AS Phone, MIN(Created_Date) AS Created_Date
    FROM TblPhoneNumbers
    WHERE Phone IS NOT NULL
    GROUP BY {$phone}
)
UPDATE p SET Contacted = 1, Response_Date = CASE WHEN p.Response_Date IS NULL OR c.Created_Date < p.Response_Date THEN c.Created_Date ELSE p.Response_Date END
FROM TblProspects p
JOIN Contacts c ON c.Phone = p.Phone
WHERE (p.Contacted = 0 OR p.Response_Date IS NULL OR c.Created_Date < p.Response_Date) AND p.Phone IS NOT NULL
{$hashJoins}
SQL);
    }

    /** @return array{selected:int, target:int, shortfall:int, contacted_flagged:int} */
    public function select(string $requestId, int $target): array
    {
        if ($target < 1) {
            throw ValidationException::withMessages(['target' => 'Enter a positive number of records.']);
        }
        if ($this->hasRows('TblProspectsExport', $requestId) || $this->hasRows('TblSMSHistory', $requestId)) {
            throw ValidationException::withMessages(['request_id' => 'This export run already has its records. Discard it or start a new run.']);
        }
        return $this->withQueryTimeout(self::STEP_QUERY_TIMEOUT, function () use ($requestId, $target): array {
            $flagged = $this->markContacted();
            $priority = self::PRIORITY;
            $hashJoins = self::HASH_JOINS;
            $selected = $this->connection()->affectingStatement($this->eligibleSql().<<<SQL

INSERT INTO TblProspectsExport (Request_ID, Prospect_PK, Phone, Debt_Tier, Client, Address, City, State, Zip, Email)
SELECT TOP (?) ?, PK, Phone, Tier, Client, Address, City, State, Zip, Email
FROM Eligible
WHERE Phone_Rank = 1
ORDER BY {$priority}
{$hashJoins}
SQL, [self::SHARED_PHONE_ADDRESSES, $target, $requestId]);

            return ['selected' => $selected, 'target' => $target, 'shortfall' => max(0, $target - $selected), 'contacted_flagged' => $flagged];
        });
    }

    /**
     * What a new run could take right now, by the same rules as select(). Read-only.
     *
     * @return array{eligible:int, never_texted:int, texted_before:int}
     */
    public function availability(): array
    {
        $row = $this->connection()->selectOne($this->eligibleSql().'
SELECT COUNT(*) AS Eligible, COALESCE(SUM(CASE WHEN Latest_Export_Date IS NULL THEN 1 ELSE 0 END), 0) AS Never_Texted
FROM Eligible WHERE Phone_Rank = 1
'.self::HASH_JOINS, [self::SHARED_PHONE_ADDRESSES]);
        $eligible = (int) ($row->Eligible ?? 0);
        $never = (int) ($row->Never_Texted ?? 0);

        return ['eligible' => $eligible, 'never_texted' => $never, 'texted_before' => $eligible - $never];
    }

    /**
     * Prospects a run may take, one per phone (Phone_Rank = 1 is the phone's best prospect):
     * a 10-digit phone that is not a contact, not on SHARED_PHONE_ADDRESSES or more addresses,
     * not opted out or invalid, and not held by the open run. The contact check repeats the nightly
     * Contacted flag for contacts synced since. One placeholder: SHARED_PHONE_ADDRESSES. End the
     * statement with HASH_JOINS.
     */
    private function eligibleSql(): string
    {
        $priority = self::PRIORITY;
        $phone = self::CONTACT_PHONE;

        return <<<SQL
WITH Contacts AS (
    SELECT {$phone} AS Phone FROM TblPhoneNumbers WHERE Phone IS NOT NULL
),
SharedPhones AS (
    SELECT Phone FROM TblProspects
    WHERE Phone IS NOT NULL
    GROUP BY Phone
    HAVING COUNT(DISTINCT CONCAT(Address, '|', Zip)) >= ?
),
PhoneHistory AS (
    SELECT Phone, MAX(Send_Date) AS Sent FROM TblSMSLegacySends GROUP BY Phone
),
Prospects AS (
    SELECT p.PK, p.Phone, p.Tier, p.Creation_Date, p.Client, p.Address, p.City, p.State, p.Zip, p.Email,
        p.Contacted, p.Invalid_Phone, p.Opt_Out,
        CASE WHEN h.Sent IS NOT NULL AND (p.Latest_Export_Date IS NULL OR h.Sent > p.Latest_Export_Date)
            THEN h.Sent ELSE p.Latest_Export_Date END AS Latest_Export_Date
    FROM TblProspects p LEFT JOIN PhoneHistory h ON h.Phone = p.Phone
),
Eligible AS (
    SELECT p.PK, p.Phone, p.Tier, p.Latest_Export_Date, p.Creation_Date,
        p.Client, p.Address, p.City, p.State, p.Zip, p.Email,
        ROW_NUMBER() OVER (PARTITION BY p.Phone ORDER BY {$priority}) AS Phone_Rank
    FROM Prospects p
    WHERE p.Phone IS NOT NULL AND LEN(p.Phone) = 10 AND p.Phone NOT LIKE '%[^0-9]%'
      AND p.Contacted = 0 AND p.Invalid_Phone = 0 AND p.Opt_Out = 0
      AND NOT EXISTS (SELECT 1 FROM Contacts c WHERE c.Phone = p.Phone)
      AND NOT EXISTS (SELECT 1 FROM TblSMSSuppressions s WHERE s.Phone = p.Phone AND (s.Opt_Out = 1 OR s.Invalid_Phone = 1))
      AND NOT EXISTS (SELECT 1 FROM SharedPhones s WHERE s.Phone = p.Phone)
      AND NOT EXISTS (SELECT 1 FROM TblProspectsExport x WHERE x.Prospect_PK = p.PK)
      AND NOT EXISTS (SELECT 1 FROM TblProspectsExport x WHERE x.Phone = p.Phone)
)
SQL;
    }

    /**
     * Debt for each selected row (Jacob, 14 Oct): TblTUData (first + last name, address, 5-digit
     * zip; newest Creation_Date), else TblMailers (same person: Client, Address, 5-digit zip; newest
     * PK), else the tier's default amount, recorded as source Tier with no source key. Rows still
     * without an amount are recorded as None and stay blank. Only positive debts count: IWCO mailer
     * rows were saved as 0.
     *
     * @param array<string, float|int|string> $tierDebts Debt_Tier => default debt
     * @return array{TblTUData:int, TblMailers:int, Tier:int, None:int}
     */
    public function lookupDebts(string $requestId, array $tierDebts = [], ?callable $progress = null): array
    {
        return $this->withQueryTimeout(self::STEP_QUERY_TIMEOUT, fn (): array => $this->lookupDebtsNow($requestId, $tierDebts, $progress));
    }

    /** @return array{TblTUData:int, TblMailers:int, Tier:int, None:int} */
    private function lookupDebtsNow(string $requestId, array $tierDebts, ?callable $progress): array
    {
        $this->assertOpenRun($requestId);
        $db = $this->connection();
        // One pass over TblTUData for the whole run. Production's copy is 26.5M rows (52 GB) with no
        // index, so a lookup per person would read all of it each time. With a covering index on
        // address the hash join reads that index instead of the table.
        $hashJoins = self::HASH_JOINS;
        $tu = $db->update(<<<SQL
WITH Wanted AS (
    SELECT x.PK AS Export_PK, COALESCE(x.Address, p.Address) AS Address,
        COALESCE(x.Zip, p.Zip) AS Zip, COALESCE(x.Client, p.Client) AS Client
    FROM TblProspectsExport x
    JOIN TblProspects p ON p.PK = x.Prospect_PK
    WHERE x.Request_ID = ? AND x.Debt_Source IS NULL
),
Matches AS (
    SELECT w.Export_PK, t.PK, t.derivedFields1_unsecdebtbalance AS Debt, t.Tier_Tag,
        ROW_NUMBER() OVER (PARTITION BY w.Export_PK ORDER BY t.Creation_Date DESC, t.PK DESC) AS Match_Rank
    FROM Wanted w
    JOIN TblTUData t ON t.ncoaAddr_addrLine1Txt = w.Address AND LEFT(t.ncoaAddr_zipCde, 5) = w.Zip
    WHERE t.ncoaAddr_frstNme <> '' AND t.ncoaAddr_lastNme <> ''
      AND LEFT(w.Client, LEN(t.ncoaAddr_frstNme) + 1) = t.ncoaAddr_frstNme + ' '
      AND RIGHT(w.Client, LEN(t.ncoaAddr_lastNme) + 1) = ' ' + t.ncoaAddr_lastNme
      AND t.derivedFields1_unsecdebtbalance > 0
)
UPDATE x SET Debt_Amount = m.Debt, Debt_Source = 'TblTUData', Debt_Source_PK = m.PK,
    Debt_Tier = COALESCE(x.Debt_Tier, NULLIF(m.Tier_Tag, ''))
FROM TblProspectsExport x
JOIN Matches m ON m.Export_PK = x.PK AND m.Match_Rank = 1
{$hashJoins}
SQL, [$requestId]);
        $progress && $progress('mailers');

        // One indexed search per person: TblMailers (107M rows) has an index on (Client, Zip).
        // It keeps Client and Address at 50 characters.
        $mailers = $db->update(<<<'SQL'
UPDATE x SET Debt_Amount = m.Debt_Amount, Debt_Source = 'TblMailers', Debt_Source_PK = m.PK,
    Debt_Tier = COALESCE(x.Debt_Tier, NULLIF(m.Debt_Tier, ''))
FROM TblProspectsExport x
JOIN TblProspects p ON p.PK = x.Prospect_PK
CROSS APPLY (
    SELECT TOP 1 m.PK, m.Debt_Amount, m.Debt_Tier
    FROM TblMailers m
    WHERE m.Client = COALESCE(x.Client, p.Client) AND m.Address = COALESCE(x.Address, p.Address)
      AND LEFT(m.Zip, 5) = COALESCE(x.Zip, p.Zip) AND m.Debt_Amount > 0
    ORDER BY m.PK DESC
) m
WHERE x.Request_ID = ? AND x.Debt_Source IS NULL
SQL, [$requestId]);

        $progress && $progress('tiers');
        // TU calls these Tier1..Tier9. Canonicalize before fallback and grouping, not after
        // creating drops (otherwise one run incorrectly gets 18 drops instead of nine).
        $tierSql = SmsProspectValues::tierSql('Debt_Tier');
        $db->update("UPDATE TblProspectsExport SET Debt_Tier = {$tierSql} WHERE Request_ID = ?", [$requestId]);

        $fromTier = 0;
        foreach ($tierDebts as $tier => $debt) {
            if (! is_numeric($debt) || (float) $debt <= 0) {
                throw ValidationException::withMessages(['tier_debts' => "The default debt for tier {$tier} must be a positive number."]);
            }
            $fromTier += $db->update("UPDATE TblProspectsExport SET Debt_Source = 'Tier', Debt_Source_PK = NULL, Debt_Amount = CAST(? AS decimal(12,2))
                WHERE Request_ID = ? AND Debt_Source IS NULL AND Debt_Tier = ?", [number_format((float) $debt, 2, '.', ''), $requestId, (string) $tier]);
        }
        $none = $db->update("UPDATE TblProspectsExport SET Debt_Source = 'None' WHERE Request_ID = ? AND Debt_Source IS NULL", [$requestId]);

        return ['TblTUData' => $tu, 'TblMailers' => $mailers, 'Tier' => $fromTier, 'None' => $none];
    }

    /**
     * One TblSMSDrops row per tier, numbered on from the highest SMS name used so far (old
     * TblSmsExports names included), and each row of the run linked to its drop.
     *
     * @return array<int, array{PK:int, Drop_Name:string, Debt_Tier:?string, Amount_Dropped:int}>
     */
    public function assignDrops(string $requestId, Carbon $dropDate): array
    {
        $this->assertOpenRun($requestId);
        $db = $this->connection();

        return $db->transaction(function () use ($db, $requestId, $dropDate): array {
            if ($db->table('TblProspectsExport')->where('Request_ID', $requestId)->whereNotNull('SMS_Drop_PK')->exists()) {
                return $this->drops($requestId);
            }
            $tiers = $db->table('TblProspectsExport')->where('Request_ID', $requestId)
                ->groupBy('Debt_Tier')->selectRaw('Debt_Tier, COUNT(*) AS Records')->get()->all();
            // T1, T2 … T10 in natural order; rows without a tier get the last drop.
            usort($tiers, fn ($a, $b) => (($a->Debt_Tier === null) <=> ($b->Debt_Tier === null))
                ?: strnatcasecmp((string) $a->Debt_Tier, (string) $b->Debt_Tier));

            $next = $this->highestSmsNumber() + 1;
            $now = Carbon::now()->toDateTimeString();
            foreach ($tiers as $tier) {
                $name = 'SMS'.str_pad((string) $next++, 4, '0', STR_PAD_LEFT);
                $pk = $db->table('TblSMSDrops')->insertGetId([
                'Drop_Name' => $name, 'Debt_Tier' => $tier->Debt_Tier, 'Drop_Date' => $dropDate->toDateString(), 'Request_ID' => $requestId,
                    'Amount_Dropped' => (int) $tier->Records, 'Created_At' => $now,
                ]);
                $rows = $db->table('TblProspectsExport')->where('Request_ID', $requestId);
                $tier->Debt_Tier === null ? $rows->whereNull('Debt_Tier') : $rows->where('Debt_Tier', $tier->Debt_Tier);
                $rows->update(['SMS_Drop_PK' => $pk, 'Drop_Name' => $name]);
            }

            return $this->drops($requestId);
        });
    }

    /** Writes the vendor file from the run's rows; returns the number of records written. */
    public function writeCsv(string $requestId, string $path): int
    {
        return $this->withQueryTimeout(self::STEP_QUERY_TIMEOUT, fn (): int => $this->writeCsvNow($requestId, $path));
    }

    private function writeCsvNow(string $requestId, string $path): int
    {
        $this->assertOpenRun($requestId);
        if ($this->table('TblProspectsExport')->where('Request_ID', $requestId)->whereNull('SMS_Drop_PK')->exists()) {
            throw new RuntimeException('Assign the SMS drops before writing the file.');
        }
        // Feedback/contact synchronization may have changed since review. Do not silently
        // ship a stale file or change the reviewed count; ask for a fresh selection instead.
        $blocked = $this->connection()->selectOne(<<<'SQL'
WITH Contacts AS (
    SELECT CASE WHEN LEN(Phone) = 11 AND LEFT(Phone, 1) = '1' THEN SUBSTRING(Phone, 2, 10) ELSE Phone END AS Phone
    FROM TblPhoneNumbers GROUP BY CASE WHEN LEN(Phone) = 11 AND LEFT(Phone, 1) = '1' THEN SUBSTRING(Phone, 2, 10) ELSE Phone END
)
SELECT COUNT(*) AS n FROM TblProspectsExport x
JOIN TblProspects p ON p.PK = x.Prospect_PK
LEFT JOIN Contacts c ON c.Phone = x.Phone
LEFT JOIN TblSMSSuppressions s ON s.Phone = x.Phone
WHERE x.Request_ID = ? AND (p.Contacted = 1 OR p.Invalid_Phone = 1 OR p.Opt_Out = 1
    OR x.Debt_Amount IS NULL OR x.Debt_Amount <= 0
    OR s.Opt_Out = 1 OR s.Invalid_Phone = 1 OR c.Phone IS NOT NULL)
OPTION (HASH JOIN)
SQL, [$requestId]);
        if ((int) $blocked->n > 0) throw new RuntimeException('The reviewed list now contains a contact, suppressed phone or missing debt. Discard it and choose a fresh list.');
        $out = fopen($path, 'wb');
        if ($out === false) throw new RuntimeException('Unable to open the SMS export file.');
        $count = 0;
        try {
            if (fwrite($out, implode(',', self::CSV_HEADER)."\r\n") === false) throw new RuntimeException('Unable to write the SMS export.');
            $rows = $this->table('TblProspectsExport', 'x')
                ->join('TblProspects as p', 'p.PK', '=', 'x.Prospect_PK')
                ->join('TblSMSDrops as d', 'd.PK', '=', 'x.SMS_Drop_PK')
                ->where('x.Request_ID', $requestId)
                ->orderBy('d.Drop_Name')->orderBy('x.PK')
                ->selectRaw('x.Phone, x.Debt_Amount, COALESCE(x.Client, p.Client) AS Client, COALESCE(x.Address, p.Address) AS Address, d.Drop_Date')
                ->cursor();
            foreach ($rows as $row) {
                $this->writeCsvRow($out, [
                    explode(' ', trim((string) $row->Client))[0], (string) $row->Address,
                    $row->Debt_Amount === null ? '' : $this->formatDebt($row->Debt_Amount),
                    (string) $row->Phone, '', '', '', '', Carbon::parse($row->Drop_Date)->toDateString(),
                ]);
                $count++;
            }
            if (! fflush($out)) throw new RuntimeException('Unable to finish the SMS export.');
        } finally {
            fclose($out);
        }

        return $count;
    }

    /**
     * Records the run as sent, in one transaction: one more attempt and Latest_Export_Date (the
     * CA date) on every prospect holding a phone that was texted (a shared household phone counts
     * for everyone on it, so the phone is not texted again next week), then the rows move from
     * TblProspectsExport to TblSMSHistory. Repeating it after it finished does nothing.
     */
    public function markSent(string $requestId, Carbon $sentAt): int
    {
        $db = $this->connection();
        if (! $this->hasRows('TblProspectsExport', $requestId)) {
            if ($this->hasRows('TblSMSHistory', $requestId)) return 0;
            throw ValidationException::withMessages(['request_id' => 'This export run has no records. Select them first.']);
        }

        $hashJoins = self::HASH_JOINS;

        return $this->withQueryTimeout(self::STEP_QUERY_TIMEOUT, fn (): int => $db->transaction(function () use ($db, $requestId, $sentAt, $hashJoins): int {
            // Record the exact old values in the SAME update/transaction as the increment.
            // A legacy send without this audit must never be automatically reversed.
            $db->update(<<<SQL
UPDATE p SET SMS_Attempts = p.SMS_Attempts + 1, Latest_Export_Date = ?
OUTPUT ?, deleted.PK, deleted.Latest_Export_Date, deleted.SMS_Attempts, inserted.Latest_Export_Date, inserted.SMS_Attempts
INTO TblSMSAttemptAudit (Request_ID, Prospect_PK, Previous_Date, Previous_Attempts, Recorded_Date, Recorded_Attempts)
FROM TblProspects p
WHERE EXISTS (SELECT 1 FROM TblProspectsExport x WHERE x.Request_ID = ? AND x.Phone = p.Phone)
{$hashJoins}
SQL, [$sentAt->copy()->setTimezone(self::TIMEZONE)->toDateString(), $requestId, $requestId]);

            $db->insert(<<<'SQL'
INSERT INTO TblSMSLegacySends (Phone, Send_Date, Request_ID)
SELECT x.Phone, ?, ? FROM TblProspectsExport x
WHERE x.Request_ID = ? AND NOT EXISTS (SELECT 1 FROM TblSMSLegacySends s WHERE s.Phone = x.Phone AND s.Send_Date = ?)
SQL, [$sentAt->copy()->setTimezone(self::TIMEZONE)->toDateString(), $requestId, $requestId, $sentAt->copy()->setTimezone(self::TIMEZONE)->toDateString()]);

            $moved = $db->affectingStatement(<<<'SQL'
INSERT INTO TblSMSHistory (Request_ID, SMS_Drop_PK, Drop_Name, Prospect_PK, Phone, Export_Date,
    Debt_Amount, Debt_Tier, Debt_Source, Debt_Source_PK, Client, Address, City, State, Zip, Email)
SELECT Request_ID, SMS_Drop_PK, Drop_Name, Prospect_PK, Phone, ?, Debt_Amount, Debt_Tier, Debt_Source, Debt_Source_PK, Client, Address, City, State, Zip, Email
FROM TblProspectsExport
WHERE Request_ID = ?
SQL, [$sentAt->toDateTimeString(), $requestId]);
            $db->table('TblProspectsExport')->where('Request_ID', $requestId)->delete();

            return $moved;
        }));
    }

    /** Keep the review file/drops, release its people, and do NOT record any send. */
    public function releaseReview(string $requestId): int
    {
        if ($this->hasRows('TblSMSHistory', $requestId)) {
            throw ValidationException::withMessages(['request_id' => 'This run already has send history. It needs a send correction, not review-only.']);
        }
        return $this->table('TblProspectsExport')->where('Request_ID', $requestId)->delete();
    }

    /** Reversal is allowed only with complete, unchanged before-values, never a guessed date. */
    public function reverseSent(string $requestId): int
    {
        return $this->withQueryTimeout(self::STEP_QUERY_TIMEOUT, fn (): int => $this->connection()->transaction(function () use ($requestId): int {
            $db = $this->connection();
            $audit = $db->table('TblSMSAttemptAudit')->where('Request_ID', $requestId);
            if (! $audit->exists()) throw ValidationException::withMessages(['request_id' => 'This older send has no before-values. Restore-point reconciliation is required; no dates or counts were changed.']);
            $changed = $db->selectOne(<<<'SQL'
SELECT COUNT(*) AS n FROM TblSMSAttemptAudit a
LEFT JOIN TblProspects p WITH (UPDLOCK, HOLDLOCK) ON p.PK = a.Prospect_PK
WHERE a.Request_ID = ? AND (p.PK IS NULL OR p.SMS_Attempts <> a.Recorded_Attempts
    OR p.Latest_Export_Date IS NULL OR p.Latest_Export_Date <> a.Recorded_Date)
SQL, [$requestId]);
            if ((int) $changed->n > 0) throw ValidationException::withMessages(['request_id' => 'Prospect send history changed after this run. Reconcile it before correcting the send. Nothing was changed.']);
            // Keep TblSMSHistory and the audit as evidence; only Status=sent counts in reporting.
            $restored = $db->update(<<<'SQL'
UPDATE p SET SMS_Attempts = a.Previous_Attempts, Latest_Export_Date = a.Previous_Date
FROM TblProspects p JOIN TblSMSAttemptAudit a ON a.Prospect_PK = p.PK
WHERE a.Request_ID = ?
SQL, [$requestId]);
            $db->table('TblSMSLegacySends')->where('Request_ID', $requestId)->delete();
            return $restored;
        }));
    }

    /** Removes a run that was not sent (its rows and SMS drops), so its people can be selected again. */
    public function discard(string $requestId): int
    {
        $db = $this->connection();

        return $db->transaction(function () use ($db, $requestId): int {
            if ($this->hasRows('TblSMSHistory', $requestId)) {
                throw ValidationException::withMessages(['request_id' => 'This run was already sent and cannot be discarded.']);
            }
            $drops = $db->table('TblProspectsExport')->where('Request_ID', $requestId)->whereNotNull('SMS_Drop_PK')
                ->distinct()->pluck('SMS_Drop_PK')->all();
            $deleted = $db->table('TblProspectsExport')->where('Request_ID', $requestId)->delete();
            if ($drops !== []) $db->table('TblSMSDrops')->whereIn('PK', $drops)->delete();

            return $deleted;
        });
    }

    /** @return array<int, array{PK:int, Drop_Name:string, Debt_Tier:?string, Amount_Dropped:int}> */
    public function drops(string $requestId): array
    {
        $table = $this->hasRows('TblProspectsExport', $requestId) ? 'TblProspectsExport' : 'TblSMSHistory';

        return $this->table('TblSMSDrops', 'd')
            ->where(function ($q) use ($table, $requestId): void {
                $q->where('d.Request_ID', $requestId)->orWhereIn('d.PK', $this->table($table)->where('Request_ID', $requestId)->whereNotNull('SMS_Drop_PK')->select('SMS_Drop_PK'));
            })
            ->orderBy('d.Drop_Name')->get(['d.PK', 'd.Drop_Name', 'd.Debt_Tier', 'd.Amount_Dropped'])
            ->map(fn ($d) => ['PK' => (int) $d->PK, 'Drop_Name' => (string) $d->Drop_Name, 'Debt_Tier' => $d->Debt_Tier, 'Amount_Dropped' => (int) $d->Amount_Dropped])
            ->all();
    }

    private function hasRows(string $table, string $requestId): bool
    {
        return $this->table($table)->where('Request_ID', $requestId)->exists();
    }

    private function assertOpenRun(string $requestId): void
    {
        if (! $this->hasRows('TblProspectsExport', $requestId)) {
            throw ValidationException::withMessages(['request_id' => 'This export run has no records. Select them first.']);
        }
    }

    private function highestSmsNumber(): int
    {
        $names = $this->table('TblSMSDrops')->lockForUpdate()->pluck('Drop_Name');
        if ($this->connection()->getSchemaBuilder()->hasTable('TblSmsExports')) {
            $names = $names->merge($this->table('TblSmsExports')->pluck('SMS_Drop_Name'));
        }

        return (int) $names->map(fn ($name) => preg_match('/^SMS(\d+)$/i', (string) $name, $m) ? (int) $m[1] : 0)->max();
    }

    private function formatDebt(mixed $value): string
    {
        $text = trim((string) $value);
        if (! preg_match('/^(-?)(\d*)(?:\.(\d+))?$/D', $text, $m)) return $text;
        $integer = ltrim($m[2], '0');
        $fraction = rtrim($m[3] ?? '', '0');

        return $m[1].($integer === '' ? '0' : $integer).($fraction === '' ? '' : '.'.$fraction);
    }

    /** @param resource $out @param array<int, string> $values */
    private function writeCsvRow(mixed $out, array $values): void
    {
        // A leading =, +, @ or - would run as a formula in Excel.
        $values = array_map(fn (string $v): string => preg_match('/^[=+@\-\t\r\n]/', $v) ? "'".$v : $v, $values);
        if (fputcsv($out, $values, ',', '"', '', "\r\n") === false) {
            throw new RuntimeException('Unable to write the SMS export.');
        }
    }
}
