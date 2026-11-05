<?php

namespace Modules\BLCreditVisitReport\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BLCreditVisitReportResource extends JsonResource
{
     public function toArray(Request $request): array
    {
        return [
            'id'       => $this->id,
            'lead_uuid' => $this->lead_uuid,

        
            'pd_done_by'               => $this->pd_done_by,
            'pd_date'                  => $this->pd_date?->toDateString(),
            'meeting_place'            => $this->meeting_place,
            'person_meet'              => $this->person_meet,
            'loan_purpose'             => $this->loan_purpose,
            'residence_details'        => $this->residence_details,
            'staying_together'         => $this->staying_together,
            'years_current_residence'  => $this->years_current_residence,


            'business_premises_ownership'     => $this->business_premises_ownership,
            'years_current_business_premises' => $this->years_current_business_premises,
            'years_incurrent_business'        => $this->years_incurrent_business,
            'office_type'                     => $this->office_type,
            'name_board_seen'                 => $this->name_board_seen,
            'monthly_emi_liabilities'         => $this->monthly_emi_liabilities,
            'comfortable_emi'                 => $this->comfortable_emi,
            'business_registration'           => $this->business_registration,
            'industry_type'                   => $this->industry_type,
            'sub_industry'                    => $this->sub_industry,
            'business_vintage'                => $this->business_vintage,
            'inventory_in_business'           => $this->inventory_in_business,
            'pd_status'                       => $this->pd_status,
            'remarks'                         => $this->remarks,
            'property_value'                  => $this->property_value,
            'pd_property_owner_done'          => $this->pd_property_owner_done,

        
            'stay_verification'    => $this->stay_verification,
            'stay_since'           => $this->stay_since?->toDateString(),
            'no_of_family_members' => $this->no_of_family_members,
            'stay_with'            => $this->stay_with,
            'house_ownership'      => $this->house_ownership,
            'house_structure'      => $this->house_structure,
            'neighbour_reference'  => $this->neighbour_reference,
            'address_as_per_kyc'   => $this->address_as_per_kyc,

        
            'landlord_name'       => $this->landlord_name,
            'relation_with_owner' => $this->relation_with_owner,
            'landlord_contact'    => $this->landlord_contact,

 
            'documents'       => $this->documents,
            'document_remark' => $this->document_remark,
            'site_image'      => $this->site_image,

            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
