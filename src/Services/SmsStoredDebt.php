<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/**
 * Debt, tier and source stored on TblProspects (Jacob, Nov 7, 2026).
 *
 * A run reads these with no live check. The weekly TU import is what changes them, and a newer
 * mailer doesn't. Every value comes from the same rule, SmsCurrentDebtSql, so there is no second
 * version of it. A NULL source means not worked out yet. 'None' means worked out with no usable
 * value, which covers people held by the rule, so a held person stays out of every run.
 */
final class SmsStoredDebt
{
    public const COLUMNS = ['Current_Debt', 'Current_Tier', 'Current_Debt_Source', 'Current_Debt_Source_PK'];

    public const NO_VALUE = 'None';

    public const SOURCES = ['TblTUData', 'TblMailers', 'Tier'];

    /** Debt is raw here and rounds at freeze, the same as the live rule. */
    public const ROUNDED_DEBT_SQL = 'CAST(CEILING(w.Current_Debt/CAST(1000 AS decimal(12,2)))*1000 AS decimal(12,2))';

    /** The refresh's saved progress, relative to storage_path(); the command and the run guard read the same file. */
    public const STATE_FILE = 'app/private/sms-stored-debt/state.json';

    /** The tables stored values point into (Current_Debt_Source_PK). */
    public const SOURCE_TABLES = ['TblTUData', 'TblMailers'];

    /** Who can be texted, in the same words as the two priority indexes and the refresh. */
    public const ELIGIBLE_SQL = 'Contacted=0 AND Opt_Out=0 AND Invalid_Phone=0 AND Phone IS NOT NULL';

    public static function selectionEnabled(): bool
    {
        return config('sms-exports.stored_debt_selection', false) === true;
    }

    public static function statePath(): string
    {
        return storage_path(self::STATE_FILE);
    }

    /**
     * Identity and highest row of each source table (plan review M2, Nov 11 2026): a swapped table has another
     * OBJECT_ID even when its count and highest PK match, and a reload usually brings the highest PK down.
     *
     * @return array<string, array{object_id: int, max_pk: int}>
     */
    public static function sourceMarks(ConnectionInterface $db): array
    {
        $marks = [];
        foreach (self::SOURCE_TABLES as $table) {
            $row = $db->selectOne("SELECT OBJECT_ID(N'dbo.{$table}') AS object_id, (SELECT MAX(PK) FROM dbo.{$table}) AS max_pk");
            $marks[$table] = ['object_id' => (int) ($row->object_id ?? 0), 'max_pk' => (int) ($row->max_pk ?? 0)];
        }

        return $marks;
    }

    /** Why stored values no longer fit the live tables, or null when they still do. Tables never saved are not judged. */
    public static function sourceChange(array $saved, array $live): ?string
    {
        foreach (self::SOURCE_TABLES as $table) {
            if (! is_array($saved[$table] ?? null)) continue;
            if ((int) $saved[$table]['object_id'] !== (int) ($live[$table]['object_id'] ?? 0)) {
                return "{$table} was replaced after the stored debt was worked out.";
            }
            if ((int) ($live[$table]['max_pk'] ?? 0) < (int) $saved[$table]['max_pk']) {
                return "{$table} was reloaded (its highest row number went down) after the stored debt was worked out.";
            }
        }

        return null;
    }

    /**
     * Refuses a run on stored values that can't be trusted: a source table was swapped or reloaded since they were
     * worked out, or TU rows arrived that the refresh hasn't handled yet (those people would keep an old debt or be
     * missing). New mailers don't count: the refresh never starts on them (Jacob, Nov 7). No state file or another
     * database driver: nothing to compare, so nothing is refused.
     */
    public static function assertCurrent(ConnectionInterface $db, ?string $statePath = null): void
    {
        $waiting = self::waiting($db, $statePath);
        if ($waiting !== null) throw new RuntimeException($waiting['message']);
    }

    /**
     * What a new run has to wait for, or null when the stored values are current. The same answer stops the run at
     * the click (assertCurrent) and tells the page why Generate is off, so nobody has to ask (Monther, Nov 18, 2026:
     * "next time i wanna jude make everything without ask me").
     *
     * new_people: prospects added since the refresh last finished that can be texted and have no stored value yet.
     * They only ever arrive with a higher PK, so only the rows above the saved mark are read. A run started before
     * they are filled would leave them out without a word.
     *
     * @return array{reason:string, message:string, tu_rows?:int, people?:int}|null
     */
    public static function waiting(ConnectionInterface $db, ?string $statePath = null): ?array
    {
        if ($db->getDriverName() !== 'sqlsrv') return null;
        $path = $statePath ?? self::statePath();
        if (! is_file($path)) return null;
        $state = json_decode((string) file_get_contents($path), true);
        if (! is_array($state)) {
            return ['reason' => 'unreadable', 'message' => 'The stored debt state file is unreadable, so a run on stored debt is stopped. Look at it first.'];
        }
        $live = self::sourceMarks($db);
        if (is_array($state['sources'] ?? null) && ($change = self::sourceChange($state['sources'], $live)) !== null) {
            return ['reason' => 'source_changed', 'message' => $change.' Runs on stored debt are stopped until it is worked out again.'];
        }
        if (isset($state['tu_watermark']) && $live['TblTUData']['max_pk'] > (int) $state['tu_watermark']) {
            $rows = $live['TblTUData']['max_pk'] - (int) $state['tu_watermark'];

            return ['reason' => 'new_tu_rows', 'tu_rows' => $rows, 'message' => sprintf(
                'Stored debt is still catching up with %s new TU rows. It is worked out in the background; generate again when it is done.', number_format($rows))];
        }
        if (isset($state['prospects_watermark'])) {
            $mark = (int) $state['prospects_watermark'];
            $newest = (int) ($db->selectOne('SELECT MAX(PK) AS newest FROM dbo.TblProspects')->newest ?? 0);
            if ($newest > $mark) {
                $people = (int) ($db->selectOne('SELECT COUNT_BIG(*) AS people FROM dbo.TblProspects WITH (INDEX(1)) WHERE PK>? AND PK<=? AND '
                    .self::ELIGIBLE_SQL.' AND Current_Debt_Source IS NULL', [$mark, $newest])->people ?? 0);
                if ($people > 0) {
                    return ['reason' => 'new_people', 'people' => $people, 'message' => sprintf(
                        '%s new people from TU are still being given their debt and tier. It is worked out in the background; generate again when it is done.', number_format($people))];
                }
            }
        }

        return null;
    }

    /** Refuses a run that would silently fall back or read columns that aren't there. */
    public static function assertReady(ConnectionInterface $db): void
    {
        if ($db->getDriverName() !== 'sqlsrv') throw new RuntimeException('Stored SMS debt requires SQL Server.');
        $found = [];
        foreach ($db->select("SELECT c.name,t.name AS type_name,c.max_length,c.precision,c.scale,c.is_nullable
            FROM sys.columns c JOIN sys.types t ON t.user_type_id=c.user_type_id
            WHERE c.object_id=OBJECT_ID(N'dbo.TblProspects') AND c.name IN (N'Current_Debt',N'Current_Tier',N'Current_Debt_Source',N'Current_Debt_Source_PK')") as $column) {
            $found[$column->name] = $column;
        }
        $shape = static fn (string $name, string $type, int $length): bool => isset($found[$name]) && $found[$name]->type_name === $type
            && (int) $found[$name]->max_length === $length && (bool) $found[$name]->is_nullable;
        if (! $shape('Current_Debt', 'decimal', 9) || (int) ($found['Current_Debt']->precision ?? 0) !== 18 || (int) ($found['Current_Debt']->scale ?? 0) !== 2
            || ! $shape('Current_Tier', 'char', 2) || ! $shape('Current_Debt_Source', 'varchar', 10) || ! $shape('Current_Debt_Source_PK', 'int', 4)) {
            throw new RuntimeException('Stored SMS debt columns are missing or differ from the reviewed shape.');
        }
    }
}
