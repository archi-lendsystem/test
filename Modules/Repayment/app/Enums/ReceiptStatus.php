<?php

namespace Modules\Repayment\Enums;

enum ReceiptStatus: string
{
    /**
     * Receipt successfully issued hai.
     */
    case ISSUED = 'ISSUED';

    /**
     * Related payment reverse hone ke baad receipt
     * void mark ki gayi hai.
     *
     * Receipt record aur generated document audit
     * purpose ke liye preserve rahenge.
     */
    case VOID = 'VOID';

    /**
     * Only an issued receipt can be voided.
     */
    public function canBeVoided(): bool
    {
        return $this === self::ISSUED;
    }

    /**
     * Check whether receipt has been voided.
     */
    public function isVoid(): bool
    {
        return $this === self::VOID;
    }

    /**
     * Receipt is usable only while it is issued.
     */
    public function isValid(): bool
    {
        return $this === self::ISSUED;
    }

    /**
     * Human-readable label for API and frontend.
     */
    public function label(): string
    {
        return match ($this) {
            self::ISSUED => 'Issued',
            self::VOID => 'Void',
        };
    }
}