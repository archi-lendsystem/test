<?php

namespace Modules\PropertyVisitReport\App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PropertyVisitReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        /*
         * POST  = all main fields required
         * PUT/PATCH = only sent fields validated
         */
        $required = $this->isMethod('post')
            ? 'required'
            : 'sometimes';

        return [

            // Lead UUID and product_id are NOT required here.
            // Backend gets them automatically.

            // ── GENERAL DETAILS ─────────────────────────────

            'pd_done_by' => [
                $required,
                'string',
                'max:255',
            ],

            'pd_date' => [
                $required,
                'date',
            ],

            'meeting_place' => [
                $required,
                'string',
                'max:255',
            ],

            'person_meet' => [
                $required,
                'string',
                'max:255',
            ],

            // ── RESIDENCE DETAILS ───────────────────────────

            'loan_purpose' => [
                $required,
                'string',
                'max:255',
            ],

            'residence_type' => [
                'sometimes',
                'nullable',
                'string',
                'max:255',
            ],

            'staying_together' => [
                'sometimes',
                'nullable',
                'string',
                'max:255',
            ],

            'years_at_residence' => [
                'sometimes',
                'nullable',
                'integer',
                'min:0',
            ],

            // ── PROPERTY DETAILS ────────────────────────────

            'property_type' => [
                $required,
                'string',
                'max:255',
            ],

            'property_sub_type' => [
                'sometimes',
                'nullable',
                'string',
                'max:255',
            ],

            'construction_status' => [
                $required,
                'string',
                'max:255',
            ],

            'age_of_property' => [
                'sometimes',
                'nullable',
                'integer',
                'min:0',
            ],

            'total_area' => [
                $required,
                'numeric',
                'min:0',
            ],

            'buildup_area' => [
                'sometimes',
                'nullable',
                'numeric',
                'min:0',
            ],

            'num_floors' => [
                'sometimes',
                'nullable',
                'integer',
                'min:0',
            ],

            'floor_number' => [
                'sometimes',
                'nullable',
                'integer',
                'min:0',
            ],

            // ── VALUATION ───────────────────────────────────

            'market_value' => [
                $required,
                'numeric',
                'min:0',
            ],

            'distress_value' => [
                'sometimes',
                'nullable',
                'numeric',
                'min:0',
            ],

            'circle_rate' => [
                'sometimes',
                'nullable',
                'numeric',
                'min:0',
            ],

            'loan_amount' => [
                $required,
                'numeric',
                'min:0',
            ],

            // ── LEGAL DETAILS ───────────────────────────────

            'ownership_type' => [
                $required,
                'string',
                'max:255',
            ],

            'legal_docs_verified' => [
                $required,
                'string',
                'max:255',
            ],

            'encumbrance_status' => [
                $required,
                'string',
                'max:255',
            ],

            'property_condition' => [
                $required,
                'string',
                'max:255',
            ],

            // ── LOCATION DETAILS ────────────────────────────

            'neighbourhood_type' => [
                'sometimes',
                'nullable',
                'string',
                'max:255',
            ],

            'road_width' => [
                'sometimes',
                'nullable',
                'numeric',
                'min:0',
            ],

            'distance_from_branch' => [
                'sometimes',
                'nullable',
                'numeric',
                'min:0',
            ],

            'pd_with_owner' => [
                $required,
                'string',
                'max:255',
            ],

            // ── REMARKS ─────────────────────────────────────

            'remarks' => [
                'sometimes',
                'nullable',
                'string',
            ],

            // ── MULTIPLE DOCUMENTS ──────────────────────────

            'documents' => [
                'sometimes',
                'nullable',
                'array',
                'max:10',
            ],

            'documents.*' => [
                'file',
                'mimes:pdf,jpg,jpeg,png,webp,doc,docx',
                'max:10240', // 10MB
            ],

            // ── MULTIPLE SITE IMAGES ────────────────────────

            'site_images' => [
                'sometimes',
                'nullable',
                'array',
                'max:10',
            ],

            'site_images.*' => [
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:5120', // 5MB
            ],

            // Indexes of documents to remove during update
            'remove_documents' => [
                'sometimes',
                'nullable',
                'array',
            ],

            'remove_documents.*' => [
                'integer',
                'min:0',
            ],

            // Indexes of images to remove during update
            'remove_site_images' => [
                'sometimes',
                'nullable',
                'array',
            ],

            'remove_site_images.*' => [
                'integer',
                'min:0',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'documents.max' =>
                'Maximum 10 documents are allowed.',

            'documents.*.mimes' =>
                'Document must be PDF, JPG, JPEG, PNG, WEBP, DOC or DOCX.',

            'documents.*.max' =>
                'Each document must not exceed 10 MB.',

            'site_images.max' =>
                'Maximum 10 site images are allowed.',

            'site_images.*.image' =>
                'Each site image must be a valid image.',

            'site_images.*.mimes' =>
                'Site image must be JPG, JPEG, PNG or WEBP.',

            'site_images.*.max' =>
                'Each site image must not exceed 5 MB.',
        ];
    }
}