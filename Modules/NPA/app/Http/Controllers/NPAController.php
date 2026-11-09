<?php

namespace Modules\NPA\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Modules\Repayment\Enums\RepaymentAccountStatus;
use Modules\Repayment\Models\RepaymentAccount;
use Modules\Settlement\Enums\SettlementStatus;
use Modules\Settlement\Models\RepaymentSettlement;
use Modules\Settlement\Services\SettlementService;
use Modules\UserManagement\Support\ReportBranchAccess;

class NPAController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly SettlementService $settlementService
    ) {}

    /**
     * A loan becomes NPA once its oldest overdue installment has been
     * unpaid for more than 90 days (DPD > 90) — this is the standard
     * NBFC/RBI threshold. Sub-classified from there by how long it's
     * *been* NPA (days_as_npa = dpd - 90):
     *   Sub-Standard: 0 - 365 days as NPA
     *   Doubtful:     366 - 1095 days as NPA (1-3 years)
     *   Loss:         1095+ days as NPA (3+ years)
     */
    private const DPD_NPA_THRESHOLD = 90;

    private const STAGES = [
        ['key' => 'SUBSTANDARD', 'label' => 'Sub-Standard', 'min' => 0, 'max' => 365],
        ['key' => 'DOUBTFUL', 'label' => 'Doubtful', 'min' => 366, 'max' => 1095],
        ['key' => 'LOSS', 'label' => 'Loss', 'min' => 1096, 'max' => null],
    ];

    /**
     * Customer-wise NPA report: every loan with DPD > 90, staged
     * Sub-Standard / Doubtful / Loss.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'stage' => 'nullable|string|in:SUBSTANDARD,DOUBTFUL,LOSS',
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

        $rows = $accounts
            ->map(function (RepaymentAccount $account) use ($asOf) {
                $oldestUnpaid = $account->installments->first();

                if (!$oldestUnpaid) {
                    return null;
                }

                $dueDate = CarbonImmutable::parse($oldestUnpaid->due_date, config('app.timezone'))->startOfDay();

                if (!$dueDate->isBefore($asOf)) {
                    return null;
                }

                $dpd = $dueDate->diffInDays($asOf);

                if ($dpd <= self::DPD_NPA_THRESHOLD) {
                    return null;
                }

                $daysAsNpa = $dpd - self::DPD_NPA_THRESHOLD;
                $npaSinceDate = $asOf->subDays($daysAsNpa)->toDateString();

                $applicant = $account->lead?->applicantDetails->first();
                $branch = $account->lead?->branch;
                $settlement = RepaymentSettlement::query()
                    ->where('repayment_account_id', $account->id)
                    ->whereIn('status', [
                        SettlementStatus::PROPOSED->value,
                        SettlementStatus::APPROVED->value,
                    ])
                    ->latest('id')
                    ->first();

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
                    'dpd' => $dpd,
                    'days_as_npa' => $daysAsNpa,
                    'npa_since_date' => $npaSinceDate,
                    'stage' => $this->stageFor($daysAsNpa),
                    'settlement_eligible' => $settlement === null,
                    'settlement' => $settlement ? [
                        'uuid' => $settlement->uuid,
                        'status' => $settlement->status->value,
                        'outstanding_at_proposal' => (float) $settlement->total_outstanding_at_proposal,
                        'settlement_amount' => (float) $settlement->settlement_amount,
                        'created_at' => optional($settlement->created_at)->toDateTimeString(),
                    ] : null,
                ];
            })
            ->filter()
            ->values();

        // if (!empty($validated['branch_id'])) {
        //     $rows = $rows->filter(fn ($row) => ($row['branch']['id'] ?? null) === (int) $validated['branch_id']);
        // }

        if (!empty($validated['search'])) {
            $search = strtolower($validated['search']);
            $rows = $rows->filter(function ($row) use ($search) {
                return str_contains(strtolower((string) $row['loan_number']), $search)
                    || str_contains(strtolower((string) $row['customer_name']), $search)
                    || str_contains((string) $row['mobile_number'], $search);
            });
        }

        $summary = collect(self::STAGES)->map(function ($stage) use ($rows) {
            $inStage = $rows->where('stage', $stage['key']);

            return [
                'stage' => $stage['key'],
                'label' => $stage['label'],
                'count' => $inStage->count(),
                'total_outstanding' => (float) $inStage->sum('total_outstanding'),
                'total_overdue_amount' => (float) $inStage->sum('overdue_amount'),
            ];
        })->values();

        if (!empty($validated['stage'])) {
            $rows = $rows->where('stage', $validated['stage']);
        }

        $rows = $rows->sortByDesc('dpd')->values();

        $perPage = (int) ($validated['per_page'] ?? 25);
        $page = max(1, (int) $request->get('page', 1));
        $paged = $rows->slice(($page - 1) * $perPage, $perPage)->values();

        return $this->success([
            'as_of_date' => $asOf->toDateString(),
            'dpd_threshold' => self::DPD_NPA_THRESHOLD,
            'summary' => $summary,
            'accounts' => $paged,
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $rows->count(),
                'last_page' => (int) max(1, ceil($rows->count() / $perPage)),
            ],
        ], 'NPA report fetched successfully.');
    }

    /**
     * Move an NPA account into the settlement workflow.
     * The amount outstanding at movement and negotiated amount are persisted
     * by the Settlement module for audit/display.
     */
    public function moveToSettlement(Request $request, string $accountUuid): JsonResponse
    {
        $validated = $request->validate([
            'settlement_amount' => 'required|numeric|min:0.01',
            'reason' => 'required|string|max:1000',
        ]);

        $account = RepaymentAccount::query()
            ->where('uuid', $accountUuid)
            ->whereIn('status', [
                RepaymentAccountStatus::ACTIVE->value,
                RepaymentAccountStatus::SUSPENDED->value,
            ])
            ->first();

        if (! $account) {
            return $this->error('NPA repayment account not found or is not eligible for settlement.', null, 404);
        }

        // Re-check DPD so this endpoint cannot move a non-NPA account.
        $oldestUnpaid = $account->installments()
            ->where('outstanding_amount', '>', 0)
            ->orderBy('due_date')
            ->orderBy('installment_number')
            ->first();

        $asOf = CarbonImmutable::now(config('app.timezone'))->startOfDay();
        if (! $oldestUnpaid) {
            return $this->error('Account has no unpaid installment.', null, 422);
        }

        $dueDate = CarbonImmutable::parse($oldestUnpaid->due_date, config('app.timezone'))->startOfDay();
        $dpd = $dueDate->isBefore($asOf) ? $dueDate->diffInDays($asOf) : 0;

        if ($dpd <= self::DPD_NPA_THRESHOLD) {
            return $this->error('Only accounts with DPD greater than 90 can be moved from NPA to settlement.', null, 422);
        }

        try {
            $settlement = $this->settlementService->propose(
                account: $account,
                settlementAmount: (float) $validated['settlement_amount'],
                reason: $validated['reason'],
                proposedBy: optional($request->user())->id
            );

            return $this->success([
                'settlement_uuid' => $settlement->uuid,
                'repayment_account_uuid' => $account->uuid,
                'loan_number' => $account->loan_number,
                'npa_dpd_at_movement' => $dpd,
                'amount_at_movement' => (float) $settlement->total_outstanding_at_proposal,
                'settlement_amount' => (float) $settlement->settlement_amount,
                'waived_amount' => (float) $settlement->waived_amount,
                'status' => $settlement->status->value,
                'moved_to_settlement_at' => optional($settlement->created_at)->toDateTimeString(),
            ], 'NPA lead moved to settlement successfully.', 201);
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), null, 422);
        }
    }

    private function stageFor(int $daysAsNpa): string
    {
        foreach (self::STAGES as $stage) {
            if ($daysAsNpa >= $stage['min'] && ($stage['max'] === null || $daysAsNpa <= $stage['max'])) {
                return $stage['key'];
            }
        }

        return 'LOSS';
    }
}