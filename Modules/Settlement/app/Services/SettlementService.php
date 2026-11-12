<?php

namespace Modules\Settlement\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Repayment\Enums\InstallmentPaymentStatus;
use Modules\Repayment\Enums\RepaymentAccountStatus;
use Modules\Repayment\Enums\RepaymentTransactionStatus;
use Modules\Repayment\Enums\RepaymentTransactionType;
use Modules\Repayment\Enums\StatusHistorySource;
use Modules\Repayment\Enums\StatusHistoryType;
use Modules\Repayment\Models\RepaymentAccount;
use Modules\Repayment\Models\RepaymentStatusHistory;
use Modules\Repayment\Models\RepaymentTransaction;
use Modules\Repayment\Services\RepaymentTransactionNumberGenerator;
use Modules\Settlement\Enums\SettlementStatus;
use Modules\Settlement\Models\RepaymentSettlement;
use RuntimeException;

class SettlementService
{
    public function __construct(
        private readonly RepaymentTransactionNumberGenerator $transactionNumberGenerator
    ) {}

    /**
     * Create a negotiated full-and-final settlement proposal.
     * The snapshot amount is retained so Settlement UI can always show
     * "amount due when moved to settlement" vs "customer settled at".
     */
    public function propose(
        RepaymentAccount $account,
        float $settlementAmount,
        string $reason,
        ?int $proposedBy = null
    ): RepaymentSettlement {
        if ($this->hasOpenProposal($account)) {
            throw new InvalidArgumentException('This account already has an open settlement proposal.');
        }

        $totalOutstanding = (float) $account->total_outstanding;

        if ($settlementAmount <= 0) {
            throw new InvalidArgumentException('Settlement amount must be greater than zero.');
        }

        if ($settlementAmount >= $totalOutstanding) {
            throw new InvalidArgumentException('Settlement amount must be less than the total outstanding — otherwise this is a normal repayment, not a settlement.');
        }

        return RepaymentSettlement::create([
            'repayment_account_id' => $account->id,
            'total_outstanding_at_proposal' => $totalOutstanding,
            'settlement_amount' => round($settlementAmount, 2),
            'waived_amount' => round($totalOutstanding - $settlementAmount, 2),
            'status' => SettlementStatus::PROPOSED->value,
            'reason' => $reason,
            'proposed_by' => $proposedBy,
        ]);
    }

    public function approve(RepaymentSettlement $settlement, ?string $remarks, ?int $approvedBy): RepaymentSettlement
    {
        $this->assertStatus($settlement, SettlementStatus::PROPOSED);

        $settlement->update([
            'status' => SettlementStatus::APPROVED->value,
            'approved_by' => $approvedBy,
            'approved_at' => now(),
            'decision_remarks' => $remarks,
        ]);

        return $settlement->fresh();
    }

    public function reject(RepaymentSettlement $settlement, string $remarks, ?int $approvedBy): RepaymentSettlement
    {
        $this->assertStatus($settlement, SettlementStatus::PROPOSED);

        $settlement->update([
            'status' => SettlementStatus::REJECTED->value,
            'approved_by' => $approvedBy,
            'approved_at' => now(),
            'decision_remarks' => $remarks,
        ]);

        return $settlement->fresh();
    }

    /**
     * Collect only the negotiated settlement amount. Remaining future EMIs
     * are frozen as SETTLED (not PAID), which keeps the audit trail honest
     * and prevents those EMIs from becoming pending again later.
     */
    public function markPaidAndClose(
        RepaymentSettlement $settlement,
        ?string $paidReference,
        ?int $actorId = null,
        ?string $settlementDate = null
    ): RepaymentSettlement {
        return DB::transaction(function () use ($settlement, $paidReference, $actorId, $settlementDate) {
            $settlement = RepaymentSettlement::where('id', $settlement->id)
                ->lockForUpdate()
                ->first();

            $this->assertStatus($settlement, SettlementStatus::APPROVED);

            $account = RepaymentAccount::where('id', $settlement->repayment_account_id)
                ->lockForUpdate()
                ->first();

            $oldAccountStatus = $account->status;
            $idempotencyKey = 'SETTLEMENT_' . $settlement->uuid;
            $postingTimestamp = now();
            $effectiveDate = $settlementDate ?: $postingTimestamp->toDateString();
            $settlementAmount = number_format((float) $settlement->settlement_amount, 2, '.', '');

            if (!RepaymentTransaction::where('idempotency_key', $idempotencyKey)->exists()) {
                $installments = $account->installments()
                    ->whereIn('payment_status', [
                        InstallmentPaymentStatus::UNPAID->value,
                        InstallmentPaymentStatus::PARTIAL->value,
                    ])
                    ->orderBy('due_date')
                    ->orderBy('installment_number')
                    ->lockForUpdate()
                    ->get();

                $transaction = RepaymentTransaction::create([
                    'repayment_account_id' => $account->id,
                    'transaction_number' => $this->transactionNumberGenerator->generateTemporary(),
                    'idempotency_key' => $idempotencyKey,
                    'transaction_type' => RepaymentTransactionType::SETTLEMENT->value,
                    'amount' => $settlementAmount,
                    'allocated_amount' => $settlementAmount,
                    'unallocated_amount' => '0.00',
                    'transaction_reference' => $paidReference,
                    'received_date' => $effectiveDate,
                    'value_date' => $effectiveDate,
                    'status' => RepaymentTransactionStatus::POSTED->value,
                    'posted_at' => $postingTimestamp,
                    'remarks' => 'Negotiated full-and-final settlement payment.',
                    'metadata' => [
                        'posting_source' => 'SETTLEMENT',
                        'allocation_strategy' => 'ACCOUNT_LEVEL_FULL_AND_FINAL',
                        'settlement_uuid' => $settlement->uuid,
                        'total_outstanding_at_proposal' => (string) $settlement->total_outstanding_at_proposal,
                        'settlement_amount_collected' => $settlementAmount,
                        'waived_amount' => (string) $settlement->waived_amount,
                        'frozen_installments_count' => $installments->count(),
                    ],
                    'created_by' => $actorId,
                    'approved_by' => $actorId,
                ]);

                $transaction->forceFill([
                    'transaction_number' => $this->transactionNumberGenerator->generateFinal(
                        (int) $transaction->id,
                        $postingTimestamp
                    ),
                ])->save();

                foreach ($installments as $installment) {
                    $oldPaymentStatus = $installment->payment_status;
                    $outstandingBeforeClosure = (string) $installment->outstanding_amount;

                    $installment->forceFill([
                        // Do not fabricate principal/interest paid for a write-off.
                        'outstanding_amount' => '0.00',
                        'payment_status' => InstallmentPaymentStatus::SETTLED->value,
                        'fully_paid_at' => null,
                    ])->save();

                    RepaymentStatusHistory::create([
                        'repayment_account_id' => $account->id,
                        'repayment_installment_id' => $installment->id,
                        'repayment_transaction_id' => $transaction->id,
                        'status_type' => StatusHistoryType::PAYMENT_STATUS->value,
                        'old_status' => $oldPaymentStatus->value,
                        'new_status' => InstallmentPaymentStatus::SETTLED->value,
                        'source' => StatusHistorySource::ACCOUNT_CLOSURE->value,
                        'reason' => 'Remaining installment frozen because the loan was settled.',
                        'metadata' => [
                            'settlement_date' => $effectiveDate,
                            'outstanding_before_closure' => $outstandingBeforeClosure,
                            'settlement_uuid' => $settlement->uuid,
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
                    'total_received' => bcadd((string) $account->total_received, $settlementAmount, 2),
                    'next_due_date' => null,
                    'last_payment_at' => $postingTimestamp,
                    'status' => RepaymentAccountStatus::SETTLED->value,
                    'closed_at' => $postingTimestamp,
                    'updated_by' => $actorId,
                ])->save();

                RepaymentStatusHistory::create([
                    'repayment_account_id' => $account->id,
                    'repayment_transaction_id' => $transaction->id,
                    'status_type' => StatusHistoryType::ACCOUNT_STATUS->value,
                    'old_status' => $oldAccountStatus->value,
                    'new_status' => RepaymentAccountStatus::SETTLED->value,
                    'source' => StatusHistorySource::ACCOUNT_CLOSURE->value,
                    'reason' => 'Negotiated full-and-final settlement paid and closed.',
                    'metadata' => [
                        'settlement_uuid' => $settlement->uuid,
                        'settlement_amount' => $settlementAmount,
                        'waived_amount' => (string) $settlement->waived_amount,
                        'settlement_date' => $effectiveDate,
                    ],
                    'changed_by' => $actorId,
                    'changed_at' => $postingTimestamp,
                ]);
            }

            $settlement->update([
                'status' => SettlementStatus::PAID->value,
                'settlement_date' => $effectiveDate,
                'paid_at' => $postingTimestamp,
                'paid_reference' => $paidReference,
            ]);

            return $settlement->fresh(['account']);
        });
    }

    private function hasOpenProposal(RepaymentAccount $account): bool
    {
        return RepaymentSettlement::query()
            ->where('repayment_account_id', $account->id)
            ->whereIn('status', [SettlementStatus::PROPOSED->value, SettlementStatus::APPROVED->value])
            ->exists();
    }

    private function assertStatus(RepaymentSettlement $settlement, SettlementStatus $expected): void
    {
        if ($settlement->status !== $expected) {
            throw new RuntimeException("This settlement is {$settlement->status->label()}, not {$expected->label()}.");
        }
    }
}