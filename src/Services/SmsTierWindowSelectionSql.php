<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

use RuntimeException;

/** Resolve and append one already-prioritized candidate window. */
final class SmsTierWindowSelectionSql
{
    /**
     * #SmsTierWanted is connection-local and populated by the repository from
     * the exact eligible keyset sequence. The caller owns SNAPSHOT/replay and
     * repeats this statement until target rows are frozen or candidates end.
     *
     * Binding order: remaining quantity, request ID, then canonical tiers.
     */
    public static function insert(array $tiers, string $mailerDate = 'Send_Date'): string
    {
        if (! array_is_list($tiers) || $tiers === [] || count($tiers) > count(SmsRunOptions::TIERS)) {
            throw new RuntimeException('Choose a nonempty unique list of tiers from T1 to T9.');
        }
        $seen = [];
        foreach ($tiers as $tier) {
            if (! is_string($tier) || ! in_array($tier, SmsRunOptions::TIERS, true) || isset($seen[$tier])) {
                throw new RuntimeException('Choose only unique tiers from T1 to T9.');
            }
            $seen[$tier] = true;
        }

        $wanted = <<<'SQL'
    SELECT Export_PK, Client, Address, Zip
    FROM #SmsTierWanted
SQL;
        $ctes = SmsCurrentDebtSql::withResolved($wanted, $mailerDate, true);
        $debt = SmsCurrentDebtSql::ROUNDED_DEBT_SQL;
        $valid = SmsCurrentDebtSql::VALID_PREDICATE;
        $tierBindings = implode(',', array_fill(0, count($tiers), '?'));

        return $ctes.<<<SQL

INSERT INTO TblProspectsExport (Request_ID, Prospect_PK, Phone, Debt_Tier, Debt_Amount,
    Debt_Source, Debt_Source_PK, Client, Address, City, State, Zip, Email,
    Was_Previously_Texted, Last_SMS_Date_At_Selection)
SELECT TOP (?) ?, w.Export_PK, w.Phone, r.Tier, {$debt},
    r.Source, r.Source_PK, w.Client, w.Address, w.City, w.State, w.Snapshot_Zip, w.Email,
    CASE WHEN w.Last_SMS_Date IS NULL THEN 0 ELSE 1 END, w.Last_SMS_Date
FROM #SmsTierWanted w JOIN Resolved r ON r.Export_PK=w.Export_PK
WHERE {$valid}
  AND r.Tier IN ({$tierBindings})
ORDER BY w.Window_Position ASC
OPTION (RECOMPILE)
SQL;
    }
}
