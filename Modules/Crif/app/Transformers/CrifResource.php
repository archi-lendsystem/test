<?php

namespace Modules\Crif\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Route;

class CrifResource extends JsonResource
{
    /**
     * Convert the CRIF report into an API response.
     */
    public function toArray(
        Request $request
    ): array {
        $data = [
            /*
             * CRIF report identity.
             */
            'uuid' =>
                $this->uuid,

            /*
             * BL lead mapping.
             */
            'lead_uuid' =>
                $this->lead_uuid,

            'lead_id' =>
                $this->lead_id,

                'applicant_id' =>
    $this->applicant_id,

'applicant_sno' =>
    $this->applicant_sno,

'applicant_type' =>
    $this->applicant_type,

            /*
            * BL Applicant Details linkage.
            */
            'applicant_id' =>
                $this->applicant_id,

            'applicant_type' =>
                $this->applicant_type,

            /*
             * Applicant information.
             */
            'full_name' =>
                $this->full_name,

            'mobile_number' =>
                $this->maskedMobileNumber(),

            'pan_card_number' =>
                $this->maskedPan(),

                'date_of_birth' =>
    $this
        ->date_of_birth
        ?->format('Y-m-d'),

            /*
             * Consent information.
             */
            'consent' =>
                (bool) $this->consent,

            'consent_message' =>
                $this->consent_message,

            'consent_given_at' =>
                $this->consent_given_at?->toISOString(),

            /*
             * CRIF environment.
             */
            'environment' => config(
                'crif.environment',
                'uat'
            ),

            /*
             * Verification result.
             */
            'verification_status' =>
                $this->verification_status,

            'bureau_score' =>
                $this->bureau_score,

            'reference_key' =>
                $this->reference_key,

            'vendor_request_id' =>
                $this->vendor_request_id,

            'http_status_code' =>
                $this->http_status_code,

            /*
             * Error information.
             */
            'error_code' =>
                $this->error_code,

            'error_message' =>
                $this->error_message,

            /*
             * CRIF report data.
             */
            'report' => data_get(
                $this->response_payload,
                'data.report'
            ),

            /*
             * PDF download URL.
             */
 'pdf_download_url' =>
    Route::has('api.crif.reports.pdf')
        ? route(
            'api.crif.reports.pdf',
            [
                'uuid' => $this->uuid,
            ]
        )
        : null,

            /*
             * Timestamps.
             */
            'requested_at' =>
                $this->requested_at?->toISOString(),

            'completed_at' =>
                $this->completed_at?->toISOString(),

            'created_at' =>
                $this->created_at?->toISOString(),

            'updated_at' =>
                $this->updated_at?->toISOString(),
        ];

        /*
         * Raw CRIF response is returned only when enabled.
         *
         * Keep CRIF_EXPOSE_RAW_RESPONSE=false in production.
         */
        if (
            config(
                'crif.expose_raw_response',
                false
            )
        ) {
            $data['vendor_response'] =
                $this->response_payload;
        }

        return $data;
    }

    /**
     * Mask the mobile number except for the final four digits.
     *
     * Example:
     * 9876543210 becomes ******3210
     */
    protected function maskedMobileNumber(): ?string
    {
        if (!$this->mobile_number) {
            return null;
        }

        return str_repeat(
            '*',
            max(
                0,
                strlen($this->mobile_number) - 4
            )
        ) . substr(
            $this->mobile_number,
            -4
        );
    }

    /**
     * Mask the middle part of the PAN.
     *
     * Example:
     * ABCDE1234F becomes AB*******F
     */
    protected function maskedPan(): ?string
    {
        if (!$this->pan_card_number) {
            return null;
        }

        return substr(
            $this->pan_card_number,
            0,
            2
        )
            . str_repeat(
                '*',
                max(
                    0,
                    strlen($this->pan_card_number) - 3
                )
            )
            . substr(
                $this->pan_card_number,
                -1
            );
    }
}