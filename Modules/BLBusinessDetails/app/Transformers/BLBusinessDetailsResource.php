<?php

namespace Modules\BLBusinessDetails\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BLBusinessDetailsResource extends JsonResource
{
    public function __construct($resource)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        return [
            

                'id'                         => $this->id,
                'lead_uuid'                  => $this->lead_uuid,
                'lead_id'                    => $this->lead_id,
                'applicant_id'               => $this->applicant_id,
                'applicant_sno'              => $this->applicant_sno,
                'applicant_type'             => $this->applicant_type,
                'created_by'                 => $this->created_by,
                'business_name'              => $this->business_name,
                'nature_of_business'         => $this->nature_of_business,
                'incorporation_date'         => $this->incorporation_date,
                'pan_number'                 => $this->pan_number,
                'monthly_sales'              => $this->monthly_sales,
                'office_address'             => $this->office_address,
                'pincode'                    => $this->pincode,
                'district'                   => $this->district,
                'city'                       => $this->city,
                'state'                      => $this->state,
                'business_premise_ownership' => $this->business_premise_ownership,
                'created_at'                 => $this->created_at?->toISOString(),
                'updated_at'                 => $this->updated_at?->toISOString(),
            
        ];
    }

    public function withResponse($request, $response)
    {
        $response->setStatusCode(200);
    }
}
