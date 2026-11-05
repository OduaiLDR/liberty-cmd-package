<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

use RuntimeException;

/** Resolve and append one already-prioritized candidate window. */
final class SmsTierWindowSelectionSql
{
    /**
     * One hash join finds everyone with a wanted mailer tier or debt, so a tier subset or debt minimum
     * doesn't resolve the whole pool person by person (3M T4-T9 would have meant checking 21M). A mailer
     * only narrows who gets checked: insert() still decides each person's current debt and tier. Someone
     * who qualifies only through TblTUData isn't a candidate.
     */
    public static function candidatesSql(array $tiers, int $minimumDebt = 0): string
    {
        $values = [];
        foreach ($tiers as $tier) {
            if (! is_string($tier) || ! in_array($tier, SmsRunOptions::TIERS, true)) {
                throw new RuntimeException('Choose only tiers from T1 to T9.');
            }
            $values[] = "'{$tier}'";
            $values[] = "'".substr($tier, 1)."'";
        }
        if ($values === []) throw new RuntimeException('Choose at least one tier.');
        $filters = [];
        if ($tiers !== SmsRunOptions::TIERS) $filters[] = 'Debt_Tier IN ('.implode(',', $values).')';
        if ($minimumDebt > 0) $filters[] = 'Debt_Amount>='.$minimumDebt;
        if ($filters === []) throw new RuntimeException('Narrowing needs a tier subset or a minimum debt.');
        $wanted = implode(' AND ', $filters);

        return <<<SQL
WITH m AS (SELECT DISTINCT Client, Address, SMS_Zip5 FROM TblMailers WHERE {$wanted})
INSERT #SmsTierCandidates (PK)
SELECT p.PK FROM TblProspects p JOIN m ON m.Client=p.Client AND m.Address=p.Address AND m.SMS_Zip5=LEFT(p.Zip,5)
WHERE p.Contacted=0 AND p.Opt_Out=0 AND p.Invalid_Phone=0 AND p.Phone IS NOT NULL
OPTION (HASH JOIN, MAXDOP 4)
SQL;
    }

    /**
     * #SmsTierWanted is connection-local and populated by the repository from
     * the exact eligible keyset sequence. The caller owns SNAPSHOT/replay and
     * repeats this statement until target rows are frozen or candidates end.
     *
     * Binding order: remaining quantity, request ID, canonical tiers, validated debt minimum,
     * then the frozen maximum only when $maximumDebt (SmsDebtPolicy::bindings()).
     */
    public static function insert(array $tiers, string $mailerDate = 'Send_Date', bool $leanDebtSources = false, bool $maximumDebt = false): string
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
        $sourcePk = $leanDebtSources ? "CASE WHEN r.Source='Tier' THEN NULL ELSE r.Source_PK END" : 'r.Source_PK';
        $tierBindings = implode(',', array_fill(0, count($tiers), '?'));
        // Runs without a frozen maximum keep their exact SQL text and binding count.
        $maximum = $maximumDebt ? "\n  AND r.Debt<=?" : '';

        return $ctes.<<<SQL

INSERT INTO TblProspectsExport (Request_ID, Prospect_PK, Phone, Debt_Tier, Debt_Amount,
    Debt_Source, Debt_Source_PK, Client, Address, City, State, Zip, Email,
    Was_Previously_Texted, Last_SMS_Date_At_Selection)
SELECT TOP (?) ?, w.Export_PK, w.Phone, r.Tier, {$debt},
    r.Source, {$sourcePk}, w.Client, w.Address, w.City, w.State, w.Snapshot_Zip, w.Email,
    CASE WHEN w.Last_SMS_Date IS NULL THEN 0 ELSE 1 END, w.Last_SMS_Date
FROM #SmsTierWanted w JOIN Resolved r ON r.Export_PK=w.Export_PK
WHERE {$valid}
  AND r.Tier IN ({$tierBindings})
  AND r.Debt>=?{$maximum}
ORDER BY w.Window_Position ASC
OPTION (RECOMPILE)
SQL;
    }
}
