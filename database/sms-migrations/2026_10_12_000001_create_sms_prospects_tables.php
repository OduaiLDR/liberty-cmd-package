<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Person-level SMS (Jacob, 12-14 Oct 2026): one prospect per person instead of exporting whole
 * mail drops, which sent the same person once per drop and the same phone many times.
 *
 * TblProspects is Jacob's schema (14 Oct) plus a primary key and 0 defaults. A run's people are
 * selected into TblProspectsExport, which holds only the open run; when the run is marked as sent
 * its rows move to TblSMSHistory. TblPhoneNumbers gets the contact's creation date (CA time) so
 * Response_Date can be filled when a prospect becomes a contact.
 */
return new class extends Migration
{
    protected $connection = 'sqlsrv';

    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());
        $this->createProspects($schema);

        // The open run's rows, until it is marked as sent (then moved to TblSMSHistory) or discarded.
        // Debt_Source is TblTUData, TblMailers, Tier, or None when there is no amount;
        // Debt_Source_PK is that row's key (NULL for Tier and None).
        $schema->create('TblProspectsExport', function (Blueprint $table): void {
            $table->bigIncrements('PK');
            $table->uuid('Request_ID');
            $table->integer('SMS_Drop_PK')->nullable();
            $table->string('Drop_Name', 32)->nullable();
            $table->unsignedInteger('Prospect_PK');
            $table->string('Phone', 10);
            $table->decimal('Debt_Amount', 12, 2)->nullable();
            $table->string('Debt_Tier', 30)->nullable();
            $table->string('Debt_Source', 20)->nullable();
            $table->bigInteger('Debt_Source_PK')->nullable();
            $table->unique(['Request_ID', 'Prospect_PK']);
            $table->unique(['Request_ID', 'Phone']);
        });

        // Like TblMarketing for SMS: one row per SMS drop (SMSxxxx), one drop per debt tier per run.
        // Invoice number and cost keep the weekly SMS invoice split working.
        $schema->create('TblSMSDrops', function (Blueprint $table): void {
            $table->increments('PK');
            $table->string('Drop_Name', 32)->unique();
            $table->string('Debt_Tier', 30)->nullable();
            $table->date('Drop_Date');
            $table->integer('Amount_Dropped')->default(0);
            $table->string('Invoice_Number', 100)->nullable();
            $table->decimal('Cost', 12, 2)->nullable();
            $table->integer('Calls')->nullable();
            $table->dateTime('Created_At');
        });

        // One row per export run, for the Mail Drop Export page: where the run is, its totals,
        // its file, and who started and sent it. Only one run may be open (not sent or discarded).
        $schema->create('TblSMSRuns', function (Blueprint $table): void {
            $table->increments('PK');
            $table->uuid('Request_ID')->unique();
            $table->integer('Target');
            // queued, selecting, review, building_file, file_ready, sending, sent, discarded, failed
            $table->string('Status', 20)->index();
            $table->string('Failed_Step', 20)->nullable();
            $table->string('Error', 500)->nullable();
            $table->integer('Selected')->nullable();
            $table->integer('Debt_TU')->nullable();
            $table->integer('Debt_Mailers')->nullable();
            $table->integer('Debt_Tier_Default')->nullable();
            $table->integer('Debt_Missing')->nullable();
            $table->string('File_Path', 255)->nullable();
            $table->bigInteger('File_Bytes')->nullable();
            $table->string('File_Sha256', 64)->nullable();
            $table->string('Created_By', 255);
            $table->string('Sent_By', 255)->nullable();
            $table->dateTime('Created_At');
            $table->dateTime('Updated_At');
            $table->dateTime('File_At')->nullable();
            $table->dateTime('Sent_At')->nullable();
        });

        // Every SMS that was sent: a sent run's rows from TblProspectsExport, with the debt, tier and
        // phone that went out and where the debt came from.
        $schema->create('TblSMSHistory', function (Blueprint $table): void {
            $table->bigIncrements('PK');
            $table->uuid('Request_ID');
            $table->integer('SMS_Drop_PK')->nullable()->index();
            $table->string('Drop_Name', 32)->nullable();
            $table->unsignedInteger('Prospect_PK')->index();
            $table->string('Phone', 10);
            $table->dateTime('Export_Date');
            $table->decimal('Debt_Amount', 12, 2)->nullable();
            $table->string('Debt_Tier', 30)->nullable();
            $table->string('Debt_Source', 20)->nullable();
            $table->bigInteger('Debt_Source_PK')->nullable();
            $table->unique(['Request_ID', 'Prospect_PK']);
            $table->unique(['Request_ID', 'Phone']);
        });

        // The contact's creation date (Snowflake CONTACTS.CREATED, CA date), filled by Sync:phone-numbers.
        // Adding a nullable column changes no rows.
        if ($schema->hasTable('TblPhoneNumbers') && ! $schema->hasColumn('TblPhoneNumbers', 'Created_Date')) {
            $schema->table('TblPhoneNumbers', function (Blueprint $table): void {
                $table->date('Created_Date')->nullable();
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());
        if ($schema->hasTable('TblPhoneNumbers') && $schema->hasColumn('TblPhoneNumbers', 'Created_Date')) {
            $schema->table('TblPhoneNumbers', fn (Blueprint $table) => $table->dropColumn('Created_Date'));
        }
        foreach (['TblSMSHistory', 'TblSMSRuns', 'TblSMSDrops', 'TblProspectsExport', 'TblProspects'] as $table) {
            $schema->dropIfExists($table);
        }
    }

    /**
     * Jacob's TblProspects. SQL Server gets his exact DDL (varchar columns, filtered indexes);
     * Laravel's schema builder only makes nvarchar, so the builder version is for the SQLite tests.
     */
    private function createProspects(\Illuminate\Database\Schema\Builder $schema): void
    {
        $db = $schema->getConnection();
        if ($db->getDriverName() !== 'sqlsrv') {
            $schema->create('TblProspects', function (Blueprint $table): void {
                $table->increments('PK');
                $table->date('Creation_Date')->nullable();
                $table->string('Client', 150)->nullable();
                $table->string('Address', 200)->nullable();
                $table->string('City', 100)->nullable();
                $table->char('State', 2)->nullable();
                $table->string('Zip', 10)->nullable();
                $table->string('Tier', 2)->nullable();
                $table->string('Phone', 10)->nullable()->index('IX_TblProspects_Phone');
                $table->string('Email', 254)->nullable();
                $table->boolean('Contacted')->default(false);
                $table->date('Response_Date')->nullable();
                $table->boolean('Invalid_Phone')->default(false);
                $table->boolean('Opt_Out')->default(false);
                $table->date('Latest_Export_Date')->nullable();
                $table->integer('SMS_Attempts')->default(0);
                $table->index(['Latest_Export_Date', 'Creation_Date', 'PK'], 'IX_TblProspects_ExportQueue');
                $table->index(['Client', 'Address', 'Zip'], 'IX_TblProspects_Match');
            });

            return;
        }

        $db->unprepared(<<<'SQL'
CREATE TABLE dbo.TblProspects (
    PK INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_TblProspects PRIMARY KEY CLUSTERED,
    Creation_Date DATE NULL,
    Client VARCHAR(150) NULL,
    Address VARCHAR(200) NULL,
    City VARCHAR(100) NULL,
    State CHAR(2) NULL,
    Zip VARCHAR(10) NULL,
    Tier VARCHAR(2) NULL,
    Phone VARCHAR(10) NULL,
    Email VARCHAR(254) NULL,
    Contacted BIT NOT NULL CONSTRAINT DF_TblProspects_Contacted DEFAULT 0,
    Response_Date DATE NULL,
    Invalid_Phone BIT NOT NULL CONSTRAINT DF_TblProspects_Invalid_Phone DEFAULT 0,
    Opt_Out BIT NOT NULL CONSTRAINT DF_TblProspects_Opt_Out DEFAULT 0,
    Latest_Export_Date DATE NULL,
    SMS_Attempts INT NOT NULL CONSTRAINT DF_TblProspects_SMS_Attempts DEFAULT 0
);

-- Selecting eligible prospects in rotation order.
CREATE NONCLUSTERED INDEX IX_TblProspects_ExportQueue
ON dbo.TblProspects (Latest_Export_Date ASC, Creation_Date ASC, PK ASC)
WHERE Contacted = 0 AND Invalid_Phone = 0 AND Opt_Out = 0 AND Phone IS NOT NULL;

-- The nightly comparison against TblPhoneNumbers.
CREATE NONCLUSTERED INDEX IX_TblProspects_Phone
ON dbo.TblProspects (Phone)
WHERE Contacted = 0 AND Phone IS NOT NULL;

-- Checking incoming weekly records against existing prospects before enrichment.
CREATE NONCLUSTERED INDEX IX_TblProspects_Match
ON dbo.TblProspects (Client, Address, Zip);
SQL);
    }
};
