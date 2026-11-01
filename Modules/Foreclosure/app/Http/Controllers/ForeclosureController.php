<?php

namespace Modules\Foreclosure\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Modules\Foreclosure\Models\Foreclosure;
use Modules\Foreclosure\Services\ForeclosureService;
use Modules\Repayment\Enums\PaymentMode;
use Modules\Repayment\Enums\RepaymentAccountStatus;
use Modules\Repayment\Enums\RepaymentChargeStatus;
use Modules\Repayment\Enums\RepaymentChargeType;
use Modules\Repayment\Models\RepaymentAccount;
use Modules\Repayment\Models\RepaymentCharge;
use Modules\Repayment\Services\RepaymentChargeService;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ForeclosureController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly RepaymentChargeService $chargeService,
        private readonly ForeclosureService $foreclosureService
    ) {}

    /**
     * Active/suspended loans that can still be foreclosed.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => 'nullable|string|max:255',
            'per_page' => 'nullable|integer|min:1|max:200',
        ]);

        // $query = RepaymentAccount::query()
        //     ->whereIn('status', [
        //         RepaymentAccountStatus::ACTIVE->value,
        //         RepaymentAccountStatus::SUSPENDED->value,
        //     ])
        //     ->whereDoesntHave('foreclosure')
        //     ->with([
        //         'lead:uuid,lead_id,branch_id',
        //         'lead.branch:id,name,branch_code',
        //         'lead.applicantDetails' => fn ($query) => $query
        //             ->where('applicant_sno', 0)
        //             ->select([
        //                 'id',
        //                 'lead_uuid',
        //                 'full_name',
        //                 'alternate_contact_number',
        //             ]),
        //     ]);

        $query = RepaymentAccount::query()
    ->whereIn('status', [
        RepaymentAccountStatus::ACTIVE->value,
        RepaymentAccountStatus::SUSPENDED->value,
    ])
    ->whereNotExists(function ($subQuery): void {
        $subQuery
            ->select(DB::raw(1))
            ->from('foreclosures')
            ->whereColumn(
                'foreclosures.repayment_account_id',
                'repayment_accounts.id'
            );
    })
    ->with([
        'lead:uuid,lead_id,branch_id',

        'lead.branch:id,name,branch_code',

        'lead.applicantDetails' => fn ($query) => $query
            ->where('applicant_sno', 0)
            ->select([
                'id',
                'lead_uuid',
                'full_name',
                'alternate_contact_number',
            ]),
    ]);

        if (filled($validated['search'] ?? null)) {
            $search = trim($validated['search']);

            $query->where(function (Builder $query) use ($search): void {
                $query->where('loan_number', 'like', "%{$search}%")
                    ->orWhereHas(
                        'lead.applicantDetails',
                        fn (Builder $applicantQuery) => $applicantQuery
                            ->where('applicant_sno', 0)
                            ->where('full_name', 'like', "%{$search}%")
                    );
            });
        }

        $accounts = $query
            ->orderByDesc('id')
            ->paginate((int) ($validated['per_page'] ?? 25));

        $accounts->getCollection()->transform(
            fn (RepaymentAccount $account): array => $this->eligibleAccountData($account)
        );

        return $this->success([
            'data' => $accounts->items(),
            'meta' => $this->paginationMeta($accounts),
        ], 'Foreclosure-eligible loans fetched successfully.');
    }

    /**
     * Completed foreclosure cases. These remain visible after account closure.
     */
    public function cases(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => 'nullable|string|max:255',
            'per_page' => 'nullable|integer|min:1|max:200',
        ]);

        $query = Foreclosure::query()->with([
            'account.lead:uuid,lead_id,branch_id',
            'account.lead.branch:id,name,branch_code',
            'account.lead.applicantDetails' => fn ($query) => $query
                ->where('applicant_sno', 0)
                ->select([
                    'id',
                    'lead_uuid',
                    'full_name',
                    'alternate_contact_number',
                ]),
            'transaction',
            'charge',
        ]);

        if (filled($validated['search'] ?? null)) {
            $search = trim($validated['search']);

            $query->where(function (Builder $query) use ($search): void {
                $query->whereHas(
                    'account',
                    fn (Builder $accountQuery) => $accountQuery
                        ->where('loan_number', 'like', "%{$search}%")
                )->orWhereHas(
                    'account.lead.applicantDetails',
                    fn (Builder $applicantQuery) => $applicantQuery
                        ->where('applicant_sno', 0)
                        ->where('full_name', 'like', "%{$search}%")
                );
            });
        }

        $cases = $query
            ->orderByDesc('foreclosure_date')
            ->orderByDesc('id')
            ->paginate((int) ($validated['per_page'] ?? 25));

        $cases->getCollection()->transform(
            fn (Foreclosure $foreclosure): array => $this->foreclosureData($foreclosure)
        );

        return $this->success([
            'data' => $cases->items(),
            'meta' => $this->paginationMeta($cases),
        ], 'Foreclosed loan cases fetched successfully.');
    }

    /**
     * Execute a foreclosure and create its repayment transaction/case record.
     */
    public function store(Request $request, string $accountUuid): JsonResponse
    {
        $validated = $request->validate([
            'foreclosure_amount' => 'required|numeric|min:0.01',
            'foreclosure_date' => 'nullable|date_format:Y-m-d|before_or_equal:today',
            'payment_mode' => ['required', Rule::enum(PaymentMode::class)],
            'transaction_reference' => 'nullable|string|max:150',
            'basis' => 'required|string|in:PERCENTAGE,AMOUNT',
            'value' => 'required|numeric|min:0.01',
            'waive_amount' => 'nullable|numeric|min:0.01',
            'waiver_reason' => 'required_with:waive_amount|nullable|string|max:500',
            'remarks' => 'nullable|string|max:1000',
        ]);

        try {
            $foreclosure = DB::transaction(function () use (
                $accountUuid,
                $validated,
                $request
            ): Foreclosure {
                $account = RepaymentAccount::query()
                    ->where('uuid', $accountUuid)
                    ->lockForUpdate()
                    ->first();

                if (! $account) {
                    throw new InvalidArgumentException('Repayment account not found.');
                }

                if (! in_array($account->status, [
                    RepaymentAccountStatus::ACTIVE,
                    RepaymentAccountStatus::SUSPENDED,
                ], true)) {
                    throw new InvalidArgumentException(
                        'Only active or suspended accounts can be foreclosed.'
                    );
                }

                if (Foreclosure::query()
                    ->where('repayment_account_id', $account->id)
                    ->exists()) {
                    throw new InvalidArgumentException(
                        'This repayment account has already been foreclosed.'
                    );
                }

                $existingCharge = RepaymentCharge::query()
                    ->where('repayment_account_id', $account->id)
                    ->where('charge_type', RepaymentChargeType::FORECLOSURE->value)
                    ->where('status', '<>', RepaymentChargeStatus::WAIVED->value)
                    ->lockForUpdate()
                    ->first();

                if ($existingCharge) {
                    throw new InvalidArgumentException(
                        'A foreclosure charge has already been levied on this account.'
                    );
                }

                $actorId = optional($request->user())->id;

                $charge = $this->chargeService->levyForeclosureCharge(
                    account: $account,
                    basis: $validated['basis'],
                    value: (float) $validated['value'],
                    reason: $validated['remarks'] ?? null,
                    leviedBy: $actorId
                );

                if (isset($validated['waive_amount'])) {
                    $charge = $this->chargeService->waive(
                        charge: $charge,
                        waiveAmount: (float) $validated['waive_amount'],
                        reason: $validated['waiver_reason'],
                        waivedBy: $actorId
                    );
                }

                return $this->foreclosureService->execute(
                    account: $account,
                    charge: $charge,
                    foreclosureAmount: (float) $validated['foreclosure_amount'],
                    foreclosureDate: $validated['foreclosure_date'] ?? now()->toDateString(),
                    paymentMode: PaymentMode::from($validated['payment_mode']),
                    transactionReference: $validated['transaction_reference'] ?? null,
                    remarks: $validated['remarks'] ?? null,
                    actorId: $actorId
                );
            });

            $foreclosure->loadMissing([
                'account.lead.branch',
                'account.lead.applicantDetails',
                'transaction',
                'charge',
            ]);

            return $this->success(
                $this->foreclosureData($foreclosure),
                'Loan foreclosed successfully.'
            );
        } catch (InvalidArgumentException $exception) {
            $status = $exception->getMessage() === 'Repayment account not found.'
                ? 404
                : 422;

            return $this->error($exception->getMessage(), null, $status);
        } catch (Throwable $exception) {
            Log::error('Foreclosure execution failed.', [
                'repayment_account_uuid' => $accountUuid,
                'actor_id' => optional($request->user())->id,
                'exception_class' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return $this->error('Failed to foreclose loan.', null, 500);
        }
    }

    /**
     * Download a complete foreclosure letter for a completed case.
     */
    public function download(string $foreclosureUuid): Response
    {
        $foreclosure = Foreclosure::query()
            ->where('uuid', $foreclosureUuid)
            ->with([
                'account.lead.branch',
                'account.lead.applicantDetails' => fn ($query) => $query
                    ->where('applicant_sno', 0)
                    ->orderBy('id'),
                'account.disbursement',
                'transaction',
                'charge',
                'foreclosedBy',
            ])
            ->firstOrFail();

        $loanNumber = Str::slug(
            (string) ($foreclosure->account?->loan_number ?: $foreclosure->uuid)
        );

        return Pdf::loadView(
            'foreclosure::pdf.foreclosure-letter',
            ['foreclosure' => $foreclosure]
        )
            ->setPaper('a4')
            ->download("foreclosure-letter-{$loanNumber}.pdf");
    }

    private function eligibleAccountData(RepaymentAccount $account): array
    {
        $applicant = $account->lead?->applicantDetails->first();
        $branch = $account->lead?->branch;

        return [
            'repayment_account_uuid' => $account->uuid,
            'loan_number' => $account->loan_number,
            'lead_id' => $account->lead?->lead_id,
            'customer_name' => $applicant?->full_name,
            'mobile_number' => $applicant?->alternate_contact_number,
            'branch' => $branch
                ? ['id' => $branch->id, 'name' => $branch->name]
                : null,
            'principal_outstanding' => (float) $account->principal_outstanding,
            'interest_outstanding' => (float) $account->interest_outstanding,
            'total_outstanding' => (float) $account->total_outstanding,
            'status' => $account->status->value,
        ];
    }

    private function foreclosureData(Foreclosure $foreclosure): array
    {
        $account = $foreclosure->account;
        $applicant = $account?->lead?->applicantDetails?->first();
        $branch = $account?->lead?->branch;

        return [
            'foreclosure_uuid' => $foreclosure->uuid,
            'repayment_account_uuid' => $account?->uuid,
            'transaction_uuid' => $foreclosure->transaction?->uuid,
            'transaction_number' => $foreclosure->transaction?->transaction_number,
            'loan_number' => $account?->loan_number,
            'lead_id' => $account?->lead?->lead_id,
            'customer_name' => $applicant?->full_name,
            'mobile_number' => $applicant?->alternate_contact_number,
            'branch' => $branch
                ? ['id' => $branch->id, 'name' => $branch->name]
                : null,
            'foreclosure_amount' => (float) $foreclosure->foreclosure_amount,
            'foreclosure_date' => $foreclosure->foreclosure_date?->format('Y-m-d'),
            'foreclosure_charges' => (float) $foreclosure->foreclosure_charges,
            'waived_charges' => (float) $foreclosure->waived_charges,
            'net_foreclosure_charges' => (float) $foreclosure->net_foreclosure_charges,
            'total_closure_payable' => (float) $foreclosure->totalClosurePayable(),
            'payment_mode' => $foreclosure->payment_mode?->value,
            'transaction_reference' => $foreclosure->transaction_reference,
            'status' => $account?->status?->value,
            'pdf_url' => route('api.foreclosure.cases.download', [
                'foreclosureUuid' => $foreclosure->uuid,
            ]),
        ];
    }

    private function paginationMeta($paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ];
    }
}


// namespace Modules\Foreclosure\Http\Controllers;

// use App\Http\Controllers\Controller;
// use App\Traits\ApiResponse;
// use Illuminate\Http\JsonResponse;
// use Illuminate\Http\Request;
// use Illuminate\Support\Facades\DB;
// use InvalidArgumentException;
// use Modules\Repayment\Enums\RepaymentAccountStatus;
// use Modules\Repayment\Enums\RepaymentChargeStatus;
// use Modules\Repayment\Enums\RepaymentChargeType;
// use Modules\Repayment\Enums\StatusHistorySource;
// use Modules\Repayment\Enums\StatusHistoryType;
// use Modules\Repayment\Models\RepaymentAccount;
// use Modules\Repayment\Models\RepaymentCharge;
// use Modules\Repayment\Models\RepaymentStatusHistory;
// use Modules\Repayment\Services\RepaymentChargeService;
// use Throwable;

// class ForeclosureController extends Controller
// {
//     use ApiResponse;

//     public function __construct(
//         private readonly RepaymentChargeService $chargeService
//     ) {}

//     /**
//      * Loans eligible for foreclosure — every active/suspended account,
//      * with its current outstanding.
//      */
//     public function index(Request $request): JsonResponse
//     {
//         $validated = $request->validate([
//             'search' => 'nullable|string|max:255',
//             'per_page' => 'nullable|integer|min:1|max:200',
//         ]);

//         $query = RepaymentAccount::query()
//             ->whereIn('status', [
//                 RepaymentAccountStatus::ACTIVE->value,
//                 RepaymentAccountStatus::SUSPENDED->value,
//             ])
//             ->with([
//                 'lead:uuid,lead_id,branch_id',
//                 'lead.branch:id,name,branch_code',
//                 'lead.applicantDetails' => fn ($q) => $q
//                     ->where('applicant_sno', 0)
//                     ->select(['id', 'lead_uuid', 'full_name', 'alternate_contact_number']),
//             ]);

//         if (!empty($validated['search'])) {
//             $search = $validated['search'];
//             $query->where(function ($q) use ($search) {
//                 $q->where('loan_number', 'like', "%{$search}%")
//                     ->orWhereHas('lead.applicantDetails', fn ($aq) => $aq->where('full_name', 'like', "%{$search}%"));
//             });
//         }

//         $accounts = $query->orderByDesc('id')->paginate((int) ($validated['per_page'] ?? 25));

//         $accounts->getCollection()->transform(function (RepaymentAccount $account) {
//             $applicant = $account->lead?->applicantDetails->first();
//             $branch = $account->lead?->branch;

//             $pendingForeclosureCharge = RepaymentCharge::query()
//                 ->where('repayment_account_id', $account->id)
//                 ->where('charge_type', RepaymentChargeType::FORECLOSURE->value)
//                 ->whereNot('status', RepaymentChargeStatus::WAIVED->value)
//                 ->latest('id')
//                 ->first();

//             return [
//                 'repayment_account_uuid' => $account->uuid,
//                 'loan_number' => $account->loan_number,
//                 'lead_id' => $account->lead?->lead_id,
//                 'customer_name' => $applicant?->full_name,
//                 'mobile_number' => $applicant?->alternate_contact_number,
//                 'branch' => $branch ? ['id' => $branch->id, 'name' => $branch->name] : null,
//                 'principal_outstanding' => $account->principal_outstanding,
//                 'total_outstanding' => $account->total_outstanding,
//                 'status' => $account->status->value,
//                 'pending_foreclosure_charge' => $pendingForeclosureCharge,
//             ];
//         });

//         return $this->success([
//             'data' => $accounts->items(),
//             'meta' => [
//                 'current_page' => $accounts->currentPage(),
//                 'per_page' => $accounts->perPage(),
//                 'total' => $accounts->total(),
//                 'last_page' => $accounts->lastPage(),
//             ],
//         ], 'Foreclosure-eligible loans fetched successfully.');
//     }

//     /**
//      * Execute a foreclosure: levy the charge (percentage of outstanding
//      * principal, or a flat amount — admin's choice), optionally waive part
//      * or all of it immediately, then close the account.
//      */
//     public function store(Request $request, string $accountUuid): JsonResponse
//     {
//         $account = RepaymentAccount::where('uuid', $accountUuid)->first();

//         if (!$account) {
//             return $this->error('Repayment account not found.', null, 404);
//         }

//         if (!in_array($account->status, [RepaymentAccountStatus::ACTIVE, RepaymentAccountStatus::SUSPENDED], true)) {
//             return $this->error('Only active or suspended accounts can be foreclosed.', null, 422);
//         }

//         $validated = $request->validate([
//             'basis' => 'required|string|in:PERCENTAGE,AMOUNT',
//             'value' => 'required|numeric|min:0.01',
//             'waive_amount' => 'nullable|numeric|min:0.01',
//             'waiver_reason' => 'required_with:waive_amount|nullable|string|max:500',
//             'remarks' => 'nullable|string|max:1000',
//         ]);

//         try {
//             $result = DB::transaction(function () use ($account, $validated, $request) {
//                 $locked = RepaymentAccount::where('id', $account->id)->lockForUpdate()->first();

//                 if (!in_array($locked->status, [RepaymentAccountStatus::ACTIVE, RepaymentAccountStatus::SUSPENDED], true)) {
//                     throw new InvalidArgumentException('Only active or suspended accounts can be foreclosed.');
//                 }

//                 $existingCharge = RepaymentCharge::query()
//                     ->where('repayment_account_id', $locked->id)
//                     ->where('charge_type', RepaymentChargeType::FORECLOSURE->value)
//                     ->whereNot('status', RepaymentChargeStatus::WAIVED->value)
//                     ->latest('id')
//                     ->first();

//                 if ($existingCharge) {
//                     throw new InvalidArgumentException('A foreclosure charge has already been levied on this account.');
//                 }

//                 $charge = $this->chargeService->levyForeclosureCharge(
//                     account: $locked,
//                     basis: $validated['basis'],
//                     value: (float) $validated['value'],
//                     reason: $validated['remarks'] ?? null,
//                     leviedBy: optional($request->user())->id
//                 );

//                 if (!empty($validated['waive_amount'])) {
//                     $charge = $this->chargeService->waive(
//                         charge: $charge,
//                         waiveAmount: (float) $validated['waive_amount'],
//                         reason: $validated['waiver_reason'],
//                         waivedBy: optional($request->user())->id
//                     );
//                 }

//                 // A foreclosure isn't just a status flip — the customer is
//                 // paying off every remaining rupee of principal + interest
//                 // right now, on top of the foreclosure fee above. Settle the
//                 // ledger to zero before closing, the same way a normal
//                 // full-and-final payment would.
//                 $oldStatus = $locked->status;

//                 $this->chargeService->payOffOutstandingBalance(
//                     account: $locked,
//                     reason: $validated['remarks'] ?? 'Foreclosure — full payoff of remaining principal and interest.',
//                     actorId: optional($request->user())->id
//                 );

//                 $locked->refresh();

//                 $locked->update([
//                     'status' => RepaymentAccountStatus::CLOSED->value,
//                     'closed_at' => now(),
//                 ]);

//                 RepaymentStatusHistory::create([
//                     'repayment_account_id' => $locked->id,
//                     'status_type' => StatusHistoryType::ACCOUNT_STATUS->value,
//                     'old_status' => $oldStatus->value,
//                     'new_status' => RepaymentAccountStatus::CLOSED->value,
//                     'source' => StatusHistorySource::ACCOUNT_CLOSURE->value,
//                     'reason' => 'Loan foreclosed — full payoff and closure.',
//                     'metadata' => [
//                         'foreclosure_charge_uuid' => $charge->uuid,
//                         'foreclosure_charge_net_payable' => $charge->net_payable,
//                     ],
//                     'changed_by' => optional($request->user())->id,
//                     'changed_at' => now(),
//                 ]);

//                 return ['account' => $locked->fresh(), 'charge' => $charge];
//             });

//             return $this->success($result, 'Loan foreclosed successfully.');
//         } catch (InvalidArgumentException $exception) {
//             return $this->error($exception->getMessage(), null, 422);
//         } catch (Throwable $exception) {
//             return $this->error('Failed to foreclose loan: ' . $exception->getMessage(), null, 500);
//         }
//     }
// }
