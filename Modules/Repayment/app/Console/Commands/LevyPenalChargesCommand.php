<?php

namespace Modules\Repayment\Console\Commands;

use Illuminate\Console\Command;
use Modules\Repayment\Enums\RepaymentAccountStatus;
use Modules\Repayment\Models\RepaymentAccount;
use Modules\Repayment\Services\RepaymentChargeService;
use Throwable;

/**
 * Penal charges were only ever levied when someone manually clicked
 * "Levy Penal Charge" per account — nothing accrued automatically, so
 * accounts sitting overdue for months showed zero penal charge. This
 * mirrors real NBFC practice: penal interest accrues on its own once an
 * installment goes overdue, it isn't an opt-in action.
 *
 * levyPenalCharge() is already idempotent per account/day, so running
 * this daily over every overdue account is safe.
 */
final class LevyPenalChargesCommand extends Command
{
    protected $signature = 'repayment:levy-penal-charges';

    protected $description = 'Automatically levy penal charges on every account with an overdue installment.';

    public function handle(RepaymentChargeService $chargeService): int
    {
        $accounts = RepaymentAccount::query()
            ->whereIn('status', [
                RepaymentAccountStatus::ACTIVE->value,
                RepaymentAccountStatus::SUSPENDED->value,
            ])
            ->where('overdue_amount', '>', 0)
            ->get();

        $levied = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($accounts as $account) {
            try {
                $charge = $chargeService->levyPenalCharge($account);

                if ($charge) {
                    $levied++;
                } else {
                    $skipped++;
                }
            } catch (Throwable $e) {
                $failed++;
                $this->components->error("Account {$account->uuid}: {$e->getMessage()}");
            }
        }

        $this->components->info("Penal charge sweep complete: {$levied} levied, {$skipped} skipped (already levied today / nothing overdue), {$failed} failed.");

        return self::SUCCESS;
    }
}
