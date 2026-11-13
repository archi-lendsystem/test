<?php

namespace Modules\Repayment\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;

final class ValidateRepaymentImportRequest extends FormRequest
{
    public const TEMPLATE_VERSION = 3;
    private const MAX_ROWS = 1000;

    public function authorize(): bool { return true; }

    protected function prepareForValidation(): void
    {
        if ($this->filled('original_filename')) {
            $this->merge(['original_filename' => trim((string) $this->input('original_filename'))]);
        }
    }

    public function rules(): array
    {
        return [
            'template_version' => ['required', 'integer', 'in:' . self::TEMPLATE_VERSION],
            'original_filename' => ['required', 'string', 'max:255', 'ends_with:.xlsx,.XLSX'],
            'template_generated_at' => ['nullable', 'date'],
            'rows' => ['required', 'array', 'min:1', 'max:' . self::MAX_ROWS],
            'rows.*' => ['required', 'array'],
            'rows.*.row_number' => ['required', 'integer', 'min:2', 'max:1048576', 'distinct'],
            'rows.*.loan_number' => ['nullable'],
            'rows.*.amount_collected' => ['nullable'],
            'rows.*.collection_date' => ['nullable'],
            'rows.*.payment_mode' => ['nullable'],
            'rows.*.utr_number' => ['nullable'],
            'rows.*.penalty' => ['nullable'],
            'rows.*.bounce' => ['nullable'],
            'rows.*.remarks' => ['nullable'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'status' => false,
            'status_code' => 422,
            'request_code' => 'REQ-' . strtoupper(Str::random(8)) . '-' . now()->format('YmdHis'),
            'timestamp' => now()->toIso8601String(),
            'message' => 'Repayment bulk collection validation failed.',
            'errors' => $validator->errors(),
        ], 422));
    }
}




