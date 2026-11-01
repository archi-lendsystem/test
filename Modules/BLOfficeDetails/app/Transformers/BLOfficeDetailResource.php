<?php

namespace Modules\BLOfficeDetails\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BLOfficeDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
           
            'status_code' => 200,
            'request_id'  => app('request_id') ?? null,
            'timestamp'   => now()->toISOString(),
         

            'data' => [
                'id'                => $this->id,
                'lead_uuid'         => $this->lead_uuid,
                'lead_id'           => $this->lead_id,
                'applicant_id'      => $this->applicant_id,
                'applicant_sno'     => $this->applicant_sno,
                'applicant_type'    => $this->applicant_type,
                'created_by'        => $this->created_by,
                'address'           => $this->address,
                'landmark'          => $this->landmark,
                'pincode'           => $this->pincode,
                'district'          => $this->district,
                'city'              => $this->city,
                'state'             => $this->state,
                'working_in_months' => $this->working_in_months,

                'applicant' => [
                    'id'   => $this->applicant_id,
                    'sno'  => $this->applicant_sno,
                    'type' => $this->applicant_type,
                ],

                'office_address' => [
                    'address'  => $this->address,
                    'landmark' => $this->landmark,
                    'pincode'  => $this->pincode,
                    'district' => $this->district,
                    'city'     => $this->city,
                    'state'    => $this->state,
                ],

                'creator' => $this->whenLoaded('creator', fn () => [
                    'id'   => $this->creator->id,
                    'name' => $this->creator->name,
                ]),

                'created_at' => $this->created_at?->toISOString(),
                'updated_at' => $this->updated_at?->toISOString(),
            ],
        ];
    }

    public function withResponse($request, $response)
    {
        $response->setStatusCode(200);
    }
}
