<?php

namespace Modules\BLApplicant\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class BLApplicantCollection extends ResourceCollection
{
     public function toArray(Request $request): array
    {
        return [
            'status' => true,
            'code' => 200,
            'request' => [
                'lead_uuid' => $request->route('uuid') ?? null,
            ],
            'data' => $this->collection->map(function ($item) use ($request) {
                return $item->toArray($request);
            }),
            'meta' => $this->resource instanceof LengthAwarePaginator
                ? [
                    'current_page' => $this->currentPage(),
                    'last_page' => $this->lastPage(),
                    'per_page' => $this->perPage(),
                    'total' => $this->total(),
                ]
                : null,
        ];
    }

    public function withResponse($request, $response): void
    {
        $response->setStatusCode(200);
    }
}
