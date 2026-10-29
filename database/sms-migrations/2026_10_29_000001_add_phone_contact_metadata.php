<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Add source/status audit metadata without rewriting or deleting phone evidence. */
return new class extends Migration
{
    protected $connection = 'sqlsrv';

    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());
        if (! $schema->hasTable('TblPhoneNumbers')) throw new RuntimeException('Phone metadata requires the existing phone table.');
        foreach ([
            'Data_Source_ID' => ['string', 50],
            'Data_Source_Origin' => ['string', 20],
            'Data_Source_Map_SHA256' => ['char', 64],
            'Status' => ['string', 256],
            'Status_ID' => ['string', 50],
            'Status_Stamp' => ['string', 64],
        ] as $column => [$type, $length]) {
            if ($schema->hasColumn('TblPhoneNumbers', $column)) continue;
            $schema->table('TblPhoneNumbers', function (Blueprint $table) use ($column, $type, $length): void {
                $table->{$type}($column, $length)->nullable();
            });
        }
    }

    // This metadata is historical evidence; rollback never discards it.
    public function down(): void {}
};
