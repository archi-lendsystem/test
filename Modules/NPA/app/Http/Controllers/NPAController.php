<?php

namespace Modules\NPA\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Repayment\Enums\RepaymentAccountStatus;
use Modules\Repayment\Models\RepaymentAccount;

class NPAController extends Controller
{
    use ApiResponse;

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

        $asOf = CarbonImmutable::now(config('app.timezone'))->startOfDay();

        $accounts = RepaymentAccount::query()
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
                    ->select(['id', 'lead_uuid', 'full_name', 'alternate_contact_number']),
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
                ];
            })
            ->filter()
            ->values();

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
