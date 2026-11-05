<?php

namespace Modules\BLSchemeSelect\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductSchemeOptionResource extends JsonResource
{
  

     public function toArray(Request $request): array
    {
        return [
            'uuid'                => $this->uuid,
            'product_id'          => $this->product_id,
            'product_name'        => $this->product?->name ?? $this->product_name,
            'product_code'        => $this->product?->code,

            'scheme_name'         => $this->scheme_name,
            'sub_loan_type'       => $this->sub_loan_type,

            'interest_rate' => $this->interest_rate !== null
                ? (float) $this->interest_rate
                : null,

            'repayment_frequency' => $this->repayment_frequency,
            'calculation_method'  => $this->calculation_method,

            'lpf_rate' => $this->lpf_rate !== null
                ? (float) $this->lpf_rate
                : null,

            'platform_charges' => $this->platform_charges !== null
                ? (float) $this->platform_charges
                : null,

            'sms_charges' => $this->sms_charges !== null
                ? (float) $this->sms_charges
                : null,

            'minimum_loan_amount' => $this->minimum_loan_amount,
            'maximum_loan_amount' => $this->maximum_loan_amount,

            'minimum_pf' => $this->minimum_pf !== null
                ? (float) $this->minimum_pf
                : null,

            'maximum_pf' => $this->maximum_pf !== null
                ? (float) $this->maximum_pf
                : null,

            'moratorium_days'     => $this->moratorium_days,
            'overdue_interest_on' => $this->overdue_interest_on,

            'emi_round_off_type' => $this->emi_round_off_type,

            'emi_round_up_value' => $this->emi_round_up_value !== null
                ? (float) $this->emi_round_up_value
                : null,

            'tenure_value' => $this->tenure_value,
            'tenure_unit'  => $this->tenure_unit,

            'valid_from' => $this->valid_from?->format('Y-m-d'),
            'valid_till' => $this->valid_till?->format('Y-m-d'),

            'remarks'   => $this->remarks,
            'is_active' => (bool) $this->is_active,

            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}

// namespace Modules\BLSchemeSelect\Transformers;

// use Illuminate\Http\Request;
// use Illuminate\Http\Resources\Json\JsonResource;

// class ProductSchemeOptionResource extends JsonResource
// {
  

//      public function toArray(Request $request): array
//     {
//         return [
//             'uuid'                => $this->uuid,
//             'product_id'          => $this->product_id,
//             'product_name'        => $this->product_name,

//             'scheme_name'         => $this->scheme_name,
//             'sub_loan_type'       => $this->sub_loan_type,

//             'interest_rate' => $this->interest_rate !== null
//                 ? (float) $this->interest_rate
//                 : null,

//             'repayment_frequency' => $this->repayment_frequency,
//             'calculation_method'  => $this->calculation_method,

//             'lpf_rate' => $this->lpf_rate !== null
//                 ? (float) $this->lpf_rate
//                 : null,

//             'platform_charges' => $this->platform_charges !== null
//                 ? (float) $this->platform_charges
//                 : null,

//             'sms_charges' => $this->sms_charges !== null
//                 ? (float) $this->sms_charges
//                 : null,

//             'minimum_loan_amount' => $this->minimum_loan_amount,
//             'maximum_loan_amount' => $this->maximum_loan_amount,

//             'minimum_pf' => $this->minimum_pf !== null
//                 ? (float) $this->minimum_pf
//                 : null,

//             'maximum_pf' => $this->maximum_pf !== null
//                 ? (float) $this->maximum_pf
//                 : null,

//             'moratorium_days'     => $this->moratorium_days,
//             'overdue_interest_on' => $this->overdue_interest_on,

//             'emi_round_off_type' => $this->emi_round_off_type,

//             'emi_round_up_value' => $this->emi_round_up_value !== null
//                 ? (float) $this->emi_round_up_value
//                 : null,

//             'tenure_value' => $this->tenure_value,
//             'tenure_unit'  => $this->tenure_unit,

//             'valid_from' => $this->valid_from?->format('Y-m-d'),
//             'valid_till' => $this->valid_till?->format('Y-m-d'),

//             'remarks'   => $this->remarks,
//             'is_active' => (bool) $this->is_active,

//             'created_at' => $this->created_at?->toISOString(),
//             'updated_at' => $this->updated_at?->toISOString(),
//         ];
//     }
// }
