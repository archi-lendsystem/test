<?php

namespace Modules\BLTeleVerification\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Traits\ApiResponse;
use Modules\BLLeadCreation\Models\BLLeadCreation;
use Modules\BLTeleVerification\Models\BLTeleVerification;
use Modules\BLTeleVerification\Transformers\BLTeleVerificationResource;

class BLTeleVerificationController extends Controller
{
   use ApiResponse;
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lead_uuid'      => 'required|uuid|exists:b_l_lead_creations,uuid',
            'applicant_sno'  => 'required|integer',
            'applicant_type' => 'required|string|max:50',

           
            'q1_name_correct'     => 'sometimes|nullable|in:yes,no',
            'q1_correct_name'     => 'sometimes|nullable|string|max:255',
            'q2_mobile_verified'  => 'sometimes|nullable|in:yes,no',
            'q3_address_correct'  => 'sometimes|nullable|in:yes,no',
            'q3_updated_address'  => 'sometimes|nullable|string',

          
            'q4_self_employed'    => 'sometimes|nullable|in:yes,no',
            'q4_business_type'    => 'sometimes|nullable|string|max:100',
            'q5_business_vintage' => 'sometimes|nullable|in:yes,no',
            'q5_vintage_years'    => 'sometimes|nullable|numeric',
            'q6_fulltime_business' => 'sometimes|nullable|in:yes,no',

           
            'q7_salaried'         => 'sometimes|nullable|in:yes,no',
            'q8_employer_name'    => 'sometimes|nullable|string|max:255',
            'q9_employment_type'  => 'sometimes|nullable|string|max:50',
            'q10_total_experience' => 'sometimes|nullable|in:yes,no',
            'q11_current_tenure'  => 'sometimes|nullable|in:yes,no',
            'q12_salary_bank'     => 'sometimes|nullable|in:yes,no',

      
            'q13_income_threshold' => 'sometimes|nullable|in:yes,no',
            'q13_monthly_income'  => 'sometimes|nullable|numeric',
            'q14_income_banking'  => 'sometimes|nullable|in:yes,no',
            'q15_additional_income' => 'sometimes|nullable|in:yes,no',
            'q15_income_source'   => 'sometimes|nullable|string|max:255',
            'q15_income_amount'   => 'sometimes|nullable|numeric',

          
            'q16_married'         => 'sometimes|nullable|in:yes,no',
            'q17_spouse_income'   => 'sometimes|nullable|in:yes,no,na',
            'q17_spouse_income_nature' => 'sometimes|nullable|string|max:255',
            'q18_dependents'      => 'sometimes|nullable|in:yes,no',
            'q18_dependent_count' => 'sometimes|nullable|integer',
            'q19_expenses'        => 'sometimes|nullable|in:yes,no',
            'q19_expense_amount'  => 'sometimes|nullable|numeric',

         
            'q20_existing_loans'  => 'sometimes|nullable|in:yes,no',
            'q20_loan_details'    => 'sometimes|nullable|string|max:255',
            'q20_emi_amount'      => 'sometimes|nullable|numeric',
            'q21_loan_defaults'   => 'sometimes|nullable|in:yes,no',

            

            
            'q27_emi_aware'       => 'sometimes|nullable|in:yes,no',
            'q28_other_applications' => 'sometimes|nullable|in:yes,no',

          
            'q29_consent'         => 'sometimes|nullable|in:yes,no',
            'q30_information_true' => 'sometimes|nullable|in:yes,no',

          
            'q31_tvr_status'      => 'sometimes|nullable|string|max:50',
            'q32_risk_assessment' => 'sometimes|nullable|string|max:50',
            'q33_caller_remarks'  => 'sometimes|nullable|string',
            'q34_recommended_action' => 'sometimes|nullable|string|max:50',

           
            'tvr_flag'            => 'sometimes|nullable|in:pending,completed,rejected,on-hold',
            'tvr_status'          => 'sometimes|nullable|in:positive,negative,hold',
            'tvr_risk_assessment' => 'sometimes|nullable|in:low,medium,high',
            'tvr_completed_at'    => 'sometimes|nullable|date',
            'consent_given'       => 'sometimes|nullable|boolean',
            'consent_at'          => 'sometimes|nullable|date',
            'verified_by'         => 'sometimes|nullable|integer|exists:users,id',
            'verified_at'         => 'sometimes|nullable|date',
        ]);

        $lead = BLLeadCreation::where('uuid', $validated['lead_uuid'])->firstOrFail();

        $exists = BLTeleVerification::where('lead_uuid', $validated['lead_uuid'])
            ->where('applicant_sno', $validated['applicant_sno'])
            ->exists();

        if ($exists) {
            return response()->json([
                'success' => false,
                'message' => 'Tele verification already exists for this applicant.',
                'request_id' => app('request_id'),
                'timestamp'  => now()->toISOString(),
            ], 409);
        }

        $verification = BLTeleVerification::create(array_merge($validated, [
            'lead_id'    => $lead->lead_id,
            'tvr_flag'   => $validated['tvr_flag'] ?? 'pending',  
            'ip_address' => $request->ip(),
        ]));

        return response()->json([
            'success' => true,
            'message' => 'BL Tele Verification created successfully',
            'data'    => new BLTeleVerificationResource($verification),
            'request_id' => app('request_id'),
            'timestamp'  => now()->toISOString(),
        ], 201);
    }

    public function show(string $lead_uuid): JsonResponse
    {
        $verifications = BLTeleVerification::where('lead_uuid', $lead_uuid)
            ->with(['leadCreation', 'verifiedBy'])
            ->get();

        if ($verifications->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No tele verification found for this lead.',
                'request_id' => app('request_id'),
                'timestamp'  => now()->toISOString(),
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'BL Tele Verification retrieved successfully',
            'data'    => BLTeleVerificationResource::collection($verifications),
            'request_id' => app('request_id'),
            'timestamp'  => now()->toISOString(),
        ], 200);
    }

   
    public function showByApplicant(string $lead_uuid, int $applicant_sno): JsonResponse
    {
        $verification = BLTeleVerification::where('lead_uuid', $lead_uuid)
            ->where('applicant_sno', $applicant_sno)
            ->with(['leadCreation', 'verifiedBy'])
            ->first();

        if (!$verification) {
            return response()->json([
                'success' => false,
                'message' => 'No tele verification found for this applicant.',
                'request_id' => app('request_id'),
                 'timestamp'  => now()->toISOString(),
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'BL Tele Verification retrieved successfully',
            'data'    => new BLTeleVerificationResource($verification),
            'request_id' => app('request_id'),
           'timestamp'  => now()->toISOString(),
        ], 200);
    }
}
