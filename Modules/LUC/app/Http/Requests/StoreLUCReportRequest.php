<?php

namespace Modules\LUC\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLUCReportRequest extends FormRequest
{
     public function authorize(): bool
{
    return $this->user() !== null;
}

    protected function prepareForValidation(): void
    {
        $this->merge([
            'utilization_status' => $this->filled('utilization_status')
                ? strtolower(trim((string) $this->input('utilization_status')))
                : null,
            'note_code' => $this->filled('note_code')
                ? strtolower(trim((string) $this->input('note_code')))
                : null,
        ]);
    }

    public function rules(): array
    {
        $status = (string) $this->input('utilization_status');
        $noteCodes = array_keys(config("luc.note_options.{$status}", []));

        return [
            'utilization_status' => [
                'required',
                'string',
                Rule::in(config('luc.utilization_statuses', [])),
            ],
            'note_code' => [
                'required',
                'string',
                Rule::in($noteCodes),
            ],
            'note_details' => [
                Rule::requiredIf($this->input('note_code') === 'other'),
                'nullable',
                'string',
                'max:2000',
            ],
            'photo' => [
                'required',
                'file',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:5120',
            ],
            'remarks' => ['nullable', 'string', 'max:3000'],
            'date_of_luc' => ['required', 'date', 'before_or_equal:today'],
        ];
    }

    public function messages(): array
    {
        return [
            'photo.required' => 'A utilization verification photo is mandatory.',
            'note_code.in' => 'The selected note is not valid for this utilization status.',
            'note_details.required' => 'Please enter note details when Other is selected.',
        ];
    }
}
