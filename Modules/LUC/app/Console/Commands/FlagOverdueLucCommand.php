<?php

namespace Modules\LUC\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\BLApplicantDetails\Models\BLApplicantDetails;
use Modules\BLDisbursment\Models\BLDisbursment;
use Modules\BLLeadCreation\Models\BLLeadCreation;
use Modules\LUC\Models\LucReport;
use Modules\Notification\Notifications\LucOverdueNotification;
use Modules\UserManagement\Models\User;

class FlagOverdueLucCommand extends Command
{
    protected $signature = 'luc:flag-overdue';

    protected $description = "Notify the branch manager and lead creator when a loan's LUC is past due and still not filed.";

    public function handle(): int
    {
        $dueAfterDays = (int) config('luc.due_after_days', 15);
        $timezone = config('app.timezone', 'UTC');
        $cutoff = CarbonImmutable::now($timezone)->subDays($dueAfterDays)->toDateString();
        $today = CarbonImmutable::now($timezone)->toDateString();

        $reportedLeadUuids = LucReport::query()->pluck('lead_uuid');

        $overdueDisbursements = BLDisbursment::query()
            ->whereDate('disbursement_date', '<=', $cutoff)
            ->whereNotIn('lead_uuid', $reportedLeadUuids)
            ->get(['lead_uuid', 'disbursement_date', 'loan_number']);

        $flagged = 0;
        $notified = 0;

        foreach ($overdueDisbursements as $disbursement) {
            $lead = BLLeadCreation::query()->where('uuid', $disbursement->lead_uuid)->first();

            if (!$lead) {
                continue;
            }

            $flagged++;

            // One notification per lead per day, not one per scheduler tick.
            $alreadyNotifiedToday = DB::table('notifications')
                ->where('type', LucOverdueNotification::class)
                ->where('data->lead_uuid', $lead->uuid)
                ->whereDate('created_at', $today)
                ->exists();

            if ($alreadyNotifiedToday) {
                continue;
            }

            $dueDate = CarbonImmutable::parse($disbursement->disbursement_date, $timezone)->addDays($dueAfterDays);
            $daysOverdue = (int) $dueDate->diffInDays(CarbonImmutable::now($timezone));

            $recipients = $this->resolveRecipients($lead);

            foreach ($recipients as $recipient) {
                $recipient->notify(new LucOverdueNotification(
                    leadUuid: $lead->uuid,
                    leadId: $lead->lead_id,
                    borrowerName: $this->borrowerName($lead),
                    daysOverdue: $daysOverdue,
                ));
            }

            if ($recipients->isNotEmpty()) {
                $notified++;
            }
        }

        $this->info("LUC overdue sweep complete: {$flagged} loan(s) overdue, {$notified} notified today.");

        return self::SUCCESS;
    }

    private function resolveRecipients(BLLeadCreation $lead)
    {
        $recipients = collect();

        if ($lead->branch_id) {
            $recipients = $recipients->merge(
                User::query()
                    ->whereHas('roles', fn ($q) => $q->where('name', 'branch_manager'))
                    ->where('branch_id', $lead->branch_id)
                    ->get()
            );
        }

        if ($lead->created_by) {
            $creator = User::find($lead->created_by);
            if ($creator) {
                $recipients->push($creator);
            }
        }

        return $recipients->unique('id');
    }

    private function borrowerName(BLLeadCreation $lead): string
    {
        $applicant = BLApplicantDetails::query()
            ->where('lead_uuid', $lead->uuid)
            ->where(function ($query) {
                $query->where('is_co_applicant', 0)->orWhereNull('is_co_applicant');
            })
            ->orderBy('applicant_sno')
            ->orderBy('id')
            ->first();

        return $applicant?->full_name ?? $lead->applicant_name ?? 'Borrower';
    }
}
