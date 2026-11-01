<?php


namespace Modules\Reports\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use Modules\BLLeadCreation\Models\BLLeadCreation;
use Modules\Reports\Exports\LeadReportExport;
use Modules\Reports\Http\Requests\LeadReportRequest;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class ReportsController extends Controller
{
    /**
     * Show report data on frontend.
     */
    public function index(LeadReportRequest $request): JsonResponse
    {
        $filters = $request->filters();
        $logContext = $this->logContext($request, $filters);

        Log::channel('single')->info(
            'Lead report data request started.',
            $logContext
        );

        try {
            $leads = BLLeadCreation::query()
                ->with([
                    'creator',
                    'branch',
                    'applicantDetails',
                    'applicantRoleDetails',
                    'bankDetails',
                    'referenceDetails',
                    'officeDetails',
                    'workDetails',
                ])
                ->when(
                    $filters['from_date'] ?? null,
                    fn ($query, $date) =>
                        $query->whereDate('created_at', '>=', $date)
                )
                ->when(
                    $filters['to_date'] ?? null,
                    fn ($query, $date) =>
                        $query->whereDate('created_at', '<=', $date)
                )
                ->when(
                    $filters['branch_id'] ?? null,
                    fn ($query, $branchId) =>
                        $query->where('branch_id', $branchId)
                )
                ->when(
                    $filters['created_by'] ?? null,
                    fn ($query, $createdBy) =>
                        $query->where('created_by', $createdBy)
                )
                ->when(
                    $filters['loan_type'] ?? null,
                    fn ($query, $loanType) =>
                        $query->where('loan_type', $loanType)
                )
                ->when(
                    $filters['customer_type'] ?? null,
                    fn ($query, $customerType) =>
                        $query->where('customer_type', $customerType)
                )
                ->when($filters['search'] ?? null, function ($query, $search) {
                    $query->where(function ($innerQuery) use ($search) {
                        $innerQuery
                            ->where('lead_id', 'like', "%{$search}%")
                            ->orWhere('uuid', 'like', "%{$search}%")
                            ->orWhere('mobile_number', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
                })
                ->latest('id')
                ->paginate($request->integer('per_page', 25))
                ->withQueryString();

            Log::channel('single')->info(
                'Lead report data fetched successfully.',
                $logContext + [
                    'current_page' => $leads->currentPage(),
                    'per_page' => $leads->perPage(),
                    'page_result_count' => $leads->count(),
                    'total_result_count' => $leads->total(),
                ]
            );

            return response()->json([
                'success' => true,
                'message' => 'Report data fetched successfully.',
                'data' => $leads,
            ]);
        } catch (Throwable $exception) {
            Log::channel('single')->error(
                'Lead report data request failed.',
                $logContext + ['exception' => $exception]
            );

            throw $exception;
        }
    }

    /**
     * Download report as Excel.
     */
    public function export(
        LeadReportRequest $request
    ): BinaryFileResponse {
        $filters = $request->filters();
        $logContext = $this->logContext($request, $filters);
        $filename = 'lead-report-' . now()->format('Y-m-d-His') . '.xlsx';

        Log::channel('single')->info(
            'Lead report export started.',
            $logContext + ['filename' => $filename]
        );

        try {
            $response = Excel::download(
                new LeadReportExport($filters),
                $filename
            );

            Log::channel('single')->info(
                'Lead report export prepared successfully.',
                $logContext + ['filename' => $filename]
            );

            return $response;
        } catch (Throwable $exception) {
            Log::channel('single')->error(
                'Lead report export failed.',
                $logContext + [
                    'filename' => $filename,
                    'exception' => $exception,
                ]
            );

            throw $exception;
        }
    }

    /**
     * Build a safe context for report logs.
     *
     * The raw search value is intentionally excluded because it can contain
     * an email address, mobile number, lead ID, or UUID.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function logContext(
        LeadReportRequest $request,
        array $filters
    ): array {
        return [
            'user_id' => $request->user()?->getAuthIdentifier(),
            'from_date' => $filters['from_date'] ?? null,
            'to_date' => $filters['to_date'] ?? null,
            'branch_id' => $filters['branch_id'] ?? null,
            'created_by' => $filters['created_by'] ?? null,
            'loan_type' => $filters['loan_type'] ?? null,
            'customer_type' => $filters['customer_type'] ?? null,
            'has_search' => filled($filters['search'] ?? null),
        ];
    }
}