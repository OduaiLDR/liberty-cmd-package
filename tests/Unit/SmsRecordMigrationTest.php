<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Unit;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\SqlServerConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/** Real SQLite DDL/transactions plus SQL Server catalog/DDL contract tests; no app or network. */
final class SmsRecordMigrationTest extends TestCase
{
    private const RUN = '11111111-2222-4333-8444-555555555555';
    private const OTHER = '22222222-3333-4444-8555-666666666666';
    private const MIGRATION = '/database/sms-migrations/2026_10_20_000003_add_sms_record_approval.php';
    private const TABLES = ['TblProspects', 'TblProspectsExport', 'TblSMSRuns', 'TblSMSHistory'];
    private const ADDED = [
        'TblProspects' => ['Last_SMS_Date', 'Contacted_Date'],
        'TblSMSRuns' => ['Selection_Policy', 'Never_Texted', 'Previously_Texted', 'Approved_At', 'Approved_By', 'File_Cleanup_Path'],
        'TblProspectsExport' => ['Was_Previously_Texted', 'Last_SMS_Date_At_Selection'],
        'TblSMSHistory' => ['Was_Previously_Texted', 'Last_SMS_Date_At_Selection'],
    ];

    private Container $previous;
    private Container $app;
    private Connection $db;
    private Migration $migration;
    private array $oldColumns;

    protected function setUp(): void
    {
        $this->previous = Container::getInstance();
        $this->app = new Container;
        Container::setInstance($this->app);
        $this->app->instance('config', new Repository(['database' => ['default' => 'sqlsrv']]));
        $capsule = new Manager($this->app);
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'sqlsrv');
        $capsule->getDatabaseManager()->setDefaultConnection('sqlsrv');
        $this->app->instance('db', $capsule->getDatabaseManager());
        $this->db = $capsule->getConnection('sqlsrv');
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);
        (require dirname(__DIR__, 2).'/database/sms-migrations/2026_10_12_000001_create_sms_prospects_tables.php')->up();

        // An unrelated legacy date must never become a record send/contact date.
        $this->db->getSchemaBuilder()->table('TblProspects', fn (Blueprint $t) => $t->date('SentDate')->nullable());
        $this->db->statement('CREATE TABLE UntouchedSource (PK INTEGER PRIMARY KEY, SentDate date, Contacted_Date date)');
        $this->db->table('UntouchedSource')->insert(['PK' => 73, 'SentDate' => '2026-09-07', 'Contacted_Date' => '2026-09-09']);
        $this->db->statement('CREATE INDEX "Unrelated export phone lookup" ON TblProspectsExport (Phone, Debt_Tier)');
        $this->db->statement('CREATE INDEX "Unrelated history filter" ON TblSMSHistory (Export_Date) WHERE Debt_Amount IS NOT NULL');
        $this->db->statement('CREATE TRIGGER "Unrelated history trigger" AFTER INSERT ON TblSMSHistory BEGIN SELECT 1; END');
        foreach (range(1, 3) as $id) {
            $this->db->table('TblProspects')->insert(['PK' => $id, 'Client' => 'Fixture '.$id, 'Phone' => '2025550101',
                'Contacted' => $id % 2, 'Invalid_Phone' => $id % 2, 'Opt_Out' => $id % 2, 'SMS_Attempts' => $id + 4,
                'Latest_Export_Date' => '2026-09-01', 'Response_Date' => '2026-09-03', 'SentDate' => '2026-09-05']);
        }
        foreach ([self::RUN => 'review', self::OTHER => 'sent'] as $request => $status) {
            $this->db->table('TblSMSRuns')->insert(['Request_ID' => $request, 'Target' => 50, 'Status' => $status,
                'Created_By' => 'fixture@example.invalid', 'Created_At' => '2026-10-18 01:02:03',
                'Updated_At' => '2026-10-18 02:03:04', 'Sent_At' => $status === 'sent' ? '2026-10-18 02:03:04' : null,
                'Selected' => 1, 'File_Path' => 'synthetic.csv']);
            foreach (['TblProspectsExport', 'TblSMSHistory'] as $table) {
                $this->db->table($table)->insert(['Request_ID' => $request, 'Prospect_PK' => 1,
                    'Phone' => '2025550101', 'Debt_Amount' => 9876.54, 'Debt_Tier' => 'T1', 'Debt_Source' => 'Tier']
                    + ($table === 'TblSMSHistory' ? ['Export_Date' => '2026-09-11 12:34:56'] : []));
            }
        }
        $this->oldColumns = [];
        foreach ($this->businessTables() as $table) $this->oldColumns[$table] = $this->db->getSchemaBuilder()->getColumnListing($table);
        $this->migration = require dirname(__DIR__, 2).self::MIGRATION;
    }

    protected function tearDown(): void
    {
        $this->db->disconnect();
        Facade::clearResolvedInstances();
        Container::setInstance($this->previous);
        Facade::setFacadeApplication($this->previous);
    }

    private function businessTables(): array
    {
        return [...self::TABLES, 'TblSMSDrops', 'UntouchedSource'];
    }

    private function rows(): array
    {
        $out = [];
        foreach ($this->oldColumns as $table => $columns) {
            $out[$table] = array_map(fn (object $row): array => (array) $row,
                $this->db->table($table)->select($columns)->orderBy('PK')->get()->all());
        }

        return $out;
    }

    private function schema(): array
    {
        return $this->db->select("SELECT type,name,tbl_name,sql FROM sqlite_master ORDER BY type,name");
    }

    private function refuses(callable $work, string $message): Throwable
    {
        try { $work(); }
        catch (Throwable $error) {
            self::assertStringContainsString($message, $error->getMessage());
            self::assertSame(0, $this->db->transactionLevel());
            self::assertFalse($this->db->getPdo()->inTransaction());

            return $error;
        }
        self::fail('Expected migration refusal: '.$message);
    }

    public function test_install_changes_only_the_requested_schema_and_preserves_every_existing_value(): void
    {
        $rows = $this->rows();
        $before = $this->schema();
        $this->db->enableQueryLog();
        $this->migration->up();
        $queries = array_column($this->db->getQueryLog(), 'query');
        self::assertSame($rows, $this->rows());
        self::assertSame(0, $this->db->table('TblSMSResponseAttribution')->count());
        $untouched = fn (array $objects): array => array_values(array_filter($objects, fn (object $o): bool =>
            ! ($o->type === 'table' && in_array($o->name, self::TABLES, true))
            && ! str_contains($o->name, 'request_id_phone_unique')
            && ! str_contains($o->name, '_Request_Phone_Record')
            && ! str_contains($o->name, 'TblSMSResponseAttribution')));
        self::assertEquals($untouched($before), $untouched($this->schema()));
        foreach (self::ADDED as $table => $columns) {
            self::assertSame([...$this->oldColumns[$table], ...$columns], $this->db->getSchemaBuilder()->getColumnListing($table));
            foreach ($columns as $column) {
                self::assertSame([$column === 'Selection_Policy' ? 'legacy-phone-v1' : null],
                    $this->db->table($table)->distinct()->pluck($column)->all(), $table.'.'.$column);
            }
        }
        self::assertSame(['review', 'sent'], $this->db->table('TblSMSRuns')->orderBy('PK')->pluck('Status')->all());
        // The migration never executes business DML, table reconstruction or a prospects index build.
        $writes = array_values(array_filter($queries, fn (string $q): bool => ! preg_match('/^(SELECT|PRAGMA)/i', $q)));
        self::assertCount(18, $writes);
        foreach ($writes as $query) {
            self::assertMatchesRegularExpression('/^(ALTER TABLE .* ADD |DROP INDEX |CREATE INDEX |CREATE TABLE )/', $query);
            self::assertDoesNotMatchRegularExpression('/^CREATE INDEX .* ON ("main"\.)?"TblProspects" /', $query);
        }
        $types = array_column($this->db->select('PRAGMA table_info("TblSMSRuns")'), null, 'name');
        self::assertSame('varchar(40)', $types['Selection_Policy']->type);
        self::assertSame(1, $types['Selection_Policy']->notnull);
        self::assertSame("'legacy-phone-v1'", $types['Selection_Policy']->dflt_value);
        foreach (['Approved_By', 'File_Cleanup_Path'] as $column) {
            self::assertSame('nvarchar(255)', $types[$column]->type);
            self::assertSame(0, $types[$column]->notnull);
        }
    }

    public function test_same_phone_records_are_allowed_but_request_prospect_uniqueness_is_unchanged(): void
    {
        $this->migration->up();
        foreach (['TblProspectsExport', 'TblSMSHistory'] as $table) {
            $row = ['Request_ID' => self::RUN, 'Prospect_PK' => 2, 'Phone' => '2025550101']
                + ($table === 'TblSMSHistory' ? ['Export_Date' => '2026-10-20 11:22:33'] : []);
            $this->db->table($table)->insert($row);
            self::assertSame(2, $this->db->table($table)->where('Request_ID', self::RUN)->where('Phone', '2025550101')->count());
            try {
                $this->db->table($table)->insert(array_replace($row, ['Phone' => '2025550199']));
                self::fail('Duplicate request/prospect must fail.');
            } catch (\Illuminate\Database\UniqueConstraintViolationException) {}
            $this->db->table($table)->insert(array_replace($row, ['Request_ID' => self::OTHER]));
            $indexes = $this->db->getSchemaBuilder()->getIndexes($table);
            $lookup = array_values(array_filter($indexes, fn (array $i): bool => strcasecmp($i['name'], 'IX_'.$table.'_Request_Phone_Record') === 0));
            self::assertCount(1, $lookup);
            self::assertFalse($lookup[0]['unique']);
            // Satisfies the legacy import Core guard's leading Request_ID,Phone lookup.
            self::assertSame(['Request_ID', 'Phone'], $lookup[0]['columns']);
        }
    }

    public function test_attribution_has_only_its_record_primary_key_lookup_and_no_foreign_keys(): void
    {
        $this->migration->up();
        $table = 'TblSMSResponseAttribution';
        $row = ['Prospect_PK' => 999999, 'Request_ID' => null, 'Contact_At_UTC' => '2026-10-20 23:59:59.1234567',
            'Contacted_Date' => '2026-10-20', 'Created_At' => '2026-10-21 00:00:00.1234567'];
        $this->db->table($table)->insert($row);
        self::assertSame($row, (array) $this->db->table($table)->first());
        self::assertSame([], $this->db->getSchemaBuilder()->getForeignKeys($table));
        $indexes = $this->db->getSchemaBuilder()->getIndexes($table);
        self::assertCount(2, $indexes);
        self::assertSame(['Prospect_PK'], array_values(array_filter($indexes, fn (array $i): bool => $i['primary']))[0]['columns']);
        $lookup = array_values(array_filter($indexes, fn (array $i): bool => ! $i['primary']))[0];
        self::assertSame(['Request_ID', 'Prospect_PK'], $lookup['columns']);
        self::assertFalse($lookup['unique']);
        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        $this->db->table($table)->insert(array_replace($row, ['Request_ID' => self::RUN]));
    }

    public function test_defaults_keep_new_and_active_legacy_runs_unapproved_and_allow_explicit_record_policy(): void
    {
        $this->migration->up();
        $row = ['Request_ID' => '33333333-4444-4555-8666-777777777777', 'Target' => 1, 'Status' => 'queued',
            'Created_By' => 'fixture@example.invalid', 'Created_At' => '2026-10-20 01:02:03', 'Updated_At' => '2026-10-20 01:02:03'];
        $this->db->table('TblSMSRuns')->insert($row);
        $run = $this->db->table('TblSMSRuns')->where('Request_ID', $row['Request_ID'])->first();
        foreach (self::ADDED['TblSMSRuns'] as $column) self::assertSame($column === 'Selection_Policy' ? 'legacy-phone-v1' : null, $run->$column);
        $this->db->table('TblSMSRuns')->where('Request_ID', $row['Request_ID'])->update([
            'Selection_Policy' => 'prospect-record-v1', 'Never_Texted' => 0, 'Previously_Texted' => 1,
            'Approved_At' => '2026-10-20 08:15:00', 'Approved_By' => 'fixture@example.invalid',
            'File_Cleanup_Path' => 'quarantine/synthetic.csv',
        ]);
        self::assertSame('prospect-record-v1', $this->db->table('TblSMSRuns')->where('Request_ID', $row['Request_ID'])->value('Selection_Policy'));
    }

    public static function partialSchemas(): iterable
    {
        foreach (self::ADDED as $table => $columns) {
            foreach ($columns as $column) yield $table.'.'.$column => ['ALTER TABLE "'.$table.'" ADD "'.$column.'" TEXT', 'partially installed'];
        }
        yield 'attribution' => ['CREATE TABLE TblSMSResponseAttribution (Marker INTEGER)', 'partially installed'];
        foreach (['TblProspectsExport', 'TblSMSHistory'] as $table) {
            yield $table.' lookup' => ['CREATE INDEX IX_'.$table.'_Request_Phone_Record ON '.$table.' (Phone)', 'partially installed'];
        }
    }

    #[DataProvider('partialSchemas')]
    public function test_partial_schemas_refuse_without_altering_the_existing_partial(string $ddl, string $message): void
    {
        $this->db->statement($ddl);
        $schema = $this->schema();
        $rows = $this->rows();
        $this->refuses(fn () => $this->migration->up(), $message);
        self::assertEquals($schema, $this->schema());
        self::assertSame($rows, $this->rows());
    }

    public static function unexpectedKeys(): iterable
    {
        foreach (['TblProspectsExport', 'TblSMSHistory'] as $table) {
            $drop = 'DROP INDEX '.strtolower($table).'_request_id_phone_unique';
            yield $table.' missing phone' => [[$drop]];
            yield $table.' wrong order' => [[$drop, 'CREATE UNIQUE INDEX WrongPhoneKey ON '.$table.' (Phone,Request_ID)']];
            yield $table.' extra key column' => [[$drop, 'CREATE UNIQUE INDEX WrongPhoneKey ON '.$table.' (Request_ID,Phone,Prospect_PK)']];
            yield $table.' filtered' => [[$drop, 'CREATE UNIQUE INDEX WrongPhoneKey ON '.$table.' (Request_ID,Phone) WHERE Debt_Tier IS NOT NULL']];
            yield $table.' duplicate phone key' => [['CREATE UNIQUE INDEX OtherPhoneKey ON '.$table.' (Request_ID,Phone)']];
            yield $table.' additional phone tier key' => [['CREATE UNIQUE INDEX OtherPhoneTierKey ON '.$table.' (Request_ID,Phone,Debt_Tier)']];
            yield $table.' additional phone tier filtered key' => [['CREATE UNIQUE INDEX OtherPhoneTierKey ON '.$table.' (Request_ID,Phone,Debt_Tier) WHERE Debt_Tier IS NOT NULL']];
            yield $table.' phone only' => [['DELETE FROM '.$table.' WHERE Request_ID="'.self::OTHER.'"', 'CREATE UNIQUE INDEX OtherPhoneKey ON '.$table.' (Phone)']];
            yield $table.' missing prospect' => [['DROP INDEX '.strtolower($table).'_request_id_prospect_pk_unique']];
        }
    }

    #[DataProvider('unexpectedKeys')]
    public function test_unexpected_keys_refuse_before_any_ddl(array $statements): void
    {
        foreach ($statements as $statement) $this->db->statement($statement);
        $schema = $this->schema();
        $rows = $this->rows();
        $this->refuses(fn () => $this->migration->up(), 'SMS');
        self::assertEquals($schema, $this->schema());
        self::assertSame($rows, $this->rows());
    }

    public function test_missing_base_column_refuses_before_any_ddl(): void
    {
        $this->db->statement('ALTER TABLE TblProspects DROP COLUMN SMS_Attempts');
        $schema = $this->schema();
        $this->refuses(fn () => $this->migration->up(), 'TblProspects.SMS_Attempts');
        self::assertEquals($schema, $this->schema());
    }

    public function test_repeat_install_and_down_refuse_without_destroying_data(): void
    {
        $this->migration->up();
        $schema = $this->schema();
        $rows = $this->rows();
        $this->refuses(fn () => $this->migration->up(), 'partially installed');
        $this->refuses(fn () => $this->migration->down(), 'Retain SMS record');
        self::assertEquals($schema, $this->schema());
        self::assertSame($rows, $this->rows());
    }

    public static function ddlFailurePoints(): iterable
    {
        yield 'after first table' => ['ALTER TABLE "main"."TblSMSRuns" ADD'];
        yield 'after first phone drop' => ['CREATE INDEX "main"."IX_TblProspectsExport_Request_Phone_Record"'];
        yield 'after both phone replacements' => ['CREATE TABLE "main"."TblSMSResponseAttribution"'];
        yield 'final index' => ['CREATE INDEX "main"."IX_TblSMSResponseAttribution_Request_Prospect"'];
    }

    #[DataProvider('ddlFailurePoints')]
    public function test_any_mid_install_failure_rolls_back_all_columns_keys_and_rows(string $failurePoint): void
    {
        $schema = $this->schema();
        $rows = $this->rows();
        $fail = true;
        $this->db->beforeExecuting(function (string $sql) use (&$fail, $failurePoint): void {
            if ($fail && str_starts_with($sql, $failurePoint)) throw new RuntimeException('Injected atomic DDL failure');
        });
        $this->refuses(fn () => $this->migration->up(), 'Injected atomic DDL failure');
        $fail = false;
        self::assertEquals($schema, $this->schema());
        self::assertSame($rows, $this->rows());
        $this->migration->up(); // No half-installed schema prevents a clean retry.
        self::assertSame($rows, $this->rows());
    }

    public function test_an_ambient_transaction_is_refused_without_committing_its_work(): void
    {
        $schema = $this->schema();
        $this->db->beginTransaction();
        try {
            try { $this->migration->up(); self::fail('Expected ambient transaction refusal.'); }
            catch (RuntimeException $error) { self::assertStringContainsString('own its atomic transaction', $error->getMessage()); }
            self::assertSame(1, $this->db->transactionLevel());
            self::assertEquals($schema, $this->schema());
        } finally { $this->db->rollBack(); }
    }

    public function test_metadata_discovered_sqlite_index_names_are_quoted_not_assumed(): void
    {
        foreach (['TblProspectsExport', 'TblSMSHistory'] as $table) {
            $this->db->statement('DROP INDEX '.strtolower($table).'_request_id_phone_unique');
            $name = 'legacy " phone '.$table.'; retained';
            $this->db->statement('CREATE UNIQUE INDEX "'.str_replace('"', '""', $name).'" ON '.$table.' (Request_ID,Phone)');
        }
        $rows = $this->rows();
        $this->migration->up();
        self::assertSame($rows, $this->rows());
        self::assertSame(3, $this->db->table('TblProspects')->count());
    }

    /** Build synthetic SQL Server catalog rows from the actual deployed baseline SQLite schema. */
    private function sqlServerCatalog(): array
    {
        $catalog = ['objects' => [], 'columns' => [], 'indexes' => []];
        foreach (self::TABLES as $table) {
            $catalog['objects'][] = (object) ['schema_name' => 'dbo', 'name' => $table, 'type' => 'U'];
            foreach ($this->db->select('PRAGMA table_info("'.$table.'")') as $column) {
                $type = strtolower(preg_replace('/\(.*/', '', $column->type));
                $type = match (true) {
                    $column->name === 'Request_ID' => 'uniqueidentifier',
                    $type === 'integer' => 'int', $type === 'tinyint' => 'bit', default => $type,
                };
                $catalog['columns'][$table][] = (object) ['name' => $column->name, 'type_name' => $type,
                    'is_nullable' => ! ($column->notnull || $column->pk)];
            }
            foreach ($this->db->getSchemaBuilder()->getIndexes($table) as $index) {
                foreach ($index['columns'] as $offset => $column) {
                    $catalog['indexes'][$table][] = (object) ['name' => $index['name'], 'is_unique' => $index['unique'],
                        'is_primary_key' => $index['primary'], 'is_unique_constraint' => false, 'type' => $index['primary'] ? 1 : 2,
                        'is_disabled' => 0, 'is_hypothetical' => 0, 'has_filter' => 0, 'key_ordinal' => $offset + 1,
                        'is_included_column' => 0, 'is_descending_key' => 0, 'column_name' => $column];
                }
            }
        }

        return $catalog;
    }

    /** Real transaction on an isolated SQLite PDO; SQL Server catalog reads/DDL are modeled. */
    private function sqlServerModel(array $catalog, array &$statements): SqlServerConnection
    {
        $db = $this->getMockBuilder(SqlServerConnection::class)
            ->setConstructorArgs([new PDO('sqlite::memory:'), 'isolated-catalog-model', '', ['driver' => 'sqlsrv']])
            ->onlyMethods(['selectFromWriteConnection', 'statement'])->getMock();
        $db->method('selectFromWriteConnection')->willReturnCallback(function (string $sql, array $bindings = []) use ($catalog): array {
            if (str_contains($sql, 'FROM sys.objects')) return $catalog['objects'];
            $table = substr($bindings[0], 4);
            if (str_contains($sql, 'FROM sys.columns')) return $catalog['columns'][$table];
            if (str_contains($sql, 'FROM sys.indexes')) return $catalog['indexes'][$table];
            self::fail('Unexpected catalog query: '.$sql);
        });
        $db->method('statement')->willReturnCallback(function (string $sql) use (&$statements, $db): bool {
            self::assertSame(1, $db->transactionLevel(), 'Every SQL Server DDL write must be in the installer transaction.');
            $statements[] = $sql;

            return true;
        });
        DB::swap(new class($db) {
            public function __construct(private Connection $db) {}
            public function connection(?string $name = null): Connection { return $this->db; }
        });

        return $db;
    }

    public function test_sql_server_exact_types_dbo_qualification_and_constraint_vs_index_drops(): void
    {
        $catalog = $this->sqlServerCatalog();
        foreach (['TblProspectsExport', 'TblSMSHistory'] as $table) {
            foreach ($catalog['indexes'][$table] as $index) {
                if ($index->name === strtolower($table).'_request_id_phone_unique') {
                    $index->name = 'legacy ] phone; '.$table;
                    $index->is_unique_constraint = $table === 'TblSMSHistory';
                }
            }
        }
        $statements = [];
        $db = $this->sqlServerModel($catalog, $statements);
        $this->migration->up();
        self::assertSame(0, $db->transactionLevel());
        self::assertFalse($db->getPdo()->inTransaction());
        self::assertCount(18, $statements);
        self::assertContains("ALTER TABLE [dbo].[TblSMSRuns] ADD [Selection_Policy] varchar(40) NOT NULL DEFAULT 'legacy-phone-v1'", $statements);
        self::assertContains('ALTER TABLE [dbo].[TblSMSRuns] ADD [Approved_At] datetime2 NULL', $statements);
        self::assertContains('ALTER TABLE [dbo].[TblSMSRuns] ADD [Approved_By] nvarchar(255) NULL', $statements);
        self::assertContains('ALTER TABLE [dbo].[TblSMSRuns] ADD [File_Cleanup_Path] nvarchar(255) NULL', $statements);
        self::assertContains('DROP INDEX [legacy ]] phone; TblProspectsExport] ON [dbo].[TblProspectsExport]', $statements);
        self::assertContains('ALTER TABLE [dbo].[TblSMSHistory] DROP CONSTRAINT [legacy ]] phone; TblSMSHistory]', $statements);
        self::assertContains('CREATE TABLE [dbo].[TblSMSResponseAttribution] ([Prospect_PK] int NOT NULL PRIMARY KEY, [Request_ID] uniqueidentifier NULL, [Contact_At_UTC] datetime2 NOT NULL, [Contacted_Date] date NOT NULL, [Created_At] datetime2 NOT NULL)', $statements);
        foreach (['TblProspectsExport', 'TblSMSHistory'] as $table) {
            self::assertContains('CREATE INDEX [IX_'.$table.'_Request_Phone_Record] ON [dbo].['.$table.'] ([Request_ID], [Phone])', $statements);
        }
        foreach ($statements as $statement) self::assertDoesNotMatchRegularExpression('/\b(UPDATE|INSERT|DELETE|FOREIGN KEY)\b/i', $statement);
    }

    public static function sqlServerDrift(): iterable
    {
        yield 'wrong schema' => ['schema'];
        yield 'duplicate schema' => ['duplicate'];
        yield 'view' => ['view'];
        yield 'missing table' => ['missing'];
        yield 'new table already exists' => ['partial'];
        yield 'disabled unique index' => ['is_disabled'];
        yield 'hypothetical unique index' => ['is_hypothetical'];
        yield 'filtered unique index' => ['has_filter'];
        yield 'descending key' => ['is_descending_key'];
        yield 'clustered phone index' => ['clustered'];
        yield 'included phone column' => ['is_included_column'];
    }

    #[DataProvider('sqlServerDrift')]
    public function test_sql_server_schema_and_key_drift_refuse_without_ddl(string $drift): void
    {
        $catalog = $this->sqlServerCatalog();
        switch ($drift) {
            case 'schema': $catalog['objects'][0]->schema_name = 'other'; break;
            case 'duplicate': $catalog['objects'][] = (object) ['schema_name' => 'other', 'name' => 'TblSMSHistory', 'type' => 'U']; break;
            case 'view': $catalog['objects'][0]->type = 'V'; break;
            case 'missing': array_pop($catalog['objects']); break;
            case 'partial': $catalog['objects'][] = (object) ['schema_name' => 'dbo', 'name' => 'TblSMSResponseAttribution', 'type' => 'U']; break;
            default:
                foreach ($catalog['indexes']['TblSMSHistory'] as $index) {
                    if ($index->name === 'tblsmshistory_request_id_phone_unique') {
                        if ($drift === 'clustered') $index->type = 1;
                        else $index->$drift = 1;
                    }
                }
        }
        $statements = [];
        $db = $this->sqlServerModel($catalog, $statements);
        $this->refuses(fn () => $this->migration->up(), 'SMS');
        self::assertSame([], $statements);
        self::assertSame(0, $db->transactionLevel());
        self::assertFalse($db->getPdo()->inTransaction());
    }
}
