<?php

namespace Modules\BLSanctionCondition\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Modules\BLSanctionCondition\Models\BLSanctionCondition;
use Modules\BLSanctionCondition\Models\BLSanctionConditionMaster;
use Modules\BLCam\Models\BLCam;
use Modules\BLApplicantDetails\Models\BLApplicantDetails;
use Illuminate\Support\Facades\DB;

class BLSanctionConditionController extends Controller
{
    public function getPageData(string $leadUuid): JsonResponse
    {
        $summary = $this->getCustomerLoanSummary($leadUuid);

        $allConditions = BLSanctionConditionMaster::where('is_active', true)
            ->orderBy('id')
            ->get();

        $selectedMap = BLSanctionCondition::where('lead_uuid', $leadUuid)
            ->pluck('is_selected', 'master_condition_id');

        $conditions = $allConditions->map(function ($condition) use ($selectedMap) {
            return [
                'id'           => $condition->id,
                'condition_id' => $condition->condition_id,
                'title'        => $condition->title,
                'is_selected'  => $selectedMap[$condition->id] ?? false,
                'is_custom'    => $condition->is_custom,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => [
                'summary'    => $summary,
                'conditions' => $conditions,
            ]
        ]);
    }

    private function getCustomerLoanSummary(string $leadUuid): array
    {
        $applicants = BLApplicantDetails::where('lead_uuid', $leadUuid)->get();

        $mainApplicant = $applicants->firstWhere('is_co_applicant', false)
                      ?? $applicants->firstWhere('applicant_type', 'main')
                      ?? $applicants->first();

        $coApplicant = $applicants->firstWhere('is_co_applicant', true)
                    ?? $applicants->firstWhere('applicant_type', 'co');

        $cam = BLCam::where('lead_uuid', $leadUuid)->first();
        // $selectedLender = BLSelectLender::with('lender')
        //     ->where('lead_uuid', $leadUuid)
        //     ->first();

        return [
            'lead_uuid'         => $leadUuid,
            'applicant_name'    => $mainApplicant?->full_name ?? 'N/A',
            'co_applicant_name' => $coApplicant?->full_name ?? 'N/A',
            // 'selected_lender' => $selectedLender?->lender?->lender_name ?? 'N/A',
            'approved_amount'   => $cam?->loan_amount ?? 0,
            'tenure'            => $cam?->loan_tenure ?? 0,
            'roi'               => $cam?->roi ?? 0,
            'processing_fee'    => $cam?->pf ?? 0,
            'gst'               => $cam?->gst ?? 0,
        ];
    }

    public function addCustomCondition(Request $request, string $leadUuid): JsonResponse
    {
        $request->validate([
            'condition_title' => 'required|string|max:500',
        ]);

   
        $condition = BLSanctionConditionMaster::create([
            'condition_id' => null,
            'title'        => $request->condition_title,
            'is_custom'    => true,
            'is_active'    => true,
            'created_by'   => auth()->id(),
        ]);

 
        BLSanctionCondition::create([
            'lead_uuid'           => $leadUuid,
            'master_condition_id' => $condition->id,
            'is_custom'           => true,
            'condition_title'     => $request->condition_title,
            'is_selected'         => true,
            'created_by'          => auth()->id(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Custom condition added successfully',
            'data'    => $condition
        ], 201);
    }

    public function saveSelectedConditions(Request $request, string $leadUuid): JsonResponse
    {
        $request->validate([
            'selected_conditions' => 'required|array',
        ]);

        DB::transaction(function () use ($request, $leadUuid) {
            foreach ($request->selected_conditions as $cond) {
                BLSanctionCondition::updateOrCreate(
                    [
                        'lead_uuid'           => $leadUuid,
                        'master_condition_id' => $cond['id'],
                    ],
                    [
                        'condition_title' => $cond['title'] ?? '',
                        'is_selected'     => $cond['is_selected'],
                        'is_custom'       => $cond['is_custom'] ?? false,
                        'created_by'      => auth()->id(),
                    ]
                );
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Conditions saved successfully'
        ]);
    }
}
