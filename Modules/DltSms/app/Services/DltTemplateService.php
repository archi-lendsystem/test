<?php

namespace Modules\DltSms\Services;

use RuntimeException;

class DltTemplateService
{
    /**
     * Customer Lending OTP template configuration key.
     */
    private const CUSTOMER_LENDING_OTP_TEMPLATE =
        'customer_lending_otp';

    /**
     * Staff Login OTP template configuration key.
     */
    private const STAFF_LOGIN_OTP_TEMPLATE =
        'staff_login_otp';

    /**
     * Render Customer Lending OTP SMS.
     *
     * Example:
     *
     * OTP:
     * 482157
     *
     * Result:
     * Thank you for your enquiry with Friendlysalary.com
     * Your OTP for enquiry verification code is 482157...
     *
     * @throws RuntimeException
     */
    public function renderCustomerLendingOtp(
        string $otp
    ): string {
        $otp = trim($otp);

        $this->validateOtp($otp);

        return $this->renderTemplate(
            self::CUSTOMER_LENDING_OTP_TEMPLATE,
            $otp
        );
    }

    /**
     * Get Customer Lending OTP expiry in minutes.
     *
     * The value should match the validity mentioned in the
     * approved DLT SMS template.
     *
     * @throws RuntimeException
     */
    public function getCustomerLendingOtpExpiryMinutes(): int
    {
        $expiryMinutes = (int) config(
            'dltsms.templates.'
            . self::CUSTOMER_LENDING_OTP_TEMPLATE
            . '.otp_expiry_minutes',
            5
        );

        if ($expiryMinutes <= 0) {
            throw new RuntimeException(
                'Customer Lending OTP expiry must be greater than zero.'
            );
        }

        return $expiryMinutes;
    }

    /**
     * Render Staff Login OTP SMS.
     *
     * @throws RuntimeException
     */
    public function renderStaffLoginOtp(
        string $otp
    ): string {
        $otp = trim($otp);

        $this->validateOtp($otp);

        return $this->renderTemplate(
            self::STAFF_LOGIN_OTP_TEMPLATE,
            $otp
        );
    }

    /**
     * Get Staff Login OTP expiry in minutes.
     *
     * The value should match the validity mentioned in the
     * approved DLT SMS template.
     *
     * @throws RuntimeException
     */
    public function getStaffLoginOtpExpiryMinutes(): int
    {
        $expiryMinutes = (int) config(
            'dltsms.templates.'
            . self::STAFF_LOGIN_OTP_TEMPLATE
            . '.otp_expiry_minutes',
            5
        );

        if ($expiryMinutes <= 0) {
            throw new RuntimeException(
                'Staff Login OTP expiry must be greater than zero.'
            );
        }

        return $expiryMinutes;
    }

    /**
     * Render a configured DLT template.
     *
     * @throws RuntimeException
     */
    private function renderTemplate(
        string $templateKey,
        string $value
    ): string {
        $configPath = 'dltsms.templates.'
            . $templateKey;

        $template = config(
            $configPath . '.message'
        );

        $placeholder = config(
            $configPath . '.placeholder'
        );

        if (
            ! is_string($template)
            || trim($template) === ''
        ) {
            throw new RuntimeException(
                sprintf(
                    'DLT template [%s] is not configured.',
                    $templateKey
                )
            );
        }

        if (
            ! is_string($placeholder)
            || trim($placeholder) === ''
        ) {
            throw new RuntimeException(
                sprintf(
                    'DLT placeholder for template [%s] is not configured.',
                    $templateKey
                )
            );
        }

        /*
         * The currently approved OTP template contains exactly
         * one DLT variable placeholder.
         *
         * If somebody accidentally changes the configured
         * template and adds/removes a placeholder, fail safely
         * instead of sending a DLT-invalid SMS.
         */
        $placeholderCount = substr_count(
            $template,
            $placeholder
        );

        if ($placeholderCount !== 1) {
            throw new RuntimeException(
                sprintf(
                    'DLT template [%s] must contain exactly one [%s] placeholder.',
                    $templateKey,
                    $placeholder
                )
            );
        }

        /*
         * Replace the approved DLT placeholder with the
         * actual OTP value.
         */
        $message = str_replace(
            $placeholder,
            $value,
            $template
        );

        /*
         * Defensive check.
         *
         * An unresolved DLT placeholder should never be sent
         * to the SMS provider.
         */
        if (
            str_contains(
                $message,
                $placeholder
            )
        ) {
            throw new RuntimeException(
                sprintf(
                    'Unable to resolve DLT template [%s].',
                    $templateKey
                )
            );
        }

        /*
         * Message must not be empty after rendering.
         */
        if (trim($message) === '') {
            throw new RuntimeException(
                sprintf(
                    'Rendered DLT template [%s] is empty.',
                    $templateKey
                )
            );
        }

        return $message;
    }

    /**
     * Validate OTP before inserting it into the approved
     * DLT message.
     *
     * Business Rule:
     *
     * - OTP must always contain exactly 6 digits.
     * - OTP must contain numeric characters only.
     *
     * Valid:
     *
     * 482157
     * 100001
     * 999999
     *
     * Invalid:
     *
     * 4821
     * 12345
     * 1234567
     * ABC123
     * 12 3456
     *
     * @throws RuntimeException
     */
    private function validateOtp(
        string $otp
    ): void {
        if ($otp === '') {
            throw new RuntimeException(
                'OTP cannot be empty.'
            );
        }

        if (
            ! preg_match(
                '/^\d{6}$/',
                $otp
            )
        ) {
            throw new RuntimeException(
                'OTP must contain exactly 6 numeric digits.'
            );
        }
    }
}