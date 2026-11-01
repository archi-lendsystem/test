<?php

namespace Modules\CollectionReport\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Repayment\Enums\RepaymentTransactionStatus;
use Modules\Repayment\Enums\RepaymentTransactionType;
use Modules\Repayment\Models\RepaymentInstallment;
use Modules\Repayment\Models\RepaymentTransaction;
use Modules\BLLeadAssignment\Models\BLLeadAssignment;
use Modules\Customers\Models\Customer;
use Modules\Repayment\Enums\RepaymentChargeType;
use Modules\Repayment\Models\RepaymentCharge;
use Modules\UserManagement\Models\User;
use Illuminate\Support\Carbon;
use Modules\BLAmortization\Models\BLAmortization;
use Modules\BLApplicantDetails\Models\BLApplicantDetails;
use Modules\BLBankDetails\Models\BLBankDetail;
use Modules\BLCam\Models\BLCam;
use Modules\BLCreditVisitReport\Models\BLCreditVisitReport;
use Modules\BLDisbursment\Models\BLDisbursment;
use Modules\BLSchemeSelect\Models\BLSchemeSelect;
use Modules\Repayment\Models\RepaymentAccount;
use Modules\Insurance\Models\Insurance;
use Modules\Charges\Models\Charge;
use Modules\LUC\Models\LucReport;
use Modules\NOC\Models\NocCertificate;
use Modules\Settlement\Models\RepaymentSettlement;
use Illuminate\Support\Facades\Log;

use BackedEnum;


class CollectionReportController extends Controller
{
    /**
     * Demand (what was due) vs collection (what actually came in) for a
     * period, overall and branch-wise. "Demand" is every installment whose
     * due date falls in the period, regardless of the account's current
     * status — that's what collection was supposed to chase.
     */
    public function summary(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from_date' => 'nullable|date',
            'to_date' => 'nullable|date|after_or_equal:from_date',
            'branch_id' => 'nullable|integer|exists:organization_branches,id',
        ]);

        $fromDate = $validated['from_date'] ?? now()->startOfMonth()->toDateString();
        $toDate = $validated['to_date'] ?? now()->toDateString();
        $branchFilter = !empty($validated['branch_id']) ? (int) $validated['branch_id'] : null;

        $demandInstallments = RepaymentInstallment::query()
            ->whereBetween('due_date', [$fromDate, $toDate])
            ->with(['account:id,uuid,lead_uuid', 'account.lead:uuid,branch_id', 'account.lead.branch:id,name,branch_code'])
            ->get();

        $collectedTransactions = RepaymentTransaction::query()
            ->where('transaction_type', RepaymentTransactionType::PAYMENT->value)
            ->where('status', RepaymentTransactionStatus::POSTED->value)
            ->whereBetween('received_date', [$fromDate, $toDate])
            ->with(['account:id,uuid,lead_uuid', 'account.lead:uuid,branch_id', 'account.lead.branch:id,name,branch_code'])
            ->get();

        $branchOf = function ($record) {
            $branch = $record->account?->lead?->branch;
            return $branch ? ['id' => $branch->id, 'name' => $branch->name] : ['id' => null, 'name' => 'Unassigned'];
        };

        if ($branchFilter !== null) {
            $demandInstallments = $demandInstallments->filter(fn($i) => ($branchOf($i)['id']) === $branchFilter)->values();
            $collectedTransactions = $collectedTransactions->filter(fn($t) => ($branchOf($t)['id']) === $branchFilter)->values();
        }

        $totalDemand = (float) $demandInstallments->sum('installment_amount');
        $totalCollected = (float) $collectedTransactions->sum('amount');
        $efficiency = $totalDemand > 0 ? round(($totalCollected / $totalDemand) * 100, 2) : 0.0;

        $demandByBranch = $demandInstallments
            ->groupBy(fn($i) => $branchOf($i)['name'])
            ->map(fn($group, $name) => [
                'branch' => $name,
                'demand_amount' => (float) $group->sum('installment_amount'),
                'demand_count' => $group->count(),
            ]);

        $collectedByBranch = $collectedTransactions
            ->groupBy(fn($t) => $branchOf($t)['name']);

        $branchWise = $demandByBranch
            ->map(function ($row, $branchName) use ($collectedByBranch) {
                $collectedGroup = $collectedByBranch->get($branchName);
                $collectedAmount = (float) ($collectedGroup?->sum('amount') ?? 0);

                return [
                    'branch' => $branchName,
                    'demand_amount' => $row['demand_amount'],
                    'demand_count' => $row['demand_count'],
                    'collected_amount' => $collectedAmount,
                    'collected_count' => $collectedGroup?->count() ?? 0,
                    'efficiency_percent' => $row['demand_amount'] > 0
                        ? round(($collectedAmount / $row['demand_amount']) * 100, 2)
                        : 0.0,
                ];
            })
            ->values()
            ->sortByDesc('demand_amount')
            ->values();

        return response()->json([
            'success' => true,
            'message' => 'Collection report fetched successfully.',
            'data' => [
                'from_date' => $fromDate,
                'to_date' => $toDate,
                'total_demand_amount' => $totalDemand,
                'total_collected_amount' => $totalCollected,
                'collection_efficiency_percent' => $efficiency,
                'demand_installment_count' => $demandInstallments->count(),
                'collected_transaction_count' => $collectedTransactions->count(),
                'branch_wise' => $branchWise,
            ],
        ]);
    }


    /**
     * Excel-format row-wise repayment collection report.
     *
     * जिन columns का data उपलब्ध नहीं है उनमें blank value आएगी।
     * किसी migration या नए model relation की आवश्यकता नहीं है।
     */
    public function repaymentSummary(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from_date' => 'nullable|date',
            'to_date' => 'nullable|date|after_or_equal:from_date',
            'branch_id' => 'nullable|integer|exists:organization_branches,id',
            'per_page' => 'nullable|integer|min:1|max:500',
        ]);

        $fromDate = $validated['from_date']
            ?? now()->startOfMonth()->toDateString();

        $toDate = $validated['to_date']
            ?? now()->toDateString();

        $branchId = !empty($validated['branch_id'])
            ? (int) $validated['branch_id']
            : null;

        $perPage = (int) ($validated['per_page'] ?? 100);

        /*
     * Casted enum या normal string दोनों handle करेगा।
     */
        $enumValue = static function ($value) {
            return $value instanceof BackedEnum
                ? $value->value
                : $value;
        };

        $transactions = RepaymentTransaction::query()
            ->where(
                'transaction_type',
                RepaymentTransactionType::PAYMENT->value
            )
            ->where(
                'status',
                RepaymentTransactionStatus::POSTED->value
            )
            ->whereBetween('received_date', [$fromDate, $toDate])
            ->when(
                $branchId !== null,
                function ($query) use ($branchId) {
                    $query->whereHas(
                        'account.lead',
                        function ($leadQuery) use ($branchId) {
                            $leadQuery->where('branch_id', $branchId);
                        }
                    );
                }
            )
            ->with([
                'allocations.installment',
                'receipt',
                'reversal',
                'account',
                'account.lead',
                'account.lead.branch',
                'account.lead.branch.parentBranch',
                'account.lead.branch.parentBranch.parentBranch',
                'account.lead.applicantDetails',
                'account.lead.schemeSelect.productScheme',
            ])
            ->orderByDesc('received_date')
            ->orderByDesc('id')
            ->paginate($perPage);

        $transactionCollection = $transactions->getCollection();

        /*
     * Current page के lead UUIDs।
     */
        $leadUuids = $transactionCollection
            ->pluck('account.lead_uuid')
            ->filter()
            ->unique()
            ->values();

        /*
     * BLLeadAssignment direct model access।
     * कोई नया relation use नहीं हो रहा।
     */
        $assignmentsByLead = BLLeadAssignment::query()
            ->whereIn('lead_uuid', $leadUuids)
            ->where('is_active', true)
            ->orderByDesc('assigned_at')
            ->orderByDesc('id')
            ->get()
            ->groupBy('lead_uuid')
            ->map(function ($assignments) {
                return $assignments->first();
            });

        /*
     * Customer direct model access।
     */
        $customerIds = $transactionCollection
            ->pluck('account.lead.customer_id')
            ->filter()
            ->unique()
            ->values();

        $customersById = Customer::query()
            ->whereIn('id', $customerIds)
            ->get()
            ->keyBy('id');

        /*
     * Transaction charges direct model access।
     */
        $transactionIds = $transactionCollection
            ->pluck('id')
            ->filter()
            ->unique()
            ->values();

        $chargesByTransaction = RepaymentCharge::query()
            ->whereIn('repayment_transaction_id', $transactionIds)
            ->get()
            ->groupBy('repayment_transaction_id');

        /*
     * Assignment और transaction से user IDs।
     */
        // $userIds = collect()
        //     ->merge($assignmentsByLead->pluck('assigned_to_user_id'))
        //     ->merge($assignmentsByLead->pluck('assigned_by_user_id'))
        //     ->merge($transactionCollection->pluck('created_by'))
        //     ->merge($transactionCollection->pluck('approved_by'))
        //     ->filter()
        //     ->unique()
        //     ->values();

        $legacyUserIds = $assignmentsByLead
            ->pluck('assigned_to')
            ->filter(fn($value) => is_numeric($value))
            ->map(fn($value) => (int) $value);

        $userIds = collect()
            ->merge($assignmentsByLead->pluck('assigned_to_user_id'))
            ->merge($assignmentsByLead->pluck('assigned_by_user_id'))
            ->merge($legacyUserIds)
            ->merge($transactionCollection->pluck('created_by'))
            ->merge($transactionCollection->pluck('approved_by'))
            ->filter()
            ->unique()
            ->values();

        /*
     * User model direct access।
     */
        $usersById = User::query()
            ->whereIn('id', $userIds)
            ->get()
            ->keyBy('id');

        /*
     * User name उपलब्ध नहीं होने पर ID return होगी।
     */
        // $userDisplayName = static function ($user): string {
        //     if (!$user) {
        //         return '';
        //     }

        //     $name = $user->full_name
        //         ?? $user->name
        //         ?? $user->username
        //         ?? '';

        //     if ($name === '') {
        //         return (string) $user->id;
        //     }

        //     return trim($user->id . ' - ' . $name);
        // };

        $userDisplayName = static function ($user): string {
            if (!$user) {
                return '';
            }

            return $user->user_name ?? '';
        };

        //         $userDisplayName = static function ($user): string {
        //     if (!$user) {
        //         return '';
        //     }

        //     return $user->user_name ?? '';
        // };

        $rows = $transactionCollection
            ->map(function ($transaction) use (
                $assignmentsByLead,
                $customersById,
                $chargesByTransaction,
                $usersById,
                $userDisplayName,
                $enumValue
            ) {
                $account = $transaction->account;
                $lead = $account?->lead;
                $branch = $lead?->branch;

                /*
             * Organization hierarchy:
             * Branch -> Unit -> Region
             */
                $unit = $branch?->parentBranch;
                $region = $unit?->parentBranch;

                /*
             * Primary applicant name।
             */
                $primaryApplicant = $lead?->applicantDetails
                    ?->first(function ($applicant) {
                        return !((bool) $applicant->is_co_applicant);
                    });

                if (!$primaryApplicant) {
                    $primaryApplicant = $lead?->applicantDetails?->first();
                }

                $scheme = $lead?->schemeSelect?->productScheme;

                /*
             * Direct fetched assignment/customer।
             */
                $assignment = $lead
                    ? $assignmentsByLead->get($lead->uuid)
                    : null;

                $customer = $lead?->customer_id
                    ? $customersById->get($lead->customer_id)
                    : null;

                // $loanOfficerUser = $assignment?->assigned_to_user_id
                //     ? $usersById->get($assignment->assigned_to_user_id)
                //     : null;

                $loanOfficerId = $assignment?->assigned_to_user_id;

                if (!$loanOfficerId && is_numeric($assignment?->assigned_to)) {
                    $loanOfficerId = (int) $assignment->assigned_to;
                }

                $loanOfficerUser = $loanOfficerId
                    ? $usersById->get($loanOfficerId)
                    : null;

                $createdByUser = $transaction->created_by
                    ? $usersById->get($transaction->created_by)
                    : null;

                $modifiedByUser = $transaction->approved_by
                    ? $usersById->get($transaction->approved_by)
                    : null;

                $allocations = $transaction->allocations ?? collect();

                /*
             * Principal allocation total।
             */
                $principal = round(
                    (float) $allocations
                        ->filter(function ($allocation) use ($enumValue) {
                            return $enumValue($allocation->component)
                                === 'PRINCIPAL';
                        })
                        ->sum('amount'),
                    2
                );

                /*
             * Interest allocation total।
             */
                $interest = round(
                    (float) $allocations
                        ->filter(function ($allocation) use ($enumValue) {
                            return $enumValue($allocation->component)
                                === 'INTEREST';
                        })
                        ->sum('amount'),
                    0
                );

                /*
             * Transaction multiple installments में allocated होने पर
             * sequence "1,2,3" format में आएगा।
             */
                $sequenceNumbers = $allocations
                    ->pluck('installment.installment_number')
                    ->filter(function ($number) {
                        return $number !== null;
                    })
                    ->unique()
                    ->sort()
                    ->values()
                    ->implode(',');

                $transactionCharges = $chargesByTransaction
                    ->get($transaction->id, collect());

                /*
             * Specific charge type और field का total।
             */
                $chargeAmount = static function (
                    $charges,
                    string $chargeType,
                    string $field
                ) use ($enumValue): float {
                    return (float) $charges
                        ->filter(function ($charge) use (
                            $chargeType,
                            $enumValue
                        ) {
                            return $enumValue($charge->charge_type)
                                === $chargeType;
                        })
                        ->sum($field);
                };

                $penaltyBase = $chargeAmount(
                    $transactionCharges,
                    RepaymentChargeType::PENAL->value,
                    'base_amount'
                );

                $penaltyWithGst = $chargeAmount(
                    $transactionCharges,
                    RepaymentChargeType::PENAL->value,
                    'total_amount'
                );

                $bounceBase = $chargeAmount(
                    $transactionCharges,
                    RepaymentChargeType::BOUNCE->value,
                    'base_amount'
                );

                $bounceWithGst = $chargeAmount(
                    $transactionCharges,
                    RepaymentChargeType::BOUNCE->value,
                    'total_amount'
                );

                $foreclosureBase = $chargeAmount(
                    $transactionCharges,
                    RepaymentChargeType::FORECLOSURE->value,
                    'base_amount'
                );

                $foreclosureWithGst = $chargeAmount(
                    $transactionCharges,
                    RepaymentChargeType::FORECLOSURE->value,
                    'total_amount'
                );

                $waivedAmount = (float) $transactionCharges
                    ->sum('waived_amount');

                $paymentMode = $enumValue(
                    $transaction->payment_mode
                );

                $transactionStatus = $enumValue(
                    $transaction->status
                );

                $transactionType = $enumValue(
                    $transaction->transaction_type
                );

                $metadata = is_array($transaction->metadata)
                    ? $transaction->metadata
                    : [];

                return [
                    'Region' => $region
                        ? trim(
                            ($region->branch_code
                                ? $region->branch_code . ' - '
                                : '') . $region->name
                        )
                        : '',

                    'Unit' => $unit
                        ? trim(
                            ($unit->branch_code
                                ? $unit->branch_code . ' - '
                                : '') . $unit->name
                        )
                        : '',

                    'Branch ID' => $branch
                        ? trim(
                            ($branch->branch_code
                                ? $branch->branch_code . ' - '
                                : '') . $branch->name
                        )
                        : '',

                    // 'Loan Officer' =>
                    // $userDisplayName($loanOfficerUser)
                    //     ?: ($assignment?->assigned_to ?? '')
                    //     ?: ($assignment?->assigned_to_user_id ?? ''),

                    'Loan Officer' =>
                    $loanOfficerUser?->user_name ?? '',

                    'Partner Bank ID' =>
                    data_get($metadata, 'partner_bank_id', ''),

                    'Loan ID' =>
                    $account?->loan_number
                        ?? $lead?->lead_id
                        ?? '',

                    /*
                 * User requirement:
                 * Customer ID में lead_id दिखेगा।
                 */
                    'Cust ID' => $lead?->lead_id ?? '',

                    'NAME' =>
                    $primaryApplicant?->full_name
                        ?? $customer?->full_name
                        ?? '',

                    'Product ID' =>
                    $scheme?->product_name
                        ?? $scheme?->scheme_name
                        ?? $scheme?->uuid
                        ?? '',

                    'Collected By' =>
                    data_get($metadata, 'collected_by')
                        ?: $userDisplayName($createdByUser)
                        ?: ($transaction->created_by ?? ''),

                    'Collection Date' =>
                    $transaction->received_date
                        ?->format('Y-m-d') ?? '',

                    'Seq Num' => $sequenceNumbers,

                    'Amount' => round((float) $transaction->amount, 2),

                    'Principal' => $principal,

                    'Interest' => $interest,

                    'Penalty Charge' => $penaltyBase,

                    'OD Interest Collected' =>
                    data_get(
                        $metadata,
                        'od_interest_collected',
                        ''
                    ),

                    'Excess Amount Collected' => round(
                        (float) $transaction->unallocated_amount,
                        2
                    ),

                    'Penal Interest Collected' => '',

                    'Bounce Charge' => $bounceBase,

                    'Fore Closure Charges' => $foreclosureBase,

                    'Bounce Charges(With GST)' => $bounceWithGst,

                    'Penalty Charges(With GST)' => $penaltyWithGst,

                    'Other Charges' => '',

                    'Other Charges(With GST)' => '',

                    'Fore Closure Charges(With GST)' =>
                    $foreclosureWithGst,

                    'Paid By' =>
                    data_get($metadata, 'paid_by', ''),

                    'Collection Type' => $transactionType ?? '',

                    'Pay Mode' => $paymentMode ?? '',

                    'Wave Off Amount' => $waivedAmount,

                    'Paid By Borrower' => round(
                        (float) $transaction->amount,
                        2
                    ),

                    'Collection Status' =>
                    $transactionStatus ?? '',

                    'Collection Mode' =>
                    data_get(
                        $metadata,
                        'collection_mode',
                        $paymentMode ?? ''
                    ),

                    'Receipt Number' =>
                    $transaction->receipt?->receipt_number ?? '',

                    'Write Off' =>
                    data_get($metadata, 'write_off', ''),

                    'UPI Transaction ID' =>
                    $paymentMode === 'UPI'
                        ? ($transaction->transaction_reference ?? '')
                        : data_get(
                            $metadata,
                            'upi_transaction_id',
                            ''
                        ),

                    // 'Created By' =>
                    // $userDisplayName($createdByUser)
                    //     ?: ($transaction->created_by ?? ''),

                    'Created By' =>
                    $createdByUser?->user_name ?? '',

                    'Created On' =>
                    $transaction->created_at
                        ?->format('Y-m-d H:i:s') ?? '',

                    'Modified By' =>
                    $modifiedByUser?->user_name ?? '',

                    'Modified On' =>
                    $transaction->updated_at
                        ?->format('Y-m-d H:i:s') ?? '',

                    'Comments' =>
                    $transaction->remarks ?? '',

                    'Collection Reversal Reason' =>
                    $transaction->reversal?->remarks
                        ?? data_get(
                            $metadata,
                            'collection_reversal_reason',
                            ''
                        ),

                    'Latitude' =>
                    data_get($metadata, 'latitude', ''),

                    'Longitude' =>
                    data_get($metadata, 'longitude', ''),

                    'Source' =>
                    data_get($metadata, 'source', ''),

                    'OD Reason' =>
                    data_get($metadata, 'od_reason', ''),
                ];
            })
            ->values();

        return response()->json([
            'success' => true,
            'message' =>
            'Repayment collection report fetched successfully.',

            'data' => [
                'from_date' => $fromDate,
                'to_date' => $toDate,

                /*
             * Column order exactly Excel report के अनुसार।
             */
                'columns' => [
                    'Region',
                    'Unit',
                    'Branch ID',
                    'Loan Officer',
                    'Partner Bank ID',
                    'Loan ID',
                    'Cust ID',
                    'NAME',
                    'Product ID',
                    'Collected By',
                    'Collection Date',
                    'Seq Num',
                    'Amount',
                    'Principal',
                    'Interest',
                    'Penalty Charge',
                    'OD Interest Collected',
                    'Excess Amount Collected',
                    'Penal Interest Collected',
                    'Bounce Charge',
                    'Fore Closure Charges',
                    'Bounce Charges(With GST)',
                    'Penalty Charges(With GST)',
                    'Other Charges',
                    'Other Charges(With GST)',
                    'Fore Closure Charges(With GST)',
                    'Paid By',
                    'Collection Type',
                    'Pay Mode',
                    'Wave Off Amount',
                    'Paid By Borrower',
                    'Collection Status',
                    'Collection Mode',
                    'Receipt Number',
                    'Write Off',
                    'UPI Transaction ID',
                    'Created By',
                    'Created On',
                    'Modified By',
                    'Modified On',
                    'Comments',
                    'Collection Reversal Reason',
                    'Latitude',
                    'Longitude',
                    'Source',
                    'OD Reason',
                ],

                'rows' => $rows,

                'pagination' => [
                    'current_page' => $transactions->currentPage(),
                    'per_page' => $transactions->perPage(),
                    'last_page' => $transactions->lastPage(),
                    'total' => $transactions->total(),
                    'from' => $transactions->firstItem(),
                    'to' => $transactions->lastItem(),
                ],
            ],
        ]);
    }

       public function posReport(Request $request): JsonResponse
    {


        $validated = $request->validate([
            'as_on_date' => 'nullable|date',
            'from_date'  => 'nullable|date',
            'to_date'    => 'nullable|date|after_or_equal:from_date',
            'branch_id'  => 'nullable|integer|exists:organization_branches,id',
            'status'     => 'nullable|string|max:50',
            'od_bucket' => 'nullable|in:CURRENT,1-30,31-60,61-90,90+',
            'search'     => 'nullable|string|max:255',
            'per_page'   => 'nullable|integer|min:1|max:500',
        ]);

        // $asOnDate = $validated['as_on_date']
        //     ?? now()->toDateString();
        $asOnDate = $validated['to_date']
            ?? $validated['as_on_date']
            ?? now()->toDateString();

        $fromDateFilter = $validated['from_date'] ?? null;
        $toDateFilter   = $validated['to_date'] ?? null;

        $branchId = !empty($validated['branch_id'])
            ? (int) $validated['branch_id']
            : null;

        $statusFilter = !empty($validated['status'])
            ? strtoupper(trim($validated['status']))
            : null;

        $searchTerm = $validated['search'] ?? null;

        $odBucketFilter = !empty($validated['od_bucket'])
            ? strtoupper(trim($validated['od_bucket']))
            : null;

        $fromDateFilter = $validated['from_date'] ?? null;
        $toDateFilter = $validated['to_date'] ?? null;

        Log::channel('lms_audit')->info('POS_REPORT_REQUEST', [
            'user_id' => $request->user()?->id,
            'request_url' => $request->fullUrl(),
            'request_method' => $request->method(),
            'request_params' => $request->query(),
            'validated_params' => $validated,

            'as_on_date' => $asOnDate,
            'from_date' => $fromDateFilter,
            'to_date' => $toDateFilter,
            'branch_id' => $branchId,
            'status' => $statusFilter,
            'od_bucket' => $odBucketFilter,
            'search' => $searchTerm,
            'page' => (int) $request->input('page', 1),
            // 'per_page' => $perPage,

            'host' => $request->getHost(),
            'controller_file' => __FILE__,
            'ip_address' => $request->ip(),
        ]);

        $perPage = (int) ($validated['per_page'] ?? 100);


        $gstPercentage = 18;

        $enumValue = static function ($value) {
            return $value instanceof BackedEnum
                ? $value->value
                : $value;
        };

        $money = static function ($value): float {
            return round((float) ($value ?? 0), 2);
        };

        $dateFormat = static function ($value): string {
            if (!$value) {
                return '';
            }

            try {
                return Carbon::parse($value)->format('d-M-y');
            } catch (\Throwable $exception) {
                return '';
            }
        };

        $accounts = RepaymentAccount::query()
            ->when(
                $branchId !== null,
                function ($query) use ($branchId) {
                    $query->whereHas(
                        'lead',
                        function ($leadQuery) use ($branchId) {
                            $leadQuery->where('branch_id', $branchId);
                        }
                    );
                }
            )
            ->when(
                $statusFilter,
                function ($query) use ($statusFilter) {
                    $query->where('status', $statusFilter);
                }
            )
            ->when(
                $searchTerm,
                function ($query) use ($searchTerm) {
                    $query->where(function ($q) use ($searchTerm) {
                        $q->where('loan_number', 'like', "%{$searchTerm}%")
                            ->orWhereHas('lead', function ($leadQuery) use ($searchTerm) {
                                $leadQuery->where('lead_id', 'like', "%{$searchTerm}%")
                                    ->orWhere('mobile_number', 'like', "%{$searchTerm}%")
                                    ->orWhereHas('applicantDetails', function ($applicantQuery) use ($searchTerm) {
                                        $applicantQuery->where('full_name', 'like', "%{$searchTerm}%");
                                    });
                            });
                    });
                }
            )
            /*
             * Historical POS eligibility business disbursement date se hogi.
             * activated_at system-entry date ho sakti hai, isliye old/imported
             * loans ko historical snapshot se exclude nahi karna hai.
             * From Date ko lower disbursement bound nahi banayenge: POS as-on
             * snapshot mein as-on date tak disbursed sabhi loans aane chahiye.
             */
            ->whereIn(
                'lead_uuid',
                \Modules\BLDisbursment\Models\BLDisbursment::query()
                    ->whereDate('disbursement_date', '<=', $asOnDate)
                    ->select('lead_uuid')
            )
            ->when(
                $odBucketFilter,
                function ($query) use ($odBucketFilter, $asOnDate) {
                    $asOn = Carbon::parse($asOnDate)->startOfDay();

                    $dayBefore = $asOn->copy()->subDay()->toDateString();
                    $day30 = $asOn->copy()->subDays(30)->toDateString();
                    $day31 = $asOn->copy()->subDays(31)->toDateString();
                    $day60 = $asOn->copy()->subDays(60)->toDateString();
                    $day61 = $asOn->copy()->subDays(61)->toDateString();
                    $day90 = $asOn->copy()->subDays(90)->toDateString();
                    $day91 = $asOn->copy()->subDays(91)->toDateString();

                    $outstandingCondition = function ($installmentQuery) {
                        $installmentQuery->where('outstanding_amount', '>', 0);
                    };

                    switch ($odBucketFilter) {
                        case 'CURRENT':
                            /*
                 * Due date today होने पर OD days 0 होते हैं,
                 * इसलिए account CURRENT bucket में रहेगा।
                 */
                            $query->whereDoesntHave(
                                'installments',
                                function ($installmentQuery) use (
                                    $dayBefore,
                                    $outstandingCondition
                                ) {
                                    $installmentQuery
                                        ->whereDate('due_date', '<=', $dayBefore)
                                        ->where($outstandingCondition);
                                }
                            );
                            break;

                        case '1-30':
                            $query
                                ->whereHas(
                                    'installments',
                                    function ($installmentQuery) use (
                                        $day30,
                                        $dayBefore,
                                        $outstandingCondition
                                    ) {
                                        $installmentQuery
                                            ->whereBetween('due_date', [
                                                $day30,
                                                $dayBefore,
                                            ])
                                            ->where($outstandingCondition);
                                    }
                                )
                                ->whereDoesntHave(
                                    'installments',
                                    function ($installmentQuery) use (
                                        $day31,
                                        $outstandingCondition
                                    ) {
                                        $installmentQuery
                                            ->whereDate('due_date', '<=', $day31)
                                            ->where($outstandingCondition);
                                    }
                                );
                            break;

                        case '31-60':
                            $query
                                ->whereHas(
                                    'installments',
                                    function ($installmentQuery) use (
                                        $day60,
                                        $day31,
                                        $outstandingCondition
                                    ) {
                                        $installmentQuery
                                            ->whereBetween('due_date', [
                                                $day60,
                                                $day31,
                                            ])
                                            ->where($outstandingCondition);
                                    }
                                )
                                ->whereDoesntHave(
                                    'installments',
                                    function ($installmentQuery) use (
                                        $day61,
                                        $outstandingCondition
                                    ) {
                                        $installmentQuery
                                            ->whereDate('due_date', '<=', $day61)
                                            ->where($outstandingCondition);
                                    }
                                );
                            break;

                        case '61-90':
                            $query
                                ->whereHas(
                                    'installments',
                                    function ($installmentQuery) use (
                                        $day90,
                                        $day61,
                                        $outstandingCondition
                                    ) {
                                        $installmentQuery
                                            ->whereBetween('due_date', [
                                                $day90,
                                                $day61,
                                            ])
                                            ->where($outstandingCondition);
                                    }
                                )
                                ->whereDoesntHave(
                                    'installments',
                                    function ($installmentQuery) use (
                                        $day91,
                                        $outstandingCondition
                                    ) {
                                        $installmentQuery
                                            ->whereDate('due_date', '<=', $day91)
                                            ->where($outstandingCondition);
                                    }
                                );
                            break;

                        case '90+':
                            $query->whereHas(
                                'installments',
                                function ($installmentQuery) use (
                                    $day91,
                                    $outstandingCondition
                                ) {
                                    $installmentQuery
                                        ->whereDate('due_date', '<=', $day91)
                                        ->where($outstandingCondition);
                                }
                            );
                            break;
                    }
                }
            )
            ->with([
                'lead',
                'lead.branch',
                'lead.branch.parentBranch',
                'lead.branch.parentBranch.parentBranch',
                'installments',
                'transactions' => function ($query) use ($asOnDate) {
                    $query
                        ->whereDate('received_date', '<=', $asOnDate)
                        ->orderBy('received_date')
                        ->orderBy('id');
                },
                'transactions.allocations',
                'charges',
            ])
            ->orderByDesc('id')
            ->paginate($perPage);

        Log::channel('lms_audit')->info('POS_REPORT_QUERY_RESULT', [
            'user_id' => $request->user()?->id,
            'od_bucket_filter' => $odBucketFilter,
            'as_on_date' => $asOnDate,

            'current_page' => $accounts->currentPage(),
            'per_page' => $accounts->perPage(),
            'last_page' => $accounts->lastPage(),
            'total_accounts' => $accounts->total(),
            'current_page_count' => $accounts->count(),

            'account_ids' => $accounts->getCollection()
                ->pluck('id')
                ->values()
                ->all(),

            'loan_numbers' => $accounts->getCollection()
                ->pluck('loan_number')
                ->filter()
                ->values()
                ->all(),
        ]);

        $accountCollection = $accounts->getCollection();

        $leadUuids = $accountCollection
            ->pluck('lead_uuid')
            ->filter()
            ->unique()
            ->values();

        $accountIds = $accountCollection
            ->pluck('id')
            ->filter()
            ->unique()
            ->values();


        $applicantsByLead = BLApplicantDetails::query()
            ->whereIn('lead_uuid', $leadUuids)
            ->orderBy('applicant_sno')
            ->orderBy('id')
            ->get()
            ->groupBy('lead_uuid');

        /*
     * Bank details।
     */
        $bankDetailsByLead = BLBankDetail::query()
            ->whereIn('lead_uuid', $leadUuids)
            ->orderBy('applicant_sno')
            ->orderBy('id')
            ->get()
            ->groupBy('lead_uuid');

        /*
     * Credit Visit Report।
     */
        $creditReportsByLead = BLCreditVisitReport::query()
            ->whereIn('lead_uuid', $leadUuids)
            ->orderByDesc('id')
            ->get()
            ->groupBy('lead_uuid')
            ->map(fn($records) => $records->first());

        /*
     * Disbursement।
     */
        $disbursementsByLead = BLDisbursment::query()
            ->whereIn('lead_uuid', $leadUuids)
            ->orderByDesc('id')
            ->get()
            ->groupBy('lead_uuid')
            ->map(fn($records) => $records->first());

        /*
     * Amortization।
     */
        $amortizationsByLead = BLAmortization::query()
            ->whereIn('lead_uuid', $leadUuids)
            ->orderByDesc('id')
            ->get()
            ->groupBy('lead_uuid')
            ->map(fn($records) => $records->first());

        /*
     * Latest CAM।
     */
        $camsByLead = BLCam::query()
            ->whereIn('lead_uuid', $leadUuids)
            ->orderByDesc('id')
            ->get()
            ->groupBy('lead_uuid')
            ->map(fn($records) => $records->first());

        /*
     * Product scheme।
     */
        $schemeSelectionsByLead = BLSchemeSelect::query()
            ->whereIn('lead_uuid', $leadUuids)
            ->with('productScheme')
            ->orderByDesc('id')
            ->get()
            ->groupBy('lead_uuid')
            ->map(fn($records) => $records->first());

        /*
     * Latest active loan assignment।
     */
        $assignmentsByLead = BLLeadAssignment::query()
            ->whereIn('lead_uuid', $leadUuids)
            ->where('is_active', true)
            ->orderByDesc('assigned_at')
            ->orderByDesc('id')
            ->get()
            ->groupBy('lead_uuid')
            ->map(fn($records) => $records->first());

        /*
     * Charges।
     */
        $loanChargesByLead = Charge::query()
            ->whereIn('lead_uuid', $leadUuids)
            ->orderByDesc('id')
            ->get()
            ->groupBy('lead_uuid')
            ->map(fn($records) => $records->first());

        /*
     * Insurance।
     */
        $insuranceByLead = Insurance::query()
            ->whereIn('lead_uuid', $leadUuids)
            ->orderByDesc('id')
            ->get()
            ->groupBy('lead_uuid')
            ->map(fn($records) => $records->first());

        /*
     * LUC report।
     */
        $lucReportsByLead = LucReport::query()
            ->whereIn('lead_uuid', $leadUuids)
            ->orderByDesc('id')
            ->get()
            ->groupBy('lead_uuid')
            ->map(fn($records) => $records->first());

        /*
     * NOC।
     */
        $nocByAccount = NocCertificate::query()
            ->whereIn('repayment_account_id', $accountIds)
            ->orderByDesc('issue_date')
            ->orderByDesc('id')
            ->get()
            ->groupBy('repayment_account_id')
            ->map(fn($records) => $records->first());

        /*
     * Settlement।
     */
        $settlementsByAccount = RepaymentSettlement::query()
            ->whereIn('repayment_account_id', $accountIds)
            ->orderByDesc('id')
            ->get()
            ->groupBy('repayment_account_id')
            ->map(fn($records) => $records->first());

        /*
     * Loan Officer users।
     */
        $legacyUserIds = $assignmentsByLead
            ->pluck('assigned_to')
            ->filter(fn($value) => is_numeric($value))
            ->map(fn($value) => (int) $value);

        $userIds = collect()
            ->merge($assignmentsByLead->pluck('assigned_to_user_id'))
            ->merge($assignmentsByLead->pluck('assigned_by_user_id'))
            ->merge($legacyUserIds)
            ->filter()
            ->unique()
            ->values();

        $usersById = User::query()
            ->whereIn('id', $userIds)
            ->get()
            ->keyBy('id');



        $userDisplayName = static function ($user): string {
            if (!$user) {
                return '';
            }

            return $user->user_name ?? '';
        };

        $rows = $accountCollection
            ->map(function ($account) use (
                $asOnDate,
                $gstPercentage,
                $applicantsByLead,
                $bankDetailsByLead,
                $creditReportsByLead,
                $disbursementsByLead,
                $amortizationsByLead,
                $camsByLead,
                $schemeSelectionsByLead,
                $assignmentsByLead,
                $loanChargesByLead,
                $insuranceByLead,
                $lucReportsByLead,
                $nocByAccount,
                $settlementsByAccount,
                $usersById,
                $userDisplayName,
                $enumValue,
                $money,
                $dateFormat
            ) {
                $lead = $account->lead;
                $leadUuid = $account->lead_uuid;

                $branch = $lead?->branch;
                $unit = $branch?->parentBranch;
                $region = $unit?->parentBranch;

                $applicants = $applicantsByLead
                    ->get($leadUuid, collect());

                /*
             * Primary applicant।
             */
                $primaryApplicant = $applicants
                    ->first(function ($applicant) {
                        return !((bool) $applicant->is_co_applicant);
                    });

                if (!$primaryApplicant) {
                    $primaryApplicant = $applicants->first();
                }

                /*
             * Co-applicant।
             */
                $coApplicant = $applicants
                    ->first(function ($applicant) {
                        return (bool) $applicant->is_co_applicant;
                    });

                $bankDetail = $bankDetailsByLead
                    ->get($leadUuid, collect())
                    ->first();

                $creditReport = $creditReportsByLead->get($leadUuid);
                $disbursement = $disbursementsByLead->get($leadUuid);
                $amortization = $amortizationsByLead->get($leadUuid);
                $cam = $camsByLead->get($leadUuid);
                $schemeSelection = $schemeSelectionsByLead->get($leadUuid);
                $scheme = $schemeSelection?->productScheme;
                $assignment = $assignmentsByLead->get($leadUuid);
                $loanCharge = $loanChargesByLead->get($leadUuid);
                $insurance = $insuranceByLead->get($leadUuid);
                $lucReport = $lucReportsByLead->get($leadUuid);
                $noc = $nocByAccount->get($account->id);
                $settlement = $settlementsByAccount->get($account->id);

                $loanOfficerId = $assignment?->assigned_to_user_id;

                if (!$loanOfficerId && is_numeric($assignment?->assigned_to)) {
                    $loanOfficerId = (int) $assignment->assigned_to;
                }

                $loanOfficer = $loanOfficerId
                    ? $usersById->get($loanOfficerId)
                    : null;

                $installments = $account->installments ?? collect();

                $postedPayments = ($account->transactions ?? collect())
                    ->filter(function ($transaction) use ($enumValue) {
                        return $enumValue($transaction->transaction_type)
                            === RepaymentTransactionType::PAYMENT->value
                            && $enumValue($transaction->status)
                            === RepaymentTransactionStatus::POSTED->value;
                    });

                $allocations = $postedPayments
                    ->flatMap(fn($transaction) => $transaction->allocations);

                $principalCollected = $money(
                    $allocations
                        ->filter(function ($allocation) use ($enumValue) {
                            return $enumValue($allocation->component)
                                === 'PRINCIPAL';
                        })
                        ->sum('amount')
                );

                $interestCollected = $money(
                    $allocations
                        ->filter(function ($allocation) use ($enumValue) {
                            return $enumValue($allocation->component)
                                === 'INTEREST';
                        })
                        ->sum('amount')
                );

                /*
                 * EMI-period snapshot:
                 * selected date par ya usse pehle ki latest EMI row ka opening
                 * principal next EMI date tak unchanged rahega.
                 *
                 * Example:
                 * 29-Sep-2025 se 05-Oct-2025 = 29-Sep EMI opening principal.
                 * 06-Oct-2025 se next EMI tak = 06-Oct EMI opening principal.
                 */
                $snapshotInstallment = $installments
                    ->filter(function ($installment) use ($asOnDate) {
                        return $installment->due_date
                            && $installment->due_date->toDateString() <= $asOnDate;
                    })
                    ->sortByDesc(function ($installment) {
                        return $installment->due_date->toDateString();
                    })
                    ->first();

                if ($snapshotInstallment) {
                    $snapshotDueDate =
                        $snapshotInstallment->due_date->toDateString();

                    $principalOutstandingAsOn = $money(
                        $snapshotInstallment->opening_principal
                            ?? $account->principal_amount
                    );

                    /*
                     * Snapshot EMI ka interest outstanding mein include rahega.
                     * Sirf snapshot EMI se pehle ka scheduled interest deduct hoga.
                     */
                    $interestBeforeSnapshot = $money(
                        $installments
                            ->filter(function ($installment) use ($snapshotDueDate) {
                                return $installment->due_date
                                    && $installment->due_date->toDateString()
                                        < $snapshotDueDate;
                            })
                            ->sum('interest_due')
                    );

                    $interestOutstandingAsOn = $money(
                        max(
                            0,
                            (float) $account->total_interest
                                - (float) $interestBeforeSnapshot
                        )
                    );
                } else {
                    /* Selected date first EMI se pehle hai. */
                    $principalOutstandingAsOn = $money(
                        $account->principal_amount
                    );

                    $interestOutstandingAsOn = $money(
                        $account->total_interest
                    );
                }

                $overdueInstallments = $installments
                    ->filter(function ($installment) use ($asOnDate) {
                        return $installment->due_date
                            && $installment->due_date->toDateString()
                            <= $asOnDate
                            && (float) $installment->outstanding_amount > 0;
                    });

                $principalArrear = $money(
                    $overdueInstallments->sum(function ($installment) {
                        return max(
                            0,
                            (float) $installment->principal_due
                                - (float) $installment->principal_paid
                        );
                    })
                );

                $interestArrear = $money(
                    $overdueInstallments->sum(function ($installment) {
                        return max(
                            0,
                            (float) $installment->interest_due
                                - (float) $installment->interest_paid
                        );
                    })
                );

                $totalArrear = $money(
                    $principalArrear + $interestArrear
                );

                $firstInstallment = $installments
                    ->sortBy('installment_number')
                    ->first();

                $lastInstallment = $installments
                    ->sortByDesc('installment_number')
                    ->first();

                $nextInstallment = $installments
                    ->filter(function ($installment) use ($asOnDate) {
                        return $installment->due_date
                            && $installment->due_date->toDateString()
                            > $asOnDate
                            && (float) $installment->outstanding_amount > 0;
                    })
                    ->sortBy('due_date')
                    ->first();

                $lastCollection = $postedPayments
                    ->sortByDesc('received_date')
                    ->first();

                $installmentOutstandingCount = $installments
                    ->filter(function ($installment) {
                        return (float) $installment->outstanding_amount > 0;
                    })
                    ->count();

                $oldestOverdue = $overdueInstallments
                    ->sortBy('due_date')
                    ->first();

                $odDays = 0;

                if ($oldestOverdue?->due_date) {
                    $odDays = max(
                        0,
                        (int) $oldestOverdue->due_date->diffInDays(
                            Carbon::parse($asOnDate),
                            false
                        )
                    );
                }

                $odBucket = match (true) {
                    $odDays <= 0 => 'CURRENT',
                    $odDays <= 30 => '1-30',
                    $odDays <= 60 => '31-60',
                    $odDays <= 90 => '61-90',
                    default => '90+',
                };

                $repaymentChargeWaiver = $money(
                    ($account->charges ?? collect())
                        ->sum('waived_amount')
                );

                $settlementWaiver = $money(
                    $settlement?->waived_amount
                );

                $totalWaiver = $money(
                    $repaymentChargeWaiver + $settlementWaiver
                );

                $settlementStatus = $enumValue($settlement?->status);

                $netOffAmount = in_array(
                    $settlementStatus,
                    ['APPROVED', 'PAID'],
                    true
                )
                    ? $money($settlement?->settlement_amount)
                    : 0.0;


                $totalCharges = $money($loanCharge?->total_charges);

                $chargesGst = $money(
                    ($totalCharges * $gstPercentage) / 100
                );

                $totalChargesWithGst = $money(
                    $totalCharges + $chargesGst
                );

                $status = $enumValue($account->status);

                $closureRemark = '';

                if ($noc) {
                    $closureRemark = $noc->remarks
                        ?: $noc->closure_type
                        ?: 'NOC Issued';
                } elseif ($status === 'CLOSED') {
                    $closureRemark = 'Closed';
                }

                return [
                    'CUST_ID' => $lead?->lead_id ?? '',

                    'LOAN_NO' =>
                    $account->loan_number
                        ?? $disbursement?->loan_number
                        ?? '',

                    'MEMBER_NAME' =>
                    $primaryApplicant?->full_name ?? '',

                    'DATE_OF_BIRTH' =>
                    $dateFormat($primaryApplicant?->date_of_birth),

                    'AGE' => $primaryApplicant?->age ?? '',

                    'MOBILE_NUMBER' => $lead?->mobile_number ?? '',

                    /*
                 * Nominee columns removed।
                 * Co-applicant columns added।
                 */
                    'CO_APPLICANT_NAME' =>
                    $coApplicant?->full_name ?? '',

                    'CO_APPLICANT_DOB' =>
                    $dateFormat($coApplicant?->date_of_birth),

                    'CO_APPLICANT_AGE' =>
                    $coApplicant?->age ?? '',

                    'CO_APPLICANT_RELATIONSHIP' =>
                    $coApplicant?->relationship ?? '',

                    'SPOUSE NAME' =>
                    $primaryApplicant?->spouse_full_name ?? '',

                    'FATHER NAME' =>
                    $primaryApplicant?->father_full_name ?? '',

                    'CASTE' => $primaryApplicant?->caste ?? '',

                    'RELIGION' => $primaryApplicant?->religion ?? '',

                    /*
                 * Applicant KYC columns।
                 * PAN और VOTER removed।
                 */
                    'AADHAAR_NUMBER' =>
                    $primaryApplicant?->aadhaar_card_number ?? '',

                    'SECONDARY_KYC_TYPE' =>
                    $primaryApplicant?->secondary_kyc_type ?? '',

                    'SECONDARY_KYC_NUMBER' =>
                    $primaryApplicant?->secondary_kyc_number ?? '',

                    'DISBURSEMENT_DATE' =>
                    $dateFormat($disbursement?->disbursement_date),

                    'RURAL/URBAN' => '',

                    'PARTNER_BANK_NAME' => '',

                    /*
                 * Credit Visit Report से loan purpose।
                 */
                    'LOAN_PURPOSE' =>
                    $creditReport?->loan_purpose ?? '',

                    'SUB_PURPOSE' =>
                    $creditReport?->sub_industry ?? '',

                    'STATUS' => $status ?? '',

                    'Closure_Remark' => $closureRemark,

                    'LOAN_CREATED_ON' =>
                    $dateFormat($lead?->created_at),

                    'ADDRESS' =>
                    $primaryApplicant?->current_address ?? '',

                    'VILLAGE_NAME' =>
                    $primaryApplicant?->current_city ?? '',

                    'DISTRICT' =>
                    $primaryApplicant?->current_district ?? '',

                    'STATE' =>
                    $primaryApplicant?->current_state ?? '',

                    'PINCODE' =>
                    $primaryApplicant?->current_pincode ?? '',

                    'FUNDER_ID' => '',
                    'FUND_ALLOCATION_DATE' => '',
                    'LENDER_ID' => '',

                    'BRANCH ID & NAME' => $branch
                        ? trim(
                            ($branch->branch_code
                                ? $branch->branch_code . ' - '
                                : '') . $branch->name
                        )
                        : '',

                    'BRANCH_STATE' => $branch?->state ?? '',

                    'REGION' => $region
                        ? trim(
                            ($region->branch_code
                                ? $region->branch_code . ' - '
                                : '') . $region->name
                        )
                        : '',

                    'UNIT' => $unit
                        ? trim(
                            ($unit->branch_code
                                ? $unit->branch_code . ' - '
                                : '') . $unit->name
                        )
                        : '',



                    'LO_NAME' =>
                    $loanOfficer?->user_name ?? '',

                    'BANK_ACCOUNT' =>
                    $bankDetail?->account_number ?? '',

                    'BANK_IFSC_CODE' =>
                    $bankDetail?->ifsc_code ?? '',

                    'DISBURSE_BY_FO' => '',

                    // 'EMPCODE' => $loanOfficerId ?? '',
                    'EMPCODE' => $loanOfficer?->user_code ?? '',

                    'PRINCIPAL_ARREAR' => $principalArrear,

                    'INTEREST_ARREAR' => $interestArrear,

                    'TOTAL_ARREAR' => $totalArrear,

                    'PRIN_COLLECTED' => $principalCollected,

                    'INT_COLLECCTED' => $interestCollected,

                    'FIRST_DUE_AMOUNT' =>
                    $money($firstInstallment?->installment_amount),

                    'FIRST_DEMAND_DATE' =>
                    $dateFormat($firstInstallment?->due_date),

                    'LAST_EMI' =>
                    $money($lastInstallment?->installment_amount),

                    'IS_QUALIFY' =>
                    $lucReport?->utilization_status ?? '',

                    'LAST_MATURITY_DATE' =>
                    $dateFormat(
                        $account->maturity_date
                            ?? $lastInstallment?->due_date
                    ),

                    'LAST_COLL_DATE' =>
                    $dateFormat($lastCollection?->received_date),

                    'LAST_COLL_AMOUNT' =>
                    $money($lastCollection?->amount),

                    'PRODUCT_CATEGORY_ID' =>
                    $scheme?->product_name ?? '',

                    'PRODUCT_ID' =>
                    $scheme?->scheme_name
                        ?? $scheme?->uuid
                        ?? '',

                    'PRINCIPAL_TOTAL' =>
                    $money($account->principal_amount),

                    'INT_RATE' => round(
                        (float) (
                            $account->interest_rate
                            ?? $scheme?->interest_rate
                            ?? 0
                        ),
                        2
                    ),

                    'PROCESSING_FEE' =>
                    $money(
                        $amortization?->processing_fee
                            ?? $cam?->pf
                    ),

                    'TENURE' =>
                    $account->tenure
                        ?? $amortization?->tenure
                        ?? '',

                    'TOTAL_INTEREST' =>
                    $money($account->total_interest),

                    'TOTAL_INSTAL' =>
                    $account->installment_count
                        ?? $installments->count(),

                    'INSTAL_OS' => $installmentOutstandingCount,

                    'INSURANCE_ID' =>
                    $insurance?->id ?? '',

                    'INSURANCE_CHARGES' =>
                    $money($insurance?->insurance_amount),

                    /*
                 * Charge fields।
                 */
                    'TOTAL_CHARGES' => $totalCharges,

                    'TOTAL_CHARGES_WITH_GST' =>
                    $totalChargesWithGst,



                    'FO_ID' =>
                    $loanOfficer?->user_name ?? '',

                    'PAYMENT_REF_NUMBER' =>
                    $lastCollection?->transaction_reference ?? '',

                    'CYCLE' => $account->schedule_version ?? '',

                    'REPAYMENT_FREQUENCY' =>
                    $account->frequency
                        ?? $scheme?->repayment_frequency
                        ?? '',

                    'NEXT_DEMAND_DATE' =>
                    $dateFormat(
                        $account->next_due_date
                            ?? $nextInstallment?->due_date
                    ),

                    'LOAN_CLOSURE_DATE' =>
                    $dateFormat(
                        $account->closed_at
                            ?? $noc?->issue_date
                    ),

                    'PROD_CLASSIFICATION' =>
                    $scheme?->sub_loan_type
                        ?? $lead?->sub_loan_type
                        ?? '',

                    'REPAYMENT_MODE' =>
                    $enumValue($lastCollection?->payment_mode) ?? '',

                    'REPAYMENT_BANK' =>
                    $disbursement?->bank_name ?? '',

                    'EXT_LOAN_ID' => '',

                    'INSURANCE_MATURITY_DATE' => '',

                    // 'OUTSTANDING_PRINCIPAL' =>
                    // $money($account->principal_outstanding),

                    // 'OUTSTANDING_INTEREST' =>
                    // $money($account->interest_outstanding),

                    'OUTSTANDING_PRINCIPAL' =>
                    $principalOutstandingAsOn,

                    'OUTSTANDING_INTEREST' =>
                    $interestOutstandingAsOn,

                    'TOTAL_ARREAR_2' => $totalArrear,

                    'OD_DAYS' => $odDays,

                    'OD_BUCKET' => $odBucket,

                    'NETOFF_AMOUNT' => $netOffAmount,

                    'DOCUMENT_CHARGES' => '',

                    'STAMPDUTY_CHARGES' => '',

                    'LEGAL_FEE' => '',

                    'APPLICATION_FEE' =>
                    $money($loanCharge?->application_charges),

                    'PRE_EMI_AMT' => '',

                    'Fee_Pay_Mode' => '',

                    'NPA_DATE' => '',

                    'EXCESS_AMOUNT' =>
                    $money($account->advance_amount),

                    'WAIVEOFF_AMOUNT' => $totalWaiver,

                    'OD_interest_collected_till_date' => '',

                    'WRITE_OFF_AMOUNT' => '',
                ];
            })
            ->values();

        $columns = [
            'CUST_ID',
            'LOAN_NO',
            'MEMBER_NAME',
            'DATE_OF_BIRTH',
            'AGE',
            'MOBILE_NUMBER',

            'CO_APPLICANT_NAME',
            'CO_APPLICANT_DOB',
            'CO_APPLICANT_AGE',
            'CO_APPLICANT_RELATIONSHIP',

            'SPOUSE NAME',
            'FATHER NAME',
            'CASTE',
            'RELIGION',

            'AADHAAR_NUMBER',
            'SECONDARY_KYC_TYPE',
            'SECONDARY_KYC_NUMBER',

            'DISBURSEMENT_DATE',
            'RURAL/URBAN',
            'PARTNER_BANK_NAME',

            'LOAN_PURPOSE',
            'SUB_PURPOSE',

            'STATUS',
            'Closure_Remark',
            'LOAN_CREATED_ON',
            'ADDRESS',
            'VILLAGE_NAME',
            'DISTRICT',
            'STATE',
            'PINCODE',
            'FUNDER_ID',
            'FUND_ALLOCATION_DATE',
            'LENDER_ID',
            'BRANCH ID & NAME',
            'BRANCH_STATE',
            'REGION',
            'UNIT',
            'LO_NAME',
            'BANK_ACCOUNT',
            'BANK_IFSC_CODE',
            'DISBURSE_BY_FO',
            'EMPCODE',
            'PRINCIPAL_ARREAR',
            'INTEREST_ARREAR',
            'TOTAL_ARREAR',
            'PRIN_COLLECTED',
            'INT_COLLECCTED',
            'FIRST_DUE_AMOUNT',
            'FIRST_DEMAND_DATE',
            'LAST_EMI',
            'IS_QUALIFY',
            'LAST_MATURITY_DATE',
            'LAST_COLL_DATE',
            'LAST_COLL_AMOUNT',
            'PRODUCT_CATEGORY_ID',
            'PRODUCT_ID',
            'PRINCIPAL_TOTAL',
            'INT_RATE',
            'PROCESSING_FEE',
            'TENURE',
            'TOTAL_INTEREST',
            'TOTAL_INSTAL',
            'INSTAL_OS',

            'INSURANCE_ID',
            'INSURANCE_CHARGES',

            'TOTAL_CHARGES',
            'TOTAL_CHARGES_WITH_GST',

            'FO_ID',
            'PAYMENT_REF_NUMBER',
            'CYCLE',
            'REPAYMENT_FREQUENCY',
            'NEXT_DEMAND_DATE',
            'LOAN_CLOSURE_DATE',
            'PROD_CLASSIFICATION',
            'REPAYMENT_MODE',
            'REPAYMENT_BANK',
            'EXT_LOAN_ID',
            'INSURANCE_MATURITY_DATE',
            'OUTSTANDING_PRINCIPAL',
            'OUTSTANDING_INTEREST',
            'TOTAL_ARREAR_2',
            'OD_DAYS',
            'OD_BUCKET',
            'NETOFF_AMOUNT',
            'DOCUMENT_CHARGES',
            'STAMPDUTY_CHARGES',
            'LEGAL_FEE',
            'APPLICATION_FEE',
            'PRE_EMI_AMT',
            'Fee_Pay_Mode',
            'NPA_DATE',
            'EXCESS_AMOUNT',
            'WAIVEOFF_AMOUNT',
            'OD_interest_collected_till_date',
            'WRITE_OFF_AMOUNT',
        ];

        $bucketCounts = $rows
            ->groupBy('OD_BUCKET')
            ->map(function ($bucketRows) {
                return $bucketRows->count();
            })
            ->all();

        $mismatchedRows = $odBucketFilter
            ? $rows
            ->filter(function ($row) use ($odBucketFilter) {
                return ($row['OD_BUCKET'] ?? null) !== $odBucketFilter;
            })
            ->take(20)
            ->map(function ($row) {
                return [
                    'cust_id' => $row['CUST_ID'] ?? null,
                    'loan_number' => $row['LOAN_NO'] ?? null,
                    'od_days' => $row['OD_DAYS'] ?? null,
                    'od_bucket' => $row['OD_BUCKET'] ?? null,
                    'total_arrear' => $row['TOTAL_ARREAR'] ?? null,
                ];
            })
            ->values()
            ->all()
            : [];

        Log::channel('lms_audit')->info('POS_REPORT_RESPONSE', [
            'user_id' => $request->user()?->id,
            'requested_od_bucket' => $request->query('od_bucket'),
            'applied_od_bucket' => $odBucketFilter,
            'as_on_date' => $asOnDate,

            'paginator_total' => $accounts->total(),
            'response_row_count' => $rows->count(),
            'bucket_counts' => $bucketCounts,
            'mismatched_row_count' => count($mismatchedRows),
            'mismatched_rows' => $mismatchedRows,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'POS report fetched successfully.',
            'data' => [
                'as_on_date' => $asOnDate,
                'gst_percentage' => $gstPercentage,
                'columns' => $columns,
                'rows' => $rows,
                'pagination' => [
                    'current_page' => $accounts->currentPage(),
                    'per_page' => $accounts->perPage(),
                    'last_page' => $accounts->lastPage(),
                    'total' => $accounts->total(),
                    'from' => $accounts->firstItem(),
                    'to' => $accounts->lastItem(),
                ],
            ],
        ]);
    }

    // public function posReport(Request $request): JsonResponse
    // {


    //     $validated = $request->validate([
    //         'as_on_date' => 'nullable|date',
    //         'from_date'  => 'nullable|date',
    //         'to_date'    => 'nullable|date|after_or_equal:from_date',
    //         'branch_id'  => 'nullable|integer|exists:organization_branches,id',
    //         'status'     => 'nullable|string|max:50',
    //         'od_bucket' => 'nullable|in:CURRENT,1-30,31-60,61-90,90+',
    //         'search'     => 'nullable|string|max:255',
    //         'per_page'   => 'nullable|integer|min:1|max:500',
    //     ]);

    //     // $asOnDate = $validated['as_on_date']
    //     //     ?? now()->toDateString();
    //     $asOnDate = $validated['to_date']
    //         ?? $validated['as_on_date']
    //         ?? now()->toDateString();

    //     $fromDateFilter = $validated['from_date'] ?? null;
    //     $toDateFilter   = $validated['to_date'] ?? null;

    //     $branchId = !empty($validated['branch_id'])
    //         ? (int) $validated['branch_id']
    //         : null;

    //     $statusFilter = !empty($validated['status'])
    //         ? strtoupper(trim($validated['status']))
    //         : null;

    //     $searchTerm = $validated['search'] ?? null;

    //     $odBucketFilter = !empty($validated['od_bucket'])
    //         ? strtoupper(trim($validated['od_bucket']))
    //         : null;

    //     $fromDateFilter = $validated['from_date'] ?? null;
    //     $toDateFilter = $validated['to_date'] ?? null;

    //     Log::channel('lms_audit')->info('POS_REPORT_REQUEST', [
    //         'user_id' => $request->user()?->id,
    //         'request_url' => $request->fullUrl(),
    //         'request_method' => $request->method(),
    //         'request_params' => $request->query(),
    //         'validated_params' => $validated,

    //         'as_on_date' => $asOnDate,
    //         'from_date' => $fromDateFilter,
    //         'to_date' => $toDateFilter,
    //         'branch_id' => $branchId,
    //         'status' => $statusFilter,
    //         'od_bucket' => $odBucketFilter,
    //         'search' => $searchTerm,
    //         'page' => (int) $request->input('page', 1),
    //         // 'per_page' => $perPage,

    //         'host' => $request->getHost(),
    //         'controller_file' => __FILE__,
    //         'ip_address' => $request->ip(),
    //     ]);

    //     $perPage = (int) ($validated['per_page'] ?? 100);


    //     $gstPercentage = 18;

    //     $enumValue = static function ($value) {
    //         return $value instanceof BackedEnum
    //             ? $value->value
    //             : $value;
    //     };

    //     $money = static function ($value): float {
    //         return round((float) ($value ?? 0), 2);
    //     };

    //     $dateFormat = static function ($value): string {
    //         if (!$value) {
    //             return '';
    //         }

    //         try {
    //             return Carbon::parse($value)->format('d-M-y');
    //         } catch (\Throwable $exception) {
    //             return '';
    //         }
    //     };

    //     $accounts = RepaymentAccount::query()
    //         ->when(
    //             $branchId !== null,
    //             function ($query) use ($branchId) {
    //                 $query->whereHas(
    //                     'lead',
    //                     function ($leadQuery) use ($branchId) {
    //                         $leadQuery->where('branch_id', $branchId);
    //                     }
    //                 );
    //             }
    //         )
    //         ->when(
    //             $statusFilter,
    //             function ($query) use ($statusFilter) {
    //                 $query->where('status', $statusFilter);
    //             }
    //         )
    //         ->when(
    //             $searchTerm,
    //             function ($query) use ($searchTerm) {
    //                 $query->where(function ($q) use ($searchTerm) {
    //                     $q->where('loan_number', 'like', "%{$searchTerm}%")
    //                         ->orWhereHas('lead', function ($leadQuery) use ($searchTerm) {
    //                             $leadQuery->where('lead_id', 'like', "%{$searchTerm}%")
    //                                 ->orWhere('mobile_number', 'like', "%{$searchTerm}%")
    //                                 ->orWhereHas('applicantDetails', function ($applicantQuery) use ($searchTerm) {
    //                                     $applicantQuery->where('full_name', 'like', "%{$searchTerm}%");
    //                                 });
    //                         });
    //                 });
    //             }
    //         )
    //         /*
    //          * Historical POS eligibility business disbursement date se hogi.
    //          * activated_at system-entry date ho sakti hai, isliye old/imported
    //          * loans ko historical snapshot se exclude nahi karna hai.
    //          * From Date ko lower disbursement bound nahi banayenge: POS as-on
    //          * snapshot mein as-on date tak disbursed sabhi loans aane chahiye.
    //          */
    //         ->whereIn(
    //             'lead_uuid',
    //             \Modules\BLDisbursment\Models\BLDisbursment::query()
    //                 ->whereDate('disbursement_date', '<=', $asOnDate)
    //                 ->select('lead_uuid')
    //         )
    //         ->when(
    //             $odBucketFilter,
    //             function ($query) use ($odBucketFilter, $asOnDate) {
    //                 $asOn = Carbon::parse($asOnDate)->startOfDay();

    //                 $dayBefore = $asOn->copy()->subDay()->toDateString();
    //                 $day30 = $asOn->copy()->subDays(30)->toDateString();
    //                 $day31 = $asOn->copy()->subDays(31)->toDateString();
    //                 $day60 = $asOn->copy()->subDays(60)->toDateString();
    //                 $day61 = $asOn->copy()->subDays(61)->toDateString();
    //                 $day90 = $asOn->copy()->subDays(90)->toDateString();
    //                 $day91 = $asOn->copy()->subDays(91)->toDateString();

    //                 $outstandingCondition = function ($installmentQuery) {
    //                     $installmentQuery->where('outstanding_amount', '>', 0);
    //                 };

    //                 switch ($odBucketFilter) {
    //                     case 'CURRENT':
    //                         /*
    //              * Due date today होने पर OD days 0 होते हैं,
    //              * इसलिए account CURRENT bucket में रहेगा।
    //              */
    //                         $query->whereDoesntHave(
    //                             'installments',
    //                             function ($installmentQuery) use (
    //                                 $dayBefore,
    //                                 $outstandingCondition
    //                             ) {
    //                                 $installmentQuery
    //                                     ->whereDate('due_date', '<=', $dayBefore)
    //                                     ->where($outstandingCondition);
    //                             }
    //                         );
    //                         break;

    //                     case '1-30':
    //                         $query
    //                             ->whereHas(
    //                                 'installments',
    //                                 function ($installmentQuery) use (
    //                                     $day30,
    //                                     $dayBefore,
    //                                     $outstandingCondition
    //                                 ) {
    //                                     $installmentQuery
    //                                         ->whereBetween('due_date', [
    //                                             $day30,
    //                                             $dayBefore,
    //                                         ])
    //                                         ->where($outstandingCondition);
    //                                 }
    //                             )
    //                             ->whereDoesntHave(
    //                                 'installments',
    //                                 function ($installmentQuery) use (
    //                                     $day31,
    //                                     $outstandingCondition
    //                                 ) {
    //                                     $installmentQuery
    //                                         ->whereDate('due_date', '<=', $day31)
    //                                         ->where($outstandingCondition);
    //                                 }
    //                             );
    //                         break;

    //                     case '31-60':
    //                         $query
    //                             ->whereHas(
    //                                 'installments',
    //                                 function ($installmentQuery) use (
    //                                     $day60,
    //                                     $day31,
    //                                     $outstandingCondition
    //                                 ) {
    //                                     $installmentQuery
    //                                         ->whereBetween('due_date', [
    //                                             $day60,
    //                                             $day31,
    //                                         ])
    //                                         ->where($outstandingCondition);
    //                                 }
    //                             )
    //                             ->whereDoesntHave(
    //                                 'installments',
    //                                 function ($installmentQuery) use (
    //                                     $day61,
    //                                     $outstandingCondition
    //                                 ) {
    //                                     $installmentQuery
    //                                         ->whereDate('due_date', '<=', $day61)
    //                                         ->where($outstandingCondition);
    //                                 }
    //                             );
    //                         break;

    //                     case '61-90':
    //                         $query
    //                             ->whereHas(
    //                                 'installments',
    //                                 function ($installmentQuery) use (
    //                                     $day90,
    //                                     $day61,
    //                                     $outstandingCondition
    //                                 ) {
    //                                     $installmentQuery
    //                                         ->whereBetween('due_date', [
    //                                             $day90,
    //                                             $day61,
    //                                         ])
    //                                         ->where($outstandingCondition);
    //                                 }
    //                             )
    //                             ->whereDoesntHave(
    //                                 'installments',
    //                                 function ($installmentQuery) use (
    //                                     $day91,
    //                                     $outstandingCondition
    //                                 ) {
    //                                     $installmentQuery
    //                                         ->whereDate('due_date', '<=', $day91)
    //                                         ->where($outstandingCondition);
    //                                 }
    //                             );
    //                         break;

    //                     case '90+':
    //                         $query->whereHas(
    //                             'installments',
    //                             function ($installmentQuery) use (
    //                                 $day91,
    //                                 $outstandingCondition
    //                             ) {
    //                                 $installmentQuery
    //                                     ->whereDate('due_date', '<=', $day91)
    //                                     ->where($outstandingCondition);
    //                             }
    //                         );
    //                         break;
    //                 }
    //             }
    //         )
    //         ->with([
    //             'lead',
    //             'lead.branch',
    //             'lead.branch.parentBranch',
    //             'lead.branch.parentBranch.parentBranch',
    //             'installments',
    //             'transactions' => function ($query) use ($asOnDate) {
    //                 $query
    //                     ->whereDate('received_date', '<=', $asOnDate)
    //                     ->orderBy('received_date')
    //                     ->orderBy('id');
    //             },
    //             'transactions.allocations',
    //             'charges',
    //         ])
    //         ->orderByDesc('id')
    //         ->paginate($perPage);

    //     Log::channel('lms_audit')->info('POS_REPORT_QUERY_RESULT', [
    //         'user_id' => $request->user()?->id,
    //         'od_bucket_filter' => $odBucketFilter,
    //         'as_on_date' => $asOnDate,

    //         'current_page' => $accounts->currentPage(),
    //         'per_page' => $accounts->perPage(),
    //         'last_page' => $accounts->lastPage(),
    //         'total_accounts' => $accounts->total(),
    //         'current_page_count' => $accounts->count(),

    //         'account_ids' => $accounts->getCollection()
    //             ->pluck('id')
    //             ->values()
    //             ->all(),

    //         'loan_numbers' => $accounts->getCollection()
    //             ->pluck('loan_number')
    //             ->filter()
    //             ->values()
    //             ->all(),
    //     ]);

    //     $accountCollection = $accounts->getCollection();

    //     $leadUuids = $accountCollection
    //         ->pluck('lead_uuid')
    //         ->filter()
    //         ->unique()
    //         ->values();

    //     $accountIds = $accountCollection
    //         ->pluck('id')
    //         ->filter()
    //         ->unique()
    //         ->values();


    //     $applicantsByLead = BLApplicantDetails::query()
    //         ->whereIn('lead_uuid', $leadUuids)
    //         ->orderBy('applicant_sno')
    //         ->orderBy('id')
    //         ->get()
    //         ->groupBy('lead_uuid');

    //     /*
    //  * Bank details।
    //  */
    //     $bankDetailsByLead = BLBankDetail::query()
    //         ->whereIn('lead_uuid', $leadUuids)
    //         ->orderBy('applicant_sno')
    //         ->orderBy('id')
    //         ->get()
    //         ->groupBy('lead_uuid');

    //     /*
    //  * Credit Visit Report।
    //  */
    //     $creditReportsByLead = BLCreditVisitReport::query()
    //         ->whereIn('lead_uuid', $leadUuids)
    //         ->orderByDesc('id')
    //         ->get()
    //         ->groupBy('lead_uuid')
    //         ->map(fn($records) => $records->first());

    //     /*
    //  * Disbursement।
    //  */
    //     $disbursementsByLead = BLDisbursment::query()
    //         ->whereIn('lead_uuid', $leadUuids)
    //         ->orderByDesc('id')
    //         ->get()
    //         ->groupBy('lead_uuid')
    //         ->map(fn($records) => $records->first());

    //     /*
    //  * Amortization।
    //  */
    //     $amortizationsByLead = BLAmortization::query()
    //         ->whereIn('lead_uuid', $leadUuids)
    //         ->orderByDesc('id')
    //         ->get()
    //         ->groupBy('lead_uuid')
    //         ->map(fn($records) => $records->first());

    //     /*
    //  * Latest CAM।
    //  */
    //     $camsByLead = BLCam::query()
    //         ->whereIn('lead_uuid', $leadUuids)
    //         ->orderByDesc('id')
    //         ->get()
    //         ->groupBy('lead_uuid')
    //         ->map(fn($records) => $records->first());

    //     /*
    //  * Product scheme।
    //  */
    //     $schemeSelectionsByLead = BLSchemeSelect::query()
    //         ->whereIn('lead_uuid', $leadUuids)
    //         ->with('productScheme')
    //         ->orderByDesc('id')
    //         ->get()
    //         ->groupBy('lead_uuid')
    //         ->map(fn($records) => $records->first());

    //     /*
    //  * Latest active loan assignment।
    //  */
    //     $assignmentsByLead = BLLeadAssignment::query()
    //         ->whereIn('lead_uuid', $leadUuids)
    //         ->where('is_active', true)
    //         ->orderByDesc('assigned_at')
    //         ->orderByDesc('id')
    //         ->get()
    //         ->groupBy('lead_uuid')
    //         ->map(fn($records) => $records->first());

    //     /*
    //  * Charges।
    //  */
    //     $loanChargesByLead = Charge::query()
    //         ->whereIn('lead_uuid', $leadUuids)
    //         ->orderByDesc('id')
    //         ->get()
    //         ->groupBy('lead_uuid')
    //         ->map(fn($records) => $records->first());

    //     /*
    //  * Insurance।
    //  */
    //     $insuranceByLead = Insurance::query()
    //         ->whereIn('lead_uuid', $leadUuids)
    //         ->orderByDesc('id')
    //         ->get()
    //         ->groupBy('lead_uuid')
    //         ->map(fn($records) => $records->first());

    //     /*
    //  * LUC report।
    //  */
    //     $lucReportsByLead = LucReport::query()
    //         ->whereIn('lead_uuid', $leadUuids)
    //         ->orderByDesc('id')
    //         ->get()
    //         ->groupBy('lead_uuid')
    //         ->map(fn($records) => $records->first());

    //     /*
    //  * NOC।
    //  */
    //     $nocByAccount = NocCertificate::query()
    //         ->whereIn('repayment_account_id', $accountIds)
    //         ->orderByDesc('issue_date')
    //         ->orderByDesc('id')
    //         ->get()
    //         ->groupBy('repayment_account_id')
    //         ->map(fn($records) => $records->first());

    //     /*
    //  * Settlement।
    //  */
    //     $settlementsByAccount = RepaymentSettlement::query()
    //         ->whereIn('repayment_account_id', $accountIds)
    //         ->orderByDesc('id')
    //         ->get()
    //         ->groupBy('repayment_account_id')
    //         ->map(fn($records) => $records->first());

    //     /*
    //  * Loan Officer users।
    //  */
    //     $legacyUserIds = $assignmentsByLead
    //         ->pluck('assigned_to')
    //         ->filter(fn($value) => is_numeric($value))
    //         ->map(fn($value) => (int) $value);

    //     $userIds = collect()
    //         ->merge($assignmentsByLead->pluck('assigned_to_user_id'))
    //         ->merge($assignmentsByLead->pluck('assigned_by_user_id'))
    //         ->merge($legacyUserIds)
    //         ->filter()
    //         ->unique()
    //         ->values();

    //     $usersById = User::query()
    //         ->whereIn('id', $userIds)
    //         ->get()
    //         ->keyBy('id');



    //     $userDisplayName = static function ($user): string {
    //         if (!$user) {
    //             return '';
    //         }

    //         return $user->user_name ?? '';
    //     };

    //     $rows = $accountCollection
    //         ->map(function ($account) use (
    //             $asOnDate,
    //             $gstPercentage,
    //             $applicantsByLead,
    //             $bankDetailsByLead,
    //             $creditReportsByLead,
    //             $disbursementsByLead,
    //             $amortizationsByLead,
    //             $camsByLead,
    //             $schemeSelectionsByLead,
    //             $assignmentsByLead,
    //             $loanChargesByLead,
    //             $insuranceByLead,
    //             $lucReportsByLead,
    //             $nocByAccount,
    //             $settlementsByAccount,
    //             $usersById,
    //             $userDisplayName,
    //             $enumValue,
    //             $money,
    //             $dateFormat
    //         ) {
    //             $lead = $account->lead;
    //             $leadUuid = $account->lead_uuid;

    //             $branch = $lead?->branch;
    //             $unit = $branch?->parentBranch;
    //             $region = $unit?->parentBranch;

    //             $applicants = $applicantsByLead
    //                 ->get($leadUuid, collect());

    //             /*
    //          * Primary applicant।
    //          */
    //             $primaryApplicant = $applicants
    //                 ->first(function ($applicant) {
    //                     return !((bool) $applicant->is_co_applicant);
    //                 });

    //             if (!$primaryApplicant) {
    //                 $primaryApplicant = $applicants->first();
    //             }

    //             /*
    //          * Co-applicant।
    //          */
    //             $coApplicant = $applicants
    //                 ->first(function ($applicant) {
    //                     return (bool) $applicant->is_co_applicant;
    //                 });

    //             $bankDetail = $bankDetailsByLead
    //                 ->get($leadUuid, collect())
    //                 ->first();

    //             $creditReport = $creditReportsByLead->get($leadUuid);
    //             $disbursement = $disbursementsByLead->get($leadUuid);
    //             $amortization = $amortizationsByLead->get($leadUuid);
    //             $cam = $camsByLead->get($leadUuid);
    //             $schemeSelection = $schemeSelectionsByLead->get($leadUuid);
    //             $scheme = $schemeSelection?->productScheme;
    //             $assignment = $assignmentsByLead->get($leadUuid);
    //             $loanCharge = $loanChargesByLead->get($leadUuid);
    //             $insurance = $insuranceByLead->get($leadUuid);
    //             $lucReport = $lucReportsByLead->get($leadUuid);
    //             $noc = $nocByAccount->get($account->id);
    //             $settlement = $settlementsByAccount->get($account->id);

    //             $loanOfficerId = $assignment?->assigned_to_user_id;

    //             if (!$loanOfficerId && is_numeric($assignment?->assigned_to)) {
    //                 $loanOfficerId = (int) $assignment->assigned_to;
    //             }

    //             $loanOfficer = $loanOfficerId
    //                 ? $usersById->get($loanOfficerId)
    //                 : null;

    //             $installments = $account->installments ?? collect();

    //             $postedPayments = ($account->transactions ?? collect())
    //                 ->filter(function ($transaction) use ($enumValue) {
    //                     return $enumValue($transaction->transaction_type)
    //                         === RepaymentTransactionType::PAYMENT->value
    //                         && $enumValue($transaction->status)
    //                         === RepaymentTransactionStatus::POSTED->value;
    //                 });

    //             $allocations = $postedPayments
    //                 ->flatMap(fn($transaction) => $transaction->allocations);

    //             $principalCollected = $money(
    //                 $allocations
    //                     ->filter(function ($allocation) use ($enumValue) {
    //                         return $enumValue($allocation->component)
    //                             === 'PRINCIPAL';
    //                     })
    //                     ->sum('amount')
    //             );

    //             $interestCollected = $money(
    //                 $allocations
    //                     ->filter(function ($allocation) use ($enumValue) {
    //                         return $enumValue($allocation->component)
    //                             === 'INTEREST';
    //                     })
    //                     ->sum('amount')
    //             );

    //             /*
    //              * As-on date wali EMI deduct nahi hogi. Us date ka opening
    //              * principal POS balance hoga. Sirf as-on date se pehle wali
    //              * scheduled principal/interest demand deduct hogi.
    //              */
    //             $installmentsBeforeAsOn = $installments
    //                 ->filter(function ($installment) use ($asOnDate) {
    //                     return $installment->due_date
    //                         && $installment->due_date->toDateString() < $asOnDate;
    //                 });

    //             $scheduledPrincipalBeforeAsOn = $money(
    //                 $installmentsBeforeAsOn->sum('principal_due')
    //             );

    //             $scheduledInterestBeforeAsOn = $money(
    //                 $installmentsBeforeAsOn->sum('interest_due')
    //             );

    //             $principalOutstandingAsOn = $money(
    //                 max(0, (float) $account->principal_amount - $scheduledPrincipalBeforeAsOn)
    //             );

    //             $interestOutstandingAsOn = $money(
    //                 max(0, (float) $account->total_interest - $scheduledInterestBeforeAsOn)
    //             );

    //             $overdueInstallments = $installments
    //                 ->filter(function ($installment) use ($asOnDate) {
    //                     return $installment->due_date
    //                         && $installment->due_date->toDateString()
    //                         <= $asOnDate
    //                         && (float) $installment->outstanding_amount > 0;
    //                 });

    //             $principalArrear = $money(
    //                 $overdueInstallments->sum(function ($installment) {
    //                     return max(
    //                         0,
    //                         (float) $installment->principal_due
    //                             - (float) $installment->principal_paid
    //                     );
    //                 })
    //             );

    //             $interestArrear = $money(
    //                 $overdueInstallments->sum(function ($installment) {
    //                     return max(
    //                         0,
    //                         (float) $installment->interest_due
    //                             - (float) $installment->interest_paid
    //                     );
    //                 })
    //             );

    //             $totalArrear = $money(
    //                 $principalArrear + $interestArrear
    //             );

    //             $firstInstallment = $installments
    //                 ->sortBy('installment_number')
    //                 ->first();

    //             $lastInstallment = $installments
    //                 ->sortByDesc('installment_number')
    //                 ->first();

    //             $nextInstallment = $installments
    //                 ->filter(function ($installment) use ($asOnDate) {
    //                     return $installment->due_date
    //                         && $installment->due_date->toDateString()
    //                         > $asOnDate
    //                         && (float) $installment->outstanding_amount > 0;
    //                 })
    //                 ->sortBy('due_date')
    //                 ->first();

    //             $lastCollection = $postedPayments
    //                 ->sortByDesc('received_date')
    //                 ->first();

    //             $installmentOutstandingCount = $installments
    //                 ->filter(function ($installment) {
    //                     return (float) $installment->outstanding_amount > 0;
    //                 })
    //                 ->count();

    //             $oldestOverdue = $overdueInstallments
    //                 ->sortBy('due_date')
    //                 ->first();

    //             $odDays = 0;

    //             if ($oldestOverdue?->due_date) {
    //                 $odDays = max(
    //                     0,
    //                     (int) $oldestOverdue->due_date->diffInDays(
    //                         Carbon::parse($asOnDate),
    //                         false
    //                     )
    //                 );
    //             }

    //             $odBucket = match (true) {
    //                 $odDays <= 0 => 'CURRENT',
    //                 $odDays <= 30 => '1-30',
    //                 $odDays <= 60 => '31-60',
    //                 $odDays <= 90 => '61-90',
    //                 default => '90+',
    //             };

    //             $repaymentChargeWaiver = $money(
    //                 ($account->charges ?? collect())
    //                     ->sum('waived_amount')
    //             );

    //             $settlementWaiver = $money(
    //                 $settlement?->waived_amount
    //             );

    //             $totalWaiver = $money(
    //                 $repaymentChargeWaiver + $settlementWaiver
    //             );

    //             $settlementStatus = $enumValue($settlement?->status);

    //             $netOffAmount = in_array(
    //                 $settlementStatus,
    //                 ['APPROVED', 'PAID'],
    //                 true
    //             )
    //                 ? $money($settlement?->settlement_amount)
    //                 : 0.0;


    //             $totalCharges = $money($loanCharge?->total_charges);

    //             $chargesGst = $money(
    //                 ($totalCharges * $gstPercentage) / 100
    //             );

    //             $totalChargesWithGst = $money(
    //                 $totalCharges + $chargesGst
    //             );

    //             $status = $enumValue($account->status);

    //             $closureRemark = '';

    //             if ($noc) {
    //                 $closureRemark = $noc->remarks
    //                     ?: $noc->closure_type
    //                     ?: 'NOC Issued';
    //             } elseif ($status === 'CLOSED') {
    //                 $closureRemark = 'Closed';
    //             }

    //             return [
    //                 'CUST_ID' => $lead?->lead_id ?? '',

    //                 'LOAN_NO' =>
    //                 $account->loan_number
    //                     ?? $disbursement?->loan_number
    //                     ?? '',

    //                 'MEMBER_NAME' =>
    //                 $primaryApplicant?->full_name ?? '',

    //                 'DATE_OF_BIRTH' =>
    //                 $dateFormat($primaryApplicant?->date_of_birth),

    //                 'AGE' => $primaryApplicant?->age ?? '',

    //                 'MOBILE_NUMBER' => $lead?->mobile_number ?? '',

    //                 /*
    //              * Nominee columns removed।
    //              * Co-applicant columns added।
    //              */
    //                 'CO_APPLICANT_NAME' =>
    //                 $coApplicant?->full_name ?? '',

    //                 'CO_APPLICANT_DOB' =>
    //                 $dateFormat($coApplicant?->date_of_birth),

    //                 'CO_APPLICANT_AGE' =>
    //                 $coApplicant?->age ?? '',

    //                 'CO_APPLICANT_RELATIONSHIP' =>
    //                 $coApplicant?->relationship ?? '',

    //                 'SPOUSE NAME' =>
    //                 $primaryApplicant?->spouse_full_name ?? '',

    //                 'FATHER NAME' =>
    //                 $primaryApplicant?->father_full_name ?? '',

    //                 'CASTE' => $primaryApplicant?->caste ?? '',

    //                 'RELIGION' => $primaryApplicant?->religion ?? '',

    //                 /*
    //              * Applicant KYC columns।
    //              * PAN और VOTER removed।
    //              */
    //                 'AADHAAR_NUMBER' =>
    //                 $primaryApplicant?->aadhaar_card_number ?? '',

    //                 'SECONDARY_KYC_TYPE' =>
    //                 $primaryApplicant?->secondary_kyc_type ?? '',

    //                 'SECONDARY_KYC_NUMBER' =>
    //                 $primaryApplicant?->secondary_kyc_number ?? '',

    //                 'DISBURSEMENT_DATE' =>
    //                 $dateFormat($disbursement?->disbursement_date),

    //                 'RURAL/URBAN' => '',

    //                 'PARTNER_BANK_NAME' => '',

    //                 /*
    //              * Credit Visit Report से loan purpose।
    //              */
    //                 'LOAN_PURPOSE' =>
    //                 $creditReport?->loan_purpose ?? '',

    //                 'SUB_PURPOSE' =>
    //                 $creditReport?->sub_industry ?? '',

    //                 'STATUS' => $status ?? '',

    //                 'Closure_Remark' => $closureRemark,

    //                 'LOAN_CREATED_ON' =>
    //                 $dateFormat($lead?->created_at),

    //                 'ADDRESS' =>
    //                 $primaryApplicant?->current_address ?? '',

    //                 'VILLAGE_NAME' =>
    //                 $primaryApplicant?->current_city ?? '',

    //                 'DISTRICT' =>
    //                 $primaryApplicant?->current_district ?? '',

    //                 'STATE' =>
    //                 $primaryApplicant?->current_state ?? '',

    //                 'PINCODE' =>
    //                 $primaryApplicant?->current_pincode ?? '',

    //                 'FUNDER_ID' => '',
    //                 'FUND_ALLOCATION_DATE' => '',
    //                 'LENDER_ID' => '',

    //                 'BRANCH ID & NAME' => $branch
    //                     ? trim(
    //                         ($branch->branch_code
    //                             ? $branch->branch_code . ' - '
    //                             : '') . $branch->name
    //                     )
    //                     : '',

    //                 'BRANCH_STATE' => $branch?->state ?? '',

    //                 'REGION' => $region
    //                     ? trim(
    //                         ($region->branch_code
    //                             ? $region->branch_code . ' - '
    //                             : '') . $region->name
    //                     )
    //                     : '',

    //                 'UNIT' => $unit
    //                     ? trim(
    //                         ($unit->branch_code
    //                             ? $unit->branch_code . ' - '
    //                             : '') . $unit->name
    //                     )
    //                     : '',



    //                 'LO_NAME' =>
    //                 $loanOfficer?->user_name ?? '',

    //                 'BANK_ACCOUNT' =>
    //                 $bankDetail?->account_number ?? '',

    //                 'BANK_IFSC_CODE' =>
    //                 $bankDetail?->ifsc_code ?? '',

    //                 'DISBURSE_BY_FO' => '',

    //                 // 'EMPCODE' => $loanOfficerId ?? '',
    //                 'EMPCODE' => $loanOfficer?->user_code ?? '',

    //                 'PRINCIPAL_ARREAR' => $principalArrear,

    //                 'INTEREST_ARREAR' => $interestArrear,

    //                 'TOTAL_ARREAR' => $totalArrear,

    //                 'PRIN_COLLECTED' => $principalCollected,

    //                 'INT_COLLECCTED' => $interestCollected,

    //                 'FIRST_DUE_AMOUNT' =>
    //                 $money($firstInstallment?->installment_amount),

    //                 'FIRST_DEMAND_DATE' =>
    //                 $dateFormat($firstInstallment?->due_date),

    //                 'LAST_EMI' =>
    //                 $money($lastInstallment?->installment_amount),

    //                 'IS_QUALIFY' =>
    //                 $lucReport?->utilization_status ?? '',

    //                 'LAST_MATURITY_DATE' =>
    //                 $dateFormat(
    //                     $account->maturity_date
    //                         ?? $lastInstallment?->due_date
    //                 ),

    //                 'LAST_COLL_DATE' =>
    //                 $dateFormat($lastCollection?->received_date),

    //                 'LAST_COLL_AMOUNT' =>
    //                 $money($lastCollection?->amount),

    //                 'PRODUCT_CATEGORY_ID' =>
    //                 $scheme?->product_name ?? '',

    //                 'PRODUCT_ID' =>
    //                 $scheme?->scheme_name
    //                     ?? $scheme?->uuid
    //                     ?? '',

    //                 'PRINCIPAL_TOTAL' =>
    //                 $money($account->principal_amount),

    //                 'INT_RATE' => round(
    //                     (float) (
    //                         $account->interest_rate
    //                         ?? $scheme?->interest_rate
    //                         ?? 0
    //                     ),
    //                     2
    //                 ),

    //                 'PROCESSING_FEE' =>
    //                 $money(
    //                     $amortization?->processing_fee
    //                         ?? $cam?->pf
    //                 ),

    //                 'TENURE' =>
    //                 $account->tenure
    //                     ?? $amortization?->tenure
    //                     ?? '',

    //                 'TOTAL_INTEREST' =>
    //                 $money($account->total_interest),

    //                 'TOTAL_INSTAL' =>
    //                 $account->installment_count
    //                     ?? $installments->count(),

    //                 'INSTAL_OS' => $installmentOutstandingCount,

    //                 'INSURANCE_ID' =>
    //                 $insurance?->id ?? '',

    //                 'INSURANCE_CHARGES' =>
    //                 $money($insurance?->insurance_amount),

    //                 /*
    //              * Charge fields।
    //              */
    //                 'TOTAL_CHARGES' => $totalCharges,

    //                 'TOTAL_CHARGES_WITH_GST' =>
    //                 $totalChargesWithGst,



    //                 'FO_ID' =>
    //                 $loanOfficer?->user_name ?? '',

    //                 'PAYMENT_REF_NUMBER' =>
    //                 $lastCollection?->transaction_reference ?? '',

    //                 'CYCLE' => $account->schedule_version ?? '',

    //                 'REPAYMENT_FREQUENCY' =>
    //                 $account->frequency
    //                     ?? $scheme?->repayment_frequency
    //                     ?? '',

    //                 'NEXT_DEMAND_DATE' =>
    //                 $dateFormat(
    //                     $account->next_due_date
    //                         ?? $nextInstallment?->due_date
    //                 ),

    //                 'LOAN_CLOSURE_DATE' =>
    //                 $dateFormat(
    //                     $account->closed_at
    //                         ?? $noc?->issue_date
    //                 ),

    //                 'PROD_CLASSIFICATION' =>
    //                 $scheme?->sub_loan_type
    //                     ?? $lead?->sub_loan_type
    //                     ?? '',

    //                 'REPAYMENT_MODE' =>
    //                 $enumValue($lastCollection?->payment_mode) ?? '',

    //                 'REPAYMENT_BANK' =>
    //                 $disbursement?->bank_name ?? '',

    //                 'EXT_LOAN_ID' => '',

    //                 'INSURANCE_MATURITY_DATE' => '',

    //                 // 'OUTSTANDING_PRINCIPAL' =>
    //                 // $money($account->principal_outstanding),

    //                 // 'OUTSTANDING_INTEREST' =>
    //                 // $money($account->interest_outstanding),

    //                 'OUTSTANDING_PRINCIPAL' =>
    //                 $principalOutstandingAsOn,

    //                 'OUTSTANDING_INTEREST' =>
    //                 $interestOutstandingAsOn,

    //                 'TOTAL_ARREAR_2' => $totalArrear,

    //                 'OD_DAYS' => $odDays,

    //                 'OD_BUCKET' => $odBucket,

    //                 'NETOFF_AMOUNT' => $netOffAmount,

    //                 'DOCUMENT_CHARGES' => '',

    //                 'STAMPDUTY_CHARGES' => '',

    //                 'LEGAL_FEE' => '',

    //                 'APPLICATION_FEE' =>
    //                 $money($loanCharge?->application_charges),

    //                 'PRE_EMI_AMT' => '',

    //                 'Fee_Pay_Mode' => '',

    //                 'NPA_DATE' => '',

    //                 'EXCESS_AMOUNT' =>
    //                 $money($account->advance_amount),

    //                 'WAIVEOFF_AMOUNT' => $totalWaiver,

    //                 'OD_interest_collected_till_date' => '',

    //                 'WRITE_OFF_AMOUNT' => '',
    //             ];
    //         })
    //         ->values();

    //     $columns = [
    //         'CUST_ID',
    //         'LOAN_NO',
    //         'MEMBER_NAME',
    //         'DATE_OF_BIRTH',
    //         'AGE',
    //         'MOBILE_NUMBER',

    //         'CO_APPLICANT_NAME',
    //         'CO_APPLICANT_DOB',
    //         'CO_APPLICANT_AGE',
    //         'CO_APPLICANT_RELATIONSHIP',

    //         'SPOUSE NAME',
    //         'FATHER NAME',
    //         'CASTE',
    //         'RELIGION',

    //         'AADHAAR_NUMBER',
    //         'SECONDARY_KYC_TYPE',
    //         'SECONDARY_KYC_NUMBER',

    //         'DISBURSEMENT_DATE',
    //         'RURAL/URBAN',
    //         'PARTNER_BANK_NAME',

    //         'LOAN_PURPOSE',
    //         'SUB_PURPOSE',

    //         'STATUS',
    //         'Closure_Remark',
    //         'LOAN_CREATED_ON',
    //         'ADDRESS',
    //         'VILLAGE_NAME',
    //         'DISTRICT',
    //         'STATE',
    //         'PINCODE',
    //         'FUNDER_ID',
    //         'FUND_ALLOCATION_DATE',
    //         'LENDER_ID',
    //         'BRANCH ID & NAME',
    //         'BRANCH_STATE',
    //         'REGION',
    //         'UNIT',
    //         'LO_NAME',
    //         'BANK_ACCOUNT',
    //         'BANK_IFSC_CODE',
    //         'DISBURSE_BY_FO',
    //         'EMPCODE',
    //         'PRINCIPAL_ARREAR',
    //         'INTEREST_ARREAR',
    //         'TOTAL_ARREAR',
    //         'PRIN_COLLECTED',
    //         'INT_COLLECCTED',
    //         'FIRST_DUE_AMOUNT',
    //         'FIRST_DEMAND_DATE',
    //         'LAST_EMI',
    //         'IS_QUALIFY',
    //         'LAST_MATURITY_DATE',
    //         'LAST_COLL_DATE',
    //         'LAST_COLL_AMOUNT',
    //         'PRODUCT_CATEGORY_ID',
    //         'PRODUCT_ID',
    //         'PRINCIPAL_TOTAL',
    //         'INT_RATE',
    //         'PROCESSING_FEE',
    //         'TENURE',
    //         'TOTAL_INTEREST',
    //         'TOTAL_INSTAL',
    //         'INSTAL_OS',

    //         'INSURANCE_ID',
    //         'INSURANCE_CHARGES',

    //         'TOTAL_CHARGES',
    //         'TOTAL_CHARGES_WITH_GST',

    //         'FO_ID',
    //         'PAYMENT_REF_NUMBER',
    //         'CYCLE',
    //         'REPAYMENT_FREQUENCY',
    //         'NEXT_DEMAND_DATE',
    //         'LOAN_CLOSURE_DATE',
    //         'PROD_CLASSIFICATION',
    //         'REPAYMENT_MODE',
    //         'REPAYMENT_BANK',
    //         'EXT_LOAN_ID',
    //         'INSURANCE_MATURITY_DATE',
    //         'OUTSTANDING_PRINCIPAL',
    //         'OUTSTANDING_INTEREST',
    //         'TOTAL_ARREAR_2',
    //         'OD_DAYS',
    //         'OD_BUCKET',
    //         'NETOFF_AMOUNT',
    //         'DOCUMENT_CHARGES',
    //         'STAMPDUTY_CHARGES',
    //         'LEGAL_FEE',
    //         'APPLICATION_FEE',
    //         'PRE_EMI_AMT',
    //         'Fee_Pay_Mode',
    //         'NPA_DATE',
    //         'EXCESS_AMOUNT',
    //         'WAIVEOFF_AMOUNT',
    //         'OD_interest_collected_till_date',
    //         'WRITE_OFF_AMOUNT',
    //     ];

    //     $bucketCounts = $rows
    //         ->groupBy('OD_BUCKET')
    //         ->map(function ($bucketRows) {
    //             return $bucketRows->count();
    //         })
    //         ->all();

    //     $mismatchedRows = $odBucketFilter
    //         ? $rows
    //         ->filter(function ($row) use ($odBucketFilter) {
    //             return ($row['OD_BUCKET'] ?? null) !== $odBucketFilter;
    //         })
    //         ->take(20)
    //         ->map(function ($row) {
    //             return [
    //                 'cust_id' => $row['CUST_ID'] ?? null,
    //                 'loan_number' => $row['LOAN_NO'] ?? null,
    //                 'od_days' => $row['OD_DAYS'] ?? null,
    //                 'od_bucket' => $row['OD_BUCKET'] ?? null,
    //                 'total_arrear' => $row['TOTAL_ARREAR'] ?? null,
    //             ];
    //         })
    //         ->values()
    //         ->all()
    //         : [];

    //     Log::channel('lms_audit')->info('POS_REPORT_RESPONSE', [
    //         'user_id' => $request->user()?->id,
    //         'requested_od_bucket' => $request->query('od_bucket'),
    //         'applied_od_bucket' => $odBucketFilter,
    //         'as_on_date' => $asOnDate,

    //         'paginator_total' => $accounts->total(),
    //         'response_row_count' => $rows->count(),
    //         'bucket_counts' => $bucketCounts,
    //         'mismatched_row_count' => count($mismatchedRows),
    //         'mismatched_rows' => $mismatchedRows,
    //     ]);

    //     return response()->json([
    //         'success' => true,
    //         'message' => 'POS report fetched successfully.',
    //         'data' => [
    //             'as_on_date' => $asOnDate,
    //             'gst_percentage' => $gstPercentage,
    //             'columns' => $columns,
    //             'rows' => $rows,
    //             'pagination' => [
    //                 'current_page' => $accounts->currentPage(),
    //                 'per_page' => $accounts->perPage(),
    //                 'last_page' => $accounts->lastPage(),
    //                 'total' => $accounts->total(),
    //                 'from' => $accounts->firstItem(),
    //                 'to' => $accounts->lastItem(),
    //             ],
    //         ],
    //     ]);
    // }


    public function disbursementReport(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from_date' => 'nullable|date',
            'to_date' => 'nullable|date|after_or_equal:from_date',
            'branch_id' => 'nullable|integer|exists:organization_branches,id',
            'status' => 'nullable|string|max:50',
            'per_page' => 'nullable|integer|min:1|max:500',
        ]);

        $fromDate = $validated['from_date']
            ?? now()->startOfMonth()->toDateString();

        $toDate = $validated['to_date']
            ?? now()->toDateString();

        $branchId = !empty($validated['branch_id'])
            ? (int) $validated['branch_id']
            : null;

        $statusFilter = !empty($validated['status'])
            ? strtoupper(trim($validated['status']))
            : null;

        $perPage = (int) ($validated['per_page'] ?? 100);

        /*
     * Charges table में GST fields उपलब्ध नहीं हैं।
     */
        $gstPercentage = 18;

        $enumValue = static function ($value) {
            return $value instanceof BackedEnum
                ? $value->value
                : $value;
        };

        $money = static function ($value): float {
            return round((float) ($value ?? 0), 2);
        };

        $dateFormat = static function (
            $value,
            bool $withTime = false
        ): string {
            if (!$value) {
                return '';
            }

            try {
                return Carbon::parse($value)->format(
                    $withTime ? 'd-m-Y H:i:s' : 'd-m-Y'
                );
            } catch (\Throwable $exception) {
                return '';
            }
        };

        /*
     * Base query BL disbursement से होगी।
     */
        $disbursements = BLDisbursment::query()
            ->whereBetween(
                'disbursement_date',
                [$fromDate, $toDate]
            )
            ->when(
                $branchId !== null,
                function ($query) use ($branchId) {
                    $query->whereHas(
                        'lead',
                        function ($leadQuery) use ($branchId) {
                            $leadQuery->where('branch_id', $branchId);
                        }
                    );
                }
            )
            ->with([
                'lead',
                'lead.branch',
                'lead.branch.parentBranch',
                'lead.branch.parentBranch.parentBranch',
            ])
            ->orderByDesc('disbursement_date')
            ->orderByDesc('id')
            ->paginate($perPage);

        $disbursementCollection = $disbursements->getCollection();

        $leadUuids = $disbursementCollection
            ->pluck('lead_uuid')
            ->filter()
            ->unique()
            ->values();

        /*
     * Repayment accounts।
     */
        $accountsByLead = RepaymentAccount::query()
            ->whereIn('lead_uuid', $leadUuids)
            ->with([
                'installments',
                'transactions' => function ($query) use ($toDate) {
                    $query
                        ->whereDate('received_date', '<=', $toDate)
                        ->orderBy('received_date')
                        ->orderBy('id');
                },
                'transactions.allocations',
                'charges',
            ])
            ->orderByDesc('id')
            ->get()
            ->groupBy('lead_uuid')
            ->map(fn($records) => $records->first());

        if ($statusFilter) {
            $allowedLeadUuids = $accountsByLead
                ->filter(function ($account) use (
                    $statusFilter,
                    $enumValue
                ) {
                    return strtoupper(
                        (string) $enumValue($account->status)
                    ) === $statusFilter;
                })
                ->keys();

            $disbursementCollection = $disbursementCollection
                ->filter(function ($disbursement) use (
                    $allowedLeadUuids
                ) {
                    return $allowedLeadUuids->contains(
                        $disbursement->lead_uuid
                    );
                })
                ->values();
        }

        $accountIds = $accountsByLead
            ->pluck('id')
            ->filter()
            ->unique()
            ->values();

        /*
     * Applicant और Co-applicant।
     */
        $applicantsByLead = BLApplicantDetails::query()
            ->whereIn('lead_uuid', $leadUuids)
            ->orderBy('applicant_sno')
            ->orderBy('id')
            ->get()
            ->groupBy('lead_uuid');

        /*
     * Bank details।
     */
        $bankDetailsByLead = BLBankDetail::query()
            ->whereIn('lead_uuid', $leadUuids)
            ->orderBy('applicant_sno')
            ->orderBy('id')
            ->get()
            ->groupBy('lead_uuid');

        /*
     * Credit Visit Report।
     */
        $creditReportsByLead = BLCreditVisitReport::query()
            ->whereIn('lead_uuid', $leadUuids)
            ->orderByDesc('id')
            ->get()
            ->groupBy('lead_uuid')
            ->map(fn($records) => $records->first());

        /*
     * CAM।
     */
        $camsByLead = BLCam::query()
            ->whereIn('lead_uuid', $leadUuids)
            ->orderByDesc('id')
            ->get()
            ->groupBy('lead_uuid')
            ->map(fn($records) => $records->first());

        /*
     * Amortization।
     */
        $amortizationsByLead = BLAmortization::query()
            ->whereIn('lead_uuid', $leadUuids)
            ->orderByDesc('id')
            ->get()
            ->groupBy('lead_uuid')
            ->map(fn($records) => $records->first());

        /*
     * Product Scheme।
     */
        $schemeSelectionsByLead = BLSchemeSelect::query()
            ->whereIn('lead_uuid', $leadUuids)
            ->with('productScheme')
            ->orderByDesc('id')
            ->get()
            ->groupBy('lead_uuid')
            ->map(fn($records) => $records->first());

        /*
     * Loan assignment।
     */
        $assignmentsByLead = BLLeadAssignment::query()
            ->whereIn('lead_uuid', $leadUuids)
            ->where('is_active', true)
            ->orderByDesc('assigned_at')
            ->orderByDesc('id')
            ->get()
            ->groupBy('lead_uuid')
            ->map(fn($records) => $records->first());

        /*
     * Charges।
     */
        $loanChargesByLead = Charge::query()
            ->whereIn('lead_uuid', $leadUuids)
            ->orderByDesc('id')
            ->get()
            ->groupBy('lead_uuid')
            ->map(fn($records) => $records->first());

        /*
     * Insurance।
     */
        $insuranceByLead = Insurance::query()
            ->whereIn('lead_uuid', $leadUuids)
            ->orderByDesc('id')
            ->get()
            ->groupBy('lead_uuid')
            ->map(fn($records) => $records->first());

        /*
     * LUC।
     */
        $lucReportsByLead = LucReport::query()
            ->whereIn('lead_uuid', $leadUuids)
            ->orderByDesc('id')
            ->get()
            ->groupBy('lead_uuid')
            ->map(fn($records) => $records->first());

        /*
     * NOC।
     */
        $nocByAccount = NocCertificate::query()
            ->whereIn('repayment_account_id', $accountIds)
            ->orderByDesc('issue_date')
            ->orderByDesc('id')
            ->get()
            ->groupBy('repayment_account_id')
            ->map(fn($records) => $records->first());

        /*
     * Settlement।
     */
        $settlementsByAccount = RepaymentSettlement::query()
            ->whereIn('repayment_account_id', $accountIds)
            ->orderByDesc('id')
            ->get()
            ->groupBy('repayment_account_id')
            ->map(fn($records) => $records->first());

        /*
     * Users।
     */
        $legacyAssignedUserIds = $assignmentsByLead
            ->pluck('assigned_to')
            ->filter(fn($value) => is_numeric($value))
            ->map(fn($value) => (int) $value);

        $userIds = collect()
            ->merge($assignmentsByLead->pluck('assigned_to_user_id'))
            ->merge($legacyAssignedUserIds)
            ->merge(
                $disbursementCollection
                    ->pluck('lead.created_by')
            )
            ->merge($accountsByLead->pluck('updated_by'))
            ->filter()
            ->unique()
            ->values();

        $usersById = User::query()
            ->whereIn('id', $userIds)
            ->get()
            ->keyBy('id');

        // $userDisplayName = static function ($user): string {
        //     if (!$user) {
        //         return '';
        //     }

        //     $name = trim(
        //         ($user->first_name ?? '') . ' ' .
        //             ($user->last_name ?? '')
        //     );

        //     if ($name === '') {
        //         $name = $user->full_name
        //             ?? $user->name
        //             ?? $user->username
        //             ?? '';
        //     }

        //     return $name !== ''
        //         ? trim($user->id . ' - ' . $name)
        //         : (string) $user->id;
        // };

        $userDisplayName = static function ($user): string {
            if (!$user) {
                return '';
            }

            return $user->user_name ?? '';
        };

        $rows = $disbursementCollection
            ->map(function ($disbursement) use (
                $toDate,
                $gstPercentage,
                $accountsByLead,
                $applicantsByLead,
                $bankDetailsByLead,
                $creditReportsByLead,
                $camsByLead,
                $amortizationsByLead,
                $schemeSelectionsByLead,
                $assignmentsByLead,
                $loanChargesByLead,
                $insuranceByLead,
                $lucReportsByLead,
                $nocByAccount,
                $settlementsByAccount,
                $usersById,
                $userDisplayName,
                $enumValue,
                $money,
                $dateFormat
            ) {
                $lead = $disbursement->lead;
                $leadUuid = $disbursement->lead_uuid;

                $account = $accountsByLead->get($leadUuid);

                $branch = $lead?->branch;
                $unit = $branch?->parentBranch;
                $region = $unit?->parentBranch;

                $applicants = $applicantsByLead
                    ->get($leadUuid, collect());

                $primaryApplicant = $applicants
                    ->first(function ($applicant) {
                        return !((bool) $applicant->is_co_applicant);
                    });

                if (!$primaryApplicant) {
                    $primaryApplicant = $applicants->first();
                }

                $coApplicant = $applicants
                    ->first(function ($applicant) {
                        return (bool) $applicant->is_co_applicant;
                    });

                $bankDetail = $bankDetailsByLead
                    ->get($leadUuid, collect())
                    ->first();

                $creditReport = $creditReportsByLead->get($leadUuid);
                $cam = $camsByLead->get($leadUuid);
                $amortization = $amortizationsByLead->get($leadUuid);
                $schemeSelection = $schemeSelectionsByLead->get($leadUuid);
                $scheme = $schemeSelection?->productScheme;
                $assignment = $assignmentsByLead->get($leadUuid);
                $loanCharge = $loanChargesByLead->get($leadUuid);
                $insurance = $insuranceByLead->get($leadUuid);
                $lucReport = $lucReportsByLead->get($leadUuid);

                $noc = $account
                    ? $nocByAccount->get($account->id)
                    : null;

                $settlement = $account
                    ? $settlementsByAccount->get($account->id)
                    : null;

                $loanOfficerId = $assignment?->assigned_to_user_id;

                if (
                    !$loanOfficerId &&
                    is_numeric($assignment?->assigned_to)
                ) {
                    $loanOfficerId = (int) $assignment->assigned_to;
                }

                $loanOfficer = $loanOfficerId
                    ? $usersById->get($loanOfficerId)
                    : null;

                $createdByUser = $lead?->created_by
                    ? $usersById->get($lead->created_by)
                    : null;

                $modifiedByUser = $account?->updated_by
                    ? $usersById->get($account->updated_by)
                    : null;

                $installments = $account?->installments ?? collect();

                $postedPayments = ($account?->transactions ?? collect())
                    ->filter(function ($transaction) use ($enumValue) {
                        return $enumValue($transaction->transaction_type)
                            === RepaymentTransactionType::PAYMENT->value
                            && $enumValue($transaction->status)
                            === RepaymentTransactionStatus::POSTED->value;
                    });

                $allocations = $postedPayments
                    ->flatMap(
                        fn($transaction) =>
                        $transaction->allocations
                    );

                $principalCollected = $money(
                    $allocations
                        ->filter(function ($allocation) use ($enumValue) {
                            return $enumValue($allocation->component)
                                === 'PRINCIPAL';
                        })
                        ->sum('amount')
                );

                $interestCollected = $money(
                    $allocations
                        ->filter(function ($allocation) use ($enumValue) {
                            return $enumValue($allocation->component)
                                === 'INTEREST';
                        })
                        ->sum('amount')
                );

                $overdueInstallments = $installments
                    ->filter(function ($installment) use ($toDate) {
                        return $installment->due_date
                            && $installment->due_date->toDateString()
                            <= $toDate
                            && (float) $installment->outstanding_amount > 0;
                    });

                $principalArrear = $money(
                    $overdueInstallments
                        ->sum(function ($installment) {
                            return max(
                                0,
                                (float) $installment->principal_due
                                    - (float) $installment->principal_paid
                            );
                        })
                );

                $interestArrear = $money(
                    $overdueInstallments
                        ->sum(function ($installment) {
                            return max(
                                0,
                                (float) $installment->interest_due
                                    - (float) $installment->interest_paid
                            );
                        })
                );

                $oldestOverdue = $overdueInstallments
                    ->sortBy('due_date')
                    ->first();

                $parDays = 0;

                if ($oldestOverdue?->due_date) {
                    $parDays = max(
                        0,
                        (int) $oldestOverdue->due_date->diffInDays(
                            Carbon::parse($toDate),
                            false
                        )
                    );
                }

                $penalDue = $money(
                    ($account?->charges ?? collect())
                        ->filter(function ($charge) use ($enumValue) {
                            return $enumValue($charge->charge_type)
                                === RepaymentChargeType::PENAL->value;
                        })
                        ->sum('net_payable')
                );

                $totalDue = $money(
                    $principalArrear
                        + $interestArrear
                        + $penalDue
                );

                $firstInstallment = $installments
                    ->sortBy('installment_number')
                    ->first();

                $lastInstallment = $installments
                    ->sortByDesc('installment_number')
                    ->first();

                $lastCollection = $postedPayments
                    ->sortByDesc('received_date')
                    ->first();

                $installmentOutstanding = $installments
                    ->filter(
                        fn($installment) =>
                        (float) $installment->outstanding_amount > 0
                    )
                    ->count();

                $fullName = trim(
                    (string) $primaryApplicant?->full_name
                );

                $nameParts = preg_split(
                    '/\s+/',
                    $fullName,
                    2
                );

                $firstName = $nameParts[0] ?? '';
                $lastName = $nameParts[1] ?? '';

                $totalCharges = $money(
                    $loanCharge?->total_charges
                );

                $applicationFee = $money(
                    $loanCharge?->application_charges
                );

                $applicationFeeGst = $money(
                    ($applicationFee * $gstPercentage) / 100
                );

                $processingFee = $money(
                    $amortization?->processing_fee
                        ?? $cam?->pf
                );

                /*
             * CAM gst field processing fee GST के लिए।
             */
                $processingFeeGst = $money(
                    $cam?->gst
                );

                $settlementStatus = $enumValue(
                    $settlement?->status
                );

                $netOffAmount = in_array(
                    $settlementStatus,
                    ['APPROVED', 'PAID'],
                    true
                )
                    ? $money($settlement?->settlement_amount)
                    : $money($account?->net_disbursed_amount);

                $accountStatus = $enumValue($account?->status);

                $closureRemark = '';

                if ($noc) {
                    $closureRemark = $noc->remarks
                        ?: $noc->closure_type
                        ?: 'NOC Issued';
                } elseif ($accountStatus === 'CLOSED') {
                    $closureRemark = 'Closed';
                }

                return [
                    'Region ID' => $region
                        ? trim(
                            ($region->branch_code
                                ? $region->branch_code . ' - '
                                : '') . $region->name
                        )
                        : '',

                    'Unit' => $unit
                        ? trim(
                            ($unit->branch_code
                                ? $unit->branch_code . ' - '
                                : '') . $unit->name
                        )
                        : '',

                    'Branch ID' => $branch
                        ? trim(
                            ($branch->branch_code
                                ? $branch->branch_code . ' - '
                                : '') . $branch->name
                        )
                        : '',

                    'Closure Remark' => $closureRemark,

                    'Loan Type' =>
                    $primaryApplicant?->applying_as
                        ?? $lead?->loan_type
                        ?? '',

                    'Customer ID' => $lead?->lead_id ?? '',

                    'Customer Status' =>
                    $accountStatus ?? '',

                    'Risk Categorization' =>
                    $primaryApplicant?->bureau_deviation ?? '',

                    'Loan ID' =>
                    $account?->loan_number
                        ?? $disbursement->loan_number
                        ?? '',

                    'Status' =>
                    $accountStatus ?: 'DISBURSED',

                    'First Name' => $firstName,

                    'Last Name' => $lastName,

                    'Spouse Name' =>
                    $primaryApplicant?->spouse_full_name ?? '',

                    'Father Name' =>
                    $primaryApplicant?->father_full_name ?? '',

                    'Loan Officer' =>
                    $userDisplayName($loanOfficer)
                        ?: ($assignment?->assigned_to ?? ''),

                    'DSA Id' => '',

                    'Disbursement Date' =>
                    $dateFormat(
                        $disbursement->disbursement_date,
                        true
                    ),

                    'Payment Inst Number' => '',

                    'Product Cat ID' =>
                    $scheme?->product_name ?? '',

                    'Product ID' =>
                    $scheme?->scheme_name
                        ?? $scheme?->uuid
                        ?? '',

                    'Partner Bank' => '',

                    'Loan Amount' =>
                    $money(
                        $cam?->loan_amount
                            ?? $account?->principal_amount
                            ?? $disbursement->disbursement_amount
                    ),

                    'Interest Rate' => round(
                        (float) (
                            $account?->interest_rate
                            ?? $scheme?->interest_rate
                            ?? $cam?->roi
                            ?? 0
                        ),
                        2
                    ),

                    'Annual Percentage Rate' => '',

                    'Repayment Frequency' =>
                    $account?->frequency
                        ?? $scheme?->repayment_frequency
                        ?? '',

                    'Purpose ID' =>
                    $creditReport?->loan_purpose ?? '',

                    'Sub Purpose' =>
                    $creditReport?->sub_industry ?? '',

                    'Business Name' => '',

                    'UMRN' => '',

                    'Processing Fee' => $processingFee,

                    'Processing Fee Gst' => $processingFeeGst,

                    'HCI Primary Premium' =>
                    $money($insurance?->insurance_amount),

                    'HCI Secondary Premium' => 0.0,

                    'Application Fee' => $applicationFee,

                    'Application Fee Gst' => $applicationFeeGst,

                    'Technical evaluation charges' => 0.0,

                    'Technical evaluation charges Gst' => 0.0,

                    'Login Fee Due' => 0.0,

                    'Login fee collected' => 0.0,

                    'Advance Principle' => 0.0,

                    'Payment Mode' => '',

                    'Payment Agency' =>
                    $disbursement->bank_name ?? '',

                    'Payment Reference' =>
                    $disbursement->utr_number ?? '',

                    'NEFT Status' =>
                    $disbursement->utr_number
                        ? 'Disbursed'
                        : '',

                    'Principal Total' =>
                    $money($account?->principal_amount),

                    'Principal O/S' =>
                    $money($account?->principal_outstanding),

                    'Interest Total' =>
                    $money($account?->total_interest),

                    'Interest O/S' =>
                    $money($account?->interest_outstanding),

                    'Tenure (In Months)' =>
                    $account?->tenure
                        ?? $cam?->loan_tenure
                        ?? '',

                    'Installments Total' =>
                    $account?->installment_count
                        ?? $installments->count(),

                    'Installment O/S' =>
                    $installmentOutstanding,

                    'Form Number' => '',

                    'Ext. Customer ID' => '',

                    'STAMP DUTY' => 0.0,
                    'STAMP DUTY Gst' => 0.0,
                    'Document Charges' => 0.0,
                    'Document Charges Gst' => 0.0,
                    'Legal Fee' => 0.0,
                    'Legal Fee Gst' => 0.0,
                    'CERSAI Charges' => 0.0,
                    'CERSAI Charges With Gst' => 0.0,

                    'Insurance Id' =>
                    $insurance?->id ?? '',

                    'Insurance Charges' =>
                    $money($insurance?->insurance_amount),

                    'Net off Amount' => $netOffAmount,

                    'Repayment Mode' =>
                    $enumValue(
                        $lastCollection?->payment_mode
                    ) ?? '',

                    'First repayment date' =>
                    $dateFormat(
                        $account?->first_due_date
                            ?? $firstInstallment?->due_date
                    ),

                    'Cycle' =>
                    $account?->schedule_version ?? '',

                    'Principal Collected' =>
                    $principalCollected,

                    'Interest Collected' =>
                    $interestCollected,

                    'Gender' =>
                    $primaryApplicant?->gender ?? '',

                    'LUC Status' =>
                    $lucReport?->utilization_status ?? '',

                    'Lender Id' => '',

                    'Facility Id' => '',

                    'Fund Allocation Date' => '',

                    'Par Days' => $parDays,

                    'Principal Arrear' => $principalArrear,

                    'Interest Arrear' => $interestArrear,

                    'OD Due' => $money(
                        $principalArrear + $interestArrear
                    ),

                    'Penal Due' => $penalDue,

                    'Total Due' => $totalDue,

                    'Last Demand Date' =>
                    $dateFormat(
                        $account?->maturity_date
                            ?? $lastInstallment?->due_date
                    ),

                    'Last EMI Amount' =>
                    $money($lastInstallment?->installment_amount),

                    'Last Collection Date' =>
                    $dateFormat($lastCollection?->received_date),

                    'Borrower Contact No.' =>
                    $lead?->mobile_number ?? '',

                    'Co-Borrower Name' =>
                    $coApplicant?->full_name ?? '',

                    'Co-Borrower Contact No' =>
                    $coApplicant?->alternate_contact_number ?? '',

                    'Loan Reversal Reason' =>
                    $lastCollection?->reversal?->remarks ?? '',

                    'Created On' =>
                    $dateFormat($lead?->created_at, true),

                    'Created By' =>
                    $userDisplayName($createdByUser)
                        ?: ($lead?->created_by ?? ''),

                    'Modified On' =>
                    $dateFormat(
                        $account?->updated_at
                            ?? $disbursement->updated_at,
                        true
                    ),

                    'Modified By' =>
                    $userDisplayName($modifiedByUser)
                        ?: ($account?->updated_by ?? ''),

                    'EMI Amount' =>
                    $money(
                        $amortization?->installment_amount
                            ?? $firstInstallment?->installment_amount
                    ),

                    'Restruct Loan Id' => '',

                    'Renewal Loan Amount' => 0.0,

                    'Ckyc Number' => '',

                    'Write-Off Date' => '',

                    /*
                 * Additional charge fields available in your model।
                 */
                    'Total Charges' => $totalCharges,

                    'Total Charges With Gst' => $money(
                        $totalCharges
                            + (($totalCharges * $gstPercentage) / 100)
                    ),

                    'Bank Account' =>
                    $bankDetail?->account_number ?? '',

                    'Bank IFSC' =>
                    $bankDetail?->ifsc_code ?? '',
                ];
            })
            ->values();

        $columns = [
            'Region ID',
            'Unit',
            'Branch ID',
            'Closure Remark',
            'Loan Type',
            'Customer ID',
            'Customer Status',
            'Risk Categorization',
            'Loan ID',
            'Status',
            'First Name',
            'Last Name',
            'Spouse Name',
            'Father Name',
            'Loan Officer',
            'DSA Id',
            'Disbursement Date',
            'Payment Inst Number',
            'Product Cat ID',
            'Product ID',
            'Partner Bank',
            'Loan Amount',
            'Interest Rate',
            'Annual Percentage Rate',
            'Repayment Frequency',
            'Purpose ID',
            'Sub Purpose',
            'Business Name',
            'UMRN',
            'Processing Fee',
            'Processing Fee Gst',
            'HCI Primary Premium',
            'HCI Secondary Premium',
            'Application Fee',
            'Application Fee Gst',
            'Technical evaluation charges',
            'Technical evaluation charges Gst',
            'Login Fee Due',
            'Login fee collected',
            'Advance Principle',
            'Payment Mode',
            'Payment Agency',
            'Payment Reference',
            'NEFT Status',
            'Principal Total',
            'Principal O/S',
            'Interest Total',
            'Interest O/S',
            'Tenure (In Months)',
            'Installments Total',
            'Installment O/S',
            'Form Number',
            'Ext. Customer ID',
            'STAMP DUTY',
            'STAMP DUTY Gst',
            'Document Charges',
            'Document Charges Gst',
            'Legal Fee',
            'Legal Fee Gst',
            'CERSAI Charges',
            'CERSAI Charges With Gst',
            'Insurance Id',
            'Insurance Charges',
            'Net off Amount',
            'Repayment Mode',
            'First repayment date',
            'Cycle',
            'Principal Collected',
            'Interest Collected',
            'Gender',
            'LUC Status',
            'Lender Id',
            'Facility Id',
            'Fund Allocation Date',
            'Par Days',
            'Principal Arrear',
            'Interest Arrear',
            'OD Due',
            'Penal Due',
            'Total Due',
            'Last Demand Date',
            'Last EMI Amount',
            'Last Collection Date',
            'Borrower Contact No.',
            'Co-Borrower Name',
            'Co-Borrower Contact No',
            'Loan Reversal Reason',
            'Created On',
            'Created By',
            'Modified On',
            'Modified By',
            'EMI Amount',
            'Restruct Loan Id',
            'Renewal Loan Amount',
            'Ckyc Number',
            'Write-Off Date',

            /*
         * Extra available fields।
         */
            'Total Charges',
            'Total Charges With Gst',
            'Bank Account',
            'Bank IFSC',
        ];

        return response()->json([
            'success' => true,
            'message' =>
            'Disbursement report fetched successfully.',
            'data' => [
                'from_date' => $fromDate,
                'to_date' => $toDate,
                'gst_percentage' => $gstPercentage,
                'columns' => $columns,
                'rows' => $rows,
                'pagination' => [
                    'current_page' =>
                    $disbursements->currentPage(),
                    'per_page' =>
                    $disbursements->perPage(),
                    'last_page' =>
                    $disbursements->lastPage(),
                    'total' =>
                    $disbursements->total(),
                    'from' =>
                    $disbursements->firstItem(),
                    'to' =>
                    $disbursements->lastItem(),
                ],
            ],
        ]);
    }




    //  public function cicReport(Request $request): JsonResponse
    // {
    //     $validated = $request->validate([
    //         'as_on_date' => 'nullable|date',
    //         'from_date'  => 'nullable|date',
    //         'to_date'    => 'nullable|date|after_or_equal:from_date',
    //         'branch_id'  => 'nullable|integer|exists:organization_branches,id',
    //         'search'     => 'nullable|string|max:255',
    //         'per_page'   => 'nullable|integer|min:1|max:500',
    //     ]);

    //     $searchTerm = isset($validated['search'])
    //         ? trim($validated['search'])
    //         : null;

    //     $fromDate = $validated['from_date'] ?? null;
    //     $toDate   = $validated['to_date'] ?? null;
    //     $asOnDate = $toDate
    //         ?? $validated['as_on_date']
    //         ?? now()->toDateString();
    //     $branchId = !empty($validated['branch_id']) ? (int) $validated['branch_id'] : null;
    //     $perPage  = (int) ($validated['per_page'] ?? 100);

    //     /*
    //      * Request start होते ही audit entry — किसने, कब, किन filters
    //      * से report generate किया।
    //      */
    //     \Illuminate\Support\Facades\Log::channel('bl_audit')->info('CIC_REPORT_REQUEST', [
    //         'user_id'    => $request->user()?->id,
    //         'as_on_date' => $asOnDate,
    //         'from_date'  => $fromDate,
    //         'to_date'    => $toDate,
    //         'branch_id'  => $branchId,
    //         'per_page'   => $perPage,
    //         'ip'         => $request->ip(),
    //     ]);

    //     try {
    //         $enumValue = static function ($value) {
    //             return $value instanceof BackedEnum ? $value->value : $value;
    //         };

    //         $money = static function ($value): float {
    //             return round((float) ($value ?? 0), 2);
    //         };

    //         /*
    //          * Bureau date format DDMMYYYY है (sample: 30031990)।
    //          */
    //         $bureauDate = static function ($value): string {
    //             if (!$value) {
    //                 return '';
    //             }
    //             try {
    //                 return Carbon::parse($value)->format('dmY');
    //             } catch (\Throwable $e) {
    //                 return '';
    //             }
    //         };

    //         $accounts = RepaymentAccount::query()
    //             ->when($branchId !== null, function ($query) use ($branchId) {
    //                 $query->whereHas('lead', function ($leadQuery) use ($branchId) {
    //                     $leadQuery->where('branch_id', $branchId);
    //                 });
    //             })
    //             ->when(
    //                 $searchTerm,
    //                 function ($query) use ($searchTerm) {
    //                     $query->where(function ($searchQuery) use ($searchTerm) {
    //                         $searchQuery
    //                             ->where(
    //                                 'loan_number',
    //                                 'like',
    //                                 "%{$searchTerm}%"
    //                             )
    //                             ->orWhereHas(
    //                                 'lead',
    //                                 function ($leadQuery) use ($searchTerm) {
    //                                     $leadQuery->where(
    //                                         'lead_id',
    //                                         'like',
    //                                         "%{$searchTerm}%"
    //                                     );
    //                                 }
    //                             );
    //                     });
    //                 }
    //             )
    //             /*
    //              * CIC historical eligibility bhi POS jaisi rahegi:
    //              * activated_at ke badle disbursement_date <= as-on date.
    //              */
    //             ->whereIn(
    //                 'lead_uuid',
    //                 \Modules\BLDisbursment\Models\BLDisbursment::query()
    //                     ->whereDate('disbursement_date', '<=', $asOnDate)
    //                     ->select('lead_uuid')
    //             )
    //             ->with([
    //                 'lead',
    //                 'installments',
    //                 'transactions' => function ($query) use ($asOnDate) {
    //                     $query->whereDate('received_date', '<=', $asOnDate)
    //                         ->orderBy('received_date')
    //                         ->orderBy('id');
    //                 },
    //                 'transactions.allocations',
    //             ])
    //             ->orderByDesc('id')
    //             ->paginate($perPage);

    //         $accountCollection = $accounts->getCollection();

    //         $leadUuids = $accountCollection->pluck('lead_uuid')->filter()->unique()->values();
    //         $accountIds = $accountCollection->pluck('id')->filter()->unique()->values();

    //         $applicantsByLead = BLApplicantDetails::query()
    //             ->whereIn('lead_uuid', $leadUuids)
    //             ->orderBy('applicant_sno')
    //             ->orderBy('id')
    //             ->get()
    //             ->groupBy('lead_uuid');

    //         $disbursementsByLead = BLDisbursment::query()
    //             ->whereIn('lead_uuid', $leadUuids)
    //             ->orderByDesc('id')
    //             ->get()
    //             ->groupBy('lead_uuid')
    //             ->map(fn($records) => $records->first());

    //         $amortizationsByLead = BLAmortization::query()
    //             ->whereIn('lead_uuid', $leadUuids)
    //             ->orderByDesc('id')
    //             ->get()
    //             ->groupBy('lead_uuid')
    //             ->map(fn($records) => $records->first());

    //         $settlementsByAccount = RepaymentSettlement::query()
    //             ->whereIn('repayment_account_id', $accountIds)
    //             ->orderByDesc('id')
    //             ->get()
    //             ->groupBy('repayment_account_id')
    //             ->map(fn($records) => $records->first());

    //         /*
    //          * हर applicant एक अलग row बनता है (co-applicant सहित) — bureau
    //          * format में हर consumer अपनी row में report होता है।
    //          */
    //         $rows = collect();

    //         foreach ($accountCollection as $account) {
    //             $lead = $account->lead;
    //             $leadUuid = $account->lead_uuid;

    //             // $applicants = $applicantsByLead->get($leadUuid, collect());
    //             // if ($applicants->isEmpty()) {
    //             //     continue;
    //             // }


    //             $applicants = $applicantsByLead
    //                 ->get($leadUuid, collect())
    //                 ->filter(function ($applicant) {
    //                     return trim((string) ($applicant->full_name ?? '')) !== '';
    //                 })
    //                 ->sortBy(function ($applicant) {
    //                     return (bool) $applicant->is_co_applicant ? 1 : 0;
    //                 })
    //                 ->take(1)
    //                 ->values();

    //             if ($applicants->isEmpty()) {
    //                 continue;
    //             }

    //             $disbursement = $disbursementsByLead->get($leadUuid);
    //             $amortization = $amortizationsByLead->get($leadUuid);
    //             $settlement   = $settlementsByAccount->get($account->id);

    //             $installments = $account->installments ?? collect();

    //             $postedPayments = ($account->transactions ?? collect())
    //                 ->filter(function ($transaction) use ($enumValue) {
    //                     return $enumValue($transaction->transaction_type)
    //                         === RepaymentTransactionType::PAYMENT->value
    //                         && $enumValue($transaction->status)
    //                         === RepaymentTransactionStatus::POSTED->value;
    //                 });

    //             $lastCollection = $postedPayments->sortByDesc('received_date')->first();

    //             /*
    //              * POS report wali same cutoff rule:
    //              * selected as-on date ki EMI deduct nahi hogi. Sirf selected
    //              * date se pehle ki scheduled principal/interest demand deduct
    //              * hogi, isliye due-date row ka opening principal balance aayega.
    //              */
    //             $installmentsBeforeAsOn = $installments
    //                 ->filter(function ($installment) use ($asOnDate) {
    //                     return $installment->due_date
    //                         && $installment->due_date->toDateString() < $asOnDate;
    //                 });

    //             $scheduledPrincipalBeforeAsOn = $money(
    //                 $installmentsBeforeAsOn->sum('principal_due')
    //             );

    //             $principalOutstandingAsOn = $money(
    //                 max(
    //                     0,
    //                     (float) $account->principal_amount
    //                         - (float) $scheduledPrincipalBeforeAsOn
    //                 )
    //             );

    //             $overdueInstallments = $installments->filter(function ($installment) use ($asOnDate) {
    //                 return $installment->due_date
    //                     && $installment->due_date->toDateString() <= $asOnDate
    //                     && (float) $installment->outstanding_amount > 0;
    //             });

    //             $arrear = $money(
    //                 $overdueInstallments->sum(function ($installment) {
    //                     return max(0, (float) $installment->principal_due - (float) $installment->principal_paid)
    //                         + max(0, (float) $installment->interest_due - (float) $installment->interest_paid);
    //                 })
    //             );

    //             $oldestOverdue = $overdueInstallments->sortBy('due_date')->first();
    //             $odDays = 0;
    //             if ($oldestOverdue?->due_date) {
    //                 $odDays = max(0, (int) $oldestOverdue->due_date->diffInDays(Carbon::parse($asOnDate), false));
    //             }

    //             $firstInstallment = $installments->sortBy('installment_number')->first();

    //             foreach ($applicants as $applicant) {

    //                 $rows->push([
    //                     'Consumer Name'                  => $applicant->full_name ?? '',
    //                     'Date of Birth'                  => $bureauDate($applicant->date_of_birth),
    //                     'Gender'                          => $applicant->gender ?? '', // NOT MAPPED: bureau code table (1/2/3) missing
    //                     'Income Tax ID Number'            => $applicant->pan_card_number ?? '',
    //                     'Passport Number'                 => '', // NOT MAPPED: field doesn't exist on model
    //                     'Passport Issue Date'              => '', // NOT MAPPED
    //                     'Passport Expiry Date'             => '', // NOT MAPPED
    //                     'Voter ID Number'                  => '', // NOT MAPPED: field doesn't exist on model
    //                     'Driving License Number'           => '', // NOT MAPPED
    //                     'Driving License Issue Date'        => '', // NOT MAPPED
    //                     'Driving License Expiry Date'       => '', // NOT MAPPED
    //                     'Ration Card Number'               => '', // NOT MAPPED
    //                     'Universal ID Number'              => $applicant->aadhaar_card_number ?? '',
    //                     'Additional ID #1'                 => $applicant->secondary_kyc_number ?? '',
    //                     'Additional ID #2'                 => '',
    //                     'Telephone No.Mobile'              => $lead?->mobile_number ?? '',
    //                     'Telephone No.Residence'           => '', // NOT MAPPED
    //                     'Telephone No.Office'              => '', // NOT MAPPED
    //                     'Extension Office'                 => '',
    //                     'Telephone No.Other '              => $applicant->alternate_contact_number ?? '',
    //                     'Extension Other'                  => '',
    //                     'Email ID 1'                       => $applicant->email_address ?? '',
    //                     'Email ID 2'                        => '',
    //                     'Address 1'                        => $applicant->current_address ?? '',
    //                     'State Code 1'                     => $applicant->current_state ?? '', // NOT MAPPED: needs bureau state-code table
    //                     'PIN Code 1'                       => $applicant->current_pincode ?? '',
    //                     'Address Category 1'               => '', // NOT MAPPED: needs bureau address-category code table
    //                     'Residence Code 1'                 => $applicant->premise_ownership ?? '', // NOT MAPPED: needs bureau code table
    //                     'Address 2'                        => $applicant->permanent_address ?? '',
    //                     'State Code 2'                     => $applicant->permanent_state ?? '', // NOT MAPPED: code table
    //                     'PIN Code 2'                       => $applicant->permanent_pincode ?? '',
    //                     'Address Category 2'               => '', // NOT MAPPED
    //                     'Residence Code 2'                 => '', // NOT MAPPED
    //                     'Current/New Member Code'          => '', // NOT MAPPED: bureau-registered institution code (config value, not in DB)
    //                     'Current/New Member Short Name'    => '', // NOT MAPPED: institution name (config value)
    //                     'Curr/New Account No'              => $account->loan_number ?? '',
    //                     'Account Type'                     => '', // NOT MAPPED: needs bureau account-type code table
    //                     'Ownership Indicator'              => (bool) $applicant->is_co_applicant ? '3' : '1', // GUESS: 1=borrower,3=co-borrower — confirm with bureau spec
    //                     'Date Opened/Disbursed'            => $bureauDate($disbursement?->disbursement_date),
    //                     'Date of Last Payment'             => $bureauDate($lastCollection?->received_date),
    //                     'Date Closed'                      => $bureauDate($account->closed_at),
    //                     'Date Reported'                    => $bureauDate($asOnDate),
    //                     'High Credit/Sanctioned Amt'       => $money($account->principal_amount),
    //                     'Current Balance'                  => $principalOutstandingAsOn,
    //                     'Amt Overdue'                      => $arrear,
    //                     'No of Days Past Due'              => $odDays,
    //                     'Old Mbr Code'                     => '', // NOT MAPPED
    //                     'Old Mbr Short Name'               => '', // NOT MAPPED
    //                     'Old Acc No'                        => '', // NOT MAPPED
    //                     'Old Acc Type'                      => '', // NOT MAPPED
    //                     'Old Ownership Indicator'          => '', // NOT MAPPED
    //                     'Suit Filed / Wilful Default'      => '', // NOT MAPPED: not modeled anywhere
    //                     'Credit_Facility_Status'           => $enumValue($account->status) ?? '', // NOT MAPPED to bureau status codes
    //                     'Asset Classification'             => '', // NOT MAPPED: NPA classification not modeled
    //                     'Value of Collateral'              => '', // NOT MAPPED: no collateral model referenced
    //                     'Type of Collateral'               => '', // NOT MAPPED
    //                     'Credit Limit'                     => '', // NOT MAPPED: not applicable / no field
    //                     'Cash Limit'                       => '', // NOT MAPPED
    //                     'Rate of Interest'                 => $account->interest_rate ?? '',
    //                     'RepaymentTenure'                  => $account->tenure ?? '',
    //                     'EMI Amount'                        => $money($amortization?->installment_amount ?? $firstInstallment?->installment_amount),
    //                     'Written- off Amount (Total)'      => '', // NOT MAPPED: no write-off field found
    //                     'Written- off Principal Amount'    => '', // NOT MAPPED
    //                     'Settlement Amt'                   => $money($settlement?->settlement_amount),
    //                     'Payment Frequency'                => $account->frequency ?? '',
    //                     'Actual Payment Amt'                => $money($lastCollection?->amount),
    //                     'Occupation Code'                   => $applicant->employment_type ?? '', // NOT MAPPED to bureau occupation code table
    //                     'Income'                            => '', // NOT MAPPED: no income field on applicant model
    //                     'Net/Gross Income Indicator'        => '', // NOT MAPPED
    //                     'Monthly/Annual Income Indicator'  => '', // NOT MAPPED
    //                 ]);
    //             }
    //         }

    //         /*
    //          * Success audit entry — कितनी rows generate हुईं।
    //          */
    //         \Illuminate\Support\Facades\Log::channel('bl_audit')->info('CIC_REPORT_SUCCESS', [
    //             'user_id'      => $request->user()?->id,
    //             'as_on_date'   => $asOnDate,
    //             'branch_id'    => $branchId,
    //             'account_count' => $accountCollection->count(),
    //             'row_count'    => $rows->count(),
    //         ]);

    //         return response()->json([
    //             'success' => true,
    //             'message' => 'CIC report fetched successfully.',
    //             'data'    => [
    //                 'as_on_date' => $asOnDate,
    //                 'columns'    => array_keys($rows->first() ?? []),
    //                 'rows'       => $rows->values(),
    //                 'pagination' => [
    //                     'current_page' => $accounts->currentPage(),
    //                     'per_page'     => $accounts->perPage(),
    //                     'last_page'    => $accounts->lastPage(),
    //                     'total'        => $accounts->total(),
    //                     'from'         => $accounts->firstItem(),
    //                     'to'           => $accounts->lastItem(),
    //                 ],
    //             ],
    //         ]);
    //     } catch (\Throwable $e) {
    //         /*
    //          * Exception होने पर पूरा detail 'bl_audit' channel में जाएगा।
    //          */
    //         \Illuminate\Support\Facades\Log::channel('bl_audit')->error('CIC_REPORT_FAILED', [
    //             'user_id'    => $request->user()?->id,
    //             'as_on_date' => $asOnDate,
    //             'branch_id'  => $branchId,
    //             'message'    => $e->getMessage(),
    //             'file'       => $e->getFile(),
    //             'line'       => $e->getLine(),
    //             'trace'      => collect($e->getTrace())->take(10)->toArray(),
    //         ]);

    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Something went wrong while generating CIC report.',
    //             'errors'  => null,
    //         ], 500);
    //     }
    // }



    public function cicReport(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'as_on_date' => 'nullable|date',
            'from_date'  => 'nullable|date',
            'to_date'    => 'nullable|date|after_or_equal:from_date',
            'branch_id'  => 'nullable|integer|exists:organization_branches,id',
            'search'     => 'nullable|string|max:255',
            'per_page'   => 'nullable|integer|min:1|max:500',
        ]);

        $searchTerm = isset($validated['search'])
            ? trim($validated['search'])
            : null;

        $fromDate = $validated['from_date'] ?? null;
        $toDate   = $validated['to_date'] ?? null;
        $asOnDate = $toDate
            ?? $validated['as_on_date']
            ?? now()->toDateString();
        $branchId = !empty($validated['branch_id']) ? (int) $validated['branch_id'] : null;
        $perPage  = (int) ($validated['per_page'] ?? 100);

        /*
         * Request start होते ही audit entry — किसने, कब, किन filters
         * से report generate किया।
         */
        \Illuminate\Support\Facades\Log::channel('bl_audit')->info('CIC_REPORT_REQUEST', [
            'user_id'    => $request->user()?->id,
            'as_on_date' => $asOnDate,
            'from_date'  => $fromDate,
            'to_date'    => $toDate,
            'branch_id'  => $branchId,
            'per_page'   => $perPage,
            'ip'         => $request->ip(),
        ]);

        try {
            $enumValue = static function ($value) {
                return $value instanceof BackedEnum ? $value->value : $value;
            };

            $money = static function ($value): float {
                return round((float) ($value ?? 0), 2);
            };

            /*
             * Bureau date format DDMMYYYY है (sample: 30031990)।
             */
            $bureauDate = static function ($value): string {
                if (!$value) {
                    return '';
                }
                try {
                    return Carbon::parse($value)->format('dmY');
                } catch (\Throwable $e) {
                    return '';
                }
            };

            $accounts = RepaymentAccount::query()
                ->when($branchId !== null, function ($query) use ($branchId) {
                    $query->whereHas('lead', function ($leadQuery) use ($branchId) {
                        $leadQuery->where('branch_id', $branchId);
                    });
                })
                ->when(
                    $searchTerm,
                    function ($query) use ($searchTerm) {
                        $query->where(function ($searchQuery) use ($searchTerm) {
                            $searchQuery
                                ->where(
                                    'loan_number',
                                    'like',
                                    "%{$searchTerm}%"
                                )
                                ->orWhereHas(
                                    'lead',
                                    function ($leadQuery) use ($searchTerm) {
                                        $leadQuery->where(
                                            'lead_id',
                                            'like',
                                            "%{$searchTerm}%"
                                        );
                                    }
                                );
                        });
                    }
                )
                /*
                 * CIC historical eligibility bhi POS jaisi rahegi:
                 * activated_at ke badle disbursement_date <= as-on date.
                 */
                ->whereIn(
                    'lead_uuid',
                    \Modules\BLDisbursment\Models\BLDisbursment::query()
                        ->whereDate('disbursement_date', '<=', $asOnDate)
                        ->select('lead_uuid')
                )
                ->with([
                    'lead',
                    'installments',
                    'transactions' => function ($query) use ($asOnDate) {
                        $query->whereDate('received_date', '<=', $asOnDate)
                            ->orderBy('received_date')
                            ->orderBy('id');
                    },
                    'transactions.allocations',
                ])
                ->orderByDesc('id')
                ->paginate($perPage);

            $accountCollection = $accounts->getCollection();

            $leadUuids = $accountCollection->pluck('lead_uuid')->filter()->unique()->values();
            $accountIds = $accountCollection->pluck('id')->filter()->unique()->values();

            $applicantsByLead = BLApplicantDetails::query()
                ->whereIn('lead_uuid', $leadUuids)
                ->orderBy('applicant_sno')
                ->orderBy('id')
                ->get()
                ->groupBy('lead_uuid');

            $disbursementsByLead = BLDisbursment::query()
                ->whereIn('lead_uuid', $leadUuids)
                ->orderByDesc('id')
                ->get()
                ->groupBy('lead_uuid')
                ->map(fn($records) => $records->first());

            $amortizationsByLead = BLAmortization::query()
                ->whereIn('lead_uuid', $leadUuids)
                ->orderByDesc('id')
                ->get()
                ->groupBy('lead_uuid')
                ->map(fn($records) => $records->first());

            $settlementsByAccount = RepaymentSettlement::query()
                ->whereIn('repayment_account_id', $accountIds)
                ->orderByDesc('id')
                ->get()
                ->groupBy('repayment_account_id')
                ->map(fn($records) => $records->first());

            /*
             * हर applicant एक अलग row बनता है (co-applicant सहित) — bureau
             * format में हर consumer अपनी row में report होता है।
             */
            $rows = collect();

            foreach ($accountCollection as $account) {
                $lead = $account->lead;
                $leadUuid = $account->lead_uuid;

                // $applicants = $applicantsByLead->get($leadUuid, collect());
                // if ($applicants->isEmpty()) {
                //     continue;
                // }


                $applicants = $applicantsByLead
                    ->get($leadUuid, collect())
                    ->filter(function ($applicant) {
                        return trim((string) ($applicant->full_name ?? '')) !== '';
                    })
                    ->sortBy(function ($applicant) {
                        return (bool) $applicant->is_co_applicant ? 1 : 0;
                    })
                    ->take(1)
                    ->values();

                if ($applicants->isEmpty()) {
                    continue;
                }

                $disbursement = $disbursementsByLead->get($leadUuid);
                $amortization = $amortizationsByLead->get($leadUuid);
                $settlement   = $settlementsByAccount->get($account->id);

                $installments = $account->installments ?? collect();

                $postedPayments = ($account->transactions ?? collect())
                    ->filter(function ($transaction) use ($enumValue) {
                        return $enumValue($transaction->transaction_type)
                            === RepaymentTransactionType::PAYMENT->value
                            && $enumValue($transaction->status)
                            === RepaymentTransactionStatus::POSTED->value;
                    });

                $lastCollection = $postedPayments->sortByDesc('received_date')->first();

                /*
                 * CIC mein bhi POS wali EMI-period snapshot rule.
                 * Latest due EMI ka opening principal next EMI date tak same.
                 */
                $snapshotInstallment = $installments
                    ->filter(function ($installment) use ($asOnDate) {
                        return $installment->due_date
                            && $installment->due_date->toDateString() <= $asOnDate;
                    })
                    ->sortByDesc(function ($installment) {
                        return $installment->due_date->toDateString();
                    })
                    ->first();

                $principalOutstandingAsOn = $money(
                    $snapshotInstallment?->opening_principal
                        ?? $account->principal_amount
                );

                $overdueInstallments = $installments->filter(function ($installment) use ($asOnDate) {
                    return $installment->due_date
                        && $installment->due_date->toDateString() <= $asOnDate
                        && (float) $installment->outstanding_amount > 0;
                });

                $arrear = $money(
                    $overdueInstallments->sum(function ($installment) {
                        return max(0, (float) $installment->principal_due - (float) $installment->principal_paid)
                            + max(0, (float) $installment->interest_due - (float) $installment->interest_paid);
                    })
                );

                $oldestOverdue = $overdueInstallments->sortBy('due_date')->first();
                $odDays = 0;
                if ($oldestOverdue?->due_date) {
                    $odDays = max(0, (int) $oldestOverdue->due_date->diffInDays(Carbon::parse($asOnDate), false));
                }

                $firstInstallment = $installments->sortBy('installment_number')->first();

                foreach ($applicants as $applicant) {

                    $rows->push([
                        'Consumer Name'                  => $applicant->full_name ?? '',
                        'Date of Birth'                  => $bureauDate($applicant->date_of_birth),
                        'Gender'                          => $applicant->gender ?? '', // NOT MAPPED: bureau code table (1/2/3) missing
                        'Income Tax ID Number'            => $applicant->pan_card_number ?? '',
                        'Passport Number'                 => '', // NOT MAPPED: field doesn't exist on model
                        'Passport Issue Date'              => '', // NOT MAPPED
                        'Passport Expiry Date'             => '', // NOT MAPPED
                        'Voter ID Number'                  => '', // NOT MAPPED: field doesn't exist on model
                        'Driving License Number'           => '', // NOT MAPPED
                        'Driving License Issue Date'        => '', // NOT MAPPED
                        'Driving License Expiry Date'       => '', // NOT MAPPED
                        'Ration Card Number'               => '', // NOT MAPPED
                        'Universal ID Number'              => $applicant->aadhaar_card_number ?? '',
                        'Additional ID #1'                 => $applicant->secondary_kyc_number ?? '',
                        'Additional ID #2'                 => '',
                        'Telephone No.Mobile'              => $lead?->mobile_number ?? '',
                        'Telephone No.Residence'           => '', // NOT MAPPED
                        'Telephone No.Office'              => '', // NOT MAPPED
                        'Extension Office'                 => '',
                        'Telephone No.Other '              => $applicant->alternate_contact_number ?? '',
                        'Extension Other'                  => '',
                        'Email ID 1'                       => $applicant->email_address ?? '',
                        'Email ID 2'                        => '',
                        'Address 1'                        => $applicant->current_address ?? '',
                        'State Code 1'                     => $applicant->current_state ?? '', // NOT MAPPED: needs bureau state-code table
                        'PIN Code 1'                       => $applicant->current_pincode ?? '',
                        'Address Category 1'               => '', // NOT MAPPED: needs bureau address-category code table
                        'Residence Code 1'                 => $applicant->premise_ownership ?? '', // NOT MAPPED: needs bureau code table
                        'Address 2'                        => $applicant->permanent_address ?? '',
                        'State Code 2'                     => $applicant->permanent_state ?? '', // NOT MAPPED: code table
                        'PIN Code 2'                       => $applicant->permanent_pincode ?? '',
                        'Address Category 2'               => '', // NOT MAPPED
                        'Residence Code 2'                 => '', // NOT MAPPED
                        'Current/New Member Code'          => '', // NOT MAPPED: bureau-registered institution code (config value, not in DB)
                        'Current/New Member Short Name'    => '', // NOT MAPPED: institution name (config value)
                        'Curr/New Account No'              => $account->loan_number ?? '',
                        'Account Type'                     => '', // NOT MAPPED: needs bureau account-type code table
                        'Ownership Indicator'              => (bool) $applicant->is_co_applicant ? '3' : '1', // GUESS: 1=borrower,3=co-borrower — confirm with bureau spec
                        'Date Opened/Disbursed'            => $bureauDate($disbursement?->disbursement_date),
                        'Date of Last Payment'             => $bureauDate($lastCollection?->received_date),
                        'Date Closed'                      => $bureauDate($account->closed_at),
                        'Date Reported'                    => $bureauDate($asOnDate),
                        'High Credit/Sanctioned Amt'       => $money($account->principal_amount),
                        'Current Balance'                  => $principalOutstandingAsOn,
                        'Amt Overdue'                      => $arrear,
                        'No of Days Past Due'              => $odDays,
                        'Old Mbr Code'                     => '', // NOT MAPPED
                        'Old Mbr Short Name'               => '', // NOT MAPPED
                        'Old Acc No'                        => '', // NOT MAPPED
                        'Old Acc Type'                      => '', // NOT MAPPED
                        'Old Ownership Indicator'          => '', // NOT MAPPED
                        'Suit Filed / Wilful Default'      => '', // NOT MAPPED: not modeled anywhere
                        'Credit_Facility_Status'           => $enumValue($account->status) ?? '', // NOT MAPPED to bureau status codes
                        'Asset Classification'             => '', // NOT MAPPED: NPA classification not modeled
                        'Value of Collateral'              => '', // NOT MAPPED: no collateral model referenced
                        'Type of Collateral'               => '', // NOT MAPPED
                        'Credit Limit'                     => '', // NOT MAPPED: not applicable / no field
                        'Cash Limit'                       => '', // NOT MAPPED
                        'Rate of Interest'                 => $account->interest_rate ?? '',
                        'RepaymentTenure'                  => $account->tenure ?? '',
                        'EMI Amount'                        => $money($amortization?->installment_amount ?? $firstInstallment?->installment_amount),
                        'Written- off Amount (Total)'      => '', // NOT MAPPED: no write-off field found
                        'Written- off Principal Amount'    => '', // NOT MAPPED
                        'Settlement Amt'                   => $money($settlement?->settlement_amount),
                        'Payment Frequency'                => $account->frequency ?? '',
                        'Actual Payment Amt'                => $money($lastCollection?->amount),
                        'Occupation Code'                   => $applicant->employment_type ?? '', // NOT MAPPED to bureau occupation code table
                        'Income'                            => '', // NOT MAPPED: no income field on applicant model
                        'Net/Gross Income Indicator'        => '', // NOT MAPPED
                        'Monthly/Annual Income Indicator'  => '', // NOT MAPPED
                    ]);
                }
            }

            /*
             * Success audit entry — कितनी rows generate हुईं।
             */
            \Illuminate\Support\Facades\Log::channel('bl_audit')->info('CIC_REPORT_SUCCESS', [
                'user_id'      => $request->user()?->id,
                'as_on_date'   => $asOnDate,
                'branch_id'    => $branchId,
                'account_count' => $accountCollection->count(),
                'row_count'    => $rows->count(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'CIC report fetched successfully.',
                'data'    => [
                    'as_on_date' => $asOnDate,
                    'columns'    => array_keys($rows->first() ?? []),
                    'rows'       => $rows->values(),
                    'pagination' => [
                        'current_page' => $accounts->currentPage(),
                        'per_page'     => $accounts->perPage(),
                        'last_page'    => $accounts->lastPage(),
                        'total'        => $accounts->total(),
                        'from'         => $accounts->firstItem(),
                        'to'           => $accounts->lastItem(),
                    ],
                ],
            ]);
        } catch (\Throwable $e) {
            /*
             * Exception होने पर पूरा detail 'bl_audit' channel में जाएगा।
             */
            \Illuminate\Support\Facades\Log::channel('bl_audit')->error('CIC_REPORT_FAILED', [
                'user_id'    => $request->user()?->id,
                'as_on_date' => $asOnDate,
                'branch_id'  => $branchId,
                'message'    => $e->getMessage(),
                'file'       => $e->getFile(),
                'line'       => $e->getLine(),
                'trace'      => collect($e->getTrace())->take(10)->toArray(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Something went wrong while generating CIC report.',
                'errors'  => null,
            ], 500);
        }
    }
}
