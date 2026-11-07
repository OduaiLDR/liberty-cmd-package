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

    public static function selectionEnabled(): bool
    {
        return config('sms-exports.stored_debt_selection', false) === true;
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
