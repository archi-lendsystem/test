<?php
namespace Modules\DemandReport\Http\Controllers;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\Repayment\Enums\RepaymentAccountStatus;
use Modules\Repayment\Models\RepaymentInstallment;
use Modules\BLDisbursment\Models\BLDisbursment;
use Modules\Repayment\Models\RepaymentTransaction;
use Modules\Repayment\Enums\RepaymentTransactionType;
use Modules\Repayment\Enums\RepaymentTransactionStatus;
use Modules\UserManagement\Support\ReportBranchAccess;
class DemandReportController extends Controller
{
    private const SUB_LOAN_TYPES = [
        'weekly' => 'Weekly Loan',
        'bi_weekly' => 'By-Weekly Loan',
        'monthly' => 'Monthly Loan',
        'daily' => 'Daily Loan',
        'bullet' => 'Bullet Loan',
        'payday' => 'Payday Loan',
    ];
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from_date' => 'nullable|date',
            'to_date' => 'nullable|date|after_or_equal:from_date',
            'as_on_date' => 'nullable|date',
            'branch_id' => 'nullable|integer|exists:organization_branches,id',
            'frequency' => 'nullable|string|in:' . implode(',', array_keys(self::SUB_LOAN_TYPES)),
            'search' => 'nullable|string|max:255',
            'per_page' => 'nullable|integer|min:1|max:5000',
        ]);
        $branchId = !empty($validated['branch_id'])
            ? (int) $validated['branch_id']
            : null;
        $today = now()->startOfDay();
        $fromDate = $validated['from_date'] ?? $today->toDateString();
        $toDate = $validated['to_date'] ?? now()->addDays(7)->toDateString();
        // O/S principal is a snapshot; independent of the future due-date window.
        $asOnDate = $validated['as_on_date'] ?? $today->toDateString();
        $query = ReportBranchAccess::scopeByRelation(
            RepaymentInstallment::query(),
            $request->user(),
            'account.lead',
            $branchId
        )
            ->outstanding()
            ->whereBetween('due_date', [$fromDate, $toDate])
            ->whereHas('account', fn($q) => $q->whereIn('status', [
                RepaymentAccountStatus::ACTIVE->value,
                RepaymentAccountStatus::SUSPENDED->value,
            ]))
            ->with([
                // `status` added so the report can display account status.
                'account:id,uuid,loan_number,lead_uuid,principal_amount,total_outstanding,frequency,status',
                'account.lead:uuid,lead_id,branch_id',
                'account.lead.branch:id,name,branch_code',
                'account.lead.applicantDetails' => fn($q) => $q
                    ->where('applicant_sno', 0)
                    ->select(['id', 'lead_uuid', 'full_name', 'alternate_contact_number']),
            ]);
        if (!empty($validated['frequency'])) {
            $frequency = $validated['frequency'];
            $query->whereHas('account', fn($q) => $q->where('frequency', $frequency));
        }
        if (!empty($validated['search'])) {
            $search = $validated['search'];
            $query->whereHas('account', function ($q) use ($search) {
                $q->where('loan_number', 'like', "%{$search}%")
                    ->orWhereHas('lead.applicantDetails', fn($aq) => $aq->where('full_name', 'like', "%{$search}%"));
            });
        }
        // Snapshot the filtered-but-unpaginated query before paginate()
        // mutates $query's limit/offset in place.
        $summaryQuery = clone $query;
        $installments = $query->orderBy('due_date')->paginate((int) ($validated['per_page'] ?? 25));
        $items = collect($installments->items());
        $leadUuids = $items->pluck('account.lead.uuid')->filter()->unique()->values();
        $accountIds = $items->pluck('account.id')->filter()->unique()->values();
        // Latest disbursement date per lead.
        $disbursements = BLDisbursment::query()
            ->whereIn('lead_uuid', $leadUuids)
            ->select(['lead_uuid', 'disbursement_date'])
            ->orderByDesc('disbursement_date')
            ->get()
            ->keyBy('lead_uuid');
        // Arrears = installments flagged OVERDUE (due_status) for these accounts.
        // Uses the model's business-defined overdue() scope, NOT raw date math,
        // so future/not-yet-due installments never leak into arrears.
        $arrearsByAccount = $this->arrearsMap($accountIds);

        // Same approach as POS: original principal minus posted principal allocations
        // received on/before the requested as-on date. Do not use the amortization
        // schedule's opening_principal: it ignores actual early/late collections.
        $principalCollectedByAccount = RepaymentTransaction::query()
            ->whereIn('repayment_account_id', $accountIds)
            ->where('transaction_type', RepaymentTransactionType::PAYMENT->value)
            ->where('status', RepaymentTransactionStatus::POSTED->value)
            ->whereDate('received_date', '<=', $asOnDate)
            ->with('allocations')
            ->get()
            ->groupBy('repayment_account_id')
            ->map(function ($transactions) {
                return round((float) $transactions
                    ->flatMap(fn($transaction) => $transaction->allocations)
                    ->filter(fn($allocation) => (
                        $allocation->component instanceof \BackedEnum
                        ? $allocation->component->value
                        : $allocation->component
                    ) === 'PRINCIPAL')
                    ->unique(fn($allocation) => $allocation->getKey() ?? spl_object_id($allocation))
                    ->sum('amount'), 2);
            });
        $rows = $items->map(function (RepaymentInstallment $installment) use ($disbursements, $arrearsByAccount, $principalCollectedByAccount, $today) {
            $account = $installment->account;
            $lead = $account?->lead;
            $applicant = $lead?->applicantDetails->first();
            $branch = $lead?->branch;
            $osPrincipal = $account
                ? round(max(0, (float) $account->principal_amount -
                    (float) $principalCollectedByAccount->get($account->id, 0)), 2)
                : 0.0;
            // ---- Name split (fallback). If applicantDetails ever exposes
            // first_name / last_name columns, prefer those and drop this split.
            $fullName = trim((string) $applicant?->full_name);
            $nameParts = $fullName === '' ? [] : preg_split('/\s+/', $fullName);
            $firstName = $nameParts[0] ?? '';
            $lastName = count($nameParts) > 1 ? implode(' ', array_slice($nameParts, 1)) : '';
            // ---- Status label (works whether cast to enum or stored as string) ----
            $status = $account?->status;
            $statusLabel = $status instanceof RepaymentAccountStatus ? $status->label() : $status;
            // ---- Arrears (only genuinely overdue installments) ----
            $arr = $arrearsByAccount->get($account?->id, collect());
            $pArr = round($arr->sum(fn($i) => (float) $i->principalOutstanding()), 2);
            $iArr = round($arr->sum(fn($i) => (float) $i->interestOutstanding()), 2);
            $tArr = round($pArr + $iArr, 2);
            // ---- Par days (days past due, 0 if not yet due) ----
            // $dueDate = $installment->due_date instanceof Carbon ? $installment->due_date : null;
            // $parDays = ($dueDate && $dueDate->lt($today)) ? (int) $dueDate->diffInDays($today) : 0;



            $dueDate = $installment->due_date instanceof Carbon
                ? $installment->due_date
                : ($installment->due_date
                    ? Carbon::parse($installment->due_date)
                    : null);

            $oldestOverdue = $arr
                ->filter(function ($item) {
                    return $item->due_date
                        && (float) $item->outstanding_amount > 0;
                })
                ->sortBy('due_date')
                ->first();

            $parDays = 0;

            if ($oldestOverdue?->due_date) {
                $oldestOverdueDate = $oldestOverdue->due_date instanceof Carbon
                    ? $oldestOverdue->due_date->copy()->startOfDay()
                    : Carbon::parse($oldestOverdue->due_date)->startOfDay();

                $parDays = max(
                    0,
                    (int) $oldestOverdueDate->diffInDays(
                        $today,
                        false
                    )
                );
            }
            return [
                // ===== CSV column order =====
                'region' => null,   // ⛔ relation needed — org hierarchy above branch
                'unit' => null,   // ⛔ relation needed — org hierarchy above branch
                'branch' => $branch ? ['id' => $branch->id, 'name' => $branch->name, 'branch_code' => $branch->branch_code] : null,
                'village_id' => null,   // ⛔ relation needed — village master / lead field
                'loan_officer' => null,   // ⛔ relation needed — lead's assigned officer
                'ext_customer_id' => null,   // empty in the source export too — null is correct
                'customer_id' => null,   // ⛔ business customer id (not applicant PK) — confirm source
                'first_name' => $firstName,
                'last_name' => $lastName,
                'customer_name' => $fullName ?: null,   // for the on-screen table
                'loan_number' => $account?->loan_number,
                'status' => $statusLabel,
                'mobile_number' => $applicant?->alternate_contact_number,
                'disbursement_date' => optional($disbursements->get($lead?->uuid)?->disbursement_date)->toDateString(),
                'due_date' => optional($dueDate)->toDateString(),
                'product_id' => null,   // ⛔ product master code (frequency != product) — confirm source
                'frequency' => $account?->frequency,
                'cycle' => null,   // ⛔ loan cycle number — confirm source
                'installment_number' => $installment->installment_number,
                'os_principal' => $osPrincipal, // Actual as-on principal; matches POS method
                // 'installment_amount' => (int) $installment->installment_amount,  // scheduled EMI
                'installment_amount' => (int) round((float) $installment->installment_amount),
                'principal_due' => (float) $installment->principalOutstanding(),
                'interest_due' => (float) $installment->interestOutstanding(),
                // 'outstanding_amount' => (int) $installment->outstanding_amount,  // Total Due
                'outstanding_amount' => (int) round((float) $installment->outstanding_amount),
                'principal_arrear' => $pArr,
                'interest_arrear' => $iArr,
                'total_arrear' => $tArr,
                'umrn' => null,   // ⛔ NACH mandate — relation needed (or drop)
                'nach_type' => null,   // ⛔ NACH mandate — relation needed (or drop)
                'par_days' => $parDays,
                'collected_amount' => (float) $installment->principal_paid + (float) $installment->interest_paid,
                // ===== meta =====
                'installment_uuid' => $installment->uuid,
                'repayment_account_uuid' => $account?->uuid,
                'due_status' => $installment->due_status?->value,
            ];
        });
        $summary = [
            'total_installments' => $installments->total(),
            'total_demand_amount' => (float) (clone $summaryQuery)->sum('outstanding_amount'),
            'unique_accounts' => (clone $summaryQuery)->distinct('repayment_account_id')->count('repayment_account_id'),
        ];
        return response()->json([
            'success' => true,
            'message' => 'Demand report fetched successfully.',
            'data' => [
                'from_date' => $fromDate,
                'to_date' => $toDate,
                'as_on_date' => $asOnDate,
                'frequency' => $validated['frequency'] ?? null,
                'sub_loan_types' => self::SUB_LOAN_TYPES,
                'summary' => $summary,
                'installments' => $rows,
            ],
            'meta' => [
                'current_page' => $installments->currentPage(),
                'per_page' => $installments->perPage(),
                'total' => $installments->total(),
                'last_page' => $installments->lastPage(),
            ],
        ]);
    }
    /**
     * Overdue installments grouped by account id.
     *
     * Uses the model's overdue() scope (due_status = OVERDUE AND outstanding > 0),
     * which is the business definition of arrears — not raw "due_date < today"
     * math, which incorrectly swept in future/not-yet-billed installments.
     * principalOutstanding()/interestOutstanding() are pure bcmath (no relations
     * touched → no N+1).
     */
    private function arrearsMap(Collection $accountIds): Collection
    {
        if ($accountIds->isEmpty()) {
            return collect();
        }
        return RepaymentInstallment::query()
            ->overdue()
            ->whereIn('repayment_account_id', $accountIds)
            ->get()
            ->groupBy('repayment_account_id');
    }
}


// namespace Modules\DemandReport\Http\Controllers;

// use App\Http\Controllers\Controller;
// use Illuminate\Http\JsonResponse;
// use Illuminate\Http\Request;
// use Illuminate\Support\Carbon;
// use Illuminate\Support\Collection;
// use Modules\Repayment\Enums\RepaymentAccountStatus;
// use Modules\Repayment\Models\RepaymentInstallment;
// use Modules\BLDisbursment\Models\BLDisbursment;
// use Modules\UserManagement\Support\ReportBranchAccess;

// class DemandReportController extends Controller
// {

//     private const SUB_LOAN_TYPES = [
//         'weekly'    => 'Weekly Loan',
//         'bi_weekly' => 'By-Weekly Loan',
//         'monthly'   => 'Monthly Loan',
//         'daily'     => 'Daily Loan',
//         'bullet'    => 'Bullet Loan',
//         'payday'    => 'Payday Loan',
//     ];


//     public function index(Request $request): JsonResponse
//     {
//         $validated = $request->validate([
//             'from_date' => 'nullable|date',
//             'to_date'   => 'nullable|date|after_or_equal:from_date',
//             'branch_id' => 'nullable|integer|exists:organization_branches,id',
//             'frequency' => 'nullable|string|in:' . implode(',', array_keys(self::SUB_LOAN_TYPES)),
//             'search'    => 'nullable|string|max:255',
//             'per_page'  => 'nullable|integer|min:1|max:5000',
//         ]);

//         $branchId = !empty($validated['branch_id'])
//     ? (int) $validated['branch_id']
//     : null;

//         $today    = now()->startOfDay();
//         $fromDate = $validated['from_date'] ?? $today->toDateString();
//         $toDate   = $validated['to_date']  ?? now()->addDays(7)->toDateString();

//         $query = ReportBranchAccess::scopeByRelation(
//     RepaymentInstallment::query(),
//     $request->user(),
//     'account.lead',
//     $branchId
// )
//     ->outstanding()
//             ->whereBetween('due_date', [$fromDate, $toDate])
//             ->whereHas('account', fn ($q) => $q->whereIn('status', [
//                 RepaymentAccountStatus::ACTIVE->value,
//                 RepaymentAccountStatus::SUSPENDED->value,
//             ]))
//             ->with([
//                 // `status` added so the report can display account status.
//                 'account:id,uuid,loan_number,lead_uuid,total_outstanding,frequency,status',
//                 'account.lead:uuid,lead_id,branch_id',
//                 'account.lead.branch:id,name,branch_code',
//                 'account.lead.applicantDetails' => fn ($q) => $q
//                     ->where('applicant_sno', 0)
//                     ->select(['id', 'lead_uuid', 'full_name', 'alternate_contact_number']),
//             ]);


//         if (!empty($validated['frequency'])) {
//             $frequency = $validated['frequency'];
//             $query->whereHas('account', fn ($q) => $q->where('frequency', $frequency));
//         }

//         if (!empty($validated['search'])) {
//             $search = $validated['search'];
//             $query->whereHas('account', function ($q) use ($search) {
//                 $q->where('loan_number', 'like', "%{$search}%")
//                     ->orWhereHas('lead.applicantDetails', fn ($aq) => $aq->where('full_name', 'like', "%{$search}%"));
//             });
//         }

//         // Snapshot the filtered-but-unpaginated query before paginate()
//         // mutates $query's limit/offset in place.
//         $summaryQuery = clone $query;

//         $installments = $query->orderBy('due_date')->paginate((int) ($validated['per_page'] ?? 25));

//         $items      = collect($installments->items());
//         $leadUuids  = $items->pluck('account.lead.uuid')->filter()->unique()->values();
//         $accountIds = $items->pluck('account.id')->filter()->unique()->values();

//         // Latest disbursement date per lead.
//         $disbursements = BLDisbursment::query()
//             ->whereIn('lead_uuid', $leadUuids)
//             ->select(['lead_uuid', 'disbursement_date'])
//             ->orderByDesc('disbursement_date')
//             ->get()
//             ->keyBy('lead_uuid');

//         // Arrears = installments flagged OVERDUE (due_status) for these accounts.
//         // Uses the model's business-defined overdue() scope, NOT raw date math,
//         // so future/not-yet-due installments never leak into arrears.
//         $arrearsByAccount = $this->arrearsMap($accountIds);

//         $rows = $items->map(function (RepaymentInstallment $installment) use ($disbursements, $arrearsByAccount, $today) {
//             $account   = $installment->account;
//             $lead      = $account?->lead;
//             $applicant = $lead?->applicantDetails->first();
//             $branch    = $lead?->branch;

//             // ---- Name split (fallback). If applicantDetails ever exposes
//             // first_name / last_name columns, prefer those and drop this split.
//             $fullName  = trim((string) $applicant?->full_name);
//             $nameParts = $fullName === '' ? [] : preg_split('/\s+/', $fullName);
//             $firstName = $nameParts[0] ?? '';
//             $lastName  = count($nameParts) > 1 ? implode(' ', array_slice($nameParts, 1)) : '';

//             // ---- Status label (works whether cast to enum or stored as string) ----
//             $status      = $account?->status;
//             $statusLabel = $status instanceof RepaymentAccountStatus ? $status->label() : $status;

//             // ---- Arrears (only genuinely overdue installments) ----
//             $arr  = $arrearsByAccount->get($account?->id, collect());
//             $pArr = round($arr->sum(fn ($i) => (float) $i->principalOutstanding()), 2);
//             $iArr = round($arr->sum(fn ($i) => (float) $i->interestOutstanding()), 2);
//             $tArr = round($pArr + $iArr, 2);

//             // ---- Par days (days past due, 0 if not yet due) ----
//             $dueDate = $installment->due_date instanceof Carbon ? $installment->due_date : null;
//             $parDays = ($dueDate && $dueDate->lt($today)) ? (int) $dueDate->diffInDays($today) : 0;

//             return [
//                 // ===== CSV column order =====
//                 'region'             => null,   // ⛔ relation needed — org hierarchy above branch
//                 'unit'               => null,   // ⛔ relation needed — org hierarchy above branch
//                 'branch'             => $branch ? ['id' => $branch->id, 'name' => $branch->name, 'branch_code' => $branch->branch_code] : null,
//                 'village_id'         => null,   // ⛔ relation needed — village master / lead field
//                 'loan_officer'       => null,   // ⛔ relation needed — lead's assigned officer
//                 'ext_customer_id'    => null,   // empty in the source export too — null is correct
//                 'customer_id'        => null,   // ⛔ business customer id (not applicant PK) — confirm source
//                 'first_name'         => $firstName,
//                 'last_name'          => $lastName,
//                 'customer_name'      => $fullName ?: null,   // for the on-screen table
//                 'loan_number'        => $account?->loan_number,
//                 'status'             => $statusLabel,
//                 'mobile_number'      => $applicant?->alternate_contact_number,
//                 'disbursement_date'  => optional($disbursements->get($lead?->uuid)?->disbursement_date)->toDateString(),
//                 'due_date'           => optional($dueDate)->toDateString(),
//                 'product_id'         => null,   // ⛔ product master code (frequency != product) — confirm source
//                 'frequency'          => $account?->frequency,
//                 'cycle'              => null,   // ⛔ loan cycle number — confirm source
//                 'installment_number' => $installment->installment_number,
//                 'os_principal'       => (float) $installment->opening_principal,   // real principal-only balance
//                 // 'installment_amount' => (int) $installment->installment_amount,  // scheduled EMI
//                 'installment_amount' => (int) round((float) $installment->installment_amount),
//                 'principal_due'      => (float) $installment->principalOutstanding(),
//                 'interest_due'       => (float) $installment->interestOutstanding(),
//                 // 'outstanding_amount' => (int) $installment->outstanding_amount,  // Total Due
//                 'outstanding_amount' => (int) round((float) $installment->outstanding_amount),
//                 'principal_arrear'   => $pArr,
//                 'interest_arrear'    => $iArr,
//                 'total_arrear'       => $tArr,
//                 'umrn'               => null,   // ⛔ NACH mandate — relation needed (or drop)
//                 'nach_type'          => null,   // ⛔ NACH mandate — relation needed (or drop)
//                 'par_days'           => $parDays,
//                 'collected_amount'   => (float) $installment->principal_paid + (float) $installment->interest_paid,
//                 // ===== meta =====
//                 'installment_uuid'       => $installment->uuid,
//                 'repayment_account_uuid' => $account?->uuid,
//                 'due_status'             => $installment->due_status?->value,
//             ];
//         });

//         $summary = [
//             'total_installments'  => $installments->total(),
//             'total_demand_amount' => (float) (clone $summaryQuery)->sum('outstanding_amount'),
//             'unique_accounts'     => (clone $summaryQuery)->distinct('repayment_account_id')->count('repayment_account_id'),
//         ];

//         return response()->json([
//             'success' => true,
//             'message' => 'Demand report fetched successfully.',
//             'data' => [
//                 'from_date'      => $fromDate,
//                 'to_date'        => $toDate,
//                 'frequency'      => $validated['frequency'] ?? null,
//                 'sub_loan_types' => self::SUB_LOAN_TYPES,
//                 'summary'        => $summary,
//                 'installments'   => $rows,
//             ],
//             'meta' => [
//                 'current_page' => $installments->currentPage(),
//                 'per_page'     => $installments->perPage(),
//                 'total'        => $installments->total(),
//                 'last_page'    => $installments->lastPage(),
//             ],
//         ]);
//     }

//     /**
//      * Overdue installments grouped by account id.
//      *
//      * Uses the model's overdue() scope (due_status = OVERDUE AND outstanding > 0),
//      * which is the business definition of arrears — not raw "due_date < today"
//      * math, which incorrectly swept in future/not-yet-billed installments.
//      * principalOutstanding()/interestOutstanding() are pure bcmath (no relations
//      * touched → no N+1).
//      */
//     private function arrearsMap(Collection $accountIds): Collection
//     {
//         if ($accountIds->isEmpty()) {
//             return collect();
//         }

//         return RepaymentInstallment::query()
//             ->overdue()
//             ->whereIn('repayment_account_id', $accountIds)
//             ->get()
//             ->groupBy('repayment_account_id');
//     }
// }
