<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Opt-in attribution evidence only; never infer old contact or approval precision. */
return new class extends Migration
{
    protected $connection = 'sqlsrv';
    public $withinTransaction = false;

    private const EVIDENCE = [
        'Contact_At_UTC_Exact' => 'varchar(30) NULL',
        'Source_System' => 'varchar(40) NULL',
        'Source_Contact_ID' => 'nvarchar(255) NULL',
        'Source_Contract_SHA256' => 'char(64) NULL',
        'Source_Candidate_Count' => 'int NULL',
    ];

    public function up(): void
    {
        $db = DB::connection($this->getConnection());
        if (! in_array($db->getDriverName(), ['sqlsrv', 'sqlite'], true)
            || $db->getTablePrefix() !== '' || $db->transactionLevel() !== 0) {
            throw new RuntimeException('SMS attribution evidence requires its own unprefixed SQL Server/SQLite transaction.');
        }
        $db->transaction(function () use ($db): void {
            $schema = $db->getSchemaBuilder();
            $required = [
                'TblSMSRuns' => ['Request_ID', 'Status', 'Selection_Policy', 'Approved_At', 'Failed_Step'],
                'TblSMSResponseAttribution' => ['Prospect_PK', 'Request_ID', 'Contact_At_UTC', 'Contacted_Date', 'Created_At'],
                'TblSMSHistory' => ['Prospect_PK', 'Phone', 'Request_ID'],
                'TblProspectsExport' => ['Prospect_PK', 'Phone', 'Request_ID'],
            ];
            foreach ($required as $table => $columns) {
                if (! $schema->hasTable($table) || ! $schema->hasColumns($table, $columns)) {
                    throw new RuntimeException('Apply the reviewed record-approval schema before attribution evidence.');
                }
            }
            if ($schema->hasColumn('TblSMSRuns', 'Approved_At_Precision')) {
                throw new RuntimeException('SMS approval precision already exists or is partially installed.');
            }
            foreach (array_keys(self::EVIDENCE) as $column) {
                if ($schema->hasColumn('TblSMSResponseAttribution', $column)) {
                    throw new RuntimeException('SMS contact evidence already exists or is partially installed.');
                }
            }
            foreach (['TblSMSHistory', 'TblProspectsExport'] as $table) {
                if ($schema->hasIndex($table, strtolower('IX_'.$table.'_Contact_Attribution'))) {
                    throw new RuntimeException('SMS attribution lookup index already exists or is partially installed.');
                }
            }
            if ($db->getDriverName() === 'sqlsrv') {
                $rows = $db->selectFromWriteConnection("SELECT s.name AS schema_name,t.name FROM sys.tables t JOIN sys.schemas s ON s.schema_id=t.schema_id WHERE t.name IN ('TblSMSRuns','TblSMSResponseAttribution','TblSMSHistory','TblProspectsExport')");
                if (count($rows) !== 4 || array_filter($rows, static fn ($row) => $row->schema_name !== 'dbo')) {
                    throw new RuntimeException('SMS attribution requires unambiguous dbo tables.');
                }
            }
            $prefix = $db->getDriverName() === 'sqlsrv' ? '[dbo].' : '"main".';
            $quote = static fn (string $value): string => $db->getQueryGrammar()->wrap($value);
            $db->statement('ALTER TABLE '.$prefix.$quote('TblSMSRuns').' ADD '.$quote('Approved_At_Precision').' tinyint NULL');
            foreach (self::EVIDENCE as $column => $type) {
                $db->statement('ALTER TABLE '.$prefix.$quote('TblSMSResponseAttribution').' ADD '.$quote($column).' '.$type);
            }
            foreach (['TblSMSHistory', 'TblProspectsExport'] as $table) {
                // Seek one prospect/phone's history; never scan the entire campaign population per event.
                $index = $quote('IX_'.$table.'_Contact_Attribution');
                $db->statement('CREATE INDEX '.($db->getDriverName() === 'sqlite' ? '"main".' : '').$index
                    .' ON '.($db->getDriverName() === 'sqlsrv' ? '[dbo].' : '').$quote($table)
                    .' ('.$quote('Prospect_PK').','.$quote('Phone').','.$quote('Request_ID').')');
            }
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Retain original contact provenance and approval precision; roll back application code, not evidence.');
    }
};
