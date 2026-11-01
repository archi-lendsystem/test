<?php

namespace Modules\Repayment\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;

class ReverseRepaymentPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize reversal inputs before validation.
     */
    protected function prepareForValidation(): void
    {
        $normalized = [];

        if ($this->filled('idempotency_key')) {
            $normalized['idempotency_key'] = trim(
                (string) $this->input('idempotency_key')
            );
        }

        if ($this->filled('reason')) {
            $normalized['reason'] = trim(
                (string) $this->input('reason')
            );
        }

        if ($normalized !== []) {
            $this->merge($normalized);
        }
    }

    public function rules(): array
    {
        return [
            /*
             * Duplicate reversal-request protection.
             *
             * Do not add a database unique validation rule.
             * Repeated requests must be handled idempotently
             * inside the reversal service.
             */
            'idempotency_key' => [
                'bail',
                'required',
                'string',
                'min:8',
                'max:100',
            ],

            /*
             * Business date on which reversal is recorded.
             */
            'reversal_date' => [
                'bail',
                'required',
                'date_format:Y-m-d',
                'before_or_equal:today',
            ],

            /*
             * Accounting-effective reversal date.
             */
            'value_date' => [
                'bail',
                'required',
                'date_format:Y-m-d',
                'after_or_equal:reversal_date',
                'before_or_equal:today',
            ],

            /*
             * Mandatory audit reason.
             */
            'reason' => [
                'bail',
                'required',
                'string',
                'min:5',
                'max:2000',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'idempotency_key.required' =>
                'Idempotency key is required.',

            'idempotency_key.min' =>
                'Idempotency key must contain at least 8 characters.',

            'idempotency_key.max' =>
                'Idempotency key cannot exceed 100 characters.',

            'reversal_date.required' =>
                'Reversal date is required.',

            'reversal_date.date_format' =>
                'Reversal date must be in YYYY-MM-DD format.',

            'reversal_date.before_or_equal' =>
                'Reversal date cannot be in the future.',

            'value_date.required' =>
                'Value date is required.',

            'value_date.date_format' =>
                'Value date must be in YYYY-MM-DD format.',

            'value_date.after_or_equal' =>
                'Value date cannot be before reversal date.',

            'value_date.before_or_equal' =>
                'Value date cannot be in the future.',

            'reason.required' =>
                'Reversal reason is required.',

            'reason.min' =>
                'Reversal reason must contain at least 5 characters.',

            'reason.max' =>
                'Reversal reason cannot exceed 2000 characters.',
        ];
    }

    public function attributes(): array
    {
        return [
            'idempotency_key' => 'idempotency key',
            'reversal_date' => 'reversal date',
            'value_date' => 'value date',
            'reason' => 'reversal reason',
        ];
    }

    /**
     * Return validation errors in Repayment API format.
     */
    protected function failedValidation(
        Validator $validator
    ): void {
        throw new HttpResponseException(
            response()->json([
                'status' => false,
                'status_code' => 422,
                'request_code' =>
                    'REQ-'
                    . strtoupper(Str::random(8))
                    . '-'
                    . now()->format('YmdHis'),
                'timestamp' => now()->toIso8601String(),
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422)
        );
    }
}