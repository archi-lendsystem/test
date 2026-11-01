<?php

namespace Modules\BLBusinessDetails\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Traits\ApiResponse;
use Modules\BLBusinessDetails\Models\BLBusinessDetails;
use Modules\BLBusinessDetails\Transformers\BLBusinessDetailsResource;

class BLBusinessDetailsController extends Controller
{
    use ApiResponse;

 
    public function store(Request $request, $lead_uuid, $applicant_id): JsonResponse
    {
        // Validation rules
        $validated = $request->validate([
            'created_by' => ['required', 'exists:users,id'],
            'lead_id' => ['required', 'string', 'max:255'],
            'applicant_sno' => ['required', 'integer'],
            'applicant_type' => ['required', 'string', 'max:255'],
            'business_name' => ['required', 'string', 'max:255'],
            'nature_of_business' => ['required', 'string', 'max:255'],
            'incorporation_date' => ['required', 'date'],
            'pan_number' => ['required', 'string', 'max:20'],
            'monthly_sales' => ['required', 'integer'],
            'office_address' => ['required', 'string'],
            'pincode' => ['required', 'string'],
            'district' => ['required', 'string'],
            'city' => ['required', 'string'],
            'state' => ['required', 'string'],
            'business_premise_ownership' => ['required', 'in:Yes,No'],
        ]);


        $business = BLBusinessDetails::updateOrCreate(
            [
                'lead_uuid' => $lead_uuid,
                'applicant_id' => $applicant_id
            ],
            $validated
        );


        $message = $business->wasRecentlyCreated 
            ? 'BL Business Detail created successfully' 
            : 'BL Business Detail updated successfully';

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => new BLBusinessDetailsResource($business),
        ], $business->wasRecentlyCreated ? 201 : 200);
    }

 
    public function show($lead_uuid, $applicant_id): JsonResponse
    {
        $businessDetail = BLBusinessDetails::where('lead_uuid', $lead_uuid)
            ->where('applicant_id', $applicant_id)
            ->first();

        if (!$businessDetail) {
            return response()->json([
                'success' => false,
                'message' => "Record not found for Applicant ID: $applicant_id",
            ], 404);
        }

        return response()->json([
            'success'     => true,
            'status_code' => 200,
            'request_id'  => app('request_id') ?? null,
            'timestamp'   => now()->toISOString(),
            'message'     => 'BL Business Detail retrieved successfully',
            'data' => new BLBusinessDetailsResource($businessDetail),
        ]);
    }
}
