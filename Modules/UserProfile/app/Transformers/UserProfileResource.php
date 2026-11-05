<?php

namespace Modules\UserProfile\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserProfileResource extends JsonResource
{
   
    public function toArray(Request $request): array
    {
        return [
            'user_name'           => $this->user_name,
            'user_mobile_number'  => $this->user_mobile_number,
            'user_email'          => $this->user_email,
            'address'             => $this->address,
            'pincode'             => $this->pincode,
            'city'                => $this->city,
            'district'            => $this->district,
            'state'               => $this->state,
            'user_designation'    => $this->user_designation,
            'roles' => $this->whenLoaded(
                'roles',
                fn () => $this->roles->map(fn ($role) => [
                    'id'   => $role->id,
                    'name' => $role->name,
                ])
            ),
             'branches' => $this->whenLoaded(
            'branches',
            fn () => $this->branches->map(fn ($branch) => [
                'id'   => $branch->id,
                'name' => $branch->name,
            ])->values()
        ),
        ];
    }
}