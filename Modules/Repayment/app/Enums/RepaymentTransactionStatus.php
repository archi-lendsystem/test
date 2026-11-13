<?php

namespace Modules\Repayment\Enums;

enum RepaymentTransactionStatus: string
{
    /**
     * Transaction record created hai, lekin posting
     * aur allocation process abhi complete nahi hua.
     */
    case PENDING = 'PENDING';

    /**
     * Transaction successfully account balances aur
     * installments par apply ho chuki hai.
     */
    case POSTED = 'POSTED';

    /**
     * Previously posted transaction ko separate reversal
     * transaction ke through reverse kiya gaya.
     */
    case REVERSED = 'REVERSED';

    /**
     * Transaction processing fail hui aur financial
     * balances par apply nahi hui.
     */
    case FAILED = 'FAILED';

    /**
     * Check whether transaction posting process start ho sakta hai.
     */
    public function canBePosted(): bool
    {
        return $this === self::PENDING;
    }

    /**
     * Check whether transaction account ledger mein posted hai.
     */
    public function isPosted(): bool
    {
        return $this === self::POSTED;
    }

    /**
     * Only successfully posted transaction can be reversed.
     */
    public function canBeReversed(): bool
    {
        return $this === self::POSTED;
    }

    /**
     * Check whether transaction processing has failed.
     */
    public function hasFailed(): bool
    {
        return $this === self::FAILED;
    }

    /**
     * Check whether transaction can no longer be processed.
     */
    public function isTerminal(): bool
    {
        return in_array($this, [
            self::REVERSED,
            self::FAILED,
        ], true);
    }

    /**
     * Human-readable label for API and frontend.
     */
    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pending',
            self::POSTED => 'Posted',
            self::REVERSED => 'Reversed',
            self::FAILED => 'Failed',
        };
    }
}