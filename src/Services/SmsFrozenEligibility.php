<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Read current suppressions without repricing, replacing or removing frozen members. */
final class SmsFrozenEligibility
{
    public static function assertHandoff(string $requestId): void
    {
        if (config('sms-exports.approved_handoff_suppression_enabled', false)) self::assertApproved($requestId);
    }

    public static function assertPending(ConnectionInterface $db, string $requestId): void
    {
        // The debt-on-the-minimum proof rides on the same pass: it needs the same prospect rows the member check reads.
        $stored = SmsDebtPolicy::storedBoundary($db, $requestId);
        $unproven = self::assertMembers($db, $requestId, 'TblProspectsExport', $stored);
        SmsStepLog::mark($requestId, 'check_debt');
        SmsDebtPolicy::assertPending($db, $requestId, $stored === null ? null : ['unproven' => $unproven] + $stored);
    }

    /** New outward handoffs must use complete approved membership; history reads stay independent. */
    public static function assertApproved(string $requestId): void
    {
        $db = DB::connection('sqlsrv');
        $run = $db->table('TblSMSRuns')->useWritePdo()->where('Request_ID', $requestId)->first();
        if ($run === null || ! SmsRecordPolicy::isRecord($run) || $run->Status !== 'approved'
            || (int) $run->Selected < 1 || ! $run->Approved_At || ! $run->Approved_By) {
            throw new SmsFrozenEligibilityHold('Final SMS handoff requires complete approved record membership.');
        }
        if ($db->table('TblSMSHistory')->useWritePdo()->where('Request_ID', $requestId)->count() !== (int) $run->Selected) {
            throw new SmsFrozenEligibilityHold('Approved SMS history count differs from its frozen batch. Handoff was held.');
        }
        self::assertMembers($db, $requestId, 'TblSMSHistory');
    }

    /**
     * Who a hold is about, so one person can come out without losing the batch. Read only, and only
     * for a pending batch. It matches the check below: a person listed here is what stops the batch.
     *
     * @return list<int>
     */
    public static function heldMembers(ConnectionInterface $db, string $requestId): array
    {
        $native = $db->getDriverName() === 'sqlsrv';
        $members = fn (): Builder => $db->table('TblProspectsExport as x')->useWritePdo()
            ->leftJoin('TblProspects as p', 'p.PK', '=', 'x.Prospect_PK')->where('x.Request_ID', $requestId);
        $state = SmsStatePolicy::sqlPredicate('p.State', $db->getDriverName());
        $frozenState = SmsStatePolicy::sqlPredicate('x.State', $db->getDriverName());
        $sameState = SmsStatePolicy::normalizedSql('p.State', $db->getDriverName()).' = '.SmsStatePolicy::normalizedSql('x.State', $db->getDriverName());
        $phoneValid = self::phoneValidSql($native);
        $samePhone = self::samePhoneSql($native);
        $people = $members()->where(function (Builder $q) use ($state, $frozenState, $sameState, $phoneValid, $samePhone): void {
            $q->whereNull('p.PK')->orWhereRaw("NOT ({$state})")->orWhereRaw("NOT ({$frozenState})")->orWhereRaw("NOT ({$sameState})");
            foreach (['Contacted','Opt_Out','Invalid_Phone'] as $flag) $q->orWhereNull('p.'.$flag)->orWhere('p.'.$flag, '<>', 0);
            $q->orWhereNull('p.Phone')->orWhereNull('x.Phone')->orWhereRaw("NOT ({$phoneValid})")->orWhereRaw("NOT ({$samePhone})");
        })->pluck('x.Prospect_PK')->all();
        $contactRows = config('sms-exports.wide_phone_contacts_enabled', false)
            ? SmsPhoneNumbersSql::from('c', '', true) : 'TblPhoneNumbers as c';
        foreach ($db->select("SELECT x.Prospect_PK FROM TblProspectsExport AS x
 JOIN TblSMSSuppressions AS s ON s.Phone=x.Phone WHERE x.Request_ID=? AND (s.Opt_Out=1 OR s.Invalid_Phone=1)
 UNION
 SELECT x.Prospect_PK FROM TblProspectsExport AS x JOIN {$contactRows} ON c.Phone=x.Phone WHERE x.Request_ID=?
 UNION
 SELECT x.Prospect_PK FROM TblProspectsExport AS x JOIN {$contactRows} ON c.Phone='1'+x.Phone WHERE x.Request_ID=?
OPTION (HASH JOIN)", [$requestId, $requestId, $requestId], false) as $row) {
            $people[] = $row->Prospect_PK;
        }
        $people = array_values(array_unique(array_map('intval', $people)));
        sort($people);

        return $people;
    }

    private static function samePhoneSql(bool $native): string
    {
        return $native ? 'CONVERT(nvarchar(max),p.Phone) COLLATE Latin1_General_100_BIN2 = CONVERT(nvarchar(max),x.Phone) COLLATE Latin1_General_100_BIN2 AND DATALENGTH(CONVERT(nvarchar(max),p.Phone))=DATALENGTH(CONVERT(nvarchar(max),x.Phone))'
            : 'p.Phone COLLATE BINARY = x.Phone COLLATE BINARY';
    }

    private static function phoneValidSql(bool $native): string
    {
        return $native ? "DATALENGTH(CONVERT(nvarchar(max),x.Phone))=20 AND CONVERT(nvarchar(max),x.Phone) COLLATE Latin1_General_100_BIN2 LIKE '".str_repeat('[0-9]', 10)."'"
            : "length(x.Phone)=10 AND x.Phone NOT GLOB '*[^0-9]*'";
    }

    /**
     * $stored: SmsDebtPolicy::storedBoundary(), read in the same pass. Returns whether any member on the debt minimum is
     * not proven by their stored debt (always false without $stored).
     */
    private static function assertMembers(ConnectionInterface $db, string $requestId, string $table, ?array $stored = null): bool
    {
        $native = $db->getDriverName() === 'sqlsrv';
        $locking = $native && $db->transactionLevel() > 0;
        if ($locking) {
            // Current prospect rows and frozen rows remain locked through the caller's
            // approval transaction. No broad flag update or membership mutation occurs.
            SmsStepLog::mark($requestId, 'check_lock');
            $db->select("SELECT p.PK FROM TblProspects p WITH (UPDLOCK,HOLDLOCK)
JOIN {$table} x WITH (HOLDLOCK) ON x.Prospect_PK=p.PK
WHERE x.Request_ID=? ORDER BY p.PK", [$requestId], false);
        }
        $members = fn (): Builder => $db->table($table.' as x')->useWritePdo()
            ->leftJoin('TblProspects as p', 'p.PK', '=', 'x.Prospect_PK')->where('x.Request_ID', $requestId);
        $state = SmsStatePolicy::sqlPredicate('p.State', $db->getDriverName());
        $frozenState = SmsStatePolicy::sqlPredicate('x.State', $db->getDriverName());
        $sameState = SmsStatePolicy::normalizedSql('p.State', $db->getDriverName()).' = '.SmsStatePolicy::normalizedSql('x.State', $db->getDriverName());
        $stateBad = static function (Builder $q) use ($state, $frozenState, $sameState): void {
            $q->whereNull('p.PK')->orWhereRaw("NOT ({$state})")->orWhereRaw("NOT ({$frozenState})")->orWhereRaw("NOT ({$sameState})");
        };

        // Frozen/history phones are nvarchar in the published migrations while
        // prospects may be varchar. Compare a common, lossless Unicode width,
        // not native storage bytes. Keep exact ASCII digits and trailing-space
        // rejection; SQL Server string equality alone pads trailing spaces.
        $phoneValid = self::phoneValidSql($native);
        $samePhone = self::samePhoneSql($native);
        $contactPhone = $native ? "(c.Phone=x.Phone OR c.Phone='1'+x.Phone)"
            : "(c.Phone=x.Phone OR c.Phone='1'||x.Phone)";
        $hint = $locking ? ' WITH (UPDLOCK,HOLDLOCK)' : '';
        $contactRows = config('sms-exports.wide_phone_contacts_enabled', false)
            ? SmsPhoneNumbersSql::from('c', $hint, true) : 'TblPhoneNumbers as c'.$hint;
        $memberBad = static function (Builder $q) use ($phoneValid, $samePhone, $contactPhone, $hint, $contactRows, $native): void {
            foreach (['Contacted','Opt_Out','Invalid_Phone'] as $flag) $q->orWhereNull('p.'.$flag)->orWhere('p.'.$flag, '<>', 0);
            $q->orWhereNull('p.Phone')->orWhereNull('x.Phone')->orWhereRaw("NOT ({$phoneValid})")->orWhereRaw("NOT ({$samePhone})");
            if (! $native) $q->orWhereExists(fn (Builder $s) => $s->selectRaw('1')->fromRaw('TblSMSSuppressions as s'.$hint)
                    ->whereColumn('s.Phone', 'x.Phone')->where(fn (Builder $flags) => $flags->where('s.Opt_Out', 1)->orWhere('s.Invalid_Phone', 1)))
                ->orWhereExists(fn (Builder $c) => $c->selectRaw('1')->fromRaw($contactRows)->whereRaw($contactPhone));
        };
        // One pass over the batch for State and for the flags and phone together. Each pass looks every member up in
        // TblProspects by key: on the 3M run of Nov 18, 2026 that was about 100 seconds warm and up to 7 minutes cold, and
        // the two ran one after the other. Only when someone is wrong are they read apart, to say which, State first as before.
        SmsStepLog::mark($requestId, 'check_members');
        $unsafeMember = false;
        $unproven = false;
        if ($members()->where(static function (Builder $q) use ($stateBad, $memberBad, $stored): void {
            $q->where($stateBad)->orWhere($memberBad);
            if ($stored !== null) $q->orWhereRaw($stored['sql'], $stored['bindings']);
        })->exists()) {
            if ($members()->where($stateBad)->exists()) {
                throw new SmsFrozenEligibilityHold('A frozen SMS prospect State is missing, invalid, excluded or changed. The batch was held without changing membership.');
            }
            $unsafeMember = $members()->where($memberBad)->exists();
            if ($stored !== null) $unproven = $members()->whereRaw($stored['sql'], $stored['bindings'])->exists();
        }
        SmsStepLog::mark($requestId, 'check_suppressions');
        // Keep suppression probes separate from the per-member OR predicate.
        // A row-goal nested-loop plan otherwise repeats every wide-phone branch
        // millions of times. Equality hash joins retain the identical raw-phone
        // semantics (including country prefix, source branches and lock hints).
        $unsafeSuppression = ! $unsafeMember && $native && $db->selectOne("SELECT TOP(1) found
FROM (
 SELECT 1 AS found FROM {$table} AS x
 JOIN TblSMSSuppressions AS s{$hint} ON s.Phone=x.Phone
 WHERE x.Request_ID=? AND (s.Opt_Out=1 OR s.Invalid_Phone=1)
 UNION ALL
 SELECT 1 AS found FROM {$table} AS x
 JOIN {$contactRows} ON c.Phone=x.Phone WHERE x.Request_ID=?
 UNION ALL
 SELECT 1 AS found FROM {$table} AS x
 JOIN {$contactRows} ON c.Phone='1'+x.Phone WHERE x.Request_ID=?
) AS unsafe_phone OPTION (HASH JOIN)", [$requestId, $requestId, $requestId], false) !== null;
        if ($unsafeMember || $unsafeSuppression) {
            throw new SmsFrozenEligibilityHold('A frozen SMS member is contacted, suppressed, invalid or has a changed phone. The batch was held without changing membership.');
        }

        return $unproven;
    }
}
