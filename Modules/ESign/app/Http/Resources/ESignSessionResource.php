<?php

namespace Modules\ESign\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \Modules\ESign\Models\ESignSession */
final class ESignSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'signer_type' => $this->signer_type->value,
            'attempt' => $this->attempt_no,
            'status' => $this->status->value,
            'signing_url' => $this->when($this->hasUsableUrl(), fn (): ?string => $this->esign_url),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'signed_at' => $this->signed_at?->toIso8601String(),
            'failure_reason' => $this->failure_reason,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
