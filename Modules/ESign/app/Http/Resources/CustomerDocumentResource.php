<?php

namespace Modules\ESign\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \Modules\ESign\Models\ESignDocument */
final class CustomerDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'application_number' => $this->whenLoaded(
                'customerLending',
                fn (): mixed => $this->customerLending?->getAttribute('application_number')
            ),
            'version' => $this->version,
            'status' => $this->status->value,
            'borrower_signed_at' => $this->borrower_signed_at?->toIso8601String(),
            'borrower_session' => new ESignSessionResource($this->whenLoaded('borrowerSession')),
            'borrower_signed_download' => $this->when(
                $this->borrower_signed_document_path !== null,
                fn (): string => route('esign.customer.documents.borrower-download', $this->resource)
            ),
        ];
    }
}
