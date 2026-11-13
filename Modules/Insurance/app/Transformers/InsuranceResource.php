<?php

namespace Modules\Insurance\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InsuranceResource extends JsonResource
{
  public function toArray(Request $request): array
    {
        return [
            'id'        => $this->id,
            'lead_uuid' => $this->lead_uuid,

            'loan_amount' => $this->whenLoaded(
                'cam',
                fn () => $this->cam
                    ? (float) $this->cam->loan_amount
                    : null
            ),

            'insurance_percentage' =>
                (float) $this->insurance_percentage,

            'insurance_amount' =>
                (float) $this->insurance_amount,

            /*
             * Applicant details
             */
            'applicant_details' => $this->whenLoaded(
                'applicantDetails',
                function () {
                    return $this->applicantDetails
                        ->map(function ($applicant) {
                            return [
                                'id'               => $applicant->id,
                                'lead_uuid'        => $applicant->lead_uuid,
                                'lead_id'          => $applicant->lead_id,
                                'applicant_id'     => $applicant->applicant_id,
                                'applicant_sno'    => $applicant->applicant_sno,
                                'applicant_type'   => $applicant->applicant_type,
                                'is_co_applicant'  => (bool) $applicant->is_co_applicant,

                                'full_name' => $applicant->full_name,

                                'aadhaar_card_number' =>
                                    $applicant->aadhaar_card_number,

                                'pan_card_number' =>
                                    $applicant->pan_card_number,

                                'date_of_birth' => $applicant->date_of_birth
                                    ? $applicant->date_of_birth->format('Y-m-d')
                                    : null,

                                'age'         => $applicant->age,
                                'gender'      => $applicant->gender,
                                'nationality' => $applicant->nationality,
                                'religion'    => $applicant->religion,
                                'caste'       => $applicant->caste,

                                'marital_status' =>
                                    $applicant->marital_status,

                                'email_address' =>
                                    $applicant->email_address,

                                'alternate_contact_number' =>
                                    $applicant->alternate_contact_number,

                                'father_full_name' =>
                                    $applicant->father_full_name,

                                'mother_full_name' =>
                                    $applicant->mother_full_name,

                                'spouse_full_name' =>
                                    $applicant->spouse_full_name,

                                'qualification' =>
                                    $applicant->qualification,

                                'applying_as' =>
                                    $applicant->applying_as,

                                'employment_type' =>
                                    $applicant->employment_type,

                                'entity_type' =>
                                    $applicant->entity_type,

                                'is_income_considered' =>
                                    $applicant->is_income_considered,

                                'bureau_deviation' =>
                                    $applicant->bureau_deviation,

                                'premise_ownership' =>
                                    $applicant->premise_ownership,

                                'residence_in_months' =>
                                    $applicant->residence_in_months,

                                'numbers_of_family_members' =>
                                    $applicant->numbers_of_family_members,

                                'numbers_of_earning_members' =>
                                    $applicant->numbers_of_earning_members,

                                'numbers_dependents' =>
                                    $applicant->numbers_dependents,

                                'relationship' =>
                                    $applicant->relationship,

                                'current_address' =>
                                    $applicant->current_address,

                                'current_landmark' =>
                                    $applicant->current_landmark,

                                'current_pincode' =>
                                    $applicant->current_pincode,

                                'current_district' =>
                                    $applicant->current_district,

                                'current_city' =>
                                    $applicant->current_city,

                                'current_state' =>
                                    $applicant->current_state,

                                'current_staying_in_years' =>
                                    $applicant->current_staying_in_years,

                                'permanent_address' =>
                                    $applicant->permanent_address,

                                'permanent_landmark' =>
                                    $applicant->permanent_landmark,

                                'permanent_pincode' =>
                                    $applicant->permanent_pincode,

                                'permanent_district' =>
                                    $applicant->permanent_district,

                                'permanent_city' =>
                                    $applicant->permanent_city,

                                'permanent_state' =>
                                    $applicant->permanent_state,

                                'permanent_staying_in_years' =>
                                    $applicant->permanent_staying_in_years,

                                'same_as_current_address' =>
                                    (bool) $applicant->same_as_current_address,

                                'profile_photo' =>
                                    $applicant->profile_photo,

                                'profile_photo_url' =>
                                    $applicant->profile_photo_url,
                            ];
                        })
                        ->values();
                }
            ),

            /*
             * Latest sanction details
             */
            'sanction_details' => $this->whenLoaded(
                'latestSanction',
                function () {
                    if (! $this->latestSanction) {
                        return null;
                    }

                    $sanction = $this->latestSanction;

                    return [
                        'id'              => $sanction->id,
                        'lead_uuid'       => $sanction->lead_uuid,
                        'sanction_number' => $sanction->sanction_number,
                        'version'         => (int) $sanction->version,
                        'pdf_path'        => $sanction->pdf_path,

                        'sanctioned_amount' =>
                            (float) $sanction->sanctioned_amount,

                        'interest_rate' =>
                            (float) $sanction->interest_rate,

                        'tenure_months' =>
                            (int) $sanction->tenure_months,

                        'emi_amount' =>
                            (float) $sanction->emi_amount,

                        'sanction_date' => $sanction->sanction_date
                            ? $sanction->sanction_date->format('Y-m-d')
                            : null,

                        'sanction_expiry_date' =>
                            $sanction->sanction_expiry_date
                                ? $sanction->sanction_expiry_date->format('Y-m-d')
                                : null,

                        'loan_type' =>
                            $sanction->loan_type,

                        'repayment_mode' =>
                            $sanction->repayment_mode,

                        'terms_and_conditions' =>
                            $sanction->terms_and_conditions,

                        'remarks' =>
                            $sanction->remarks,

                        'status' =>
                            $sanction->status,

                        'confirmed_by' =>
                            $sanction->confirmed_by,

                        'confirmed_at' => $sanction->confirmed_at
                            ? $sanction->confirmed_at->toIso8601String()
                            : null,

                        'generated_by' =>
                            $sanction->generated_by,

                        'created_at' => $sanction->created_at
                            ? $sanction->created_at->toIso8601String()
                            : null,

                        'updated_at' => $sanction->updated_at
                            ? $sanction->updated_at->toIso8601String()
                            : null,
                    ];
                }
            ),

            'created_at' => $this->created_at
                ? $this->created_at->toIso8601String()
                : null,

            'updated_at' => $this->updated_at
                ? $this->updated_at->toIso8601String()
                : null,
        ];
    }
}
