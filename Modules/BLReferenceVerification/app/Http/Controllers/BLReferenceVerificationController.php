<?php

namespace Modules\BLReferenceVerification\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\BLReferenceDetails\Models\BLReferenceDetails;
use Modules\BLReferenceVerification\Models\BLReferenceVerification;
use Modules\BLReferenceVerification\Transformers\BLReferenceVerificationCollection;
use Modules\BLReferenceVerification\Transformers\BLReferenceVerificationResource;
use Modules\BLLeadCreation\Models\BLLeadCreation;

class BLReferenceVerificationController extends Controller
{
    public function show(Request $request, string $lead_uuid): JsonResponse
    {
        $lead = BLLeadCreation::where('uuid', $lead_uuid)->first();
        if (!$lead) {
            return response()->json([
                'success' => false,
                'message' => 'Lead not found.',
            ], 404);
        }

        $referenceDetails = BLReferenceDetails::where('lead_uuid', $lead_uuid)
            ->with('verification')
            ->latest()
            ->paginate($request->get('per_page', 10));

        if ($referenceDetails->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No reference details found for this lead.',
            ], 404);
        }

        return (new BLReferenceVerificationCollection($referenceDetails))
            ->response()
            ->setStatusCode(200);
    }

   
    public function update(Request $request, string $lead_uuid, int $reference_detail_id): JsonResponse
    {
        $lead = BLLeadCreation::where('uuid', $lead_uuid)->first();
        if (!$lead) {
            return response()->json([
                'success' => false,
                'message' => 'Lead not found.',
            ], 404);
        }

        $validated = $request->validate([
            'status'  => 'required|string|max:255',
            'remarks' => 'required|string',
            'Known_since'=> 'required|integer'
        ]);

        $referenceDetail = BLReferenceDetails::where('id', $reference_detail_id)
            ->where('lead_uuid', $lead_uuid)
            ->first();

        if (!$referenceDetail) {
            return response()->json([
                'success' => false,
                'message' => 'Reference detail not found for this lead.',
            ], 404);
        }

        BLReferenceVerification::updateOrCreate(
            [
                'lead_uuid'           => $lead_uuid,
                'reference_detail_id' => $referenceDetail->id,
            ],
            [
                'reference_id' => $referenceDetail->reference_id,
                'status'       => $validated['status'] ?? null,
                'remarks'      => $validated['remarks'] ?? null,
                 'Known_since'      => $validated['Known_since'] ?? null,
            ]
        );

        $referenceDetail->load('verification');

        return response()->json([
            'success' => true,
            'message' => 'BL Reference Verification updated successfully.',
            'data'    => new BLReferenceVerificationResource($referenceDetail),
        ], 200);
    }
}
