<?php

namespace Modules\LUCReport\Http\Controllers;

use App\Http\Controllers\Controller;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Modules\BLApplicantDetails\Models\BLApplicantDetails;
use Modules\BLDisbursment\Models\BLDisbursment;
use Modules\BLLeadCreation\Models\BLLeadCreation;
use Modules\LUC\Models\LucReport;
use Modules\UserManagement\Models\OrganizationBranch;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class LUCReportController extends Controller
{
      private const SIMPLE_STATUS_LABELS = [
        'success' => 'LUC Completed',
        'pending' => 'LUC Pending',
        'overdue' => 'LUC Overdue',
    ];
 

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'luc_status' => ['nullable', Rule::in(array_keys(self::SIMPLE_STATUS_LABELS))],
             'branch_id' => [
        'nullable',
        'integer',
        'exists:organization_branches,id',
    ],
            'branch_name' => ['nullable', 'string', 'max:150'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $allowedBranchIds = $this->allowedBranchIds($request);

$branchId = !empty($validated['branch_id'])
    ? (int) $validated['branch_id']
    : null;

if ($branchId !== null) {
    abort_unless(
        in_array($branchId, $allowedBranchIds, true),
        403,
        'You cannot access data from another branch.'
    );

    $allowedBranchIds = [$branchId];
}
 
$leadsTable = (new BLLeadCreation())->getTable();

$query = $this->reportQuery()
    ->whereIn(
        "{$leadsTable}.branch_id",
        $allowedBranchIds
    );
 
        if (! empty($validated['luc_status'])) {
            $query->havingRaw('luc_status = ?', [$validated['luc_status']]);
        }
 
        if (! empty($validated['branch_name'])) {
            $query->where('branch_name', 'like', "%{$validated['branch_name']}%");
        }
 
        if (! empty($validated['search'])) {
            $search = $validated['search'];
            $query->where(function (Builder $nested) use ($search): void {
                $nested->where('applicant_name', 'like', "%{$search}%")
                    ->orWhere('loan_number', 'like', "%{$search}%")
                    ->orWhere('lead_id', 'like', "%{$search}%")
                    ->orWhere('lead_uuid', 'like', "%{$search}%");
            });
        }
 
        $perPage = (int) ($validated['per_page'] ?? 15);
        $paginator = $query->orderBy('lead_id')->paginate($perPage);
 
        $rows = collect($paginator->items())->map(fn (object $row): array => $this->mapReportRow($row))->values();
 
        $this->auditLog('luc_report_listed', null, [
            'filters' => $validated,
            'result_count' => $paginator->total(),
        ]);
 
        return $this->success('LUC customer list fetched successfully.', [
            'rows' => $rows,
            'status_options' => collect(self::SIMPLE_STATUS_LABELS)
                ->map(fn (string $label, string $value): array => compact('value', 'label'))
                ->values(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }
 
   
    public function show(string $lead_uuid): JsonResponse
    {
        $leadExists = BLLeadCreation::query()->where('uuid', $lead_uuid)->exists();
 
        if (! $leadExists) {
            return $this->error('Lead not found. Invalid lead_uuid.', 404);
        }
 
        $leadsTable = (new BLLeadCreation())->getTable();
        $row = $this->reportQuery()->where("{$leadsTable}.uuid", $lead_uuid)->first();
 
        if (! $row) {
            return $this->error('This lead has not been disbursed yet, so no LUC record exists.', 404);
        }
 
        $report = LucReport::query()->where('lead_uuid', $lead_uuid)->first();
 
        $this->auditLog('luc_report_viewed', $lead_uuid, [
            'loan_number' => $row->loan_number ?? null,
        ]);
 
        return $this->success('LUC report detail fetched successfully.', array_merge(
            $this->mapReportRow($row),
            [
                'applicant' => $this->primaryApplicant($lead_uuid),
                'report' => $report ? $this->transformReport($report) : null,
            ]
        ));
    }
 


public function download(string $lead_uuid): Response|JsonResponse
{
    try {
        /*
         * Lead UUID valid hai ya nahi.
         */
        $leadExists = BLLeadCreation::query()
            ->where('uuid', $lead_uuid)
            ->exists();

        if (! $leadExists) {
            return $this->error(
                'Lead not found. Invalid lead_uuid.',
                404
            );
        }

     
        $leadsTable = (new BLLeadCreation())->getTable();

        $row = $this->reportQuery()
            ->where("{$leadsTable}.uuid", $lead_uuid)
            ->first();

        if (! $row) {
            return $this->error(
                'This lead has not been disbursed yet, so no LUC record exists.',
                404
            );
        }

        $report = LucReport::query()
            ->where('lead_uuid', $lead_uuid)
            ->first();

        /*
         * Blade file ko required variables bhejo.
         */
        $data = [
            'row' => $this->mapReportRow($row),
            'applicant' => $this->primaryApplicant($lead_uuid),
            'report' => $report
                ? $this->transformReport($report)
                : null,
            'generated_at' => now()->format('d M Y, h:i A'),
        ];

        /*
         * Actual Blade location:
         * resources/views/lucreport/luc-status.blade.php
         */
        $pdf = Pdf::setOptions([
            'isRemoteEnabled' => true,
            'isHtml5ParserEnabled' => true,
            'defaultFont' => 'DejaVu Sans',
        ])->loadView(
            'lucreport::lucreport.luc-status',
            $data
        );

        $leadId = $row->lead_id ?? $lead_uuid;

        $safeLeadId = preg_replace(
            '/[^A-Za-z0-9_-]/',
            '-',
            (string) $leadId
        );

        $this->auditLog(
            'luc_report_downloaded',
            $lead_uuid,
            [
                'loan_number' => $row->loan_number ?? null,
            ]
        );

        return $pdf->download(
            "LUC-Report-{$safeLeadId}.pdf"
        );
    } catch (Throwable $exception) {
        Log::error('LUC PDF download failed.', [
            'lead_uuid' => $lead_uuid,
            'exception' => get_class($exception),
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
        ]);

        return response()->json([
            'status' => false,
            'message' => 'Unable to generate LUC PDF.',
            'error' => config('app.debug')
                ? $exception->getMessage()
                : null,
        ], 500);
    }
}
 

    private function reportQuery(): Builder
    {
        $dueAfterDays = (int) config('luc.due_after_days', 15);
 
        $leadsTable = (new BLLeadCreation())->getTable();               // b_l_lead_creations
        $disbursementsTable = (new BLDisbursment())->getTable();
        $lucTable = (new LucReport())->getTable();                      // luc_reports
        $applicantsTable = (new BLApplicantDetails())->getTable();      // b_l_applicant_details
        $branchesTable = (new OrganizationBranch())->getTable();        // organization_branches
  
        $usersTable = (new BLLeadCreation())->creator()->getRelated()->getTable();
 
        $dueDateExpr = "COALESCE({$lucTable}.luc_due_date, "
            . "DATE_ADD({$disbursementsTable}.disbursement_date, INTERVAL {$dueAfterDays} DAY))";
 
        $statusCase = "
            CASE
                WHEN {$lucTable}.id IS NOT NULL THEN 'success'
                WHEN {$lucTable}.id IS NULL AND {$dueDateExpr} <= CURDATE() THEN 'overdue'
                ELSE 'pending'
            END
        ";
 
        $applicantNameSubQuery = "(
            SELECT {$applicantsTable}.full_name
            FROM {$applicantsTable}
            WHERE {$applicantsTable}.lead_uuid = {$leadsTable}.uuid
              AND ({$applicantsTable}.is_co_applicant = 0 OR {$applicantsTable}.is_co_applicant IS NULL)
            ORDER BY {$applicantsTable}.applicant_sno ASC, {$applicantsTable}.id ASC
            LIMIT 1
        )";
 
        return DB::table($leadsTable)
            ->join($disbursementsTable, "{$disbursementsTable}.lead_uuid", '=', "{$leadsTable}.uuid")
            ->leftJoin($lucTable, "{$lucTable}.lead_uuid", '=', "{$leadsTable}.uuid")
     
            ->leftJoin("{$branchesTable} as lead_branches", 'lead_branches.id', '=', "{$leadsTable}.branch_id")
            ->leftJoin("{$usersTable} as creators", 'creators.id', '=', "{$leadsTable}.created_by")
            ->leftJoin("{$branchesTable} as creator_branches", 'creator_branches.id', '=', 'creators.branch_id')
            ->whereIn("{$disbursementsTable}.id", function ($sub) use ($disbursementsTable): void {
                $sub->selectRaw('MAX(id)')->from($disbursementsTable)->groupBy('lead_uuid');
            })
            ->select([
                "{$leadsTable}.uuid as lead_uuid",
                "{$leadsTable}.lead_id as lead_id",
                "{$disbursementsTable}.loan_number",
                DB::raw("{$applicantNameSubQuery} as applicant_name"),

                DB::raw('COALESCE(lead_branches.name, creator_branches.name) as branch_name'),
                "{$disbursementsTable}.disbursement_date",
                "{$lucTable}.date_of_luc",
                DB::raw("{$dueDateExpr} as luc_due_date"),
                DB::raw("{$statusCase} as luc_status"),
                DB::raw("DATEDIFF({$dueDateExpr}, CURDATE()) as days_remaining"),
                DB::raw("DATEDIFF(CURDATE(), {$dueDateExpr}) as days_overdue"),
            ]);
    }
 
    private function mapReportRow(object $row): array
    {
        return [
            'lead_id' => $row->lead_id,
            'lead_uuid' => $row->lead_uuid,
            'loan_number' => $row->loan_number,
            'applicant_name' => $row->applicant_name,
            'branch_name' => $row->branch_name,
            'luc_status' => $row->luc_status,
            'luc_status_label' => self::SIMPLE_STATUS_LABELS[$row->luc_status] ?? Str::headline((string) $row->luc_status),
            'luc_due_date' => $row->luc_due_date,
            'date_of_luc' => $row->date_of_luc,
            'days_remaining' => $row->luc_status === 'pending' ? max(0, (int) $row->days_remaining) : null,
            'days_overdue' => $row->luc_status === 'overdue' ? max(0, (int) $row->days_overdue) : null,
        ];
    }
 
    private function primaryApplicant(string $lead_uuid): ?array
    {
        $applicant = BLApplicantDetails::query()
            ->where('lead_uuid', $lead_uuid)
            ->where(function ($query): void {
                $query->where('is_co_applicant', 0)->orWhereNull('is_co_applicant');
            })
            ->orderBy('applicant_sno')
            ->orderBy('id')
            ->first();
 
        if (! $applicant) {
            return null;
        }
 
        return [
            'full_name' => $applicant->full_name,
            'aadhaar_card_number' => $applicant->aadhaar_card_number,
            'pan_card_number' => $applicant->pan_card_number,
            'date_of_birth' => optional($applicant->date_of_birth)->toDateString(),
            'gender' => $applicant->gender,
            'marital_status' => $applicant->marital_status,
            'email_address' => $applicant->email_address,
            'alternate_contact_number' => $applicant->alternate_contact_number,
            'current_address' => $applicant->current_address,
            'current_city' => $applicant->current_city,
            'current_state' => $applicant->current_state,
            'current_pincode' => $applicant->current_pincode,
            'profile_photo_url' => $applicant->profile_photo_url,
        ];
    }
 
    private function transformReport(LucReport $report): array
    {
        return [
            'uuid' => $report->uuid,
            'utilization_status' => $report->utilization_status,
            'note_code' => $report->note_code,
            'note_details' => $report->note_details,
            'remarks' => $report->remarks,
            'photo_url' => $report->photo_url,
            'workflow_status' => $report->workflow_status,
            'verified_by_name' => $report->verified_by_name,
            'verified_at' => optional($report->verified_at)->toIso8601String(),
        ];
    }

    private function allowedBranchIds(Request $request): array
{
    return $request->user()
        ->branches()
        ->where('organization_branches.is_active', true)
        ->pluck('organization_branches.id')
        ->map(fn ($id) => (int) $id)
        ->unique()
        ->values()
        ->all();
}
 
 
    private function auditLog(string $action, ?string $lead_uuid, array $meta = []): void
    {
        try {
            Log::channel('lms_audit')->info($action, [
                'module' => 'luc_report',
                'action' => $action,              
                'subject_type' => 'lead',
                'subject_uuid' => $lead_uuid,
                'user_id' => auth()->id(),
                'ip_address' => request()->ip(),
                'user_agent' => (string) request()->userAgent(),
                'meta' => $meta,
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
 
    private function success(string $message, mixed $data, int $statusCode = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'status_code' => $statusCode,
            'message' => $message,
            'timestamp' => now()->toIso8601String(),
            'data' => $data,
        ], $statusCode);
    }
 
    private function error(string $message, int $statusCode): JsonResponse
    {
        return response()->json([
            'success' => false,
            'status_code' => $statusCode,
            'message' => $message,
            'timestamp' => now()->toIso8601String(),
            'data' => null,
        ], $statusCode);
    }
}