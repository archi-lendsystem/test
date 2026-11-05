<?php

namespace Modules\Crif\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCrifRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $leadUuid = $this->input('lead_uuid');

        $this->merge([
            'lead_uuid' => is_string($leadUuid)
                ? trim($leadUuid)
                : $leadUuid,
        ]);
    }

    public function rules(): array
    {
        return [
            'lead_uuid' => [
                'required',
                'uuid',

                Rule::exists(
                    'b_l_lead_creations',
                    'uuid'
                )->whereNull('deleted_at'),
            ],

            /*
             * applicant_id must belong to the same lead.
             */
            'applicant_id' => [
                'required',
                'integer',

                Rule::exists(
                    'b_l_applicants',
                    'id'
                )->where(
                    function ($query) {
                        $query->where(
                            'lead_uuid',
                            $this->input('lead_uuid')
                        );
                    }
                ),
            ],

            'consent' => [
                'required',
                'accepted',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'lead_uuid.required' =>
                'Lead UUID is required.',

            'lead_uuid.uuid' =>
                'Lead UUID must be a valid UUID.',

            'lead_uuid.exists' =>
                'The selected lead does not exist.',

            'applicant_id.required' =>
                'Applicant ID is required.',

            'applicant_id.integer' =>
                'Applicant ID must be valid.',

            'applicant_id.exists' =>
                'The selected applicant does not belong to this lead.',

            'consent.required' =>
                'Applicant consent is required.',

            'consent.boolean' =>
                'Applicant consent must be valid.',

            'consent.accepted' =>
                'Applicant consent must be accepted.',
        ];
    }
}