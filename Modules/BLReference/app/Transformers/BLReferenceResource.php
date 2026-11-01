<?php

namespace Modules\BLReference\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BLReferenceResource extends JsonResource
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
            'message'     => 'BL Reference retrieved successfully',

            'data' => [
                'id'              => $this->id,
            'reference_id'    => $this->id,
            'lead_uuid'       => $this->lead_uuid,
            'lead_id'         => $this->lead_id,
            'created_by'      => $this->created_by,
            'reference_sno'   => $this->reference_sno,
            'reference_types' => $this->reference_types,
            'created_at'      => $this->created_at,
            'updated_at'      => $this->updated_at,
            ],
        ];
    }

    public function withResponse($request, $response)
    {
        $response->setStatusCode(200);
    }
}
