<?php

namespace Modules\Repayment\Enums;

enum PaymentMode: string
{
    /**
     * Physical cash collection.
     */
    case CASH = 'CASH';

    /**
     * Cheque-based payment.
     */
    case CHEQUE = 'CHEQUE';

    /**
     * Unified Payments Interface.
     */
    case UPI = 'UPI';

    /**
     * National Electronic Funds Transfer.
     */
    case NEFT = 'NEFT';

    /**
     * Real Time Gross Settlement.
     */
    case RTGS = 'RTGS';

    /**
     * Immediate Payment Service.
     */
    case IMPS = 'IMPS';

    /**
     * Automated bank mandate collection.
     */
    case NACH = 'NACH';

    /**
     * Generic bank transfer where specific transfer
     * rail is unavailable or not captured.
     */
    case BANK_TRANSFER = 'BANK_TRANSFER';

    /**
     * Payment collected through an integrated
     * online payment gateway.
     */
    case PAYMENT_GATEWAY = 'PAYMENT_GATEWAY';

    /**
     * Whether this mode normally requires an external
     * payment or instrument reference.
     *
     * Cash collection may not have a banking reference.
     */
    public function requiresReference(): bool
    {
        return $this !== self::CASH;
    }

    /**
     * Whether payment is received through an
     * electronic collection channel.
     */
    public function isElectronic(): bool
    {
        return in_array($this, [
            self::UPI,
            self::NEFT,
            self::RTGS,
            self::IMPS,
            self::NACH,
            self::BANK_TRANSFER,
            self::PAYMENT_GATEWAY,
        ], true);
    }

    /**
     * Whether physical instrument handling is required.
     */
    public function isPhysicalInstrument(): bool
    {
        return in_array($this, [
            self::CASH,
            self::CHEQUE,
        ], true);
    }

    /**
     * Human-readable label for API and frontend.
     */
    public function label(): string
    {
        return match ($this) {
            self::CASH => 'Cash',
            self::CHEQUE => 'Cheque',
            self::UPI => 'UPI',
            self::NEFT => 'NEFT',
            self::RTGS => 'RTGS',
            self::IMPS => 'IMPS',
            self::NACH => 'NACH',
            self::BANK_TRANSFER => 'Bank Transfer',
            self::PAYMENT_GATEWAY => 'Payment Gateway',
        };
    }
}