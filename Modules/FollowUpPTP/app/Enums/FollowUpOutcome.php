<?php

namespace Modules\FollowUpPTP\Enums;

enum FollowUpOutcome: string
{
    case PTP_TAKEN = 'PTP_TAKEN';
    case NO_RESPONSE = 'NO_RESPONSE';
    case REFUSED = 'REFUSED';
    case DISPUTED = 'DISPUTED';
    case ALREADY_PAID = 'ALREADY_PAID';
    case OTHER = 'OTHER';

    public function requiresPtpDetails(): bool
    {
        return $this === self::PTP_TAKEN;
    }

    public function label(): string
    {
        return match ($this) {
            self::PTP_TAKEN => 'Promise to Pay Taken',
            self::NO_RESPONSE => 'No Response',
            self::REFUSED => 'Refused to Pay',
            self::DISPUTED => 'Disputed',
            self::ALREADY_PAID => 'Claims Already Paid',
            self::OTHER => 'Other',
        };
    }
}
