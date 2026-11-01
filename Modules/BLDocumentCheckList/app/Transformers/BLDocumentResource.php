<?php

namespace Modules\BLDocumentCheckList\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BLDocumentResource extends JsonResource
{
    public function __construct($resource)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        return [
            'success'     => true,
            'status_code' => 200,
            'request_id'  => app('request_id') ?? null,
            'timestamp'   => now()->toISOString(),
            'message'     => 'BL Document retrieved successfully',

            'data' => [
                'id'                    => $this->id,
                'uuid'                  => $this->uuid,
                'document_checklist_id' => $this->document_checklist_id,
                'document_group_id'     => $this->document_group_id,
                'dg_document_class'     => $this->dg_document_class,
                'dg_document_name'      => $this->dg_document_name,
                'dg_rule'               => $this->dg_rule,
                'document_sequence'     => $this->document_sequence,
                'created_at'            => $this->created_at?->toISOString(),
                'updated_at'            => $this->updated_at?->toISOString(),
            ],
        ];
    }

    public function withResponse($request, $response)
    {
        $response->setStatusCode(200);
    }
}