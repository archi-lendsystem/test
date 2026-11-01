<?php

namespace Modules\BLApplicantRoleDetails\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class BLApplicantRoleDetailsCollection extends ResourceCollection
{
    public function toArray(Request $request): array
    {
        return [
            'success'      => true,
            'status_code'  => 200,
            'request_id'   => app('request_id') ?? null,
            'timestamp'    => now()->toDateTimeString(),

            'message' => 'BL Applicant Role Details retrieved successfully',

            'data' => BLApplicantRoleDetailsResource::collection($this->collection),

            'meta' => [
                'current_page' => $this->currentPage(),
                'last_page'    => $this->lastPage(),
                'per_page'     => $this->perPage(),
                'total'        => $this->total(),
            ],
        ];
    }

    public function withResponse($request, $response)
    {
        $response->setStatusCode(200);
    }
}
