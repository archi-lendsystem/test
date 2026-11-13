<?php

namespace Modules\BLReferenceVerification\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BLReferenceVerificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'reference_detail_id' => $this->id,
            'lead_uuid'           => $this->lead_uuid,
            'reference_id'        => $this->reference_id,
            'lead_id'             => $this->lead_id,
            'reference_sno'       => $this->reference_sno,
            'reference_type'      => $this->reference_type,


            'full_name'    => $this->full_name,
            'mobile_no'    => $this->mobile_no,
            'email'        => $this->email,
            'gender'       => $this->gender,
            'relationship' => $this->relationship,
            'address'      => $this->address,
            'pincode'      => $this->pincode,
            'district'     => $this->district,
            'city'         => $this->city,
            'state'        => $this->state,


            'verification' => $this->verification ? [
                'id'         => $this->verification->id,
                'status'     => $this->verification->status,
                'remarks'    => $this->verification->remarks,
                'Known_since'=> $this->verification->Known_since,
                'created_at' => $this->verification->created_at?->toDateTimeString(),
                'updated_at' => $this->verification->updated_at?->toDateTimeString(),
            ] : null,

            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
