<?php

namespace Modules\Repayment\Enums;

enum RepaymentTransactionType: string
{
    /**
     * Normal customer repayment.
     */
    case PAYMENT = 'PAYMENT';

    /**
     * Previously posted repayment transaction reversal.
     */
    case REVERSAL = 'REVERSAL';

    /**
     * Customer ko amount return kiya gaya.
     */
    case REFUND = 'REFUND';

    /**
     * Authorized accounting adjustment.
     */
    case ADJUSTMENT = 'ADJUSTMENT';

    /**
     * Full-and-final amount received for early loan closure.
     */
    case FORECLOSURE = 'FORECLOSURE';

    /** Customer payment received under a negotiated settlement. */
    case SETTLEMENT = 'SETTLEMENT';

    /**
     * Whether this transaction increases collected repayment.
     */
    public function increasesCollection(): bool
    {
        return in_array($this, [
            self::PAYMENT,
            self::ADJUSTMENT,
            self::FORECLOSURE,
            self::SETTLEMENT,
        ], true);
    }

    /**
     * Whether this transaction reduces or restores balances.
     */
    public function reducesCollection(): bool
    {
        return in_array($this, [
            self::REVERSAL,
            self::REFUND,
        ], true);
    }

    /**
     * Whether transaction requires an original transaction reference.
     */
    public function requiresOriginalTransaction(): bool
    {
        return $this === self::REVERSAL;
    }

    /**
     * Human-readable label for API and frontend.
     */
    public function label(): string
    {
        return match ($this) {
            self::PAYMENT => 'Payment',
            self::REVERSAL => 'Payment Reversal',
            self::REFUND => 'Refund',
            self::ADJUSTMENT => 'Adjustment',
            self::FORECLOSURE => 'Foreclosure',
            self::SETTLEMENT => 'Settlement',
        };
    }
}