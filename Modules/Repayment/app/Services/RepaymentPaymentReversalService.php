<?php

namespace Modules\Repayment\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Repayment\Enums\InstallmentPaymentStatus;
use Modules\Repayment\Enums\RepaymentAccountStatus;
use Modules\Repayment\Enums\RepaymentTransactionStatus;
use Modules\Repayment\Enums\RepaymentTransactionType;
use Modules\Repayment\Enums\StatusHistorySource;
use Modules\Repayment\Enums\StatusHistoryType;
use Modules\Repayment\Exceptions\RepaymentPaymentReversalException;
use Modules\Repayment\Models\RepaymentAccount;
use Modules\Repayment\Models\RepaymentAllocation;
use Modules\Repayment\Models\RepaymentInstallment;
use Modules\Repayment\Models\RepaymentStatusHistory;
use Modules\Repayment\Models\RepaymentTransaction;

final class RepaymentPaymentReversalService
{
    public function __construct(
        private readonly RepaymentTransactionNumberGenerator
            $transactionNumberGenerator,

        private readonly RepaymentReceiptService
            $receiptService
    ) {}

    /**
     * Reverse a successfully posted customer payment.
     *
     * @param array<string, mixed> $payload
     */
    public function reverse(
        string $accountUuid,
        string $transactionUuid,
        array $payload,
        ?int $actorId = null
    ): RepaymentTransaction {
        try {
            return DB::transaction(
                fn (): RepaymentTransaction =>
                    $this->reverseInsideTransaction(
                        accountUuid: $accountUuid,
                        transactionUuid: $transactionUuid,
                        payload: $payload,
                        actorId: $actorId
                    ),
                attempts: 3
            );
        } catch (QueryException $exception) {
            if (! $this->isDuplicateKeyException($exception)) {
                throw $exception;
            }

            return $this->resolveConcurrentDuplicate(
                accountUuid: $accountUuid,
                transactionUuid: $transactionUuid,
                payload: $payload,
                exception: $exception
            );
        }
    }

    /**
     * Execute complete reversal inside one database transaction.
     *
     * @param array<string, mixed> $payload
     */
    private function reverseInsideTransaction(
        string $accountUuid,
        string $transactionUuid,
        array $payload,
        ?int $actorId
    ): RepaymentTransaction {
        $account = RepaymentAccount::query()
            ->useWritePdo()
            ->where('uuid', $accountUuid)
            ->lockForUpdate()
            ->first();

        if (! $account) {
            throw RepaymentPaymentReversalException::accountNotFound(
                $accountUuid
            );
        }

        $originalTransaction = RepaymentTransaction::query()
            ->useWritePdo()
            ->where(
                'repayment_account_id',
                $account->id
            )
            ->where('uuid', $transactionUuid)
            ->lockForUpdate()
            ->first();

        if (! $originalTransaction) {
            throw RepaymentPaymentReversalException::
                transactionNotFound(
                    accountUuid: $accountUuid,
                    transactionUuid: $transactionUuid
                );
        }

        $idempotencyKey = $this->normalizeRequiredString(
            $payload['idempotency_key'] ?? ''
        );

        /*
         * Idempotency check eligibility validation se pehle.
         *
         * Successful first reversal ke baad original transaction
         * REVERSED ho jayegi. Same request retry par existing
         * reversal safely return honi chahiye.
         */
        $existingByIdempotency =
            RepaymentTransaction::query()
                ->useWritePdo()
                ->where(
                    'idempotency_key',
                    $idempotencyKey
                )
                ->lockForUpdate()
                ->first();

        if ($existingByIdempotency) {
            if (
                $this->requestMatchesReversal(
                    account: $account,
                    originalTransaction: $originalTransaction,
                    reversalTransaction:
                        $existingByIdempotency,
                    payload: $payload
                )
            ) {
                return $this->hydrateTransaction(
                    $existingByIdempotency
                );
            }

            throw RepaymentPaymentReversalException::
                idempotencyConflict(
                    idempotencyKey: $idempotencyKey,
                    existingTransactionUuid:
                        (string) $existingByIdempotency->uuid
                );
        }

        $existingReversal = RepaymentTransaction::query()
            ->useWritePdo()
            ->where(
                'reversal_of_transaction_id',
                $originalTransaction->id
            )
            ->lockForUpdate()
            ->first();

        if ($existingReversal) {
            throw RepaymentPaymentReversalException::
                alreadyReversed(
                    transactionUuid:
                        (string) $originalTransaction->uuid,

                    reversalTransactionUuid:
                        (string) $existingReversal->uuid
                );
        }

        $this->assertTransactionIsReversible(
            $originalTransaction
        );

        $reversalDate = $this->normalizeRequiredString(
            $payload['reversal_date'] ?? ''
        );

        $valueDate = $this->normalizeRequiredString(
            $payload['value_date'] ?? ''
        );

        $reason = $this->normalizeRequiredString(
            $payload['reason'] ?? ''
        );

        $originalReceivedDate = optional(
            $originalTransaction->received_date
        )->format('Y-m-d');

        if (
            ! $originalReceivedDate
            || $reversalDate < $originalReceivedDate
        ) {
            throw RepaymentPaymentReversalException::
                invalidReversalDate(
                    originalReceivedDate:
                        $originalReceivedDate ?? '',

                    reversalDate: $reversalDate
                );
        }

        $originalAllocations = RepaymentAllocation::query()
            ->useWritePdo()
            ->where(
                'repayment_transaction_id',
                $originalTransaction->id
            )
            ->orderBy('allocation_order')
            ->lockForUpdate()
            ->get();

        $this->assertValidOriginalAllocationState(
            transaction: $originalTransaction,
            allocations: $originalAllocations
        );

        $originalAmount = $this->normalizeMoney(
            $originalTransaction->amount
        );

        $originalAllocatedAmount = $this->normalizeMoney(
            $originalTransaction->allocated_amount
        );

        $originalUnallocatedAmount = $this->normalizeMoney(
            $originalTransaction->unallocated_amount
        );

        $availableAdvance = $this->normalizeMoney(
            $account->advance_amount
        );

        if (
            bccomp(
                $availableAdvance,
                $originalUnallocatedAmount,
                2
            ) === -1
        ) {
            throw RepaymentPaymentReversalException::
                insufficientAdvanceBalance(
                    accountUuid: (string) $account->uuid,

                    requiredAmount:
                        $originalUnallocatedAmount,

                    availableAmount:
                        $availableAdvance
                );
        }

        $availableTotalReceived = $this->normalizeMoney(
            $account->total_received
        );

        if (
            bccomp(
                $availableTotalReceived,
                $originalAmount,
                2
            ) === -1
        ) {
            throw RepaymentPaymentReversalException::
                financialInvariantViolation(
                    message: 'Account total received is lower than the payment being reversed.',
                    context: [
                        'repayment_account_uuid' =>
                            $account->uuid,

                        'account_total_received' =>
                            $availableTotalReceived,

                        'reversal_amount' =>
                            $originalAmount,
                    ]
                );
        }

        $requestFingerprint =
            $this->buildRequestFingerprint(
                accountUuid: $accountUuid,
                transactionUuid: $transactionUuid,
                reversalDate: $reversalDate,
                valueDate: $valueDate,
                reason: $reason
            );

        $reversalTransaction =
            RepaymentTransaction::query()->create([
                'repayment_account_id' =>
                    $account->id,

                'transaction_number' =>
                    $this->transactionNumberGenerator
                        ->generateTemporary(),

                'idempotency_key' =>
                    $idempotencyKey,

                'transaction_type' =>
                    RepaymentTransactionType::REVERSAL
                        ->value,

                /*
                 * Reversal amount positive store hota hai.
                 * Direction REVERSAL transaction type se aati hai.
                 */
                'amount' => $originalAmount,

                'allocated_amount' =>
                    $originalAllocatedAmount,

                'unallocated_amount' =>
                    $originalUnallocatedAmount,

                'payment_mode' => null,
                'transaction_reference' => null,

                'received_date' => $reversalDate,
                'value_date' => $valueDate,

                'status' =>
                    RepaymentTransactionStatus::PENDING
                        ->value,

                'reversal_of_transaction_id' =>
                    $originalTransaction->id,

                'remarks' => $reason,

                'metadata' => [
                    'request_fingerprint' =>
                        $requestFingerprint,

                    'original_transaction_uuid' =>
                        $originalTransaction->uuid,

                    'original_transaction_number' =>
                        $originalTransaction
                            ->transaction_number,

                    'reversal_strategy' =>
                        'MIRROR_ORIGINAL_ALLOCATIONS',
                ],

                'created_by' => $actorId,
            ]);

        $reversalTransaction->transaction_number =
            $this->transactionNumberGenerator->generateFinal(
                transactionId:
                    (int) $reversalTransaction->id,

                businessDate: $reversalDate
            );

        $reversalTransaction->save();

        $postingTimestamp = now();

        $restorationResult =
            $this->restoreInstallmentAllocations(
                account: $account,
                originalTransaction:
                    $originalTransaction,
                reversalTransaction:
                    $reversalTransaction,
                originalAllocations:
                    $originalAllocations,
                actorId: $actorId,
                postingTimestamp: $postingTimestamp
            );

        $reversalTransaction->forceFill([
            'status' =>
                RepaymentTransactionStatus::POSTED->value,

            'posted_at' => $postingTimestamp,
            'approved_by' => $actorId,

            'metadata' => array_merge(
                $reversalTransaction->metadata ?? [],
                [
                    'allocation_count' =>
                        $restorationResult[
                            'allocation_count'
                        ],

                    'interest_restored' =>
                        $restorationResult[
                            'interest_restored'
                        ],

                    'principal_restored' =>
                        $restorationResult[
                            'principal_restored'
                        ],

                    'advance_reversed' =>
                        $originalUnallocatedAmount,
                ]
            ),
        ])->save();

        $originalTransaction->forceFill([
            'status' =>
                RepaymentTransactionStatus::REVERSED->value,

            'metadata' => array_merge(
                $originalTransaction->metadata ?? [],
                [
                    'reversed_at' =>
                        $postingTimestamp->toIso8601String(),

                    'reversed_by' => $actorId,

                    'reversal_transaction_uuid' =>
                        $reversalTransaction->uuid,

                    'reversal_reason' => $reason,
                ]
            ),
        ])->save();

        /*
         * Related receipt is preserved for audit and marked
         * VOID after successful payment reversal.
         *
         * Older payments may not have a receipt, so a missing
         * receipt is accepted.
         */
        $this->receiptService->voidIfExistsForTransaction(
            transaction: $originalTransaction,
            reason: sprintf(
                'Payment reversed through transaction %s. Reason: %s',
                $reversalTransaction->transaction_number,
                $reason
            ),
            actorId: $actorId
        );

        $this->refreshAccountAfterReversal(
            account: $account,
            originalTransaction:
                $originalTransaction,
            reversalTransaction:
                $reversalTransaction,
            actorId: $actorId,
            postingTimestamp: $postingTimestamp
        );

        return $this->hydrateTransaction(
            $reversalTransaction
        );
    }

    /**
     * Restore original allocation amounts back to installments.
     *
     * @param Collection<int, RepaymentAllocation> $originalAllocations
     *
     * @return array{
     *     allocation_count: int,
     *     interest_restored: string,
     *     principal_restored: string
     * }
     */
    private function restoreInstallmentAllocations(
        RepaymentAccount $account,
        RepaymentTransaction $originalTransaction,
        RepaymentTransaction $reversalTransaction,
        Collection $originalAllocations,
        ?int $actorId,
        mixed $postingTimestamp
    ): array {
        if ($originalAllocations->isEmpty()) {
            return [
                'allocation_count' => 0,
                'interest_restored' => '0.00',
                'principal_restored' => '0.00',
            ];
        }

        $installmentIds = $originalAllocations
            ->pluck('repayment_installment_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->sort()
            ->values();

        $installments = RepaymentInstallment::query()
            ->useWritePdo()
            ->whereIn('id', $installmentIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        if ($installments->count() !== $installmentIds->count()) {
            throw RepaymentPaymentReversalException::
                invalidAllocationState(
                    transactionUuid:
                        (string) $originalTransaction->uuid,

                    details: [
                        'reason' =>
                            'One or more allocated installments no longer exist.',
                    ]
                );
        }

        $allocationOrder = 1;
        $allocationCount = 0;
        $interestRestored = '0.00';
        $principalRestored = '0.00';

        $groupedAllocations = $originalAllocations
            ->groupBy('repayment_installment_id')
            ->sortBy(
                fn (Collection $group) =>
                    (int) $group->min('allocation_order')
            );

        foreach (
            $groupedAllocations as $installmentId => $group
        ) {
            /** @var RepaymentInstallment|null $installment */
            $installment = $installments->get(
                (int) $installmentId
            );

            if (
                ! $installment
                || (int) $installment
                    ->repayment_account_id
                    !== (int) $account->id
            ) {
                throw RepaymentPaymentReversalException::
                    invalidAllocationState(
                        transactionUuid:
                            (string) $originalTransaction
                                ->uuid,

                        details: [
                            'installment_id' =>
                                (int) $installmentId,

                            'reason' =>
                                'Allocation installment does not belong to the repayment account.',
                        ]
                    );
            }

            $oldPaymentStatus =
                $installment->payment_status;

            $interestToRestore = '0.00';
            $principalToRestore = '0.00';

            foreach (
                $group->sortBy('allocation_order')
                as $originalAllocation
            ) {
                $amount = $this->normalizeMoney(
                    $originalAllocation->amount
                );

                if ($originalAllocation->isInterest()) {
                    $interestToRestore = bcadd(
                        $interestToRestore,
                        $amount,
                        2
                    );
                } elseif (
                    $originalAllocation->isPrincipal()
                ) {
                    $principalToRestore = bcadd(
                        $principalToRestore,
                        $amount,
                        2
                    );
                } else {
                    throw RepaymentPaymentReversalException::
                        invalidAllocationState(
                            transactionUuid:
                                (string) $originalTransaction
                                    ->uuid,

                            details: [
                                'allocation_uuid' =>
                                    $originalAllocation->uuid,

                                'reason' =>
                                    'Unsupported allocation component.',
                            ]
                        );
                }

                /*
                 * Reversal allocation positive amount store karti
                 * hai. Parent transaction type financial direction
                 * represent karta hai.
                 */
                RepaymentAllocation::query()->create([
                    'repayment_transaction_id' =>
                        $reversalTransaction->id,

                    'repayment_installment_id' =>
                        $installment->id,

                    'component' =>
                        $originalAllocation
                            ->component
                            ->value,

                    'amount' => $amount,

                    'allocation_order' =>
                        $allocationOrder++,

                    'metadata' => [
                        'original_allocation_uuid' =>
                            $originalAllocation->uuid,

                        'original_transaction_uuid' =>
                            $originalTransaction->uuid,

                        'installment_number' =>
                            $installment
                                ->installment_number,

                        'reversal' => true,
                    ],
                ]);

                $allocationCount++;
            }

            $currentInterestPaid =
                $this->normalizeMoney(
                    $installment->interest_paid
                );

            $currentPrincipalPaid =
                $this->normalizeMoney(
                    $installment->principal_paid
                );

            if (
                bccomp(
                    $currentInterestPaid,
                    $interestToRestore,
                    2
                ) === -1
                || bccomp(
                    $currentPrincipalPaid,
                    $principalToRestore,
                    2
                ) === -1
            ) {
                throw RepaymentPaymentReversalException::
                    invalidAllocationState(
                        transactionUuid:
                            (string) $originalTransaction
                                ->uuid,

                        details: [
                            'installment_uuid' =>
                                $installment->uuid,

                            'current_interest_paid' =>
                                $currentInterestPaid,

                            'interest_to_restore' =>
                                $interestToRestore,

                            'current_principal_paid' =>
                                $currentPrincipalPaid,

                            'principal_to_restore' =>
                                $principalToRestore,
                        ]
                    );
            }

            $newInterestPaid = bcsub(
                $currentInterestPaid,
                $interestToRestore,
                2
            );

            $newPrincipalPaid = bcsub(
                $currentPrincipalPaid,
                $principalToRestore,
                2
            );

            /*
             * Restore the reversed allocation back into the rounded
             * collectible outstanding. Do not rebuild outstanding from exact
             * principal_due + interest_due because that would reintroduce the
             * paise residual that caused PARTIAL EMIs.
             */
            $restoredAmount = bcadd(
                $interestToRestore,
                $principalToRestore,
                2
            );

            $maximumOutstanding =
                $this->roundWholeMoney(
                    $installment->installment_amount
                );

            $newOutstanding = bcadd(
                $this->roundWholeMoney(
                    $installment->outstanding_amount
                ),
                $restoredAmount,
                2
            );

            if (
                bccomp(
                    $newOutstanding,
                    $maximumOutstanding,
                    2
                ) === 1
            ) {
                $newOutstanding =
                    $maximumOutstanding;
            }

            $newOutstanding =
                $this->roundWholeMoney(
                    $newOutstanding
                );

            $newTotalPaid = bcadd(
                $newInterestPaid,
                $newPrincipalPaid,
                2
            );

            $newPaymentStatus = match (true) {
                bccomp($newOutstanding, '0.00', 2) === 0 =>
                    InstallmentPaymentStatus::PAID,

                bccomp($newTotalPaid, '0.00', 2) === 1 =>
                    InstallmentPaymentStatus::PARTIAL,

                default =>
                    InstallmentPaymentStatus::UNPAID,
            };

            /*
             * Original transaction is still POSTED at this point
             * inside the database transaction, therefore it must
             * be explicitly excluded from timestamp recomputation.
             */
            $remainingLastPaymentAt =
                RepaymentAllocation::query()
                    ->join(
                        'repayment_transactions',
                        'repayment_transactions.id',
                        '=',
                        'repayment_allocations.repayment_transaction_id'
                    )
                    ->where(
                        'repayment_allocations.repayment_installment_id',
                        $installment->id
                    )
                    ->where(
                        'repayment_transactions.repayment_account_id',
                        $account->id
                    )
                    ->where(
                        'repayment_transactions.transaction_type',
                        RepaymentTransactionType::PAYMENT->value
                    )
                    ->where(
                        'repayment_transactions.status',
                        RepaymentTransactionStatus::POSTED->value
                    )
                    ->where(
                        'repayment_transactions.id',
                        '!=',
                        $originalTransaction->id
                    )
                    ->max(
                        'repayment_transactions.posted_at'
                    );

            $installment->forceFill([
                'interest_paid' => $newInterestPaid,
                'principal_paid' => $newPrincipalPaid,
                'outstanding_amount' => $newOutstanding,
                'payment_status' =>
                    $newPaymentStatus->value,

                'last_payment_at' =>
                    $remainingLastPaymentAt,

                'fully_paid_at' =>
                    $newPaymentStatus
                        === InstallmentPaymentStatus::PAID
                            ? $remainingLastPaymentAt
                            : null,
            ])->save();

            app(RepaymentInstallmentDueStatusSyncService::class)->sync(
                $installment,
                StatusHistorySource::PAYMENT_REVERSAL,
                $reversalTransaction,
                $actorId
            );

            if ($oldPaymentStatus !== $newPaymentStatus) {
                RepaymentStatusHistory::query()->create([
                    'repayment_account_id' =>
                        $account->id,

                    'repayment_installment_id' =>
                        $installment->id,

                    'repayment_transaction_id' =>
                        $reversalTransaction->id,

                    'status_type' =>
                        StatusHistoryType::PAYMENT_STATUS
                            ->value,

                    'old_status' =>
                        $oldPaymentStatus->value,

                    'new_status' =>
                        $newPaymentStatus->value,

                    'source' =>
                        StatusHistorySource::PAYMENT_REVERSAL
                            ->value,

                    'reason' =>
                        'Original repayment payment allocation was reversed.',

                    'metadata' => [
                        'original_transaction_uuid' =>
                            $originalTransaction->uuid,

                        'interest_restored' =>
                            $interestToRestore,

                        'principal_restored' =>
                            $principalToRestore,

                        'outstanding_after_reversal' =>
                            $newOutstanding,
                    ],

                    'changed_by' => $actorId,
                    'changed_at' => $postingTimestamp,
                ]);
            }

            $interestRestored = bcadd(
                $interestRestored,
                $interestToRestore,
                2
            );

            $principalRestored = bcadd(
                $principalRestored,
                $principalToRestore,
                2
            );
        }

        return [
            'allocation_count' => $allocationCount,
            'interest_restored' => $interestRestored,
            'principal_restored' => $principalRestored,
        ];
    }

    /**
     * Recalculate account totals after restoring installments.
     */
    private function refreshAccountAfterReversal(
        RepaymentAccount $account,
        RepaymentTransaction $originalTransaction,
        RepaymentTransaction $reversalTransaction,
        ?int $actorId,
        mixed $postingTimestamp
    ): void {
        $totals = RepaymentInstallment::query()
            ->where(
                'repayment_account_id',
                $account->id
            )
            ->selectRaw(
                '
                COALESCE(
                    SUM(
                        LEAST(
                            GREATEST(
                                interest_due - interest_paid,
                                0
                            ),
                            ROUND(outstanding_amount, 0)
                        )
                    ),
                    0
                ) AS interest_outstanding,

                COALESCE(
                    SUM(
                        ROUND(outstanding_amount, 0)
                    ),
                    0
                ) AS total_outstanding,

                COALESCE(
                    SUM(
                        CASE
                            WHEN due_status = ?
                            THEN ROUND(outstanding_amount, 0)
                            ELSE 0
                        END
                    ),
                    0
                ) AS overdue_amount
                ',
                ['OVERDUE']
            )
            ->first();

        $totalOutstanding = $this->roundWholeMoney(
            $totals?->total_outstanding ?? '0'
        );

        $interestOutstanding = $this->roundWholeMoney(
            $totals?->interest_outstanding ?? '0'
        );

        if (
            bccomp(
                $interestOutstanding,
                $totalOutstanding,
                2
            ) === 1
        ) {
            $interestOutstanding = $totalOutstanding;
        }

        $principalOutstanding = bcsub(
            $totalOutstanding,
            $interestOutstanding,
            2
        );

        $overdueAmount = $this->roundWholeMoney(
            $totals?->overdue_amount ?? '0'
        );

        $componentTotal = bcadd(
            $principalOutstanding,
            $interestOutstanding,
            2
        );

        if (
            bccomp(
                $componentTotal,
                $totalOutstanding,
                2
            ) !== 0
        ) {
            throw RepaymentPaymentReversalException::
                financialInvariantViolation(
                    message: 'Account component balances do not match total outstanding after reversal.',
                    context: [
                        'repayment_account_uuid' =>
                            $account->uuid,

                        'principal_outstanding' =>
                            $principalOutstanding,

                        'interest_outstanding' =>
                            $interestOutstanding,

                        'total_outstanding' =>
                            $totalOutstanding,
                    ]
                );
        }

        $newAdvanceAmount = bcsub(
            (string) $account->advance_amount,
            (string) $originalTransaction
                ->unallocated_amount,
            2
        );

        $newTotalReceived = bcsub(
            (string) $account->total_received,
            (string) $originalTransaction->amount,
            2
        );

        if (
            bccomp($newAdvanceAmount, '0.00', 2) === -1
            || bccomp(
                $newTotalReceived,
                '0.00',
                2
            ) === -1
        ) {
            throw RepaymentPaymentReversalException::
                financialInvariantViolation(
                    message: 'Account collection balances became negative after reversal.',
                    context: [
                        'repayment_account_uuid' =>
                            $account->uuid,

                        'advance_amount_after_reversal' =>
                            $newAdvanceAmount,

                        'total_received_after_reversal' =>
                            $newTotalReceived,
                    ]
                );
        }

        $nextDueDate = RepaymentInstallment::query()
            ->where(
                'repayment_account_id',
                $account->id
            )
            ->whereRaw(
                'ROUND(outstanding_amount, 0) > 0'
            )
            ->orderBy('due_date')
            ->orderBy('installment_number')
            ->value('due_date');

        $lastPostedPaymentAt =
            RepaymentTransaction::query()
                ->where(
                    'repayment_account_id',
                    $account->id
                )
                ->where(
                    'transaction_type',
                    RepaymentTransactionType::PAYMENT->value
                )
                ->where(
                    'status',
                    RepaymentTransactionStatus::POSTED->value
                )
                ->max('posted_at');

        $oldAccountStatus = $account->status;

        /*
         * Reversal CLOSED account ko reopen karegi.
         * SUSPENDED/CANCELLED state silently ACTIVE nahi hogi.
         */
        $newAccountStatus = match (true) {
            bccomp(
                $totalOutstanding,
                '0.00',
                2
            ) === 1
                && $oldAccountStatus
                    === RepaymentAccountStatus::CLOSED =>
                RepaymentAccountStatus::ACTIVE,

            default => $oldAccountStatus,
        };

        $account->forceFill([
            'principal_outstanding' =>
                $principalOutstanding,

            'interest_outstanding' =>
                $interestOutstanding,

            'total_outstanding' =>
                $totalOutstanding,

            'overdue_amount' =>
                $overdueAmount,

            'advance_amount' =>
                $newAdvanceAmount,

            'total_received' =>
                $newTotalReceived,

            'next_due_date' =>
                $nextDueDate,

            'last_payment_at' =>
                $lastPostedPaymentAt,

            'status' =>
                $newAccountStatus->value,

            'closed_at' =>
                $newAccountStatus
                    === RepaymentAccountStatus::CLOSED
                        ? $account->closed_at
                        : null,

            'updated_by' => $actorId,
        ])->save();

        if ($oldAccountStatus !== $newAccountStatus) {
            RepaymentStatusHistory::query()->create([
                'repayment_account_id' =>
                    $account->id,

                'repayment_transaction_id' =>
                    $reversalTransaction->id,

                'status_type' =>
                    StatusHistoryType::ACCOUNT_STATUS->value,

                'old_status' =>
                    $oldAccountStatus->value,

                'new_status' =>
                    $newAccountStatus->value,

                'source' =>
                    StatusHistorySource::PAYMENT_REVERSAL->value,

                'reason' =>
                    'Repayment account reopened after payment reversal.',

                'metadata' => [
                    'original_transaction_uuid' =>
                        $originalTransaction->uuid,

                    'principal_outstanding' =>
                        $principalOutstanding,

                    'interest_outstanding' =>
                        $interestOutstanding,

                    'total_outstanding' =>
                        $totalOutstanding,
                ],

                'changed_by' => $actorId,
                'changed_at' => $postingTimestamp,
            ]);
        }
    }

    /**
     * Validate transaction type and posting status.
     */
    private function assertTransactionIsReversible(
        RepaymentTransaction $transaction
    ): void {
        if (
            $transaction->transaction_type
                !== RepaymentTransactionType::PAYMENT
            || $transaction->status
                !== RepaymentTransactionStatus::POSTED
        ) {
            throw RepaymentPaymentReversalException::
                transactionNotReversible(
                    transactionUuid:
                        (string) $transaction->uuid,

                    transactionType:
                        $transaction->transaction_type->value,

                    transactionStatus:
                        $transaction->status->value
                );
        }
    }

    /**
     * Validate original transaction allocation totals.
     *
     * @param Collection<int, RepaymentAllocation> $allocations
     */
    private function assertValidOriginalAllocationState(
        RepaymentTransaction $transaction,
        Collection $allocations
    ): void {
        $allocationTotal = '0.00';

        foreach ($allocations as $allocation) {
            $amount = $this->normalizeMoney(
                $allocation->amount
            );

            if (bccomp($amount, '0.00', 2) !== 1) {
                throw RepaymentPaymentReversalException::
                    invalidAllocationState(
                        transactionUuid:
                            (string) $transaction->uuid,

                        details: [
                            'allocation_uuid' =>
                                $allocation->uuid,

                            'allocation_amount' =>
                                $amount,
                        ]
                    );
            }

            $allocationTotal = bcadd(
                $allocationTotal,
                $amount,
                2
            );
        }

        $allocatedAmount = $this->normalizeMoney(
            $transaction->allocated_amount
        );

        $unallocatedAmount = $this->normalizeMoney(
            $transaction->unallocated_amount
        );

        $transactionAmount = $this->normalizeMoney(
            $transaction->amount
        );

        if (
            bccomp(
                $allocationTotal,
                $allocatedAmount,
                2
            ) !== 0
        ) {
            throw RepaymentPaymentReversalException::
                invalidAllocationState(
                    transactionUuid:
                        (string) $transaction->uuid,

                    details: [
                        'allocation_rows_total' =>
                            $allocationTotal,

                        'stored_allocated_amount' =>
                            $allocatedAmount,
                    ]
                );
        }

        $componentTotal = bcadd(
            $allocatedAmount,
            $unallocatedAmount,
            2
        );

        if (
            bccomp(
                $componentTotal,
                $transactionAmount,
                2
            ) !== 0
        ) {
            throw RepaymentPaymentReversalException::
                invalidAllocationState(
                    transactionUuid:
                        (string) $transaction->uuid,

                    details: [
                        'allocated_amount' =>
                            $allocatedAmount,

                        'unallocated_amount' =>
                            $unallocatedAmount,

                        'component_total' =>
                            $componentTotal,

                        'transaction_amount' =>
                            $transactionAmount,
                    ]
                );
        }
    }

    /**
     * Compare repeated reversal request with stored transaction.
     *
     * @param array<string, mixed> $payload
     */
    private function requestMatchesReversal(
        RepaymentAccount $account,
        RepaymentTransaction $originalTransaction,
        RepaymentTransaction $reversalTransaction,
        array $payload
    ): bool {
        if (
            (int) $reversalTransaction
                ->repayment_account_id
                !== (int) $account->id
            || $reversalTransaction->transaction_type
                !== RepaymentTransactionType::REVERSAL
            || (int) $reversalTransaction
                ->reversal_of_transaction_id
                !== (int) $originalTransaction->id
        ) {
            return false;
        }

        $fingerprint = $this->buildRequestFingerprint(
            accountUuid: (string) $account->uuid,
            transactionUuid:
                (string) $originalTransaction->uuid,

            reversalDate:
                $this->normalizeRequiredString(
                    $payload['reversal_date'] ?? ''
                ),

            valueDate:
                $this->normalizeRequiredString(
                    $payload['value_date'] ?? ''
                ),

            reason:
                $this->normalizeRequiredString(
                    $payload['reason'] ?? ''
                )
        );

        $storedFingerprint =
            $reversalTransaction
                ->metadata['request_fingerprint']
                ?? null;

        return is_string($storedFingerprint)
            && hash_equals(
                $storedFingerprint,
                $fingerprint
            );
    }

    /**
     * Resolve duplicate-key race after transaction rollback.
     *
     * @param array<string, mixed> $payload
     */
    private function resolveConcurrentDuplicate(
        string $accountUuid,
        string $transactionUuid,
        array $payload,
        QueryException $exception
    ): RepaymentTransaction {
        $idempotencyKey = $this->normalizeRequiredString(
            $payload['idempotency_key'] ?? ''
        );

        $account = RepaymentAccount::query()
            ->useWritePdo()
            ->where('uuid', $accountUuid)
            ->first();

        if (! $account) {
            throw RepaymentPaymentReversalException::accountNotFound(
                $accountUuid
            );
        }

        $originalTransaction =
            RepaymentTransaction::query()
                ->useWritePdo()
                ->where(
                    'repayment_account_id',
                    $account->id
                )
                ->where('uuid', $transactionUuid)
                ->first();

        if (! $originalTransaction) {
            throw RepaymentPaymentReversalException::
                transactionNotFound(
                    accountUuid: $accountUuid,
                    transactionUuid: $transactionUuid
                );
        }

        $existingByIdempotency =
            RepaymentTransaction::query()
                ->useWritePdo()
                ->where(
                    'idempotency_key',
                    $idempotencyKey
                )
                ->first();

        if ($existingByIdempotency) {
            if (
                $this->requestMatchesReversal(
                    account: $account,
                    originalTransaction:
                        $originalTransaction,
                    reversalTransaction:
                        $existingByIdempotency,
                    payload: $payload
                )
            ) {
                return $this->hydrateTransaction(
                    $existingByIdempotency
                );
            }

            throw RepaymentPaymentReversalException::
                idempotencyConflict(
                    idempotencyKey: $idempotencyKey,

                    existingTransactionUuid:
                        (string) $existingByIdempotency->uuid
                );
        }

        $existingReversal = RepaymentTransaction::query()
            ->useWritePdo()
            ->where(
                'reversal_of_transaction_id',
                $originalTransaction->id
            )
            ->first();

        if ($existingReversal) {
            throw RepaymentPaymentReversalException::
                alreadyReversed(
                    transactionUuid:
                        (string) $originalTransaction->uuid,

                    reversalTransactionUuid:
                        (string) $existingReversal->uuid
                );
        }

        throw $exception;
    }

    /**
     * Canonical request fingerprint.
     */
    private function buildRequestFingerprint(
        string $accountUuid,
        string $transactionUuid,
        string $reversalDate,
        string $valueDate,
        string $reason
    ): string {
        return hash(
            'sha256',
            json_encode([
                'repayment_account_uuid' =>
                    $accountUuid,

                'original_transaction_uuid' =>
                    $transactionUuid,

                'reversal_date' =>
                    $reversalDate,

                'value_date' =>
                    $valueDate,

                'reason' =>
                    $reason,
            ], JSON_THROW_ON_ERROR)
        );
    }

    /**
     * Load response relationships consistently.
     */
    private function hydrateTransaction(
        RepaymentTransaction $transaction
    ): RepaymentTransaction {
        $transaction->refresh();

        $transaction->load([
            'receipt',
            'reversalOf',
            'reversal',

            'allocations' => fn ($query) =>
                $query->allocationOrder(),
        ]);

        $transaction->loadCount('allocations');

        return $transaction;
    }

    /**
     * Round collectible/outstanding to a whole rupee while preserving
     * two-decimal database formatting.
     */
    private function roundWholeMoney(mixed $value): string
    {
        return number_format(
            round(
                (float) ($value ?? 0),
                0,
                PHP_ROUND_HALF_UP
            ),
            2,
            '.',
            ''
        );
    }

    /**
     * Normalize money to two decimal places.
     */
    private function normalizeMoney(mixed $value): string
    {
        return bcadd(
            trim((string) $value),
            '0.00',
            2
        );
    }

    private function normalizeRequiredString(
        mixed $value
    ): string {
        return trim((string) $value);
    }

    /**
     * MariaDB/MySQL duplicate-key detection.
     */
    private function isDuplicateKeyException(
        QueryException $exception
    ): bool {
        $sqlState = $exception->errorInfo[0] ?? null;
        $driverCode = $exception->errorInfo[1] ?? null;

        return $sqlState === '23000'
            && (int) $driverCode === 1062;
    }
}

// namespace Modules\Repayment\Services;

// use Illuminate\Database\QueryException;
// use Illuminate\Support\Collection;
// use Illuminate\Support\Facades\DB;
// use Modules\Repayment\Enums\InstallmentPaymentStatus;
// use Modules\Repayment\Enums\RepaymentAccountStatus;
// use Modules\Repayment\Enums\RepaymentTransactionStatus;
// use Modules\Repayment\Enums\RepaymentTransactionType;
// use Modules\Repayment\Enums\StatusHistorySource;
// use Modules\Repayment\Enums\StatusHistoryType;
// use Modules\Repayment\Exceptions\RepaymentPaymentReversalException;
// use Modules\Repayment\Models\RepaymentAccount;
// use Modules\Repayment\Models\RepaymentAllocation;
// use Modules\Repayment\Models\RepaymentInstallment;
// use Modules\Repayment\Models\RepaymentStatusHistory;
// use Modules\Repayment\Models\RepaymentTransaction;

// final class RepaymentPaymentReversalService
// {
//     public function __construct(
//         private readonly RepaymentTransactionNumberGenerator
//         $transactionNumberGenerator,

//         private readonly RepaymentReceiptService
//         $receiptService
//     ) {
//     }

//     /**
//      * Reverse a successfully posted customer payment.
//      *
//      * @param array<string, mixed> $payload
//      */
//     public function reverse(
//         string $accountUuid,
//         string $transactionUuid,
//         array $payload,
//         ?int $actorId = null
//     ): RepaymentTransaction {
//         try {
//             return DB::transaction(
//                 fn(): RepaymentTransaction =>
//                     $this->reverseInsideTransaction(
//                         accountUuid: $accountUuid,
//                         transactionUuid: $transactionUuid,
//                         payload: $payload,
//                         actorId: $actorId
//                     ),
//                 attempts: 3
//             );
//         } catch (QueryException $exception) {
//             if (!$this->isDuplicateKeyException($exception)) {
//                 throw $exception;
//             }

//             return $this->resolveConcurrentDuplicate(
//                 accountUuid: $accountUuid,
//                 transactionUuid: $transactionUuid,
//                 payload: $payload,
//                 exception: $exception
//             );
//         }
//     }

//     /**
//      * Execute complete reversal inside one database transaction.
//      *
//      * @param array<string, mixed> $payload
//      */
//     private function reverseInsideTransaction(
//         string $accountUuid,
//         string $transactionUuid,
//         array $payload,
//         ?int $actorId
//     ): RepaymentTransaction {
//         $account = RepaymentAccount::query()
//             ->useWritePdo()
//             ->where('uuid', $accountUuid)
//             ->lockForUpdate()
//             ->first();

//         if (!$account) {
//             throw RepaymentPaymentReversalException::accountNotFound(
//                 $accountUuid
//             );
//         }

//         $originalTransaction = RepaymentTransaction::query()
//             ->useWritePdo()
//             ->where(
//                 'repayment_account_id',
//                 $account->id
//             )
//             ->where('uuid', $transactionUuid)
//             ->lockForUpdate()
//             ->first();

//         if (!$originalTransaction) {
//             throw RepaymentPaymentReversalException::
//                 transactionNotFound(
//                     accountUuid: $accountUuid,
//                     transactionUuid: $transactionUuid
//                 );
//         }

//         $idempotencyKey = $this->normalizeRequiredString(
//             $payload['idempotency_key'] ?? ''
//         );

//         /*
//          * Idempotency check eligibility validation se pehle.
//          *
//          * Successful first reversal ke baad original transaction
//          * REVERSED ho jayegi. Same request retry par existing
//          * reversal safely return honi chahiye.
//          */
//         $existingByIdempotency =
//             RepaymentTransaction::query()
//                 ->useWritePdo()
//                 ->where(
//                     'idempotency_key',
//                     $idempotencyKey
//                 )
//                 ->lockForUpdate()
//                 ->first();

//         if ($existingByIdempotency) {
//             if (
//                 $this->requestMatchesReversal(
//                     account: $account,
//                     originalTransaction: $originalTransaction,
//                     reversalTransaction:
//                     $existingByIdempotency,
//                     payload: $payload
//                 )
//             ) {
//                 return $this->hydrateTransaction(
//                     $existingByIdempotency
//                 );
//             }

//             throw RepaymentPaymentReversalException::
//                 idempotencyConflict(
//                     idempotencyKey: $idempotencyKey,
//                     existingTransactionUuid:
//                     (string) $existingByIdempotency->uuid
//                 );
//         }

//         $existingReversal = RepaymentTransaction::query()
//             ->useWritePdo()
//             ->where(
//                 'reversal_of_transaction_id',
//                 $originalTransaction->id
//             )
//             ->lockForUpdate()
//             ->first();

//         if ($existingReversal) {
//             throw RepaymentPaymentReversalException::
//                 alreadyReversed(
//                     transactionUuid:
//                     (string) $originalTransaction->uuid,

//                     reversalTransactionUuid:
//                     (string) $existingReversal->uuid
//                 );
//         }

//         $this->assertTransactionIsReversible(
//             $originalTransaction
//         );

//         $reversalDate = $this->normalizeRequiredString(
//             $payload['reversal_date'] ?? ''
//         );

//         $valueDate = $this->normalizeRequiredString(
//             $payload['value_date'] ?? ''
//         );

//         $reason = $this->normalizeRequiredString(
//             $payload['reason'] ?? ''
//         );

//         $originalReceivedDate = optional(
//             $originalTransaction->received_date
//         )->format('Y-m-d');

//         if (
//             !$originalReceivedDate
//             || $reversalDate < $originalReceivedDate
//         ) {
//             throw RepaymentPaymentReversalException::
//                 invalidReversalDate(
//                     originalReceivedDate:
//                     $originalReceivedDate ?? '',

//                     reversalDate: $reversalDate
//                 );
//         }

//         $originalAllocations = RepaymentAllocation::query()
//             ->useWritePdo()
//             ->where(
//                 'repayment_transaction_id',
//                 $originalTransaction->id
//             )
//             ->orderBy('allocation_order')
//             ->lockForUpdate()
//             ->get();

//         $this->assertValidOriginalAllocationState(
//             transaction: $originalTransaction,
//             allocations: $originalAllocations
//         );

//         $originalAmount = $this->normalizeMoney(
//             $originalTransaction->amount
//         );

//         $originalAllocatedAmount = $this->normalizeMoney(
//             $originalTransaction->allocated_amount
//         );

//         $originalUnallocatedAmount = $this->normalizeMoney(
//             $originalTransaction->unallocated_amount
//         );

//         $availableAdvance = $this->normalizeMoney(
//             $account->advance_amount
//         );

//         if (
//             bccomp(
//                 $availableAdvance,
//                 $originalUnallocatedAmount,
//                 2
//             ) === -1
//         ) {
//             throw RepaymentPaymentReversalException::
//                 insufficientAdvanceBalance(
//                     accountUuid: (string) $account->uuid,

//                     requiredAmount:
//                     $originalUnallocatedAmount,

//                     availableAmount:
//                     $availableAdvance
//                 );
//         }

//         $availableTotalReceived = $this->normalizeMoney(
//             $account->total_received
//         );

//         if (
//             bccomp(
//                 $availableTotalReceived,
//                 $originalAmount,
//                 2
//             ) === -1
//         ) {
//             throw RepaymentPaymentReversalException::
//                 financialInvariantViolation(
//                     message: 'Account total received is lower than the payment being reversed.',
//                     context: [
//                         'repayment_account_uuid' =>
//                             $account->uuid,

//                         'account_total_received' =>
//                             $availableTotalReceived,

//                         'reversal_amount' =>
//                             $originalAmount,
//                     ]
//                 );
//         }

//         $requestFingerprint =
//             $this->buildRequestFingerprint(
//                 accountUuid: $accountUuid,
//                 transactionUuid: $transactionUuid,
//                 reversalDate: $reversalDate,
//                 valueDate: $valueDate,
//                 reason: $reason
//             );

//         $reversalTransaction =
//             RepaymentTransaction::query()->create([
//                 'repayment_account_id' =>
//                     $account->id,

//                 'transaction_number' =>
//                     $this->transactionNumberGenerator
//                         ->generateTemporary(),

//                 'idempotency_key' =>
//                     $idempotencyKey,

//                 'transaction_type' =>
//                     RepaymentTransactionType::REVERSAL
//                         ->value,

//                 /*
//                  * Reversal amount positive store hota hai.
//                  * Direction REVERSAL transaction type se aati hai.
//                  */
//                 'amount' => $originalAmount,

//                 'allocated_amount' =>
//                     $originalAllocatedAmount,

//                 'unallocated_amount' =>
//                     $originalUnallocatedAmount,

//                 'payment_mode' => null,
//                 'transaction_reference' => null,

//                 'received_date' => $reversalDate,
//                 'value_date' => $valueDate,

//                 'status' =>
//                     RepaymentTransactionStatus::PENDING
//                         ->value,

//                 'reversal_of_transaction_id' =>
//                     $originalTransaction->id,

//                 'remarks' => $reason,

//                 'metadata' => [
//                     'request_fingerprint' =>
//                         $requestFingerprint,

//                     'original_transaction_uuid' =>
//                         $originalTransaction->uuid,

//                     'original_transaction_number' =>
//                         $originalTransaction
//                             ->transaction_number,

//                     'reversal_strategy' =>
//                         'MIRROR_ORIGINAL_ALLOCATIONS',
//                 ],

//                 'created_by' => $actorId,
//             ]);

//         $reversalTransaction->transaction_number =
//             $this->transactionNumberGenerator->generateFinal(
//                 transactionId:
//                 (int) $reversalTransaction->id,

//                 businessDate: $reversalDate
//             );

//         $reversalTransaction->save();

//         $postingTimestamp = now();

//         $restorationResult =
//             $this->restoreInstallmentAllocations(
//                 account: $account,
//                 originalTransaction:
//                 $originalTransaction,
//                 reversalTransaction:
//                 $reversalTransaction,
//                 originalAllocations:
//                 $originalAllocations,
//                 actorId: $actorId,
//                 postingTimestamp: $postingTimestamp
//             );

//         $reversalTransaction->forceFill([
//             'status' =>
//                 RepaymentTransactionStatus::POSTED->value,

//             'posted_at' => $postingTimestamp,
//             'approved_by' => $actorId,

//             'metadata' => array_merge(
//                 $reversalTransaction->metadata ?? [],
//                 [
//                     'allocation_count' =>
//                         $restorationResult[
//                             'allocation_count'
//                         ],

//                     'interest_restored' =>
//                         $restorationResult[
//                             'interest_restored'
//                         ],

//                     'principal_restored' =>
//                         $restorationResult[
//                             'principal_restored'
//                         ],

//                     'advance_reversed' =>
//                         $originalUnallocatedAmount,
//                 ]
//             ),
//         ])->save();

//         $originalTransaction->forceFill([
//             'status' =>
//                 RepaymentTransactionStatus::REVERSED->value,

//             'metadata' => array_merge(
//                 $originalTransaction->metadata ?? [],
//                 [
//                     'reversed_at' =>
//                         $postingTimestamp->toIso8601String(),

//                     'reversed_by' => $actorId,

//                     'reversal_transaction_uuid' =>
//                         $reversalTransaction->uuid,

//                     'reversal_reason' => $reason,
//                 ]
//             ),
//         ])->save();

//         /*
//          * Related receipt is preserved for audit and marked
//          * VOID after successful payment reversal.
//          *
//          * Older payments may not have a receipt, so a missing
//          * receipt is accepted.
//          */
//         $this->receiptService->voidIfExistsForTransaction(
//             transaction: $originalTransaction,
//             reason: sprintf(
//                 'Payment reversed through transaction %s. Reason: %s',
//                 $reversalTransaction->transaction_number,
//                 $reason
//             ),
//             actorId: $actorId
//         );

//         $this->refreshAccountAfterReversal(
//             account: $account,
//             originalTransaction:
//             $originalTransaction,
//             reversalTransaction:
//             $reversalTransaction,
//             actorId: $actorId,
//             postingTimestamp: $postingTimestamp
//         );

//         return $this->hydrateTransaction(
//             $reversalTransaction
//         );
//     }

//     /**
//      * Restore original allocation amounts back to installments.
//      *
//      * @param Collection<int, RepaymentAllocation> $originalAllocations
//      *
//      * @return array{
//      *     allocation_count: int,
//      *     interest_restored: string,
//      *     principal_restored: string
//      * }
//      */
//     private function restoreInstallmentAllocations(
//         RepaymentAccount $account,
//         RepaymentTransaction $originalTransaction,
//         RepaymentTransaction $reversalTransaction,
//         Collection $originalAllocations,
//         ?int $actorId,
//         mixed $postingTimestamp
//     ): array {
//         if ($originalAllocations->isEmpty()) {
//             return [
//                 'allocation_count' => 0,
//                 'interest_restored' => '0.00',
//                 'principal_restored' => '0.00',
//             ];
//         }

//         $installmentIds = $originalAllocations
//             ->pluck('repayment_installment_id')
//             ->map(fn($id): int => (int) $id)
//             ->unique()
//             ->sort()
//             ->values();

//         $installments = RepaymentInstallment::query()
//             ->useWritePdo()
//             ->whereIn('id', $installmentIds)
//             ->orderBy('id')
//             ->lockForUpdate()
//             ->get()
//             ->keyBy('id');

//         if ($installments->count() !== $installmentIds->count()) {
//             throw RepaymentPaymentReversalException::
//                 invalidAllocationState(
//                     transactionUuid:
//                     (string) $originalTransaction->uuid,

//                     details: [
//                         'reason' =>
//                             'One or more allocated installments no longer exist.',
//                     ]
//                 );
//         }

//         $allocationOrder = 1;
//         $allocationCount = 0;
//         $interestRestored = '0.00';
//         $principalRestored = '0.00';

//         $groupedAllocations = $originalAllocations
//             ->groupBy('repayment_installment_id')
//             ->sortBy(
//                 fn(Collection $group) =>
//                     (int) $group->min('allocation_order')
//             );

//         foreach (
//             $groupedAllocations as $installmentId => $group
//         ) {
//             /** @var RepaymentInstallment|null $installment */
//             $installment = $installments->get(
//                 (int) $installmentId
//             );

//             if (
//                 !$installment
//                 || (int) $installment
//                     ->repayment_account_id
//                 !== (int) $account->id
//             ) {
//                 throw RepaymentPaymentReversalException::
//                     invalidAllocationState(
//                         transactionUuid:
//                         (string) $originalTransaction
//                             ->uuid,

//                         details: [
//                             'installment_id' =>
//                                 (int) $installmentId,

//                             'reason' =>
//                                 'Allocation installment does not belong to the repayment account.',
//                         ]
//                     );
//             }

//             $oldPaymentStatus =
//                 $installment->payment_status;

//             $interestToRestore = '0.00';
//             $principalToRestore = '0.00';

//             foreach (
//                 $group->sortBy('allocation_order')
//                 as $originalAllocation
//             ) {
//                 $amount = $this->normalizeMoney(
//                     $originalAllocation->amount
//                 );

//                 if ($originalAllocation->isInterest()) {
//                     $interestToRestore = bcadd(
//                         $interestToRestore,
//                         $amount,
//                         2
//                     );
//                 } elseif (
//                     $originalAllocation->isPrincipal()
//                 ) {
//                     $principalToRestore = bcadd(
//                         $principalToRestore,
//                         $amount,
//                         2
//                     );
//                 } else {
//                     throw RepaymentPaymentReversalException::
//                         invalidAllocationState(
//                             transactionUuid:
//                             (string) $originalTransaction
//                                 ->uuid,

//                             details: [
//                                 'allocation_uuid' =>
//                                     $originalAllocation->uuid,

//                                 'reason' =>
//                                     'Unsupported allocation component.',
//                             ]
//                         );
//                 }

//                 /*
//                  * Reversal allocation positive amount store karti
//                  * hai. Parent transaction type financial direction
//                  * represent karta hai.
//                  */
//                 RepaymentAllocation::query()->create([
//                     'repayment_transaction_id' =>
//                         $reversalTransaction->id,

//                     'repayment_installment_id' =>
//                         $installment->id,

//                     'component' =>
//                         $originalAllocation
//                             ->component
//                             ->value,

//                     'amount' => $amount,

//                     'allocation_order' =>
//                         $allocationOrder++,

//                     'metadata' => [
//                         'original_allocation_uuid' =>
//                             $originalAllocation->uuid,

//                         'original_transaction_uuid' =>
//                             $originalTransaction->uuid,

//                         'installment_number' =>
//                             $installment
//                                 ->installment_number,

//                         'reversal' => true,
//                     ],
//                 ]);

//                 $allocationCount++;
//             }

//             $currentInterestPaid =
//                 $this->normalizeMoney(
//                     $installment->interest_paid
//                 );

//             $currentPrincipalPaid =
//                 $this->normalizeMoney(
//                     $installment->principal_paid
//                 );

//             if (
//                 bccomp(
//                     $currentInterestPaid,
//                     $interestToRestore,
//                     2
//                 ) === -1
//                 || bccomp(
//                     $currentPrincipalPaid,
//                     $principalToRestore,
//                     2
//                 ) === -1
//             ) {
//                 throw RepaymentPaymentReversalException::
//                     invalidAllocationState(
//                         transactionUuid:
//                         (string) $originalTransaction
//                             ->uuid,

//                         details: [
//                             'installment_uuid' =>
//                                 $installment->uuid,

//                             'current_interest_paid' =>
//                                 $currentInterestPaid,

//                             'interest_to_restore' =>
//                                 $interestToRestore,

//                             'current_principal_paid' =>
//                                 $currentPrincipalPaid,

//                             'principal_to_restore' =>
//                                 $principalToRestore,
//                         ]
//                     );
//             }

//             $newInterestPaid = bcsub(
//                 $currentInterestPaid,
//                 $interestToRestore,
//                 2
//             );

//             $newPrincipalPaid = bcsub(
//                 $currentPrincipalPaid,
//                 $principalToRestore,
//                 2
//             );

//             /*
//              * Restore the reversed allocation back into the rounded
//              * collectible outstanding. Do not rebuild outstanding from exact
//              * principal_due + interest_due because that would reintroduce the
//              * paise residual that caused PARTIAL EMIs.
//              */
//             $restoredAmount = bcadd(
//                 $interestToRestore,
//                 $principalToRestore,
//                 2
//             );

//             $maximumOutstanding =
//                 $this->roundWholeMoney(
//                     $installment->installment_amount
//                 );

//             $newOutstanding = bcadd(
//                 $this->roundWholeMoney(
//                     $installment->outstanding_amount
//                 ),
//                 $restoredAmount,
//                 2
//             );

//             if (
//                 bccomp(
//                     $newOutstanding,
//                     $maximumOutstanding,
//                     2
//                 ) === 1
//             ) {
//                 $newOutstanding =
//                     $maximumOutstanding;
//             }

//             $newOutstanding =
//                 $this->roundWholeMoney(
//                     $newOutstanding
//                 );

//             $newTotalPaid = bcadd(
//                 $newInterestPaid,
//                 $newPrincipalPaid,
//                 2
//             );

//             $newPaymentStatus = match (true) {
//                 bccomp($newOutstanding, '0.00', 2) === 0 =>
//                     InstallmentPaymentStatus::PAID,

//                 bccomp($newTotalPaid, '0.00', 2) === 1 =>
//                     InstallmentPaymentStatus::PARTIAL,

//                 default =>
//                     InstallmentPaymentStatus::UNPAID,
//             };

//             /*
//              * Original transaction is still POSTED at this point
//              * inside the database transaction, therefore it must
//              * be explicitly excluded from timestamp recomputation.
//              */
//             $remainingLastPaymentAt =
//                 RepaymentAllocation::query()
//                     ->join(
//                         'repayment_transactions',
//                         'repayment_transactions.id',
//                         '=',
//                         'repayment_allocations.repayment_transaction_id'
//                     )
//                     ->where(
//                         'repayment_allocations.repayment_installment_id',
//                         $installment->id
//                     )
//                     ->where(
//                         'repayment_transactions.repayment_account_id',
//                         $account->id
//                     )
//                     ->where(
//                         'repayment_transactions.transaction_type',
//                         RepaymentTransactionType::PAYMENT->value
//                     )
//                     ->where(
//                         'repayment_transactions.status',
//                         RepaymentTransactionStatus::POSTED->value
//                     )
//                     ->where(
//                         'repayment_transactions.id',
//                         '!=',
//                         $originalTransaction->id
//                     )
//                     ->max(
//                         'repayment_transactions.posted_at'
//                     );

//             $installment->forceFill([
//                 'interest_paid' => $newInterestPaid,
//                 'principal_paid' => $newPrincipalPaid,
//                 'outstanding_amount' => $newOutstanding,
//                 'payment_status' =>
//                     $newPaymentStatus->value,

//                 'last_payment_at' =>
//                     $remainingLastPaymentAt,

//                 'fully_paid_at' =>
//                     $newPaymentStatus
//                     === InstallmentPaymentStatus::PAID
//                     ? $remainingLastPaymentAt
//                     : null,
//             ])->save();

//             if ($oldPaymentStatus !== $newPaymentStatus) {
//                 RepaymentStatusHistory::query()->create([
//                     'repayment_account_id' =>
//                         $account->id,

//                     'repayment_installment_id' =>
//                         $installment->id,

//                     'repayment_transaction_id' =>
//                         $reversalTransaction->id,

//                     'status_type' =>
//                         StatusHistoryType::PAYMENT_STATUS
//                             ->value,

//                     'old_status' =>
//                         $oldPaymentStatus->value,

//                     'new_status' =>
//                         $newPaymentStatus->value,

//                     'source' =>
//                         StatusHistorySource::PAYMENT_REVERSAL
//                             ->value,

//                     'reason' =>
//                         'Original repayment payment allocation was reversed.',

//                     'metadata' => [
//                         'original_transaction_uuid' =>
//                             $originalTransaction->uuid,

//                         'interest_restored' =>
//                             $interestToRestore,

//                         'principal_restored' =>
//                             $principalToRestore,

//                         'outstanding_after_reversal' =>
//                             $newOutstanding,
//                     ],

//                     'changed_by' => $actorId,
//                     'changed_at' => $postingTimestamp,
//                 ]);
//             }

//             $interestRestored = bcadd(
//                 $interestRestored,
//                 $interestToRestore,
//                 2
//             );

//             $principalRestored = bcadd(
//                 $principalRestored,
//                 $principalToRestore,
//                 2
//             );
//         }

//         return [
//             'allocation_count' => $allocationCount,
//             'interest_restored' => $interestRestored,
//             'principal_restored' => $principalRestored,
//         ];
//     }

//     /**
//      * Recalculate account totals after restoring installments.
//      */
//     private function refreshAccountAfterReversal(
//         RepaymentAccount $account,
//         RepaymentTransaction $originalTransaction,
//         RepaymentTransaction $reversalTransaction,
//         ?int $actorId,
//         mixed $postingTimestamp
//     ): void {
//         $totals = RepaymentInstallment::query()
//             ->where(
//                 'repayment_account_id',
//                 $account->id
//             )
//             ->selectRaw(
//                 '
//                 COALESCE(
//                     SUM(
//                         LEAST(
//                             GREATEST(
//                                 interest_due - interest_paid,
//                                 0
//                             ),
//                             ROUND(outstanding_amount, 0)
//                         )
//                     ),
//                     0
//                 ) AS interest_outstanding,

//                 COALESCE(
//                     SUM(
//                         ROUND(outstanding_amount, 0)
//                     ),
//                     0
//                 ) AS total_outstanding,

//                 COALESCE(
//                     SUM(
//                         CASE
//                             WHEN due_status = ?
//                             THEN ROUND(outstanding_amount, 0)
//                             ELSE 0
//                         END
//                     ),
//                     0
//                 ) AS overdue_amount
//                 ',
//                 ['OVERDUE']
//             )
//             ->first();

//         $totalOutstanding = $this->roundWholeMoney(
//             $totals?->total_outstanding ?? '0'
//         );

//         $interestOutstanding = $this->roundWholeMoney(
//             $totals?->interest_outstanding ?? '0'
//         );

//         if (
//             bccomp(
//                 $interestOutstanding,
//                 $totalOutstanding,
//                 2
//             ) === 1
//         ) {
//             $interestOutstanding = $totalOutstanding;
//         }

//         $principalOutstanding = bcsub(
//             $totalOutstanding,
//             $interestOutstanding,
//             2
//         );

//         $overdueAmount = $this->roundWholeMoney(
//             $totals?->overdue_amount ?? '0'
//         );

//         $componentTotal = bcadd(
//             $principalOutstanding,
//             $interestOutstanding,
//             2
//         );

//         if (
//             bccomp(
//                 $componentTotal,
//                 $totalOutstanding,
//                 2
//             ) !== 0
//         ) {
//             throw RepaymentPaymentReversalException::
//                 financialInvariantViolation(
//                     message: 'Account component balances do not match total outstanding after reversal.',
//                     context: [
//                         'repayment_account_uuid' =>
//                             $account->uuid,

//                         'principal_outstanding' =>
//                             $principalOutstanding,

//                         'interest_outstanding' =>
//                             $interestOutstanding,

//                         'total_outstanding' =>
//                             $totalOutstanding,
//                     ]
//                 );
//         }

//         $newAdvanceAmount = bcsub(
//             (string) $account->advance_amount,
//             (string) $originalTransaction
//                 ->unallocated_amount,
//             2
//         );

//         $newTotalReceived = bcsub(
//             (string) $account->total_received,
//             (string) $originalTransaction->amount,
//             2
//         );

//         if (
//             bccomp($newAdvanceAmount, '0.00', 2) === -1
//             || bccomp(
//                 $newTotalReceived,
//                 '0.00',
//                 2
//             ) === -1
//         ) {
//             throw RepaymentPaymentReversalException::
//                 financialInvariantViolation(
//                     message: 'Account collection balances became negative after reversal.',
//                     context: [
//                         'repayment_account_uuid' =>
//                             $account->uuid,

//                         'advance_amount_after_reversal' =>
//                             $newAdvanceAmount,

//                         'total_received_after_reversal' =>
//                             $newTotalReceived,
//                     ]
//                 );
//         }

//         $nextDueDate = RepaymentInstallment::query()
//             ->where(
//                 'repayment_account_id',
//                 $account->id
//             )
//             ->whereRaw(
//                 'ROUND(outstanding_amount, 0) > 0'
//             )
//             ->orderBy('due_date')
//             ->orderBy('installment_number')
//             ->value('due_date');

//         $lastPostedPaymentAt =
//             RepaymentTransaction::query()
//                 ->where(
//                     'repayment_account_id',
//                     $account->id
//                 )
//                 ->where(
//                     'transaction_type',
//                     RepaymentTransactionType::PAYMENT->value
//                 )
//                 ->where(
//                     'status',
//                     RepaymentTransactionStatus::POSTED->value
//                 )
//                 ->max('posted_at');

//         $oldAccountStatus = $account->status;

//         /*
//          * Reversal CLOSED account ko reopen karegi.
//          * SUSPENDED/CANCELLED state silently ACTIVE nahi hogi.
//          */
//         $newAccountStatus = match (true) {
//             bccomp(
//                 $totalOutstanding,
//                 '0.00',
//                 2
//             ) === 1
//             && $oldAccountStatus
//             === RepaymentAccountStatus::CLOSED =>
//                 RepaymentAccountStatus::ACTIVE,

//             default => $oldAccountStatus,
//         };

//         $account->forceFill([
//             'principal_outstanding' =>
//                 $principalOutstanding,

//             'interest_outstanding' =>
//                 $interestOutstanding,

//             'total_outstanding' =>
//                 $totalOutstanding,

//             'overdue_amount' =>
//                 $overdueAmount,

//             'advance_amount' =>
//                 $newAdvanceAmount,

//             'total_received' =>
//                 $newTotalReceived,

//             'next_due_date' =>
//                 $nextDueDate,

//             'last_payment_at' =>
//                 $lastPostedPaymentAt,

//             'status' =>
//                 $newAccountStatus->value,

//             'closed_at' =>
//                 $newAccountStatus
//                 === RepaymentAccountStatus::CLOSED
//                 ? $account->closed_at
//                 : null,

//             'updated_by' => $actorId,
//         ])->save();

//         if ($oldAccountStatus !== $newAccountStatus) {
//             RepaymentStatusHistory::query()->create([
//                 'repayment_account_id' =>
//                     $account->id,

//                 'repayment_transaction_id' =>
//                     $reversalTransaction->id,

//                 'status_type' =>
//                     StatusHistoryType::ACCOUNT_STATUS->value,

//                 'old_status' =>
//                     $oldAccountStatus->value,

//                 'new_status' =>
//                     $newAccountStatus->value,

//                 'source' =>
//                     StatusHistorySource::PAYMENT_REVERSAL->value,

//                 'reason' =>
//                     'Repayment account reopened after payment reversal.',

//                 'metadata' => [
//                     'original_transaction_uuid' =>
//                         $originalTransaction->uuid,

//                     'principal_outstanding' =>
//                         $principalOutstanding,

//                     'interest_outstanding' =>
//                         $interestOutstanding,

//                     'total_outstanding' =>
//                         $totalOutstanding,
//                 ],

//                 'changed_by' => $actorId,
//                 'changed_at' => $postingTimestamp,
//             ]);
//         }
//     }

//     /**
//      * Validate transaction type and posting status.
//      */
//     private function assertTransactionIsReversible(
//         RepaymentTransaction $transaction
//     ): void {
//         if (
//             $transaction->transaction_type
//             !== RepaymentTransactionType::PAYMENT
//             || $transaction->status
//             !== RepaymentTransactionStatus::POSTED
//         ) {
//             throw RepaymentPaymentReversalException::
//                 transactionNotReversible(
//                     transactionUuid:
//                     (string) $transaction->uuid,

//                     transactionType:
//                     $transaction->transaction_type->value,

//                     transactionStatus:
//                     $transaction->status->value
//                 );
//         }
//     }

//     /**
//      * Validate original transaction allocation totals.
//      *
//      * @param Collection<int, RepaymentAllocation> $allocations
//      */
//     private function assertValidOriginalAllocationState(
//         RepaymentTransaction $transaction,
//         Collection $allocations
//     ): void {
//         $allocationTotal = '0.00';

//         foreach ($allocations as $allocation) {
//             $amount = $this->normalizeMoney(
//                 $allocation->amount
//             );

//             if (bccomp($amount, '0.00', 2) !== 1) {
//                 throw RepaymentPaymentReversalException::
//                     invalidAllocationState(
//                         transactionUuid:
//                         (string) $transaction->uuid,

//                         details: [
//                             'allocation_uuid' =>
//                                 $allocation->uuid,

//                             'allocation_amount' =>
//                                 $amount,
//                         ]
//                     );
//             }

//             $allocationTotal = bcadd(
//                 $allocationTotal,
//                 $amount,
//                 2
//             );
//         }

//         $allocatedAmount = $this->normalizeMoney(
//             $transaction->allocated_amount
//         );

//         $unallocatedAmount = $this->normalizeMoney(
//             $transaction->unallocated_amount
//         );

//         $transactionAmount = $this->normalizeMoney(
//             $transaction->amount
//         );

//         if (
//             bccomp(
//                 $allocationTotal,
//                 $allocatedAmount,
//                 2
//             ) !== 0
//         ) {
//             throw RepaymentPaymentReversalException::
//                 invalidAllocationState(
//                     transactionUuid:
//                     (string) $transaction->uuid,

//                     details: [
//                         'allocation_rows_total' =>
//                             $allocationTotal,

//                         'stored_allocated_amount' =>
//                             $allocatedAmount,
//                     ]
//                 );
//         }

//         $componentTotal = bcadd(
//             $allocatedAmount,
//             $unallocatedAmount,
//             2
//         );

//         if (
//             bccomp(
//                 $componentTotal,
//                 $transactionAmount,
//                 2
//             ) !== 0
//         ) {
//             throw RepaymentPaymentReversalException::
//                 invalidAllocationState(
//                     transactionUuid:
//                     (string) $transaction->uuid,

//                     details: [
//                         'allocated_amount' =>
//                             $allocatedAmount,

//                         'unallocated_amount' =>
//                             $unallocatedAmount,

//                         'component_total' =>
//                             $componentTotal,

//                         'transaction_amount' =>
//                             $transactionAmount,
//                     ]
//                 );
//         }
//     }

//     /**
//      * Compare repeated reversal request with stored transaction.
//      *
//      * @param array<string, mixed> $payload
//      */
//     private function requestMatchesReversal(
//         RepaymentAccount $account,
//         RepaymentTransaction $originalTransaction,
//         RepaymentTransaction $reversalTransaction,
//         array $payload
//     ): bool {
//         if (
//             (int) $reversalTransaction
//                 ->repayment_account_id
//             !== (int) $account->id
//             || $reversalTransaction->transaction_type
//             !== RepaymentTransactionType::REVERSAL
//             || (int) $reversalTransaction
//                 ->reversal_of_transaction_id
//             !== (int) $originalTransaction->id
//         ) {
//             return false;
//         }

//         $fingerprint = $this->buildRequestFingerprint(
//             accountUuid: (string) $account->uuid,
//             transactionUuid:
//             (string) $originalTransaction->uuid,

//             reversalDate:
//             $this->normalizeRequiredString(
//                 $payload['reversal_date'] ?? ''
//             ),

//             valueDate:
//             $this->normalizeRequiredString(
//                 $payload['value_date'] ?? ''
//             ),

//             reason:
//             $this->normalizeRequiredString(
//                 $payload['reason'] ?? ''
//             )
//         );

//         $storedFingerprint =
//             $reversalTransaction
//                 ->metadata['request_fingerprint']
//             ?? null;

//         return is_string($storedFingerprint)
//             && hash_equals(
//                 $storedFingerprint,
//                 $fingerprint
//             );
//     }

//     /**
//      * Resolve duplicate-key race after transaction rollback.
//      *
//      * @param array<string, mixed> $payload
//      */
//     private function resolveConcurrentDuplicate(
//         string $accountUuid,
//         string $transactionUuid,
//         array $payload,
//         QueryException $exception
//     ): RepaymentTransaction {
//         $idempotencyKey = $this->normalizeRequiredString(
//             $payload['idempotency_key'] ?? ''
//         );

//         $account = RepaymentAccount::query()
//             ->useWritePdo()
//             ->where('uuid', $accountUuid)
//             ->first();

//         if (!$account) {
//             throw RepaymentPaymentReversalException::accountNotFound(
//                 $accountUuid
//             );
//         }

//         $originalTransaction =
//             RepaymentTransaction::query()
//                 ->useWritePdo()
//                 ->where(
//                     'repayment_account_id',
//                     $account->id
//                 )
//                 ->where('uuid', $transactionUuid)
//                 ->first();

//         if (!$originalTransaction) {
//             throw RepaymentPaymentReversalException::
//                 transactionNotFound(
//                     accountUuid: $accountUuid,
//                     transactionUuid: $transactionUuid
//                 );
//         }

//         $existingByIdempotency =
//             RepaymentTransaction::query()
//                 ->useWritePdo()
//                 ->where(
//                     'idempotency_key',
//                     $idempotencyKey
//                 )
//                 ->first();

//         if ($existingByIdempotency) {
//             if (
//                 $this->requestMatchesReversal(
//                     account: $account,
//                     originalTransaction:
//                     $originalTransaction,
//                     reversalTransaction:
//                     $existingByIdempotency,
//                     payload: $payload
//                 )
//             ) {
//                 return $this->hydrateTransaction(
//                     $existingByIdempotency
//                 );
//             }

//             throw RepaymentPaymentReversalException::
//                 idempotencyConflict(
//                     idempotencyKey: $idempotencyKey,

//                     existingTransactionUuid:
//                     (string) $existingByIdempotency->uuid
//                 );
//         }

//         $existingReversal = RepaymentTransaction::query()
//             ->useWritePdo()
//             ->where(
//                 'reversal_of_transaction_id',
//                 $originalTransaction->id
//             )
//             ->first();

//         if ($existingReversal) {
//             throw RepaymentPaymentReversalException::
//                 alreadyReversed(
//                     transactionUuid:
//                     (string) $originalTransaction->uuid,

//                     reversalTransactionUuid:
//                     (string) $existingReversal->uuid
//                 );
//         }

//         throw $exception;
//     }

//     /**
//      * Canonical request fingerprint.
//      */
//     private function buildRequestFingerprint(
//         string $accountUuid,
//         string $transactionUuid,
//         string $reversalDate,
//         string $valueDate,
//         string $reason
//     ): string {
//         return hash(
//             'sha256',
//             json_encode([
//                 'repayment_account_uuid' =>
//                     $accountUuid,

//                 'original_transaction_uuid' =>
//                     $transactionUuid,

//                 'reversal_date' =>
//                     $reversalDate,

//                 'value_date' =>
//                     $valueDate,

//                 'reason' =>
//                     $reason,
//             ], JSON_THROW_ON_ERROR)
//         );
//     }

//     /**
//      * Load response relationships consistently.
//      */
//     private function hydrateTransaction(
//         RepaymentTransaction $transaction
//     ): RepaymentTransaction {
//         $transaction->refresh();

//         $transaction->load([
//             'receipt',
//             'reversalOf',
//             'reversal',

//             'allocations' => fn($query) =>
//                 $query->allocationOrder(),
//         ]);

//         $transaction->loadCount('allocations');

//         return $transaction;
//     }

//     /**
//      * Round collectible/outstanding to a whole rupee while preserving
//      * two-decimal database formatting.
//      */
//     private function roundWholeMoney(mixed $value): string
//     {
//         return number_format(
//             round(
//                 (float) ($value ?? 0),
//                 0,
//                 PHP_ROUND_HALF_UP
//             ),
//             2,
//             '.',
//             ''
//         );
//     }

//     /**
//      * Normalize money to two decimal places.
//      */
//     private function normalizeMoney(mixed $value): string
//     {
//         return bcadd(
//             trim((string) $value),
//             '0.00',
//             2
//         );
//     }

//     private function normalizeRequiredString(
//         mixed $value
//     ): string {
//         return trim((string) $value);
//     }

//     /**
//      * MariaDB/MySQL duplicate-key detection.
//      */
//     private function isDuplicateKeyException(
//         QueryException $exception
//     ): bool {
//         $sqlState = $exception->errorInfo[0] ?? null;
//         $driverCode = $exception->errorInfo[1] ?? null;

//         return $sqlState === '23000'
//             && (int) $driverCode === 1062;
//     }
// }

