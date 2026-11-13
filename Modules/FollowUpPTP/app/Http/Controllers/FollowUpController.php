<?php

namespace Modules\FollowUpPTP\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\FollowUpPTP\Enums\FollowUpOutcome;
use Modules\FollowUpPTP\Enums\PtpStatus;
use Modules\FollowUpPTP\Models\FollowUp;
use Modules\Repayment\Enums\RepaymentAccountStatus;
use Modules\Repayment\Models\RepaymentAccount;
use Modules\UserManagement\Support\ReportBranchAccess;

class FollowUpController extends Controller
{
    use ApiResponse;

    /**
     * Customer-wise collections work list: every active loan, its DPD,
     * its latest follow-up, and its open PTP (if any).
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ptp_status' => 'nullable|string|in:none,pending,kept,partially_kept,broken',
            'overdue_only' => 'nullable|boolean',
            'branch_id' => 'nullable|integer|exists:organization_branches,id',
            'search' => 'nullable|string|max:255',
            'per_page' => 'nullable|integer|min:1|max:200',
        ]);

        $branchId = !empty($validated['branch_id'])
    ? (int) $validated['branch_id']
    : null;

        $asOf = CarbonImmutable::now(config('app.timezone'))->startOfDay();

        // $accounts = RepaymentAccount::query()
        //     ->whereIn('status', [
        //         RepaymentAccountStatus::ACTIVE->value,
        //         RepaymentAccountStatus::SUSPENDED->value,
        //     ])
        //     ->with([
        //         'installments' => fn ($q) => $q
        //             ->where('outstanding_amount', '>', 0)
        //             ->orderBy('due_date')
        //             ->orderBy('installment_number'),
        //         'lead:uuid,lead_id,branch_id',
        //         'lead.branch:id,name,branch_code',
        //         'lead.applicantDetails' => fn ($q) => $q
        //             ->where('applicant_sno', 0)
        //             ->select(['id', 'lead_uuid', 'full_name', 'alternate_contact_number']),
        //         'followUps' => fn ($q) => $q->orderByDesc('follow_up_date'),
        //     ])
        //     ->get();


        $accounts = ReportBranchAccess::scopeByRelation(
    RepaymentAccount::query(),
    $request->user(),
    'lead',
    $branchId
)
    ->whereIn('status', [
        RepaymentAccountStatus::ACTIVE->value,
        RepaymentAccountStatus::SUSPENDED->value,
    ])
    ->with([
        'installments' => fn ($q) => $q
            ->where('outstanding_amount', '>', 0)
            ->orderBy('due_date')
            ->orderBy('installment_number'),

        'lead:uuid,lead_id,branch_id',

        'lead.branch:id,name,branch_code',

        'lead.applicantDetails' => fn ($q) => $q
            ->where('applicant_sno', 0)
            ->select([
                'id',
                'lead_uuid',
                'full_name',
                'alternate_contact_number',
            ]),

        'followUps' => fn ($q) => $q
            ->orderByDesc('follow_up_date'),
    ])
    ->get();

        $rows = $accounts->map(function (RepaymentAccount $account) use ($asOf) {
            $oldestUnpaid = $account->installments->first();
            $dpd = 0;

            if ($oldestUnpaid) {
                $dueDate = CarbonImmutable::parse($oldestUnpaid->due_date, config('app.timezone'))->startOfDay();
                if ($dueDate->isBefore($asOf)) {
                    $dpd = $dueDate->diffInDays($asOf);
                }
            }

            $latestFollowUp = $account->followUps->first();
            $openPtp = $account->followUps->first(fn (FollowUp $f) => $f->hasOpenPtp());

            $applicant = $account->lead?->applicantDetails->first();
            $branch = $account->lead?->branch;

            return [
                'repayment_account_uuid' => $account->uuid,
                'loan_number' => $account->loan_number,
                'lead_uuid' => $account->lead_uuid,
                'lead_id' => $account->lead?->lead_id,
                'customer_name' => $applicant?->full_name,
                'mobile_number' => $applicant?->alternate_contact_number,
                'branch' => $branch ? [
                    'id' => $branch->id,
                    'name' => $branch->name,
                    'branch_code' => $branch->branch_code,
                ] : null,
                'total_outstanding' => $account->total_outstanding,
                'overdue_amount' => $account->overdue_amount,
                'dpd' => $dpd,
                'latest_follow_up' => $latestFollowUp ? [
                    'uuid' => $latestFollowUp->uuid,
                    'type' => $latestFollowUp->follow_up_type->value,
                    'date' => $latestFollowUp->follow_up_date?->toDateTimeString(),
                    'outcome' => $latestFollowUp->outcome->value,
                    'outcome_label' => $latestFollowUp->outcome->label(),
                    'remarks' => $latestFollowUp->remarks,
                    'next_follow_up_date' => $latestFollowUp->next_follow_up_date?->toDateString(),
                ] : null,
                'open_ptp' => $openPtp ? [
                    'follow_up_uuid' => $openPtp->uuid,
                    'ptp_amount' => $openPtp->ptp_amount,
                    'ptp_date' => $openPtp->ptp_date?->toDateString(),
                    'is_overdue' => $openPtp->ptp_date && $openPtp->ptp_date->lt($asOf),
                ] : null,
                'ptp_status' => $openPtp ? PtpStatus::PENDING->value : null,
            ];
        });

        if (!empty($validated['overdue_only'])) {
            $rows = $rows->filter(fn ($row) => $row['dpd'] > 0);
        }

        // if (!empty($validated['branch_id'])) {
        //     $rows = $rows->filter(fn ($row) => ($row['branch']['id'] ?? null) === (int) $validated['branch_id']);
        // }

        if (!empty($validated['ptp_status'])) {
            $wanted = strtoupper($validated['ptp_status']);
            $rows = $rows->filter(function ($row) use ($wanted) {
                if ($wanted === 'NONE') {
                    return !$row['open_ptp'];
                }
                if ($wanted === 'PENDING') {
                    return (bool) $row['open_ptp'];
                }
                return ($row['latest_follow_up']['ptp_status'] ?? null) === $wanted;
            });
        }

        if (!empty($validated['search'])) {
            $search = strtolower($validated['search']);
            $rows = $rows->filter(function ($row) use ($search) {
                return str_contains(strtolower((string) $row['loan_number']), $search)
                    || str_contains(strtolower((string) $row['customer_name']), $search)
                    || str_contains((string) $row['mobile_number'], $search);
            });
        }

        $rows = $rows->sortByDesc('dpd')->values();

        $perPage = (int) ($validated['per_page'] ?? 25);
        $page = max(1, (int) $request->get('page', 1));
        $paged = $rows->slice(($page - 1) * $perPage, $perPage)->values();

        return $this->success([
            'as_of_date' => $asOf->toDateString(),
            'summary' => [
                'total_loans' => $rows->count(),
                'with_open_ptp' => $rows->filter(fn ($r) => (bool) $r['open_ptp'])->count(),
                'overdue_ptp' => $rows->filter(fn ($r) => $r['open_ptp']['is_overdue'] ?? false)->count(),
                'no_follow_up_yet' => $rows->filter(fn ($r) => !$r['latest_follow_up'])->count(),
            ],
            'accounts' => $paged,
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $rows->count(),
                'last_page' => (int) max(1, ceil($rows->count() / $perPage)),
            ],
        ], 'Follow-up & PTP list fetched successfully.');
    }

    /**
     * Full follow-up history for one account, most recent first.
     */
    public function history(string $accountUuid): JsonResponse
    {
        $account = RepaymentAccount::where('uuid', $accountUuid)->first();

        if (!$account) {
            return $this->error('Repayment account not found.', null, 404);
        }

        $followUps = FollowUp::query()
            ->where('repayment_account_id', $account->id)
            ->with(['createdBy:id,user_name', 'ptpResolvedBy:id,user_name'])
            ->orderByDesc('follow_up_date')
            ->get();

        return $this->success($followUps, 'Follow-up history fetched successfully.');
    }

    /**
     * Log a new follow-up (call/visit/etc.), optionally taking a PTP.
     */
    public function store(Request $request, string $accountUuid): JsonResponse
    {
        $account = RepaymentAccount::where('uuid', $accountUuid)->first();

        if (!$account) {
            return $this->error('Repayment account not found.', null, 404);
        }

        $validated = $request->validate([
            'follow_up_type' => ['required', 'string', Rule::in(array_column(\Modules\FollowUpPTP\Enums\FollowUpType::cases(), 'value'))],
            'follow_up_date' => 'nullable|date',
            'remarks' => 'nullable|string|max:2000',
            'outcome' => ['required', 'string', Rule::in(array_column(FollowUpOutcome::cases(), 'value'))],
            'next_follow_up_date' => 'nullable|date|after_or_equal:today',
            'ptp_amount' => 'required_if:outcome,PTP_TAKEN|nullable|numeric|min:0.01',
            'ptp_date' => 'required_if:outcome,PTP_TAKEN|nullable|date|after_or_equal:today',
        ]);

        $isPtp = $validated['outcome'] === FollowUpOutcome::PTP_TAKEN->value;

        $followUp = FollowUp::create([
            'repayment_account_id' => $account->id,
            'follow_up_type' => $validated['follow_up_type'],
            'follow_up_date' => $validated['follow_up_date'] ?? now(),
            'remarks' => $validated['remarks'] ?? null,
            'outcome' => $validated['outcome'],
            'next_follow_up_date' => $validated['next_follow_up_date'] ?? null,
            'ptp_amount' => $isPtp ? $validated['ptp_amount'] : null,
            'ptp_date' => $isPtp ? $validated['ptp_date'] : null,
            'ptp_status' => $isPtp ? PtpStatus::PENDING->value : null,
            'created_by' => optional($request->user())->id,
        ]);

        return $this->success($followUp, 'Follow-up logged successfully.', 201);
    }

    /**
     * Resolve an open PTP as kept, partially kept, or broken.
     */
    public function resolvePtp(Request $request, string $followUpUuid): JsonResponse
    {
        $followUp = FollowUp::where('uuid', $followUpUuid)->first();

        if (!$followUp) {
            return $this->error('Follow-up not found.', null, 404);
        }

        if ($followUp->outcome !== FollowUpOutcome::PTP_TAKEN) {
            return $this->error('This follow-up did not record a PTP.', null, 422);
        }

        if (!$followUp->hasOpenPtp()) {
            return $this->error('This PTP has already been resolved.', null, 422);
        }

        $validated = $request->validate([
            'ptp_status' => ['required', 'string', Rule::in([
                PtpStatus::KEPT->value,
                PtpStatus::PARTIALLY_KEPT->value,
                PtpStatus::BROKEN->value,
            ])],
            'remarks' => 'nullable|string|max:2000',
        ]);

        $followUp->update([
            'ptp_status' => $validated['ptp_status'],
            'ptp_resolved_by' => optional($request->user())->id,
            'ptp_resolved_at' => now(),
            'ptp_resolution_remarks' => $validated['remarks'] ?? null,
        ]);

        return $this->success($followUp->fresh(), 'PTP status updated successfully.');
    }
}
