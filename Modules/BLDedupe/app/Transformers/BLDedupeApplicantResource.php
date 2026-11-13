<?php

namespace Modules\BLDedupe\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BLDedupeApplicantResource extends JsonResource
{
   public function toArray($request): array
    {
        return [
            'applicant_id'            => $this->applicant_id,
            'lead_id'                 => $this->lead_id,
            'lead_uuid'               => $this->lead_uuid,
            'full_name'               => $this->full_name,
            'applicant_type'          => $this->applicant_type,
            'pan_card_number'         => $this->pan_card_number,
            'aadhaar_card_number'     => $this->aadhaar_card_number,
            'email_address'           => $this->email_address,
            'alternate_contact_number'=> $this->alternate_contact_number,

   
            'matched_fields'          => $this->when(
                isset($this->matched_fields),
                $this->matched_fields ?? []
            ),
        ];
    }
}
