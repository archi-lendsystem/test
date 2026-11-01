<?php

namespace Modules\BLIMDDetails\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BLIMDDetailsResource extends JsonResource
{
   
    public function toArray(Request $request): array
    {
        return parent::toArray($request);
    }
}
