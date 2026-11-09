<?php

namespace Modules\Settlement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Modules\Repayment\Enums\RepaymentAccountStatus;
use Modules\Repayment\Models\RepaymentAccount;
use Modules\Settlement\Models\RepaymentSettlement;
use Modules\Settlement\Services\SettlementService;
use RuntimeException;
use Throwable;

class SettlementController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly SettlementService $settlementService
    ) {}

    /**
     * Settlement proposals, optionally filtered by status.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'nullable|string|in:PROPOSED,APPROVED,REJECTED,PAID',
            'search' => 'nullable|string|max:255',
            'per_page' => 'nullable|integer|min:1|max:200',
        ]);

        $query = RepaymentSettlement::query()
            ->with([
                'account:id,uuid,loan_number,lead_uuid,total_outstanding,status',
                'account.lead:uuid,lead_id',
                'account.lead.applicantDetails' => fn ($q) => $q
                    ->where('applicant_sno', 0)
                    ->select(['id', 'lead_uuid', 'full_name', 'alternate_contact_number']),
                'proposedBy:id,user_name',
                'approvedBy:id,user_name',
            ]);

        if (!empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (!empty($validated['search'])) {
            $search = $validated['search'];
            $query->whereHas('account', fn ($aq) => $aq->where('loan_number', 'like', "%{$search}%"));
        }

        $settlements = $query->orderByDesc('id')->paginate((int) ($validated['per_page'] ?? 25));

        $settlements->getCollection()->transform(function (RepaymentSettlement $settlement): array {
            $account = $settlement->account;
            $applicant = $account?->lead?->applicantDetails?->first();

            return [
                'settlement_uuid' => $settlement->uuid,
                'repayment_account_uuid' => $account?->uuid,
                'loan_number' => $account?->loan_number,
                'lead_id' => $account?->lead?->lead_id,
                'customer_name' => $applicant?->full_name,
                'mobile_number' => $applicant?->alternate_contact_number,
                'amount_at_movement' => (float) $settlement->total_outstanding_at_proposal,
                'customer_settlement_amount' => (float) $settlement->settlement_amount,
                'waived_amount' => (float) $settlement->waived_amount,
                'status' => $settlement->status->value,
                'account_status' => $account?->status?->value,
                'moved_to_settlement_at' => optional($settlement->created_at)->toDateTimeString(),
                'settlement_date' => optional($settlement->settlement_date)->toDateString(),
                'approved_at' => optional($settlement->approved_at)->toDateTimeString(),
                'paid_at' => optional($settlement->paid_at)->toDateTimeString(),
                'reason' => $settlement->reason,
                'decision_remarks' => $settlement->decision_remarks,
                'paid_reference' => $settlement->paid_reference,
            ];
        });

        return $this->success([
            'data' => $settlements->items(),
            'meta' => [
                'current_page' => $settlements->currentPage(),
                'per_page' => $settlements->perPage(),
                'total' => $settlements->total(),
                'last_page' => $settlements->lastPage(),
            ],
        ], 'Settlements fetched successfully.');
    }

    /**
     * Propose a full-and-final settlement for an account.
     */
    public function store(Request $request, string $accountUuid): JsonResponse
    {
        $account = RepaymentAccount::where('uuid', $accountUuid)->first();

        if (!$account) {
            return $this->error('Repayment account not found.', null, 404);
        }

        if (!in_array($account->status, [RepaymentAccountStatus::ACTIVE, RepaymentAccountStatus::SUSPENDED], true)) {
            return $this->error('Only active or suspended accounts can have a settlement proposed.', null, 422);
        }

        $validated = $request->validate([
            'settlement_amount' => 'required|numeric|min:0.01',
            'reason' => 'required|string|max:1000',
        ]);

        try {
            $settlement = $this->settlementService->propose(
                account: $account,
                settlementAmount: (float) $validated['settlement_amount'],
                reason: $validated['reason'],
                proposedBy: optional($request->user())->id
            );

            return $this->success($settlement, 'Settlement proposed successfully.', 201);
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), null, 422);
        }
    }

    public function approve(Request $request, string $settlementUuid): JsonResponse
    {
        return $this->transition($request, $settlementUuid, 'approve');
    }

    public function reject(Request $request, string $settlementUuid): JsonResponse
    {
        return $this->transition($request, $settlementUuid, 'reject');
    }

    /**
     * Mark an approved settlement as paid, closing the loan.
     */
    public function markPaid(Request $request, string $settlementUuid): JsonResponse
    {
        $settlement = RepaymentSettlement::where('uuid', $settlementUuid)->first();

        if (!$settlement) {
            return $this->error('Settlement not found.', null, 404);
        }

        $validated = $request->validate([
            'paid_reference' => 'nullable|string|max:255',
            'settlement_date' => 'nullable|date_format:Y-m-d|before_or_equal:today',
        ]);

        try {
            $updated = $this->settlementService->markPaidAndClose(
                settlement: $settlement,
                paidReference: $validated['paid_reference'] ?? null,
                actorId: optional($request->user())->id,
                settlementDate: $validated['settlement_date'] ?? now()->toDateString()
            );

            return $this->success($updated, 'Settlement marked as paid; loan closed.');
        } catch (RuntimeException $exception) {
            return $this->error($exception->getMessage(), null, 422);
        } catch (Throwable $exception) {
            return $this->error('Failed to close the settlement: ' . $exception->getMessage(), null, 500);
        }
    }

    private function transition(Request $request, string $settlementUuid, string $action): JsonResponse
    {
        $settlement = RepaymentSettlement::where('uuid', $settlementUuid)->first();

        if (!$settlement) {
            return $this->error('Settlement not found.', null, 404);
        }

        $validated = $request->validate([
            'remarks' => $action === 'reject' ? 'required|string|max:1000' : 'nullable|string|max:1000',
        ]);

        try {
            $updated = $action === 'approve'
                ? $this->settlementService->approve($settlement, $validated['remarks'] ?? null, optional($request->user())->id)
                : $this->settlementService->reject($settlement, $validated['remarks'], optional($request->user())->id);

            return $this->success($updated, $action === 'approve' ? 'Settlement approved.' : 'Settlement rejected.');
        } catch (RuntimeException $exception) {
            return $this->error($exception->getMessage(), null, 422);
        }
    }
}