<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sqlsrv';

    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());
        $schema->table('TblMarketing', function (Blueprint $table): void {
            $table->integer('SMS_Drops')->default(0);
            $table->dateTime('SMS_Last_Export_Date')->nullable();
        });
        $schema->table('TblPhoneNumbers', function (Blueprint $table): void {
            $table->index('Phone', 'IX_TblPhoneNumbers_SMS_Phone');
        });
        $schema->create('TblSmsExports', function (Blueprint $table): void {
            $table->bigInteger('PK')->primary();
            $table->string('SMS_Drop_Name', 32)->unique();
            $table->uuid('Request_ID')->index();
            $table->string('Debt_Tier', 100);
            $table->date('Week_Start')->index();
            $table->dateTime('Exported_At');
            $table->bigInteger('SMS_Count');
            $table->string('SMS_Invoice_Number', 100)->nullable();
            $table->decimal('SMS_Cost', 12, 2)->default(0);
            $table->unique(['Request_ID', 'Debt_Tier']);
        });
        $schema->create('TblSmsExportSources', function (Blueprint $table): void {
            $table->bigInteger('SMS_Export_PK');
            $table->integer('Marketing_PK');
            $table->bigInteger('SMS_Count');
            $table->primary(['SMS_Export_PK', 'Marketing_PK']);
            $table->foreign('SMS_Export_PK')->references('PK')->on('TblSmsExports');
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());
        $schema->table('TblPhoneNumbers', function (Blueprint $table): void {
            $table->dropIndex('IX_TblPhoneNumbers_SMS_Phone');
        });
        $schema->dropIfExists('TblSmsExportSources');
        $schema->dropIfExists('TblSmsExports');
        $schema->table('TblMarketing', function (Blueprint $table): void {
            $table->dropColumn(['SMS_Drops', 'SMS_Last_Export_Date']);
        });
    }
};
