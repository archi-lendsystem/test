<?php

namespace Modules\Transunion\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Route;

class TransunionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $response = $this->response_json ?? [];

        if (is_string($response)) {
            $response = json_decode($response, true) ?? [];
        }

        if (! is_array($response)) {
            $response = [];
        }

        data_forget($response, 'data.report_pdf_base64');

        return [
            'uuid' => $this->uuid,
            'report_uuid' => $this->uuid,

            'lead_uuid' => $this->lead_uuid,
            'lead_id' => $this->lead_id,

            'applicant_id' => $this->applicant_id,
            'applicant_sno' => $this->applicant_sno,
            'applicant_type' => $this->applicant_type,

            'full_name' => $this->full_name,
            'mobile_number' => $this->maskedMobileNumber(),
            'pan_card_number' => $this->maskedPan(),
            'date_of_birth' => $this->date_of_birth?->format('Y-m-d'),

            'reference_key' => $this->reference_key,
            'request_id' => $this->request_id,
            'product_code' => $this->product_code,

            'status' => $this->status,
            'verification_status' => $this->status,
            'http_status' => $this->http_status,
            'bureau_score' => $this->bureau_score,
            'environment' => $this->environment,
            'billable' => (bool) $this->billable,
            'reconciliation_status' => $this->reconciliation_status,
            'error_message' => $this->error_message,

            'consent' => (bool) $this->consent,
            'consented_at' => $this->consented_at?->toISOString(),

            'response_json' =>
                $responseForFrontend,

            /*
             * PDF.
             */
            'pdf' => [
                'generated' => ! empty($this->pdf_path),
                'generated_at' => $this->pdf_generated_at?->toISOString(),
                'url' => $this->pdf_path && Route::has('api.transunion.reports.pdf')
                    ? route('api.transunion.reports.pdf', [
                        'transunion' => $this->uuid,
                    ])
                    : null,
            ],

            'response_json' => $response,

            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

    protected function maskedMobileNumber(): ?string
    {
        if (! $this->mobile_number) {
            return null;
        }

        return str_repeat('*', max(0, strlen($this->mobile_number) - 4))
            . substr($this->mobile_number, -4);
    }

    protected function maskedPan(): ?string
    {
        if (! $this->pan_card_number) {
            return null;
        }

        return substr($this->pan_card_number, 0, 2)
            . str_repeat('*', max(0, strlen($this->pan_card_number) - 3))
            . substr($this->pan_card_number, -1);
    }
}
