<?php

namespace Modules\Repayment\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Repayment\Enums\AllocationComponent;
use Modules\Repayment\Enums\InstallmentPaymentStatus;
use Modules\Repayment\Enums\PaymentMode;
use Modules\Repayment\Enums\RepaymentAccountStatus;
use Modules\Repayment\Enums\RepaymentTransactionStatus;
use Modules\Repayment\Enums\RepaymentTransactionType;
use Modules\Repayment\Enums\StatusHistorySource;
use Modules\Repayment\Enums\StatusHistoryType;
use Modules\Repayment\Exceptions\RepaymentPaymentException;
use Modules\Repayment\Models\RepaymentAccount;
use Modules\Repayment\Models\RepaymentAllocation;
use Modules\Repayment\Models\RepaymentInstallment;
use Modules\Repayment\Models\RepaymentStatusHistory;
use Modules\Repayment\Models\RepaymentTransaction;

final class RepaymentPaymentPostingService
{
    public function __construct(
        private readonly RepaymentTransactionNumberGenerator
            $transactionNumberGenerator
    ) {}

    /**
     * Post a customer payment against a repayment account.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $metadata
     */
    public function post(
        string $accountUuid,
        array $payload,
        ?int $actorId = null,
        array $metadata = []
    ): RepaymentTransaction {
        try {
            return DB::transaction(
                fn (): RepaymentTransaction =>
                    $this->postInsideTransaction(
                        accountUuid: $accountUuid,
                        payload: $payload,
                        actorId: $actorId,
                        metadata: $metadata
                    ),
                attempts: 3
            );
        } catch (QueryException $exception) {
            /*
             * Handles a rare concurrent request where another
             * process inserted the same idempotency key first.
             */
            if ($this->isDuplicateKeyException($exception)) {
                $existing = RepaymentTransaction::query()
                    ->useWritePdo()
                    ->where(
                        'idempotency_key',
                        $this->normalizeRequiredString(
                            $payload['idempotency_key'] ?? ''
                        )
                    )
                    ->first();

                if ($existing) {
                    $account = RepaymentAccount::query()
                        ->useWritePdo()
                        ->where('uuid', $accountUuid)
                        ->first();

                    if (! $account) {
                        throw RepaymentPaymentException::accountNotFound(
                            $accountUuid
                        );
                    }

                    if (
                        $this->requestMatchesTransaction(
                            account: $account,
                            transaction: $existing,
                            payload: $payload
                        )
                    ) {
                        return $this->hydrateTransaction(
                            $existing
                        );
                    }

                    throw RepaymentPaymentException::idempotencyConflict(
                        idempotencyKey: (string)
                            $existing->idempotency_key,
                        existingTransactionUuid: (string)
                            $existing->uuid
                    );
                }
            }

            throw $exception;
        }
    }

    /**
     * Post a payment inside an already-open database transaction.
     *
     * This entry point is intended for multi-payment workflows
     * such as bulk Excel imports where every payment must commit
     * or roll back as one atomic unit.
     *
     * IMPORTANT:
     *
     * Caller MUST already own the outer database transaction.
     *
     * This method intentionally reuses the exact same posting,
     * installment allocation and account refresh logic as the
     * normal single-payment endpoint.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $metadata
     */
    public function postWithinTransaction(
        string $accountUuid,
        array $payload,
        ?int $actorId = null,
        array $metadata = []
    ): RepaymentTransaction {
        if (DB::connection()->transactionLevel() < 1) {
            throw new LogicException(
                'postWithinTransaction() requires an active database transaction.'
            );
        }

        return $this->postInsideTransaction(
            accountUuid: $accountUuid,
            payload: $payload,
            actorId: $actorId,
            metadata: $metadata
        );
    }

    /**
     * Execute the complete posting workflow inside one
     * database transaction.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $metadata
     */
    private function postInsideTransaction(
        string $accountUuid,
        array $payload,
        ?int $actorId,
        array $metadata = []
    ): RepaymentTransaction {
        $account = RepaymentAccount::query()
            ->useWritePdo()
            ->where('uuid', $accountUuid)
            ->lockForUpdate()
            ->first();

        if (! $account) {
            throw RepaymentPaymentException::accountNotFound(
                $accountUuid
            );
        }

        $idempotencyKey = $this->normalizeRequiredString(
            $payload['idempotency_key'] ?? ''
        );

        /*
         * Idempotency check account-status validation se pehle
         * hota hai.
         *
         * Original payment ke baad account CLOSED ho sakta hai,
         * lekin same request retry hone par existing transaction
         * safely return honi chahiye.
         */
        $existing = RepaymentTransaction::query()
            ->useWritePdo()
            ->where('idempotency_key', $idempotencyKey)
            ->lockForUpdate()
            ->first();

        if ($existing) {
            if (
                $this->requestMatchesTransaction(
                    account: $account,
                    transaction: $existing,
                    payload: $payload
                )
            ) {
                return $this->hydrateTransaction($existing);
            }

            throw RepaymentPaymentException::idempotencyConflict(
                idempotencyKey: $idempotencyKey,
                existingTransactionUuid: (string) $existing->uuid
            );
        }

        if (! $account->allowsPayment()) {
            throw RepaymentPaymentException::
                accountDoesNotAllowPayment(
                    accountUuid: (string) $account->uuid,
                    accountStatus: $account->status->value
                );
        }

        $amount = $this->normalizeMoney(
            $payload['amount'] ?? '0'
        );

        if (bccomp($amount, '0.00', 2) !== 1) {
            throw new RepaymentPaymentException(
                message: 'Payment amount must be greater than zero.',
                context: [
                    'repayment_account_uuid' =>
                        $accountUuid,
                    'amount' => $amount,
                ],
                statusCode: 422
            );
        }

        $paymentMode = PaymentMode::tryFrom(
            strtoupper(
                $this->normalizeRequiredString(
                    $payload['payment_mode'] ?? ''
                )
            )
        );

        if (! $paymentMode) {
            throw new RepaymentPaymentException(
                message: 'Invalid payment mode selected.',
                context: [
                    'repayment_account_uuid' =>
                        $accountUuid,
                    'payment_mode' =>
                        $payload['payment_mode'] ?? null,
                ],
                statusCode: 422
            );
        }

        $transactionReference =
            $this->normalizeNullableString(
                $payload['transaction_reference'] ?? null
            );

        if (
            $paymentMode->requiresReference()
            && $transactionReference === null
        ) {
            throw new RepaymentPaymentException(
                message: 'Transaction reference is required for the selected payment mode.',
                context: [
                    'repayment_account_uuid' =>
                        $accountUuid,
                    'payment_mode' =>
                        $paymentMode->value,
                ],
                statusCode: 422
            );
        }

        $this->assertReferenceIsAvailable(
            account: $account,
            paymentMode: $paymentMode,
            transactionReference: $transactionReference
        );

        $receivedDate = $this->normalizeRequiredString(
            $payload['received_date'] ?? ''
        );

        $valueDate = $this->normalizeRequiredString(
            $payload['value_date'] ?? ''
        );

        $remarks = $this->normalizeNullableString(
            $payload['remarks'] ?? null
        );

        $requestFingerprint = $this->buildRequestFingerprint(
            accountUuid: $accountUuid,
            amount: $amount,
            paymentMode: $paymentMode,
            transactionReference: $transactionReference,
            receivedDate: $receivedDate,
            valueDate: $valueDate,
            remarks: $remarks
        );

        $transaction = RepaymentTransaction::query()->create([
            'repayment_account_id' => $account->id,

            /*
             * Initial insert requires a unique number.
             * Final ID-based number is assigned immediately after.
             */
            'transaction_number' =>
                $this->transactionNumberGenerator
                    ->generateTemporary(),

            'idempotency_key' => $idempotencyKey,

            'transaction_type' =>
                RepaymentTransactionType::PAYMENT->value,

            'amount' => $amount,
            'allocated_amount' => '0.00',
            'unallocated_amount' => $amount,

            'payment_mode' => $paymentMode->value,
            'transaction_reference' =>
                $transactionReference,

            'received_date' => $receivedDate,
            'value_date' => $valueDate,

            'status' =>
                RepaymentTransactionStatus::PENDING->value,

            'remarks' => $remarks,

            'metadata' => array_merge(
                $metadata,
                [
                    /*
                     * System-owned metadata values cannot be
                     * overridden by caller-supplied metadata.
                     */
                    'request_fingerprint' =>
                        $requestFingerprint,

                    'allocation_strategy' =>
                        'OLDEST_DUE_INTEREST_THEN_PRINCIPAL',
                ]
            ),

            'created_by' => $actorId,
        ]);

        $transaction->transaction_number =
            $this->transactionNumberGenerator->generateFinal(
                transactionId: (int) $transaction->id,
                businessDate: $receivedDate
            );

        $transaction->save();

        $postingTimestamp = now();

        $allocationResult = $this->allocatePayment(
            account: $account,
            transaction: $transaction,
            paymentAmount: $amount,
            actorId: $actorId,
            postingTimestamp: $postingTimestamp
        );

        $allocatedAmount = bcsub(
            $amount,
            $allocationResult['remaining_amount'],
            2
        );

        $transaction->forceFill([
            'allocated_amount' => $allocatedAmount,

            'unallocated_amount' =>
                $allocationResult['remaining_amount'],

            'posted_at' => $postingTimestamp,

            'status' =>
                RepaymentTransactionStatus::POSTED->value,

            /*
             * Immediate posting endpoint mein authenticated
             * operator creator and approver dono hai.
             */
            'approved_by' => $actorId,

            'metadata' => array_merge(
                $transaction->metadata ?? [],
                [
                    'allocation_count' =>
                        $allocationResult['allocation_count'],

                    'interest_allocated' =>
                        $allocationResult['interest_allocated'],

                    'principal_allocated' =>
                        $allocationResult['principal_allocated'],

                    'unallocated_amount' =>
                        $allocationResult['remaining_amount'],
                ]
            ),
        ])->save();

        $this->refreshAccountBalances(
            account: $account,
            receivedAmount: $amount,
            unallocatedAmount:
                $allocationResult['remaining_amount'],
            transaction: $transaction,
            actorId: $actorId,
            postingTimestamp: $postingTimestamp
        );

        return $this->hydrateTransaction($transaction);
    }

    /**
     * Allocate payment to installments in oldest-due order.
     *
     * @return array{
     *     remaining_amount: string,
     *     interest_allocated: string,
     *     principal_allocated: string,
     *     allocation_count: int
     * }
     */
    private function allocatePayment(
        RepaymentAccount $account,
        RepaymentTransaction $transaction,
        string $paymentAmount,
        ?int $actorId,
        mixed $postingTimestamp
    ): array {
        $remainingAmount = $paymentAmount;
        $interestAllocatedTotal = '0.00';
        $principalAllocatedTotal = '0.00';
        $allocationOrder = 1;
        $allocationCount = 0;

        $installments = $account
            ->installments()
            ->outstanding()
            ->allocationOrder()
            ->lockForUpdate()
            ->get();

        if ($installments->isEmpty()) {
            if (
                bccomp(
                    (string) $account->total_outstanding,
                    '0.00',
                    2
                ) === 1
            ) {
                throw RepaymentPaymentException::
                    financialInvariantViolation(
                        message: 'Repayment account has an outstanding balance but no outstanding installments.',
                        context: [
                            'repayment_account_uuid' =>
                                $account->uuid,

                            'account_total_outstanding' =>
                                $account->total_outstanding,
                        ]
                    );
            }

            throw new RepaymentPaymentException(
                message: 'No outstanding repayment obligation is available for payment.',
                context: [
                    'repayment_account_uuid' =>
                        $account->uuid,
                ],
                statusCode: 409
            );
        }

        foreach ($installments as $installment) {
            if (
                bccomp(
                    $remainingAmount,
                    '0.00',
                    2
                ) !== 1
            ) {
                break;
            }

            $this->assertValidInstallmentState(
                $installment
            );

            $oldPaymentStatus =
                $installment->payment_status;

            /*
             * Repayment collection is based on the rounded installment
             * outstanding stored for the customer, not on the exact
             * principal + interest component total.
             *
             * This also self-heals legacy decimal outstanding values during
             * the next payment posting because 0.xx can become 0.00 here.
             */
            $installmentCollectible =
                $this->roundWholeMoney(
                    $installment->outstanding_amount
                );

            $installmentInterestAllocated = '0.00';
            $installmentPrincipalAllocated = '0.00';

            /*
             * Component 1: interest.
             */
            $interestOutstanding =
                $installment->interestOutstanding();

            $interestOutstanding = $this->minimumMoney(
                $interestOutstanding,
                $installmentCollectible
            );

            $interestAllocation = $this->minimumMoney(
                $remainingAmount,
                $interestOutstanding
            );

            if (
                bccomp(
                    $interestAllocation,
                    '0.00',
                    2
                ) === 1
            ) {
                RepaymentAllocation::query()->create([
                    'repayment_transaction_id' =>
                        $transaction->id,

                    'repayment_installment_id' =>
                        $installment->id,

                    'component' =>
                        AllocationComponent::INTEREST->value,

                    'amount' => $interestAllocation,

                    'allocation_order' =>
                        $allocationOrder++,

                    'metadata' => [
                        'installment_number' =>
                            $installment
                                ->installment_number,

                        'due_date' =>
                            $installment
                                ->due_date
                                ->format('Y-m-d'),

                        'component_priority' =>
                            AllocationComponent::INTEREST
                                ->priority(),
                    ],
                ]);

                $installment->interest_paid = bcadd(
                    (string) $installment->interest_paid,
                    $interestAllocation,
                    2
                );

                $remainingAmount = bcsub(
                    $remainingAmount,
                    $interestAllocation,
                    2
                );

                $installmentCollectible = bcsub(
                    $installmentCollectible,
                    $interestAllocation,
                    2
                );

                if (
                    bccomp(
                        $installmentCollectible,
                        '0.00',
                        2
                    ) === -1
                ) {
                    $installmentCollectible = '0.00';
                }

                $interestAllocatedTotal = bcadd(
                    $interestAllocatedTotal,
                    $interestAllocation,
                    2
                );

                $installmentInterestAllocated =
                    $interestAllocation;

                $allocationCount++;
            }

            /*
             * Component 2: principal.
             */
            /*
             * Whatever remains from the rounded collectible after interest
             * is treated as the principal-side collectible. This absorbs the
             * per-EMI rounding difference without carrying paise to the next
             * EMI.
             */
            $principalOutstanding =
                $installmentCollectible;

            $principalAllocation = $this->minimumMoney(
                $remainingAmount,
                $principalOutstanding
            );

            if (
                bccomp(
                    $principalAllocation,
                    '0.00',
                    2
                ) === 1
            ) {
                RepaymentAllocation::query()->create([
                    'repayment_transaction_id' =>
                        $transaction->id,

                    'repayment_installment_id' =>
                        $installment->id,

                    'component' =>
                        AllocationComponent::PRINCIPAL->value,

                    'amount' => $principalAllocation,

                    'allocation_order' =>
                        $allocationOrder++,

                    'metadata' => [
                        'installment_number' =>
                            $installment
                                ->installment_number,

                        'due_date' =>
                            $installment
                                ->due_date
                                ->format('Y-m-d'),

                        'component_priority' =>
                            AllocationComponent::PRINCIPAL
                                ->priority(),
                    ],
                ]);

                $installment->principal_paid = bcadd(
                    (string) $installment->principal_paid,
                    $principalAllocation,
                    2
                );

                $remainingAmount = bcsub(
                    $remainingAmount,
                    $principalAllocation,
                    2
                );

                $installmentCollectible = bcsub(
                    $installmentCollectible,
                    $principalAllocation,
                    2
                );

                if (
                    bccomp(
                        $installmentCollectible,
                        '0.00',
                        2
                    ) === -1
                ) {
                    $installmentCollectible = '0.00';
                }

                $principalAllocatedTotal = bcadd(
                    $principalAllocatedTotal,
                    $principalAllocation,
                    2
                );

                $installmentPrincipalAllocated =
                    $principalAllocation;

                $allocationCount++;
            }

            /*
             * Always persist outstanding as a whole-rupee amount.
             */
            $newOutstanding =
                $this->roundWholeMoney(
                    $installmentCollectible
                );

            $totalPaid = bcadd(
                (string) $installment->interest_paid,
                (string) $installment->principal_paid,
                2
            );

            $newPaymentStatus = match (true) {
                bccomp($newOutstanding, '0.00', 2) === 0 =>
                    InstallmentPaymentStatus::PAID,

                bccomp($totalPaid, '0.00', 2) === 1 =>
                    InstallmentPaymentStatus::PARTIAL,

                default =>
                    InstallmentPaymentStatus::UNPAID,
            };

            $installment->forceFill([
                'outstanding_amount' => $newOutstanding,

                'payment_status' =>
                    $newPaymentStatus->value,

                'last_payment_at' => $postingTimestamp,

                'fully_paid_at' =>
                    $newPaymentStatus
                        === InstallmentPaymentStatus::PAID
                            ? $postingTimestamp
                            : null,
            ])->save();

            app(RepaymentInstallmentDueStatusSyncService::class)->sync(
                $installment,
                StatusHistorySource::PAYMENT_POSTING,
                $transaction,
                $actorId
            );

            if ($oldPaymentStatus !== $newPaymentStatus) {
                RepaymentStatusHistory::query()->create([
                    'repayment_account_id' =>
                        $account->id,

                    'repayment_installment_id' =>
                        $installment->id,

                    'repayment_transaction_id' =>
                        $transaction->id,

                    'status_type' =>
                        StatusHistoryType::PAYMENT_STATUS
                            ->value,

                    'old_status' =>
                        $oldPaymentStatus->value,

                    'new_status' =>
                        $newPaymentStatus->value,

                    'source' =>
                        StatusHistorySource::PAYMENT_POSTING
                            ->value,

                    'reason' =>
                        'Payment allocated to repayment installment.',

                    'metadata' => [
                        'interest_allocated' =>
                            $installmentInterestAllocated,

                        'principal_allocated' =>
                            $installmentPrincipalAllocated,

                        'outstanding_after_payment' =>
                            $newOutstanding,
                    ],

                    'changed_by' => $actorId,
                    'changed_at' => $postingTimestamp,
                ]);
            }
        }

        return [
            'remaining_amount' =>
                $remainingAmount,

            'interest_allocated' =>
                $interestAllocatedTotal,

            'principal_allocated' =>
                $principalAllocatedTotal,

            'allocation_count' =>
                $allocationCount,
        ];
    }

    /**
     * Recalculate account balances from persisted installments.
     */
    private function refreshAccountBalances(
        RepaymentAccount $account,
        string $receivedAmount,
        string $unallocatedAmount,
        RepaymentTransaction $transaction,
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

        foreach (
            [
                'principal_outstanding' =>
                    $principalOutstanding,

                'interest_outstanding' =>
                    $interestOutstanding,

                'total_outstanding' =>
                    $totalOutstanding,

                'overdue_amount' =>
                    $overdueAmount,
            ] as $field => $value
        ) {
            if (bccomp($value, '0.00', 2) === -1) {
                throw RepaymentPaymentException::
                    financialInvariantViolation(
                        message: 'Repayment balance became negative after payment allocation.',
                        context: [
                            'repayment_account_uuid' =>
                                $account->uuid,

                            'field' => $field,
                            'value' => $value,

                            'repayment_transaction_uuid' =>
                                $transaction->uuid,
                        ]
                    );
            }
        }

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
            throw RepaymentPaymentException::
                financialInvariantViolation(
                    message: 'Repayment account component balances do not match total outstanding.',
                    context: [
                        'repayment_account_uuid' =>
                            $account->uuid,

                        'principal_outstanding' =>
                            $principalOutstanding,

                        'interest_outstanding' =>
                            $interestOutstanding,

                        'component_total' =>
                            $componentTotal,

                        'total_outstanding' =>
                            $totalOutstanding,

                        'repayment_transaction_uuid' =>
                            $transaction->uuid,
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

        $oldAccountStatus = $account->status;

        $newAccountStatus =
            bccomp(
                $totalOutstanding,
                '0.00',
                2
            ) === 0
                ? RepaymentAccountStatus::CLOSED
                : RepaymentAccountStatus::ACTIVE;

        $account->forceFill([
            'principal_outstanding' =>
                $principalOutstanding,

            'interest_outstanding' =>
                $interestOutstanding,

            'total_outstanding' =>
                $totalOutstanding,

            'overdue_amount' =>
                $overdueAmount,

            'advance_amount' => bcadd(
                (string) $account->advance_amount,
                $unallocatedAmount,
                2
            ),

            'total_received' => bcadd(
                (string) $account->total_received,
                $receivedAmount,
                2
            ),

            'next_due_date' => $nextDueDate,

            'last_payment_at' => $postingTimestamp,

            'status' => $newAccountStatus->value,

            'closed_at' =>
                $newAccountStatus
                    === RepaymentAccountStatus::CLOSED
                        ? $postingTimestamp
                        : null,

            'updated_by' => $actorId,
        ])->save();

        if ($oldAccountStatus !== $newAccountStatus) {
            RepaymentStatusHistory::query()->create([
                'repayment_account_id' => $account->id,

                'repayment_transaction_id' =>
                    $transaction->id,

                'status_type' =>
                    StatusHistoryType::ACCOUNT_STATUS->value,

                'old_status' =>
                    $oldAccountStatus->value,

                'new_status' =>
                    $newAccountStatus->value,

                'source' =>
                    StatusHistorySource::ACCOUNT_CLOSURE->value,

                'reason' =>
                    'Repayment obligations were fully settled.',

                'metadata' => [
                    'principal_outstanding' =>
                        $principalOutstanding,

                    'interest_outstanding' =>
                        $interestOutstanding,

                    'total_outstanding' =>
                        $totalOutstanding,

                    'advance_amount_added' =>
                        $unallocatedAmount,
                ],

                'changed_by' => $actorId,
                'changed_at' => $postingTimestamp,
            ]);
        }
    }

    /**
     * Validate installment component balances before allocation.
     */
    private function assertValidInstallmentState(
        RepaymentInstallment $installment
    ): void {
        $values = [
            'principal_due' =>
                $this->normalizeMoney(
                    $installment->principal_due
                ),

            'interest_due' =>
                $this->normalizeMoney(
                    $installment->interest_due
                ),

            'principal_paid' =>
                $this->normalizeMoney(
                    $installment->principal_paid
                ),

            'interest_paid' =>
                $this->normalizeMoney(
                    $installment->interest_paid
                ),

            'outstanding_amount' =>
                $this->normalizeMoney(
                    $installment->outstanding_amount
                ),
        ];

        foreach ($values as $field => $value) {
            if (bccomp($value, '0.00', 2) === -1) {
                throw RepaymentPaymentException::
                    invalidInstallmentState(
                        installmentUuid:
                            (string) $installment->uuid,

                        installmentNumber:
                            (int) $installment
                                ->installment_number,

                        details: [
                            'field' => $field,
                            'value' => $value,
                        ]
                    );
            }
        }

        /*
         * Interest remains an exact financial component, therefore it may
         * never be paid above its due value.
         *
         * Principal is intentionally not compared with principal_due here:
         * the rounded-EMI difference is absorbed on the principal side, so
         * principal_paid can differ from the exact amortization principal by
         * a few paise.
         */
        if (
            bccomp(
                $values['interest_paid'],
                $values['interest_due'],
                2
            ) === 1
        ) {
            throw RepaymentPaymentException::
                invalidInstallmentState(
                    installmentUuid:
                        (string) $installment->uuid,

                    installmentNumber:
                        (int) $installment
                            ->installment_number,

                    details: [
                        'interest_due' =>
                            $values['interest_due'],

                        'interest_paid' =>
                            $values['interest_paid'],
                    ]
                );
        }

        /*
         * Do not enforce:
         *
         * principal_due + interest_due - paid == outstanding_amount
         *
         * because outstanding_amount is deliberately a rounded collectible
         * amount. Keeping that old invariant is what caused a fully-paid
         * rounded EMI to remain PARTIAL.
         */
    }

    /**
     * Check duplicate external reference policy.
     */
    private function assertReferenceIsAvailable(
        RepaymentAccount $account,
        PaymentMode $paymentMode,
        ?string $transactionReference
    ): void {
        /*
         * Cash may have a locally entered reference, but it is
         * not treated as a globally reconcilable banking key.
         */
        if (
            ! $paymentMode->requiresReference()
            || $transactionReference === null
        ) {
            return;
        }

        $existing = RepaymentTransaction::query()
            ->where(
                'repayment_account_id',
                $account->id
            )
            ->where(
                'payment_mode',
                $paymentMode->value
            )
            ->where(
                'transaction_reference',
                $transactionReference
            )
            ->where(
                'transaction_type',
                RepaymentTransactionType::PAYMENT->value
            )
            ->where(
                'status',
                '!=',
                RepaymentTransactionStatus::FAILED->value
            )
            ->lockForUpdate()
            ->first();

        if ($existing) {
            throw RepaymentPaymentException::
                transactionReferenceAlreadyUsed(
                    paymentMode: $paymentMode->value,

                    transactionReference:
                        $transactionReference,

                    existingTransactionUuid:
                        (string) $existing->uuid
                );
        }
    }

    /**
     * Determine whether the repeated idempotent request has
     * exactly the same financial payload.
     *
     * @param array<string, mixed> $payload
     */
    private function requestMatchesTransaction(
        RepaymentAccount $account,
        RepaymentTransaction $transaction,
        array $payload
    ): bool {
        if (
            (int) $transaction->repayment_account_id
                !== (int) $account->id
        ) {
            return false;
        }

        if (
            $transaction->transaction_type
                !== RepaymentTransactionType::PAYMENT
        ) {
            return false;
        }

        $paymentMode = PaymentMode::tryFrom(
            strtoupper(
                $this->normalizeRequiredString(
                    $payload['payment_mode'] ?? ''
                )
            )
        );

        if (! $paymentMode) {
            return false;
        }

        $fingerprint = $this->buildRequestFingerprint(
            accountUuid: (string) $account->uuid,

            amount: $this->normalizeMoney(
                $payload['amount'] ?? '0'
            ),

            paymentMode: $paymentMode,

            transactionReference:
                $this->normalizeNullableString(
                    $payload['transaction_reference'] ?? null
                ),

            receivedDate:
                $this->normalizeRequiredString(
                    $payload['received_date'] ?? ''
                ),

            valueDate:
                $this->normalizeRequiredString(
                    $payload['value_date'] ?? ''
                ),

            remarks:
                $this->normalizeNullableString(
                    $payload['remarks'] ?? null
                )
        );

        $existingFingerprint =
            $transaction->metadata['request_fingerprint']
                ?? null;

        if (is_string($existingFingerprint)) {
            return hash_equals(
                $existingFingerprint,
                $fingerprint
            );
        }

        /*
         * Backward-compatible comparison for records created
         * before request fingerprint storage.
         */
        return bccomp(
            (string) $transaction->amount,
            $this->normalizeMoney(
                $payload['amount'] ?? '0'
            ),
            2
        ) === 0
            && $transaction->payment_mode === $paymentMode
            && $transaction->transaction_reference
                === $this->normalizeNullableString(
                    $payload['transaction_reference'] ?? null
                )
            && optional(
                $transaction->received_date
            )->format('Y-m-d')
                === $this->normalizeRequiredString(
                    $payload['received_date'] ?? ''
                )
            && optional(
                $transaction->value_date
            )->format('Y-m-d')
                === $this->normalizeRequiredString(
                    $payload['value_date'] ?? ''
                )
            && $this->normalizeNullableString(
                $transaction->remarks
            ) === $this->normalizeNullableString(
                $payload['remarks'] ?? null
            );
    }

    /**
     * Generate canonical request fingerprint.
     */
    private function buildRequestFingerprint(
        string $accountUuid,
        string $amount,
        PaymentMode $paymentMode,
        ?string $transactionReference,
        string $receivedDate,
        string $valueDate,
        ?string $remarks
    ): string {
        return hash(
            'sha256',
            json_encode([
                'repayment_account_uuid' => $accountUuid,
                'amount' => $amount,
                'payment_mode' => $paymentMode->value,
                'transaction_reference' =>
                    $transactionReference,
                'received_date' => $receivedDate,
                'value_date' => $valueDate,
                'remarks' => $remarks,
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
     * Round repayment collectible/outstanding to a whole rupee while
     * preserving DECIMAL(18,2)-style formatting in the database.
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
     * Normalize a decimal value to two places.
     */
    private function normalizeMoney(mixed $value): string
    {
        return bcadd(
            trim((string) $value),
            '0.00',
            2
        );
    }

    /**
     * Return the smaller of two non-negative money values.
     */
    private function minimumMoney(
        string $first,
        string $second
    ): string {
        return bccomp($first, $second, 2) <= 0
            ? $first
            : $second;
    }

    private function normalizeRequiredString(
        mixed $value
    ): string {
        return trim((string) $value);
    }

    private function normalizeNullableString(
        mixed $value
    ): ?string {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === ''
            ? null
            : $normalized;
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
// use Illuminate\Support\Facades\DB;
// use LogicException;
// use Modules\Repayment\Enums\AllocationComponent;
// use Modules\Repayment\Enums\InstallmentPaymentStatus;
// use Modules\Repayment\Enums\PaymentMode;
// use Modules\Repayment\Enums\RepaymentAccountStatus;
// use Modules\Repayment\Enums\RepaymentTransactionStatus;
// use Modules\Repayment\Enums\RepaymentTransactionType;
// use Modules\Repayment\Enums\StatusHistorySource;
// use Modules\Repayment\Enums\StatusHistoryType;
// use Modules\Repayment\Exceptions\RepaymentPaymentException;
// use Modules\Repayment\Models\RepaymentAccount;
// use Modules\Repayment\Models\RepaymentAllocation;
// use Modules\Repayment\Models\RepaymentInstallment;
// use Modules\Repayment\Models\RepaymentStatusHistory;
// use Modules\Repayment\Models\RepaymentTransaction;

// final class RepaymentPaymentPostingService
// {
//     public function __construct(
//         private readonly RepaymentTransactionNumberGenerator
//         $transactionNumberGenerator
//     ) {
//     }

//     /**
//      * Post a customer payment against a repayment account.
//      *
//      * @param array<string, mixed> $payload
//      * @param array<string, mixed> $metadata
//      */
//     public function post(
//         string $accountUuid,
//         array $payload,
//         ?int $actorId = null,
//         array $metadata = []
//     ): RepaymentTransaction {
//         try {
//             return DB::transaction(
//                 fn(): RepaymentTransaction =>
//                     $this->postInsideTransaction(
//                         accountUuid: $accountUuid,
//                         payload: $payload,
//                         actorId: $actorId,
//                         metadata: $metadata
//                     ),
//                 attempts: 3
//             );
//         } catch (QueryException $exception) {
//             /*
//              * Handles a rare concurrent request where another
//              * process inserted the same idempotency key first.
//              */
//             if ($this->isDuplicateKeyException($exception)) {
//                 $existing = RepaymentTransaction::query()
//                     ->useWritePdo()
//                     ->where(
//                         'idempotency_key',
//                         $this->normalizeRequiredString(
//                             $payload['idempotency_key'] ?? ''
//                         )
//                     )
//                     ->first();

//                 if ($existing) {
//                     $account = RepaymentAccount::query()
//                         ->useWritePdo()
//                         ->where('uuid', $accountUuid)
//                         ->first();

//                     if (!$account) {
//                         throw RepaymentPaymentException::accountNotFound(
//                             $accountUuid
//                         );
//                     }

//                     if (
//                         $this->requestMatchesTransaction(
//                             account: $account,
//                             transaction: $existing,
//                             payload: $payload
//                         )
//                     ) {
//                         return $this->hydrateTransaction(
//                             $existing
//                         );
//                     }

//                     throw RepaymentPaymentException::idempotencyConflict(
//                         idempotencyKey: (string) 
//                         $existing->idempotency_key,
//                         existingTransactionUuid: (string) 
//                         $existing->uuid
//                     );
//                 }
//             }

//             throw $exception;
//         }
//     }

//     /**
//      * Post a payment inside an already-open database transaction.
//      *
//      * This entry point is intended for multi-payment workflows
//      * such as bulk Excel imports where every payment must commit
//      * or roll back as one atomic unit.
//      *
//      * IMPORTANT:
//      *
//      * Caller MUST already own the outer database transaction.
//      *
//      * This method intentionally reuses the exact same posting,
//      * installment allocation and account refresh logic as the
//      * normal single-payment endpoint.
//      *
//      * @param array<string, mixed> $payload
//      * @param array<string, mixed> $metadata
//      */
//     public function postWithinTransaction(
//         string $accountUuid,
//         array $payload,
//         ?int $actorId = null,
//         array $metadata = []
//     ): RepaymentTransaction {
//         if (DB::connection()->transactionLevel() < 1) {
//             throw new LogicException(
//                 'postWithinTransaction() requires an active database transaction.'
//             );
//         }

//         return $this->postInsideTransaction(
//             accountUuid: $accountUuid,
//             payload: $payload,
//             actorId: $actorId,
//             metadata: $metadata
//         );
//     }

//     /**
//      * Execute the complete posting workflow inside one
//      * database transaction.
//      *
//      * @param array<string, mixed> $payload
//      * @param array<string, mixed> $metadata
//      */
//     private function postInsideTransaction(
//         string $accountUuid,
//         array $payload,
//         ?int $actorId,
//         array $metadata = []
//     ): RepaymentTransaction {
//         $account = RepaymentAccount::query()
//             ->useWritePdo()
//             ->where('uuid', $accountUuid)
//             ->lockForUpdate()
//             ->first();

//         if (!$account) {
//             throw RepaymentPaymentException::accountNotFound(
//                 $accountUuid
//             );
//         }

//         $idempotencyKey = $this->normalizeRequiredString(
//             $payload['idempotency_key'] ?? ''
//         );

//         /*
//          * Idempotency check account-status validation se pehle
//          * hota hai.
//          *
//          * Original payment ke baad account CLOSED ho sakta hai,
//          * lekin same request retry hone par existing transaction
//          * safely return honi chahiye.
//          */
//         $existing = RepaymentTransaction::query()
//             ->useWritePdo()
//             ->where('idempotency_key', $idempotencyKey)
//             ->lockForUpdate()
//             ->first();

//         if ($existing) {
//             if (
//                 $this->requestMatchesTransaction(
//                     account: $account,
//                     transaction: $existing,
//                     payload: $payload
//                 )
//             ) {
//                 return $this->hydrateTransaction($existing);
//             }

//             throw RepaymentPaymentException::idempotencyConflict(
//                 idempotencyKey: $idempotencyKey,
//                 existingTransactionUuid: (string) $existing->uuid
//             );
//         }

//         if (!$account->allowsPayment()) {
//             throw RepaymentPaymentException::
//                 accountDoesNotAllowPayment(
//                     accountUuid: (string) $account->uuid,
//                     accountStatus: $account->status->value
//                 );
//         }

//         $amount = $this->normalizeMoney(
//             $payload['amount'] ?? '0'
//         );

//         if (bccomp($amount, '0.00', 2) !== 1) {
//             throw new RepaymentPaymentException(
//                 message: 'Payment amount must be greater than zero.',
//                 context: [
//                     'repayment_account_uuid' =>
//                         $accountUuid,
//                     'amount' => $amount,
//                 ],
//                 statusCode: 422
//             );
//         }

//         $paymentMode = PaymentMode::tryFrom(
//             strtoupper(
//                 $this->normalizeRequiredString(
//                     $payload['payment_mode'] ?? ''
//                 )
//             )
//         );

//         if (!$paymentMode) {
//             throw new RepaymentPaymentException(
//                 message: 'Invalid payment mode selected.',
//                 context: [
//                     'repayment_account_uuid' =>
//                         $accountUuid,
//                     'payment_mode' =>
//                         $payload['payment_mode'] ?? null,
//                 ],
//                 statusCode: 422
//             );
//         }

//         $transactionReference =
//             $this->normalizeNullableString(
//                 $payload['transaction_reference'] ?? null
//             );

//         if (
//             $paymentMode->requiresReference()
//             && $transactionReference === null
//         ) {
//             throw new RepaymentPaymentException(
//                 message: 'Transaction reference is required for the selected payment mode.',
//                 context: [
//                     'repayment_account_uuid' =>
//                         $accountUuid,
//                     'payment_mode' =>
//                         $paymentMode->value,
//                 ],
//                 statusCode: 422
//             );
//         }

//         $this->assertReferenceIsAvailable(
//             account: $account,
//             paymentMode: $paymentMode,
//             transactionReference: $transactionReference
//         );

//         $receivedDate = $this->normalizeRequiredString(
//             $payload['received_date'] ?? ''
//         );

//         $valueDate = $this->normalizeRequiredString(
//             $payload['value_date'] ?? ''
//         );

//         $remarks = $this->normalizeNullableString(
//             $payload['remarks'] ?? null
//         );

//         $requestFingerprint = $this->buildRequestFingerprint(
//             accountUuid: $accountUuid,
//             amount: $amount,
//             paymentMode: $paymentMode,
//             transactionReference: $transactionReference,
//             receivedDate: $receivedDate,
//             valueDate: $valueDate,
//             remarks: $remarks
//         );

//         $transaction = RepaymentTransaction::query()->create([
//             'repayment_account_id' => $account->id,

//             /*
//              * Initial insert requires a unique number.
//              * Final ID-based number is assigned immediately after.
//              */
//             'transaction_number' =>
//                 $this->transactionNumberGenerator
//                     ->generateTemporary(),

//             'idempotency_key' => $idempotencyKey,

//             'transaction_type' =>
//                 RepaymentTransactionType::PAYMENT->value,

//             'amount' => $amount,
//             'allocated_amount' => '0.00',
//             'unallocated_amount' => $amount,

//             'payment_mode' => $paymentMode->value,
//             'transaction_reference' =>
//                 $transactionReference,

//             'received_date' => $receivedDate,
//             'value_date' => $valueDate,

//             'status' =>
//                 RepaymentTransactionStatus::PENDING->value,

//             'remarks' => $remarks,

//             'metadata' => array_merge(
//                 $metadata,
//                 [
//                     /*
//                      * System-owned metadata values cannot be
//                      * overridden by caller-supplied metadata.
//                      */
//                     'request_fingerprint' =>
//                         $requestFingerprint,

//                     'allocation_strategy' =>
//                         'OLDEST_DUE_INTEREST_THEN_PRINCIPAL',
//                 ]
//             ),

//             'created_by' => $actorId,
//         ]);

//         $transaction->transaction_number =
//             $this->transactionNumberGenerator->generateFinal(
//                 transactionId: (int) $transaction->id,
//                 businessDate: $receivedDate
//             );

//         $transaction->save();

//         $postingTimestamp = now();

//         $allocationResult = $this->allocatePayment(
//             account: $account,
//             transaction: $transaction,
//             paymentAmount: $amount,
//             actorId: $actorId,
//             postingTimestamp: $postingTimestamp
//         );

//         $allocatedAmount = bcsub(
//             $amount,
//             $allocationResult['remaining_amount'],
//             2
//         );

//         $transaction->forceFill([
//             'allocated_amount' => $allocatedAmount,

//             'unallocated_amount' =>
//                 $allocationResult['remaining_amount'],

//             'posted_at' => $postingTimestamp,

//             'status' =>
//                 RepaymentTransactionStatus::POSTED->value,

//             /*
//              * Immediate posting endpoint mein authenticated
//              * operator creator and approver dono hai.
//              */
//             'approved_by' => $actorId,

//             'metadata' => array_merge(
//                 $transaction->metadata ?? [],
//                 [
//                     'allocation_count' =>
//                         $allocationResult['allocation_count'],

//                     'interest_allocated' =>
//                         $allocationResult['interest_allocated'],

//                     'principal_allocated' =>
//                         $allocationResult['principal_allocated'],

//                     'unallocated_amount' =>
//                         $allocationResult['remaining_amount'],
//                 ]
//             ),
//         ])->save();

//         $this->refreshAccountBalances(
//             account: $account,
//             receivedAmount: $amount,
//             unallocatedAmount:
//             $allocationResult['remaining_amount'],
//             transaction: $transaction,
//             actorId: $actorId,
//             postingTimestamp: $postingTimestamp
//         );

//         return $this->hydrateTransaction($transaction);
//     }

//     /**
//      * Allocate payment to installments in oldest-due order.
//      *
//      * @return array{
//      *     remaining_amount: string,
//      *     interest_allocated: string,
//      *     principal_allocated: string,
//      *     allocation_count: int
//      * }
//      */
//     private function allocatePayment(
//         RepaymentAccount $account,
//         RepaymentTransaction $transaction,
//         string $paymentAmount,
//         ?int $actorId,
//         mixed $postingTimestamp
//     ): array {
//         $remainingAmount = $paymentAmount;
//         $interestAllocatedTotal = '0.00';
//         $principalAllocatedTotal = '0.00';
//         $allocationOrder = 1;
//         $allocationCount = 0;

//         $installments = $account
//             ->installments()
//             ->outstanding()
//             ->allocationOrder()
//             ->lockForUpdate()
//             ->get();

//         if ($installments->isEmpty()) {
//             if (
//                 bccomp(
//                     (string) $account->total_outstanding,
//                     '0.00',
//                     2
//                 ) === 1
//             ) {
//                 throw RepaymentPaymentException::
//                     financialInvariantViolation(
//                         message: 'Repayment account has an outstanding balance but no outstanding installments.',
//                         context: [
//                             'repayment_account_uuid' =>
//                                 $account->uuid,

//                             'account_total_outstanding' =>
//                                 $account->total_outstanding,
//                         ]
//                     );
//             }

//             throw new RepaymentPaymentException(
//                 message: 'No outstanding repayment obligation is available for payment.',
//                 context: [
//                     'repayment_account_uuid' =>
//                         $account->uuid,
//                 ],
//                 statusCode: 409
//             );
//         }

//         foreach ($installments as $installment) {
//             if (
//                 bccomp(
//                     $remainingAmount,
//                     '0.00',
//                     2
//                 ) !== 1
//             ) {
//                 break;
//             }

//             $this->assertValidInstallmentState(
//                 $installment
//             );

//             $oldPaymentStatus =
//                 $installment->payment_status;

//             /*
//              * Repayment collection is based on the rounded installment
//              * outstanding stored for the customer, not on the exact
//              * principal + interest component total.
//              *
//              * This also self-heals legacy decimal outstanding values during
//              * the next payment posting because 0.xx can become 0.00 here.
//              */
//             $installmentCollectible =
//                 $this->roundWholeMoney(
//                     $installment->outstanding_amount
//                 );

//             $installmentInterestAllocated = '0.00';
//             $installmentPrincipalAllocated = '0.00';

//             /*
//              * Component 1: interest.
//              */
//             $interestOutstanding =
//                 $installment->interestOutstanding();

//             $interestOutstanding = $this->minimumMoney(
//                 $interestOutstanding,
//                 $installmentCollectible
//             );

//             $interestAllocation = $this->minimumMoney(
//                 $remainingAmount,
//                 $interestOutstanding
//             );

//             if (
//                 bccomp(
//                     $interestAllocation,
//                     '0.00',
//                     2
//                 ) === 1
//             ) {
//                 RepaymentAllocation::query()->create([
//                     'repayment_transaction_id' =>
//                         $transaction->id,

//                     'repayment_installment_id' =>
//                         $installment->id,

//                     'component' =>
//                         AllocationComponent::INTEREST->value,

//                     'amount' => $interestAllocation,

//                     'allocation_order' =>
//                         $allocationOrder++,

//                     'metadata' => [
//                         'installment_number' =>
//                             $installment
//                                 ->installment_number,

//                         'due_date' =>
//                             $installment
//                                 ->due_date
//                                 ->format('Y-m-d'),

//                         'component_priority' =>
//                             AllocationComponent::INTEREST
//                                 ->priority(),
//                     ],
//                 ]);

//                 $installment->interest_paid = bcadd(
//                     (string) $installment->interest_paid,
//                     $interestAllocation,
//                     2
//                 );

//                 $remainingAmount = bcsub(
//                     $remainingAmount,
//                     $interestAllocation,
//                     2
//                 );

//                 $installmentCollectible = bcsub(
//                     $installmentCollectible,
//                     $interestAllocation,
//                     2
//                 );

//                 if (
//                     bccomp(
//                         $installmentCollectible,
//                         '0.00',
//                         2
//                     ) === -1
//                 ) {
//                     $installmentCollectible = '0.00';
//                 }

//                 $interestAllocatedTotal = bcadd(
//                     $interestAllocatedTotal,
//                     $interestAllocation,
//                     2
//                 );

//                 $installmentInterestAllocated =
//                     $interestAllocation;

//                 $allocationCount++;
//             }

//             /*
//              * Component 2: principal.
//              */
//             /*
//              * Whatever remains from the rounded collectible after interest
//              * is treated as the principal-side collectible. This absorbs the
//              * per-EMI rounding difference without carrying paise to the next
//              * EMI.
//              */
//             $principalOutstanding =
//                 $installmentCollectible;

//             $principalAllocation = $this->minimumMoney(
//                 $remainingAmount,
//                 $principalOutstanding
//             );

//             if (
//                 bccomp(
//                     $principalAllocation,
//                     '0.00',
//                     2
//                 ) === 1
//             ) {
//                 RepaymentAllocation::query()->create([
//                     'repayment_transaction_id' =>
//                         $transaction->id,

//                     'repayment_installment_id' =>
//                         $installment->id,

//                     'component' =>
//                         AllocationComponent::PRINCIPAL->value,

//                     'amount' => $principalAllocation,

//                     'allocation_order' =>
//                         $allocationOrder++,

//                     'metadata' => [
//                         'installment_number' =>
//                             $installment
//                                 ->installment_number,

//                         'due_date' =>
//                             $installment
//                                 ->due_date
//                                 ->format('Y-m-d'),

//                         'component_priority' =>
//                             AllocationComponent::PRINCIPAL
//                                 ->priority(),
//                     ],
//                 ]);

//                 $installment->principal_paid = bcadd(
//                     (string) $installment->principal_paid,
//                     $principalAllocation,
//                     2
//                 );

//                 $remainingAmount = bcsub(
//                     $remainingAmount,
//                     $principalAllocation,
//                     2
//                 );

//                 $installmentCollectible = bcsub(
//                     $installmentCollectible,
//                     $principalAllocation,
//                     2
//                 );

//                 if (
//                     bccomp(
//                         $installmentCollectible,
//                         '0.00',
//                         2
//                     ) === -1
//                 ) {
//                     $installmentCollectible = '0.00';
//                 }

//                 $principalAllocatedTotal = bcadd(
//                     $principalAllocatedTotal,
//                     $principalAllocation,
//                     2
//                 );

//                 $installmentPrincipalAllocated =
//                     $principalAllocation;

//                 $allocationCount++;
//             }

//             /*
//              * Always persist outstanding as a whole-rupee amount.
//              */
//             $newOutstanding =
//                 $this->roundWholeMoney(
//                     $installmentCollectible
//                 );

//             $totalPaid = bcadd(
//                 (string) $installment->interest_paid,
//                 (string) $installment->principal_paid,
//                 2
//             );

//             $newPaymentStatus = match (true) {
//                 bccomp($newOutstanding, '0.00', 2) === 0 =>
//                     InstallmentPaymentStatus::PAID,

//                 bccomp($totalPaid, '0.00', 2) === 1 =>
//                     InstallmentPaymentStatus::PARTIAL,

//                 default =>
//                     InstallmentPaymentStatus::UNPAID,
//             };

//             $installment->forceFill([
//                 'outstanding_amount' => $newOutstanding,

//                 'payment_status' =>
//                     $newPaymentStatus->value,

//                 'last_payment_at' => $postingTimestamp,

//                 'fully_paid_at' =>
//                     $newPaymentStatus
//                     === InstallmentPaymentStatus::PAID
//                     ? $postingTimestamp
//                     : null,
//             ])->save();

//             if ($oldPaymentStatus !== $newPaymentStatus) {
//                 RepaymentStatusHistory::query()->create([
//                     'repayment_account_id' =>
//                         $account->id,

//                     'repayment_installment_id' =>
//                         $installment->id,

//                     'repayment_transaction_id' =>
//                         $transaction->id,

//                     'status_type' =>
//                         StatusHistoryType::PAYMENT_STATUS
//                             ->value,

//                     'old_status' =>
//                         $oldPaymentStatus->value,

//                     'new_status' =>
//                         $newPaymentStatus->value,

//                     'source' =>
//                         StatusHistorySource::PAYMENT_POSTING
//                             ->value,

//                     'reason' =>
//                         'Payment allocated to repayment installment.',

//                     'metadata' => [
//                         'interest_allocated' =>
//                             $installmentInterestAllocated,

//                         'principal_allocated' =>
//                             $installmentPrincipalAllocated,

//                         'outstanding_after_payment' =>
//                             $newOutstanding,
//                     ],

//                     'changed_by' => $actorId,
//                     'changed_at' => $postingTimestamp,
//                 ]);
//             }
//         }

//         return [
//             'remaining_amount' =>
//                 $remainingAmount,

//             'interest_allocated' =>
//                 $interestAllocatedTotal,

//             'principal_allocated' =>
//                 $principalAllocatedTotal,

//             'allocation_count' =>
//                 $allocationCount,
//         ];
//     }

//     /**
//      * Recalculate account balances from persisted installments.
//      */
//     private function refreshAccountBalances(
//         RepaymentAccount $account,
//         string $receivedAmount,
//         string $unallocatedAmount,
//         RepaymentTransaction $transaction,
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

//         foreach (
//             [
//                 'principal_outstanding' =>
//                     $principalOutstanding,

//                 'interest_outstanding' =>
//                     $interestOutstanding,

//                 'total_outstanding' =>
//                     $totalOutstanding,

//                 'overdue_amount' =>
//                     $overdueAmount,
//             ] as $field => $value
//         ) {
//             if (bccomp($value, '0.00', 2) === -1) {
//                 throw RepaymentPaymentException::
//                     financialInvariantViolation(
//                         message: 'Repayment balance became negative after payment allocation.',
//                         context: [
//                             'repayment_account_uuid' =>
//                                 $account->uuid,

//                             'field' => $field,
//                             'value' => $value,

//                             'repayment_transaction_uuid' =>
//                                 $transaction->uuid,
//                         ]
//                     );
//             }
//         }

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
//             throw RepaymentPaymentException::
//                 financialInvariantViolation(
//                     message: 'Repayment account component balances do not match total outstanding.',
//                     context: [
//                         'repayment_account_uuid' =>
//                             $account->uuid,

//                         'principal_outstanding' =>
//                             $principalOutstanding,

//                         'interest_outstanding' =>
//                             $interestOutstanding,

//                         'component_total' =>
//                             $componentTotal,

//                         'total_outstanding' =>
//                             $totalOutstanding,

//                         'repayment_transaction_uuid' =>
//                             $transaction->uuid,
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

//         $oldAccountStatus = $account->status;

//         $newAccountStatus =
//             bccomp(
//                 $totalOutstanding,
//                 '0.00',
//                 2
//             ) === 0
//             ? RepaymentAccountStatus::CLOSED
//             : RepaymentAccountStatus::ACTIVE;

//         $account->forceFill([
//             'principal_outstanding' =>
//                 $principalOutstanding,

//             'interest_outstanding' =>
//                 $interestOutstanding,

//             'total_outstanding' =>
//                 $totalOutstanding,

//             'overdue_amount' =>
//                 $overdueAmount,

//             'advance_amount' => bcadd(
//                 (string) $account->advance_amount,
//                 $unallocatedAmount,
//                 2
//             ),

//             'total_received' => bcadd(
//                 (string) $account->total_received,
//                 $receivedAmount,
//                 2
//             ),

//             'next_due_date' => $nextDueDate,

//             'last_payment_at' => $postingTimestamp,

//             'status' => $newAccountStatus->value,

//             'closed_at' =>
//                 $newAccountStatus
//                 === RepaymentAccountStatus::CLOSED
//                 ? $postingTimestamp
//                 : null,

//             'updated_by' => $actorId,
//         ])->save();

//         if ($oldAccountStatus !== $newAccountStatus) {
//             RepaymentStatusHistory::query()->create([
//                 'repayment_account_id' => $account->id,

//                 'repayment_transaction_id' =>
//                     $transaction->id,

//                 'status_type' =>
//                     StatusHistoryType::ACCOUNT_STATUS->value,

//                 'old_status' =>
//                     $oldAccountStatus->value,

//                 'new_status' =>
//                     $newAccountStatus->value,

//                 'source' =>
//                     StatusHistorySource::ACCOUNT_CLOSURE->value,

//                 'reason' =>
//                     'Repayment obligations were fully settled.',

//                 'metadata' => [
//                     'principal_outstanding' =>
//                         $principalOutstanding,

//                     'interest_outstanding' =>
//                         $interestOutstanding,

//                     'total_outstanding' =>
//                         $totalOutstanding,

//                     'advance_amount_added' =>
//                         $unallocatedAmount,
//                 ],

//                 'changed_by' => $actorId,
//                 'changed_at' => $postingTimestamp,
//             ]);
//         }
//     }

//     /**
//      * Validate installment component balances before allocation.
//      */
//     private function assertValidInstallmentState(
//         RepaymentInstallment $installment
//     ): void {
//         $values = [
//             'principal_due' =>
//                 $this->normalizeMoney(
//                     $installment->principal_due
//                 ),

//             'interest_due' =>
//                 $this->normalizeMoney(
//                     $installment->interest_due
//                 ),

//             'principal_paid' =>
//                 $this->normalizeMoney(
//                     $installment->principal_paid
//                 ),

//             'interest_paid' =>
//                 $this->normalizeMoney(
//                     $installment->interest_paid
//                 ),

//             'outstanding_amount' =>
//                 $this->normalizeMoney(
//                     $installment->outstanding_amount
//                 ),
//         ];

//         foreach ($values as $field => $value) {
//             if (bccomp($value, '0.00', 2) === -1) {
//                 throw RepaymentPaymentException::
//                     invalidInstallmentState(
//                         installmentUuid:
//                         (string) $installment->uuid,

//                         installmentNumber:
//                         (int) $installment
//                             ->installment_number,

//                         details: [
//                             'field' => $field,
//                             'value' => $value,
//                         ]
//                     );
//             }
//         }

//         /*
//          * Interest remains an exact financial component, therefore it may
//          * never be paid above its due value.
//          *
//          * Principal is intentionally not compared with principal_due here:
//          * the rounded-EMI difference is absorbed on the principal side, so
//          * principal_paid can differ from the exact amortization principal by
//          * a few paise.
//          */
//         if (
//             bccomp(
//                 $values['interest_paid'],
//                 $values['interest_due'],
//                 2
//             ) === 1
//         ) {
//             throw RepaymentPaymentException::
//                 invalidInstallmentState(
//                     installmentUuid:
//                     (string) $installment->uuid,

//                     installmentNumber:
//                     (int) $installment
//                         ->installment_number,

//                     details: [
//                         'interest_due' =>
//                             $values['interest_due'],

//                         'interest_paid' =>
//                             $values['interest_paid'],
//                     ]
//                 );
//         }

//         /*
//          * Do not enforce:
//          *
//          * principal_due + interest_due - paid == outstanding_amount
//          *
//          * because outstanding_amount is deliberately a rounded collectible
//          * amount. Keeping that old invariant is what caused a fully-paid
//          * rounded EMI to remain PARTIAL.
//          */
//     }

//     /**
//      * Check duplicate external reference policy.
//      */
//     private function assertReferenceIsAvailable(
//         RepaymentAccount $account,
//         PaymentMode $paymentMode,
//         ?string $transactionReference
//     ): void {
//         /*
//          * Cash may have a locally entered reference, but it is
//          * not treated as a globally reconcilable banking key.
//          */
//         if (
//             !$paymentMode->requiresReference()
//             || $transactionReference === null
//         ) {
//             return;
//         }

//         $existing = RepaymentTransaction::query()
//             ->where(
//                 'repayment_account_id',
//                 $account->id
//             )
//             ->where(
//                 'payment_mode',
//                 $paymentMode->value
//             )
//             ->where(
//                 'transaction_reference',
//                 $transactionReference
//             )
//             ->where(
//                 'transaction_type',
//                 RepaymentTransactionType::PAYMENT->value
//             )
//             ->where(
//                 'status',
//                 '!=',
//                 RepaymentTransactionStatus::FAILED->value
//             )
//             ->lockForUpdate()
//             ->first();

//         if ($existing) {
//             throw RepaymentPaymentException::
//                 transactionReferenceAlreadyUsed(
//                     paymentMode: $paymentMode->value,

//                     transactionReference:
//                     $transactionReference,

//                     existingTransactionUuid:
//                     (string) $existing->uuid
//                 );
//         }
//     }

//     /**
//      * Determine whether the repeated idempotent request has
//      * exactly the same financial payload.
//      *
//      * @param array<string, mixed> $payload
//      */
//     private function requestMatchesTransaction(
//         RepaymentAccount $account,
//         RepaymentTransaction $transaction,
//         array $payload
//     ): bool {
//         if (
//             (int) $transaction->repayment_account_id
//             !== (int) $account->id
//         ) {
//             return false;
//         }

//         if (
//             $transaction->transaction_type
//             !== RepaymentTransactionType::PAYMENT
//         ) {
//             return false;
//         }

//         $paymentMode = PaymentMode::tryFrom(
//             strtoupper(
//                 $this->normalizeRequiredString(
//                     $payload['payment_mode'] ?? ''
//                 )
//             )
//         );

//         if (!$paymentMode) {
//             return false;
//         }

//         $fingerprint = $this->buildRequestFingerprint(
//             accountUuid: (string) $account->uuid,

//             amount: $this->normalizeMoney(
//                 $payload['amount'] ?? '0'
//             ),

//             paymentMode: $paymentMode,

//             transactionReference:
//             $this->normalizeNullableString(
//                 $payload['transaction_reference'] ?? null
//             ),

//             receivedDate:
//             $this->normalizeRequiredString(
//                 $payload['received_date'] ?? ''
//             ),

//             valueDate:
//             $this->normalizeRequiredString(
//                 $payload['value_date'] ?? ''
//             ),

//             remarks:
//             $this->normalizeNullableString(
//                 $payload['remarks'] ?? null
//             )
//         );

//         $existingFingerprint =
//             $transaction->metadata['request_fingerprint']
//             ?? null;

//         if (is_string($existingFingerprint)) {
//             return hash_equals(
//                 $existingFingerprint,
//                 $fingerprint
//             );
//         }

//         /*
//          * Backward-compatible comparison for records created
//          * before request fingerprint storage.
//          */
//         return bccomp(
//             (string) $transaction->amount,
//             $this->normalizeMoney(
//                 $payload['amount'] ?? '0'
//             ),
//             2
//         ) === 0
//             && $transaction->payment_mode === $paymentMode
//             && $transaction->transaction_reference
//             === $this->normalizeNullableString(
//                 $payload['transaction_reference'] ?? null
//             )
//             && optional(
//                 $transaction->received_date
//             )->format('Y-m-d')
//             === $this->normalizeRequiredString(
//                 $payload['received_date'] ?? ''
//             )
//             && optional(
//                 $transaction->value_date
//             )->format('Y-m-d')
//             === $this->normalizeRequiredString(
//                 $payload['value_date'] ?? ''
//             )
//             && $this->normalizeNullableString(
//                 $transaction->remarks
//             ) === $this->normalizeNullableString(
//                     $payload['remarks'] ?? null
//                 );
//     }

//     /**
//      * Generate canonical request fingerprint.
//      */
//     private function buildRequestFingerprint(
//         string $accountUuid,
//         string $amount,
//         PaymentMode $paymentMode,
//         ?string $transactionReference,
//         string $receivedDate,
//         string $valueDate,
//         ?string $remarks
//     ): string {
//         return hash(
//             'sha256',
//             json_encode([
//                 'repayment_account_uuid' => $accountUuid,
//                 'amount' => $amount,
//                 'payment_mode' => $paymentMode->value,
//                 'transaction_reference' =>
//                     $transactionReference,
//                 'received_date' => $receivedDate,
//                 'value_date' => $valueDate,
//                 'remarks' => $remarks,
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
//      * Round repayment collectible/outstanding to a whole rupee while
//      * preserving DECIMAL(18,2)-style formatting in the database.
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
//      * Normalize a decimal value to two places.
//      */
//     private function normalizeMoney(mixed $value): string
//     {
//         return bcadd(
//             trim((string) $value),
//             '0.00',
//             2
//         );
//     }

//     /**
//      * Return the smaller of two non-negative money values.
//      */
//     private function minimumMoney(
//         string $first,
//         string $second
//     ): string {
//         return bccomp($first, $second, 2) <= 0
//             ? $first
//             : $second;
//     }

//     private function normalizeRequiredString(
//         mixed $value
//     ): string {
//         return trim((string) $value);
//     }

//     private function normalizeNullableString(
//         mixed $value
//     ): ?string {
//         if ($value === null) {
//             return null;
//         }

//         $normalized = trim((string) $value);

//         return $normalized === ''
//             ? null
//             : $normalized;
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
