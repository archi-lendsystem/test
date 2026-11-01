<?php

namespace Modules\Repayment\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Modules\Repayment\Enums\PaymentMode;

class StoreRepaymentPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize user-provided payment values before validation.
     */
    protected function prepareForValidation(): void
    {
        $normalized = [];

        if ($this->filled('idempotency_key')) {
            $normalized['idempotency_key'] = trim(
                (string) $this->input('idempotency_key')
            );
        }

        if ($this->filled('payment_mode')) {
            $normalized['payment_mode'] = strtoupper(
                trim((string) $this->input('payment_mode'))
            );
        }

        if ($this->filled('transaction_reference')) {
            $normalized['transaction_reference'] = trim(
                (string) $this->input('transaction_reference')
            );
        }

        if ($this->filled('remarks')) {
            $normalized['remarks'] = trim(
                (string) $this->input('remarks')
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
             * Client-generated duplicate-request protection key.
             *
             * Do not add a database "unique" validation rule here.
             * Repeated requests must be handled idempotently by
             * the payment service.
             */
            'idempotency_key' => [
                'bail',
                'required',
                'string',
                'min:8',
                'max:100',
            ],

            /*
             * repayment_transactions.amount is decimal(18, 2).
             *
             * Maximum:
             * 16 integer digits + 2 decimal digits.
             */
            'amount' => [
                'bail',
                'required',
                'numeric',
                'decimal:0,2',
                'gt:0',
                'max:9999999999999999.99',
            ],

            'payment_mode' => [
                'bail',
                'required',
                Rule::enum(PaymentMode::class),
            ],

            /*
             * CASH may be recorded without an external reference.
             *
             * UPI, cheque, bank transfer and other non-cash modes
             * require an instrument or banking reference.
             */
            'transaction_reference' => [
                'bail',
                Rule::requiredIf(
                    function (): bool {
                        $paymentMode = PaymentMode::tryFrom(
                            (string) $this->input('payment_mode')
                        );

                        return $paymentMode?->requiresReference()
                            ?? false;
                    }
                ),
                'nullable',
                'string',
                'max:150',
            ],

            /*
             * Actual date on which payment was received.
             *
             * Future collection dates are not accepted because
             * this endpoint posts an already-received payment.
             */
            'received_date' => [
                'bail',
                'required',
                'date_format:Y-m-d',
                'before_or_equal:today',
            ],

            /*
             * Accounting-effective date.
             *
             * Value date cannot be before received date or in
             * the future for immediate payment posting.
             */
            'value_date' => [
                'bail',
                'required',
                'date_format:Y-m-d',
                'after_or_equal:received_date',
                'before_or_equal:today',
            ],

            'remarks' => [
                'nullable',
                'string',
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

            'amount.required' =>
                'Payment amount is required.',

            'amount.numeric' =>
                'Payment amount must be numeric.',

            'amount.decimal' =>
                'Payment amount can have maximum 2 decimal places.',

            'amount.gt' =>
                'Payment amount must be greater than zero.',

            'amount.max' =>
                'Payment amount exceeds the supported monetary limit.',

            'payment_mode.required' =>
                'Payment mode is required.',

            'payment_mode.enum' =>
                'Invalid payment mode selected.',

            'transaction_reference.required' =>
                'Transaction reference is required for the selected payment mode.',

            'transaction_reference.max' =>
                'Transaction reference cannot exceed 150 characters.',

            'received_date.required' =>
                'Received date is required.',

            'received_date.date_format' =>
                'Received date must be in YYYY-MM-DD format.',

            'received_date.before_or_equal' =>
                'Received date cannot be in the future.',

            'value_date.required' =>
                'Value date is required.',

            'value_date.date_format' =>
                'Value date must be in YYYY-MM-DD format.',

            'value_date.after_or_equal' =>
                'Value date cannot be before received date.',

            'value_date.before_or_equal' =>
                'Value date cannot be in the future.',

            'remarks.max' =>
                'Remarks cannot exceed 2000 characters.',
        ];
    }

    public function attributes(): array
    {
        return [
            'idempotency_key' => 'idempotency key',
            'amount' => 'payment amount',
            'payment_mode' => 'payment mode',
            'transaction_reference' => 'transaction reference',
            'received_date' => 'received date',
            'value_date' => 'value date',
            'remarks' => 'remarks',
        ];
    }

    /**
     * Return validation failures in the module API format.
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