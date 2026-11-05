<?php

namespace Modules\Repayment\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Repayment\Enums\ReceiptStatus;
use Modules\Repayment\Enums\RepaymentTransactionStatus;
use Modules\Repayment\Enums\RepaymentTransactionType;
use Modules\Repayment\Exceptions\RepaymentReceiptException;
use Modules\Repayment\Models\RepaymentAccount;
use Modules\Repayment\Models\RepaymentReceipt;
use Modules\Repayment\Models\RepaymentTransaction;

final class RepaymentReceiptService
{
    public function __construct(
        private readonly RepaymentReceiptNumberGenerator
            $receiptNumberGenerator,

        private readonly RepaymentReceiptDocumentService
            $receiptDocumentService
    ) {}

    /**
     * Issue one receipt for a successfully posted payment.
     *
     * Repeated calls return the existing receipt, so receipt
     * generation remains idempotent.
     */
    public function issue(
        string $accountUuid,
        string $transactionUuid,
        ?int $actorId = null
    ): RepaymentReceipt {
        try {
            $receipt = DB::transaction(
                fn (): RepaymentReceipt =>
                    $this->issueInsideTransaction(
                        accountUuid: $accountUuid,
                        transactionUuid: $transactionUuid,
                        actorId: $actorId
                    ),
                attempts: 3
            );
        } catch (QueryException $exception) {
            if (! $this->isDuplicateKeyException($exception)) {
                throw $exception;
            }

            /*
             * Concurrent requests may reach the unique
             * repayment_transaction_id constraint together.
             */
            $existingReceipt = RepaymentReceipt::query()
                ->useWritePdo()
                ->whereHas(
                    'transaction',
                    function ($query) use (
                        $accountUuid,
                        $transactionUuid
                    ): void {
                        $query
                            ->where('uuid', $transactionUuid)
                            ->whereHas(
                                'account',
                                fn ($accountQuery) =>
                                    $accountQuery->where(
                                        'uuid',
                                        $accountUuid
                                    )
                            );
                    }
                )
                ->first();

            if (! $existingReceipt) {
                throw $exception;
            }

            $receipt = $this->hydrateReceipt(
                $existingReceipt
            );
        }

        /*
         * PDF generation intentionally runs after the receipt
         * database transaction commits.
         *
         * A filesystem failure therefore cannot roll back or
         * duplicate the financial receipt record. A repeated
         * request will reuse the same receipt and retry the PDF.
         */
        return $this->receiptDocumentService
            ->ensureGenerated($receipt);
    }

    /**
     * Void an issued receipt when its payment is reversed.
     *
     * Missing receipt is allowed because older payment records
     * may not have had a receipt generated.
     */
    public function voidIfExistsForTransaction(
        RepaymentTransaction $transaction,
        string $reason,
        ?int $actorId = null
    ): ?RepaymentReceipt {
        return DB::transaction(
            function () use (
                $transaction,
                $reason,
                $actorId
            ): ?RepaymentReceipt {
                $receipt = RepaymentReceipt::query()
                    ->useWritePdo()
                    ->where(
                        'repayment_transaction_id',
                        $transaction->id
                    )
                    ->lockForUpdate()
                    ->first();

                if (! $receipt) {
                    return null;
                }

                /*
                 * Repeated reversal attempts should not mutate
                 * an already void receipt again.
                 */
                if ($receipt->isVoid()) {
                    return $this->hydrateReceipt($receipt);
                }

                if (! $receipt->canBeVoided()) {
                    throw RepaymentReceiptException::
                        receiptCannotBeVoided(
                            receiptUuid:
                                (string) $receipt->uuid,

                            receiptStatus:
                                $receipt->status->value
                        );
                }

                $voidReason = trim($reason);

                if ($voidReason === '') {
                    $voidReason =
                        'Related repayment payment was reversed.';
                }

                $receipt->forceFill([
                    'status' => ReceiptStatus::VOID->value,

                    'voided_by' => $actorId,
                    'voided_at' => now(),
                    'void_reason' => $voidReason,

                    'metadata' => array_merge(
                        $receipt->metadata ?? [],
                        [
                            'void_source' =>
                                'PAYMENT_REVERSAL',

                            'voided_transaction_uuid' =>
                                $transaction->uuid,
                        ]
                    ),
                ])->save();

                return $this->hydrateReceipt($receipt);
            },
            attempts: 3
        );
    }

    /**
     * Execute receipt issuance inside a locked transaction.
     */
    private function issueInsideTransaction(
        string $accountUuid,
        string $transactionUuid,
        ?int $actorId
    ): RepaymentReceipt {
        $account = RepaymentAccount::query()
            ->useWritePdo()
            ->where('uuid', $accountUuid)
            ->lockForUpdate()
            ->first();

        if (! $account) {
            throw RepaymentReceiptException::accountNotFound(
                $accountUuid
            );
        }

        $transaction = RepaymentTransaction::query()
            ->useWritePdo()
            ->where(
                'repayment_account_id',
                $account->id
            )
            ->where('uuid', $transactionUuid)
            ->lockForUpdate()
            ->first();

        if (! $transaction) {
            throw RepaymentReceiptException::
                transactionNotFound(
                    accountUuid: $accountUuid,
                    transactionUuid: $transactionUuid
                );
        }

        /*
         * Maximum one receipt per transaction.
         *
         * Existing receipt is returned rather than creating
         * a duplicate or changing its original number.
         */
        $existingReceipt = RepaymentReceipt::query()
            ->useWritePdo()
            ->where(
                'repayment_transaction_id',
                $transaction->id
            )
            ->lockForUpdate()
            ->first();

        if ($existingReceipt) {
            return $this->hydrateReceipt(
                $existingReceipt
            );
        }

        $this->assertTransactionIsReceiptable(
            $transaction
        );

        $receiptDate = optional(
            $transaction->received_date
        )->format('Y-m-d');

        if (! $receiptDate) {
            throw RepaymentReceiptException::
                financialInvariantViolation(
                    message: 'Posted repayment payment does not contain a valid received date.',
                    context: [
                        'repayment_transaction_uuid' =>
                            $transaction->uuid,
                    ]
                );
        }

        $generatedAt = now();

        $receipt = RepaymentReceipt::query()->create([
            'repayment_transaction_id' =>
                $transaction->id,

            /*
             * Initial insert requires a unique value.
             * Final ID-based number is assigned immediately.
             */
            'receipt_number' =>
                $this->receiptNumberGenerator
                    ->generateTemporary(),

            'receipt_date' => $receiptDate,
            'status' => ReceiptStatus::ISSUED->value,

            /*
             * PDF fields are populated by the document
             * generation layer in the next step.
             */
            'storage_disk' => null,
            'file_path' => null,
            'file_hash' => null,

            'generated_by' => $actorId,
            'generated_at' => $generatedAt,

            'metadata' => [
                'repayment_account_uuid' =>
                    $account->uuid,

                'loan_number' =>
                    $account->loan_number,

                'transaction_uuid' =>
                    $transaction->uuid,

                'transaction_number' =>
                    $transaction->transaction_number,

                'transaction_amount' =>
                    (string) $transaction->amount,

                'payment_mode' =>
                    $transaction->payment_mode?->value,

                'transaction_reference' =>
                    $transaction->transaction_reference,

                'document_status' =>
                    'PENDING_GENERATION',
            ],
        ]);

        $receipt->receipt_number =
            $this->receiptNumberGenerator->generateFinal(
                receiptId: (int) $receipt->id,
                receiptDate: $receiptDate
            );

        $receipt->save();

        return $this->hydrateReceipt($receipt);
    }

    /**
     * Receipt is issued only for a posted customer payment.
     */
    private function assertTransactionIsReceiptable(
        RepaymentTransaction $transaction
    ): void {
        if (
            $transaction->transaction_type
                !== RepaymentTransactionType::PAYMENT
            || $transaction->status
                !== RepaymentTransactionStatus::POSTED
        ) {
            throw RepaymentReceiptException::
                transactionNotReceiptable(
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
     * Load receipt response relationships.
     */
    private function hydrateReceipt(
        RepaymentReceipt $receipt
    ): RepaymentReceipt {
        $receipt->refresh();

        $receipt->load([
            'transaction.account',
        ]);

        return $receipt;
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