<?php

namespace Modules\ProductScheme\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Product\Models\Product;

class StoreProductSchemeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'product_id' => [
                'required',
                'integer',
                Rule::exists('products', 'id')->whereNull('deleted_at'),
            ],
            'scheme_name' => ['nullable', 'string', 'max:255'],
            'sub_loan_type' => [
                'required',
                'string',
                Rule::in($this->configKeys('sub_loan_types')),
            ],
            'interest_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'repayment_frequency' => [
                'required',
                'string',
                Rule::in($this->configKeys('repayment_frequencies')),
            ],
            'lpf_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'platform_charges' => ['nullable', 'numeric', 'min:0'],
            'sms_charges' => ['nullable', 'numeric', 'min:0'],
            'minimum_loan_amount' => ['nullable', 'integer', 'min:0'],
            'maximum_loan_amount' => [
                'nullable',
                'integer',
                'min:0',
                'gte:minimum_loan_amount',
            ],
            'minimum_pf' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'maximum_pf' => [
                'nullable',
                'numeric',
                'min:0',
                'max:100',
                'gte:minimum_pf',
            ],
            'moratorium_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'calculation_method' => [
                'required',
                'string',
                Rule::in($this->configKeys('calculation_methods')),
            ],
            'overdue_interest_on' => [
                'required',
                'string',
                Rule::in($this->configKeys('overdue_interest_on')),
            ],
            'emi_round_off_type' => [
                'required',
                'string',
                Rule::in($this->configKeys('emi_round_off_types')),
            ],
            'emi_round_up_value' => ['nullable', 'numeric', 'min:0'],
            'tenure_type' => [
                'required',
                'string',
                Rule::in($this->configKeys('tenure_types')),
            ],
            'tenure_value' => [
                'exclude_unless:tenure_type,fixed',
                'required',
                'integer',
                'min:1',
                'max:600',
            ],
            'minimum_tenure' => [
                'exclude_unless:tenure_type,range',
                'required',
                'integer',
                'min:1',
                'max:600',
            ],
            'maximum_tenure' => [
                'exclude_unless:tenure_type,range',
                'required',
                'integer',
                'min:1',
                'max:600',
                'gte:minimum_tenure',
            ],
            'tenure_unit' => [
                'required',
                'string',
                Rule::in($this->configKeys('tenure_units')),
            ],
            'valid_from' => ['nullable', 'date'],
            'valid_till' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'remarks' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (
                $validator->errors()->has('product_id') ||
                $validator->errors()->has('sub_loan_type')
            ) {
                return;
            }

            $product = Product::query()->find($this->input('product_id'));

            if (! $product) {
                return;
            }

            $subLoanType = (string) $this->input('sub_loan_type');
            $allowed = $this->allowedSubLoanTypesForProduct($product);

            if (! in_array($subLoanType, $allowed, true)) {
                $validator->errors()->add(
                    'sub_loan_type',
                    $this->subLoanTypeMessage($product)
                );
            }
        });
    }

    public function messages(): array
    {
        return [
            'product_id.required' => 'Product is required.',
            'product_id.exists' => 'Selected product does not exist.',
            'sub_loan_type.required' => 'Sub loan type is required.',
            'sub_loan_type.in' => 'Selected sub loan type is invalid.',
            'interest_rate.required' => 'Interest rate is required.',
            'interest_rate.numeric' => 'Interest rate must be a valid number.',
            'interest_rate.max' => 'Interest rate cannot be greater than 100.',
            'repayment_frequency.required' => 'Repayment frequency is required.',
            'repayment_frequency.in' => 'Selected repayment frequency is invalid.',
            'calculation_method.required' => 'Calculation method is required.',
            'calculation_method.in' => 'Selected calculation method is invalid.',
            'overdue_interest_on.required' => 'Overdue interest on is required.',
            'overdue_interest_on.in' => 'Selected overdue interest option is invalid.',
            'emi_round_off_type.required' => 'EMI round off type is required.',
            'emi_round_off_type.in' => 'Selected EMI round off type is invalid.',
            'tenure_unit.required' => 'Tenure unit is required.',
            'tenure_unit.in' => 'Selected tenure unit is invalid.',
            'valid_till.after_or_equal' => 'End date must be after or equal to start date.',
            'minimum_pf.numeric' => 'Minimum PF must be a valid percentage.',
            'minimum_pf.min' => 'Minimum PF percentage cannot be less than 0.',
            'minimum_pf.max' => 'Minimum PF percentage cannot be greater than 100.',
            'maximum_pf.numeric' => 'Maximum PF must be a valid percentage.',
            'maximum_pf.min' => 'Maximum PF percentage cannot be less than 0.',
            'maximum_pf.max' => 'Maximum PF percentage cannot be greater than 100.',
            'maximum_pf.gte' => 'Maximum PF percentage must be greater than or equal to minimum PF percentage.',
            'tenure_type.required' => 'Tenure type is required.',
            'tenure_type.in' => 'Selected tenure type is invalid.',
            'tenure_value.required' => 'Tenure is required for fixed tenure type.',
            'tenure_value.integer' => 'Tenure must be a valid whole number.',
            'tenure_value.min' => 'Tenure must be at least 1.',
            'tenure_value.max' => 'Tenure cannot be greater than 600.',
            'minimum_tenure.required' => 'Minimum tenure is required for tenure range.',
            'minimum_tenure.integer' => 'Minimum tenure must be a valid whole number.',
            'minimum_tenure.min' => 'Minimum tenure must be at least 1.',
            'minimum_tenure.max' => 'Minimum tenure cannot be greater than 600.',
            'maximum_tenure.required' => 'Maximum tenure is required for tenure range.',
            'maximum_tenure.integer' => 'Maximum tenure must be a valid whole number.',
            'maximum_tenure.min' => 'Maximum tenure must be at least 1.',
            'maximum_tenure.max' => 'Maximum tenure cannot be greater than 600.',
            'maximum_tenure.gte' => 'Maximum tenure must be greater than or equal to minimum tenure.',
        ];
    }

    private function configKeys(string $key): array
    {
        return array_keys(config("product_configuration.{$key}", []));
    }

    private function allowedSubLoanTypesForProduct(Product $product): array
    {
        $all = $this->configKeys('sub_loan_types');
        $configured = config(
            'product_configuration.product_sub_loan_types.' . strtoupper((string) $product->code)
        );

        if (! is_array($configured) || $configured === []) {
            return $all;
        }

        return array_values(array_intersect($all, $configured));
    }

    private function subLoanTypeMessage(Product $product): string
    {
        $code = strtoupper((string) $product->code);

        if (in_array($code, ['LAP', 'HL'], true)) {
            return "Only Monthly Loan scheme can be created for {$product->name}.";
        }

        return "Selected sub loan type is not allowed for {$product->name}.";
    }
}

// namespace Modules\ProductScheme\Http\Requests;

// use Illuminate\Foundation\Http\FormRequest;
// use Illuminate\Validation\Rule;

// class StoreProductSchemeRequest extends FormRequest
// {
//     public function authorize(): bool
//     {
//         return true;
//     }

//     public function rules(): array
//     {
//         return [
//             'product_id' => [
//                 'required',
//                 'integer',
//                 Rule::exists('products', 'id')->whereNull('deleted_at'),
//             ],

//             'scheme_name' => [
//                 'nullable',
//                 'string',
//                 'max:255',
//             ],

//             'sub_loan_type' => [
//                 'required',
//                 'string',
//                 Rule::in($this->configKeys('sub_loan_types')),
//             ],

//             'interest_rate' => [
//                 'required',
//                 'numeric',
//                 'min:0',
//                 'max:100',
//             ],

//             'repayment_frequency' => [
//                 'required',
//                 'string',
//                 Rule::in($this->configKeys('repayment_frequencies')),
//             ],

//             'lpf_rate' => [
//                 'nullable',
//                 'numeric',
//                 'min:0',
//                 'max:100',
//             ],

//             'platform_charges' => [
//                 'nullable',
//                 'numeric',
//                 'min:0',
//             ],

//             'sms_charges' => [
//                 'nullable',
//                 'numeric',
//                 'min:0',
//             ],

//             'minimum_loan_amount' => [
//                 'nullable',
//                 'integer',
//                 'min:0',
//             ],

//             'maximum_loan_amount' => [
//                 'nullable',
//                 'integer',
//                 'min:0',
//                 'gte:minimum_loan_amount',
//             ],

//             'minimum_pf' => [
//                 'nullable',
//                 'numeric',
//                 'min:0',
//                 'max:100',
//             ],

//             'maximum_pf' => [
//                 'nullable',
//                 'numeric',
//                 'min:0',
//                 'max:100',
//                 'gte:minimum_pf',
//             ],

//             'moratorium_days' => [
//                 'nullable',
//                 'integer',
//                 'min:0',
//                 'max:365',
//             ],

//             'calculation_method' => [
//                 'required',
//                 'string',
//                 Rule::in($this->configKeys('calculation_methods')),
//             ],

//             'overdue_interest_on' => [
//                 'required',
//                 'string',
//                 Rule::in($this->configKeys('overdue_interest_on')),
//             ],

//             'emi_round_off_type' => [
//                 'required',
//                 'string',
//                 Rule::in($this->configKeys('emi_round_off_types')),
//             ],

//             'emi_round_up_value' => [
//                 'nullable',
//                 'numeric',
//                 'min:0',
//             ],

//             'tenure_type' => [
//                 'required',
//                 'string',
//                 Rule::in($this->configKeys('tenure_types')),
//             ],

//             'tenure_value' => [
//                 'exclude_unless:tenure_type,fixed',
//                 'required',
//                 'integer',
//                 'min:1',
//                 'max:600',
//             ],

//             'minimum_tenure' => [
//                 'exclude_unless:tenure_type,range',
//                 'required',
//                 'integer',
//                 'min:1',
//                 'max:600',
//             ],

//             'maximum_tenure' => [
//                 'exclude_unless:tenure_type,range',
//                 'required',
//                 'integer',
//                 'min:1',
//                 'max:600',
//                 'gte:minimum_tenure',
//             ],

//             'tenure_unit' => [
//                 'required',
//                 'string',
//                 Rule::in($this->configKeys('tenure_units')),
//             ],

//             'valid_from' => [
//                 'nullable',
//                 'date',
//             ],

//             'valid_till' => [
//                 'nullable',
//                 'date',
//                 'after_or_equal:valid_from',
//             ],

//             'remarks' => [
//                 'nullable',
//                 'string',
//                 'max:1000',
//             ],

//             'is_active' => [
//                 'sometimes',
//                 'boolean',
//             ],
//         ];
//     }

//     public function messages(): array
//     {
//         return [
//             'product_id.required' => 'Product is required.',
//             'product_id.exists' => 'Selected product does not exist.',

//             'sub_loan_type.required' => 'Sub loan type is required.',
//             'sub_loan_type.in' => 'Selected sub loan type is invalid.',

//             'interest_rate.required' => 'Interest rate is required.',
//             'interest_rate.numeric' => 'Interest rate must be a valid number.',
//             'interest_rate.max' => 'Interest rate cannot be greater than 100.',

//             'repayment_frequency.required' => 'Repayment frequency is required.',
//             'repayment_frequency.in' => 'Selected repayment frequency is invalid.',

//             'calculation_method.required' => 'Calculation method is required.',
//             'calculation_method.in' => 'Selected calculation method is invalid.',

//             'overdue_interest_on.required' => 'Overdue interest on is required.',
//             'overdue_interest_on.in' => 'Selected overdue interest option is invalid.',

//             'emi_round_off_type.required' => 'EMI round off type is required.',
//             'emi_round_off_type.in' => 'Selected EMI round off type is invalid.',

//             'tenure_value.required' => 'Tenure is required.',
//             'tenure_value.integer' => 'Tenure must be a valid number.',
//             'tenure_value.min' => 'Tenure must be at least 1.',

//             'tenure_unit.required' => 'Tenure unit is required.',
//             'tenure_unit.in' => 'Selected tenure unit is invalid.',

//             'valid_till.after_or_equal' => 'End date must be after or equal to start date.',

//             'minimum_pf.numeric' => 'Minimum PF must be a valid percentage.',
//             'minimum_pf.min' => 'Minimum PF percentage cannot be less than 0.',
//             'minimum_pf.max' => 'Minimum PF percentage cannot be greater than 100.',

//             'maximum_pf.numeric' => 'Maximum PF must be a valid percentage.',
//             'maximum_pf.min' => 'Maximum PF percentage cannot be less than 0.',
//             'maximum_pf.max' => 'Maximum PF percentage cannot be greater than 100.',
//             'maximum_pf.gte' => 'Maximum PF percentage must be greater than or equal to minimum PF percentage.',

//             'tenure_type.required' => 'Tenure type is required.',
//             'tenure_type.in' => 'Selected tenure type is invalid.',

//             'tenure_value.required' => 'Tenure is required for fixed tenure type.',
//             'tenure_value.integer' => 'Tenure must be a valid whole number.',
//             'tenure_value.min' => 'Tenure must be at least 1.',
//             'tenure_value.max' => 'Tenure cannot be greater than 600.',

//             'minimum_tenure.required' => 'Minimum tenure is required for tenure range.',
//             'minimum_tenure.integer' => 'Minimum tenure must be a valid whole number.',
//             'minimum_tenure.min' => 'Minimum tenure must be at least 1.',
//             'minimum_tenure.max' => 'Minimum tenure cannot be greater than 600.',

//             'maximum_tenure.required' => 'Maximum tenure is required for tenure range.',
//             'maximum_tenure.integer' => 'Maximum tenure must be a valid whole number.',
//             'maximum_tenure.min' => 'Maximum tenure must be at least 1.',
//             'maximum_tenure.max' => 'Maximum tenure cannot be greater than 600.',
//             'maximum_tenure.gte' => 'Maximum tenure must be greater than or equal to minimum tenure.',
//         ];
//     }

//     private function configKeys(string $key): array
//     {
//         return array_keys(config("product_configuration.{$key}", []));
//     }

//     private function configValues(string $key): array
//     {
//         return array_values(config("product_configuration.{$key}", []));
//     }
// }
