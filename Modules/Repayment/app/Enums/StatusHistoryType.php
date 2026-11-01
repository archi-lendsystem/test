<?php

namespace Modules\Repayment\Enums;

enum StatusHistoryType: string
{
    /**
     * Repayment account lifecycle status change.
     *
     * Example:
     * ACTIVATION_PENDING -> ACTIVE
     */
    case ACCOUNT_STATUS = 'ACCOUNT_STATUS';

    /**
     * Installment payment status change.
     *
     * Example:
     * UNPAID -> PARTIAL -> PAID
     */
    case PAYMENT_STATUS = 'PAYMENT_STATUS';

    /**
     * Installment due-date status change.
     *
     * Example:
     * UPCOMING -> DUE -> OVERDUE
     *
     * DPD calculation is not part of this type.
     */
    case DUE_STATUS = 'DUE_STATUS';

    /**
     * Check whether history record must reference
     * a repayment installment.
     */
    public function requiresInstallment(): bool
    {
        return in_array($this, [
            self::PAYMENT_STATUS,
            self::DUE_STATUS,
        ], true);
    }

    /**
     * Check whether this is an account-level history entry.
     */
    public function isAccountLevel(): bool
    {
        return $this === self::ACCOUNT_STATUS;
    }

    /**
     * Check whether this is an installment-level history entry.
     */
    public function isInstallmentLevel(): bool
    {
        return $this->requiresInstallment();
    }

    /**
     * Human-readable label for API and frontend.
     */
    public function label(): string
    {
        return match ($this) {
            self::ACCOUNT_STATUS => 'Account Status',
            self::PAYMENT_STATUS => 'Payment Status',
            self::DUE_STATUS => 'Due Status',
        };
    }
}