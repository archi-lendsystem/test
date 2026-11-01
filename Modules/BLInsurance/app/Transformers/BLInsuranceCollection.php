<?php

namespace Modules\BLInsurance\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class BLInsuranceCollection extends ResourceCollection
{
    
    public $collects = BLInsuranceResource::class;

    public function toArray(Request $request): array
    {
        return [
            'data' => $this->collection,
            'meta' => [
                'total'        => $this->collection->count(),
            ],
        ];
    }
}

