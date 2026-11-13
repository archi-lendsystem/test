<?php

namespace Modules\Repayment\Services;

use Carbon\CarbonImmutable;
use Modules\Repayment\Exceptions\RepaymentActivationException;
use Throwable;

final class AmortizationScheduleNormalizer
{
    private const MONEY_SCALE = 2;

    private const ROUNDING_TOLERANCE = '0.01';

    /**
     * Validate and normalize the complete amortization schedule.
     *
     * @return array<int, array<string, mixed>>
     */
    public function normalize(
        mixed $schedule,
        int $amortizationId
    ): array {
        if (! is_array($schedule) || $schedule === []) {
            throw RepaymentActivationException::scheduleMissing(
                $amortizationId
            );
        }

        $normalizedRows = [];

        $previousDueDate = null;
        $previousClosingPrincipal = null;

        foreach (array_values($schedule) as $index => $row) {
            $rowNumber = $index + 1;

            if (! is_array($row)) {
                throw RepaymentActivationException::invalidScheduleRow(
                    $rowNumber,
                    'schedule row must be an array'
                );
            }

            $normalizedRow = $this->normalizeRow(
                row: $row,
                rowNumber: $rowNumber
            );

            $this->validateScheduleContinuity(
                row: $normalizedRow,
                rowNumber: $rowNumber,
                previousDueDate: $previousDueDate,
                previousClosingPrincipal: $previousClosingPrincipal
            );

            $normalizedRows[] = $normalizedRow;

            $previousDueDate = $normalizedRow['due_date'];
            $previousClosingPrincipal =
                $normalizedRow['closing_principal'];
        }

        return $normalizedRows;
    }

    /**
     * Normalize an individual amortization schedule row.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeRow(
        array $row,
        int $rowNumber
    ): array {
        $installmentNumber = $this->positiveInteger(
            value: $row['installment_no'] ?? null,
            field: 'installment_no',
            rowNumber: $rowNumber
        );

        if ($installmentNumber !== $rowNumber) {
            throw RepaymentActivationException::invalidScheduleRow(
                $rowNumber,
                sprintf(
                    'installment_no must be %d, received %d',
                    $rowNumber,
                    $installmentNumber
                )
            );
        }

        $dueDate = $this->date(
            value: $row['payment_date'] ?? null,
            field: 'payment_date',
            rowNumber: $rowNumber
        );

        $openingPrincipal = $this->money(
            value: $row['opening_balance'] ?? null,
            field: 'opening_balance',
            rowNumber: $rowNumber
        );

        $installmentAmount = $this->money(
            value: $row['installment_amount'] ?? null,
            field: 'installment_amount',
            rowNumber: $rowNumber,
            mustBePositive: true
        );

        $principalDue = $this->money(
            value: $row['principal'] ?? null,
            field: 'principal',
            rowNumber: $rowNumber
        );

        $interestDue = $this->money(
            value: $row['interest'] ?? null,
            field: 'interest',
            rowNumber: $rowNumber
        );

        $closingPrincipal = $this->money(
            value: $row['closing_balance'] ?? null,
            field: 'closing_balance',
            rowNumber: $rowNumber
        );

        $componentTotal = bcadd(
            $principalDue,
            $interestDue,
            self::MONEY_SCALE
        );

        /*
         * BL amortization may expose installment_amount rounded to the
         * nearest whole rupee while principal and interest remain exact
         * to two decimal places.
         *
         * Repayment accepts either the exact component total or the
         * legitimate nearest whole-rupee source amount. The source
         * installment_amount itself remains unchanged.
         */
        $roundedComponentTotal = number_format(
            round(
                (float) $componentTotal,
                0,
                PHP_ROUND_HALF_UP
            ),
            self::MONEY_SCALE,
            '.',
            ''
        );

        $matchesExactAmount = $this->moneyEquals(
            $componentTotal,
            $installmentAmount
        );

        $matchesRoundedAmount = bccomp(
            $roundedComponentTotal,
            $installmentAmount,
            self::MONEY_SCALE
        ) === 0;

        if (
            ! $matchesExactAmount
            && ! $matchesRoundedAmount
        ) {
            throw RepaymentActivationException::invalidScheduleRow(
                $rowNumber,
                sprintf(
                    'principal plus interest must equal installment_amount or its nearest whole-rupee value; calculated %s, rounded %s, received %s',
                    $componentTotal,
                    $roundedComponentTotal,
                    $installmentAmount
                )
            );
        }

        $calculatedClosingPrincipal = bcsub(
            $openingPrincipal,
            $principalDue,
            self::MONEY_SCALE
        );

        if (! $this->moneyEquals(
            $calculatedClosingPrincipal,
            $closingPrincipal
        )) {
            throw RepaymentActivationException::invalidScheduleRow(
                $rowNumber,
                sprintf(
                    'opening_balance minus principal must equal closing_balance; calculated %s, received %s',
                    $calculatedClosingPrincipal,
                    $closingPrincipal
                )
            );
        }

        if (
            bccomp(
                $closingPrincipal,
                $openingPrincipal,
                self::MONEY_SCALE
            ) === 1
        ) {
            throw RepaymentActivationException::invalidScheduleRow(
                $rowNumber,
                'closing_balance cannot exceed opening_balance'
            );
        }

        return [
            'installment_number' => $installmentNumber,
            'due_date' => $dueDate,

            'opening_principal' => $openingPrincipal,
            'principal_due' => $principalDue,
            'interest_due' => $interestDue,
            'installment_amount' => $installmentAmount,
            'financial_installment_amount' => $componentTotal,
            'closing_principal' => $closingPrincipal,

            /*
             * Original source row preserved for audit.
             */
            'schedule_snapshot' => $row,
        ];
    }

    /**
     * Validate continuity between consecutive schedule rows.
     *
     * @param array<string, mixed> $row
     */
    private function validateScheduleContinuity(
        array $row,
        int $rowNumber,
        ?string $previousDueDate,
        ?string $previousClosingPrincipal
    ): void {
        if (
            $previousDueDate !== null
            && $row['due_date'] <= $previousDueDate
        ) {
            throw RepaymentActivationException::invalidScheduleRow(
                $rowNumber,
                'payment_date must be later than the previous installment date'
            );
        }

        if (
            $previousClosingPrincipal !== null
            && ! $this->moneyEquals(
                $previousClosingPrincipal,
                $row['opening_principal']
            )
        ) {
            throw RepaymentActivationException::invalidScheduleRow(
                $rowNumber,
                sprintf(
                    'opening_balance must equal previous closing_balance; expected %s, received %s',
                    $previousClosingPrincipal,
                    $row['opening_principal']
                )
            );
        }
    }

    /**
     * Normalize a positive integer field.
     */
    private function positiveInteger(
        mixed $value,
        string $field,
        int $rowNumber
    ): int {
        if (
            filter_var(
                $value,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]
            ) === false
        ) {
            throw RepaymentActivationException::invalidScheduleRow(
                $rowNumber,
                "{$field} must be a positive integer"
            );
        }

        return (int) $value;
    }

    /**
     * Validate a strict YYYY-MM-DD date.
     */
    private function date(
        mixed $value,
        string $field,
        int $rowNumber
    ): string {
        if (! is_string($value) || trim($value) === '') {
            throw RepaymentActivationException::invalidScheduleRow(
                $rowNumber,
                "{$field} is required"
            );
        }

        $value = trim($value);

        try {
            $date = CarbonImmutable::createFromFormat(
                '!Y-m-d',
                $value
            );
        } catch (Throwable) {
            $date = null;
        }

        if (
            $date === null
            || $date->format('Y-m-d') !== $value
        ) {
            throw RepaymentActivationException::invalidScheduleRow(
                $rowNumber,
                "{$field} must be a valid date in Y-m-d format"
            );
        }

        return $date->format('Y-m-d');
    }

    /**
     * Normalize a non-negative monetary value.
     */
    private function money(
        mixed $value,
        string $field,
        int $rowNumber,
        bool $mustBePositive = false
    ): string {
        if (
            (! is_string($value)
                && ! is_int($value)
                && ! is_float($value))
            || ! is_numeric($value)
        ) {
            throw RepaymentActivationException::invalidScheduleRow(
                $rowNumber,
                "{$field} must be numeric"
            );
        }

        $normalized = number_format(
            round((float) $value, self::MONEY_SCALE),
            self::MONEY_SCALE,
            '.',
            ''
        );

        if (
            bccomp(
                $normalized,
                '0.00',
                self::MONEY_SCALE
            ) === -1
        ) {
            throw RepaymentActivationException::invalidScheduleRow(
                $rowNumber,
                "{$field} cannot be negative"
            );
        }

        if (
            $mustBePositive
            && bccomp(
                $normalized,
                '0.00',
                self::MONEY_SCALE
            ) !== 1
        ) {
            throw RepaymentActivationException::invalidScheduleRow(
                $rowNumber,
                "{$field} must be greater than zero"
            );
        }

        return $normalized;
    }

    /**
     * Compare monetary values with one-paisa rounding tolerance.
     */
    private function moneyEquals(
        string $left,
        string $right
    ): bool {
        $difference = bcsub(
            $left,
            $right,
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

        return bccomp(
            $difference,
            self::ROUNDING_TOLERANCE,
            self::MONEY_SCALE
        ) <= 0;
    }
}