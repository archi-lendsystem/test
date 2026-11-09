<?php

namespace Modules\Repayment\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Repayment\Enums\InstallmentPaymentStatus;
use Modules\Repayment\Enums\StatusHistorySource;
use Modules\Repayment\Enums\StatusHistoryType;
use Modules\Repayment\Models\RepaymentInstallment;
use Modules\Repayment\Models\RepaymentStatusHistory;
use Modules\Repayment\Services\RepaymentInstallmentDueStatusSyncService;

/** Safe one-time correction of historical fully paid EMI due statuses. */
class BackfillPaidRepaymentDueStatusesCommand extends Command
{
    protected $signature = 'repayment:backfill-paid-due-statuses {--apply : Write to database; otherwise preview only}';
    protected $description = 'Mark fully paid EMIs PAID_ON_TIME/PAID_LATE using transaction received dates';

    public function handle(RepaymentInstallmentDueStatusSyncService $sync): int
    {
        $query = RepaymentInstallment::query()
            ->where('payment_status', InstallmentPaymentStatus::PAID->value)
            ->whereRaw('ROUND(outstanding_amount, 0) = 0');

        $apply = (bool) $this->option('apply');
        $examined = 0;
        $wouldChange = 0;
        $updated = 0;
        $newStatuses = [];

        $query->select('id')->orderBy('id')->chunkById(100, function ($rows) use (
            $sync, $apply, &$examined, &$wouldChange, &$updated, &$newStatuses
        ): void {
            foreach ($rows as $row) {
                $worker = function () use ($row, $sync, $apply, &$examined, &$wouldChange, &$updated, &$newStatuses): void {
                    $installment = RepaymentInstallment::query()
                        ->whereKey($row->id)
                        ->when($apply, fn ($q) => $q->lockForUpdate())
                        ->first();
                    if (! $installment
                        || $installment->payment_status !== InstallmentPaymentStatus::PAID
                        || $installment->hasOutstanding()) {
                        return;
                    }

                    $examined++;
                    $new = $sync->paidDueStatus($installment);
                    $old = $installment->due_status;
                    if ($old === $new) {
                        return;
                    }

                    $wouldChange++;
                    $newStatuses[$new->value] = ($newStatuses[$new->value] ?? 0) + 1;
                    if (! $apply) {
                        return;
                    }

                    $installment->forceFill(['due_status' => $new->value])->save();
                    RepaymentStatusHistory::query()->create([
                        'repayment_account_id' => $installment->repayment_account_id,
                        'repayment_installment_id' => $installment->id,
                        'repayment_transaction_id' => null,
                        'status_type' => StatusHistoryType::DUE_STATUS->value,
                        'old_status' => $old->value,
                        'new_status' => $new->value,
                        'source' => StatusHistorySource::SYSTEM->value,
                        'reason' => 'One-time correction of fully paid EMI using effective received_date.',
                        'metadata' => ['backfill' => true, 'source' => 'POSTED PAYMENT received_date'],
                        'changed_by' => null,
                        'changed_at' => now(),
                    ]);
                    $updated++;
                };

                if ($apply) {
                    DB::transaction($worker, 3);
                } else {
                    $worker();
                }
            }
        });

        $this->info('Paid EMIs checked: '.$examined);
        $this->info(($apply ? 'Updated: ' : 'Would change: ').($apply ? $updated : $wouldChange));
        foreach ($newStatuses as $status => $count) {
            $this->line($status.': '.$count);
        }
        if (! $apply) {
            $this->warn('DRY RUN. Back up DB and test on staging before running with --apply.');
        }
        return self::SUCCESS;
    }
}
