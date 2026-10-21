<?php

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Opt-in record selection storage only. Existing runs keep legacy-phone-v1; enabling
 * prospect-record-v1 and excluding mixed open runs belong to the application guard.
 * No dates are inferred from Latest_Export_Date, Response_Date or historical sends.
 */
return new class extends Migration
{
    protected $connection = 'sqlsrv';

    // Own the transaction even when invoked directly; refuse an ambient transaction.
    public $withinTransaction = false;

    private const ADDITIONS = [
        'TblProspects' => ['Last_SMS_Date' => 'date NULL', 'Contacted_Date' => 'date NULL'],
        'TblSMSRuns' => [
            'Selection_Policy' => "varchar(40) NOT NULL DEFAULT 'legacy-phone-v1'",
            'Never_Texted' => 'int NULL', 'Previously_Texted' => 'int NULL',
            'Approved_At' => 'datetime2 NULL', 'Approved_By' => 'nvarchar(255) NULL',
            'File_Cleanup_Path' => 'nvarchar(255) NULL',
        ],
        'TblProspectsExport' => ['Was_Previously_Texted' => 'bit NULL', 'Last_SMS_Date_At_Selection' => 'date NULL'],
        'TblSMSHistory' => ['Was_Previously_Texted' => 'bit NULL', 'Last_SMS_Date_At_Selection' => 'date NULL'],
    ];

    public function up(): void
    {
        $db = DB::connection($this->getConnection());
        if (! in_array($db->getDriverName(), ['sqlsrv', 'sqlite'], true) || $db->getTablePrefix() !== '') {
            throw new RuntimeException('SMS record migration requires an unprefixed SQL Server dbo or SQLite main schema.');
        }
        if ($db->transactionLevel() !== 0) {
            throw new RuntimeException('SMS record migration must own its atomic transaction.');
        }

        $db->transaction(function () use ($db): void {
            $this->assertTables($db);
            $indexes = [];
            foreach (self::ADDITIONS as $table => $additions) {
                $columns = $this->columns($db, $table);
                foreach (array_keys($additions) as $column) {
                    if (isset($columns[strtolower($column)])) {
                        throw new RuntimeException('SMS record schema already exists or is partially installed: '.$table.'.'.$column);
                    }
                }
                $this->assertBaseColumns($db, $table, $columns);
                $indexes[$table] = $this->indexes($db, $table);
                $this->assertUnique($table, $indexes[$table], ['PK'], true);
            }
            $this->assertUnique('TblSMSRuns', $indexes['TblSMSRuns'], ['Request_ID']);
            $phoneKeys = [];
            foreach (['TblProspectsExport', 'TblSMSHistory'] as $table) {
                $this->assertUnique($table, $indexes[$table], ['Request_ID', 'Prospect_PK']);
                $phoneKeys[$table] = $this->assertUnique($table, $indexes[$table], ['Request_ID', 'Phone']);
                foreach ($indexes[$table] as $index) {
                    if (strcasecmp($index['name'], $this->phoneIndex($table)) === 0) {
                        throw new RuntimeException('SMS record phone index already exists or is partially installed: '.$table);
                    }
                    // Only PK and request/prospect identity may remain unique. Any other
                    // unique grouping (including request/phone/tier) can reject valid PKs.
                    if ($index['unique'] && $index['name'] !== $phoneKeys[$table]['name']
                        && ! in_array(array_map('strtolower', $index['columns']), [['pk'], ['request_id', 'prospect_pk']], true)) {
                        throw new RuntimeException('Unexpected SMS phone uniqueness: '.$table.'.'.$index['name']);
                    }
                }
            }

            // All existing tables, columns and keys are checked before the first DDL write.
            foreach (self::ADDITIONS as $table => $additions) {
                foreach ($additions as $column => $definition) {
                    $db->statement('ALTER TABLE '.$this->table($db, $table).' ADD '.$this->quote($db, $column).' '.$definition);
                }
            }
            foreach ($phoneKeys as $table => $key) {
                // SQL Server UNIQUE constraints own their indexes and need DROP CONSTRAINT.
                $db->statement($key['constraint']
                    ? 'ALTER TABLE '.$this->table($db, $table).' DROP CONSTRAINT '.$this->quote($db, $key['name'])
                    : 'DROP INDEX '.($db->getDriverName() === 'sqlite' ? '"main".' : '').$this->quote($db, $key['name'])
                        .($db->getDriverName() === 'sqlsrv' ? ' ON '.$this->table($db, $table) : ''));
                $this->createIndex($db, $this->phoneIndex($table), $table, ['Request_ID', 'Phone']);
            }

            // UTC instants are supplied by the application. Contacted_Date is a separate date,
            // pending response attribution rules. No foreign key until prospect deletion is proven.
            $db->statement('CREATE TABLE '.$this->table($db, 'TblSMSResponseAttribution').' ('
                .$this->quote($db, 'Prospect_PK').' int NOT NULL PRIMARY KEY, '
                .$this->quote($db, 'Request_ID').' '.($db->getDriverName() === 'sqlsrv' ? 'uniqueidentifier' : 'uuid').' NULL, '
                .$this->quote($db, 'Contact_At_UTC').' datetime2 NOT NULL, '
                .$this->quote($db, 'Contacted_Date').' date NOT NULL, '
                .$this->quote($db, 'Created_At').' datetime2 NOT NULL)');
            $this->createIndex($db, 'IX_TblSMSResponseAttribution_Request_Prospect', 'TblSMSResponseAttribution', ['Request_ID', 'Prospect_PK']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Retain SMS record approval, attribution and cleanup evidence. Roll back application code, not this schema.');
    }

    private function assertTables(Connection $db): void
    {
        $names = [...array_keys(self::ADDITIONS), 'TblSMSResponseAttribution'];
        $placeholders = implode(',', array_fill(0, count($names), '?'));
        $rows = $db->getDriverName() === 'sqlsrv'
            ? $db->selectFromWriteConnection("SELECT s.name AS schema_name,o.name,RTRIM(o.type) AS type FROM sys.objects o JOIN sys.schemas s ON s.schema_id=o.schema_id WHERE o.name IN ($placeholders)", $names)
            : $db->selectFromWriteConnection("SELECT 'main' AS schema_name,name,type FROM main.sqlite_master WHERE name COLLATE NOCASE IN ($placeholders)", $names);
        $found = [];
        foreach ($rows as $row) {
            if ($row->schema_name !== ($db->getDriverName() === 'sqlsrv' ? 'dbo' : 'main')
                || ! in_array($row->type, ['U', 'table'], true) || ! in_array($row->name, $names, true)
                || isset($found[$row->name])) {
                throw new RuntimeException('Unexpected SMS record table schema or object: '.$row->name);
            }
            $found[$row->name] = true;
        }
        if (isset($found['TblSMSResponseAttribution'])) {
            throw new RuntimeException('SMS record schema already exists or is partially installed: TblSMSResponseAttribution');
        }
        foreach (array_keys(self::ADDITIONS) as $name) {
            if (! isset($found[$name])) throw new RuntimeException('Required SMS record table missing: '.$name);
        }
    }

    private function columns(Connection $db, string $table): array
    {
        $rows = $db->getDriverName() === 'sqlsrv'
            ? $db->selectFromWriteConnection('SELECT c.name,t.name AS type_name,c.is_nullable FROM sys.columns c JOIN sys.types t ON t.user_type_id=c.user_type_id WHERE c.object_id=OBJECT_ID(?)', ['dbo.'.$table])
            : $db->selectFromWriteConnection('PRAGMA main.table_xinfo('.$this->quote($db, $table).')');
        $columns = [];
        foreach ($rows as $row) {
            $columns[strtolower($row->name)] = [
                'type' => strtolower(preg_replace('/\(.*/', '', $row->type_name ?? $row->type)),
                'nullable' => $db->getDriverName() === 'sqlsrv' ? (bool) $row->is_nullable : ! ($row->notnull || $row->pk),
            ];
        }

        return $columns;
    }

    private function assertBaseColumns(Connection $db, string $table, array $columns): void
    {
        $required = match ($table) {
            'TblProspects' => ['PK' => 'int', 'Phone' => 'string', 'Contacted' => 'bit', 'SMS_Attempts' => 'int',
                'Latest_Export_Date' => 'date', 'Response_Date' => 'date'],
            'TblSMSRuns' => ['PK' => 'int', 'Request_ID' => 'uuid', 'Status' => 'string', 'Sent_At' => 'datetime'],
            default => ['PK' => 'int', 'Request_ID' => 'uuid', 'Prospect_PK' => 'int', 'Phone' => 'string']
                + ($table === 'TblSMSHistory' ? ['Export_Date' => 'datetime'] : []),
        };
        $types = ['int' => ['int', 'integer', 'bigint'], 'string' => ['varchar', 'nvarchar'],
            'uuid' => ['uniqueidentifier', 'uuid', 'varchar', 'nvarchar'], 'date' => ['date'],
            'datetime' => ['datetime', 'datetime2'], 'bit' => ['bit', 'tinyint', 'boolean']];
        if ($db->getDriverName() === 'sqlsrv') $types['uuid'] = ['uniqueidentifier'];
        foreach ($required as $name => $type) {
            $column = $columns[strtolower($name)] ?? null;
            if ($column === null || ! in_array($column['type'], $types[$type], true)
                || ((in_array($name, ['PK', 'Request_ID', 'Prospect_PK'], true)
                    || ($name === 'Phone' && in_array($table, ['TblProspectsExport', 'TblSMSHistory'], true))) && $column['nullable'])) {
                throw new RuntimeException('Unexpected SMS record column schema: '.$table.'.'.$name);
            }
        }
    }

    private function indexes(Connection $db, string $table): array
    {
        if ($db->getDriverName() === 'sqlite') {
            $indexes = [];
            $primary = [];
            foreach ($db->selectFromWriteConnection('PRAGMA main.table_xinfo('.$this->quote($db, $table).')') as $column) {
                if ($column->pk) $primary[$column->pk] = $column->name;
            }
            ksort($primary);
            if ($primary !== []) $indexes[] = ['name' => 'PRIMARY', 'columns' => array_values($primary),
                'unique' => true, 'primary' => true, 'constraint' => false, 'plain' => true];
            foreach ($db->selectFromWriteConnection('PRAGMA main.index_list('.$this->quote($db, $table).')') as $index) {
                if ($index->origin === 'pk') continue;
                $keys = $db->selectFromWriteConnection('PRAGMA main.index_xinfo('.$this->quote($db, $index->name).')');
                $columns = [];
                $plain = ! $index->partial && $index->origin === 'c';
                foreach ($keys as $key) {
                    if (! $key->key) continue;
                    $columns[] = $key->name;
                    $plain = $plain && $key->cid >= 0 && ! $key->desc && $key->coll === 'BINARY';
                }
                // Inline SQLite UNIQUE autoindexes cannot be dropped without rebuilding a
                // table. Refuse that unfamiliar schema; deployed Laravel keys are named indexes.
                $indexes[] = ['name' => $index->name, 'columns' => $columns, 'unique' => (bool) $index->unique,
                    'primary' => false, 'constraint' => false, 'plain' => $plain];
            }

            return $indexes;
        }

        $rows = $db->selectFromWriteConnection(<<<'SQL'
SELECT i.name,i.is_unique,i.is_primary_key,i.is_unique_constraint,i.type,i.is_disabled,i.is_hypothetical,i.has_filter,
       ic.key_ordinal,ic.is_included_column,ic.is_descending_key,c.name AS column_name
FROM sys.indexes i
JOIN sys.index_columns ic ON ic.object_id=i.object_id AND ic.index_id=i.index_id
JOIN sys.columns c ON c.object_id=ic.object_id AND c.column_id=ic.column_id
WHERE i.object_id=OBJECT_ID(?) AND i.index_id>0
ORDER BY i.index_id,ic.key_ordinal,ic.index_column_id
SQL, ['dbo.'.$table]);
        $indexes = [];
        foreach ($rows as $row) {
            $indexes[$row->name] ??= ['name' => $row->name, 'columns' => [], 'unique' => (bool) $row->is_unique,
                'primary' => (bool) $row->is_primary_key, 'constraint' => (bool) $row->is_unique_constraint,
                'plain' => in_array((int) $row->type, [1, 2], true) && ! $row->is_disabled && ! $row->is_hypothetical && ! $row->has_filter];
            if ($row->is_included_column || $row->is_descending_key || ! $row->key_ordinal) $indexes[$row->name]['plain'] = false;
            if ($row->key_ordinal > 0) $indexes[$row->name]['columns'][] = $row->column_name;
            // Never remove/rebuild a clustered phone key, even if its columns match.
            if (! $row->is_primary_key && (int) $row->type !== 2) $indexes[$row->name]['plain'] = false;
        }

        return array_values($indexes);
    }

    private function assertUnique(string $table, array $indexes, array $columns, bool $primary = false): array
    {
        $matches = array_values(array_filter($indexes, fn (array $index): bool => $index['unique'] && $index['columns'] === $columns));
        if (count($matches) !== 1 || ! $matches[0]['plain'] || $matches[0]['primary'] !== $primary) {
            throw new RuntimeException('Unexpected SMS unique key schema: '.$table.'('.implode(',', $columns).')');
        }

        return $matches[0];
    }

    private function phoneIndex(string $table): string
    {
        return 'IX_'.$table.'_Request_Phone_Record';
    }

    private function createIndex(Connection $db, string $name, string $table, array $columns): void
    {
        // SQLite qualifies the index's schema, and requires an unqualified table here.
        $db->statement('CREATE INDEX '.($db->getDriverName() === 'sqlite' ? '"main".' : '').$this->quote($db, $name)
            .' ON '.($db->getDriverName() === 'sqlite' ? $this->quote($db, $table) : $this->table($db, $table))
            .' ('.implode(', ', array_map(fn (string $column): string => $this->quote($db, $column), $columns)).')');
    }

    private function table(Connection $db, string $table): string
    {
        return ($db->getDriverName() === 'sqlsrv' ? '[dbo].' : '"main".').$this->quote($db, $table);
    }

    private function quote(Connection $db, string $identifier): string
    {
        return $db->getDriverName() === 'sqlsrv' ? '['.str_replace(']', ']]', $identifier).']' : '"'.str_replace('"', '""', $identifier).'"';
    }
};
