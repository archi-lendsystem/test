<?php

namespace Modules\Repayment\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RepaymentAccountResource extends JsonResource
{
    /**
     * Transform the repayment account into an API response.
     */
    public function toArray(Request $request): array
    {
        $lead = $this->relationLoaded('lead')
            ? $this->lead
            : null;

        $primaryApplicant = null;

        if (
            $lead
            && $lead->relationLoaded('applicantDetails')
        ) {
            $primaryApplicant = $lead
                ->applicantDetails
                ->first();
        }

        return [
            /*
             * Public identifiers.
             *
             * Numeric database IDs intentionally expose nahi honge.
             */
            'uuid' => $this->uuid,

            'loan_type' => $this->loan_type,

            'lead_uuid' => $this->lead_uuid,

            'loan_number' => $this->loan_number,

            /*
             * Primary borrower identity.
             *
             * Applicant details controller mein applicant_sno = 0
             * ke saath eager-load hoti hain.
             */
            'borrower' => [
                'name' => $primaryApplicant?->full_name,

                'mobile_number' => $primaryApplicant
                    ?->alternate_contact_number
                    ?: $lead?->mobile_number,

                'email' => $primaryApplicant?->email_address
                    ?: $lead?->email,

                'lead_id' => $lead?->lead_id,

                'lead_uuid' => $this->lead_uuid,
            ],

            /*
             * Backward-compatible convenience fields.
             */
            'borrower_name' => $primaryApplicant?->full_name,

            'borrower_mobile_number' => $primaryApplicant
                ?->alternate_contact_number
                ?: $lead?->mobile_number,

            'schedule_version' => (int) $this->schedule_version,

            /*
             * Frozen loan terms.
             */
            'principal_amount' => (float) $this->principal_amount,

            'net_disbursed_amount' => (float) $this
                ->net_disbursed_amount,

            'interest_rate' => (float) $this->interest_rate,

            'tenure' => (float) $this->tenure,

            'tenure_unit' => $this->tenure_unit,

            'frequency' => $this->frequency,

            'installment_count' => (int) $this
                ->installment_count,

            'total_interest' => (float) $this->total_interest,

            'total_payable' => (float) $this->total_payable,

            /*
             * Current repayment balances.
             */
            // 'principal_outstanding' => (int) $this
            //     ->principal_outstanding,

            'principal_outstanding' => (int) round(
                (float) $this->principal_outstanding,
                0,
                PHP_ROUND_HALF_UP
            ),

            'interest_outstanding' => (float) $this
                ->interest_outstanding,

            // 'total_outstanding' => (int) $this
            //     ->total_outstanding,

            'total_outstanding' => (int) round(
                (float) $this->total_outstanding,
                0,
                PHP_ROUND_HALF_UP
            ),

            /*
                         * Due date cross kar chuki unpaid amount.
                         *
                         * Ye DPD count nahi hai.
                         */
            'overdue_amount' => (float) $this->overdue_amount,

            'advance_amount' => (float) $this->advance_amount,

            'total_received' => (float) $this->total_received,

            /*
                         * Repayment dates.
                         */
            'first_due_date' => optional(
                $this->first_due_date
            )->format('Y-m-d'),

            'next_due_date' => optional(
                $this->next_due_date
            )->format('Y-m-d'),

            'maturity_date' => optional(
                $this->maturity_date
            )->format('Y-m-d'),

            'last_payment_at' => optional(
                $this->last_payment_at
            )->toIso8601String(),

            /*
             * Account lifecycle status.
             */
            'status' => $this->status->value,

            'status_label' => $this->status->label(),

            /*
             * Convenient frontend flags.
             *
             * Payment service final validation database lock
             * ke saath dobara karegi.
             */
            'allows_payment' => $this->allowsPayment(),

            'has_outstanding' => $this->hasOutstanding(),

            'is_closed' => $this->isClosed(),

            'is_final' => $this->status->isFinal(),

            /*
             * Activation and closure timestamps.
             */
            'activated_at' => optional(
                $this->activated_at
            )->toIso8601String(),

            'closed_at' => optional(
                $this->closed_at
            )->toIso8601String(),

            /*
             * Basic source lead information.
             *
             * Controller lead relationship load karega tabhi
             * response mein include hogi.
             */
            'lead' => $this->whenLoaded(
                'lead',
                function (): ?array {
                    if (! $this->lead) {
                        return null;
                    }

                    return [
                        'uuid' => $this->lead->uuid,
                        'lead_id' => $this->lead->lead_id,
                        'loan_type' => $this->lead->loan_type,
                        'sub_loan_type' => $this->lead->sub_loan_type,
                        'customer_type' => $this->lead->customer_type,
                        'mobile_number' => $this->lead->mobile_number,
                        'email' => $this->lead->email,
                    ];
                }
            ),

            /*
             * Source disbursement summary.
             */
            'disbursement' => $this->whenLoaded(
                'disbursement',
                function (): ?array {
                    if (! $this->disbursement) {
                        return null;
                    }

                    return [
                        'loan_number' => $this->disbursement->loan_number,

                        'disbursement_amount' => (float)
                            $this->disbursement
                                ->disbursement_amount,

                        'disbursement_date' => optional(
                            $this->disbursement
                                ->disbursement_date
                        )->format('Y-m-d'),

                        'bank_name' => $this->disbursement->bank_name,

                        'utr_number' => $this->disbursement->utr_number,
                    ];
                }
            ),

            /*
             * Source amortization summary.
             */
            'amortization' => $this->whenLoaded(
                'amortization',
                function (): ?array {
                    if (! $this->amortization) {
                        return null;
                    }

                    return [
                        'loan_amount' => (float)
                            $this->amortization->loan_amount,

                        // 'installment_amount' => (float)
                        //     $this->amortization
                        //         ->installment_amount,

                        'installment_amount' => (int) round($this->installment_amount),

                        'number_of_payments' => (int)
                            $this->amortization
                                ->number_of_payments,

                        'total_interest' => (float)
                            $this->amortization
                                ->total_interest,

                        'total_payable' => (float)
                            $this->amortization
                                ->total_payable,

                        'calculation_method' => $this->amortization
                            ->calculation_method,
                    ];
                }
            ),

            /*
             * Relationship counts.
             *
             * Controller withCount() use karega tabhi fields
             * response mein include hongi.
             */
            'installments_count' => $this->whenCounted(
                'installments'
            ),

            'transactions_count' => $this->whenCounted(
                'transactions'
            ),

            /*
             * Complete schedule/detail endpoint mein controller
             * relationships load karega.
             *
             * Listing endpoint mein ye arrays load nahi hongi.
             */
            'installments' => RepaymentInstallmentResource::collection(
                $this->whenLoaded('installments')
            ),

            'transactions' => RepaymentTransactionResource::collection(
                $this->whenLoaded('transactions')
            ),

            /*
             * Internal activation diagnostics only when explicitly
             * requested.
             *
             * Example:
             * ?with_diagnostics=1
             */
            'activation_error' => $this->when(
                $request->boolean('with_diagnostics', false),
                $this->activation_error
            ),

            /*
             * Frozen activation snapshot only when explicitly
             * requested.
             *
             * Example:
             * ?with_snapshot=1
             */
            'financial_snapshot' => $this->when(
                $request->boolean('with_snapshot', false),
                $this->financial_snapshot
            ),

            /*
             * Audit fields.
             */
            'created_by' => $this->created_by,

            'updated_by' => $this->updated_by,

            'created_at' => optional(
                $this->created_at
            )->toIso8601String(),

            'updated_at' => optional(
                $this->updated_at
            )->toIso8601String(),
        ];
    }
}
