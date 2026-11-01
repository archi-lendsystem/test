<?php

namespace Modules\Repayment\Services;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use Illuminate\Support\Str;

final class RepaymentTransactionNumberGenerator
{
    private const TEMPORARY_PREFIX = 'TMP-';

    private const FINAL_PREFIX = 'RPT-';

    /**
     * Generate a temporary unique number for the initial insert.
     *
     * Example:
     * TMP-550E8400-E29B-41D4-A716-446655440000
     */
    public function generateTemporary(): string
    {
        return self::TEMPORARY_PREFIX
            . strtoupper((string) Str::uuid());
    }

    /**
     * Generate the final public transaction number after
     * the database transaction ID becomes available.
     *
     * Example:
     * RPT-20260723-0000000123
     */
    public function generateFinal(
        int $transactionId,
        DateTimeInterface|string $businessDate
    ): string {
        if ($transactionId < 1) {
            throw new InvalidArgumentException(
                'Transaction ID must be greater than zero.'
            );
        }

        $datePart = $this->resolveDatePart($businessDate);

        /*
         * Numeric database ID provides global uniqueness.
         *
         * %010d gives at least 10 digits but automatically
         * expands when the ID becomes larger.
         */
        $transactionNumber = sprintf(
            '%s%s-%010d',
            self::FINAL_PREFIX,
            $datePart,
            $transactionId
        );

        if (strlen($transactionNumber) > 60) {
            throw new InvalidArgumentException(
                'Generated transaction number exceeds 60 characters.'
            );
        }

        return $transactionNumber;
    }

    /**
     * Convert the received/value date into YYYYMMDD.
     */
    private function resolveDatePart(
        DateTimeInterface|string $businessDate
    ): string {
        if ($businessDate instanceof DateTimeInterface) {
            return $businessDate->format('Ymd');
        }

        $businessDate = trim($businessDate);

        $date = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $businessDate
        );

        $errors = DateTimeImmutable::getLastErrors();

        $containsErrors = is_array($errors)
            && (
                $errors['warning_count'] > 0
                || $errors['error_count'] > 0
            );

        if (
            ! $date
            || $containsErrors
            || $date->format('Y-m-d') !== $businessDate
        ) {
            throw new InvalidArgumentException(
                'Business date must be a valid YYYY-MM-DD date.'
            );
        }

        return $date->format('Ymd');
    }
}