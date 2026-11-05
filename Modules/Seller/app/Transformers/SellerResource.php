<?php

namespace Modules\Seller\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SellerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            'lead_uuid' => $this->lead_uuid,

            'lead_id' => $this->lead_id,

            'product_id' => $this->product_id,

            'seller_sno' => $this->seller_sno,

            'created_by' => $this->created_by,

            /*
             * Has detail or not
             */
            'has_detail' => $this->sellerDetail
                ? true
                : false,

            'created_at' => $this->created_at,

            'updated_at' => $this->updated_at,
        ];
    }
}