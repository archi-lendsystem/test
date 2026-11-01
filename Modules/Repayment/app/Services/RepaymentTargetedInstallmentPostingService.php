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

final class RepaymentTargetedInstallmentPostingService
{
    private const MONEY_SCALE = 2;

    public function __construct(
        private readonly RepaymentTransactionNumberGenerator
            $transactionNumberGenerator
    ) {
    }

    /**
     * Post one targeted repayment transaction.
     *
     * Unlike RepaymentPaymentPostingService, this service does
     * NOT perform oldest-outstanding waterfall allocation.
     *
     * It allocates only to explicitly supplied installments.
     *
     * @param array<string, mixed> $payload
     * @param array<int, array<string, mixed>> $targets
     * @param array<string, mixed> $metadata
     */
    public function post(
        string $accountUuid,
        array $payload,
        array $targets,
        ?int $actorId = null,
        array $metadata = []
    ): RepaymentTransaction {
        try {
            return DB::transaction(
                fn (): RepaymentTransaction =>
                    $this->postInsideTransaction(
                        accountUuid:
                            $accountUuid,

                        payload:
                            $payload,

                        targets:
                            $targets,

                        actorId:
                            $actorId,

                        metadata:
                            $metadata
                    ),
                attempts: 3
            );
        } catch (QueryException $exception) {
            /*
             * Same duplicate-idempotency protection used by
             * the normal payment posting service.
             */
            if (
                $this->isDuplicateKeyException(
                    $exception
                )
            ) {
                $idempotencyKey =
                    $this->normalizeRequiredString(
                        $payload[
                            'idempotency_key'
                        ] ?? ''
                    );

                $existing =
                    RepaymentTransaction::query()
                        ->useWritePdo()
                        ->where(
                            'idempotency_key',
                            $idempotencyKey
                        )
                        ->first();

                if ($existing) {
                    $account =
                        RepaymentAccount::query()
                            ->useWritePdo()
                            ->where(
                                'uuid',
                                $accountUuid
                            )
                            ->first();

                    if (! $account) {
                        throw RepaymentPaymentException::
                            accountNotFound(
                                $accountUuid
                            );
                    }

                    if (
                        $this->requestMatchesTransaction(
                            account:
                                $account,

                            transaction:
                                $existing,

                            payload:
                                $payload,

                            targets:
                                $targets
                        )
                    ) {
                        return $this
                            ->hydrateTransaction(
                                $existing
                            );
                    }

                    throw RepaymentPaymentException::
                        idempotencyConflict(
                            idempotencyKey:
                                (string)
                                $existing
                                    ->idempotency_key,

                            existingTransactionUuid:
                                (string)
                                $existing
                                    ->uuid
                        );
                }
            }

            throw $exception;
        }
    }

    /**
     * Post targeted collection inside an already-open
     * database transaction.
     *
     * Bulk repayment import uses this method so the complete
     * Excel file remains all-or-nothing.
     *
     * @param array<string, mixed> $payload
     * @param array<int, array<string, mixed>> $targets
     * @param array<string, mixed> $metadata
     */
    public function postWithinTransaction(
        string $accountUuid,
        array $payload,
        array $targets,
        ?int $actorId = null,
        array $metadata = []
    ): RepaymentTransaction {
        if (
            DB::connection()
                ->transactionLevel() < 1
        ) {
            throw new LogicException(
                'postWithinTransaction() requires an active database transaction.'
            );
        }

        return $this->postInsideTransaction(
            accountUuid:
                $accountUuid,

            payload:
                $payload,

            targets:
                $targets,

            actorId:
                $actorId,

            metadata:
                $metadata
        );
    }

    /**
     * Execute complete exact-installment posting workflow.
     *
     * @param array<string, mixed> $payload
     * @param array<int, array<string, mixed>> $targets
     * @param array<string, mixed> $metadata
     */
    private function postInsideTransaction(
        string $accountUuid,
        array $payload,
        array $targets,
        ?int $actorId,
        array $metadata = []
    ): RepaymentTransaction {
        /*
         * =========================================================
         * LOCK ACCOUNT
         * =========================================================
         */
        $account =
            RepaymentAccount::query()
                ->useWritePdo()
                ->where(
                    'uuid',
                    $accountUuid
                )
                ->lockForUpdate()
                ->first();

        if (! $account) {
            throw RepaymentPaymentException::
                accountNotFound(
                    $accountUuid
                );
        }

        $idempotencyKey =
            $this->normalizeRequiredString(
                $payload[
                    'idempotency_key'
                ] ?? ''
            );

        /*
         * Normalize targeted selections before transaction
         * creation.
         */
        $normalizedTargets =
            $this->normalizeTargets(
                $targets
            );

        /*
         * =========================================================
         * IDEMPOTENCY
         * =========================================================
         *
         * Check before account status validation.
         *
         * A successful payment may have closed the account, but
         * retrying exactly the same request must still safely
         * return the original transaction.
         */
        $existing =
            RepaymentTransaction::query()
                ->useWritePdo()
                ->where(
                    'idempotency_key',
                    $idempotencyKey
                )
                ->lockForUpdate()
                ->first();

        if ($existing) {
            if (
                $this->requestMatchesTransaction(
                    account:
                        $account,

                    transaction:
                        $existing,

                    payload:
                        $payload,

                    targets:
                        $normalizedTargets
                )
            ) {
                return $this
                    ->hydrateTransaction(
                        $existing
                    );
            }

            throw RepaymentPaymentException::
                idempotencyConflict(
                    idempotencyKey:
                        $idempotencyKey,

                    existingTransactionUuid:
                        (string)
                        $existing->uuid
                );
        }

        /*
         * =========================================================
         * ACCOUNT PAYMENT ELIGIBILITY
         * =========================================================
         */
        if (
            ! $account->allowsPayment()
        ) {
            throw RepaymentPaymentException::
                accountDoesNotAllowPayment(
                    accountUuid:
                        (string)
                        $account->uuid,

                    accountStatus:
                        $account
                            ->status
                            ->value
                );
        }

        /*
         * =========================================================
         * PAYMENT AMOUNT
         * =========================================================
         */
        $amount =
            $this->normalizeMoney(
                $payload[
                    'amount'
                ] ?? '0'
            );

        if (
            bccomp(
                $amount,
                '0.00',
                self::MONEY_SCALE
            ) !== 1
        ) {
            throw new RepaymentPaymentException(
                message:
                    'Payment amount must be greater than zero.',

                context: [
                    'repayment_account_uuid' =>
                        $accountUuid,

                    'amount' =>
                        $amount,
                ],

                statusCode:
                    422
            );
        }

        /*
         * Target amounts must exactly equal transaction amount.
         */
        $targetTotal =
            $this->sumTargetAmounts(
                $normalizedTargets
            );

        if (
            bccomp(
                $targetTotal,
                $amount,
                self::MONEY_SCALE
            ) !== 0
        ) {
            throw new RepaymentPaymentException(
                message:
                    'Targeted EMI collection total does not match the payment amount.',

                context: [
                    'repayment_account_uuid' =>
                        $accountUuid,

                    'payment_amount' =>
                        $amount,

                    'targeted_amount' =>
                        $targetTotal,
                ],

                statusCode:
                    422
            );
        }

        /*
         * =========================================================
         * PAYMENT MODE / REFERENCE
         * =========================================================
         */
        $paymentMode =
            PaymentMode::tryFrom(
                strtoupper(
                    $this
                        ->normalizeRequiredString(
                            $payload[
                                'payment_mode'
                            ] ?? ''
                        )
                )
            );

        if (! $paymentMode) {
            throw new RepaymentPaymentException(
                message:
                    'Invalid payment mode selected.',

                context: [
                    'repayment_account_uuid' =>
                        $accountUuid,

                    'payment_mode' =>
                        $payload[
                            'payment_mode'
                        ] ?? null,
                ],

                statusCode:
                    422
            );
        }

        $transactionReference =
            $this->normalizeNullableString(
                $payload[
                    'transaction_reference'
                ] ?? null
            );

        if (
            $paymentMode
                ->requiresReference()
            && $transactionReference
                === null
        ) {
            throw new RepaymentPaymentException(
                message:
                    'Transaction reference is required for the selected payment mode.',

                context: [
                    'repayment_account_uuid' =>
                        $accountUuid,

                    'payment_mode' =>
                        $paymentMode
                            ->value,
                ],

                statusCode:
                    422
            );
        }

        $this->assertReferenceIsAvailable(
            account:
                $account,

            paymentMode:
                $paymentMode,

            transactionReference:
                $transactionReference
        );

        /*
         * =========================================================
         * PAYMENT DATES / REMARKS
         * =========================================================
         */
        $receivedDate =
            $this->normalizeRequiredString(
                $payload[
                    'received_date'
                ] ?? ''
            );

        $valueDate =
            $this->normalizeRequiredString(
                $payload[
                    'value_date'
                ] ?? ''
            );

        $remarks =
            $this->normalizeNullableString(
                $payload[
                    'remarks'
                ] ?? null
            );

        /*
         * =========================================================
         * LOCK EXACT TARGETED INSTALLMENTS
         * =========================================================
         *
         * Deterministic database-ID ordering prevents deadlock
         * surprises when overlapping targeted requests occur.
         */
        $targetUuids =
            array_column(
                $normalizedTargets,
                'repayment_installment_uuid'
            );

        $liveInstallments =
            RepaymentInstallment::query()
                ->useWritePdo()
                ->whereIn(
                    'uuid',
                    $targetUuids
                )
                ->orderBy(
                    'id'
                )
                ->lockForUpdate()
                ->get();

        $liveByUuid = [];

        foreach (
            $liveInstallments
            as $installment
        ) {
            $liveByUuid[
                strtolower(
                    (string)
                    $installment->uuid
                )
            ] =
                $installment;
        }

        /*
         * Every target must resolve to an actual live EMI.
         */
        foreach (
            $normalizedTargets
            as $target
        ) {
            $targetUuid =
                $target[
                    'repayment_installment_uuid'
                ];

            if (
                ! isset(
                    $liveByUuid[
                        $targetUuid
                    ]
                )
            ) {
                throw new RepaymentPaymentException(
                    message:
                        'A targeted repayment installment no longer exists.',

                    context: [
                        'repayment_account_uuid' =>
                            $accountUuid,

                        'repayment_installment_uuid' =>
                            $targetUuid,

                        'installment_number' =>
                            $target[
                                'installment_number'
                            ],
                    ],

                    statusCode:
                        409
                );
            }

            /** @var RepaymentInstallment $installment */
            $installment =
                $liveByUuid[
                    $targetUuid
                ];

            $this->assertTargetStillEligible(
                account:
                    $account,

                installment:
                    $installment,

                target:
                    $target
            );
        }

        /*
         * =========================================================
         * TARGET-AWARE REQUEST FINGERPRINT
         * =========================================================
         *
         * Important:
         *
         * Normal payment fingerprint only contains total amount.
         *
         * Targeted payment fingerprint must also contain the
         * exact EMI UUID + amount mapping.
         */
        $requestFingerprint =
            $this->buildRequestFingerprint(
                accountUuid:
                    $accountUuid,

                amount:
                    $amount,

                paymentMode:
                    $paymentMode,

                transactionReference:
                    $transactionReference,

                receivedDate:
                    $receivedDate,

                valueDate:
                    $valueDate,

                remarks:
                    $remarks,

                targets:
                    $normalizedTargets
            );

        /*
         * =========================================================
         * CREATE PAYMENT TRANSACTION
         * =========================================================
         *
         * Still one transaction per account/Excel row.
         */
        $transaction =
            RepaymentTransaction::query()
                ->create([
                    'repayment_account_id' =>
                        $account->id,

                    /*
                     * Temporary unique number first.
                     */
                    'transaction_number' =>
                        $this
                            ->transactionNumberGenerator
                            ->generateTemporary(),

                    'idempotency_key' =>
                        $idempotencyKey,

                    'transaction_type' =>
                        RepaymentTransactionType::
                            PAYMENT
                            ->value,

                    'amount' =>
                        $amount,

                    'allocated_amount' =>
                        '0.00',

                    /*
                     * During creation, everything is still
                     * considered unallocated.
                     */
                    'unallocated_amount' =>
                        $amount,

                    'payment_mode' =>
                        $paymentMode
                            ->value,

                    'transaction_reference' =>
                        $transactionReference,

                    'received_date' =>
                        $receivedDate,

                    'value_date' =>
                        $valueDate,

                    'status' =>
                        RepaymentTransactionStatus::
                            PENDING
                            ->value,

                    'remarks' =>
                        $remarks,

                    'metadata' =>
                        array_merge(
                            $metadata,
                            [
                                /*
                                 * Caller cannot override these
                                 * system-owned values.
                                 */
                                'request_fingerprint' =>
                                    $requestFingerprint,

                                'allocation_strategy' =>
                                    'TARGETED_INSTALLMENTS_INTEREST_THEN_PRINCIPAL',

                                'targeted_installment_count' =>
                                    count(
                                        $normalizedTargets
                                    ),

                                /*
                                 * Public/audit-safe target map.
                                 */
                                'targeted_installments' =>
                                    array_map(
                                        static fn (
                                            array $target
                                        ): array => [
                                            'repayment_installment_uuid' =>
                                                $target[
                                                    'repayment_installment_uuid'
                                                ],

                                            'installment_number' =>
                                                $target[
                                                    'installment_number'
                                                ],

                                            'collection_amount' =>
                                                $target[
                                                    'collection_amount'
                                                ],
                                        ],
                                        $normalizedTargets
                                    ),
                            ]
                        ),

                    'created_by' =>
                        $actorId,
                ]);

        /*
         * Replace temporary number with final ID-based number.
         */
        $transaction
            ->transaction_number =
                $this
                    ->transactionNumberGenerator
                    ->generateFinal(
                        transactionId:
                            (int)
                            $transaction->id,

                        businessDate:
                            $receivedDate
                    );

        $transaction->save();

        $postingTimestamp =
            now();

        /*
         * =========================================================
         * EXACT TARGETED ALLOCATION
         * =========================================================
         */
        $allocationResult =
            $this->allocateTargets(
                account:
                    $account,

                transaction:
                    $transaction,

                targets:
                    $normalizedTargets,

                liveByUuid:
                    $liveByUuid,

                actorId:
                    $actorId,

                postingTimestamp:
                    $postingTimestamp
            );

        /*
         * Targeted import never allows advance/unallocated
         * spillover.
         */
        if (
            bccomp(
                $allocationResult[
                    'remaining_amount'
                ],
                '0.00',
                self::MONEY_SCALE
            ) !== 0
        ) {
            throw RepaymentPaymentException::
                financialInvariantViolation(
                    message:
                        'Targeted repayment payment could not be fully allocated to the selected installments.',

                    context: [
                        'repayment_account_uuid' =>
                            $account->uuid,

                        'repayment_transaction_uuid' =>
                            $transaction->uuid,

                        'payment_amount' =>
                            $amount,

                        'remaining_amount' =>
                            $allocationResult[
                                'remaining_amount'
                            ],
                    ]
                );
        }

        $allocatedAmount =
            bcsub(
                $amount,
                $allocationResult[
                    'remaining_amount'
                ],
                self::MONEY_SCALE
            );

        if (
            bccomp(
                $allocatedAmount,
                $amount,
                self::MONEY_SCALE
            ) !== 0
        ) {
            throw RepaymentPaymentException::
                financialInvariantViolation(
                    message:
                        'Targeted repayment allocated amount does not match transaction amount.',

                    context: [
                        'repayment_account_uuid' =>
                            $account->uuid,

                        'repayment_transaction_uuid' =>
                            $transaction->uuid,

                        'payment_amount' =>
                            $amount,

                        'allocated_amount' =>
                            $allocatedAmount,
                    ]
                );
        }

        /*
         * =========================================================
         * POST TRANSACTION
         * =========================================================
         */
        $transaction
            ->forceFill([
                'allocated_amount' =>
                    $allocatedAmount,

                'unallocated_amount' =>
                    '0.00',

                'posted_at' =>
                    $postingTimestamp,

                'status' =>
                    RepaymentTransactionStatus::
                        POSTED
                        ->value,

                'approved_by' =>
                    $actorId,

                'metadata' =>
                    array_merge(
                        $transaction
                            ->metadata
                            ?? [],
                        [
                            'allocation_count' =>
                                $allocationResult[
                                    'allocation_count'
                                ],

                            'interest_allocated' =>
                                $allocationResult[
                                    'interest_allocated'
                                ],

                            'principal_allocated' =>
                                $allocationResult[
                                    'principal_allocated'
                                ],

                            'unallocated_amount' =>
                                '0.00',
                        ]
                    ),
            ])
            ->save();

        /*
         * Same account recalculation behaviour as normal payment
         * posting, but unallocated amount is always zero.
         */
        $this->refreshAccountBalances(
            account:
                $account,

            receivedAmount:
                $amount,

            unallocatedAmount:
                '0.00',

            transaction:
                $transaction,

            actorId:
                $actorId,

            postingTimestamp:
                $postingTimestamp
        );

        return $this
            ->hydrateTransaction(
                $transaction
            );
    }

    /**
     * Normalize and canonicalize targeted EMI selections.
     *
     * Canonical sorting makes request fingerprint stable
     * regardless of array input order.
     *
     * @param array<int, array<string, mixed>> $targets
     *
     * @return array<int, array{
     *     repayment_installment_uuid: string,
     *     installment_number: int,
     *     collection_amount: string
     * }>
     */
    private function normalizeTargets(
        array $targets
    ): array {
        if ($targets === []) {
            throw new RepaymentPaymentException(
                message:
                    'At least one targeted repayment installment is required.',

                context: [],

                statusCode:
                    422
            );
        }

        $normalized = [];

        $seenUuids = [];
        $seenNumbers = [];

        foreach (
            $targets as $index => $target
        ) {
            if (! is_array($target)) {
                throw new RepaymentPaymentException(
                    message:
                        'Targeted repayment installment structure is invalid.',

                    context: [
                        'target_index' =>
                            $index,
                    ],

                    statusCode:
                        422
                );
            }

            $uuid =
                strtolower(
                    $this->normalizeRequiredString(
                        $target[
                            'repayment_installment_uuid'
                        ] ?? ''
                    )
                );

            $installmentNumber =
                (int) (
                    $target[
                        'installment_number'
                    ] ?? 0
                );

            $collectionAmount =
                $this->normalizeMoney(
                    $target[
                        'collection_amount'
                    ] ?? '0'
                );

            if ($uuid === '') {
                throw new RepaymentPaymentException(
                    message:
                        'Targeted repayment installment UUID is required.',

                    context: [
                        'target_index' =>
                            $index,
                    ],

                    statusCode:
                        422
                );
            }

            if ($installmentNumber < 1) {
                throw new RepaymentPaymentException(
                    message:
                        'Targeted repayment installment number is invalid.',

                    context: [
                        'target_index' =>
                            $index,

                        'installment_number' =>
                            $target[
                                'installment_number'
                            ] ?? null,
                    ],

                    statusCode:
                        422
                );
            }

            if (
                bccomp(
                    $collectionAmount,
                    '0.00',
                    self::MONEY_SCALE
                ) !== 1
            ) {
                throw new RepaymentPaymentException(
                    message:
                        'Targeted EMI collection amount must be greater than zero.',

                    context: [
                        'target_index' =>
                            $index,

                        'repayment_installment_uuid' =>
                            $uuid,

                        'installment_number' =>
                            $installmentNumber,

                        'collection_amount' =>
                            $collectionAmount,
                    ],

                    statusCode:
                        422
                );
            }

            if (isset($seenUuids[$uuid])) {
                throw new RepaymentPaymentException(
                    message:
                        'The same repayment installment UUID was targeted more than once.',

                    context: [
                        'repayment_installment_uuid' =>
                            $uuid,
                    ],

                    statusCode:
                        422
                );
            }

            if (
                isset(
                    $seenNumbers[
                        $installmentNumber
                    ]
                )
            ) {
                throw new RepaymentPaymentException(
                    message:
                        'The same repayment installment number was targeted more than once.',

                    context: [
                        'installment_number' =>
                            $installmentNumber,
                    ],

                    statusCode:
                        422
                );
            }

            $seenUuids[
                $uuid
            ] =
                true;

            $seenNumbers[
                $installmentNumber
            ] =
                true;

            $normalized[] = [
                'repayment_installment_uuid' =>
                    $uuid,

                'installment_number' =>
                    $installmentNumber,

                'collection_amount' =>
                    $collectionAmount,
            ];
        }

        /*
         * Deterministic schedule order.
         */
        usort(
            $normalized,
            static function (
                array $left,
                array $right
            ): int {
                $numberComparison =
                    $left[
                        'installment_number'
                    ]
                    <=>
                    $right[
                        'installment_number'
                    ];

                if ($numberComparison !== 0) {
                    return $numberComparison;
                }

                return strcmp(
                    $left[
                        'repayment_installment_uuid'
                    ],
                    $right[
                        'repayment_installment_uuid'
                    ]
                );
            }
        );

        return $normalized;
    }

    /**
     * Sum exact selected EMI collection amounts.
     *
     * @param array<int, array<string, mixed>> $targets
     */
    private function sumTargetAmounts(
        array $targets
    ): string {
        $total =
            '0.00';

        foreach ($targets as $target) {
            $total =
                bcadd(
                    $total,
                    $this->normalizeMoney(
                        $target[
                            'collection_amount'
                        ] ?? '0'
                    ),
                    self::MONEY_SCALE
                );
        }

        return $total;
    }

    /**
     * Confirm a selected live EMI still belongs to this
     * account/current schedule and can accept the exact
     * targeted amount.
     *
     * @param array<string, mixed> $target
     */
    private function assertTargetStillEligible(
        RepaymentAccount $account,
        RepaymentInstallment $installment,
        array $target
    ): void {
        if (
            (int)
            $installment
                ->repayment_account_id
            !==
            (int)
            $account->id
        ) {
            throw new RepaymentPaymentException(
                message:
                    'Targeted repayment installment does not belong to this repayment account.',

                context: [
                    'repayment_account_uuid' =>
                        $account->uuid,

                    'repayment_installment_uuid' =>
                        $installment->uuid,
                ],

                statusCode:
                    409
            );
        }

        if (
            (int)
            $installment
                ->schedule_version
            !==
            (int)
            $account
                ->schedule_version
        ) {
            throw new RepaymentPaymentException(
                message:
                    'Targeted repayment installment is no longer part of the current repayment schedule.',

                context: [
                    'repayment_account_uuid' =>
                        $account->uuid,

                    'repayment_installment_uuid' =>
                        $installment->uuid,

                    'installment_schedule_version' =>
                        (int)
                        $installment
                            ->schedule_version,

                    'account_schedule_version' =>
                        (int)
                        $account
                            ->schedule_version,
                ],

                statusCode:
                    409
            );
        }

        if (
            (int)
            $installment
                ->installment_number
            !==
            (int)
            $target[
                'installment_number'
            ]
        ) {
            throw new RepaymentPaymentException(
                message:
                    'Targeted EMI number no longer matches its installment UUID.',

                context: [
                    'repayment_installment_uuid' =>
                        $installment->uuid,

                    'expected_installment_number' =>
                        (int)
                        $target[
                            'installment_number'
                        ],

                    'current_installment_number' =>
                        (int)
                        $installment
                            ->installment_number,
                ],

                statusCode:
                    409
            );
        }

        $this->assertValidInstallmentState(
            $installment
        );

        $currentOutstanding =
            $this->roundWholeMoney(
                $installment
                    ->outstanding_amount
            );

        if (
            bccomp(
                $currentOutstanding,
                '0.00',
                self::MONEY_SCALE
            ) !== 1
        ) {
            throw new RepaymentPaymentException(
                message:
                    'Targeted EMI has no outstanding balance.',

                context: [
                    'repayment_installment_uuid' =>
                        $installment->uuid,

                    'installment_number' =>
                        $installment
                            ->installment_number,

                    'outstanding_amount' =>
                        $currentOutstanding,
                ],

                statusCode:
                    409
            );
        }

        if (
            ! $installment
                ->payment_status
                ->allowsAllocation()
        ) {
            throw new RepaymentPaymentException(
                message:
                    'Targeted EMI no longer allows payment allocation.',

                context: [
                    'repayment_installment_uuid' =>
                        $installment->uuid,

                    'installment_number' =>
                        $installment
                            ->installment_number,

                    'payment_status' =>
                        $installment
                            ->payment_status
                            ->value,
                ],

                statusCode:
                    409
            );
        }

        $collectionAmount =
            $this->normalizeMoney(
                $target[
                    'collection_amount'
                ]
            );

        if (
            bccomp(
                $collectionAmount,
                $currentOutstanding,
                self::MONEY_SCALE
            ) === 1
        ) {
            throw new RepaymentPaymentException(
                message:
                    'Targeted EMI collection amount is greater than the current installment outstanding.',

                context: [
                    'repayment_installment_uuid' =>
                        $installment->uuid,

                    'installment_number' =>
                        $installment
                            ->installment_number,

                    'collection_amount' =>
                        $collectionAmount,

                    'current_outstanding' =>
                        $currentOutstanding,
                ],

                statusCode:
                    409
            );
        }
    }

    /**
     * Allocate only to explicitly selected EMIs.
     *
     * Within each selected EMI:
     *
     * 1. remaining interest
     * 2. remaining principal
     *
     * No amount spills into any unselected installment.
     *
     * @param array<int, array<string, mixed>> $targets
     * @param array<string, RepaymentInstallment> $liveByUuid
     *
     * @return array{
     *     remaining_amount: string,
     *     interest_allocated: string,
     *     principal_allocated: string,
     *     allocation_count: int
     * }
     */
    private function allocateTargets(
        RepaymentAccount $account,
        RepaymentTransaction $transaction,
        array $targets,
        array $liveByUuid,
        ?int $actorId,
        mixed $postingTimestamp
    ): array {
        $transactionRemaining =
            $this->normalizeMoney(
                $transaction->amount
            );

        $interestAllocatedTotal =
            '0.00';

        $principalAllocatedTotal =
            '0.00';

        $allocationOrder =
            1;

        $allocationCount =
            0;

        foreach ($targets as $target) {
            $targetUuid =
                $target[
                    'repayment_installment_uuid'
                ];

            /** @var RepaymentInstallment $installment */
            $installment =
                $liveByUuid[
                    $targetUuid
                ];

            $this->assertValidInstallmentState(
                $installment
            );

            $oldPaymentStatus =
                $installment
                    ->payment_status;

            /*
             * Targeted collection also uses the same whole-rupee
             * collectible as normal repayment posting.
             */
            $installmentCollectible =
                $this->roundWholeMoney(
                    $installment
                        ->outstanding_amount
                );

            $targetAmount =
                $this->normalizeMoney(
                    $target[
                        'collection_amount'
                    ]
                );

            $targetRemaining =
                $targetAmount;

            $installmentInterestAllocated =
                '0.00';

            $installmentPrincipalAllocated =
                '0.00';

            /*
             * =====================================================
             * COMPONENT 1: INTEREST
             * =====================================================
             */
            $interestOutstanding =
                $installment
                    ->interestOutstanding();

            $interestOutstanding =
                $this->minimumMoney(
                    $interestOutstanding,
                    $installmentCollectible
                );

            $interestAllocation =
                $this->minimumMoney(
                    $targetRemaining,
                    $interestOutstanding
                );

            if (
                bccomp(
                    $interestAllocation,
                    '0.00',
                    self::MONEY_SCALE
                ) === 1
            ) {
                RepaymentAllocation::query()
                    ->create([
                        'repayment_transaction_id' =>
                            $transaction->id,

                        'repayment_installment_id' =>
                            $installment->id,

                        'component' =>
                            AllocationComponent::
                                INTEREST
                                ->value,

                        'amount' =>
                            $interestAllocation,

                        'allocation_order' =>
                            $allocationOrder++,

                        'metadata' => [
                            'installment_number' =>
                                $installment
                                    ->installment_number,

                            'due_date' =>
                                $installment
                                    ->due_date
                                    ->format(
                                        'Y-m-d'
                                    ),

                            'component_priority' =>
                                AllocationComponent::
                                    INTEREST
                                    ->priority(),

                            'allocation_strategy' =>
                                'TARGETED_INSTALLMENT',

                            'target_collection_amount' =>
                                $targetAmount,
                        ],
                    ]);

                $installment
                    ->interest_paid =
                        bcadd(
                            (string)
                            $installment
                                ->interest_paid,

                            $interestAllocation,

                            self::MONEY_SCALE
                        );

                $targetRemaining =
                    bcsub(
                        $targetRemaining,
                        $interestAllocation,
                        self::MONEY_SCALE
                    );

                $installmentCollectible =
                    bcsub(
                        $installmentCollectible,
                        $interestAllocation,
                        self::MONEY_SCALE
                    );

                if (
                    bccomp(
                        $installmentCollectible,
                        '0.00',
                        self::MONEY_SCALE
                    ) === -1
                ) {
                    $installmentCollectible = '0.00';
                }

                $transactionRemaining =
                    bcsub(
                        $transactionRemaining,
                        $interestAllocation,
                        self::MONEY_SCALE
                    );

                $interestAllocatedTotal =
                    bcadd(
                        $interestAllocatedTotal,
                        $interestAllocation,
                        self::MONEY_SCALE
                    );

                $installmentInterestAllocated =
                    $interestAllocation;

                $allocationCount++;
            }

            /*
             * =====================================================
             * COMPONENT 2: PRINCIPAL
             * =====================================================
             */
            /*
             * Remaining rounded collectible is the principal-side
             * collectible, so paise are not carried to another EMI.
             */
            $principalOutstanding =
                $installmentCollectible;

            $principalAllocation =
                $this->minimumMoney(
                    $targetRemaining,
                    $principalOutstanding
                );

            if (
                bccomp(
                    $principalAllocation,
                    '0.00',
                    self::MONEY_SCALE
                ) === 1
            ) {
                RepaymentAllocation::query()
                    ->create([
                        'repayment_transaction_id' =>
                            $transaction->id,

                        'repayment_installment_id' =>
                            $installment->id,

                        'component' =>
                            AllocationComponent::
                                PRINCIPAL
                                ->value,

                        'amount' =>
                            $principalAllocation,

                        'allocation_order' =>
                            $allocationOrder++,

                        'metadata' => [
                            'installment_number' =>
                                $installment
                                    ->installment_number,

                            'due_date' =>
                                $installment
                                    ->due_date
                                    ->format(
                                        'Y-m-d'
                                    ),

                            'component_priority' =>
                                AllocationComponent::
                                    PRINCIPAL
                                    ->priority(),

                            'allocation_strategy' =>
                                'TARGETED_INSTALLMENT',

                            'target_collection_amount' =>
                                $targetAmount,
                        ],
                    ]);

                $installment
                    ->principal_paid =
                        bcadd(
                            (string)
                            $installment
                                ->principal_paid,

                            $principalAllocation,

                            self::MONEY_SCALE
                        );

                $targetRemaining =
                    bcsub(
                        $targetRemaining,
                        $principalAllocation,
                        self::MONEY_SCALE
                    );

                $installmentCollectible =
                    bcsub(
                        $installmentCollectible,
                        $principalAllocation,
                        self::MONEY_SCALE
                    );

                if (
                    bccomp(
                        $installmentCollectible,
                        '0.00',
                        self::MONEY_SCALE
                    ) === -1
                ) {
                    $installmentCollectible = '0.00';
                }

                $transactionRemaining =
                    bcsub(
                        $transactionRemaining,
                        $principalAllocation,
                        self::MONEY_SCALE
                    );

                $principalAllocatedTotal =
                    bcadd(
                        $principalAllocatedTotal,
                        $principalAllocation,
                        self::MONEY_SCALE
                    );

                $installmentPrincipalAllocated =
                    $principalAllocation;

                $allocationCount++;
            }

            /*
             * Exact-target rule:
             *
             * selected EMI amount MUST be fully consumed
             * inside this exact installment.
             */
            if (
                bccomp(
                    $targetRemaining,
                    '0.00',
                    self::MONEY_SCALE
                ) !== 0
            ) {
                throw RepaymentPaymentException::
                    financialInvariantViolation(
                        message:
                            'Targeted EMI collection amount could not be fully allocated to the selected installment.',

                        context: [
                            'repayment_account_uuid' =>
                                $account->uuid,

                            'repayment_installment_uuid' =>
                                $installment->uuid,

                            'installment_number' =>
                                $installment
                                    ->installment_number,

                            'target_amount' =>
                                $targetAmount,

                            'remaining_target_amount' =>
                                $targetRemaining,

                            'repayment_transaction_uuid' =>
                                $transaction->uuid,
                        ]
                    );
            }

            /*
             * =====================================================
             * UPDATE TARGET INSTALLMENT
             * =====================================================
             */
            $newOutstanding =
                $this->roundWholeMoney(
                    $installmentCollectible
                );

            $totalPaid =
                bcadd(
                    (string)
                    $installment
                        ->interest_paid,

                    (string)
                    $installment
                        ->principal_paid,

                    self::MONEY_SCALE
                );

            $newPaymentStatus =
                match (true) {
                    bccomp(
                        $newOutstanding,
                        '0.00',
                        self::MONEY_SCALE
                    ) === 0 =>
                        InstallmentPaymentStatus::
                            PAID,

                    bccomp(
                        $totalPaid,
                        '0.00',
                        self::MONEY_SCALE
                    ) === 1 =>
                        InstallmentPaymentStatus::
                            PARTIAL,

                    default =>
                        InstallmentPaymentStatus::
                            UNPAID,
                };

            $installment
                ->forceFill([
                    'outstanding_amount' =>
                        $newOutstanding,

                    'payment_status' =>
                        $newPaymentStatus
                            ->value,

                    'last_payment_at' =>
                        $postingTimestamp,

                    'fully_paid_at' =>
                        $newPaymentStatus
                        ===
                        InstallmentPaymentStatus::
                            PAID
                            ? $postingTimestamp
                            : null,
                ])
                ->save();

            /*
             * Same payment-status history behaviour as normal
             * payment posting.
             */
            if (
                $oldPaymentStatus
                !==
                $newPaymentStatus
            ) {
                RepaymentStatusHistory::query()
                    ->create([
                        'repayment_account_id' =>
                            $account->id,

                        'repayment_installment_id' =>
                            $installment->id,

                        'repayment_transaction_id' =>
                            $transaction->id,

                        'status_type' =>
                            StatusHistoryType::
                                PAYMENT_STATUS
                                ->value,

                        'old_status' =>
                            $oldPaymentStatus
                                ->value,

                        'new_status' =>
                            $newPaymentStatus
                                ->value,

                        'source' =>
                            StatusHistorySource::
                                PAYMENT_POSTING
                                ->value,

                        'reason' =>
                            'Payment allocated to repayment installment.',

                        'metadata' => [
                            'allocation_strategy' =>
                                'TARGETED_INSTALLMENT',

                            'target_collection_amount' =>
                                $targetAmount,

                            'interest_allocated' =>
                                $installmentInterestAllocated,

                            'principal_allocated' =>
                                $installmentPrincipalAllocated,

                            'outstanding_after_payment' =>
                                $newOutstanding,
                        ],

                        'changed_by' =>
                            $actorId,

                        'changed_at' =>
                            $postingTimestamp,
                    ]);
            }
        }

        return [
            'remaining_amount' =>
                $transactionRemaining,

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
     *
     * Kept aligned with RepaymentPaymentPostingService so
     * targeted bulk payments update account aggregates exactly
     * like normal payments.
     */
    private function refreshAccountBalances(
        RepaymentAccount $account,
        string $receivedAmount,
        string $unallocatedAmount,
        RepaymentTransaction $transaction,
        ?int $actorId,
        mixed $postingTimestamp
    ): void {
        $totals =
            RepaymentInstallment::query()
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

        $totalOutstanding =
            $this->roundWholeMoney(
                $totals
                    ?->total_outstanding
                ?? '0'
            );

        $interestOutstanding =
            $this->roundWholeMoney(
                $totals
                    ?->interest_outstanding
                ?? '0'
            );

        if (
            bccomp(
                $interestOutstanding,
                $totalOutstanding,
                self::MONEY_SCALE
            ) === 1
        ) {
            $interestOutstanding =
                $totalOutstanding;
        }

        $principalOutstanding =
            bcsub(
                $totalOutstanding,
                $interestOutstanding,
                self::MONEY_SCALE
            );

        $overdueAmount =
            $this->roundWholeMoney(
                $totals
                    ?->overdue_amount
                ?? '0'
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
            if (
                bccomp(
                    $value,
                    '0.00',
                    self::MONEY_SCALE
                ) === -1
            ) {
                throw RepaymentPaymentException::
                    financialInvariantViolation(
                        message:
                            'Repayment balance became negative after targeted payment allocation.',

                        context: [
                            'repayment_account_uuid' =>
                                $account->uuid,

                            'field' =>
                                $field,

                            'value' =>
                                $value,

                            'repayment_transaction_uuid' =>
                                $transaction->uuid,
                        ]
                    );
            }
        }

        $componentTotal =
            bcadd(
                $principalOutstanding,
                $interestOutstanding,
                self::MONEY_SCALE
            );

        if (
            bccomp(
                $componentTotal,
                $totalOutstanding,
                self::MONEY_SCALE
            ) !== 0
        ) {
            throw RepaymentPaymentException::
                financialInvariantViolation(
                    message:
                        'Repayment account component balances do not match total outstanding.',

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

        $nextDueDate =
            RepaymentInstallment::query()
                ->where(
                    'repayment_account_id',
                    $account->id
                )
                ->whereRaw(
                    'ROUND(outstanding_amount, 0) > 0'
                )
                ->orderBy(
                    'due_date'
                )
                ->orderBy(
                    'installment_number'
                )
                ->value(
                    'due_date'
                );

        $oldAccountStatus =
            $account->status;

        $newAccountStatus =
            bccomp(
                $totalOutstanding,
                '0.00',
                self::MONEY_SCALE
            ) === 0
                ? RepaymentAccountStatus::
                    CLOSED
                : RepaymentAccountStatus::
                    ACTIVE;

        $account
            ->forceFill([
                'principal_outstanding' =>
                    $principalOutstanding,

                'interest_outstanding' =>
                    $interestOutstanding,

                'total_outstanding' =>
                    $totalOutstanding,

                'overdue_amount' =>
                    $overdueAmount,

                /*
                 * Targeted import always sends zero here.
                 */
                'advance_amount' =>
                    bcadd(
                        (string)
                        $account
                            ->advance_amount,

                        $unallocatedAmount,

                        self::MONEY_SCALE
                    ),

                'total_received' =>
                    bcadd(
                        (string)
                        $account
                            ->total_received,

                        $receivedAmount,

                        self::MONEY_SCALE
                    ),

                'next_due_date' =>
                    $nextDueDate,

                'last_payment_at' =>
                    $postingTimestamp,

                'status' =>
                    $newAccountStatus
                        ->value,

                'closed_at' =>
                    $newAccountStatus
                    ===
                    RepaymentAccountStatus::
                        CLOSED
                        ? $postingTimestamp
                        : null,

                'updated_by' =>
                    $actorId,
            ])
            ->save();

        if (
            $oldAccountStatus
            !==
            $newAccountStatus
        ) {
            RepaymentStatusHistory::query()
                ->create([
                    'repayment_account_id' =>
                        $account->id,

                    'repayment_transaction_id' =>
                        $transaction->id,

                    'status_type' =>
                        StatusHistoryType::
                            ACCOUNT_STATUS
                            ->value,

                    'old_status' =>
                        $oldAccountStatus
                            ->value,

                    'new_status' =>
                        $newAccountStatus
                            ->value,

                    'source' =>
                        StatusHistorySource::
                            ACCOUNT_CLOSURE
                            ->value,

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

                        'allocation_strategy' =>
                            'TARGETED_INSTALLMENTS_INTEREST_THEN_PRINCIPAL',
                    ],

                    'changed_by' =>
                        $actorId,

                    'changed_at' =>
                        $postingTimestamp,
                ]);
        }
    }

    /**
     * Validate installment component balances before
     * targeted allocation.
     *
     * Kept equivalent to normal payment posting invariant.
     */
    private function assertValidInstallmentState(
        RepaymentInstallment $installment
    ): void {
        $values = [
            'principal_due' =>
                $this->normalizeMoney(
                    $installment
                        ->principal_due
                ),

            'interest_due' =>
                $this->normalizeMoney(
                    $installment
                        ->interest_due
                ),

            'principal_paid' =>
                $this->normalizeMoney(
                    $installment
                        ->principal_paid
                ),

            'interest_paid' =>
                $this->normalizeMoney(
                    $installment
                        ->interest_paid
                ),

            'outstanding_amount' =>
                $this->normalizeMoney(
                    $installment
                        ->outstanding_amount
                ),
        ];

        foreach (
            $values as $field => $value
        ) {
            if (
                bccomp(
                    $value,
                    '0.00',
                    self::MONEY_SCALE
                ) === -1
            ) {
                throw RepaymentPaymentException::
                    invalidInstallmentState(
                        installmentUuid:
                            (string)
                            $installment
                                ->uuid,

                        installmentNumber:
                            (int)
                            $installment
                                ->installment_number,

                        details: [
                            'field' =>
                                $field,

                            'value' =>
                                $value,
                        ]
                    );
            }
        }

        /*
         * Interest remains exact and may not be over-allocated.
         * Principal may differ from principal_due by the EMI rounding
         * adjustment, so the old principal_due/expected-outstanding
         * invariants are intentionally not enforced.
         */
        if (
            bccomp(
                $values[
                    'interest_paid'
                ],
                $values[
                    'interest_due'
                ],
                self::MONEY_SCALE
            ) === 1
        ) {
            throw RepaymentPaymentException::
                invalidInstallmentState(
                    installmentUuid:
                        (string)
                        $installment->uuid,

                    installmentNumber:
                        (int)
                        $installment
                            ->installment_number,

                    details: [
                        'interest_due' =>
                            $values[
                                'interest_due'
                            ],

                        'interest_paid' =>
                            $values[
                                'interest_paid'
                            ],
                    ]
                );
        }
    }

    /**
     * Duplicate banking reference policy.
     */
    private function assertReferenceIsAvailable(
        RepaymentAccount $account,
        PaymentMode $paymentMode,
        ?string $transactionReference
    ): void {
        if (
            ! $paymentMode
                ->requiresReference()
            || $transactionReference
                === null
        ) {
            return;
        }

        $existing =
            RepaymentTransaction::query()
                ->useWritePdo()
                ->where(
                    'repayment_account_id',
                    $account->id
                )
                ->where(
                    'payment_mode',
                    $paymentMode
                        ->value
                )
                ->where(
                    'transaction_reference',
                    $transactionReference
                )
                ->where(
                    'transaction_type',
                    RepaymentTransactionType::
                        PAYMENT
                        ->value
                )
                ->where(
                    'status',
                    '!=',
                    RepaymentTransactionStatus::
                        FAILED
                        ->value
                )
                ->lockForUpdate()
                ->first();

        if ($existing) {
            throw RepaymentPaymentException::
                transactionReferenceAlreadyUsed(
                    paymentMode:
                        $paymentMode
                            ->value,

                    transactionReference:
                        $transactionReference,

                    existingTransactionUuid:
                        (string)
                        $existing
                            ->uuid
                );
        }
    }

    /**
     * Determine whether an idempotent retry represents
     * exactly the same targeted payment request.
     *
     * @param array<string, mixed> $payload
     * @param array<int, array<string, mixed>> $targets
     */
    private function requestMatchesTransaction(
        RepaymentAccount $account,
        RepaymentTransaction $transaction,
        array $payload,
        array $targets
    ): bool {
        if (
            (int)
            $transaction
                ->repayment_account_id
            !==
            (int)
            $account->id
        ) {
            return false;
        }

        if (
            $transaction
                ->transaction_type
            !==
            RepaymentTransactionType::
                PAYMENT
        ) {
            return false;
        }

        $paymentMode =
            PaymentMode::tryFrom(
                strtoupper(
                    $this
                        ->normalizeRequiredString(
                            $payload[
                                'payment_mode'
                            ] ?? ''
                        )
                )
            );

        if (! $paymentMode) {
            return false;
        }

        try {
            $normalizedTargets =
                $this->normalizeTargets(
                    $targets
                );
        } catch (
            RepaymentPaymentException
        ) {
            return false;
        }

        $fingerprint =
            $this->buildRequestFingerprint(
                accountUuid:
                    (string)
                    $account->uuid,

                amount:
                    $this->normalizeMoney(
                        $payload[
                            'amount'
                        ] ?? '0'
                    ),

                paymentMode:
                    $paymentMode,

                transactionReference:
                    $this
                        ->normalizeNullableString(
                            $payload[
                                'transaction_reference'
                            ] ?? null
                        ),

                receivedDate:
                    $this
                        ->normalizeRequiredString(
                            $payload[
                                'received_date'
                            ] ?? ''
                        ),

                valueDate:
                    $this
                        ->normalizeRequiredString(
                            $payload[
                                'value_date'
                            ] ?? ''
                        ),

                remarks:
                    $this
                        ->normalizeNullableString(
                            $payload[
                                'remarks'
                            ] ?? null
                        ),

                targets:
                    $normalizedTargets
            );

        $existingFingerprint =
            $transaction
                ->metadata[
                    'request_fingerprint'
                ]
            ?? null;

        /*
         * Targeted service deliberately does NOT fall back to
         * ordinary amount-only comparison.
         *
         * Without a targeted fingerprint we cannot prove that
         * the original transaction targeted the same EMIs.
         */
        if (
            ! is_string(
                $existingFingerprint
            )
        ) {
            return false;
        }

        return hash_equals(
            $existingFingerprint,
            $fingerprint
        );
    }

    /**
     * Generate canonical targeted request fingerprint.
     *
     * @param array<int, array<string, mixed>> $targets
     */
    private function buildRequestFingerprint(
        string $accountUuid,
        string $amount,
        PaymentMode $paymentMode,
        ?string $transactionReference,
        string $receivedDate,
        string $valueDate,
        ?string $remarks,
        array $targets
    ): string {
        return hash(
            'sha256',
            json_encode(
                [
                    'repayment_account_uuid' =>
                        strtolower(
                            trim(
                                $accountUuid
                            )
                        ),

                    'amount' =>
                        $amount,

                    'payment_mode' =>
                        $paymentMode
                            ->value,

                    'transaction_reference' =>
                        $transactionReference,

                    'received_date' =>
                        $receivedDate,

                    'value_date' =>
                        $valueDate,

                    'remarks' =>
                        $remarks,

                    'targets' =>
                        array_map(
                            static fn (
                                array $target
                            ): array => [
                                'repayment_installment_uuid' =>
                                    $target[
                                        'repayment_installment_uuid'
                                    ],

                                'installment_number' =>
                                    (int)
                                    $target[
                                        'installment_number'
                                    ],

                                'collection_amount' =>
                                    (string)
                                    $target[
                                        'collection_amount'
                                    ],
                            ],
                            $targets
                        ),
                ],
                JSON_THROW_ON_ERROR
            )
        );
    }

    /**
     * Load transaction response relationships consistently
     * with the normal posting service.
     */
    private function hydrateTransaction(
        RepaymentTransaction $transaction
    ): RepaymentTransaction {
        $transaction->refresh();

        $transaction->load([
            'receipt',
            'reversalOf',
            'reversal',

            'allocations' =>
                fn ($query) =>
                    $query
                        ->allocationOrder(),
        ]);

        $transaction
            ->loadCount(
                'allocations'
            );

        return $transaction;
    }

    /**
     * Round collectible/outstanding to a whole rupee while preserving
     * two-decimal database formatting.
     */
    private function roundWholeMoney(
        mixed $value
    ): string {
        return number_format(
            round(
                (float) ($value ?? 0),
                0,
                PHP_ROUND_HALF_UP
            ),
            self::MONEY_SCALE,
            '.',
            ''
        );
    }

    /**
     * Normalize decimal value to two places.
     */
    private function normalizeMoney(
        mixed $value
    ): string {
        return bcadd(
            trim(
                (string)
                ($value ?? '0')
            ),
            '0.00',
            self::MONEY_SCALE
        );
    }

    /**
     * Smaller of two non-negative money values.
     */
    private function minimumMoney(
        string $first,
        string $second
    ): string {
        return bccomp(
            $first,
            $second,
            self::MONEY_SCALE
        ) <= 0
            ? $first
            : $second;
    }

    private function normalizeRequiredString(
        mixed $value
    ): string {
        return trim(
            (string)
            $value
        );
    }

    private function normalizeNullableString(
        mixed $value
    ): ?string {
        if ($value === null) {
            return null;
        }

        $normalized =
            trim(
                (string)
                $value
            );

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
        $sqlState =
            $exception
                ->errorInfo[
                    0
                ]
            ?? null;

        $driverCode =
            $exception
                ->errorInfo[
                    1
                ]
            ?? null;

        return $sqlState === '23000'
            && (int)
                $driverCode === 1062;
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

// final class RepaymentTargetedInstallmentPostingService
// {
//     private const MONEY_SCALE = 2;

//     public function __construct(
//         private readonly RepaymentTransactionNumberGenerator
//             $transactionNumberGenerator
//     ) {
//     }

//     /**
//      * Post one targeted repayment transaction.
//      *
//      * Unlike RepaymentPaymentPostingService, this service does
//      * NOT perform oldest-outstanding waterfall allocation.
//      *
//      * It allocates only to explicitly supplied installments.
//      *
//      * @param array<string, mixed> $payload
//      * @param array<int, array<string, mixed>> $targets
//      * @param array<string, mixed> $metadata
//      */
//     public function post(
//         string $accountUuid,
//         array $payload,
//         array $targets,
//         ?int $actorId = null,
//         array $metadata = []
//     ): RepaymentTransaction {
//         try {
//             return DB::transaction(
//                 fn (): RepaymentTransaction =>
//                     $this->postInsideTransaction(
//                         accountUuid:
//                             $accountUuid,

//                         payload:
//                             $payload,

//                         targets:
//                             $targets,

//                         actorId:
//                             $actorId,

//                         metadata:
//                             $metadata
//                     ),
//                 attempts: 3
//             );
//         } catch (QueryException $exception) {
//             /*
//              * Same duplicate-idempotency protection used by
//              * the normal payment posting service.
//              */
//             if (
//                 $this->isDuplicateKeyException(
//                     $exception
//                 )
//             ) {
//                 $idempotencyKey =
//                     $this->normalizeRequiredString(
//                         $payload[
//                             'idempotency_key'
//                         ] ?? ''
//                     );

//                 $existing =
//                     RepaymentTransaction::query()
//                         ->useWritePdo()
//                         ->where(
//                             'idempotency_key',
//                             $idempotencyKey
//                         )
//                         ->first();

//                 if ($existing) {
//                     $account =
//                         RepaymentAccount::query()
//                             ->useWritePdo()
//                             ->where(
//                                 'uuid',
//                                 $accountUuid
//                             )
//                             ->first();

//                     if (! $account) {
//                         throw RepaymentPaymentException::
//                             accountNotFound(
//                                 $accountUuid
//                             );
//                     }

//                     if (
//                         $this->requestMatchesTransaction(
//                             account:
//                                 $account,

//                             transaction:
//                                 $existing,

//                             payload:
//                                 $payload,

//                             targets:
//                                 $targets
//                         )
//                     ) {
//                         return $this
//                             ->hydrateTransaction(
//                                 $existing
//                             );
//                     }

//                     throw RepaymentPaymentException::
//                         idempotencyConflict(
//                             idempotencyKey:
//                                 (string)
//                                 $existing
//                                     ->idempotency_key,

//                             existingTransactionUuid:
//                                 (string)
//                                 $existing
//                                     ->uuid
//                         );
//                 }
//             }

//             throw $exception;
//         }
//     }

//     /**
//      * Post targeted collection inside an already-open
//      * database transaction.
//      *
//      * Bulk repayment import uses this method so the complete
//      * Excel file remains all-or-nothing.
//      *
//      * @param array<string, mixed> $payload
//      * @param array<int, array<string, mixed>> $targets
//      * @param array<string, mixed> $metadata
//      */
//     public function postWithinTransaction(
//         string $accountUuid,
//         array $payload,
//         array $targets,
//         ?int $actorId = null,
//         array $metadata = []
//     ): RepaymentTransaction {
//         if (
//             DB::connection()
//                 ->transactionLevel() < 1
//         ) {
//             throw new LogicException(
//                 'postWithinTransaction() requires an active database transaction.'
//             );
//         }

//         return $this->postInsideTransaction(
//             accountUuid:
//                 $accountUuid,

//             payload:
//                 $payload,

//             targets:
//                 $targets,

//             actorId:
//                 $actorId,

//             metadata:
//                 $metadata
//         );
//     }

//     /**
//      * Execute complete exact-installment posting workflow.
//      *
//      * @param array<string, mixed> $payload
//      * @param array<int, array<string, mixed>> $targets
//      * @param array<string, mixed> $metadata
//      */
//     private function postInsideTransaction(
//         string $accountUuid,
//         array $payload,
//         array $targets,
//         ?int $actorId,
//         array $metadata = []
//     ): RepaymentTransaction {
//         /*
//          * =========================================================
//          * LOCK ACCOUNT
//          * =========================================================
//          */
//         $account =
//             RepaymentAccount::query()
//                 ->useWritePdo()
//                 ->where(
//                     'uuid',
//                     $accountUuid
//                 )
//                 ->lockForUpdate()
//                 ->first();

//         if (! $account) {
//             throw RepaymentPaymentException::
//                 accountNotFound(
//                     $accountUuid
//                 );
//         }

//         $idempotencyKey =
//             $this->normalizeRequiredString(
//                 $payload[
//                     'idempotency_key'
//                 ] ?? ''
//             );

//         /*
//          * Normalize targeted selections before transaction
//          * creation.
//          */
//         $normalizedTargets =
//             $this->normalizeTargets(
//                 $targets
//             );

//         /*
//          * =========================================================
//          * IDEMPOTENCY
//          * =========================================================
//          *
//          * Check before account status validation.
//          *
//          * A successful payment may have closed the account, but
//          * retrying exactly the same request must still safely
//          * return the original transaction.
//          */
//         $existing =
//             RepaymentTransaction::query()
//                 ->useWritePdo()
//                 ->where(
//                     'idempotency_key',
//                     $idempotencyKey
//                 )
//                 ->lockForUpdate()
//                 ->first();

//         if ($existing) {
//             if (
//                 $this->requestMatchesTransaction(
//                     account:
//                         $account,

//                     transaction:
//                         $existing,

//                     payload:
//                         $payload,

//                     targets:
//                         $normalizedTargets
//                 )
//             ) {
//                 return $this
//                     ->hydrateTransaction(
//                         $existing
//                     );
//             }

//             throw RepaymentPaymentException::
//                 idempotencyConflict(
//                     idempotencyKey:
//                         $idempotencyKey,

//                     existingTransactionUuid:
//                         (string)
//                         $existing->uuid
//                 );
//         }

//         /*
//          * =========================================================
//          * ACCOUNT PAYMENT ELIGIBILITY
//          * =========================================================
//          */
//         if (
//             ! $account->allowsPayment()
//         ) {
//             throw RepaymentPaymentException::
//                 accountDoesNotAllowPayment(
//                     accountUuid:
//                         (string)
//                         $account->uuid,

//                     accountStatus:
//                         $account
//                             ->status
//                             ->value
//                 );
//         }

//         /*
//          * =========================================================
//          * PAYMENT AMOUNT
//          * =========================================================
//          */
//         $amount =
//             $this->normalizeMoney(
//                 $payload[
//                     'amount'
//                 ] ?? '0'
//             );

//         if (
//             bccomp(
//                 $amount,
//                 '0.00',
//                 self::MONEY_SCALE
//             ) !== 1
//         ) {
//             throw new RepaymentPaymentException(
//                 message:
//                     'Payment amount must be greater than zero.',

//                 context: [
//                     'repayment_account_uuid' =>
//                         $accountUuid,

//                     'amount' =>
//                         $amount,
//                 ],

//                 statusCode:
//                     422
//             );
//         }

//         /*
//          * Target amounts must exactly equal transaction amount.
//          */
//         $targetTotal =
//             $this->sumTargetAmounts(
//                 $normalizedTargets
//             );

//         if (
//             bccomp(
//                 $targetTotal,
//                 $amount,
//                 self::MONEY_SCALE
//             ) !== 0
//         ) {
//             throw new RepaymentPaymentException(
//                 message:
//                     'Targeted EMI collection total does not match the payment amount.',

//                 context: [
//                     'repayment_account_uuid' =>
//                         $accountUuid,

//                     'payment_amount' =>
//                         $amount,

//                     'targeted_amount' =>
//                         $targetTotal,
//                 ],

//                 statusCode:
//                     422
//             );
//         }

//         /*
//          * =========================================================
//          * PAYMENT MODE / REFERENCE
//          * =========================================================
//          */
//         $paymentMode =
//             PaymentMode::tryFrom(
//                 strtoupper(
//                     $this
//                         ->normalizeRequiredString(
//                             $payload[
//                                 'payment_mode'
//                             ] ?? ''
//                         )
//                 )
//             );

//         if (! $paymentMode) {
//             throw new RepaymentPaymentException(
//                 message:
//                     'Invalid payment mode selected.',

//                 context: [
//                     'repayment_account_uuid' =>
//                         $accountUuid,

//                     'payment_mode' =>
//                         $payload[
//                             'payment_mode'
//                         ] ?? null,
//                 ],

//                 statusCode:
//                     422
//             );
//         }

//         $transactionReference =
//             $this->normalizeNullableString(
//                 $payload[
//                     'transaction_reference'
//                 ] ?? null
//             );

//         if (
//             $paymentMode
//                 ->requiresReference()
//             && $transactionReference
//                 === null
//         ) {
//             throw new RepaymentPaymentException(
//                 message:
//                     'Transaction reference is required for the selected payment mode.',

//                 context: [
//                     'repayment_account_uuid' =>
//                         $accountUuid,

//                     'payment_mode' =>
//                         $paymentMode
//                             ->value,
//                 ],

//                 statusCode:
//                     422
//             );
//         }

//         $this->assertReferenceIsAvailable(
//             account:
//                 $account,

//             paymentMode:
//                 $paymentMode,

//             transactionReference:
//                 $transactionReference
//         );

//         /*
//          * =========================================================
//          * PAYMENT DATES / REMARKS
//          * =========================================================
//          */
//         $receivedDate =
//             $this->normalizeRequiredString(
//                 $payload[
//                     'received_date'
//                 ] ?? ''
//             );

//         $valueDate =
//             $this->normalizeRequiredString(
//                 $payload[
//                     'value_date'
//                 ] ?? ''
//             );

//         $remarks =
//             $this->normalizeNullableString(
//                 $payload[
//                     'remarks'
//                 ] ?? null
//             );

//         /*
//          * =========================================================
//          * LOCK EXACT TARGETED INSTALLMENTS
//          * =========================================================
//          *
//          * Deterministic database-ID ordering prevents deadlock
//          * surprises when overlapping targeted requests occur.
//          */
//         $targetUuids =
//             array_column(
//                 $normalizedTargets,
//                 'repayment_installment_uuid'
//             );

//         $liveInstallments =
//             RepaymentInstallment::query()
//                 ->useWritePdo()
//                 ->whereIn(
//                     'uuid',
//                     $targetUuids
//                 )
//                 ->orderBy(
//                     'id'
//                 )
//                 ->lockForUpdate()
//                 ->get();

//         $liveByUuid = [];

//         foreach (
//             $liveInstallments
//             as $installment
//         ) {
//             $liveByUuid[
//                 strtolower(
//                     (string)
//                     $installment->uuid
//                 )
//             ] =
//                 $installment;
//         }

//         /*
//          * Every target must resolve to an actual live EMI.
//          */
//         foreach (
//             $normalizedTargets
//             as $target
//         ) {
//             $targetUuid =
//                 $target[
//                     'repayment_installment_uuid'
//                 ];

//             if (
//                 ! isset(
//                     $liveByUuid[
//                         $targetUuid
//                     ]
//                 )
//             ) {
//                 throw new RepaymentPaymentException(
//                     message:
//                         'A targeted repayment installment no longer exists.',

//                     context: [
//                         'repayment_account_uuid' =>
//                             $accountUuid,

//                         'repayment_installment_uuid' =>
//                             $targetUuid,

//                         'installment_number' =>
//                             $target[
//                                 'installment_number'
//                             ],
//                     ],

//                     statusCode:
//                         409
//                 );
//             }

//             /** @var RepaymentInstallment $installment */
//             $installment =
//                 $liveByUuid[
//                     $targetUuid
//                 ];

//             $this->assertTargetStillEligible(
//                 account:
//                     $account,

//                 installment:
//                     $installment,

//                 target:
//                     $target
//             );
//         }

//         /*
//          * =========================================================
//          * TARGET-AWARE REQUEST FINGERPRINT
//          * =========================================================
//          *
//          * Important:
//          *
//          * Normal payment fingerprint only contains total amount.
//          *
//          * Targeted payment fingerprint must also contain the
//          * exact EMI UUID + amount mapping.
//          */
//         $requestFingerprint =
//             $this->buildRequestFingerprint(
//                 accountUuid:
//                     $accountUuid,

//                 amount:
//                     $amount,

//                 paymentMode:
//                     $paymentMode,

//                 transactionReference:
//                     $transactionReference,

//                 receivedDate:
//                     $receivedDate,

//                 valueDate:
//                     $valueDate,

//                 remarks:
//                     $remarks,

//                 targets:
//                     $normalizedTargets
//             );

//         /*
//          * =========================================================
//          * CREATE PAYMENT TRANSACTION
//          * =========================================================
//          *
//          * Still one transaction per account/Excel row.
//          */
//         $transaction =
//             RepaymentTransaction::query()
//                 ->create([
//                     'repayment_account_id' =>
//                         $account->id,

//                     /*
//                      * Temporary unique number first.
//                      */
//                     'transaction_number' =>
//                         $this
//                             ->transactionNumberGenerator
//                             ->generateTemporary(),

//                     'idempotency_key' =>
//                         $idempotencyKey,

//                     'transaction_type' =>
//                         RepaymentTransactionType::
//                             PAYMENT
//                             ->value,

//                     'amount' =>
//                         $amount,

//                     'allocated_amount' =>
//                         '0.00',

//                     /*
//                      * During creation, everything is still
//                      * considered unallocated.
//                      */
//                     'unallocated_amount' =>
//                         $amount,

//                     'payment_mode' =>
//                         $paymentMode
//                             ->value,

//                     'transaction_reference' =>
//                         $transactionReference,

//                     'received_date' =>
//                         $receivedDate,

//                     'value_date' =>
//                         $valueDate,

//                     'status' =>
//                         RepaymentTransactionStatus::
//                             PENDING
//                             ->value,

//                     'remarks' =>
//                         $remarks,

//                     'metadata' =>
//                         array_merge(
//                             $metadata,
//                             [
//                                 /*
//                                  * Caller cannot override these
//                                  * system-owned values.
//                                  */
//                                 'request_fingerprint' =>
//                                     $requestFingerprint,

//                                 'allocation_strategy' =>
//                                     'TARGETED_INSTALLMENTS_INTEREST_THEN_PRINCIPAL',

//                                 'targeted_installment_count' =>
//                                     count(
//                                         $normalizedTargets
//                                     ),

//                                 /*
//                                  * Public/audit-safe target map.
//                                  */
//                                 'targeted_installments' =>
//                                     array_map(
//                                         static fn (
//                                             array $target
//                                         ): array => [
//                                             'repayment_installment_uuid' =>
//                                                 $target[
//                                                     'repayment_installment_uuid'
//                                                 ],

//                                             'installment_number' =>
//                                                 $target[
//                                                     'installment_number'
//                                                 ],

//                                             'collection_amount' =>
//                                                 $target[
//                                                     'collection_amount'
//                                                 ],
//                                         ],
//                                         $normalizedTargets
//                                     ),
//                             ]
//                         ),

//                     'created_by' =>
//                         $actorId,
//                 ]);

//         /*
//          * Replace temporary number with final ID-based number.
//          */
//         $transaction
//             ->transaction_number =
//                 $this
//                     ->transactionNumberGenerator
//                     ->generateFinal(
//                         transactionId:
//                             (int)
//                             $transaction->id,

//                         businessDate:
//                             $receivedDate
//                     );

//         $transaction->save();

//         $postingTimestamp =
//             now();

//         /*
//          * =========================================================
//          * EXACT TARGETED ALLOCATION
//          * =========================================================
//          */
//         $allocationResult =
//             $this->allocateTargets(
//                 account:
//                     $account,

//                 transaction:
//                     $transaction,

//                 targets:
//                     $normalizedTargets,

//                 liveByUuid:
//                     $liveByUuid,

//                 actorId:
//                     $actorId,

//                 postingTimestamp:
//                     $postingTimestamp
//             );

//         /*
//          * Targeted import never allows advance/unallocated
//          * spillover.
//          */
//         if (
//             bccomp(
//                 $allocationResult[
//                     'remaining_amount'
//                 ],
//                 '0.00',
//                 self::MONEY_SCALE
//             ) !== 0
//         ) {
//             throw RepaymentPaymentException::
//                 financialInvariantViolation(
//                     message:
//                         'Targeted repayment payment could not be fully allocated to the selected installments.',

//                     context: [
//                         'repayment_account_uuid' =>
//                             $account->uuid,

//                         'repayment_transaction_uuid' =>
//                             $transaction->uuid,

//                         'payment_amount' =>
//                             $amount,

//                         'remaining_amount' =>
//                             $allocationResult[
//                                 'remaining_amount'
//                             ],
//                     ]
//                 );
//         }

//         $allocatedAmount =
//             bcsub(
//                 $amount,
//                 $allocationResult[
//                     'remaining_amount'
//                 ],
//                 self::MONEY_SCALE
//             );

//         if (
//             bccomp(
//                 $allocatedAmount,
//                 $amount,
//                 self::MONEY_SCALE
//             ) !== 0
//         ) {
//             throw RepaymentPaymentException::
//                 financialInvariantViolation(
//                     message:
//                         'Targeted repayment allocated amount does not match transaction amount.',

//                     context: [
//                         'repayment_account_uuid' =>
//                             $account->uuid,

//                         'repayment_transaction_uuid' =>
//                             $transaction->uuid,

//                         'payment_amount' =>
//                             $amount,

//                         'allocated_amount' =>
//                             $allocatedAmount,
//                     ]
//                 );
//         }

//         /*
//          * =========================================================
//          * POST TRANSACTION
//          * =========================================================
//          */
//         $transaction
//             ->forceFill([
//                 'allocated_amount' =>
//                     $allocatedAmount,

//                 'unallocated_amount' =>
//                     '0.00',

//                 'posted_at' =>
//                     $postingTimestamp,

//                 'status' =>
//                     RepaymentTransactionStatus::
//                         POSTED
//                         ->value,

//                 'approved_by' =>
//                     $actorId,

//                 'metadata' =>
//                     array_merge(
//                         $transaction
//                             ->metadata
//                             ?? [],
//                         [
//                             'allocation_count' =>
//                                 $allocationResult[
//                                     'allocation_count'
//                                 ],

//                             'interest_allocated' =>
//                                 $allocationResult[
//                                     'interest_allocated'
//                                 ],

//                             'principal_allocated' =>
//                                 $allocationResult[
//                                     'principal_allocated'
//                                 ],

//                             'unallocated_amount' =>
//                                 '0.00',
//                         ]
//                     ),
//             ])
//             ->save();

//         /*
//          * Same account recalculation behaviour as normal payment
//          * posting, but unallocated amount is always zero.
//          */
//         $this->refreshAccountBalances(
//             account:
//                 $account,

//             receivedAmount:
//                 $amount,

//             unallocatedAmount:
//                 '0.00',

//             transaction:
//                 $transaction,

//             actorId:
//                 $actorId,

//             postingTimestamp:
//                 $postingTimestamp
//         );

//         return $this
//             ->hydrateTransaction(
//                 $transaction
//             );
//     }

//     /**
//      * Normalize and canonicalize targeted EMI selections.
//      *
//      * Canonical sorting makes request fingerprint stable
//      * regardless of array input order.
//      *
//      * @param array<int, array<string, mixed>> $targets
//      *
//      * @return array<int, array{
//      *     repayment_installment_uuid: string,
//      *     installment_number: int,
//      *     collection_amount: string
//      * }>
//      */
//     private function normalizeTargets(
//         array $targets
//     ): array {
//         if ($targets === []) {
//             throw new RepaymentPaymentException(
//                 message:
//                     'At least one targeted repayment installment is required.',

//                 context: [],

//                 statusCode:
//                     422
//             );
//         }

//         $normalized = [];

//         $seenUuids = [];
//         $seenNumbers = [];

//         foreach (
//             $targets as $index => $target
//         ) {
//             if (! is_array($target)) {
//                 throw new RepaymentPaymentException(
//                     message:
//                         'Targeted repayment installment structure is invalid.',

//                     context: [
//                         'target_index' =>
//                             $index,
//                     ],

//                     statusCode:
//                         422
//                 );
//             }

//             $uuid =
//                 strtolower(
//                     $this->normalizeRequiredString(
//                         $target[
//                             'repayment_installment_uuid'
//                         ] ?? ''
//                     )
//                 );

//             $installmentNumber =
//                 (int) (
//                     $target[
//                         'installment_number'
//                     ] ?? 0
//                 );

//             $collectionAmount =
//                 $this->normalizeMoney(
//                     $target[
//                         'collection_amount'
//                     ] ?? '0'
//                 );

//             if ($uuid === '') {
//                 throw new RepaymentPaymentException(
//                     message:
//                         'Targeted repayment installment UUID is required.',

//                     context: [
//                         'target_index' =>
//                             $index,
//                     ],

//                     statusCode:
//                         422
//                 );
//             }

//             if ($installmentNumber < 1) {
//                 throw new RepaymentPaymentException(
//                     message:
//                         'Targeted repayment installment number is invalid.',

//                     context: [
//                         'target_index' =>
//                             $index,

//                         'installment_number' =>
//                             $target[
//                                 'installment_number'
//                             ] ?? null,
//                     ],

//                     statusCode:
//                         422
//                 );
//             }

//             if (
//                 bccomp(
//                     $collectionAmount,
//                     '0.00',
//                     self::MONEY_SCALE
//                 ) !== 1
//             ) {
//                 throw new RepaymentPaymentException(
//                     message:
//                         'Targeted EMI collection amount must be greater than zero.',

//                     context: [
//                         'target_index' =>
//                             $index,

//                         'repayment_installment_uuid' =>
//                             $uuid,

//                         'installment_number' =>
//                             $installmentNumber,

//                         'collection_amount' =>
//                             $collectionAmount,
//                     ],

//                     statusCode:
//                         422
//                 );
//             }

//             if (isset($seenUuids[$uuid])) {
//                 throw new RepaymentPaymentException(
//                     message:
//                         'The same repayment installment UUID was targeted more than once.',

//                     context: [
//                         'repayment_installment_uuid' =>
//                             $uuid,
//                     ],

//                     statusCode:
//                         422
//                 );
//             }

//             if (
//                 isset(
//                     $seenNumbers[
//                         $installmentNumber
//                     ]
//                 )
//             ) {
//                 throw new RepaymentPaymentException(
//                     message:
//                         'The same repayment installment number was targeted more than once.',

//                     context: [
//                         'installment_number' =>
//                             $installmentNumber,
//                     ],

//                     statusCode:
//                         422
//                 );
//             }

//             $seenUuids[
//                 $uuid
//             ] =
//                 true;

//             $seenNumbers[
//                 $installmentNumber
//             ] =
//                 true;

//             $normalized[] = [
//                 'repayment_installment_uuid' =>
//                     $uuid,

//                 'installment_number' =>
//                     $installmentNumber,

//                 'collection_amount' =>
//                     $collectionAmount,
//             ];
//         }

//         /*
//          * Deterministic schedule order.
//          */
//         usort(
//             $normalized,
//             static function (
//                 array $left,
//                 array $right
//             ): int {
//                 $numberComparison =
//                     $left[
//                         'installment_number'
//                     ]
//                     <=>
//                     $right[
//                         'installment_number'
//                     ];

//                 if ($numberComparison !== 0) {
//                     return $numberComparison;
//                 }

//                 return strcmp(
//                     $left[
//                         'repayment_installment_uuid'
//                     ],
//                     $right[
//                         'repayment_installment_uuid'
//                     ]
//                 );
//             }
//         );

//         return $normalized;
//     }

//     /**
//      * Sum exact selected EMI collection amounts.
//      *
//      * @param array<int, array<string, mixed>> $targets
//      */
//     private function sumTargetAmounts(
//         array $targets
//     ): string {
//         $total =
//             '0.00';

//         foreach ($targets as $target) {
//             $total =
//                 bcadd(
//                     $total,
//                     $this->normalizeMoney(
//                         $target[
//                             'collection_amount'
//                         ] ?? '0'
//                     ),
//                     self::MONEY_SCALE
//                 );
//         }

//         return $total;
//     }

//     /**
//      * Confirm a selected live EMI still belongs to this
//      * account/current schedule and can accept the exact
//      * targeted amount.
//      *
//      * @param array<string, mixed> $target
//      */
//     private function assertTargetStillEligible(
//         RepaymentAccount $account,
//         RepaymentInstallment $installment,
//         array $target
//     ): void {
//         if (
//             (int)
//             $installment
//                 ->repayment_account_id
//             !==
//             (int)
//             $account->id
//         ) {
//             throw new RepaymentPaymentException(
//                 message:
//                     'Targeted repayment installment does not belong to this repayment account.',

//                 context: [
//                     'repayment_account_uuid' =>
//                         $account->uuid,

//                     'repayment_installment_uuid' =>
//                         $installment->uuid,
//                 ],

//                 statusCode:
//                     409
//             );
//         }

//         if (
//             (int)
//             $installment
//                 ->schedule_version
//             !==
//             (int)
//             $account
//                 ->schedule_version
//         ) {
//             throw new RepaymentPaymentException(
//                 message:
//                     'Targeted repayment installment is no longer part of the current repayment schedule.',

//                 context: [
//                     'repayment_account_uuid' =>
//                         $account->uuid,

//                     'repayment_installment_uuid' =>
//                         $installment->uuid,

//                     'installment_schedule_version' =>
//                         (int)
//                         $installment
//                             ->schedule_version,

//                     'account_schedule_version' =>
//                         (int)
//                         $account
//                             ->schedule_version,
//                 ],

//                 statusCode:
//                     409
//             );
//         }

//         if (
//             (int)
//             $installment
//                 ->installment_number
//             !==
//             (int)
//             $target[
//                 'installment_number'
//             ]
//         ) {
//             throw new RepaymentPaymentException(
//                 message:
//                     'Targeted EMI number no longer matches its installment UUID.',

//                 context: [
//                     'repayment_installment_uuid' =>
//                         $installment->uuid,

//                     'expected_installment_number' =>
//                         (int)
//                         $target[
//                             'installment_number'
//                         ],

//                     'current_installment_number' =>
//                         (int)
//                         $installment
//                             ->installment_number,
//                 ],

//                 statusCode:
//                     409
//             );
//         }

//         $this->assertValidInstallmentState(
//             $installment
//         );

//         $currentOutstanding =
//             $this->normalizeMoney(
//                 $installment
//                     ->outstanding_amount
//             );

//         if (
//             bccomp(
//                 $currentOutstanding,
//                 '0.00',
//                 self::MONEY_SCALE
//             ) !== 1
//         ) {
//             throw new RepaymentPaymentException(
//                 message:
//                     'Targeted EMI has no outstanding balance.',

//                 context: [
//                     'repayment_installment_uuid' =>
//                         $installment->uuid,

//                     'installment_number' =>
//                         $installment
//                             ->installment_number,

//                     'outstanding_amount' =>
//                         $currentOutstanding,
//                 ],

//                 statusCode:
//                     409
//             );
//         }

//         if (
//             ! $installment
//                 ->payment_status
//                 ->allowsAllocation()
//         ) {
//             throw new RepaymentPaymentException(
//                 message:
//                     'Targeted EMI no longer allows payment allocation.',

//                 context: [
//                     'repayment_installment_uuid' =>
//                         $installment->uuid,

//                     'installment_number' =>
//                         $installment
//                             ->installment_number,

//                     'payment_status' =>
//                         $installment
//                             ->payment_status
//                             ->value,
//                 ],

//                 statusCode:
//                     409
//             );
//         }

//         $collectionAmount =
//             $this->normalizeMoney(
//                 $target[
//                     'collection_amount'
//                 ]
//             );

//         if (
//             bccomp(
//                 $collectionAmount,
//                 $currentOutstanding,
//                 self::MONEY_SCALE
//             ) === 1
//         ) {
//             throw new RepaymentPaymentException(
//                 message:
//                     'Targeted EMI collection amount is greater than the current installment outstanding.',

//                 context: [
//                     'repayment_installment_uuid' =>
//                         $installment->uuid,

//                     'installment_number' =>
//                         $installment
//                             ->installment_number,

//                     'collection_amount' =>
//                         $collectionAmount,

//                     'current_outstanding' =>
//                         $currentOutstanding,
//                 ],

//                 statusCode:
//                     409
//             );
//         }
//     }

//     /**
//      * Allocate only to explicitly selected EMIs.
//      *
//      * Within each selected EMI:
//      *
//      * 1. remaining interest
//      * 2. remaining principal
//      *
//      * No amount spills into any unselected installment.
//      *
//      * @param array<int, array<string, mixed>> $targets
//      * @param array<string, RepaymentInstallment> $liveByUuid
//      *
//      * @return array{
//      *     remaining_amount: string,
//      *     interest_allocated: string,
//      *     principal_allocated: string,
//      *     allocation_count: int
//      * }
//      */
//     private function allocateTargets(
//         RepaymentAccount $account,
//         RepaymentTransaction $transaction,
//         array $targets,
//         array $liveByUuid,
//         ?int $actorId,
//         mixed $postingTimestamp
//     ): array {
//         $transactionRemaining =
//             $this->normalizeMoney(
//                 $transaction->amount
//             );

//         $interestAllocatedTotal =
//             '0.00';

//         $principalAllocatedTotal =
//             '0.00';

//         $allocationOrder =
//             1;

//         $allocationCount =
//             0;

//         foreach ($targets as $target) {
//             $targetUuid =
//                 $target[
//                     'repayment_installment_uuid'
//                 ];

//             /** @var RepaymentInstallment $installment */
//             $installment =
//                 $liveByUuid[
//                     $targetUuid
//                 ];

//             $this->assertValidInstallmentState(
//                 $installment
//             );

//             $oldPaymentStatus =
//                 $installment
//                     ->payment_status;

//             $targetAmount =
//                 $this->normalizeMoney(
//                     $target[
//                         'collection_amount'
//                     ]
//                 );

//             $targetRemaining =
//                 $targetAmount;

//             $installmentInterestAllocated =
//                 '0.00';

//             $installmentPrincipalAllocated =
//                 '0.00';

//             /*
//              * =====================================================
//              * COMPONENT 1: INTEREST
//              * =====================================================
//              */
//             $interestOutstanding =
//                 $installment
//                     ->interestOutstanding();

//             $interestAllocation =
//                 $this->minimumMoney(
//                     $targetRemaining,
//                     $interestOutstanding
//                 );

//             if (
//                 bccomp(
//                     $interestAllocation,
//                     '0.00',
//                     self::MONEY_SCALE
//                 ) === 1
//             ) {
//                 RepaymentAllocation::query()
//                     ->create([
//                         'repayment_transaction_id' =>
//                             $transaction->id,

//                         'repayment_installment_id' =>
//                             $installment->id,

//                         'component' =>
//                             AllocationComponent::
//                                 INTEREST
//                                 ->value,

//                         'amount' =>
//                             $interestAllocation,

//                         'allocation_order' =>
//                             $allocationOrder++,

//                         'metadata' => [
//                             'installment_number' =>
//                                 $installment
//                                     ->installment_number,

//                             'due_date' =>
//                                 $installment
//                                     ->due_date
//                                     ->format(
//                                         'Y-m-d'
//                                     ),

//                             'component_priority' =>
//                                 AllocationComponent::
//                                     INTEREST
//                                     ->priority(),

//                             'allocation_strategy' =>
//                                 'TARGETED_INSTALLMENT',

//                             'target_collection_amount' =>
//                                 $targetAmount,
//                         ],
//                     ]);

//                 $installment
//                     ->interest_paid =
//                         bcadd(
//                             (string)
//                             $installment
//                                 ->interest_paid,

//                             $interestAllocation,

//                             self::MONEY_SCALE
//                         );

//                 $targetRemaining =
//                     bcsub(
//                         $targetRemaining,
//                         $interestAllocation,
//                         self::MONEY_SCALE
//                     );

//                 $transactionRemaining =
//                     bcsub(
//                         $transactionRemaining,
//                         $interestAllocation,
//                         self::MONEY_SCALE
//                     );

//                 $interestAllocatedTotal =
//                     bcadd(
//                         $interestAllocatedTotal,
//                         $interestAllocation,
//                         self::MONEY_SCALE
//                     );

//                 $installmentInterestAllocated =
//                     $interestAllocation;

//                 $allocationCount++;
//             }

//             /*
//              * =====================================================
//              * COMPONENT 2: PRINCIPAL
//              * =====================================================
//              */
//             $principalOutstanding =
//                 $installment
//                     ->principalOutstanding();

//             $principalAllocation =
//                 $this->minimumMoney(
//                     $targetRemaining,
//                     $principalOutstanding
//                 );

//             if (
//                 bccomp(
//                     $principalAllocation,
//                     '0.00',
//                     self::MONEY_SCALE
//                 ) === 1
//             ) {
//                 RepaymentAllocation::query()
//                     ->create([
//                         'repayment_transaction_id' =>
//                             $transaction->id,

//                         'repayment_installment_id' =>
//                             $installment->id,

//                         'component' =>
//                             AllocationComponent::
//                                 PRINCIPAL
//                                 ->value,

//                         'amount' =>
//                             $principalAllocation,

//                         'allocation_order' =>
//                             $allocationOrder++,

//                         'metadata' => [
//                             'installment_number' =>
//                                 $installment
//                                     ->installment_number,

//                             'due_date' =>
//                                 $installment
//                                     ->due_date
//                                     ->format(
//                                         'Y-m-d'
//                                     ),

//                             'component_priority' =>
//                                 AllocationComponent::
//                                     PRINCIPAL
//                                     ->priority(),

//                             'allocation_strategy' =>
//                                 'TARGETED_INSTALLMENT',

//                             'target_collection_amount' =>
//                                 $targetAmount,
//                         ],
//                     ]);

//                 $installment
//                     ->principal_paid =
//                         bcadd(
//                             (string)
//                             $installment
//                                 ->principal_paid,

//                             $principalAllocation,

//                             self::MONEY_SCALE
//                         );

//                 $targetRemaining =
//                     bcsub(
//                         $targetRemaining,
//                         $principalAllocation,
//                         self::MONEY_SCALE
//                     );

//                 $transactionRemaining =
//                     bcsub(
//                         $transactionRemaining,
//                         $principalAllocation,
//                         self::MONEY_SCALE
//                     );

//                 $principalAllocatedTotal =
//                     bcadd(
//                         $principalAllocatedTotal,
//                         $principalAllocation,
//                         self::MONEY_SCALE
//                     );

//                 $installmentPrincipalAllocated =
//                     $principalAllocation;

//                 $allocationCount++;
//             }

//             /*
//              * Exact-target rule:
//              *
//              * selected EMI amount MUST be fully consumed
//              * inside this exact installment.
//              */
//             if (
//                 bccomp(
//                     $targetRemaining,
//                     '0.00',
//                     self::MONEY_SCALE
//                 ) !== 0
//             ) {
//                 throw RepaymentPaymentException::
//                     financialInvariantViolation(
//                         message:
//                             'Targeted EMI collection amount could not be fully allocated to the selected installment.',

//                         context: [
//                             'repayment_account_uuid' =>
//                                 $account->uuid,

//                             'repayment_installment_uuid' =>
//                                 $installment->uuid,

//                             'installment_number' =>
//                                 $installment
//                                     ->installment_number,

//                             'target_amount' =>
//                                 $targetAmount,

//                             'remaining_target_amount' =>
//                                 $targetRemaining,

//                             'repayment_transaction_uuid' =>
//                                 $transaction->uuid,
//                         ]
//                     );
//             }

//             /*
//              * =====================================================
//              * UPDATE TARGET INSTALLMENT
//              * =====================================================
//              */
//             $newOutstanding =
//                 bcadd(
//                     $installment
//                         ->interestOutstanding(),

//                     $installment
//                         ->principalOutstanding(),

//                     self::MONEY_SCALE
//                 );

//             $totalPaid =
//                 bcadd(
//                     (string)
//                     $installment
//                         ->interest_paid,

//                     (string)
//                     $installment
//                         ->principal_paid,

//                     self::MONEY_SCALE
//                 );

//             $newPaymentStatus =
//                 match (true) {
//                     bccomp(
//                         $newOutstanding,
//                         '0.00',
//                         self::MONEY_SCALE
//                     ) === 0 =>
//                         InstallmentPaymentStatus::
//                             PAID,

//                     bccomp(
//                         $totalPaid,
//                         '0.00',
//                         self::MONEY_SCALE
//                     ) === 1 =>
//                         InstallmentPaymentStatus::
//                             PARTIAL,

//                     default =>
//                         InstallmentPaymentStatus::
//                             UNPAID,
//                 };

//             $installment
//                 ->forceFill([
//                     'outstanding_amount' =>
//                         $newOutstanding,

//                     'payment_status' =>
//                         $newPaymentStatus
//                             ->value,

//                     'last_payment_at' =>
//                         $postingTimestamp,

//                     'fully_paid_at' =>
//                         $newPaymentStatus
//                         ===
//                         InstallmentPaymentStatus::
//                             PAID
//                             ? $postingTimestamp
//                             : null,
//                 ])
//                 ->save();

//             /*
//              * Same payment-status history behaviour as normal
//              * payment posting.
//              */
//             if (
//                 $oldPaymentStatus
//                 !==
//                 $newPaymentStatus
//             ) {
//                 RepaymentStatusHistory::query()
//                     ->create([
//                         'repayment_account_id' =>
//                             $account->id,

//                         'repayment_installment_id' =>
//                             $installment->id,

//                         'repayment_transaction_id' =>
//                             $transaction->id,

//                         'status_type' =>
//                             StatusHistoryType::
//                                 PAYMENT_STATUS
//                                 ->value,

//                         'old_status' =>
//                             $oldPaymentStatus
//                                 ->value,

//                         'new_status' =>
//                             $newPaymentStatus
//                                 ->value,

//                         'source' =>
//                             StatusHistorySource::
//                                 PAYMENT_POSTING
//                                 ->value,

//                         'reason' =>
//                             'Payment allocated to repayment installment.',

//                         'metadata' => [
//                             'allocation_strategy' =>
//                                 'TARGETED_INSTALLMENT',

//                             'target_collection_amount' =>
//                                 $targetAmount,

//                             'interest_allocated' =>
//                                 $installmentInterestAllocated,

//                             'principal_allocated' =>
//                                 $installmentPrincipalAllocated,

//                             'outstanding_after_payment' =>
//                                 $newOutstanding,
//                         ],

//                         'changed_by' =>
//                             $actorId,

//                         'changed_at' =>
//                             $postingTimestamp,
//                     ]);
//             }
//         }

//         return [
//             'remaining_amount' =>
//                 $transactionRemaining,

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
//      *
//      * Kept aligned with RepaymentPaymentPostingService so
//      * targeted bulk payments update account aggregates exactly
//      * like normal payments.
//      */
//     private function refreshAccountBalances(
//         RepaymentAccount $account,
//         string $receivedAmount,
//         string $unallocatedAmount,
//         RepaymentTransaction $transaction,
//         ?int $actorId,
//         mixed $postingTimestamp
//     ): void {
//         $totals =
//             RepaymentInstallment::query()
//                 ->where(
//                     'repayment_account_id',
//                     $account->id
//                 )
//                 ->selectRaw(
//                     '
//                     COALESCE(
//                         SUM(principal_due - principal_paid),
//                         0
//                     ) AS principal_outstanding,

//                     COALESCE(
//                         SUM(interest_due - interest_paid),
//                         0
//                     ) AS interest_outstanding,

//                     COALESCE(
//                         SUM(outstanding_amount),
//                         0
//                     ) AS total_outstanding,

//                     COALESCE(
//                         SUM(
//                             CASE
//                                 WHEN due_status = ?
//                                 THEN outstanding_amount
//                                 ELSE 0
//                             END
//                         ),
//                         0
//                     ) AS overdue_amount
//                     ',
//                     ['OVERDUE']
//                 )
//                 ->first();

//         $principalOutstanding =
//             $this->normalizeMoney(
//                 $totals
//                     ?->principal_outstanding
//                 ?? '0'
//             );

//         $interestOutstanding =
//             $this->normalizeMoney(
//                 $totals
//                     ?->interest_outstanding
//                 ?? '0'
//             );

//         $totalOutstanding =
//             $this->normalizeMoney(
//                 $totals
//                     ?->total_outstanding
//                 ?? '0'
//             );

//         $overdueAmount =
//             $this->normalizeMoney(
//                 $totals
//                     ?->overdue_amount
//                 ?? '0'
//             );

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
//             if (
//                 bccomp(
//                     $value,
//                     '0.00',
//                     self::MONEY_SCALE
//                 ) === -1
//             ) {
//                 throw RepaymentPaymentException::
//                     financialInvariantViolation(
//                         message:
//                             'Repayment balance became negative after targeted payment allocation.',

//                         context: [
//                             'repayment_account_uuid' =>
//                                 $account->uuid,

//                             'field' =>
//                                 $field,

//                             'value' =>
//                                 $value,

//                             'repayment_transaction_uuid' =>
//                                 $transaction->uuid,
//                         ]
//                     );
//             }
//         }

//         $componentTotal =
//             bcadd(
//                 $principalOutstanding,
//                 $interestOutstanding,
//                 self::MONEY_SCALE
//             );

//         if (
//             bccomp(
//                 $componentTotal,
//                 $totalOutstanding,
//                 self::MONEY_SCALE
//             ) !== 0
//         ) {
//             throw RepaymentPaymentException::
//                 financialInvariantViolation(
//                     message:
//                         'Repayment account component balances do not match total outstanding.',

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

//         $nextDueDate =
//             RepaymentInstallment::query()
//                 ->where(
//                     'repayment_account_id',
//                     $account->id
//                 )
//                 ->where(
//                     'outstanding_amount',
//                     '>',
//                     0
//                 )
//                 ->orderBy(
//                     'due_date'
//                 )
//                 ->orderBy(
//                     'installment_number'
//                 )
//                 ->value(
//                     'due_date'
//                 );

//         $oldAccountStatus =
//             $account->status;

//         $newAccountStatus =
//             bccomp(
//                 $totalOutstanding,
//                 '0.00',
//                 self::MONEY_SCALE
//             ) === 0
//                 ? RepaymentAccountStatus::
//                     CLOSED
//                 : RepaymentAccountStatus::
//                     ACTIVE;

//         $account
//             ->forceFill([
//                 'principal_outstanding' =>
//                     $principalOutstanding,

//                 'interest_outstanding' =>
//                     $interestOutstanding,

//                 'total_outstanding' =>
//                     $totalOutstanding,

//                 'overdue_amount' =>
//                     $overdueAmount,

//                 /*
//                  * Targeted import always sends zero here.
//                  */
//                 'advance_amount' =>
//                     bcadd(
//                         (string)
//                         $account
//                             ->advance_amount,

//                         $unallocatedAmount,

//                         self::MONEY_SCALE
//                     ),

//                 'total_received' =>
//                     bcadd(
//                         (string)
//                         $account
//                             ->total_received,

//                         $receivedAmount,

//                         self::MONEY_SCALE
//                     ),

//                 'next_due_date' =>
//                     $nextDueDate,

//                 'last_payment_at' =>
//                     $postingTimestamp,

//                 'status' =>
//                     $newAccountStatus
//                         ->value,

//                 'closed_at' =>
//                     $newAccountStatus
//                     ===
//                     RepaymentAccountStatus::
//                         CLOSED
//                         ? $postingTimestamp
//                         : null,

//                 'updated_by' =>
//                     $actorId,
//             ])
//             ->save();

//         if (
//             $oldAccountStatus
//             !==
//             $newAccountStatus
//         ) {
//             RepaymentStatusHistory::query()
//                 ->create([
//                     'repayment_account_id' =>
//                         $account->id,

//                     'repayment_transaction_id' =>
//                         $transaction->id,

//                     'status_type' =>
//                         StatusHistoryType::
//                             ACCOUNT_STATUS
//                             ->value,

//                     'old_status' =>
//                         $oldAccountStatus
//                             ->value,

//                     'new_status' =>
//                         $newAccountStatus
//                             ->value,

//                     'source' =>
//                         StatusHistorySource::
//                             ACCOUNT_CLOSURE
//                             ->value,

//                     'reason' =>
//                         'Repayment obligations were fully settled.',

//                     'metadata' => [
//                         'principal_outstanding' =>
//                             $principalOutstanding,

//                         'interest_outstanding' =>
//                             $interestOutstanding,

//                         'total_outstanding' =>
//                             $totalOutstanding,

//                         'advance_amount_added' =>
//                             $unallocatedAmount,

//                         'allocation_strategy' =>
//                             'TARGETED_INSTALLMENTS_INTEREST_THEN_PRINCIPAL',
//                     ],

//                     'changed_by' =>
//                         $actorId,

//                     'changed_at' =>
//                         $postingTimestamp,
//                 ]);
//         }
//     }

//     /**
//      * Validate installment component balances before
//      * targeted allocation.
//      *
//      * Kept equivalent to normal payment posting invariant.
//      */
//     private function assertValidInstallmentState(
//         RepaymentInstallment $installment
//     ): void {
//         $values = [
//             'principal_due' =>
//                 $this->normalizeMoney(
//                     $installment
//                         ->principal_due
//                 ),

//             'interest_due' =>
//                 $this->normalizeMoney(
//                     $installment
//                         ->interest_due
//                 ),

//             'principal_paid' =>
//                 $this->normalizeMoney(
//                     $installment
//                         ->principal_paid
//                 ),

//             'interest_paid' =>
//                 $this->normalizeMoney(
//                     $installment
//                         ->interest_paid
//                 ),

//             'outstanding_amount' =>
//                 $this->normalizeMoney(
//                     $installment
//                         ->outstanding_amount
//                 ),
//         ];

//         foreach (
//             $values as $field => $value
//         ) {
//             if (
//                 bccomp(
//                     $value,
//                     '0.00',
//                     self::MONEY_SCALE
//                 ) === -1
//             ) {
//                 throw RepaymentPaymentException::
//                     invalidInstallmentState(
//                         installmentUuid:
//                             (string)
//                             $installment
//                                 ->uuid,

//                         installmentNumber:
//                             (int)
//                             $installment
//                                 ->installment_number,

//                         details: [
//                             'field' =>
//                                 $field,

//                             'value' =>
//                                 $value,
//                         ]
//                     );
//             }
//         }

//         if (
//             bccomp(
//                 $values[
//                     'principal_paid'
//                 ],
//                 $values[
//                     'principal_due'
//                 ],
//                 self::MONEY_SCALE
//             ) === 1
//         ) {
//             throw RepaymentPaymentException::
//                 invalidInstallmentState(
//                     installmentUuid:
//                         (string)
//                         $installment->uuid,

//                     installmentNumber:
//                         (int)
//                         $installment
//                             ->installment_number,

//                     details: [
//                         'principal_due' =>
//                             $values[
//                                 'principal_due'
//                             ],

//                         'principal_paid' =>
//                             $values[
//                                 'principal_paid'
//                             ],
//                     ]
//                 );
//         }

//         if (
//             bccomp(
//                 $values[
//                     'interest_paid'
//                 ],
//                 $values[
//                     'interest_due'
//                 ],
//                 self::MONEY_SCALE
//             ) === 1
//         ) {
//             throw RepaymentPaymentException::
//                 invalidInstallmentState(
//                     installmentUuid:
//                         (string)
//                         $installment->uuid,

//                     installmentNumber:
//                         (int)
//                         $installment
//                             ->installment_number,

//                     details: [
//                         'interest_due' =>
//                             $values[
//                                 'interest_due'
//                             ],

//                         'interest_paid' =>
//                             $values[
//                                 'interest_paid'
//                             ],
//                     ]
//                 );
//         }

//         $expectedOutstanding =
//             bcadd(
//                 bcsub(
//                     $values[
//                         'principal_due'
//                     ],
//                     $values[
//                         'principal_paid'
//                     ],
//                     self::MONEY_SCALE
//                 ),
//                 bcsub(
//                     $values[
//                         'interest_due'
//                     ],
//                     $values[
//                         'interest_paid'
//                     ],
//                     self::MONEY_SCALE
//                 ),
//                 self::MONEY_SCALE
//             );

//         if (
//             bccomp(
//                 $expectedOutstanding,
//                 $values[
//                     'outstanding_amount'
//                 ],
//                 self::MONEY_SCALE
//             ) !== 0
//         ) {
//             throw RepaymentPaymentException::
//                 invalidInstallmentState(
//                     installmentUuid:
//                         (string)
//                         $installment->uuid,

//                     installmentNumber:
//                         (int)
//                         $installment
//                             ->installment_number,

//                     details: [
//                         'expected_outstanding' =>
//                             $expectedOutstanding,

//                         'stored_outstanding' =>
//                             $values[
//                                 'outstanding_amount'
//                             ],
//                     ]
//                 );
//         }
//     }

//     /**
//      * Duplicate banking reference policy.
//      */
//     private function assertReferenceIsAvailable(
//         RepaymentAccount $account,
//         PaymentMode $paymentMode,
//         ?string $transactionReference
//     ): void {
//         if (
//             ! $paymentMode
//                 ->requiresReference()
//             || $transactionReference
//                 === null
//         ) {
//             return;
//         }

//         $existing =
//             RepaymentTransaction::query()
//                 ->useWritePdo()
//                 ->where(
//                     'repayment_account_id',
//                     $account->id
//                 )
//                 ->where(
//                     'payment_mode',
//                     $paymentMode
//                         ->value
//                 )
//                 ->where(
//                     'transaction_reference',
//                     $transactionReference
//                 )
//                 ->where(
//                     'transaction_type',
//                     RepaymentTransactionType::
//                         PAYMENT
//                         ->value
//                 )
//                 ->where(
//                     'status',
//                     '!=',
//                     RepaymentTransactionStatus::
//                         FAILED
//                         ->value
//                 )
//                 ->lockForUpdate()
//                 ->first();

//         if ($existing) {
//             throw RepaymentPaymentException::
//                 transactionReferenceAlreadyUsed(
//                     paymentMode:
//                         $paymentMode
//                             ->value,

//                     transactionReference:
//                         $transactionReference,

//                     existingTransactionUuid:
//                         (string)
//                         $existing
//                             ->uuid
//                 );
//         }
//     }

//     /**
//      * Determine whether an idempotent retry represents
//      * exactly the same targeted payment request.
//      *
//      * @param array<string, mixed> $payload
//      * @param array<int, array<string, mixed>> $targets
//      */
//     private function requestMatchesTransaction(
//         RepaymentAccount $account,
//         RepaymentTransaction $transaction,
//         array $payload,
//         array $targets
//     ): bool {
//         if (
//             (int)
//             $transaction
//                 ->repayment_account_id
//             !==
//             (int)
//             $account->id
//         ) {
//             return false;
//         }

//         if (
//             $transaction
//                 ->transaction_type
//             !==
//             RepaymentTransactionType::
//                 PAYMENT
//         ) {
//             return false;
//         }

//         $paymentMode =
//             PaymentMode::tryFrom(
//                 strtoupper(
//                     $this
//                         ->normalizeRequiredString(
//                             $payload[
//                                 'payment_mode'
//                             ] ?? ''
//                         )
//                 )
//             );

//         if (! $paymentMode) {
//             return false;
//         }

//         try {
//             $normalizedTargets =
//                 $this->normalizeTargets(
//                     $targets
//                 );
//         } catch (
//             RepaymentPaymentException
//         ) {
//             return false;
//         }

//         $fingerprint =
//             $this->buildRequestFingerprint(
//                 accountUuid:
//                     (string)
//                     $account->uuid,

//                 amount:
//                     $this->normalizeMoney(
//                         $payload[
//                             'amount'
//                         ] ?? '0'
//                     ),

//                 paymentMode:
//                     $paymentMode,

//                 transactionReference:
//                     $this
//                         ->normalizeNullableString(
//                             $payload[
//                                 'transaction_reference'
//                             ] ?? null
//                         ),

//                 receivedDate:
//                     $this
//                         ->normalizeRequiredString(
//                             $payload[
//                                 'received_date'
//                             ] ?? ''
//                         ),

//                 valueDate:
//                     $this
//                         ->normalizeRequiredString(
//                             $payload[
//                                 'value_date'
//                             ] ?? ''
//                         ),

//                 remarks:
//                     $this
//                         ->normalizeNullableString(
//                             $payload[
//                                 'remarks'
//                             ] ?? null
//                         ),

//                 targets:
//                     $normalizedTargets
//             );

//         $existingFingerprint =
//             $transaction
//                 ->metadata[
//                     'request_fingerprint'
//                 ]
//             ?? null;

//         /*
//          * Targeted service deliberately does NOT fall back to
//          * ordinary amount-only comparison.
//          *
//          * Without a targeted fingerprint we cannot prove that
//          * the original transaction targeted the same EMIs.
//          */
//         if (
//             ! is_string(
//                 $existingFingerprint
//             )
//         ) {
//             return false;
//         }

//         return hash_equals(
//             $existingFingerprint,
//             $fingerprint
//         );
//     }

//     /**
//      * Generate canonical targeted request fingerprint.
//      *
//      * @param array<int, array<string, mixed>> $targets
//      */
//     private function buildRequestFingerprint(
//         string $accountUuid,
//         string $amount,
//         PaymentMode $paymentMode,
//         ?string $transactionReference,
//         string $receivedDate,
//         string $valueDate,
//         ?string $remarks,
//         array $targets
//     ): string {
//         return hash(
//             'sha256',
//             json_encode(
//                 [
//                     'repayment_account_uuid' =>
//                         strtolower(
//                             trim(
//                                 $accountUuid
//                             )
//                         ),

//                     'amount' =>
//                         $amount,

//                     'payment_mode' =>
//                         $paymentMode
//                             ->value,

//                     'transaction_reference' =>
//                         $transactionReference,

//                     'received_date' =>
//                         $receivedDate,

//                     'value_date' =>
//                         $valueDate,

//                     'remarks' =>
//                         $remarks,

//                     'targets' =>
//                         array_map(
//                             static fn (
//                                 array $target
//                             ): array => [
//                                 'repayment_installment_uuid' =>
//                                     $target[
//                                         'repayment_installment_uuid'
//                                     ],

//                                 'installment_number' =>
//                                     (int)
//                                     $target[
//                                         'installment_number'
//                                     ],

//                                 'collection_amount' =>
//                                     (string)
//                                     $target[
//                                         'collection_amount'
//                                     ],
//                             ],
//                             $targets
//                         ),
//                 ],
//                 JSON_THROW_ON_ERROR
//             )
//         );
//     }

//     /**
//      * Load transaction response relationships consistently
//      * with the normal posting service.
//      */
//     private function hydrateTransaction(
//         RepaymentTransaction $transaction
//     ): RepaymentTransaction {
//         $transaction->refresh();

//         $transaction->load([
//             'receipt',
//             'reversalOf',
//             'reversal',

//             'allocations' =>
//                 fn ($query) =>
//                     $query
//                         ->allocationOrder(),
//         ]);

//         $transaction
//             ->loadCount(
//                 'allocations'
//             );

//         return $transaction;
//     }

//     /**
//      * Normalize decimal value to two places.
//      */
//     private function normalizeMoney(
//         mixed $value
//     ): string {
//         return bcadd(
//             trim(
//                 (string)
//                 ($value ?? '0')
//             ),
//             '0.00',
//             self::MONEY_SCALE
//         );
//     }

//     /**
//      * Smaller of two non-negative money values.
//      */
//     private function minimumMoney(
//         string $first,
//         string $second
//     ): string {
//         return bccomp(
//             $first,
//             $second,
//             self::MONEY_SCALE
//         ) <= 0
//             ? $first
//             : $second;
//     }

//     private function normalizeRequiredString(
//         mixed $value
//     ): string {
//         return trim(
//             (string)
//             $value
//         );
//     }

//     private function normalizeNullableString(
//         mixed $value
//     ): ?string {
//         if ($value === null) {
//             return null;
//         }

//         $normalized =
//             trim(
//                 (string)
//                 $value
//             );

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
//         $sqlState =
//             $exception
//                 ->errorInfo[
//                     0
//                 ]
//             ?? null;

//         $driverCode =
//             $exception
//                 ->errorInfo[
//                     1
//                 ]
//             ?? null;

//         return $sqlState === '23000'
//             && (int)
//                 $driverCode === 1062;
//     }
// }