<?php

namespace Modules\Repayment\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RepaymentReceiptResource extends JsonResource
{
    /**
     * Transform repayment receipt into an API response.
     */
    public function toArray(Request $request): array
    {
        return [
            /*
             * Public receipt identifiers.
             */
            'uuid' => $this->uuid,

            'receipt_number' => $this->receipt_number,

            'receipt_date' => optional(
                $this->receipt_date
            )->format('Y-m-d'),

            /*
             * Receipt lifecycle.
             */
            'status' => $this->status->value,

            'status_label' => $this->status->label(),

            'is_valid' => $this->isValid(),

            'can_be_voided' => $this->canBeVoided(),

            'is_void' => $this->isVoid(),

            /*
             * Generated document information.
             *
             * Direct physical server path expose nahi hoga.
             */
            'has_file' => $this->hasFile(),

            'has_file_hash' => $this->hasFileHash(),

            'file_hash' => $this->when(
                $this->hasFileHash(),
                $this->file_hash
            ),

            /*
             * Receipt generation audit.
             */
            'generated_by' => $this->generated_by,

            'generated_at' => optional(
                $this->generated_at
            )->toIso8601String(),

            /*
             * Receipt void audit.
             */
            'voided_by' => $this->voided_by,

            'voided_at' => optional(
                $this->voided_at
            )->toIso8601String(),

            'void_reason' => $this->void_reason,

            /*
             * Related payment summary.
             *
             * Numeric transaction/account IDs expose nahi honge.
             */
            'transaction' => $this->whenLoaded(
                'transaction',
                function (): ?array {
                    if (! $this->transaction) {
                        return null;
                    }

                    return [
                        'uuid' => $this->transaction->uuid,

                        'transaction_number' =>
                            $this->transaction
                                ->transaction_number,

                        'transaction_type' =>
                            $this->transaction
                                ->transaction_type
                                ->value,

                        'transaction_status' =>
                            $this->transaction
                                ->status
                                ->value,

                        'amount' => (float)
                            $this->transaction->amount,

                        'payment_mode' =>
                            $this->transaction
                                ->payment_mode
                                ?->value,

                        'transaction_reference' =>
                            $this->transaction
                                ->transaction_reference,

                        'received_date' => optional(
                            $this->transaction
                                ->received_date
                        )->format('Y-m-d'),
                    ];
                }
            ),

            /*
             * Parent repayment account summary.
             *
             * transaction.account relationship load hone par
             * hi include hogi.
             */
            'account' => $this->when(
                $this->relationLoaded('transaction')
                    && $this->transaction
                    && $this->transaction
                        ->relationLoaded('account'),
                function (): ?array {
                    $account = $this->transaction?->account;

                    if (! $account) {
                        return null;
                    }

                    return [
                        'uuid' => $account->uuid,
                        'loan_number' => $account->loan_number,
                        'lead_uuid' => $account->lead_uuid,
                        'status' => $account->status->value,
                    ];
                }
            ),

            /*
             * Supplemental context only when explicitly asked.
             *
             * Example:
             * ?with_metadata=1
             */
            'metadata' => $this->when(
                $request->boolean(
                    'with_metadata',
                    false
                ),
                $this->metadata
            ),

            'created_at' => optional(
                $this->created_at
            )->toIso8601String(),

            'updated_at' => optional(
                $this->updated_at
            )->toIso8601String(),
        ];
    }
}