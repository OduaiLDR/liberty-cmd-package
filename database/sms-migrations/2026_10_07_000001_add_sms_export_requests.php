<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sqlsrv';

    public function up(): void
    {
        Schema::connection($this->getConnection())->create('TblSmsExportRequests', function (Blueprint $table): void {
            $table->uuid('Request_ID')->primary();
            $table->string('Actor_Email', 255);
            $table->string('Status', 16);
            $table->bigInteger('Target');
            $table->text('Drop_PKs')->nullable();
            $table->string('Artifact_Key', 255)->nullable();
            $table->string('Artifact_Format', 8)->nullable();
            $table->bigInteger('Artifact_Bytes')->nullable();
            $table->bigInteger('SMS_Count')->nullable();
            $table->integer('Part_Count')->nullable();
            $table->string('Error', 500)->nullable();
            $table->dateTime('Created_At');
            $table->dateTime('Updated_At');
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists('TblSmsExportRequests');
    }
};
