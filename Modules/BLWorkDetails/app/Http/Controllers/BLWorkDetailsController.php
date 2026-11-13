<?php

namespace Modules\BLWorkDetails\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\BLWorkDetails\Models\BLWorkDetails;
use Modules\BLLeadCreation\Models\BLLeadCreation;
use Modules\BLWorkDetails\Transformers\BLWorkDetailsResource;

class BLWorkDetailsController extends Controller
{
    use ApiResponse;

 
    public function store(Request $request, $lead_uuid, $applicant_id): JsonResponse
    {
        $validated = $request->validate([
            'applicant_sno'          => ['required'],
            'applicant_type'         => ['required'],
            'company_name'           => ['required', 'string', 'max:255'],
            'designation'            => ['required', 'string', 'max:255'],
            'salary_credit_method'   => ['required', 'string', 'max:255'],
            'monthly_salary'         => ['required', 'numeric'],
            'official_email'       => ['required', 'email', 'max:255'],
            'total_work_company'     => ['required', 'integer'],
            'epf_deducted' => ['required', 'in:Yes,No'],
        ]);

        $userId = Auth::id();

        $lead = BLLeadCreation::query()
            ->where('uuid', $lead_uuid)
            ->first();

        if (! $lead) {
            return response()->json([
                'success' => false,
                'message' => 'Lead not found.',
                'data' => null,
            ], 404);
        }

      
        $workDetail = BLWorkDetails::updateOrCreate(
            [
                'lead_uuid'    => $lead_uuid,
                'applicant_id' => $applicant_id,
                'created_by'   => $userId,
            ],
            array_merge($validated, [
                'lead_uuid'    => $lead_uuid,
                'lead_id'      => $lead->lead_id,
                'applicant_id' => $applicant_id,
                'created_by'   => $userId,
            ])
        );

        $message = $workDetail->wasRecentlyCreated
            ? 'BL Work Detail created successfully'
            : 'BL Work Detail updated successfully';

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => new BLWorkDetailsResource($workDetail->load('creator')),
        ], $workDetail->wasRecentlyCreated ? 201 : 200);
    }

   
    public function show($lead_uuid, $applicant_id): JsonResponse
    {
        $userId = Auth::id();

        $lead = BLLeadCreation::query()
            ->where('uuid', $lead_uuid)
            ->first();

        if (! $lead) {
            return response()->json([
                'success' => false,
                'message' => 'Lead not found.',
                'data' => null,
            ], 404);
        }

        

        $workDetail = BLWorkDetails::with('creator')
            ->where('lead_uuid', $lead_uuid)
            ->where('applicant_id', $applicant_id)
            ->where('created_by', $userId)
            ->first();

        if (! $workDetail) {
            return response()->json([
                'success' => false,
                'message' => "Record not found for Applicant ID: $applicant_id",
                'data' => null,
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'BL Work Detail retrieved successfully',
            'data' => new BLWorkDetailsResource($workDetail),
        ], 200);
    }
}
