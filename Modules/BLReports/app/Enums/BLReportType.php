<?php

namespace Modules\BLReports\Enums;

enum BLReportType: string
{
    case LOAN_APPLICATION = 'LOAN_APPLICATION';
    case LOAN_AGREEMENT = 'LOAN_AGREEMENT';
    case DPN = 'DPN';
    case KEY_FACT_STATEMENT = 'KEY_FACT_STATEMENT';
    case DECLARATION = 'DECLARATION';

    public function label(): string
    {
        return match ($this) {
            self::LOAN_APPLICATION => 'Loan Application Form',
            self::LOAN_AGREEMENT => 'Loan Agreement',
            self::DPN => 'Demand Promissory Note',
            self::KEY_FACT_STATEMENT => 'Key Facts Statement',
            self::DECLARATION => 'Declaration',
        };
    }

    public function filePrefix(): string
    {
        return match ($this) {
            self::LOAN_APPLICATION => 'LoanApplication',
            self::LOAN_AGREEMENT => 'LoanAgreement',
            self::DPN => 'DPN',
            self::KEY_FACT_STATEMENT => 'KeyFactStatement',
            self::DECLARATION => 'Declaration',
        };
    }

    /**
     * Reports tab mein documents ka fixed display order.
     *
     * @return array<int, self>
     */
    public static function ordered(): array
    {
        return [
            self::LOAN_APPLICATION,
            self::LOAN_AGREEMENT,
            self::DPN,
            self::KEY_FACT_STATEMENT,
            self::DECLARATION,
        ];
    }
}