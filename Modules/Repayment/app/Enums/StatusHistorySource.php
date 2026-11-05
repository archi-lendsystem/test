<?php

namespace Modules\Repayment\Enums;

enum StatusHistorySource: string
{
    /**
     * Repayment account activation process.
     *
     * Example:
     * ACTIVATION_PENDING -> ACTIVE
     */
    case ACTIVATION = 'ACTIVATION';

    /**
     * Customer payment successfully post hone ke
     * baad status change hua.
     *
     * Example:
     * UNPAID -> PARTIAL
     * PARTIAL -> PAID
     */
    case PAYMENT_POSTING = 'PAYMENT_POSTING';

    /**
     * Previously posted payment reverse hone ke
     * baad status restore hua.
     *
     * Example:
     * PAID -> PARTIAL
     * PARTIAL -> UNPAID
     */
    case PAYMENT_REVERSAL = 'PAYMENT_REVERSAL';

    /**
     * Scheduled date-status refresh process.
     *
     * Example:
     * UPCOMING -> DUE
     * DUE -> OVERDUE
     *
     * DPD calculation is excluded.
     */
    case DAILY_STATUS_REFRESH = 'DAILY_STATUS_REFRESH';

    /**
     * All repayment obligations clear hone ke baad
     * account close kiya gaya.
     */
    case ACCOUNT_CLOSURE = 'ACCOUNT_CLOSURE';

    /**
     * Internal automated system operation.
     */
    case SYSTEM = 'SYSTEM';

    /**
     * Authorized user ke manual action se
     * status change hua.
     */
    case MANUAL = 'MANUAL';

    /**
     * Check whether source is an automated process.
     */
    public function isAutomated(): bool
    {
        return in_array($this, [
            self::ACTIVATION,
            self::PAYMENT_POSTING,
            self::PAYMENT_REVERSAL,
            self::DAILY_STATUS_REFRESH,
            self::ACCOUNT_CLOSURE,
            self::SYSTEM,
        ], true);
    }

    /**
     * Check whether source is directly related
     * to a repayment transaction.
     */
    public function requiresTransaction(): bool
    {
        return in_array($this, [
            self::PAYMENT_POSTING,
            self::PAYMENT_REVERSAL,
        ], true);
    }

    /**
     * Check whether source represents a manual action.
     */
    public function isManual(): bool
    {
        return $this === self::MANUAL;
    }

    /**
     * Human-readable label for API and frontend.
     */
    public function label(): string
    {
        return match ($this) {
            self::ACTIVATION => 'Account Activation',
            self::PAYMENT_POSTING => 'Payment Posting',
            self::PAYMENT_REVERSAL => 'Payment Reversal',
            self::DAILY_STATUS_REFRESH => 'Daily Status Refresh',
            self::ACCOUNT_CLOSURE => 'Account Closure',
            self::SYSTEM => 'System',
            self::MANUAL => 'Manual',
        };
    }
}