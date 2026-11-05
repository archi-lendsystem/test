<?php

namespace Modules\BLInsurance\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BLInsuranceResource extends JsonResource
{
   public function toArray(Request $request): array
    {
        return [
            'id'                        => $this->id,
            'applicant_name'            => $this->applicant_name,
            'loan_reference'            => $this->loan_reference,
            'loan_amount'               => $this->loan_amount,
            'tenure'                    => $this->tenure,
            'age'                       => $this->age,
            'rate'                      => $this->rate,
            'multiplier'                => $this->multiplier,
            'premium_amount_before_gst' => $this->premium_amount_before_gst,
            'gst_amount'                => $this->gst_amount,
            'premium_amount_after_gst'  => $this->premium_amount_after_gst,
            'created_at'                => $this->created_at?->toDateTimeString(),
            'updated_at'                => $this->updated_at?->toDateTimeString(),
        ];
    }
}
