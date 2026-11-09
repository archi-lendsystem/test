<?php

namespace Modules\Repayment\Services;

use Illuminate\Support\Facades\DB;
use Modules\BLAmortization\Models\BLAmortization;
use Modules\BLDisbursment\Models\BLDisbursment;
use Modules\Repayment\Enums\InstallmentDueStatus;
use Modules\Repayment\Enums\InstallmentPaymentStatus;
use Modules\Repayment\Enums\RepaymentAccountStatus;
use Modules\Repayment\Enums\StatusHistorySource;
use Modules\Repayment\Enums\StatusHistoryType;
use Modules\Repayment\Exceptions\RepaymentActivationException;
use Modules\Repayment\Models\RepaymentAccount;

final class RepaymentAccountActivationService
{
    private const MONEY_SCALE = 2;

    private const ROUNDING_TOLERANCE = '0.01';

    public function __construct(
        private readonly AmortizationScheduleNormalizer $normalizer
    ) {}

    /**
     * Activate a repayment account from a saved amortization.
     */
    public function activate(
        BLAmortization $amortization,
        ?int $actorId = null
    ): RepaymentAccount {
        return DB::transaction(function () use (
            $amortization,
            $actorId
        ): RepaymentAccount {
            $lockedAmortization = BLAmortization::query()
                ->lockForUpdate()
                ->find($amortization->getKey());

            if (! $lockedAmortization) {
                throw new RepaymentActivationException(
                    message: 'Amortization record is not available for repayment activation.',
                    context: [
                        'amortization_id' => $amortization->getKey(),
                        'activation_stage' => 'AMORTIZATION_LOOKUP',
                    ]
                );
            }

            $leadUuid = trim(
                (string) $lockedAmortization->lead_uuid
            );

            if ($leadUuid === '') {
                throw new RepaymentActivationException(
                    message: 'Lead UUID is missing on the amortization record.',
                    context: [
                        'amortization_id' => $lockedAmortization->id,
                        'activation_stage' => 'SOURCE_VALIDATION',
                    ]
                );
            }

            $disbursement = BLDisbursment::query()
                ->where('lead_uuid', $leadUuid)
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if (! $disbursement) {
                throw RepaymentActivationException::disbursementNotFound(
                    $leadUuid
                );
            }

            $this->validateSources(
                amortization: $lockedAmortization,
                disbursement: $disbursement
            );

            /*
             * Same amortization, disbursement, ya loan number ke liye
             * duplicate repayment account create nahi hoga.
             */
            $existingAccount = RepaymentAccount::query()
                ->where(
                    'amortization_id',
                    $lockedAmortization->id
                )
                ->orWhere(
                    'disbursement_id',
                    $disbursement->id
                )
                ->orWhere(
                    'loan_number',
                    trim((string) $disbursement->loan_number)
                )
                ->lockForUpdate()
                ->first();

            if ($existingAccount) {
                return $existingAccount->load([
                    'installments',
                    'statusHistories',
                ]);
            }

            $normalizedSchedule = $this->normalizer->normalize(
                schedule: $lockedAmortization->schedule,
                amortizationId: (int) $lockedAmortization->id
            );

            $this->validateScheduleSummary(
                amortization: $lockedAmortization,
                schedule: $normalizedSchedule
            );

            $firstInstallment = $normalizedSchedule[0];

            $lastInstallment = $normalizedSchedule[
                array_key_last($normalizedSchedule)
            ];

            /*
             * Har installment ka collectible amount:
             *
             * principal_due + interest_due
             * then PHP_ROUND_HALF_UP.
             *
             * Examples:
             * 106.49 => 106.00
             * 106.50 => 107.00
             * 106.60 => 107.00
             *
             * Account outstanding individual rounded installments
             * ka total hoga.
             */
            $roundedCollectionTotal = '0.00';

            foreach ($normalizedSchedule as $row) {
                $roundedCollectionTotal = bcadd(
                    $roundedCollectionTotal,
                    $this->collectibleInstallmentAmount($row),
                    self::MONEY_SCALE
                );
            }

            $roundedPrincipalOutstanding = $this->roundWholeMoney(
                $lockedAmortization->loan_amount
            );

            /*
             * Account component balances ka total rounded collectible
             * outstanding ke equal rehna chahiye.
             */
            $roundedInterestOutstanding = bcsub(
                $roundedCollectionTotal,
                $roundedPrincipalOutstanding,
                self::MONEY_SCALE
            );

            if (
                bccomp(
                    $roundedInterestOutstanding,
                    '0.00',
                    self::MONEY_SCALE
                ) === -1
            ) {
                $roundedPrincipalOutstanding =
                    $roundedCollectionTotal;

                $roundedInterestOutstanding = '0.00';
            }

            $activatedAt = now();

            $account = RepaymentAccount::create([
                'loan_type' => 'BL',

                'lead_uuid' => $leadUuid,

                'disbursement_id' => $disbursement->id,

                'amortization_id' => $lockedAmortization->id,

                'loan_number' => trim(
                    (string) $disbursement->loan_number
                ),

                'schedule_version' => 1,

                'principal_amount' => $this->money(
                    $lockedAmortization->loan_amount
                ),

                'net_disbursed_amount' => $this->money(
                    $lockedAmortization->net_disbursed_amount
                    ?? $disbursement->disbursement_amount
                ),

                'interest_rate' => number_format(
                    (float) $lockedAmortization->interest_rate,
                    6,
                    '.',
                    ''
                ),

                'tenure' => number_format(
                    (float) $lockedAmortization->tenure,
                    2,
                    '.',
                    ''
                ),

                'tenure_unit' => $lockedAmortization->tenure_unit,

                'frequency' => $lockedAmortization->frequency,

                'installment_count' => count(
                    $normalizedSchedule
                ),

                /*
                 * Amortization financial values original decimal
                 * precision ke saath preserve rahenge.
                 */
                'total_interest' => $this->money(
                    $lockedAmortization->total_interest
                ),

                'total_payable' => $this->money(
                    $lockedAmortization->total_payable
                ),

                /*
                 * Repayment outstanding rounded installments
                 * ke total ke according store hoga.
                 */
                'principal_outstanding' =>
                    $roundedPrincipalOutstanding,

                'interest_outstanding' =>
                    $roundedInterestOutstanding,

                'total_outstanding' =>
                    $roundedCollectionTotal,

                'overdue_amount' => '0.00',

                'advance_amount' => '0.00',

                'total_received' => '0.00',

                'first_due_date' =>
                    $firstInstallment['due_date'],

                'next_due_date' =>
                    $firstInstallment['due_date'],

                'maturity_date' =>
                    $lastInstallment['due_date'],

                'status' =>
                    RepaymentAccountStatus::ACTIVATION_PENDING,

                'financial_snapshot' => [
                    'source' => 'BL_AMORTIZATION',

                    'lead_uuid' => $leadUuid,

                    'disbursement_id' => $disbursement->id,

                    'amortization_id' => $lockedAmortization->id,

                    'loan_number' => trim(
                        (string) $disbursement->loan_number
                    ),

                    'loan_amount' => $this->money(
                        $lockedAmortization->loan_amount
                    ),

                    'net_disbursed_amount' => $this->money(
                        $lockedAmortization->net_disbursed_amount
                        ?? $disbursement->disbursement_amount
                    ),

                    'total_interest' => $this->money(
                        $lockedAmortization->total_interest
                    ),

                    'total_payable' => $this->money(
                        $lockedAmortization->total_payable
                    ),

                    'rounded_collection_total' =>
                        $roundedCollectionTotal,

                    'installment_count' => count(
                        $normalizedSchedule
                    ),

                    'captured_at' => $activatedAt->toISOString(),
                ],

                'activation_error' => null,

                'activated_at' => null,

                'created_by' => $actorId,

                'updated_by' => $actorId,
            ]);

            $installmentRows = array_map(
                function (array $row) use (
                    $lockedAmortization
                ): array {
                    /*
                     * Important:
                     *
                     * Existing schedule installment_amount ko directly
                     * use nahi karna.
                     *
                     * Correct calculation:
                     *
                     * round(
                     *     principal_due + interest_due,
                     *     0,
                     *     PHP_ROUND_HALF_UP
                     * )
                     */
                    $roundedInstallmentAmount =
                        $this->collectibleInstallmentAmount($row);

                    return [
                        'amortization_id' =>
                            $lockedAmortization->id,

                        'schedule_version' => 1,

                        'installment_number' =>
                            $row['installment_number'],

                        'due_date' =>
                            $row['due_date'],

                        'opening_principal' =>
                            $row['opening_principal'],

                        /*
                         * Principal aur interest separately round nahi
                         * karne hain. Original values preserve hongi.
                         */
                        'principal_due' =>
                            $row['principal_due'],

                        'interest_due' =>
                            $row['interest_due'],

                        /*
                         * Example:
                         *
                         * principal_due = 100.20
                         * interest_due  =   6.40
                         *
                         * exact total   = 106.60
                         *
                         * installment_amount = 107.00
                         * outstanding_amount = 107.00
                         */
                        'installment_amount' =>
                            $roundedInstallmentAmount,

                        'closing_principal' =>
                            $row['closing_principal'],

                        'principal_paid' => '0.00',

                        'interest_paid' => '0.00',

                        'outstanding_amount' =>
                            $roundedInstallmentAmount,

                        'payment_status' =>
                            InstallmentPaymentStatus::UNPAID,

                        'due_status' => $this->dueStatus(
                            $row['due_date']
                        ),

                        'schedule_snapshot' =>
                            $row['schedule_snapshot'],
                    ];
                },
                $normalizedSchedule
            );

            /*
             * Relationship repayment_account_id automatically set karega.
             */
            $account->installments()->createMany(
                $installmentRows
            );

            $account->update([
                'status' => RepaymentAccountStatus::ACTIVE,

                'activation_error' => null,

                'activated_at' => $activatedAt,

                'updated_by' => $actorId,
            ]);

            $account->statusHistories()->create([
                'status_type' =>
                    StatusHistoryType::ACCOUNT_STATUS,

                'old_status' =>
                    RepaymentAccountStatus::ACTIVATION_PENDING->value,

                'new_status' =>
                    RepaymentAccountStatus::ACTIVE->value,

                'source' =>
                    StatusHistorySource::ACTIVATION,

                'reason' =>
                    'Repayment account activated from amortization schedule.',

                'metadata' => [
                    'schedule_version' => 1,

                    'installment_count' => count(
                        $normalizedSchedule
                    ),

                    'disbursement_id' =>
                        $disbursement->id,

                    'amortization_id' =>
                        $lockedAmortization->id,
                ],

                'changed_by' => $actorId,

                'changed_at' => $activatedAt,
            ]);

            return $account->fresh([
                'installments',

                'statusHistories',

                'disbursement',

                'amortization',
            ]);
        }, 3);
    }

    /**
     * Validate source records before account creation.
     */
    private function validateSources(
        BLAmortization $amortization,
        BLDisbursment $disbursement
    ): void {
        $amortizationLeadUuid = trim(
            (string) $amortization->lead_uuid
        );

        $disbursementLeadUuid = trim(
            (string) $disbursement->lead_uuid
        );

        if ($amortizationLeadUuid !== $disbursementLeadUuid) {
            throw RepaymentActivationException::sourceLeadMismatch(
                amortizationLeadUuid: $amortizationLeadUuid,
                disbursementLeadUuid: $disbursementLeadUuid
            );
        }

        if (blank($disbursement->loan_number)) {
            throw RepaymentActivationException::loanNumberMissing(
                (int) $disbursement->id
            );
        }

        if (
            bccomp(
                $this->money($amortization->loan_amount),
                '0.00',
                self::MONEY_SCALE
            ) !== 1
        ) {
            throw new RepaymentActivationException(
                message: 'Amortization loan amount must be greater than zero.',
                context: [
                    'amortization_id' => $amortization->id,

                    'loan_amount' => $amortization->loan_amount,

                    'activation_stage' => 'SOURCE_VALIDATION',
                ]
            );
        }
    }

    /**
     * Validate schedule count and aggregate financial values.
     *
     * @param array<int, array<string, mixed>> $schedule
     */
    private function validateScheduleSummary(
        BLAmortization $amortization,
        array $schedule
    ): void {
        $scheduleCount = count($schedule);

        $expectedCount = (int) $amortization->number_of_payments;

        if (
            $expectedCount <= 0
            || $scheduleCount !== $expectedCount
        ) {
            throw new RepaymentActivationException(
                message: 'Schedule row count does not match the amortization payment count.',
                context: [
                    'amortization_id' => $amortization->id,

                    'expected_installment_count' => $expectedCount,

                    'actual_installment_count' => $scheduleCount,

                    'activation_stage' => 'SCHEDULE_SUMMARY_VALIDATION',
                ]
            );
        }

        $principalTotal = '0.00';

        $interestTotal = '0.00';

        foreach ($schedule as $row) {
            $principalTotal = bcadd(
                $principalTotal,
                (string) $row['principal_due'],
                self::MONEY_SCALE
            );

            $interestTotal = bcadd(
                $interestTotal,
                (string) $row['interest_due'],
                self::MONEY_SCALE
            );
        }

        /*
         * Validation amortization ke original exact principal
         * aur interest values se hi hogi.
         *
         * Rounded value sirf repayment collection ke liye hai.
         */
        $payableTotal = bcadd(
            $principalTotal,
            $interestTotal,
            self::MONEY_SCALE
        );

        $this->assertMoneyMatches(
            field: 'loan_amount',
            expected: $this->money(
                $amortization->loan_amount
            ),
            actual: $principalTotal,
            amortizationId: (int) $amortization->id
        );

        $this->assertMoneyMatches(
            field: 'total_interest',
            expected: $this->money(
                $amortization->total_interest
            ),
            actual: $interestTotal,
            amortizationId: (int) $amortization->id
        );

        $this->assertMoneyMatches(
            field: 'total_payable',
            expected: $this->money(
                $amortization->total_payable
            ),
            actual: $payableTotal,
            amortizationId: (int) $amortization->id
        );

        $lastRow = $schedule[
            array_key_last($schedule)
        ];

        if (
            bccomp(
                (string) $lastRow['closing_principal'],
                self::ROUNDING_TOLERANCE,
                self::MONEY_SCALE
            ) === 1
        ) {
            throw new RepaymentActivationException(
                message: 'Final schedule closing balance must be zero.',
                context: [
                    'amortization_id' => $amortization->id,

                    'final_closing_balance' =>
                        $lastRow['closing_principal'],

                    'activation_stage' =>
                        'SCHEDULE_SUMMARY_VALIDATION',
                ]
            );
        }
    }

    /**
     * Determine due status without calculating DPD.
     */
    private function dueStatus(
        string $dueDate
    ): InstallmentDueStatus {
        $today = now()->toDateString();

        if ($dueDate < $today) {
            return InstallmentDueStatus::OVERDUE;
        }

        if ($dueDate === $today) {
            return InstallmentDueStatus::DUE;
        }

        return InstallmentDueStatus::UPCOMING;
    }

    /**
     * Exact principal aur interest ko add karke final amount round karo.
     *
     * Example:
     *
     * 100.20 + 6.29 = 106.49 => 106.00
     * 100.20 + 6.30 = 106.50 => 107.00
     * 100.20 + 6.40 = 106.60 => 107.00
     *
     * @param array<string, mixed> $row
     */
    private function collectibleInstallmentAmount(
        array $row
    ): string {
        $financialInstallmentAmount = bcadd(
            $this->money(
                $row['principal_due']
            ),
            $this->money(
                $row['interest_due']
            ),
            self::MONEY_SCALE
        );

        return $this->roundWholeMoney(
            $financialInstallmentAmount
        );
    }

    /**
     * Amount ko nearest whole rupee me round karo
     * aur database ke liye two-decimal format return karo.
     */
    private function roundWholeMoney(
        mixed $value
    ): string {
        return number_format(
            round(
                (float) $value,
                0,
                PHP_ROUND_HALF_UP
            ),
            self::MONEY_SCALE,
            '.',
            ''
        );
    }

    /**
     * Normalize value to a two-decimal money string.
     */
    private function money(
        mixed $value
    ): string {
        return number_format(
            round(
                (float) $value,
                self::MONEY_SCALE
            ),
            self::MONEY_SCALE,
            '.',
            ''
        );
    }

    /**
     * Verify aggregate schedule money against source summary.
     */
    private function assertMoneyMatches(
        string $field,
        string $expected,
        string $actual,
        int $amortizationId
    ): void {
        $difference = bcsub(
            $expected,
            $actual,
            self::MONEY_SCALE
        );

        if (
            bccomp(
                $difference,
                '0.00',
                self::MONEY_SCALE
            ) === -1
        ) {
            $difference = bcmul(
                $difference,
                '-1',
                self::MONEY_SCALE
            );
        }

        if (
            bccomp(
                $difference,
                self::ROUNDING_TOLERANCE,
                self::MONEY_SCALE
            ) === 1
        ) {
            throw new RepaymentActivationException(
                message: sprintf(
                    'Schedule %s total does not match amortization summary.',
                    $field
                ),
                context: [
                    'amortization_id' => $amortizationId,

                    'field' => $field,

                    'expected' => $expected,

                    'actual' => $actual,

                    'difference' => $difference,

                    'activation_stage' =>
                        'SCHEDULE_SUMMARY_VALIDATION',
                ]
            );
        }
    }
}

