<?php

namespace Modules\BLDocumentCheckList\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class BLDocumentGroupCollection extends ResourceCollection
{
    public function toArray(Request $request): array
    {
        return [
            'data' => BLDocumentGroupResource::collection($this->collection),
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