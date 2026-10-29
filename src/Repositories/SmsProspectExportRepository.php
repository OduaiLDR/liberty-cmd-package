<?php

namespace Cmd\Reports\Repositories;

use Carbon\Carbon;
use Cmd\Reports\Services\SmsAvailabilityRefresh;
use Cmd\Reports\Services\SmsContactFlagSeek;
use Cmd\Reports\Services\SmsRecordPolicy;
use Cmd\Reports\Services\SmsCurrentDebtSql;
use Cmd\Reports\Services\SmsDeliveryPolicy;
use Cmd\Reports\Services\SmsTuCompletionDate;
use Cmd\Reports\Services\SmsWorkflowLock;
use Cmd\Reports\Services\SmsRunOptions;
use Cmd\Reports\Services\SmsStatePolicy;
use Cmd\Reports\Services\SmsPhoneNumbersSql;
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

    private function contactRowsSql(): string
    {
        return config('sms-exports.wide_phone_contacts_enabled', false)
            ? SmsPhoneNumbersSql::from('sms_contact_numbers', '', true) : 'TblPhoneNumbers';
    }

    /**
     * Prospects whose phone is now a synced contact (TblPhoneNumbers) get Contacted = 1 and the
     * contact's creation date as Response_Date when that legacy column exists. Contacted_Date is
     * separate attribution evidence and is never inferred here. Run after each phone sync and
     * before each legacy selection.
     */
    public function markContacted(): int
    {
        $db = $this->connection();
        $phone = self::CONTACT_PHONE;
        $hashJoins = self::HASH_JOINS;
        $contacts = $this->contactRowsSql();
        $withResponseDate = $db->getSchemaBuilder()->hasColumn('TblProspects', 'Response_Date');

        if (! $withResponseDate && config('sms-exports.contact_flag_seek_enabled', false)) {
            return SmsAvailabilityRefresh::transaction($db, function () use ($db): int {
                SmsContactFlagSeek::assertReady($db);
                $changed=$db->update(SmsContactFlagSeek::sql((bool) config('sms-exports.wide_phone_contacts_enabled', false)));
                if ($changed>0) SmsAvailabilityRefresh::afterCommit($db);
                return $changed;
            });
        }

        return SmsAvailabilityRefresh::transaction($db, function () use ($db, $phone, $hashJoins, $withResponseDate, $contacts): int {
            $sql = $withResponseDate ? <<<SQL
WITH Contacts AS (
    SELECT {$phone} AS Phone, MIN(Created_Date) AS Created_Date
    FROM {$contacts}
    WHERE Phone IS NOT NULL
    GROUP BY {$phone}
)
UPDATE p SET Contacted = 1, Response_Date = CASE WHEN p.Response_Date IS NULL OR c.Created_Date < p.Response_Date THEN c.Created_Date ELSE p.Response_Date END
FROM TblProspects p
JOIN Contacts c ON c.Phone = p.Phone
WHERE (p.Contacted = 0 OR p.Response_Date IS NULL OR c.Created_Date < p.Response_Date) AND p.Phone IS NOT NULL
{$hashJoins}
SQL : <<<SQL
WITH Contacts AS (
    SELECT DISTINCT {$phone} AS Phone
    FROM {$contacts}
    WHERE Phone IS NOT NULL
)
UPDATE p SET Contacted = 1
FROM TblProspects p
JOIN Contacts c ON c.Phone = p.Phone
WHERE p.Contacted = 0 AND p.Phone IS NOT NULL
{$hashJoins}
SQL;
            $changed = $db->update($sql);
            if ($changed > 0) SmsAvailabilityRefresh::afterCommit($db);
            return $changed;
        });
    }

    /** @return array{selected:int, target:int, shortfall:int, contacted_flagged:int} */
    public function select(string $requestId, int $target): array
    {
        if ($this->isRecordRun($requestId)) return app(SmsRecordExportRepository::class)->select($requestId, $target);
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
            $db = $this->connection();
            $selected = SmsAvailabilityRefresh::transaction($db, function () use ($db, $priority, $hashJoins, $requestId, $target): int {
                $selected = $db->affectingStatement($this->eligibleSql().<<<SQL

INSERT INTO TblProspectsExport (Request_ID, Prospect_PK, Phone, Debt_Tier, Client, Address, City, State, Zip, Email)
SELECT TOP (?) ?, PK, Phone, NULL, Client, Address, City, State, Zip, Email
FROM Eligible
WHERE Phone_Rank = 1
ORDER BY {$priority}
{$hashJoins}
SQL, [self::SHARED_PHONE_ADDRESSES, $target, $requestId]);
                if ($selected > 0) SmsAvailabilityRefresh::afterCommit($db);
                return $selected;
            });

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
        if (SmsRecordPolicy::enabled()) return app(SmsRecordExportRepository::class)->availability();
        $row = $this->connection()->selectOne($this->eligibleSql().'
SELECT COUNT(*) AS Eligible, COALESCE(SUM(CASE WHEN Latest_Export_Date IS NULL THEN 1 ELSE 0 END), 0) AS Never_Texted
FROM Eligible WHERE Phone_Rank = 1
'.self::HASH_JOINS, [self::SHARED_PHONE_ADDRESSES]);
        $eligible = (int) ($row->Eligible ?? 0);
        $never = (int) ($row->Never_Texted ?? 0);

        return ['eligible' => $eligible, 'never_texted' => $never, 'texted_before' => $eligible - $never];
    }

    /** Background aggregate only: restore the connection's normal timeout even on failure. */
    public function availabilityWithTimeout(): array
    {
        return $this->withQueryTimeout(600, fn (): array => $this->availability());
    }

    /** Durable workers count a consistent snapshot; legacy callers keep their existing path. */
    public function availabilitySnapshotWithTimeout(): array
    {
        return $this->withReadSnapshot(function (): array {
            $counts = $this->availabilityWithTimeout();
            if (SmsTuCompletionDate::enabled()) {
                // Do not read TU metadata after ending the count snapshot, or attach
                // a newer completed dataset date to counts from an older dataset.
                $counts['latest_data_date'] = app(SmsTuCompletionDate::class)->latest($this->connection());
            }
            return $counts;
        });
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
        $contacts = $this->contactRowsSql();
        $state = SmsStatePolicy::sqlPredicate('p.State', 'sqlsrv');

        return <<<SQL
WITH Contacts AS (
    SELECT {$phone} AS Phone FROM {$contacts} WHERE Phone IS NOT NULL
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
    SELECT p.PK, p.Phone, p.Creation_Date, p.Client, p.Address, p.City, p.State, p.Zip, p.Email,
        p.Contacted, p.Invalid_Phone, p.Opt_Out,
        CASE WHEN h.Sent IS NOT NULL AND (p.Latest_Export_Date IS NULL OR h.Sent > p.Latest_Export_Date)
            THEN h.Sent ELSE p.Latest_Export_Date END AS Latest_Export_Date
    FROM TblProspects p LEFT JOIN PhoneHistory h ON h.Phone = p.Phone
),
Eligible AS (
    SELECT p.PK, p.Phone, p.Latest_Export_Date, p.Creation_Date,
        p.Client, p.Address, p.City, p.State, p.Zip, p.Email,
        ROW_NUMBER() OVER (PARTITION BY p.Phone ORDER BY {$priority}) AS Phone_Rank
    FROM Prospects p
    WHERE p.Phone IS NOT NULL AND LEN(p.Phone) = 10 AND p.Phone NOT LIKE '%[^0-9]%'
      AND p.Contacted = 0 AND p.Invalid_Phone = 0 AND p.Opt_Out = 0
      AND {$state}
      AND NOT EXISTS (SELECT 1 FROM Contacts c WHERE c.Phone = p.Phone)
      AND NOT EXISTS (SELECT 1 FROM TblSMSSuppressions s WHERE s.Phone = p.Phone AND (s.Opt_Out = 1 OR s.Invalid_Phone = 1))
      AND NOT EXISTS (SELECT 1 FROM SharedPhones s WHERE s.Phone = p.Phone)
      AND NOT EXISTS (SELECT 1 FROM TblProspectsExport x WHERE x.Prospect_PK = p.PK)
      AND NOT EXISTS (SELECT 1 FROM TblProspectsExport x WHERE x.Phone = p.Phone)
)
SQL;
    }

    /**
     * Resolve TU first, positive-debt mailers second, then the approved mailer-tier amount.
     * Missing dates, conflicting same-date revisions and invalid latest values remain held.
     * The tier-default argument is retained for caller compatibility; the resolver uses
     * the approved fixed tier table, not arbitrary caller-supplied amounts.
     *
     * @return array{TblTUData:int, TblMailers:int, Tier:int, None:int}
     */
    public function lookupDebts(string $requestId, array $tierDebts = [], ?callable $progress = null): array
    {
        $this->assertOrdinaryRun($requestId);
        return $this->withQueryTimeout(self::STEP_QUERY_TIMEOUT, fn (): array => $this->lookupDebtsNow($requestId, $tierDebts, $progress));
    }

    private function lookupDebtsNow(string $requestId, array $tierDebts, ?callable $progress): array
    {
        $this->assertOpenRun($requestId);
        // Validate the confirmed mailer clock before any mutation.
        $sql = SmsCurrentDebtSql::resolve((string) config('sms-exports.mailer_recency_date', 'Send_Date'));
        $db = $this->connection();
        return $db->transaction(function () use ($db, $sql, $requestId, $progress): array {
            if (! $db->table('TblProspectsExport')->where('Request_ID', $requestId)->whereNotNull('SMS_Drop_PK')->exists()) {
                $progress && $progress('current_sources');
                $db->update($sql, [$requestId]);
                $db->update("UPDATE TblProspectsExport SET Debt_Source='None',Debt_Amount=NULL,Debt_Tier=NULL,Debt_Source_PK=NULL WHERE Request_ID=? AND Debt_Source IS NULL", [$requestId]);
            }
            $counts = ['TblTUData' => 0, 'TblMailers' => 0, 'Tier' => 0, 'None' => 0];
            foreach ($db->table('TblProspectsExport')->where('Request_ID', $requestId)
                ->groupBy('Debt_Source')->selectRaw('Debt_Source,COUNT(*) AS Records')->get() as $row) {
                if (array_key_exists($row->Debt_Source, $counts)) $counts[$row->Debt_Source] = (int) $row->Records;
            }
            return $counts;
        });
    }

    /**
     * One TblSMSDrops row per tier, numbered on from the highest SMS name used so far (old
     * TblSmsExports names included), and each row of the run linked to its drop.
     *
     * @return array<int, array{PK:int, Drop_Name:string, Debt_Tier:?string, Amount_Dropped:int}>
     */
    public function assignDrops(string $requestId, Carbon $dropDate): array
    {
        $this->assertOrdinaryRun($requestId);
        $this->assertOpenRun($requestId);
        $db = $this->connection();
        $run = $db->table('TblSMSRuns')->where('Request_ID', $requestId)->first();
        $options = $run ? SmsRunOptions::fromStored($run->Run_Options_JSON ?? null, (int) $run->Target) : null;
        if (($options['filename_version'] ?? null) === SmsRunOptions::APPROVAL_FILENAME_VERSION
            && ($run->Status !== 'approving' || ! $run->Approved_At || ! $run->Approved_By || $dropDate->toDateString() !== $options['send_date'])) {
            throw ValidationException::withMessages(['request_id' => 'Approval-time SMS IDs can only be assigned after accepted approval, on the frozen planned date.']);
        }

        return SmsWorkflowLock::run('drop-numbering', fn () => $db->transaction(function () use ($db, $requestId, $dropDate): array {
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
        }));
    }

    /** Writes the vendor file from the run's rows; returns the number of records written. */
    public function writeCsv(string $requestId, string $path): int
    {
        if ($this->isRecordRun($requestId)) {
            return $this->withQueryTimeout(self::STEP_QUERY_TIMEOUT, fn () => app(SmsRecordExportRepository::class)->writeCsv($requestId, $path));
        }
        return $this->withQueryTimeout(self::STEP_QUERY_TIMEOUT, fn (): int => $this->writeCsvNow($requestId, $path));
    }

    private function writeCsvNow(string $requestId, string $path): int
    {
        $this->assertOpenRun($requestId);
        $contacts = $this->contactRowsSql();
        \Cmd\Reports\Services\SmsDebtPolicy::assertPending($this->connection(), $requestId);
        if ($this->table('TblProspectsExport')->where('Request_ID', $requestId)->whereNull('SMS_Drop_PK')->exists()) {
            throw new RuntimeException('Assign the SMS drops before writing the file.');
        }
        // Feedback/contact synchronization may have changed since review. Do not silently
        // ship a stale file or change the reviewed count; ask for a fresh selection instead.
        $state = SmsStatePolicy::sqlPredicate('p.State', 'sqlsrv');
        $frozenState = SmsStatePolicy::sqlPredicate('x.State', 'sqlsrv');
        $sameState = SmsStatePolicy::normalizedSql('p.State', 'sqlsrv').' = '.SmsStatePolicy::normalizedSql('x.State', 'sqlsrv');
        $blocked = $this->connection()->selectOne(<<<SQL
WITH Contacts AS (
    SELECT CASE WHEN LEN(Phone) = 11 AND LEFT(Phone, 1) = '1' THEN SUBSTRING(Phone, 2, 10) ELSE Phone END AS Phone
    FROM {$contacts} GROUP BY CASE WHEN LEN(Phone) = 11 AND LEFT(Phone, 1) = '1' THEN SUBSTRING(Phone, 2, 10) ELSE Phone END
)
SELECT COUNT(*) AS n FROM TblProspectsExport x
JOIN TblProspects p ON p.PK = x.Prospect_PK
LEFT JOIN Contacts c ON c.Phone = x.Phone
LEFT JOIN TblSMSSuppressions s ON s.Phone = x.Phone
WHERE x.Request_ID = ? AND (p.Contacted = 1 OR p.Invalid_Phone = 1 OR p.Opt_Out = 1
    OR NOT ({$state}) OR NOT ({$frozenState}) OR NOT ({$sameState})
    OR x.Debt_Amount IS NULL OR x.Debt_Amount <= 0
    OR x.Debt_Amount <> CAST(x.Debt_Amount / 1000 AS bigint) * 1000
    OR s.Opt_Out = 1 OR s.Invalid_Phone = 1 OR c.Phone IS NOT NULL)
OPTION (HASH JOIN)
SQL, [$requestId]);
        if ((int) $blocked->n > 0) throw new RuntimeException('The reviewed list contains a contact, suppressed phone, missing debt or an old unrounded debt snapshot. Discard it and choose a fresh list.');
        $out = fopen($path, 'wb');
        if ($out === false) throw new RuntimeException('Unable to open the SMS export file.');
        $count = 0;
        $singlePhone = (bool) config('sms-exports.single_phone_csv', false);
        try {
            if (fwrite($out, implode(',', SmsDeliveryPolicy::header($singlePhone))."\r\n") === false) throw new RuntimeException('Unable to write the SMS export.');
            $rows = $this->table('TblProspectsExport', 'x')
                ->join('TblProspects as p', 'p.PK', '=', 'x.Prospect_PK')
                ->join('TblSMSDrops as d', 'd.PK', '=', 'x.SMS_Drop_PK')
                ->where('x.Request_ID', $requestId)
                ->orderBy('d.Drop_Name')->orderBy('x.PK')
                ->selectRaw('x.Phone, x.Debt_Amount, COALESCE(x.Client, p.Client) AS Client, COALESCE(x.Address, p.Address) AS Address, d.Drop_Date')
                ->cursor();
            foreach ($rows as $row) {
                $this->writeCsvRow($out, SmsDeliveryPolicy::row((string) $row->Client, (string) $row->Address,
                    $row->Debt_Amount, (string) $row->Phone, Carbon::parse($row->Drop_Date)->toDateString(), $singlePhone));
                $count++;
            }
            if (! fflush($out)) throw new RuntimeException('Unable to finish the SMS export.');
        } finally {
            fclose($out);
        }
        $this->assertCurrentStates($requestId);

        return $count;
    }

    /** Compatibility entry point for final State and contact/phone suppression checks. */
    public function assertCurrentStates(string $requestId): void
    {
        \Cmd\Reports\Services\SmsFrozenEligibility::assertPending($this->connection(), $requestId);
    }

    /**
     * Records the run as sent, in one transaction: one more attempt and Latest_Export_Date (the
     * CA date) on every prospect holding a phone that was texted (a shared household phone counts
     * for everyone on it, so the phone is not texted again next week), then the rows move from
     * TblProspectsExport to TblSMSHistory. Repeating it after it finished does nothing.
     */
    public function markSent(string $requestId, Carbon $sentAt): int
    {
        if ($this->isRecordRun($requestId)) throw new RuntimeException('Record batches must use immutable approval, not legacy phone-based sending.');
        $db = $this->connection();
        if (! $this->hasRows('TblProspectsExport', $requestId)) {
            if ($this->hasRows('TblSMSHistory', $requestId)) return 0;
            throw ValidationException::withMessages(['request_id' => 'This export run has no records. Select them first.']);
        }

        $hashJoins = self::HASH_JOINS;

        $moved = $this->withQueryTimeout(self::STEP_QUERY_TIMEOUT, fn (): int => $db->transaction(function () use ($db, $requestId, $sentAt, $hashJoins): int {
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

            if ($moved > 0) SmsAvailabilityRefresh::afterCommit($db);
            return $moved;
        }));
        return $moved;
    }

    /** Keep the review file/drops, release its people, and do NOT record any send. */
    public function releaseReview(string $requestId): int
    {
        $this->assertOrdinaryRun($requestId);
        if ($this->hasRows('TblSMSHistory', $requestId)) {
            throw ValidationException::withMessages(['request_id' => 'This run already has send history. It needs a send correction, not review-only.']);
        }
        $db = $this->connection();
        return SmsAvailabilityRefresh::transaction($db, function () use ($db, $requestId): int {
            $released = $db->table('TblProspectsExport')->where('Request_ID', $requestId)->delete();
            if ($released > 0) SmsAvailabilityRefresh::afterCommit($db);
            return $released;
        });
    }

    /** Reversal is allowed only with complete, unchanged before-values, never a guessed date. */
    public function reverseSent(string $requestId): int
    {
        $this->assertOrdinaryRun($requestId);
        $restored = $this->withQueryTimeout(self::STEP_QUERY_TIMEOUT, fn (): int => $this->connection()->transaction(function () use ($requestId): int {
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
            if ($restored > 0) SmsAvailabilityRefresh::afterCommit($db);
            return $restored;
        }));
        return $restored;
    }

    /** Removes a run that was not sent (its rows and SMS drops), so its people can be selected again. */
    public function discard(string $requestId): int
    {
        $this->assertOrdinaryRun($requestId);
        $db = $this->connection();

        $deleted = $db->transaction(function () use ($db, $requestId): int {
            if ($this->hasRows('TblSMSHistory', $requestId)) {
                throw ValidationException::withMessages(['request_id' => 'This run was already sent and cannot be discarded.']);
            }
            $drops = $db->table('TblProspectsExport')->where('Request_ID', $requestId)->whereNotNull('SMS_Drop_PK')
                ->distinct()->pluck('SMS_Drop_PK')->all();
            $deleted = $db->table('TblProspectsExport')->where('Request_ID', $requestId)->delete();
            if ($drops !== []) $db->table('TblSMSDrops')->whereIn('PK', $drops)->delete();

            if ($deleted > 0) SmsAvailabilityRefresh::afterCommit($db);
            return $deleted;
        });
        return $deleted;
    }

    /** @return array<int, array{PK:int, Drop_Name:string, Debt_Tier:?string, Amount_Dropped:int}> */
    public function drops(string $requestId): array
    {
        // assignDrops saves Request_ID on all of a run's drops in one transaction, so an indexed seek finds
        // them. Only legacy runs numbered before that column need their membership (possibly millions of
        // rows); record runs never do, since they have no drops until approval assigns them.
        $drops = $this->table('TblSMSDrops', 'd')->where('d.Request_ID', $requestId);
        if (! $this->isRecordRun($requestId) && ! (clone $drops)->exists()) {
            $table = $this->hasRows('TblProspectsExport', $requestId) ? 'TblProspectsExport' : 'TblSMSHistory';
            $drops = $this->table('TblSMSDrops', 'd')->where(function ($q) use ($table, $requestId): void {
                $q->where('d.Request_ID', $requestId)->orWhereIn('d.PK', $this->table($table)->where('Request_ID', $requestId)->whereNotNull('SMS_Drop_PK')->select('SMS_Drop_PK'));
            });
        }

        return $drops->orderBy('d.Drop_Name')->get(['d.PK', 'd.Drop_Name', 'd.Debt_Tier', 'd.Amount_Dropped'])
            ->map(fn ($d) => ['PK' => (int) $d->PK, 'Drop_Name' => (string) $d->Drop_Name, 'Debt_Tier' => $d->Debt_Tier, 'Amount_Dropped' => (int) $d->Amount_Dropped])
            ->all();
    }

    private function hasRows(string $table, string $requestId): bool
    {
        return $this->table($table)->where('Request_ID', $requestId)->exists();
    }

    private function isRecordRun(string $requestId): bool
    {
        $run = $this->assertOrdinaryRun($requestId);
        return $run !== null && SmsRecordPolicy::isRecord($run);
    }

    private function assertOrdinaryRun(string $requestId): ?object
    {
        if (! $this->connection()->getSchemaBuilder()->hasTable('TblSMSRuns')) return null;
        $run = $this->table('TblSMSRuns')->where('Request_ID', $requestId)->first();
        if ($run !== null) SmsRecordPolicy::assertOrdinary($run);
        return $run;
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
