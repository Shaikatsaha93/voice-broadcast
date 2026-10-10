<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $admin = Role::firstOrCreate(['name' => Role::SUPER_ADMIN], ['label' => 'Super Admin']);
        $manager = Role::firstOrCreate(['name' => Role::ADMIN], ['label' => 'Admin']);
        $user = Role::updateOrCreate(['name' => Role::USER], ['label' => 'User']);

        $all = ['users.manage', 'dids.manage', 'campaigns.approve', 'campaigns.view_all', 'reports.view_all', 'audit.view'];
        $own = ['campaigns.manage_own', 'audio.manage_own', 'reports.view_own'];

        foreach (array_merge($all, $own) as $p) {
            Permission::firstOrCreate(['name' => $p]);
        }

        $admin->permissions()->sync(Permission::pluck('id'));
        $manager->permissions()->sync(Permission::whereIn('name', array_merge(['users.manage', 'campaigns.view_all', 'reports.view_all', 'audit.view'], $own))->pluck('id'));
        $user->permissions()->sync(Permission::whereIn('name', $own)->pluck('id'));
    }
}
