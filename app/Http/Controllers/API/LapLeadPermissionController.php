<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\LapLeadPermissionService;
use Modules\LapLeadCreation\Models\LapLeadCreation;
use Illuminate\Http\Request;

class LapLeadPermissionController extends Controller
{
    public function getPermissions($leadId, Request $request)
    {
        $user = $request->user();
        $permissions = LapLeadPermissionService::permissions($leadId, $user);
        return response()->json($permissions);
    }

    // Optional: global permissions (without leadId)
    public function globalPermissions(Request $request)
    {
        $user = $request->user();
        return response()->json([
            'can_create_lead' => LapLeadPermissionService::canCreate($user),
            'can_assign' => LapLeadPermissionService::canAssign($user), // without leadId for global check
            'roles' => $user->user_role ?? 'normal_user',
        ]);
    }
}