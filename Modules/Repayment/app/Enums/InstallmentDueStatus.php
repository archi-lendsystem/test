<?php

namespace Modules\Repayment\Enums;

enum InstallmentDueStatus: string
{
    /**
     * Installment ki due date future mein hai.
     */
    case UPCOMING = 'UPCOMING';

    /**
     * Installment ki due date current date hai.
     */
    case DUE = 'DUE';

    /**
     * Due date cross ho chuki hai aur installment
     * ka outstanding amount abhi baki hai.
     *
     * Kitne din overdue hai, woh DPD module calculate karega.
     */
    case OVERDUE = 'OVERDUE';

    /**
     * Check whether installment due date cross kar chuki hai.
     */
    public function isOverdue(): bool
    {
        return $this === self::OVERDUE;
    }

    /**
     * Check whether payment currently due hai.
     */
    public function requiresAttention(): bool
    {
        return in_array($this, [
            self::DUE,
            self::OVERDUE,
        ], true);
    }

    /**
     * Human-readable label for API and frontend.
     */
    public function label(): string
    {
        return match ($this) {
            self::UPCOMING => 'Upcoming',
            self::DUE => 'Due Today',
            self::OVERDUE => 'Overdue',
        };
    }
}