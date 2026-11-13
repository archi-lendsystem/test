<?php

namespace Modules\Repayment\Services;

use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class RepaymentReceiptNumberGenerator
{
    private const TEMPORARY_PREFIX = 'TMP-RCP-';

    private const FINAL_PREFIX = 'RCP-';

    /**
     * Generate a unique temporary number for initial insert.
     *
     * Example:
     * TMP-RCP-550E8400-E29B-41D4-A716-446655440000
     */
    public function generateTemporary(): string
    {
        $receiptNumber = self::TEMPORARY_PREFIX
            . strtoupper((string) Str::uuid());

        $this->assertSupportedLength($receiptNumber);

        return $receiptNumber;
    }

    /**
     * Generate final receipt number after the receipt
     * database ID becomes available.
     *
     * Example:
     * RCP-20260723-0000000123
     */
    public function generateFinal(
        int $receiptId,
        DateTimeInterface|string $receiptDate
    ): string {
        if ($receiptId < 1) {
            throw new InvalidArgumentException(
                'Receipt ID must be greater than zero.'
            );
        }

        $datePart = $this->resolveDatePart($receiptDate);

        /*
         * Numeric receipt ID ensures database-wide uniqueness.
         */
        $receiptNumber = sprintf(
            '%s%s-%010d',
            self::FINAL_PREFIX,
            $datePart,
            $receiptId
        );

        $this->assertSupportedLength($receiptNumber);

        return $receiptNumber;
    }

    /**
     * Convert receipt date into YYYYMMDD.
     */
    private function resolveDatePart(
        DateTimeInterface|string $receiptDate
    ): string {
        if ($receiptDate instanceof DateTimeInterface) {
            return $receiptDate->format('Ymd');
        }

        $receiptDate = trim($receiptDate);

        $date = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $receiptDate
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
            || $date->format('Y-m-d') !== $receiptDate
        ) {
            throw new InvalidArgumentException(
                'Receipt date must be a valid YYYY-MM-DD date.'
            );
        }

        return $date->format('Ymd');
    }

    /**
     * Protect the receipt_number varchar(60) constraint.
     */
    private function assertSupportedLength(
        string $receiptNumber
    ): void {
        if (strlen($receiptNumber) > 60) {
            throw new InvalidArgumentException(
                'Generated receipt number exceeds 60 characters.'
            );
        }
    }
}