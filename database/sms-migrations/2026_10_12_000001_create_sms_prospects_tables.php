<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Person-level SMS (Jacob, 12-13 Oct 2026): one prospect per person instead of exporting whole
 * mail drops, which sent the same person once per drop and the same phone many times.
 *
 * A person is Client + Address + Zip, unique under SQL Server's case-insensitive collation;
 * names keep their original case. Nobody gets a second SMS until every eligible prospect at 0
 * attempts has had one: order by SMS_Attempts, then newest Creation_Date, then oldest
 * Latest_SMS_Date. Contacted, Invalid_Phone and Opt_Out exclude a prospect.
 */
return new class extends Migration
{
    protected $connection = 'sqlsrv';

    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());

        // Queue of people from each CMD mailer import who are not in TblProspects yet, waiting
        // for TU. Rows leave the queue once their TU result is processed, so someone TU found
        // nothing for can be queued again when their data is bought again.
        $schema->create('TblProspectsPending', function (Blueprint $table): void {
            $table->bigIncrements('PK');
            $table->string('Client', 150);
            $table->string('Address', 255);
            $table->string('City', 100)->nullable();
            $table->string('State', 50)->nullable();
            $table->string('Zip', 10);
            $table->string('Debt_Tier', 30)->nullable();
            $table->string('Drop_Name', 100)->nullable();
            $table->string('External_ID', 50)->nullable();
            $table->string('TU_Batch', 50)->nullable()->index();
            $table->dateTime('Sent_To_TU_At')->nullable();
            $table->dateTime('Created_At');
            $table->unique(['Client', 'Address', 'Zip'], 'UX_TblProspectsPending_Person');
        });

        // One row per person with a phone or email. Creation_Date is the send date of the drop
        // the person came from (TblMarketing). Zip is the 5-digit zip (TblMailersUniqueEnriched
        // .Zip5_Normalized), so ZIP+4 variants stay one person; Phone is the normalized 10 digits.
        // Sizes follow TblMailersUniqueEnriched; Debt_Tier follows TblTUData.Tier_Tag.
        // Drop_Name / External_ID make the TblMailers debt lookup exact.
        $schema->create('TblProspects', function (Blueprint $table): void {
            $table->increments('PK');
            $table->date('Creation_Date');
            $table->string('Client', 150);
            $table->string('Address', 255);
            $table->string('City', 100)->nullable();
            $table->string('State', 50)->nullable();
            $table->string('Zip', 10);
            $table->string('Phone', 10)->nullable()->index();
            $table->string('Email', 255)->nullable();
            $table->boolean('Contacted')->default(false);
            $table->boolean('Invalid_Phone')->default(false);
            $table->boolean('Opt_Out')->default(false);
            $table->dateTime('Latest_SMS_Date')->nullable();
            $table->integer('SMS_Attempts')->default(0);
            $table->string('Debt_Tier', 30)->nullable();
            $table->string('Drop_Name', 100)->nullable();
            $table->string('External_ID', 50)->nullable();
            $table->unique(['Client', 'Address', 'Zip'], 'UX_TblProspects_Person');
            $table->index(['SMS_Attempts', 'Latest_SMS_Date', 'Creation_Date'], 'IX_TblProspects_SMS_Priority');
        });

        // Per TU batch: how many were sent and what came back.
        $schema->create('TblProspectEnrichmentLog', function (Blueprint $table): void {
            $table->bigIncrements('PK');
            $table->string('TU_Batch', 50)->unique();
            $table->dateTime('Submitted_At');
            $table->integer('Submitted');
            $table->dateTime('Returned_At')->nullable();
            $table->integer('With_Phone')->nullable();
            $table->integer('With_Email')->nullable();
            $table->integer('With_Both')->nullable();
            $table->integer('With_Neither')->nullable();
        });

        // SMS drops, like TblMarketing for mailers: one per tier per export (SMS0001, SMS0002, ...).
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

        // Every exported SMS. Also the export table: an export run is selected into it, given its
        // SMS drops, and the file is written from it. The debt, tier and phone are what was sent,
        // with where the debt came from (TblTUData, TblMailers or None) and that row's key.
        $schema->create('TblSMSHistory', function (Blueprint $table): void {
            $table->bigIncrements('PK');
            $table->uuid('Request_ID');
            $table->integer('SMS_Drop_PK')->nullable()->index();
            $table->string('Drop_Name', 32)->nullable();
            $table->unsignedInteger('Prospect_PK')->index();
            $table->string('Phone', 10);
            $table->dateTime('Export_Date')->nullable();
            $table->decimal('Debt_Amount', 12, 2)->nullable();
            $table->string('Debt_Tier', 30)->nullable();
            $table->string('Debt_Source', 20)->nullable();
            $table->bigInteger('Debt_Source_PK')->nullable();
            $table->unique(['Request_ID', 'Prospect_PK']);
            $table->unique(['Request_ID', 'Phone']);
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());
        foreach (['TblSMSHistory', 'TblSMSRuns', 'TblSMSDrops', 'TblProspectEnrichmentLog', 'TblProspects', 'TblProspectsPending'] as $table) {
            $schema->dropIfExists($table);
        }
    }
};
