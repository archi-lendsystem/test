<?php

namespace Modules\BLReferenceDetails\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BLReferenceDetailsResource extends JsonResource
{
    public function __construct($resource)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        return [
            'success'     => true,
            'status_code' => 200,
            'request_id'  => app('request_id') ?? null,
            'timestamp'   => now()->toISOString(),
            'message'     => 'BL Reference Detail retrieved successfully',

            'data' => [
                'id'             => $this->id,
                'reference_id'   => $this->reference_id,
                'lead_uuid'      => $this->lead_uuid,
                'lead_id'        => $this->lead_id,
                'reference_sno'  => $this->reference_sno,
                'reference_type' => $this->reference_type,

                'full_name'      => $this->full_name,
                'mobile_no'      => $this->mobile_no,
                'email'          => $this->email,

                'gender'         => $this->gender,
                'relationship'   => $this->relationship,
                'address'        => $this->address,
                'pincode'        => $this->pincode,
                'district'       => $this->district,
                'city'           => $this->city,
                'state'          => $this->state,
                'status'         => $this->status,
                'remarks'        => $this->remarks,

                'created_at'     => $this->created_at,
                'updated_at'     => $this->updated_at,
            ],
        ];
    }

    public function withResponse($request, $response)
    {
        $response->setStatusCode(200);
    }
}
