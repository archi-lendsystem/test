<?php

namespace Modules\FollowUpPTP\Enums;

enum PtpStatus: string
{
    /**
     * PTP date hasn't arrived yet.
     */
    case PENDING = 'PENDING';

    /**
     * Customer paid in full by the promised date.
     */
    case KEPT = 'KEPT';

    /**
     * Customer paid something, but not the full promised amount.
     */
    case PARTIALLY_KEPT = 'PARTIALLY_KEPT';

    /**
     * PTP date passed with nothing paid.
     */
    case BROKEN = 'BROKEN';

    public function isResolved(): bool
    {
        return $this !== self::PENDING;
    }

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pending',
            self::KEPT => 'Kept',
            self::PARTIALLY_KEPT => 'Partially Kept',
            self::BROKEN => 'Broken',
        };
    }
}
