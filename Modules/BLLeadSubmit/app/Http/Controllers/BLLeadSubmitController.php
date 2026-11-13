<?php

namespace Modules\BLLeadSubmit\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Modules\BLJourney\Models\BLJourney;
use Modules\BLLeadCreation\Models\BLLeadCreation;
use Modules\BLLeadSubmit\Models\BLLeadSubmit;
use Modules\BLLeadSubmit\Transformers\BLLeadSubmitResource;
use Throwable;

class BLLeadSubmitController extends Controller
{
     public function store(Request $request): JsonResponse
  {
    try {
        $validated = $request->validate([
            'lead_uuid'        => 'required|uuid|exists:b_l_lead_creations,uuid',
            'customer_consent' => 'required|boolean',
            'force_submit'     => 'nullable|boolean', 
        ]);

        if (!$validated['customer_consent']) {
            return $this->apiError(
                'Lead submission failed. Customer consent is required.',
                ['customer_consent' => false],
                422,
                'CUSTOMER_CONSENT_REQUIRED'
            );
        }

        $lead = BLLeadCreation::where('uuid', $validated['lead_uuid'])->first();

        if (!$lead) {
            return $this->apiError('Lead not found.', null, 404, 'LEAD_NOT_FOUND');
        }

        $alreadySubmitted = BLLeadSubmit::where('lead_uuid', $validated['lead_uuid'])->exists();

        if ($alreadySubmitted) {
            return $this->apiError(
                'BL Lead already submitted.',
                ['lead_uuid' => $validated['lead_uuid']],
                409,
                'LEAD_ALREADY_SUBMITTED'
            );
        }

        $forceSubmit       = (bool) ($validated['force_submit'] ?? false);
        $journeyValidation = $this->validatePreviousJourneyStages($validated['lead_uuid'], $forceSubmit);

     
        if ($journeyValidation['hard_failed']) {
            $pendingText = collect($journeyValidation['hard_pending'])
                ->pluck('label')
                ->implode(', ');

            return $this->apiError(
                'Lead submission failed. Please complete required section(s): ' . $pendingText . '.',
                [
                    'lead_uuid'              => $validated['lead_uuid'],
                    'can_submit'             => false,
                    'requires_confirmation'  => false,
                    'pending_count'          => count($journeyValidation['hard_pending']),
                    'hard_pending'           => $journeyValidation['hard_pending'],
                    'soft_pending'           => $journeyValidation['soft_pending'],
                    'completed_stages'       => $journeyValidation['completed_stages'],
                ],
                422,
                'REQUIRED_STAGES_INCOMPLETE'
            );
        }

    
        if ($journeyValidation['soft_warning']) {
            $pendingLabels = collect($journeyValidation['soft_pending'])
                ->pluck('label')
                ->implode(', ');

            return $this->apiError(
                'Some sections are incomplete: ' . $pendingLabels . '. Are you sure you want to submit?',
                [
                    'lead_uuid'             => $validated['lead_uuid'],
                    'can_submit'            => true,        
                    'requires_confirmation' => true,        
                    'soft_pending'          => $journeyValidation['soft_pending'],
                    'soft_completed'        => $journeyValidation['soft_completed'],
                    'hard_completed'        => $journeyValidation['hard_completed'],
                    'hint'                  => 'Re-send with force_submit: true to proceed.',
                ],
                422,
                'SOFT_STAGES_INCOMPLETE'
            );
        }

        return DB::transaction(function () use ($validated, $lead, $request) {

            $submit = BLLeadSubmit::create([
                'lead_uuid'        => $validated['lead_uuid'],
                'customer_consent' => $validated['customer_consent'],
            ]);

            $submit->load('applicantDetails');

            $journey = $this->createLeadSubmitJourneyStep($lead, $request);

            return $this->apiSuccess(
                [
                    'lead_submit' => new BLLeadSubmitResource($submit),
                    'journey'     => $journey,
                ],
                'BL Lead submitted successfully.',
                201,
                'BL_LEAD_SUBMITTED_SUCCESSFULLY'
            );
        });

    } catch (ValidationException $e) {
        return $this->apiError('Validation failed.', $e->errors(), 422, 'VALIDATION_ERROR');

    } catch (Throwable $e) {
        return $this->apiError(
            'Something went wrong while submitting BL lead.',
            app()->environment('local') ? $e->getMessage() : null,
            500,
            'SERVER_ERROR'
        );
    }
  }

    
    public function show(string $lead_uuid): JsonResponse
    {
        try {
            $blLeadSubmit = BLLeadSubmit::where('lead_uuid', $lead_uuid)
                ->first();

            if (!$blLeadSubmit) {
                return $this->apiError(
                    'BL Lead Submit not found.',
                    [
                        'lead_uuid' => $lead_uuid,
                    ],
                    404,
                    'LEAD_SUBMIT_NOT_FOUND'
                );
            }

            $blLeadSubmit->load('applicantDetails');

            return $this->apiSuccess(
                new BLLeadSubmitResource($blLeadSubmit),
                'BL Lead Submit retrieved successfully.',
                200,
                'LEAD_SUBMIT_FETCHED'
            );

        } catch (Throwable $e) {
            return $this->apiError(
                'Something went wrong while fetching BL lead submit.',
                app()->environment('local') ? $e->getMessage() : null,
                500,
                'SERVER_ERROR'
            );
        }
    }

    private function validatePreviousJourneyStages(string $leadUuid, bool $forceSubmit = false): array
 {

    $hardRequiredStages = [
        [
            'label'           => 'Applicant Details',
            'message'         => 'Applicant Details form is incomplete.',
            'required_action' => 'Please complete Applicant Details first.',
            'stage_names'     => ['Applicant Details'],
            'action_types'    => ['APPLICANT_DETAILS_COMPLETED'],
        ],
    ];

 
   $softOptionalStages = [
    [
        'label'           => 'Income Details',
        'message'         => 'Income Details form is incomplete or not saved.',
        'required_action' => 'Please complete the Income Details form.',
        'stage_names'     => ['Income Details'],
        'action_types'    => ['INCOME_DETAILS_COMPLETED'],
    ],

    [
        'label'           => 'Reference Details',
        'message'         => 'Reference Details form is incomplete or not saved.',
        'required_action' => 'Please complete the Reference Details form.',
        'stage_names'     => ['Reference Details'],
        'action_types'    => ['REFERENCE_DETAILS_COMPLETED'],
    ],
    [
        'label'           => 'Document Uploads',
        'message'         => 'Required documents are not uploaded or incomplete.',
        'required_action' => 'Please upload and complete all required documents.',
        'stage_names'     => ['Document Uploads', 'Document Collection'],
        'action_types'    => ['DOCUMENT_UPLOADS_COMPLETED'],
    ],
  ];

    $hardPending      = [];
    $hardCompleted    = [];
    $softPending      = [];
    $softCompleted    = [];


    foreach ($hardRequiredStages as $stage) {
        $exists = $this->journeyStageExists($leadUuid, $stage);

        if ($exists) {
            $hardCompleted[] = ['label' => $stage['label'], 'message' => $stage['label'] . ' completed.'];
        } else {
            $hardPending[] = [
                'label'                  => $stage['label'],
                'message'                => $stage['message'],
                'required_action'        => $stage['required_action'],
                'expected_stage_names'   => $stage['stage_names'],
                'expected_action_types'  => $stage['action_types'],
                'type'                   => 'hard',
            ];
        }
    }


    foreach ($softOptionalStages as $stage) {
        $exists = $this->journeyStageExists($leadUuid, $stage);

        if ($exists) {
            $softCompleted[] = ['label' => $stage['label'], 'message' => $stage['label'] . ' completed.'];
        } else {
            $softPending[] = [
                'label'                  => $stage['label'],
                'message'                => $stage['message'],
                'required_action'        => $stage['required_action'],
                'expected_stage_names'   => $stage['stage_names'],
                'expected_action_types'  => $stage['action_types'],
                'type'                   => 'soft',
            ];
        }
    }

    $hardFailed = !empty($hardPending);


    $softWarning = !$forceSubmit && !empty($softPending);

    return [
        'passed'              => !$hardFailed && !$softWarning,
        'hard_failed'         => $hardFailed,
        'soft_warning'        => $softWarning,

     
        'hard_pending'        => $hardPending,
        'soft_pending'        => $softPending,
        'hard_completed'      => $hardCompleted,
        'soft_completed'      => $softCompleted,


        'missing_stages'      => collect(array_merge($hardPending, $softPending))->pluck('label')->values()->toArray(),
        'completed_stages'    => collect(array_merge($hardCompleted, $softCompleted))->pluck('label')->values()->toArray(),
        'pending_requirements'   => array_merge($hardPending, $softPending),
        'completed_requirements' => array_merge($hardCompleted, $softCompleted),
    ];
  }


  private function journeyStageExists(string $leadUuid, array $stage): bool
  {
    $query = BLJourney::where('lead_uuid', $leadUuid);

    $query->where(function ($q) use ($stage) {
        if (Schema::hasColumn('b_l_journeys', 'stage')) {
            $q->orWhereIn('stage', $stage['stage_names']);
        }
        if (Schema::hasColumn('b_l_journeys', 'action_type')) {
            $q->orWhereIn('action_type', $stage['action_types']);
        }
        if (Schema::hasColumn('b_l_journeys', 'action_performed')) {
            $q->orWhereIn('action_performed', $stage['action_types']);
        }
    });

    if (Schema::hasColumn('b_l_journeys', 'status')) {
        $query->where(function ($q) {
            $q->whereIn('status', ['Completed', 'Submitted'])
              ->orWhereNull('status');
        });
    }

    return $query->exists();
  }

    private function createLeadSubmitJourneyStep(
        BLLeadCreation $lead,
        Request $request
    ): BLJourney {
        
        BLJourney::where('lead_uuid', $lead->uuid)
            ->where('is_latest', true)
            ->update(['is_latest' => false]);

  
        $workflowStage = null;
        $workflowStatus = null;

        if (Schema::hasTable('b_l_workflow_stages')) {
            $workflowStage = DB::table('b_l_workflow_stages')
                ->whereIn('stage_code', ['LEAD_SUBMITTED', 'SUBMITTED', 'DOCUMENT_COLLECTION'])
                ->orderByRaw("
                    FIELD(stage_code, 'LEAD_SUBMITTED', 'SUBMITTED', 'DOCUMENT_COLLECTION')
                ")
                ->first();
        }

        if ($workflowStage && Schema::hasTable('b_l_workflow_statuses')) {
            $workflowStatus = DB::table('b_l_workflow_statuses')
                ->where('stage_id', $workflowStage->id)
                ->whereIn('status_code', ['SUBMITTED', 'COMPLETED'])
                ->orderByRaw("FIELD(status_code, 'SUBMITTED', 'COMPLETED')")
                ->first();
        }

        $authUser = $request->user();

        $payload = [
            'lead_uuid' => $lead->uuid,
            'lead_id' => $lead->lead_id,

        
            'workflow_stage_id' => $workflowStage->id ?? null,
            'workflow_status_id' => $workflowStatus->id ?? null,
            'previous_stage_id' => null,
            'action_performed' => 'LEAD_SUBMITTED',
            'performed_by_role' => $authUser->user_role
                ?? $authUser->user_designation
                ?? $authUser->user_type
                ?? null,
            'transition_type' => 'SUBMITTED',

      
            'stage' => $workflowStage->stage_name ?? 'Lead Submitted',
            'status' => $workflowStatus->status_name ?? 'Submitted',
            'team' => $workflowStage->team_name ?? 'Sales',
            'action_type' => 'LEAD_SUBMITTED',
            'remarks' => 'BL lead submitted successfully after customer consent.',
            'user_id' => $authUser->id ?? null,
            'user_name' => $authUser->user_name
                ?? $authUser->name
                ?? null,
            'is_latest' => true,
            'ip_address' => $request->ip(),
            'device_info' => $request->header('User-Agent'),

        
            'created_by' => $authUser->id ?? null,
            'is_completed' => true,
        ];

        return BLJourney::create(
            $this->onlyExistingColumns('b_l_journeys', $payload)
        );
    }

    private function onlyExistingColumns(string $table, array $payload): array
    {
        return collect($payload)
            ->filter(function ($value, $key) use ($table) {
                return Schema::hasColumn($table, $key);
            })
            ->toArray();
    }

    private function apiSuccess(
        mixed $data = null,
        string $message = 'Success',
        int $statusCode = 200,
        string $code = 'SUCCESS'
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'status_code' => $statusCode,
            'code' => $code,
            'message' => $message,
            'data' => $data,
        ], $statusCode);
    }

    private function apiError(
        string $message = 'Something went wrong.',
        mixed $data = null,
        int $statusCode = 400,
        string $code = 'ERROR'
    ): JsonResponse {
        return response()->json([
            'success' => false,
            'status_code' => $statusCode,
            'code' => $code,
            'message' => $message,
            'data' => $data,
        ], $statusCode);
    }
}
