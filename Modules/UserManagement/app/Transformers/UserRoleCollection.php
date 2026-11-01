<?php

namespace Modules\UserManagement\Transformers;

use Illuminate\Http\Resources\Json\ResourceCollection;

class UserRoleCollection extends ResourceCollection
{
    public function toArray($request)
    {
        return [
            'data' => \Modules\UserManagement\Transformers\RoleResource::collection($this->collection),
        ];
    }

    public function with($request)
    {
        return [
            'success' => true,
        ];
    }

    public function paginationInformation($request, $paginated, $default)
    {
        return [
            'meta' => [
                'current_page' => $paginated['current_page'],
                'last_page' => $paginated['last_page'],
                'per_page' => $paginated['per_page'],
                'total' => $paginated['total'],
            ],
        ];
    }
}
