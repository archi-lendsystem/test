<?php

namespace Modules\BLDocumentCheckList\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BLDocumentCheckListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'success'     => true,
            'status_code' => 200,
            'request_id'  => app('request_id') ?? null,
            'timestamp'   => now()->toISOString(),
            'message'     => 'BL Document Check List retrieved successfully',

            'data' => [
                'id'                   => $this->id,
                'uuid'                 => $this->uuid,
                'title'                => $this->title,
                'type'                 => $this->type,
                'applicable_to'        => $this->applicable_to,
                'loan_type'            => $this->loan_type,
                'is_income_considered' => $this->is_income_considered !== null
                                            ? (bool) $this->is_income_considered
                                            : null,
                'applying_as'          => $this->applying_as,
                'employment_type'      => $this->employment_type,
                'entity_type'          => $this->entity_type,
                'rule'                 => $this->rule,
                'sequence'             => (int) $this->sequence,
                'status'               => (bool) $this->status,
                'created_at'           => optional($this->created_at)->toISOString(),
                'updated_at'           => optional($this->updated_at)->toISOString(),
            ],
        ];
    }

    public function withResponse($request, $response)
    {
        $response->setStatusCode(200);
    }
}