<?php

namespace Modules\Repayment\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Modules\Repayment\Enums\PaymentMode;
use Modules\Repayment\Enums\RepaymentTransactionStatus;
use Modules\Repayment\Enums\RepaymentTransactionType;

class RepaymentTransactionIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize case-insensitive query parameters.
     */
    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (
            [
                'transaction_type',
                'status',
                'payment_mode',
            ] as $field
        ) {
            if ($this->filled($field)) {
                $normalized[$field] = strtoupper(
                    trim((string) $this->input($field))
                );
            }
        }

        if ($this->filled('transaction_number')) {
            $normalized['transaction_number'] = trim(
                (string) $this->input('transaction_number')
            );
        }

        if ($this->filled('transaction_reference')) {
            $normalized['transaction_reference'] = trim(
                (string) $this->input('transaction_reference')
            );
        }

        if ($this->filled('sort_direction')) {
            $normalized['sort_direction'] = strtolower(
                trim((string) $this->input('sort_direction'))
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
             * PAYMENT
             * REVERSAL
             * REFUND
             * ADJUSTMENT
             */
            'transaction_type' => [
                'nullable',
                Rule::enum(RepaymentTransactionType::class),
            ],

            /*
             * PENDING
             * POSTED
             * REVERSED
             * FAILED
             */
            'status' => [
                'nullable',
                Rule::enum(RepaymentTransactionStatus::class),
            ],

            /*
             * CASH, CHEQUE, UPI, NEFT, RTGS, IMPS,
             * NACH, BANK_TRANSFER, PAYMENT_GATEWAY
             */
            'payment_mode' => [
                'nullable',
                Rule::enum(PaymentMode::class),
            ],

            'transaction_number' => [
                'nullable',
                'string',
                'max:60',
            ],

            'transaction_reference' => [
                'nullable',
                'string',
                'max:150',
            ],

            /*
             * Received-date range.
             */
            'from_date' => [
                'nullable',
                'date_format:Y-m-d',
            ],

            'to_date' => [
                'nullable',
                'date_format:Y-m-d',
                'after_or_equal:from_date',
            ],

            /*
             * Include supplemental transaction metadata.
             */
            'with_metadata' => [
                'nullable',
                'boolean',
            ],

            'page' => [
                'nullable',
                'integer',
                'min:1',
            ],

            'per_page' => [
                'nullable',
                'integer',
                'min:1',
                'max:100',
            ],

            'sort_by' => [
                'nullable',
                'string',
                Rule::in([
                    'transaction_number',
                    'transaction_type',
                    'amount',
                    'received_date',
                    'value_date',
                    'posted_at',
                    'status',
                    'created_at',
                ]),
            ],

            'sort_direction' => [
                'nullable',
                'string',
                Rule::in([
                    'asc',
                    'desc',
                ]),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'transaction_type.enum' =>
                'Invalid repayment transaction type selected.',

            'status.enum' =>
                'Invalid repayment transaction status selected.',

            'payment_mode.enum' =>
                'Invalid payment mode selected.',

            'transaction_number.max' =>
                'Transaction number cannot be greater than 60 characters.',

            'transaction_reference.max' =>
                'Transaction reference cannot be greater than 150 characters.',

            'from_date.date_format' =>
                'From date must be in YYYY-MM-DD format.',

            'to_date.date_format' =>
                'To date must be in YYYY-MM-DD format.',

            'to_date.after_or_equal' =>
                'To date must be equal to or after from date.',

            'with_metadata.boolean' =>
                'With metadata must be true or false.',

            'page.integer' =>
                'Page must be an integer.',

            'page.min' =>
                'Page must be at least 1.',

            'per_page.integer' =>
                'Per page must be an integer.',

            'per_page.min' =>
                'Per page must be at least 1.',

            'per_page.max' =>
                'Per page cannot be greater than 100.',

            'sort_by.in' =>
                'Invalid transaction sorting field selected.',

            'sort_direction.in' =>
                'Sort direction must be asc or desc.',
        ];
    }

    public function attributes(): array
    {
        return [
            'transaction_type' => 'transaction type',
            'status' => 'transaction status',
            'payment_mode' => 'payment mode',
            'transaction_number' => 'transaction number',
            'transaction_reference' => 'transaction reference',
            'from_date' => 'from date',
            'to_date' => 'to date',
            'with_metadata' => 'with metadata',
            'per_page' => 'per page',
            'sort_by' => 'sorting field',
            'sort_direction' => 'sort direction',
        ];
    }

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