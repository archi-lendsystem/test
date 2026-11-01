<?php

namespace Modules\BLWorkflow\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\BLWorkflow\Services\BLDecisionService;
use Modules\BLLeadCreation\Models\BLLeadCreation;
use Modules\UserManagement\Support\BranchAccess;
use Throwable;

class BLDecisionController extends Controller
{
    public function approve(
        Request $request,
        string $leadUuid,
        BLDecisionService $service
    ): JsonResponse {
        $this->ensureLeadBranchAccess($request, $leadUuid);

        try {
            $validated = $request->validate([
                'stage_code' => [
                    'required',
                    'string',
                    Rule::exists(
                        'b_l_workflow_stages',
                        'stage_code'
                    ),
                ],
                'remarks' => 'nullable|string|max:2000',
            ]);

            $journey = $service->approve(
                $leadUuid,
                $validated['stage_code'],
                $validated['remarks'] ?? null
            );

            return response()->json([
                'success' => true,
                'status_code' => 200,
                'code' => 'WORKFLOW_APPROVED',
                'message' =>
                    'Stage approved and lead moved to next stage.',
                'request_id' => app('request_id'),
                'data' => $journey->load([
                    'workflowStage',
                    'workflowStatus',
                    'previousStage',
                    'user',
                ]),
            ]);
        } catch (Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    public function reject(
        Request $request,
        string $leadUuid,
        BLDecisionService $service
    ): JsonResponse {
        $this->ensureLeadBranchAccess($request, $leadUuid);

        try {
            $validated = $request->validate([
                'stage_code' => [
                    'required',
                    'string',
                    Rule::exists(
                        'b_l_workflow_stages',
                        'stage_code'
                    ),
                ],
                'remarks' => 'required|string|min:5|max:2000',
            ]);

            $journey = $service->reject(
                $leadUuid,
                $validated['stage_code'],
                $validated['remarks']
            );

            return response()->json([
                'success' => true,
                'status_code' => 200,
                'code' => 'LEAD_REJECTED',
                'message' => 'Lead rejected successfully.',
                'request_id' => app('request_id'),
                'data' => $journey->load([
                    'workflowStage',
                    'workflowStatus',
                    'previousStage',
                    'user',
                ]),
            ]);
        } catch (Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    public function reopen(
        Request $request,
        string $leadUuid,
        BLDecisionService $service
    ): JsonResponse {
        $this->ensureLeadBranchAccess($request, $leadUuid);

        try {
            $validated = $request->validate([
                'remarks' =>
                    'required|string|min:5|max:2000',
            ]);

            $journey = $service->reopen(
                $leadUuid,
                $validated['remarks']
            );

            return response()->json([
                'success' => true,
                'status_code' => 200,
                'code' => 'LEAD_REOPENED',
                'message' => 'Lead reopened successfully.',
                'request_id' => app('request_id'),
                'data' => $journey->load([
                    'workflowStage',
                    'workflowStatus',
                    'previousStage',
                    'user',
                ]),
            ]);
        } catch (Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    public function state(
        Request $request,
        string $leadUuid,
        BLDecisionService $service
    ): JsonResponse {
        $this->ensureLeadBranchAccess($request, $leadUuid);

        try {
            return response()->json([
                'success' => true,
                'status_code' => 200,
                'code' => 'SUCCESS',
                'message' => 'Lead workflow state fetched.',
                'request_id' => app('request_id'),
                'data' => $service->state($leadUuid),
            ]);
        } catch (Throwable $e) {
            return $this->errorResponse($e);
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

    private function errorResponse(Throwable $e): JsonResponse
    {
        $status = method_exists($e, 'status')
            ? $e->status
            : 500;

        return response()->json([
            'success' => false,
            'status_code' => $status,
            'code' => $status === 422
                ? 'VALIDATION_ERROR'
                : 'SERVER_ERROR',
            'message' => $e->getMessage(),
            'request_id' => app('request_id'),
            'data' => null,
        ], $status);
    }
}

// namespace Modules\BLWorkflow\Http\Controllers;

// use App\Http\Controllers\Controller;
// use Illuminate\Http\JsonResponse;
// use Illuminate\Http\Request;
// use Illuminate\Validation\Rule;
// use Modules\BLWorkflow\Services\BLDecisionService;
// use Throwable;

// class BLDecisionController extends Controller
// {
//     public function approve(
//         Request $request,
//         string $leadUuid,
//         BLDecisionService $service
//     ): JsonResponse {
//         try {
//             $validated = $request->validate([
//                 'stage_code' => [
//                     'required',
//                     'string',
//                     Rule::exists(
//                         'b_l_workflow_stages',
//                         'stage_code'
//                     ),
//                 ],
//                 'remarks' => 'nullable|string|max:2000',
//             ]);

//             $journey = $service->approve(
//                 $leadUuid,
//                 $validated['stage_code'],
//                 $validated['remarks'] ?? null
//             );

//             return response()->json([
//                 'success' => true,
//                 'status_code' => 200,
//                 'code' => 'WORKFLOW_APPROVED',
//                 'message' =>
//                     'Stage approved and lead moved to next stage.',
//                 'request_id' => app('request_id'),
//                 'data' => $journey->load([
//                     'workflowStage',
//                     'workflowStatus',
//                     'previousStage',
//                     'user',
//                 ]),
//             ]);
//         } catch (Throwable $e) {
//             return $this->errorResponse($e);
//         }
//     }

//     public function reject(
//         Request $request,
//         string $leadUuid,
//         BLDecisionService $service
//     ): JsonResponse {
//         try {
//             $validated = $request->validate([
//                 'stage_code' => [
//                     'required',
//                     'string',
//                     Rule::exists(
//                         'b_l_workflow_stages',
//                         'stage_code'
//                     ),
//                 ],
//                 'remarks' => 'required|string|min:5|max:2000',
//             ]);

//             $journey = $service->reject(
//                 $leadUuid,
//                 $validated['stage_code'],
//                 $validated['remarks']
//             );

//             return response()->json([
//                 'success' => true,
//                 'status_code' => 200,
//                 'code' => 'LEAD_REJECTED',
//                 'message' => 'Lead rejected successfully.',
//                 'request_id' => app('request_id'),
//                 'data' => $journey->load([
//                     'workflowStage',
//                     'workflowStatus',
//                     'previousStage',
//                     'user',
//                 ]),
//             ]);
//         } catch (Throwable $e) {
//             return $this->errorResponse($e);
//         }
//     }

//     public function reopen(
//         Request $request,
//         string $leadUuid,
//         BLDecisionService $service
//     ): JsonResponse {
//         try {
//             $validated = $request->validate([
//                 'remarks' =>
//                     'required|string|min:5|max:2000',
//             ]);

//             $journey = $service->reopen(
//                 $leadUuid,
//                 $validated['remarks']
//             );

//             return response()->json([
//                 'success' => true,
//                 'status_code' => 200,
//                 'code' => 'LEAD_REOPENED',
//                 'message' => 'Lead reopened successfully.',
//                 'request_id' => app('request_id'),
//                 'data' => $journey->load([
//                     'workflowStage',
//                     'workflowStatus',
//                     'previousStage',
//                     'user',
//                 ]),
//             ]);
//         } catch (Throwable $e) {
//             return $this->errorResponse($e);
//         }
//     }

//     public function state(
//         string $leadUuid,
//         BLDecisionService $service
//     ): JsonResponse {
//         try {
//             return response()->json([
//                 'success' => true,
//                 'status_code' => 200,
//                 'code' => 'SUCCESS',
//                 'message' => 'Lead workflow state fetched.',
//                 'request_id' => app('request_id'),
//                 'data' => $service->state($leadUuid),
//             ]);
//         } catch (Throwable $e) {
//             return $this->errorResponse($e);
//         }
//     }

//     private function errorResponse(Throwable $e): JsonResponse
//     {
//         $status = method_exists($e, 'status')
//             ? $e->status
//             : 500;

//         return response()->json([
//             'success' => false,
//             'status_code' => $status,
//             'code' => $status === 422
//                 ? 'VALIDATION_ERROR'
//                 : 'SERVER_ERROR',
//             'message' => $e->getMessage(),
//             'request_id' => app('request_id'),
//             'data' => null,
//         ], $status);
//     }
// }