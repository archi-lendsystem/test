<?php

namespace Modules\BLSchemeSelect\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BLSchemeSelectResource extends JsonResource
{


 public function toArray(Request $request): array
    {
        return [
            'id'                  => $this->id,
            'lead_uuid'           => $this->lead_uuid,
                        'product_id' => $this->product_id,
            'product_scheme_uuid' => $this->product_scheme_uuid,

               'product' => $this->when(
                $this->relationLoaded('product'),
                fn () => $this->product
                    ? [
                        'id' => $this->product->id,
                        'name' => $this->product->name,
                        'code' => $this->product->code,
                    ]
                    : null
            ),

            'scheme' => $this->when(
                $this->relationLoaded('productScheme'),
                function () use ($request) {
                    if (! $this->productScheme) {
                        return null;
                    }

                    return array_merge(
                        (new ProductSchemeOptionResource(
                            $this->productScheme
                        ))->resolve($request),
                        [
                            'is_selected' => true,
                        ]
                    );
                }
            ),

            'lead' => $this->when(
                $this->relationLoaded('lead'),
                fn () => $this->lead
                    ? [
                        'uuid'          => $this->lead->uuid,
                        'lead_id'       => $this->lead->lead_id,
                        'loan_type'     => $this->lead->loan_type,
                        'sub_loan_type' => $this->lead->sub_loan_type,

                        'loan_amount' => $this->lead->loan_amount !== null
                            ? (float) $this->lead->loan_amount
                            : null,

                        'loan_tenure' => $this->lead->loan_tenur,
                    ]
                    : null
            ),

            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
