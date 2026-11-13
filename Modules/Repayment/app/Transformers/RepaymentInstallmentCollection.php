<?php

namespace Modules\Repayment\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class RepaymentInstallmentCollection extends ResourceCollection
{
    /**
     * Resource used for each installment.
     *
     * @var class-string<RepaymentInstallmentResource>
     */
    public $collects = RepaymentInstallmentResource::class;

    /**
     * Transform paginated repayment installments.
     */
    public function toArray(Request $request): array
    {
        return [
            'items' => $this->collection,

            'meta' => [
                'current_page' => $this->resource->currentPage(),

                'per_page' => $this->resource->perPage(),

                'total' => $this->resource->total(),

                'last_page' => $this->resource->lastPage(),

                'from' => $this->resource->firstItem(),

                'to' => $this->resource->lastItem(),
            ],
        ];
    }
}