<?php

namespace Modules\BLApplicantRoleDetails\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BLApplicantRoleDetailsResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            'lead_uuid' => $this->lead_uuid,
            'applicant_role_id' => $this->applicant_role_id,
            'lead_id' => $this->lead_id,
            'applicant_id' => $this->applicant_id,

            'applicant_sno' => $this->applicant_sno,
            'applicant_type' => $this->applicant_type,
            'type' => $this->type,
            'role' => $this->role,
            'sno' => $this->sno,

            'full_name' => $this->full_name,
            'mother_name' => $this->mother_name,
            'date_of_birth' => $this->date_of_birth,
            'mobile_number' => $this->mobile_number,
            'gender' => $this->gender,
            'pan_number' => $this->pan_number,
            'residence_address' => $this->residence_address,
            'pincode' => $this->pincode,
            'district' => $this->district,
            'city' => $this->city,
            'state' => $this->state,
            'ownership_status' => $this->ownership_status,

            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
