<?php

namespace Modules\BLAmortization\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreBLAmortizationRequest extends FormRequest
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

    public function rules(): array
    {
        return [
            'lead_uuid'           => ['required', 'uuid', 'exists:b_l_lead_creations,uuid'],

            'loan_amount'         => ['nullable', 'numeric', 'min:1', 'max:999999999999.99'],
            'interest_rate'       => ['nullable', 'numeric', 'min:0', 'max:999.999'],
            'tenure'              => ['nullable', 'numeric', 'min:0.01', 'max:9999.99'],
            'tenure_unit'         => ['nullable', 'in:years,months'],
            'frequency' => [
                'nullable',
                'string',
                'in:daily,weekly,bi_weekly,monthly,quarterly,half_yearly,yearly,one_time,bullet',
            ],
            'first_emi_date' => [
                'nullable',
                'date_format:Y-m-d',
            ],
            'start_date'          => ['nullable', 'date'],
            'processing_fee'      => ['nullable', 'numeric', 'min:0'],
            'calculation_method' => [
                'nullable',
                'string',
                'in:reducing_balance,flat_rate,daily_reducing',
            ],

        ];
    }

    public function messages(): array
    {
        return [
            'lead_uuid.required'          => 'Lead UUID is required.',
            'lead_uuid.exists'            => 'The selected lead does not exist.',
            'loan_amount.required'        => 'Loan amount is required.',
            'loan_amount.min'             => 'Loan amount must be greater than 0.',
            'interest_rate.required'      => 'Interest rate is required.',
            'interest_rate.min'           => 'Interest rate cannot be negative.',
            'tenure.required'             => 'Loan tenure is required.',
            'tenure.min'                  => 'Tenure must be greater than 0.',
            'tenure_unit.in'              => 'Tenure unit must be either years or months.',
            'frequency.in'                => 'Invalid repayment frequency selected.',
            'start_date.required'         => 'Loan start date is required.',
            'start_date.date'             => 'Loan start date must be a valid date.',
            'processing_fee.lt'           => 'Processing fee cannot exceed the loan amount.',
            'calculation_method.required' => 'Calculation method is required.',
            'calculation_method.in'       => 'Invalid calculation method selected.',
            'first_emi_date.date_format' =>
            'First EMI date must be in YYYY-MM-DD format.',

            'tenure_unit.in' =>
            'Tenure unit must be days, weeks, months, or years.',

            'frequency.in' =>
            'Repayment frequency must be daily, weekly, bi-weekly, monthly, quarterly, half-yearly, yearly, one-time, or bullet.',

            'calculation_method.in' =>
            'Calculation method must be reducing balance, flat rate, or daily reducing.',
        ];
    }

    public function attributes(): array
    {
        return [
            'lead_uuid'          => 'lead',
            'loan_amount'        => 'loan amount',
            'interest_rate'      => 'interest rate',
            'tenure_unit'        => 'tenure unit',
            'tenure'              => 'loan tenure',
            'frequency'           => 'repayment frequency',
            'calculation_method'  => 'calculation method',
            'start_date'         => 'start date',
            'processing_fee'     => 'processing fee',
            'calculation_method' => 'calculation method',
            'first_emi_date' => 'first EMI date',
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
