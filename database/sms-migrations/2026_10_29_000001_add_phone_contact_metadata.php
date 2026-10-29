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
        // Jacob keeps values only; the source and status IDs are used for lookup during sync, not stored.
        foreach ([
            'Status' => ['string', 256],
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
