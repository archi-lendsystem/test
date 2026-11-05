<?php

namespace Modules\BLApplicant\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BLApplicantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [

            
                'id'                   => $this->id,
                'lead_uuid'            => $this->lead_uuid,
                'created_by'           => $this->created_by,
                'lead_id'              => $this->lead_id,
                'applicant_sno'        => $this->applicant_sno,
                'applicant_types'      => $this->applicant_types,
                'is_income_considered' => optional($this->detail)->is_income_considered,
                'entity_type'          => optional($this->detail)->entity_type,
                'employment_type'      => optional($this->detail)->employment_type,
                'applying_as'          => optional($this->detail)->applying_as,
                'created_at'           => $this->created_at?->toISOString(),
                'updated_at'           => $this->updated_at?->toISOString(),

            
        ];
    }
}
