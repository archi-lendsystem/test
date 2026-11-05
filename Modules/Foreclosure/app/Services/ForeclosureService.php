<?php

namespace Modules\Foreclosure\Services;

use InvalidArgumentException;
use Modules\Foreclosure\Models\Foreclosure;
use Modules\Repayment\Enums\InstallmentPaymentStatus;
use Modules\Repayment\Enums\PaymentMode;
use Modules\Repayment\Enums\RepaymentAccountStatus;
use Modules\Repayment\Enums\RepaymentTransactionStatus;
use Modules\Repayment\Enums\RepaymentTransactionType;
use Modules\Repayment\Enums\StatusHistorySource;
use Modules\Repayment\Enums\StatusHistoryType;
use Modules\Repayment\Models\RepaymentAccount;
use Modules\Repayment\Models\RepaymentCharge;
use Modules\Repayment\Models\RepaymentStatusHistory;
use Modules\Repayment\Models\RepaymentTransaction;
use Modules\Repayment\Services\RepaymentTransactionNumberGenerator;

final class ForeclosureService
{
    public function __construct(
        private readonly RepaymentTransactionNumberGenerator $transactionNumberGenerator
    ) {}

    /**
     * Post a full-and-final foreclosure transaction, freeze every remaining
     * installment without marking it PAID, and close the repayment account.
     *
     * The caller must run this method inside a DB transaction and pass an
     * account row already locked with lockForUpdate().
     */
    public function execute(
        RepaymentAccount $account,
        RepaymentCharge $charge,
        float $foreclosureAmount,
        string $foreclosureDate,
        PaymentMode $paymentMode,
        ?string $transactionReference = null,
        ?string $remarks = null,
        ?int $actorId = null
    ): Foreclosure {
        if (! in_array($account->status, [
            RepaymentAccountStatus::ACTIVE,
            RepaymentAccountStatus::SUSPENDED,
        ], true)) {
            throw new InvalidArgumentException(
                'Only active or suspended accounts can be foreclosed.'
            );
        }

        if (Foreclosure::query()
            ->where('repayment_account_id', $account->id)
            ->exists()) {
            throw new InvalidArgumentException(
                'This repayment account has already been foreclosed.'
            );
        }

        $amount = $this->money($foreclosureAmount);
        if (bccomp($amount, '0.00', 2) !== 1) {
            throw new InvalidArgumentException(
                'Foreclosure amount must be greater than zero.'
            );
        }

        if ($paymentMode->requiresReference() && blank($transactionReference)) {
            throw new InvalidArgumentException(
                'Transaction reference is required for the selected payment mode.'
            );
        }

        $postingTimestamp = now();
        $oldAccountStatus = $account->status;
        $principalSnapshot = $this->money($account->principal_outstanding);
        $interestSnapshot = $this->money($account->interest_outstanding);
        $totalSnapshot = $this->money($account->total_outstanding);

        $transaction = RepaymentTransaction::query()->create([
            'repayment_account_id' => $account->id,
            'transaction_number' => $this->transactionNumberGenerator
                ->generateTemporary(),
            'idempotency_key' => 'FORECLOSURE_' . $account->uuid,
            'transaction_type' => RepaymentTransactionType::FORECLOSURE->value,
            'amount' => $amount,

            /*
             * This is an account-level full-and-final settlement. It does not
             * allocate money into future EMIs because that would make them
             * appear PAID. The component snapshot is stored in metadata and
             * the whole transaction is treated as consumed by foreclosure.
             */
            'allocated_amount' => $amount,
            'unallocated_amount' => '0.00',
            'payment_mode' => $paymentMode->value,
            'transaction_reference' => $transactionReference,
            'received_date' => $foreclosureDate,
            'value_date' => $foreclosureDate,
            'status' => RepaymentTransactionStatus::POSTED->value,
            'posted_at' => $postingTimestamp,
            'remarks' => $remarks
                ?: 'Full-and-final payment received for loan foreclosure.',
            'metadata' => [
                'posting_source' => 'FORECLOSURE',
                'allocation_strategy' => 'ACCOUNT_LEVEL_FULL_AND_FINAL',
                'principal_outstanding_before_closure' => $principalSnapshot,
                'interest_outstanding_before_closure' => $interestSnapshot,
                'total_outstanding_before_closure' => $totalSnapshot,
                'settlement_variance' => bcsub($totalSnapshot, $amount, 2),
                'foreclosure_charge_total' => $this->money($charge->total_amount),
                'foreclosure_charge_waived' => $this->money($charge->waived_amount),
                'foreclosure_charge_net_payable' => $this->money($charge->net_payable),
            ],
            'created_by' => $actorId,
            'approved_by' => $actorId,
        ]);

        $transaction->forceFill([
            'transaction_number' => $this->transactionNumberGenerator
                ->generateFinal((int) $transaction->id, $postingTimestamp),
        ])->save();

        $remainingInstallments = $account->installments()
            ->whereIn('payment_status', [
                InstallmentPaymentStatus::UNPAID->value,
                InstallmentPaymentStatus::PARTIAL->value,
            ])
            ->orderBy('due_date')
            ->orderBy('installment_number')
            ->lockForUpdate()
            ->get();

        foreach ($remainingInstallments as $installment) {
            $oldPaymentStatus = $installment->payment_status;
            $outstandingBeforeClosure = $this->money(
                $installment->outstanding_amount
            );

            $installment->forceFill([
                // Keep principal_paid/interest_paid unchanged for audit.
                'outstanding_amount' => '0.00',
                'payment_status' => InstallmentPaymentStatus::FORECLOSED->value,
                'fully_paid_at' => null,
            ])->save();

            RepaymentStatusHistory::query()->create([
                'repayment_account_id' => $account->id,
                'repayment_installment_id' => $installment->id,
                'repayment_transaction_id' => $transaction->id,
                'status_type' => StatusHistoryType::PAYMENT_STATUS->value,
                'old_status' => $oldPaymentStatus->value,
                'new_status' => InstallmentPaymentStatus::FORECLOSED->value,
                'source' => StatusHistorySource::ACCOUNT_CLOSURE->value,
                'reason' => 'Remaining installment frozen because the loan was foreclosed.',
                'metadata' => [
                    'foreclosure_date' => $foreclosureDate,
                    'outstanding_before_closure' => $outstandingBeforeClosure,
                    'principal_paid_preserved' => $this->money($installment->principal_paid),
                    'interest_paid_preserved' => $this->money($installment->interest_paid),
                ],
                'changed_by' => $actorId,
                'changed_at' => $postingTimestamp,
            ]);
        }

        $account->forceFill([
            'principal_outstanding' => '0.00',
            'interest_outstanding' => '0.00',
            'total_outstanding' => '0.00',
            'overdue_amount' => '0.00',
            'next_due_date' => null,
            'total_received' => bcadd(
                (string) $account->total_received,
                $amount,
                2
            ),
            'last_payment_at' => $postingTimestamp,
            'status' => RepaymentAccountStatus::CLOSED->value,
            'closed_at' => $postingTimestamp,
            'updated_by' => $actorId,
        ])->save();

        $foreclosure = Foreclosure::query()->create([
            'repayment_account_id' => $account->id,
            'repayment_transaction_id' => $transaction->id,
            'repayment_charge_id' => $charge->id,
            'foreclosure_amount' => $amount,
            'foreclosure_date' => $foreclosureDate,
            'foreclosure_charges' => $this->money($charge->total_amount),
            'waived_charges' => $this->money($charge->waived_amount),
            'net_foreclosure_charges' => $this->money($charge->net_payable),
            'principal_outstanding' => $principalSnapshot,
            'interest_outstanding' => $interestSnapshot,
            'total_outstanding' => $totalSnapshot,
            'payment_mode' => $paymentMode->value,
            'transaction_reference' => $transactionReference,
            'remarks' => $remarks,
            'metadata' => [
                'loan_number' => $account->loan_number,
                'previous_account_status' => $oldAccountStatus->value,
                'frozen_installments_count' => $remainingInstallments->count(),
                'settlement_variance' => bcsub($totalSnapshot, $amount, 2),
            ],
            'foreclosed_by' => $actorId,
        ]);

        RepaymentStatusHistory::query()->create([
            'repayment_account_id' => $account->id,
            'repayment_transaction_id' => $transaction->id,
            'status_type' => StatusHistoryType::ACCOUNT_STATUS->value,
            'old_status' => $oldAccountStatus->value,
            'new_status' => RepaymentAccountStatus::CLOSED->value,
            'source' => StatusHistorySource::ACCOUNT_CLOSURE->value,
            'reason' => 'Loan foreclosed after full-and-final payment.',
            'metadata' => [
                'foreclosure_uuid' => $foreclosure->uuid,
                'foreclosure_amount' => $amount,
                'foreclosure_charge_net_payable' => $this->money($charge->net_payable),
            ],
            'changed_by' => $actorId,
            'changed_at' => $postingTimestamp,
        ]);

        return $foreclosure->fresh([
            'account',
            'transaction',
            'charge',
            'foreclosedBy',
        ]);
    }

    private function money(mixed $value): string
    {
        return number_format(
            round((float) ($value ?? 0), 2, PHP_ROUND_HALF_UP),
            2,
            '.',
            ''
        );
    }
}
