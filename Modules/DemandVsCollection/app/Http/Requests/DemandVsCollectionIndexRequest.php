<?php

namespace Modules\DemandVsCollection\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Modules\Repayment\Enums\RepaymentAccountStatus;

class DemandVsCollectionIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize incoming filter values before validation.
     */
    protected function prepareForValidation(): void
    {
        $normalized = [];

        if ($this->has('search')) {
            $normalized['search'] = trim(
                (string) $this->input('search')
            );
        }

        if ($this->filled('loan_number')) {
            $normalized['loan_number'] = trim(
                (string) $this->input('loan_number')
            );
        }

        if ($this->filled('loan_type')) {
            $normalized['loan_type'] = strtoupper(
                trim((string) $this->input('loan_type'))
            );
        }

        if ($this->filled('status')) {
            $normalized['status'] = strtoupper(
                trim((string) $this->input('status'))
            );
        }

        if ($this->filled('sort_direction')) {
            $normalized['sort_direction'] = strtolower(
                trim((string) $this->input('sort_direction'))
            );
        }

        /*
         * Default collection cut-off will be demand period's
         * ending date.
         */
        if (
            ! $this->filled('as_of_date')
            && $this->filled('to_date')
        ) {
            $normalized['as_of_date'] = $this->input('to_date');
        }

        if ($normalized !== []) {
            $this->merge($normalized);
        }
    }

    public function rules(): array
    {
        return [
            /*
             * Demand period.
             */
            'from_date' => [
                'required',
                'date_format:Y-m-d',
            ],

            'to_date' => [
                'required',
                'date_format:Y-m-d',
                'after_or_equal:from_date',
            ],

            /*
             * Collection will be calculated up to this date.
             */
            'as_of_date' => [
                'required',
                'date_format:Y-m-d',
                'after_or_equal:from_date',
                'before_or_equal:today',
            ],

            /*
             * Account filters.
             */
            'account_uuid' => [
                'nullable',
                'uuid',
            ],

            'loan_number' => [
                'nullable',
                'string',
                'max:60',
            ],

            'loan_type' => [
                'nullable',
                'string',
                Rule::in(['BL']),
            ],

            'status' => [
                'nullable',
                Rule::enum(RepaymentAccountStatus::class),
            ],

            /*
             * Generic search.
             *
             * Initially loan number and lead UUID par apply hoga.
             * Customer name/mobile baad mein relation mapping ke
             * saath add karenge.
             */
            'search' => [
                'nullable',
                'string',
                'max:150',
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
             * Safe server-side sorting.
             */
            'sort_by' => [
                'nullable',
                Rule::in([
                    'loan_number',
                    'repayment_date',
                    'total_demand',
                    'total_collected',
                    'total_pending',
                    'collection_efficiency',
                    'principal_outstanding',
                    'closed_at',
                    'created_at',
                ]),
            ],

            'sort_direction' => [
                'nullable',
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
            'from_date.required' =>
                'From date is required.',

            'from_date.date_format' =>
                'From date must be in YYYY-MM-DD format.',

            'to_date.required' =>
                'To date is required.',

            'to_date.date_format' =>
                'To date must be in YYYY-MM-DD format.',

            'to_date.after_or_equal' =>
                'To date must be equal to or after from date.',

            'as_of_date.required' =>
                'As-of date is required.',

            'as_of_date.date_format' =>
                'As-of date must be in YYYY-MM-DD format.',

            'as_of_date.after_or_equal' =>
                'As-of date cannot be before from date.',

            'as_of_date.before_or_equal' =>
                'As-of date cannot be in the future.',

            'account_uuid.uuid' =>
                'Repayment account UUID must be valid.',

            'loan_number.max' =>
                'Loan number cannot exceed 60 characters.',

            'loan_type.in' =>
                'Loan type must be BL.',

            'status.enum' =>
                'Invalid repayment account status selected.',

            'search.max' =>
                'Search text cannot exceed 150 characters.',

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
            'from_date' => 'from date',
            'to_date' => 'to date',
            'as_of_date' => 'as-of date',
            'account_uuid' => 'repayment account UUID',
            'loan_number' => 'loan number',
            'loan_type' => 'loan type',
            'status' => 'account status',
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