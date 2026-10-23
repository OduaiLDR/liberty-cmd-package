<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

use RuntimeException;

/** Jacob's ordered source waterfall. PK only breaks agreeing ties within a source. */
final class SmsCurrentDebtSql
{
    /** Approved tier-only mailer fallback, never a debt-to-tier calculation. */
    public const TIER_AMOUNTS = [
        'T1' => 10000, 'T2' => 13500, 'T3' => 17000, 'T4' => 22500,
        'T5' => 30500, 'T6' => 42500, 'T7' => 57500, 'T8' => 72500, 'T9' => 85000,
    ];

    /** Both writers use this exact validity rule; the resolver alias is always r. */
    public const VALID_PREDICATE = 'r.Winner=1 AND r.Unknown_Date=0 AND r.Source_Date IS NOT NULL
  AND r.Debt>0 AND r.Debt<=9999999000 AND r.Tier IS NOT NULL
  AND r.Revisions=r.Debts AND r.Revisions=r.Tiers AND r.Min_Debt=r.Max_Debt AND r.Min_Tier=r.Max_Tier';

    public const ROUNDED_DEBT_SQL = 'CAST(CEILING(r.Debt/CAST(1000 AS decimal(12,2)))*1000 AS decimal(12,2))';

    public static function resolve(string $mailerDate = 'Send_Date'): string
    {
        $ctes = self::withResolved(<<<'SQL'
    SELECT x.PK AS Export_PK, x.Client, x.Address, LEFT(x.Zip,5) AS Zip
    FROM TblProspectsExport x WHERE x.Request_ID=? AND x.Debt_Source IS NULL
SQL, $mailerDate);
        $debt = self::ROUNDED_DEBT_SQL;
        $valid = self::VALID_PREDICATE;
        return $ctes.<<<SQL

UPDATE x SET Debt_Amount={$debt},
    Debt_Tier=r.Tier, Debt_Source=r.Source, Debt_Source_PK=r.Source_PK
FROM TblProspectsExport x JOIN Resolved r ON r.Export_PK=x.PK
WHERE {$valid}
OPTION (HASH JOIN)
SQL;
    }

    /**
     * Reusable, unchanged waterfall over a TRUSTED internal SELECT named Wanted.
     * Required columns: unique Export_PK, Client, Address and ZIP5 as Zip.
     * Never pass request text or an unvalidated identifier as $wantedSql.
     */
    public static function withResolved(string $wantedSql, string $mailerDate = 'Send_Date'): string
    {
        if ($mailerDate !== 'Send_Date') {
            throw new RuntimeException('SMS mailer ordering must use Jacob\'s confirmed TblMarketing.Send_Date. Remove any obsolete mailer-clock override.');
        }
        if (trim($wantedSql) === '') throw new RuntimeException('The SMS source resolver requires a trusted candidate SELECT.');
        $tuTier = SmsProspectValues::tierSql('t.Tier_Tag');
        $mailerTier = SmsProspectValues::tierSql('m.Debt_Tier');
        $tierValues = implode(',', array_map(
            static fn (string $tier, int $amount): string => "('{$tier}',CAST({$amount} AS decimal(18,2)))",
            array_keys(self::TIER_AMOUNTS), array_values(self::TIER_AMOUNTS)
        ));
        return <<<SQL
WITH Wanted AS (
{$wantedSql}
), MailDates AS (
    SELECT Drop_Name, CASE WHEN COUNT(*)=COUNT(Send_Date) AND MIN(Send_Date)=MAX(Send_Date)
        THEN MAX(Send_Date) END AS Source_Date
    FROM TblMarketing GROUP BY Drop_Name
), TierAmounts AS (
    SELECT v.Tier,v.Debt FROM (VALUES {$tierValues}) v(Tier,Debt)
), TU AS (
    SELECT w.Export_PK, t.PK AS Source_PK, t.Creation_Date AS Source_Date,
        t.derivedFields1_unsecdebtbalance AS Debt, {$tuTier} AS Tier
    FROM Wanted w JOIN TblTUData t ON t.ncoaAddr_addrLine1Txt=w.Address AND LEFT(t.ncoaAddr_zipCde,5)=w.Zip
    WHERE t.ncoaAddr_frstNme<>'' AND t.ncoaAddr_lastNme<>''
      AND LEFT(w.Client,LEN(t.ncoaAddr_frstNme)+1)=t.ncoaAddr_frstNme+' '
      AND RIGHT(w.Client,LEN(t.ncoaAddr_lastNme)+1)=' '+t.ncoaAddr_lastNme
), Mailers AS (
    SELECT w.Export_PK, m.PK AS Source_PK, d.Source_Date, m.Debt_Amount AS Debt, {$mailerTier} AS Tier
    FROM Wanted w JOIN TblMailers m ON m.Client=w.Client AND m.Address=w.Address AND LEFT(m.Zip,5)=w.Zip
    LEFT JOIN MailDates d ON d.Drop_Name=m.Drop_Name
    WHERE NOT EXISTS(SELECT 1 FROM TU t WHERE t.Export_PK=w.Export_PK)
), Candidates AS (
    SELECT Export_PK, 'TblTUData' AS Source, Source_PK, Source_Date, Debt, Tier, 0 AS Stage
    FROM TU
    UNION ALL
    SELECT Export_PK, 'TblMailers', Source_PK, Source_Date, Debt, Tier, 1
    FROM Mailers WHERE Debt>0
    UNION ALL
    SELECT m.Export_PK, 'Tier', m.Source_PK, m.Source_Date, a.Debt, m.Tier, 2
    FROM Mailers m LEFT JOIN TierAmounts a ON a.Tier=m.Tier
), Priorities AS (
    SELECT *, MIN(Stage) OVER(PARTITION BY Export_PK) AS Chosen_Stage FROM Candidates
), Chosen AS (
    SELECT *, MAX(CASE WHEN Source_Date IS NULL THEN 1 ELSE 0 END)
        OVER(PARTITION BY Export_PK) AS Unknown_Date
    FROM Priorities WHERE Stage=Chosen_Stage
), Latest AS (
    SELECT *, DENSE_RANK() OVER(PARTITION BY Export_PK ORDER BY Source_Date DESC) AS Recency
    FROM Chosen
), Resolved AS (
    SELECT *, ROW_NUMBER() OVER(PARTITION BY Export_PK ORDER BY Source_PK DESC) AS Winner,
        MIN(Debt) OVER(PARTITION BY Export_PK) AS Min_Debt, MAX(Debt) OVER(PARTITION BY Export_PK) AS Max_Debt,
        MIN(Tier) OVER(PARTITION BY Export_PK) AS Min_Tier, MAX(Tier) OVER(PARTITION BY Export_PK) AS Max_Tier,
        COUNT(*) OVER(PARTITION BY Export_PK) AS Revisions,
        COUNT(Debt) OVER(PARTITION BY Export_PK) AS Debts,
        COUNT(Tier) OVER(PARTITION BY Export_PK) AS Tiers
    FROM Latest WHERE Recency=1
)
SQL;
    }
}
