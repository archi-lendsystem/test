<?php



// namespace Modules\BLLeadAssignment\Http\Controllers;

// use App\Http\Controllers\Controller;
// use Illuminate\Database\Eloquent\ModelNotFoundException;
// use Illuminate\Http\JsonResponse;
// use Illuminate\Http\Request;
// use Illuminate\Support\Facades\DB;
// use Illuminate\Support\Facades\Schema;
// use Illuminate\Validation\ValidationException;
// use Modules\BLLeadAssignment\Models\BLLeadAssignment;
// use Modules\BLLeadCreation\Models\BLLeadCreation;
// use Modules\BLWorkflow\Models\BLStageRolePermission;
// use Modules\BLWorkflow\Services\BLWorkflowService;
// use Modules\UserManagement\Models\User;
// use Throwable;

// class BLLeadAssignmentController extends Controller
// {
//     public function __construct(
//         private readonly BLWorkflowService $workflowService
//     ) {}

   
//     private const MATRIX = [
//         'CREDIT_CHECKER'       => ['CREDIT_MAKER', 'CREDIT_CHECKER', 'ADMINISTRATIVE', 'SUPER_ADMINISTRATIVE', 'OPS_CHECKER'],
//         'CREDIT_MAKER'         => ['CREDIT_MAKER', 'CREDIT_CHECKER'],
//         'OPS_CHECKER'          => ['OPS_MAKER', 'OPS_CHECKER', 'ADMINISTRATIVE', 'SUPER_ADMINISTRATIVE', 'CREDIT_CHECKER'],
//         'OPS_MAKER'            => ['OPS_CHECKER'],
//         'ADMINISTRATIVE'       => ['*'],
//         'SUPER_ADMINISTRATIVE' => ['*'],
//     ];

  
//     private const NON_EMPLOYEE_ROLE_CODES = [
//         'PARTNER_ADMINISTRATIVE',
//         'PARTNER_SUB_USER',
//     ];

//     private const EMPLOYEE_USER_TYPE_ID = 1;

   
//     public function index(Request $request): JsonResponse
//     {
//         try {
//             $validated = $request->validate([
//                 'lead_uuid' => 'nullable|uuid|exists:b_l_lead_creations,uuid',
//                 'lead_id'   => 'nullable|string',
//                 'is_active' => 'nullable|in:0,1,true,false',
//                 'per_page'  => 'nullable|integer|min:1|max:100',
//             ]);

//             $assignments = BLLeadAssignment::query()
//                 ->with(['assignedByUser', 'assignedToUser'])
//                 ->when($validated['lead_uuid'] ?? null, fn ($q, $v) => $q->where('lead_uuid', $v))
//                 ->when($validated['lead_id']   ?? null, fn ($q, $v) => $q->where('lead_id', $v))
//                 ->when(
//                     array_key_exists('is_active', $validated),
//                     fn ($q) => $q->where('is_active', filter_var($validated['is_active'], FILTER_VALIDATE_BOOLEAN))
//                 )
//                 ->latest('id')
//                 ->paginate($validated['per_page'] ?? 15);

//             return $this->success($assignments, 'Assignment list fetched successfully.');
//         } catch (ValidationException $e) {
//             return $this->error('Validation failed.', $e->errors(), 422, 'VALIDATION_ERROR');
//         } catch (Throwable $e) {
//             return $this->serverError($e);
//         }
//     }


//     public function show(int $id): JsonResponse
//     {
//         try {
//             $assignment = BLLeadAssignment::query()
//                 ->with(['assignedByUser', 'assignedToUser'])
//                 ->findOrFail($id);

//             return $this->success($assignment, 'Assignment fetched successfully.');
//         } catch (ModelNotFoundException) {
//             return $this->error('Assignment not found.', null, 404, 'ASSIGNMENT_NOT_FOUND');
//         } catch (Throwable $e) {
//             return $this->serverError($e);
//         }
//     }


//     public function store(Request $request): JsonResponse
//     {
//         try {
//             $validated = $request->validate([
//                 'lead_uuid'           => 'required|uuid|exists:b_l_lead_creations,uuid',
//                 'lead_id'             => 'nullable|string',
//                 'assigned_to_user_id' => 'nullable|integer|exists:users,id',
//                 'assigned_to'         => 'nullable|integer|exists:users,id',
//                 'assigned_role'       => 'nullable|string|max:255',
//                 'remarks'             => 'nullable|string',
//                 'remark'              => 'nullable|string',
//             ]);

//             $authUser = $request->user();

//             if (!$this->hasBlAccess($authUser)) {
//                 return $this->error(
//                     'You do not have permission to perform this action.',
//                     null, 403, 'PERMISSION_DENIED'
//                 );
//             }

//             $lead = BLLeadCreation::query()->where('uuid', $validated['lead_uuid'])->firstOrFail();

//             $assignedToUserId = $validated['assigned_to_user_id'] ?? $validated['assigned_to'] ?? null;
//             if (!$assignedToUserId) {
//                 return $this->error(
//                     'Please select a user to assign.',
//                     ['assigned_to_user_id' => ['Please select a user.']],
//                     422, 'VALIDATION_ERROR'
//                 );
//             }

//             $assignedTo = User::query()->with('roles')->findOrFail($assignedToUserId);
//             $authUser?->loadMissing('roles');

    
//             $currentData = $this->workflowService->getLeadCurrentData($lead->uuid);
//             if (!$currentData['current_journey']) {
//                 $this->workflowService->initiateLead(
//                     $lead->uuid, $authUser, 'DRAFT',
//                     'Workflow auto-initiated before assignment.', $request
//                 );
//                 $currentData = $this->workflowService->getLeadCurrentData($lead->uuid);
//             }

//             $isAdmin           = $this->isAdmin($authUser);
//             $isFirstAssignment = !BLLeadAssignment::query()->where('lead_uuid', $lead->uuid)->exists();

//             $activeAssignment  = BLLeadAssignment::query()
//                 ->where('lead_uuid', $lead->uuid)
//                 ->where('is_active', true)
//                 ->first();

//             $currentHolderId = $activeAssignment
//                 ? ($activeAssignment->assigned_to_user_id ?? $activeAssignment->assigned_to)
//                 : null;

   
//             if (!$isFirstAssignment && $activeAssignment && !$isAdmin) {
//                 if ((int) $currentHolderId !== (int) $authUser?->id) {
//                     return $this->error(
//                         'Only the currently assigned user can re-assign this lead.',
//                         null, 403, 'ONLY_CURRENT_ASSIGNEE_CAN_REASSIGN'
//                     );
//                 }
//             }

//             if (!$isAdmin && (int) $assignedTo->id === (int) $authUser?->id) {
//                 return $this->error(
//                     'You cannot assign this lead to yourself.',
//                     ['assigned_to_user_id' => ['You cannot assign this lead to yourself.']],
//                     422, 'CANNOT_ASSIGN_TO_SELF'
//                 );
//             }

//             if (!$isAdmin && $activeAssignment && (int) $currentHolderId === (int) $assignedTo->id) {
//                 return $this->error(
//                     'This lead is already assigned to this user.',
//                     ['assigned_to_user_id' => ['This user is already the current assignee.']],
//                     422, 'ALREADY_CURRENT_ASSIGNEE'
//                 );
//             }

   
//             [$ok, $code, $message] = $this->canAssign(
//                 $authUser, $assignedTo, $lead, $isFirstAssignment, $isAdmin
//             );
//             if (!$ok) {
//                 return $this->error(
//                     $message,
//                     ['assigned_to_user_id' => [$message]],
//                     422, $code
//                 );
//             }

        
//             BLLeadAssignment::query()
//                 ->where('lead_uuid', $lead->uuid)
//                 ->where('is_active', true)
//                 ->update($this->onlyExistingColumns('b_l_lead_assignments', [
//                     'is_active'         => false,
//                     'assignment_status' => 'REASSIGNED',
//                     'completed_at'      => now(),
//                 ]));

//             $assignedRole = $validated['assigned_role'] ?? $this->primaryRoleCode($assignedTo);
//             $remarks      = $validated['remarks'] ?? $validated['remark'] ?? null;
//             $historyCount = BLLeadAssignment::query()->where('lead_uuid', $lead->uuid)->count();

//             $payload = [
//                 'lead_uuid'           => $lead->uuid,
//                 'lead_id'             => $lead->lead_id ?? $validated['lead_id'] ?? null,
//                 'assigned_by'         => $authUser?->id,
//                 'assigned_to'         => $assignedTo->id,
//                 'assigned_to_role'    => $assignedRole,
//                 'step_number'         => $historyCount + 1,
//                 'type'                => 'assign',
//                 'remark'              => $remarks,
//                 'assigned_by_user_id' => $authUser?->id,
//                 'assigned_to_user_id' => $assignedTo->id,
//                 'assigned_role'       => $assignedRole,
//                 'assignment_status'   => 'ACTIVE',
//                 'is_active'           => true,
//                 'assigned_at'         => now(),
//                 'remarks'             => $remarks,
//             ];

//             $assignment = DB::transaction(function () use ($payload) {
//                 return BLLeadAssignment::query()->create(
//                     $this->onlyExistingColumns('b_l_lead_assignments', $payload)
//                 );
//             });

//             // Only re-affirm a status change when we actually know the
//             // current one. Many real stages ("Lead Submitted", "Applicant
//             // Details", ...) don't resolve against this generic engine's
//             // seeded stage names, so current_status comes back null for
//             // them — falling back to a guessed 'DRAFT' here previously
//             // reset the lead's displayed stage back to Lead Created on
//             // every assignment. The assignment itself is already recorded
//             // above regardless of whether this fires.
//             if ($currentData['current_status']) {
//                 $this->workflowService->changeStatus(
//                     $lead->uuid,
//                     $currentData['current_status']->status_code,
//                     $assignedTo,
//                     $remarks ?? 'Lead assigned to ' . ($assignedTo->user_name ?? 'user'),
//                     $request,
//                     false
//                 );
//             }

//             return $this->success(
//                 $assignment->load(['assignedByUser', 'assignedToUser']),
//                 'Lead assigned successfully.',
//                 201, 'LEAD_ASSIGNED_SUCCESSFULLY'
//             );
//         } catch (ValidationException $e) {
//             return $this->error('Validation failed.', $e->errors(), 422, 'VALIDATION_ERROR');
//         } catch (ModelNotFoundException) {
//             return $this->error('Lead or user not found.', null, 404, 'NOT_FOUND');
//         } catch (Throwable $e) {
//             return $this->serverError($e);
//         }
//     }

 
//     public function checkPermission(Request $request, string $lead_uuid): JsonResponse
//     {
//         try {
//             $authUser = $request->user();
//             if (!$authUser) {
//                 return $this->error('Unauthenticated.', null, 401, 'UNAUTHENTICATED');
//             }

//             $lead = BLLeadCreation::query()->where('uuid', $lead_uuid)->firstOrFail();

//             $isAssignedBy = BLLeadAssignment::query()
//                 ->where('lead_uuid', $lead->uuid)->where('is_active', true)
//                 ->where(fn ($q) => $q->where('assigned_by_user_id', $authUser->id)->orWhere('assigned_by', $authUser->id))
//                 ->exists();

//             $isAssignedTo = BLLeadAssignment::query()
//                 ->where('lead_uuid', $lead->uuid)->where('is_active', true)
//                 ->where(fn ($q) => $q->where('assigned_to_user_id', $authUser->id)->orWhere('assigned_to', $authUser->id))
//                 ->exists();

//             $currentData  = $this->workflowService->getLeadCurrentData($lead->uuid);
//             $currentStage = $currentData['current_stage'] ?? null;

//             if ($isAssignedBy && !$isAssignedTo) {
//                 return $this->success([
//                     'lead_uuid'     => $lead->uuid,
//                     'user_id'       => $authUser->id,
//                     'user_role'     => $this->primaryRoleCode($authUser),
//                     'relation'      => 'assigned_by',
//                     'current_stage' => $currentStage?->stage_code ?? null,
//                     'permissions'   => $this->viewOnlyPermissions(),
//                     'note'          => 'You assigned this lead. You can only view it now.',
//                 ], 'Permission fetched successfully.');
//             }

//             $relation         = $isAssignedTo ? 'assigned_to' : 'none';
//             $stagePermissions = $this->getStagePermissionsForUser($currentStage, $authUser);

//             return $this->success([
//                 'lead_uuid'     => $lead->uuid,
//                 'user_id'       => $authUser->id,
//                 'user_role'     => $this->primaryRoleCode($authUser),
//                 'relation'      => $relation,
//                 'current_stage' => $currentStage?->stage_code ?? null,
//                 'permissions'   => $stagePermissions,
//                 'note'          => 'Permissions based on your role at the current workflow stage.',
//             ], 'Permission fetched successfully.');
//         } catch (ModelNotFoundException) {
//             return $this->error('Lead not found.', null, 404, 'LEAD_NOT_FOUND');
//         } catch (Throwable $e) {
//             return $this->serverError($e);
//         }
//     }


//     public function eligibleUsersForAssignment(Request $request, string $lead_uuid): JsonResponse
//     {
//         try {
//             $request->validate([
//                 'role_name' => 'nullable|string|max:255',
//             ]);

//             $authUser = $request->user();
//             if (!$authUser) {
//                 return $this->error('Unauthenticated.', null, 401, 'UNAUTHENTICATED');
//             }
//             $authUser->loadMissing('roles');

//             $lead = BLLeadCreation::query()->where('uuid', $lead_uuid)->firstOrFail();

//             $currentData = $this->workflowService->getLeadCurrentData($lead->uuid);
//             if (!$currentData['current_journey']) {
//                 // Only initiate when there is truly no journey yet. A journey
//                 // can exist with a stage whose free-text name (e.g. "Lead
//                 // Submitted", "Applicant Details") doesn't match this
//                 // generic engine's seeded stage vocabulary — that's a
//                 // resolution gap, not evidence the workflow never started,
//                 // and re-initiating here was resetting real progress back
//                 // to "Lead Created / Draft" on every call.
//                 $this->workflowService->initiateLead(
//                     $lead->uuid, $authUser, 'DRAFT',
//                     'Workflow auto-initiated while fetching eligible users for assignment.', $request
//                 );
//                 $currentData = $this->workflowService->getLeadCurrentData($lead->uuid);
//             }

//             $currentStage = $currentData['current_stage'];

//             $isAdmin           = $this->isAdmin($authUser);
//             $isFirstAssignment = !BLLeadAssignment::query()->where('lead_uuid', $lead->uuid)->exists();

//             $activeAssignment = BLLeadAssignment::query()
//                 ->where('lead_uuid', $lead->uuid)->where('is_active', true)->first();
//             $currentHolderId  = $activeAssignment
//                 ? ($activeAssignment->assigned_to_user_id ?? $activeAssignment->assigned_to)
//                 : null;

        
//             if (!$isFirstAssignment && $activeAssignment && !$isAdmin
//                 && (int) $currentHolderId !== (int) $authUser->id) {
//                 return $this->success([
//                     'lead_uuid'           => $lead->uuid,
//                     'current_stage'       => $currentStage,
//                     'eligible_roles'      => [],
//                     'users'               => [],
//                     'is_first_assignment' => false,
//                     'is_locked'           => true,
//                     'lock_reason'         => 'Only the current assignee can reassign this lead.',
//                 ], 'You are not the current assignee for this lead.');
//             }

  
//             $includeCreator = false;

//             if ($isAdmin) {
//                 $allowedCodes = $this->allRoleCodes();
//             } elseif ($isFirstAssignment) {
//                 $allowedCodes = ['CREDIT_CHECKER'];
//             } else {
//                 $allowedCodes   = $this->allowedTargetRolesFor($authUser);
//                 $includeCreator = $this->userHasRole($authUser, 'CREDIT_CHECKER');
//                 if ($allowedCodes === ['*']) {
//                     $allowedCodes = $this->allRoleCodes();
//                 }

//                 if ($this->isLeadCreator($authUser, $lead)) {
//                     $allowedCodes[] = 'CREDIT_CHECKER';
//                     $allowedCodes = array_values(array_unique($allowedCodes));
//                 }
//             }

      
//             if ($request->filled('role_name')) {
//                 $wanted = strtoupper(str_replace([' ', '-'], '_', trim($request->input('role_name'))));
//                 $allowedCodes = array_values(array_intersect($allowedCodes, [$wanted]));
//             }

//             $users = $this->fetchUsersByRoleCodes($allowedCodes);

     
//             if ($includeCreator && $lead->created_by) {
//                 $alreadyListed = $users->contains(fn ($u) => (int) $u['user_id'] === (int) $lead->created_by);
//                 if (!$alreadyListed) {
//                     $creator = User::query()->with('roles')->find($lead->created_by);
//                     if ($creator) {
//                         $users = $users->push($this->mapUser($creator, true))->values();
//                     }
//                 }
//             }


//             if (!$isAdmin && $authUser?->id) {
//                 $users = $users
//                     ->reject(fn ($u) => (int) $u['user_id'] === (int) $authUser->id)
//                     ->values();
//             }

//             return $this->success([
//                 'lead_uuid'           => $lead->uuid,
//                 'current_stage'       => $currentStage,
//                 'eligible_roles'      => $allowedCodes,
//                 'users'               => $users,
//                 'is_first_assignment' => $isFirstAssignment,
//                 'is_locked'           => false,
//             ], 'Eligible users fetched successfully.');
//         } catch (ValidationException $e) {
//             return $this->error('Validation failed.', $e->errors(), 422, 'VALIDATION_ERROR');
//         } catch (ModelNotFoundException) {
//             return $this->error('Lead not found.', null, 404, 'LEAD_NOT_FOUND');
//         } catch (Throwable $e) {
//             return $this->serverError($e);
//         }
//     }

//     public function complete(Request $request, int $id): JsonResponse
//     {
//         try {
//             $validated  = $request->validate(['remarks' => 'nullable|string']);
//             $assignment = BLLeadAssignment::query()->findOrFail($id);

//             $assignment->update($this->onlyExistingColumns('b_l_lead_assignments', [
//                 'assignment_status' => 'COMPLETED',
//                 'is_active'         => false,
//                 'completed_at'      => now(),
//                 'remarks'           => $validated['remarks'] ?? $assignment->remarks ?? $assignment->remark ?? null,
//             ]));

//             return $this->success(
//                 $assignment->fresh(['assignedByUser', 'assignedToUser']),
//                 'Assignment completed successfully.'
//             );
//         } catch (ValidationException $e) {
//             return $this->error('Validation failed.', $e->errors(), 422, 'VALIDATION_ERROR');
//         } catch (ModelNotFoundException) {
//             return $this->error('Assignment not found.', null, 404, 'ASSIGNMENT_NOT_FOUND');
//         } catch (Throwable $e) {
//             return $this->serverError($e);
//         }
//     }


//     public function cancel(Request $request, int $id): JsonResponse
//     {
//         try {
//             $validated  = $request->validate(['remarks' => 'nullable|string']);
//             $assignment = BLLeadAssignment::query()->findOrFail($id);

//             $assignment->update($this->onlyExistingColumns('b_l_lead_assignments', [
//                 'assignment_status' => 'CANCELLED',
//                 'is_active'         => false,
//                 'completed_at'      => now(),
//                 'remarks'           => $validated['remarks'] ?? $assignment->remarks ?? $assignment->remark ?? null,
//             ]));

//             return $this->success(
//                 $assignment->fresh(['assignedByUser', 'assignedToUser']),
//                 'Assignment cancelled successfully.'
//             );
//         } catch (ValidationException $e) {
//             return $this->error('Validation failed.', $e->errors(), 422, 'VALIDATION_ERROR');
//         } catch (ModelNotFoundException) {
//             return $this->error('Assignment not found.', null, 404, 'ASSIGNMENT_NOT_FOUND');
//         } catch (Throwable $e) {
//             return $this->serverError($e);
//         }
//     }


//     public function myAssignmentView(Request $request, string $lead_uuid): JsonResponse
//     {
//         try {
//             $authUser = $request->user();
//             if (!$authUser) {
//                 return $this->error('Unauthenticated.', null, 401, 'UNAUTHENTICATED');
//             }

//             $lead = BLLeadCreation::query()->where('uuid', $lead_uuid)->firstOrFail();

//             $myAssignment = BLLeadAssignment::query()
//                 ->with(['assignedByUser', 'assignedToUser'])
//                 ->where('lead_uuid', $lead->uuid)
//                 ->where(fn ($q) => $q->where('assigned_by_user_id', $authUser->id)->orWhere('assigned_by', $authUser->id))
//                 ->latest('id')->first();

//             $assignedToMe = BLLeadAssignment::query()
//                 ->with(['assignedByUser'])
//                 ->where('lead_uuid', $lead->uuid)->where('is_active', true)
//                 ->where(fn ($q) => $q->where('assigned_to_user_id', $authUser->id)->orWhere('assigned_to', $authUser->id))
//                 ->first();

//             if ($assignedToMe && !$myAssignment) {
//                 return $this->success([
//                     'lead_uuid'   => $lead->uuid,
//                     'lead_id'     => $lead->lead_id ?? null,
//                     'my_relation' => 'assigned_to_me',
//                     'message'     => 'This lead is assigned to you.',
//                     'assigned_by' => [
//                         'id'   => $assignedToMe->assignedByUser?->id,
//                         'name' => $assignedToMe->assignedByUser?->user_name,
//                         'role' => $this->primaryRoleCode($assignedToMe->assignedByUser),
//                     ],
//                     'assigned_to' => null,
//                     'assigned_at' => $assignedToMe->assigned_at,
//                     'remarks'     => $assignedToMe->remarks ?? $assignedToMe->remark ?? null,
//                 ], 'My assignment view fetched successfully.');
//             }

//             if ($myAssignment) {
//                 return $this->success([
//                     'lead_uuid'   => $lead->uuid,
//                     'lead_id'     => $lead->lead_id ?? null,
//                     'my_relation' => 'i_assigned',
//                     'message'     => 'You assigned this lead to ' . ($myAssignment->assignedToUser?->user_name ?? 'someone') . '.',
//                     'assigned_by' => [
//                         'id'   => $authUser->id,
//                         'name' => $authUser->user_name,
//                         'role' => $this->primaryRoleCode($authUser),
//                     ],
//                     'assigned_to' => [
//                         'id'          => $myAssignment->assignedToUser?->id,
//                         'name'        => $myAssignment->assignedToUser?->user_name,
//                         'role'        => $this->primaryRoleCode($myAssignment->assignedToUser),
//                         'designation' => $myAssignment->assignedToUser?->user_designation ?? null,
//                         'email'       => $myAssignment->assignedToUser?->user_email ?? null,
//                         'mobile'      => $myAssignment->assignedToUser?->user_mobile_number ?? null,
//                     ],
//                     'assigned_at' => $myAssignment->assigned_at,
//                     'remarks'     => $myAssignment->remarks ?? $myAssignment->remark ?? null,
//                 ], 'My assignment view fetched successfully.');
//             }

//             return $this->success([
//                 'lead_uuid'   => $lead->uuid,
//                 'lead_id'     => $lead->lead_id ?? null,
//                 'my_relation' => 'none',
//                 'message'     => 'You have no assignment activity on this lead.',
//                 'assigned_by' => null,
//                 'assigned_to' => null,
//                 'assigned_at' => null,
//                 'remarks'     => null,
//             ], 'My assignment view fetched successfully.');
//         } catch (ModelNotFoundException) {
//             return $this->error('Lead not found.', null, 404, 'LEAD_NOT_FOUND');
//         } catch (Throwable $e) {
//             return $this->serverError($e);
//         }
//     }

//     private function canAssign($actor, User $assignedTo, $lead, bool $isFirst, bool $isAdmin): array
//     {
//         if ($isAdmin) {
//             return [true, '', ''];
//         }

//         if ($isFirst) {
//             if (!$this->isCreditChecker($assignedTo)) {
//                 return [false, 'FIRST_ASSIGNMENT_MUST_BE_CREDIT_CHECKER',
//                     'The first assignment can only be made to a Credit Checker.'];
//             }
//             return [true, '', ''];
//         }

   
//         if ($this->isCreditChecker($actor)
//             && $lead->created_by
//             && (int) $lead->created_by === (int) $assignedTo->id) {
//             return [true, '', ''];
//         }

//         $allowed = $this->allowedTargetRolesFor($actor);

//         if ($allowed === ['*']) {
//             return [true, '', ''];
//         }

  
//         if ($this->isLeadCreator($actor, $lead)) {
//             $allowed[] = 'CREDIT_CHECKER';
//             $allowed = array_values(array_unique($allowed));
//         }

//         if (empty($allowed)) {
//             return [false, 'ASSIGNMENT_TRANSITION_NOT_ALLOWED',
//                 'Your current role is not permitted to assign this lead.'];
//         }

//         $targetCodes = $this->userRoleCodes($assignedTo);

//         if (!empty(array_intersect($allowed, $targetCodes))) {
//             return [true, '', ''];
//         }

//         return [false, 'ASSIGNMENT_TRANSITION_NOT_ALLOWED',
//             'You are not allowed to assign this lead to the selected user from your current role.'];
//     }

//     private function isLeadCreator($user, $lead): bool
//     {
//         return $user
//             && $lead
//             && $lead->created_by
//             && (int) $lead->created_by === (int) $user->id;
//     }


//     private function allowedTargetRolesFor($user): array
//     {
//         $allowed = [];
//         foreach ($this->userRoleCodes($user) as $code) {
//             if (!isset(self::MATRIX[$code])) {
//                 continue;
//             }
//             if (self::MATRIX[$code] === ['*']) {
//                 return ['*'];
//             }
//             $allowed = array_merge($allowed, self::MATRIX[$code]);
//         }
//         return array_values(array_unique($allowed));
//     }


//     private function userRoleCodes($user): array
//     {
//         if (!$user) {
//             return [];
//         }

//         try {
//             $user->loadMissing('roles');
//         } catch (Throwable) {
           
//         }

//         $codes = [];

//         if (isset($user->roles)) {
//             foreach ($user->roles as $role) {
//                 $value = $role->code ?? $role->name ?? $role->display_name ?? null;
//                 if ($value) {
//                     $codes[] = strtoupper(str_replace([' ', '-'], '_', trim($value)));
//                 }
//             }
//         }

  
//         if (empty($codes) && !empty($user->user_designation)) {
//             $codes[] = strtoupper(str_replace([' ', '-'], '_', trim($user->user_designation)));
//         }

//         return array_values(array_unique(array_filter($codes)));
//     }

//     private function userHasRole($user, string $code): bool
//     {
//         return in_array(strtoupper($code), $this->userRoleCodes($user), true);
//     }

//     private function isCreditChecker($user): bool
//     {
//         return $this->userHasRole($user, 'CREDIT_CHECKER');
//     }

//     private function isAdmin($user): bool
//     {
//         $codes = $this->userRoleCodes($user);
//         return in_array('ADMINISTRATIVE', $codes, true)
//             || in_array('SUPER_ADMINISTRATIVE', $codes, true);
//     }

//     private function primaryRoleCode($user): ?string
//     {
//         return $this->userRoleCodes($user)[0] ?? null;
//     }

  
//     private function allRoleCodes(): array
//     {
//         $codes = ['CREDIT_CHECKER', 'CREDIT_MAKER', 'OPS_CHECKER', 'OPS_MAKER', 'ADMINISTRATIVE', 'SUPER_ADMINISTRATIVE'];
//         try {
//             $codes = \Modules\UserManagement\Models\Role::query()
//                 ->pluck('code')->filter()
//                 ->map(fn ($c) => strtoupper(trim($c)))->unique()->values()->toArray();
//         } catch (Throwable) {
           
//         }

       
//         return array_values(array_diff($codes, self::NON_EMPLOYEE_ROLE_CODES));
//     }

   
//     private function fetchUsersByRoleCodes(array $roleCodes)
//     {
//         if (empty($roleCodes)) {
//             return collect();
//         }

//         $codes = collect($roleCodes)
//             ->map(fn ($c) => strtoupper(trim($c)))
//             ->reject(fn ($c) => in_array($c, self::NON_EMPLOYEE_ROLE_CODES, true))
//             ->unique()
//             ->values()
//             ->toArray();

//         if (empty($codes)) {
//             return collect();
//         }

//         return User::query()
//             ->with('roles')
//             ->when(
//                 Schema::hasColumn('users', 'user_type_id'),
//                 fn ($q) => $q->where('user_type_id', self::EMPLOYEE_USER_TYPE_ID)
//             )
//             ->whereHas('roles', function ($q) use ($codes) {
//                 $q->whereIn(DB::raw('UPPER(code)'), $codes)
//                   ->orWhereIn(DB::raw("UPPER(REPLACE(REPLACE(name, '-', '_'), ' ', '_'))"), $codes);
//             })
//             ->whereDoesntHave('roles', function ($q) {
//                 $q->whereIn(DB::raw('UPPER(code)'), self::NON_EMPLOYEE_ROLE_CODES);
//             })
//             ->when(Schema::hasColumn('users', 'is_active'), fn ($q) => $q->where('is_active', true))
//             ->orderBy(Schema::hasColumn('users', 'user_name') ? 'user_name' : 'id')
//             ->get()
//             ->map(fn ($u) => $this->mapUser($u))
//             ->values();
//     }

//     private function mapUser(User $u, bool $isCreator = false): array
//     {
//         return [
//             'user_id'     => $u->id,
//             'name'        => $u->user_name ?? null,
//             'email'       => $u->user_email ?? null,
//             'mobile'      => $u->user_mobile_number ?? null,
//             'role'        => $this->primaryRoleCode($u),
//             'designation' => $u->user_designation ?? null,
//             'is_creator'  => $isCreator,
//         ];
//     }

  
//     private function hasBlAccess($user): bool
//     {
//         if (!$user) return false;
//         if (!method_exists($user, 'products')) return true;

//         try {
//             $keyColumn = Schema::hasColumn('products', 'key') ? 'key'
//                 : (Schema::hasColumn('products', 'product_key') ? 'product_key'
//                 : (Schema::hasColumn('products', 'slug') ? 'slug' : null));

//             return $keyColumn ? $user->products()->where($keyColumn, 'bl')->exists() : true;
//         } catch (Throwable) {
//             return true;
//         }
//     }

   
//     private function getStagePermissionsForUser(?object $currentStage, $user): array
//     {
//         if (!$currentStage || !$user) return $this->noPermissions();

//         $candidates = $this->stageRoleMatchValues($user);
//         if (empty($candidates)) return $this->noPermissions();

//         $permission = BLStageRolePermission::query()
//             ->where('stage_id', $currentStage->id)
//             ->where(function ($q) use ($candidates) {
//                 $q->whereIn('role_name', $candidates)
//                   ->orWhereIn(
//                       DB::raw("UPPER(REPLACE(REPLACE(role_name, '-', '_'), ' ', '_'))"),
//                       array_map(fn ($v) => strtoupper(str_replace([' ', '-'], '_', $v)), $candidates)
//                   );
//             })
//             ->first();

//         if (!$permission) return $this->noPermissions();

//         return [
//             'can_view'            => (bool) $permission->can_view,
//             'can_edit'            => (bool) $permission->can_edit,
//             'can_assign'          => (bool) $permission->can_assign,
//             'can_change_status'   => (bool) $permission->can_change_status,
//             'can_upload_document' => (bool) $permission->can_upload_document,
//             'can_move_next'       => (bool) $permission->can_move_next,
//             'can_move_previous'   => (bool) $permission->can_move_previous,
//         ];
//     }

//     private function stageRoleMatchValues($user): array
//     {
//         $values = [];
//         try {
//             $user->loadMissing('roles');
//             foreach ($user->roles as $r) {
//                 foreach ([$r->code, $r->name, $r->display_name] as $v) {
//                     if ($v) $values[] = trim($v);
//                 }
//             }
//         } catch (Throwable) {
            
//         }
//         if (empty($values) && !empty($user->user_designation)) {
//             $values[] = trim($user->user_designation);
//         }
//         return array_values(array_unique(array_filter($values)));
//     }

//     private function viewOnlyPermissions(): array
//     {
//         return [
//             'can_view' => true, 'can_edit' => false, 'can_assign' => false,
//             'can_change_status' => false, 'can_upload_document' => false,
//             'can_move_next' => false, 'can_move_previous' => false,
//         ];
//     }

//     private function noPermissions(): array
//     {
//         return [
//             'can_view' => false, 'can_edit' => false, 'can_assign' => false,
//             'can_change_status' => false, 'can_upload_document' => false,
//             'can_move_next' => false, 'can_move_previous' => false,
//         ];
//     }

//     private function onlyExistingColumns(string $table, array $payload): array
//     {
//         return collect($payload)
//             ->filter(fn ($value, $key) => Schema::hasColumn($table, $key))
//             ->toArray();
//     }


//     private function success($data = null, string $message = 'Success', int $status = 200, string $code = 'SUCCESS'): JsonResponse
//     {
//         return response()->json([
//             'success' => true, 'status_code' => $status, 'code' => $code,
//             'message' => $message, 'data' => $data,
//         ], $status);
//     }

//     private function error(string $message, $data = null, int $status = 400, string $code = 'ERROR'): JsonResponse
//     {
//         return response()->json([
//             'success' => false, 'status_code' => $status, 'code' => $code,
//             'message' => $message, 'data' => $data,
//         ], $status);
//     }

//     private function serverError(Throwable $e): JsonResponse
//     {
//         return $this->error(
//             'Something went wrong.',
//             app()->environment('local') ? $e->getMessage() : null,
//             500, 'SERVER_ERROR'
//         );
//     }
// }




// namespace Modules\BLLeadAssignment\Http\Controllers;

// use App\Http\Controllers\Controller;
// use Illuminate\Database\Eloquent\ModelNotFoundException;
// use Illuminate\Http\JsonResponse;
// use Illuminate\Http\Request;
// use Illuminate\Support\Facades\DB;
// use Illuminate\Support\Facades\Schema;
// use Illuminate\Validation\ValidationException;
// use Modules\BLLeadAssignment\Models\BLLeadAssignment;
// use Modules\BLLeadCreation\Models\BLLeadCreation;
// use Modules\BLWorkflow\Models\BLStageRolePermission;
// use Modules\BLWorkflow\Services\BLWorkflowService;
// use Modules\UserManagement\Models\User;
// use Throwable;

// class BLLeadAssignmentController extends Controller
// {
//     public function __construct(
//         private readonly BLWorkflowService $workflowService
//     ) {}

   
//     /**
//      * Lead assignment flow. Keys/values here are role codes as normalized by
//      * userRoleCodes() (spaces and hyphens folded to underscores — so the
//      * seeded "PARTNER BRANCH_MANAGER" code is matched here as
//      * PARTNER_BRANCH_MANAGER), matching Modules/UserManagement's RoleSeeder.
//      *
//      * Employee channel:
//      *   SALES_PERSON -> BRANCH_MANAGER (first assignment only, see canAssign)
//      *   BRANCH_MANAGER -> SALES_PERSON / BRANCH_CREDIT_MANAGER / HEAD_OFFICE_RISK_CONTROL_UNIT
//      *   BRANCH_CREDIT_MANAGER -> HEAD_OFFICE_CREDIT_CHECKER / BRANCH_MANAGER
//      *   HEAD_OFFICE_CREDIT_CHECKER -> BRANCH_MANAGER / SANCTION_AUTHORIZED_PERSON
//      *   SANCTION_AUTHORIZED_PERSON -> HEAD_OFFICE_CREDIT_CHECKER / BRANCH_MANAGER
//      *   HEAD_OFFICE_RISK_CONTROL_UNIT -> BRANCH_MANAGER / ACCOUNT
//      *   ACCOUNT -> HEAD_OFFICE_RISK_CONTROL_UNIT (only)
//      *
//      * Partner channel (mirrors the employee channel, then hands off to HO credit):
//      *   PARTNER_SALES_PERSON -> PARTNER_BRANCH_MANAGER (first assignment only)
//      *   PARTNER_BRANCH_MANAGER -> PARTNER_SALES_PERSON / PARTNER_CREDIT_PERSON / HEAD_OFFICE_CREDIT_CHECKER
//      *   PARTNER_CREDIT_PERSON -> HEAD_OFFICE_CREDIT_CHECKER
//      *
//      * BRANCH_MANAGER and BRANCH_CREDIT_MANAGER targets are additionally
//      * constrained to the branch the lead belongs to — see canAssign() and
//      * fetchUsersByRoleCodes().
//      *
//      * Administrative and Super Administrative users can assign to anyone.
//      */
//     private const MATRIX = [
//         'SALES_PERSON'                   => ['BRANCH_MANAGER'],
//         'BRANCH_MANAGER'                 => ['SALES_PERSON', 'BRANCH_CREDIT_MANAGER', 'HEAD_OFFICE_RISK_CONTROL_UNIT'],
//         'BRANCH_CREDIT_MANAGER'          => ['HEAD_OFFICE_CREDIT_CHECKER', 'BRANCH_MANAGER'],
//         'HEAD_OFFICE_CREDIT_CHECKER'     => ['BRANCH_MANAGER', 'SANCTION_AUTHORIZED_PERSON'],
//         'SANCTION_AUTHORIZED_PERSON'     => ['HEAD_OFFICE_CREDIT_CHECKER', 'BRANCH_MANAGER'],
//         'HEAD_OFFICE_RISK_CONTROL_UNIT'  => ['BRANCH_MANAGER', 'ACCOUNT'],
//         'ACCOUNT'                        => ['HEAD_OFFICE_RISK_CONTROL_UNIT'],

//         'PARTNER_SALES_PERSON'           => ['PARTNER_BRANCH_MANAGER'],
//         'PARTNER_BRANCH_MANAGER'         => ['PARTNER_SALES_PERSON', 'PARTNER_CREDIT_PERSON', 'HEAD_OFFICE_CREDIT_CHECKER'],
//         'PARTNER_CREDIT_PERSON'          => ['HEAD_OFFICE_CREDIT_CHECKER'],

//         'ADMINISTRATIVE'                 => ['*'],
//         'SUPER_ADMINISTRATIVE'           => ['*'],
//     ];

//     /**
//      * First assignment on a lead is a one-time handoff from whoever punched
//      * it to their branch manager — keyed by (normalized) creator role code,
//      * valued by the one role the first assignment must go to.
//      */
//     private const FIRST_ASSIGNMENT_PAIRS = [
//         'SALES_PERSON'         => 'BRANCH_MANAGER',
//         'PARTNER_SALES_PERSON' => 'PARTNER_BRANCH_MANAGER',
//     ];

//     /**
//      * Roles whose eligible targets must additionally belong to the same
//      * branch as the lead.
//      */
//     private const BRANCH_SCOPED_ROLE_CODES = ['BRANCH_MANAGER', 'BRANCH_CREDIT_MANAGER'];

//     private const NON_EMPLOYEE_ROLE_CODES = [
//         'PARTNER_ADMINISTRATIVE',
//         'PARTNER_SUB_USER',
//     ];


//     public function index(Request $request): JsonResponse
//     {
//         try {
//             $validated = $request->validate([
//                 'lead_uuid' => 'nullable|uuid|exists:b_l_lead_creations,uuid',
//                 'lead_id'   => 'nullable|string',
//                 'is_active' => 'nullable|in:0,1,true,false',
//                 'per_page'  => 'nullable|integer|min:1|max:100',
//             ]);

//             $assignments = BLLeadAssignment::query()
//                 ->with(['assignedByUser', 'assignedToUser'])
//                 ->when($validated['lead_uuid'] ?? null, fn ($q, $v) => $q->where('lead_uuid', $v))
//                 ->when($validated['lead_id']   ?? null, fn ($q, $v) => $q->where('lead_id', $v))
//                 ->when(
//                     array_key_exists('is_active', $validated),
//                     fn ($q) => $q->where('is_active', filter_var($validated['is_active'], FILTER_VALIDATE_BOOLEAN))
//                 )
//                 ->latest('id')
//                 ->paginate($validated['per_page'] ?? 15);

//             return $this->success($assignments, 'Assignment list fetched successfully.');
//         } catch (ValidationException $e) {
//             return $this->error('Validation failed.', $e->errors(), 422, 'VALIDATION_ERROR');
//         } catch (Throwable $e) {
//             return $this->serverError($e);
//         }
//     }


//     public function show(int $id): JsonResponse
//     {
//         try {
//             $assignment = BLLeadAssignment::query()
//                 ->with(['assignedByUser', 'assignedToUser'])
//                 ->findOrFail($id);

//             return $this->success($assignment, 'Assignment fetched successfully.');
//         } catch (ModelNotFoundException) {
//             return $this->error('Assignment not found.', null, 404, 'ASSIGNMENT_NOT_FOUND');
//         } catch (Throwable $e) {
//             return $this->serverError($e);
//         }
//     }


//     public function store(Request $request): JsonResponse
//     {
//         try {
//             $validated = $request->validate([
//                 'lead_uuid'           => 'required|uuid|exists:b_l_lead_creations,uuid',
//                 'lead_id'             => 'nullable|string',
//                 'assigned_to_user_id' => 'nullable|integer|exists:users,id',
//                 'assigned_to'         => 'nullable|integer|exists:users,id',
//                 'assigned_role'       => 'nullable|string|max:255',
//                 'remarks'             => 'nullable|string',
//                 'remark'              => 'nullable|string',
//             ]);

//             $authUser = $request->user();

//             if (!$this->hasBlAccess($authUser)) {
//                 return $this->error(
//                     'You do not have permission to perform this action.',
//                     null, 403, 'PERMISSION_DENIED'
//                 );
//             }

//             $lead = BLLeadCreation::query()->where('uuid', $validated['lead_uuid'])->firstOrFail();

//             $assignedToUserId = $validated['assigned_to_user_id'] ?? $validated['assigned_to'] ?? null;
//             if (!$assignedToUserId) {
//                 return $this->error(
//                     'Please select a user to assign.',
//                     ['assigned_to_user_id' => ['Please select a user.']],
//                     422, 'VALIDATION_ERROR'
//                 );
//             }

//             $assignedTo = User::query()->with('roles')->findOrFail($assignedToUserId);
//             $authUser?->loadMissing('roles');

    
//             $currentData = $this->workflowService->getLeadCurrentData($lead->uuid);
//             if (!$currentData['current_journey']) {
//                 $this->workflowService->initiateLead(
//                     $lead->uuid, $authUser, 'DRAFT',
//                     'Workflow auto-initiated before assignment.', $request
//                 );
//                 $currentData = $this->workflowService->getLeadCurrentData($lead->uuid);
//             }

//             $isAdmin           = $this->isAdmin($authUser);
//             $isFirstAssignment = !BLLeadAssignment::query()->where('lead_uuid', $lead->uuid)->exists();

//             $activeAssignment  = BLLeadAssignment::query()
//                 ->where('lead_uuid', $lead->uuid)
//                 ->where('is_active', true)
//                 ->first();

//             $currentHolderId = $activeAssignment
//                 ? ($activeAssignment->assigned_to_user_id ?? $activeAssignment->assigned_to)
//                 : null;

   
//             if (!$isFirstAssignment && $activeAssignment && !$isAdmin) {
//                 if ((int) $currentHolderId !== (int) $authUser?->id) {
//                     return $this->error(
//                         'Only the currently assigned user can re-assign this lead.',
//                         null, 403, 'ONLY_CURRENT_ASSIGNEE_CAN_REASSIGN'
//                     );
//                 }
//             }

//             if (!$isAdmin && (int) $assignedTo->id === (int) $authUser?->id) {
//                 return $this->error(
//                     'You cannot assign this lead to yourself.',
//                     ['assigned_to_user_id' => ['You cannot assign this lead to yourself.']],
//                     422, 'CANNOT_ASSIGN_TO_SELF'
//                 );
//             }

//             if (!$isAdmin && $activeAssignment && (int) $currentHolderId === (int) $assignedTo->id) {
//                 return $this->error(
//                     'This lead is already assigned to this user.',
//                     ['assigned_to_user_id' => ['This user is already the current assignee.']],
//                     422, 'ALREADY_CURRENT_ASSIGNEE'
//                 );
//             }

   
//             [$ok, $code, $message] = $this->canAssign(
//                 $authUser, $assignedTo, $lead, $isFirstAssignment, $isAdmin
//             );
//             if (!$ok) {
//                 return $this->error(
//                     $message,
//                     ['assigned_to_user_id' => [$message]],
//                     422, $code
//                 );
//             }

        
//             BLLeadAssignment::query()
//                 ->where('lead_uuid', $lead->uuid)
//                 ->where('is_active', true)
//                 ->update($this->onlyExistingColumns('b_l_lead_assignments', [
//                     'is_active'         => false,
//                     'assignment_status' => 'REASSIGNED',
//                     'completed_at'      => now(),
//                 ]));

//             $assignedRole = $validated['assigned_role'] ?? $this->primaryRoleCode($assignedTo);
//             $remarks      = $validated['remarks'] ?? $validated['remark'] ?? null;
//             $historyCount = BLLeadAssignment::query()->where('lead_uuid', $lead->uuid)->count();

//             $payload = [
//                 'lead_uuid'           => $lead->uuid,
//                 'lead_id'             => $lead->lead_id ?? $validated['lead_id'] ?? null,
//                 'assigned_by'         => $authUser?->id,
//                 'assigned_to'         => $assignedTo->id,
//                 'assigned_to_role'    => $assignedRole,
//                 'step_number'         => $historyCount + 1,
//                 'type'                => 'assign',
//                 'remark'              => $remarks,
//                 'assigned_by_user_id' => $authUser?->id,
//                 'assigned_to_user_id' => $assignedTo->id,
//                 'assigned_role'       => $assignedRole,
//                 'assignment_status'   => 'ACTIVE',
//                 'is_active'           => true,
//                 'assigned_at'         => now(),
//                 'remarks'             => $remarks,
//             ];

//             $assignment = DB::transaction(function () use ($payload) {
//                 return BLLeadAssignment::query()->create(
//                     $this->onlyExistingColumns('b_l_lead_assignments', $payload)
//                 );
//             });

//             $this->workflowService->changeStatus(
//                 $lead->uuid,
//                 $currentData['current_status']?->status_code ?? 'DRAFT',
//                 $assignedTo,
//                 $remarks ?? 'Lead assigned to ' . ($assignedTo->user_name ?? 'user'),
//                 $request,
//                 false
//             );

//             return $this->success(
//                 $assignment->load(['assignedByUser', 'assignedToUser']),
//                 'Lead assigned successfully.',
//                 201, 'LEAD_ASSIGNED_SUCCESSFULLY'
//             );
//         } catch (ValidationException $e) {
//             return $this->error('Validation failed.', $e->errors(), 422, 'VALIDATION_ERROR');
//         } catch (ModelNotFoundException) {
//             return $this->error('Lead or user not found.', null, 404, 'NOT_FOUND');
//         } catch (Throwable $e) {
//             return $this->serverError($e);
//         }
//     }

 
//     public function checkPermission(Request $request, string $lead_uuid): JsonResponse
//     {
//         try {
//             $authUser = $request->user();
//             if (!$authUser) {
//                 return $this->error('Unauthenticated.', null, 401, 'UNAUTHENTICATED');
//             }

//             $lead = BLLeadCreation::query()->where('uuid', $lead_uuid)->firstOrFail();

//             $isAssignedBy = BLLeadAssignment::query()
//                 ->where('lead_uuid', $lead->uuid)->where('is_active', true)
//                 ->where(fn ($q) => $q->where('assigned_by_user_id', $authUser->id)->orWhere('assigned_by', $authUser->id))
//                 ->exists();

//             $isAssignedTo = BLLeadAssignment::query()
//                 ->where('lead_uuid', $lead->uuid)->where('is_active', true)
//                 ->where(fn ($q) => $q->where('assigned_to_user_id', $authUser->id)->orWhere('assigned_to', $authUser->id))
//                 ->exists();

//             $currentData  = $this->workflowService->getLeadCurrentData($lead->uuid);
//             $currentStage = $currentData['current_stage'] ?? null;

//             if ($isAssignedBy && !$isAssignedTo) {
//                 return $this->success([
//                     'lead_uuid'     => $lead->uuid,
//                     'user_id'       => $authUser->id,
//                     'user_role'     => $this->primaryRoleCode($authUser),
//                     'relation'      => 'assigned_by',
//                     'current_stage' => $currentStage?->stage_code ?? null,
//                     'permissions'   => $this->viewOnlyPermissions(),
//                     'note'          => 'You assigned this lead. You can only view it now.',
//                 ], 'Permission fetched successfully.');
//             }

//             $relation         = $isAssignedTo ? 'assigned_to' : 'none';
//             $stagePermissions = $this->getStagePermissionsForUser($currentStage, $authUser);

//             return $this->success([
//                 'lead_uuid'     => $lead->uuid,
//                 'user_id'       => $authUser->id,
//                 'user_role'     => $this->primaryRoleCode($authUser),
//                 'relation'      => $relation,
//                 'current_stage' => $currentStage?->stage_code ?? null,
//                 'permissions'   => $stagePermissions,
//                 'note'          => 'Permissions based on your role at the current workflow stage.',
//             ], 'Permission fetched successfully.');
//         } catch (ModelNotFoundException) {
//             return $this->error('Lead not found.', null, 404, 'LEAD_NOT_FOUND');
//         } catch (Throwable $e) {
//             return $this->serverError($e);
//         }
//     }


//     public function eligibleUsersForAssignment(Request $request, string $lead_uuid): JsonResponse
//     {
//         try {
//             $request->validate([
//                 'role_name' => 'nullable|string|max:255',
//             ]);

//             $authUser = $request->user();
//             if (!$authUser) {
//                 return $this->error('Unauthenticated.', null, 401, 'UNAUTHENTICATED');
//             }
//             $authUser->loadMissing('roles');

//             $lead = BLLeadCreation::query()->where('uuid', $lead_uuid)->firstOrFail();

//             $currentData = $this->workflowService->getLeadCurrentData($lead->uuid);
//             if (!$currentData['current_journey'] || !$currentData['current_stage']) {
//                 $this->workflowService->initiateLead(
//                     $lead->uuid, $authUser, 'DRAFT',
//                     'Workflow auto-initiated while fetching eligible users for assignment.', $request
//                 );
//                 $currentData = $this->workflowService->getLeadCurrentData($lead->uuid);
//             }

//             $currentStage = $currentData['current_stage'];

//             $isAdmin           = $this->isAdmin($authUser);
//             $isFirstAssignment = !BLLeadAssignment::query()->where('lead_uuid', $lead->uuid)->exists();

//             $activeAssignment = BLLeadAssignment::query()
//                 ->where('lead_uuid', $lead->uuid)->where('is_active', true)->first();
//             $currentHolderId  = $activeAssignment
//                 ? ($activeAssignment->assigned_to_user_id ?? $activeAssignment->assigned_to)
//                 : null;

        
//             if (!$isFirstAssignment && $activeAssignment && !$isAdmin
//                 && (int) $currentHolderId !== (int) $authUser->id) {
//                 return $this->success([
//                     'lead_uuid'           => $lead->uuid,
//                     'current_stage'       => $currentStage,
//                     'eligible_roles'      => [],
//                     'users'               => [],
//                     'is_first_assignment' => false,
//                     'is_locked'           => true,
//                     'lock_reason'         => 'Only the current assignee can reassign this lead.',
//                 ], 'You are not the current assignee for this lead.');
//             }

  
//             $includeCreator = false;
//             $creatorSalesRoleCode = null;

//             if ($isAdmin) {
//                 $allowedCodes = $this->allRoleCodes();
//             } elseif ($isFirstAssignment) {
//                 $actorCodes = $this->userRoleCodes($authUser);
//                 $salesRole  = array_values(array_intersect($actorCodes, array_keys(self::FIRST_ASSIGNMENT_PAIRS)))[0] ?? null;

//                 $allowedCodes = ($salesRole && $this->isLeadCreator($authUser, $lead))
//                     ? [self::FIRST_ASSIGNMENT_PAIRS[$salesRole]]
//                     : [];
//             } else {
//                 $allowedCodes = $this->allowedTargetRolesFor($authUser);
//                 if ($allowedCodes === ['*']) {
//                     $allowedCodes = $this->allRoleCodes();
//                 }

//                 if ($this->userHasRole($authUser, 'BRANCH_MANAGER')) {
//                     $includeCreator       = true;
//                     $creatorSalesRoleCode = 'SALES_PERSON';
//                 } elseif ($this->userHasRole($authUser, 'PARTNER_BRANCH_MANAGER')) {
//                     $includeCreator       = true;
//                     $creatorSalesRoleCode = 'PARTNER_SALES_PERSON';
//                 }

//                 // The lead creator does not receive any extra transition outside MATRIX.
//             }


//             if ($request->filled('role_name')) {
//                 $wanted = strtoupper(str_replace([' ', '-'], '_', trim($request->input('role_name'))));
//                 $allowedCodes = array_values(array_intersect($allowedCodes, [$wanted]));
//             }

//             $users = $this->fetchUsersByRoleCodes($allowedCodes, $this->leadBranchId($lead));


//             if ($includeCreator && $lead->created_by) {
//                 $alreadyListed = $users->contains(fn ($u) => (int) $u['user_id'] === (int) $lead->created_by);
//                 if (!$alreadyListed) {
//                     $creator = User::query()->with('roles')->find($lead->created_by);
//                     if ($creator && $this->userHasRole($creator, $creatorSalesRoleCode)) {
//                         $users = $users->push($this->mapUser($creator, true))->values();
//                     }
//                 }
//             }


//             if (!$isAdmin && $authUser?->id) {
//                 $users = $users
//                     ->reject(fn ($u) => (int) $u['user_id'] === (int) $authUser->id)
//                     ->values();
//             }

//             return $this->success([
//                 'lead_uuid'           => $lead->uuid,
//                 'current_stage'       => $currentStage,
//                 'eligible_roles'      => $allowedCodes,
//                 'users'               => $users,
//                 'is_first_assignment' => $isFirstAssignment,
//                 'is_locked'           => false,
//             ], 'Eligible users fetched successfully.');
//         } catch (ValidationException $e) {
//             return $this->error('Validation failed.', $e->errors(), 422, 'VALIDATION_ERROR');
//         } catch (ModelNotFoundException) {
//             return $this->error('Lead not found.', null, 404, 'LEAD_NOT_FOUND');
//         } catch (Throwable $e) {
//             return $this->serverError($e);
//         }
//     }

//     public function complete(Request $request, int $id): JsonResponse
//     {
//         try {
//             $validated  = $request->validate(['remarks' => 'nullable|string']);
//             $assignment = BLLeadAssignment::query()->findOrFail($id);

//             $assignment->update($this->onlyExistingColumns('b_l_lead_assignments', [
//                 'assignment_status' => 'COMPLETED',
//                 'is_active'         => false,
//                 'completed_at'      => now(),
//                 'remarks'           => $validated['remarks'] ?? $assignment->remarks ?? $assignment->remark ?? null,
//             ]));

//             return $this->success(
//                 $assignment->fresh(['assignedByUser', 'assignedToUser']),
//                 'Assignment completed successfully.'
//             );
//         } catch (ValidationException $e) {
//             return $this->error('Validation failed.', $e->errors(), 422, 'VALIDATION_ERROR');
//         } catch (ModelNotFoundException) {
//             return $this->error('Assignment not found.', null, 404, 'ASSIGNMENT_NOT_FOUND');
//         } catch (Throwable $e) {
//             return $this->serverError($e);
//         }
//     }


//     public function cancel(Request $request, int $id): JsonResponse
//     {
//         try {
//             $validated  = $request->validate(['remarks' => 'nullable|string']);
//             $assignment = BLLeadAssignment::query()->findOrFail($id);

//             $assignment->update($this->onlyExistingColumns('b_l_lead_assignments', [
//                 'assignment_status' => 'CANCELLED',
//                 'is_active'         => false,
//                 'completed_at'      => now(),
//                 'remarks'           => $validated['remarks'] ?? $assignment->remarks ?? $assignment->remark ?? null,
//             ]));

//             return $this->success(
//                 $assignment->fresh(['assignedByUser', 'assignedToUser']),
//                 'Assignment cancelled successfully.'
//             );
//         } catch (ValidationException $e) {
//             return $this->error('Validation failed.', $e->errors(), 422, 'VALIDATION_ERROR');
//         } catch (ModelNotFoundException) {
//             return $this->error('Assignment not found.', null, 404, 'ASSIGNMENT_NOT_FOUND');
//         } catch (Throwable $e) {
//             return $this->serverError($e);
//         }
//     }


//     public function myAssignmentView(Request $request, string $lead_uuid): JsonResponse
//     {
//         try {
//             $authUser = $request->user();
//             if (!$authUser) {
//                 return $this->error('Unauthenticated.', null, 401, 'UNAUTHENTICATED');
//             }

//             $lead = BLLeadCreation::query()->where('uuid', $lead_uuid)->firstOrFail();

//             $myAssignment = BLLeadAssignment::query()
//                 ->with(['assignedByUser', 'assignedToUser'])
//                 ->where('lead_uuid', $lead->uuid)
//                 ->where(fn ($q) => $q->where('assigned_by_user_id', $authUser->id)->orWhere('assigned_by', $authUser->id))
//                 ->latest('id')->first();

//             $assignedToMe = BLLeadAssignment::query()
//                 ->with(['assignedByUser'])
//                 ->where('lead_uuid', $lead->uuid)->where('is_active', true)
//                 ->where(fn ($q) => $q->where('assigned_to_user_id', $authUser->id)->orWhere('assigned_to', $authUser->id))
//                 ->first();

//             if ($assignedToMe && !$myAssignment) {
//                 return $this->success([
//                     'lead_uuid'   => $lead->uuid,
//                     'lead_id'     => $lead->lead_id ?? null,
//                     'my_relation' => 'assigned_to_me',
//                     'message'     => 'This lead is assigned to you.',
//                     'assigned_by' => [
//                         'id'   => $assignedToMe->assignedByUser?->id,
//                         'name' => $assignedToMe->assignedByUser?->user_name,
//                         'role' => $this->primaryRoleCode($assignedToMe->assignedByUser),
//                     ],
//                     'assigned_to' => null,
//                     'assigned_at' => $assignedToMe->assigned_at,
//                     'remarks'     => $assignedToMe->remarks ?? $assignedToMe->remark ?? null,
//                 ], 'My assignment view fetched successfully.');
//             }

//             if ($myAssignment) {
//                 return $this->success([
//                     'lead_uuid'   => $lead->uuid,
//                     'lead_id'     => $lead->lead_id ?? null,
//                     'my_relation' => 'i_assigned',
//                     'message'     => 'You assigned this lead to ' . ($myAssignment->assignedToUser?->user_name ?? 'someone') . '.',
//                     'assigned_by' => [
//                         'id'   => $authUser->id,
//                         'name' => $authUser->user_name,
//                         'role' => $this->primaryRoleCode($authUser),
//                     ],
//                     'assigned_to' => [
//                         'id'          => $myAssignment->assignedToUser?->id,
//                         'name'        => $myAssignment->assignedToUser?->user_name,
//                         'role'        => $this->primaryRoleCode($myAssignment->assignedToUser),
//                         'designation' => $myAssignment->assignedToUser?->user_designation ?? null,
//                         'email'       => $myAssignment->assignedToUser?->user_email ?? null,
//                         'mobile'      => $myAssignment->assignedToUser?->user_mobile_number ?? null,
//                     ],
//                     'assigned_at' => $myAssignment->assigned_at,
//                     'remarks'     => $myAssignment->remarks ?? $myAssignment->remark ?? null,
//                 ], 'My assignment view fetched successfully.');
//             }

//             return $this->success([
//                 'lead_uuid'   => $lead->uuid,
//                 'lead_id'     => $lead->lead_id ?? null,
//                 'my_relation' => 'none',
//                 'message'     => 'You have no assignment activity on this lead.',
//                 'assigned_by' => null,
//                 'assigned_to' => null,
//                 'assigned_at' => null,
//                 'remarks'     => null,
//             ], 'My assignment view fetched successfully.');
//         } catch (ModelNotFoundException) {
//             return $this->error('Lead not found.', null, 404, 'LEAD_NOT_FOUND');
//         } catch (Throwable $e) {
//             return $this->serverError($e);
//         }
//     }

//     private function canAssign($actor, User $assignedTo, $lead, bool $isFirst, bool $isAdmin): array
//     {
//         if ($isAdmin) {
//             return [true, '', ''];
//         }

//         if ($isFirst) {
//             $actorCodes = $this->userRoleCodes($actor);
//             $salesRole  = array_values(array_intersect($actorCodes, array_keys(self::FIRST_ASSIGNMENT_PAIRS)))[0] ?? null;

//             if (!$salesRole) {
//                 return [false, 'FIRST_ASSIGNMENT_ONLY_BY_SALES_PERSON',
//                     'The first assignment can only be made by the Sales Person (or Partner Sales Person) who punched the lead.'];
//             }

//             if (!$this->isLeadCreator($actor, $lead)) {
//                 return [false, 'FIRST_ASSIGNMENT_ONLY_BY_LEAD_CREATOR',
//                     'Only whoever created the lead can make the first assignment.'];
//             }

//             $expectedBranchManagerRole = self::FIRST_ASSIGNMENT_PAIRS[$salesRole];
//             if (!$this->userHasRole($assignedTo, $expectedBranchManagerRole)) {
//                 return [false, 'FIRST_ASSIGNMENT_MUST_BE_BRANCH_MANAGER',
//                     'The first assignment can only be made to a Branch Manager.'];
//             }

//             return [true, '', ''];
//         }


//         // Branch Manager can send the lead back to the Sales Person who created it
//         // (same for the partner-channel equivalent).
//         if ($lead->created_by
//             && (int) $lead->created_by === (int) $assignedTo->id
//             && (
//                 ($this->userHasRole($actor, 'BRANCH_MANAGER') && $this->userHasRole($assignedTo, 'SALES_PERSON'))
//                 || ($this->userHasRole($actor, 'PARTNER_BRANCH_MANAGER') && $this->userHasRole($assignedTo, 'PARTNER_SALES_PERSON'))
//             )) {
//             return [true, '', ''];
//         }

//         $allowed = $this->allowedTargetRolesFor($actor);

//         if ($allowed === ['*']) {
//             return [true, '', ''];
//         }


//         // All regular transitions are controlled by MATRIX.

//         if (empty($allowed)) {
//             return [false, 'ASSIGNMENT_TRANSITION_NOT_ALLOWED',
//                 'Your current role is not permitted to assign this lead.'];
//         }

//         $targetCodes = $this->userRoleCodes($assignedTo);

//         if (empty(array_intersect($allowed, $targetCodes))) {
//             return [false, 'ASSIGNMENT_TRANSITION_NOT_ALLOWED',
//                 'You are not allowed to assign this lead to the selected user from your current role.'];
//         }

//         // Branch Manager / Branch Credit Manager targets must belong to the
//         // same branch this lead belongs to.
//         if (!empty(array_intersect($targetCodes, self::BRANCH_SCOPED_ROLE_CODES))) {
//             $leadBranchId = $this->leadBranchId($lead);
//             if ($leadBranchId && (int) $assignedTo->branch_id !== (int) $leadBranchId) {
//                 return [false, 'ASSIGNEE_MUST_BE_SAME_BRANCH',
//                     'You can only assign this lead to a Branch Manager or Branch Credit Manager of the branch this lead belongs to.'];
//             }
//         }

//         return [true, '', ''];
//     }

//     private function leadBranchId($lead): ?int
//     {
//         $branchId = $lead->branch_id ?? $lead->creator?->branch_id;
//         return $branchId ? (int) $branchId : null;
//     }

//     private function isLeadCreator($user, $lead): bool
//     {
//         return $user
//             && $lead
//             && $lead->created_by
//             && (int) $lead->created_by === (int) $user->id;
//     }


//     private function allowedTargetRolesFor($user): array
//     {
//         $allowed = [];
//         foreach ($this->userRoleCodes($user) as $code) {
//             if (!isset(self::MATRIX[$code])) {
//                 continue;
//             }
//             if (self::MATRIX[$code] === ['*']) {
//                 return ['*'];
//             }
//             $allowed = array_merge($allowed, self::MATRIX[$code]);
//         }
//         return array_values(array_unique($allowed));
//     }


//     private function userRoleCodes($user): array
//     {
//         if (!$user) {
//             return [];
//         }

//         try {
//             $user->loadMissing('roles');
//         } catch (Throwable) {
           
//         }

//         $codes = [];

//         if (isset($user->roles)) {
//             foreach ($user->roles as $role) {
//                 $value = $role->code ?? $role->name ?? $role->display_name ?? null;
//                 if ($value) {
//                     $codes[] = strtoupper(str_replace([' ', '-'], '_', trim($value)));
//                 }
//             }
//         }

  
//         if (empty($codes) && !empty($user->user_designation)) {
//             $codes[] = strtoupper(str_replace([' ', '-'], '_', trim($user->user_designation)));
//         }

//         return array_values(array_unique(array_filter($codes)));
//     }

//     private function userHasRole($user, string $code): bool
//     {
//         return in_array(strtoupper($code), $this->userRoleCodes($user), true);
//     }

//     private function isAdmin($user): bool
//     {
//         $codes = $this->userRoleCodes($user);
//         return in_array('ADMINISTRATIVE', $codes, true)
//             || in_array('SUPER_ADMINISTRATIVE', $codes, true);
//     }

//     private function primaryRoleCode($user): ?string
//     {
//         return $this->userRoleCodes($user)[0] ?? null;
//     }

  
//     private function allRoleCodes(): array
//     {
//         $codes = [
//             'SALES_PERSON',
//             'BRANCH_MANAGER',
//             'BRANCH_CREDIT_MANAGER',
//             'HEAD_OFFICE_CREDIT_CHECKER',
//             'HEAD_OFFICE_CREDIT_OPERATIONS',
//             'SANCTION_AUTHORIZED_PERSON',
//             'HEAD_OFFICE_RISK_CONTROL_UNIT',
//             'ACCOUNT',
//             'PARTNER_SALES_PERSON',
//             'PARTNER_BRANCH_MANAGER',
//             'PARTNER_CREDIT_PERSON',
//             'ADMINISTRATIVE',
//             'SUPER_ADMINISTRATIVE',
//         ];
//         try {
//             $codes = \Modules\UserManagement\Models\Role::query()
//                 ->pluck('code')->filter()
//                 ->map(fn ($c) => strtoupper(trim($c)))->unique()->values()->toArray();
//         } catch (Throwable) {
           
//         }

       
//         return array_values(array_diff($codes, self::NON_EMPLOYEE_ROLE_CODES));
//     }

   
//     private function fetchUsersByRoleCodes(array $roleCodes, ?int $branchId = null)
//     {
//         if (empty($roleCodes)) {
//             return collect();
//         }

//         $codes = collect($roleCodes)
//             ->map(fn ($c) => strtoupper(trim($c)))
//             ->reject(fn ($c) => in_array($c, self::NON_EMPLOYEE_ROLE_CODES, true))
//             ->unique()
//             ->values()
//             ->toArray();

//         if (empty($codes)) {
//             return collect();
//         }

//         // Role codes here span both channels (employee: BRANCH_MANAGER,
//         // HEAD_OFFICE_CREDIT_CHECKER, ... / partner: PARTNER_SALES_PERSON,
//         // PARTNER_BRANCH_MANAGER, ...) — user_type is implied by which role
//         // a user actually holds, so it is not filtered separately here.
//         $query = User::query()
//             ->with('roles')
//             ->whereHas('roles', function ($q) use ($codes) {
//                 $q->whereIn(DB::raw('UPPER(code)'), $codes)
//                   ->orWhereIn(DB::raw("UPPER(REPLACE(REPLACE(name, '-', '_'), ' ', '_'))"), $codes);
//             })
//             ->whereDoesntHave('roles', function ($q) {
//                 $q->whereIn(DB::raw('UPPER(code)'), self::NON_EMPLOYEE_ROLE_CODES);
//             })
//             ->when(Schema::hasColumn('users', 'is_active'), fn ($q) => $q->where('is_active', true));

//         // Branch Manager / Branch Credit Manager targets must be in the
//         // lead's own branch; any other role in the same $codes list (e.g. a
//         // Head Office desk) is not branch-bound and passes through untouched.
//         if ($branchId && Schema::hasColumn('users', 'branch_id')) {
//             $query->where(function ($q) use ($branchId) {
//                 $q->where('branch_id', $branchId)
//                   ->orWhereDoesntHave('roles', function ($qq) {
//                       $qq->whereIn(DB::raw('UPPER(code)'), self::BRANCH_SCOPED_ROLE_CODES)
//                          ->orWhereIn(DB::raw("UPPER(REPLACE(REPLACE(name, '-', '_'), ' ', '_'))"), self::BRANCH_SCOPED_ROLE_CODES);
//                   });
//             });
//         }

//         return $query
//             ->orderBy(Schema::hasColumn('users', 'user_name') ? 'user_name' : 'id')
//             ->get()
//             ->map(fn ($u) => $this->mapUser($u))
//             ->values();
//     }

//     private function mapUser(User $u, bool $isCreator = false): array
//     {
//         return [
//             'user_id'     => $u->id,
//             'name'        => $u->user_name ?? null,
//             'email'       => $u->user_email ?? null,
//             'mobile'      => $u->user_mobile_number ?? null,
//             'role'        => $this->primaryRoleCode($u),
//             'designation' => $u->user_designation ?? null,
//             'is_creator'  => $isCreator,
//         ];
//     }

  
//     private function hasBlAccess($user): bool
//     {
//         if (!$user) return false;
//         if (!method_exists($user, 'products')) return true;

//         try {
//             $keyColumn = Schema::hasColumn('products', 'key') ? 'key'
//                 : (Schema::hasColumn('products', 'product_key') ? 'product_key'
//                 : (Schema::hasColumn('products', 'slug') ? 'slug' : null));

//             return $keyColumn ? $user->products()->where($keyColumn, 'bl')->exists() : true;
//         } catch (Throwable) {
//             return true;
//         }
//     }

   
//     private function getStagePermissionsForUser(?object $currentStage, $user): array
//     {
//         if (!$currentStage || !$user) return $this->noPermissions();

//         $candidates = $this->stageRoleMatchValues($user);
//         if (empty($candidates)) return $this->noPermissions();

//         $permission = BLStageRolePermission::query()
//             ->where('stage_id', $currentStage->id)
//             ->where(function ($q) use ($candidates) {
//                 $q->whereIn('role_name', $candidates)
//                   ->orWhereIn(
//                       DB::raw("UPPER(REPLACE(REPLACE(role_name, '-', '_'), ' ', '_'))"),
//                       array_map(fn ($v) => strtoupper(str_replace([' ', '-'], '_', $v)), $candidates)
//                   );
//             })
//             ->first();

//         if (!$permission) return $this->noPermissions();

//         return [
//             'can_view'            => (bool) $permission->can_view,
//             'can_edit'            => (bool) $permission->can_edit,
//             'can_assign'          => (bool) $permission->can_assign,
//             'can_change_status'   => (bool) $permission->can_change_status,
//             'can_upload_document' => (bool) $permission->can_upload_document,
//             'can_move_next'       => (bool) $permission->can_move_next,
//             'can_move_previous'   => (bool) $permission->can_move_previous,
//         ];
//     }

//     private function stageRoleMatchValues($user): array
//     {
//         $values = [];
//         try {
//             $user->loadMissing('roles');
//             foreach ($user->roles as $r) {
//                 foreach ([$r->code, $r->name, $r->display_name] as $v) {
//                     if ($v) $values[] = trim($v);
//                 }
//             }
//         } catch (Throwable) {
            
//         }
//         if (empty($values) && !empty($user->user_designation)) {
//             $values[] = trim($user->user_designation);
//         }
//         return array_values(array_unique(array_filter($values)));
//     }

//     private function viewOnlyPermissions(): array
//     {
//         return [
//             'can_view' => true, 'can_edit' => false, 'can_assign' => false,
//             'can_change_status' => false, 'can_upload_document' => false,
//             'can_move_next' => false, 'can_move_previous' => false,
//         ];
//     }

//     private function noPermissions(): array
//     {
//         return [
//             'can_view' => false, 'can_edit' => false, 'can_assign' => false,
//             'can_change_status' => false, 'can_upload_document' => false,
//             'can_move_next' => false, 'can_move_previous' => false,
//         ];
//     }

//     private function onlyExistingColumns(string $table, array $payload): array
//     {
//         return collect($payload)
//             ->filter(fn ($value, $key) => Schema::hasColumn($table, $key))
//             ->toArray();
//     }


//     private function success($data = null, string $message = 'Success', int $status = 200, string $code = 'SUCCESS'): JsonResponse
//     {
//         return response()->json([
//             'success' => true, 'status_code' => $status, 'code' => $code,
//             'message' => $message, 'data' => $data,
//         ], $status);
//     }

//     private function error(string $message, $data = null, int $status = 400, string $code = 'ERROR'): JsonResponse
//     {
//         return response()->json([
//             'success' => false, 'status_code' => $status, 'code' => $code,
//             'message' => $message, 'data' => $data,
//         ], $status);
//     }

//     private function serverError(Throwable $e): JsonResponse
//     {
//         return $this->error(
//             'Something went wrong.',
//             app()->environment('local') ? $e->getMessage() : null,
//             500, 'SERVER_ERROR'
//         );
//     }
// }



namespace Modules\BLLeadAssignment\Http\Controllers;
 
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Modules\BLLeadAssignment\Models\BLLeadAssignment;
use Modules\BLLeadCreation\Models\BLLeadCreation;
use Modules\BLWorkflow\Models\BLStageRolePermission;
use Modules\BLWorkflow\Services\BLWorkflowService;
use Modules\Notification\Notifications\LeadAssignedNotification;
use Modules\UserManagement\Models\User;
use Throwable;
 
class BLLeadAssignmentController extends Controller
{
    public function __construct(
        private readonly BLWorkflowService $workflowService
    ) {}
 
   
    /**
     * Lead assignment flow. Role codes are normalized by userRoleCodes()
     * (spaces and hyphens are folded to underscores).
     *
     * Employee channel:
     *   SALES_PERSON -> BRANCH_MANAGER (first assignment only)
     *   BRANCH_MANAGER -> SALES_PERSON / BRANCH_CREDIT_CHECKER /
     *                     HEAD_OFFICE_CREDIT_CHECKER / HEAD_OFFICE_RISK_CONTROL_UNIT
     *   BRANCH_CREDIT_CHECKER -> HEAD_OFFICE_CREDIT_CHECKER / BRANCH_MANAGER
     *   HEAD_OFFICE_CREDIT_CHECKER -> BRANCH_MANAGER /
     *                                 SANCTION_AUTHORIZED_PERSON /
     *                                 BRANCH_CREDIT_CHECKER
     *   SANCTION_AUTHORIZED_PERSON -> HEAD_OFFICE_CREDIT_CHECKER / BRANCH_MANAGER
     *   HEAD_OFFICE_RISK_CONTROL_UNIT -> BRANCH_MANAGER / ACCOUNT
     *   ACCOUNT -> HEAD_OFFICE_RISK_CONTROL_UNIT
     *
     * Partner channel mirrors the complete employee flow:
     *   PARTNER_SALES_PERSON -> PARTNER_BRANCH_MANAGER (first assignment only)
     *   PARTNER_BRANCH_MANAGER -> PARTNER_SALES_PERSON / PARTNER_CREDIT_PERSON /
     *                             HEAD_OFFICE_CREDIT_CHECKER /
     *                             HEAD_OFFICE_RISK_CONTROL_UNIT
     *   PARTNER_CREDIT_PERSON -> HEAD_OFFICE_CREDIT_CHECKER /
     *                            PARTNER_BRANCH_MANAGER
     *   HEAD_OFFICE_CREDIT_CHECKER -> PARTNER_BRANCH_MANAGER /
     *                                 SANCTION_AUTHORIZED_PERSON /
     *                                 PARTNER_CREDIT_PERSON
     *   SANCTION_AUTHORIZED_PERSON -> HEAD_OFFICE_CREDIT_CHECKER /
     *                                  PARTNER_BRANCH_MANAGER
     *   HEAD_OFFICE_RISK_CONTROL_UNIT -> PARTNER_BRANCH_MANAGER / ACCOUNT
     *   ACCOUNT -> HEAD_OFFICE_RISK_CONTROL_UNIT
     *
     * Shared HO roles use CHANNEL_MATRIX so an employee lead returns to employee
     * branch roles and a partner lead returns to partner branch roles.
     *
     * When SAP directly returns a lead to its channel manager, that manager
     * cannot send it back to the channel Sales Person. All other allowed targets,
     * including HEAD_OFFICE_RISK_CONTROL_UNIT, remain available.
     */
    private const MATRIX = [
        'SALES_PERSON'                  => ['BRANCH_MANAGER'],
        'BRANCH_MANAGER'                => [
            'SALES_PERSON',
            'BRANCH_CREDIT_CHECKER',
            'HEAD_OFFICE_CREDIT_CHECKER',
            'HEAD_OFFICE_RISK_CONTROL_UNIT',
        ],
        'BRANCH_CREDIT_CHECKER'         => ['HEAD_OFFICE_CREDIT_CHECKER', 'BRANCH_MANAGER'],

        'PARTNER_SALES_PERSON'          => ['PARTNER_BRANCH_MANAGER'],
        'PARTNER_BRANCH_MANAGER'        => [
            'PARTNER_SALES_PERSON',
            'PARTNER_CREDIT_PERSON',
            'HEAD_OFFICE_CREDIT_CHECKER',
            'HEAD_OFFICE_RISK_CONTROL_UNIT',
        ],
        'PARTNER_CREDIT_PERSON'         => ['HEAD_OFFICE_CREDIT_CHECKER', 'PARTNER_BRANCH_MANAGER'],

        // Shared Head Office roles. Channel-specific branch targets are added
        // from CHANNEL_MATRIX at runtime.
        'HEAD_OFFICE_CREDIT_CHECKER'    => ['SANCTION_AUTHORIZED_PERSON'],
        'SANCTION_AUTHORIZED_PERSON'    => ['HEAD_OFFICE_CREDIT_CHECKER'],
        'HEAD_OFFICE_RISK_CONTROL_UNIT' => ['ACCOUNT'],
        'ACCOUNT'                       => ['HEAD_OFFICE_RISK_CONTROL_UNIT'],

        'ADMINISTRATIVE'                => ['*'],
        'SUPER_ADMINISTRATIVE'          => ['*'],
    ];

    private const CHANNEL_EMPLOYEE = 'employee';
    private const CHANNEL_PARTNER  = 'partner';

    /**
     * Channel-specific targets for the shared Head Office roles.
     */
    private const CHANNEL_MATRIX = [
        self::CHANNEL_EMPLOYEE => [
            'HEAD_OFFICE_CREDIT_CHECKER'    => ['BRANCH_MANAGER', 'BRANCH_CREDIT_CHECKER'],
            'SANCTION_AUTHORIZED_PERSON'    => ['BRANCH_MANAGER'],
            'HEAD_OFFICE_RISK_CONTROL_UNIT' => ['BRANCH_MANAGER'],
        ],
        self::CHANNEL_PARTNER => [
            'HEAD_OFFICE_CREDIT_CHECKER'    => ['PARTNER_BRANCH_MANAGER', 'PARTNER_CREDIT_PERSON'],
            'SANCTION_AUTHORIZED_PERSON'    => ['PARTNER_BRANCH_MANAGER'],
            'HEAD_OFFICE_RISK_CONTROL_UNIT' => ['PARTNER_BRANCH_MANAGER'],
        ],
    ];

    private const FIRST_ASSIGNMENT_PAIRS = [
        'SALES_PERSON'         => 'BRANCH_MANAGER',
        'PARTNER_SALES_PERSON' => 'PARTNER_BRANCH_MANAGER',
    ];

    private const BRANCH_SCOPED_ROLE_CODES = [
        'BRANCH_MANAGER',
        'BRANCH_CREDIT_CHECKER',
        'PARTNER_BRANCH_MANAGER',
        'PARTNER_CREDIT_PERSON',
    ];

    private const EMPLOYEE_CHANNEL_ROLE_CODES = [
        'SALES_PERSON',
        'BRANCH_MANAGER',
        'BRANCH_CREDIT_CHECKER',
    ];

    private const PARTNER_CHANNEL_ROLE_CODES = [
        'PARTNER_SALES_PERSON',
        'PARTNER_BRANCH_MANAGER',
        'PARTNER_CREDIT_PERSON',
    ];

    private const NON_EMPLOYEE_ROLE_CODES = [
        'PARTNER_ADMINISTRATIVE',
        'PARTNER_SUB_USER',
    ];
 
 
    public function index(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'lead_uuid' => 'nullable|uuid|exists:b_l_lead_creations,uuid',
                'lead_id'   => 'nullable|string',
                'is_active' => 'nullable|in:0,1,true,false',
                'per_page'  => 'nullable|integer|min:1|max:100',
            ]);
 
            $assignments = BLLeadAssignment::query()
                ->with(['assignedByUser', 'assignedToUser'])
                ->when($validated['lead_uuid'] ?? null, fn ($q, $v) => $q->where('lead_uuid', $v))
                ->when($validated['lead_id']   ?? null, fn ($q, $v) => $q->where('lead_id', $v))
                ->when(
                    array_key_exists('is_active', $validated),
                    fn ($q) => $q->where('is_active', filter_var($validated['is_active'], FILTER_VALIDATE_BOOLEAN))
                )
                ->latest('id')
                ->paginate($validated['per_page'] ?? 15);
 
            return $this->success($assignments, 'Assignment list fetched successfully.');
        } catch (ValidationException $e) {
            return $this->error('Validation failed.', $e->errors(), 422, 'VALIDATION_ERROR');
        } catch (Throwable $e) {
            return $this->serverError($e);
        }
    }
 
 
    public function show(int $id): JsonResponse
    {
        try {
            $assignment = BLLeadAssignment::query()
                ->with(['assignedByUser', 'assignedToUser'])
                ->findOrFail($id);
 
            return $this->success([
                'assignment' => $assignment,
                'workflow'   => $this->workflowResponse($assignment->lead_uuid),
            ], 'Assignment fetched successfully.');
        } catch (ModelNotFoundException) {
            return $this->error('Assignment not found.', null, 404, 'ASSIGNMENT_NOT_FOUND');
        } catch (Throwable $e) {
            return $this->serverError($e);
        }
    }
 
 
    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'lead_uuid'           => 'required|uuid|exists:b_l_lead_creations,uuid',
                'lead_id'             => 'nullable|string',
                'assigned_to_user_id' => 'nullable|integer|exists:users,id',
                'assigned_to'         => 'nullable|integer|exists:users,id',
                'assigned_role'       => 'nullable|string|max:255',
                'remarks'             => 'nullable|string',
                'remark'              => 'nullable|string',
            ]);
 
            $authUser = $request->user();
 
            if (!$this->hasBlAccess($authUser)) {
                return $this->error(
                    'You do not have permission to perform this action.',
                    null, 403, 'PERMISSION_DENIED'
                );
            }
 
            $lead = BLLeadCreation::query()->where('uuid', $validated['lead_uuid'])->firstOrFail();
 
            $assignedToUserId = $validated['assigned_to_user_id'] ?? $validated['assigned_to'] ?? null;
            if (!$assignedToUserId) {
                return $this->error(
                    'Please select a user to assign.',
                    ['assigned_to_user_id' => ['Please select a user.']],
                    422, 'VALIDATION_ERROR'
                );
            }
 
            $assignedTo = User::query()->with('roles')->findOrFail($assignedToUserId);
            $authUser?->loadMissing('roles');
 
    
            $currentData = $this->workflowService->getLeadCurrentData($lead->uuid);
            if (!$currentData['current_journey']) {
                $this->workflowService->initiateLead(
                    $lead->uuid, $authUser, 'DRAFT',
                    'Workflow auto-initiated before assignment.', $request
                );
                $currentData = $this->workflowService->getLeadCurrentData($lead->uuid);
            }
 
            $isAdmin           = $this->isAdmin($authUser);
            $isFirstAssignment = !BLLeadAssignment::query()->where('lead_uuid', $lead->uuid)->exists();
 
            $activeAssignment  = BLLeadAssignment::query()
                ->with('assignedByUser.roles')
                ->where('lead_uuid', $lead->uuid)
                ->where('is_active', true)
                ->first();
 
            $currentHolderId = $activeAssignment
                ? ($activeAssignment->assigned_to_user_id ?? $activeAssignment->assigned_to)
                : null;
 
   
            if (!$isFirstAssignment && $activeAssignment && !$isAdmin) {
                if ((int) $currentHolderId !== (int) $authUser?->id) {
                    return $this->error(
                        'Only the currently assigned user can re-assign this lead.',
                        null, 403, 'ONLY_CURRENT_ASSIGNEE_CAN_REASSIGN'
                    );
                }
            }
 
            if (!$isAdmin && (int) $assignedTo->id === (int) $authUser?->id) {
                return $this->error(
                    'You cannot assign this lead to yourself.',
                    ['assigned_to_user_id' => ['You cannot assign this lead to yourself.']],
                    422, 'CANNOT_ASSIGN_TO_SELF'
                );
            }
 
            if (!$isAdmin && $activeAssignment && (int) $currentHolderId === (int) $assignedTo->id) {
                return $this->error(
                    'This lead is already assigned to this user.',
                    ['assigned_to_user_id' => ['This user is already the current assignee.']],
                    422, 'ALREADY_CURRENT_ASSIGNEE'
                );
            }
 
   
            [$ok, $code, $message] = $this->canAssign(
                $authUser, $assignedTo, $lead, $isFirstAssignment, $isAdmin, $activeAssignment
            );
            if (!$ok) {
                return $this->error(
                    $message,
                    ['assigned_to_user_id' => [$message]],
                    422, $code
                );
            }
 
        
            BLLeadAssignment::query()
                ->where('lead_uuid', $lead->uuid)
                ->where('is_active', true)
                ->update($this->onlyExistingColumns('b_l_lead_assignments', [
                    'is_active'         => false,
                    'assignment_status' => 'REASSIGNED',
                    'completed_at'      => now(),
                ]));
 
            $assignedRole = $validated['assigned_role'] ?? $this->primaryRoleCode($assignedTo);
            $remarks      = $validated['remarks'] ?? $validated['remark'] ?? null;
            $historyCount = BLLeadAssignment::query()->where('lead_uuid', $lead->uuid)->count();
 
            $payload = [
                'lead_uuid'           => $lead->uuid,
                'lead_id'             => $lead->lead_id ?? $validated['lead_id'] ?? null,
                'assigned_by'         => $authUser?->id,
                'assigned_to'         => $assignedTo->id,
                'assigned_to_role'    => $assignedRole,
                'step_number'         => $historyCount + 1,
                'type'                => 'assign',
                'remark'              => $remarks,
                'assigned_by_user_id' => $authUser?->id,
                'assigned_to_user_id' => $assignedTo->id,
                'assigned_role'       => $assignedRole,
                'assignment_status'   => 'ACTIVE',
                'is_active'           => true,
                'assigned_at'         => now(),
                'remarks'             => $remarks,
            ];
 
            $assignment = DB::transaction(function () use ($payload) {
                return BLLeadAssignment::query()->create(
                    $this->onlyExistingColumns('b_l_lead_assignments', $payload)
                );
            });
 
            $this->workflowService->changeStatus(
                $lead->uuid,
                $currentData['current_status']?->status_code ?? 'DRAFT',
                $assignedTo,
                $remarks ?? 'Lead assigned to ' . ($assignedTo->user_name ?? 'user'),
                $request,
                false
            );
 
            try {
                $assignedTo->notify(new LeadAssignedNotification(
                    leadUuid: $lead->uuid,
                    leadId: $lead->lead_id,
                    assignedByName: $authUser?->user_name ?? 'A colleague',
                    assignedRole: $assignedRole,
                ));
            } catch (Throwable) {
                // Notification failure must never block the assignment itself.
            }
 
            return $this->success([
                'assignment' => $assignment->load(['assignedByUser', 'assignedToUser']),
                'workflow'   => $this->workflowResponse($lead->uuid),
            ], 'Lead assigned successfully.', 201, 'LEAD_ASSIGNED_SUCCESSFULLY');
        } catch (ValidationException $e) {
            return $this->error('Validation failed.', $e->errors(), 422, 'VALIDATION_ERROR');
        } catch (ModelNotFoundException) {
            return $this->error('Lead or user not found.', null, 404, 'NOT_FOUND');
        } catch (Throwable $e) {
            return $this->serverError($e);
        }
    }
 
 
    public function checkPermission(Request $request, string $lead_uuid): JsonResponse
    {
        try {
            $authUser = $request->user();
            if (!$authUser) {
                return $this->error('Unauthenticated.', null, 401, 'UNAUTHENTICATED');
            }
 
            $lead = BLLeadCreation::query()->where('uuid', $lead_uuid)->firstOrFail();
 
            $isAssignedBy = BLLeadAssignment::query()
                ->where('lead_uuid', $lead->uuid)->where('is_active', true)
                ->where(fn ($q) => $q->where('assigned_by_user_id', $authUser->id)->orWhere('assigned_by', $authUser->id))
                ->exists();
 
            $isAssignedTo = BLLeadAssignment::query()
                ->where('lead_uuid', $lead->uuid)->where('is_active', true)
                ->where(fn ($q) => $q->where('assigned_to_user_id', $authUser->id)->orWhere('assigned_to', $authUser->id))
                ->exists();
 
            $currentData  = $this->workflowService->getLeadCurrentData($lead->uuid);
            $currentStage = $currentData['current_stage'] ?? null;
 
            if ($isAssignedBy && !$isAssignedTo) {
                return $this->success([
                    'lead_uuid'     => $lead->uuid,
                    'user_id'       => $authUser->id,
                    'user_role'     => $this->primaryRoleCode($authUser),
                    'relation'      => 'assigned_by',
                    'current_stage' => $currentStage?->stage_code ?? null,
                    'current_status' => ($currentData['current_status'] ?? null)?->status_code,
                    'workflow'      => $this->workflowResponse($lead->uuid),
                    'permissions'   => $this->viewOnlyPermissions(),
                    'note'          => 'You assigned this lead. You can only view it now.',
                ], 'Permission fetched successfully.');
            }
 
            $relation         = $isAssignedTo ? 'assigned_to' : 'none';
            $stagePermissions = $this->getStagePermissionsForUser($currentStage, $authUser);
 
            return $this->success([
                'lead_uuid'     => $lead->uuid,
                'user_id'       => $authUser->id,
                'user_role'     => $this->primaryRoleCode($authUser),
                'relation'      => $relation,
                'current_stage' => $currentStage?->stage_code ?? null,
                'current_status' => ($currentData['current_status'] ?? null)?->status_code,
                'workflow'      => $this->workflowResponse($lead->uuid),
                'permissions'   => $stagePermissions,
                'note'          => 'Permissions based on your role at the current workflow stage.',
            ], 'Permission fetched successfully.');
        } catch (ModelNotFoundException) {
            return $this->error('Lead not found.', null, 404, 'LEAD_NOT_FOUND');
        } catch (Throwable $e) {
            return $this->serverError($e);
        }
    }
 
 
    public function eligibleUsersForAssignment(Request $request, string $lead_uuid): JsonResponse
    {
        try {
            $request->validate([
                'role_name' => 'nullable|string|max:255',
            ]);
 
            $authUser = $request->user();
            if (!$authUser) {
                return $this->error('Unauthenticated.', null, 401, 'UNAUTHENTICATED');
            }
            $authUser->loadMissing('roles');
 
            $lead = BLLeadCreation::query()->where('uuid', $lead_uuid)->firstOrFail();
 
            $currentData = $this->workflowService->getLeadCurrentData($lead->uuid);
            if (!$currentData['current_journey'] || !$currentData['current_stage']) {
                $this->workflowService->initiateLead(
                    $lead->uuid, $authUser, 'DRAFT',
                    'Workflow auto-initiated while fetching eligible users for assignment.', $request
                );
                $currentData = $this->workflowService->getLeadCurrentData($lead->uuid);
            }
 
            $currentStage = $currentData['current_stage'];
 
            $isAdmin           = $this->isAdmin($authUser);
            $isFirstAssignment = !BLLeadAssignment::query()->where('lead_uuid', $lead->uuid)->exists();
 
            $activeAssignment = BLLeadAssignment::query()
                ->with('assignedByUser.roles')
                ->where('lead_uuid', $lead->uuid)
                ->where('is_active', true)
                ->first();
            $currentHolderId  = $activeAssignment
                ? ($activeAssignment->assigned_to_user_id ?? $activeAssignment->assigned_to)
                : null;
 
        
            if (!$isFirstAssignment && $activeAssignment && !$isAdmin
                && (int) $currentHolderId !== (int) $authUser->id) {
                return $this->success([
                    'lead_uuid'           => $lead->uuid,
                    'current_stage'       => $currentStage,
                    'workflow'            => $this->workflowResponse($lead->uuid),
                    'eligible_roles'      => [],
                    'users'               => [],
                    'is_first_assignment' => false,
                    'is_locked'           => true,
                    'lock_reason'         => 'Only the current assignee can reassign this lead.',
                ], 'You are not the current assignee for this lead.');
            }
 
  
            $leadChannel = $this->leadChannel($lead);
            $includeCreator = false;
            $creatorSalesRoleCode = null;
            $isSapReturnToChannelManager = $this->isSapReturnToChannelManager(
                $activeAssignment,
                $authUser,
                $leadChannel
            );

            if ($isAdmin) {
                $allowedCodes = $this->allRoleCodes();
            } elseif ($isFirstAssignment) {
                $actorCodes = $this->userRoleCodes($authUser);
                $salesRole  = array_values(array_intersect($actorCodes, array_keys(self::FIRST_ASSIGNMENT_PAIRS)))[0] ?? null;

                $allowedCodes = ($salesRole && $this->isLeadCreator($authUser, $lead))
                    ? [self::FIRST_ASSIGNMENT_PAIRS[$salesRole]]
                    : [];
            } else {
                $allowedCodes = $this->allowedTargetRolesFor($authUser, $leadChannel);
                if ($allowedCodes === ['*']) {
                    $allowedCodes = $this->allRoleCodes();
                }

                // SAP-return restriction is channel-aware:
                // BM cannot return to SALES_PERSON and PBM cannot return to
                // PARTNER_SALES_PERSON. HORCU remains available in both cases.
                if ($isSapReturnToChannelManager) {
                    $allowedCodes = array_values(array_diff(
                        $allowedCodes,
                        [$this->salesRoleForChannel($leadChannel)]
                    ));
                }

                $managerRole = $this->managerRoleForChannel($leadChannel);
                if ($this->userHasRole($authUser, $managerRole) && !$isSapReturnToChannelManager) {
                    $includeCreator       = true;
                    $creatorSalesRoleCode = $this->salesRoleForChannel($leadChannel);
                }

                // The lead creator does not receive any extra transition outside MATRIX.
            }
 
 
            if ($request->filled('role_name')) {
                $wanted = strtoupper(str_replace([' ', '-'], '_', trim($request->input('role_name'))));
                $allowedCodes = array_values(array_intersect($allowedCodes, [$wanted]));
            }
 
            $users = $this->fetchUsersByRoleCodes($allowedCodes, $this->leadBranchId($lead));
 
 
            if ($includeCreator && $lead->created_by) {
                $alreadyListed = $users->contains(fn ($u) => (int) $u['user_id'] === (int) $lead->created_by);
                if (!$alreadyListed) {
                    $creator = User::query()->with('roles')->find($lead->created_by);
                    if ($creator && $this->userHasRole($creator, $creatorSalesRoleCode)) {
                        $users = $users->push($this->mapUser($creator, true))->values();
                    }
                }
            }
 
 
            if (!$isAdmin && $authUser?->id) {
                $users = $users
                    ->reject(fn ($u) => (int) $u['user_id'] === (int) $authUser->id)
                    ->values();
            }
 
            return $this->success([
                'lead_uuid'           => $lead->uuid,
                'current_stage'       => $currentStage,
                'workflow'            => $this->workflowResponse($lead->uuid),
                'eligible_roles'      => $allowedCodes,
                'users'               => $users,
                'is_first_assignment' => $isFirstAssignment,
                'is_locked'           => false,
            ], 'Eligible users fetched successfully.');
        } catch (ValidationException $e) {
            return $this->error('Validation failed.', $e->errors(), 422, 'VALIDATION_ERROR');
        } catch (ModelNotFoundException) {
            return $this->error('Lead not found.', null, 404, 'LEAD_NOT_FOUND');
        } catch (Throwable $e) {
            return $this->serverError($e);
        }
    }
 
    public function complete(Request $request, int $id): JsonResponse
    {
        try {
            $validated  = $request->validate(['remarks' => 'nullable|string']);
            $assignment = BLLeadAssignment::query()->findOrFail($id);
 
            $assignment->update($this->onlyExistingColumns('b_l_lead_assignments', [
                'assignment_status' => 'COMPLETED',
                'is_active'         => false,
                'completed_at'      => now(),
                'remarks'           => $validated['remarks'] ?? $assignment->remarks ?? $assignment->remark ?? null,
            ]));
 
            return $this->success([
                'assignment' => $assignment->fresh(['assignedByUser', 'assignedToUser']),
                'workflow'   => $this->workflowResponse($assignment->lead_uuid),
            ], 'Assignment completed successfully.');
        } catch (ValidationException $e) {
            return $this->error('Validation failed.', $e->errors(), 422, 'VALIDATION_ERROR');
        } catch (ModelNotFoundException) {
            return $this->error('Assignment not found.', null, 404, 'ASSIGNMENT_NOT_FOUND');
        } catch (Throwable $e) {
            return $this->serverError($e);
        }
    }
 
 
    public function cancel(Request $request, int $id): JsonResponse
    {
        try {
            $validated  = $request->validate(['remarks' => 'nullable|string']);
            $assignment = BLLeadAssignment::query()->findOrFail($id);
 
            $assignment->update($this->onlyExistingColumns('b_l_lead_assignments', [
                'assignment_status' => 'CANCELLED',
                'is_active'         => false,
                'completed_at'      => now(),
                'remarks'           => $validated['remarks'] ?? $assignment->remarks ?? $assignment->remark ?? null,
            ]));
 
            return $this->success([
                'assignment' => $assignment->fresh(['assignedByUser', 'assignedToUser']),
                'workflow'   => $this->workflowResponse($assignment->lead_uuid),
            ], 'Assignment cancelled successfully.');
        } catch (ValidationException $e) {
            return $this->error('Validation failed.', $e->errors(), 422, 'VALIDATION_ERROR');
        } catch (ModelNotFoundException) {
            return $this->error('Assignment not found.', null, 404, 'ASSIGNMENT_NOT_FOUND');
        } catch (Throwable $e) {
            return $this->serverError($e);
        }
    }
 
 
    public function myAssignmentView(Request $request, string $lead_uuid): JsonResponse
    {
        try {
            $authUser = $request->user();
            if (!$authUser) {
                return $this->error('Unauthenticated.', null, 401, 'UNAUTHENTICATED');
            }
 
            $lead = BLLeadCreation::query()->where('uuid', $lead_uuid)->firstOrFail();
 
            $myAssignment = BLLeadAssignment::query()
                ->with(['assignedByUser', 'assignedToUser'])
                ->where('lead_uuid', $lead->uuid)
                ->where(fn ($q) => $q->where('assigned_by_user_id', $authUser->id)->orWhere('assigned_by', $authUser->id))
                ->latest('id')->first();
 
            $assignedToMe = BLLeadAssignment::query()
                ->with(['assignedByUser'])
                ->where('lead_uuid', $lead->uuid)->where('is_active', true)
                ->where(fn ($q) => $q->where('assigned_to_user_id', $authUser->id)->orWhere('assigned_to', $authUser->id))
                ->first();
 
            if ($assignedToMe && !$myAssignment) {
                return $this->success([
                    'lead_uuid'   => $lead->uuid,
                    'lead_id'     => $lead->lead_id ?? null,
                    'workflow'    => $this->workflowResponse($lead->uuid),
                    'my_relation' => 'assigned_to_me',
                    'message'     => 'This lead is assigned to you.',
                    'assigned_by' => [
                        'id'   => $assignedToMe->assignedByUser?->id,
                        'name' => $assignedToMe->assignedByUser?->user_name,
                        'role' => $this->primaryRoleCode($assignedToMe->assignedByUser),
                    ],
                    'assigned_to' => null,
                    'assigned_at' => $assignedToMe->assigned_at,
                    'remarks'     => $assignedToMe->remarks ?? $assignedToMe->remark ?? null,
                ], 'My assignment view fetched successfully.');
            }
 
            if ($myAssignment) {
                return $this->success([
                    'lead_uuid'   => $lead->uuid,
                    'lead_id'     => $lead->lead_id ?? null,
                    'workflow'    => $this->workflowResponse($lead->uuid),
                    'my_relation' => 'i_assigned',
                    'message'     => 'You assigned this lead to ' . ($myAssignment->assignedToUser?->user_name ?? 'someone') . '.',
                    'assigned_by' => [
                        'id'   => $authUser->id,
                        'name' => $authUser->user_name,
                        'role' => $this->primaryRoleCode($authUser),
                    ],
                    'assigned_to' => [
                        'id'          => $myAssignment->assignedToUser?->id,
                        'name'        => $myAssignment->assignedToUser?->user_name,
                        'role'        => $this->primaryRoleCode($myAssignment->assignedToUser),
                        'designation' => $myAssignment->assignedToUser?->user_designation ?? null,
                        'email'       => $myAssignment->assignedToUser?->user_email ?? null,
                        'mobile'      => $myAssignment->assignedToUser?->user_mobile_number ?? null,
                    ],
                    'assigned_at' => $myAssignment->assigned_at,
                    'remarks'     => $myAssignment->remarks ?? $myAssignment->remark ?? null,
                ], 'My assignment view fetched successfully.');
            }
 
            return $this->success([
                'lead_uuid'   => $lead->uuid,
                'lead_id'     => $lead->lead_id ?? null,
                'workflow'    => $this->workflowResponse($lead->uuid),
                'my_relation' => 'none',
                'message'     => 'You have no assignment activity on this lead.',
                'assigned_by' => null,
                'assigned_to' => null,
                'assigned_at' => null,
                'remarks'     => null,
            ], 'My assignment view fetched successfully.');
        } catch (ModelNotFoundException) {
            return $this->error('Lead not found.', null, 404, 'LEAD_NOT_FOUND');
        } catch (Throwable $e) {
            return $this->serverError($e);
        }
    }
 
    private function canAssign(
        $actor,
        User $assignedTo,
        $lead,
        bool $isFirst,
        bool $isAdmin,
        ?BLLeadAssignment $activeAssignment = null
    ): array
    {
        if ($isAdmin) {
            return [true, '', ''];
        }

        if ($isFirst) {
            $actorCodes = $this->userRoleCodes($actor);
            $salesRole  = array_values(array_intersect($actorCodes, array_keys(self::FIRST_ASSIGNMENT_PAIRS)))[0] ?? null;

            if (!$salesRole) {
                return [false, 'FIRST_ASSIGNMENT_ONLY_BY_SALES_PERSON',
                    'The first assignment can only be made by the Sales Person (or Partner Sales Person) who punched the lead.'];
            }

            if (!$this->isLeadCreator($actor, $lead)) {
                return [false, 'FIRST_ASSIGNMENT_ONLY_BY_LEAD_CREATOR',
                    'Only whoever created the lead can make the first assignment.'];
            }

            $expectedBranchManagerRole = self::FIRST_ASSIGNMENT_PAIRS[$salesRole];
            if (!$this->userHasRole($assignedTo, $expectedBranchManagerRole)) {
                return [false, 'FIRST_ASSIGNMENT_MUST_BE_BRANCH_MANAGER',
                    'The first assignment can only be made to the applicable Branch Manager.'];
            }

            return [true, '', ''];
        }

        $leadChannel = $this->leadChannel($lead);
        $managerRole = $this->managerRoleForChannel($leadChannel);
        $salesRole   = $this->salesRoleForChannel($leadChannel);

        // When SAP returns a lead directly to the channel manager, that manager
        // cannot send the lead back to the channel Sales Person. Every other
        // matrix transition remains available, including assignment to HORCU.
        if ($this->isSapReturnToChannelManager($activeAssignment, $actor, $leadChannel)
            && $this->userHasRole($assignedTo, $salesRole)) {
            return [
                false,
                'SALES_ASSIGNMENT_NOT_ALLOWED_AFTER_SAP_RETURN',
                sprintf(
                    'This lead was returned by the Sanction Authorized Person. The %s cannot assign it back to the %s.',
                    $this->roleLabel($managerRole),
                    $this->roleLabel($salesRole)
                ),
            ];
        }

        // The applicable channel manager can send the lead back to the Sales
        // Person who created it, except in the SAP-return case handled above.
        if ($lead->created_by
            && (int) $lead->created_by === (int) $assignedTo->id
            && $this->userHasRole($actor, $managerRole)
            && $this->userHasRole($assignedTo, $salesRole)) {
            return [true, '', ''];
        }

        $allowed = $this->allowedTargetRolesFor($actor, $leadChannel);

        if ($allowed === ['*']) {
            return [true, '', ''];
        }

        if (empty($allowed)) {
            return [false, 'ASSIGNMENT_TRANSITION_NOT_ALLOWED',
                'Your current role is not permitted to assign this lead.'];
        }

        $targetCodes = $this->userRoleCodes($assignedTo);

        if (empty(array_intersect($allowed, $targetCodes))) {
            return [false, 'ASSIGNMENT_TRANSITION_NOT_ALLOWED',
                'You are not allowed to assign this lead to the selected user from your current role.'];
        }

        // Employee and partner branch-level targets must belong to the same
        // branch as the lead. Shared Head Office roles are not branch-bound.
        if (!empty(array_intersect($targetCodes, self::BRANCH_SCOPED_ROLE_CODES))) {
            $leadBranchId = $this->leadBranchId($lead);
            if ($leadBranchId && (int) $assignedTo->branch_id !== (int) $leadBranchId) {
                return [false, 'ASSIGNEE_MUST_BE_SAME_BRANCH',
                    'The selected branch-level assignee must belong to the same branch as this lead.'];
            }
        }

        return [true, '', ''];
    }

    /**
     * Detect the direct SAP -> channel manager handoff for the current active
     * assignment. Employee leads use BRANCH_MANAGER; partner leads use
     * PARTNER_BRANCH_MANAGER.
     */
    private function isSapReturnToChannelManager(
        ?BLLeadAssignment $activeAssignment,
        $actor,
        string $leadChannel
    ): bool {
        if (!$activeAssignment || !$actor) {
            return false;
        }

        if (!$this->userHasRole($actor, $this->managerRoleForChannel($leadChannel))) {
            return false;
        }

        $currentHolderId = $activeAssignment->assigned_to_user_id
            ?? $activeAssignment->assigned_to;

        if ($currentHolderId && (int) $currentHolderId !== (int) $actor->id) {
            return false;
        }

        $assignedBy = $activeAssignment->assignedByUser;

        if (!$assignedBy) {
            $assignedByUserId = $activeAssignment->assigned_by_user_id
                ?? $activeAssignment->assigned_by;

            if (!$assignedByUserId) {
                return false;
            }

            $assignedBy = User::query()
                ->with('roles')
                ->find($assignedByUserId);
        }

        return $this->userHasRole($assignedBy, 'SANCTION_AUTHORIZED_PERSON');
    }

    /**
     * Determine whether the lead belongs to the employee or partner channel.
     * The creator role is the primary source because the first assignment is
     * restricted to SALES_PERSON/PARTNER_SALES_PERSON. Assignment history is
     * used as a safe fallback for legacy records.
     */
    private function leadChannel($lead): string
    {
        if (!$lead) {
            return self::CHANNEL_EMPLOYEE;
        }

        try {
            if (!empty($lead->created_by)) {
                $creator = User::query()
                    ->with('roles')
                    ->find($lead->created_by);

                if ($creator && !empty(array_intersect(
                    $this->userRoleCodes($creator),
                    self::PARTNER_CHANNEL_ROLE_CODES
                ))) {
                    return self::CHANNEL_PARTNER;
                }
            }
        } catch (Throwable) {
            // Fall through to assignment-history detection.
        }

        try {
            $firstAssignment = BLLeadAssignment::query()
                ->where('lead_uuid', $lead->uuid)
                ->oldest('id')
                ->first();

            $firstTargetRole = $this->normalizeRoleCode(
                $firstAssignment?->assigned_role
                    ?? $firstAssignment?->assigned_to_role
                    ?? null
            );

            if ($firstTargetRole && in_array(
                $firstTargetRole,
                self::PARTNER_CHANNEL_ROLE_CODES,
                true
            )) {
                return self::CHANNEL_PARTNER;
            }
        } catch (Throwable) {
            // Legacy/incomplete records default to the employee channel.
        }

        return self::CHANNEL_EMPLOYEE;
    }

    private function managerRoleForChannel(string $leadChannel): string
    {
        return $leadChannel === self::CHANNEL_PARTNER
            ? 'PARTNER_BRANCH_MANAGER'
            : 'BRANCH_MANAGER';
    }

    private function salesRoleForChannel(string $leadChannel): string
    {
        return $leadChannel === self::CHANNEL_PARTNER
            ? 'PARTNER_SALES_PERSON'
            : 'SALES_PERSON';
    }

    private function roleLabel(string $roleCode): string
    {
        return ucwords(strtolower(str_replace('_', ' ', $roleCode)));
    }

    private function normalizeRoleCode(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return strtoupper(str_replace([' ', '-'], '_', trim($value)));
    }

    private function leadBranchId($lead): ?int
    {
        $branchId = $lead->branch_id ?? $lead->creator?->branch_id;
        return $branchId ? (int) $branchId : null;
    }
 
    private function isLeadCreator($user, $lead): bool
    {
        return $user
            && $lead
            && $lead->created_by
            && (int) $lead->created_by === (int) $user->id;
    }
 
 
    private function allowedTargetRolesFor($user, string $leadChannel): array
    {
        $allowed = [];

        foreach ($this->userRoleCodes($user) as $code) {
            if (!isset(self::MATRIX[$code])) {
                continue;
            }

            if (self::MATRIX[$code] === ['*']) {
                return ['*'];
            }

            $allowed = array_merge($allowed, self::MATRIX[$code]);
            $allowed = array_merge(
                $allowed,
                self::CHANNEL_MATRIX[$leadChannel][$code] ?? []
            );
        }

        $allowed = array_values(array_unique($allowed));

        // Do not expose employee branch roles for partner leads or partner
        // branch roles for employee leads. Shared Head Office roles remain.
        $blockedChannelRoles = $leadChannel === self::CHANNEL_PARTNER
            ? self::EMPLOYEE_CHANNEL_ROLE_CODES
            : self::PARTNER_CHANNEL_ROLE_CODES;

        return array_values(array_diff($allowed, $blockedChannelRoles));
    }


    private function userRoleCodes($user): array
    {
        if (!$user) {
            return [];
        }
 
        try {
            $user->loadMissing('roles');
        } catch (Throwable) {
           
        }
 
        $codes = [];
 
        if (isset($user->roles)) {
            foreach ($user->roles as $role) {
                $value = $role->code ?? $role->name ?? $role->display_name ?? null;
                if ($value) {
                    $codes[] = strtoupper(str_replace([' ', '-'], '_', trim($value)));
                }
            }
        }
 
  
        if (empty($codes) && !empty($user->user_designation)) {
            $codes[] = strtoupper(str_replace([' ', '-'], '_', trim($user->user_designation)));
        }
 
        return array_values(array_unique(array_filter($codes)));
    }
 
    private function userHasRole($user, string $code): bool
    {
        return in_array(strtoupper($code), $this->userRoleCodes($user), true);
    }
 
    private function isAdmin($user): bool
    {
        $codes = $this->userRoleCodes($user);
        return in_array('ADMINISTRATIVE', $codes, true)
            || in_array('SUPER_ADMINISTRATIVE', $codes, true);
    }
 
    private function primaryRoleCode($user): ?string
    {
        return $this->userRoleCodes($user)[0] ?? null;
    }
 
  
    private function allRoleCodes(): array
    {
        $codes = [
            'SALES_PERSON',
            'BRANCH_MANAGER',
            'BRANCH_CREDIT_CHECKER',
            'HEAD_OFFICE_CREDIT_CHECKER',
            'HEAD_OFFICE_CREDIT_OPERATIONS',
            'SANCTION_AUTHORIZED_PERSON',
            'HEAD_OFFICE_RISK_CONTROL_UNIT',
            'ACCOUNT',
            'PARTNER_SALES_PERSON',
            'PARTNER_BRANCH_MANAGER',
            'PARTNER_CREDIT_PERSON',
            'ADMINISTRATIVE',
            'SUPER_ADMINISTRATIVE',
        ];
        try {
            $codes = \Modules\UserManagement\Models\Role::query()
                ->pluck('code')->filter()
                ->map(fn ($c) => strtoupper(trim($c)))->unique()->values()->toArray();
        } catch (Throwable) {
           
        }
 
       
        return array_values(array_diff($codes, self::NON_EMPLOYEE_ROLE_CODES));
    }
 
   
    private function fetchUsersByRoleCodes(array $roleCodes, ?int $branchId = null)
    {
        if (empty($roleCodes)) {
            return collect();
        }
 
        $codes = collect($roleCodes)
            ->map(fn ($c) => strtoupper(trim($c)))
            ->reject(fn ($c) => in_array($c, self::NON_EMPLOYEE_ROLE_CODES, true))
            ->unique()
            ->values()
            ->toArray();
 
        if (empty($codes)) {
            return collect();
        }
 
        // Role codes here span both channels (employee: BRANCH_MANAGER,
        // HEAD_OFFICE_CREDIT_CHECKER, ... / partner: PARTNER_SALES_PERSON,
        // PARTNER_BRANCH_MANAGER, ...) — user_type is implied by which role
        // a user actually holds, so it is not filtered separately here.
        $query = User::query()
            ->with('roles')
            ->whereHas('roles', function ($q) use ($codes) {
                $q->whereIn(DB::raw('UPPER(code)'), $codes)
                  ->orWhereIn(DB::raw("UPPER(REPLACE(REPLACE(name, '-', '_'), ' ', '_'))"), $codes);
            })
            ->whereDoesntHave('roles', function ($q) {
                $q->whereIn(DB::raw('UPPER(code)'), self::NON_EMPLOYEE_ROLE_CODES);
            })
            ->when(Schema::hasColumn('users', 'is_active'), fn ($q) => $q->where('is_active', true));
 
        // Branch Manager / Branch Credit Manager targets (and their partner-channel
        // equivalents) must be in the lead's own branch; any other role in the
        // same $codes list (e.g. a Head Office desk) is not branch-bound and
        // passes through untouched.
        if ($branchId && Schema::hasColumn('users', 'branch_id')) {
            $query->where(function ($q) use ($branchId) {
                $q->where('branch_id', $branchId)
                  ->orWhereDoesntHave('roles', function ($qq) {
                      $qq->whereIn(DB::raw('UPPER(code)'), self::BRANCH_SCOPED_ROLE_CODES)
                         ->orWhereIn(DB::raw("UPPER(REPLACE(REPLACE(name, '-', '_'), ' ', '_'))"), self::BRANCH_SCOPED_ROLE_CODES);
                  });
            });
        }
 
        return $query
            ->orderBy(Schema::hasColumn('users', 'user_name') ? 'user_name' : 'id')
            ->get()
            ->map(fn ($u) => $this->mapUser($u))
            ->values();
    }
 
    private function mapUser(User $u, bool $isCreator = false): array
    {
        return [
            'user_id'     => $u->id,
            'name'        => $u->user_name ?? null,
            'email'       => $u->user_email ?? null,
            'mobile'      => $u->user_mobile_number ?? null,
            'role'        => $this->primaryRoleCode($u),
            'designation' => $u->user_designation ?? null,
            'is_creator'  => $isCreator,
        ];
    }
 
  
    private function hasBlAccess($user): bool
    {
        if (!$user) return false;
        if (!method_exists($user, 'products')) return true;
 
        try {
            $keyColumn = Schema::hasColumn('products', 'key') ? 'key'
                : (Schema::hasColumn('products', 'product_key') ? 'product_key'
                : (Schema::hasColumn('products', 'slug') ? 'slug' : null));
 
            return $keyColumn ? $user->products()->where($keyColumn, 'bl')->exists() : true;
        } catch (Throwable) {
            return true;
        }
    }
 
   
    private function getStagePermissionsForUser(?object $currentStage, $user): array
    {
        if (!$currentStage || !$user) return $this->noPermissions();
 
        $candidates = $this->stageRoleMatchValues($user);
        if (empty($candidates)) return $this->noPermissions();
 
        $permission = BLStageRolePermission::query()
            ->where('stage_id', $currentStage->id)
            ->where(function ($q) use ($candidates) {
                $q->whereIn('role_name', $candidates)
                  ->orWhereIn(
                      DB::raw("UPPER(REPLACE(REPLACE(role_name, '-', '_'), ' ', '_'))"),
                      array_map(fn ($v) => strtoupper(str_replace([' ', '-'], '_', $v)), $candidates)
                  );
            })
            ->first();
 
        if (!$permission) return $this->noPermissions();
 
        return [
            'can_view'            => (bool) $permission->can_view,
            'can_edit'            => (bool) $permission->can_edit,
            'can_assign'          => (bool) $permission->can_assign,
            'can_change_status'   => (bool) $permission->can_change_status,
            'can_upload_document' => (bool) $permission->can_upload_document,
            'can_move_next'       => (bool) $permission->can_move_next,
            'can_move_previous'   => (bool) $permission->can_move_previous,
        ];
    }
 
    private function stageRoleMatchValues($user): array
    {
        $values = [];
        try {
            $user->loadMissing('roles');
            foreach ($user->roles as $r) {
                foreach ([$r->code, $r->name, $r->display_name] as $v) {
                    if ($v) $values[] = trim($v);
                }
            }
        } catch (Throwable) {
            
        }
        if (empty($values) && !empty($user->user_designation)) {
            $values[] = trim($user->user_designation);
        }
        return array_values(array_unique(array_filter($values)));
    }
 
    private function viewOnlyPermissions(): array
    {
        return [
            'can_view' => true, 'can_edit' => false, 'can_assign' => false,
            'can_change_status' => false, 'can_upload_document' => false,
            'can_move_next' => false, 'can_move_previous' => false,
        ];
    }
 
    private function noPermissions(): array
    {
        return [
            'can_view' => false, 'can_edit' => false, 'can_assign' => false,
            'can_change_status' => false, 'can_upload_document' => false,
            'can_move_next' => false, 'can_move_previous' => false,
        ];
    }
 
    /**
     * Return the latest workflow stage and status for a lead.
     */
    private function workflowResponse(string $leadUuid): array
    {
        $workflowData = $this->workflowService->getLeadCurrentData($leadUuid);
 
        $journey = $workflowData['current_journey'] ?? null;
        $stage   = $workflowData['current_stage'] ?? null;
        $status  = $workflowData['current_status'] ?? null;
 
        return [
            'journey_id' => $journey?->id,
            'stage' => $stage ? [
                'id'                 => $stage->id,
                'stage_code'         => $stage->stage_code,
                'stage_name'         => $stage->stage_name,
                'sequence_no'        => $stage->sequence_no,
                'team_name'          => $stage->team_name,
                'is_reversible'      => (bool) $stage->is_reversible,
                'is_terminal'        => (bool) $stage->is_terminal,
                'allow_reassignment' => (bool) $stage->allow_reassignment,
                'allow_edit'         => (bool) $stage->allow_edit,
            ] : null,
            'status' => $status ? [
                'id'          => $status->id,
                'stage_id'    => $status->stage_id,
                'status_code' => $status->status_code,
                'status_name' => $status->status_name,
                'is_final'    => (bool) $status->is_final,
            ] : null,
        ];
    }
 
 
    private function onlyExistingColumns(string $table, array $payload): array
    {
        return collect($payload)
            ->filter(fn ($value, $key) => Schema::hasColumn($table, $key))
            ->toArray();
    }
 
 
    private function success($data = null, string $message = 'Success', int $status = 200, string $code = 'SUCCESS'): JsonResponse
    {
        return response()->json([
            'success' => true, 'status_code' => $status, 'code' => $code,
            'message' => $message, 'data' => $data,
        ], $status);
    }
 
    private function error(string $message, $data = null, int $status = 400, string $code = 'ERROR'): JsonResponse
    {
        return response()->json([
            'success' => false, 'status_code' => $status, 'code' => $code,
            'message' => $message, 'data' => $data,
        ], $status);
    }
 
    private function serverError(Throwable $e): JsonResponse
    {
        return $this->error(
            'Something went wrong.',
            app()->environment('local') ? $e->getMessage() : null,
            500, 'SERVER_ERROR'
        );
    }
}