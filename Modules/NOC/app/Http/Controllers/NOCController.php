<?php

namespace Modules\NOC\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\NOC\Models\NocCertificate;
use Modules\Repayment\Enums\RepaymentAccountStatus;
use Modules\Repayment\Models\RepaymentAccount;
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
            'search' => 'nullable|string|max:255',
            'per_page' => 'nullable|integer|min:1|max:200',
        ]);

        $query = RepaymentAccount::query()
            ->where('status', RepaymentAccountStatus::CLOSED->value)
            ->with([
                'lead:uuid,lead_id',
                'lead.applicantDetails' => fn ($q) => $q
                    ->where('applicant_sno', 0)
                    ->select(['id', 'lead_uuid', 'full_name', 'alternate_contact_number']),
                'nocCertificate',
            ]);

        if (!empty($validated['search'])) {
            $search = $validated['search'];
            $query->where(function ($q) use ($search) {
                $q->where('loan_number', 'like', "%{$search}%")
                    ->orWhereHas('lead.applicantDetails', fn ($aq) => $aq->where('full_name', 'like', "%{$search}%"));
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
     * Generate (or re-fetch, if one already exists) the NOC for a closed
     * loan, including its PDF.
     */
    public function store(Request $request, string $accountUuid): JsonResponse
    {
        $account = RepaymentAccount::with([
            'lead:uuid,lead_id',
            'lead.applicantDetails' => fn ($q) => $q->where('applicant_sno', 0),
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

                $applicant = $account->lead?->applicantDetails->first();
                $applicantName = $applicant?->full_name ?? '—';
                $leadId = $account->lead?->lead_id ?? '—';
                $closureType = $validated['closure_type'] ?? 'NORMAL_CLOSURE';

                $noc = $this->createNocWithRetry($locked->id, $closureType, $validated['remarks'] ?? null, optional($request->user())->id);

                $closureTypeLabel = match ($closureType) {
                    'FORECLOSURE' => 'Foreclosure',
                    'SETTLEMENT' => 'Settlement',
                    default => 'Normal Closure',
                };

                $pdf = Pdf::loadView('noc::pdf.noc-certificate', [
                    'noc' => $noc,
                    'account' => $locked,
                    'applicantName' => $applicantName,
                    'leadId' => $leadId,
                    'closureTypeLabel' => $closureTypeLabel,
                ])->setPaper('a4', 'portrait');

                $pdfPath = 'noc/' . now()->format('Y/m') . "/{$noc->noc_number}.pdf";
                Storage::disk('local')->put($pdfPath, $pdf->output());

                $noc->update(['pdf_path' => $pdfPath]);

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
     * Download a previously generated NOC PDF.
     */
    public function download(string $nocUuid)
    {
        $noc = NocCertificate::where('uuid', $nocUuid)->first();

        if (!$noc || !$noc->pdf_path || !Storage::disk('local')->exists($noc->pdf_path)) {
            return $this->error('NOC certificate not found.', null, 404);
        }

        return Storage::disk('local')->download($noc->pdf_path, "{$noc->noc_number}.pdf");
    }

    private function generateNocNumber(): string
    {
        $year = now()->format('Y');
        $count = NocCertificate::whereYear('created_at', $year)->count() + 1;

        return sprintf('NOC-%s-%04d', $year, $count);
    }
}
