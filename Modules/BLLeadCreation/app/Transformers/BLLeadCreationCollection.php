<?php

namespace Modules\BLLeadCreation\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class BLLeadCreationCollection extends ResourceCollection
{
    public $collects = BLLeadCreationResource::class;

    public function toArray(Request $request): array
    {
        return $this->collection->map(fn($item) => $item->toArray($request))->toArray();
    }

    public function withPaginationMeta()
    {
        if ($this->resource instanceof \Illuminate\Pagination\LengthAwarePaginator) {
            return [
                'current_page' => $this->currentPage(),
                'last_page'    => $this->lastPage(),
                'per_page'     => $this->perPage(),
                'total'        => $this->total(),
            ];
        }
        return null;
    }
}