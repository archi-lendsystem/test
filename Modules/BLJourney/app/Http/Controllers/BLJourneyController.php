<?php

namespace Modules\BLJourney\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Modules\BLJourney\Models\BLJourney;
use Modules\BLJourney\Transformers\BLJourneyResource;
use Throwable;

class BLJourneyController extends Controller
{
    public function show(string $lead_uuid): JsonResponse
    {
        try {

            $journeys = BLJourney::query()
                ->with([
                    'workflowStage:id,stage_name,stage_code,team_name',
                    'workflowStatus:id,status_name,status_code',
                    'previousStage:id,stage_name,stage_code',
                    'user:id,user_name,user_email'
                ])
                ->where('lead_uuid', $lead_uuid)
                ->latest('id')
                ->get();

            if ($journeys->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'status_code' => 404,
                    'code' => 'JOURNEY_NOT_FOUND',
                    'message' => 'Journey not found.',
                    'data' => [],
                ], 404);
            }

            return response()->json([
                'success' => true,
                'status_code' => 200,
                'code' => 'SUCCESS',
                'message' => 'Journey fetched successfully.',
             'data' => $journeys->map(fn ($journey) =>
                    BLJourneyResource::transformJourney($journey)
                ),
                            ]);

        } catch (Throwable $e) {

            return response()->json([
                'success' => false,
                'status_code' => 500,
                'code' => 'SERVER_ERROR',
                'message' => $e->getMessage(),
                'data' => null,
            ], 500);
        }
    }
}
