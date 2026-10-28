<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Add provenance without rewriting or deleting any existing phone row. */
return new class extends Migration
{
    protected $connection = 'sqlsrv';

    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());
        if (! $schema->hasTable('TblPhoneNumbers')) throw new RuntimeException('Phone provenance requires the existing phone table.');
        foreach (['Data_Source' => 50, 'External_ID' => 256] as $column => $length) {
            if (! $schema->hasColumn('TblPhoneNumbers', $column)) {
                $schema->table('TblPhoneNumbers', fn (Blueprint $table) => $table->string($column, $length)->nullable());
            }
        }
    }

    // Provenance can be historical evidence; rollback does not discard it.
    public function down(): void {}
};
