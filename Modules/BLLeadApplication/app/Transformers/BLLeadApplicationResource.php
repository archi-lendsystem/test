<?php

namespace Modules\BLLeadApplication\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\BLApplicantDetails\Models\BLApplicantDetails;
use Modules\BLBusinessDetails\Models\BLBusinessDetails;
use Modules\BLOfficeDetails\Models\BLOfficeDetail;
use Modules\BLReferenceDetails\Models\BLReferenceDetails;


use Modules\BLWorkDetails\Models\BLWorkDetails;

class BLLeadApplicationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $lead             = $this->resource['lead'];
        $applicants       = $this->resource['applicants'];
        $applicantDetails = $this->resource['applicant_details'];
        $officeDetails    = $this->resource['office_details'];
        $workDetails      = $this->resource['work_details'];
        $businessDetails  = $this->resource['business_details'];
     
        $references       = $this->resource['references'];
        $referenceDetails = $this->resource['reference_details'];
        $applicantImages  = $this->resource['applicant_images'] ?? collect();


        $bankDetails  = $this->resource['bank_details']  ?? collect();
        $ownerDetails = $this->resource['owner_details'] ?? collect();

        return [
            'lead' => [
                'id'             => $lead->id,
                'uuid'           => $lead->uuid,
                'lead_id'        => $lead->lead_id,
                'created_by'     => $lead->created_by,
                'loan_type'      => $lead->loan_type,
                'customer_type'  => $lead->customer_type,
                'sub_loan_type'  => $lead->sub_loan_type,
                'source_by'      => $lead->source_by,
                'email'          => $lead->email,
                'mobile_number'  => $lead->mobile_number,
                'loan_amount'    => $lead->loan_amount,
                'loan_tenur'     => $lead->loan_tenur,
                'created_at'     => $lead->created_at?->toDateTimeString(),
                'updated_at'     => $lead->updated_at?->toDateTimeString(),
            ],

            'summary' => [
                'has_lead_creation'       => true,
                'total_applicants'        => $applicants->count(),
                'total_applicant_details' => $applicantDetails->count(),
                'total_office_details'    => $officeDetails->count(),
                'total_work_details'      => $workDetails->count(),
                'total_business_details'  => $businessDetails->count(),
                'total_co_applicants'     => $this->countCoApplicants($applicantDetails),
            
                'total_references'        => $references->count(),
                'total_reference_details' => $referenceDetails->count(),

                'total_bank_details'      => $bankDetails->flatten(1)->count(),
                'total_owner_details'     => $ownerDetails->flatten(1)->count(),
            ],

            'applicants' => $applicants->map(function ($applicant) use (
                $applicantDetails,
                $officeDetails,
                $workDetails,
                $businessDetails,
                $applicantImages,
                $bankDetails,  
                $ownerDetails   
            ) {
                $applicantDetail = $applicantDetails->get($applicant->id);
                $officeDetail    = $officeDetails->get($applicant->id);
                $workDetail      = $workDetails->get($applicant->id);
                $businessDetail  = $businessDetails->get($applicant->id);

       
                $applicantBankDetails  = $bankDetails->get($applicant->id,  collect());
                $applicantOwnerDetails = $ownerDetails->get($applicant->id, collect());

                return [
                    'id'             => $applicant->id,
                    'lead_uuid'      => $applicant->lead_uuid,
                    'created_by'     => $applicant->created_by,
                    'lead_id'        => $applicant->lead_id,
                    'applicant_sno'  => $applicant->applicant_sno,
                    'applicant_types' => $applicant->applicant_types,

                    'profile_picture' => collect($applicantImages->get($applicant->applicant_sno, []))
                        ->values(),

                    'has_applicant_detail' => $applicantDetail !== null,
                    'applicant_detail'     => $applicantDetail
                        ? $this->formatApplicantDetail($applicantDetail)
                        : null,

                    'has_office_detail' => $officeDetail !== null,
                    'office_detail'     => $officeDetail
                        ? $this->formatOfficeDetail($officeDetail)
                        : null,

                    'has_work_detail' => $workDetail !== null,
                    'work_detail'     => $workDetail
                        ? $this->formatWorkDetail($workDetail)
                        : null,

                    'has_business_detail' => $businessDetail !== null,
                    'business_detail'     => $businessDetail
                        ? $this->formatBusinessDetail($businessDetail)
                        : null,

               
                    'bank_details' => $this->formatBankDetails($applicantBankDetails),

              
                    'owner_details' => $this->formatOwnerDetails($applicantOwnerDetails),

                    'created_at' => $applicant->created_at?->toDateTimeString(),
                    'updated_at' => $applicant->updated_at?->toDateTimeString(),
                ];
            })->values(),

     

            'references' => $references->map(function ($reference) use ($referenceDetails) {
                $referenceDetail = $referenceDetails->get($reference->id);

                return [
                    'id'                   => $reference->id,
                    'lead_uuid'            => $reference->lead_uuid,
                    'created_by'           => $reference->created_by,
                    'lead_id'              => $reference->lead_id,
                    'reference_sno'        => $reference->reference_sno,
                    'reference_types'      => $reference->reference_types,
                    'has_reference_detail' => $referenceDetail !== null,
                    'reference_detail'     => $referenceDetail
                        ? $this->formatReferenceDetail($referenceDetail)
                        : null,
                    'created_at'           => $reference->created_at?->toDateTimeString(),
                    'updated_at'           => $reference->updated_at?->toDateTimeString(),
                ];
            })->values(),
        ];
    }


    private function formatBankDetails(mixed $records): array
    {
        return collect($records)
            ->map(fn ($record) => [
                'bank_name'           => data_get($record, 'bank_name'),
                'account_holder_name' => data_get($record, 'account_holder_name'),
                'account_type'        => data_get($record, 'account_type'),
            ])
            ->values()
            ->toArray();
    }


    private function formatOwnerDetails(mixed $records): array
    {
        return collect($records)
            ->map(fn ($record) => [
                'applicant_role_id'  => data_get($record, 'applicant_role_id'),
                'role'               => data_get($record, 'role'),
                'type'               => data_get($record, 'type'),
                'full_name'          => data_get($record, 'full_name'),
                'mother_name'        => data_get($record, 'mother_name'),
                'date_of_birth'      => data_get($record, 'date_of_birth'),
                'mobile_number'      => data_get($record, 'mobile_number'),
                'gender'             => data_get($record, 'gender'),
                'pan_number'         => data_get($record, 'pan_number'),
                'residence_address'  => data_get($record, 'residence_address'),
                'pincode'            => data_get($record, 'pincode'),
                'district'           => data_get($record, 'district'),
                'city'               => data_get($record, 'city'),
                'state'              => data_get($record, 'state'),
                'ownership_status'   => data_get($record, 'ownership_status'),
            ])
            ->values()
            ->toArray();
    }


    private function countCoApplicants(iterable $applicantDetails): int
    {
        $count = 0;
        foreach ($applicantDetails as $detail) {
            if (in_array($detail->getAttribute('is_co_applicant'), [true, 1, '1', 'true', 'TRUE'], true)) {
                $count++;
            }
        }
        return $count;
    }

    private function formatApplicantDetail(BLApplicantDetails $detail): array
    {
        return [
            'id'                          => $detail->id,
            'lead_uuid'                   => $detail->lead_uuid,
            'created_by'                  => $detail->created_by,
            'lead_id'                     => $detail->lead_id,
            'applicant_id'                => $detail->applicant_id,
            'applicant_sno'               => $detail->applicant_sno,
            'applicant_type'              => $detail->applicant_type,
            'full_name'                   => $detail->full_name,
            'gender'                      => $detail->gender,
            'employment_type'             => $detail->employment_type,
            'is_co_applicant'             => $detail->is_co_applicant,
            'aadhaar_card_number'         => $detail->aadhaar_card_number,
            'pan_card_number'             => $detail->pan_card_number,
            'secondary_kyc_type'          => $detail->secondary_kyc_type,
'secondary_kyc_number'        => $detail->secondary_kyc_number,
            'date_of_birth'               => $detail->date_of_birth,
            'age'                         => $detail->age,
            'nationality'                 => $detail->nationality,
            'religion'                    => $detail->religion,
            'caste'                       => $detail->caste,
            'bureau_deviation'            => $detail->bureau_deviation,
            'is_income_considered'        => $detail->is_income_considered,
            'applying_as'                 => $detail->applying_as,
            'entity_type'                 => $detail->entity_type,
            'proposed_property_owner'     => $detail->proposed_property_owner,
            'alternate_contact_number'    => $detail->alternate_contact_number,
            'qualification'               => $detail->qualification,
            'marital_status'              => $detail->marital_status,
            'email_address'               => $detail->email_address,
            'father_full_name'            => $detail->father_full_name,
            'mother_full_name'            => $detail->mother_full_name,
            'spouse_full_name'            => $detail->spouse_full_name,
            'living_standard_indicators'  => $detail->living_standard_indicators,
            'premise_ownership'           => $detail->premise_ownership,
            'residence_in_months'         => $detail->residence_in_months,
            'numbers_of_family_members'   => $detail->numbers_of_family_members,
            'numbers_of_earning_members'  => $detail->numbers_of_earning_members,
            'numbers_dependents'          => $detail->numbers_dependents,
            'property_owner'              => $detail->property_owner,
            'relationship'                => $detail->relationship,
            'current_address'             => $detail->current_address,
            'current_landmark'            => $detail->current_landmark,
            'current_pincode'             => $detail->current_pincode,
            'current_district'            => $detail->current_district,
            'current_city'                => $detail->current_city,
            'current_state'               => $detail->current_state,
            'current_staying_in_years'    => $detail->current_staying_in_years,
            'permanent_address'           => $detail->permanent_address,
            'permanent_landmark'          => $detail->permanent_landmark,
            'permanent_pincode'           => $detail->permanent_pincode,
            'permanent_district'          => $detail->permanent_district,
            'permanent_city'              => $detail->permanent_city,
            'permanent_state'             => $detail->permanent_state,
            'permanent_staying_in_years'  => $detail->permanent_staying_in_years,
            'same_as_current_address'     => $detail->same_as_current_address,
            'profile_photo_url'           => $detail->profile_photo_url,
            'created_at'                  => $detail->created_at?->toDateTimeString(),
            'updated_at'                  => $detail->updated_at?->toDateTimeString(),
        ];
    }

    private function formatOfficeDetail(BLOfficeDetail $detail): array
    {
        return [
            'id'               => $detail->id,
            'lead_uuid'        => $detail->lead_uuid,
            'created_by'       => $detail->created_by,
            'lead_id'          => $detail->lead_id,
            'applicant_type'   => $detail->applicant_type,
            'applicant_sno'    => $detail->applicant_sno,
            'applicant_id'     => $detail->applicant_id,
            'address'          => $detail->address,
            'landmark'         => $detail->landmark,
            'pincode'          => $detail->pincode,
            'district'         => $detail->district,
            'city'             => $detail->city,
            'state'            => $detail->state,
            'working_in_months' => $detail->working_in_months,
            'created_at'       => $detail->created_at?->toDateTimeString(),
            'updated_at'       => $detail->updated_at?->toDateTimeString(),
        ];
    }

    private function formatWorkDetail(BLWorkDetails $detail): array
    {
        return [
            'id'                   => $detail->id,
            'lead_uuid'            => $detail->lead_uuid,
            'created_by'           => $detail->created_by,
            'lead_id'              => $detail->lead_id,
            'applicant_id'         => $detail->applicant_id,
            'applicant_sno'        => $detail->applicant_sno,
            'applicant_type'       => $detail->applicant_type,
            'company_name'         => $detail->company_name,
            'designation'          => $detail->designation,
            'salary_credit_method' => $detail->salary_credit_method,
            'monthly_salary'       => $detail->monthly_salary,
            'official_email'       => $detail->official_email,
            'total_work_company'   => $detail->total_work_company,
            'epf_deducted'         => $detail->epf_deducted,
            'working_months'       => $detail->working_months,
            'created_at'           => $detail->created_at?->toDateTimeString(),
            'updated_at'           => $detail->updated_at?->toDateTimeString(),
        ];
    }

    private function formatBusinessDetail(BLBusinessDetails $detail): array
    {
        return [
            'id'                         => $detail->id,
            'lead_uuid'                  => $detail->lead_uuid,
            'created_by'                 => $detail->created_by,
            'lead_id'                    => $detail->lead_id,
            'applicant_id'               => $detail->applicant_id,
            'applicant_sno'              => $detail->applicant_sno,
            'applicant_type'             => $detail->applicant_type,
            'business_name'              => $detail->business_name,
            'nature_of_business'         => $detail->nature_of_business,
            'incorporation_date'         => $detail->incorporation_date,
            'pan_number'                 => $detail->pan_number,
            'monthly_sales'              => $detail->monthly_sales,
            'office_address'             => $detail->office_address,
            'pincode'                    => $detail->pincode,
            'district'                   => $detail->district,
            'city'                       => $detail->city,
            'state'                      => $detail->state,
            'business_premise_ownership' => $detail->business_premise_ownership,
            'created_at'                 => $detail->created_at?->toDateTimeString(),
            'updated_at'                 => $detail->updated_at?->toDateTimeString(),
        ];
    }

    private function formatPropertyDetail(BLPropertyDetail $detail): array
    {
        return [
            'id'                         => $detail->getAttribute('id'),
            'lead_uuid'                  => $detail->getAttribute('lead_uuid'),
            'created_by'                 => $detail->getAttribute('created_by'),
            'lead_id'                    => $detail->getAttribute('lead_id'),
            'property_id'                => $detail->getAttribute('property_id'),
            'pd_property_owner'          => $this->formatJsonValue($detail->getAttribute('pd_property_owner')),
            'pd_collateral_usage'        => $detail->getAttribute('pd_collateral_usage'),
            'pd_collateral_type'         => $detail->getAttribute('pd_collateral_type'),
            'pd_property_categorization' => $detail->getAttribute('pd_property_categorization'),
            'pd_collateral_status'       => $detail->getAttribute('pd_collateral_status'),
            'pd_property_ownership'      => $detail->getAttribute('pd_property_ownership'),
            'pd_property_type'           => $detail->getAttribute('pd_property_type'),
            'pd_occupancy_status'        => $detail->getAttribute('pd_occupancy_status'),
            'pd_property_address'        => $detail->getAttribute('pd_property_address'),
            'pd_landmark'                => $detail->getAttribute('pd_landmark'),
            'pincode'                    => $detail->getAttribute('pincode'),
            'district'                   => $detail->getAttribute('district'),
            'city'                       => $detail->getAttribute('city'),
            'state'                      => $detail->getAttribute('state'),
            'pd_property_paper'          => $detail->getAttribute('pd_property_paper'),
            'created_at'                 => $detail->getAttribute('created_at')?->toDateTimeString(),
            'updated_at'                 => $detail->getAttribute('updated_at')?->toDateTimeString(),
        ];
    }

    private function formatReferenceDetail(BLReferenceDetails $detail): array
    {
        return [
            'id'             => $detail->id,
            'lead_uuid'      => $detail->lead_uuid,
            'created_by'     => $detail->created_by,
            'lead_id'        => $detail->lead_id,
            'reference_id'   => $detail->reference_id,
            'reference_sno'  => $detail->reference_sno,
            'reference_type' => $detail->reference_type,
            'full_name'      => $detail->full_name,
            'mobile_no'      => $detail->mobile_no,
            'email'          => $detail->email,
            'gender'         => $detail->gender,
            'relationship'   => $detail->relationship,
            'address'        => $detail->address,
            'pincode'        => $detail->pincode,
            'district'       => $detail->district,
            'city'           => $detail->city,
            'state'          => $detail->state,
            'status'         => $detail->status,
            'remarks'        => $detail->remarks,
            'created_at'     => $detail->created_at?->toDateTimeString(),
            'updated_at'     => $detail->updated_at?->toDateTimeString(),
        ];
    }

    private function formatJsonValue(mixed $value): mixed
    {
        if (is_array($value) || is_object($value) || $value === null) {
            return $value;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        return $value;
    }
}
