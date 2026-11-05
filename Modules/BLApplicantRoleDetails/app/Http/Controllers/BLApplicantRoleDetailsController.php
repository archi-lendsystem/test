<?php

namespace Modules\BLApplicantRoleDetails\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\BLApplicantRole\Models\BLApplicantRole;
use Modules\BLApplicantRoleDetails\Models\BLApplicantRoleDetails;
use Modules\BLApplicantRoleDetails\Transformers\BLApplicantRoleDetailsResource;

class BLApplicantRoleDetailsController extends Controller
{
    use ApiResponse;

  
    public function show($lead_uuid, $applicant_id): JsonResponse
    {
     
        $roles = BLApplicantRole::where('lead_uuid', $lead_uuid)
            ->where('applicant_id', $applicant_id)
            ->orderBy('sno')
            ->get();

        if ($roles->isEmpty()) {
            return response()->json([
                'status' => false,
                'code' => 404,
                'request_id' => app('request_id'),
                'data' => [],
            ], 404);
        }


        $existingDetails = BLApplicantRoleDetails::where('lead_uuid', $lead_uuid)
            ->where('applicant_id', $applicant_id)
            ->get()
            ->keyBy('applicant_role_id');

        $result = [];

        foreach ($roles as $role) {
            if ($existingDetails->has($role->id)) {
          
                $result[] = $existingDetails[$role->id];
            } else {
       
                $result[] = [
                    'applicant_role_id' => $role->id,
                    'role' => $role->role,
                    'type' => $role->type,
                    'full_name' => null,
                    'mother_name' => null,
                    'date_of_birth' => null,
                    'mobile_number' => null,
                    'gender' => null,
                    'pan_number' => null,
                    'residence_address' => null,
                    'pincode' => null,
                    'district' => null,
                    'city' => null,
                    'state' => null,
                    'ownership_status' => null,
                ];
            }
        }

        return response()->json([
            'status' => true,
            'code' => 200,
            'request_id' => app('request_id'),
            'data' => $result,
        ], 200);
    }

   
    public function update(Request $request, $lead_uuid, $applicant_id): JsonResponse
    {
        $validated = $request->validate([
            'applicant_role_id' => 'required|integer|exists:b_l_applicant_roles,id',

            'full_name' => 'sometimes|nullable|string|max:255',
            'mother_name' => 'sometimes|nullable|string|max:255',
            'date_of_birth' => 'sometimes|nullable|date',
            'mobile_number' => 'sometimes|nullable|string|max:20',
            'gender' => 'sometimes|nullable|string|max:50',
            'pan_number' => 'sometimes|nullable|string|max:20',
            'residence_address' => 'sometimes|nullable|string|max:500',
            'pincode' => 'sometimes|nullable|string|max:10',
            'district' => 'sometimes|nullable|string|max:255',
            'city' => 'sometimes|nullable|string|max:255',
            'state' => 'sometimes|nullable|string|max:255',
            'ownership_status' => 'sometimes|nullable|string|max:255',
        ]);

    
        $role = BLApplicantRole::where('id', $validated['applicant_role_id'])
            ->where('lead_uuid', $lead_uuid)
            ->where('applicant_id', $applicant_id)
            ->first();

        if (! $role) {
            return response()->json([
                'status' => false,
                'code' => 404,
                'request_id' => app('request_id'),
                'data' => null,
            ], 404);
        }

        $data = array_merge($validated, [
            'lead_uuid' => $lead_uuid,
            'lead_id' => $role->lead_id,
            'applicant_id' => $applicant_id,
            'applicant_role_id' => $role->id,
        ]);

        $detail = BLApplicantRoleDetails::updateOrCreate(
            ['applicant_role_id' => $role->id],
            $data
        );

        return response()->json([
            'status' => true,
            'code' => 200,
            'request_id' => app('request_id'),
            'data' => new BLApplicantRoleDetailsResource($detail->refresh()),
        ], 200);
    }
}
