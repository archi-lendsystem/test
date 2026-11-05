<?php

namespace Modules\Auth\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuthResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                  => $this->id,
            'user_code'           => $this->user_code,
            'user_name'           => $this->user_name,
            'user_email'          => $this->user_email,
            'user_mobile_number'  => $this->user_mobile_number,
            'user_company_name'   => $this->user_company_name,

            'department_id'       => $this->department_id,
            'user_designation'    => $this->user_designation,
            'branch_id'           => $this->branch_id,

            'address'             => $this->address,
            'pincode'             => $this->pincode,
            'district'            => $this->district,
            'city'                => $this->city,
            'state'               => $this->state,

            'is_active'           => $this->is_active,

            'user_type'           => $this->whenLoaded('userType', fn() => [
                                        'id'   => $this->userType->id,
                                        'name' => $this->userType->name,
                                     ]),
            'roles'               => $this->whenLoaded('roles', fn() =>
                                        $this->roles->map(fn($r) => [
                                            'id'   => $r->id,
                                            'name' => $r->name,
                                        ])
                                     ),
            'products'            => $this->whenLoaded('products', fn() =>
                                        $this->products->map(fn($p) => [
                                            'id'   => $p->id,
                                            'name' => $p->name,
                                            'code' => $p->code,
                                        ])
                                     ),
            'vendors'             => $this->whenLoaded('vendors', fn() =>
                                        $this->vendors->map(fn($v) => [
                                            'id'              => $v->id,
                                            'user_name'       => $v->user_name,
                                            'user_email'      => $v->user_email,
                                            'vendor_type_id'  => $v->pivot->vendor_type_id,
                                        ])
                                     ),

            'created_at'          => $this->created_at?->toDateTimeString(),
        ];
    }
}
