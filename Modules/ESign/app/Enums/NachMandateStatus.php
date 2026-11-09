<?php

namespace Modules\ESign\Enums;

enum NachMandateStatus: string
{
    case INITIATED = 'initiated';
    case REGISTERED = 'registered';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';
    case EXPIRED = 'expired';
}
