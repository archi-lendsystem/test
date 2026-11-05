<?php

namespace Modules\ESign\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class RegisterAgreementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_lending_id' => ['required', 'integer', 'min:1', 'exists:customer_lendings,customer_id'],
            'original_document_disk' => [
                'nullable',
                'string',
                Rule::in(array_keys((array) config('filesystems.disks', []))),
            ],
            'original_document_path' => ['required', 'string', 'max:1024', 'not_regex:/\.\./'],
            'metadata' => ['sometimes', 'array'],
        ];
    }
}
