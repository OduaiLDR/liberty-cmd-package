<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Whether the approved files are published and emailed is chosen by the person who approves (Monther, Nov 18, 2026:
 * "add a check box when approve to select if you want to send emails or what"; Jude the same day: "we dont want it to be
 * emailed automatically, i still want the files and i will send them to the vendor").
 *
 * The choice is one nullable column on the run, written when Approve is accepted and read when the approval commits,
 * so it survives a retry of the approval. Before the column exists nothing changes: the switch alone decides, as before.
 * With the column, the email goes only for a run whose approval asked for it.
 */
final class SmsRunHandoffChoice
{
    public const COLUMN = 'Handoff_Requested';

    /** Publishing and the email are both set up and the automatic handoff is switched on. */
    public static function switchedOn(): bool
    {
        return config('sms-exports.handoff_automatic', false) === true
            && config('sms-exports.file_publication_enabled', false) === true
            && config('sms-exports.email_handoff_enabled', false) === true;
    }

    /** The run table can hold the choice. Read each time: the column is added by hand on the live database. */
    public static function stored(): bool
    {
        try {
            return DB::connection('sqlsrv')->getSchemaBuilder()->hasColumn('TblSMSRuns', self::COLUMN);
        } catch (Throwable) {
            return false;
        }
    }

    /** The person approving is asked. */
    public static function asked(): bool
    {
        return self::switchedOn() && self::stored();
    }

    /** At the approval commit: does this run get the handoff? Without the column every approval does, as before. */
    public static function wanted(object $run, ?bool $stored = null): bool
    {
        return ! ($stored ?? self::stored()) || (int) ($run->{self::COLUMN} ?? 0) === 1;
    }

    /**
     * For the page: what happens to the files after approval.
     * off: nothing starts by itself. automatic: every approval is published and emailed.
     * choice: the person approving decides. requested / not_requested: what they decided.
     */
    public static function afterApproval(object $run, ?bool $stored = null): string
    {
        if (! self::switchedOn()) return 'off';
        if (! ($stored ?? self::stored())) return 'automatic';
        if (! in_array($run->Status ?? null, ['approving', 'approved'], true)) return 'choice';

        return (int) ($run->{self::COLUMN} ?? 0) === 1 ? 'requested' : 'not_requested';
    }
}
