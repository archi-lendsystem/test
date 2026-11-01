<?php

namespace Modules\DemandNotice\Enums;

enum DemandNoticeType: string
{
    case FIRST = 'FIRST';
    case SECOND = 'SECOND';
    case FINAL = 'FINAL';

    public function label(): string
    {
        return match ($this) {
            self::FIRST => 'First Notice',
            self::SECOND => 'Second Notice',
            self::FINAL => 'Final Notice',
        };
    }

    /**
     * Days given to the borrower to clear dues before escalation.
     */
    public function responseWindowDays(): int
    {
        return match ($this) {
            self::FIRST => 7,
            self::SECOND => 5,
            self::FINAL => 3,
        };
    }
}
