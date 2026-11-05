<?php

namespace Modules\BLAmortization\Services;

use Carbon\Carbon;
use InvalidArgumentException;

class AmortizationCalculatorService
{


    protected const FREQUENCY_MAP = [
        'daily'       => 365,
        'weekly'      => 52,
        'bi_weekly'   => 26,
        'monthly'     => 12,
        'quarterly'   => 4,
        'half_yearly' => 2,
        'yearly'      => 1,
        'one_time' => 1,
    ];




    public function calculate(array $data): array
    {
        $loanAmount = (float) $data['loan_amount'];
        $interestRate = (float) $data['interest_rate'];
        $tenure = (float) $data['tenure'];

        $tenureUnit = strtolower(
            trim((string) $data['tenure_unit'])
        );

        $frequency = $this->normalizeFrequency(
            $data['frequency']
        );

        $subLoanType = strtolower(
            trim((string) ($data['sub_loan_type'] ?? ''))
        );

        $startDate = Carbon::parse($data['start_date']);

        $firstEmiDate = ! empty($data['first_emi_date'])
            ? Carbon::parse($data['first_emi_date'])->startOfDay()
            : null;

        if ($loanAmount <= 0) {
            throw new InvalidArgumentException(
                'Loan amount must be greater than zero.'
            );
        }

        if ($tenure <= 0) {
            throw new InvalidArgumentException(
                'Loan tenure must be greater than zero.'
            );
        }


        $isPayday = in_array(
            $subLoanType,
            [
                'payday',
                'payday loan',
            ],
            true
        );

        if ($isPayday) {
            return $this->buildPaydayOneTimeSchedule(
                loanAmount: $loanAmount,
                perDayRate: $interestRate,
                tenure: $tenure,
                tenureUnit: $tenureUnit,
                startDate: $startDate
            );
        }


        if (in_array($frequency, ['one_time', 'bullet'], true)) {
            return $this->buildStandardOneTimeSchedule(
                loanAmount: $loanAmount,
                annualRate: $interestRate,
                tenure: $tenure,
                tenureUnit: $tenureUnit,
                startDate: $startDate
            );
        }

        $tenureInYears = $this->convertTenureToYears(
            $tenure,
            $tenureUnit
        );

        $params = [
            'loanAmount' => $loanAmount,
            'annualRate' => $interestRate,
            'tenureInYears' => $tenureInYears,
            'frequency' => $frequency,
            'startDate' => $startDate,
            'firstEmiDate'  => $firstEmiDate,
        ];

        return match ($data['calculation_method']) {
            'flat_rate' =>
            $this->buildFlatRateSchedule($params),

            'daily_reducing' =>
            $this->buildDailyReducingSchedule($params),

            'reducing_balance' =>
            $this->buildReducingBalanceSchedule($params),

            default => throw new InvalidArgumentException(
                'Invalid calculation_method.'
            ),
        };
    }

    protected function buildPaydayOneTimeSchedule(
        float $loanAmount,
        float $perDayRate,
        float $tenure,
        string $tenureUnit,
        Carbon $startDate
    ): array {
        $tenureDays = $this->convertTenureToDays(
            $tenure,
            $tenureUnit
        );


        $totalInterest = $this->round2(
            $loanAmount *
                ($perDayRate / 100) *
                $tenureDays
        );

        $totalPayable = $this->round2(
            $loanAmount + $totalInterest
        );

        $maturityDate = $this->getMaturityDate(
            $startDate,
            $tenure,
            $tenureUnit
        );

        return [
            'rows' => [
                [
                    'installment_no' => 1,
                    'payment_date' => $maturityDate->toDateString(),
                    'opening_balance' => $this->round2($loanAmount),


                    'installment_amount' => $totalPayable,

                    'principal' => $this->round2($loanAmount),
                    'interest' => $totalInterest,
                    'closing_balance' => 0.0,
                    'days' => $tenureDays,
                    'repayment_type' => 'one_time',
                ],
            ],

            /*
         * Existing DB field installment_amount/emi_amount के लिए
         * यही one-time amount return होगा.
         */
            'installment' => $totalPayable,
            'numberOfPayments' => 1,
            'totalInterest' => $totalInterest,
            'totalPayable' => $totalPayable,
            'isOneTime' => true,
        ];
    }


    protected function buildStandardOneTimeSchedule(
        float $loanAmount,
        float $annualRate,
        float $tenure,
        string $tenureUnit,
        Carbon $startDate
    ): array {
        $tenureInYears = $this->convertTenureToYears(
            $tenure,
            $tenureUnit
        );

        $totalInterest = $this->round2(
            $loanAmount *
                ($annualRate / 100) *
                $tenureInYears
        );

        $totalPayable = $this->round2(
            $loanAmount + $totalInterest
        );

        $maturityDate = $this->getMaturityDate(
            $startDate,
            $tenure,
            $tenureUnit
        );

        return [
            'rows' => [
                [
                    'installment_no' => 1,
                    'payment_date' => $maturityDate->toDateString(),
                    'opening_balance' => $this->round2($loanAmount),
                    'installment_amount' => $totalPayable,
                    'principal' => $this->round2($loanAmount),
                    'interest' => $totalInterest,
                    'closing_balance' => 0.0,
                    'repayment_type' => 'one_time',
                ],
            ],

            'installment' => $totalPayable,
            'numberOfPayments' => 1,
            'totalInterest' => $totalInterest,
            'totalPayable' => $totalPayable,
            'isOneTime' => true,
        ];
    }

    protected function convertTenureToDays(
        float $tenure,
        string $tenureUnit
    ): int {
        return match ($tenureUnit) {
            'days' => max(
                1,
                (int) ceil($tenure)
            ),

            'weeks' => max(
                1,
                (int) ceil($tenure * 7)
            ),

            'months' => max(
                1,
                (int) ceil($tenure * 30)
            ),

            'years' => max(
                1,
                (int) ceil($tenure * 365)
            ),

            default => throw new InvalidArgumentException(
                "Unsupported tenure unit: {$tenureUnit}."
            ),
        };
    }

    protected function getMaturityDate(
        Carbon $startDate,
        float $tenure,
        string $tenureUnit
    ): Carbon {
        return match ($tenureUnit) {
            'days' => $startDate
                ->copy()
                ->addDays((int) ceil($tenure)),

            'weeks' => $startDate
                ->copy()
                ->addWeeks((int) ceil($tenure)),

            'months' => $startDate
                ->copy()
                ->addMonthsNoOverflow((int) ceil($tenure)),

            'years' => $startDate
                ->copy()
                ->addYearsNoOverflow((int) ceil($tenure)),

            default => throw new InvalidArgumentException(
                "Unsupported tenure unit: {$tenureUnit}."
            ),
        };
    }

    protected function convertTenureToYears(
        float $tenure,
        string $tenureUnit
    ): float {
        return match ($tenureUnit) {
            'days' => $tenure / 365,

            'weeks' => $tenure / 52,

            'months' => $tenure / 12,

            'years' => $tenure,

            default => throw new InvalidArgumentException(
                "Unsupported tenure unit: {$tenureUnit}."
            ),
        };
    }

    protected function normalizeFrequency(
        ?string $frequency
    ): string {
        $value = strtolower(trim((string) $frequency));

        return match ($value) {
            'daily',
            'daily loan' => 'daily',

            'weekly',
            'weekly loan' => 'weekly',

            'bi_weekly',
            'biweekly',
            'bi-weekly',
            'by-weekly',
            'by-weekly loan',
            'bi-weekly loan' => 'bi_weekly',

            'monthly',
            'monthly loan' => 'monthly',

            'quarterly',
            'quarterly loan' => 'quarterly',

            'half_yearly',
            'half-yearly',
            'half yearly' => 'half_yearly',

            'yearly',
            'annual',
            'yearly loan' => 'yearly',

            'one_time',
            'one-time',
            'one time' => 'one_time',

            'bullet',
            'bullet loan' => 'bullet',

            default => throw new InvalidArgumentException(
                "Unsupported repayment frequency: {$frequency}."
            ),
        };
    }

    protected function round2(float $value): float
    {
        return round($value, 2);
    }

    protected function computeNumberOfPayments(float $tenureInYears, string $frequency): int
    {
        $paymentsPerYear = self::FREQUENCY_MAP[$frequency];
        return max(1, (int) round($tenureInYears * $paymentsPerYear));
    }



    protected function getPaymentDate(
        Carbon $startDate,
        string $frequency,
        int $periodIndex
    ): Carbon {
        return match ($frequency) {
            'daily' => $startDate
                ->copy()
                ->addDays($periodIndex),

            'weekly' => $startDate
                ->copy()
                ->addDays(7 * $periodIndex),

            'bi_weekly' => $startDate
                ->copy()
                ->addDays(14 * $periodIndex),

            'monthly' => $startDate
                ->copy()
                ->addMonthsNoOverflow($periodIndex),

            'quarterly' => $startDate
                ->copy()
                ->addMonthsNoOverflow(3 * $periodIndex),

            'half_yearly' => $startDate
                ->copy()
                ->addMonthsNoOverflow(6 * $periodIndex),

            'yearly' => $startDate
                ->copy()
                ->addMonthsNoOverflow(12 * $periodIndex),

            default => throw new InvalidArgumentException(
                "Unsupported repayment frequency: {$frequency}."
            ),
        };
    }

    protected function getSchedulePaymentDate(
        Carbon $startDate,
        ?Carbon $firstEmiDate,
        string $frequency,
        int $installmentNumber
    ): Carbon {

        if ($firstEmiDate === null) {
            return $this->getPaymentDate(
                $startDate,
                $frequency,
                $installmentNumber
            );
        }


        $periodIndex = $installmentNumber - 1;

        return match ($frequency) {
            'daily' => $firstEmiDate
                ->copy()
                ->addDays($periodIndex),

            'weekly' => $firstEmiDate
                ->copy()
                ->addDays(7 * $periodIndex),

            'bi_weekly' => $firstEmiDate
                ->copy()
                ->addDays(14 * $periodIndex),

            'monthly' => $firstEmiDate
                ->copy()
                ->addMonthsNoOverflow($periodIndex),

            'quarterly' => $firstEmiDate
                ->copy()
                ->addMonthsNoOverflow(3 * $periodIndex),

            'half_yearly' => $firstEmiDate
                ->copy()
                ->addMonthsNoOverflow(6 * $periodIndex),

            'yearly' => $firstEmiDate
                ->copy()
                ->addYearsNoOverflow($periodIndex),

            default => throw new InvalidArgumentException(
                "Unsupported repayment frequency: {$frequency}."
            ),
        };
    }



    protected function buildReducingBalanceSchedule(array $p): array
    {
        [
            'loanAmount' => $loanAmount,
            'annualRate' => $annualRate,
            'tenureInYears' => $tenureInYears,
            'frequency' => $frequency,
            'startDate' => $startDate
        ] = $p;

        $firstEmiDate = $p['firstEmiDate'] ?? null;
        $paymentsPerYear   = self::FREQUENCY_MAP[$frequency];

        $delayDays = 0;
        $delayInterest = 0.0;
        if ($firstEmiDate !== null) {
            $standardFirstDate = $this->getPaymentDate($startDate, $frequency, 1);
            if ($firstEmiDate->gt($standardFirstDate)) {
                $delayDays = $standardFirstDate->diffInDays($firstEmiDate);
                $delayInterest = $this->round2(
                    $loanAmount * ($annualRate / 100 / 365) * $delayDays
                );
            }
        }

        $numberOfPayments  = $this->computeNumberOfPayments($tenureInYears, $frequency);
        $periodicRate      = $annualRate / 100 / $paymentsPerYear;

        if ($periodicRate === 0.0) {
            $installment = $loanAmount / $numberOfPayments;
        } else {
            $factor      = (1 + $periodicRate) ** $numberOfPayments;
            $installment = $loanAmount * (($periodicRate * $factor) / ($factor - 1));
        }
        $installment = $this->round2($installment);

        $rows = [];
        $openingBalance = $this->round2($loanAmount);
        $totalInterest = 0.0;
        $totalPrincipal = 0.0;

        for ($i = 1; $i <= $numberOfPayments; $i++) {
            $isLastRow = $i === $numberOfPayments;

            $interest  = $this->round2($openingBalance * $periodicRate);
            if ($i === 1 && $delayInterest > 0) {
                $interest = $this->round2($interest + $delayInterest);
            }

            $currentInstallment = $this->round2($installment + ($i === 1 ? $delayInterest : 0));
            $principal = $this->round2($currentInstallment - $interest);

            if ($isLastRow) {
                $principal = $openingBalance;
                $currentInstallment = $this->round2($principal + $interest);
            }

            $principal = max(0, min($principal, $openingBalance));

            $closingBalance = $this->round2($openingBalance - $principal);
            if ($closingBalance < 0 || $isLastRow) {
                $closingBalance = 0.0;
            }

            $rows[] = [
                'installment_no'     => $i,
                'payment_date' => $this
                    ->getSchedulePaymentDate(
                        $startDate,
                        $firstEmiDate,
                        $frequency,
                        $i
                    )
                    ->toDateString(),
                'opening_balance'    => $openingBalance,
                'installment_amount' => $currentInstallment,
                'principal'          => $principal,
                'interest'           => $interest,
                'closing_balance'    => $closingBalance,
                'days'               => ($i === 1 && $delayInterest > 0) ? $delayDays : null,
                'delay_interest'     => ($i === 1 && $delayInterest > 0) ? $delayInterest : null,
            ];

            $totalInterest  += $interest;
            $totalPrincipal += $principal;
            $openingBalance  = $closingBalance;
        }

        $totalInterest = $this->round2($totalInterest);
        $totalPayable  = $this->round2($totalPrincipal + $totalInterest);

        return [
            'rows'              => $rows,
            'installment'       => $installment,
            'numberOfPayments'  => $numberOfPayments,
            'totalInterest'     => $totalInterest,
            'totalPayable'      => $totalPayable,
            'firstEmiDelayDays'     => $delayDays,
            'firstEmiDelayInterest' => $delayInterest,
        ];
    }


    protected function buildFlatRateSchedule(array $p): array
    {
        [
            'loanAmount' => $loanAmount,
            'annualRate' => $annualRate,
            'tenureInYears' => $tenureInYears,
            'frequency' => $frequency,
            'startDate' => $startDate
        ] = $p;
        $firstEmiDate = $p['firstEmiDate'] ?? null;
        $numberOfPayments = $this->computeNumberOfPayments($tenureInYears, $frequency);

        $delayDays = 0;
        $delayInterest = 0.0;
        if ($firstEmiDate !== null) {
            $standardFirstDate = $this->getPaymentDate($startDate, $frequency, 1);
            if ($firstEmiDate->gt($standardFirstDate)) {
                $delayDays = $standardFirstDate->diffInDays($firstEmiDate);
                $delayInterest = $this->round2(
                    $loanAmount * ($annualRate / 100 / 365) * $delayDays
                );
            }
        }

        $totalInterest = $this->round2($loanAmount * ($annualRate / 100) * $tenureInYears);
        $totalPayable  = $this->round2($loanAmount + $totalInterest);
        $installment   = $this->round2($totalPayable / $numberOfPayments);

        $principalPerInstallment = $this->round2($loanAmount / $numberOfPayments);
        $interestPerInstallment  = $this->round2($totalInterest / $numberOfPayments);

        $rows = [];
        $openingBalance = $this->round2($loanAmount);
        $cumulativePrincipal = 0.0;
        $cumulativeInterest  = 0.0;

        for ($i = 1; $i <= $numberOfPayments; $i++) {
            $isLastRow = $i === $numberOfPayments;

            $principal = $principalPerInstallment;
            $interest  = $interestPerInstallment;
            if ($i === 1 && $delayInterest > 0) {
                $interest = $this->round2($interest + $delayInterest);
            }
            $currentInstallment = $this->round2($principal + $interest);


            if ($isLastRow) {
                $principal = $this->round2($loanAmount - $cumulativePrincipal);
                $interest  = $this->round2($totalInterest - $cumulativeInterest);
                $currentInstallment = $this->round2($principal + $interest);
            }

            $principal = max(0, min($principal, $openingBalance));

            $closingBalance = $this->round2($openingBalance - $principal);
            if ($closingBalance < 0 || $isLastRow) {
                $closingBalance = 0.0;
            }

            $rows[] = [
                'installment_no'     => $i,
                'payment_date' => $this
                    ->getSchedulePaymentDate(
                        $startDate,
                        $firstEmiDate,
                        $frequency,
                        $i
                    )
                    ->toDateString(),
                'opening_balance'    => $openingBalance,
                'installment_amount' => $currentInstallment,
                'principal'          => $principal,
                'interest'           => $interest,
                'closing_balance'    => $closingBalance,
                'days'               => ($i === 1 && $delayInterest > 0) ? $delayDays : null,
                'delay_interest'     => ($i === 1 && $delayInterest > 0) ? $delayInterest : null,
            ];

            $cumulativePrincipal += $principal;
            $cumulativeInterest  += $interest;
            $openingBalance = $closingBalance;
        }

        return [
            'rows'              => $rows,
            'installment'       => $installment,
            'numberOfPayments'  => $numberOfPayments,
            'totalInterest'     => $this->round2($cumulativeInterest),
            'totalPayable'      => $this->round2($cumulativePrincipal + $cumulativeInterest),
            'firstEmiDelayDays'     => $delayDays,       // NEW
            'firstEmiDelayInterest' => $delayInterest,   // NEW
        ];
    }

    /* ----------------------------------------------------------------- */
    /* Method 3: Daily Reducing Balance (actual/365)                      */
    /* ----------------------------------------------------------------- */

    protected function simulateFinalBalance(
        float $installment,
        float $loanAmount,
        float $dailyRate,
        Carbon $startDate,
        string $frequency,
        int $numberOfPayments
    ): float {
        $balance  = $loanAmount;
        $prevDate = $startDate->copy();

        for ($i = 1; $i <= $numberOfPayments; $i++) {
            $paymentDate = $this->getPaymentDate($startDate, $frequency, $i);
            $days        = $prevDate->diffInDays($paymentDate);
            $interest    = $balance * $dailyRate * $days;
            $balance     = $balance + $interest - $installment;
            $prevDate    = $paymentDate;
        }

        return $balance;
    }

    protected function buildDailyReducingSchedule(array $p): array
    {
        [
            'loanAmount' => $loanAmount,
            'annualRate' => $annualRate,
            'tenureInYears' => $tenureInYears,
            'frequency' => $frequency,
            'startDate' => $startDate
        ] = $p;

        $firstEmiDate = $p['firstEmiDate'] ?? null;

        $numberOfPayments = $this->computeNumberOfPayments($tenureInYears, $frequency);
        $dailyRate = $annualRate / 100 / 365;

        $lo = $loanAmount / $numberOfPayments;
        $hi = $loanAmount / $numberOfPayments + $loanAmount;

        $hiBalance = $this->simulateFinalBalance($hi, $loanAmount, $dailyRate, $startDate, $frequency, $numberOfPayments);
        $guard = 0;
        while ($hiBalance > 0 && $guard < 10) {
            $hi *= 2;
            $hiBalance = $this->simulateFinalBalance($hi, $loanAmount, $dailyRate, $startDate, $frequency, $numberOfPayments);
            $guard++;
        }

        for ($iter = 0; $iter < 100; $iter++) {
            $mid = ($lo + $hi) / 2;
            $finalBalance = $this->simulateFinalBalance($mid, $loanAmount, $dailyRate, $startDate, $frequency, $numberOfPayments);
            if ($finalBalance > 0) {
                $lo = $mid;
            } else {
                $hi = $mid;
            }
        }

        $installment = $this->round2($hi);

        $rows = [];
        $openingBalance = $this->round2($loanAmount);
        $prevDate = $startDate->copy();
        $totalInterest = 0.0;
        $totalPrincipal = 0.0;

        for ($i = 1; $i <= $numberOfPayments; $i++) {
            $isLastRow   = $i === $numberOfPayments;

            $paymentDate = $this->getSchedulePaymentDate(
                $startDate,
                $firstEmiDate,
                $frequency,
                $i
            );
            $days        = $prevDate->diffInDays($paymentDate);
            $interest    = $this->round2($openingBalance * $dailyRate * $days);

            $currentInstallment = $installment;
            $principal = $this->round2($currentInstallment - $interest);

            if ($isLastRow) {
                $principal = $openingBalance;
                $currentInstallment = $this->round2($principal + $interest);
            }

            $principal = max(0, min($principal, $openingBalance));

            $closingBalance = $this->round2($openingBalance - $principal);
            if ($closingBalance < 0 || $isLastRow) {
                $closingBalance = 0.0;
            }

            $rows[] = [
                'installment_no'     => $i,
                'payment_date'       => $paymentDate->toDateString(),
                'opening_balance'    => $openingBalance,
                'installment_amount' => $currentInstallment,
                'principal'          => $principal,
                'interest'           => $interest,
                'closing_balance'    => $closingBalance,
                'days'               => $days,
            ];

            $totalInterest  += $interest;
            $totalPrincipal += $principal;
            $openingBalance  = $closingBalance;
            $prevDate = $paymentDate;
        }

        $totalInterest = $this->round2($totalInterest);
        $totalPayable  = $this->round2($totalPrincipal + $totalInterest);

        return [
            'rows'              => $rows,
            'installment'       => $installment,
            'numberOfPayments'  => $numberOfPayments,
            'totalInterest'     => $totalInterest,
            'totalPayable'      => $totalPayable,
        ];
    }

    // public function getStandardFirstEmiDate(string $startDate, string $frequency): Carbon
    // {
    //     $frequency = $this->normalizeFrequency($frequency);

    //     if ($frequency === 'monthly') {
    //         return Carbon::parse($startDate)->addDays(30);
    //     }

    //     return $this->getPaymentDate(
    //         Carbon::parse($startDate),
    //         $frequency,
    //         1
    //     );
    // }

    public function getStandardFirstEmiDate(
    string $startDate,
    string $frequency
): Carbon {
    $frequency = $this->normalizeFrequency($frequency);

    $disbursementDate = Carbon::parse($startDate)
        ->startOfDay();

    if ($frequency === 'monthly') {
        $thirtyDaysDate = $disbursementDate
            ->copy()
            ->addDays(30);

        $calendarMonthDate = $disbursementDate
            ->copy()
            ->addMonthNoOverflow();

        return $calendarMonthDate->lt($thirtyDaysDate)
            ? $calendarMonthDate
            : $thirtyDaysDate;
    }

    return $this->getPaymentDate(
        $disbursementDate,
        $frequency,
        1
    );
}
}
