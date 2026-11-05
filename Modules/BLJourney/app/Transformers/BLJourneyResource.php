<?php

namespace Modules\BLJourney\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\BLJourney\Models\BLJourney;

class BLJourneyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return self::transformJourney($this->resource);
    }

    public static function transformJourney(BLJourney $journey): array
    {
        return [
            'id' => $journey->id,
            'lead_id' => $journey->lead_id,
            'lead_uuid' => $journey->lead_uuid,
            'is_latest' => $journey->is_latest,
            'remarks' => $journey->remarks,
            'action_type' => $journey->action_type,
            'ip_address' => $journey->ip_address,
            'device_info' => $journey->device_info,
            'created_at' => $journey->created_at?->toISOString(),
            'workflow' => [
                'stage' => $journey->workflowStage ? [
                    'id' => $journey->workflowStage->id,
                    'name' => $journey->workflowStage->stage_name,
                    'code' => $journey->workflowStage->stage_code,
                    'team' => $journey->workflowStage->team_name,
                ] : null,
                'status' => $journey->workflowStatus ? [
                    'id' => $journey->workflowStatus->id,
                    'name' => $journey->workflowStatus->status_name,
                    'code' => $journey->workflowStatus->status_code,
                ] : null,
                'previous_stage' => $journey->previousStage ? [
                    'id' => $journey->previousStage->id,
                    'name' => $journey->previousStage->stage_name,
                    'code' => $journey->previousStage->stage_code,
                ] : null,
                'action_performed' => $journey->action_performed,
                'transition_type' => $journey->transition_type,
                'performed_by_role' => $journey->performed_by_role,
            ],
            'performed_by' => [
                'id' => $journey->user?->id ?? $journey->user_id,
                'name' => $journey->user?->user_name ?? $journey->user_name,
                'email' => $journey->user?->user_email,
            ],
        ];
    }
}