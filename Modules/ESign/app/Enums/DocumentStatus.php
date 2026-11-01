<?php

namespace Modules\ESign\Enums;

enum DocumentStatus: string
{
    case Generated = 'generated';
    case BorrowerPending = 'borrower_pending';
    case BorrowerSigned = 'borrower_signed';
    case LenderPending = 'lender_pending';
    case Completed = 'completed';
    case Failed = 'failed';
}
