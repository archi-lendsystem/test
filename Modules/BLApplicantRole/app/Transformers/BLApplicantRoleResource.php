<?php

namespace Modules\BLApplicantRole\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BLApplicantRoleResource extends JsonResource
{
    public function __construct($resource)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        return [
           
                'id'             => $this->id,
                'lead_uuid'      => $this->lead_uuid,
                'applicant_id'   => $this->applicant_id,
                'lead_id'        => $this->lead_id,
                'role'           => $this->role,
                'type'           => $this->type,
                'sno'            => $this->sno,
                'created_at'     => $this->created_at?->toISOString(),
                'updated_at'     => $this->updated_at?->toISOString(),
            
        ];
    }

    public function withResponse($request, $response)
    {
        $response->setStatusCode(200);
    }
}
