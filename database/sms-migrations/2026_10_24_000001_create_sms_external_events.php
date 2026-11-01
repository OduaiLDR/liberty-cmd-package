<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/** Explicit historical-event ledger only. Does not register a send or rewrite prospects. */
return new class extends Migration
{
    protected $connection = 'sqlsrv';
    public $withinTransaction = false;

    public function up(): void
    {
        $db = DB::connection($this->getConnection());
        if (! in_array($db->getDriverName(), ['sqlsrv', 'sqlite'], true)
            || $db->getTablePrefix() !== '' || $db->transactionLevel() !== 0 || $db->getPdo()->inTransaction()) {
            throw new RuntimeException('External SMS schema requires its own unprefixed SQL Server/SQLite transaction.');
        }
        $db->transaction(function () use ($db): void {
            $schema = $db->getSchemaBuilder();
            foreach (['TblSMSRuns' => ['Request_ID','Selection_Policy','Status','Run_Options_JSON'],
                'TblProspects' => ['PK','Phone','Client','Address','SMS_Attempts','Last_SMS_Date','Latest_Export_Date'],
                'TblSMSHistory' => ['Request_ID','Prospect_PK','Phone','Export_Date'],
                'TblSMSResponseAttribution' => ['Prospect_PK','Request_ID'],
                'TblSMSAvailabilitySummary' => ['scope','revision']] as $table => $columns) {
                if (! $schema->hasTable($table) || ! $schema->hasColumns($table, $columns)) {
                    throw new RuntimeException('Missing reviewed external SMS prerequisite: '.$table);
                }
            }
            $names = ['TblSMSExternalEvents','TblSMSExternalMembers','TblSMSExternalChunks'];
            foreach ($names as $name) if ($schema->hasTable($name)) {
                throw new RuntimeException('External SMS schema already exists or is partially installed.');
            }
            if ($db->getDriverName() === 'sqlsrv') {
                $objects = $db->select("SELECT s.name AS schema_name,o.name FROM sys.objects o JOIN sys.schemas s ON s.schema_id=o.schema_id WHERE o.name IN ('TblSMSExternalEvents','TblSMSExternalMembers','TblSMSExternalChunks')");
                if ($objects !== []) throw new RuntimeException('Conflicting external SMS schema object.');
                $base = $db->select("SELECT s.name AS schema_name,t.name FROM sys.tables t JOIN sys.schemas s ON s.schema_id=t.schema_id WHERE t.name IN ('TblSMSRuns','TblProspects','TblSMSHistory','TblSMSResponseAttribution','TblSMSAvailabilitySummary')");
                if (count($base) !== 5 || array_filter($base, static fn ($row) => $row->schema_name !== 'dbo')) {
                    throw new RuntimeException('External SMS prerequisites must be unambiguous dbo tables.');
                }
            }
            $prefix=$db->getDriverName()==='sqlsrv'?'dbo.':'';
            $schema->create($prefix.'TblSMSExternalEvents', function (Blueprint $t) use ($prefix): void {
                $t->uuid('Request_ID')->primary();
                $t->string('Event_Key',80)->unique();
                $t->string('Policy_Version',40);
                $t->string('State',16);
                $t->dateTime('Occurred_At_UTC',0);
                $t->unsignedTinyInteger('Occurred_Precision');
                $t->date('Business_Date');
                $t->string('Reported_Submitter',255)->nullable();
                $t->string('Registered_By',255);
                $t->dateTime('Started_At_UTC',6);
                $t->dateTime('Updated_At_UTC',6);
                $t->dateTime('Completed_At_UTC',6)->nullable();
                $t->integer('Expected_Rows');
                $t->integer('Staged_Rows')->default(0);
                $t->integer('Applied_Rows')->default(0);
                $t->integer('Last_Applied_PK')->nullable();
                $t->integer('Last_Chunk')->default(0);
                $t->char('Plan_SHA256',64);
                $t->char('Membership_SHA256',64);
                $t->char('Preview_SHA256',64);
                $t->char('Before_State_SHA256',64);
                $t->text('Original_Artifacts_JSON');
                $t->string('Error_Code',80)->nullable();
                $t->dateTime('Error_At_UTC',6)->nullable();
                $t->index(['State','Request_ID'],'IX_SMSExternal_State_Request');
                $t->foreign('Request_ID')->references('Request_ID')->on($prefix.'TblSMSRuns');
            });
            $schema->create($prefix.'TblSMSExternalMembers', function (Blueprint $t) use ($prefix): void {
                $t->uuid('Request_ID');
                $t->integer('Prospect_PK');
                $t->string('Original_Phone',10);
                $t->string('Original_First_Name',150);
                $t->string('Original_Address',200);
                $t->string('Original_Debt_Text',100);
                $t->decimal('Original_Debt_Amount',12,2)->nullable();
                $t->date('Original_Send_Date');
                $t->unsignedTinyInteger('Part_Number');
                $t->integer('Part_Row');
                $t->char('Row_SHA256',64);
                $t->char('Before_SHA256',64);
                $t->integer('Expected_Attempts');
                $t->date('Expected_Last_SMS_Date')->nullable();
                $t->date('Expected_Latest_Export_Date')->nullable();
                $t->integer('Applied_Chunk')->nullable();
                $t->dateTime('Applied_At_UTC',6)->nullable();
                $t->integer('Previous_Attempts')->nullable();
                $t->integer('Recorded_Attempts')->nullable();
                foreach (['Previous_Last_SMS_Date','Recorded_Last_SMS_Date','Previous_Latest_Export_Date','Recorded_Latest_Export_Date'] as $column) $t->date($column)->nullable();
                $t->primary(['Request_ID','Prospect_PK']);
                $t->unique(['Request_ID','Part_Number','Part_Row'],'UQ_SMSExternal_Source_Row');
                $t->index(['Prospect_PK','Original_Phone','Request_ID'],'IX_SMSExternal_Prospect_Phone');
                $t->foreign('Request_ID')->references('Request_ID')->on($prefix.'TblSMSExternalEvents');
            });
            $schema->create($prefix.'TblSMSExternalChunks', function (Blueprint $t) use ($prefix): void {
                $t->uuid('Request_ID');
                $t->integer('Chunk_Number');
                $t->integer('After_PK')->nullable();
                $t->integer('Through_PK');
                $t->integer('Members');
                $t->char('Membership_SHA256',64);
                $t->integer('Updated_Prospects');
                $t->integer('History_Rows');
                $t->dateTime('Committed_At_UTC',6);
                $t->primary(['Request_ID','Chunk_Number']);
                $t->unique(['Request_ID','Through_PK'],'UQ_SMSExternal_Chunk_Through');
                $t->foreign('Request_ID')->references('Request_ID')->on($prefix.'TblSMSExternalEvents');
            });
            if ($db->getDriverName()==='sqlsrv') {
                // Laravel's SQL Server dateTime grammar emits datetime regardless
                // of requested precision. Correct the newly empty ledger explicitly.
                foreach (['TblSMSExternalEvents'=>['Occurred_At_UTC'=>[0,false],'Started_At_UTC'=>[6,false],
                    'Updated_At_UTC'=>[6,false],'Completed_At_UTC'=>[6,true],'Error_At_UTC'=>[6,true]],
                    'TblSMSExternalMembers'=>['Applied_At_UTC'=>[6,true]],
                    'TblSMSExternalChunks'=>['Committed_At_UTC'=>[6,false]]] as $table=>$columns) {
                    foreach ($columns as $name=>[$precision,$nullable]) $db->statement('ALTER TABLE [dbo].['.$table.'] ALTER COLUMN ['.$name.'] datetime2('.$precision.') '.($nullable?'NULL':'NOT NULL'));
                }
            }
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Retain historical SMS event, chunk and before-value evidence. Disable the writer; never delete this ledger.');
    }
};
