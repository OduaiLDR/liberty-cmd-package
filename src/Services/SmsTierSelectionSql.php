<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

use RuntimeException;

/** SQL Server membership for explicitly versioned current-source-tier runs only. */
final class SmsTierSelectionSql
{
    /**
     * $eligibleSql is a trusted, unbounded internal eligibility SELECT, not request SQL.
     * It must provide unique PK, Phone, Client, Address, City, State, Zip, Email,
     * Creation_Date and Last_SMS_Date. Do not put a quantity limit in that query.
     *
     * Binding order: eligible-query bindings first, target, request ID, then tiers
     * in the supplied order, then the validated source-debt minimum.
     * Caller owns snapshot isolation, run serialization, transaction/replay policy.
     */
    public static function select(string $eligibleSql, array $tiers, string $mailerDate = 'Send_Date', bool $leanDebtSources = false): string
    {
        if (trim($eligibleSql) === '') throw new RuntimeException('Tier selection requires a trusted unbounded eligibility SELECT.');
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
        $wanted = <<<SQL
    SELECT e.PK AS Export_PK, e.Phone, e.Client, e.Address, e.City, e.State,
        e.Zip AS Snapshot_Zip, LEFT(e.Zip,5) AS Zip, e.Email, e.Creation_Date, e.Last_SMS_Date
    FROM ({$eligibleSql}) e
SQL;
        $ctes = SmsCurrentDebtSql::withResolved($wanted, $mailerDate);
        $debt = SmsCurrentDebtSql::ROUNDED_DEBT_SQL;
        $valid = SmsCurrentDebtSql::VALID_PREDICATE;
        $sourcePk = $leanDebtSources ? "CASE WHEN r.Source='Tier' THEN NULL ELSE r.Source_PK END" : 'r.Source_PK';
        $tierBindings = implode(',', array_fill(0, count($tiers), '?'));
        return $ctes.<<<SQL

INSERT INTO TblProspectsExport (Request_ID, Prospect_PK, Phone, Debt_Tier, Debt_Amount,
    Debt_Source, Debt_Source_PK, Client, Address, City, State, Zip, Email,
    Was_Previously_Texted, Last_SMS_Date_At_Selection)
SELECT TOP (?) ?, w.Export_PK, w.Phone, r.Tier, {$debt},
    r.Source, {$sourcePk}, w.Client, w.Address, w.City, w.State, w.Snapshot_Zip, w.Email,
    CASE WHEN w.Last_SMS_Date IS NULL THEN 0 ELSE 1 END, w.Last_SMS_Date
FROM Wanted w JOIN Resolved r ON r.Export_PK=w.Export_PK
WHERE {$valid}
  AND r.Tier IN ({$tierBindings})
  AND r.Debt>=?
ORDER BY CASE WHEN w.Last_SMS_Date IS NULL THEN 0 ELSE 1 END ASC,
    CASE WHEN w.Last_SMS_Date IS NULL THEN w.Creation_Date END DESC,
    w.Last_SMS_Date ASC, w.Export_PK ASC
OPTION (HASH JOIN)
SQL;
    }
}
