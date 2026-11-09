<?php

namespace Modules\BLSchemeSelect\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;
use Illuminate\Contracts\Validation\Validator;


class StoreBLSchemeSelectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'product_scheme_uuid' => [
                'required',
                'uuid',
                'exists:product_schemes,uuid',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'product_scheme_uuid.required' => 'Product scheme UUID is required.',
            'product_scheme_uuid.uuid'     => 'Product scheme UUID must be a valid UUID.',
            'product_scheme_uuid.exists'   => 'Selected product scheme does not exist.',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            response()->json([
                'success'     => false,
                'message'     => 'Validation failed.',
                'status_code' => 422,
                'request_id'  => (string) Str::uuid(),
                'timestamp'   => now()->toISOString(),
                'errors'      => $validator->errors(),
            ], 422)
        );
    }
}
