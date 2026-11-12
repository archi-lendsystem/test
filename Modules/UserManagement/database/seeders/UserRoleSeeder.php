<?php

namespace Modules\UserManagement\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\UserManagement\Models\Role;
use Modules\UserManagement\Models\User;
use Modules\UserManagement\Models\UserRole;

class UserRoleSeeder extends Seeder
{
    // public function run(): void
    // {
    //     $admin = User::where('user_email', 'shubham@thelendsystem.com')->first();
    //     $manager = User::where('user_email', 'manager@example.com')->first();
    //     $employee = User::where('user_email', 'employee@example.com')->first();

    //     $adminRole = Role::where('name', 'Admin')->first();
    //     $managerRole = Role::where('name', 'Manager')->first();
    //     $employeeRole = Role::where('name', 'Employee')->first();

    //     $this->assignRole($admin, $adminRole);
    //     $this->assignRole($manager, $managerRole);
    //     $this->assignRole($employee, $employeeRole);
    // }

    // private function assignRole($user, $role)
    // {
    //     if (! $user || ! $role) {
    //         return;
    //     }

    //     UserRole::withTrashed()->updateOrCreate(
    //         [
    //             'user_id' => $user->id,
    //             'role_id' => $role->id,
    //         ],
    //         [
    //             'deleted_at' => null,
    //         ]
    //     );
    // }


    public function run(): void
    {
      $assignments = [
    'shubham@thelendsystem.com'          => 'SUPER_ADMINISTRATIVE',
    'sourav.manral@thelendsystem.com'    => 'SUPER_ADMINISTRATIVE',
    'pushpendra.ahirwar@thelendsystem.com' => 'SUPER_ADMINISTRATIVE',
    'tarun.gupta@thelendsystem.com'      => 'SUPER_ADMINISTRATIVE',
    'manager@example.com'                => 'SALES_MANAGER',
    'employee@example.com'               => 'SALES_PERSON',
];

        foreach ($assignments as $email => $roleCode) {
            $user = User::where('user_email', $email)->first();

            $role = Role::withTrashed()
                ->where('code', $roleCode)
                ->first();

            if (! $role) {
                throw new RuntimeException(
                    "Role with code {$roleCode} was not found."
                );
            }

            if (! $user) {
                $this->command?->warn(
                    "User with email {$email} was not found. Role assignment skipped."
                );

                continue;
            }

            if ($role->trashed()) {
                $role->restore();
            }

            $this->assignRole($user, $role);
        }
    }

    private function assignRole(User $user, Role $role): void
    {
        UserRole::withTrashed()->updateOrCreate(
            [
                'user_id' => $user->id,
                'role_id' => $role->id,
            ],
            [
                'deleted_at' => null,
            ]
        );

        $this->command?->info(
            "Role {$role->display_name} assigned to {$user->user_email}."
        );
    }
}

