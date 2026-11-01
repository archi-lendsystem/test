<?php

namespace Modules\BLTeleVerification\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BLTeleVerificationResource extends JsonResource
{
     public function toArray($request): array
    {
        return [
            'id'           => $this->id,
            'lead_uuid'    => $this->lead_uuid,
            'lead_id'      => $this->lead_id,

           
            'applicant_sno'  => $this->applicant_sno,
            'applicant_type' => $this->applicant_type,

        
            'tvr_flag'            => $this->tvr_flag,
            'tvr_status'          => $this->tvr_status,
            'tvr_risk_assessment' => $this->tvr_risk_assessment,
            'tvr_completed_at'    => $this->tvr_completed_at?->toDateTimeString(),

        
            'section_a' => [
                'q1_name_correct'    => $this->q1_name_correct,
                'q1_correct_name'    => $this->q1_correct_name,
                'q2_mobile_verified' => $this->q2_mobile_verified,
                'q3_address_correct' => $this->q3_address_correct,
                'q3_updated_address' => $this->q3_updated_address,
            ],

           
            'section_b' => [
                'q4_self_employed'    => $this->q4_self_employed,
                'q4_business_type'    => $this->q4_business_type,
                'q5_business_vintage' => $this->q5_business_vintage,
                'q5_vintage_years'    => $this->q5_vintage_years,
                'q6_fulltime_business'=> $this->q6_fulltime_business,
            ],

      
            'section_c' => [
                'q7_salaried'          => $this->q7_salaried,
                'q8_employer_name'     => $this->q8_employer_name,
                'q9_employment_type'   => $this->q9_employment_type,
                'q10_total_experience' => $this->q10_total_experience,
                'q11_current_tenure'   => $this->q11_current_tenure,
                'q12_salary_bank'      => $this->q12_salary_bank,
            ],

            'section_d' => [
                'q13_income_threshold' => $this->q13_income_threshold,
                'q13_monthly_income'   => $this->q13_monthly_income,
                'q14_income_banking'   => $this->q14_income_banking,
                'q15_additional_income'=> $this->q15_additional_income,
                'q15_income_source'    => $this->q15_income_source,
                'q15_income_amount'    => $this->q15_income_amount,
            ],


            'section_e' => [
                'q16_married'              => $this->q16_married,
                'q17_spouse_income'        => $this->q17_spouse_income,
                'q17_spouse_income_nature' => $this->q17_spouse_income_nature,
                'q18_dependents'           => $this->q18_dependents,
                'q18_dependent_count'      => $this->q18_dependent_count,
                'q19_expenses'             => $this->q19_expenses,
                'q19_expense_amount'       => $this->q19_expense_amount,
            ],

       
            'section_f' => [
                'q20_existing_loans' => $this->q20_existing_loans,
                'q20_loan_details'   => $this->q20_loan_details,
                'q20_emi_amount'     => $this->q20_emi_amount,
                'q21_loan_defaults'  => $this->q21_loan_defaults,
            ],

    
            'section_h' => [
                'q27_emi_aware'          => $this->q27_emi_aware,
                'q28_other_applications' => $this->q28_other_applications,
            ],

          
            'section_i' => [
                'q29_consent'        => $this->q29_consent,
                'q30_information_true'=> $this->q30_information_true,
            ],

          
            'section_j' => [
                'q31_tvr_status'        => $this->q31_tvr_status,
                'q32_risk_assessment'   => $this->q32_risk_assessment,
                'q33_caller_remarks'    => $this->q33_caller_remarks,
                'q34_recommended_action'=> $this->q34_recommended_action,
            ],

          
            'consent_given' => $this->consent_given,
            'consent_at'    => $this->consent_at?->toDateTimeString(),
            'verified_by'   => $this->verified_by,
            'verified_at'   => $this->verified_at?->toDateTimeString(),
            'ip_address'    => $this->ip_address,

          
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),

   
            'lead' => $this->whenLoaded('leadCreation', fn() => [
                'uuid'    => $this->leadCreation->uuid,
                'lead_id' => $this->leadCreation->lead_id,
            ]),
            'verified_by_user' => $this->whenLoaded('verifiedBy', fn() => [
                'id'   => $this->verifiedBy->id,
                    'name' => $this->verifiedBy?->user_name,
            ]),
        ];
    }
}
