<?php

namespace Modules\BLLeads\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\BLLeadPermissionService;
use Illuminate\Http\JsonResponse;
use Modules\BLLeadCreation\Models\BLLeadCreation;

class BLLeadAccessController extends Controller

{
    public function check(string $leadUuid): JsonResponse
    {
        $user = auth()->user();

        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 401);
        }

       
        $user->loadMissing('roles');

        $lead = BLLeadCreation::where('uuid', $leadUuid)
            ->select('id', 'uuid', 'lead_id', 'created_by')
            ->first();

        if (!$lead) {
            return response()->json(['success' => false, 'message' => 'Lead not found.'], 404);
        }

        $canEdit = BLLeadPermissionService::canEdit($lead->lead_id, $user);

        return response()->json([
            'success'  => true,
            'can_edit' => $canEdit,
        ]);
    }
}