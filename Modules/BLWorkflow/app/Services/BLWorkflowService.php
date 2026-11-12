<?php

namespace Modules\BLWorkflow\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\BLJourney\Models\BLJourney;
use Modules\BLLeadCreation\Models\BLLeadCreation;
use Modules\BLWorkflow\Models\BLStageRolePermission;
use Modules\BLWorkflow\Models\BLWorkflowStage;
use Modules\BLWorkflow\Models\BLWorkflowStatus;
use Modules\BLWorkflow\Models\BLWorkflowTransition;

class BLWorkflowService
{
    public function getStages()
    {
        return BLWorkflowStage::query()
            ->with(['statuses', 'rolePermissions'])
            ->orderBy('sequence_no', 'asc')
            ->get();
    }

    public function getStage(string|int $stage): BLWorkflowStage
    {
        return BLWorkflowStage::query()
            ->with(['statuses', 'rolePermissions'])
            ->where('id', $stage)
            ->orWhere('stage_code', $stage)
            ->firstOrFail();
    }

    public function getStageByCode(string $stageCode): BLWorkflowStage
    {
        return BLWorkflowStage::query()
            ->with(['statuses', 'rolePermissions'])
            ->where('stage_code', $stageCode)
            ->firstOrFail();
    }

    public function getTransitions()
    {
        return BLWorkflowTransition::query()
            ->with(['fromStage', 'toStage'])
            ->get();
    }

    public function getLeadCurrentData(string $leadUuid): array
    {
        $lead = BLLeadCreation::query()
            ->where('uuid', $leadUuid)
            ->firstOrFail();

        $journey = BLJourney::query()
            ->with(['workflowStage', 'workflowStatus'])
            ->where('lead_uuid', $leadUuid)
            ->where('is_latest', true)
            ->latest('id')
            ->first();

        if (!$journey) {
            return [
                'lead' => $lead,
                'current_journey' => null,
                'current_stage' => null,
                'current_status' => null,
                'message' => 'Workflow not initiated for this lead.',
            ];
        }

        $stage = $this->resolveStageFromJourney($journey);
        $status = $this->resolveStatusFromJourney($journey, $stage);

        return [
            'lead' => $lead,
            'current_journey' => $journey,
            'current_stage' => $stage,
            'current_status' => $status,
            'message' => 'Current workflow fetched successfully.',
        ];
    }

    public function initiateLead(
        string $leadUuid,
        mixed $user = null,
        string $statusCode = 'DRAFT',
        ?string $remarks = null,
        ?Request $request = null
    ): BLJourney {
        $lead = BLLeadCreation::query()
            ->where('uuid', $leadUuid)
            ->firstOrFail();

        $stage = $this->getStageByCode('LEAD_CREATED');

        $status = BLWorkflowStatus::query()
            ->where('stage_id', $stage->id)
            ->where('status_code', $statusCode)
            ->first();

        if (!$status) {
            $status = $this->getDefaultStatusForStage($stage);
        }

        return $this->createJourney([
            'lead' => $lead,
            'stage' => $stage,
            'status' => $status,
            'user' => $user,
            'request' => $request,
            'remarks' => $remarks ?? 'Workflow initiated.',
            'action_type' => 'INITIATED',
            'transition_type' => 'INITIATED',
            'previous_stage_id' => null,
        ]);
    }

    public function changeStatus(
        string $leadUuid,
        string $statusCode,
        mixed $user = null,
        ?string $remarks = null,
        ?Request $request = null,
        bool $autoMove = false
    ): array {
        $currentData = $this->ensureLeadHasJourney($leadUuid, $user, $request);

        /** @var BLLeadCreation $lead */
        $lead = $currentData['lead'];

        /** @var BLWorkflowStage $stage */
        $stage = $currentData['current_stage'];

        if ($stage->is_terminal) {
            throw new RuntimeException('This workflow is already terminal. Status change is not allowed.');
        }

        $status = BLWorkflowStatus::query()
            ->where('stage_id', $stage->id)
            ->where('status_code', $statusCode)
            ->first();

        if (!$status) {
            throw new RuntimeException('Invalid status for current stage.');
        }

        $journey = $this->createJourney([
            'lead' => $lead,
            'stage' => $stage,
            'status' => $status,
            'user' => $user,
            'request' => $request,
            'remarks' => $remarks ?? 'Status changed to ' . $status->status_name,
            'action_type' => 'STATUS_CHANGE',
            'transition_type' => 'STATUS_CHANGE',
            'previous_stage_id' => $stage->id,
        ]);

        $result = [
            'journey' => $journey,
            'auto_moved' => false,
            'next_journey' => null,
        ];

        if ($autoMove && $status->is_final) {
            $moveResult = $this->moveNext($leadUuid, $user, $remarks, $request);
            $result['auto_moved'] = true;
            $result['next_journey'] = $moveResult['journey'];
        }

        return $result;
    }

    public function moveNext(
        string $leadUuid,
        mixed $user = null,
        ?string $remarks = null,
        ?Request $request = null
    ): array {
        $currentData = $this->ensureLeadHasJourney($leadUuid, $user, $request);

        /** @var BLLeadCreation $lead */
        $lead = $currentData['lead'];

        /** @var BLWorkflowStage $currentStage */
        $currentStage = $currentData['current_stage'];

        /** @var BLWorkflowStatus|null $currentStatus */
        $currentStatus = $currentData['current_status'];

        if ($currentStage->is_terminal) {
            throw new RuntimeException('Lead is already in terminal stage.');
        }

        $transition = BLWorkflowTransition::query()
            ->with('toStage')
            ->where('from_stage_id', $currentStage->id)
            ->where('allow_back', false)
            ->where(function ($query) use ($currentStatus) {
                $query->whereNull('required_status_code');

                if ($currentStatus) {
                    $query->orWhere('required_status_code', $currentStatus->status_code);
                }
            })
            ->first();

        if (!$transition) {
            throw new RuntimeException('Next movement is not allowed from current stage/status.');
        }

        $toStage = $transition->toStage;

        $toStatus = $this->getDefaultStatusForStage($toStage);

        $journey = $this->createJourney([
            'lead' => $lead,
            'stage' => $toStage,
            'status' => $toStatus,
            'user' => $user,
            'request' => $request,
            'remarks' => $remarks ?? 'Moved from ' . $currentStage->stage_name . ' to ' . $toStage->stage_name,
            'action_type' => 'MOVE_NEXT',
            'transition_type' => 'FORWARD',
            'previous_stage_id' => $currentStage->id,
        ]);

        return [
            'journey' => $journey,
            'from_stage' => $currentStage,
            'to_stage' => $toStage,
        ];
    }

    public function movePrevious(
        string $leadUuid,
        mixed $user = null,
        ?string $toStageCode = null,
        ?string $remarks = null,
        ?Request $request = null
    ): array {
        $currentData = $this->ensureLeadHasJourney($leadUuid, $user, $request);

        /** @var BLLeadCreation $lead */
        $lead = $currentData['lead'];

        /** @var BLWorkflowStage $currentStage */
        $currentStage = $currentData['current_stage'];

        if (!$currentStage->is_reversible) {
            throw new RuntimeException('Current stage is not reversible.');
        }

        $transitionQuery = BLWorkflowTransition::query()
            ->with('toStage')
            ->where('from_stage_id', $currentStage->id)
            ->where('allow_back', true);

        if ($toStageCode) {
            $toStage = BLWorkflowStage::query()
                ->where('stage_code', $toStageCode)
                ->firstOrFail();

            $transitionQuery->where('to_stage_id', $toStage->id);
        }

        $transition = $transitionQuery->first();

        if (!$transition) {
            throw new RuntimeException('Previous movement is not allowed from current stage.');
        }

        $toStage = $transition->toStage;
        $toStatus = $this->getDefaultStatusForStage($toStage);

        $journey = $this->createJourney([
            'lead' => $lead,
            'stage' => $toStage,
            'status' => $toStatus,
            'user' => $user,
            'request' => $request,
            'remarks' => $remarks ?? 'Moved back from ' . $currentStage->stage_name . ' to ' . $toStage->stage_name,
            'action_type' => 'MOVE_PREVIOUS',
            'transition_type' => 'BACKWARD',
            'previous_stage_id' => $currentStage->id,
        ]);

        return [
            'journey' => $journey,
            'from_stage' => $currentStage,
            'to_stage' => $toStage,
        ];
    }

    public function getPermissionForRole(int|string $stage, string $roleName): ?BLStageRolePermission
    {
        $workflowStage = $stage instanceof BLWorkflowStage
            ? $stage
            : $this->getStage($stage);

        $roleSlug = $this->normalizeRole($roleName);

        return BLStageRolePermission::query()
            ->where('stage_id', $workflowStage->id)
            ->whereRaw("LOWER(REPLACE(role_name, ' ', '_')) = ?", [$roleSlug])
            ->first();
    }

    public function userCan(?BLWorkflowStage $stage, mixed $user, string $permissionColumn): bool
    {
        if (!$stage || !$user) {
            return false;
        }

        if ($this->isAdminUser($user)) {
            return true;
        }

        if (!Schema::hasColumn('b_l_stage_role_permissions', $permissionColumn)) {
            return false;
        }

        $roleCandidates = array_filter([
            $user->user_role ?? null,
            $user->user_designation ?? null,
            $user->user_type ?? null,
        ]);

        foreach ($roleCandidates as $roleName) {
            $permission = $this->getPermissionForRole($stage, (string) $roleName);

            if ($permission && (bool) $permission->{$permissionColumn}) {
                return true;
            }
        }

        return false;
    }

    public function isAdminUser(mixed $user): bool
    {
        $role = $this->normalizeRole(
            $user->user_role
                ?? $user->user_designation
                ?? $user->user_type
                ?? ''
        );

        return in_array($role, ['admin', 'administrative', 'administrator'], true);
    }

    public function normalizeRole(?string $role): string
    {
        return Str::of((string) $role)
            ->lower()
            ->replace('-', '_')
            ->replace(' ', '_')
            ->trim()
            ->toString();
    }

    private function ensureLeadHasJourney(
        string $leadUuid,
        mixed $user = null,
        ?Request $request = null
    ): array {
        $currentData = $this->getLeadCurrentData($leadUuid);

        if (!$currentData['current_journey']) {
            $this->initiateLead($leadUuid, $user, 'DRAFT', 'Workflow auto initiated.', $request);
            $currentData = $this->getLeadCurrentData($leadUuid);
        }

        return $currentData;
    }

    private function resolveStageFromJourney(BLJourney $journey): ?BLWorkflowStage
    {
        if ($journey->workflowStage) {
            return $journey->workflowStage;
        }

        if ($journey->workflow_stage_id) {
            return BLWorkflowStage::query()->find($journey->workflow_stage_id);
        }

        if ($journey->stage) {
            return BLWorkflowStage::query()
                ->where('stage_name', $journey->stage)
                ->orWhere('stage_code', $journey->stage)
                ->first();
        }

        return null;
    }

    private function resolveStatusFromJourney(
        BLJourney $journey,
        ?BLWorkflowStage $stage
    ): ?BLWorkflowStatus {
        if ($journey->workflowStatus) {
            return $journey->workflowStatus;
        }

        if ($journey->workflow_status_id) {
            return BLWorkflowStatus::query()->find($journey->workflow_status_id);
        }

        if (!$stage || !$journey->status) {
            return null;
        }

        return BLWorkflowStatus::query()
            ->where('stage_id', $stage->id)
            ->where(function ($query) use ($journey) {
                $query->where('status_name', $journey->status)
                    ->orWhere('status_code', $journey->status);
            })
            ->first();
    }

    private function getDefaultStatusForStage(BLWorkflowStage $stage): BLWorkflowStatus
    {
        $preferredCodes = ['DRAFT', 'PENDING'];

        $status = BLWorkflowStatus::query()
            ->where('stage_id', $stage->id)
            ->whereIn('status_code', $preferredCodes)
            ->orderByRaw("FIELD(status_code, 'DRAFT', 'PENDING')")
            ->first();

        if ($status) {
            return $status;
        }

        $status = BLWorkflowStatus::query()
            ->where('stage_id', $stage->id)
            ->where('is_final', false)
            ->orderBy('id')
            ->first();

        if ($status) {
            return $status;
        }

        return BLWorkflowStatus::query()
            ->where('stage_id', $stage->id)
            ->orderBy('id')
            ->firstOrFail();
    }

    private function createJourney(array $data): BLJourney
    {
        /** @var BLLeadCreation $lead */
        $lead = $data['lead'];

        /** @var BLWorkflowStage $stage */
        $stage = $data['stage'];

        /** @var BLWorkflowStatus $status */
        $status = $data['status'];

        $user = $data['user'] ?? null;
        $request = $data['request'] ?? null;

        BLJourney::query()
            ->where('lead_uuid', $lead->uuid)
            ->where('is_latest', true)
            ->update(['is_latest' => false]);

        $payload = [
            'lead_id' => $lead->lead_id ?? null,
            'lead_uuid' => $lead->uuid,

            'workflow_stage_id' => $stage->id,
            'workflow_status_id' => $status->id,
            'previous_stage_id' => $data['previous_stage_id'] ?? null,
            'action_performed' => $data['action_type'] ?? null,
            'performed_by_role' => $user
                ? ($user->user_role ?? $user->user_designation ?? $user->user_type ?? null)
                : null,
            'transition_type' => $data['transition_type'] ?? null,

            'stage' => $stage->stage_name,
            'status' => $status->status_name,
            'team' => $stage->team_name,
            'action_type' => $data['action_type'] ?? null,
            'remarks' => $data['remarks'] ?? null,
            'user_id' => $user->id ?? null,
            'user_name' => $user->user_name ?? $user->name ?? null,
            'is_latest' => true,
            'ip_address' => $request?->ip(),
            'device_info' => $request?->header('User-Agent'),
        ];

        return BLJourney::query()->create(
            $this->onlyExistingColumns('b_l_journeys', $payload)
        );
    }

    private function onlyExistingColumns(string $table, array $payload): array
    {
        return collect($payload)
            ->filter(fn ($value, $key) => Schema::hasColumn($table, $key))
            ->toArray();
    }
}