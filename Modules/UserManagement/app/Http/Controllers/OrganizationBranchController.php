<?php

namespace Modules\UserManagement\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\UserManagement\Http\Requests\OrganizationBranchRequest;
use Modules\UserManagement\Models\OrganizationBranch;
use Modules\UserManagement\Transformers\OrganizationBranchTransformer;
use Modules\UserManagement\Support\BranchAccess;
use Modules\UserManagement\Models\Organization;
use App\Traits\ApiResponse;

class OrganizationBranchController extends Controller
{
    use ApiResponse;
    public function index(Request $request): JsonResponse
    {
        $query = OrganizationBranch::query();

        BranchAccess::scope($query, $request->user(), 'id');

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }
        if ($request->has('organization_id')) {
            $query->where('organization_id', $request->organization_id);
        }
        if ($request->has('type')) {
            $query->where('type', $request->type);
        }
        if ($request->filled('user_type_id')) {
    $query->where('user_type_id', $request->user_type_id);
}

        $branches = $query->latest()->paginate(15);

        return response()->json([
            'status' => 'success',
            'data'   => OrganizationBranchTransformer::collection($branches),
            'meta'   => [
                'current_page' => $branches->currentPage(),
                'last_page'    => $branches->lastPage(),
                'per_page'     => $branches->perPage(),
                'total'        => $branches->total(),
            ],
        ]);
    }

    public function store(OrganizationBranchRequest $request): JsonResponse
    {
        $this->ensureSuperAdministrative($request);

        $data = $request->validated();


        $organization = Organization::find($data['organization_id']);


        $firstLetter = $organization
            ? strtoupper(substr(trim($organization->name), 0, 1))
            : 'B';


        $randomDigits = str_pad(rand(10000, 99999), 5, '0', STR_PAD_LEFT);
        $data['branch_code'] = $firstLetter . $randomDigits;

        $branch = OrganizationBranch::create($data);

        return response()->json([
            'status'  => 'success',
            'message' => 'Organization branch created successfully',
            'data'    => new OrganizationBranchTransformer($branch),
        ], 201);
    }

    public function show(Request $request, OrganizationBranch $organizationBranch): JsonResponse
    {
        BranchAccess::ensureCanAccessBranch($request->user(), (int) $organizationBranch->id);

        return response()->json([
            'status' => 'success',
            'data'   => new OrganizationBranchTransformer($organizationBranch),
        ]);
    }

    // public function update(OrganizationBranchRequest $request, OrganizationBranch $organizationBranch): JsonResponse
    // {
    //     $organizationBranch->update($request->validated());

    //     return response()->json([
    //         'status'  => 'success',
    //         'message' => 'Organization branch updated successfully',
    //         'data'    => new OrganizationBranchTransformer($organizationBranch),
    //     ]);
    // }

public function update(
    OrganizationBranchRequest $request,
    OrganizationBranch $branch
): JsonResponse {
    $this->ensureSuperAdministrative($request);

    $branch->update($request->validated());
    $branch->refresh();

    return response()->json([
        'status'  => 'success',
        'message' => 'Organization branch updated successfully',
        'data'    => new OrganizationBranchTransformer($branch),
    ]);
}

    public function destroy(OrganizationBranch $organizationBranch): JsonResponse
    {
        return response()->json([
            'status'  => 'error',
            'message' => 'Deletion is not allowed. Use toggle-active instead.'
        ], 405);
    }

    public function toggleActive(Request $request, OrganizationBranch $organizationBranch): JsonResponse
    {
        $this->ensureSuperAdministrative($request);

        $organizationBranch->is_active = !$organizationBranch->is_active;
        $organizationBranch->save();

        return response()->json([
            'status'  => 'success',
            'message' => 'Organization branch status updated successfully',
            'data'    => new OrganizationBranchTransformer($organizationBranch),
        ]);
    }

    // DROPDOWN – GET /api/v1/branches/dropdown
    public function dropdown(Request $request): JsonResponse
    {
        $query = OrganizationBranch::where('is_active', true);

        BranchAccess::scope($query, $request->user(), 'id');

        if ($request->filled('user_type_id')) {
            $query->where('user_type_id', $request->user_type_id);
        }

        if ($request->filled('organization_id')) {
            $query->where('organization_id', $request->organization_id);
        }

        $branches = $query->select('id', 'name', 'branch_code', 'city', 'type')
            ->orderBy('name')
            ->get();

        return $this->success($branches);
    }

    private function ensureSuperAdministrative(Request $request): void
    {
        abort_unless(
            BranchAccess::isSuperAdministrative($request->user()),
            403,
            'Only Super Administrative can create or update branches.'
        );
    }
}


// namespace Modules\UserManagement\Http\Controllers;

// use Illuminate\Http\JsonResponse;
// use Illuminate\Http\Request;
// use Illuminate\Routing\Controller;
// use Modules\UserManagement\Http\Requests\OrganizationBranchRequest;
// use Modules\UserManagement\Models\OrganizationBranch;
// use Modules\UserManagement\Transformers\OrganizationBranchTransformer;
// use Modules\UserManagement\Models\Organization;
// use App\Traits\ApiResponse;

// class OrganizationBranchController extends Controller
// {
//     use ApiResponse;
//     public function index(Request $request): JsonResponse
//     {
//         $query = OrganizationBranch::query();

//         if ($request->has('is_active')) {
//             $query->where('is_active', $request->boolean('is_active'));
//         }
//         if ($request->has('organization_id')) {
//             $query->where('organization_id', $request->organization_id);
//         }
//         if ($request->has('type')) {
//             $query->where('type', $request->type);
//         }
//         if ($request->filled('user_type_id')) {
//     $query->where('user_type_id', $request->user_type_id);
// }

//         $branches = $query->latest()->paginate(15);

//         return response()->json([
//             'status' => 'success',
//             'data'   => OrganizationBranchTransformer::collection($branches),
//             'meta'   => [
//                 'current_page' => $branches->currentPage(),
//                 'last_page'    => $branches->lastPage(),
//                 'per_page'     => $branches->perPage(),
//                 'total'        => $branches->total(),
//             ],
//         ]);
//     }

//     public function store(OrganizationBranchRequest $request): JsonResponse
//     {
//         $data = $request->validated();


//         $organization = Organization::find($data['organization_id']);


//         $firstLetter = $organization
//             ? strtoupper(substr(trim($organization->name), 0, 1))
//             : 'B';


//         $randomDigits = str_pad(rand(10000, 99999), 5, '0', STR_PAD_LEFT);
//         $data['branch_code'] = $firstLetter . $randomDigits;

//         $branch = OrganizationBranch::create($data);

//         return response()->json([
//             'status'  => 'success',
//             'message' => 'Organization branch created successfully',
//             'data'    => new OrganizationBranchTransformer($branch),
//         ], 201);
//     }

//     public function show(OrganizationBranch $organizationBranch): JsonResponse
//     {
//         return response()->json([
//             'status' => 'success',
//             'data'   => new OrganizationBranchTransformer($organizationBranch),
//         ]);
//     }

//     // public function update(OrganizationBranchRequest $request, OrganizationBranch $organizationBranch): JsonResponse
//     // {
//     //     $organizationBranch->update($request->validated());

//     //     return response()->json([
//     //         'status'  => 'success',
//     //         'message' => 'Organization branch updated successfully',
//     //         'data'    => new OrganizationBranchTransformer($organizationBranch),
//     //     ]);
//     // }

// public function update(
//     OrganizationBranchRequest $request,
//     OrganizationBranch $branch
// ): JsonResponse {
//     $branch->update($request->validated());
//     $branch->refresh();

//     return response()->json([
//         'status'  => 'success',
//         'message' => 'Organization branch updated successfully',
//         'data'    => new OrganizationBranchTransformer($branch),
//     ]);
// }

//     public function destroy(OrganizationBranch $organizationBranch): JsonResponse
//     {
//         return response()->json([
//             'status'  => 'error',
//             'message' => 'Deletion is not allowed. Use toggle-active instead.'
//         ], 405);
//     }

//     public function toggleActive(OrganizationBranch $organizationBranch): JsonResponse
//     {
//         $organizationBranch->is_active = !$organizationBranch->is_active;
//         $organizationBranch->save();

//         return response()->json([
//             'status'  => 'success',
//             'message' => 'Organization branch status updated successfully',
//             'data'    => new OrganizationBranchTransformer($organizationBranch),
//         ]);
//     }

//     // DROPDOWN – GET /api/v1/branches/dropdown
//     public function dropdown(Request $request): JsonResponse
//     {
//         $query = OrganizationBranch::where('is_active', true);

//         if ($request->filled('user_type_id')) {
//             $query->where('user_type_id', $request->user_type_id);
//         }

//         if ($request->filled('organization_id')) {
//             $query->where('organization_id', $request->organization_id);
//         }

//         $branches = $query->select('id', 'name', 'branch_code', 'city', 'type')
//             ->orderBy('name')
//             ->get();

//         return $this->success($branches);
//     }
// }
