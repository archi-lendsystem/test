<?php

namespace Modules\LUC\Http\Controllers;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\BLApplicantDetails\Models\BLApplicantDetails;
use Modules\BLDisbursment\Models\BLDisbursment;
use Modules\BLLeadCreation\Models\BLLeadCreation;
use Modules\LUC\Http\Requests\StoreLUCReportRequest;
use Modules\LUC\Http\Requests\UpdateLUCReportRequest;
use Modules\LUC\Models\LucReport;
use Modules\LUC\Transformers\LUCReportResource;
use Modules\BLCreditVisitReport\Models\BLCreditVisitReport;
use Throwable;

class LUCController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'utilization_status' => [
                'nullable',
                Rule::in(config('luc.utilization_statuses', [])),
            ],
            'workflow_status' => [
                'nullable',
                Rule::in(['submitted', 'verified', 'rejected']),
            ],
            'late' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = LucReport::query()->latest('id');

        $query->when(
            $validated['utilization_status'] ?? null,
            fn($builder, $status) => $builder->where('utilization_status', $status)
        );

        $query->when(
            $validated['workflow_status'] ?? null,
            fn($builder, $status) => $builder->where('workflow_status', $status)
        );

        if ($request->boolean('late')) {
            $query->whereColumn('date_of_luc', '>', 'luc_due_date');
        }

        $query->when($validated['search'] ?? null, function ($builder, $search): void {
            $builder->where(function ($nested) use ($search): void {
                $nested->where('borrower_name', 'like', "%{$search}%")
                    ->orWhere('loan_number', 'like', "%{$search}%")
                    ->orWhere('lead_uuid', 'like', "%{$search}%");
            });
        });

        $reports = $query->paginate((int) ($validated['per_page'] ?? 15));

        return $this->success(
            'LUC reports fetched successfully.',
            [
                'reports' => LUCReportResource::collection($reports->getCollection())->resolve($request),
                'pagination' => [
                    'current_page' => $reports->currentPage(),
                    'last_page' => $reports->lastPage(),
                    'per_page' => $reports->perPage(),
                    'total' => $reports->total(),
                ],
            ]
        );
    }

    public function options(): JsonResponse
    {
        $statusLabels = [
            'fully_utilized' => 'Fully Utilized',
            'partly_utilized' => 'Partly Utilized',
            'mis_utilized' => 'Mis-utilized',
        ];

        $statuses = collect(config('luc.utilization_statuses', []))
            ->map(fn(string $value): array => [
                'value' => $value,
                'label' => $statusLabels[$value] ?? Str::headline($value),
                'notes' => collect(config("luc.note_options.{$value}", []))
                    ->map(fn(string $label, string $code): array => [
                        'value' => $code,
                        'label' => $label,
                    ])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();

        return $this->success('LUC dropdown options fetched successfully.', [
            'due_after_days' => (int) config('luc.due_after_days', 15),
            'utilization_statuses' => $statuses,
        ]);
    }

    public function eligibility(string $lead_uuid): JsonResponse
    {
        $lead = BLLeadCreation::query()
            ->where('uuid', $lead_uuid)
            ->first();

        if (! $lead) {
            return $this->error('Lead not found. Invalid lead_uuid.', 404);
        }

        $disbursement = $this->latestDisbursement($lead_uuid);
        $existingReport = LucReport::query()
            ->where('lead_uuid', $lead_uuid)
            ->first();

        $disbursement = $this->latestDisbursement($lead_uuid);
        $existingReport = LucReport::query()
            ->where('lead_uuid', $lead_uuid)
            ->first();

        $creditVisitReport = BLCreditVisitReport::query()
            ->where('lead_uuid', $lead_uuid)
            ->latest('id')
            ->first();

        if (! $disbursement) {
            return $this->success('Lead is not eligible for LUC because it has not been disbursed.', [
                'lead_uuid' => $lead_uuid,
                'eligible' => false,
                'reason' => 'DISBURSEMENT_NOT_FOUND',
                'existing_report' => null,
            ]);
        }


        $dueDate = $this->dueDate($disbursement)->startOfDay();
        $currentDate = CarbonImmutable::today(config('app.timezone'));

        $eligibleFromDate = $dueDate->toDateString();
        $eligible = $currentDate->greaterThanOrEqualTo($dueDate);

        return $this->success('LUC eligibility fetched successfully.', [
            'lead_uuid' => $lead_uuid,
            'eligible' => $eligible,
            'reason' => $eligible
                ? null
                : 'WAITING_PERIOD_NOT_COMPLETED',
            'loan_number' => $disbursement->loan_number,
            'loan_amount' => $disbursement->disbursement_amount,
            'loan_purpose' => $creditVisitReport->loan_purpose ?? null,
            'disbursement_date' => CarbonImmutable::parse(
                $disbursement->disbursement_date
            )->toDateString(),

            'eligible_from_date' => $eligibleFromDate,

            'days_remaining' => $eligible
                ? 0
                : $currentDate->diffInDays($dueDate),

            'is_overdue' => $eligible && ! $existingReport,

            'days_overdue' => $eligible && ! $existingReport
                ? $dueDate->diffInDays($currentDate)
                : 0,

            'report_already_exists' => (bool) $existingReport,

            'existing_report' => $existingReport
                ? (new LUCReportResource($existingReport))->resolve(request())
                : null,
        ]);
    }



    public function show(string $lead_uuid): JsonResponse
    {
        $leadExists = BLLeadCreation::query()
            ->where('uuid', $lead_uuid)
            ->exists();

        if (! $leadExists) {
            return $this->error(
                'Lead not found. Invalid lead_uuid.',
                404
            );
        }

        $report = LucReport::query()
            ->where('lead_uuid', $lead_uuid)
            ->first();

  
        if (! $report) {
            return $this->success(
                'No LUC report has been submitted for this lead.',
                null
            );
        }

        return $this->success(
            'LUC report fetched successfully.',
            (new LUCReportResource($report))->resolve(request())
        );
    }

    public function photo(string $lead_uuid)
    {
        $report = LucReport::query()
            ->where('lead_uuid', $lead_uuid)
            ->first();

        if (! $report || ! $report->photo_path) {

            Log::channel('bl_audit')->warning('luc.photo_not_found', [
                'lead_uuid' => $lead_uuid,
                'ip' => request()->ip(),
                'user_id' => auth()->id(),
            ]);
            return $this->error('LUC photo not found for this lead.', 404);
        }

        $disk = Storage::disk(config('luc.disk', 'local'));

        if (! $disk->exists($report->photo_path)) {

        Log::channel('bl_audit')->error('luc.photo_file_missing', [
                'lead_uuid' => $lead_uuid,
                'luc_uuid' => $report->uuid,
                'path' => $report->photo_path,
                'ip' => request()->ip(),
                'user_id' => auth()->id(),
            ]);
            return $this->error('LUC photo file is missing.', 404);
        }

        Log::channel('bl_audit')->info('luc.photo_accessed', [
            'lead_uuid' => $lead_uuid,
            'luc_uuid' => $report->uuid,
            'accessed_by' => auth()->id(),
            'ip' => request()->ip(),
        ]);

        return $disk->response(
            $report->photo_path,
            "luc-{$report->uuid}." . pathinfo($report->photo_path, PATHINFO_EXTENSION),
            ['Cache-Control' => 'private, max-age=300']
        );
    }

    public function store(
        StoreLUCReportRequest $request,
        string $lead_uuid
    ): JsonResponse {
        $lead = BLLeadCreation::query()
            ->where('uuid', $lead_uuid)
            ->first();

        if (! $lead) {
            return $this->error('Lead not found. Invalid lead_uuid.', 404);
        }

        if (LucReport::query()->where('lead_uuid', $lead_uuid)->exists()) {
            return $this->error(
                'An LUC report already exists for this lead. Use the update API.',
                409
            );
        }

        $disbursement = $this->latestDisbursement($lead_uuid);

        if (! $disbursement) {
            return $this->error(
                'LUC cannot be created before loan disbursement.',
                422
            );
        }

        $dueDate = $this->dueDate($disbursement);
        $this->validateLUCDate($request->date('date_of_luc'), $dueDate);

        $disk = config('luc.disk', 'local');
        $photoPath = $request->file('photo')->store(
            trim(config('luc.photo_directory', 'luc-reports'), '/'),
            $disk
        );

        try {
            $report = DB::transaction(function () use (
                $request,
                $lead,
                $lead_uuid,
                $disbursement,
                $dueDate,
                $photoPath
            ): LucReport {
                return LucReport::query()->create([
                    'uuid' => (string) Str::uuid(),
                    'lead_uuid' => $lead_uuid,
                    'disbursement_id' => $disbursement->id,
                    'borrower_name' => $this->borrowerName($lead),
                    'loan_number' => $disbursement->loan_number,
                    'loan_amount' => $disbursement->disbursement_amount,
                    'disbursement_date' => $disbursement->disbursement_date,
                    'luc_due_date' => $dueDate->toDateString(),
                    'utilization_status' => $request->string('utilization_status')->toString(),
                    'note_code' => $request->string('note_code')->toString(),
                    'note_details' => $request->input('note_details'),
                    'photo_path' => $photoPath,
                    'remarks' => $request->input('remarks'),
                    'workflow_status' => 'submitted',
                    'created_by' => auth()->id(),
                    'date_of_luc' => $request->date('date_of_luc')->toDateString(),
                ]);
            });
        } catch (Throwable $e) {
            Storage::disk($disk)->delete($photoPath);

            $this->audit('luc.create_failed', [
                'lead_uuid' => $lead_uuid,
                'error' => $e->getMessage(),
            ], 'error');

            Log::channel('bl_audit')->error('luc.create_failed', [
                'lead_uuid' => $lead_uuid,
                'error' => $e->getMessage(),
                'ip' => request()->ip(),
            ]);

            return $this->error('Unable to create the LUC report.', 500);
        }

        $this->audit('luc.created', [
            'lead_uuid' => $lead_uuid,
            'luc_uuid' => $report->uuid,
            'utilization_status' => $report->utilization_status,
            'created_by' => auth()->id(),
        ]);

        Log::channel('bl_audit')->info('luc.created', [
            'lead_uuid' => $lead_uuid,
            'luc_uuid' => $report->uuid,
            'utilization_status' => $report->utilization_status,
            'created_by' => auth()->id(),
            'ip' => request()->ip(),
        ]);

        return $this->success(
            'LUC report submitted successfully.',
            (new LUCReportResource($report))->resolve($request),
            201
        );
    }

    public function update(
        UpdateLUCReportRequest $request,
        string $lead_uuid
    ): JsonResponse {
        $report = LucReport::query()
            ->where('lead_uuid', $lead_uuid)
            ->first();

        if (! $report) {
            return $this->error('LUC report not found for this lead.', 404);
        }

        if ($report->workflow_status === 'verified') {
            return $this->error(
                'A verified LUC report cannot be changed. Reopen it through an authorized workflow first.',
                422
            );
        }

        $this->validateLUCDate(
            $request->date('date_of_luc'),
            CarbonImmutable::parse($report->luc_due_date)
        );

        $disk = config('luc.disk', 'local');
        $oldPhotoPath = $report->photo_path;
        $newPhotoPath = null;

        if ($request->hasFile('photo')) {
            $newPhotoPath = $request->file('photo')->store(
                trim(config('luc.photo_directory', 'luc-reports'), '/'),
                $disk
            );
        }

        try {
            DB::transaction(function () use ($request, $report, $newPhotoPath): void {
                $report->update([
                    'utilization_status' => $request->string('utilization_status')->toString(),
                    'note_code' => $request->string('note_code')->toString(),
                    'note_details' => $request->input('note_details'),
                    'photo_path' => $newPhotoPath ?: $report->photo_path,
                    'remarks' => $request->input('remarks'),
                    'date_of_luc' => $request->date('date_of_luc')->toDateString(),
                    'workflow_status' => 'submitted',
                    'verified_by' => null,
                    'verified_by_name' => null,
                    'verified_by_role' => null,
                    'verified_at' => null,
                ]);
            });
        } catch (Throwable $e) {
            if ($newPhotoPath) {
                Storage::disk($disk)->delete($newPhotoPath);
            }

            $this->audit('luc.update_failed', [
                'lead_uuid' => $lead_uuid,
                'error' => $e->getMessage(),
            ], 'error');

            return $this->error('Unable to update the LUC report.', 500);
        }

        if ($newPhotoPath && $oldPhotoPath !== $newPhotoPath) {
            Storage::disk($disk)->delete($oldPhotoPath);
        }

        $this->audit('luc.updated', [
            'lead_uuid' => $lead_uuid,
            'luc_uuid' => $report->uuid,
            'updated_by' => auth()->id(),
        ]);

        return $this->success(
            'LUC report updated and resubmitted successfully.',
            (new LUCReportResource($report->fresh()))->resolve($request)
        );
    }

    public function decision(Request $request, string $lead_uuid): JsonResponse
    {
        if (! $request->user()?->can('verify-luc')) {
            return $this->error(
                'You are not authorized to verify or reject an LUC report.',
                403
            );
        }

        $validated = $request->validate([
            'decision' => ['required', Rule::in(['verified', 'rejected'])],
            'remarks' => [
                Rule::requiredIf($request->input('decision') === 'rejected'),
                'nullable',
                'string',
                'max:3000',
            ],
        ]);

        $report = LucReport::query()
            ->where('lead_uuid', $lead_uuid)
            ->first();

        if (! $report) {
            return $this->error('LUC report not found for this lead.', 404);
        }

        if ($report->workflow_status !== 'submitted') {
            return $this->error(
                'Only a submitted LUC report can be verified or rejected.',
                422
            );
        }

        $user = $request->user();
        $roleName = $this->userRoleName($user);

        $report->update([
            'workflow_status' => $validated['decision'],
            'remarks' => $validated['remarks'] ?? $report->remarks,
            'verified_by' => $user->getKey(),
            'verified_by_name' => $user->user_name ?? $user->name ?? 'Unknown',
            'verified_by_role' => $roleName,
            'verified_at' => now(),
        ]);

        $this->audit("luc.{$validated['decision']}", [
            'lead_uuid' => $lead_uuid,
            'luc_uuid' => $report->uuid,
            'decision_by' => $user->getKey(),
            'decision_by_role' => $roleName,
        ]);

        return $this->success(
            "LUC report {$validated['decision']} successfully.",
            (new LUCReportResource($report->fresh()))->resolve($request)
        );
    }

    private function latestDisbursement(string $leadUuid): ?BLDisbursment
    {
        return BLDisbursment::query()
            ->where('lead_uuid', $leadUuid)
            ->latest('id')
            ->first();
    }

    // private function dueDate(BLDisbursment $disbursement): CarbonImmutable
    // {
    //     return CarbonImmutable::parse($disbursement->disbursement_date)
    //         ->addDays((int) config('luc.due_after_days', 15));
    // }

    private function dueDate(BLDisbursment $disbursement): CarbonImmutable
    {
        return CarbonImmutable::parse(
            $disbursement->disbursement_date,
            config('app.timezone')
        )
            ->startOfDay()
            ->addDays((int) config('luc.due_after_days', 15));
    }

    /**
     * @throws ValidationException
     */
    private function validateLUCDate(
        ?\Carbon\CarbonInterface $dateOfLuc,
        CarbonImmutable $dueDate
    ): void {
        if (! $dateOfLuc || $dateOfLuc->startOfDay()->lt($dueDate->startOfDay())) {
            throw ValidationException::withMessages([
                'date_of_luc' => [
                    "LUC can be completed on or after {$dueDate->toDateString()} (15 days after disbursement).",
                ],
            ]);
        }
    }

    private function borrowerName(BLLeadCreation $lead): string
    {
        $applicant = BLApplicantDetails::query()
            ->where('lead_uuid', $lead->uuid)
            ->where(function ($query): void {
                $query->where('is_co_applicant', 0)
                    ->orWhereNull('is_co_applicant');
            })
            ->orderBy('applicant_sno')
            ->orderBy('id')
            ->first();

        if ($applicant) {
            foreach (['full_name', 'applicant_name', 'name'] as $field) {
                if (filled($applicant->{$field} ?? null)) {
                    return trim((string) $applicant->{$field});
                }
            }

            $composedName = trim(implode(' ', array_filter([
                $applicant->first_name ?? null,
                $applicant->middle_name ?? null,
                $applicant->last_name ?? null,
            ])));

            if ($composedName !== '') {
                return $composedName;
            }
        }

        foreach (['applicant_name', 'customer_name', 'full_name'] as $field) {
            if (filled($lead->{$field} ?? null)) {
                return trim((string) $lead->{$field});
            }
        }

        return 'Borrower';
    }

    private function userRoleName(object $user): ?string
    {
        if (method_exists($user, 'roles')) {
            return $user->roles()->value('name');
        }

        return $user->user_designation ?? null;
    }

    private function success(
        string $message,
        mixed $data,
        int $statusCode = 200
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'status_code' => $statusCode,
            'message' => $message,
            'request_id' => $this->requestId(),
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
            'request_id' => $this->requestId(),
            'timestamp' => now()->toIso8601String(),
            'data' => null,
        ], $statusCode);
    }

    private function requestId(): string
    {
        if (app()->bound('request_id')) {
            return (string) app('request_id');
        }

        return (string) (request()->header('X-Request-ID') ?: Str::uuid());
    }

    private function audit(
        string $event,
        array $context,
        string $level = 'info'
    ): void {
        $payload = array_merge($context, [
            'event' => $event,
            'request_id' => $this->requestId(),
            'ip' => request()->ip(),
        ]);

        try {
            Log::channel(config('luc.audit_channel', 'lms_audit'))
                ->{$level}($event, $payload);
        } catch (Throwable) {
            // A missing custom channel must never break the business action.
            Log::log($level, $event, $payload);
        }
    }

    
}
