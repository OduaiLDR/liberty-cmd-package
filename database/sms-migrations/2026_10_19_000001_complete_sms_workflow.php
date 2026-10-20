<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Additive only: the 12 Oct migration is already deployed. Publish/run this file explicitly,
// after checking backups. No existing prospects, sends or purchased data are rewritten here.
return new class extends Migration
{
    protected $connection = 'sqlsrv';

    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());
        foreach (['TblProspectsExport', 'TblSMSHistory'] as $name) {
            $schema->table($name, function (Blueprint $t): void {
                $t->string('Client', 150)->nullable();
                $t->string('Address', 200)->nullable();
                $t->string('City', 100)->nullable();
                $t->char('State', 2)->nullable();
                $t->string('Zip', 10)->nullable();
                $t->string('Email', 254)->nullable();
            });
        }
        $schema->table('TblSMSRuns', function (Blueprint $t): void {
            $t->string('Progress_Step', 40)->nullable();
            $t->string('Closed_By', 255)->nullable();
            $t->string('Close_Reason', 500)->nullable();
            $t->dateTime('Closed_At')->nullable();
        });
        $schema->table('TblSMSDrops', function (Blueprint $t): void {
            $t->uuid('Request_ID')->nullable()->index();
            $t->string('Vendor', 100)->nullable();
            $t->string('Language', 50)->nullable();
        });
        // Exact before/after values for every prospect affected by a confirmed send (including
        // household matches). Never guess an old send date when correcting an accidental click.
        $schema->create('TblSMSAttemptAudit', function (Blueprint $t): void {
            $t->bigIncrements('PK');
            $t->uuid('Request_ID');
            $t->integer('Prospect_PK');
            $t->date('Previous_Date')->nullable();
            $t->integer('Previous_Attempts');
            $t->date('Recorded_Date');
            $t->integer('Recorded_Attempts');
            $t->unique(['Request_ID', 'Prospect_PK']);
        });
        $schema->create('TblSMSFeedbackImports', function (Blueprint $t): void {
            $t->bigIncrements('PK');
            $t->uuid('Request_ID');
            $t->integer('Chunk');
            $t->string('Content_Hash', 64);
            $t->integer('Records');
            $t->integer('Matched');
            $t->integer('Updated');
            $t->string('Created_By', 255);
            $t->dateTime('Created_At');
            $t->unique(['Request_ID', 'Chunk']);
        });
        $schema->create('TblSMSSuppressions', function (Blueprint $t): void {
            $t->string('Phone', 10)->primary();
            $t->boolean('Opt_Out')->default(false);
            $t->boolean('Invalid_Phone')->default(false);
            $t->dateTime('Updated_At');
        });
        $schema->create('TblSMSLegacySends', function (Blueprint $t): void {
            $t->string('Phone', 10);
            $t->date('Send_Date');
            $t->uuid('Request_ID')->nullable();
            $t->unique(['Phone', 'Send_Date']);
        });
        $schema->create('TblSMSMaintenanceJobs', function (Blueprint $t): void {
            $t->increments('PK');
            $t->uuid('Request_ID')->unique();
            $t->string('Status', 20);
            $t->date('Since_Date');
            $t->text('Drop_List')->nullable();
            $t->integer('Drops_Total')->default(0);
            $t->integer('Drops_Done')->default(0);
            $t->integer('Added')->default(0);
            $t->integer('Filled')->default(0);
            $t->string('Current_Drop', 100)->nullable();
            $t->string('Error', 500)->nullable();
            $t->string('Created_By', 255);
            $t->dateTime('Created_At');
            $t->dateTime('Updated_At');
        });
    }

    public function down(): void
    {
        // Do not silently destroy send audit/feedback history during a production rollback.
        throw new RuntimeException('SMS workflow data must be retained. Roll back application code, not this schema.');
    }
};
