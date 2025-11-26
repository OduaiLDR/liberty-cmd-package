<?php

namespace Cmd\Reports\Database\Seeders;

use App\Entities\TenantEntity;
use Cmd\Reports\Support\CmdReportPermissions;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Modules\Main\Permission\Models\Permission;
use Modules\Main\Role\Models\Role;

class CmdReportsPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedCentral();

        TenantEntity::query()->cursor()->each(function (TenantEntity $tenant) {
            tenancy()->initialize($tenant);
            $this->seedTenant();
            tenancy()->end();
        });
    }

    public function seedCentral(): void
    {
        $this->upsertPermissions(CmdReportPermissions::central());
        $this->ensureRoleHasPermissions('central');
    }

    public function seedTenant(): void
    {
        $this->upsertPermissions(CmdReportPermissions::tenant());
        $this->ensureRoleHasPermissions('tenant');
    }

    private function upsertPermissions(array $permissions): void
    {
        foreach ($permissions as $permission) {
            Permission::firstOrCreate(
                ['name' => $permission['name']],
                [
                    'id' => Str::uuid(),
                    'guard_name' => 'web',
                    'domain' => $permission['domain'],
                ],
            );
        }
    }

    private function ensureRoleHasPermissions(string $domain): void
    {
        $names = array_map(static fn($p) => $p['name'], $domain === 'tenant' ? CmdReportPermissions::tenant() : CmdReportPermissions::central());
        $role = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web'], ['domain' => $domain]);
        try { $role->syncPermissions($names); } catch (\Throwable $e) {}
    }
}