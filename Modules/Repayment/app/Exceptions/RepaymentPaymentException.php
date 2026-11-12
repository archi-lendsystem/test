<?php

namespace Modules\Repayment\Exceptions;

use Throwable;

final class RepaymentPaymentException extends RuntimeException
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
     * Structured diagnostic information for logging.
     *
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }

    /**
     * HTTP status suitable for the API response.
     */
    public function statusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Requested repayment account does not exist.
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
     * Account lifecycle status does not allow payment posting.
     */
    public static function accountDoesNotAllowPayment(
        string $accountUuid,
        string $accountStatus
    ): self {
        return new self(
            message: 'Payment cannot be posted to the repayment account in its current status.',
            context: [
                'repayment_account_uuid' => $accountUuid,
                'account_status' => $accountStatus,
            ],
            statusCode: 409
        );
    }

    /**
     * Same idempotency key was reused with a different payload.
     */
    public static function idempotencyConflict(
        string $idempotencyKey,
        string $existingTransactionUuid
    ): self {
        return new self(
            message: 'The idempotency key has already been used for a different payment request.',
            context: [
                'idempotency_key' => $idempotencyKey,
                'existing_transaction_uuid' =>
                    $existingTransactionUuid,
            ],
            statusCode: 409
        );
    }

    /**
     * External banking or instrument reference was already used.
     */
    public static function transactionReferenceAlreadyUsed(
        string $paymentMode,
        string $transactionReference,
        string $existingTransactionUuid
    ): self {
        return new self(
            message: 'The transaction reference has already been used for this repayment account and payment mode.',
            context: [
                'payment_mode' => $paymentMode,
                'transaction_reference' =>
                    $transactionReference,
                'existing_transaction_uuid' =>
                    $existingTransactionUuid,
            ],
            statusCode: 409
        );
    }

    /**
     * Stored installment financial state is inconsistent.
     */
    public static function invalidInstallmentState(
        string $installmentUuid,
        int $installmentNumber,
        array $details = []
    ): self {
        return new self(
            message: 'Repayment installment contains an invalid financial state.',
            context: array_merge([
                'installment_uuid' => $installmentUuid,
                'installment_number' => $installmentNumber,
            ], $details),
            statusCode: 409
        );
    }

    /**
     * Stored account and installment totals violate a
     * financial invariant.
     *
     * This represents a backend/data-integrity failure.
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

    /**
     * Backend could not generate a unique transaction number.
     */
    public static function transactionNumberGenerationFailed(
        int $attempts
    ): self {
        return new self(
            message: 'Unable to generate a unique repayment transaction number.',
            context: [
                'generation_attempts' => $attempts,
            ],
            statusCode: 500
        );
    }
}