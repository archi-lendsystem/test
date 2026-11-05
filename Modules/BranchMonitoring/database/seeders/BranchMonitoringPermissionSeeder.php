<?php

namespace Modules\BranchMonitoring\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\UserManagement\Models\Permission;
use Modules\UserManagement\Models\User;

class BranchMonitoringPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permission = Permission::where('slug', 'branch-monitoring')->first();

        if (! $permission) {
            $this->command?->warn(
                "Permission 'branch-monitoring' not found. Run `php artisan permission:sync` first."
            );

            return;
        }

        $existingSlug = 'bl-lead-creation';

        $users = User::whereHas('permissions', fn ($q) => $q->where('slug', $existingSlug))->get();

        foreach ($users as $user) {
            $user->permissions()->syncWithoutDetaching([$permission->id]);

            $this->command?->info(
                "Permission 'branch-monitoring' granted to {$user->user_email}."
            );
        }
    }
}
