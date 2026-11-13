<?php

namespace Modules\Repayment\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Modules\Repayment\Enums\InstallmentDueStatus;
use Modules\Repayment\Enums\InstallmentPaymentStatus;

class RepaymentInstallmentIndexRequest extends FormRequest
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

        if ($this->filled('payment_status')) {
            $normalized['payment_status'] = strtoupper(
                trim((string) $this->input('payment_status'))
            );
        }

        if ($this->filled('due_status')) {
            $normalized['due_status'] = strtoupper(
                trim((string) $this->input('due_status'))
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
             * UNPAID
             * PARTIAL
             * PAID
             */
            'payment_status' => [
                'nullable',
                Rule::enum(InstallmentPaymentStatus::class),
            ],

            /*
             * UPCOMING
             * DUE
             * OVERDUE
             *
             * DPD filtering intentionally absent.
             */
            'due_status' => [
                'nullable',
                Rule::enum(InstallmentDueStatus::class),
            ],

            /*
             * Installment due-date range.
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
             * Return only installments having outstanding amount.
             */
            'only_outstanding' => [
                'nullable',
                'boolean',
            ],

            /*
             * Include original amortization row when needed.
             */
            'with_snapshot' => [
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
                    'installment_number',
                    'due_date',
                    'installment_amount',
                    'outstanding_amount',
                    'payment_status',
                    'due_status',
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
            'payment_status.enum' =>
                'Invalid installment payment status selected.',

            'due_status.enum' =>
                'Invalid installment due status selected.',

            'from_date.date_format' =>
                'From date must be in YYYY-MM-DD format.',

            'to_date.date_format' =>
                'To date must be in YYYY-MM-DD format.',

            'to_date.after_or_equal' =>
                'To date must be equal to or after from date.',

            'only_outstanding.boolean' =>
                'Only outstanding must be true or false.',

            'with_snapshot.boolean' =>
                'With snapshot must be true or false.',

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
                'Invalid installment sorting field selected.',

            'sort_direction.in' =>
                'Sort direction must be asc or desc.',
        ];
    }

    public function attributes(): array
    {
        return [
            'payment_status' => 'payment status',
            'due_status' => 'due status',
            'from_date' => 'from date',
            'to_date' => 'to date',
            'only_outstanding' => 'only outstanding',
            'with_snapshot' => 'with snapshot',
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