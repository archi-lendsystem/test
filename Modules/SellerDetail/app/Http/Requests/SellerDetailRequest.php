<?php

namespace Modules\SellerDetail\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\BLLeadCreation\Models\BLLeadCreation;

class SellerDetailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return BLLeadCreation::query()
            ->where('uuid', $this->route('lead_uuid'))
            ->where('product_id', 4)
            ->exists();
    }

    public function rules(): array
    {
        $isCreate = $this->isMethod('POST');
        $requiredOnCreate = $isCreate ? 'required' : 'sometimes';

        return [
            'seller_name' => [$requiredOnCreate, 'string', 'max:255'],
            'mobile_number' => [$requiredOnCreate, 'string', 'max:20'],
            'email' => [$requiredOnCreate, 'email', 'max:255'],
            'pan_number' => [
                $requiredOnCreate,
                'string',
                'size:10',
                'regex:/^[A-Z]{5}[0-9]{4}[A-Z]$/',
            ],
            'aadhar_number' => [
    'nullable',
    'string',
    'size:12',
    'regex:/^[0-9]{12}$/',
],

            'current_address' => [$requiredOnCreate, 'string'],
            'current_pincode' => [$requiredOnCreate, 'string', 'max:10'],
            'current_city' => [$requiredOnCreate, 'string', 'max:150'],
            'current_district' => [$requiredOnCreate, 'string', 'max:150'],
            'current_state' => [$requiredOnCreate, 'string', 'max:150'],
            'current_landmark' => [
    'nullable',
    'string',
    'max:255',
],

'current_staying_in_years' => [
    'nullable',
    'integer',
    'min:0',
    'max:100',
],

            'same_as_current_address' => [$requiredOnCreate, 'boolean'],

            'permanent_address' => [
                'nullable', 'required_if:same_as_current_address,0', 'string',
            ],
            'permanent_pincode' => [
                'nullable', 'required_if:same_as_current_address,0',
                'string', 'max:10',
            ],
            'permanent_city' => [
                'nullable', 'required_if:same_as_current_address,0',
                'string', 'max:150',
            ],
            'permanent_district' => [
                'nullable', 'required_if:same_as_current_address,0',
                'string', 'max:150',
            ],
            'permanent_state' => [
                'nullable', 'required_if:same_as_current_address,0',
                'string', 'max:150',
            ],

            'has_passport' => [$requiredOnCreate, 'boolean'],
            'passport_number' => [
                'nullable', 'required_if:has_passport,1',
                'string', 'max:30',
            ],

            'bank_account_holder_name' => [
                $requiredOnCreate, 'string', 'max:255',
            ],
            'bank_account_number' => [
                $requiredOnCreate, 'string', 'max:50',
            ],
            'bank_name' => [$requiredOnCreate, 'string', 'max:255'],
            'ifsc_code' => [
                $requiredOnCreate,
                'string',
                'size:11',
                'regex:/^[A-Z]{4}0[A-Z0-9]{6}$/',
            ],

            'pan_document' => [
                'nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120',
            ],
            'address_document' => [
                'nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120',
            ],
            'passport_file' => [
                $isCreate ? 'required_if:has_passport,1' : 'nullable',
                'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120',
            ],
            'bank_statement' => [
                'nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120',
            ],
            'passbook_image' => [
                'nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120',
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (['pan_number', 'ifsc_code', 'passport_number'] as $field) {
            if ($this->filled($field)) {
                $normalized[$field] = strtoupper((string) $this->input($field));
            }
        }

        $this->merge($normalized);
    }
}