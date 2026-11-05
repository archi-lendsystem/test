<?php

namespace Modules\Customers\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;

class CustomerCollection extends ResourceCollection
{
    public function toArray(Request $request): array
    {
        return [
            'status' => true,
            'code'   => 200,
            'data'   => $this->collection->map(fn ($item) => $item->toArray($request)),
            'meta'   => $this->resource instanceof LengthAwarePaginator
                ? [
                    'current_page' => $this->currentPage(),
                    'last_page'    => $this->lastPage(),
                    'per_page'     => $this->perPage(),
                    'total'        => $this->total(),
                ]
                : null,
        ];
    }

    public function withResponse($request, $response): void
    {
        $response->setStatusCode(200);
    }
}