<?php

namespace Modules\LUCReport\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class LucReportCollection extends ResourceCollection
{
    public $collects = LucReportResource::class;

    public function toArray(Request $request): array
    {
        return [
            'rows' => $this->collection,
            'status_options' => collect(LucReportResource::STATUS_LABELS)
                ->map(fn (string $label, string $value): array => compact('value', 'label'))
                ->values(),
            'pagination' => [
                'current_page' => $this->currentPage(),
                'last_page' => $this->lastPage(),
                'per_page' => $this->perPage(),
                'total' => $this->total(),
            ],
        ];
    }
}