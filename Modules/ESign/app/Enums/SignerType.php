<?php

namespace Modules\ESign\Enums;

enum SignerType: string
{
    case Borrower = 'borrower';
    case Lender = 'lender';
}
