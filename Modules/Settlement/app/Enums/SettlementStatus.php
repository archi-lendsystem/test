<?php

namespace Modules\Settlement\Enums;

enum SettlementStatus: string
{
    /**
     * Settlement offer proposed, awaiting approval.
     */
    case PROPOSED = 'PROPOSED';

    /**
     * Approved — waiting for the customer to actually pay the
     * settlement amount.
     */
    case APPROVED = 'APPROVED';

    /**
     * Offer rejected; the account remains under normal collection.
     */
    case REJECTED = 'REJECTED';

    /**
     * Customer paid the settlement amount; the loan is closed as
     * "settled" (full-and-final, not full repayment).
     */
    case PAID = 'PAID';

    public function isFinal(): bool
    {
        return in_array($this, [self::REJECTED, self::PAID], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::PROPOSED => 'Proposed',
            self::APPROVED => 'Approved — Awaiting Payment',
            self::REJECTED => 'Rejected',
            self::PAID => 'Paid & Closed',
        };
    }
}
