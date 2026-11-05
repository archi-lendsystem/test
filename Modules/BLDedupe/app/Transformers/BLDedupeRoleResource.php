<?php

namespace Modules\BLDedupe\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BLDedupeRoleResource extends JsonResource
{
     public function toArray($request): array
    {
        return [
            'id'           => $this->id,
           'lead_id' => $this->lead_number ?? $this->lead_id,
            'lead_uuid'    => $this->lead_uuid,
            'applicant_id' => $this->applicant_id,
            'full_name'    => $this->full_name,
            'pan_number'   => $this->pan_number,
            'role'         => $this->role,
            'type'         => $this->type,
        ];
    }
}
