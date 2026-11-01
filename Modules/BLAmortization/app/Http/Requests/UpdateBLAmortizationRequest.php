<?php

namespace Modules\BLAmortization\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class UpdateBLAmortizationRequest extends FormRequest
{
   public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
{
    if ($this->route('lead_uuid')) {
        $this->merge([
            'lead_uuid' => $this->route('lead_uuid'),
        ]);
    }
}
 
    // public function rules(): array
    // {
    //     return [
    //         'lead_uuid'           => ['sometimes', 'required', 'uuid', 'exists:b_l_lead_creations,uuid'],
    //         'loan_amount'         => ['sometimes', 'required', 'numeric', 'min:1', 'max:999999999999.99'],
    //         'interest_rate'       => ['sometimes', 'required', 'numeric', 'min:0', 'max:999.999'],
    //         'tenure'              => ['sometimes', 'required', 'numeric', 'min:0.01', 'max:9999.99'],
    //         'tenure_unit'         => ['sometimes', 'required', 'in:years,months'],
    //         'start_date'          => ['sometimes', 'required', 'date'],
    //         'processing_fee'      => ['sometimes', 'nullable', 'numeric', 'min:0'],
    //     ];
    // }

    public function rules(): array
{
    return [
        'lead_uuid' => [
            'sometimes',
            'required',
            'uuid',
            'exists:b_l_lead_creations,uuid',
        ],

        'loan_amount' => [
            'sometimes',
            'required',
            'numeric',
            'min:1',
            'max:999999999999.99',
        ],

        'interest_rate' => [
            'sometimes',
            'required',
            'numeric',
            'min:0',
            'max:999.999',
        ],

        'tenure' => [
            'sometimes',
            'required',
            'numeric',
            'min:0.01',
            'max:9999.99',
        ],

        'tenure_unit' => [
            'sometimes',
            'required',
            'string',
            'in:days,weeks,months,years',
        ],

        'frequency' => [
            'sometimes',
            'required',
            'string',
            'in:daily,weekly,bi_weekly,monthly,quarterly,half_yearly,yearly,one_time,bullet',
        ],

        'start_date' => [
            'sometimes',
            'required',
            'date',
        ],

        'processing_fee' => [
            'sometimes',
            'nullable',
            'numeric',
            'min:0',
        ],

        'calculation_method' => [
            'sometimes',
            'required',
            'string',
            'in:reducing_balance,flat_rate,daily_reducing',
        ],
    ];
}
 
    public function messages(): array
    {
        return [
            'lead_uuid.exists'            => 'The selected lead does not exist.',
            'loan_amount.min'             => 'Loan amount must be greater than 0.',
            'interest_rate.min'           => 'Interest rate cannot be negative.',
            'tenure.min'                  => 'Tenure must be greater than 0.',
            'tenure_unit.in'              => 'Tenure unit must be either years or months.',
            'frequency.in'                => 'Invalid repayment frequency selected.',
            'start_date.date'             => 'Loan start date must be a valid date.',
            'calculation_method.in'       => 'Invalid calculation method selected.',
        ];
    }
 
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'status'       => false,
            'status_code'  => 422,
            'request_code' => 'REQ-' . strtoupper(\Illuminate\Support\Str::random(8)) . '-' . now()->format('YmdHis'),
            'timestamp'    => now()->toIso8601String(),
            'message'      => 'Validation failed.',
            'errors'       => $validator->errors(),
        ], 422));
    }
}
