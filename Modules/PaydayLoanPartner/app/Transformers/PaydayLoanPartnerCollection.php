<?php

namespace Modules\PaydayLoanPartner\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class PaydayLoanPartnerCollection extends ResourceCollection
{
     /**
     * Disable the default outer "data" wrapper so status, message, data,
     * and pagination are returned at the top level.
     */
    public static $wrap = null;

    /**
     * The resource that this resource collects.
     *
     * @var class-string
     */
    public $collects = PaydayLoanPartnerResource::class;

    /**
     * Transform the resource collection into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'status' => true,
            'message' => 'Payday loan partners retrieved successfully.',
            'data' => $this->collection,
            'pagination' => $this->paginationMeta(),
        ];
    }

    /**
     * Build pagination metadata for the response.
     *
     * @return array<string, int|null>
     */
    protected function paginationMeta(): array
    {
        return [
            'total' => $this->resource->total(),
            'per_page' => $this->resource->perPage(),
            'current_page' => $this->resource->currentPage(),
            'last_page' => $this->resource->lastPage(),
            'from' => $this->resource->firstItem(),
            'to' => $this->resource->lastItem(),
        ];
    }

    /**
     * Disable default Laravel pagination links/meta wrapper.
     *
     * @param  array<string, mixed>  $paginated
     * @param  array<string, mixed>  $default
     * @return array<string, mixed>
     */
    public function paginationInformation(Request $request, array $paginated, array $default): array
    {
        return [];
    }
}
