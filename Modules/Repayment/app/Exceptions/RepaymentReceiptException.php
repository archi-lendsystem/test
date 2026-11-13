<?php

namespace Modules\Repayment\Exceptions;
use RuntimeException;

use Throwable;

final class RepaymentReceiptException extends RuntimeException
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
     * Structured diagnostic information.
     *
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }

    /**
     * HTTP status suitable for API responses.
     */
    public function statusCode(): int
    {
        return $this->statusCode;
    }

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

    public static function transactionNotFound(
        string $accountUuid,
        string $transactionUuid
    ): self {
        return new self(
            message: 'Repayment transaction not found.',
            context: [
                'repayment_account_uuid' => $accountUuid,
                'repayment_transaction_uuid' =>
                    $transactionUuid,
            ],
            statusCode: 404
        );
    }

    /**
     * Receipt only successfully posted PAYMENT
     * transaction ke liye issue hogi.
     */
    public static function transactionNotReceiptable(
        string $transactionUuid,
        string $transactionType,
        string $transactionStatus
    ): self {
        return new self(
            message: 'A receipt cannot be issued for the selected repayment transaction.',
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
     * Transaction already has a receipt.
     */
    public static function receiptAlreadyExists(
        string $transactionUuid,
        string $receiptUuid,
        string $receiptNumber
    ): self {
        return new self(
            message: 'A receipt has already been issued for this repayment transaction.',
            context: [
                'repayment_transaction_uuid' =>
                    $transactionUuid,

                'receipt_uuid' =>
                    $receiptUuid,

                'receipt_number' =>
                    $receiptNumber,
            ],
            statusCode: 409
        );
    }

    public static function receiptNotFound(
        string $receiptUuid
    ): self {
        return new self(
            message: 'Repayment receipt not found.',
            context: [
                'receipt_uuid' => $receiptUuid,
            ],
            statusCode: 404
        );
    }

    /**
     * Already void receipt ko dobara void nahi karenge.
     */
    public static function receiptCannotBeVoided(
        string $receiptUuid,
        string $receiptStatus
    ): self {
        return new self(
            message: 'The repayment receipt cannot be voided in its current status.',
            context: [
                'receipt_uuid' =>
                    $receiptUuid,

                'receipt_status' =>
                    $receiptStatus,
            ],
            statusCode: 409
        );
    }

    /**
     * Receipt number generation failure.
     */
    public static function numberGenerationFailed(): self
    {
        return new self(
            message: 'Unable to generate a unique repayment receipt number.',
            statusCode: 500
        );
    }

    /**
     * PDF or storage operation failure.
     *
     * Receipt record create hone se pehle transaction
     * rollback hogi.
     */
    public static function documentGenerationFailed(
        string $message,
        array $context = [],
        ?Throwable $previous = null
    ): self {
        return new self(
            message: $message,
            context: $context,
            statusCode: 500,
            previous: $previous
        );
    }

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