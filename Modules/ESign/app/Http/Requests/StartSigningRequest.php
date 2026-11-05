<?php

namespace Modules\ESign\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StartSigningRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'signer' => ['sometimes', 'array:name,email,mobile,identifier,metadata'],
            'signer.name' => ['required_with:signer', 'string', 'max:255'],
            'signer.email' => ['nullable', 'email:rfc', 'max:255'],
            'signer.mobile' => ['nullable', 'string', 'max:32'],
            'signer.identifier' => ['nullable', 'string', 'max:255'],
            'signer.metadata' => ['nullable', 'array'],
        ];
    }
}
