<?php

namespace Modules\Repayment\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RepaymentInstallmentResource extends JsonResource
{
    /**
     * Transform the repayment installment into an API response.
     */
    public function toArray(Request $request): array
    {
        return [
            /*
             * Public identifier.
             *
             * Internal numeric ID intentionally expose nahi hoga.
             */
            'uuid' => $this->uuid,

            /*
             * Schedule information.
             */
            'schedule_version' => (int) $this->schedule_version,

            'installment_number' => (int) $this->installment_number,

            'due_date' => optional($this->due_date)
                ->format('Y-m-d'),

            /*
             * Frozen amortization values.
             */
            'opening_principal' => (float) $this->opening_principal,

            'principal_due' => (float) $this->principal_due,

            'interest_due' => (float) $this->interest_due,

            // 'installment_amount' => (float) $this->installment_amount,

            'installment_amount' => (int) round($this->installment_amount),

            'closing_principal' => (float) $this->closing_principal,

            /*
             * Actual payment allocation values.
             */
            'principal_paid' => (float) $this->principal_paid,

            'interest_paid' => (float) $this->interest_paid,

            /*
             * Remaining component balances.
             */
            'principal_outstanding' => (float) $this
                ->principalOutstanding(),

            'interest_outstanding' => (float) $this
                ->interestOutstanding(),

            'outstanding_amount' => (int) $this
                ->outstanding_amount,

            /*
             * Payment lifecycle status:
             *
             * UNPAID
             * PARTIAL
             * PAID
             */
            'payment_status' => $this->payment_status->value,

            'payment_status_label' => $this
                ->payment_status
                ->label(),

            /*
             * Installment date status:
             *
             * UPCOMING
             * DUE
             * OVERDUE
             *
             * DPD count intentionally absent.
             */
            'due_status' => $this->due_status->value,

            'due_status_label' => $this
                ->due_status
                ->label(),

            /*
             * Convenient frontend flags.
             */
            'has_outstanding' => $this->hasOutstanding(),

            'is_paid' => $this->isPaid(),

            'is_overdue' => $this->isOverdue(),

            /*
             * Payment timestamps.
             */
            'last_payment_at' => optional(
                $this->last_payment_at
            )->toIso8601String(),

            'fully_paid_at' => optional(
                $this->fully_paid_at
            )->toIso8601String(),

            /*
             * Immutable source row sirf explicitly request hone
             * par API response mein include hogi.
             *
             * Example:
             * ?with_snapshot=1
             */
            'schedule_snapshot' => $this->when(
                $request->boolean('with_snapshot', false),
                $this->schedule_snapshot
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