<?php

namespace Modules\BLLeads\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\BLApplicantDetails\Models\BLApplicantDetails;
use Modules\BLLeadCreation\Models\BLLeadCreation;
use Modules\UserManagement\Models\User;
use Throwable;

class BLLeadsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        try {
            $authUser = auth()->user();

            if (!$authUser) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized user.',
                ], 401);
            }

            // $authUser->loadMissing(['userType', 'roles']);
            $authUser->loadMissing(['userType', 'roles', 'branches']);

            $search = trim((string) $request->get('search'));
            $userType = optional($authUser->userType)->name;
            $roleCode = optional($authUser->primary_role)->code;
            $branchFilter = trim((string) $request->get('branch_id', ''));

            if (
                $branchFilter !== '' &&
                strtolower($branchFilter) !== 'all' &&
                (!ctype_digit((string) $branchFilter) || (int) $branchFilter <= 0)
            ) {
                return response()->json([
                    'success' => false,
                    'message' => 'The branch_id must be "all" or a valid positive integer.',
                ], 422);
            }

            $branchFilter = $branchFilter !== '' && strtolower($branchFilter) !== 'all'
                ? (int) $branchFilter
                : null;

            $query = BLLeadCreation::query()
                ->select([
                    'id',
                    'uuid',
                    'lead_id',
                    'created_by',
                    'branch_id',
                    'loan_type',
                    'customer_type',
                    'is_reloan',
                    'previous_lead_uuid',
                    'sub_loan_type',
                    'mobile_number',
                    'loan_amount',
                    'loan_tenur',
                    'created_at',
                    'updated_at',
                ])
                ->with([
'creator:id,user_name',
                    'currentJourney' => function ($query) {
                        $query->select([
                            'b_l_journeys.id',
                            'b_l_journeys.lead_id',
                            'b_l_journeys.stage',
                            'b_l_journeys.status',
                            'b_l_journeys.is_latest',
                        ]);
                    },

                    'schemeSelect',
                    'latestCam',
                    'disbursement',
                    'branch:id,name,branch_code',
                    // 'creator.branch:id,name,branch_code',

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


                    'currentAssignment' => function ($query) {
                        $query->where('is_active', true)
                            ->with([
                                'assignedToUser:id,user_name,user_email,user_mobile_number,user_designation',
                            ])
                            ->select([
                                'id',
                                'lead_uuid',
                                'assigned_to_user_id',
                                'assigned_to',
                                'assigned_to_role',
                                'assigned_at',
                                'remarks',
                            ]);
                    },
                ]);

            // $applyExactBranchScope = function ($branchQuery, int $branchId): void {
            //     $branchQuery->where(function ($query) use ($branchId) {
            //         $query->where('branch_id', $branchId)
            //             ->orWhere(function ($legacyQuery) use ($branchId) {
            //                 $legacyQuery->whereNull('branch_id')
            //                     ->whereHas('creator', function ($creatorQuery) use ($branchId) {
            //                         $creatorQuery->where('branch_id', $branchId);
            //                     });
            //             });
            //     });
            // };
            $applyExactBranchScope = function ($branchQuery, int $branchId): void {
                $branchQuery->where('branch_id', $branchId);
            };

            // if (
            //     $userType === 'employee' &&
            //     in_array($roleCode, [
            //         'SUPER_ADMINISTRATIVE', 'ADMINISTRATIVE',
            //         'CREDIT_MAKER', 'CREDIT_CHECKER', 'CREDIT_MANAGER',
            //         'OPS_MAKER', 'OPS_CHECKER', 'OPS_MANAGER',
            //         // Head Office desks: see every case, across every branch.
            //         'HEAD_OFFICE_CREDIT_CHECKER', 'HEAD_OFFICE_CREDIT_OPERATIONS',
            //         'SANCTION_AUTHORIZED_PERSON', 'HEAD_OFFICE_RISK_CONTROL_UNIT',
            //     ])
            // ) {

            //     // Head Office users see every branch by default. They may
            //     // optionally filter the list with ?branch_id={branchId}.
            //     if ($branchFilter !== null) {
            //         $applyExactBranchScope($query, $branchFilter);
            //     }

            // } elseif (
            //     $userType === 'employee' &&
            //     in_array($roleCode, ['BRANCH_MANAGER', 'BRANCH_CREDIT_MANAGER', 'PARTNER BRANCH_MANAGER']) &&
            //     !empty($authUser->branch_id)
            // ) {
            //     $branchId = $authUser->branch_id;

            //     // Branch managers can see leads of their exact branch only.
            //     // Do not include child/sub-branches. For legacy leads without
            //     // branch_id, fall back to the creator's branch.
            //     $applyExactBranchScope($query, (int) $branchId);

            // } elseif (

            if (
                $userType === 'employee' &&
                $roleCode === 'SUPER_ADMINISTRATIVE'
            ) {

                // Sirf Super Administrative ko sab branches, restriction-free.
                if ($branchFilter !== null) {
                    $applyExactBranchScope($query, $branchFilter);
                }

            } elseif (
                $userType === 'employee' &&
                in_array($roleCode, [
                    'ADMINISTRATIVE',

                    // Area Manager
                    'AREA_MANAGER',

                    // Account
                    'ACCOUNT',

                    // Collection
                    'COLLECTION_EXECUTIVE',
                    'COLLECTION_MANAGER',

                    // Credit
                    'CREDIT_MAKER',
                    'CREDIT_CHECKER',
                    'CREDIT_MANAGER',

                    // Operations
                    'OPS_MAKER',
                    'OPS_CHECKER',
                    'OPS_MANAGER',

                    // Head Office
                    'HEAD_OFFICE_CREDIT_CHECKER',
                    'HEAD_OFFICE_CREDIT_OPERATIONS',

                    'SANCTION_AUTHORIZED_PERSON',
                    'HEAD_OFFICE_RISK_CONTROL_UNIT',

                    // Branch
                    'BRANCH_MANAGER',
                    'BRANCH_CREDIT_MANAGER',
                    'PARTNER BRANCH_MANAGER',

                ], true)
            ) {
                // In sabko sirf unki assigned branches (multi-branch) ki leads dikhengi.
                $branchIds = $authUser->branches->pluck('id')->map(fn($id) => (int) $id)->all();

                if (empty($branchIds) && !empty($authUser->branch_id)) {
                    $branchIds = [(int) $authUser->branch_id];
                }

                if (empty($branchIds)) {
                    // Koi branch assign nahi -> koi lead na dikhe.
                    $query->whereRaw('1 = 0');
                } elseif ($branchFilter !== null) {
                    abort_unless(
                        in_array($branchFilter, $branchIds, true),
                        403,
                        'You are not allowed to view leads of this branch.'
                    );
                    $applyExactBranchScope($query, $branchFilter);
                    // } else {
                    //     $query->where(function ($branchQuery) use ($branchIds) {
                    //         $branchQuery->whereIn('branch_id', $branchIds)
                    //             ->orWhere(function ($legacyQuery) use ($branchIds) {
                    //                 $legacyQuery->whereNull('branch_id')
                    //                     ->whereHas('creator', function ($creatorQuery) use ($branchIds) {
                    //                         $creatorQuery->whereIn('branch_id', $branchIds);
                    //                     });
                    //             });
                    //     });
                    // }

                } else {
                    $query->whereIn('branch_id', $branchIds);
                }

            } elseif (
                $userType === 'employee' &&
                in_array($roleCode, ['SALES_PERSON', 'SALES_MANAGER'])
            ) {
                $query->where('created_by', $authUser->id);

            } elseif (
                $userType === 'partner' &&
                $roleCode === 'PARTNER_ADMINISTRATIVE'
            ) {
                $organizationId = $authUser->organization_id;
                $branchId = $authUser->branch_id;

                $peerUsersQuery = User::query()
                    ->where('organization_id', $organizationId)
                    ->where('user_type_id', $authUser->user_type_id);

                if (!empty($branchId)) {
                    $peerUsersQuery->where('branch_id', $branchId);
                }

                $allowedUserIds = $peerUsersQuery->pluck('id')->toArray();
                $allowedUserIds = array_unique([...$allowedUserIds, $authUser->id]);

                $query->whereIn('created_by', $allowedUserIds);

            } elseif (
                $userType === 'partner' &&
                $roleCode === 'PARTNER_SUB_USER'
            ) {
                $query->where('created_by', $authUser->id);

            } else {

                $query->where('created_by', $authUser->id);
            }


            if (!empty($search)) {
                $query->where(function ($q) use ($search) {
                    $q->where('uuid', 'LIKE', "%{$search}%")
                        ->orWhere('lead_id', 'LIKE', "%{$search}%")
                        ->orWhere('mobile_number', 'LIKE', "%{$search}%")
                        ->orWhereHas('applicantDetails', function ($applicantQuery) use ($search) {
                            $applicantQuery->where('full_name', 'LIKE', "%{$search}%")
                                ->orWhere('alternate_contact_number', 'LIKE', "%{$search}%")
                                ->orWhere('applicant_type', 'LIKE', "%{$search}%");
                        });
                });
            }

            $leads = $query->latest('id')->get();


            $formattedLeads = $leads->map(function (BLLeadCreation $lead) {
                $workflowStatus = $this->resolveWorkflowStatus($lead);

                $pendingWith = null;

                if ($lead->currentAssignment) {
                    $assignedUser = $lead->currentAssignment->assignedToUser;

                    $pendingWith = [
                        'assignment_id' => $lead->currentAssignment->id,
                        'user_id' => $lead->currentAssignment->assigned_to_user_id
                            ?? $lead->currentAssignment->assigned_to,
                        'name' => $assignedUser?->user_name,
                        'email' => $assignedUser?->user_email,
                        'mobile' => $assignedUser?->user_mobile_number,
                        'role' => $lead->currentAssignment->assigned_to_role
                            ?? $assignedUser?->user_designation,
                        'assigned_at' => optional($lead->currentAssignment->assigned_at)
                            ->format('Y-m-d H:i:s'),
                        'remarks' => $lead->currentAssignment->remarks,
                    ];
                }

                // The lead carries its own branch since 2026-07-27; older
                // leads created before that fall back to the creator's branch.
                // $branch = $lead->branch ?? $lead->creator?->branch;

                $branch = $lead->branch;

                return [
                    'uuid' => $lead->uuid,
                    'lead_id' => $lead->lead_id,
                    // 'stage'         => optional($lead->currentJourney)->stage,
                    // 'status'        => optional($lead->currentJourney)->status,
    'created_by' => $lead->created_by,
    'created_by_name' => $lead->creator?->user_name,
                    'stage' => $lead->currentJourney?->stage,
                    'status' => $workflowStatus,
                    'journey_status' => $lead->currentJourney?->status,
                    'loan_type' => $lead->loan_type,
                    'customer_type' => $lead->customer_type,
                    'is_reloan' => (bool) $lead->is_reloan,
                    'previous_lead_uuid' => $lead->previous_lead_uuid,
                    'sub_loan_type' => $lead->sub_loan_type,
                    'mobile_number' => $lead->mobile_number,
                    'loan_amount' => $lead->loan_amount,
                    'loan_tenur' => $lead->loan_tenur,

                    'branch' => $branch ? [
                        'id' => $branch->id,
                        'name' => $branch->name,
                        'branch_code' => $branch->branch_code,
                    ] : null,
                    'branch_name' => $branch?->name,

                    'applicants' => $lead->applicantDetails->map(function (BLApplicantDetails $applicant) {
                        return [
                            'applicant_id' => $applicant->applicant_id,
                            'applicant_type' => $applicant->applicant_type,
                            'name' => $applicant->full_name,
                            'phone_number' => $applicant->alternate_contact_number,
                        ];
                    })->values(),

                    'pending_with' => $pendingWith,

                    'created_at' => optional($lead->created_at)->format('Y-m-d H:i:s'),
                    'updated_at' => optional($lead->updated_at)->format('Y-m-d H:i:s'),
                ];
            })->values();

            return response()->json([
                'success' => true,
                'message' => 'All BL leads fetched successfully.',
                'count' => $formattedLeads->count(),
                'data' => $formattedLeads,
            ], 200);

        } catch (Throwable $e) {
            Log::error('BL Leads Fetch Error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Something went wrong while fetching BL leads.',
            ], 500);
        }
    }

    private function resolveWorkflowStatus(BLLeadCreation $lead): string
    {

        if ($lead->disbursement !== null) {
            return 'disbursed';
        }


        if ($lead->latestCam !== null) {
            $camStatus = strtolower(
                trim((string) $lead->latestCam->status)
            );

            if (in_array($camStatus, ['approve', 'approved'], true)) {
                return 'disbursement_pending';
            }

            if (in_array($camStatus, ['reject', 'rejected'], true)) {
                return 'cam_rejected';
            }

            return 'cam_approval_pending';
        }


        if ($lead->schemeSelect !== null) {
            return 'cam_pending';
        }


        $journeyStage = strtolower(
            trim((string) $lead->currentJourney?->stage)
        );

        $journeyStage = str_replace(
            [' ', '-', '/'],
            '_',
            $journeyStage
        );

        if (
            in_array($journeyStage, [
                'lead_submit',
                'lead_submitted',
                'submitted',
            ], true)
        ) {
            return 'scheme_select_pending';
        }


        return strtolower(
            (string) ($lead->currentJourney?->status ?? 'draft')
        );
    }
}

// namespace Modules\BLLeads\Http\Controllers;

// use App\Http\Controllers\Controller;
// use Illuminate\Http\JsonResponse;
// use Illuminate\Http\Request;
// use Illuminate\Support\Facades\Log;
// use Modules\BLApplicantDetails\Models\BLApplicantDetails;
// use Modules\BLLeadCreation\Models\BLLeadCreation;
// use Modules\UserManagement\Models\User;
// use Throwable;

// class BLLeadsController extends Controller
// {
//     public function index(Request $request): JsonResponse
//     {
//         try {
//             $authUser = auth()->user();

//             if (!$authUser) {
//                 return response()->json([
//                     'success' => false,
//                     'message' => 'Unauthorized user.',
//                 ], 401);
//             }

//             $authUser->loadMissing(['userType', 'roles']);

//             $search   = trim((string) $request->get('search'));
//             $userType = optional($authUser->userType)->name;
//             $roleCode = optional($authUser->primary_role)->code;
//             $branchFilter = trim((string) $request->get('branch_id', ''));

//             if (
//                 $branchFilter !== '' &&
//                 strtolower($branchFilter) !== 'all' &&
//                 (!ctype_digit((string) $branchFilter) || (int) $branchFilter <= 0)
//             ) {
//                 return response()->json([
//                     'success' => false,
//                     'message' => 'The branch_id must be "all" or a valid positive integer.',
//                 ], 422);
//             }

//             $branchFilter = $branchFilter !== '' && strtolower($branchFilter) !== 'all'
//                 ? (int) $branchFilter
//                 : null;

//             $query = BLLeadCreation::query()
//                 ->select([
//                     'id', 'uuid', 'lead_id', 'created_by', 'branch_id',
//                     'loan_type', 'customer_type', 'sub_loan_type',
//                     'mobile_number', 'loan_amount', 'loan_tenur',
//                     'created_at', 'updated_at',
//                 ])
//                 ->with([

//                    'currentJourney' => function ($query) {
//                     $query->select([
//                         'b_l_journeys.id', 'b_l_journeys.lead_id',
//                         'b_l_journeys.stage', 'b_l_journeys.status', 'b_l_journeys.is_latest',
//                     ]);
//                     },

//                     'schemeSelect',
//                     'latestCam',
//                     'disbursement',
//                     'branch:id,name,branch_code',
//                     // 'creator.branch:id,name,branch_code',

//                     'applicantDetails' => function ($query) {
//                         $query->select([
//                             'id', 'lead_uuid', 'lead_id',
//                             'applicant_id', 'applicant_type',
//                             'full_name', 'alternate_contact_number',
//                         ]);
//                     },


//                     'currentAssignment' => function ($query) {
//                         $query->where('is_active', true)
//                             ->with([
//                                 'assignedToUser:id,user_name,user_email,user_mobile_number,user_designation',
//                             ])
//                             ->select([
//                                 'id', 'lead_uuid', 'assigned_to_user_id', 'assigned_to',
//                                 'assigned_to_role', 'assigned_at', 'remarks',
//                             ]);
//                     },
//                 ]);

//             // $applyExactBranchScope = function ($branchQuery, int $branchId): void {
//             //     $branchQuery->where(function ($query) use ($branchId) {
//             //         $query->where('branch_id', $branchId)
//             //             ->orWhere(function ($legacyQuery) use ($branchId) {
//             //                 $legacyQuery->whereNull('branch_id')
//             //                     ->whereHas('creator', function ($creatorQuery) use ($branchId) {
//             //                         $creatorQuery->where('branch_id', $branchId);
//             //                     });
//             //             });
//             //     });
//             // };

//             $applyExactBranchScope = function ($branchQuery, int $branchId): void {
//     $branchQuery->where('branch_id', $branchId);
// };


//             if (
//                 $userType === 'employee' &&
//                 in_array($roleCode, [
//                     'SUPER_ADMINISTRATIVE', 'ADMINISTRATIVE',
//                     'CREDIT_MAKER', 'CREDIT_CHECKER', 'CREDIT_MANAGER',
//                     'OPS_MAKER', 'OPS_CHECKER', 'OPS_MANAGER',
//                     // Head Office desks: see every case, across every branch.
//                     'HEAD_OFFICE_CREDIT_CHECKER', 'HEAD_OFFICE_CREDIT_OPERATIONS',
//                     'SANCTION_AUTHORIZED_PERSON', 'HEAD_OFFICE_RISK_CONTROL_UNIT',
//                 ])
//             ) {

//                 // Head Office users see every branch by default. They may
//                 // optionally filter the list with ?branch_id={branchId}.
//                 if ($branchFilter !== null) {
//                     $applyExactBranchScope($query, $branchFilter);
//                 }

//             } elseif (
//                 $userType === 'employee' &&
//                 in_array($roleCode, ['BRANCH_MANAGER', 'BRANCH_CREDIT_MANAGER', 'PARTNER BRANCH_MANAGER']) &&
//                 !empty($authUser->branch_id)
//             ) {
//                 $branchId = $authUser->branch_id;

//                 // Branch managers can see leads of their exact branch only.
//                 // Do not include child/sub-branches. For legacy leads without
//                 // branch_id, fall back to the creator's branch.
//                 $applyExactBranchScope($query, (int) $branchId);

//             } elseif (
//                 $userType === 'employee' &&
//                 in_array($roleCode, ['SALES_PERSON', 'SALES_MANAGER'])
//             ) {
//                 $query->where('created_by', $authUser->id);

//             } elseif (
//                 $userType === 'partner' &&
//                 $roleCode === 'PARTNER_ADMINISTRATIVE'
//             ) {
//                 $organizationId = $authUser->organization_id;
//                 $branchId       = $authUser->branch_id;

//                 $peerUsersQuery = User::query()
//                     ->where('organization_id', $organizationId)
//                     ->where('user_type_id', $authUser->user_type_id);

//                 if (!empty($branchId)) {
//                     $peerUsersQuery->where('branch_id', $branchId);
//                 }

//                 $allowedUserIds = $peerUsersQuery->pluck('id')->toArray();
//                 $allowedUserIds = array_unique([...$allowedUserIds, $authUser->id]);

//                 $query->whereIn('created_by', $allowedUserIds);

//             } elseif (
//                 $userType === 'partner' &&
//                 $roleCode === 'PARTNER_SUB_USER'
//             ) {
//                 $query->where('created_by', $authUser->id);

//             } else {

//                 $query->where('created_by', $authUser->id);
//             }


//             if (!empty($search)) {
//                 $query->where(function ($q) use ($search) {
//                     $q->where('uuid', 'LIKE', "%{$search}%")
//                         ->orWhere('lead_id', 'LIKE', "%{$search}%")
//                         ->orWhere('mobile_number', 'LIKE', "%{$search}%")
//                         ->orWhereHas('applicantDetails', function ($applicantQuery) use ($search) {
//                             $applicantQuery->where('full_name', 'LIKE', "%{$search}%")
//                                 ->orWhere('alternate_contact_number', 'LIKE', "%{$search}%")
//                                 ->orWhere('applicant_type', 'LIKE', "%{$search}%");
//                         });
//                 });
//             }

//             $leads = $query->latest('id')->get();


//             $formattedLeads = $leads->map(function (BLLeadCreation $lead) {
//                 $workflowStatus = $this->resolveWorkflowStatus($lead);

//                 $pendingWith = null;

//                 if ($lead->currentAssignment) {
//                     $assignedUser = $lead->currentAssignment->assignedToUser;

//                     $pendingWith = [
//                         'assignment_id' => $lead->currentAssignment->id,
//                         'user_id'       => $lead->currentAssignment->assigned_to_user_id
//                                         ?? $lead->currentAssignment->assigned_to,
//                         'name'          => $assignedUser?->user_name,
//                         'email'         => $assignedUser?->user_email,
//                         'mobile'        => $assignedUser?->user_mobile_number,
//                         'role'          => $lead->currentAssignment->assigned_to_role
//                                         ?? $assignedUser?->user_designation,
//                         'assigned_at'   => optional($lead->currentAssignment->assigned_at)
//                                             ->format('Y-m-d H:i:s'),
//                         'remarks'       => $lead->currentAssignment->remarks,
//                     ];
//                 }

//                 // The lead carries its own branch since 2026-07-27; older
//                 // leads created before that fall back to the creator's branch.
//                 // $branch = $lead->branch ?? $lead->creator?->branch;
//                 $branch = $lead->branch;

//                 return [
//                     'uuid'          => $lead->uuid,
//                     'lead_id'       => $lead->lead_id,
//                     // 'stage'         => optional($lead->currentJourney)->stage,
//                     // 'status'        => optional($lead->currentJourney)->status,

//                     'stage'          => $lead->currentJourney?->stage,
//                     'status'         => $workflowStatus,
//                     'journey_status' => $lead->currentJourney?->status,
//                         'loan_type'     => $lead->loan_type,
//                     'customer_type' => $lead->customer_type,
//                     'sub_loan_type' => $lead->sub_loan_type,
//                     'mobile_number' => $lead->mobile_number,
//                     'loan_amount'   => $lead->loan_amount,
//                     'loan_tenur'    => $lead->loan_tenur,

//                     'branch' => $branch ? [
//                         'id'          => $branch->id,
//                         'name'        => $branch->name,
//                         'branch_code' => $branch->branch_code,
//                     ] : null,
//                     'branch_name' => $branch?->name,

//                     'applicants' => $lead->applicantDetails->map(function (BLApplicantDetails $applicant) {
//                         return [
//                             'applicant_id'   => $applicant->applicant_id,
//                             'applicant_type' => $applicant->applicant_type,
//                             'name'           => $applicant->full_name,
//                             'phone_number'   => $applicant->alternate_contact_number,
//                         ];
//                     })->values(),

//                     'pending_with' => $pendingWith,

//                     'created_at' => optional($lead->created_at)->format('Y-m-d H:i:s'),
//                     'updated_at' => optional($lead->updated_at)->format('Y-m-d H:i:s'),
//                 ];
//             })->values();

//             return response()->json([
//                 'success' => true,
//                 'message' => 'All BL leads fetched successfully.',
//                 'count'   => $formattedLeads->count(),
//                 'data'    => $formattedLeads,
//             ], 200);

//         } catch (Throwable $e) {
//             Log::error('BL Leads Fetch Error', [
//                 'message' => $e->getMessage(),
//                 'file'    => $e->getFile(),
//                 'line'    => $e->getLine(),
//             ]);

//             return response()->json([
//                 'success' => false,
//                 'message' => 'Something went wrong while fetching BL leads.',
//             ], 500);
//         }
//     }

//     private function resolveWorkflowStatus(BLLeadCreation $lead): string
// {

//     if ($lead->disbursement !== null) {
//         return 'disbursed';
//     }


//     if ($lead->latestCam !== null) {
//         $camStatus = strtolower(
//             trim((string) $lead->latestCam->status)
//         );

//         if (in_array($camStatus, ['approve', 'approved'], true)) {
//             return 'disbursement_pending';
//         }

//         if (in_array($camStatus, ['reject', 'rejected'], true)) {
//             return 'cam_rejected';
//         }

//         return 'cam_approval_pending';
//     }


//     if ($lead->schemeSelect !== null) {
//         return 'cam_pending';
//     }


//     $journeyStage = strtolower(
//         trim((string) $lead->currentJourney?->stage)
//     );

//     $journeyStage = str_replace(
//         [' ', '-', '/'],
//         '_',
//         $journeyStage
//     );

//     if (in_array($journeyStage, [
//         'lead_submit',
//         'lead_submitted',
//         'submitted',
//     ], true)) {
//         return 'scheme_select_pending';
//     }


//     return strtolower(
//         (string) ($lead->currentJourney?->status ?? 'draft')
//     );
// }
// }