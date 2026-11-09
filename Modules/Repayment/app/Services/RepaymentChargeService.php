<?php

namespace Modules\Repayment\Services;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Modules\Repayment\Enums\AllocationComponent;
use Modules\Repayment\Enums\InstallmentPaymentStatus;
use Modules\Repayment\Enums\RepaymentChargeStatus;
use Modules\Repayment\Enums\RepaymentChargeType;
use Modules\Repayment\Enums\RepaymentTransactionStatus;
use Modules\Repayment\Enums\RepaymentTransactionType;
use Modules\Repayment\Models\RepaymentAccount;
use Modules\Repayment\Models\RepaymentAllocation;
use Modules\Repayment\Models\RepaymentCharge;
use Modules\Repayment\Models\RepaymentTransaction;

class RepaymentChargeService
{
    public function __construct(
        private readonly RepaymentTransactionNumberGenerator $transactionNumberGenerator
    ) {}
    /**
     * Levy a flat bounce/mandate-return charge (base + GST).
     */
    public function levyBounceCharge(
        RepaymentAccount $account,
        ?RepaymentTransaction $transaction = null,
        ?int $installmentId = null,
        ?string $reason = null,
        ?int $leviedBy = null
    ): RepaymentCharge {
        $gstPercentage = (float) config('repayment.charges.gst_percentage', 18);
        $baseAmount = (float) config('repayment.charges.bounce_amount', 500);
        $gstAmount = round($baseAmount * $gstPercentage / 100, 2);
        $totalAmount = round($baseAmount + $gstAmount, 2);

        return RepaymentCharge::create([
            'repayment_account_id' => $account->id,
            'repayment_installment_id' => $installmentId,
            'repayment_transaction_id' => $transaction?->id,
            'charge_type' => RepaymentChargeType::BOUNCE->value,
            'charge_date' => now()->toDateString(),
            'base_amount' => $baseAmount,
            'gst_percentage' => $gstPercentage,
            'gst_amount' => $gstAmount,
            'total_amount' => $totalAmount,
            'waived_amount' => 0,
            'net_payable' => $totalAmount,
            'status' => RepaymentChargeStatus::PENDING->value,
            'reason' => $reason ?? 'Payment instrument bounced.',
            'levied_by' => $leviedBy,
        ]);
    }

    /**
     * Levy a penal charge on an account's oldest overdue installment:
     * penal_percentage_per_period % of the overdue amount, for every full
     * penal_period_days the installment has been overdue (minimum one
     * period), plus GST on that.
     *
     * Returns null if the account currently has nothing overdue, or if a
     * penal charge for this installment was already levied today.
     */
    public function levyPenalCharge(
        RepaymentAccount $account,
        ?int $leviedBy = null
    ): ?RepaymentCharge {
        $oldestUnpaid = $account->installments()
            ->whereRaw(
                'ROUND(outstanding_amount, 0) > 0'
            )
            ->orderBy('due_date')
            ->orderBy('installment_number')
            ->first();

        if (!$oldestUnpaid) {
            return null;
        }

        $asOf = CarbonImmutable::now(config('app.timezone'))->startOfDay();
        $dueDate = CarbonImmutable::parse($oldestUnpaid->due_date, config('app.timezone'))->startOfDay();

        if (!$dueDate->isBefore($asOf)) {
            return null;
        }

        $alreadyLeviedToday = RepaymentCharge::query()
            ->where('repayment_account_id', $account->id)
            ->where('repayment_installment_id', $oldestUnpaid->id)
            ->where('charge_type', RepaymentChargeType::PENAL->value)
            ->where('charge_date', $asOf->toDateString())
            ->exists();

        if ($alreadyLeviedToday) {
            return null;
        }

        $daysOverdue = $dueDate->diffInDays($asOf);
        $periodDays = max(1, (int) config('repayment.charges.penal_period_days', 30));
        $periods = max(1, (int) ceil($daysOverdue / $periodDays));

        $percentage = $this->penalPercentageFor($account);
        $gstPercentage = (float) config('repayment.charges.gst_percentage', 18);

        $overdueBase = (float) $this->roundWholeMoney(
            $oldestUnpaid->outstanding_amount
        );
        $baseAmount = round($overdueBase * $percentage / 100 * $periods, 2);
        $gstAmount = round($baseAmount * $gstPercentage / 100, 2);
        $totalAmount = round($baseAmount + $gstAmount, 2);

        return RepaymentCharge::create([
            'repayment_account_id' => $account->id,
            'repayment_installment_id' => $oldestUnpaid->id,
            'charge_type' => RepaymentChargeType::PENAL->value,
            'charge_date' => $asOf->toDateString(),
            'base_amount' => $baseAmount,
            'gst_percentage' => $gstPercentage,
            'gst_amount' => $gstAmount,
            'total_amount' => $totalAmount,
            'waived_amount' => 0,
            'net_payable' => $totalAmount,
            'status' => RepaymentChargeStatus::PENDING->value,
            'reason' => "Penal charge for {$daysOverdue} day(s) overdue ({$periods} period(s) at {$percentage}% per {$periodDays} days).",
            'metadata' => [
                'days_overdue' => $daysOverdue,
                'periods' => $periods,
                'percentage_per_period' => $percentage,
                'period_days' => $periodDays,
                'overdue_base_amount' => $overdueBase,
            ],
            'levied_by' => $leviedBy,
        ]);
    }

    /**
     * Levy a foreclosure (pre-closure) charge — either a percentage of the
     * account's current principal outstanding, or a flat amount, whichever
     * the caller picks — plus GST on that.
     */
    public function levyForeclosureCharge(
        RepaymentAccount $account,
        string $basis,
        float $value,
        ?string $reason = null,
        ?int $leviedBy = null,
        ?string $chargeDate = null
    ): RepaymentCharge {
        if (!in_array($basis, ['PERCENTAGE', 'AMOUNT'], true)) {
            throw new InvalidArgumentException('Foreclosure charge basis must be PERCENTAGE or AMOUNT.');
        }

        if ($value < 0) {
            throw new InvalidArgumentException('Foreclosure charge value cannot be negative.');
        }

        $gstPercentage = (float) config('repayment.charges.gst_percentage', 18);
        $principalOutstanding = (float) $account->principal_outstanding;

        $baseAmount = $basis === 'PERCENTAGE'
            ? round($principalOutstanding * $value / 100, 2)
            : round($value, 2);

        $gstAmount = round($baseAmount * $gstPercentage / 100, 2);
        $totalAmount = round($baseAmount + $gstAmount, 2);

        return RepaymentCharge::create([
            'repayment_account_id' => $account->id,
            'charge_type' => RepaymentChargeType::FORECLOSURE->value,
            'charge_date' => $chargeDate ?: now()->toDateString(),
            'base_amount' => $baseAmount,
            'gst_percentage' => $gstPercentage,
            'gst_amount' => $gstAmount,
            'total_amount' => $totalAmount,
            'waived_amount' => 0,
            'net_payable' => $totalAmount,
            'status' => RepaymentChargeStatus::PENDING->value,
            'reason' => $reason ?? ($basis === 'PERCENTAGE'
                ? "Foreclosure charge: {$value}% of outstanding principal ({$principalOutstanding})."
                : "Foreclosure charge: flat amount."),
            'metadata' => [
                'basis' => $basis,
                'value' => $value,
                'principal_outstanding' => $principalOutstanding,
            ],
            'levied_by' => $leviedBy,
        ]);
    }

    /**
     * Waive some or all of a charge's still-payable amount.
     */
    public function waive(
        RepaymentCharge $charge,
        float $waiveAmount,
        string $reason,
        ?int $waivedBy = null
    ): RepaymentCharge {
        if ($waiveAmount <= 0) {
            throw new InvalidArgumentException('Waiver amount must be greater than zero.');
        }

        $maxWaivable = round((float) $charge->total_amount - (float) $charge->waived_amount, 2);

        if ($waiveAmount > $maxWaivable) {
            throw new InvalidArgumentException('Waiver amount exceeds the outstanding charge amount.');
        }

        $newWaivedAmount = round((float) $charge->waived_amount + $waiveAmount, 2);
        $netPayable = max(0, round((float) $charge->total_amount - $newWaivedAmount, 2));

        $status = $netPayable <= 0
            ? RepaymentChargeStatus::WAIVED
            : RepaymentChargeStatus::PARTIALLY_WAIVED;

        $charge->update([
            'waived_amount' => $newWaivedAmount,
            'net_payable' => $netPayable,
            'status' => $status->value,
            'waived_by' => $waivedBy,
            'waived_at' => now(),
            'waiver_reason' => $reason,
        ]);

        return $charge->fresh();
    }

    /**
     * Pay off every remaining installment on an account in full — the
     * ledger-settlement half of a foreclosure. A real NBFC foreclosure means
     * the customer pays off 100% of the remaining principal + due interest
     * (on top of the separately-levied foreclosure fee) in one go; the loan
     * doesn't just flip to CLOSED with balances still sitting non-zero.
     *
     * Caller is responsible for wrapping this in a transaction with the
     * account row locked.
     */
    public function payOffOutstandingBalance(
        RepaymentAccount $account,
        string $reason,
        ?int $actorId = null
    ): RepaymentTransaction {
        $installments = $account->installments()
            ->whereRaw(
                'ROUND(outstanding_amount, 0) > 0'
            )
            ->orderBy('due_date')
            ->orderBy('installment_number')
            ->lockForUpdate()
            ->get();

        /*
         * Foreclosure payoff must use the same rounded installment
         * collectible as normal repayment posting.
         */
        $payoffAmount = '0.00';

        foreach ($installments as $installment) {
            $payoffAmount = bcadd(
                $payoffAmount,
                $this->roundWholeMoney(
                    $installment->outstanding_amount
                ),
                2
            );
        }

        $postingTimestamp = now();

        $transaction = RepaymentTransaction::create([
            'repayment_account_id' => $account->id,
            'transaction_number' => $this->transactionNumberGenerator->generateTemporary(),
            'idempotency_key' => 'FORECLOSURE_PAYOFF_' . $account->uuid . '_' . $postingTimestamp->timestamp,
            'transaction_type' => RepaymentTransactionType::ADJUSTMENT->value,
            'amount' => $payoffAmount,
            'allocated_amount' => $payoffAmount,
            'unallocated_amount' => '0.00',
            'received_date' => $postingTimestamp->toDateString(),
            'value_date' => $postingTimestamp->toDateString(),
            'status' => RepaymentTransactionStatus::POSTED->value,
            'posted_at' => $postingTimestamp,
            'remarks' => $reason,
            'created_by' => $actorId,
            'approved_by' => $actorId,
        ]);

        $transaction->transaction_number = $this->transactionNumberGenerator->generateFinal(
            (int) $transaction->id,
            $postingTimestamp
        );
        $transaction->save();

        $allocationOrder = 1;

        foreach ($installments as $installment) {
            $collectible = $this->roundWholeMoney(
                $installment->outstanding_amount
            );

            $remainingInterest = bcsub(
                (string) $installment->interest_due,
                (string) $installment->interest_paid,
                2
            );

            if (
                bccomp(
                    $remainingInterest,
                    '0.00',
                    2
                ) === -1
            ) {
                $remainingInterest = '0.00';
            }

            if (
                bccomp(
                    $remainingInterest,
                    $collectible,
                    2
                ) === 1
            ) {
                $remainingInterest = $collectible;
            }

            $remainingPrincipal = bcsub(
                $collectible,
                $remainingInterest,
                2
            );

            if (bccomp($remainingInterest, '0.00', 2) === 1) {
                RepaymentAllocation::create([
                    'repayment_transaction_id' => $transaction->id,
                    'repayment_installment_id' => $installment->id,
                    'component' => AllocationComponent::INTEREST->value,
                    'amount' => $remainingInterest,
                    'allocation_order' => $allocationOrder++,
                    'metadata' => ['reason' => 'foreclosure_payoff'],
                ]);
            }

            if (bccomp($remainingPrincipal, '0.00', 2) === 1) {
                RepaymentAllocation::create([
                    'repayment_transaction_id' => $transaction->id,
                    'repayment_installment_id' => $installment->id,
                    'component' => AllocationComponent::PRINCIPAL->value,
                    'amount' => $remainingPrincipal,
                    'allocation_order' => $allocationOrder++,
                    'metadata' => ['reason' => 'foreclosure_payoff'],
                ]);
            }

            $installment->forceFill([
                'principal_paid' => bcadd(
                    (string) $installment->principal_paid,
                    $remainingPrincipal,
                    2
                ),
                'interest_paid' => bcadd(
                    (string) $installment->interest_paid,
                    $remainingInterest,
                    2
                ),
                'outstanding_amount' => '0.00',
                'payment_status' => InstallmentPaymentStatus::PAID->value,
                'last_payment_at' => $postingTimestamp,
                'fully_paid_at' => $postingTimestamp,
            ])->save();
        }

        $account->forceFill([
            'principal_outstanding' => '0.00',
            'interest_outstanding' => '0.00',
            'total_outstanding' => '0.00',
            'overdue_amount' => '0.00',
            'total_received' => bcadd((string) $account->total_received, $payoffAmount, 2),
            'last_payment_at' => $postingTimestamp,
            'updated_by' => $actorId,
        ])->save();

        // Account-status transition (e.g. ACTIVE -> CLOSED) and its history
        // entry are the caller's responsibility — this method only settles
        // the ledger, it doesn't decide what the account's new status is.

        return $transaction;
    }

    /**
     * Round collectible/outstanding to a whole rupee while preserving
     * two-decimal database formatting.
     */
    private function roundWholeMoney(mixed $value): string
    {
        return number_format(
            round(
                (float) ($value ?? 0),
                0,
                PHP_ROUND_HALF_UP
            ),
            2,
            '.',
            ''
        );
    }

    /**
     * The penal rate is a per-product-scheme term (ProductScheme.lpf_rate —
     * "late payment fee rate"), not a single app-wide number. Falls back to
     * the config default only when the loan's scheme can't be resolved
     * (e.g. legacy data), so nothing breaks — it just stops being
     * scheme-accurate for that one account.
     */
    private function penalPercentageFor(RepaymentAccount $account): float
    {
        $lpfRate = $account->lead?->schemeSelect?->productScheme?->lpf_rate;

        return $lpfRate !== null
            ? (float) $lpfRate
            : (float) config('repayment.charges.penal_percentage_per_period', 3);
    }
}