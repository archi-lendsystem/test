<?php

namespace Modules\Reports\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LeadReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from_date' => [
                'nullable',
                'date_format:Y-m-d',
            ],

            'to_date' => [
                'nullable',
                'date_format:Y-m-d',
                'after_or_equal:from_date',
            ],

            'branch_id' => [
                'nullable',
                'integer',
            ],

            'created_by' => [
                'nullable',
                'integer',
            ],

            'loan_type' => [
                'nullable',
                'string',
                'max:100',
            ],

            'customer_type' => [
                'nullable',
                'string',
                'max:100',
            ],

            'search' => [
                'nullable',
                'string',
                'max:100',
            ],

            'per_page' => [
                'nullable',
                'integer',
                'min:10',
                'max:100',
            ],
        ];
    }

    public function filters(): array
    {
        return $this->safe()->only([
            'from_date',
            'to_date',
            'branch_id',
            'created_by',
            'loan_type',
            'customer_type',
            'search',
        ]);
    }
}