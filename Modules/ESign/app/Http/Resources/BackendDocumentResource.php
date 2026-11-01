<?php

namespace Modules\ESign\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \Modules\ESign\Models\ESignDocument */
final class BackendDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'customer_lending_id' => $this->customer_lending_id,
            'version' => $this->version,
            'status' => $this->status->value,
            'borrower_signed_at' => $this->borrower_signed_at?->toIso8601String(),
            'lender_signed_at' => $this->lender_signed_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'last_error' => $this->last_error,
            'metadata' => $this->metadata,
            'sessions' => ESignSessionResource::collection($this->whenLoaded('sessions')),
            'downloads' => [
                'original' => route('esign.backend.documents.download', [$this->resource, 'original']),
                'borrower_signed' => $this->when(
                    $this->borrower_signed_document_path !== null,
                    fn (): string => route('esign.backend.documents.download', [$this->resource, 'borrower'])
                ),
                'final_signed' => $this->when(
                    $this->final_signed_document_path !== null,
                    fn (): string => route('esign.backend.documents.download', [$this->resource, 'final'])
                ),
            ],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
