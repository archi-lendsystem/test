<?php

namespace Modules\NOC\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\NOC\Models\NocCertificate;
use Modules\Repayment\Enums\RepaymentAccountStatus;
use Modules\Repayment\Models\RepaymentAccount;
use Modules\UserManagement\Support\ReportBranchAccess;
use Throwable;

class NOCController extends Controller
{
    use ApiResponse;

    /**
     * Closed loans, with whether an NOC has already been issued.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => 'nullable|integer|exists:organization_branches,id',   //branch wize
            'search' => 'nullable|string|max:255',
            'per_page' => 'nullable|integer|min:1|max:200',
        ]);

        $branchId = !empty($validated['branch_id'])   //branch wize
            ? (int) $validated['branch_id']
            : null;

        // $query = RepaymentAccount::query()
        //     ->where('status', RepaymentAccountStatus::CLOSED->value)
        //     ->with([
        //         'lead:uuid,lead_id',
        //         'lead.applicantDetails' => fn ($q) => $q
        //             ->where('applicant_sno', 0)
        //             ->select(['id', 'lead_uuid', 'full_name', 'alternate_contact_number']),
        //         'nocCertificate',
        //     ]);

        $query = ReportBranchAccess::scopeByRelation(
            RepaymentAccount::query(),
            $request->user(),
            'lead',
            $branchId
        )
            ->where(
                'status',
                RepaymentAccountStatus::CLOSED->value
            )
            ->with([
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

                'nocCertificate',
            ]);

        if (!empty($validated['search'])) {
            $search = $validated['search'];
            $query->where(function ($q) use ($search) {
                $q->where('loan_number', 'like', "%{$search}%")
                    ->orWhereHas('lead.applicantDetails', fn($aq) => $aq->where('full_name', 'like', "%{$search}%"));
            });
        }

        $accounts = $query->orderByDesc('closed_at')->paginate((int) ($validated['per_page'] ?? 25));

        $accounts->getCollection()->transform(function (RepaymentAccount $account) {
            $applicant = $account->lead?->applicantDetails->first();

            return [
                'repayment_account_uuid' => $account->uuid,
                'loan_number' => $account->loan_number,
                'lead_id' => $account->lead?->lead_id,
                'customer_name' => $applicant?->full_name,
                'mobile_number' => $applicant?->alternate_contact_number,
                'closed_at' => optional($account->closed_at)->toDateString(),
                'noc' => $account->nocCertificate ? [
                    'uuid' => $account->nocCertificate->uuid,
                    'noc_number' => $account->nocCertificate->noc_number,
                    'issue_date' => $account->nocCertificate->issue_date?->toDateString(),
                ] : null,
            ];
        });

        return $this->success([
            'data' => $accounts->items(),
            'meta' => [
                'current_page' => $accounts->currentPage(),
                'per_page' => $accounts->perPage(),
                'total' => $accounts->total(),
                'last_page' => $accounts->lastPage(),
            ],
        ], 'Closed loans fetched successfully.');
    }

    /**
     * Issue an NOC record for a closed loan (or return the existing one).
     *
     * NOTE: this no longer builds or stores a PDF. The PDF is built fresh,
     * in-memory, every time download() is called — that's what lets the
     * "print date" the employee types in change what actually prints,
     * instead of always returning a file generated once at issue time.
     */
    public function store(Request $request, string $accountUuid): JsonResponse
    {
        $account = RepaymentAccount::with([
            'lead:uuid,lead_id',
            'lead.applicantDetails' => fn($q) => $q->where('applicant_sno', 0),
        ])->where('uuid', $accountUuid)->first();

        if (!$account) {
            return $this->error('Repayment account not found.', null, 404);
        }

        if ($account->status !== RepaymentAccountStatus::CLOSED) {
            return $this->error('An NOC can only be issued for a fully closed loan.', null, 422);
        }

        $validated = $request->validate([
            'closure_type' => 'nullable|string|in:NORMAL_CLOSURE,FORECLOSURE,SETTLEMENT',
            'remarks' => 'nullable|string|max:1000',
        ]);

        try {
            $result = DB::transaction(function () use ($account, $validated, $request) {
                // Lock the account row so two concurrent requests for the
                // same loan can't both pass the "no existing NOC" check.
                $locked = RepaymentAccount::where('id', $account->id)->lockForUpdate()->first();

                $existing = NocCertificate::where('repayment_account_id', $locked->id)->first();
                if ($existing) {
                    return ['noc' => $existing, 'already_existed' => true];
                }

                $closureType = $validated['closure_type'] ?? 'NORMAL_CLOSURE';

                $noc = $this->createNocWithRetry($locked->id, $closureType, $validated['remarks'] ?? null, optional($request->user())->id);

                return ['noc' => $noc->fresh(), 'already_existed' => false];
            });

            $message = $result['already_existed'] ? 'NOC already issued for this loan.' : 'NOC generated successfully.';
            return $this->success($result['noc'], $message, $result['already_existed'] ? 200 : 201);
        } catch (Throwable $exception) {
            return $this->error('Failed to generate NOC: ' . $exception->getMessage(), null, 500);
        }
    }

    /**
     * generateNocNumber() reads a count then writes — two different accounts
     * closing at the same instant can compute the same seq. Retry on the
     * noc_number unique-constraint violation instead of trusting the count.
     */
    private function createNocWithRetry(int $accountId, string $closureType, ?string $remarks, ?int $generatedBy): NocCertificate
    {
        $attempts = 0;

        while (true) {
            try {
                return NocCertificate::create([
                    'repayment_account_id' => $accountId,
                    'noc_number' => $this->generateNocNumber(),
                    'issue_date' => now()->toDateString(),
                    'closure_type' => $closureType,
                    'remarks' => $remarks,
                    'generated_by' => $generatedBy,
                ]);
            } catch (QueryException $exception) {
                $attempts++;
                if ($attempts >= 3 || !str_contains($exception->getMessage(), 'noc_number')) {
                    throw $exception;
                }
            }
        }
    }

    /**
     * Build the NOC PDF fresh on every call and stream it straight to the
     * browser — nothing is read from or written to disk. This is what
     * makes the "print date" the employee fills in on the list page take
     * effect: each download re-renders the certificate with whatever
     * print_date was submitted (falling back to today's date if it wasn't).
     */
    public function download(Request $request, string $nocUuid)
    {
        $validated = $request->validate([
            'print_date' => 'nullable|date',
        ]);

        $noc = NocCertificate::with([
            'account',
            'account.lead:uuid,lead_id',
            'account.lead.applicantDetails' => fn($q) => $q
                ->where('applicant_sno', 0)
                ->select(['id', 'lead_uuid', 'full_name']),
        ])->where('uuid', $nocUuid)->first();

        if (!$noc) {
            return $this->error('NOC certificate not found.', null, 404);
        }

        $account = $noc->account;
        $applicant = $account?->lead?->applicantDetails->first();
        $applicantName = $applicant?->full_name ?? '—';
        $leadId = $account?->lead?->lead_id ?? '—';

        $closureTypeLabel = match ($noc->closure_type) {
            'FORECLOSURE' => 'Foreclosure',
            'SETTLEMENT' => 'Settlement',
            default => 'Normal Closure',
        };

        $pdf = Pdf::loadView('noc::pdf.noc-certificate', [
            'noc' => $noc,
            'account' => $account,
            'applicantName' => $applicantName,
            'leadId' => $leadId,
            'closureTypeLabel' => $closureTypeLabel,
            'printDate' => $validated['print_date'] ?? null,
        ])->setPaper('a4', 'portrait');

        return $pdf->download("{$noc->noc_number}.pdf");
    }

    private function generateNocNumber(): string
    {
        $year = now()->format('Y');
        $count = NocCertificate::whereYear('created_at', $year)->count() + 1;

        return sprintf('NOC-%s-%04d', $year, $count);
    }
}