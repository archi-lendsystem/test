<?php

namespace Modules\BLIMDDetailsVerification\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Modules\BLLeadCreation\Models\BLLeadCreation;
use Modules\BLIMDDetailsVerification\Models\BLIMDDetailsVerification;

class BLIMDDetailsVerificationController extends Controller
{
   use ApiResponse;

  
    public function store(Request $request, string $lead_uuid, int $applicant_id): JsonResponse
    {
        $validated = $request->validate([
         
            'status'        => ['nullable', 'in:APPROVED,REJECTED,PENDING'],
            'remarks'       => ['nullable', 'string'],
            'consent_given' => ['required', 'boolean'],
            'consent_at'    => ['nullable', 'date'],
            'verified_by'   => ['nullable', 'exists:users,id'],
            'verified_at'   => ['nullable', 'date'],
        ]);

       
        if (!$validated['consent_given']) {
            return response()->json([
                'success' => false,
                'message' => 'Consent is required for IMD verification.',
                'request_id' => app('request_id'),
                'timestamp'  => now()->toISOString(),
            ], 422);
        }

        $lead = BLLeadCreation::where('uuid', $lead_uuid)->first();
        if (!$lead) {
            return response()->json([
                'success' => false,
                'message' => 'Lead not found.',
                'request_id' => app('request_id'),
                'timestamp'  => now()->toISOString(),
            ], 404);
        }

     
        $alreadyExists = BLIMDDetailsVerification::where('lead_uuid', $lead_uuid)
            ->where('applicant_id', $applicant_id)
            ->exists();

        if ($alreadyExists) {
            return response()->json([
                'success' => false,
                'message' => 'IMD Verification already exists for this applicant.',
                'request_id' => app('request_id'),
                'timestamp'  => now()->toISOString(),
            ], 409);
        }

        $validated['lead_uuid']    = $lead_uuid;
        $validated['lead_id']      = $lead->lead_id;   
        $validated['applicant_id'] = $applicant_id;

        $verification = BLIMDDetailsVerification::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'IMD Verification created successfully.',
            'request_id' => app('request_id'),
            'timestamp'  => now()->toISOString(),
            'data'    => $verification,
        ], 201);
    }

  
    public function show(string $lead_uuid): JsonResponse
    {
        $verifications = BLIMDDetailsVerification::where('lead_uuid', $lead_uuid)->get();

        if ($verifications->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No IMD verification found for this lead.',
                'request_id' => app('request_id'),
                'timestamp'  => now()->toISOString(),
            ], 404);
        }

        return response()->json([
            'success' => true,
              'request_id' => app('request_id'),
              
              'timestamp'  => now()->toISOString(),
            'message' => 'IMD Verification retrieved successfully.',
            'data'    => $verifications,
        ], 200);
    }

  
    public function update(Request $request, string $lead_uuid, int $applicant_id): JsonResponse
    {
        $validated = $request->validate([
            'status'        => ['nullable', 'in:APPROVED,REJECTED,PENDING'],
            'remarks'       => ['nullable', 'string'],
            'consent_given' => ['nullable', 'boolean'],
            'consent_at'    => ['nullable', 'date'],
            'verified_by'   => ['nullable', 'exists:users,id'],
            'verified_at'   => ['nullable', 'date'],
        ]);

   
        $lead = BLLeadCreation::where('uuid', $lead_uuid)->first();
        if (!$lead) {
            return response()->json([
                'success' => false,
                'message' => 'Lead not found.',
                'request_id' => app('request_id'),
                'timestamp'  => now()->toISOString(),
            ], 404);
        }

       
        $verification = BLIMDDetailsVerification::updateOrCreate(
            [
                'lead_uuid'    => $lead_uuid,
                'applicant_id' => $applicant_id,
            ],
            array_merge($validated, [
                'lead_id' => $lead->lead_id,
            ])
        );

        return response()->json([
            'success' => true,
            'message' => $verification->wasRecentlyCreated
                ? 'IMD Verification created successfully.'
                : 'IMD Verification updated successfully.',
            'data'    => $verification->fresh(),
            'request_id' => app('request_id'),
            'timestamp'  => now()->toISOString(),
        ], 200);
    }
}
