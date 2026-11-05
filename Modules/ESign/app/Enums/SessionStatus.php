<?php

namespace Modules\ESign\Enums;

enum SessionStatus: string
{
    case Initiating = 'initiating';
    case Pending = 'pending';
    case Viewed = 'viewed';
    case Signed = 'signed';
    case Failed = 'failed';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Signed, self::Failed, self::Expired, self::Cancelled], true);
    }

    public function isActive(): bool
    {
        return in_array($this, [self::Initiating, self::Pending, self::Viewed], true);
    }
}
