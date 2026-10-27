<?php

declare(strict_types=1);

namespace Cmd\Reports\Tests\Support;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;

/** Owned synthetic current/history membership for outbound workflow suppression tests. */
final class SmsFrozenEligibilityFixture
{
    public static function seed(ConnectionInterface $db, string $id): array
    {
        $schema = $db->getSchemaBuilder();
        if (! $schema->hasTable('TblProspects')) $schema->create('TblProspects', function (Blueprint $t): void {
            $t->increments('PK'); $t->string('State'); $t->string('Phone');
            foreach (['Contacted','Opt_Out','Invalid_Phone'] as $flag) $t->integer($flag)->default(0);
        });
        if (! $schema->hasTable('TblSMSHistory')) $schema->create('TblSMSHistory', function (Blueprint $t): void {
            $t->increments('PK'); $t->string('Request_ID'); $t->integer('Prospect_PK');
            $t->string('State'); $t->string('Phone'); $t->string('Export_Date');
        });
        if (! $schema->hasTable('TblPhoneNumbers')) $schema->create('TblPhoneNumbers', fn (Blueprint $t) => $t->string('Phone'));
        if (! $schema->hasTable('TblSMSSuppressions')) $schema->create('TblSMSSuppressions', function (Blueprint $t): void {
            $t->string('Phone')->primary(); $t->integer('Opt_Out')->default(0); $t->integer('Invalid_Phone')->default(0);
        });
        $ids = [];
        foreach (['2025550100','2025550100','2025550102'] as $phone) {
            $pk = $db->table('TblProspects')->insertGetId(['State' => 'CA', 'Phone' => $phone]);
            $db->table('TblSMSHistory')->insert(['Request_ID' => $id, 'Prospect_PK' => $pk, 'State' => 'CA',
                'Phone' => $phone, 'Export_Date' => '2026-10-23 17:01:00']);
            $ids[] = (int) $pk;
        }
        return $ids;
    }
}
