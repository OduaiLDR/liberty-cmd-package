<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sqlsrv';

    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());
        $schema->create('TblProspectMailDateBuilds', function (Blueprint $table): void {
            $table->id();
            $table->char('Source_Version', 64)->index();
            $table->text('Source_Snapshot');
            $table->unsignedBigInteger('Previous_Build_ID')->nullable();
            $table->boolean('Reusable')->default(true);
            $table->integer('Prospect_Max_PK');
            $table->integer('Cursor_PK')->default(0);
            $table->dateTime('Published_At')->nullable();
            $table->dateTime('Retired_At')->nullable();
        });
        $schema->create('TblProspectMailDates', function (Blueprint $table): void {
            $table->unsignedBigInteger('Build_ID');
            $table->integer('Prospect_PK');
            $table->date('Mailed_On');
            $table->primary(['Build_ID', 'Prospect_PK']);
        });

        DB::connection($this->getConnection())->statement(
            'CREATE INDEX IX_TblProspectMailDates_Order ON TblProspectMailDates (Build_ID, Mailed_On DESC, Prospect_PK ASC)',
        );
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());
        $schema->dropIfExists('TblProspectMailDates');
        $schema->dropIfExists('TblProspectMailDateBuilds');
    }
};
