<?php

namespace Modules\BLBankDetails\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BLBankDetailResource extends JsonResource
{
     public function toArray($request): array
    {
        return [
            'id'                   => $this->id,
            'lead_uuid'            => $this->lead_uuid,
            'lead_id'              => $this->lead_id,
            'applicant_id'         => $this->applicant_id,
            'applicant_sno'        => $this->applicant_sno,
            'applicant_type'       => $this->applicant_type,

          'account_holder_name'  => $this->account_holder_name,
            'bank_name'            => $this->bank_name,
            'account_type'         => $this->account_type,
            'statement_file'       => $this->statement_file,
            'statement_password'   => $this->statement_password,

            'account_number' => $this->account_number,

            'ifsc_code'            => $this->ifsc_code,
            'created_at'           => $this->created_at?->toDateTimeString(),
            'updated_at'           => $this->updated_at?->toDateTimeString(),
        ];
    }
}
