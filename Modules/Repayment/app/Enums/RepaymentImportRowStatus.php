<?php

namespace Modules\Repayment\Enums;

enum RepaymentImportRowStatus: string
{
    /**
     * Row passed all validation checks.
     */
    case VALID = 'VALID';

    /**
     * Row contains one or more errors.
     *
     * No financial posting is allowed for this row.
     */
    case ERROR = 'ERROR';

    /**
     * Check whether row is ready for final posting.
     */
    public function isValid(): bool
    {
        return $this === self::VALID;
    }

    /**
     * Check whether row contains validation errors.
     */
    public function hasError(): bool
    {
        return $this === self::ERROR;
    }

    /**
     * Human-readable label for API/frontend.
     */
    public function label(): string
    {
        return match ($this) {
            self::VALID => 'Valid',
            self::ERROR => 'Error',
        };
    }
}