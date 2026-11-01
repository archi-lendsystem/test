<?php

namespace Modules\Customers\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                       => $this->id,
            'customer_application_id'  => $this->customer_application_id,
            'full_name'                => $this->full_name,
            'mobile_number'            => $this->mobile_number,
            'pan_card_number'          => $this->pan_card_number,
            'aadhaar_card_number'      => $this->aadhaar_card_number,
            'email_address'            => $this->email_address,
            'total_loans'              => $this->whenCounted('leads'),
            'created_at'               => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at'               => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
}