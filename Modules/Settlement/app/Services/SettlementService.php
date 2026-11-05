<?php

namespace Modules\Settlement\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Repayment\Enums\AllocationComponent;
use Modules\Repayment\Enums\InstallmentPaymentStatus;
use Modules\Repayment\Enums\RepaymentAccountStatus;
use Modules\Repayment\Enums\RepaymentTransactionStatus;
use Modules\Repayment\Enums\RepaymentTransactionType;
use Modules\Repayment\Enums\StatusHistorySource;
use Modules\Repayment\Enums\StatusHistoryType;
use Modules\Repayment\Models\RepaymentAccount;
use Modules\Repayment\Models\RepaymentAllocation;
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
     * Propose a full-and-final settlement: the customer pays
     * settlement_amount (less than what's actually outstanding) and the
     * remainder is written off once paid.
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
            'settlement_amount' => $settlementAmount,
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
     * Record that the customer paid the settlement amount, write off the
     * remaining outstanding balance across installments so the ledger
     * actually zeroes out (not just a status flag flip), and close the loan.
     */
    public function markPaidAndClose(RepaymentSettlement $settlement, ?string $paidReference, ?int $actorId = null): RepaymentSettlement
    {
        return DB::transaction(function () use ($settlement, $paidReference, $actorId) {
            $settlement = RepaymentSettlement::where('id', $settlement->id)->lockForUpdate()->first();
            $this->assertStatus($settlement, SettlementStatus::APPROVED);

            $account = RepaymentAccount::where('id', $settlement->repayment_account_id)->lockForUpdate()->first();
            $oldAccountStatus = $account->status;

            $idempotencyKey = 'SETTLEMENT_' . $settlement->uuid;
            $postingTimestamp = now();

            if (!RepaymentTransaction::where('idempotency_key', $idempotencyKey)->exists()) {
                $installments = $account->installments()
                    ->where('outstanding_amount', '>', 0)
                    ->orderBy('due_date')
                    ->orderBy('installment_number')
                    ->lockForUpdate()
                    ->get();

                $transaction = RepaymentTransaction::create([
                    'repayment_account_id' => $account->id,
                    'transaction_number' => $this->transactionNumberGenerator->generateTemporary(),
                    'idempotency_key' => $idempotencyKey,
                    'transaction_type' => RepaymentTransactionType::ADJUSTMENT->value,
                    'amount' => $account->total_outstanding,
                    'allocated_amount' => $account->total_outstanding,
                    'unallocated_amount' => '0.00',
                    'transaction_reference' => $paidReference,
                    'received_date' => $postingTimestamp->toDateString(),
                    'value_date' => $postingTimestamp->toDateString(),
                    'status' => RepaymentTransactionStatus::POSTED->value,
                    'posted_at' => $postingTimestamp,
                    'remarks' => 'Full-and-final settlement closure.',
                    'metadata' => [
                        'settlement_uuid' => $settlement->uuid,
                        'settlement_amount_collected' => (string) $settlement->settlement_amount,
                        'waived_amount' => (string) $settlement->waived_amount,
                    ],
                    'created_by' => $actorId,
                    'approved_by' => $actorId,
                ]);

                $transaction->transaction_number = $this->transactionNumberGenerator->generateFinal(
                    (int) $transaction->id,
                    $postingTimestamp
                );
                $transaction->save();

                $allocationOrder = 1;

                foreach ($installments as $installment) {
                    $remainingInterest = bcsub((string) $installment->interest_due, (string) $installment->interest_paid, 2);
                    $remainingPrincipal = bcsub((string) $installment->principal_due, (string) $installment->principal_paid, 2);

                    if (bccomp($remainingInterest, '0.00', 2) === 1) {
                        RepaymentAllocation::create([
                            'repayment_transaction_id' => $transaction->id,
                            'repayment_installment_id' => $installment->id,
                            'component' => AllocationComponent::INTEREST->value,
                            'amount' => $remainingInterest,
                            'allocation_order' => $allocationOrder++,
                            'metadata' => ['reason' => 'settlement_write_off'],
                        ]);
                    }

                    if (bccomp($remainingPrincipal, '0.00', 2) === 1) {
                        RepaymentAllocation::create([
                            'repayment_transaction_id' => $transaction->id,
                            'repayment_installment_id' => $installment->id,
                            'component' => AllocationComponent::PRINCIPAL->value,
                            'amount' => $remainingPrincipal,
                            'allocation_order' => $allocationOrder++,
                            'metadata' => ['reason' => 'settlement_write_off'],
                        ]);
                    }

                    $installment->forceFill([
                        'principal_paid' => $installment->principal_due,
                        'interest_paid' => $installment->interest_due,
                        'outstanding_amount' => '0.00',
                        'payment_status' => InstallmentPaymentStatus::PAID->value,
                        'last_payment_at' => $postingTimestamp,
                        'fully_paid_at' => $postingTimestamp,
                    ])->save();
                }

                $account->forceFill([
                    'principal_outstanding' => '0.00',
                    'interest_outstanding' => '0.00',
                    'total_outstanding' => '0.00',
                    'overdue_amount' => '0.00',
                    'total_received' => bcadd((string) $account->total_received, (string) $settlement->settlement_amount, 2),
                    'next_due_date' => null,
                    'last_payment_at' => $postingTimestamp,
                    'status' => RepaymentAccountStatus::CLOSED->value,
                    'closed_at' => $postingTimestamp,
                    'updated_by' => $actorId,
                ])->save();

                RepaymentStatusHistory::create([
                    'repayment_account_id' => $account->id,
                    'repayment_transaction_id' => $transaction->id,
                    'status_type' => StatusHistoryType::ACCOUNT_STATUS->value,
                    'old_status' => $oldAccountStatus->value,
                    'new_status' => RepaymentAccountStatus::CLOSED->value,
                    'source' => StatusHistorySource::ACCOUNT_CLOSURE->value,
                    'reason' => 'Full-and-final settlement paid and closed.',
                    'metadata' => [
                        'settlement_uuid' => $settlement->uuid,
                        'settlement_amount' => (string) $settlement->settlement_amount,
                        'waived_amount' => (string) $settlement->waived_amount,
                    ],
                    'changed_by' => $actorId,
                    'changed_at' => $postingTimestamp,
                ]);
            }

            $settlement->update([
                'status' => SettlementStatus::PAID->value,
                'paid_at' => now(),
                'paid_reference' => $paidReference,
            ]);

            return $settlement->fresh();
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
