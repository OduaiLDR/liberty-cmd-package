<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Dedicated intake ledger. No existing prospect, mailer or SMS history schema is changed. */
return new class extends Migration
{
    protected $connection = 'sqlsrv';

    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());
        $schema->create('TblSMSEnrichmentBatches', function (Blueprint $t): void {
            $t->string('Batch_ID', 100)->primary();
            $t->string('Created_By', 255);
            $t->string('Status', 20); // staging, awaiting_return, returning, completed
            $t->dateTime('Created_At');
            $t->dateTime('Updated_At');
            $t->dateTime('Completed_At')->nullable();
        });
        $schema->create('TblSMSEnrichmentChunks', function (Blueprint $t): void {
            $t->bigIncrements('PK');
            $t->string('Batch_ID', 100);
            $t->integer('Chunk');
            $t->char('Payload_Hash', 64);
            $t->integer('Records');
            $t->integer('Rejected');
            $t->integer('Existing');
            $t->integer('Duplicate');
            $t->string('Created_By', 255);
            $t->dateTime('Created_At');
            $t->unique(['Batch_ID', 'Chunk'], 'UQ_SMSEnrichmentChunks_BatchChunk');
            $t->foreign('Batch_ID')->references('Batch_ID')->on('TblSMSEnrichmentBatches');
        });
        $schema->create('TblSMSEnrichmentPeople', function (Blueprint $t): void {
            $t->bigIncrements('PK');
            $t->string('Batch_ID', 100);
            $t->char('Person_Key', 64);
            $t->char('Active_Key', 64)->nullable();
            $t->string('Client', 150);
            $t->string('Address', 200);
            $t->string('City', 100)->nullable();
            $t->char('State', 2)->nullable();
            $t->string('Zip', 5);
            $t->string('Tier', 2)->nullable();
            $t->date('Creation_Date')->nullable();
            $t->string('Drop_Name', 255)->nullable();
            $t->string('External_ID', 255)->nullable();
            $t->bigInteger('Mailer_PK')->nullable();
            $t->boolean('Submitted')->default(false);
            $t->string('Status', 20); // pending, existing, duplicate, returned, closed_unreturned
            $t->unsignedInteger('Prospect_PK')->nullable();
            $t->string('Phone', 10)->nullable();
            $t->string('Email', 254)->nullable();
            $t->boolean('Added')->default(false);
            $t->boolean('Filled')->default(false);
            $t->boolean('Invalid_Phone_Result')->default(false);
            $t->boolean('Invalid_Email_Result')->default(false);
            $t->char('Return_Hash', 64)->nullable();
            $t->dateTime('Returned_At')->nullable();
            $t->dateTime('Created_At');
            $t->dateTime('Updated_At');
            $t->unique(['Batch_ID', 'Person_Key'], 'UQ_SMSEnrichmentPeople_BatchPerson');
            $t->index(['Batch_ID', 'Submitted', 'PK'], 'IX_SMSEnrichmentPeople_Export');
            $t->foreign('Batch_ID')->references('Batch_ID')->on('TblSMSEnrichmentBatches');
        });
        // SQL Server's ordinary UNIQUE index permits only one NULL. Terminal rows must
        // share NULL freely, while exactly one pending item can own a given person.
        $schema->getConnection()->statement('CREATE UNIQUE INDEX UQ_SMSEnrichmentPeople_Active
            ON TblSMSEnrichmentPeople (Active_Key) WHERE Active_Key IS NOT NULL');
        // The hash is an identifier, not the database's definition of person equality.
        // SQL Server inherits the configured CI collation, matching TblProspects. SQLite
        // needs NOCASE explicitly on both the lookup and the active-identity indexes.
        $identity = $schema->getConnection()->getDriverName() === 'sqlite'
            ? 'Client COLLATE NOCASE, Address COLLATE NOCASE, Zip'
            : 'Client, Address, Zip';
        $schema->getConnection()->statement("CREATE INDEX IX_SMSEnrichmentPeople_Identity
            ON TblSMSEnrichmentPeople ({$identity})");
        $schema->getConnection()->statement("CREATE UNIQUE INDEX UQ_SMSEnrichmentPeople_ActiveIdentity
            ON TblSMSEnrichmentPeople ({$identity}) WHERE Active_Key IS NOT NULL");
        // A no-match releases the person, but never makes the same purchased source row
        // billable again. A later purchase carries a different Mailer_PK.
        $schema->getConnection()->statement('CREATE UNIQUE INDEX UQ_SMSEnrichmentPeople_SubmittedMailer
            ON TblSMSEnrichmentPeople (Mailer_PK) WHERE Mailer_PK IS NOT NULL AND Submitted = 1');
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());
        foreach (['TblSMSEnrichmentPeople', 'TblSMSEnrichmentChunks', 'TblSMSEnrichmentBatches'] as $name) {
            $schema->dropIfExists($name);
        }
    }
};
