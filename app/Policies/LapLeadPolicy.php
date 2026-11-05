<?php

namespace App\Policies;
use Modules\UserManagement\Models\user;
use Modules\LapLeadCreation\Models\LapLeadCreation;
use App\Services\LapLeadPermissionService;

class LapLeadPolicy
{
    public function viewAny(User $user): bool
    {
        return LapLeadPermissionService::canViewAny($user);
    }

    public function view(User $user, LapLeadCreation $lead): bool
    {
        return LapLeadPermissionService::canView($lead->lead_id, $user);
    }

    public function create(User $user): bool
    {
        return LapLeadPermissionService::canCreate($user);
    }

    public function update(User $user, LapLeadCreation $lead): bool
    {
        return LapLeadPermissionService::canEdit($lead->lead_id, $user);
    }

    public function delete(User $user, LapLeadCreation $lead): bool
    {
        return LapLeadPermissionService::canDelete($lead->lead_id, $user);
    }

    // Custom method for assign permission (not default Laravel policy method)
    public function assign(User $user, LapLeadCreation $lead): bool
    {
        return LapLeadPermissionService::canAssign($user, $lead->lead_id);
    }
}