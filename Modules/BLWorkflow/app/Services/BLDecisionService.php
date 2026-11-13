<?php

// namespace Modules\BLWorkflow\Services;

// use Illuminate\Support\Facades\Auth;
// use Illuminate\Support\Facades\DB;
// use Illuminate\Validation\ValidationException;
// use Modules\BLDisbursment\Models\BLDisbursment;
// use Modules\BLLeadCreation\Models\BLLeadCreation;
// use Modules\BLWorkflow\Models\BLLeadDecision;
// use Modules\BLWorkflow\Models\BLWorkflowStage;

// class BLDecisionService
// {
//     public function approve(
//         string $leadUuid,
//         string $stageCode,
//         ?string $remarks = null
//     ): BLLeadDecision {
//         return DB::transaction(function () use (
//             $leadUuid,
//             $stageCode,
//             $remarks
//         ) {
//             $this->validateLeadAndStage(
//                 $leadUuid,
//                 $stageCode
//             );

//             $this->ensureLeadNotDisbursed($leadUuid);
//             $this->ensurePreviousStageApproved(
//                 $leadUuid,
//                 $stageCode
//             );

//             $currentDecision = $this->getCurrentDecision(
//                 $leadUuid,
//                 $stageCode
//             );

//             if ($currentDecision?->decision === 'REJECTED') {
//                 throw ValidationException::withMessages([
//                     'lead_uuid' =>
//                         'Rejected lead must be reopened first.',
//                 ]);
//             }

//             if ($currentDecision?->decision === 'APPROVED') {
//                 throw ValidationException::withMessages([
//                     'lead_uuid' =>
//                         'This stage is already approved.',
//                 ]);
//             }

//             $this->closeOldDecisions(
//                 $leadUuid,
//                 $stageCode
//             );

//             return BLLeadDecision::create([
//                 'lead_uuid'   => $leadUuid,
//                 'stage_code'  => strtoupper($stageCode),
//                 'decision'    => 'APPROVED',
//                 'remarks'     => $remarks,
//                 'decision_by' => Auth::id(),
//                 'decision_at' => now(),
//                 'is_latest'   => true,
//             ]);
//         });
//     }

//     public function reject(
//         string $leadUuid,
//         string $stageCode,
//         string $remarks
//     ): BLLeadDecision {
//         return DB::transaction(function () use (
//             $leadUuid,
//             $stageCode,
//             $remarks
//         ) {
//             $this->validateLeadAndStage(
//                 $leadUuid,
//                 $stageCode
//             );

//             $this->ensureLeadNotDisbursed($leadUuid);
//             $this->ensurePreviousStageApproved(
//                 $leadUuid,
//                 $stageCode
//             );

//             $currentDecision = $this->getCurrentDecision(
//                 $leadUuid,
//                 $stageCode
//             );

//             if ($currentDecision?->decision === 'REJECTED') {
//                 throw ValidationException::withMessages([
//                     'lead_uuid' =>
//                         'Lead is already rejected at this stage.',
//                 ]);
//             }

//             $this->closeOldDecisions(
//                 $leadUuid,
//                 $stageCode
//             );

//             /*
//              * Agar kisi stage ko dubara reject kiya gaya,
//              * toh uske baad ke approvals inactive kar do.
//              */
//             $this->closeLaterStageDecisions(
//                 $leadUuid,
//                 $stageCode
//             );

//             return BLLeadDecision::create([
//                 'lead_uuid'   => $leadUuid,
//                 'stage_code'  => strtoupper($stageCode),
//                 'decision'    => 'REJECTED',
//                 'remarks'     => $remarks,
//                 'decision_by' => Auth::id(),
//                 'decision_at' => now(),
//                 'is_latest'   => true,
//             ]);
//         });
//     }

//     public function reopen(
//         string $leadUuid,
//         string $stageCode,
//         string $remarks
//     ): BLLeadDecision {
//         return DB::transaction(function () use (
//             $leadUuid,
//             $stageCode,
//             $remarks
//         ) {
//             $this->validateLeadAndStage(
//                 $leadUuid,
//                 $stageCode
//             );

//             $this->ensureLeadNotDisbursed($leadUuid);

//             $currentDecision = $this->getCurrentDecision(
//                 $leadUuid,
//                 $stageCode
//             );

//             if (
//                 !$currentDecision ||
//                 $currentDecision->decision !== 'REJECTED'
//             ) {
//                 throw ValidationException::withMessages([
//                     'lead_uuid' =>
//                         'Only rejected stage can be reopened.',
//                 ]);
//             }

//             $stage = BLWorkflowStage::where(
//                 'stage_code',
//                 strtoupper($stageCode)
//             )->firstOrFail();

//             if (!$stage->is_reversible) {
//                 throw ValidationException::withMessages([
//                     'stage_code' =>
//                         "{$stage->stage_name} cannot be reopened.",
//                 ]);
//             }

//             $currentDecision->update([
//                 'is_latest'      => false,
//                 'reopened_by'    => Auth::id(),
//                 'reopened_at'    => now(),
//                 'reopen_remarks' => $remarks,
//             ]);

//             /*
//              * REOPENED current state ka matlab:
//              * form dubara editable hai aur fresh decision pending hai.
//              */
//             return BLLeadDecision::create([
//                 'lead_uuid'   => $leadUuid,
//                 'stage_code'  => strtoupper($stageCode),
//                 'decision'    => 'REOPENED',
//                 'remarks'     => $remarks,
//                 'decision_by' => Auth::id(),
//                 'decision_at' => now(),
//                 'is_latest'   => true,
//                 'reopened_by' => Auth::id(),
//                 'reopened_at' => now(),
//                 'reopen_remarks' => $remarks,
//             ]);
//         });
//     }

//     public function getLeadState(string $leadUuid): array
//     {
//         BLLeadCreation::where('uuid', $leadUuid)
//             ->firstOrFail();

//         $decisions = BLLeadDecision::query()
//             ->where('lead_uuid', $leadUuid)
//             ->where('is_latest', true)
//             ->orderBy('id')
//             ->get();

//         $rejected = $decisions->firstWhere(
//             'decision',
//             'REJECTED'
//         );

//         return [
//             'lead_uuid' => $leadUuid,
//             'is_disbursed' => BLDisbursment::where(
//                 'lead_uuid',
//                 $leadUuid
//             )->exists(),
//             'is_rejected' => (bool) $rejected,
//             'rejected_at_stage' => $rejected?->stage_code,
//             'rejection_reason' => $rejected?->remarks,
//             'decisions' => $decisions,
//         ];
//     }

//     private function validateLeadAndStage(
//         string $leadUuid,
//         string $stageCode
//     ): void {
//         BLLeadCreation::where('uuid', $leadUuid)
//             ->lockForUpdate()
//             ->firstOrFail();

//         BLWorkflowStage::where(
//             'stage_code',
//             strtoupper($stageCode)
//         )->firstOrFail();
//     }

//     private function ensureLeadNotDisbursed(
//         string $leadUuid
//     ): void {
//         $isDisbursed = BLDisbursment::where(
//             'lead_uuid',
//             $leadUuid
//         )->exists();

//         if ($isDisbursed) {
//             throw ValidationException::withMessages([
//                 'lead_uuid' =>
//                     'Disbursed lead decision cannot be changed.',
//             ]);
//         }
//     }

//     private function ensurePreviousStageApproved(
//         string $leadUuid,
//         string $stageCode
//     ): void {
//         $stage = BLWorkflowStage::where(
//             'stage_code',
//             strtoupper($stageCode)
//         )->firstOrFail();

//         $previousStage = BLWorkflowStage::where(
//             'sequence_no',
//             '<',
//             $stage->sequence_no
//         )
//             ->orderByDesc('sequence_no')
//             ->first();

//         if (!$previousStage) {
//             return;
//         }

//         $previousApproved = BLLeadDecision::where([
//             'lead_uuid'  => $leadUuid,
//             'stage_code' => $previousStage->stage_code,
//             'decision'   => 'APPROVED',
//             'is_latest'  => true,
//         ])->exists();

//         /*
//          * Existing production leads ke liye:
//          * Agar decision history bilkul nahi hai toh block nahi karenge.
//          */
//         $hasAnyDecision = BLLeadDecision::where(
//             'lead_uuid',
//             $leadUuid
//         )->exists();

//         if ($hasAnyDecision && !$previousApproved) {
//             throw ValidationException::withMessages([
//                 'stage_code' =>
//                     "{$previousStage->stage_name} must be approved first.",
//             ]);
//         }
//     }

//     private function getCurrentDecision(
//         string $leadUuid,
//         string $stageCode
//     ): ?BLLeadDecision {
//         return BLLeadDecision::where([
//             'lead_uuid'  => $leadUuid,
//             'stage_code' => strtoupper($stageCode),
//             'is_latest'  => true,
//         ])
//             ->lockForUpdate()
//             ->latest('id')
//             ->first();
//     }

//     private function closeOldDecisions(
//         string $leadUuid,
//         string $stageCode
//     ): void {
//         BLLeadDecision::where([
//             'lead_uuid'  => $leadUuid,
//             'stage_code' => strtoupper($stageCode),
//             'is_latest'  => true,
//         ])->update([
//             'is_latest' => false,
//             'updated_at' => now(),
//         ]);
//     }

//     private function closeLaterStageDecisions(
//         string $leadUuid,
//         string $stageCode
//     ): void {
//         $stage = BLWorkflowStage::where(
//             'stage_code',
//             strtoupper($stageCode)
//         )->firstOrFail();

//         $laterStageCodes = BLWorkflowStage::where(
//             'sequence_no',
//             '>',
//             $stage->sequence_no
//         )->pluck('stage_code');

//         BLLeadDecision::where('lead_uuid', $leadUuid)
//             ->whereIn('stage_code', $laterStageCodes)
//             ->where('is_latest', true)
//             ->update([
//                 'is_latest' => false,
//                 'updated_at' => now(),
//             ]);
//     }
// }


namespace Modules\BLWorkflow\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\BLDisbursment\Models\BLDisbursment;
use Modules\BLJourney\Models\BLJourney;
use Modules\BLLeadCreation\Models\BLLeadCreation;
use Modules\BLWorkflow\Models\BLStageRolePermission;
use Modules\BLWorkflow\Models\BLWorkflowStage;
use Modules\BLWorkflow\Models\BLWorkflowStatus;
use Modules\BLWorkflow\Models\BLWorkflowTransition;

class BLDecisionService
{
    public function approve(
        string $leadUuid,
        string $stageCode,
        ?string $remarks = null
    ): BLJourney {
        return DB::transaction(function () use (
            $leadUuid,
            $stageCode,
            $remarks
        ) {
            $lead = $this->getLockedLead($leadUuid);
            $this->ensureNotDisbursed($leadUuid);

            $currentJourney = $this->getCurrentJourney($leadUuid);
            $currentStage = $this->getStage($stageCode);

            $this->ensureCurrentStage(
                $currentJourney,
                $currentStage
            );

            if (
                strtoupper((string) $currentJourney->status)
                === 'REJECTED'
            ) {
                throw ValidationException::withMessages([
                    'lead_uuid' =>
                        'Rejected lead must be reopened first.',
                ]);
            }

            $approvedStatus = $this->getStageStatus(
                $currentStage->id,
                'APPROVED'
            );

            $transition = BLWorkflowTransition::query()
                ->where('from_stage_id', $currentStage->id)
                ->where(function ($query) {
                    $query
                        ->whereNull('required_status_code')
                        ->orWhere(
                            'required_status_code',
                            'APPROVED'
                        );
                })
                ->with('toStage')
                ->first();

            if (!$transition || !$transition->toStage) {
                throw ValidationException::withMessages([
                    'stage_code' =>
                        'Next workflow transition is not configured.',
                ]);
            }

            $nextStage = $transition->toStage;

            $nextPendingStatus = $this->getStageStatus(
                $nextStage->id,
                'PENDING'
            );

            /*
             * Existing current row history ban jayegi.
             */
            BLJourney::where('lead_uuid', $leadUuid)
                ->where('is_latest', true)
                ->update(['is_latest' => false]);

            /*
             * APPROVED history record.
             */
            $this->createJourney([
                'lead_id' => $lead->lead_id,
                'lead_uuid' => $lead->uuid,
                'workflow_stage_id' => $currentStage->id,
                'workflow_status_id' => $approvedStatus->id,
                'previous_stage_id' =>
                    $currentJourney->previous_stage_id,

                'stage' => $currentStage->stage_code,
                'status' => 'APPROVED',
                'team' => $currentStage->team_name,

                'action_type' => 'DECISION',
                'transition_type' => 'FORWARD',
                'action_performed' => 'APPROVED',
                'remarks' => $remarks,

                'is_latest' => false,
            ]);

            /*
             * Next stage ka current record.
             */
            return $this->createJourney([
                'lead_id' => $lead->lead_id,
                'lead_uuid' => $lead->uuid,
                'workflow_stage_id' => $nextStage->id,
                'workflow_status_id' => $nextPendingStatus->id,
                'previous_stage_id' => $currentStage->id,

                'stage' => $nextStage->stage_code,
                'status' => 'PENDING',
                'team' => $nextStage->team_name,

                'action_type' => 'STAGE_MOVED',
                'transition_type' => 'FORWARD',
                'action_performed' =>
                    "MOVED_TO_{$nextStage->stage_code}",
                'remarks' =>
                    "Moved after {$currentStage->stage_name} approval.",

                'is_latest' => true,
            ]);
        });
    }

    public function reject(
        string $leadUuid,
        string $stageCode,
        string $remarks
    ): BLJourney {
        return DB::transaction(function () use (
            $leadUuid,
            $stageCode,
            $remarks
        ) {
            $lead = $this->getLockedLead($leadUuid);
            $this->ensureNotDisbursed($leadUuid);

            $currentJourney = $this->getCurrentJourney($leadUuid);
            $currentStage = $this->getStage($stageCode);

            $this->ensureCurrentStage(
                $currentJourney,
                $currentStage
            );

            if (
                strtoupper((string) $currentJourney->status)
                === 'REJECTED'
            ) {
                throw ValidationException::withMessages([
                    'lead_uuid' =>
                        'Lead is already rejected at this stage.',
                ]);
            }

            $rejectedStatus = $this->getStageStatus(
                $currentStage->id,
                'REJECTED'
            );

            BLJourney::where('lead_uuid', $leadUuid)
                ->where('is_latest', true)
                ->update(['is_latest' => false]);

            /*
             * Rejected record current rahega.
             * Lead next stage par move nahi hogi.
             */
            return $this->createJourney([
                'lead_id' => $lead->lead_id,
                'lead_uuid' => $lead->uuid,
                'workflow_stage_id' => $currentStage->id,
                'workflow_status_id' => $rejectedStatus->id,
                'previous_stage_id' =>
                    $currentJourney->previous_stage_id,

                'stage' => $currentStage->stage_code,
                'status' => 'REJECTED',
                'team' => $currentStage->team_name,

                'action_type' => 'DECISION',
                'transition_type' => 'STOPPED',
                'action_performed' => 'REJECTED',
                'remarks' => $remarks,

                'is_latest' => true,
            ]);
        });
    }

    public function reopen(
        string $leadUuid,
        string $remarks
    ): BLJourney {
        return DB::transaction(function () use (
            $leadUuid,
            $remarks
        ) {
            $lead = $this->getLockedLead($leadUuid);
            $this->ensureNotDisbursed($leadUuid);

            $currentJourney = $this->getCurrentJourney($leadUuid);

            $isRejected =
                strtoupper((string) $currentJourney->status)
                    === 'REJECTED'
                || strtoupper(
                    (string) $currentJourney->action_performed
                ) === 'REJECTED';

            if (!$isRejected) {
                throw ValidationException::withMessages([
                    'lead_uuid' =>
                        'Only rejected lead can be reopened.',
                ]);
            }

            $stage = BLWorkflowStage::findOrFail(
                $currentJourney->workflow_stage_id
            );

            if (!$stage->is_reversible) {
                throw ValidationException::withMessages([
                    'stage' =>
                        "{$stage->stage_name} cannot be reopened.",
                ]);
            }

            $pendingStatus = $this->getStageStatus(
                $stage->id,
                'PENDING'
            );

            BLJourney::where('lead_uuid', $leadUuid)
                ->where('is_latest', true)
                ->update(['is_latest' => false]);

            /*
             * Same rejected stage par lead dobara open hogi.
             */
            return $this->createJourney([
                'lead_id' => $lead->lead_id,
                'lead_uuid' => $lead->uuid,
                'workflow_stage_id' => $stage->id,
                'workflow_status_id' => $pendingStatus->id,
                'previous_stage_id' =>
                    $currentJourney->previous_stage_id,

                'stage' => $stage->stage_code,
                'status' => 'PENDING',
                'team' => $stage->team_name,

                'action_type' => 'REOPEN',
                'transition_type' => 'REOPENED',
                'action_performed' => 'REOPENED',
                'remarks' => $remarks,

                'is_latest' => true,
            ]);
        });
    }

    public function state(string $leadUuid): array
    {
        $lead = BLLeadCreation::where('uuid', $leadUuid)
            ->firstOrFail();

        $currentJourney = BLJourney::query()
            ->with([
                'workflowStage:id,stage_name,stage_code,team_name',
                'workflowStatus:id,status_name,status_code',
                'previousStage:id,stage_name,stage_code',
                'user:id,user_name,user_email',
            ])
            ->where('lead_uuid', $leadUuid)
            ->where('is_latest', true)
            ->latest('id')
            ->first();

        $isDisbursed = BLDisbursment::where(
            'lead_uuid',
            $leadUuid
        )->exists();

        $isRejected = $currentJourney &&
            strtoupper((string) $currentJourney->status)
                === 'REJECTED';

        return [
            'lead_uuid' => $leadUuid,
            'lead_id' => $lead->lead_id,
            'is_disbursed' => $isDisbursed,
            'is_rejected' => $isRejected,
            'can_reopen' => $isRejected &&
                (bool) $currentJourney
                    ?->workflowStage
                    ?->is_reversible,
            'current_journey' => $currentJourney,
        ];
    }

    private function getLockedLead(
        string $leadUuid
    ): BLLeadCreation {
        return BLLeadCreation::where('uuid', $leadUuid)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function getCurrentJourney(
        string $leadUuid
    ): BLJourney {
        $journey = BLJourney::where('lead_uuid', $leadUuid)
            ->where('is_latest', true)
            ->latest('id')
            ->lockForUpdate()
            ->first();

        if (!$journey) {
            throw ValidationException::withMessages([
                'lead_uuid' =>
                    'Current journey record is not available.',
            ]);
        }

        return $journey;
    }

    private function getStage(
        string $stageCode
    ): BLWorkflowStage {
        return BLWorkflowStage::where(
            'stage_code',
            strtoupper(trim($stageCode))
        )->firstOrFail();
    }

    private function getStageStatus(
        int $stageId,
        string $statusCode
    ): BLWorkflowStatus {
        return BLWorkflowStatus::where('stage_id', $stageId)
            ->where('status_code', strtoupper($statusCode))
            ->firstOrFail();
    }

    private function ensureCurrentStage(
        BLJourney $journey,
        BLWorkflowStage $requestedStage
    ): void {
        if (
            (int) $journey->workflow_stage_id
            !== (int) $requestedStage->id
        ) {
            $currentStage = BLWorkflowStage::find(
                $journey->workflow_stage_id
            );

            throw ValidationException::withMessages([
                'stage_code' =>
                    'Lead is currently at ' .
                    ($currentStage?->stage_name ?? 'another stage') .
                    '.',
            ]);
        }
    }

    private function ensureNotDisbursed(
        string $leadUuid
    ): void {
        if (
            BLDisbursment::where(
                'lead_uuid',
                $leadUuid
            )->exists()
        ) {
            throw ValidationException::withMessages([
                'lead_uuid' =>
                    'Disbursed lead cannot be modified.',
            ]);
        }
    }

    private function createJourney(array $data): BLJourney
    {
        $user = Auth::user();

        return BLJourney::create(array_merge($data, [
            'performed_by_role' => $this->resolveRole($user),
            'user_id' => $user?->id,
            'user_name' => $user?->user_name,
            'ip_address' => request()->ip(),
            'device_info' => request()->userAgent(),
        ]));
    }

    private function resolveRole($user): ?string
    {
        return $user?->role_name
            ?? $user?->user_role
            ?? $user?->user_type?->name
            ?? null;
    }
}