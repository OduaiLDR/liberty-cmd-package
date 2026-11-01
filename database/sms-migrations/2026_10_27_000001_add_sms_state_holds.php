<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Additive aggregate evidence for records held by the mandatory SMS State policy. */
return new class extends Migration
{
    protected $connection = 'sqlsrv';

    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());
        if ($schema->hasTable('TblSMSEnrichmentChunks') && ! $schema->hasColumn('TblSMSEnrichmentChunks', 'State_Held')) {
            $schema->table('TblSMSEnrichmentChunks', function (Blueprint $table): void {
                $table->integer('State_Held')->default(0);
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());
        if ($schema->hasTable('TblSMSEnrichmentChunks') && $schema->hasColumn('TblSMSEnrichmentChunks', 'State_Held')) {
            $schema->table('TblSMSEnrichmentChunks', fn (Blueprint $table) => $table->dropColumn('State_Held'));
        }
    }
};
