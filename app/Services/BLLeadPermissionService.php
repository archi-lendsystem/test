<?php

namespace App\Services;

use Modules\BLLeadCreation\Models\BLLeadCreation;

class BLLeadPermissionService
{
    // ─────────────────────────────────────────────────────────────────────────
    // ROLE CONSTANTS
    // Values match the `name` column in the roles table (lowercase).
    // getUserRole() always returns lowercase so both `name` and `code`
    // (e.g. "ADMINISTRATIVE" → "administrative") match correctly.
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Full bypass — these users can always edit, view, and assign
     * regardless of assignment or lead status.
     */
    const ADMIN_ROLES = [
        'super_administrative',   // code: SUPER_ADMINISTRATIVE
        'administrative',         // code: ADMINISTRATIVE
    ];

    /**
     * When a lead is assigned to any of these roles, it is fully locked
     * for everyone except ADMIN_ROLES.
     */
    const OPS_ROLES = [
        'ops_maker',    // code: OPS_MAKER
        'ops_checker',  // code: OPS_CHECKER
        'Ops Checker',
        'ops_manager',  // code: OPS_MANAGER
    ];

    /**
     * Lead statuses that permanently lock the lead.
     * Only ADMIN_ROLES can edit a lead in one of these statuses.
     */
    const LOCKED_STATUSES = [
        'Rejected',
        'Partially Disbursed',
        'Fully Disbursed',
    ];

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVATE HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Load a lead with currentJourney and currentAssignment eager-loaded.
     * Both relations are needed for every permission check.
     */
    private static function getLeadWithRelations(string $leadId): ?BLLeadCreation
    {
        return BLLeadCreation::with([
            'currentJourney',      // HasOne — where is_latest = true
            'currentAssignment',   // HasOne — where is_active = true
        ])->where('lead_id', $leadId)->first();
    }

    /**
     * Resolve the user's role as a lowercase string.
     *
     * Resolution order:
     *  1. $user->user_role               (legacy flat column, may not exist)
     *  2. $user->roles->first()->code    (e.g. "CREDIT_CHECKER" → lowercased)
     *  3. $user->roles->first()->name    (e.g. "credit_checker")
     *
     * Always returns lowercase so all comparisons are case-insensitive.
     */
    private static function getUserRole($user): string
    {
        return strtolower(
            $user->user_role
            ?? optional($user->roles->first())->code
            ?? optional($user->roles->first())->name
            ?? ''
        );
    }

    /**
     * Is this user an Admin or Super Admin?
     */
    private static function isAdmin($user): bool
    {
        return in_array(self::getUserRole($user), self::ADMIN_ROLES, true);
    }

    /**
     * Is the current active assignment pointing to an OPS role?
     * Checks assigned_to_role column on bl_lead_assignments.
     */
    private static function isAssignedToOps(?object $assignment): bool
    {
        if (!$assignment) return false;

        return in_array(
            strtolower($assignment->assigned_to_role ?? ''),
            self::OPS_ROLES,
            true
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PUBLIC API
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Any logged-in user can view the leads list.
     */
    public static function canViewAny($user): bool
    {
        return $user !== null;
    }

    /**
     * Can the user view this specific lead?
     *
     * Admin → always yes.
     * Others → must be the creator OR the currently assigned person.
     */
    public static function canView(string $leadId, $user): bool
    {
        if (!$user) return false;
        if (self::isAdmin($user)) return true;

        $lead = self::getLeadWithRelations($leadId);
        if (!$lead) return false;

        if ((int) $lead->created_by === (int) $user->id) return true;

        $assignment = $lead->currentAssignment;
        if ($assignment && (int) $assignment->assigned_to_user_id === (int) $user->id) {
            return true;
        }

        return false;
    }

    /**
     * Any logged-in user can create a lead.
     */
    public static function canCreate($user): bool
    {
        return $user !== null;
    }

    /**
     * Can the user edit this lead?
     *
     * ┌─────────────────────────────────────────────────────────────────────┐
     * │ DECISION TREE (evaluated top-to-bottom, first match wins)           │
     * │                                                                     │
     * │  1. Not authenticated                → false                        │
     * │  2. Admin / Super Admin              → TRUE  ← bypasses everything  │
     * │     (covers: unassigned, assigned,                                  │
     * │      OPS-locked, and status-locked leads)                           │
     * │  3. Lead not found                   → false                        │
     * │  4. Lead status is locked            → false                        │
     * │  5. No active assignment             → true only if user = creator  │
     * │  6. Assigned to OPS role             → false ← full lock            │
     * │  7. Assigned to non-OPS person       → true only if user = assignee │
     * └─────────────────────────────────────────────────────────────────────┘
     */
    public static function canEdit(string $leadId, $user): bool
    {
        // 1. Must be authenticated
        if (!$user) return false;

        // 2. Admin / Super Admin — full bypass, skip all checks below.
        if (self::isAdmin($user)) return true;

        $lead = self::getLeadWithRelations($leadId);

        // 3. Lead must exist
        if (!$lead) return false;

        // 4. Status-locked lead — admin already returned true above
        $journey = $lead->currentJourney;
        if ($journey && in_array($journey->status, self::LOCKED_STATUSES, true)) {
            return false;
        }

        $assignment = $lead->currentAssignment;

        // 5. No active assignment → only the creator can edit
        if (!$assignment) {
            return (int) $lead->created_by === (int) $user->id;
        }

        // 6. Lead is assigned to an OPS role → full lock for non-admins
        if (self::isAssignedToOps($assignment)) {
            return false;
        }

        // 7. Assigned to a non-OPS person → only that person can edit
        return (int) $assignment->assigned_to_user_id === (int) $user->id;
    }

    /**
     * Can the user delete this lead?
     *
     * Only Admin / Super Admin can delete, and only if the lead is not locked.
     */
    public static function canDelete(string $leadId, $user): bool
    {
        if (!$user) return false;
        if (!self::isAdmin($user)) return false;

        $lead = self::getLeadWithRelations($leadId);
        if (!$lead) return false;

        $journey = $lead->currentJourney;
        if ($journey && in_array($journey->status, self::LOCKED_STATUSES, true)) {
            return false;
        }

        return true;
    }

    /**
     * Can the user reassign this lead to someone else?
     *
     * Admin → yes (unless status-locked).
     * Non-admin → only if they are the currently assigned person.
     * Unassigned lead → only the creator can reassign.
     */
    public static function canAssign($user, ?string $leadId = null): bool
    {
        if (!$user) return false;

        $allowedRoles = [
            'sales_person',         // code: SALES_PERSON
            'sales_manager',        // code: SALES_MANAGER
            'credit_maker',         // code: CREDIT_MAKER
            'credit_checker',       // code: CREDIT_CHECKER
            'credit_manager',       // code: CREDIT_MANAGER
            'ops_maker',            // code: OPS_MAKER
            'ops_checker',          // code: OPS_CHECKER
            'ops_manager',          // code: OPS_MANAGER
            'administrative',       // code: ADMINISTRATIVE
            'super_administrative', // code: SUPER_ADMINISTRATIVE
        ];

        if (!in_array(self::getUserRole($user), $allowedRoles, true)) {
            return false;
        }

        if ($leadId) {
            $lead = self::getLeadWithRelations($leadId);
            if (!$lead) return false;

            // Status-locked lead — only admin can reassign
            $journey = $lead->currentJourney;
            if ($journey && in_array($journey->status, self::LOCKED_STATUSES, true)) {
                return self::isAdmin($user);
            }

            if (!self::isAdmin($user)) {
                $assignment = $lead->currentAssignment;

                if ($assignment) {
                    // Non-admin can only reassign if they are the assigned person
                    if ((int) $assignment->assigned_to_user_id !== (int) $user->id) {
                        return false;
                    }
                } else {
                    // No assignment → only creator can reassign
                    if ((int) $lead->created_by !== (int) $user->id) {
                        return false;
                    }
                }
            }
        }

        return true;
    }

    /**
     * Return all four permissions for a lead + user in a single call.
     * Used by the /edit-access endpoint.
     */
    public static function permissions(string $leadId, $user): array
    {
        if (!$user) {
            return [
                'can_view'   => false,
                'can_edit'   => false,
                'can_delete' => false,
                'can_assign' => false,
            ];
        }

        return [
            'can_view'   => self::canView($leadId, $user),
            'can_edit'   => self::canEdit($leadId, $user),
            'can_delete' => self::canDelete($leadId, $user),
            'can_assign' => self::canAssign($user, $leadId),
        ];
    }
}