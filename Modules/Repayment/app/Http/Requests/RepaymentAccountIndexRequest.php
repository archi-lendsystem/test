<?php

namespace Modules\Repayment\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Modules\Repayment\Enums\InstallmentDueStatus;
use Modules\Repayment\Enums\RepaymentAccountStatus;

class RepaymentAccountIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $data = [];

        if ($this->has('search')) {
            $data['search'] = trim(
                (string) $this->input('search')
            );
        }

        if ($this->filled('status')) {
            $data['status'] = strtoupper(
                trim((string) $this->input('status'))
            );
        }

        if ($this->filled('due_status')) {
            $data['due_status'] = strtoupper(
                trim((string) $this->input('due_status'))
            );
        }

        if ($this->filled('loan_type')) {
            $data['loan_type'] = strtoupper(
                trim((string) $this->input('loan_type'))
            );
        }

        if ($this->filled('loan_number')) {
            $data['loan_number'] = trim(
                (string) $this->input('loan_number')
            );
        }

        if ($this->filled('lead_uuid')) {
            $data['lead_uuid'] = trim(
                (string) $this->input('lead_uuid')
            );
        }

        if ($data !== []) {
            $this->merge($data);
        }
    }

    public function rules(): array
    {
        return [

            'branch_id' => 'nullable|integer|exists:organization_branches,id',
            /*
             * Generic search:
             *
             * loan number, lead UUID and future borrower details
             * against controller query mein apply hoga.
             */
            'search' => [
                'nullable',
                'string',
                'max:150',
            ],

            /*
             * Current implementation BL accounts ke liye hai.
             */
            'loan_type' => [
                'nullable',
                'string',
                Rule::in(['BL']),
            ],

            'loan_number' => [
                'nullable',
                'string',
                'max:50',
            ],

            'lead_uuid' => [
                'nullable',
                'uuid',
            ],

            /*
             * Repayment account lifecycle status.
             */
            'status' => [
                'nullable',
                Rule::enum(RepaymentAccountStatus::class),
            ],

            /*
             * Installment date status filter.
             *
             * Ye DPD filter nahi hai.
             */
            'due_status' => [
                'nullable',
                Rule::enum(InstallmentDueStatus::class),
            ],

            /*
             * Account due-date range filtering.
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
             * Pagination.
             */
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

            /*
             * Safe listing order.
             */
            'sort_by' => [
                'nullable',
                'string',
                Rule::in([
                    'created_at',
                    'loan_number',
                    'first_due_date',
                    'next_due_date',
                    'maturity_date',
                    'total_outstanding',
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
            'search.max' =>
            'Search text cannot be greater than 150 characters.',

            'loan_type.in' =>
            'Loan type must be BL.',

            'loan_number.max' =>
            'Loan number cannot be greater than 50 characters.',

            'lead_uuid.uuid' =>
            'Lead UUID must be a valid UUID.',

            'status.enum' =>
            'Invalid repayment account status selected.',

            'due_status.enum' =>
            'Invalid installment due status selected.',

            'from_date.date_format' =>
            'From date must be in YYYY-MM-DD format.',

            'to_date.date_format' =>
            'To date must be in YYYY-MM-DD format.',

            'to_date.after_or_equal' =>
            'To date must be equal to or after from date.',

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
            'Invalid sorting field selected.',

            'sort_direction.in' =>
            'Sort direction must be asc or desc.',
        ];
    }

    public function attributes(): array
    {
        return [
            'loan_type' => 'loan type',
            'loan_number' => 'loan number',
            'lead_uuid' => 'lead UUID',
            'status' => 'account status',
            'due_status' => 'installment due status',
            'from_date' => 'from date',
            'to_date' => 'to date',
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
