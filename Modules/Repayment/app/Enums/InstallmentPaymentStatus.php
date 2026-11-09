<?php

namespace Modules\Repayment\Enums;

enum InstallmentPaymentStatus: string
{
    /**
     * Installment ke against abhi koi amount allocate nahi hua.
     */
    case UNPAID = 'UNPAID';

    /**
     * Installment ke against payment hui hai,
     * lekin complete outstanding clear nahi hua.
     */
    case PARTIAL = 'PARTIAL';

    /**
     * Installment ka complete principal aur interest clear hai.
     */
    case PAID = 'PAID';

    /**
     * Loan foreclosure ke karan remaining installment freeze hui.
     *
     * Ye PAID installment nahi hai aur iske against future
     * payment allocation allow nahi hoga.
     */
    case FORECLOSED = 'FORECLOSED';

    /** Remaining installment frozen due to negotiated settlement. */
    case SETTLED = 'SETTLED';

    /**
     * Whether installment still accepts payment allocation.
     */
    public function allowsAllocation(): bool
    {
        return in_array($this, [
            self::UNPAID,
            self::PARTIAL,
        ], true);
    }

    /**
     * Whether installment is fully settled through normal payment.
     */
    public function isPaid(): bool
    {
        return $this === self::PAID;
    }

    /**
     * Whether installment was frozen due to foreclosure.
     */
    public function isForeclosed(): bool
    {
        return $this === self::FORECLOSED;
    }

    public function isSettled(): bool
    {
        return $this === self::SETTLED;
    }

    /**
     * Human-readable label for API and frontend.
     */
    public function label(): string
    {
        return match ($this) {
            self::UNPAID => 'Unpaid',
            self::PARTIAL => 'Partially Paid',
            self::PAID => 'Paid',
            self::FORECLOSED => 'Foreclosed',
            self::SETTLED => 'Settled',
        };
    }
}