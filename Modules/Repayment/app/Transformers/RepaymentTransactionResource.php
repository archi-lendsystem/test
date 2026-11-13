<?php

namespace Modules\Repayment\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RepaymentTransactionResource extends JsonResource
{
    /**
     * Transform the repayment transaction into an API response.
     */
    public function toArray(Request $request): array
    {
        return [
            /*
             * Public transaction identifiers.
             *
             * Internal numeric IDs and idempotency key
             * intentionally expose nahi honge.
             */
            'uuid' => $this->uuid,

            'transaction_number' => $this->transaction_number,

            /*
             * PAYMENT
             * REVERSAL
             * REFUND
             * ADJUSTMENT
             */
            'transaction_type' => $this
                ->transaction_type
                ->value,

            'transaction_type_label' => $this
                ->transaction_type
                ->label(),

            /*
             * Financial amounts.
             */
            'amount' => (float) $this->amount,

            'allocated_amount' => (float) $this
                ->allocated_amount,

            'unallocated_amount' => (float) $this
                ->unallocated_amount,

            'remaining_to_allocate' => (float) $this
                ->remainingToAllocate(),

            /*
             * Payment channel.
             *
             * Reversal/refund/adjustment records mein
             * payment mode null ho sakta hai.
             */
            'payment_mode' => $this->payment_mode?->value,

            'payment_mode_label' => $this->payment_mode?->label(),

            'transaction_reference' => $this
                ->transaction_reference,

            /*
             * Transaction dates.
             */
            'received_date' => optional(
                $this->received_date
            )->format('Y-m-d'),

            'value_date' => optional(
                $this->value_date
            )->format('Y-m-d'),

            'posted_at' => optional(
                $this->posted_at
            )->toIso8601String(),

            /*
             * PENDING
             * POSTED
             * REVERSED
             * FAILED
             */
            'status' => $this->status->value,

            'status_label' => $this->status->label(),

            /*
             * Convenient frontend flags.
             *
             * Final posting/reversal validation backend service
             * database locks ke saath dobara karegi.
             */
            'can_be_posted' => $this->canBePosted(),

            'is_posted' => $this->isPosted(),

            'is_reversal' => $this->isReversal(),

            'can_be_reversed' => $this->canBeReversed(),

            'has_unallocated_amount' => $this
                ->hasUnallocatedAmount(),

            'is_fully_allocated' => $this
                ->isFullyAllocated(),

            /*
             * Related reversal transactions.
             *
             * Controller relationship load karega tabhi
             * values response mein include hongi.
             */
            'original_transaction_uuid' => $this->whenLoaded(
                'reversalOf',
                fn () => $this->reversalOf?->uuid
            ),

            'reversal_transaction_uuid' => $this->whenLoaded(
                'reversal',
                fn () => $this->reversal?->uuid
            ),

            /*
             * Related receipt.
             */
            'receipt_uuid' => $this->whenLoaded(
                'receipt',
                fn () => $this->receipt?->uuid
            ),

            'receipt_number' => $this->whenLoaded(
                'receipt',
                fn () => $this->receipt?->receipt_number
            ),

            /*
             * Allocation count controller withCount()
             * use karega tab response mein include hoga.
             */
            'allocations_count' => $this->whenCounted(
                'allocations'
            ),

            'remarks' => $this->remarks,

            /*
             * Supplemental metadata sirf explicitly request
             * hone par expose hogi.
             *
             * Example: ?with_metadata=1
             */
            'metadata' => $this->when(
                $request->boolean('with_metadata', false),
                $this->metadata
            ),

            /*
             * Audit information.
             */
            'created_by' => $this->created_by,

            'approved_by' => $this->approved_by,

            'created_at' => optional(
                $this->created_at
            )->toIso8601String(),

            'updated_at' => optional(
                $this->updated_at
            )->toIso8601String(),
        ];
    }
}