<?php

namespace Modules\Experian\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Route;

class ExperianResource extends JsonResource
{
    public function toArray(
        Request $request
    ): array {
        return [
            /*
             * Report
             */
            'uuid' =>
                $this->uuid,

            /*
             * Lead
             */
            'lead_uuid' =>
                $this->lead_uuid,

            'lead_id' =>
                $this->lead_id,

            /*
             * Applicant Mapping
             */
            'applicant_id' =>
                $this->applicant_id,

            'applicant_sno' =>
                $this->applicant_sno,

            'applicant_type' =>
                $this->applicant_type,

            /*
             * Applicant Snapshot
             */
            'applicant_name' =>
                $this->applicant_name,

            'pan_card_number' =>
                $this->pan_card_number,

            'mobile_number' =>
                $this->mobile_number,

            'date_of_birth' =>
                $this
                    ->date_of_birth
                    ?->format(
                        'Y-m-d'
                    ),

            /*
             * Result
             */
            'status' =>
                $this->status,

            'bureau_score' =>
                $this->bureau_score,

            'reference_key' =>
                $this->reference_key,

            'request_id' =>
                $this->request_id,

            'environment' =>
                $this->environment,

            'billable' =>
                $this->billable,

            'http_status' =>
                $this->http_status,

            'error_message' =>
                $this->error_message,

            /*
             * Stored request / response
             *
             * report_pdf_base64 is not stored.
             */
            'request_payload' =>
                $this->request_payload,

            /*
             * Experian provider response.
             */
            'response_payload' =>
                $this->response_payload,

            /*
             * PDF
             */
            'pdf_download_url' =>
                Route::has(
                    'api.experian.reports.pdf'
                )
                    ? route(
                        'api.experian.reports.pdf',
                        [
                            'uuid' =>
                                $this->uuid,
                        ]
                    )
                    : null,

            /*
             * Dates
             */
            'created_at' =>
                $this
                    ->created_at
                    ?->toISOString(),

            'updated_at' =>
                $this
                    ->updated_at
                    ?->toISOString(),
        ];
    }
}