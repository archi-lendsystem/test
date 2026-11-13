<?php

namespace Modules\UserManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Modules\UserManagement\Models\User;
use Modules\UserManagement\Models\UserType;
use Modules\UserManagement\Http\Requests\StoreUserRequest;
use Modules\UserManagement\Http\Requests\UpdateUserRequest;
use Modules\UserManagement\Transformers\UserResource;
use Modules\UserManagement\Support\BranchAccess;
use Modules\Product\Models\Product;

class UserManagementController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $query = User::with(['userType', 'roles', 'products', 'vendors','branches']);

        $this->scopeUsersForAdministrator($query, $request->user());

        if ($request->filled('user_type_id')) {
            $query->where('user_type_id', $request->user_type_id);
        }

        if ($request->filled('user_type_slug')) {
            $query->whereHas('userType', fn($q) => $q->where('name', $request->user_type_slug));
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
        }

        $perPage = (int) $request->get('per_page', 15);
        $users   = $query->latest()->paginate($perPage);

        return $this->success(UserResource::collection($users)->response()->getData(true));
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $data         = $request->validated();
        $data['uuid'] = (string) Str::uuid();

        $data['branch_ids'] = $this->resolveRequestedBranchIds($request, $data, true);

        BranchAccess::ensureRequestedBranchesAllowed(
            $request->user(),
            $data['branch_ids'] ?? []
        );
        BranchAccess::ensureCanAssignRoles($request->user(), $data['role_ids'] ?? []);

        $this->validateBranchCountForRoles($data['role_ids'] ?? [], $data['branch_ids'] ?? []);

        // branch_id stays the user's primary/default branch (used wherever
        // code still assumes a single branch); user_branches (synced below)
        // is the authoritative multi-branch list.
        if (! empty($data['branch_ids'])) {
            $data['branch_id'] = $data['branch_ids'][0];
        }

        $userFields = collect($data)->except(['role_ids', 'product_ids', 'vendors', 'branch_ids'])->toArray();

        $user = DB::transaction(function () use ($userFields, $data) {
            $user = User::create($userFields);
            $this->syncRelationships($user, $data);

            return $user;
        });

        return $this->success(
            new UserResource($user->load(['userType', 'roles', 'products', 'vendors', 'branches'])),
            'User created successfully',
            201
        );
    }

    public function show(Request $request, User $user): JsonResponse
    {
        BranchAccess::ensureCanAccessUser($request->user(), $user);

        return $this->success(
            new UserResource($user->load([
                'userType', 'parent', 'reportsTo', 'roles', 'products', 'vendors', 'branches'
            ]))
        );
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        BranchAccess::ensureCanAccessUser($request->user(), $user);

        $data = $request->validated();

        if (array_key_exists('branch_id', $data) || array_key_exists('branch_ids', $data)) {
            $data['branch_ids'] = $this->resolveRequestedBranchIds($request, $data, false);
        }

        $this->ensureAdministratorBranchUpdateIsAllowed($request, $user, $data);
        BranchAccess::ensureCanAssignRoles($request->user(), $data['role_ids'] ?? []);

        // Validate against whatever roles/branches this user will actually
        // end up with, whether or not this particular request touches them.
        $roleIds = array_key_exists('role_ids', $data)
            ? ($data['role_ids'] ?? [])
            : $user->roles()->pluck('roles.id')->toArray();

        $branchIds = array_key_exists('branch_ids', $data)
            ? ($data['branch_ids'] ?? [])
            : $user->branches()->pluck('organization_branches.id')->toArray();

        $this->validateBranchCountForRoles($roleIds, $branchIds);

        // branch_id stays the user's primary/default branch (used wherever
        // code still assumes a single branch); user_branches (synced below)
        // is the authoritative multi-branch list.
        if (array_key_exists('branch_ids', $data)) {
            $data['branch_id'] = $data['branch_ids'][0] ?? null;
        }

        $userFields = collect($data)->except(['role_ids', 'product_ids', 'vendors', 'branch_ids'])->toArray();

        DB::transaction(function () use ($user, $userFields, $data) {
            $user->update($userFields);
            $this->syncRelationships($user, $data);
        });

        return $this->success(
            new UserResource($user->load(['userType', 'roles', 'products', 'vendors'])),
            'User updated successfully'
        );
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        BranchAccess::ensureCanAccessUser($request->user(), $user);

        $user->delete();
        return $this->success(null, 'User deleted successfully');
    }

    public function getByType(Request $request, string $type): JsonResponse
    {
        $userType = UserType::where('name', $type)->first();

        if (! $userType) {
            return $this->error("Invalid user type: '{$type}'", 400);
        }

        $query = User::with(['userType'])
            ->where('user_type_id', $userType->id);

        $this->scopeUsersForAdministrator($query, $request->user());

        $users = $query
            ->latest()
            ->get();

        return $this->success(UserResource::collection($users));
    }

    public function assignRoles(Request $request, User $user): JsonResponse
    {
        BranchAccess::ensureCanAccessUser($request->user(), $user);

        $request->validate([
            'role_ids'   => 'required|array',
            'role_ids.*' => 'integer|exists:roles,id',
        ]);

        BranchAccess::ensureCanAssignRoles($request->user(), $request->role_ids);

        $user->roles()->syncWithoutDetaching($request->role_ids);
        return $this->success(null, 'Roles assigned successfully');
    }

    public function assignProducts(Request $request, User $user): JsonResponse
    {
        BranchAccess::ensureCanAccessUser($request->user(), $user);

        $request->validate([
            'product_ids'   => 'required|array',
            'product_ids.*' => 'integer|exists:products,id',
        ]);

        if (!$user->is_active) {
            return $this->error('Products cannot be assigned to an inactive user.', 403);
        }

        $activeProductIds = Product::whereIn('id', $request->product_ids)
            ->where('status', true)
            ->pluck('id')
            ->toArray();

        if (empty($activeProductIds)) {
            return $this->error('No active products found.', 422);
        }

        $user->products()->syncWithoutDetaching($activeProductIds);
        return $this->success(null, 'Products assigned successfully');
    }

    public function assignVendors(Request $request, User $user): JsonResponse
    {
        BranchAccess::ensureCanAccessUser($request->user(), $user);

        $request->validate([
            'vendors'                  => 'required|array',
            'vendors.*.vendor_id'      => 'required|integer|exists:users,id',
            'vendors.*.vendor_type_id' => 'required|integer|exists:vendor_types,id',
        ]);

        $syncData = collect($request->vendors)
            ->mapWithKeys(fn($v) => [
                $v['vendor_id'] => ['vendor_type_id' => $v['vendor_type_id']],
            ])
            ->toArray();

        $user->vendors()->syncWithoutDetaching($syncData);
        return $this->success(null, 'Vendors assigned successfully');
    }

    /**
     * A Branch Manager (or Partner Branch Manager) must be assigned to
     * exactly one branch; every other role may span several.
     */
    private function validateBranchCountForRoles(array $roleIds, array $branchIds): void
    {
        if (empty($roleIds)) {
            return;
        }

        $singleBranchRoleCodes = ['BRANCH_MANAGER', 'PARTNER BRANCH_MANAGER'];

        $hasSingleBranchRole = \Modules\UserManagement\Models\Role::whereIn('id', $roleIds)
            ->whereIn('code', $singleBranchRoleCodes)
            ->exists();

        if ($hasSingleBranchRole && count($branchIds) !== 1) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'branch_ids' => ['A Branch Manager must be assigned to exactly one branch.'],
            ]);
        }
    }

    private function syncRelationships(User $user, array $data): void
    {
        // has() is true even when the key's value is null — a request body
        // like {"role_ids": null} (e.g. the frontend's role-name lookup
        // silently failing to resolve an id) used to pass that check, fall
        // through the `?? []`, and wipe every role/branch this user had.
        // Only sync when the caller actually sent something to sync to.
        if (array_key_exists('role_ids', $data)) {
            $user->roles()->sync($data['role_ids'] ?? []);
        }

        if (array_key_exists('branch_ids', $data)) {
            $user->branches()->sync($data['branch_ids'] ?? []);
        }

        if (array_key_exists('product_ids', $data)) {
            $user->products()->sync($data['product_ids'] ?? []);
        }

        if (array_key_exists('vendors', $data)) {
            $syncData = collect($data['vendors'] ?? [])
                ->mapWithKeys(fn($v) => [
                    $v['vendor_id'] => ['vendor_type_id' => $v['vendor_type_id']],
                ])
                ->toArray();

            $user->vendors()->sync($syncData);
        }
    }

    
// public function getBranches(Request $request): JsonResponse
// {
//     $query = \Modules\UserManagement\Models\OrganizationBranch::query()
//         ->where('is_active', true);

//     if ($request->filled('user_type_id')) {
//         $query->where('user_type_id', $request->user_type_id);
//     }

//     $branches = $query->select('id', 'name', 'branch_code', 'city', 'type')
//         ->orderBy('name')
//         ->get();

//     return $this->success($branches);
// }

public function getBranches(Request $request): JsonResponse
{
    $query = \Modules\UserManagement\Models\OrganizationBranch::query()
        ->where('is_active', true);

    // Administrator ko sirf assigned branches milengi.
    // Super Administrator ko saari branches milengi.
    BranchAccess::scope(
        $query,
        $request->user(),
        'id'
    );

    if ($request->filled('user_type_id')) {
        $query->where(
            'user_type_id',
            $request->user_type_id
        );
    }

    $branches = $query
        ->select(
            'id',
            'name',
            'branch_code',
            'city',
            'type'
        )
        ->orderBy('name')
        ->get();

    return $this->success($branches);
}

public function getManagers(Request $request): JsonResponse
{
    $query = User::where('is_active', true)
        ->where('id', '!=', auth()->id()); 

    $this->scopeUsersForAdministrator($query, $request->user());

    if ($request->filled('user_type_id')) {
        $query->where('user_type_id', $request->user_type_id);
    }

    $managers = $query->select('id', 'user_name', 'user_code', 'user_designation', 'branch_id')
        ->orderBy('user_name')
        ->get();

    return $this->success($managers);
}

// private function scopeUsersForAdministrator($query, User $actor): void
// {
//     if (! BranchAccess::isBranchScopedAdministrator($actor)) {
//         return;
//     }

//     $branchIds = BranchAccess::branchIds($actor);

//     $query->where(function ($userQuery) use ($branchIds) {
//         $userQuery->whereHas('branches', function ($branchQuery) use ($branchIds) {
//             $branchQuery->whereIn('organization_branches.id', $branchIds);
//         })->orWhereIn('branch_id', $branchIds);
//     })->whereDoesntHave('roles', function ($roleQuery) {
//         $roleQuery->where('roles.code', 'SUPER_ADMINISTRATIVE');
//     });
// }


private function scopeUsersForAdministrator($query, User $actor): void
{
    if (! BranchAccess::isBranchScopedAdministrator($actor)) {
        return;
    }

    $branchIds = BranchAccess::branchIds($actor);

    $query->where(function ($userQuery) use ($branchIds) {
        /*
         * New/multi-branch users:
         *
         * 1. Employee ki kam-se-kam ek branch honi chahiye.
         * 2. Employee ki koi branch Administrator ke scope ke
         *    bahar nahi honi chahiye.
         * 3. Primary branch_id bhi Administrator ke scope mein
         *    honi chahiye.
         */
        $userQuery->where(function ($multiBranchQuery) use ($branchIds) {
            $multiBranchQuery
                ->whereHas('branches')
                ->whereDoesntHave(
                    'branches',
                    function ($branchQuery) use ($branchIds) {
                        $branchQuery->whereNotIn(
                            'organization_branches.id',
                            $branchIds
                        );
                    }
                )
                ->where(function ($primaryBranchQuery) use ($branchIds) {
                    $primaryBranchQuery
                        ->whereNull('branch_id')
                        ->orWhereIn('branch_id', $branchIds);
                });
        })

        /*
         * Old users:
         * user_branches pivot mein record nahi hai,
         * isliye users.branch_id check hoga.
         */
        ->orWhere(function ($legacyUserQuery) use ($branchIds) {
            $legacyUserQuery
                ->whereDoesntHave('branches')
                ->whereIn('branch_id', $branchIds);
        });
    });

    // Administrator ko Super Administrator account show nahi hoga.
    $query->whereDoesntHave('roles', function ($roleQuery) {
        $roleQuery->where(
            'roles.code',
            'SUPER_ADMINISTRATIVE'
        );
    });
}

private function ensureAdministratorBranchUpdateIsAllowed(
    Request $request,
    User $target,
    array $data
): void {
    if (! BranchAccess::isBranchScopedAdministrator($request->user())) {
        return;
    }

    if (array_key_exists('branch_ids', $data) || array_key_exists('branch_id', $data)) {
        BranchAccess::ensureCanModifyAdministratorBranches(
            $request->user(),
            $target
        );
    }

    // An Administrator may assign only their own branches to employees. An
    // Administrator's branch mapping remains controlled by Super Administrative.
    if ((int) $request->user()->id !== (int) $target->id) {
        if (array_key_exists('branch_id', $data) && ! array_key_exists('branch_ids', $data)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'branch_ids' => ['Use branch_ids when changing a user branch assignment.'],
            ]);
        }

        if (array_key_exists('branch_ids', $data)) {
            BranchAccess::ensureRequestedBranchesAllowed(
                $request->user(),
                $data['branch_ids'] ?? []
            );
        }

        return;
    }

    $currentBranchIds = $target->branches()
        ->pluck('organization_branches.id')
        ->map(fn ($id) => (int) $id)
        ->all();

    if (empty($currentBranchIds) && $target->branch_id) {
        $currentBranchIds = [(int) $target->branch_id];
    }

    sort($currentBranchIds);

    if (array_key_exists('branch_ids', $data)) {
        $requestedBranchIds = array_values(array_unique(array_map(
            'intval',
            $data['branch_ids'] ?? []
        )));
        sort($requestedBranchIds);

        abort_unless(
            $requestedBranchIds === $currentBranchIds,
            403,
            'Only Super Administrative can change branch assignments.'
        );
    }

    if (array_key_exists('branch_id', $data)) {
        abort_unless(
            (int) $data['branch_id'] === (int) $target->branch_id,
            403,
            'Only Super Administrative can change branch assignments.'
        );
    }
}

private function resolveRequestedBranchIds(Request $request, array $data, bool $forCreate): array
{
    if (array_key_exists('branch_ids', $data)) {
        return array_values(array_unique(array_map('intval', $data['branch_ids'] ?? [])));
    }

    if (array_key_exists('branch_id', $data) && $data['branch_id'] !== null) {
        return [(int) $data['branch_id']];
    }

    if ($forCreate && BranchAccess::isBranchScopedAdministrator($request->user())) {
        $allowed = BranchAccess::branchIds($request->user());

        if (count($allowed) === 1) {
            return $allowed;
        }
    }

    return [];
}

} 