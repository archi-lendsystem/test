<?php

namespace Modules\Customers\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\UserManagement\Models\Permission;
use Modules\UserManagement\Models\User;

class CustomersPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permission = Permission::where('slug', 'customers')->first();

        if (! $permission) {
            $this->command?->warn(
                "Permission 'customers' not found. Run `php artisan permission:sync` first."
            );

            return;
        }

        $existingSlug = 'bl-lead-creation';

        $users = User::whereHas('permissions', fn ($q) => $q->where('slug', $existingSlug))->get();

        foreach ($users as $user) {
            $user->permissions()->syncWithoutDetaching([$permission->id]);

            $this->command?->info(
                "Permission 'customers' granted to {$user->user_email}."
            );
        }
    }
}
