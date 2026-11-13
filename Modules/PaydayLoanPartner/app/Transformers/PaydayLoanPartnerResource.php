<?php

namespace Modules\PaydayLoanPartner\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaydayLoanPartnerResource extends JsonResource
{
   public function toArray(Request $request): array
    {
        return [
            'id'                  => $this->id,
            'uuid'                => $this->uuid,
            'payday_partner_name' => $this->payday_partner_name,
            'payday_partner_code' => $this->payday_partner_code,
            'payday_partner_id'   => $this->payday_partner_id,
            'description'         => $this->description,
            'contact_email'       => $this->contact_email,
            'contact_phone'       => $this->contact_phone,
            'api_url'             => $this->api_url,
            'client_id'           => $this->client_id,           
            'extra_config'        => $this->extra_config,
            'is_active'           => $this->is_active,
            'created_by'          => $this->created_by,
            'created_at'          => $this->created_at,
            'updated_at'          => $this->updated_at,
        ];
    }
}