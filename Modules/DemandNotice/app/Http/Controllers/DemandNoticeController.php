<?php

namespace Modules\DemandNotice\Http\Controllers;

use App\Http\Controllers\Controller;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Modules\DemandNotice\Enums\DemandNoticeStatus;
use Modules\DemandNotice\Enums\DemandNoticeType;
use Modules\DemandNotice\Models\DemandNotice;
use Modules\Repayment\Enums\RepaymentAccountStatus;
use Modules\Repayment\Models\RepaymentAccount;
use Modules\UserManagement\Support\ReportBranchAccess;
use Throwable;

class DemandNoticeController extends Controller
{
    /**
     * Accounts currently overdue, with their latest demand notice (if any).
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => 'nullable|integer|exists:organization_branches,id',
            'search' => 'nullable|string|max:255',
            'per_page' => 'nullable|integer|min:1|max:200',
        ]);

        $branchId = !empty($validated['branch_id'])
            ? (int) $validated['branch_id']
            : null;

        $query = ReportBranchAccess::scopeByRelation(
            RepaymentAccount::query(),
            $request->user(),
            'lead',
            $branchId
        )
            ->whereIn('status', [RepaymentAccountStatus::ACTIVE->value, RepaymentAccountStatus::SUSPENDED->value])
            ->where('overdue_amount', '>', 0)
            ->with([
                'lead:uuid,lead_id,branch_id',
                'lead.branch:id,name,branch_code',
                'lead.applicantDetails' => fn($q) => $q
                    ->where('applicant_sno', 0)
                    ->select(['id', 'lead_uuid', 'full_name', 'alternate_contact_number']),
                'demandNotices' => fn($q) => $q->latest('id')->limit(1),
            ]);

        if (!empty($validated['search'])) {
            $search = $validated['search'];
            $query->where(function ($q) use ($search) {
                $q->where('loan_number', 'like', "%{$search}%")
                    ->orWhereHas('lead.applicantDetails', fn($aq) => $aq->where('full_name', 'like', "%{$search}%"));
            });
        }

        $accounts = $query->orderByDesc('overdue_amount')->paginate((int) ($validated['per_page'] ?? 25));

        $accounts->getCollection()->transform(function (RepaymentAccount $account) {
            $applicant = $account->lead?->applicantDetails->first();
            $branch = $account->lead?->branch;
            $latestNotice = $account->demandNotices->first();

            return [
                'repayment_account_uuid' => $account->uuid,
                'loan_number' => $account->loan_number,
                'frequency' => $account->frequency,
                'lead_id' => $account->lead?->lead_id,
                'customer_name' => $applicant?->full_name,
                'mobile_number' => $applicant?->alternate_contact_number,
                'branch' => $branch ? ['id' => $branch->id, 'name' => $branch->name] : null,
                'overdue_amount' => $account->overdue_amount,
                'latest_notice' => $latestNotice ? [
                    'uuid' => $latestNotice->uuid,
                    'notice_number' => $latestNotice->notice_number,
                    'notice_type' => $latestNotice->notice_type->value,
                    'notice_date' => optional($latestNotice->notice_date)->toDateString(),
                    'status' => $latestNotice->status->value,
                ] : null,
            ];
        });

        return response()->json([
            'success' => true,
            'message' => 'Overdue accounts fetched successfully.',
            'data' => $accounts->items(),
            'meta' => [
                'current_page' => $accounts->currentPage(),
                'per_page' => $accounts->perPage(),
                'total' => $accounts->total(),
                'last_page' => $accounts->lastPage(),
            ],
        ]);
    }

    /**
     * Full notice history for one account, most recent first.
     */
    public function history(string $accountUuid): JsonResponse
    {
        $account = RepaymentAccount::where('uuid', $accountUuid)->first();

        if (!$account) {
            return response()->json(['success' => false, 'message' => 'Repayment account not found.'], 404);
        }

        $notices = DemandNotice::query()
            ->where('repayment_account_id', $account->id)
            ->with('generatedBy:id,user_name')
            ->orderByDesc('id')
            ->get();

        return response()->json(['success' => true, 'data' => $notices]);
    }

    public function store(Request $request, string $accountUuid): JsonResponse
    {
        $account = RepaymentAccount::with([
            'lead:uuid,lead_id',
            'lead.applicantDetails' => fn($q) => $q->where('applicant_sno', 0),
        ])->where('uuid', $accountUuid)->first();

        if (!$account) {
            return response()->json(['success' => false, 'message' => 'Repayment account not found.'], 404);
        }

        if ((float) $account->overdue_amount <= 0) {
            return response()->json(['success' => false, 'message' => 'This account has no overdue amount to issue a notice for.'], 422);
        }

        $validated = $request->validate([
            'notice_type' => ['required', 'string', Rule::in(array_column(DemandNoticeType::cases(), 'value'))],
            'remarks' => 'nullable|string|max:1000',
        ]);

        try {
            $noticeType = DemandNoticeType::from($validated['notice_type']);

            $oldestOverdue = $account->installments()
                ->overdue()
                ->orderBy('due_date')
                ->first();

            $applicant = $account->lead?->applicantDetails->first();
            $applicantName = $applicant?->full_name ?? '—';
            $leadId = $account->lead?->lead_id ?? '—';

            $noticeDate = now()->toDateString();
            $dueByDate = now()->addDays($noticeType->responseWindowDays())->toDateString();

            $notice = $this->createNoticeWithRetry(
                accountId: $account->id,
                noticeType: $noticeType,
                noticeDate: $noticeDate,
                dueByDate: $dueByDate,
                overdueSinceDate: optional($oldestOverdue?->due_date)->toDateString(),
                overdueAmount: (float) $account->overdue_amount,
                remarks: $validated['remarks'] ?? null,
                generatedBy: optional($request->user())->id,
            );

            $pdf = Pdf::loadView('demandnotice::pdf.demand-notice', [
                'notice' => $notice,
                'account' => $account,
                'applicantName' => $applicantName,
                'leadId' => $leadId,
            ])->setPaper('a4', 'portrait');

            $pdfPath = 'demand-notices/' . now()->format('Y/m') . "/{$notice->notice_number}.pdf";
            Storage::disk('local')->put($pdfPath, $pdf->output());

            $notice->update(['pdf_path' => $pdfPath]);

            return response()->json(['success' => true, 'message' => 'Demand notice generated successfully.', 'data' => $notice->fresh()], 201);
        } catch (Throwable $exception) {
            return response()->json(['success' => false, 'message' => 'Failed to generate demand notice: ' . $exception->getMessage()], 500);
        }
    }

    public function markSent(Request $request, string $noticeUuid): JsonResponse
    {
        $notice = DemandNotice::where('uuid', $noticeUuid)->first();

        if (!$notice) {
            return response()->json(['success' => false, 'message' => 'Demand notice not found.'], 404);
        }

        $validated = $request->validate([
            'sent_via' => ['required', 'string', Rule::in(['EMAIL', 'SMS', 'POST', 'WHATSAPP'])],
        ]);

        $notice->update([
            'status' => DemandNoticeStatus::SENT->value,
            'sent_via' => $validated['sent_via'],
            'sent_at' => now(),
        ]);

        return response()->json(['success' => true, 'message' => 'Notice marked as sent.', 'data' => $notice->fresh()]);
    }

    public function download(string $noticeUuid)
    {
        $notice = DemandNotice::where('uuid', $noticeUuid)->first();

        if (!$notice || !$notice->pdf_path || !Storage::disk('local')->exists($notice->pdf_path)) {
            return response()->json(['success' => false, 'message' => 'Demand notice PDF not found.'], 404);
        }

        return Storage::disk('local')->download($notice->pdf_path, "{$notice->notice_number}.pdf");
    }

    /**
     * generateNoticeNumber() reads a count then writes — two notices issued
     * at the same instant could compute the same seq. Retry on the
     * notice_number unique-constraint violation instead of trusting the count.
     */
    private function createNoticeWithRetry(
        int $accountId,
        DemandNoticeType $noticeType,
        string $noticeDate,
        string $dueByDate,
        ?string $overdueSinceDate,
        float $overdueAmount,
        ?string $remarks,
        ?int $generatedBy,
    ): DemandNotice {
        $attempts = 0;

        while (true) {
            try {
                return DemandNotice::create([
                    'repayment_account_id' => $accountId,
                    'notice_number' => $this->generateNoticeNumber(),
                    'notice_type' => $noticeType->value,
                    'notice_date' => $noticeDate,
                    'due_by_date' => $dueByDate,
                    'overdue_since_date' => $overdueSinceDate,
                    'overdue_amount' => $overdueAmount,
                    'status' => DemandNoticeStatus::GENERATED->value,
                    'remarks' => $remarks,
                    'generated_by' => $generatedBy,
                ]);
            } catch (QueryException $exception) {
                $attempts++;
                if ($attempts >= 3 || !str_contains($exception->getMessage(), 'notice_number')) {
                    throw $exception;
                }
            }
        }
    }

    private function generateNoticeNumber(): string
    {
        $year = now()->format('Y');
        $count = DemandNotice::query()->whereYear('created_at', $year)->count() + 1;

        return sprintf('DN-%s-%04d', $year, $count);
    }
}
