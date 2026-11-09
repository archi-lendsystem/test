<?php

namespace Modules\ESign\Enums;

enum NachAuthMode: string
{
    case NET_BANKING = 'net_banking';
    case DEBIT_CARD = 'debit_card';
    case AADHAAR = 'aadhaar';
    case PHYSICAL = 'physical';
}
