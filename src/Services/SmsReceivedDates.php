<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * date_first_received and date_last_received for the SMS vendor file (Sam, Nov 2026).
 *
 * First received is the day the person first came into TblProspects. Last received is the date of the newest record
 * the person's debt came from: the TU row's Creation_Date, or the mail drop's send date for a mailer row. Both source
 * tables are large heaps, so the date is read through a small covering index by PK and never from the heap; looking it
 * up by name and address instead costs about 2.5 ms a person (two hours for three million, measured Nov 17, 2026).
 */
final class SmsReceivedDates
{
    public const TU_INDEX = 'IX_TblTUData_PK_Creation_Date';

    public const MAILERS_INDEX = 'IX_TblMailers_PK_Drop_Name';

    /** The format of a new run: with the two dates only when switched on and both indexes are there. */
    public static function newRunFormat(): string
    {
        return self::enabled() && self::ready(DB::connection('sqlsrv'))
            ? SmsRunOptions::RECEIVED_DATES_CSV_FORMAT : SmsRunOptions::FIELD_NAME_CSV_FORMAT;
    }

    public static function enabled(): bool
    {
        return config('sms-exports.received_dates_enabled', false) === true;
    }

    /** Both covering indexes exist, enabled, keyed on PK alone and carrying the date column. Other drivers have no such index. */
    public static function ready(ConnectionInterface $db): bool
    {
        if ($db->getDriverName() !== 'sqlsrv') return true;
        try {
            $rows = $db->select("SELECT i.name AS Index_Name,
                    SUM(CASE WHEN ic.is_included_column = 0 THEN 1 ELSE 0 END) AS Key_Columns,
                    MAX(CASE WHEN ic.is_included_column = 0 AND ic.key_ordinal = 1 THEN c.name END) AS First_Key,
                    MAX(CASE WHEN ic.is_included_column = 1 AND c.name IN (N'Creation_Date', N'Drop_Name') THEN c.name END) AS Included
                FROM sys.indexes i
                JOIN sys.index_columns ic ON ic.object_id = i.object_id AND ic.index_id = i.index_id
                JOIN sys.columns c ON c.object_id = ic.object_id AND c.column_id = ic.column_id
                WHERE i.is_disabled = 0 AND i.has_filter = 0
                  AND ((i.object_id = OBJECT_ID(N'dbo.TblTUData') AND i.name = ?) OR (i.object_id = OBJECT_ID(N'dbo.TblMailers') AND i.name = ?))
                GROUP BY i.name", [self::TU_INDEX, self::MAILERS_INDEX]);
        } catch (Throwable) {
            return false;
        }
        $found = [];
        foreach ($rows as $row) {
            if ((int) $row->Key_Columns === 1 && $row->First_Key === 'PK') $found[(string) $row->Index_Name] = (string) $row->Included;
        }

        return ($found[self::TU_INDEX] ?? null) === 'Creation_Date' && ($found[self::MAILERS_INDEX] ?? null) === 'Drop_Name';
    }

    /**
     * Adds the joins for both dates to a query over TblProspectsExport and returns the two columns to select
     * (First_Received, Last_Received). On SQL Server the covering indexes are named, so a missing one fails the step
     * instead of reading the heaps.
     *
     * @return list<Expression>
     */
    public static function join(Builder $query, string $export): array
    {
        $sqlsrv = $query->getConnection()->getDriverName() === 'sqlsrv';
        $tu = 'TblTUData as rt'.($sqlsrv ? ' WITH (INDEX('.self::TU_INDEX.'))' : '');
        $mailers = 'TblMailers as rm'.($sqlsrv ? ' WITH (INDEX('.self::MAILERS_INDEX.'))' : '');
        // A drop's date counts only when all its marketing rows agree on it, as in SmsCurrentDebtSql.
        $drops = $query->getConnection()->table('TblMarketing')->groupBy('Drop_Name')->selectRaw(
            'Drop_Name, CASE WHEN COUNT(*)=COUNT(Send_Date) AND MIN(Send_Date)=MAX(Send_Date) THEN MAX(Send_Date) END AS Source_Date');
        $query->leftJoin('TblProspects as rp', 'rp.PK', '=', $export.'.Prospect_PK')
            ->leftJoin(new Expression($tu), static fn (JoinClause $join) => $join->on('rt.PK', '=', $export.'.Debt_Source_PK')
                ->whereRaw($export.".Debt_Source = 'TblTUData'"))
            ->leftJoin(new Expression($mailers), static fn (JoinClause $join) => $join->on('rm.PK', '=', $export.'.Debt_Source_PK')
                ->whereRaw($export.".Debt_Source IN ('TblMailers','Tier')"))
            ->leftJoinSub($drops, 'rd', 'rd.Drop_Name', '=', 'rm.Drop_Name');

        return [new Expression('rp.Creation_Date AS First_Received'),
            new Expression('COALESCE(rt.Creation_Date, rd.Source_Date, rp.Creation_Date) AS Last_Received')];
    }

    /** A database date as YYYY-MM-DD, or null. */
    public static function text(mixed $value): ?string
    {
        if ($value === null) return null;
        $text = substr(trim($value instanceof \DateTimeInterface ? $value->format('Y-m-d') : (string) $value), 0, 10);

        return $text === '' ? null : $text;
    }
}
