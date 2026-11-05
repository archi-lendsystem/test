<?php

namespace Modules\BLCaseDiscusion\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

use Modules\BLCaseDiscusion\Models\BLCaseDiscusion;
use Modules\BLCaseDiscusion\Transformers\BLCaseDiscusionResource;
use Modules\BLLeadCreation\Models\BLLeadCreation;

class BLCaseDiscusionController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lead_uuid'    => 'required|uuid|exists:b_l_lead_creations,uuid',
            'status'       => 'required|in:PENDING,APPROVED,REJECTED,HOLD',
            'forwarded_to' => 'nullable|integer|exists:users,id',
            'forwarded_by' => 'nullable|integer|exists:users,id',
            'remarks'      => 'nullable|string',
        ]);


        $lead = BLLeadCreation::where('uuid', $validated['lead_uuid'])->first();

        if (!$lead) {
            return response()->json([
                'success' => false,
                  'request_id' => app('request_id'),
                'message' => 'Lead not found.',
            ], 404);
        }

        $discussion = BLCaseDiscusion::create([
            'lead_uuid'    => $lead->uuid,
            'lead_id'      => $lead->lead_id,
            'created_by'   => Auth::id(),
            'status'       => $validated['status'],
            'forwarded_to' => $validated['forwarded_to'] ?? null,
            'forwarded_by' => $validated['forwarded_by'] ?? null,
            'remarks'      => $validated['remarks'] ?? null,
        ]);

        return response()->json([
            'success'     => true,
            'status_code' => 201,
              'request_id' => app('request_id'),
            'message'     => 'Case discussion created successfully.',
            'data'        => new BLCaseDiscusionResource(
                $discussion->load(['createdBy', 'forwardedTo', 'forwardedBy'])
            ),
        ], 201);
    }



    public function show(string $leadUuid): JsonResponse
    {

        $lead = BLLeadCreation::where('uuid', $leadUuid)->first();

        if (!$lead) {
            return response()->json([
                'success' => false,
                'message' => 'Lead not found.',
                  'request_id' => app('request_id'),
            ], 404);
        }


        $discussions = BLCaseDiscusion::where('lead_uuid', $leadUuid)
            ->with(['createdBy', 'forwardedTo', 'forwardedBy'])
            ->latest()
            ->get();

        if ($discussions->isEmpty()) {
            return response()->json([
                'success'     => false,
                'status_code' => 404,
                'message'     => 'No discussions found for this lead.',
                'request_id' => app('request_id'),
                'data'        => [],
            ], 404);
        }

        return response()->json([
            'success'     => true,
            'status_code' => 200,
            'message'     => 'Case discussions retrieved successfully.',
            'total'       => $discussions->count(),
            'request_id' => app('request_id'),
            'data'        => BLCaseDiscusionResource::collection($discussions),
        ], 200);
    }
}
