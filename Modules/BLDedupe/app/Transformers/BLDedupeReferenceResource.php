<?php

namespace Modules\BLDedupe\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BLDedupeReferenceResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'             => $this->id,
            'lead_id' => $this->lead_number ?? $this->lead_id, 
            'lead_uuid'      => $this->lead_uuid,
            'full_name'      => $this->full_name,
            'mobile_no'      => $this->mobile_no,
            'email'          => $this->email,
            'reference_type' => $this->reference_type,
            'relationship'   => $this->relationship,
        ];
    }
}
