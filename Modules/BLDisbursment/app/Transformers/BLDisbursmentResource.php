<?php

namespace Modules\BLDisbursment\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BLDisbursmentResource extends JsonResource
{
   public function toArray($request): array
    {
        return [
            'id'                  => $this->id,
            'lead_uuid'           => $this->lead_uuid,
             'loan_number' => $this->loan_number,
            // 'disbursement_amount' => $this->disbursement_amount,
            'disbursement_amount' => $this->disbursement_amount !== null
    ? number_format((float) $this->disbursement_amount, 0, '.', '')
    : null,
            'bank_name'           => $this->bank_name,
            'utr_number'          => $this->utr_number,
           'disbursement_date' => $this->disbursement_date?->format('Y-m-d'),
           'first_emi_date' => $this->first_emi_date?->format('Y-m-d'),
            'created_at'          => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at'          => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
}
