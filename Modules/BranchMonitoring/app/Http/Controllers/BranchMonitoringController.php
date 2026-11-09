<?php

namespace Modules\BranchMonitoring\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\LeadStatusResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Modules\BLLeadCreation\Models\BLLeadCreation;
use Modules\UserManagement\Models\OrganizationBranch;
use Modules\UserManagement\Models\User;
use Modules\UserManagement\Support\BranchAccess;
use Throwable;

class BranchMonitoringController extends Controller
{
    public function index(): JsonResponse
    {
        try {
            $branchQuery = OrganizationBranch::where('is_active', true);
            BranchAccess::scope($branchQuery, request()->user(), 'id');

            $branches = $branchQuery->orderBy('name')->get();

            $data = $branches->map(function (OrganizationBranch $branch) {
                $employeeCount = User::where(function ($query) use ($branch) {
                    $query->whereHas('branches', fn ($q) => $q->where('branch_id', $branch->id))
                        ->orWhere('branch_id', $branch->id);
                })->count();

                $leads = BLLeadCreation::where('branch_id', $branch->id)
                    ->with('disbursement')
                    ->get();

                $activeCount = 0;
                $closedCount = 0;
                $totalDisbursed = 0;
                $stageBreakdown = [];

                foreach ($leads as $lead) {
                    $status = \Modules\Customers\Support\LeadStatusResolver::resolve($lead);

                    if ($status['is_closed']) {
                        $closedCount++;
                        $totalDisbursed += (float) ($lead->disbursement?->disbursement_amount ?? 0);
                    } else {
                        $activeCount++;
                    }

                    $stageName = $status['stage'] ?? 'Unassigned';
                    $stageBreakdown[$stageName] = ($stageBreakdown[$stageName] ?? 0) + 1;
                }

                return [
                    'branch_id'              => $branch->id,
                    'branch_name'            => $branch->name,
                    'branch_code'            => $branch->branch_code,
                    'employee_count'         => $employeeCount,
                    'total_loans'            => $leads->count(),
                    'active_loans'           => $activeCount,
                    'closed_loans'           => $closedCount,
                    'total_disbursed_amount' => $totalDisbursed,
                    'stage_breakdown'        => collect($stageBreakdown)
                        ->map(fn ($count, $stageName) => ['stage_name' => $stageName, 'count' => $count])
                        ->values(),
                ];
            });

            return response()->json([
                'success'    => true,
                'message'    => 'Branch monitoring data fetched successfully.',
                'request_id' => (string) Str::uuid(),
                'timestamp'  => now()->format('Y-m-d H:i:s'),
                'data'       => $data,
            ], 200);

        } catch (Throwable $e) {
            return response()->json([
                'success'    => false,
                'message'    => 'Something went wrong while fetching branch monitoring data.',
                'request_id' => (string) Str::uuid(),
                'timestamp'  => now()->format('Y-m-d H:i:s'),
                'errors'     => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }
}