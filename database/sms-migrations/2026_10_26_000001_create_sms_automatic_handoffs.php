<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/** Explicit opt-in intent only. Never scans approvals, queues, publishes or sends. */
return new class extends Migration {
    protected $connection = 'sqlsrv';
    public $withinTransaction = false;
    public function up(): void
    {
        $db = DB::connection($this->getConnection());
        if (!in_array($db->getDriverName(), ['sqlsrv', 'sqlite'], true) || $db->getTablePrefix() !== ''
            || $db->transactionLevel() !== 0 || $db->getPdo()->inTransaction()) throw new RuntimeException('Automatic SMS evidence requires its own unprefixed SQL transaction.');
        $pdo = $db->getPdo();
        $identity = static fn (): string => json_encode($pdo->query($db->getDriverName() === 'sqlsrv' ? 'SELECT DB_NAME() AS db,@@SPID AS session' : 'PRAGMA database_list')->fetchAll(PDO::FETCH_ASSOC), JSON_THROW_ON_ERROR);
        $original = $identity();
        $guard = static function () use ($db, $pdo, $identity, $original): void {
            if ($db->getPdo() !== $pdo || $db->transactionLevel() !== 1 || !$pdo->inTransaction() || $identity() !== $original) throw new RuntimeException('Automatic SMS migration session changed.');
        };
        try { $db->transaction(function () use ($db, $guard): void {
            $guard();
            $schema = $db->getSchemaBuilder();
            foreach (['TblSMSRuns' => ['Request_ID','Status','Selection_Policy','Target','Selected','Approved_By','Approved_At','Sent_By','Sent_At','Run_Options_JSON','Artifact_Manifest_JSON','File_Path','File_Bytes','File_Sha256','File_Cleanup_Path'],
                'TblSMSFilePublications' => ['Request_ID','Run_Sha256','Plan_JSON','Plan_Sha256','State','Created_By','Created_At','Completed_At','Error_Code'],
                'TblSMSFilePublicationParts' => ['Request_ID','Part_Number','State','Attempted_At','Completed_At','Receipt_JSON','Receipt_Sha256','Error_Code'],
                'TblSMSEmailOutbox' => ['Request_ID','Run_Sha256','Payload_JSON','Payload_Sha256','State','Created_By','Created_At','Attempted_At','Accepted_At','Error_Code']] as $table => $columns) {
                if (!$schema->hasTable($table) || !$schema->hasColumns($table, $columns)) throw new RuntimeException('Install the explicit approval/publication/email prerequisites first.');
            }
            if ($schema->hasTable('TblSMSAutomaticHandoffs')) throw new RuntimeException('Retain existing automatic SMS evidence without adoption or repair.');
            if ($db->getDriverName() === 'sqlsrv') {
                $objects = $db->select("SELECT s.name AS schema_name,t.name FROM sys.tables t JOIN sys.schemas s ON s.schema_id=t.schema_id WHERE t.name IN ('TblSMSRuns','TblSMSFilePublications','TblSMSFilePublicationParts','TblSMSEmailOutbox','TblSMSAutomaticHandoffs')");
                if (count($objects) !== 4 || array_filter($objects, static fn ($r): bool => $r->schema_name !== 'dbo')) throw new RuntimeException('Automatic SMS evidence requires unambiguous dbo prerequisites.');
            }
            $guard(); $schema->create('TblSMSAutomaticHandoffs', function (Blueprint $t): void {
                $t->string('Request_ID', 36)->primary(); $t->longText('Plan_JSON'); $t->string('Plan_Sha256', 64);
                $t->string('State', 24); $t->string('Phase', 24); $t->string('Publication_Sha256', 64)->nullable();
                $t->string('Email_Sha256', 64)->nullable(); $t->string('Lease_Token', 64)->nullable();
                $t->bigInteger('Lease_Version')->default(0); $t->dateTime('Lease_Until', 6)->nullable();
                $t->dateTime('Created_At', 6); $t->dateTime('Updated_At', 6); $t->dateTime('Completed_At', 6)->nullable();
                $t->string('Error_Code', 64)->nullable(); $t->index(['State', 'Lease_Until', 'Request_ID'], 'sms_automatic_handoff_recovery');
            });
            $guard();
        }); } catch (Throwable) {
            if ($db->getPdo() !== $pdo) {
                foreach ([$pdo, $db->getPdo()] as $handle) try { if ($handle instanceof PDO && $handle->inTransaction()) $handle->rollBack(); } catch (Throwable) {}
                $db->disconnect();
            }
            throw new RuntimeException('The explicit automatic SMS migration did not complete; inspect its retained schema.');
        }
    }
    public function down(): void { throw new RuntimeException('Retain automatic SMS handoff evidence; roll back application code instead.'); }
};
