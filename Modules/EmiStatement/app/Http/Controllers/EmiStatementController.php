<?php



namespace Modules\EmiStatement\Http\Controllers;



use App\Http\Controllers\Controller;
use RuntimeException;

use Barryvdh\DomPDF\Facade\Pdf;

use Illuminate\Database\QueryException;

use Illuminate\Http\JsonResponse;

use Illuminate\Http\Request;

use Illuminate\Support\Facades\DB;

use Illuminate\Support\Facades\Storage;

use Modules\EmiStatement\Models\EmiStatement;

use Modules\Repayment\Enums\InstallmentPaymentStatus;

use Modules\Repayment\Models\RepaymentAccount;

use Modules\Repayment\Models\RepaymentTransaction;

use Modules\Foreclosure\Models\Foreclosure;

use Modules\Settlement\Models\RepaymentSettlement;

use Modules\UserManagement\Support\ReportBranchAccess;

use Throwable;



class EmiStatementController extends Controller
{



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



        // $query = RepaymentAccount::query()

        $query = ReportBranchAccess::scopeByRelation(

            RepaymentAccount::query(),

            $request->user(),

            'lead',

            $branchId

        )

            ->with([

                'lead:uuid,lead_id,branch_id',

                'lead.branch:id,name,branch_code',

                'lead.applicantDetails' => fn($q) => $q

                    ->where('applicant_sno', 0)

                    ->select(['id', 'lead_uuid', 'full_name', 'alternate_contact_number']),

            ])

            ->withCount([

                'installments as paid_installments_count' => fn($q) => $q->where('payment_status', InstallmentPaymentStatus::PAID->value),

                'installments as total_installments_count',

            ]);



        if (!empty($validated['search'])) {

            $search = $validated['search'];

            $query->where(function ($q) use ($search) {

                $q->where('loan_number', 'like', "%{$search}%")

                    ->orWhereHas('lead.applicantDetails', fn($aq) => $aq->where('full_name', 'like', "%{$search}%"));

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

                'pending_installments' => $account->installments()->whereIn('payment_status', [InstallmentPaymentStatus::UNPAID->value, InstallmentPaymentStatus::PARTIAL->value])->count(),

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





    public function summary(string $accountUuid): JsonResponse
    {

        $account = RepaymentAccount::with([

            'lead:uuid,lead_id',

            'lead.applicantDetails' => fn($q) => $q->where('applicant_sno', 0),

            'installments' => fn($q) => $q

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

        $pending = $installments->whereIn('payment_status', [

            InstallmentPaymentStatus::UNPAID,

            InstallmentPaymentStatus::PARTIAL,

        ]);

        $statementRows = $this->buildStatementRows($account, $installments);



        $advancePayments = $this->advancePaymentDetails($account);



        return response()->json([

            'success' => true,

            'data' => [

                'loan_number' => $account->loan_number,

                'account_status' => $account->status->value,

                'total_installments' => $installments->count(),

                'paid_installments' => $paid->count(),

                'pending_installments' => $pending->count(),

                'total_received_amount' => (float) $account->total_received,

                'total_pending_amount' => (float) $account->total_outstanding,

                'installments' => $statementRows,

                'advance_payments' => $advancePayments,

                'advance_total' => round(

                    array_sum(array_column($advancePayments, 'amount')),

                    2

                ),

                'advance_balance' => (float) $account->advance_amount,

            ],

        ]);

    }





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





    public function generate(Request $request, string $accountUuid): JsonResponse
    {

        $account = RepaymentAccount::with([

            'lead:uuid,lead_id',

            'lead.applicantDetails' => fn($q) => $q->where('applicant_sno', 0),



            'installments' => fn($q) => $q

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

            $pending = $installments->whereIn('payment_status', [

                InstallmentPaymentStatus::UNPAID,

                InstallmentPaymentStatus::PARTIAL,

            ]);

            $statementRows = $this->buildStatementRows($account, $installments);



            $advancePayments = $this->advancePaymentDetails($account);



            $applicant = $account->lead?->applicantDetails->first();

            $applicantName = $applicant?->full_name ?? '—';

            $leadId = $account->lead?->lead_id ?? '—';



            $statement = $this->createStatementWithRetry(

                accountId: $account->id,

                totalInstallments: $installments->count(),

                paidInstallments: $paid->count(),

                pendingInstallments: $pending->count(),

                totalReceived: (float) $account->total_received,

                totalPending: (float) $account->total_outstanding,

                generatedBy: optional($request->user())->id,

            );



            $pdf = Pdf::loadView('emistatement::pdf.emi-statement', [

                'statement' => $statement,

                'advancePayments' => $advancePayments,

                'advanceTotal' => round(

                    array_sum(array_column($advancePayments, 'amount')),

                    2

                ),

                'account' => $account,

                'installments' => $installments,

                'statementRows' => $statementRows,

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



    /**

     * Build customer-facing rows. Normal paid/pending EMIs remain individual.

     * Once foreclosure/settlement happens, all frozen future EMIs are collapsed

     * into one closure row so they never appear as future pending/paid EMIs.

     */

    private function buildStatementRows(RepaymentAccount $account, $installments): array
    {
        // Reference-style SOA: disbursed principal + accrued EMI interest - actual receipts.
        // No repayment charges, GST or processing fees are included in this report.
        // No repayment records or allocations are modified.
        $disbursement = $account->disbursement;
        if (!$disbursement || !$disbursement->disbursement_date) {
            throw new \RuntimeException('Disbursement record/date is required to generate the SOA ledger.');
        }

        $asOfDate = now()->toDateString();
        $entries = [[
            'date' => $disbursement->disbursement_date->toDateString(),
        'particulars' => 'Loan Sanctioned Amount',
          'debit' => round((float) $account->principal_amount, 2),
            'credit' => 0.0,
            '_priority' => 0,
            '_sequence' => 0,
        ]];

        // The opening principal is already debited above; debit INTEREST only.
        // Do not recompute the rounded EMI from decimal principal + interest.
        // Include only EMI rows due on or before the statement date.
        // Future EMI rows and their interest are excluded from the SOA ledger.
        // Round cumulative scheduled interest + EMI rounding adjustments,
        // not each EMI independently. This preserves the exact scheduled total
        // while presenting whole-rupee SOA debits.
        $cumulativeDebitCents = 0;
        $previousRoundedDebit = 0;
        foreach ($installments as $installment) {
            $dueDate = $installment->due_date?->toDateString();
            if (!$dueDate || $dueDate > $asOfDate) {
                continue;
            }

            if (in_array($installment->payment_status, [
                InstallmentPaymentStatus::FORECLOSED,
                InstallmentPaymentStatus::SETTLED,
            ], true)) {
                continue;
            }

            $principalCents = (int) round((float) $installment->principal_due * 100);
            $emiCents = (int) round((float) $installment->installment_amount * 100);
            // Equivalent to interest_due + the EMI's rounding adjustment.
            $cumulativeDebitCents += $emiCents - $principalCents;
            $roundedCumulativeDebit = (int) round($cumulativeDebitCents / 100, 0, PHP_ROUND_HALF_UP);
            $roundedEmiDebit = $roundedCumulativeDebit - $previousRoundedDebit;
            $previousRoundedDebit = $roundedCumulativeDebit;

            $entries[] = [
                'date' => $dueDate,
                'particulars' => 'EMI No ' . $installment->installment_number
                    . ' / Interest / Due: ' . $installment->due_date->format('d-m-Y')
                    . ' / ' . $installment->payment_status->value,
                'debit' => (float) $roundedEmiDebit,
                'credit' => 0.0,
                '_priority' => 1,
                '_sequence' => (int) $installment->installment_number,
            ];
        }

        // Each valid payment is a separate credit on its actual received_date.
        // Never use created_at, posted_at or transaction ID as the primary date.
        $transactions = RepaymentTransaction::query()
            ->where('repayment_account_id', $account->id)
            ->where('transaction_type', 'PAYMENT')
            ->where('status', 'POSTED')
            ->whereDate('received_date', '<=', $asOfDate)
            ->whereNotIn('id', function ($query) {
                $query->select('reversal_of_transaction_id')
                    ->from('repayment_transactions')
                    ->where('transaction_type', 'REVERSAL')
                    ->where('status', 'POSTED')
                    ->whereNotNull('reversal_of_transaction_id');
            })
            ->orderBy('received_date')
            ->orderBy('id')
            ->get();

        foreach ($transactions as $transaction) {
            $details = array_filter([
                $transaction->payment_mode?->value
                    ? str_replace('_', ' ', $transaction->payment_mode->value) : null,
                $transaction->transaction_reference ?: $transaction->transaction_number,
            ]);

            $entries[] = [
                'date' => $transaction->received_date?->toDateString(),
                'particulars' => 'Payment Received' . ($details ? ' / ' . implode(' / ', $details) : ''),
                'debit' => 0.0,
                'credit' => round((float) $transaction->amount, 2),
                '_priority' => 2,
                '_sequence' => (int) $transaction->id,
            ];
        }

        usort($entries, static function (array $a, array $b): int {
            return strcmp($a['date'] ?? '', $b['date'] ?? '')
                ?: ($a['_priority'] <=> $b['_priority'])
                ?: ($a['_sequence'] <=> $b['_sequence']);
        });

        $balance = 0.0;
        foreach ($entries as &$entry) {
            $balance = round($balance + $entry['debit'] - $entry['credit'], 2);
            $entry['balance'] = $balance;
            unset($entry['_priority'], $entry['_sequence']);
        }
        unset($entry);

        return $entries;
    }


    private function advancePaymentDetails(RepaymentAccount $account): array
    {

        return RepaymentTransaction::query()

            ->where('repayment_account_id', $account->id)

            ->where('transaction_type', 'PAYMENT')

            ->where('status', 'POSTED')

            ->where('unallocated_amount', '>', 0)

            ->whereNotIn('id', function ($query) {

                $query->select('reversal_of_transaction_id')

                    ->from('repayment_transactions')

                    ->where('transaction_type', 'REVERSAL')

                    ->where('status', 'POSTED')

                    ->whereNotNull('reversal_of_transaction_id');

            })

            ->orderBy('received_date')

            ->orderBy('id')

            ->get()

            ->map(fn(RepaymentTransaction $transaction) => [

                'transaction_number' => $transaction->transaction_number,

                'received_date' => $transaction->received_date?->toDateString(),

                'amount' => (float) $transaction->unallocated_amount,

                'total_payment_amount' => (float) $transaction->amount,

                'payment_mode' => $transaction->payment_mode?->value,

                'transaction_reference' => $transaction->transaction_reference,

                'remarks' => $transaction->remarks,

            ])

            ->all();

    }





    private function closureInfo(RepaymentAccount $account): ?array
    {

        $foreclosure = Foreclosure::query()

            ->where('repayment_account_id', $account->id)

            ->with('transaction')

            ->latest('id')

            ->first();



        if ($foreclosure) {

            return [

                'status' => InstallmentPaymentStatus::FORECLOSED->value,

                'date' => optional($foreclosure->foreclosure_date)->toDateString(),

                'base_amount' => (float) $foreclosure->foreclosure_amount,

                'charges' => (float) $foreclosure->net_foreclosure_charges,

                'paid_amount' => (float) ($foreclosure->transaction?->amount ?? $foreclosure->totalClosurePayable()),

            ];

        }



        $settlement = RepaymentSettlement::query()

            ->where('repayment_account_id', $account->id)

            ->where('status', 'PAID')

            ->latest('id')

            ->first();



        if ($settlement) {

            $transaction = RepaymentTransaction::query()

                ->where('idempotency_key', 'SETTLEMENT_' . $settlement->uuid)

                ->where('status', 'POSTED')

                ->first();



            return [

                'status' => InstallmentPaymentStatus::SETTLED->value,

                'date' => optional($settlement->settlement_date)->toDateString()

                    ?: optional($settlement->paid_at)->toDateString(),

                'base_amount' => (float) $settlement->settlement_amount,

                'charges' => 0.0,

                'paid_amount' => (float) ($transaction?->amount ?? $settlement->settlement_amount),

            ];

        }



        return null;

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