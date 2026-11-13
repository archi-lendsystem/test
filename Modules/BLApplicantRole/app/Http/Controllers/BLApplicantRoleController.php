<?php

namespace Modules\BLApplicantRole\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Traits\ApiResponse;
use Modules\BLApplicantRole\Models\BLApplicantRole;
use Modules\BLApplicantRole\Transformers\BLApplicantRoleCollection;
use Modules\BLApplicantRole\Transformers\BLApplicantRoleResource;


class BLApplicantRoleController extends Controller
{
    use ApiResponse;

    
    public function store(Request $request, $lead_uuid, $applicant_id): JsonResponse
    {
        $validated = $request->validate([
            'created_by' => 'nullable|exists:users,id',
            'lead_id' => 'required|string|max:255',
            'applicant_sno' => 'nullable|string|max:255',
            'applicant_type' => 'nullable|string|max:255',
            'type' => 'required|in:Sole Proprietor,Partnership,Private Limited,Public Limited,Limited Liability Partnership,One Person Company',
            'role' => 'nullable|string|max:255',
        ]);

        $lead = \Modules\BLLeadCreation\Models\BLLeadCreation::where('uuid', $lead_uuid)->first();

        if (! $lead) {
            return response()->json([
                'success' => false,
                'message' => 'Lead not found.',
                'data' => null,
            ], 404);
        }

        $validated['lead_uuid'] = $lead_uuid;
        $validated['applicant_id'] = $applicant_id;

        $validated['sno'] = BLApplicantRole::where('lead_uuid', $lead_uuid)
            ->where('applicant_id', $applicant_id)
            ->count();

        $role = BLApplicantRole::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'BL Applicant Role created successfully',
            'request' => [
                'lead_uuid' => $lead_uuid,
                'applicant_id' => $applicant_id,
                'applicant_role_id' => $role->id,
            ],
            'request_id' => app('request_id'),
            'data' => new BLApplicantRoleResource($role),
        ], 201);
    }

   
    public function show($lead_uuid, $applicant_id): JsonResponse
    {
        $blApplicantRoles = BLApplicantRole::with(['leadCreation', 'applicant'])
            ->where('lead_uuid', $lead_uuid)
            ->where('applicant_id', $applicant_id)
            ->orderBy('sno')
            ->get();

        if ($blApplicantRoles->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No BL Applicant Roles found.',
                'data' => [],
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'BL Applicant Roles retrieved successfully',
            'request' => [
                'lead_uuid' => $lead_uuid,
                'applicant_id' => $applicant_id,
            ],
            
            'data' => BLApplicantRoleResource::collection($blApplicantRoles),
        ], 200);
    }

    
    public function update(Request $request, $lead_uuid, $applicant_id): JsonResponse
    {
        $validated = $request->validate([
            'role' => 'sometimes|nullable|string|max:255',
            'type' => 'sometimes|required|in:Sole Proprietor,Partnership,Private Limited,Public Limited,Limited Liability Partnership,One Person Company',
            'applicant_sno' => 'sometimes|nullable|string|max:255',
            'applicant_type' => 'sometimes|nullable|string|max:255',
        ]);

        $blApplicantRole = BLApplicantRole::where('lead_uuid', $lead_uuid)
            ->where('applicant_id', $applicant_id)
            ->first();

        if (! $blApplicantRole) {
            return response()->json([
                'success' => false,
                'message' => 'BL Applicant Role not found.',
                'data' => null,
            ], 404);
        }

        $blApplicantRole->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'BL Applicant Role updated successfully',
            'request' => [
                'lead_uuid' => $lead_uuid,
                'applicant_id' => $applicant_id,
            ],
            'request_id' => app('request_id'),
            'data' => new BLApplicantRoleResource($blApplicantRole->refresh()),
        ], 200);
    }

    
    public function destroy($lead_uuid, $applicant_id): JsonResponse
    {
        $blApplicantRole = BLApplicantRole::where('lead_uuid', $lead_uuid)
            ->where('applicant_id', $applicant_id)
            ->first();

        if (! $blApplicantRole) {
            return response()->json([
                'success' => false,
                'message' => 'BL Applicant Role not found.',
                'data' => null,
            ], 404);
        }

        $blApplicantRole->delete();

        $remainingRoles = BLApplicantRole::where('lead_uuid', $lead_uuid)
            ->where('applicant_id', $applicant_id)
            ->orderBy('created_at')
            ->get();

        foreach ($remainingRoles as $index => $role) {
            $role->update([
                'sno' => $index,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'BL Applicant Role deleted successfully',
            'request' => [
                'lead_uuid' => $lead_uuid,
                'applicant_id' => $applicant_id,
            ],
            'request_id' => app('request_id'),
            'data' => null,
        ], 200);
    }
}
