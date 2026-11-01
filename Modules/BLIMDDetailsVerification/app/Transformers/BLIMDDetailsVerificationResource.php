<?php

namespace Modules\BLIMDDetailsVerification\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BLIMDDetailsVerificationResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'           => $this->id,

         
            'lead'         => [
                'uuid'    => $this->lead_uuid,
                'lead_id' => $this->lead_id,
            ],

            'applicant_id' => (int) $this->applicant_id,

          
            'verification' => [
                'status'  => $this->status,
                'remarks' => $this->remarks ?? null,
            ],

   
            'consent' => [
                'given' => (bool) $this->consent_given,
                'at'    => $this->consent_at?->toDateTimeString() ?? null,
            ],

     
            'verified' => [
                'by' => $this->verified_by ?? null,
                'at' => $this->verified_at?->toDateTimeString() ?? null,
            ],

   
            'meta' => [
                'created_at' => $this->created_at?->toDateTimeString(),
                'updated_at' => $this->updated_at?->toDateTimeString(),
            ],
        ];
    }
}
