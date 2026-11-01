<?php

namespace Modules\Repayment\Enums;

enum RepaymentChargeType: string
{
    /**
     * NACH / mandate / cheque bounce charge — a flat fee.
     */
    case BOUNCE = 'BOUNCE';

    /**
     * Late-payment penal charge — a percentage of the overdue
     * amount per 30-day period overdue.
     */
    case PENAL = 'PENAL';

    /**
     * Foreclosure (pre-closure) charge — either a percentage of the
     * outstanding principal or a flat amount, admin's choice per case.
     */
    case FORECLOSURE = 'FORECLOSURE';

    /**
     * Human-readable label for API and frontend.
     */
    public function label(): string
    {
        return match ($this) {
            self::BOUNCE => 'Bounce Charge',
            self::PENAL => 'Penal Charge',
            self::FORECLOSURE => 'Foreclosure Charge',
        };
    }
}
