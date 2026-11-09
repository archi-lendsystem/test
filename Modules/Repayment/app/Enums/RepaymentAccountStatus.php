<?php

namespace Modules\Repayment\Enums;

enum RepaymentAccountStatus: string
{
    /**
     * Disbursement complete hai, lekin amortization import
     * aur repayment activation abhi pending hai.
     */
    case ACTIVATION_PENDING = 'ACTIVATION_PENDING';

    /**
     * Repayment activation process fail ho gaya.
     *
     * Error repayment_accounts.activation_error mein save hoga.
     */
    case ACTIVATION_FAILED = 'ACTIVATION_FAILED';

    /**
     * Repayment account successfully activated hai.
     */
    case ACTIVE = 'ACTIVE';

    /**
     * Account temporarily operational hold par hai.
     */
    case SUSPENDED = 'SUSPENDED';

    /**
     * Principal aur interest obligations fully clear hain.
     */
    case CLOSED = 'CLOSED';

    /** Loan closed through an early foreclosure payment. */
    case FORECLOSED = 'FORECLOSED';

    /** Loan closed through a negotiated full-and-final settlement. */
    case SETTLED = 'SETTLED';

    /**
     * Account activation/disbursement reversal ke karan
     * permanently cancel hua.
     */
    case CANCELLED = 'CANCELLED';

    /**
     * Check whether payments can be posted to this account.
     */
    public function allowsPayment(): bool
    {
        return $this === self::ACTIVE;
    }

    /**
     * Check whether account is in a final state.
     */
    public function isFinal(): bool
    {
        return in_array($this, [
            self::CLOSED,
            self::FORECLOSED,
            self::SETTLED,
            self::CANCELLED,
        ], true);
    }

    /**
     * Human-readable label for API/UI usage.
     */
    public function label(): string
    {
        return match ($this) {
            self::ACTIVATION_PENDING => 'Activation Pending',
            self::ACTIVATION_FAILED => 'Activation Failed',
            self::ACTIVE => 'Active',
            self::SUSPENDED => 'Suspended',
            self::CLOSED => 'Closed',
            self::FORECLOSED => 'Foreclosed',
            self::SETTLED => 'Settled',
            self::CANCELLED => 'Cancelled',
        };
    }
}