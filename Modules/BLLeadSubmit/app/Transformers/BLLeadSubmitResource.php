<?php

namespace Modules\BLLeadSubmit\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BLLeadSubmitResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'lead_uuid'        => $this->lead_uuid,
            'lead_id'          => $this->whenLoaded('applicantDetails', function () {
                return $this->applicantDetails->first()?->lead_id;
            }),
            'customer_consent' => (bool) $this->customer_consent,
            'submitted_at'     => $this->created_at?->toDateTimeString(),

            'applicant_details' => $this->whenLoaded('applicantDetails', function () {
    return $this->applicantDetails->map(function ($applicant) {
        return array_merge(
            $applicant->toArray(),           
            [
                'created_at' => $applicant->created_at?->toDateTimeString(),
                'updated_at' => $applicant->updated_at?->toDateTimeString(),
            ]
        );
    })->values();
}),
        ];
    }
}