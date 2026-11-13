<?php

namespace Modules\Repayment\Enums;

enum AllocationComponent: string
{
    /**
     * Installment interest component.
     *
     * Current repayment waterfall mein interest
     * principal se pehle allocate hoga.
     */
    case INTEREST = 'INTEREST';

    /**
     * Installment principal component.
     */
    case PRINCIPAL = 'PRINCIPAL';

    /**
     * Default allocation priority.
     *
     * Lower number means earlier allocation.
     */
    public function priority(): int
    {
        return match ($this) {
            self::INTEREST => 1,
            self::PRINCIPAL => 2,
        };
    }

    /**
     * Check whether component is interest.
     */
    public function isInterest(): bool
    {
        return $this === self::INTEREST;
    }

    /**
     * Check whether component is principal.
     */
    public function isPrincipal(): bool
    {
        return $this === self::PRINCIPAL;
    }

    /**
     * Human-readable label for API and frontend.
     */
    public function label(): string
    {
        return match ($this) {
            self::INTEREST => 'Interest',
            self::PRINCIPAL => 'Principal',
        };
    }
}