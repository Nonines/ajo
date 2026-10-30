<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class StaffPermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $managerPermissions = [
            'customers.view', 'exceptions.manage', 'withdrawals.review',
            'withdrawals.make', 'withdrawals.check', 'adjustments.make',
            'adjustments.check', 'waivers.make', 'waivers.check',
            'plans.hold', 'plans.release', 'recovery.release',
        ];
        $adminPermissions = [
            ...$managerPermissions, 'products.manage', 'rates.make', 'rates.check',
            'staff.manage', 'simulator.use', 'audit.view', 'plans.close',
            'recovery.write-off', 'horizon.view',
        ];

        foreach ($adminPermissions as $permission) {
            Permission::findOrCreate($permission, 'staff');
        }

        Role::findOrCreate('manager', 'staff')->syncPermissions($managerPermissions);
        Role::findOrCreate('admin', 'staff')->syncPermissions($adminPermissions);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
