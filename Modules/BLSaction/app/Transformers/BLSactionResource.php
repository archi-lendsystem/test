<?php

namespace Modules\BLSaction\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BLSactionResource extends JsonResource
{
 
  public function toArray(Request $request): array
    {
        return [
            'status'  => true,
            'code'    => 200,
            'request' => [
                'lead_uuid' => $request->route('lead_uuid') ?? $this->lead_uuid,
            ],
            'data' => [
                'id'              => $this->id,
                'lead_uuid'       => $this->lead_uuid,
                'sanction_number' => $this->sanction_number,
                'version'         => $this->version,

       
                'sanctioned_amount'    => $this->sanctioned_amount,
                'interest_rate'        => $this->interest_rate,
                'tenure_months'        => $this->tenure_months,
                'emi_amount'           => $this->emi_amount,
                'sanction_date'        => $this->sanction_date?->toDateString(),
                'sanction_expiry_date' => $this->sanction_expiry_date?->toDateString(),
                'loan_type'            => $this->loan_type,
                'repayment_mode'       => $this->repayment_mode,
                'terms_and_conditions' => $this->terms_and_conditions,
                'remarks'              => $this->remarks,

             
                'status' => $this->status,

                'pdf_url' => $this->pdf_path
                    ? url('api/v1/lap/sanctions/' . $this->lead_uuid . '/download')
                    : null,

  
                'confirmed_by' => $this->confirmed_by,
                'confirmed_at' => $this->confirmed_at?->toDateTimeString(),
                'generated_by' => $this->generated_by,

                'created_at' => $this->created_at?->toDateTimeString(),
                'updated_at' => $this->updated_at?->toDateTimeString(),
            ],
        ];
    }
}
