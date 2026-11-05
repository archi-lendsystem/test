<?php

namespace Modules\Repayment\Exceptions;

use RuntimeException;
use Throwable;

final class RepaymentActivationException extends RuntimeException
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        string $message,
        private readonly array $context = [],
        ?Throwable $previous = null
    ) {
        parent::__construct(
            message: $message,
            code: 0,
            previous: $previous
        );
    }

    /**
     * Additional information for logs and debugging.
     *
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }

    /**
     * Disbursement is required before activation.
     */
    public static function disbursementNotFound(
        string $leadUuid
    ): self {
        return new self(
            message: 'Disbursement record not found for repayment activation.',
            context: [
                'lead_uuid' => $leadUuid,
                'activation_stage' => 'DISBURSEMENT_LOOKUP',
            ]
        );
    }

    /**
     * Disbursement must contain a loan number.
     */
    public static function loanNumberMissing(
        int $disbursementId
    ): self {
        return new self(
            message: 'Loan number is missing on the disbursement record.',
            context: [
                'disbursement_id' => $disbursementId,
                'activation_stage' => 'SOURCE_VALIDATION',
            ]
        );
    }

    /**
     * Amortization schedule must contain at least one row.
     */
    public static function scheduleMissing(
        int $amortizationId
    ): self {
        return new self(
            message: 'Amortization schedule is empty or invalid.',
            context: [
                'amortization_id' => $amortizationId,
                'activation_stage' => 'SCHEDULE_VALIDATION',
            ]
        );
    }

    /**
     * Individual schedule row is malformed.
     */
    public static function invalidScheduleRow(
        int $rowNumber,
        string $reason
    ): self {
        return new self(
            message: sprintf(
                'Amortization schedule row %d is invalid: %s',
                $rowNumber,
                $reason
            ),
            context: [
                'schedule_row_number' => $rowNumber,
                'reason' => $reason,
                'activation_stage' => 'SCHEDULE_VALIDATION',
            ]
        );
    }

    /**
     * Disbursement and amortization must belong
     * to the same lead.
     */
    public static function sourceLeadMismatch(
        string $amortizationLeadUuid,
        string $disbursementLeadUuid
    ): self {
        return new self(
            message: 'Disbursement and amortization belong to different leads.',
            context: [
                'amortization_lead_uuid' => $amortizationLeadUuid,
                'disbursement_lead_uuid' => $disbursementLeadUuid,
                'activation_stage' => 'SOURCE_VALIDATION',
            ]
        );
    }
}