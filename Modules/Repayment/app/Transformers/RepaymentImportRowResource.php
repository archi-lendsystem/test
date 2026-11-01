<?php

namespace Modules\Repayment\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class RepaymentImportRowResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'row_number' => (int) $this->row_number,
            'loan_number' => $this->loan_number,
            'amount_collected' => $this->money($this->amount_collected),
            'collection_date' => optional($this->collection_date)->format('Y-m-d'),
            'collected_by' => $this->collected_by,
            'payment_mode' => $this->payment_mode,
            'utr_number' => $this->utr_number ?: $this->transaction_reference,
            'penalty_base_amount' => $this->money($this->penalty_base_amount),
            'penalty_gst_amount' => $this->money($this->penalty_gst_amount),
            'penalty_total_amount' => $this->money($this->penalty_total_amount),
            'bounce_base_amount' => $this->money($this->bounce_base_amount),
            'bounce_gst_amount' => $this->money($this->bounce_gst_amount),
            'bounce_total_amount' => $this->money($this->bounce_total_amount),
            'emi_allocation_amount' => $this->money($this->emi_allocation_amount),
            'remarks' => $this->remarks,
            'validation_status' => $this->validation_status->value,
            'validation_status_label' => $this->validation_status->label(),
            'is_valid' => $this->isValid(),
            'has_errors' => $this->hasErrors(),
            'validation_errors' => $this->validation_errors ?? [],
            'raw_payload' => $this->raw_payload ?? [],
            'repayment_transaction_uuid' => $this->whenLoaded('transaction', fn () => $this->transaction?->uuid),
            'transaction_number' => $this->whenLoaded('transaction', fn () => $this->transaction?->transaction_number),
            'imported_at' => optional($this->imported_at)->toIso8601String(),
        ];
    }

    private function money(mixed $value): ?string
    {
        return $value === null ? null : number_format((float) $value, 2, '.', '');
    }
}


