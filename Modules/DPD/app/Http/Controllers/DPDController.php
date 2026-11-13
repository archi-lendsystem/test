<?php

namespace Modules\DPD\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Repayment\Enums\RepaymentAccountStatus;
use Modules\Repayment\Models\RepaymentAccount;
use Modules\UserManagement\Support\ReportBranchAccess;

class DPDController extends Controller
{
    use ApiResponse;

    /**
     * DPD buckets, in order. An account with dpd = 0 (not overdue at all)
     * falls into the first bucket alongside genuinely early delinquency —
     * this is a customer-wise loan book view, not just an overdue list.
     */
    private const BUCKETS = [
        ['key' => '0-30', 'min' => 0, 'max' => 30],
        ['key' => '31-60', 'min' => 31, 'max' => 60],
        ['key' => '61-90', 'min' => 61, 'max' => 90],
        ['key' => '90+', 'min' => 91, 'max' => null],
    ];

    /**
     * Customer-wise DPD report, bucketed 0-30 / 31-60 / 61-90 / 90+.
     *
     * DPD for a loan = days since the due date of its oldest unpaid
     * installment (0 if nothing is overdue). This is computed directly from
     * repayment_installments here rather than trusting
     * RepaymentInstallment.due_status, since that field is only as fresh as
     * the last daily refresh run.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'bucket' => 'nullable|string|in:0-30,31-60,61-90,90+',
            'branch_id' => 'nullable|integer|exists:organization_branches,id',
            'search' => 'nullable|string|max:255',
            'per_page' => 'nullable|integer|min:1|max:200',
        ]);

        $branchId = !empty($validated['branch_id'])
            ? (int) $validated['branch_id']
            : null;

        $asOf = CarbonImmutable::now(config('app.timezone'))->startOfDay();

        $accountsQuery = ReportBranchAccess::scopeByRelation(
            RepaymentAccount::query(),
            $request->user(),
            'lead',
            $branchId
        );

        $accounts = $accountsQuery
            ->whereIn('status', [
                RepaymentAccountStatus::ACTIVE->value,
                RepaymentAccountStatus::SUSPENDED->value,
            ])
            ->with([
                'installments' => fn($q) => $q
                    ->where('outstanding_amount', '>', 0)
                    ->orderBy('due_date')
                    ->orderBy('installment_number'),

                'lead:uuid,lead_id,branch_id',

                'lead.branch:id,name,branch_code',

                'lead.applicantDetails' => fn($q) => $q
                    ->where('applicant_sno', 0)
                    ->select([
                        'id',
                        'lead_uuid',
                        'full_name',
                        'alternate_contact_number',
                    ]),
            ])
            ->get();

        $rows = $accounts->map(function (RepaymentAccount $account) use ($asOf) {
            $oldestUnpaid = $account->installments->first();

            $dpd = 0;
            $oldestOverdueDueDate = null;

            if ($oldestUnpaid) {
                $dueDate = CarbonImmutable::parse($oldestUnpaid->due_date, config('app.timezone'))->startOfDay();

                if ($dueDate->isBefore($asOf)) {
                    $dpd = $dueDate->diffInDays($asOf);
                    $oldestOverdueDueDate = $dueDate->toDateString();
                }
            }

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
                'principal_amount' => $account->principal_amount,
                'total_outstanding' => $account->total_outstanding,
                'overdue_amount' => $account->overdue_amount,
                'next_due_date' => $account->next_due_date?->toDateString(),
                'oldest_overdue_due_date' => $oldestOverdueDueDate,
                'dpd' => $dpd,
                'bucket' => $this->bucketFor($dpd),
            ];
        });

        // if (!empty($validated['branch_id'])) {
        //     $rows = $rows->filter(fn($row) => ($row['branch']['id'] ?? null) === (int) $validated['branch_id']);
        // }

        if (!empty($validated['search'])) {
            $search = strtolower($validated['search']);
            $rows = $rows->filter(function ($row) use ($search) {
                return str_contains(strtolower((string) $row['loan_number']), $search)
                    || str_contains(strtolower((string) $row['lead_id']), $search)
                    || str_contains(strtolower((string) $row['customer_name']), $search)
                    || str_contains((string) $row['mobile_number'], $search);
            });
        }

        $summary = collect(self::BUCKETS)->map(function ($bucket) use ($rows) {
            $inBucket = $rows->where('bucket', $bucket['key']);

            return [
                'bucket' => $bucket['key'],
                'count' => $inBucket->count(),
                'total_outstanding' => (float) $inBucket->sum('total_outstanding'),
                'total_overdue_amount' => (float) $inBucket->sum('overdue_amount'),
            ];
        })->values();

        if (!empty($validated['bucket'])) {
            $rows = $rows->where('bucket', $validated['bucket']);
        }

        $rows = $rows->sortByDesc('dpd')->values();

        $perPage = (int) ($validated['per_page'] ?? 25);
        $page = max(1, (int) $request->get('page', 1));
        $paged = $rows->slice(($page - 1) * $perPage, $perPage)->values();

        return $this->success([
            'as_of_date' => $asOf->toDateString(),
            'summary' => $summary,
            'customers' => $paged,
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $rows->count(),
                'last_page' => (int) max(1, ceil($rows->count() / $perPage)),
            ],
        ], 'DPD report fetched successfully.');
    }

    private function bucketFor(int $dpd): string
    {
        foreach (self::BUCKETS as $bucket) {
            if ($dpd >= $bucket['min'] && ($bucket['max'] === null || $dpd <= $bucket['max'])) {
                return $bucket['key'];
            }
        }

        return '90+';
    }
}
