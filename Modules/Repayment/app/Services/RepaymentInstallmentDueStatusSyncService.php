<?php

namespace Modules\Repayment\Services;

use Illuminate\Support\Facades\DB;
use Modules\Repayment\Enums\InstallmentDueStatus;
use Modules\Repayment\Enums\InstallmentPaymentStatus;
use Modules\Repayment\Enums\RepaymentTransactionStatus;
use Modules\Repayment\Enums\RepaymentTransactionType;
use Modules\Repayment\Enums\StatusHistorySource;
use Modules\Repayment\Enums\StatusHistoryType;
use Modules\Repayment\Models\RepaymentInstallment;
use Modules\Repayment\Models\RepaymentStatusHistory;
use Modules\Repayment\Models\RepaymentTransaction;

/** Synchronizes an EMI's persisted due_status after normal payments/reversals. */
final class RepaymentInstallmentDueStatusSyncService
{
    /**
     * Calculate the latest effective received_date for payments allocated to this EMI.
     * A newly posting transaction is still PENDING here; include it explicitly.
     * A reversed transaction may still be POSTED while the reversal is being processed.
     */
    public function paidDueStatus(
        RepaymentInstallment $installment,
        ?RepaymentTransaction $transaction = null,
        ?StatusHistorySource $source = null
    ): InstallmentDueStatus {
        $paymentQuery = DB::table('repayment_allocations as a')
            ->join('repayment_transactions as t', 't.id', '=', 'a.repayment_transaction_id')
            ->where('a.repayment_installment_id', $installment->id)
            ->where('t.transaction_type', RepaymentTransactionType::PAYMENT->value)
            ->where('t.status', RepaymentTransactionStatus::POSTED->value);

        if ($source === StatusHistorySource::PAYMENT_REVERSAL
            && $transaction?->reversal_of_transaction_id) {
            $paymentQuery->where('t.id', '!=', $transaction->reversal_of_transaction_id);
        }

        $lastDate = $paymentQuery->max('t.received_date');

        if ($source === StatusHistorySource::PAYMENT_POSTING
            && $transaction?->transaction_type === RepaymentTransactionType::PAYMENT
            && $transaction->received_date) {
            $receivedDate = $transaction->received_date->toDateString();
            if ($lastDate === null || $receivedDate > $lastDate) {
                $lastDate = $receivedDate;
            }
        }

        if ($lastDate === null) {
            // Do not fabricate a received date when historical data is unavailable.
            return InstallmentDueStatus::PAID;
        }

        return $lastDate <= $installment->due_date->toDateString()
            ? InstallmentDueStatus::PAID_ON_TIME
            : InstallmentDueStatus::PAID_LATE;
    }

    public function sync(
        RepaymentInstallment $installment,
        StatusHistorySource $source,
        RepaymentTransaction $transaction,
        ?int $actorId
    ): void {
        // Foreclosure/NPA SETTLED are separate lifecycles. Do not change them.
        if (! in_array($installment->payment_status, [
            InstallmentPaymentStatus::PAID,
            InstallmentPaymentStatus::PARTIAL,
            InstallmentPaymentStatus::UNPAID,
        ], true)) {
            return;
        }

        $businessDate = now(config('app.timezone'))->toDateString();
        $dueDate = $installment->due_date->toDateString();
        $new = match (true) {
            $installment->payment_status === InstallmentPaymentStatus::PAID
                && ! $installment->hasOutstanding()
                => $this->paidDueStatus($installment, $transaction, $source),
            $dueDate > $businessDate => InstallmentDueStatus::UPCOMING,
            $dueDate === $businessDate => InstallmentDueStatus::DUE,
            default => InstallmentDueStatus::OVERDUE,
        };

        $old = $installment->due_status;
        if ($old === $new) {
            return;
        }

        $installment->forceFill(['due_status' => $new->value])->save();
        RepaymentStatusHistory::query()->create([
            'repayment_account_id' => $installment->repayment_account_id,
            'repayment_installment_id' => $installment->id,
            'repayment_transaction_id' => $transaction->id,
            'status_type' => StatusHistoryType::DUE_STATUS->value,
            'old_status' => $old->value,
            'new_status' => $new->value,
            'source' => $source->value,
            'reason' => 'EMI status synchronized after payment or reversal.',
            'metadata' => [
                'due_date' => $dueDate,
                'business_date' => $businessDate,
                'received_date' => $transaction->received_date?->toDateString(),
                'outstanding_amount' => (string) $installment->outstanding_amount,
            ],
            'changed_by' => $actorId,
            'changed_at' => now(),
        ]);
    }
}
