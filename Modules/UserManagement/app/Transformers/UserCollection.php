<?php

namespace Modules\UserManagement\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class UserCollection extends ResourceCollection
{
   
   public function toArray($request)
{
    return [
        'data' => UserResource::collection($this->collection),
        'meta' => [
            'current_page' => $this->resource->currentPage(),
            'last_page'    => $this->resource->lastPage(),
            'per_page'     => $this->resource->perPage(),
            'total'        => $this->resource->total(),
        ],
    ];
}

    public function with($request)
    {
        return [
            'status' => true,
            'message' => 'Users fetched successfully',
        ];
    }
}

