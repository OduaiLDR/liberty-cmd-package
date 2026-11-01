<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/** Fail closed unless each lean debt reference names one currently unique source row. */
final class SmsLeanHistorySchema
{
    private const SOURCES = ['TblTUData', 'TblMailers'];

    public static function ready(ConnectionInterface $db): bool
    {
        try {
            self::assertReady($db);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public static function assertReady(ConnectionInterface $db): void
    {
        if ($db->getDriverName() !== 'sqlsrv' || $db->getTablePrefix() !== '') {
            throw new RuntimeException('Lean SMS history requires unprefixed SQL Server source tables.');
        }

        $columns = $db->select(<<<'SQL'
SELECT t.name AS table_name,c.name AS column_name,ty.name AS type_name,c.is_nullable
FROM sys.tables t
JOIN sys.schemas s ON s.schema_id=t.schema_id
JOIN sys.columns c ON c.object_id=t.object_id
JOIN sys.types ty ON ty.user_type_id=c.user_type_id
WHERE s.name='dbo' AND t.name IN ('TblTUData','TblMailers') AND c.name='PK'
SQL);
        $validColumns = [];
        foreach ($columns as $column) {
            if (in_array($column->table_name, self::SOURCES, true)
                && $column->column_name === 'PK'
                && in_array(strtolower((string) $column->type_name), ['int','bigint'], true)
                && (int) $column->is_nullable === 0) {
                $validColumns[$column->table_name] = true;
            }
        }

        $rows = $db->select(<<<'SQL'
SELECT t.name AS table_name,i.name AS index_name,i.type,i.is_unique,i.is_disabled,i.is_hypothetical,i.has_filter,
       ic.key_ordinal,ic.is_included_column,c.name AS column_name
FROM sys.tables t
JOIN sys.schemas s ON s.schema_id=t.schema_id
JOIN sys.indexes i ON i.object_id=t.object_id AND i.index_id>0
JOIN sys.index_columns ic ON ic.object_id=i.object_id AND ic.index_id=i.index_id
JOIN sys.columns c ON c.object_id=ic.object_id AND c.column_id=ic.column_id
WHERE s.name='dbo' AND t.name IN ('TblTUData','TblMailers') AND i.is_unique=1
ORDER BY t.name,i.index_id,ic.key_ordinal,ic.index_column_id
SQL);
        $indexes = [];
        foreach ($rows as $row) {
            $key = $row->table_name.'|'.$row->index_name;
            $indexes[$key] ??= [
                'table' => $row->table_name,
                'usable' => in_array((int) $row->type, [1,2], true) && (int) $row->is_unique === 1
                    && (int) $row->is_disabled === 0 && (int) $row->is_hypothetical === 0 && (int) $row->has_filter === 0,
                'keys' => [],
            ];
            if ((int) $row->key_ordinal > 0 && (int) $row->is_included_column === 0) {
                $indexes[$key]['keys'][(int) $row->key_ordinal] = $row->column_name;
            }
        }

        $uniquePk = [];
        foreach ($indexes as $index) {
            ksort($index['keys']);
            if ($index['usable'] && array_values($index['keys']) === ['PK']) $uniquePk[$index['table']] = true;
        }
        foreach (self::SOURCES as $source) {
            if (($validColumns[$source] ?? false) !== true || ($uniquePk[$source] ?? false) !== true) {
                throw new RuntimeException('Lean SMS history requires one non-null uniquely indexed '.$source.'.PK lookup identity.');
            }
        }
    }
}
