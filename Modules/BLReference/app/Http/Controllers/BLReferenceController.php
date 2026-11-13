<?php

namespace Modules\BLReference\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\BLLeadCreation\Models\BLLeadCreation;
use Modules\BLReference\Models\BLReference;
use Modules\BLReference\Transformers\BLReferenceResource;

class BLReferenceController extends Controller
{
    use ApiResponse;
  
public function index($lead_uuid): JsonResponse
{
    $lead = BLLeadCreation::where('uuid', $lead_uuid)->first();

    if (! $lead) {
        return response()->json([
            'success' => false,
            'message' => 'Lead not found.',
            'data' => null,
        ], 404);
    }

    $references = BLReference::where('lead_uuid', $lead_uuid)
        ->select('id', 'lead_uuid', 'lead_id', 'reference_types', 'created_by', 'reference_sno')
        ->get();

    return response()->json([
        'success' => true,
        'message' => 'BL References retrieved successfully',
        'request' => [
            'lead_uuid' => $lead_uuid,
        ],
        'data' => $references,
    ], 200);
}

   
    public function store(Request $request, $lead_uuid): JsonResponse
    {
        $validated = $request->validate([
            'created_by' => ['nullable', 'exists:users,id'],
            'reference_types' => ['required', 'string', 'max:255'],
        ]);

        $lead = BLLeadCreation::where('uuid', $lead_uuid)->first();

        if (! $lead) {
            return response()->json([
                'success' => false,
                'message' => 'Lead not found.',
                'data' => null,
            ], 404);
        }

        $validated['lead_uuid'] = $lead_uuid;
        $validated['lead_id'] = $lead->lead_id;

        $validated['reference_sno'] = BLReference::where('lead_uuid', $lead_uuid)->count();

        $reference = BLReference::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'BL Reference created successfully',
            'request' => [
                'lead_uuid' => $lead_uuid,
            ],
            'data' => new BLReferenceResource($reference),
        ], 201);
    }

 
    public function show($lead_uuid, $reference_id): JsonResponse
    {
        $reference = BLReference::where('lead_uuid', $lead_uuid)
            ->where('id', $reference_id)
            ->first();

        if (! $reference) {
            return response()->json([
                'success' => false,
                'message' => 'Reference not found for this lead.',
                'request' => [
                    'lead_uuid' => $lead_uuid,
                    'reference_id' => $reference_id,
                ],
                'data' => null,
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'BL Reference retrieved successfully',
            'request' => [
                'lead_uuid' => $lead_uuid,
                'reference_id' => $reference_id,
            ],
            'data' => new BLReferenceResource($reference),
        ], 200);
    }

   
    public function destroy($lead_uuid, $reference_id): JsonResponse
    {
        $lead = BLLeadCreation::where('uuid', $lead_uuid)->first();

        if (! $lead) {
            return response()->json([
                'success' => false,
                'message' => 'Lead not found.',
                'data' => null,
            ], 404);
        }

        $reference = BLReference::where('lead_uuid', $lead_uuid)
            ->where('id', $reference_id)
            ->first();

        if (! $reference) {
            return response()->json([
                'success' => false,
                'message' => 'Reference not found for this lead.',
                'request' => [
                    'lead_uuid' => $lead_uuid,
                    'reference_id' => $reference_id,
                ],
                'data' => null,
            ], 404);
        }

        $totalReferences = BLReference::where('lead_uuid', $lead_uuid)->count();

        if ($totalReferences <= 2) {
            return response()->json([
                'success' => false,
                'message' => 'Minimum 2 references are required. You cannot delete this reference.',
                'request' => [
                    'lead_uuid' => $lead_uuid,
                    'reference_id' => $reference_id,
                ],
                'data' => null,
            ], 422);
        }

        $reference->delete();

        return response()->json([
            'success' => true,
            'message' => 'BL Reference deleted successfully',
            'request' => [
                'lead_uuid' => $lead_uuid,
                'reference_id' => $reference_id,
            ],
            'data' => null,
        ], 200);
    }
}
