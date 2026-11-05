<?php

namespace Modules\BLLeadCreation\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BLLeadCreationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [

            'id'              => $this->id,
            'uuid'            => $this->uuid,
            'lead_id'         => $this->lead_id,
            'product' => $this->product ? [
    'id'   => $this->product->id,
    'name' => $this->product->name,
    'code' => $this->product->code,
] : null,
            'loan_type'       => $this->loan_type,
            'applicant_sno'   => $this->applicant_sno,
            'applicant_types' => $this->applicant_types,
        
            'created_by'    => $this->creator?->user_name ?? null,
'branch_id' => $this->branch_id,
            'customer_type'   => $this->customer_type,
            'is_reloan' => (bool) $this->is_reloan,
            'previous_lead_uuid' => $this->previous_lead_uuid,
            'sub_loan_type'   => $this->sub_loan_type,
            'source_by'       => $this->source_by,
            'loan_amount'     => $this->loan_amount,
            'loan_tenur'      => $this->loan_tenur,
            'email'           => $this->email,
            'mobile_number'   => $this->mobile_number,

          
            'customer' => [
                'email'         => $this->email,
                'mobile_number' => $this->mobile_number,
            ],

            'loan' => [
                'loan_type'     => $this->loan_type,
                'sub_loan_type' => $this->sub_loan_type,
                'loan_amount'   => $this->loan_amount,
                'loan_tenur'    => $this->loan_tenur,
            ],

         
            'meta' => [
                'customer_type' => $this->customer_type,
                'source_by'     => $this->source_by,
            ],

          
            'creator' => $this->whenLoaded('creator', fn() => [
                'id'   => $this->creator->id,
                'name' => $this->creator->name,
            ]),

            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}

