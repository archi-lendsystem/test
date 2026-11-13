<?php

namespace Modules\Repayment\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;

class RepaymentImportTemplateRequest extends FormRequest
{
    /**
     * Maximum accounts allowed in one template response.
     */
    private const MAX_ROWS = 1000;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize query-string values.
     */
    protected function prepareForValidation(): void
    {
        $normalized = [];

        if ($this->filled('search')) {
            $normalized['search'] = trim(
                (string) $this->input('search')
            );
        }

        if ($this->has('limit')) {
            $normalized['limit'] =
                $this->input('limit');
        }

        if ($normalized !== []) {
            $this->merge($normalized);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => [
                'nullable',
                'string',
                'max:100',
            ],

            'limit' => [
                'nullable',
                'integer',
                'min:1',
                'max:' . self::MAX_ROWS,
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'search.string' =>
                'Template search value is invalid.',

            'search.max' =>
                'Template search value cannot exceed 100 characters.',

            'limit.integer' =>
                'Template row limit must be a valid integer.',

            'limit.min' =>
                'Template row limit must be at least 1.',

            'limit.max' =>
                'Template row limit cannot exceed '
                . self::MAX_ROWS
                . '.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'search' =>
                'template search',

            'limit' =>
                'template row limit',
        ];
    }

    /**
     * Return validation failures in existing Repayment
     * API response format.
     */
    protected function failedValidation(
        Validator $validator
    ): void {
        throw new HttpResponseException(
            response()->json([
                'status' =>
                    false,

                'status_code' =>
                    422,

                'request_code' =>
                    'REQ-'
                    . strtoupper(
                        Str::random(8)
                    )
                    . '-'
                    . now()->format('YmdHis'),

                'timestamp' =>
                    now()->toIso8601String(),

                'message' =>
                    'Repayment import template request validation failed.',

                'errors' =>
                    $validator->errors(),
            ], 422)
        );
    }

    public static function maxRows(): int
    {
        return self::MAX_ROWS;
    }
}