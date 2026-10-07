<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sqlsrv';

    public function up(): void
    {
        Schema::connection($this->getConnection())->create('TblSmsSelectionRequests', function (Blueprint $table): void {
            $table->uuid('Request_ID')->primary();
            $table->string('Actor_Email', 255);
            $table->string('Status', 16);
            $table->string('Selection_Mode', 16);
            $table->bigInteger('Target')->nullable();
            $table->text('Drop_PKs')->nullable();
            $table->longText('Result')->nullable();
            $table->string('Error', 500)->nullable();
            $table->dateTime('Created_At');
            $table->dateTime('Updated_At');
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists('TblSmsSelectionRequests');
    }
};
