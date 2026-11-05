<?php

namespace Modules\BLWorkflow\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\BLWorkflow\Services\BLWorkflowService;
use Modules\BLLeadCreation\Models\BLLeadCreation;
use Modules\UserManagement\Support\BranchAccess;
use Throwable;

class BLWorkflowController extends Controller
{
     public function __construct(
        private readonly BLWorkflowService $workflowService
    ) {}

    public function index(): JsonResponse
    {
        try {
            return $this->success(
                $this->workflowService->getStages(),
                'Workflow stages fetched successfully.'
            );
        } catch (Throwable $e) {
            return $this->serverError($e);
        }
    }

    public function show(string $stage): JsonResponse
    {
        try {
            return $this->success(
                $this->workflowService->getStage($stage),
                'Workflow stage fetched successfully.'
            );
        } catch (ModelNotFoundException) {
            return $this->error('Workflow stage not found.', null, 404, 'STAGE_NOT_FOUND');
        } catch (Throwable $e) {
            return $this->serverError($e);
        }
    }

    public function statuses(string $stage): JsonResponse
    {
        try {
            $workflowStage = $this->workflowService->getStage($stage);

            return $this->success(
                $workflowStage->statuses,
                'Workflow statuses fetched successfully.'
            );
        } catch (ModelNotFoundException) {
            return $this->error('Workflow stage not found.', null, 404, 'STAGE_NOT_FOUND');
        } catch (Throwable $e) {
            return $this->serverError($e);
        }
    }

    public function transitions(): JsonResponse
    {
        try {
            return $this->success(
                $this->workflowService->getTransitions(),
                'Workflow transitions fetched successfully.'
            );
        } catch (Throwable $e) {
            return $this->serverError($e);
        }
    }

    public function permissions(Request $request, string $stage): JsonResponse
    {
        try {
            $workflowStage = $this->workflowService->getStage($stage);

            if ($request->filled('role_name')) {
                $permission = $this->workflowService->getPermissionForRole(
                    $workflowStage,
                    $request->string('role_name')->toString()
                );

                return $this->success(
                    $permission,
                    $permission ? 'Role permission fetched successfully.' : 'No permission found for this role.'
                );
            }

            return $this->success(
                $workflowStage->rolePermissions,
                'Stage permissions fetched successfully.'
            );
        } catch (ModelNotFoundException) {
            return $this->error('Workflow stage not found.', null, 404, 'STAGE_NOT_FOUND');
        } catch (Throwable $e) {
            return $this->serverError($e);
        }
    }

    public function current(Request $request, string $lead_uuid): JsonResponse
    {
        $this->ensureLeadBranchAccess($request, $lead_uuid);

        try {
            return $this->success(
                $this->workflowService->getLeadCurrentData($lead_uuid),
                'Current lead workflow fetched successfully.'
            );
        } catch (ModelNotFoundException) {
            return $this->error('Lead not found.', null, 404, 'LEAD_NOT_FOUND');
        } catch (Throwable $e) {
            return $this->serverError($e);
        }
    }

    public function initiate(Request $request, string $lead_uuid): JsonResponse
    {
        $this->ensureLeadBranchAccess($request, $lead_uuid);

        try {
            $validated = $request->validate([
                'status_code' => 'nullable|string',
                'remarks' => 'nullable|string',
            ]);

            $journey = $this->workflowService->initiateLead(
                $lead_uuid,
                $request->user(),
                $validated['status_code'] ?? 'DRAFT',
                $validated['remarks'] ?? null,
                $request
            );

            return $this->success(
                $journey,
                'Lead workflow initiated successfully.',
                201,
                'WORKFLOW_INITIATED'
            );
        } catch (ValidationException $e) {
            return $this->error('Validation failed.', $e->errors(), 422, 'VALIDATION_ERROR');
        } catch (ModelNotFoundException) {
            return $this->error('Lead or workflow stage not found.', null, 404, 'NOT_FOUND');
        } catch (Throwable $e) {
            return $this->serverError($e);
        }
    }

    public function changeStatus(Request $request, string $lead_uuid): JsonResponse
    {
        $this->ensureLeadBranchAccess($request, $lead_uuid);

        try {
            $validated = $request->validate([
                'status_code' => 'required|string',
                'remarks' => 'nullable|string',
                'auto_move' => 'nullable|boolean',
            ]);

            $currentData = $this->workflowService->getLeadCurrentData($lead_uuid);
            $currentStage = $currentData['current_stage'];

            if (
                $currentStage &&
                !$this->workflowService->userCan($currentStage, $request->user(), 'can_change_status')
            ) {
                return $this->error(
                    'You do not have permission to change status at this stage.',
                    null,
                    403,
                    'PERMISSION_DENIED'
                );
            }

            $result = $this->workflowService->changeStatus(
                $lead_uuid,
                $validated['status_code'],
                $request->user(),
                $validated['remarks'] ?? null,
                $request,
                (bool) ($validated['auto_move'] ?? false)
            );

            return $this->success(
                $result,
                'Workflow status changed successfully.'
            );
        } catch (ValidationException $e) {
            return $this->error('Validation failed.', $e->errors(), 422, 'VALIDATION_ERROR');
        } catch (ModelNotFoundException) {
            return $this->error('Lead not found.', null, 404, 'LEAD_NOT_FOUND');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), null, 409, 'WORKFLOW_ERROR');
        }
    }

    public function moveNext(Request $request, string $lead_uuid): JsonResponse
    {
        $this->ensureLeadBranchAccess($request, $lead_uuid);

        try {
            $validated = $request->validate([
                'remarks' => 'nullable|string',
            ]);

            $currentData = $this->workflowService->getLeadCurrentData($lead_uuid);
            $currentStage = $currentData['current_stage'];

            if (
                $currentStage &&
                !$this->workflowService->userCan($currentStage, $request->user(), 'can_move_next')
            ) {
                return $this->error(
                    'You do not have permission to move this lead next.',
                    null,
                    403,
                    'PERMISSION_DENIED'
                );
            }

            $result = $this->workflowService->moveNext(
                $lead_uuid,
                $request->user(),
                $validated['remarks'] ?? null,
                $request
            );

            return $this->success(
                $result,
                'Lead moved to next stage successfully.'
            );
        } catch (ValidationException $e) {
            return $this->error('Validation failed.', $e->errors(), 422, 'VALIDATION_ERROR');
        } catch (ModelNotFoundException) {
            return $this->error('Lead not found.', null, 404, 'LEAD_NOT_FOUND');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), null, 409, 'WORKFLOW_ERROR');
        }
    }

    public function movePrevious(Request $request, string $lead_uuid): JsonResponse
    {
        $this->ensureLeadBranchAccess($request, $lead_uuid);

        try {
            $validated = $request->validate([
                'to_stage_code' => 'nullable|string|exists:b_l_workflow_stages,stage_code',
                'remarks' => 'nullable|string',
            ]);

            $currentData = $this->workflowService->getLeadCurrentData($lead_uuid);
            $currentStage = $currentData['current_stage'];

            if (
                $currentStage &&
                !$this->workflowService->userCan($currentStage, $request->user(), 'can_move_previous')
            ) {
                return $this->error(
                    'You do not have permission to move this lead previous.',
                    null,
                    403,
                    'PERMISSION_DENIED'
                );
            }

            $result = $this->workflowService->movePrevious(
                $lead_uuid,
                $request->user(),
                $validated['to_stage_code'] ?? null,
                $validated['remarks'] ?? null,
                $request
            );

            return $this->success(
                $result,
                'Lead moved to previous stage successfully.'
            );
        } catch (ValidationException $e) {
            return $this->error('Validation failed.', $e->errors(), 422, 'VALIDATION_ERROR');
        } catch (ModelNotFoundException) {
            return $this->error('Lead or stage not found.', null, 404, 'NOT_FOUND');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), null, 409, 'WORKFLOW_ERROR');
        }
    }

    private function ensureLeadBranchAccess(Request $request, string $leadUuid): void
    {
        $lead = BLLeadCreation::query()
            ->select(['uuid', 'branch_id'])
            ->where('uuid', $leadUuid)
            ->firstOrFail();

        BranchAccess::ensureCanAccessBranch(
            $request->user(),
            $lead->branch_id === null ? null : (int) $lead->branch_id
        );
    }

    private function success($data = null, string $message = 'Success', int $status = 200, string $code = 'SUCCESS'): JsonResponse
    {
        return response()->json([
            'success' => true,
            'status_code' => $status,
            'code' => $code,
            'request_id' => app('request_id'),
            'timestamp'  => now()->toISOString(),

            'message' => $message,
            'data' => $data,
        ], $status);
    }

    private function error(string $message, $data = null, int $status = 400, string $code = 'ERROR'): JsonResponse
    {
        return response()->json([
            'success' => false,
            'status_code' => $status,
            'code' => $code,
            'message' => $message,
            'request_id' => app('request_id'),
           'timestamp'  => now()->toISOString(),
            'data' => $data,
        ], $status);
    }

    private function serverError(Throwable $e): JsonResponse
    {
        return $this->error('Something went wrong.', $e->getMessage(), 500, 'SERVER_ERROR');
    }
}


// namespace Modules\BLWorkflow\Http\Controllers;

// use App\Http\Controllers\Controller;
// use Illuminate\Database\Eloquent\ModelNotFoundException;
// use Illuminate\Http\JsonResponse;
// use Illuminate\Http\Request;
// use Illuminate\Validation\ValidationException;
// use Modules\BLWorkflow\Services\BLWorkflowService;
// use Throwable;

// class BLWorkflowController extends Controller
// {
//      public function __construct(
//         private readonly BLWorkflowService $workflowService
//     ) {}

//     public function index(): JsonResponse
//     {
//         try {
//             return $this->success(
//                 $this->workflowService->getStages(),
//                 'Workflow stages fetched successfully.'
//             );
//         } catch (Throwable $e) {
//             return $this->serverError($e);
//         }
//     }

//     public function show(string $stage): JsonResponse
//     {
//         try {
//             return $this->success(
//                 $this->workflowService->getStage($stage),
//                 'Workflow stage fetched successfully.'
//             );
//         } catch (ModelNotFoundException) {
//             return $this->error('Workflow stage not found.', null, 404, 'STAGE_NOT_FOUND');
//         } catch (Throwable $e) {
//             return $this->serverError($e);
//         }
//     }

//     public function statuses(string $stage): JsonResponse
//     {
//         try {
//             $workflowStage = $this->workflowService->getStage($stage);

//             return $this->success(
//                 $workflowStage->statuses,
//                 'Workflow statuses fetched successfully.'
//             );
//         } catch (ModelNotFoundException) {
//             return $this->error('Workflow stage not found.', null, 404, 'STAGE_NOT_FOUND');
//         } catch (Throwable $e) {
//             return $this->serverError($e);
//         }
//     }

//     public function transitions(): JsonResponse
//     {
//         try {
//             return $this->success(
//                 $this->workflowService->getTransitions(),
//                 'Workflow transitions fetched successfully.'
//             );
//         } catch (Throwable $e) {
//             return $this->serverError($e);
//         }
//     }

//     public function permissions(Request $request, string $stage): JsonResponse
//     {
//         try {
//             $workflowStage = $this->workflowService->getStage($stage);

//             if ($request->filled('role_name')) {
//                 $permission = $this->workflowService->getPermissionForRole(
//                     $workflowStage,
//                     $request->string('role_name')->toString()
//                 );

//                 return $this->success(
//                     $permission,
//                     $permission ? 'Role permission fetched successfully.' : 'No permission found for this role.'
//                 );
//             }

//             return $this->success(
//                 $workflowStage->rolePermissions,
//                 'Stage permissions fetched successfully.'
//             );
//         } catch (ModelNotFoundException) {
//             return $this->error('Workflow stage not found.', null, 404, 'STAGE_NOT_FOUND');
//         } catch (Throwable $e) {
//             return $this->serverError($e);
//         }
//     }

//     public function current(string $lead_uuid): JsonResponse
//     {
//         try {
//             return $this->success(
//                 $this->workflowService->getLeadCurrentData($lead_uuid),
//                 'Current lead workflow fetched successfully.'
//             );
//         } catch (ModelNotFoundException) {
//             return $this->error('Lead not found.', null, 404, 'LEAD_NOT_FOUND');
//         } catch (Throwable $e) {
//             return $this->serverError($e);
//         }
//     }

//     public function initiate(Request $request, string $lead_uuid): JsonResponse
//     {
//         try {
//             $validated = $request->validate([
//                 'status_code' => 'nullable|string',
//                 'remarks' => 'nullable|string',
//             ]);

//             $journey = $this->workflowService->initiateLead(
//                 $lead_uuid,
//                 $request->user(),
//                 $validated['status_code'] ?? 'DRAFT',
//                 $validated['remarks'] ?? null,
//                 $request
//             );

//             return $this->success(
//                 $journey,
//                 'Lead workflow initiated successfully.',
//                 201,
//                 'WORKFLOW_INITIATED'
//             );
//         } catch (ValidationException $e) {
//             return $this->error('Validation failed.', $e->errors(), 422, 'VALIDATION_ERROR');
//         } catch (ModelNotFoundException) {
//             return $this->error('Lead or workflow stage not found.', null, 404, 'NOT_FOUND');
//         } catch (Throwable $e) {
//             return $this->serverError($e);
//         }
//     }

//     public function changeStatus(Request $request, string $lead_uuid): JsonResponse
//     {
//         try {
//             $validated = $request->validate([
//                 'status_code' => 'required|string',
//                 'remarks' => 'nullable|string',
//                 'auto_move' => 'nullable|boolean',
//             ]);

//             $currentData = $this->workflowService->getLeadCurrentData($lead_uuid);
//             $currentStage = $currentData['current_stage'];

//             if (
//                 $currentStage &&
//                 !$this->workflowService->userCan($currentStage, $request->user(), 'can_change_status')
//             ) {
//                 return $this->error(
//                     'You do not have permission to change status at this stage.',
//                     null,
//                     403,
//                     'PERMISSION_DENIED'
//                 );
//             }

//             $result = $this->workflowService->changeStatus(
//                 $lead_uuid,
//                 $validated['status_code'],
//                 $request->user(),
//                 $validated['remarks'] ?? null,
//                 $request,
//                 (bool) ($validated['auto_move'] ?? false)
//             );

//             return $this->success(
//                 $result,
//                 'Workflow status changed successfully.'
//             );
//         } catch (ValidationException $e) {
//             return $this->error('Validation failed.', $e->errors(), 422, 'VALIDATION_ERROR');
//         } catch (ModelNotFoundException) {
//             return $this->error('Lead not found.', null, 404, 'LEAD_NOT_FOUND');
//         } catch (Throwable $e) {
//             return $this->error($e->getMessage(), null, 409, 'WORKFLOW_ERROR');
//         }
//     }

//     public function moveNext(Request $request, string $lead_uuid): JsonResponse
//     {
//         try {
//             $validated = $request->validate([
//                 'remarks' => 'nullable|string',
//             ]);

//             $currentData = $this->workflowService->getLeadCurrentData($lead_uuid);
//             $currentStage = $currentData['current_stage'];

//             if (
//                 $currentStage &&
//                 !$this->workflowService->userCan($currentStage, $request->user(), 'can_move_next')
//             ) {
//                 return $this->error(
//                     'You do not have permission to move this lead next.',
//                     null,
//                     403,
//                     'PERMISSION_DENIED'
//                 );
//             }

//             $result = $this->workflowService->moveNext(
//                 $lead_uuid,
//                 $request->user(),
//                 $validated['remarks'] ?? null,
//                 $request
//             );

//             return $this->success(
//                 $result,
//                 'Lead moved to next stage successfully.'
//             );
//         } catch (ValidationException $e) {
//             return $this->error('Validation failed.', $e->errors(), 422, 'VALIDATION_ERROR');
//         } catch (ModelNotFoundException) {
//             return $this->error('Lead not found.', null, 404, 'LEAD_NOT_FOUND');
//         } catch (Throwable $e) {
//             return $this->error($e->getMessage(), null, 409, 'WORKFLOW_ERROR');
//         }
//     }

//     public function movePrevious(Request $request, string $lead_uuid): JsonResponse
//     {
//         try {
//             $validated = $request->validate([
//                 'to_stage_code' => 'nullable|string|exists:b_l_workflow_stages,stage_code',
//                 'remarks' => 'nullable|string',
//             ]);

//             $currentData = $this->workflowService->getLeadCurrentData($lead_uuid);
//             $currentStage = $currentData['current_stage'];

//             if (
//                 $currentStage &&
//                 !$this->workflowService->userCan($currentStage, $request->user(), 'can_move_previous')
//             ) {
//                 return $this->error(
//                     'You do not have permission to move this lead previous.',
//                     null,
//                     403,
//                     'PERMISSION_DENIED'
//                 );
//             }

//             $result = $this->workflowService->movePrevious(
//                 $lead_uuid,
//                 $request->user(),
//                 $validated['to_stage_code'] ?? null,
//                 $validated['remarks'] ?? null,
//                 $request
//             );

//             return $this->success(
//                 $result,
//                 'Lead moved to previous stage successfully.'
//             );
//         } catch (ValidationException $e) {
//             return $this->error('Validation failed.', $e->errors(), 422, 'VALIDATION_ERROR');
//         } catch (ModelNotFoundException) {
//             return $this->error('Lead or stage not found.', null, 404, 'NOT_FOUND');
//         } catch (Throwable $e) {
//             return $this->error($e->getMessage(), null, 409, 'WORKFLOW_ERROR');
//         }
//     }

//     private function success($data = null, string $message = 'Success', int $status = 200, string $code = 'SUCCESS'): JsonResponse
//     {
//         return response()->json([
//             'success' => true,
//             'status_code' => $status,
//             'code' => $code,
//             'request_id' => app('request_id'),
//             'timestamp'  => now()->toISOString(),

//             'message' => $message,
//             'data' => $data,
//         ], $status);
//     }

//     private function error(string $message, $data = null, int $status = 400, string $code = 'ERROR'): JsonResponse
//     {
//         return response()->json([
//             'success' => false,
//             'status_code' => $status,
//             'code' => $code,
//             'message' => $message,
//             'request_id' => app('request_id'),
//            'timestamp'  => now()->toISOString(),
//             'data' => $data,
//         ], $status);
//     }

//     private function serverError(Throwable $e): JsonResponse
//     {
//         return $this->error('Something went wrong.', $e->getMessage(), 500, 'SERVER_ERROR');
//     }
// }
