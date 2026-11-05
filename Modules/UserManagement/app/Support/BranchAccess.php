<?php

namespace Modules\UserManagement\Support;

use Illuminate\Database\Eloquent\Builder;
use Modules\UserManagement\Models\User;
use Modules\UserManagement\Models\Role;

final class BranchAccess
{
    public static function isSuperAdministrative(User $user): bool
    {
        return self::hasRole($user, 'SUPER_ADMINISTRATIVE');
    }

    public static function isAdministrative(User $user): bool
    {
        return self::hasRole($user, 'ADMINISTRATIVE');
    }

    /**
     * Only ADMINISTRATIVE users are branch-scoped here. Keeping this explicit
     * avoids changing the existing access behaviour of every other role.
     */
    public static function isBranchScopedAdministrator(User $user): bool
    {
        return ! self::isSuperAdministrative($user)
            && self::isAdministrative($user);
    }

    public static function branchIds(User $user): array
    {
        $ids = $user->branches()
            ->pluck('organization_branches.id')
            ->map(static fn ($id) => (int) $id)
            ->all();

        if (empty($ids) && $user->branch_id) {
            $ids = [(int) $user->branch_id];
        }

        return array_values(array_unique($ids));
    }

    public static function scope(Builder $query, User $user, string $column = 'branch_id'): Builder
    {
        if (self::isBranchScopedAdministrator($user)) {
            $query->whereIn($column, self::branchIds($user));
        }

        return $query;
    }

    public static function ensureCanAccessUser(User $actor, User $target): void
    {
        if (! self::isBranchScopedAdministrator($actor)) {
            return;
        }

        $targetIsSuperAdministrator = $target->roles()
            ->where('roles.code', 'SUPER_ADMINISTRATIVE')
            ->exists();

        abort_if(
            $targetIsSuperAdministrator,
            403,
            'Only Super Administrative can manage a Super Administrative user.'
        );

        $allowedBranchIds = self::branchIds($actor);
        $canAccess = ! empty($allowedBranchIds)
            && $target->branches()
                ->whereIn('organization_branches.id', $allowedBranchIds)
                ->exists();

        // Backward-compatible fallback for users created before user_branches.
        if (! $canAccess && $target->branch_id) {
            $canAccess = in_array((int) $target->branch_id, $allowedBranchIds, true);
        }

        abort_unless($canAccess, 403, 'You cannot access a user from another branch.');
    }

    public static function ensureRequestedBranchesAllowed(User $actor, array $requestedBranchIds): void
    {
        if (! self::isBranchScopedAdministrator($actor)) {
            return;
        }

        $requested = array_values(array_unique(array_map('intval', $requestedBranchIds)));
        $invalid = array_diff($requested, self::branchIds($actor));

        if (empty($requested) || ! empty($invalid)) {
            abort(403, 'You can assign employees only to your assigned branches.');
        }
    }

    public static function ensureCanAssignRoles(User $actor, array $roleIds): void
    {
        if (! self::isBranchScopedAdministrator($actor)) {
            return;
        }

        $containsSuperAdministrativeRole = Role::query()
            ->whereIn('id', $roleIds)
            ->where('code', 'SUPER_ADMINISTRATIVE')
            ->exists();

        if ($containsSuperAdministrativeRole) {
            abort(403, 'Only Super Administrative can assign the Super Administrative role.');
        }
    }

    public static function ensureCanModifyAdministratorBranches(
        User $actor,
        User $target
    ): void {
        $targetIsAdministrator = $target->roles()
            ->where('roles.code', 'ADMINISTRATIVE')
            ->exists();

        if (! $targetIsAdministrator) {
            return;
        }

        abort_unless(
            self::isSuperAdministrative($actor),
            403,
            'Only Super Administrative can change Administrator branch assignments.'
        );
    }

    public static function ensureCanAccessBranch(User $actor, ?int $branchId): void
    {
        if (! self::isBranchScopedAdministrator($actor)) {
            return;
        }

        abort_unless(
            $branchId !== null && in_array($branchId, self::branchIds($actor), true),
            403,
            'You cannot access data from another branch.'
        );
    }

    private static function hasRole(User $user, string $code): bool
    {
        return $user->roles()->where('roles.code', $code)->exists();
    }
}
