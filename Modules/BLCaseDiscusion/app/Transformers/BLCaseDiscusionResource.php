<?php

namespace Modules\BLCaseDiscusion\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BLCaseDiscusionResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'           => $this->id,
            'lead_uuid'    => $this->lead_uuid,
            'lead_id'      => $this->lead_id,
            'status'       => $this->status,

            'forwarded_to' => $this->when(
                $this->forwardedTo,
                [
                    'id'    => $this->forwardedTo?->id,
                    'name'  => $this->forwardedTo?->user_name,
                    'email' => $this->forwardedTo?->user_email,
                ]
            ),

            'forwarded_by' => $this->when(
                $this->forwardedBy,
                [
                    'id'    => $this->forwardedBy?->id,
                    'name'  => $this->forwardedBy?->user_name,
                    'email' => $this->forwardedBy?->user_email,
                ]
            ),

            'remarks'    => $this->remarks,

            'created_by' => $this->when(
                $this->createdBy,
                [
                    'id'    => $this->createdBy?->id,
                    'name'  => $this->createdBy?->user_name,
                    'email' => $this->createdBy?->user_email,
                ]
            ),

            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
