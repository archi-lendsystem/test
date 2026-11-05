<?php

namespace Modules\BLMyTask\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\BLLeadAssignment\Models\BLLeadAssignment;
use Modules\BLLeadCreation\Models\BLLeadCreation;
use Modules\BLMyTask\Transformers\BLMyTaskCollection;
use Modules\BLMyTask\Transformers\BLMyTaskResource;

class BLMyTaskController extends Controller
{
      public function index(Request $request): JsonResponse
    {
        try {

            $authUser = $request->user();

            if (!$authUser) {
                return $this->errorResponse(401, 'UNAUTHENTICATED', 'Unauthenticated. Please login first.');
            }

            $validated = $request->validate([
                'search'        => 'nullable|string|max:255',
                'lead_id'       => 'nullable|string|max:255',
                'mobile_number' => 'nullable|string|max:20',
            ]);

            $search       = $validated['search'] ?? null;
            $leadId       = $validated['lead_id'] ?? null;
            $mobileNumber = $validated['mobile_number'] ?? null;

          

            $roleValues = [
                $authUser->role ?? null,
                $authUser->user_role ?? null,
                $authUser->role_name ?? null,
            ];

            if (method_exists($authUser, 'getRoleNames')) {
                $roleValues = array_merge(
                    $roleValues,
                    $authUser->getRoleNames()->toArray()
                );
            }

            if (method_exists($authUser, 'roles')) {
                $roles = $authUser->roles()->get();

                foreach ($roles as $role) {
                    $roleValues[] = $role->name ?? null;
                    $roleValues[] = $role->display_name ?? null;
                    $roleValues[] = $role->code ?? null;
                }
            } elseif ($authUser->relationLoaded('roles') && $authUser->roles) {
                foreach ($authUser->roles as $role) {
                    $roleValues[] = $role->name ?? null;
                    $roleValues[] = $role->display_name ?? null;
                    $roleValues[] = $role->code ?? null;
                }
            }

            $normalizedRoles = collect($roleValues)
                ->filter(function ($role) {
                    return is_string($role) || is_numeric($role);
                })
                ->map(function ($role) {
                    return Str::of((string) $role)
                        ->lower()
                        ->trim()
                        ->replace([' ', '-'], '_')
                        ->toString();
                })
                ->filter()
                ->unique()
                ->values();

            $adminRoles = [
                'administrative',
                'super_administrative',
            ];

            $canSeeAllAssignedLeads = $normalizedRoles
                ->intersect($adminRoles)
                ->isNotEmpty();

        

            $assignmentsQuery = BLLeadAssignment::query()
                ->with([
                    'assignedByUser:id,user_name,user_email',
                    'assignedToUser:id,user_name,user_email',
                ])
                ->whereNotNull('lead_uuid');

            if (!$canSeeAllAssignedLeads) {
                $assignmentsQuery
                    ->where(function ($q) use ($authUser) {
                        $q->where('assigned_to_user_id', $authUser->id)
                            ->orWhere('assigned_to', $authUser->id);
                    })
                    ->where('is_active', true)
                    ->where(function ($query) {
                        $query->where('assignment_status', 'ACTIVE')
                            ->orWhereNull('assignment_status');
                    });
            }

            $assignments = $assignmentsQuery
                ->latest('id')
                ->get()
                ->unique('lead_uuid')
                ->keyBy('lead_uuid');

       

            if ($assignments->isEmpty()) {
                return $this->successResponse('My tasks fetched successfully.');
            }

            $leadUuids = $assignments->keys()->filter()->values();

         

            $tasks = BLLeadCreation::query()
                ->select([
                    'id',
                    'uuid',
                    'lead_id',
                    'created_by',
                    'loan_type',
                    'customer_type',
                    'sub_loan_type',
                    'mobile_number',
                    'loan_amount',
                    'loan_tenur',
                    'created_at',
                    'updated_at',
                ])

                ->whereIn('uuid', $leadUuids)

                ->with([

                    'currentJourney' => function ($query) {

                        $query->select([
                            'b_l_journeys.id',
                            'b_l_journeys.lead_id',
                            'b_l_journeys.lead_uuid',
                            'b_l_journeys.workflow_stage_id',
                            'b_l_journeys.workflow_status_id',
                            'b_l_journeys.previous_stage_id',
                            'b_l_journeys.stage',
                            'b_l_journeys.status',
                            'b_l_journeys.team',
                            'b_l_journeys.action_type',
                            'b_l_journeys.transition_type',
                            'b_l_journeys.performed_by_role',
                            'b_l_journeys.action_performed',
                            'b_l_journeys.remarks',
                            'b_l_journeys.user_id',
                            'b_l_journeys.user_name',
                            'b_l_journeys.is_latest',
                            'b_l_journeys.created_at',
                            'b_l_journeys.updated_at',
                        ]);
                    },

                    'currentJourney.workflowStage:id,stage_name,stage_code,team_name',

                    'currentJourney.workflowStatus:id,status_name,status_code',

                    'applicantDetails' => function ($query) {

                        $query->select([
                            'id',
                            'lead_uuid',
                            'lead_id',
                            'applicant_id',
                            'applicant_type',
                            'full_name',
                            'alternate_contact_number',
                        ]);
                    },
                ])

                ->when($search, function ($query) use ($search) {

                    $query->where(function ($q) use ($search) {

                        $q->where('lead_id', 'LIKE', "%{$search}%")
                            ->orWhere('mobile_number', 'LIKE', "%{$search}%")
                            ->orWhere('loan_type', 'LIKE', "%{$search}%")
                            ->orWhere('customer_type', 'LIKE', "%{$search}%")
                            ->orWhere('sub_loan_type', 'LIKE', "%{$search}%")

                            ->orWhereHas('applicantDetails', function ($applicantQuery) use ($search) {

                                $applicantQuery->where('full_name', 'LIKE', "%{$search}%")
                                    ->orWhere('alternate_contact_number', 'LIKE', "%{$search}%")
                                    ->orWhere('applicant_type', 'LIKE', "%{$search}%");
                            });
                    });
                })

                ->when($leadId, function ($query) use ($leadId) {
                    $query->where('lead_id', 'LIKE', "%{$leadId}%");
                })

                ->when($mobileNumber, function ($query) use ($mobileNumber) {

                    $query->where(function ($q) use ($mobileNumber) {

                        $q->where('mobile_number', 'LIKE', "%{$mobileNumber}%")
                            ->orWhereHas('applicantDetails', function ($applicantQuery) use ($mobileNumber) {

                                $applicantQuery->where('alternate_contact_number', 'LIKE', "%{$mobileNumber}%");
                            });
                    });
                })

                ->get();

       

            $assignmentOrder = $leadUuids->flip();

            $tasks = $tasks->sortBy(function (BLLeadCreation $task) use ($assignmentOrder) {
                return $assignmentOrder[$task->uuid] ?? PHP_INT_MAX;
            })->values();
         
          
      

            return (new BLMyTaskCollection($tasks, $assignments))
                ->response();

        } catch (ValidationException $e) {

            return $this->errorResponse(
                422,
                'VALIDATION_ERROR',
                'Validation failed.',
                $e->errors()
            );

        } catch (\Throwable $e) {

            return $this->errorResponse(
                500,
                'SERVER_ERROR',
                app()->environment('local')
                    ? $e->getMessage()
                    : 'Something went wrong.',

                app()->environment('local')
                    ? [
                        'exception' => get_class($e),
                        'message'   => $e->getMessage(),
                        'file'      => $e->getFile(),
                        'line'      => $e->getLine(),
                    ]
                    : null
            );
        }
    }



    private function successResponse(
        string $message,
        array  $data       = [],
        int    $httpStatus = 200
    ): JsonResponse {
        return response()->json([
            'success'     => true,
            'status_code' => $httpStatus,
            'code'        => 'SUCCESS',
            'message'     => $message,
            'request_id'  => (string) Str::uuid(),
            'timestamp'   => now()->toIso8601String(),
            'count'       => count($data),
            'data'        => $data,
        ], $httpStatus);
    }

    private function errorResponse(
        int    $httpStatus,
        string $code,
        string $message,
        ?array $errors = null
    ): JsonResponse {
        return response()->json([
            'success'     => false,
            'status_code' => $httpStatus,
            'code'        => $code,
            'message'     => $message,
            'request_id'  => (string) Str::uuid(),
            'timestamp'   => now()->toIso8601String(),
            'count'       => 0,
            'data'        => [],
            'errors'      => $errors,
        ], $httpStatus);
    }
}
