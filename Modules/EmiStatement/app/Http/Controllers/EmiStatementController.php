<?php

namespace Modules\EmiStatement\Http\Controllers;

use App\Http\Controllers\Controller;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\EmiStatement\Models\EmiStatement;
use Modules\Repayment\Enums\InstallmentPaymentStatus;
use Modules\Repayment\Models\RepaymentAccount;
use Throwable;

class EmiStatementController extends Controller
{
    /**
     * Every loan account (any status — a customer can want a statement for
     * a closed loan too), with a quick paid/pending installment count.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => 'nullable|string|max:255',
            'per_page' => 'nullable|integer|min:1|max:200',
        ]);

        $query = RepaymentAccount::query()
            ->with([
                'lead:uuid,lead_id,branch_id',
                'lead.branch:id,name,branch_code',
                'lead.applicantDetails' => fn ($q) => $q
                    ->where('applicant_sno', 0)
                    ->select(['id', 'lead_uuid', 'full_name', 'alternate_contact_number']),
            ])
            ->withCount([
                'installments as paid_installments_count' => fn ($q) => $q->where('payment_status', InstallmentPaymentStatus::PAID->value),
                'installments as total_installments_count',
            ]);

        if (!empty($validated['search'])) {
            $search = $validated['search'];
            $query->where(function ($q) use ($search) {
                $q->where('loan_number', 'like', "%{$search}%")
                    ->orWhereHas('lead.applicantDetails', fn ($aq) => $aq->where('full_name', 'like', "%{$search}%"));
            });
        }

        $accounts = $query->orderByDesc('id')->paginate((int) ($validated['per_page'] ?? 25));

        $accounts->getCollection()->transform(function (RepaymentAccount $account) {
            $applicant = $account->lead?->applicantDetails->first();
            $branch = $account->lead?->branch;

            return [
                'repayment_account_uuid' => $account->uuid,
                'loan_number' => $account->loan_number,
                'lead_id' => $account->lead?->lead_id,
                'customer_name' => $applicant?->full_name,
                'mobile_number' => $applicant?->alternate_contact_number,
                'branch' => $branch ? ['id' => $branch->id, 'name' => $branch->name] : null,
                'status' => $account->status->value,
                'total_installments' => $account->total_installments_count,
                'paid_installments' => $account->paid_installments_count,
                'pending_installments' => $account->total_installments_count - $account->paid_installments_count,
            ];
        });

        return response()->json([
            'success' => true,
            'message' => 'Loan accounts fetched successfully.',
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
     * Live paid-vs-pending breakdown for one account, for on-screen preview
     * before generating a shareable PDF.
     */
    public function summary(string $accountUuid): JsonResponse
    {
        $account = RepaymentAccount::with([
            'lead:uuid,lead_id',
            'lead.applicantDetails' => fn ($q) => $q->where('applicant_sno', 0),
            // 'installments' => fn ($q) => $q->orderBy('due_date')->orderBy('installment_number'),
            'installments' => fn ($q) => $q
    ->select('repayment_installments.*')
    ->selectSub(function ($subQuery) {
        $subQuery
            ->from('repayment_allocations as ra')
            ->join(
                'repayment_transactions as rt',
                'rt.id',
                '=',
                'ra.repayment_transaction_id'
            )
            ->whereColumn(
                'ra.repayment_installment_id',
                'repayment_installments.id'
            )
            ->where(
                'rt.transaction_type',
                'PAYMENT'
            )
            ->where(
                'rt.status',
                'POSTED'
            )
            ->selectRaw(
                'MAX(rt.received_date)'
            );
    }, 'statement_paid_date')
    ->orderBy('due_date')
    ->orderBy('installment_number'),
        ])->where('uuid', $accountUuid)->first();

        if (!$account) {
            return response()->json(['success' => false, 'message' => 'Repayment account not found.'], 404);
        }

        $installments = $account->installments;
        $paid = $installments->where('payment_status', InstallmentPaymentStatus::PAID);

        return response()->json([
            'success' => true,
            'data' => [
                'loan_number' => $account->loan_number,
                'total_installments' => $installments->count(),
                'paid_installments' => $paid->count(),
                'pending_installments' => $installments->count() - $paid->count(),
                'total_received_amount' => $installments->sum(fn ($i) => (float) $i->principal_paid + (float) $i->interest_paid),
                'total_pending_amount' => $installments->sum('outstanding_amount'),
                'installments' => $installments->map(fn ($i) => [
                    'installment_number' => $i->installment_number,
                    'due_date' => optional($i->due_date)->toDateString(),
                    'installment_amount' => $i->installment_amount,
                   'paid_amount' => round(
    (float) $i->principal_paid
    + (float) $i->interest_paid,
    0,
    PHP_ROUND_HALF_UP
),
                    'paid_date' =>
    $i->payment_status
        === InstallmentPaymentStatus::PAID
            ? $i->statement_paid_date
            : null,
                    'status' => $i->payment_status->value,
                ])->values(),
            ],
        ]);
    }

    /**
     * Full history of statements previously generated for this account.
     */
    public function history(string $accountUuid): JsonResponse
    {
        $account = RepaymentAccount::where('uuid', $accountUuid)->first();

        if (!$account) {
            return response()->json(['success' => false, 'message' => 'Repayment account not found.'], 404);
        }

        $statements = EmiStatement::query()
            ->where('repayment_account_id', $account->id)
            ->with('generatedBy:id,user_name')
            ->orderByDesc('id')
            ->get();

        return response()->json(['success' => true, 'data' => $statements]);
    }

    /**
     * Generate a fresh, shareable EMI statement PDF for this account.
     */
    public function generate(Request $request, string $accountUuid): JsonResponse
    {
        $account = RepaymentAccount::with([
            'lead:uuid,lead_id',
            'lead.applicantDetails' => fn ($q) => $q->where('applicant_sno', 0),
            // 'installments' => fn ($q) => $q->orderBy('due_date')->orderBy('installment_number'),
            'installments' => fn ($q) => $q
    ->select('repayment_installments.*')
    ->selectSub(function ($subQuery) {
        $subQuery
            ->from('repayment_allocations as ra')
            ->join(
                'repayment_transactions as rt',
                'rt.id',
                '=',
                'ra.repayment_transaction_id'
            )
            ->whereColumn(
                'ra.repayment_installment_id',
                'repayment_installments.id'
            )
            ->where(
                'rt.transaction_type',
                'PAYMENT'
            )
            ->where(
                'rt.status',
                'POSTED'
            )
            ->orderByDesc('ra.amount')
            // ->selectRaw(
            //     'MAX(rt.received_date)'
            // );
            ->orderBy('rt.received_date')
            ->orderBy('rt.id')
            ->limit(1)
            ->select('rt.received_date');
    }, 'statement_paid_date')
    ->orderBy('due_date')
    ->orderBy('installment_number'),
        ])->where('uuid', $accountUuid)->first();

        if (!$account) {
            return response()->json(['success' => false, 'message' => 'Repayment account not found.'], 404);
        }

        try {
            $installments = $account->installments;
            $paid = $installments->where('payment_status', InstallmentPaymentStatus::PAID);

            $applicant = $account->lead?->applicantDetails->first();
            $applicantName = $applicant?->full_name ?? '—';
            $leadId = $account->lead?->lead_id ?? '—';

            $statement = $this->createStatementWithRetry(
                accountId: $account->id,
                totalInstallments: $installments->count(),
                paidInstallments: $paid->count(),
                pendingInstallments: $installments->count() - $paid->count(),
                totalReceived: (float) $installments->sum(fn ($i) => (float) $i->principal_paid + (float) $i->interest_paid),
                totalPending: (float) $installments->sum('outstanding_amount'),
                generatedBy: optional($request->user())->id,
            );

            $pdf = Pdf::loadView('emistatement::pdf.emi-statement', [
                'statement' => $statement,
                'account' => $account,
                'installments' => $installments,
                'applicantName' => $applicantName,
                'leadId' => $leadId,
            ])->setPaper('a4', 'portrait');

            $pdfPath = 'emi-statements/' . now()->format('Y/m') . "/{$statement->statement_number}.pdf";
            Storage::disk('local')->put($pdfPath, $pdf->output());

            $statement->update(['pdf_path' => $pdfPath]);

            return response()->json(['success' => true, 'message' => 'EMI statement generated successfully.', 'data' => $statement->fresh()], 201);
        } catch (Throwable $exception) {
            return response()->json(['success' => false, 'message' => 'Failed to generate EMI statement: ' . $exception->getMessage()], 500);
        }
    }

    public function download(string $statementUuid)
    {
        $statement = EmiStatement::where('uuid', $statementUuid)->first();

        if (!$statement || !$statement->pdf_path || !Storage::disk('local')->exists($statement->pdf_path)) {
            return response()->json(['success' => false, 'message' => 'EMI statement PDF not found.'], 404);
        }

        return Storage::disk('local')->download($statement->pdf_path, "{$statement->statement_number}.pdf");
    }

    private function createStatementWithRetry(
        int $accountId,
        int $totalInstallments,
        int $paidInstallments,
        int $pendingInstallments,
        float $totalReceived,
        float $totalPending,
        ?int $generatedBy,
    ): EmiStatement {
        $attempts = 0;

        while (true) {
            try {
                return EmiStatement::create([
                    'repayment_account_id' => $accountId,
                    'statement_number' => $this->generateStatementNumber(),
                    'generated_on' => now()->toDateString(),
                    'total_installments' => $totalInstallments,
                    'paid_installments' => $paidInstallments,
                    'pending_installments' => $pendingInstallments,
                    'total_received_amount' => $totalReceived,
                    'total_pending_amount' => $totalPending,
                    'generated_by' => $generatedBy,
                ]);
            } catch (QueryException $exception) {
                $attempts++;
                if ($attempts >= 3 || !str_contains($exception->getMessage(), 'statement_number')) {
                    throw $exception;
                }
            }
        }
    }

    private function generateStatementNumber(): string
    {
        $year = now()->format('Y');
        $count = EmiStatement::query()->whereYear('created_at', $year)->count() + 1;

        return sprintf('STMT-%s-%04d', $year, $count);
    }
}
