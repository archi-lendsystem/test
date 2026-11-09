<?php

namespace Modules\UserManagement\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                  => $this->id,
            'uuid'                => $this->uuid,
            'user_code'           => $this->user_code,
            'user_name'           => $this->user_name,
            'user_email'          => $this->user_email,
            'user_mobile_number'  => $this->user_mobile_number,
            'user_company_name'   => $this->user_company_name,

            'user_type_id'        => $this->user_type_id,
            'user_type'           => $this->whenLoaded('userType', fn () => $this->userType
                ? [
                    'id'   => $this->userType->id,
                    'name' => $this->userType->name,
                ]
                : null
            ),

            'department_id'       => $this->department_id,
            'user_designation'    => $this->user_designation,
            'branch_id'           => $this->branch_id,

            'branches'   => $this->whenLoaded('branches', fn () =>
    $this->branches->map(fn ($branch) => [
        'id'   => $branch->id,
        'name' => $branch->name ?? null,
    ])->values()
),

            'address'             => $this->address,
            'pincode'             => $this->pincode,
            'district'            => $this->district,
            'city'                => $this->city,
            'state'               => $this->state,

            'is_active'           => (bool) $this->is_active,

            'parent_id'           => $this->parent_id,
            'parent'              => $this->whenLoaded('parent', fn () => $this->parent
                ? [
                    'id'        => $this->parent->id,
                    'user_name' => $this->parent->user_name,
                ]
                : null
            ),

            'report_to'           => $this->report_to,
            'reports_to'          => $this->whenLoaded('reportsTo', fn () => $this->reportsTo
                ? [
                    'id'        => $this->reportsTo->id,
                    'user_name' => $this->reportsTo->user_name,
                ]
                : null
            ),

            'created_by'          => $this->created_by,

            'roles'               => $this->whenLoaded('roles', fn () =>
                $this->roles->map(fn ($role) => [
                    'id'   => $role->id,
                    'name' => $role->name ?? null,
                ])->values()
            ),

            'assigned_product_ids' => $this->whenLoaded('products', fn () =>
                $this->products->pluck('id')->values()
            ),

            'products'            => $this->whenLoaded('products', fn () =>
                $this->products->map(fn ($product) => [
                    'id'     => $product->id,
                    'name'   => $product->name ?? null,
                    'code'   => $product->code ?? null,
                    'status' => isset($product->status) ? (bool) $product->status : null,
                ])->values()
            ),

            'assigned_products'   => $this->whenLoaded('products', fn () =>
                $this->products->map(fn ($product) => [
                    'id'     => $product->id,
                    'name'   => $product->name ?? null,
                    'code'   => $product->code ?? null,
                    'status' => isset($product->status) ? (bool) $product->status : null,
                ])->values()
            ),

            'vendors'             => $this->whenLoaded('vendors', fn () =>
                $this->vendors->map(fn ($vendor) => [
                    'id'             => $vendor->id,
                    'user_name'      => $vendor->user_name,
                    'user_code'      => $vendor->user_code,
                    'vendor_type_id' => $vendor->pivot?->vendor_type_id,
                ])->values()
            ),

            'created_at'          => $this->created_at?->toISOString(),
            'updated_at'          => $this->updated_at?->toISOString(),
        ];
    }
}