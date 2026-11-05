<?php

namespace Modules\Repayment\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;

class ConfirmRepaymentImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Require explicit confirmation intent before
     * financial posting starts.
     *
     * Expected JSON:
     *
     * {
     *     "confirm": true
     * }
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'confirm' => [
                'bail',
                'required',
                'accepted',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'confirm.required' =>
                'Explicit confirmation is required before posting repayment collections.',

            'confirm.accepted' =>
                'Please confirm the repayment collection import before posting.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'confirm' =>
                'repayment import confirmation',
        ];
    }

    /**
     * Return validation failures in the existing
     * Repayment API response format.
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
                    'Repayment import confirmation validation failed.',

                'errors' =>
                    $validator->errors(),
            ], 422)
        );
    }
}