<?php

namespace Modules\Repayment\Exceptions;
use RuntimeException;

use Throwable;

final class RepaymentPaymentReversalException extends RuntimeException
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        string $message,
        private readonly array $context = [],
        private readonly int $statusCode = 409,
        ?Throwable $previous = null
    ) {
        parent::__construct(
            message: $message,
            code: 0,
            previous: $previous
        );
    }

    /**
     * Structured context for logging and API diagnostics.
     *
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }

    /**
     * HTTP status for the API response.
     */
    public function statusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Repayment account does not exist.
     */
    public static function accountNotFound(
        string $accountUuid
    ): self {
        return new self(
            message: 'Repayment account not found.',
            context: [
                'repayment_account_uuid' => $accountUuid,
            ],
            statusCode: 404
        );
    }

    /**
     * Original payment transaction does not exist
     * under the requested repayment account.
     */
    public static function transactionNotFound(
        string $accountUuid,
        string $transactionUuid
    ): self {
        return new self(
            message: 'Repayment payment transaction not found.',
            context: [
                'repayment_account_uuid' => $accountUuid,
                'repayment_transaction_uuid' =>
                    $transactionUuid,
            ],
            statusCode: 404
        );
    }

    /**
     * Only a successfully posted PAYMENT transaction
     * may be reversed.
     */
    public static function transactionNotReversible(
        string $transactionUuid,
        string $transactionType,
        string $transactionStatus
    ): self {
        return new self(
            message: 'The selected repayment transaction cannot be reversed.',
            context: [
                'repayment_transaction_uuid' =>
                    $transactionUuid,

                'transaction_type' =>
                    $transactionType,

                'transaction_status' =>
                    $transactionStatus,
            ],
            statusCode: 409
        );
    }

    /**
     * Original payment already has a reversal transaction.
     */
    public static function alreadyReversed(
        string $transactionUuid,
        string $reversalTransactionUuid
    ): self {
        return new self(
            message: 'The repayment payment has already been reversed.',
            context: [
                'original_transaction_uuid' =>
                    $transactionUuid,

                'reversal_transaction_uuid' =>
                    $reversalTransactionUuid,
            ],
            statusCode: 409
        );
    }

    /**
     * Same idempotency key was reused for a different
     * reversal request.
     */
    public static function idempotencyConflict(
        string $idempotencyKey,
        string $existingTransactionUuid
    ): self {
        return new self(
            message: 'The idempotency key has already been used for a different transaction request.',
            context: [
                'idempotency_key' =>
                    $idempotencyKey,

                'existing_transaction_uuid' =>
                    $existingTransactionUuid,
            ],
            statusCode: 409
        );
    }

    /**
     * Reversal business date cannot precede the original
     * payment business date.
     */
    public static function invalidReversalDate(
        string $originalReceivedDate,
        string $reversalDate
    ): self {
        return new self(
            message: 'Reversal date cannot be before the original payment received date.',
            context: [
                'original_received_date' =>
                    $originalReceivedDate,

                'reversal_date' =>
                    $reversalDate,
            ],
            statusCode: 422
        );
    }

    /**
     * Original payment allocations are missing or no longer
     * match the stored transaction totals.
     */
    public static function invalidAllocationState(
        string $transactionUuid,
        array $details = []
    ): self {
        return new self(
            message: 'The original payment contains an invalid allocation state.',
            context: array_merge([
                'repayment_transaction_uuid' =>
                    $transactionUuid,
            ], $details),
            statusCode: 409
        );
    }

    /**
     * Original unallocated payment amount cannot be removed
     * because the account advance balance is insufficient.
     */
    public static function insufficientAdvanceBalance(
        string $accountUuid,
        string $requiredAmount,
        string $availableAmount
    ): self {
        return new self(
            message: 'The repayment account does not contain sufficient advance balance to reverse this payment.',
            context: [
                'repayment_account_uuid' =>
                    $accountUuid,

                'required_advance_amount' =>
                    $requiredAmount,

                'available_advance_amount' =>
                    $availableAmount,
            ],
            statusCode: 409
        );
    }

    /**
     * Stored financial data violates a backend invariant.
     */
    public static function financialInvariantViolation(
        string $message,
        array $context = []
    ): self {
        return new self(
            message: $message,
            context: $context,
            statusCode: 500
        );
    }
}