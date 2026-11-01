<?php

namespace Modules\BLCam\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BLCamResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'lead_uuid'=> $this->lead_uuid,
            'status'        => $this->status,
            'forward_to'    => $this->forward_to,
            'forward_by'=> $this->forward_by,
            'remarks'       => $this->remarks,
            'loan_details'  => [
                'amount' => (float) $this->loan_amount,
                'tenure' => (int) $this->loan_tenure,
                'roi'    => (float) $this->roi,
            ],
            'financials'    => [
                'pf'           => (float) $this->pf,           
                'gst'          => (float) $this->gst,          
                
            ],
            'created_at'    => $this->created_at ? $this->created_at->toDateTimeString() : null,
            'updated_at'    => $this->updated_at ? $this->updated_at->toDateTimeString() : null,
        ];
    }
}
