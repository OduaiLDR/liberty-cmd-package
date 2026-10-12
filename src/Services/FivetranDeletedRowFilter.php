<?php

namespace Cmd\Reports\Services;

/**
 * Applies Fivetran soft-delete filtering to Snowflake table relations.
 *
 * Filtering each source as a derived table keeps LEFT JOIN behavior intact: a deleted
 * child row is treated as absent without removing its parent row from the result.
 */
final class FivetranDeletedRowFilter
{
    private const CLAUSE_KEYWORDS = [
        'WHERE', 'JOIN', 'LEFT', 'RIGHT', 'FULL', 'INNER', 'CROSS', 'ON', 'USING',
        'QUALIFY', 'ORDER', 'GROUP', 'LIMIT', 'UNION', 'EXCEPT', 'INTERSECT',
        'SAMPLE', 'TABLESAMPLE', 'PIVOT', 'UNPIVOT', 'MATCH_RECOGNIZE', 'CONNECT',
    ];

    /**
     * @param list<string> $tablesWithDeletedMarker
     * @return array{sql: string, tables: list<string>}
     */
    public static function apply(string $sql, array $tablesWithDeletedMarker): array
    {
        if ($tablesWithDeletedMarker === []) {
            return ['sql' => $sql, 'tables' => []];
        }

        $markerTables = array_fill_keys(array_map('strtoupper', $tablesWithDeletedMarker), true);
        $filteredTables = [];
        $pattern = '/(?<operator>\bFROM|\bJOIN)(?<space>\s+)(?<qualifier>(?:"?[A-Z_][A-Z0-9_$]*"?\s*\.\s*){0,2})(?<table>"?[A-Z_][A-Z0-9_$]*"?)(?<tail>(?:\s+(?:AS\s+)?"?[A-Z_][A-Z0-9_$]*"?)?)/i';

        $filteredSql = preg_replace_callback(
            $pattern,
            static function (array $match) use ($markerTables, &$filteredTables): string {
                $tableName = strtoupper(trim($match['table'], '"'));
                if (!isset($markerTables[$tableName])) {
                    return $match[0];
                }

                $tail = $match['tail'];
                $alias = null;
                if (preg_match('/^\s+(?:AS\s+)?"?([A-Z_][A-Z0-9_$]*)"?$/i', $tail, $aliasMatch)) {
                    $candidate = strtoupper($aliasMatch[1]);
                    if (!in_array($candidate, self::CLAUSE_KEYWORDS, true)) {
                        $alias = $tail;
                        $tail = '';
                    }
                }

                $source = $match['qualifier'] . $match['table'];
                $relation = '(SELECT * FROM ' . $source
                    . ' WHERE (_FIVETRAN_DELETED = FALSE OR _FIVETRAN_DELETED IS NULL))';
                $filteredTables[$tableName] = true;

                return $match['operator'] . $match['space'] . $relation
                    . ($alias ?? ' AS ' . $match['table']) . $tail;
            },
            $sql
        );

        if ($filteredSql === null) {
            throw new \RuntimeException('Could not safely inspect Snowflake relations for Fivetran deletion markers: ' . preg_last_error_msg());
        }

        return [
            'sql' => $filteredSql,
            'tables' => array_keys($filteredTables),
        ];
    }
}
