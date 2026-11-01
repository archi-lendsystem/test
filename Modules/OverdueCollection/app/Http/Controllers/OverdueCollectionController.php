<?php

namespace Modules\OverdueCollection\Http\Controllers;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Repayment\Enums\RepaymentAccountStatus;
use Modules\Repayment\Enums\RepaymentChargeStatus;
use Modules\Repayment\Enums\RepaymentChargeType;
use Modules\Repayment\Models\RepaymentAccount;

class OverdueCollectionController extends Controller
{
    /**
     * Live "who owes what today" report: every account currently overdue,
     * with where the lead came from (branch + sourced-by), when it was
     * disbursed, and the full amount to collect today — overdue principal
     * + interest, plus any still-payable bounce/penal charges on top.
     * Always as-of-today; there's no date range to pick, it's a daily
     * snapshot by design.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => 'nullable|integer|exists:organization_branches,id',
            'search' => 'nullable|string|max:255',
            'per_page' => 'nullable|integer|min:1|max:200',
        ]);

        $asOf = CarbonImmutable::now(config('app.timezone'))->startOfDay();

        $accounts = RepaymentAccount::query()
            ->whereIn('status', [
                RepaymentAccountStatus::ACTIVE->value,
                RepaymentAccountStatus::SUSPENDED->value,
            ])
            ->where('overdue_amount', '>', 0)
            ->with([
                'lead:uuid,lead_id,branch_id,created_by',
                'lead.branch:id,name,branch_code',
                'lead.creator:id,user_name,branch_id',
                'lead.creator.branch:id,name,branch_code',
                'lead.applicantDetails' => fn ($q) => $q
                    ->where('applicant_sno', 0)
                    ->select(['id', 'lead_uuid', 'full_name', 'alternate_contact_number']),
                'lead.schemeSelect.productScheme:id,uuid,scheme_name',
                'disbursement:id,disbursement_date',
                'installments' => fn ($q) => $q
                    ->where('outstanding_amount', '>', 0)
                    ->orderBy('due_date')
                    ->orderBy('installment_number'),
                'charges' => fn ($q) => $q
                    ->whereIn('charge_type', [RepaymentChargeType::BOUNCE->value, RepaymentChargeType::PENAL->value])
                    ->whereNot('status', RepaymentChargeStatus::WAIVED->value),
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

            $bounceOutstanding = (float) $account->charges
                ->where('charge_type', RepaymentChargeType::BOUNCE->value)
                ->sum('net_payable');

            $penalOutstanding = (float) $account->charges
                ->where('charge_type', RepaymentChargeType::PENAL->value)
                ->sum('net_payable');

            $applicant = $account->lead?->applicantDetails->first();
            // Leads carry their own branch_id since 2026-07-27; older leads
            // fall back to the creator's branch — same rule Dashboard/BLLeads use.
            $branch = $account->lead?->branch ?? $account->lead?->creator?->branch;

            return [
                'repayment_account_uuid' => $account->uuid,
                'loan_number' => $account->loan_number,
                'lead_id' => $account->lead?->lead_id,
                'customer_name' => $applicant?->full_name,
                'mobile_number' => $applicant?->alternate_contact_number,
                'branch' => $branch ? [
                    'id' => $branch->id,
                    'name' => $branch->name,
                    'branch_code' => $branch->branch_code,
                ] : null,
                'sourced_by' => $account->lead?->creator?->user_name,
                'scheme_name' => $account->lead?->schemeSelect?->productScheme?->scheme_name,
                'disbursement_date' => optional($account->disbursement?->disbursement_date)->toDateString(),
                'overdue_since_emi_number' => $oldestUnpaid?->installment_number,
                'dpd' => $dpd,
                'overdue_principal_interest' => (float) $account->overdue_amount,
                'bounce_charges_outstanding' => $bounceOutstanding,
                'penal_charges_outstanding' => $penalOutstanding,
                'total_collectible_today' => round((float) $account->overdue_amount + $bounceOutstanding + $penalOutstanding, 2),
            ];
        });

        if (!empty($validated['branch_id'])) {
            $rows = $rows->filter(fn ($row) => ($row['branch']['id'] ?? null) === (int) $validated['branch_id']);
        }

        if (!empty($validated['search'])) {
            $search = strtolower($validated['search']);
            $rows = $rows->filter(function ($row) use ($search) {
                return str_contains(strtolower((string) $row['loan_number']), $search)
                    || str_contains(strtolower((string) $row['customer_name']), $search)
                    || str_contains((string) $row['mobile_number'], $search);
            });
        }

        $rows = $rows->sortByDesc('total_collectible_today')->values();

        $summary = [
            'as_of_date' => $asOf->toDateString(),
            'total_accounts' => $rows->count(),
            'total_overdue_principal_interest' => round((float) $rows->sum('overdue_principal_interest'), 2),
            'total_bounce_charges_outstanding' => round((float) $rows->sum('bounce_charges_outstanding'), 2),
            'total_penal_charges_outstanding' => round((float) $rows->sum('penal_charges_outstanding'), 2),
            'total_collectible_today' => round((float) $rows->sum('total_collectible_today'), 2),
        ];

        $perPage = (int) ($validated['per_page'] ?? 25);
        $page = max(1, (int) $request->get('page', 1));
        $paged = $rows->slice(($page - 1) * $perPage, $perPage)->values();

        return response()->json([
            'success' => true,
            'message' => 'Overdue collection report fetched successfully.',
            'data' => [
                'summary' => $summary,
                'accounts' => $paged,
            ],
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $rows->count(),
                'last_page' => (int) max(1, ceil($rows->count() / $perPage)),
            ],
        ]);
    }
}
