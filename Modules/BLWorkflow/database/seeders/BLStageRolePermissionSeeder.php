<?php

namespace Modules\BLWorkflow\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\BLWorkFlow\Models\BLStageRolePermission;

class BLStageRolePermissionSeeder extends Seeder
{
      public function run(): void
    {
        $permissions = [
            'LEAD_CREATED' => [
                'Sales User' => [
                    'can_view' => true,
                    'can_edit' => true,
                    'can_assign' => true,
                    'can_change_status' => true,
                    'can_upload_document' => false,
                    'can_move_next' => true,
                    'can_move_previous' => false,
                ],
                'Admin' => [
                    'can_view' => true,
                    'can_edit' => true,
                    'can_assign' => true,
                    'can_change_status' => true,
                    'can_upload_document' => true,
                    'can_move_next' => true,
                    'can_move_previous' => true,
                ],
            ],

            'DOCUMENT_COLLECTION' => [
                'Sales User' => [
                    'can_view' => true,
                    'can_edit' => true,
                    'can_assign' => false,
                    'can_change_status' => true,
                    'can_upload_document' => true,
                    'can_move_next' => true,
                    'can_move_previous' => true,
                ],
                'Credit Checker' => [
                    'can_view' => true,
                    'can_edit' => false,
                    'can_assign' => false,
                    'can_change_status' => false,
                    'can_upload_document' => false,
                    'can_move_next' => false,
                    'can_move_previous' => false,
                ],
                'Admin' => [
                    'can_view' => true,
                    'can_edit' => true,
                    'can_assign' => true,
                    'can_change_status' => true,
                    'can_upload_document' => true,
                    'can_move_next' => true,
                    'can_move_previous' => true,
                ],
            ],

            'CREDIT_CHECK' => [
                'Sales User' => [
                    'can_view' => true,
                    'can_edit' => false,
                    'can_assign' => false,
                    'can_change_status' => false,
                    'can_upload_document' => false,
                    'can_move_next' => false,
                    'can_move_previous' => false,
                ],
                'Credit Checker' => [
                    'can_view' => true,
                    'can_edit' => true,
                    'can_assign' => true,
                    'can_change_status' => true,
                    'can_upload_document' => true,
                    'can_move_next' => true,
                    'can_move_previous' => true,
                ],
                'Admin' => [
                    'can_view' => true,
                    'can_edit' => true,
                    'can_assign' => true,
                    'can_change_status' => true,
                    'can_upload_document' => true,
                    'can_move_next' => true,
                    'can_move_previous' => true,
                ],
            ],

            'BANK_LOGIN' => [
                'Banking User' => [
                    'can_view' => true,
                    'can_edit' => true,
                    'can_assign' => true,
                    'can_change_status' => true,
                    'can_upload_document' => true,
                    'can_move_next' => true,
                    'can_move_previous' => false,
                ],
                'Admin' => [
                    'can_view' => true,
                    'can_edit' => true,
                    'can_assign' => true,
                    'can_change_status' => true,
                    'can_upload_document' => true,
                    'can_move_next' => true,
                    'can_move_previous' => true,
                ],
            ],

            'UNDERWRITING' => [
                'Underwriter' => [
                    'can_view' => true,
                    'can_edit' => true,
                    'can_assign' => true,
                    'can_change_status' => true,
                    'can_upload_document' => true,
                    'can_move_next' => true,
                    'can_move_previous' => false,
                ],
                'Admin' => [
                    'can_view' => true,
                    'can_edit' => true,
                    'can_assign' => true,
                    'can_change_status' => true,
                    'can_upload_document' => true,
                    'can_move_next' => true,
                    'can_move_previous' => true,
                ],
            ],

            'SANCTION' => [
                'Sanction Manager' => [
                    'can_view' => true,
                    'can_edit' => false,
                    'can_assign' => false,
                    'can_change_status' => true,
                    'can_upload_document' => true,
                    'can_move_next' => true,
                    'can_move_previous' => false,
                ],
                'Admin' => [
                    'can_view' => true,
                    'can_edit' => false,
                    'can_assign' => false,
                    'can_change_status' => true,
                    'can_upload_document' => true,
                    'can_move_next' => true,
                    'can_move_previous' => false,
                ],
            ],

            'DISBURSEMENT' => [
                'Operations User' => [
                    'can_view' => true,
                    'can_edit' => false,
                    'can_assign' => false,
                    'can_change_status' => true,
                    'can_upload_document' => true,
                    'can_move_next' => true,
                    'can_move_previous' => false,
                ],
                'Admin' => [
                    'can_view' => true,
                    'can_edit' => false,
                    'can_assign' => false,
                    'can_change_status' => true,
                    'can_upload_document' => true,
                    'can_move_next' => true,
                    'can_move_previous' => false,
                ],
            ],

            'CLOSED' => [
                'Admin' => [
                    'can_view' => true,
                    'can_edit' => false,
                    'can_assign' => false,
                    'can_change_status' => false,
                    'can_upload_document' => false,
                    'can_move_next' => false,
                    'can_move_previous' => false,
                ],
            ],
        ];

        foreach ($permissions as $stageCode => $roles) {
            $stage = DB::table('b_l_workflow_stages')
                ->where('stage_code', $stageCode)
                ->first();

            if (!$stage) {
                continue;
            }

            foreach ($roles as $roleName => $permission) {
                DB::table('b_l_stage_role_permissions')->updateOrInsert(
                    [
                        'stage_id' => $stage->id,
                        'role_name' => $roleName,
                    ],
                    [
                        'stage_id' => $stage->id,
                        'role_name' => $roleName,

                        'can_view' => $permission['can_view'],
                        'can_edit' => $permission['can_edit'],
                        'can_assign' => $permission['can_assign'],
                        'can_change_status' => $permission['can_change_status'],
                        'can_upload_document' => $permission['can_upload_document'],
                        'can_move_next' => $permission['can_move_next'],
                        'can_move_previous' => $permission['can_move_previous'],

                        'updated_at' => now(),
                        'created_at' => DB::raw('COALESCE(created_at, NOW())'),
                    ]
                );
            }
        }
    }
}
