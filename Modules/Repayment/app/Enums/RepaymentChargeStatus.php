<?php

namespace Modules\Repayment\Enums;

enum RepaymentChargeStatus: string
{
    /**
     * Charge levied, standing fully payable.
     */
    case PENDING = 'PENDING';

    /**
     * Part of the charge has been waived; some amount still payable.
     */
    case PARTIALLY_WAIVED = 'PARTIALLY_WAIVED';

    /**
     * The full charge has been waived — nothing payable.
     */
    case WAIVED = 'WAIVED';

    public function isWaived(): bool
    {
        return $this === self::WAIVED;
    }

    /**
     * Human-readable label for API and frontend.
     */
    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pending',
            self::PARTIALLY_WAIVED => 'Partially Waived',
            self::WAIVED => 'Waived',
        };
    }
}
