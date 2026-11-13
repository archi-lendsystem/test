<?php

namespace Modules\UserManagement\Support;

use Illuminate\Database\Eloquent\Builder;
use Modules\UserManagement\Models\User;

final class ReportBranchAccess
{
    /**
     * user_branches pivot is the source of truth.
     *
     * Har role ke liye sirf assigned branches return hongi.
     * Koi branch assigned nahi hai to [] return hoga.
     */
    public static function branchIds(User $user): array
    {
        return $user->branches()
            ->where('organization_branches.is_active', true)
            ->pluck('organization_branches.id')
            ->map(static fn($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }


    public static function resolveBranchIds(
        User $user,
        ?int $requestedBranchId = null
    ): array {
        $branchIds = self::branchIds($user);

        if ($requestedBranchId === null) {
            return $branchIds;
        }

        self::ensureRequestedBranchAllowed(
            $user,
            $requestedBranchId
        );

        return [$requestedBranchId];
    }

    /**
     * Check requested branch belongs to logged-in user.
     *
     * Agar branch_id request me nahi hai to koi problem nahi;
     * query user's all assigned branches par chalegi.
     */
    public static function ensureRequestedBranchAllowed(
        User $user,
        ?int $requestedBranchId
    ): void {
        if ($requestedBranchId === null) {
            return;
        }

        abort_unless(
            in_array(
                $requestedBranchId,
                self::branchIds($user),
                true
            ),
            403,
            'You cannot access data from another branch.'
        );
    }

    /**
     * Root table me directly branch_id ho.
     *
     * Example:
     * BLLead::query()
     * organization query etc.
     */
    public static function scopeByColumn(
        Builder $query,
        User $user,
        ?int $requestedBranchId = null,
        string $column = 'branch_id'
    ): Builder {
        $branchIds = self::branchIds($user);

        if ($requestedBranchId !== null) {
            self::ensureRequestedBranchAllowed(
                $user,
                $requestedBranchId
            );

            $branchIds = [$requestedBranchId];
        }

        return $query->whereIn(
            $column,
            $branchIds
        );
    }

    /**
     * Branch relation ke through milti ho.
     *
     * Examples:
     *
     * RepaymentAccount:
     * relation = 'lead'
     *
     * RepaymentTransaction:
     * relation = 'account.lead'
     *
     * RepaymentInstallment:
     * relation = 'account.lead'
     */
    public static function scopeByRelation(
        Builder $query,
        User $user,
        string $relation,
        ?int $requestedBranchId = null,
        string $branchColumn = 'branch_id'
    ): Builder {
        $branchIds = self::branchIds($user);

        if ($requestedBranchId !== null) {
            self::ensureRequestedBranchAllowed(
                $user,
                $requestedBranchId
            );

            $branchIds = [$requestedBranchId];
        }

        return $query->whereHas(
            $relation,
            function ($relationQuery) use (
                $branchIds,
                $branchColumn
            ) {
                $relationQuery->whereIn(
                    $branchColumn,
                    $branchIds
                );
            }
        );
    }
}
