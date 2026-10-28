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
        self::assertMembers($db, $requestId, 'TblProspectsExport');
        SmsDebtPolicy::assertPending($db, $requestId);
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

    private static function assertMembers(ConnectionInterface $db, string $requestId, string $table): void
    {
        $native = $db->getDriverName() === 'sqlsrv';
        $locking = $native && $db->transactionLevel() > 0;
        if ($locking) {
            // Current prospect rows and frozen rows remain locked through the caller's
            // approval transaction. No broad flag update or membership mutation occurs.
            $db->select("SELECT p.PK FROM TblProspects p WITH (UPDLOCK,HOLDLOCK)
JOIN {$table} x WITH (HOLDLOCK) ON x.Prospect_PK=p.PK
WHERE x.Request_ID=? ORDER BY p.PK", [$requestId], false);
        }
        $members = fn (): Builder => $db->table($table.' as x')->useWritePdo()
            ->leftJoin('TblProspects as p', 'p.PK', '=', 'x.Prospect_PK')->where('x.Request_ID', $requestId);
        $state = SmsStatePolicy::sqlPredicate('p.State', $db->getDriverName());
        $frozenState = SmsStatePolicy::sqlPredicate('x.State', $db->getDriverName());
        $sameState = SmsStatePolicy::normalizedSql('p.State', $db->getDriverName()).' = '.SmsStatePolicy::normalizedSql('x.State', $db->getDriverName());
        if ($members()->where(function (Builder $q) use ($state, $frozenState, $sameState): void {
            $q->whereNull('p.PK')->orWhereRaw("NOT ({$state})")->orWhereRaw("NOT ({$frozenState})")->orWhereRaw("NOT ({$sameState})");
        })->exists()) {
            throw new SmsFrozenEligibilityHold('A frozen SMS prospect State is missing, invalid, excluded or changed. The batch was held without changing membership.');
        }

        // Frozen/history phones are nvarchar in the published migrations while
        // prospects may be varchar. Compare a common, lossless Unicode width,
        // not native storage bytes. Keep exact ASCII digits and trailing-space
        // rejection; SQL Server string equality alone pads trailing spaces.
        $phoneValid = $native ? "DATALENGTH(CONVERT(nvarchar(max),x.Phone))=20 AND CONVERT(nvarchar(max),x.Phone) COLLATE Latin1_General_100_BIN2 LIKE '".str_repeat('[0-9]', 10)."'"
            : "length(x.Phone)=10 AND x.Phone NOT GLOB '*[^0-9]*'";
        $samePhone = $native ? 'CONVERT(nvarchar(max),p.Phone) COLLATE Latin1_General_100_BIN2 = CONVERT(nvarchar(max),x.Phone) COLLATE Latin1_General_100_BIN2 AND DATALENGTH(CONVERT(nvarchar(max),p.Phone))=DATALENGTH(CONVERT(nvarchar(max),x.Phone))'
            : 'p.Phone COLLATE BINARY = x.Phone COLLATE BINARY';
        $contactPhone = $native ? "(c.Phone=x.Phone OR c.Phone='1'+x.Phone)"
            : "(c.Phone=x.Phone OR c.Phone='1'||x.Phone)";
        $hint = $locking ? ' WITH (UPDLOCK,HOLDLOCK)' : '';
        $contactRows = config('sms-exports.wide_phone_contacts_enabled', false)
            ? SmsPhoneNumbersSql::from('c', $hint, true) : 'TblPhoneNumbers as c'.$hint;
        if ($members()->where(function (Builder $q) use ($phoneValid, $samePhone, $contactPhone, $hint, $contactRows): void {
            foreach (['Contacted','Opt_Out','Invalid_Phone'] as $flag) $q->orWhereNull('p.'.$flag)->orWhere('p.'.$flag, '<>', 0);
            $q->orWhereNull('p.Phone')->orWhereNull('x.Phone')->orWhereRaw("NOT ({$phoneValid})")->orWhereRaw("NOT ({$samePhone})")
                ->orWhereExists(fn (Builder $s) => $s->selectRaw('1')->fromRaw('TblSMSSuppressions as s'.$hint)
                    ->whereColumn('s.Phone', 'x.Phone')->where(fn (Builder $flags) => $flags->where('s.Opt_Out', 1)->orWhere('s.Invalid_Phone', 1)))
                ->orWhereExists(fn (Builder $c) => $c->selectRaw('1')->fromRaw($contactRows)->whereRaw($contactPhone));
        })->exists()) {
            throw new SmsFrozenEligibilityHold('A frozen SMS member is contacted, suppressed, invalid or has a changed phone. The batch was held without changing membership.');
        }
    }
}
