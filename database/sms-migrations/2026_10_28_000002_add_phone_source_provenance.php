<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/** Add wide contact phones/provenance and reviewed indexes without rewriting or deleting existing rows. */
return new class extends Migration
{
    protected $connection = 'sqlsrv';

    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());
        $db=DB::connection($this->getConnection());
        if (! $schema->hasTable('TblPhoneNumbers')) throw new RuntimeException('Phone provenance requires the existing phone table.');
        $phoneCollation=null;
        if($db->getDriverName()==='sqlsrv') {
            $phoneCollation=$db->selectOne("SELECT collation_name FROM sys.columns WHERE object_id=OBJECT_ID(N'TblPhoneNumbers') AND name=N'Phone'")?->collation_name;
            if(!is_string($phoneCollation)||!preg_match('/\A[A-Za-z0-9_]+\z/D',$phoneCollation)) throw new RuntimeException('Wide phone columns require the existing Phone collation.');
        }
        foreach (['Data_Source' => 50, 'External_ID' => 256, 'Phone1'=>50, 'Phone2'=>50, 'Phone3'=>50, 'Phone4'=>50] as $column => $length) {
            if (! $schema->hasColumn('TblPhoneNumbers', $column)) {
                $schema->table('TblPhoneNumbers', function(Blueprint $table) use($column,$length,$phoneCollation):void {
                    $definition=$table->string($column,$length)->nullable();
                    if(str_starts_with($column,'Phone') && $phoneCollation!==null) $definition->collation($phoneCollation);
                });
            }
        }
        if($phoneCollation!==null) {
            $columns=$db->select("SELECT name,collation_name FROM sys.columns WHERE object_id=OBJECT_ID(N'TblPhoneNumbers') AND name IN(N'Phone1',N'Phone2',N'Phone3',N'Phone4')");
            if(count($columns)!==4) throw new RuntimeException('Wide phone columns are incomplete.');
            foreach($columns as $column) if($column->collation_name!==$phoneCollation) throw new RuntimeException('Wide phone columns must retain the existing Phone collation.');
        }
        $indexes=['UX_TblPhoneNumbers_Wide_Source_CID'=>['Source,CID','Phone1'],
            'IX_TblPhoneNumbers_Phone1'=>['Phone1','Phone1'],'IX_TblPhoneNumbers_Phone2'=>['Phone2','Phone2'],
            'IX_TblPhoneNumbers_Phone3'=>['Phone3','Phone3'],'IX_TblPhoneNumbers_Phone4'=>['Phone4','Phone4']];
        foreach($indexes as $name=>[$columns,$filter]) {
            if(in_array(strtolower($name),array_map('strtolower',$schema->getIndexListing('TblPhoneNumbers')),true)) continue;
            $unique=str_starts_with($name,'UX_')?'UNIQUE ':'';
            $db->statement("CREATE {$unique}INDEX {$name} ON TblPhoneNumbers({$columns}) WHERE {$filter} IS NOT NULL");
        }
    }

    // Provenance can be historical evidence; rollback does not discard it.
    public function down(): void {}
};
