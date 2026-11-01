<?php

namespace Modules\UserManagement\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;

class OrganizationBranchTransformer extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'                => $this->id,
            'organization_id'   => $this->organization_id,
            'user_type_id'      => $this->user_type_id,
            'parent_branch_id'  => $this->parent_branch_id,
            'name'              => $this->name,
            'branch_code'       => $this->branch_code,
            'type'              => $this->type,
            'address'           => $this->address,
            'pincode'           => $this->pincode,
            'city'              => $this->city,
            'state'             => $this->state,
            'is_active'         => $this->is_active,
            'created_at'        => $this->created_at?->toISOString(),
            'updated_at'        => $this->updated_at?->toISOString(),
        ];
    }
}