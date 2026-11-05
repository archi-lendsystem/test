<?php

namespace Modules\BLDocumentCheckList\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BLDocumentGroupResource extends JsonResource
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
            'message'     => 'BL Document Group retrieved successfully',

            'data' => [
                'id'                     => $this->id,
                'uuid'                   => $this->uuid,
                'document_checklist_id'  => $this->document_checklist_id,
                'dg_document_group_name' => $this->dg_document_group_name,
                'dg_group_sequence'      => $this->dg_group_sequence,
                'dg_mandatory'           => $this->dg_mandatory,
                'created_at'             => $this->created_at?->toISOString(),
                'updated_at'             => $this->updated_at?->toISOString(),
            ],
        ];
    }

    public function withResponse($request, $response)
    {
        $response->setStatusCode(200);
    }
}