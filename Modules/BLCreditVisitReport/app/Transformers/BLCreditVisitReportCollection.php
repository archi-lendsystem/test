<?php

namespace Modules\BLCreditVisitReport\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class BLCreditVisitReportCollection extends ResourceCollection
{
    public $collects = BLCreditVisitReportResource::class;

    public function toArray(Request $request): array
    {
        return [
            'data' => $this->collection,
        ];
    }

    public function with(Request $request): array
    {
        return [
            'success' => true,
            'message' => 'Credit Visit Reports retrieved successfully.',
        ];
    }
}
