<?php

namespace Modules\FollowUpPTP\Enums;

enum FollowUpType: string
{
    case CALL = 'CALL';
    case VISIT = 'VISIT';
    case SMS = 'SMS';
    case EMAIL = 'EMAIL';
    case WHATSAPP = 'WHATSAPP';
    case LETTER = 'LETTER';
    case OTHER = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::CALL => 'Call',
            self::VISIT => 'Field Visit',
            self::SMS => 'SMS',
            self::EMAIL => 'Email',
            self::WHATSAPP => 'WhatsApp',
            self::LETTER => 'Letter',
            self::OTHER => 'Other',
        };
    }
}
