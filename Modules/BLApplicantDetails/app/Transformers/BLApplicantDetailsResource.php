<?php


namespace Modules\BLApplicantDetails\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BLApplicantDetailsResource extends JsonResource
{
    public function __construct($resource)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        return [
            'id'                          => $this->id,
            'lead_uuid'                   => $this->lead_uuid,
            'applicant_id'                => $this->applicant_id,
            'lead_id'                     => $this->lead_id,
            'applicant_sno'               => $this->applicant_sno,
            'applicant_type'              => $this->applicant_type,

            'full_name'                   => $this->full_name,
            'gender'                      => $this->gender,
            'date_of_birth'               => $this->date_of_birth,
            'age'                         => $this->age,
            'nationality'                 => $this->nationality,
            'religion'                    => $this->religion,
            'caste'                       => $this->caste,
            'marital_status'              => $this->marital_status,
            'qualification'               => $this->qualification,

            'pan_card_number'             => $this->pan_card_number,
            'secondary_kyc_type'   => $this->secondary_kyc_type,
'secondary_kyc_number' => $this->secondary_kyc_number,
            'aadhaar_card_number'         => $this->aadhaar_card_number,


            'email_address'               => $this->email_address,
            'alternate_contact_number'    => $this->alternate_contact_number,

            'father_full_name'            => $this->father_full_name,
            'mother_full_name'            => $this->mother_full_name,
            'spouse_full_name'            => $this->spouse_full_name,
            'numbers_of_family_members'   => $this->numbers_of_family_members,
            'numbers_of_earning_members'  => $this->numbers_of_earning_members,
            'numbers_dependents'          => $this->numbers_dependents,

            'employment_type'             => $this->employment_type,
            'is_income_considered'        => $this->is_income_considered,
            'bureau_deviation'            => $this->bureau_deviation,
            'applying_as'                 => $this->applying_as,
            'entity_type'                 => $this->entity_type,
            'relationship'                => $this->relationship,
            'premise_ownership'           => $this->premise_ownership,
            'living_standard_indicators'  => $this->living_standard_indicators,
            'residence_in_months'         => $this->residence_in_months,

            'current_address'             => $this->current_address,
            'current_landmark'            => $this->current_landmark,
            'current_pincode'             => $this->current_pincode,
            'current_district'            => $this->current_district,
            'current_city'                => $this->current_city,
            'current_state'               => $this->current_state,
            'current_staying_in_years'    => $this->current_staying_in_years,

            'permanent_address'           => $this->permanent_address,
            'permanent_landmark'          => $this->permanent_landmark,
            'permanent_pincode'           => $this->permanent_pincode,
            'permanent_district'          => $this->permanent_district,
            'permanent_city'              => $this->permanent_city,
            'permanent_state'             => $this->permanent_state,
            'permanent_staying_in_years'  => $this->permanent_staying_in_years,
            'same_as_current_address'     => $this->same_as_current_address,

            'is_co_applicant'             => $this->is_co_applicant,

            'profile_photo_url'           => $this->profile_photo_url,

            'created_at'                  => $this->created_at?->toISOString(),
            'updated_at'                  => $this->updated_at?->toISOString(),
        ];
    }
}