<?php

namespace Modules\ProductScheme\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductSchemeResource extends JsonResource
{
   /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,

            'product_id' => $this->product_id,
            'product_name' => $this->whenLoaded(
                'product',
                fn () => $this->product?->name,
                $this->product_name
            ),
            'product_code' => $this->whenLoaded(
                'product',
                fn () => $this->product?->code,
                null
            ),

            'scheme_name' => $this->scheme_name,

            'sub_loan_type' => $this->sub_loan_type,
            'sub_loan_type_label' => $this->configLabel('sub_loan_types', $this->sub_loan_type),

            'interest_rate' => $this->interest_rate,

            'repayment_frequency' => $this->repayment_frequency,
            'repayment_frequency_label' => $this->configLabel(
                'repayment_frequencies',
                $this->repayment_frequency
            ),

            'lpf_rate' => $this->lpf_rate,

            'platform_charges' => $this->platform_charges,
'sms_charges' => $this->sms_charges,
'minimum_loan_amount' => $this->minimum_loan_amount,
'maximum_loan_amount' => $this->maximum_loan_amount,
'minimum_pf' => $this->minimum_pf,
'maximum_pf' => $this->maximum_pf,
            'moratorium_days' => $this->moratorium_days,

            'calculation_method' => $this->calculation_method,
            'calculation_method_label' => $this->configLabel(
                'calculation_methods',
                $this->calculation_method
            ),

            'overdue_interest_on' => $this->overdue_interest_on,
            'overdue_interest_on_label' => $this->configLabel(
                'overdue_interest_on',
                $this->overdue_interest_on
            ),

            'emi_round_off_type' => $this->emi_round_off_type,
            'emi_round_off_type_label' => $this->configLabel(
                'emi_round_off_types',
                $this->emi_round_off_type
            ),

            'emi_round_up_value' => $this->emi_round_up_value,

            // 'tenure_value' => $this->tenure_value,
            // 'tenure_unit' => $this->tenure_unit,

            'tenure_type' => $this->tenure_type ?? 'fixed',

'tenure_value' => $this->tenure_value !== null
    ? (int) $this->tenure_value
    : null,

'minimum_tenure' => $this->minimum_tenure !== null
    ? (int) $this->minimum_tenure
    : null,

'maximum_tenure' => $this->maximum_tenure !== null
    ? (int) $this->maximum_tenure
    : null,

'tenure_unit' => $this->tenure_unit,
            'tenure_unit_label' => $this->configLabel('tenure_units', $this->tenure_unit),
            'tenure_label' => $this->tenure_value
                ? trim($this->tenure_value . ' ' . $this->configLabel('tenure_units', $this->tenure_unit))
                : null,

            'valid_from' => $this->valid_from?->format('Y-m-d'),
            'valid_till' => $this->valid_till?->format('Y-m-d'),

            'remarks' => $this->remarks,

            'is_active' => (bool) $this->is_active,
            'status_label' => $this->is_active ? 'Active' : 'Inactive',
      





            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,

            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'deleted_at' => $this->deleted_at?->toISOString(),
        ];
    }

    private function configLabel(string $group, ?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return config("product_configuration.{$group}.{$value}", $value);
    }
}