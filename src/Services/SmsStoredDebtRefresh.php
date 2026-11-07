<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;

/**
 * Works out stored debt for a list of prospects and writes it, using the rule a run uses.
 *
 * It reads first and writes after, so the slow lookup holds no lock on TblProspects, and each
 * write is one short statement on the primary key. It only ever touches the four stored columns.
 * Running it twice changes nothing the second time, so a stop or crash is safe at any point.
 */
final class SmsStoredDebtRefresh
{
    /** Stays under SQL Server's 5,000 lock mark, so a write never takes a table lock. */
    public const WRITE_PAGE = 2000;

    public function __construct(private readonly ConnectionInterface $db) {}

    /**
     * @param  list<int>  $prospectPks
     * @return array{people:int, written:int, resolved:int, no_value:int}
     */
    public function refresh(array $prospectPks): array
    {
        $people = $this->normalize($prospectPks);
        if ($people === []) return ['people' => 0, 'written' => 0, 'resolved' => 0, 'no_value' => 0];
        $this->resolve($people);
        $noValue = (int) $this->db->selectOne("SELECT COUNT(*) AS n FROM #SmsStoredResolved WHERE Source=?", [SmsStoredDebt::NO_VALUE])->n;
        $written = 0;
        foreach (array_chunk($people, self::WRITE_PAGE) as $page) {
            $written += $this->write((int) $page[0], (int) $page[count($page) - 1]);
        }
        $this->db->unprepared('DROP TABLE IF EXISTS #SmsStoredResolved; DROP TABLE IF EXISTS #SmsStoredPeople;');

        return ['people' => count($people), 'written' => $written, 'resolved' => count($people) - $noValue, 'no_value' => $noValue];
    }

    /**
     * Same lookup, no write. Used by the one comparison after the back fill.
     *
     * @param  list<int>  $prospectPks
     * @return array{people:int, same:int, never_filled:int, debt_differs:int, tier_differs:int, source_differs:int}
     */
    public function compare(array $prospectPks): array
    {
        $people = $this->normalize($prospectPks);
        $empty = ['people' => 0, 'same' => 0, 'never_filled' => 0, 'debt_differs' => 0, 'tier_differs' => 0, 'source_differs' => 0];
        if ($people === []) return $empty;
        $this->resolve($people);
        $row = $this->db->selectOne("SELECT COUNT(*) AS people,
            COALESCE(SUM(CASE WHEN p.Current_Debt_Source IS NULL THEN 1 ELSE 0 END),0) AS never_filled,
            COALESCE(SUM(CASE WHEN p.Current_Debt_Source IS NOT NULL AND ISNULL(p.Current_Debt,-1)<>ISNULL(r.Debt,-1) THEN 1 ELSE 0 END),0) AS debt_differs,
            COALESCE(SUM(CASE WHEN p.Current_Debt_Source IS NOT NULL AND ISNULL(p.Current_Tier,'')<>ISNULL(r.Tier,'') THEN 1 ELSE 0 END),0) AS tier_differs,
            COALESCE(SUM(CASE WHEN p.Current_Debt_Source IS NOT NULL AND (p.Current_Debt_Source<>r.Source OR ISNULL(p.Current_Debt_Source_PK,0)<>ISNULL(r.Source_PK,0)) THEN 1 ELSE 0 END),0) AS source_differs,
            COALESCE(SUM(CASE WHEN p.Current_Debt_Source=r.Source AND ISNULL(p.Current_Debt,-1)=ISNULL(r.Debt,-1) AND ISNULL(p.Current_Tier,'')=ISNULL(r.Tier,'') AND ISNULL(p.Current_Debt_Source_PK,0)=ISNULL(r.Source_PK,0) THEN 1 ELSE 0 END),0) AS same
            FROM #SmsStoredResolved r JOIN dbo.TblProspects p ON p.PK=r.Export_PK");
        $this->db->unprepared('DROP TABLE IF EXISTS #SmsStoredResolved; DROP TABLE IF EXISTS #SmsStoredPeople;');

        return array_map('intval', (array) $row) + $empty;
    }

    /** @return list<int> */
    private function normalize(array $prospectPks): array
    {
        foreach ($prospectPks as $pk) {
            if (! is_int($pk) && ! (is_string($pk) && ctype_digit($pk))) throw new InvalidArgumentException('Stored SMS debt takes prospect PKs only.');
        }
        $people = array_values(array_unique(array_map('intval', $prospectPks)));
        sort($people);
        if ($people !== [] && $people[0] < 1) throw new InvalidArgumentException('Stored SMS debt takes prospect PKs only.');

        return $people;
    }

    /** @param list<int> $people */
    private function resolve(array $people): void
    {
        $db = $this->db;
        $db->unprepared("DROP TABLE IF EXISTS #SmsStoredResolved; DROP TABLE IF EXISTS #SmsStoredPeople;
            CREATE TABLE #SmsStoredPeople (Prospect_PK int NOT NULL PRIMARY KEY);
            CREATE TABLE #SmsStoredResolved (Export_PK int NOT NULL PRIMARY KEY, Debt decimal(18,2) NULL, Tier char(2) COLLATE database_default NULL,
                Source varchar(10) COLLATE database_default NOT NULL, Source_PK int NULL);");
        foreach (array_chunk($people, 1000) as $page) {
            // Validated integers, so a plain list is safe and needs no 2,100 parameter limit.
            $db->unprepared('INSERT #SmsStoredPeople (Prospect_PK) VALUES ('.implode('),(', $page).')');
        }
        $wanted = <<<'SQL'
    SELECT p.PK AS Export_PK, p.Client, p.Address, LEFT(p.Zip,5) AS Zip
    FROM #SmsStoredPeople x JOIN dbo.TblProspects p ON p.PK=x.Prospect_PK
SQL;
        $ctes = SmsCurrentDebtSql::withResolved($wanted, 'Send_Date', true);
        $valid = SmsCurrentDebtSql::VALID_PREDICATE;
        $none = SmsStoredDebt::NO_VALUE;
        $db->unprepared($ctes.<<<SQL

INSERT #SmsStoredResolved (Export_PK, Debt, Tier, Source, Source_PK)
SELECT w.Export_PK,
    CASE WHEN {$valid} THEN r.Debt END,
    CASE WHEN {$valid} THEN r.Tier END,
    CASE WHEN {$valid} THEN r.Source ELSE '{$none}' END,
    CASE WHEN {$valid} AND r.Source<>'Tier' THEN r.Source_PK END
FROM Wanted w LEFT JOIN Resolved r ON r.Export_PK=w.Export_PK AND r.Winner=1
OPTION (RECOMPILE)
SQL);
    }

    /** One statement on the primary key, and only rows whose value really changed. */
    private function write(int $first, int $last): int
    {
        return $this->db->update("UPDATE p SET p.Current_Debt=r.Debt, p.Current_Tier=r.Tier, p.Current_Debt_Source=r.Source, p.Current_Debt_Source_PK=r.Source_PK
FROM #SmsStoredResolved r INNER LOOP JOIN dbo.TblProspects p ON p.PK=r.Export_PK
WHERE r.Export_PK>=? AND r.Export_PK<=?
  AND (p.Current_Debt_Source IS NULL OR p.Current_Debt_Source<>r.Source
    OR ISNULL(p.Current_Debt_Source_PK,0)<>ISNULL(r.Source_PK,0)
    OR ISNULL(p.Current_Debt,-1)<>ISNULL(r.Debt,-1)
    OR ISNULL(p.Current_Tier,'')<>ISNULL(r.Tier,''))
OPTION (FORCE ORDER, MAXDOP 1)", [$first, $last]);
    }
}
