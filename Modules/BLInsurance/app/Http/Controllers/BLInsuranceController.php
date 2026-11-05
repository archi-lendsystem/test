<?php

namespace Modules\BLInsurance\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\BLInsurance\Transformers\BLInsuranceCollection;
use Modules\BLInsurance\Transformers\BLInsuranceResource;
use Modules\BLInsurance\Models\BLInsurance;
use Modules\BLApplicantDetails\Models\BLApplicantDetails;
use Modules\BLSaction\Models\BLSaction;


class BLInsuranceController extends Controller
{
    use ApiResponse;

  
    private function fetchLeadData(string $leadUuid): array|JsonResponse
    {
        
        $applicantDetail = BLApplicantDetails::where('lead_uuid', $leadUuid)
            ->where('applicant_sno', '0')
            ->first();

        if (!$applicantDetail) {
            return $this->error('Applicant detail not found for this lead.', null, 404);
        }

        if (empty($applicantDetail->age)) {
            return $this->error('Age not found in applicant details. Please update applicant details first.', null, 422);
        }

        $saction = BLSaction::where('lead_uuid', $leadUuid)->latest()->first();

        if (!$saction) {
            return $this->error('Sanction not found for this lead. Please create sanction first.', null, 404);
        }

       if (empty($saction->sanctioned_amount) || empty($saction->tenure_months)) {
    return $this->error('Sanctioned amount or tenure missing in sanction record.', null, 422);
    }

        return [
            'age'         => (int)   $applicantDetail->age,
            'loan_amount' => (float) $saction->sanctioned_amount,
            'tenure'      => (int)   $saction->tenure_months,
        ];
    }

   
    public function index(Request $request): JsonResponse
    {
        $records = BLInsurance::latest()->paginate($request->get('per_page', 15));

        return (new BLInsuranceCollection($records))
            ->response()
            ->setStatusCode(200);
    }


    public function calculate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lead_uuid' => 'required|uuid',
            
        ]);

        $leadData = $this->fetchLeadData($validated['lead_uuid']);

        if ($leadData instanceof JsonResponse) {
            return $leadData;
        }

        $result = BLInsurance::calculatePremium(
            $leadData['loan_amount'],
            $leadData['tenure'],
            $leadData['age']
        );

        if (isset($result['error'])) {
            return $this->error($result['error'], null, 422);
        }

        return $this->success(
            array_merge($result, [
                'fetched_from' => [
                    'age'         => $leadData['age'],
                    'loan_amount' => $leadData['loan_amount'],
                    'tenure'      => $leadData['tenure'],
                ],
            ]),
            'Premium calculated successfully.'
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lead_uuid'      => 'required|uuid|exists:b_l_lead_creations,uuid',
            'applicant_name' => 'nullable|string|max:255',
            'loan_reference' => 'nullable|string|max:255',
        ]);

        $leadData = $this->fetchLeadData($validated['lead_uuid']);

        if ($leadData instanceof JsonResponse) {
            return $leadData;
        }

        $calculation = BLInsurance::calculatePremium(
            $leadData['loan_amount'],
            $leadData['tenure'],
            $leadData['age']
        );

        if (isset($calculation['error'])) {
            return $this->error($calculation['error'], null, 422);
        }

        $record = BLInsurance::create(array_merge($calculation, [
            'lead_uuid'      => $validated['lead_uuid'],
            'applicant_name' => $validated['applicant_name'] ?? null,
            'loan_reference' => $validated['loan_reference'] ?? null,
    

        ]));

        return $this->success(
            (new BLInsuranceResource($record))->toArray($request),
            'BL Insurance record created successfully.',
            201
        );
    }

    public function show(int $id ): JsonResponse
    {
        $record = BLInsurance::find($id);

        if (!$record) {
            return $this->error('Record not found.', null, 404);
        }

        return $this->success(
            (new BLInsuranceResource($record))->toArray(request()),
            'BL Insurance record retrieved successfully.'
        );
    }


    public function rateTable(): JsonResponse
    {
        return $this->success([
            'tenures'    => [36, 48, 60, 72, 84, 96, 108, 120],
            'rate_table' => BLInsurance::$rateTable,
        ], 'Rate table retrieved successfully.');
    }

    
}
